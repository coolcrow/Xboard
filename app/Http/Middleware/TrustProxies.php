<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * 可信代理列表：配置化收敛（见 config/trustedproxies.php）。
     * 不再硬编码全部内网段 + Cloudflare——内网位置（同宿主容器）伪造
     * X-Forwarded-For 可绕过 ADMIN_IP_ALLOWLIST 与登录限流（安全评审 H-1）。
     * @var array<int, string>|string|null
     */
    protected $proxies;

    public function __construct()
    {
        $this->proxies = config('trustedproxies.proxies');
    }

    /**
     * 代理头映射
     * @var int
     */
    protected $headers =
    Request::HEADER_X_FORWARDED_FOR |
    Request::HEADER_X_FORWARDED_HOST |
    Request::HEADER_X_FORWARDED_PORT |
    Request::HEADER_X_FORWARDED_PROTO |
    Request::HEADER_X_FORWARDED_AWS_ELB;
}
