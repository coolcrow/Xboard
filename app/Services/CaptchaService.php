<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use ReCaptcha\ReCaptcha;

class CaptchaService
{
    /**
     * 验证人机验证码
     *
     * @param Request $request 请求对象
     * @return array [是否通过, 错误消息]
     */
    public function verify(Request $request): array
    {
        if (!(int) admin_setting('captcha_enable', 0)) {
            return [true, null];
        }

        $captchaType = admin_setting('captcha_type', 'recaptcha');

        try {
            return match ($captchaType) {
                'turnstile' => $this->verifyTurnstile($request),
                'recaptcha-v3' => $this->verifyRecaptchaV3($request),
                'recaptcha' => $this->verifyRecaptcha($request),
                default => [false, [400, __('Invalid captcha type')]]
            };
        } catch (\Throwable $e) {
            // fail-closed：验证码是防撞库闸门，上游（Cloudflare/Google）不可达时显式拒绝
            // 而非放行——静默 fail-open 会在无人盯守时重开撞库窗口。带错误日志便于告警。
            \Illuminate\Support\Facades\Log::error('captcha verify failed: ' . $e->getMessage());
            return [false, [503, __('Captcha service is temporarily unavailable, please try again later')]];
        }
    }

    /**
     * 验证 Cloudflare Turnstile
     *
     * @param Request $request
     * @return array
     */
    private function verifyTurnstile(Request $request): array
    {
        $turnstileToken = $request->input('turnstile_token');
        if (!$turnstileToken) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        // 有界等待：Guzzle/Laravel 默认无总超时，上游抖动会无限占用登录 worker
        $response = Http::timeout(5)->retry(2, 100)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => admin_setting('turnstile_secret_key'),
            'response' => $turnstileToken,
            'remoteip' => $request->ip()
        ]);

        // 上游故障页可能返回非 JSON（null），避免 null 偏移访问
        $result = $response->json();
        if (!is_array($result) || empty($result['success'])) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        return [true, null];
    }

    /**
     * 验证 Google reCAPTCHA v3
     *
     * @param Request $request
     * @return array
     */
    private function verifyRecaptchaV3(Request $request): array
    {
        $recaptchaV3Token = $request->input('recaptcha_v3_token');
        if (!$recaptchaV3Token) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        $recaptcha = new ReCaptcha(admin_setting('recaptcha_v3_secret_key'));
        $recaptchaResp = $recaptcha->verify($recaptchaV3Token, $request->ip());

        if (!$recaptchaResp->isSuccess()) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        // 检查分数阈值（如果有的话）
        $score = $recaptchaResp->getScore();
        $threshold = admin_setting('recaptcha_v3_score_threshold', 0.5);
        if ($score < $threshold) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        return [true, null];
    }

    /**
     * 验证 Google reCAPTCHA v2
     *
     * @param Request $request
     * @return array
     */
    private function verifyRecaptcha(Request $request): array
    {
        $recaptchaData = $request->input('recaptcha_data');
        if (!$recaptchaData) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        $recaptcha = new ReCaptcha(admin_setting('recaptcha_key'));
        $recaptchaResp = $recaptcha->verify($recaptchaData);

        if (!$recaptchaResp->isSuccess()) {
            return [false, [400, __('Invalid code is incorrect')]];
        }

        return [true, null];
    }
} 