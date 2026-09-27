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

    public function handle($request, Closure $next)
    {
        if ($request->method() !== 'POST') {
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

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $this->redactedUri($request),
                'request_data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
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

