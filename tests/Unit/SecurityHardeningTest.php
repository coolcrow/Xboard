<?php

namespace Tests\Unit;

use App\Http\Middleware\TrustProxies;
use App\Utils\Helper;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    /**
     * randomChar：长度、字符集符合约定（安全评审 H-2 的回归锚点）。
     * 注：无法在单测中断言 CSPRNG 语义，此处锁定接口行为与字母表，
     * 实现层面由 random_int 保证（替换 mt_rand + shuffle）。
     */
    public function test_randomChar_respects_length_and_alphabet(): void
    {
        $this->assertSame(8, strlen(Helper::randomChar(8)));
        $this->assertSame(32, strlen(Helper::randomChar(32)));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', Helper::randomChar(16));

        $specialAllowed = str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$?|{/:;%^&*()-_[]{}<>~+=,.');
        foreach (str_split(Helper::randomChar(64, true)) as $ch) {
            $this->assertContains($ch, $specialAllowed);
        }
    }

    /**
     * randomChar：多次生成不塌缩（防止任何退化实现返回常量）。
     */
    public function test_randomChar_draws_are_not_degenerate(): void
    {
        $draws = [];
        for ($i = 0; $i < 200; $i++) {
            $draws[] = Helper::randomChar(8);
        }
        $this->assertSame(200, count(array_unique($draws)));
    }

    /**
     * TrustProxies 信任列表改为配置驱动（安全评审 H-1）：
     * 默认不再包含 10/8、192.168/16、Cloudflare 段等宽网段。
     */
    public function test_trusted_proxies_come_from_config(): void
    {
        config(['trustedproxies.proxies' => ['127.0.0.0/8', '172.20.0.0/24']]);

        $middleware = new TrustProxies();
        $ref = new \ReflectionProperty($middleware, 'proxies');
        $ref->setAccessible(true);

        $this->assertSame(['127.0.0.0/8', '172.20.0.0/24'], $ref->getValue($middleware));
    }

    public function test_trusted_proxies_default_excludes_broad_ranges(): void
    {
        $default = config('trustedproxies.proxies');
        $this->assertIsArray($default);
        $this->assertNotContains('10.0.0.0/8', $default);
        $this->assertNotContains('192.168.0.0/16', $default);
        $this->assertNotContains('169.254.0.0/16', $default);
        foreach ($default as $cidr) {
            $this->assertStringNotContainsString('162.158', (string) $cidr, 'Cloudflare 段不应默认可信');
        }
    }
}
