<?php

namespace Plugin\Btcpay;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['BTCPay'] = [
                    'name' => $this->getConfig('display_name', 'BTCPay'),
                    'icon' => $this->getConfig('icon', '₿'),
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
            'btcpay_url' => [
                'label' => 'API接口所在网址',
                'type' => 'string',
                'required' => true,
                'description' => '包含最后的斜杠，例如：https://your-btcpay.com/'
            ],
            'btcpay_storeId' => [
                'label' => 'Store ID',
                'type' => 'string',
                'required' => true,
                'description' => 'BTCPay商店标识符'
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'type' => 'string',
                'required' => true,
                'description' => '个人设置中的API KEY(非商店设置中的)'
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook通知密钥'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => 'CNY',
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = @json_encode($params);
        $ret_raw = $this->curlPost($this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId') . '/invoices', $params_string);
        $ret = @json_decode($ret_raw, true);

        if (empty($ret['checkoutLink'])) {
            throw new ApiException("error!");
        }
        
        return [
            'type' => 1,
            'data' => $ret['checkoutLink'],
        ];
    }

    public function notify($params): array|bool
    {
        $payload = trim(request()->getContent());
        $headers = getallheaders();
        $headerName = 'Btcpay-Sig';
        $signraturHeader = isset($headers[$headerName]) ? $headers[$headerName] : '';
        $json_param = json_decode($payload, true);

        $computedSignature = "sha256=" . \hash_hmac('sha256', $payload, $this->getConfig('btcpay_webhook_key'));

        if (!$this->hashEqual($signraturHeader, $computedSignature)) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        // P0 修复：仅接受 InvoiceSettled 事件——BTCPay webhook 覆盖全生命周期
        // （Created/ReceivedPayment/Processing/Expired/Invalid），此前任何事件都
        // 会激活订单 = 创建发票即免费拿套餐（0 确认/部分支付/过期也放行）
        if (($json_param['type'] ?? '') !== 'InvoiceSettled') {
            \Illuminate\Support\Facades\Log::info('btcpay notify: ignoring non-settled event', [
                'type' => $json_param['type'] ?? 'unknown',
                'invoiceId' => $json_param['invoiceId'] ?? '',
            ]);
            return false;
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => "Authorization:" . "token " . $this->getConfig('btcpay_api_key') . "\r\n"
            )
        ));

        $invoiceDetail = file_get_contents($this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId') . '/invoices/' . $json_param['invoiceId'], false, $context);
        $invoiceDetail = json_decode($invoiceDetail, true);

        // P0 修复：服务端回查后必须验证发票状态——webhook 类型可以被误配置，
        // 发票实际状态才是唯一可信来源
        if (($invoiceDetail['status'] ?? '') !== 'Settled') {
            \Illuminate\Support\Facades\Log::warning('btcpay notify: invoice not settled', [
                'status' => $invoiceDetail['status'] ?? 'unknown',
                'invoiceId' => $json_param['invoiceId'] ?? '',
            ]);
            return false;
        }

        $out_trade_no = $invoiceDetail['metadata']["orderId"];
        $pay_trade_no = $json_param['invoiceId'];

        // 金额校验：settled 金额与订单金额比对（BTCPay 支持部分支付后标记 settled
        // 的边缘场景），容差 0.01 元
        $settledAmount = (float)($invoiceDetail['amount'] ?? 0);
        $order = \App\Models\Order::where('trade_no', $out_trade_no)->first();
        if ($order && abs($settledAmount * 100 - $order->total_amount) > 1) {
            \Illuminate\Support\Facades\Log::error('btcpay notify: amount mismatch', [
                'settled' => $settledAmount,
                'expected_fen' => $order->total_amount,
            ]);
            return false;
        }

        return [
            'trade_no' => $out_trade_no,
            'callback_no' => $pay_trade_no,
            'amount' => $settledAmount * 100,
        ];
    }

    private function curlPost($url, $params = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('Authorization:' . 'token ' . $this->getConfig('btcpay_api_key'), 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function hashEqual($str1, $str2)
    {
        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
} 