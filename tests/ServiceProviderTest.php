<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests;

use Atrium\Bitrix\BitrixServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

final class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertIsArray(config('atrium-bitrix'));
    }

    public function test_in_the_console_the_commands_and_the_config_are_offered(): void
    {
        $this->assertArrayHasKey('atrium:bitrix:parity', Artisan::all());
        $this->assertArrayHasKey('atrium:bitrix:sync', Artisan::all());
        $this->assertSame(
            [dirname(__DIR__).'/src/../config/atrium-bitrix.php' => config_path('atrium-bitrix.php')],
            ServiceProvider::pathsToPublish(BitrixServiceProvider::class, 'atrium-bitrix-config'),
        );
    }

    public function test_outside_the_console_nothing_is_published(): void
    {
        $publishes = \Closure::bind(static fn (): array => [self::$publishes, self::$publishGroups], null, ServiceProvider::class);
        $restore = \Closure::bind(static function (array $saved): void {
            [static::$publishes, static::$publishGroups] = $saved;
        }, null, ServiceProvider::class);
        $saved = $publishes();
        $restore([[], []]);

        try {
            $app = new Application($this->app->basePath());
            (fn () => $this->isRunningInConsole = false)->call($app);
            (new BitrixServiceProvider($app))->boot();

            $this->assertSame([], ServiceProvider::pathsToPublish(BitrixServiceProvider::class, 'atrium-bitrix-config'));

            (fn () => $this->isRunningInConsole = true)->call($app);
            (new BitrixServiceProvider($app))->boot();

            $this->assertCount(1, ServiceProvider::pathsToPublish(BitrixServiceProvider::class, 'atrium-bitrix-config'));
        } finally {
            $restore($saved);
        }
    }
}
