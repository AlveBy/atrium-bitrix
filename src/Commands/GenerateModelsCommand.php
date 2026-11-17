<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Bitrix\Generators\ModelGenerator;
use Atrium\Bitrix\Generators\ReferenceModelGenerator;
use Atrium\Bitrix\Generators\SectionModelGenerator;
use Atrium\Bitrix\Mappers\FieldTypeMapper;
use Atrium\Bitrix\Mappers\PropertyTypeMapper;

class GenerateModelsCommand extends AbstractBitrixCommand
{
    protected $signature = 'atrium:bitrix:generate:models
                            {--connection= : Database connection name}
                            {--iblock=* : Specific IBlock IDs to process}
                            {--hlblock=* : Specific Highload Block IDs to process}
                            {--force : Overwrite existing files}
                            {--dry-run : Show what would be generated without writing files}';

    protected $description = 'Generate Eloquent models from Bitrix structure';

    protected ModelGenerator $modelGenerator;

    protected SectionModelGenerator $sectionGenerator;

    protected ReferenceModelGenerator $referenceGenerator;

    public function handle(): int
    {
        $this->info('Bitrix Model Generator');
        $this->info('======================');
        $this->newLine();

        if (! $this->verifyConnection()) {
            return self::FAILURE;
        }

        $this->initializeGenerators();

        $iblockIds = $this->idsOption('iblock');
        $hlblockIds = $this->idsOption('hlblock');
        $dryRun = $this->option('dry-run');

        // Process IBlocks
        $iblockIds = $iblockIds ?: $this->selectIBlocks();
        if (! empty($iblockIds)) {
            $this->generateIBlockModels($iblockIds, $dryRun);
        }

        // Process Highload Blocks
        $hlblockIds = $hlblockIds ?: $this->selectHlblocks();
        if (! empty($hlblockIds)) {
            $this->generateHlblockModels($hlblockIds, $dryRun);
        }

        $this->newLine();
        $this->info('Model generation complete!');

        return self::SUCCESS;
    }

    /**
     * Initialize generators
     */
    protected function initializeGenerators(): void
    {
        $naming = $this->getNaming();
        $typeMapper = new PropertyTypeMapper;
        $fieldMapper = new FieldTypeMapper;

        $this->modelGenerator = new ModelGenerator(
            $naming,
            $typeMapper,
            $fieldMapper,
            $this->getBitrixConnection()->getConnection()
        );
        $this->sectionGenerator = new SectionModelGenerator($naming, $this->getBitrixConnection()->getConnection());
        $this->referenceGenerator = new ReferenceModelGenerator($naming, $typeMapper, $fieldMapper);
    }

    /**
     * Generate models for IBlocks
     */
    protected function generateIBlockModels(array $iblockIds, bool $dryRun): void
    {
        $this->info('Generating IBlock models...');

        $analyzer = $this->getIBlockAnalyzer();

        // Pre-load all HL blocks so analyzeByTableName finds them in cache
        $this->getHlblockAnalyzer()->analyzeAll();

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

            $this->generateIBlockModel($iblock, $dryRun);
        }

        // Generate models for linked directories
        $directories = $analyzer->getLinkedDirectories();
        if (! empty($directories)) {
            $this->newLine();
            $this->info('Generating linked directory models...');

            foreach ($directories as $hlblock) {
                $this->generateReferenceModel($hlblock, $dryRun);
            }
        }
    }

    /**
     * Generate model for single IBlock
     */
    protected function generateIBlockModel($iblock, bool $dryRun): void
    {
        $naming = $this->getNaming();

        // Generate element model
        $modelPath = $this->modelGenerator->getFilePath($iblock);
        $modelContent = $this->modelGenerator->generate($iblock);

        $this->outputModel('Element', $naming->toModelName($iblock->code), $modelPath, $modelContent, $dryRun);

        // Generate section model if has sections
        if (! empty($iblock->sections)) {
            $sectionPath = $this->sectionGenerator->getFilePath($iblock);
            $sectionContent = $this->sectionGenerator->generate($iblock);

            $this->outputModel('Section', $naming->toSectionModelName($iblock->code), $sectionPath, $sectionContent, $dryRun);
        }
    }

    /**
     * Generate models for Highload Blocks
     */
    protected function generateHlblockModels(array $hlblockIds, bool $dryRun): void
    {
        $this->info('Generating Highload Block models...');

        $analyzer = $this->getHlblockAnalyzer();

        foreach ($hlblockIds as $hlblockId) {
            $hlblock = $analyzer->analyze($hlblockId);
            if (! $hlblock) {
                $this->warn("Highload Block {$hlblockId} not found");

                continue;
            }

            $this->generateReferenceModel($hlblock, $dryRun);
        }
    }

    /**
     * Generate reference model
     */
    protected function generateReferenceModel($hlblock, bool $dryRun): void
    {
        $naming = $this->getNaming();

        $modelPath = $this->referenceGenerator->getFilePath($hlblock);
        $modelContent = $this->referenceGenerator->generate($hlblock);

        $this->outputModel('Reference', $naming->hlblockToModelName($hlblock->name), $modelPath, $modelContent, $dryRun);
    }

    /**
     * Output model (write or display)
     */
    protected function outputModel(string $type, string $name, string $path, string $content, bool $dryRun): void
    {
        if ($dryRun) {
            $this->line("  Would create {$type} model: {$name}");
            $this->line("  Path: {$path}");

            if ($this->output->isVerbose()) {
                $this->newLine();
                $this->line($content);
                $this->newLine();
            }

            return;
        }

        // Check if file exists
        if (file_exists($path) && ! $this->option('force')) {
            if (! $this->confirm("Model {$name} already exists. Overwrite?")) {
                $this->warn("  Skipped: {$name}");

                return;
            }
        }

        // Ensure directory exists
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Write file
        file_put_contents($path, $content);

        $this->info("  Created: {$name}");
        $this->line("  Path: {$path}");
    }
}
