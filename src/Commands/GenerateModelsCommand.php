<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Admin\Support\ConfigWriter;
use Atrium\Bitrix\Generators\ModelGenerator;
use Atrium\Bitrix\Generators\ReferenceModelGenerator;
use Atrium\Bitrix\Generators\SectionModelGenerator;
use Atrium\Bitrix\Mappers\FieldTypeMapper;
use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Illuminate\Support\Facades\File;

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

    /**
     * Classes of the models this run wrote, kept or would write, in order.
     *
     * @var list<string>
     */
    private array $generated = [];

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

        $this->registerResources($dryRun);

        $this->newLine();
        $this->info('Model generation complete!');

        return self::SUCCESS;
    }

    /**
     * Initialize generators
     */
    private function initializeGenerators(): void
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
    private function generateIBlockModels(array $iblockIds, bool $dryRun): void
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
    private function generateIBlockModel($iblock, bool $dryRun): void
    {
        $naming = $this->getNaming();
        $namespace = config('atrium-bitrix.model_namespace', 'App\\Models');

        // Generate element model
        $modelPath = $this->modelGenerator->getFilePath($iblock);
        $modelContent = $this->modelGenerator->generate($iblock);

        $this->outputModel('Element', $namespace.'\\'.$naming->toModelName($iblock->code), $modelPath, $modelContent, $dryRun);

        // Generate section model if has sections
        if (! empty($iblock->sections)) {
            $sectionPath = $this->sectionGenerator->getFilePath($iblock);
            $sectionContent = $this->sectionGenerator->generate($iblock);

            $this->outputModel('Section', $namespace.'\\'.$naming->toSectionModelName($iblock->code), $sectionPath, $sectionContent, $dryRun);
        }
    }

    /**
     * Generate models for Highload Blocks
     */
    private function generateHlblockModels(array $hlblockIds, bool $dryRun): void
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
    private function generateReferenceModel($hlblock, bool $dryRun): void
    {
        $naming = $this->getNaming();
        $namespace = config('atrium-bitrix.reference_namespace', 'App\\Models\\References');

        $modelPath = $this->referenceGenerator->getFilePath($hlblock);
        $modelContent = $this->referenceGenerator->generate($hlblock);

        $this->outputModel('Reference', $namespace.'\\'.$naming->hlblockToModelName($hlblock->name), $modelPath, $modelContent, $dryRun);
    }

    /**
     * Output model (write or display)
     */
    private function outputModel(string $type, string $class, string $path, string $content, bool $dryRun): void
    {
        $name = class_basename($class);

        // Kept or not, the model is there afterwards: it belongs in the menu.
        $this->generated[] = $class;

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

        File::ensureDirectoryExists(dirname($path));

        // Write file
        file_put_contents($path, $content);

        $this->info("  Created: {$name}");
        $this->line("  Path: {$path}");
    }

    /**
     * List the models in config/atrium.php → resources, so they are in the
     * admin's menu, as atrium:make-module does. Directory (highload) models
     * too: editors add a brand or a colour there, as they did in Bitrix.
     * A model already listed, even commented out, is left as it is.
     */
    private function registerResources(bool $dryRun): void
    {
        $classes = array_unique($this->generated);

        if ($classes === []) {
            return;
        }

        $path = config_path('atrium.php');
        $missing = array_filter($classes, fn (string $class): bool => ! $this->isListed($class, $path));

        $this->newLine();

        if (! is_file($path) && $missing !== []) {
            $this->warn("config/atrium.php is not published (php artisan atrium:install). Add the models to its 'resources' by hand:");
            $this->listByHand($missing);

            return;
        }

        if (! $dryRun) {
            // Each one goes to the top of the list: added backwards, they keep the order they were generated in.
            foreach (array_reverse($missing) as $class) {
                if (! ConfigWriter::addResource($class, $path)) {
                    $this->warn("Could not find 'resources' in config/atrium.php. Add the models to it by hand:");
                    $this->listByHand($missing);

                    return;
                }
            }
        }

        $this->info('Registering models in config/atrium.php...');

        foreach ($classes as $class) {
            $this->line(match (true) {
                ! in_array($class, $missing, true) => "  Already listed: {$class}",
                $dryRun => "  Would add: {$class}",
                default => "  Added: {$class}",
            });
        }
    }

    private function isListed(string $class, string $path): bool
    {
        // The loaded config also knows entries written with a `use` import.
        $loaded = array_map(static fn (string $listed): string => ltrim($listed, '\\'), config('atrium.resources', []));

        if (in_array($class, $loaded, true)) {
            return true;
        }

        return ConfigWriter::hasResource($class, $path);
    }

    /**
     * @param  array<string>  $classes
     */
    private function listByHand(array $classes): void
    {
        foreach ($classes as $class) {
            $this->line("    \\{$class}::class,");
        }
    }
}
