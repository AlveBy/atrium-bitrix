<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Analyzers;

use Atrium\Bitrix\Schema\HighloadBlock;
use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockPropertyEnum;
use Atrium\Bitrix\Schema\IBlockSection;
use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Support\ProjectFields;

class IBlockAnalyzer
{
    protected BitrixConnection $connection;

    protected HighloadBlockAnalyzer $hlblockAnalyzer;

    /** @var IBlock[] */
    protected array $analyzedIBlocks = [];

    /** @var HighloadBlock[] */
    protected array $linkedDirectories = [];

    public function __construct(BitrixConnection $connection, ?HighloadBlockAnalyzer $hlblockAnalyzer = null)
    {
        $this->connection = $connection;
        $this->hlblockAnalyzer = $hlblockAnalyzer ?? new HighloadBlockAnalyzer($connection);
    }

    /**
     * Analyze single IBlock by ID
     */
    public function analyze(int $iblockId): ?IBlock
    {
        // Check if already analyzed
        if (isset($this->analyzedIBlocks[$iblockId])) {
            return $this->analyzedIBlocks[$iblockId];
        }

        $data = $this->connection->getIBlock($iblockId);

        if (! $data) {
            return null;
        }

        return $this->analyzeRow($data);
    }

    /**
     * Analyze an IBlock whose b_iblock row is already at hand
     */
    public function analyzeRow(array $data): IBlock
    {
        return $this->analyzedIBlocks[$data['ID']] ??= $this->read($data);
    }

    private function read(array $data): IBlock
    {
        $iblock = IBlock::fromArray($data);

        // Analyze properties
        $this->analyzeProperties($iblock);

        // Analyze sections
        $this->analyzeSections($iblock);

        return $iblock;
    }

    /**
     * Analyze all IBlocks
     */
    public function analyzeAll(?string $typeId = null): array
    {
        return array_map($this->analyzeRow(...), $this->connection->getIBlocks($typeId));
    }

    /**
     * Analyze IBlock properties
     */
    protected function analyzeProperties(IBlock $iblock): void
    {
        $properties = $this->connection->getIBlockProperties($iblock->id);

        foreach ($properties as $propData) {
            $property = IBlockProperty::fromArray($propData);

            // Load enum values for list properties
            if ($property->isList()) {
                $this->loadEnumValues($property);
            }

            // Resolve the linked IBlock for BOTH element (E) and section (G)
            // links. Resolving only E left every section link without a target,
            // so the generator fell back to a literal `Section::class` that does
            // not exist and the tree picker had nothing to offer.
            if (($property->isElementLink() || $property->isSectionLink()) && $property->linkIblockId) {
                $linkedIBlock = $this->connection->getIBlock($property->linkIblockId);
                if ($linkedIBlock) {
                    $property->linkIblockCode = ! empty($linkedIBlock['CODE'])
                        ? $linkedIBlock['CODE']
                        : 'IBLOCK_'.$property->linkIblockId;
                }
            }

            // A link "to any infoblock" (LINK_IBLOCK_ID empty): find the target
            // ourselves, otherwise the generator fell back to `self` and the
            // relation pointed at its own table. That is how related catalog
            // items disappeared under articles: the link property stored catalog
            // element IDs, but the relation looked them up among the articles.
            if ($property->isElementLink() && ! $property->linkIblockId) {
                $this->resolveOpenElementLink($iblock, $property);
            }

            // Analyze directory (HL block) for directory type
            if ($property->isDirectory() && $property->directoryTableName) {
                $this->analyzeDirectoryProperty($property);
            }

            $iblock->addProperty($property);
        }
    }

