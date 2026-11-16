<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

class MigrateAllCommand extends AbstractBitrixCommand
{
    protected $signature = 'atrium:bitrix:migrate
                            {--connection= : Database connection name}
                            {--iblock=* : Specific IBlock IDs to process}
                            {--hlblock=* : Specific Highload Block IDs to process}
                            {--skip-models : Skip model generation}
                            {--skip-migrations : Skip migration generation}
                            {--skip-data : Skip data migration}
                            {--skip-files : Skip file migration}
                            {--chunk=100 : Number of records to process at once}
                            {--force : Run without confirmation}';

    protected $description = 'Full migration from Bitrix to Laravel (analyze, generate models, migrations, migrate data)';

    public function handle(): int
    {
        $this->info('╔════════════════════════════════════════╗');
        $this->info('║     Bitrix to Laravel Migration        ║');
        $this->info('╚════════════════════════════════════════╝');
        $this->newLine();

        if (! $this->verifyConnection()) {
            return self::FAILURE;
        }

        // Confirm full migration
        if (! $this->confirmAction('This will perform a full migration from Bitrix. Continue?')) {
            $this->info('Migration cancelled.');

            return self::SUCCESS;
        }

        $iblockOption = $this->option('iblock');
        $hlblockOption = $this->option('hlblock');
        $force = $this->option('force');

        // Step 1: Analyze
        $this->newLine();
        $this->info('Step 1: Analyzing Bitrix database...');
        $this->call('atrium:bitrix:analyze', [
            '--connection' => $this->option('connection'),
            '--iblock' => $iblockOption,
            '--hlblock' => $hlblockOption,
        ]);

        // Sites with their folders and domains: they do not depend on iblocks.
        $this->newLine();
        $this->info('Moving sites...');
        $this->call('atrium:bitrix:migrate:sites', ['--connection' => $this->option('connection')]);

        // Get selection if not provided
        $iblockIds = $iblockOption ?: $this->selectIBlocks();
        $hlblockIds = $hlblockOption ?: $this->selectHlblocks();

        // Step 2: Generate migrations
        if (! $this->option('skip-migrations')) {
            $this->newLine();
            $this->info('Step 2: Generating migrations...');
            $this->call('atrium:bitrix:generate:migrations', [
                '--connection' => $this->option('connection'),
                '--iblock' => $iblockIds,
                '--hlblock' => $hlblockIds,
                '--force' => $force,
            ]);

            // Run migrations
            if ($this->confirmAction('Run the generated migrations?')) {
                $this->call('migrate');
            }
        }

        // Step 3: Generate models
        if (! $this->option('skip-models')) {
            $this->newLine();
            $this->info('Step 3: Generating Eloquent models...');
            $this->call('atrium:bitrix:generate:models', [
                '--connection' => $this->option('connection'),
                '--iblock' => $iblockIds,
                '--hlblock' => $hlblockIds,
                '--force' => $force,
            ]);
        }

        // Step 4: Migrate data
        if (! $this->option('skip-data')) {
            $this->newLine();
            $this->info('Step 4: Migrating data...');

            if ($this->confirmAction('Migrate data from Bitrix?')) {
                $this->call('atrium:bitrix:migrate:data', [
                    '--connection' => $this->option('connection'),
                    '--iblock' => $iblockIds,
                    '--hlblock' => $hlblockIds,
                    '--chunk' => $this->option('chunk'),
                    '--skip-files' => $this->option('skip-files'),
                    '--force' => $force,
                ]);
            }
        }

        $this->newLine();
        $this->info('╔════════════════════════════════════════╗');
        $this->info('║     Migration complete!                ║');
        $this->info('╚════════════════════════════════════════╝');
        $this->newLine();

        // Summary
        $this->info('Summary:');
        $this->line('  - Models generated in: app/Models/');
        $this->line('  - References generated in: app/Models/References/');
        $this->line('  - Migrations generated in: database/migrations/');
        $this->newLine();

        $this->info('Next steps:');
        $this->line('  1. Review generated models and migrations');
        $this->line('  2. Configure admin panel for your models');
        $this->line('  3. Set up file storage if needed');
        $this->line('  4. Configure SEO and slugs');

        return self::SUCCESS;
    }
}
