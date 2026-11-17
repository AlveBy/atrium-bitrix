<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests;

use Atrium\Admin\AtriumServiceProvider;
use Atrium\Bitrix\BitrixServiceProvider;
use Atrium\Bitrix\Tests\Fixtures\SyntheticBitrix;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Tighten\Ziggy\ZiggyServiceProvider;

/**
 * The made-up Bitrix database on the "bitrix" connection, the Atrium panel
 * on the default one, and a scratch directory for what the generators write.
 */
abstract class SyntheticTestCase extends TestCase
{
    use RefreshDatabase;

    protected const NAMESPACE = 'Atrium\\Bitrix\\Tests\\Generated';

    protected string $workDir;

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            ZiggyServiceProvider::class,
            AtriumServiceProvider::class,
            BitrixServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->workDir = sys_get_temp_dir().'/atrium-bitrix-'.bin2hex(random_bytes(4));
        mkdir($this->workDir.'/models', 0777, true);
        mkdir($this->workDir.'/migrations', 0777, true);
        mkdir($this->workDir.'/public', 0777, true);

        $app->usePublicPath($this->workDir.'/public');

        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('app.locale', 'en');
        $target = env('DB_CONNECTION');
        $config->set('database.default', in_array($target, ['mysql', 'pgsql'], true) ? $target : 'testing');
        $config->set('database.connections.bitrix', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $config->set('atrium-bitrix.connection', 'bitrix');
        $config->set('atrium-bitrix.strip_prefixes', ['demo_']);
        $config->set('atrium-bitrix.model_namespace', self::NAMESPACE);
        $config->set('atrium-bitrix.reference_namespace', self::NAMESPACE.'\\References');
        $config->set('atrium-bitrix.model_path', $this->workDir.'/models');
        $config->set('atrium-bitrix.reference_path', $this->workDir.'/models/References');
        $config->set('atrium-bitrix.migration_path', $this->workDir.'/migrations');
        $config->set('atrium.auth.two_factor.enabled', false);
        $config->set('atrium.dashboard.timings.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        SyntheticBitrix::install($this->bitrix());

        // The generated classes live outside Composer's autoloader.
        spl_autoload_register(function (string $class): void {
            if (! str_starts_with($class, self::NAMESPACE.'\\')) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen(self::NAMESPACE) + 1));
            $file = $this->workDir.'/models/'.$relative.'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    protected function bitrix(): Connection
    {
        return DB::connection('bitrix');
    }

    /**
     * Migrations, then models: what the data commands need in place.
     */
    protected function generateSchema(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->artisan('migrate', ['--path' => $this->workDir.'/migrations', '--realpath' => true])->assertSuccessful();
        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    /**
     * The generated files under the scratch directory, relative to it.
     *
     * @return list<string>
     */
    protected function generatedFiles(string $dir): array
    {
        $files = [];

        foreach ((new Filesystem)->allFiles($this->workDir.'/'.$dir) as $file) {
            $files[] = $file->getRelativePathname();
        }

        sort($files);

        return $files;
    }
}
