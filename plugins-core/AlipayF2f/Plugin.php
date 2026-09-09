<?php

namespace Plugin\AlipayF2f;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;
use Plugin\AlipayF2f\library\AlipayF2F;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['AlipayF2F'] = [
                    'name' => $this->getConfig('display_name', '支付宝当面付'),
                    'icon' => $this->getConfig('icon', '💙'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'app_id' => [
                'label' => '支付宝APPID',
                'type' => 'string',
                'required' => true,
                'description' => '支付宝开放平台应用的APPID'
            ],
            'private_key' => [
                'label' => '支付宝私钥',
                'type' => 'text',
                'required' => true,
                'description' => '应用私钥，用于签名'
            ],
            'public_key' => [
                'label' => '支付宝公钥',
                'type' => 'text',
                'required' => true,
                'description' => '支付宝公钥，用于验签'
            ],
            'product_name' => [
                'label' => '自定义商品名称',
                'type' => 'string',
                'description' => '将会体现在支付宝账单中'
            ]
        ];
    }

    public function pay($order): array
    {
        try {
            $gateway = new AlipayF2F();
            $gateway->setMethod('alipay.trade.precreate');
            $gateway->setAppId($this->getConfig('app_id'));
            $gateway->setPrivateKey($this->getConfig('private_key'));
            $gateway->setAlipayPublicKey($this->getConfig('public_key'));
            $gateway->setNotifyUrl($order['notify_url']);
            $gateway->setBizContent([
                'subject' => $this->getConfig('product_name') ?? (admin_setting('app_name', 'XBoard') . ' - 订阅'),
                'out_trade_no' => $order['trade_no'],
                'total_amount' => $order['total_amount'] / 100
            ]);
            $gateway->send();
            return [
                'type' => 0,
                'data' => $gateway->getQrCodeUrl()
            ];
        } catch (\Exception $e) {
            Log::error($e);
            throw new ApiException($e->getMessage());
        }
    }

    public function notify($params): array|bool
    {
        if ($params['trade_status'] !== 'TRADE_SUCCESS')
            return false;

        $gateway = new AlipayF2F();
        $gateway->setAppId($this->getConfig('app_id'));
        $gateway->setPrivateKey($this->getConfig('private_key'));
        $gateway->setAlipayPublicKey($this->getConfig('public_key'));

        // P1 跨应用重放防护：支付宝公钥对所有商户通用——攻击者可用自己的
        // 支付宝 app 对同一 out_trade_no 发起 ¥0.01 支付并重放真签名通知。
        // 绑定 app_id + 实付金额后方可信任
        if (($params['app_id'] ?? '') !== $this->getConfig('app_id')) {
            \Illuminate\Support\Facades\Log::error('alipayf2f notify: app_id mismatch (cross-app replay?)', [
                'received' => $params['app_id'] ?? '',
            ]);
            return false;
        }

        try {
            if ($gateway->verify($params)) {
                // 金额校验：实付金额 vs 订单金额（单位：元→分，容差 0.01 元）
                $order = \App\Models\Order::where('trade_no', $params['out_trade_no'])->first();
                $paidFen = (int) round(((float)($params['total_amount'] ?? 0)) * 100);
                if ($order && abs($paidFen - $order->total_amount) > 1) {
                    \Illuminate\Support\Facades\Log::error('alipayf2f notify: amount mismatch', [
                        'paid_fen' => $paidFen,
                        'expected_fen' => $order->total_amount,
                    ]);
                    return false;
                }

                return [
                    'trade_no' => $params['out_trade_no'],
                    'callback_no' => $params['trade_no'],
                    'amount' => $paidFen,
                ];
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }
}