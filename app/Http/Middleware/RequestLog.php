<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;

class RequestLog
{
    private const SENSITIVE_KEYS = [
        'password', 'token', 'secret', 'key', 'api_key',
        'passwd', 'passphrase', 'private', 'credentials', 'mnemonic',
    ];

    /**
     * 敏感 GET 端点：响应即含凭据/批量数据，读取行为必须留痕
     * （评审 M-1：机器 token 读取、一键安装命令、礼品卡/用户导出此前零审计）。
     * 命中其一即与 POST 同规则记录。
     */
    private const SENSITIVE_GET_PATTERNS = [
        'gettoken',
        'installcommand',
        'generateechkey',
        'export-codes',
        'dump',
    ];

    public function handle($request, Closure $next)
    {
        $method = $request->method();
        $shouldLog = $method === 'POST'
            || ($method !== 'GET' && $method !== 'HEAD' && $method !== 'OPTIONS')
            || ($method === 'GET' && $this->isSensitiveGet($request->path()));

        if (!$shouldLog) {
            return $next($request);
        }

        $response = $next($request);

        try {
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                return $response;
            }

            $action = $this->resolveAction($request->path());
            $data = $this->redactSensitiveData($request->all());

            // json_encode 对 >512 层嵌套或 INF/NaN 返回 false——审计行仍在但载荷为空，
            // 恶意管理员可用深度炸弹擦掉自己的审计内容；落兜底标记保留行。
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) {
                $encoded = '{"_error":"payload not encodable"}';
            }

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $this->redactedUri($request),
                'request_data' => $encoded,
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * 递归脱敏：键名包含敏感子串（含嵌套，如 config.private_key）的值替换为 [REDACTED]。
     * 此前 collect()->except() 仅顶层精确匹配，'key' 匹配不到 private_key，
     * 嵌套在 config 里的支付私钥会明文写入审计日志。
     * 值级兜底：字符串值携带 PEM 头（-----BEGIN）时无论键名一律脱敏——
     * 本次事故的成因正是"凭据存在不被键名覆盖的字段里"。
     */
    private function redactSensitiveData(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redactSensitiveData($value);
            } elseif (is_string($value) && str_contains($value, '-----BEGIN')) {
                $data[$key] = '[REDACTED]';
            }
        }

        return $data;
    }

    /**
     * uri 列同步脱敏：query 参数与请求体走同一套规则，堵住 ?token=… 这类
     * 绕过请求体脱敏的旁路（良性参数保留，保审计价值）。
     */
    private function redactedUri(\Illuminate\Http\Request $request): string
    {
        $uri = $request->getPathInfo();
        $query = $request->getQueryString();
        if ($query === null) {
            return $uri;
        }

        parse_str($query, $params);

        return $uri . '?' . http_build_query($this->redactSensitiveData($params));
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isSensitiveGet(string $path): bool
    {
        $path = strtolower($path);
        foreach (self::SENSITIVE_GET_PATTERNS as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveAction(string $path): string
    {
        // api/v2/{secure_path}/user/update → user.update
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path);
        // gift-card/create-template → gift_card.create_template
        $path = str_replace('-', '_', $path);
        // user/update → user.update, server/manage/sort → server_manage.sort
        $segments = explode('/', $path);
        $method = array_pop($segments);
        $resource = implode('_', $segments);

        return $resource . '.' . $method;
    }
}

