<?php

// 可信代理 CIDR 列表：Laravel 依据它从 X-Forwarded-For 链解析真实客户端 IP，
// 是 IP 限流、登录 IP 记录、ADMIN_IP_ALLOWLIST 白名单的根基。
//
// 默认仅信任容器内 Caddy（127.0.0.1）与 docker 私网段——覆盖单机容器部署的常见拓扑。
// 生产建议用 TRUSTED_PROXIES 精确收敛到实际反代路径，例如本 fork 的 CVM 部署：
//   TRUSTED_PROXIES=127.0.0.0/8,172.20.0.0/24,172.23.0.0/24
//   （172.20.0.0/24 = nc_nginx 所在网；172.23.0.0/24 = 面板容器网关）
// 切勿放宽到 10/8、192.168/16 或公网 CDN 段，除非确认流量确实经过它们——
// 内网位置（同宿主机容器）伪造 X-Forwarded-For 可绕过限流与 IP 白名单（安全评审 H-1）。
return [
    'proxies' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('TRUSTED_PROXIES', '127.0.0.0/8,172.16.0.0/12')
    )))),
];
