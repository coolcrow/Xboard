<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\HookManager;

class PaymentService
{
    public $method;
    protected $config;
    protected $payment;
    protected $pluginManager;
    protected $class;

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        $this->method = $method;
        $this->pluginManager = app(PluginManager::class);

        if ($method === 'temp') {
            return;
        }

        if ($id) {
            $paymentModel = Payment::find($id);
            if (!$paymentModel) {
                throw new ApiException('payment not found');
            }
            $payment = $paymentModel->makeVisible('config')->toArray();
        }
        if ($uuid) {
            $paymentModel = Payment::where('uuid', $uuid)->first();
            if (!$paymentModel) {
                throw new ApiException('payment not found');
            }
            $payment = $paymentModel->makeVisible('config')->toArray();
        }

        $this->config = [];
        if (isset($payment)) {
            $this->config = is_string($payment['config']) ? json_decode($payment['config'], true) : $payment['config'];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'] ?? '';
        }

        // 直接匹配 payment method → 已启用插件的 pluginCode（归一化：小写 + 去下划线/连字符）
        // （旧实现依赖 available_payment_methods hook：hook 注册的是显示名如
        // 'AlipayF2F'，与 v2_payment.payment 存的插件码 'alipay_f2f' 命名不一致
        // 导致空匹配；旧 fallback `new $this->class` 又因 $class 从未赋值必然
        // fatal。归一化匹配使两套命名可同时工作）
        $paymentPlugins = $this->pluginManager->getEnabledPaymentPlugins();
        // 归一化冲突防护：两个插件码归一后相同会导致配置错接到另一支付通道（资金面）
        $seen = [];
        foreach ($paymentPlugins as $plugin) {
            $normalized = $this->normalizeMethod($plugin->getPluginCode());
            if (isset($seen[$normalized])) {
                throw new ApiException('payment plugin code collision after normalization: ' . $plugin->getPluginCode() . ' vs ' . $seen[$normalized]);
            }
            $seen[$normalized] = $plugin->getPluginCode();
        }
        $target = $this->normalizeMethod($this->method);
        foreach ($paymentPlugins as $plugin) {
            if ($this->normalizeMethod($plugin->getPluginCode()) === $target) {
                $plugin->setConfig($this->config);
                $this->payment = $plugin;
                return;
            }
        }

        throw new ApiException('payment method not available: ' . $this->method);
    }

    /**
     * 归一化支付方式名：小写并去掉下划线/连字符，
     * 使 'AlipayF2F'（hook 显示名）与 'alipay_f2f'（插件码）可互相匹配。
     */
    private function normalizeMethod(string $method): string
    {
        return str_replace(['_', '-'], '', strtolower($method));
    }

    public function notify($params)
    {
        if (!$this->config['enable'])
            throw new ApiException('gate is not enable');
        return $this->payment->notify($params);
    }

    public function pay($order)
    {
        // custom notify domain name
        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}");
        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => source_base_url('/#/order/' . $order['trade_no']),
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    public function form()
    {
        $form = $this->payment->form();
        $result = [];
        foreach ($form as $key => $field) {
            $result[$key] = [
                'type' => $field['type'],
                'label' => $field['label'] ?? '',
                'placeholder' => $field['placeholder'] ?? '',
                'description' => $field['description'] ?? '',
                'value' => $this->config[$key] ?? $field['default'] ?? '',
                'options' => $field['select_options'] ?? $field['options'] ?? []
            ];
        }
        return $result;
    }

    /**
     * 获取所有可用的支付方式
     */
    public function getAvailablePaymentMethods(): array
    {
        $methods = [];

        $methods = HookManager::filter('available_payment_methods', $methods);

        return $methods;
    }

    /**
     * 获取所有支付方式名称列表（用于管理后台）
     */
    public static function getAllPaymentMethodNames(): array
    {
        $pluginManager = app(PluginManager::class);
        $pluginManager->initializeEnabledPlugins();

        $instance = new self('temp');
        $methods = $instance->getAvailablePaymentMethods();

        return array_keys($methods);
    }
}
