<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every command that reads Bitrix stops with directions when the database
 * cannot be reached, and reads the connection --connection names.
 */
final class ConnectionTest extends SyntheticTestCase
{
    /**
     * @return iterable<string,array{string}>
     */
    public static function commands(): iterable
    {
        yield 'analyze' => ['atrium:bitrix:analyze'];
        yield 'generate migrations' => ['atrium:bitrix:generate:migrations'];
        yield 'generate models' => ['atrium:bitrix:generate:models'];
        yield 'migrate everything' => ['atrium:bitrix:migrate'];
        yield 'migrate data' => ['atrium:bitrix:migrate:data'];
        yield 'migrate media library' => ['atrium:bitrix:migrate:medialibrary'];
        yield 'migrate seo' => ['atrium:bitrix:migrate:seo'];
        yield 'migrate sites' => ['atrium:bitrix:migrate:sites'];
        yield 'sync' => ['atrium:bitrix:sync'];
        yield 'verify' => ['atrium:bitrix:verify'];
    }

    #[DataProvider('commands')]
    public function test_an_unreachable_database_stops_the_command_with_directions(string $command): void
    {
        $missing = $this->workDir.'/no-such-dir/bitrix.sqlite';
        config(['database.connections.gone' => ['driver' => 'sqlite', 'database' => $missing, 'prefix' => '']]);

        $this->artisan($command, ['--connection' => 'gone', '--no-interaction' => true])
            ->expectsOutputToContain("Cannot connect to Bitrix database: Database file at path [{$missing}]")
            ->expectsOutput('Please check your database configuration in config/database.php')
            ->expectsOutput('Make sure you have configured the "bitrix" connection:')
            ->expectsOutput("'bitrix' => [")
            ->expectsOutput("    'driver' => 'mysql',")
            ->expectsOutput("    'host' => env('BITRIX_DB_HOST', '127.0.0.1'),")
            ->expectsOutput("    'database' => env('BITRIX_DB_DATABASE'),")
            ->expectsOutput("    'username' => env('BITRIX_DB_USERNAME'),")
            ->expectsOutput("    'password' => env('BITRIX_DB_PASSWORD'),")
            ->expectsOutput('],')
            ->assertFailed();
    }

    public function test_the_connection_named_on_the_command_line_is_read(): void
    {
        // Same database under another name; the default "bitrix" one is emptied.
        config(['database.connections.old' => config('database.connections.bitrix')]);
        DB::connection('old')->setPdo($this->bitrix()->getPdo());
        config(['database.connections.bitrix.database' => $this->workDir.'/no-such-dir/bitrix.sqlite']);
        DB::purge('bitrix');

        $this->artisan('atrium:bitrix:generate:migrations', ['--connection' => 'old', '--dry-run' => true, '--no-interaction' => true])
            ->doesntExpectOutputToContain('Cannot connect')
            ->expectsOutput('IBlock: News (demo_news)')
            ->assertSuccessful();
    }
}
