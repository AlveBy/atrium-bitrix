<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * --iblock=2 on the command line reaches the command as the string "2".
 */
final class CliIdsTest extends SyntheticTestCase
{
    public function test_generate_migrations_takes_ids_from_the_command_line(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--dry-run' => true, '--iblock' => ['1'], '--hlblock' => ['1']])
            ->expectsOutput('IBlock: News (demo_news)')
            ->expectsOutput('Highload Block: Brands')
            ->doesntExpectOutput('IBlock: Products (demo_products)')
            ->assertSuccessful();
    }

    public function test_generate_models_takes_ids_from_the_command_line(): void
    {
        $this->artisan('atrium:bitrix:generate:models', ['--dry-run' => true, '--iblock' => ['1'], '--hlblock' => ['1']])
            ->expectsOutput('  Would create Element model: News')
            ->doesntExpectOutputToContain('Product')
            ->assertSuccessful();
    }

    public function test_migrate_data_takes_ids_from_the_command_line(): void
    {
        $this->generateSchema();

        $this->artisan('atrium:bitrix:migrate:data', ['--iblock' => ['2'], '--hlblock' => ['1'], '--force' => true])
            ->expectsOutput('Migrating elements: Products -> products')
            ->assertSuccessful();

        $this->assertSame(3, DB::table('products')->count());
        $this->assertSame(0, DB::table('news')->count());
    }

    public function test_verify_takes_ids_from_the_command_line(): void
    {
        $this->generateSchema();
        $this->artisan('atrium:bitrix:migrate:data', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->artisan('atrium:bitrix:verify', ['--iblock' => ['2'], '--hlblock' => ['1']])
            ->expectsOutputToContain('products')
            ->doesntExpectOutputToContain('news')
            ->assertSuccessful();
    }

    public function test_the_full_migration_passes_ids_from_the_command_line_on(): void
    {
        $this->artisan('atrium:bitrix:migrate', ['--iblock' => ['1'], '--hlblock' => ['1']])
            ->expectsConfirmation('This will perform a full migration from Bitrix. Continue?', 'yes')
            ->expectsConfirmation('Run the generated migrations?', 'no')
            ->expectsConfirmation('Migrate data from Bitrix?', 'no')
            ->assertSuccessful();

        $this->assertSame(['News.php', 'References/Brands.php'], $this->generatedFiles('models'));
        $this->assertFalse(Schema::hasTable('news'));
    }
}
