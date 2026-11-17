<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Commands\MigrateDataCommand;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * A command that runs twice in one process (a queue worker, a scheduler, a
 * test) reads the Bitrix database it is told to the second time too.
 */
final class RunTwiceTest extends SyntheticTestCase
{
    public function test_the_second_run_connects_to_the_database_it_is_told_to(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--dry-run' => true, '--no-interaction' => true])
            ->expectsOutput('IBlock: News (demo_news)')
            ->assertSuccessful();

        $missing = $this->workDir.'/no-such-dir/bitrix.sqlite';
        config(['database.connections.gone' => ['driver' => 'sqlite', 'database' => $missing, 'prefix' => '']]);

        $this->artisan('atrium:bitrix:generate:migrations', ['--connection' => 'gone', '--dry-run' => true, '--no-interaction' => true])
            ->expectsOutputToContain('Cannot connect to Bitrix database')
            ->doesntExpectOutput('IBlock: News (demo_news)')
            ->assertFailed();
    }

    public function test_the_second_run_sees_what_changed_in_bitrix_in_between(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--dry-run' => true, '--no-interaction' => true])
            ->expectsOutput('IBlock: News (demo_news)')
            ->assertSuccessful();

        $this->bitrix()->table('b_iblock')->where('ID', 1)->update(['NAME' => 'Articles']);

        $this->artisan('atrium:bitrix:generate:migrations', ['--dry-run' => true, '--no-interaction' => true])
            ->expectsOutput('IBlock: Articles (demo_news)')
            ->doesntExpectOutput('IBlock: News (demo_news)')
            ->assertSuccessful();
    }

    public function test_the_second_run_reads_the_columns_of_the_tables_as_they_are_now(): void
    {
        $this->generateSchema();
        $command = $this->app->make(MigrateDataCommand::class);
        (function (): void {
            $this->jsonColumnsCache = ['products' => ['name']];
        })->call($command);
        $this->app[Kernel::class]->registerCommand($command);

        $this->artisan('atrium:bitrix:migrate:data', ['--iblock' => ['2'], '--hlblock' => ['1'], '--force' => true])->assertSuccessful();

        $this->assertSame('Laptop Pro', DB::table('products')->where('bitrix_id', 1)->value('name'), 'a text column is not JSON');
    }
}
