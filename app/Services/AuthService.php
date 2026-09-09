<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(): array
    {
        // P2：token 命名按设备类型（用户可区分要撤销哪个会话）+ 有效期缩至 90 天
        $token = $this->user->createToken(
            $this->resolveDeviceName(),
            ['*'],
            now()->addDays(90)
        );

        // Format token: remove ID prefix and add Bearer
        $tokenParts = explode('|', $token->plainTextToken);
        $formattedToken = 'Bearer ' . ($tokenParts[1] ?? $tokenParts[0]);

        return [
            'token' => $this->user->token,
            'auth_data' => $formattedToken,
            'is_admin' => $this->user->is_admin,
        ];
    }

    public function getSessions(): array
    {
        // P2：不返回 token 哈希列（防泄露）
        return $this->user->tokens()
            ->select(['id', 'name', 'last_used_at', 'created_at', 'expires_at'])
            ->get()
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'device' => $t->name,
                    'last_used_at' => $t->last_used_at,
                    'created_at' => $t->created_at,
                    'expires_at' => $t->expires_at,
                ];
            })
            ->toArray();
    }

    private function resolveDeviceName(): string
    {
        $ua = strtolower(request()->userAgent() ?? '');
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad')) return 'iOS 设备';
        if (str_contains($ua, 'android')) return 'Android 设备';
        if (str_contains($ua, 'mac os')) return 'macOS 浏览器';
        if (str_contains($ua, 'windows')) return 'Windows 浏览器';
        if (str_contains($ua, 'linux')) return 'Linux 浏览器';
        return '未知设备';
    }

    public function removeSession(string $sessionId): bool
    {
        $this->user->tokens()->where('id', $sessionId)->delete();
        return true;
    }

    public function removeAllSessions(): bool
    {
        $this->user->tokens()->delete();
        return true;
    }

    public static function findUserByBearerToken(string $bearerToken): ?User
    {
        $token = str_replace('Bearer ', '', $bearerToken);
        
        $accessToken = PersonalAccessToken::findToken($token);
        
        $tokenable = $accessToken?->tokenable;
        
        return $tokenable instanceof User ? $tokenable : null;
    }

    /**
     * 解密认证数据
     *
     * @param string $authorization
     * @return array|null 用户数据或null
     */
    public static function decryptAuthData(string $authorization): ?array
    {
        $user = self::findUserByBearerToken($authorization);
        
        if (!$user) {
            return null;
        }
        
        return [
            'id' => $user->id,
            'email' => $user->email,
            'is_admin' => (bool)$user->is_admin,
            'is_staff' => (bool)$user->is_staff
        ];
    }
}