    /**
     * Target of a link property that has no LINK_IBLOCK_ID set in Bitrix.
     *
     * Two sources, in order of trust:
     *
     * 1. `element_link_targets` in config/atrium-bitrix.php: the project's decision.
     *    Needed where the property has never been filled in: the target can't be
     *    guessed from empty values, yet the relation and foreign key must be
     *    generated now, or the first link made in the admin would hit the wrong
     *    table.
     * 2. The property's own values. If all stored IDs belong to one infoblock,
     *    that is the target.
     *
     * Values from different infoblocks can't be described by a single Eloquent
     * relation, so those are left as they are: better a property without a
     * relation than half of the links pointing to the wrong place.
     */
    protected function resolveOpenElementLink(IBlock $iblock, IBlockProperty $property): void
    {
        $configured = ProjectFields::elementLinkTargetFor($iblock->code, $property->code);

        if ($configured !== null) {
            $target = $this->connection->getIBlockByCode($configured);

            if ($target) {
                $property->linkIblockId = (int) $target['ID'];
                $property->linkIblockCode = $configured;

                return;
            }
        }

        $targets = $this->connection->getElementLinkTargetIblocks(
            $iblock->id,
            $property->id,
            $property->multiple
        );

        if (count($targets) !== 1) {
            return;
        }

        $targetId = (int) array_key_first($targets);
        $target = $this->connection->getIBlock($targetId);

        if (! $target) {
            return;
        }

        $property->linkIblockId = $targetId;
        $property->linkIblockCode = ! empty($target['CODE']) ? $target['CODE'] : 'IBLOCK_'.$targetId;
    }

    /**
     * Load enum values for list property
     */
    protected function loadEnumValues(IBlockProperty $property): void
    {
        $enumValues = $this->connection->getPropertyEnumValues($property->id);

        foreach ($enumValues as $enumData) {
            $enum = IBlockPropertyEnum::fromArray($enumData);
            $property->addEnumValue($enum);
        }
    }

    /**
     * Analyze directory property (HL block reference)
     */
    protected function analyzeDirectoryProperty(IBlockProperty $property): void
    {
        $tableName = $property->directoryTableName;

        // Check if already analyzed
        if (! isset($this->linkedDirectories[$tableName])) {
            $hlblock = $this->hlblockAnalyzer->analyzeByTableName($tableName);

            // Fallback: try suffix match (e.g. theme_a_colors → theme_b_colors)
            if (! $hlblock) {
                $hlblock = $this->hlblockAnalyzer->findBySimilarTableName($tableName);
            }

            if ($hlblock) {
                $this->linkedDirectories[$tableName] = $hlblock;
            }
        }

        if (isset($this->linkedDirectories[$tableName])) {
            $property->directoryHlblock = $this->linkedDirectories[$tableName];
        }
    }

    /**
     * Analyze IBlock sections
     */
    protected function analyzeSections(IBlock $iblock): void
    {
        $sections = $this->connection->getIBlockSections($iblock->id);

        // Build flat list first
        $sectionMap = [];
        foreach ($sections as $sectionData) {
            $section = IBlockSection::fromArray($sectionData);
            $sectionMap[$section->id] = $section;
        }

        // Build tree structure
        foreach ($sectionMap as $section) {
            if ($section->parentId && isset($sectionMap[$section->parentId])) {
                $sectionMap[$section->parentId]->addChild($section);
            }
        }

        // Add root sections to IBlock
        foreach ($sectionMap as $section) {
            $iblock->addSection($section);
        }
    }

    /**
     * Get analyzed IBlocks
     */
    public function getAnalyzedIBlocks(): array
    {
        return $this->analyzedIBlocks;
    }

    /**
     * Get linked directories (HL blocks)
     */
    public function getLinkedDirectories(): array
    {
        return $this->linkedDirectories;
    }

    /**
     * Get analysis summary
     */
    public function getSummary(): array
    {
        $summary = [
            'iblocks_count' => count($this->analyzedIBlocks),
            'directories_count' => count($this->linkedDirectories),
            'iblocks' => [],
        ];

        foreach ($this->analyzedIBlocks as $iblock) {
            $summary['iblocks'][] = [
                'id' => $iblock->id,
                'code' => $iblock->code,
                'name' => $iblock->name,
                'type' => $iblock->iblockType,
                'version' => $iblock->version,
                'properties_count' => count($iblock->properties),
                'sections_count' => count($iblock->sections),
                'elements_count' => $this->connection->getIBlockElementCount($iblock->id),
                'has_files' => $iblock->hasFileProperties(),
                'has_multiple' => $iblock->hasMultipleProperties(),
                'property_types' => $this->getPropertyTypeSummary($iblock),
            ];
        }

        return $summary;
    }

    /**
     * Get property type summary for IBlock
     */
    protected function getPropertyTypeSummary(IBlock $iblock): array
    {
        $types = [];

        foreach ($iblock->properties as $property) {
            $type = $property->getFullType();
            if (! isset($types[$type])) {
                $types[$type] = 0;
            }
            $types[$type]++;
        }

        return $types;
    }
}
