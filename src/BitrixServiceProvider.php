<?php

declare(strict_types=1);

namespace Atrium\Bitrix;

use Illuminate\Support\ServiceProvider;

final class BitrixServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atrium-bitrix.php', 'atrium-bitrix');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/atrium-bitrix.php' => config_path('atrium-bitrix.php'),
            ], 'atrium-bitrix-config');
        }
    }
}
