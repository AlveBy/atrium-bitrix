<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Bitrix\Generators\MigrationGenerator;
use Atrium\Bitrix\Mappers\PropertyTypeMapper;

class GenerateMigrationsCommand extends AbstractBitrixCommand
{
    protected $signature = 'atrium:bitrix:generate:migrations
                            {--connection= : Database connection name}
                            {--iblock=* : Specific IBlock IDs to process}
                            {--hlblock=* : Specific Highload Block IDs to process}
                            {--force : Overwrite existing files}
                            {--dry-run : Show what would be generated without writing files}';

    protected $description = 'Generate Laravel migrations from Bitrix structure';

    protected MigrationGenerator $generator;

    /**
     * Track already generated HLBlock IDs to avoid duplicates
     */
    protected array $generatedHlblockIds = [];

    public function handle(): int
    {
        $this->info('Bitrix Migration Generator');
        $this->info('==========================');
        $this->newLine();

        // Reset tracking for fresh run
        $this->generatedHlblockIds = [];

        if (! $this->verifyConnection()) {
            return self::FAILURE;
        }

        $this->initializeGenerator();

        $iblockIds = $this->idsOption('iblock');
        $hlblockIds = $this->idsOption('hlblock');
        $dryRun = $this->option('dry-run');

        // Process IBlocks
        $iblockIds = $iblockIds ?: $this->selectIBlocks();
        if (! empty($iblockIds)) {
            $this->generateIBlockMigrations($iblockIds, $dryRun);
        }

        // Process Highload Blocks
        $hlblockIds = $hlblockIds ?: $this->selectHlblocks();
        if (! empty($hlblockIds)) {
            $this->generateHlblockMigrations($hlblockIds, $dryRun);
        }

        $this->newLine();
        $this->info('Migration generation complete!');

        if (! $dryRun) {
            $this->newLine();
            $this->info('Run "php artisan migrate" to apply the migrations.');
        }

        return self::SUCCESS;
    }

    /**
     * Initialize generator
     */
    private function initializeGenerator(): void
    {
        $naming = $this->getNaming();
        $typeMapper = new PropertyTypeMapper;

        $this->generator = new MigrationGenerator($naming, $typeMapper, $this->getBitrixConnection()->getConnection());
    }

    /**
     * Generate migrations for IBlocks
     */
    private function generateIBlockMigrations(array $iblockIds, bool $dryRun): void
    {
        $analyzer = $this->getIBlockAnalyzer();

        // Pre-load all HL blocks so analyzeByTableName finds them in cache
        $this->getHlblockAnalyzer()->analyzeAll();

        // First, analyze all IBlocks to collect linked directories
        $iblocks = [];
        foreach ($iblockIds as $iblockId) {
            if ($this->shouldSkipIBlock($iblockId)) {
                $this->warn("Skipping IBlock {$iblockId} (in skip list)");

                continue;
            }

            $iblock = $analyzer->analyze($iblockId);
            if (! $iblock) {
                $this->warn("IBlock {$iblockId} not found");

                continue;
            }
            $iblocks[] = $iblock;
        }

        // Generate linked directory migrations FIRST (Highload Blocks referenced by IBlocks)
        $directories = $analyzer->getLinkedDirectories();
        if (! empty($directories)) {
            $this->info('Generating linked Highload Block migrations (referenced by IBlocks)...');

            foreach ($directories as $hlblock) {
                $this->generateHlblockMigration($hlblock, $dryRun);
            }
            $this->newLine();
        }

        // Then generate IBlock migrations
        $this->info('Generating IBlock migrations...');

        foreach ($iblocks as $iblock) {
            $this->generateIBlockMigration($iblock, $dryRun);
        }

        // Link tables go last: they may point at the table of any iblock.
        $pivots = [];
        foreach ($iblocks as $iblock) {
            foreach ($this->generator->generatePivotsForIBlock($iblock) as $key => $migration) {
                $pivots[$iblock->code.'.'.$key] = $migration;
            }
        }

        if ($pivots !== []) {
            $this->newLine();
            $this->info('Link tables');

            foreach ($pivots as $key => $migration) {
                $this->outputMigration($key, $migration['filename'], $migration['content'], $dryRun);
            }
        }
    }

    /**
     * Generate migration for single IBlock
     */
    private function generateIBlockMigration($iblock, bool $dryRun): void
    {
        $naming = $this->getNaming();

        $this->newLine();
        $this->info("IBlock: {$iblock->name} ({$iblock->code})");

        $migrations = $this->generator->generateTablesForIBlock($iblock);

        foreach ($migrations as $key => $migration) {
            $this->outputMigration($key, $migration['filename'], $migration['content'], $dryRun);
        }
    }

    /**
     * Generate migrations for Highload Blocks
     */
    private function generateHlblockMigrations(array $hlblockIds, bool $dryRun): void
    {
        $this->info('Generating Highload Block migrations...');

        $analyzer = $this->getHlblockAnalyzer();

        foreach ($hlblockIds as $hlblockId) {
            $hlblock = $analyzer->analyze($hlblockId);
            if (! $hlblock) {
                $this->warn("Highload Block {$hlblockId} not found");

                continue;
            }

            $this->generateHlblockMigration($hlblock, $dryRun);
        }
    }

    /**
     * Generate migration for single Highload Block
     */
    private function generateHlblockMigration($hlblock, bool $dryRun): void
    {
        // Skip if already generated (as linked directory from IBlock)
        if (in_array($hlblock->id, $this->generatedHlblockIds, true)) {
            $this->line("  Skipping {$hlblock->name} (already generated as linked directory)");

            return;
        }

        $naming = $this->getNaming();

        $this->newLine();
        $this->info("Highload Block: {$hlblock->name}");

        $migrations = $this->generator->generateForHlblock($hlblock);

        foreach ($migrations as $key => $migration) {
            $this->outputMigration($key, $migration['filename'], $migration['content'], $dryRun);
        }

        // Track as generated
        $this->generatedHlblockIds[] = $hlblock->id;
    }

    /**
     * Output migration (write or display)
     */
    private function outputMigration(string $type, string $filename, string $content, bool $dryRun): void
    {
        $path = $this->generator->getMigrationsPath().'/'.$filename;

        if ($dryRun) {
            $this->line("  Would create [{$type}]: {$filename}");

            if ($this->output->isVerbose()) {
                $this->newLine();
                $this->line($content);
                $this->newLine();
            }

            return;
        }

        // Check if similar migration exists
        $existingFiles = glob($this->generator->getMigrationsPath().'/*'.$this->extractTableName($filename).'*.php');
        if (! empty($existingFiles) && ! $this->option('force')) {
            $this->warn('  Migration for this table may already exist:');
            foreach ($existingFiles as $file) {
                $this->line('    '.basename($file));
            }
            if (! $this->confirm('  Create new migration anyway?')) {
                $this->warn("  Skipped: {$filename}");

                return;
            }
        }

        // Write file
        file_put_contents($path, $content);

        $this->info("  Created [{$type}]: {$filename}");
    }

    /**
     * Extract table name from migration filename
     */
    private function extractTableName(string $filename): string
    {
        // Remove timestamp, sequence number and extension
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_\d{4}_/', '', $filename);
        $name = str_replace('.php', '', $name);
        // Remove create_ prefix and _table suffix
        $name = preg_replace('/^create_/', '', $name);
        $name = preg_replace('/_table$/', '', $name);

        return $name;
    }
}
