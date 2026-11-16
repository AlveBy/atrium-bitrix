<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Analyzers;

use Atrium\Bitrix\Schema\HighloadBlock;
use Atrium\Bitrix\Schema\HighloadBlockEnumValue;
use Atrium\Bitrix\Schema\HighloadBlockField;
use Atrium\Bitrix\Support\BitrixConnection;

class HighloadBlockAnalyzer
{
    protected BitrixConnection $connection;

    /** @var HighloadBlock[] */
    protected array $analyzedHlblocks = [];

    public function __construct(BitrixConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Analyze single Highload Block by ID
     */
    public function analyze(int $hlblockId): ?HighloadBlock
    {
        // Check if already analyzed
        if (isset($this->analyzedHlblocks[$hlblockId])) {
            return $this->analyzedHlblocks[$hlblockId];
        }

        $data = $this->connection->getHighloadBlock($hlblockId);

        if (! $data) {
            return null;
        }

        $hlblock = HighloadBlock::fromArray($data);

        // Analyze fields
        $this->analyzeFields($hlblock);

        $this->analyzedHlblocks[$hlblockId] = $hlblock;

        return $hlblock;
    }

    /**
     * Analyze Highload Block by table name
     */
    public function analyzeByTableName(string $tableName): ?HighloadBlock
    {
        // Check if already analyzed
        foreach ($this->analyzedHlblocks as $hlblock) {
            if ($hlblock->tableName === $tableName) {
                return $hlblock;
            }
        }

        $data = $this->connection->getHighloadBlockByTableName($tableName);

        if (! $data) {
            return null;
        }

        return $this->analyze((int) $data['ID']);
    }

    /**
     * Analyze all Highload Blocks
     */
    public function analyzeAll(): array
    {
        $hlblocks = $this->connection->getHighloadBlocks();
        $result = [];

        foreach ($hlblocks as $data) {
            $hlblock = $this->analyze((int) $data['ID']);
            if ($hlblock) {
                $result[] = $hlblock;
            }
        }

        return $result;
    }

    /**
     * Analyze Highload Block fields
     */
    protected function analyzeFields(HighloadBlock $hlblock): void
    {
        $fields = $this->connection->getHighloadBlockFields($hlblock->id);

        foreach ($fields as $fieldData) {
            $field = HighloadBlockField::fromArray($fieldData);

            // Load enum values for enumeration fields
            if ($field->isEnum()) {
                $this->loadEnumValues($field);
            }

            $hlblock->addField($field);
        }
    }

    /**
     * Load enum values for enumeration field
     */
    protected function loadEnumValues(HighloadBlockField $field): void
    {
        $enumValues = $this->connection->getUserFieldEnumValues($field->id);

        foreach ($enumValues as $enumData) {
            $enum = HighloadBlockEnumValue::fromArray($enumData);
            $field->addEnumValue($enum);
        }
    }

    /**
     * Get analyzed Highload Blocks
     */
    public function getAnalyzedHlblocks(): array
    {
        return $this->analyzedHlblocks;
    }

    /**
     * Get analysis summary
     */
    public function getSummary(): array
    {
        $summary = [
            'hlblocks_count' => count($this->analyzedHlblocks),
            'hlblocks' => [],
        ];

        foreach ($this->analyzedHlblocks as $hlblock) {
            $summary['hlblocks'][] = [
                'id' => $hlblock->id,
                'name' => $hlblock->name,
                'table_name' => $hlblock->tableName,
                'fields_count' => count($hlblock->fields),
                'rows_count' => $this->connection->getHighloadBlockDataCount($hlblock->tableName),
                'has_files' => $hlblock->hasFileFields(),
                'field_types' => $this->getFieldTypeSummary($hlblock),
            ];
        }

        return $summary;
    }

    /**
     * Get field type summary for Highload Block
     */
    protected function getFieldTypeSummary(HighloadBlock $hlblock): array
    {
        $types = [];

        foreach ($hlblock->fields as $field) {
            $type = $field->getFullType();
            if (! isset($types[$type])) {
                $types[$type] = 0;
            }
            $types[$type]++;
        }

        return $types;
    }

    /**
     * Get all linked Highload Block IDs (from hlblock type fields)
     */
    public function getLinkedHlblockIds(): array
    {
        $linkedIds = [];

        foreach ($this->analyzedHlblocks as $hlblock) {
            foreach ($hlblock->fields as $field) {
                if ($field->isHlblockReference() && $field->linkedHlblockId) {
                    $linkedIds[] = $field->linkedHlblockId;
                }
            }
        }

        return array_unique($linkedIds);
    }

    /**
     * Find Highload Block used as directory for IBlock properties
     */
    public function findDirectoryHlblock(string $tableName): ?HighloadBlock
    {
        return $this->analyzeByTableName($tableName);
    }

    /**
     * Find Highload Block by table name suffix match.
     * Useful when Bitrix property has a different prefix (e.g. theme_a_colors vs theme_b_colors).
     */
    public function findBySimilarTableName(string $tableName): ?HighloadBlock
    {
        $parts = explode('_', $tableName);
        if (count($parts) <= 1) {
            return null;
        }

        // Strip first prefix word and match by suffix
        $suffix = implode('_', array_slice($parts, 1));

        foreach ($this->analyzedHlblocks as $hlblock) {
            if (str_ends_with($hlblock->tableName, $suffix)) {
                return $hlblock;
            }
        }

        return null;
    }

    /**
     * Check if Highload Block has complex relations
     */
    public function hasComplexRelations(HighloadBlock $hlblock): bool
    {
        foreach ($hlblock->fields as $field) {
            if ($field->isHlblockReference()) {
                return true;
            }
        }

        return false;
    }
}
