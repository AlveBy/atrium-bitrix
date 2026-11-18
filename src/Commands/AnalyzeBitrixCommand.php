<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Bitrix\Schema\IBlock;

class AnalyzeBitrixCommand extends AbstractBitrixCommand
{
    protected $signature = 'atrium:bitrix:analyze
                            {--connection= : Database connection name}
                            {--iblock=* : Specific IBlock IDs to analyze}
                            {--hlblock=* : Specific Highload Block IDs to analyze}
                            {--type= : IBlock type to filter by}
                            {--json : Output as JSON}';

    protected $description = 'Analyze Bitrix database structure';

    public function handle(): int
    {
        $this->info('Bitrix Database Analyzer');
        $this->info('========================');
        $this->newLine();

        if (! $this->verifyConnection()) {
            return self::FAILURE;
        }

        $iblockIds = $this->option('iblock') ?: null;
        $hlblockIds = $this->option('hlblock') ?: null;
        $typeId = $this->option('type');

        // Analyze IBlocks
        $this->analyzeIBlocks($iblockIds, $typeId);

        // Analyze Highload Blocks
        $this->analyzeHlblocks($hlblockIds);

        // Output as JSON if requested
        if ($this->option('json')) {
            $this->outputJson();
        }

        return self::SUCCESS;
    }

    /**
     * Analyze IBlocks
     */
    private function analyzeIBlocks(?array $iblockIds, ?string $typeId): void
    {
        $this->info('Analyzing IBlocks...');

        $analyzer = $this->getIBlockAnalyzer();
        $connection = $this->getBitrixConnection();

        // Get IBlocks to analyze
        $iblocks = $connection->getIBlocks($typeId);

        if ($iblockIds) {
            $iblocks = array_filter($iblocks, fn ($ib) => in_array($ib['ID'], $iblockIds));
        }

        if (empty($iblocks)) {
            $this->warn('No IBlocks found.');

            return;
        }

        $rows = [];
        foreach ($iblocks as $iblockData) {
            $rows[] = $this->formatIBlockRow($analyzer->analyzeRow($iblockData));
        }

        $this->displayTable([
            'ID',
            'Code',
            'Name',
            'Type',
            'Ver',
            'Props',
            'Sections',
            'Elements',
            'Files',
            'Relations',
        ], $rows);

        $this->newLine();

        // Show property details for each IBlock
        if ($this->output->isVerbose()) {
            foreach ($analyzer->getAnalyzedIBlocks() as $iblock) {
                $this->showPropertyDetails($iblock);
            }
        }
    }

    /**
     * Format IBlock row for table
     */
    private function formatIBlockRow(IBlock $iblock): array
    {
        $connection = $this->getBitrixConnection();

        return [
            $iblock->id,
            $iblock->code ?: '-',
            mb_substr($iblock->name, 0, 30),
            $iblock->iblockType,
            $iblock->version,
            count($iblock->properties),
            count($iblock->sections),
            $connection->getIBlockElementCount($iblock->id),
            $iblock->hasFileProperties() ? 'Yes' : 'No',
            $this->countRelations($iblock),
        ];
    }

    /**
     * Count relations in IBlock
     */
    private function countRelations(IBlock $iblock): int
    {
        $count = 0;
        foreach ($iblock->properties as $property) {
            if ($property->isElementLink() || $property->isSectionLink() || $property->isDirectory()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Show property details for IBlock
     */
    private function showPropertyDetails(IBlock $iblock): void
    {
        $this->newLine();
        $this->info("Properties for IBlock: {$iblock->name} ({$iblock->code})");

        $rows = [];
        foreach ($iblock->properties as $property) {
            $rows[] = [
                $property->id,
                $property->code,
                mb_substr($property->name, 0, 25),
                $property->getFullType(),
                $property->multiple ? 'Yes' : 'No',
                $property->isRequired ? 'Yes' : 'No',
                $this->getPropertyRelationInfo($property),
            ];
        }

        $this->displayTable([
            'ID',
            'Code',
            'Name',
            'Type',
            'Multiple',
            'Required',
            'Relation',
        ], $rows);
    }

    /**
     * Get property relation info
     */
    private function getPropertyRelationInfo($property): string
    {
        if ($property->isElementLink()) {
            return "E:{$property->linkIblockId}";
        }

        if ($property->isSectionLink()) {
            return 'G';
        }

        if ($property->isDirectory()) {
            return "Dir:{$property->directoryTableName}";
        }

        return '-';
    }

    /**
     * Analyze Highload Blocks
     */
    private function analyzeHlblocks(?array $hlblockIds): void
    {
        $this->info('Analyzing Highload Blocks...');

        $analyzer = $this->getHlblockAnalyzer();
        $connection = $this->getBitrixConnection();

        // Get Highload Blocks
        $hlblocks = $connection->getHighloadBlocks();

        if ($hlblockIds) {
            $hlblocks = array_filter($hlblocks, fn ($hl) => in_array($hl['ID'], $hlblockIds));
        }

        if (empty($hlblocks)) {
            $this->warn('No Highload Blocks found.');

            return;
        }

        $rows = [];
        foreach ($hlblocks as $hlblockData) {
            $hlblock = $analyzer->analyzeRow($hlblockData);

            $rows[] = [
                $hlblock->id,
                $hlblock->name,
                $hlblock->tableName,
                count($hlblock->fields),
                $connection->getHighloadBlockDataCount($hlblock->tableName),
                $hlblock->hasFileFields() ? 'Yes' : 'No',
            ];
        }

        $this->displayTable([
            'ID',
            'Name',
            'Table',
            'Fields',
            'Rows',
            'Files',
        ], $rows);

        $this->newLine();

        // Show field details
        if ($this->output->isVerbose()) {
            foreach ($analyzer->getAnalyzedHlblocks() as $hlblock) {
                $this->showHlblockFieldDetails($hlblock);
            }
        }
    }

    /**
     * Show Highload Block field details
     */
    private function showHlblockFieldDetails($hlblock): void
    {
        $this->newLine();
        $this->info("Fields for Highload Block: {$hlblock->name}");

        $rows = [];
        foreach ($hlblock->fields as $field) {
            $rows[] = [
                $field->id,
                $field->fieldName,
                $field->userTypeId,
                $field->multiple ? 'Yes' : 'No',
                $field->mandatory ? 'Yes' : 'No',
                $field->linkedHlblockId ?: '-',
            ];
        }

        $this->displayTable([
            'ID',
            'Field Name',
            'Type',
            'Multiple',
            'Required',
            'Linked HL',
        ], $rows);
    }

    /**
     * Output analysis as JSON
     */
    private function outputJson(): void
    {
        $iblockAnalyzer = $this->getIBlockAnalyzer();
        $hlblockAnalyzer = $this->getHlblockAnalyzer();

        $data = [
            'iblocks' => $iblockAnalyzer->getSummary(),
            'hlblocks' => $hlblockAnalyzer->getSummary(),
        ];

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
