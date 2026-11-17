<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Support\SectionUserFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MigrateDataCommand extends AbstractBitrixCommand
{
    protected $signature = 'atrium:bitrix:migrate:data
                            {--connection= : Database connection name}
                            {--iblock=* : Specific IBlock IDs to migrate}
                            {--hlblock=* : Specific Highload Block IDs to migrate}
                            {--chunk=100 : Number of records to process at once}
                            {--skip-files : Skip file migration}
                            {--force : Run without confirmation}';

    protected $description = 'Migrate data from Bitrix to Laravel';

    protected int $chunkSize;

    protected bool $skipFiles;

    /**
     * Cache for JSON column names per table
     */
    protected array $jsonColumnsCache = [];

    /**
     * Reserved column names - properties with these codes will be skipped
     */
    protected array $reservedColumns = [
        'id', 'bitrix_id', 'name', 'code', 'sort', 'active',
        'active_from', 'active_to', 'preview_text', 'preview_text_type', 'preview_picture',
        'detail_text', 'detail_text_type', 'detail_picture', 'section_id',
        'created_at', 'updated_at', 'deleted_at',
    ];

    public function handle(): int
    {
        $this->info('Bitrix Data Migration');
        $this->info('=====================');
        $this->newLine();

        if (! $this->verifyConnection()) {
            return self::FAILURE;
        }

        $this->chunkSize = (int) $this->option('chunk');
        $this->skipFiles = (bool) $this->option('skip-files');

        $iblockIds = $this->idsOption('iblock');
        $hlblockIds = $this->idsOption('hlblock');

        // Migrate Highload Blocks first (they may be referenced by IBlocks)
        $hlblockIds = $hlblockIds ?: $this->selectHlblocks();
        if (! empty($hlblockIds)) {
            $this->migrateHlblocks($hlblockIds);
        }

        // Migrate IBlocks
        $iblockIds = $iblockIds ?: $this->selectIBlocks();
        if (! empty($iblockIds)) {
            $this->migrateIBlocks($iblockIds);
        }

        $this->newLine();
        $this->info('Data migration complete!');

        return self::SUCCESS;
    }

    /**
     * Migrate Highload Blocks data
     */
    protected function migrateHlblocks(array $hlblockIds): void
    {
        $this->info('Migrating Highload Block data...');

        $analyzer = $this->getHlblockAnalyzer();
        $connection = $this->getBitrixConnection();
        $naming = $this->getNaming();

        foreach ($hlblockIds as $hlblockId) {
            $hlblock = $analyzer->analyze($hlblockId);
            if (! $hlblock) {
                $this->warn("Highload Block {$hlblockId} not found");

                continue;
            }

            $laravelTable = $naming->hlblockToTableName($hlblock->tableName);

            $this->newLine();
            $this->info("Migrating: {$hlblock->name} -> {$laravelTable}");

            $totalCount = $connection->getHighloadBlockDataCount($hlblock->tableName);

            if ($totalCount === 0) {
                $this->warn('  No data to migrate');

                continue;
            }

            $this->info("  Records to migrate: {$totalCount}");

            $bar = $this->output->createProgressBar($totalCount);
            $bar->start();

            $offset = 0;
            while ($offset < $totalCount) {
                $rows = $connection->getHighloadBlockData($hlblock->tableName, $this->chunkSize, $offset);

                foreach ($rows as $row) {
                    $data = $this->transformHlblockRow($row, $hlblock);
                    $data['created_at'] = now();
                    $data['updated_at'] = now();
                    $data = $this->prepareDataForInsert($data, $laravelTable);

                    try {
                        DB::table($laravelTable)->insert($data);
                    } catch (\Exception $e) {
                        $this->newLine();
                        $this->error("  Error inserting row {$row['ID']}: {$e->getMessage()}");
                    }

                    $bar->advance();
                }

                $offset += $this->chunkSize;
            }

            $bar->finish();
            $this->newLine();
        }
    }

    /**
     * Transform Highload Block row
     */
    protected function transformHlblockRow(array $row, $hlblock): array
    {
        $naming = $this->getNaming();
        $data = ['id' => $row['ID']];

        foreach ($hlblock->fields as $field) {
            $bitrixColumn = $field->fieldName;
            $laravelColumn = $naming->ufFieldToColumnName($field->fieldName);

            if (! isset($row[$bitrixColumn])) {
                continue;
            }

            $value = $row[$bitrixColumn];

            // Transform value based on type
            if ($field->isBoolean()) {
                $value = in_array($value, ['Y', '1', 1, true], true);
            } elseif ($field->isFile() && ! $this->skipFiles) {
                $value = $this->migrateFile($value);
            } elseif ($field->multiple && is_string($value)) {
                $value = @unserialize($value) ?: null;
                if (is_array($value)) {
                    // Return null for empty arrays, valid JSON for non-empty
                    $value = json_encode($value);
                }
            }

            $data[$laravelColumn] = $value;
        }

        return $data;
    }

    /**
     * Migrate IBlocks data
     */
    protected function migrateIBlocks(array $iblockIds): void
    {
        $this->info('Migrating IBlock data...');

        $analyzer = $this->getIBlockAnalyzer();

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

            // Migrate sections first
            if (! empty($iblock->sections)) {
                $this->migrateSections($iblock);
            }

            // Migrate elements
            $this->migrateElements($iblock);
        }

        $this->writeDeferredLinks();
    }

    /**
     * Elements whose links (link tables) are written after all iblocks.
     *
     * A link may point at an iblock that is migrated later. MySQL lets the
     * row in with foreign key checks off, but PostgreSQL cannot switch them
     * off (SET CONSTRAINTS only defers deferrable keys), and the link was lost.
     *
     * @var array<int,array{iblock:IBlock,table:string,ids:array<int,int>}>
     */
    protected array $deferredLinks = [];

    protected function deferLinks(IBlock $iblock, string $table, int $elementId): void
    {
        foreach ($iblock->properties as $property) {
            if ($property->needsPivotTable()) {
                $this->deferredLinks[$iblock->id] ??= ['iblock' => $iblock, 'table' => $table, 'ids' => []];
                $this->deferredLinks[$iblock->id]['ids'][] = $elementId;

                return;
            }
        }
    }

    protected function writeDeferredLinks(): void
    {
        foreach ($this->deferredLinks as $pending) {
            foreach ($pending['ids'] as $id) {
                $this->migrateElementPivotData(['ID' => $id], $pending['iblock'], $pending['table']);
            }
        }

        $this->deferredLinks = [];
    }

    /**
     * Migrate IBlock sections
     */
    protected function migrateSections(IBlock $iblock): void
    {
        $connection = $this->getBitrixConnection();
        $naming = $this->getNaming();

        $laravelTable = $naming->toSectionTableName($iblock->code);

        $this->newLine();
        $this->info("Migrating sections: {$iblock->name} -> {$laravelTable}");

        $sections = $connection->getIBlockSections($iblock->id);

        // Section user fields (UF_*). They live not in b_iblock_property but in
        // b_user_field/b_uts_iblock_{id}_section, so they used to be skipped
        // entirely and their values were lost.
        $userFields = SectionUserFields::load($connection->getConnection(), $iblock->id, $naming);
        $userFieldValues = $userFields->values($connection->getConnection(), $iblock->id);
        $sectionColumns = Schema::hasTable($laravelTable) ? Schema::getColumnListing($laravelTable) : [];

        if (empty($sections)) {
            $this->warn('  No sections to migrate');

            return;
        }

        $this->info('  Sections to migrate: '.count($sections));

        // Build parent mapping
        $parentMap = [];
        foreach ($sections as $section) {
            $parentMap[$section['ID']] = $section['IBLOCK_SECTION_ID'];
        }

        $bar = $this->output->createProgressBar(count($sections));
        $bar->start();

        foreach ($sections as $section) {
            $data = [
                'id' => $section['ID'],
                'bitrix_id' => $section['ID'],
                'parent_id' => $section['IBLOCK_SECTION_ID'] ?: null,
                'name' => $section['NAME'],
                'code' => $section['CODE'],
                'sort' => $section['SORT'] ?? 500,
                'active' => ($section['ACTIVE'] ?? 'Y') === 'Y',
                'description' => $section['DESCRIPTION'],
                'depth' => $section['DEPTH_LEVEL'] ?? 0,
                'left_margin' => $section['LEFT_MARGIN'] ?? 0,
                'right_margin' => $section['RIGHT_MARGIN'] ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            foreach ($userFieldValues[(int) $section['ID']] ?? [] as $column => $value) {
                // The column exists only if the migrations were regenerated;
                // on an old schema skip it silently instead of failing the transfer.
                if (in_array($column, $sectionColumns, true)) {
                    $data[$column] = $value;
                }
            }

            // Migrate pictures
            if (! $this->skipFiles) {
                if (! empty($section['PICTURE'])) {
                    $data['picture'] = $this->migrateFile($section['PICTURE']);
                }
                if (! empty($section['DETAIL_PICTURE'])) {
                    $data['detail_picture'] = $this->migrateFile($section['DETAIL_PICTURE']);
                }
            }

            $data = $this->prepareDataForInsert($data, $laravelTable);

            try {
                DB::table($laravelTable)->insert($data);
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("  Error inserting section {$section['ID']}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Migrate IBlock elements
     */
    protected function migrateElements(IBlock $iblock): void
    {
        $connection = $this->getBitrixConnection();
        $naming = $this->getNaming();

        $laravelTable = $naming->toTableName($iblock->code);

        $this->newLine();
        $this->info("Migrating elements: {$iblock->name} -> {$laravelTable}");

        $totalCount = $connection->getIBlockElementCount($iblock->id);

        if ($totalCount === 0) {
            $this->warn('  No elements to migrate');

            return;
        }

        $this->info("  Elements to migrate: {$totalCount}");

        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        $offset = 0;
        while ($offset < $totalCount) {
            $elements = $connection->getIBlockElements($iblock->id, $this->chunkSize, $offset);

            foreach ($elements as $element) {
                $data = $this->transformElement($element, $iblock);
                $data = $this->prepareDataForInsert($data, $laravelTable);

                try {
                    DB::table($laravelTable)->insert($data);

                    // Links to other elements are written once every iblock is in
                    $this->deferLinks($iblock, $laravelTable, (int) $element['ID']);

                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("  Error inserting element {$element['ID']}: {$e->getMessage()}");
                }

                $bar->advance();
            }

            $offset += $this->chunkSize;
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Transform element row
     */
    protected function transformElement(array $element, IBlock $iblock): array
    {
        $connection = $this->getBitrixConnection();
        $naming = $this->getNaming();

        $data = [
            'id' => $element['ID'],
            'bitrix_id' => $element['ID'],
            'name' => $element['NAME'],
            'code' => $element['CODE'],
            'sort' => $element['SORT'] ?? 500,
            'active' => ($element['ACTIVE'] ?? 'Y') === 'Y',
            // The real columns in b_iblock_element are ACTIVE_FROM/ACTIVE_TO (not DATE_ACTIVE_*);
            // with the wrong names, activity dates were not transferred for any iblock.
            'active_from' => $this->parseDate($element['ACTIVE_FROM'] ?? null),
            'active_to' => $this->parseDate($element['ACTIVE_TO'] ?? null),
            'preview_text' => $element['PREVIEW_TEXT'],
            'preview_text_type' => $element['PREVIEW_TEXT_TYPE'] ?? 'text',
            'detail_text' => $element['DETAIL_TEXT'],
            'detail_text_type' => $element['DETAIL_TEXT_TYPE'] ?? 'text',
            'created_at' => $this->parseDate($element['DATE_CREATE']) ?? now(),
            'updated_at' => $this->parseDate($element['TIMESTAMP_X']) ?? now(),
        ];

        // Only add section_id if iblock has sections
        if (! empty($iblock->sections)) {
            $data['section_id'] = $element['IBLOCK_SECTION_ID'] ?: null;
        }

        // Migrate pictures
        if (! $this->skipFiles) {
            if (! empty($element['PREVIEW_PICTURE'])) {
                $data['preview_picture'] = $this->migrateFile($element['PREVIEW_PICTURE']);
            }
            if (! empty($element['DETAIL_PICTURE'])) {
                $data['detail_picture'] = $this->migrateFile($element['DETAIL_PICTURE']);
            }
        }

        // Get property values
        $propertyValues = $iblock->version === 2
            ? $this->getPropertyValuesV2($element['ID'], $iblock)
            : $this->getPropertyValuesV1($element['ID'], $iblock);

        // Transform property values
        foreach ($iblock->properties as $property) {
            if ($property->needsPivotTable()) {
                continue; // Handled separately
            }

            $columnName = $naming->toColumnName($property->code);

            // Skip reserved columns (already populated from base element fields)
            if (in_array($columnName, $this->reservedColumns, true)) {
                continue;
            }

            $value = $this->getPropertyValue($property, $propertyValues, $iblock->version);

            if ($value !== null) {
                $transformedValue = $this->transformPropertyValue($property, $value);
                if ($transformedValue !== null) {
                    $data[$columnName] = $transformedValue;
                }
            }
        }

        return $data;
    }

    /**
     * Get property values for version 1 IBlock
     */
    protected function getPropertyValuesV1(int $elementId, IBlock $iblock): array
    {
        $connection = $this->getBitrixConnection();
        $values = $connection->getElementPropertyValues($iblock->id, $elementId);

        $result = [];
        foreach ($values as $value) {
            $propertyId = $value['IBLOCK_PROPERTY_ID'];

            // Find property by ID
            foreach ($iblock->properties as $property) {
                if ($property->id === (int) $propertyId) {
                    if ($property->multiple) {
                        if (! isset($result[$property->code])) {
                            $result[$property->code] = [];
                        }
                        $result[$property->code][] = $value['VALUE'];
                    } else {
                        $result[$property->code] = $value['VALUE'];
                    }
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Get property values for version 2 IBlock
     *
     * Single values are columns of b_iblock_element_prop_sN. Multiple values
     * are rows of b_iblock_element_prop_mN: the column of the same name in
     * prop_sN is only a cache Bitrix may leave empty.
     */
    protected function getPropertyValuesV2(int $elementId, IBlock $iblock): ?array
    {
        $connection = $this->getBitrixConnection();
        $values = $connection->getElementPropertyValuesV2($iblock->id, $elementId);

        $multiple = [];
        foreach ($connection->getElementMultiplePropertyValues($iblock->id, $elementId) as $row) {
            $multiple['PROPERTY_'.$row['IBLOCK_PROPERTY_ID']][] = $row['VALUE'];
        }

        foreach ($iblock->properties as $property) {
            if ($property->multiple) {
                $values['PROPERTY_'.$property->id] = $multiple['PROPERTY_'.$property->id] ?? null;
            }
        }

        return $values;
    }

    /**
     * Get property value from array
     */
    protected function getPropertyValue(IBlockProperty $property, ?array $values, int $version): mixed
    {
        if ($values === null) {
            return null;
        }

        if ($version === 2) {
            $columnName = 'PROPERTY_'.$property->id;

            return $values[$columnName] ?? null;
        }

        return $values[$property->code] ?? null;
    }

    /**
     * Transform property value
     */
    protected function transformPropertyValue(IBlockProperty $property, mixed $value): mixed
    {
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
            return null;
        }

        // Handle arrays of serialized values (multiple-value properties)
        if (is_array($value)) {
            $hasSerializedElements = false;
            foreach ($value as $v) {
                if (is_string($v) && preg_match('/^[aOsbNid]:[0-9]+/', $v)) {
                    $hasSerializedElements = true;
                    break;
                }
            }

            if ($hasSerializedElements) {
                $unserialized = [];
                foreach ($value as $v) {
                    if (is_string($v) && preg_match('/^[aOsbNid]:[0-9]+/', $v)) {
                        $u = @unserialize($v);
                        if ($u !== false || $v === 'b:0;') {
                            $unserialized[] = $u;
                        } else {
                            $unserialized[] = $v;
                        }
                    } else {
                        $unserialized[] = $v;
                    }
                }
                $value = $unserialized;
            }
        }

        // Handle serialized data (check if it looks like serialized PHP)
        if (is_string($value) && preg_match('/^[aOsbNid]:[0-9]+/', $value)) {
            $unserialized = @unserialize($value);
            if ($unserialized !== false || $value === 'b:0;') {
                $value = $unserialized;
                // Check again after unserialization - value might become empty
                if ($value === null || $value === '' || $value === false || (is_string($value) && trim($value) === '')) {
                    return null;
                }
            }
        }

        // Transform by type
        if ($property->isCheckbox()) {
            return in_array($value, ['Y', '1', 1, true], true);
        }

        if ($property->isFile() && ! $this->skipFiles) {
            if (is_array($value)) {
                if (empty($value)) {
                    return null;
                }
                $files = array_filter(array_map([$this, 'migrateFile'], $value));

                return empty($files) ? null : json_encode(array_values($files));
            }

            return $this->migrateFile($value);
        }

        // A directory property holds the UF_XML_ID of a highload block record;
        // our column is a key to the copied table, so look the record up.
        if ($property->isDirectory() && ! $property->multiple) {
            $table = $property->directoryTableName
                ? $this->getNaming()->hlblockToTableName($property->directoryTableName)
                : null;

            if ($table === null || ! is_scalar($value) || ! Schema::hasTable($table)) {
                return null;
            }

            return DB::table($table)->where('xml_id', (string) $value)->value('id');
        }

        if ($property->isList()) {
            // Handle multiple list values
            if (is_array($value)) {
                $result = [];
                foreach ($value as $v) {
                    foreach ($property->enumValues as $enum) {
                        if ($enum->id === (int) $v) {
                            $result[] = $enum->xmlId ?: (string) $enum->id;
                            break;
                        }
                    }
                }

                // Return null for empty arrays
                return empty($result) ? null : json_encode($result);
            }
            // Convert enum ID to XML_ID
            foreach ($property->enumValues as $enum) {
                if ($enum->id === (int) $value) {
                    return $enum->xmlId ?: (string) $enum->id;
                }
            }

            return is_scalar($value) ? $value : null;
        }

        if ($property->isHtml() && is_array($value)) {
            // Single HTML value: ['TEXT' => '...', 'TYPE' => 'HTML']
            if (isset($value['TEXT'])) {
                $text = $value['TEXT'];

                return ($text === '' || (is_string($text) && trim($text) === '')) ? null : $text;
            }

            // Multiple HTML values: [['TEXT' => '...'], ['TEXT' => '...'], ...]
            $texts = [];
            foreach ($value as $item) {
                if (is_array($item) && isset($item['TEXT'])) {
                    $text = $item['TEXT'];
                    if ($text !== '' && (! is_string($text) || trim($text) !== '')) {
                        $texts[] = $text;
                    }
                }
            }

            if (empty($texts)) {
                return null;
            }

            return json_encode($texts);
        }

        // Always encode arrays to JSON
        if (is_array($value)) {
            // Return null for empty arrays instead of empty JSON
            if (empty($value)) {
                return null;
            }

            return json_encode($value);
        }

        // Ensure scalar value
        if (! is_scalar($value)) {
            return null;
        }

        // Handle empty strings for JSON columns - return null
        if ($value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Migrate element pivot data
     */
    protected function migrateElementPivotData(array $element, IBlock $iblock, string $mainTable): void
    {
        $connection = $this->getBitrixConnection();
        $naming = $this->getNaming();

        Schema::disableForeignKeyConstraints();

        foreach ($iblock->properties as $property) {
            if (! $property->needsPivotTable()) {
                continue;
            }

            // Get multiple values
            $propertyValues = $iblock->version === 2
                ? $connection->getElementMultiplePropertyValues($iblock->id, $element['ID'])
                : $this->getPropertyValuesV1($element['ID'], $iblock);

            // Find values for this property
            $values = [];
            if ($iblock->version === 2) {
                foreach ($propertyValues as $pv) {
                    if (($pv['IBLOCK_PROPERTY_ID'] ?? null) === $property->id) {
                        $values[] = $pv['VALUE'];
                    }
                }
            } else {
                $values = $propertyValues[$property->code] ?? [];
                if (! is_array($values)) {
                    $values = [$values];
                }
            }

            if (empty($values)) {
                continue;
            }

            // Insert into pivot table
            $pivotTable = $mainTable.'_'.$naming->toColumnName($property->code);
            $singularMainTable = Str::singular($mainTable);

            foreach ($values as $index => $value) {
                if (empty($value)) {
                    continue;
                }

                $pivotData = [
                    "{$singularMainTable}_id" => $element['ID'],
                    'sort' => $index,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Determine foreign key based on property code (same formula as in ElementMigrationGenerator)
                $columnName = $naming->toColumnName($property->code);
                $relatedKey = Str::singular($columnName).'_id';

                if ($property->isElementLink()) {
                    $pivotData[$relatedKey] = $value;
                } elseif ($property->isSectionLink()) {
                    $pivotData['section_id'] = $value;
                } elseif ($property->isDirectory()) {
                    $relatedTable = $naming->hlblockToTableName($property->directoryTableName);

                    // Directory values are XML_ID, need to find Laravel ID
                    $relatedId = DB::table($relatedTable)
                        ->where('xml_id', $value)
                        ->value('id');

                    if (! $relatedId) {
                        continue; // Skip if related record not found
                    }

                    $pivotData[$relatedKey] = $relatedId;
                }

                try {
                    DB::table($pivotTable)->insertOrIgnore($pivotData);
                } catch (\Exception $e) {
                    // Silently skip errors (duplicates, constraint violations)
                }
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Parse date value safely
     */
    protected function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        // If it's already a valid date string, return it
        if (is_string($value)) {
            try {
                $date = new \DateTime($value);

                return $date->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                return null;
            }
        }

        // If it's an array or other non-scalar, return null
        if (! is_scalar($value)) {
            return null;
        }

        return null;
    }

    /**
     * Migrate file from Bitrix
     */
    protected function migrateFile(mixed $fileId): ?string
    {
        if (empty($fileId)) {
            return null;
        }

        $connection = $this->getBitrixConnection();
        $file = $connection->getFile((int) $fileId);

        if (! $file) {
            return null;
        }

        $relativePath = $file['SUBDIR'].'/'.$file['FILE_NAME'];

        // Check if file already exists in public/upload (Bitrix files location)
        $publicUploadPath = public_path('upload/'.$relativePath);
        if (file_exists($publicUploadPath)) {
            // File already in public/upload, return absolute URL path
            return '/upload/'.$relativePath;
        }

        // Build source path from config
        $uploadPath = config('atrium-bitrix.upload_path');
        if (! $uploadPath) {
            // Return relative path for later processing
            return '/upload/'.$relativePath;
        }

        $sourcePath = $uploadPath.'/'.$relativePath;

        if (! file_exists($sourcePath)) {
            return '/upload/'.$relativePath;
        }

        // Copy to Laravel storage
        $destDir = storage_path('app/public/uploads/'.$file['SUBDIR']);
        if (! is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $destPath = $destDir.'/'.$file['FILE_NAME'];
        copy($sourcePath, $destPath);

        return '/storage/uploads/'.$relativePath;
    }

    /**
     * Get JSON column names for a table
     */
    protected function getJsonColumns(string $table): array
    {
        if (! isset($this->jsonColumnsCache[$table])) {
            $this->jsonColumnsCache[$table] = collect(Schema::getColumns($table))
                ->filter(fn (array $column): bool => in_array(strtolower((string) $column['type_name']), ['json', 'jsonb'], true))
                ->pluck('name')
                ->all();
        }

        return $this->jsonColumnsCache[$table];
    }

    /**
     * Prepare data for insertion by properly encoding JSON columns
     */
    protected function prepareDataForInsert(array $data, string $table): array
    {
        $jsonColumns = $this->getJsonColumns($table);

        foreach ($data as $column => $value) {
            if (in_array($column, $jsonColumns) && $value !== null) {
                // If value is a scalar (string, number), encode it as JSON
                if (is_scalar($value) && ! $this->isValidJson($value)) {
                    $data[$column] = json_encode($value);
                }
            }
        }

        return $data;
    }

    /**
     * Check if a string is valid JSON
     */
    protected function isValidJson(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        // Quick checks for common JSON patterns
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        $firstChar = $trimmed[0];
        if (! in_array($firstChar, ['{', '[', '"'])) {
            return false;
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
