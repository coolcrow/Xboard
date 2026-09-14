<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::get('/', function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        $requestHost = $request->getHost();
        $configHost = parse_url(admin_setting('app_url'), PHP_URL_HOST);
        
        if ($requestHost !== $configHost) {
            abort(403);
        }
    }

    $theme = admin_setting('frontend_theme', 'Xboard');
    $themeService = new ThemeService();

    try {
        if (!$themeService->exists($theme)) {
            if ($theme !== 'Xboard') {
                Log::warning('Theme not found, switching to default theme', ['theme' => $theme]);
                $theme = 'Xboard';
                admin_setting(['frontend_theme' => $theme]);
            }
            $themeService->switch($theme);
        }

        if (!$themeService->getThemeViewPath($theme)) {
            throw new Exception('主题视图文件不存在');
        }

        $publicThemePath = public_path('theme/' . $theme);
        if (!File::exists($publicThemePath)) {
            $themePath = $themeService->getThemePath($theme);
            if (!$themePath || !File::copyDirectory($themePath, $publicThemePath)) {
                throw new Exception('主题初始化失败');
            }
            Log::info('Theme initialized in public directory', ['theme' => $theme]);
        }

        // SPA 运行时配置：域名分流 + 管理路径。
        // admin_path/admin_domain 仅在管理域主机头下输出——用户域页面源码不暴露
        // 管理入口（未配置 admin_domain 时视为同域单部署，按 app_url 主机判定）；
        // version 不再输出（原为日期+commit SHA，公开仓库下可关联开发者身份/部署节奏）
        $userDomain = (string) admin_setting('user_domain', '');
        $adminDomain = (string) admin_setting('admin_domain', '');
        $host = $request->getHost();
        $configHost = (string) (parse_url((string) admin_setting('app_url', ''), PHP_URL_HOST) ?: '');
        $adminHost = $adminDomain !== '' ? (parse_url($adminDomain, PHP_URL_HOST) ?: $adminDomain) : $configHost;
        $runtime = ['user_domain' => $userDomain];
        if ($adminHost === '' || strcasecmp($host, $adminHost) === 0) {
            $runtime['admin_domain'] = $adminHost;
            $runtime['admin_path'] = (string) admin_setting(
                'secure_path',
                admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
            );
        }

        // 设置层存取 JSON 会得到数组（通用配置表单往返），统一规整为 JSON 字符串
        $telemetryRaw = admin_setting('landing_telemetry', '');
        $telemetryJson = is_array($telemetryRaw)
            ? (string) json_encode($telemetryRaw, JSON_UNESCAPED_UNICODE)
            : (string) $telemetryRaw;

        $renderParams = [
            'title' => admin_setting('app_name', 'Xboard'),
            'theme' => $theme,
            'version' => '',
            'description' => (string) admin_setting('app_description', ''),
            'logo' => admin_setting('logo'),
            'theme_config' => $themeService->getConfig($theme),
            'admin_brand' => (string) admin_setting('admin_brand', 'AIBolt Ops'),
            'docs_center' => (int) admin_setting('frontend_docs_center', 0) ? 1 : 0,
            'landing_telemetry' => $telemetryJson,
            'runtime_config' => json_encode(array_filter($runtime, fn ($v) => $v !== ''), JSON_UNESCAPED_SLASHES)
        ];
        return view('theme::' . $theme . '.dashboard', $renderParams);
    } catch (Exception $e) {
        Log::error('Theme rendering failed', [
            'theme' => $theme,
            'error' => $e->getMessage()
        ]);
        abort(500, '主题加载失败');
    }
});

//TODO:: 兼容
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => '',
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware(['client', 'throttle:120,1']) // P2：订阅限流防暴力枚举/DoS
    ->name('client.subscribe');