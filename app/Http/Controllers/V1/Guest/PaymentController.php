<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                // P2：支付验证失败告警——签名不匹配/状态不对/金额不符可能是攻击
                $this->alertPaymentFailure("支付回调验证失败: method={$method} uuid={$uuid} ip={$request->ip()}");
                return $this->fail([422, 'verify error']);
            }
            HookManager::call('payment.notify.verified', $verify);
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            $this->alertPaymentFailure("支付回调订单不存在: trade_no={$tradeNo} callback_no={$callbackNo}");
            return $this->fail([400202, 'order is not found']);
        }
        if ($order->status !== Order::STATUS_PENDING)
            return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            $this->alertPaymentFailure("订单激活失败: trade_no={$tradeNo} amount={$order->total_amount}");
            return false;
        }

        HookManager::call('payment.notify.success', $order);
        return true;
    }

    /**
     * P2：支付失败告警——复用机器告警的通知通道（Telegram / 邮件）。
     * 支付验证失败可能代表攻击（伪造签名/金额篡改），激活失败影响用户。
     */
    private function alertPaymentFailure(string $message): void
    {
        try {
            Log::warning('[payment-alert] ' . $message);

            $chatId = (string) admin_setting('machine_alert_telegram_chat_id', '');
            if ($chatId !== '') {
                app(\App\Services\TelegramService::class)->sendMessage(
                    (int) $chatId,
                    '⚠️ ' . $message
                );
            }

            $email = (string) admin_setting('machine_alert_email', admin_setting('smtp_username', ''));
            if ($email) {
                \App\Jobs\SendEmailJob::dispatch([
                    'email' => $email,
                    'subject' => '[Xboard] 支付告警',
                    'template_name' => 'generic',
                    'template_value' => ['content' => $message],
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('payment alert failed: ' . $e->getMessage());
        }
    }
}
