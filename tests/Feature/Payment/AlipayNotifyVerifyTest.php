<?php

namespace Tests\Feature\Payment;

use App\Models\Order;
use Plugin\AlipayF2f\Plugin;
use Tests\TestCase;

/**
 * 支付宝当面付异步回调验签全链路（评审 A3：资金路径此前零测试覆盖）。
 *
 * 同时守护两起历史事故的回归：
 * 1. 库类不可解析导致 notify() fatal（plugins/ 被 bind-mount 遮蔽——
 *    本测试若 ClassNotFound 直接失败，即为该事故复发）；
 * 2. 手续费误拒（期望金额必须含订单快照的 handling_amount，评审 L-1）。
 */
class AlipayNotifyVerifyTest extends TestCase
{
    private string $appId = '2026000000000001';
    private string $privateKey;
    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privatePem);
        $this->privateKey = trim(str_replace(
            ["-----BEGIN PRIVATE KEY-----", "-----END PRIVATE KEY-----", "\n", "\r"],
            '',
            $privatePem
        ));
        $details = openssl_pkey_get_details($res);
        $this->publicKey = trim(str_replace(
            ["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n", "\r"],
            '',
            $details['key']
        ));
    }

    /** 按 Alipay 规则（ksort + k=v& 拼接，去 sign/sign_type）构造 RSA2 签名 */
    private function signedParams(array $params, ?string $privateKey = null): array
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $query = implode('&', array_map(
            fn ($k, $v) => $k . '=' . $v,
            array_keys($params),
            $params
        ));
        $pem = "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split($privateKey ?? $this->privateKey, 64, "\n") . "-----END RSA PRIVATE KEY-----";
        openssl_sign($query, $signature, openssl_pkey_get_private($pem), OPENSSL_ALGO_SHA256);
        $params['sign'] = base64_encode($signature);
        $params['sign_type'] = 'RSA2';
        return $params;
    }

    private function plugin(): Plugin
    {
        $plugin = new Plugin('alipay_f2f');
        $plugin->setConfig([
            'enabled' => true,
            'app_id' => $this->appId,
            'private_key' => $this->privateKey,
            'public_key' => $this->publicKey,
        ]);
        return $plugin;
    }

    private function makeOrder(int $totalFen, ?int $handlingFen = null): Order
    {
        return Order::create([
            'user_id' => 1,
            'plan_id' => 2,
            'period' => 'month_price',
            'trade_no' => 'TEST' . uniqid(),
            'total_amount' => $totalFen,
            'handling_amount' => $handlingFen,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PENDING,
        ]);
    }

    private function notifyParams(Order $order, string $paidYuan, ?string $appIdOverride = null): array
    {
        return $this->signedParams([
            'app_id' => $appIdOverride ?? $this->appId,
            'out_trade_no' => $order->trade_no,
            'trade_no' => 'ALIPAY' . uniqid(),
            'total_amount' => $paidYuan,
            'trade_status' => 'TRADE_SUCCESS',
        ]);
    }

    public function test_valid_notify_passes_full_chain(): void
    {
        $order = $this->makeOrder(1000);
        $result = $this->plugin()->notify($this->notifyParams($order, '10.00'));

        $this->assertIsArray($result);
        $this->assertSame($order->trade_no, $result['trade_no']);
        $this->assertSame(1000, $result['amount']);
    }

    public function test_handling_fee_included_in_expected_amount(): void
    {
        // 评审 L-1：用户实付 = 总额 + 手续费；期望金额必须含快照手续费
        $order = $this->makeOrder(1000, 200);
        $result = $this->plugin()->notify($this->notifyParams($order, '12.00'));

        $this->assertIsArray($result, '含手续费的正确回调不得被拒');
        $this->assertSame(1200, $result['amount']);
    }

    public function test_amount_mismatch_rejected(): void
    {
        $order = $this->makeOrder(1000);
        $this->assertFalse($this->plugin()->notify($this->notifyParams($order, '0.01')));
    }

    public function test_cross_app_replay_rejected(): void
    {
        // 评审 P1：攻击者用自己的支付宝 app 对同一 out_trade_no 支付 ¥0.01 后重放真签名
        $order = $this->makeOrder(1000);
        $this->assertFalse($this->plugin()->notify($this->notifyParams($order, '0.01', '2099 attacker-app')));
    }

    public function test_forged_signature_rejected(): void
    {
        $order = $this->makeOrder(1000);
        // 用另一对密钥签名（模拟攻击者私钥）
        $res = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($res, $attackerPem);
        $attackerKey = trim(str_replace(
            ["-----BEGIN PRIVATE KEY-----", "-----END PRIVATE KEY-----", "\n", "\r"],
            '',
            $attackerPem
        ));
        $params = [
            'app_id' => $this->appId,
            'out_trade_no' => $order->trade_no,
            'trade_no' => 'ALIPAYFORGED',
            'total_amount' => '10.00',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $this->assertFalse($this->plugin()->notify($this->signedParams($params, $attackerKey)));
    }

    public function test_non_success_status_rejected(): void
    {
        $order = $this->makeOrder(1000);
        $params = $this->signedParams([
            'app_id' => $this->appId,
            'out_trade_no' => $order->trade_no,
            'trade_no' => 'ALIPAYX',
            'total_amount' => '10.00',
            'trade_status' => 'WAIT_BUYER_PAY',
        ]);
        $this->assertFalse($this->plugin()->notify($params));
    }
}
