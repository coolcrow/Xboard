<?php

namespace App\Providers;

use App\Models\Plugin;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\WorkerStarting;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PluginManager::class, function ($app) {
            return new PluginManager();
        });

        // Plugin\ 命名空间的 PSR-4 回退：composer 只映射 plugins/（用户插件目录，
        // 常被空 bind-mount 遮蔽镜像内容——2026-10 支付回调静默断裂事故根因）。
        // 核心插件随镜像发布在 plugins-core/，在此补一条运行时映射保证库类可解析。
        spl_autoload_register(function (string $class): void {
            if (!str_starts_with($class, 'Plugin\\')) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, strlen('Plugin\\'))) . '.php';
            foreach (['plugins', 'plugins-core'] as $dir) {
                $file = base_path($dir) . '/' . $relative;
                if (is_file($file)) {
                    require_once $file;
                    return;
                }
            }
        });
    }

    public function boot(): void
    {
        foreach (['plugins', 'plugins-core'] as $dir) {
            $path = base_path($dir);
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }
        }
    }
}