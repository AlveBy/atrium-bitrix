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
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/atrium-bitrix.php' => config_path('atrium-bitrix.php'),
        ], 'atrium-bitrix-config');

        $this->commands([
            Commands\AnalyzeBitrixCommand::class,
            Commands\DownloadUploadCommand::class,
            Commands\GenerateMigrationsCommand::class,
            Commands\GenerateModelsCommand::class,
            Commands\MigrateAllCommand::class,
            Commands\MigrateDataCommand::class,
            Commands\MigrateMediaLibraryCommand::class,
            Commands\MigrateSeoCommand::class,
            Commands\MigrateSitesCommand::class,
            Commands\ParityAuditCommand::class,
            Commands\SyncDataCommand::class,
            Commands\VerifyDataCommand::class,
        ]);
    }
}
