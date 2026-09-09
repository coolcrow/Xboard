<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AuthService;
use Auth;
use Closure;
use Illuminate\Support\Facades\Cache;

class User
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!Auth::guard('sanctum')->check()) {
            throw new ApiException('未登录或登陆已过期', 403);
        }
        // P1：封禁复查——token 有效期最长一年，封禁后旧 token 此前继续可用
        $user = Auth::guard('sanctum')->user();
        if ($user && $user->banned) {
            $user->tokens()->delete();
            throw new ApiException('账户已被封禁', 403);
        }
        return $next($request);
    }
}
