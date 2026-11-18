<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Admin\Models\Site;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * migrate: every step in a row, each one skippable or declinable.
 */
final class MigrateAllCommandTest extends SyntheticTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // "migrate" runs the default migration paths; the generated ones live here.
        $this->app->make('migrator')->path($this->workDir.'/migrations');
    }

    public function test_without_configured_folders_the_summary_names_the_default_ones(): void
    {
        config(['atrium-bitrix.model_namespace' => 'App\\Models', 'atrium-bitrix.reference_namespace' => 'App\\Models\\References']);
        config(['atrium-bitrix.model_path' => null, 'atrium-bitrix.reference_path' => null, 'atrium-bitrix.migration_path' => null]);

        $this->artisan('atrium:bitrix:migrate', ['--force' => true, '--skip-models' => true, '--skip-migrations' => true, '--skip-data' => true, '--skip-files' => true, '--iblock' => [1], '--hlblock' => [1]])
            ->expectsOutput('  - Models generated in: '.app_path('Models'))
            ->expectsOutput('  - References generated in: '.app_path('Models/References'))
            ->expectsOutput('  - Migrations generated in: '.database_path('migrations'))
            ->assertSuccessful();
    }

    public function test_a_forced_run_goes_through_every_step(): void
    {
        $this->artisan('atrium:bitrix:migrate', ['--force' => true, '--skip-files' => true, '--no-interaction' => true])
            ->expectsOutput('║     Bitrix to Laravel Migration        ║')
            ->expectsOutput('Step 1: Analyzing Bitrix database...')
            ->expectsOutput('Moving sites...')
            ->expectsOutput('Step 2: Generating migrations...')
            ->expectsOutput('Step 3: Generating Eloquent models...')
            ->expectsOutput('Step 4: Migrating data...')
            ->expectsOutput('║     Migration complete!                ║')
            ->expectsOutput('  - Models generated in: '.$this->workDir.'/models')
            ->expectsOutput('  - References generated in: '.$this->workDir.'/models/References')
            ->expectsOutput('  - Migrations generated in: '.$this->workDir.'/migrations')
            ->expectsOutput('  4. Configure SEO and slugs')
            ->assertSuccessful();

        $this->assertTrue(Schema::hasTable('products'));
        $this->assertSame(['Laptop Air', 'Laptop Pro', 'Office Suite'], DB::table('products')->orderBy('name')->pluck('name')->all());
        $this->assertSame(['Contoso', 'Northwind'], DB::table('brands')->orderBy('name')->pluck('name')->all());
        $this->assertContains('Product.php', $this->generatedFiles('models'));
        $this->assertSame('s2', Site::query()->where('code', 's2')->value('code'));
    }

    public function test_the_whole_report_is_printed_in_order_with_blank_lines_between_its_parts(): void
    {
        $expected = <<<'TXT'
        ╔════════════════════════════════════════╗
        ║     Bitrix to Laravel Migration        ║
        ╚════════════════════════════════════════╝


        Step 1: Analyzing Bitrix database...
        Bitrix Database Analyzer
        ========================

        Analyzing IBlocks...
        +----+-----------+------+---------+-----+-------+----------+----------+-------+-----------+
        | ID | Code      | Name | Type    | Ver | Props | Sections | Elements | Files | Relations |
        +----+-----------+------+---------+-----+-------+----------+----------+-------+-----------+
        | 1  | demo_news | News | content | 1   | 2     | 0        | 2        | No    | 1         |
        +----+-----------+------+---------+-----+-------+----------+----------+-------+-----------+

        Analyzing Highload Blocks...
        +----+--------+---------------+--------+------+-------+
        | ID | Name   | Table         | Fields | Rows | Files |
        +----+--------+---------------+--------+------+-------+
        | 1  | Brands | b_hlbd_brands | 3      | 2    | No    |
        +----+--------+---------------+--------+------+-------+


        Moving sites...
        +------------+------+---------+---------+------------------------------+
        | Bitrix LID | Code | DIR     | Folder  | Domains                      |
        +------------+------+---------+---------+------------------------------+
        | s1         | main | /       | /       | example.com, www.example.com |
        | s2         | s2   | /en/    | /en/    | example.com, www.example.com |
        | s3         | s3   | /site2/ | /site2/ | —                            |
        +------------+------+---------+---------+------------------------------+

        ╔════════════════════════════════════════╗
        ║     Migration complete!                ║
        ╚════════════════════════════════════════╝

        Summary:
          - Models generated in: WORK/models
          - References generated in: WORK/models/References
          - Migrations generated in: WORK/migrations

        Next steps:
          1. Review generated models and migrations
          2. Configure admin panel for your models
          3. Set up file storage if needed
          4. Configure SEO and slugs

        TXT;

        Artisan::call('atrium:bitrix:migrate', [
            '--force' => true, '--skip-models' => true, '--skip-migrations' => true, '--skip-data' => true,
            '--iblock' => [1], '--hlblock' => [1],
        ]);

        $this->assertSame($expected, str_replace($this->workDir, 'WORK', Artisan::output()));
    }

    public function test_the_chunk_size_and_the_file_switch_reach_the_data_step(): void
    {
        $seen = [];
        $this->app->make(Kernel::class)->registerCommand(new class($seen) extends Command
        {
            protected $signature = 'atrium:bitrix:migrate:data {--connection=} {--iblock=*} {--hlblock=*} {--chunk=} {--skip-files} {--force}';

            /** @param array<int, array<string, mixed>> $seen */
            public function __construct(private array &$seen)
            {
                parent::__construct();
            }

            public function handle(): int
            {
                $this->seen[] = $this->options();

                return self::SUCCESS;
            }
        });

        $this->artisan('atrium:bitrix:migrate', ['--force' => true, '--skip-migrations' => true, '--skip-models' => true, '--chunk' => '7', '--skip-files' => true, '--iblock' => [1], '--hlblock' => [1]])->assertSuccessful();
        $this->artisan('atrium:bitrix:migrate', ['--force' => true, '--skip-migrations' => true, '--skip-models' => true, '--iblock' => [1], '--hlblock' => [1]])->assertSuccessful();

        $this->assertCount(2, $seen);
        $this->assertSame('7', $seen[0]['chunk']);
        $this->assertTrue($seen[0]['skip-files']);
        $this->assertSame('100', $seen[1]['chunk']);
        $this->assertFalse($seen[1]['skip-files']);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function steps(): array
    {
        return [
            'migrations' => ['Step 2: Generating migrations...', ['--skip-models', '--skip-data']],
            'models' => ['Step 3: Generating Eloquent models...', ['--skip-migrations', '--skip-data']],
            'data' => ['Step 4: Migrating data...', ['--skip-migrations', '--skip-models']],
        ];
    }

    /**
     * @param  list<string>  $skipped
     */
    #[DataProvider('steps')]
    public function test_each_step_starts_after_a_blank_line(string $heading, array $skipped): void
    {
        Artisan::call('atrium:bitrix:migrate', [
            '--force' => true, '--skip-files' => true, '--iblock' => [1], '--hlblock' => [1],
            ...array_fill_keys($skipped, true),
        ]);

        $this->assertStringContainsString("+\n\n{$heading}\n", Artisan::output());
    }

    public function test_declining_the_first_question_does_nothing(): void
    {
        $this->artisan('atrium:bitrix:migrate')
            ->expectsConfirmation('This will perform a full migration from Bitrix. Continue?', 'no')
            ->expectsOutput('Migration cancelled.')
            ->doesntExpectOutput('Step 1: Analyzing Bitrix database...')
            ->assertSuccessful();

        $this->assertSame([], $this->generatedFiles('migrations'));
        $this->assertNull(Site::query()->where('code', 's2')->first());
    }

    public function test_declined_steps_leave_the_database_alone(): void
    {
        $this->artisan('atrium:bitrix:migrate', ['--iblock' => [1], '--hlblock' => [1]])
            ->expectsConfirmation('This will perform a full migration from Bitrix. Continue?', 'yes')
            ->expectsConfirmation('Run the generated migrations?', 'no')
            ->expectsConfirmation('Migrate data from Bitrix?', 'no')
            ->doesntExpectOutputToContain('Enter IBlock IDs')
            ->expectsOutput('Step 4: Migrating data...')
            ->expectsOutput('║     Migration complete!                ║')
            ->assertSuccessful();

        $this->assertNotSame([], $this->generatedFiles('migrations'));
        $this->assertSame(['News.php', 'References/Brands.php'], $this->generatedFiles('models'));
        $this->assertFalse(Schema::hasTable('news'));
    }

    public function test_skipped_steps_are_not_run_and_the_blocks_are_asked_for(): void
    {
        $this->artisan('atrium:bitrix:migrate', ['--skip-migrations' => true, '--skip-models' => true, '--skip-data' => true])
            ->expectsConfirmation('This will perform a full migration from Bitrix. Continue?', 'yes')
            ->expectsOutput('Step 1: Analyzing Bitrix database...')
            ->expectsQuestion('Enter IBlock IDs to process (comma-separated, or "all" for all)', '1')
            ->expectsQuestion('Enter Highload Block IDs to process (comma-separated, or "all" for all)', 'all')
            ->doesntExpectOutput('Step 2: Generating migrations...')
            ->doesntExpectOutput('Step 3: Generating Eloquent models...')
            ->doesntExpectOutput('Step 4: Migrating data...')
            ->expectsOutput('║     Migration complete!                ║')
            ->assertSuccessful();

        $this->assertSame([], $this->generatedFiles('migrations'));
        $this->assertSame([], $this->generatedFiles('models'));
        $this->assertSame('s2', Site::query()->where('code', 's2')->value('code'), 'sites move whatever is skipped');
    }

    public function test_the_named_connection_is_passed_to_every_step(): void
    {
        config(['database.connections.old' => config('database.connections.bitrix')]);
        DB::connection('old')->setPdo($this->bitrix()->getPdo());
        config(['database.connections.bitrix.database' => $this->workDir.'/no-such-dir/bitrix.sqlite']);
        DB::purge('bitrix');

        $this->artisan('atrium:bitrix:migrate', ['--connection' => 'old', '--force' => true, '--skip-files' => true, '--iblock' => [2], '--hlblock' => [1]])
            ->doesntExpectOutputToContain('Cannot connect')
            ->assertSuccessful();

        $this->assertSame(3, DB::table('products')->count());
    }
}
