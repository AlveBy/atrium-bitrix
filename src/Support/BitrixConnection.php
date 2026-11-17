<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BitrixConnection
{
    protected Connection $connection;

    protected string $connectionName;

    public function __construct(?string $connectionName = null)
    {
        $this->connectionName = $connectionName ?? config('atrium-bitrix.connection', 'bitrix');
        $this->connection = DB::connection($this->connectionName);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Get all IBlocks
     */
    public function getIBlocks(?string $typeId = null): array
    {
        $query = $this->connection->table('b_iblock');

        if ($typeId) {
            $query->where('IBLOCK_TYPE_ID', $typeId);
        }

        return $query->get()->map(fn ($row) => (array) $row)->toArray();
    }

    /**
     * Get single IBlock by ID
     */
    public function getIBlock(int $id): ?array
    {
        $row = $this->connection
            ->table('b_iblock')
            ->where('ID', $id)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * Get IBlock by code
     */
    public function getIBlockByCode(string $code): ?array
    {
        $row = $this->connection
            ->table('b_iblock')
            ->where('CODE', $code)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * Get IBlock properties
     */
    public function getIBlockProperties(int $iblockId): array
    {
        return $this->connection
            ->table('b_iblock_property')
            ->where('IBLOCK_ID', $iblockId)
            ->orderBy('SORT')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Get property enum values
     */
    public function getPropertyEnumValues(int $propertyId): array
    {
        return $this->connection
            ->table('b_iblock_property_enum')
            ->where('PROPERTY_ID', $propertyId)
            ->orderBy('SORT')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Get IBlock sections
     */
    public function getIBlockSections(int $iblockId): array
    {
        return $this->connection
            ->table('b_iblock_section')
            ->where('IBLOCK_ID', $iblockId)
            ->orderBy('LEFT_MARGIN')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Get IBlock elements with pagination
     */
    public function getIBlockElements(int $iblockId, int $limit = 100, int $offset = 0): array
    {
        return $this->connection
            ->table('b_iblock_element')
            ->where('IBLOCK_ID', $iblockId)
            ->orderBy('ID')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Get element count for IBlock
     */
    public function getIBlockElementCount(int $iblockId): int
    {
        return $this->connection
            ->table('b_iblock_element')
            ->where('IBLOCK_ID', $iblockId)
            ->count();
    }

    /**
     * Get element property values (for version 1 IBlocks)
     */
    public function getElementPropertyValues(int $iblockId, int $elementId): array
    {
        return $this->connection
            ->table('b_iblock_element_property')
            ->where('IBLOCK_ELEMENT_ID', $elementId)
            ->orderBy('ID')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Get element property values (for version 2 IBlocks)
     * Version 2 stores properties in separate table b_iblock_element_prop_sN
     */
    public function getElementPropertyValuesV2(int $iblockId, int $elementId): ?array
    {
        $tableName = 'b_iblock_element_prop_s'.$iblockId;

        if (! $this->tableExists($tableName)) {
            return null;
        }

        $row = $this->connection
            ->table($tableName)
            ->where('IBLOCK_ELEMENT_ID', $elementId)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * Get multiple property values (for version 2 IBlocks)
     */
    public function getElementMultiplePropertyValues(int $iblockId, int $elementId): array
    {
        $tableName = 'b_iblock_element_prop_m'.$iblockId;

        if (! $this->tableExists($tableName)) {
            return [];
        }

        return $this->connection
            ->table($tableName)
            ->where('IBLOCK_ELEMENT_ID', $elementId)
            ->orderBy('ID')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Iblocks whose elements a link property (type E) actually points to.
     *
     * Needed where the property has no LINK_IBLOCK_ID: in Bitrix that means
     * "pick an element from any iblock", and the property settings do not
     * reveal the target. It is visible in the values themselves, though: the
     * stored element IDs belong to a specific iblock.
     *
     * @return array<int,int> iblock ID => number of values pointing to it,
     *                        in descending order
     */
    public function getElementLinkTargetIblocks(int $iblockId, int $propertyId, bool $multiple): array
    {
        $values = $this->elementLinkValuesQuery($iblockId, $propertyId, $multiple);

        if ($values === null) {
            return [];
        }

        $rows = $this->connection
            ->table('b_iblock_element')
            ->whereIn('ID', $values)
            ->selectRaw('IBLOCK_ID, COUNT(*) as cnt')
            ->groupBy('IBLOCK_ID')
            ->orderByDesc('cnt')
            ->get();

        $targets = [];

        foreach ($rows as $row) {
            $targets[(int) $row->IBLOCK_ID] = (int) $row->cnt;
        }

        return $targets;
    }

    /**
     * Subquery with the link property values: an iblock may store them in a
     * shared table (version 1) or in its own per-iblock table (version 2).
     *
     * @return Builder|null null if there is no storage
     */
    protected function elementLinkValuesQuery(int $iblockId, int $propertyId, bool $multiple): ?Builder
    {
        // Version 2: multiple values go to prop_mN, single ones are a column in prop_sN.
        $versionTwoTable = 'b_iblock_element_prop_'.($multiple ? 'm' : 's').$iblockId;

        if ($this->tableExists($versionTwoTable)) {
            if ($multiple) {
                return $this->connection
                    ->table($versionTwoTable)
                    ->where('IBLOCK_PROPERTY_ID', $propertyId)
                    ->whereNotNull('VALUE')
                    ->select('VALUE');
            }

            $column = 'PROPERTY_'.$propertyId;

            if (! in_array($column, $this->getTableColumns($versionTwoTable), true)) {
                return null;
            }

            return $this->connection
                ->table($versionTwoTable)
                ->whereNotNull($column)
                ->where($column, '<>', '')
                ->select($column);
        }

        if (! $this->tableExists('b_iblock_element_property')) {
            return null;
        }

        return $this->connection
            ->table('b_iblock_element_property')
            ->where('IBLOCK_PROPERTY_ID', $propertyId)
            ->whereNotNull('VALUE')
            ->where('VALUE', '<>', '')
            ->select('VALUE');
    }

    /**
     * Get all Highload Blocks
     */
    public function getHighloadBlocks(): array
    {
        return $this->connection
            ->table('b_hlblock_entity')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Get single Highload Block
     */
    public function getHighloadBlock(int $id): ?array
    {
        $row = $this->connection
            ->table('b_hlblock_entity')
            ->where('ID', $id)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * Get Highload Block by table name
     */
    public function getHighloadBlockByTableName(string $tableName): ?array
    {
        $row = $this->connection
            ->table('b_hlblock_entity')
            ->where('TABLE_NAME', $tableName)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * Get Highload Block fields (user fields)
     */
    public function getHighloadBlockFields(int $hlblockId): array
    {
        $entityId = 'HLBLOCK_'.$hlblockId;

        return $this->connection
            ->table('b_user_field')
            ->where('ENTITY_ID', $entityId)
            ->orderBy('SORT')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Get user field enum values
     */
    public function getUserFieldEnumValues(int $fieldId): array
    {
        return $this->connection
            ->table('b_user_field_enum')
            ->where('USER_FIELD_ID', $fieldId)
            ->orderBy('SORT')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Get Highload Block data
     */
    public function getHighloadBlockData(string $tableName, int $limit = 100, int $offset = 0): array
    {
        if (! $this->tableExists($tableName)) {
            return [];
        }

        return $this->connection
            ->table($tableName)
            ->orderBy('ID')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Get Highload Block data count
     */
    public function getHighloadBlockDataCount(string $tableName): int
    {
        if (! $this->tableExists($tableName)) {
            return 0;
        }

        return $this->connection
            ->table($tableName)
            ->count();
    }

    /**
     * Get file info by ID
     */
    public function getFile(int $fileId): ?array
    {
        $row = $this->connection
            ->table('b_file')
            ->where('ID', $fileId)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * Check if table exists
     */
    public function tableExists(string $tableName): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($tableName);
    }

    /**
     * Get table columns
     */
    public function getTableColumns(string $tableName): array
    {
        return $this->connection->getSchemaBuilder()->getColumnListing($tableName);
    }

    /**
     * Get all element IDs for an IBlock
     */
    public function getIBlockElementIds(int $iblockId): array
    {
        return $this->connection
            ->table('b_iblock_element')
            ->where('IBLOCK_ID', $iblockId)
            ->orderBy('ID')
            ->pluck('ID')
            ->toArray();
    }

    /**
     * Get single element by ID
     */
    public function getIBlockElementById(int $elementId): ?array
    {
        $row = $this->connection
            ->table('b_iblock_element')
            ->where('ID', $elementId)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * Get multiple property values count for a specific property in an IBlock
     */
    public function getMultiplePropertyValuesCount(int $iblockId, int $propertyId): int
    {
        $tableName = 'b_iblock_element_prop_m'.$iblockId;

        // Version 1 iblocks keep every value in the shared table.
        if (! $this->tableExists($tableName)) {
            $tableName = 'b_iblock_element_property';
        }

        return $this->connection
            ->table($tableName)
            ->where('IBLOCK_PROPERTY_ID', $propertyId)
            ->count();
    }
}
