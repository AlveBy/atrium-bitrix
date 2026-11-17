<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Generators;

use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Support\ReservedColumns;
use Illuminate\Support\Str;

class ElementMigrationGenerator
{
    private NamingConvention $naming;

    private PropertyTypeMapper $typeMapper;

    public function __construct(NamingConvention $naming, PropertyTypeMapper $typeMapper)
    {
        $this->naming = $naming;
        $this->typeMapper = $typeMapper;
    }

    /**
     * Generate element table migration
     */
    public function generate(IBlock $iblock): string
    {
        $tableName = $this->naming->toTableName($iblock->code);
        $sectionTableName = $this->naming->toSectionTableName($iblock->code);
        $hasSections = ! empty($iblock->sections);

        $columns = $this->generateColumns($iblock);
        $foreignKeys = $this->generateForeignKeys($iblock);
        $indexes = $this->generateIndexes($iblock);

        $columnsStr = implode("\n            ", $columns);
        $foreignKeysStr = ! empty($foreignKeys) ? "\n\n            // Foreign keys\n            ".implode("\n            ", $foreignKeys) : '';
        $indexesStr = ! empty($indexes) ? "\n\n            // Indexes\n            ".implode("\n            ", $indexes) : '';

        // Section column (only if IBlock has sections)
        $sectionColumn = $hasSections
            ? "\$table->foreignId('section_id')->nullable()->constrained('{$sectionTableName}')->nullOnDelete();"
            : '';

        $sectionComment = $hasSections ? "\n\n            // Section\n            {$sectionColumn}" : '';

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->id();
            \$table->unsignedBigInteger('bitrix_id')->nullable()->index();

            // Base fields
            \$table->string('name');
            \$table->string('code')->nullable()->index();
            \$table->integer('sort')->default(500);
            \$table->boolean('active')->default(true)->index();
            \$table->dateTime('active_from')->nullable();
            \$table->dateTime('active_to')->nullable();

            // Content fields
            \$table->longText('preview_text')->nullable();
            \$table->string('preview_text_type')->default('text');
            \$table->string('preview_picture')->nullable();
            \$table->longText('detail_text')->nullable();
            \$table->string('detail_text_type')->default('text');
            \$table->string('detail_picture')->nullable();{$sectionComment}

            // Property columns
            {$columnsStr}{$foreignKeysStr}{$indexesStr}

            \$table->timestamps();
            \$table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;
    }

    /**
     * Generate property columns
     */
    private function generateColumns(IBlock $iblock): array
    {
        $columns = [];

        foreach ($iblock->properties as $property) {
            // Skip properties that need pivot tables
            if ($property->needsPivotTable()) {
                continue;
            }

            $columnName = $this->naming->toColumnName($property->code);

            // Skip properties that conflict with base element columns (ReservedColumns is shared with
            // ModelGenerator, so the migration and the model agree on which codes the base structure owns)
            if (ReservedColumns::has($columnName)) {
                continue;
            }

            $columns[] = $this->buildColumnDefinition($columnName, $this->typeMapper->toMigrationColumn($property), $property);
        }

        return $columns;
    }

    /**
     * Build column definition string
     */
    private function buildColumnDefinition(string $name, array $def, IBlockProperty $property): string
    {
        $type = $def['type'];
        $line = '$table->';
        $comment = $property->name;

        $arguments = match ($type) {
            'string' => ", {$def['length']}",
            'decimal' => ", {$def['precision']}, {$def['scale']}",
            default => '',
        };
        $method = $type === 'datetime' ? 'dateTime' : $type;
        $line .= "{$method}('{$name}'{$arguments})";

        if ($def['nullable'] ?? false) {
            $line .= '->nullable()';
        }

        if (isset($def['default'])) {
            $line .= '->default('.var_export($def['default'], true).')';
        }

        $line .= "->comment('{$comment}');";

        return $line;
    }

    /**
     * Generate foreign key constraints
     */
    private function generateForeignKeys(IBlock $iblock): array
    {
        $foreignKeys = [];

        foreach ($iblock->properties as $property) {
            $columnName = $this->naming->toColumnName($property->code);

            // Skip reserved columns
            if (ReservedColumns::has($columnName)) {
                continue;
            }

            if ($property->isElementLink() && ! $property->multiple) {
                if ($property->linkIblockCode) {
                    $relatedTable = $this->naming->toTableName($property->linkIblockCode);
                    $foreignKeys[] = "\$table->foreign('{$columnName}')->references('id')->on('{$relatedTable}')->nullOnDelete();";
                }
            }

            if ($property->isSectionLink() && ! $property->multiple) {
                if ($property->linkIblockCode) {
                    $relatedTable = $this->naming->toSectionTableName($property->linkIblockCode);
                    $foreignKeys[] = "\$table->foreign('{$columnName}')->references('id')->on('{$relatedTable}')->nullOnDelete();";
                }
            }

            if ($property->isDirectory() && ! $property->multiple) {
                // Only add FK if HLBlock was actually found during analysis
                if ($property->directoryHlblock) {
                    $relatedTable = $this->naming->hlblockToTableName($property->directoryHlblock->tableName);
                    $foreignKeys[] = "\$table->foreign('{$columnName}')->references('id')->on('{$relatedTable}')->nullOnDelete();";
                }
            }
        }

        return $foreignKeys;
    }

    /**
     * Generate indexes
     */
    private function generateIndexes(IBlock $iblock): array
    {
        $indexes = [];

        foreach ($iblock->properties as $property) {
            $columnName = $this->naming->toColumnName($property->code);

            // Skip reserved columns
            if (ReservedColumns::has($columnName)) {
                continue;
            }

            if ($this->typeMapper->getIndexType($property) === 'index') {
                $indexes[] = "\$table->index('{$columnName}');";
            }
        }

        return $indexes;
    }

    /**
     * Generate pivot table migrations for multiple relations
     *
     * @return array<string,array{table:string,content:string}> property column => link table
     */
    public function generatePivotMigrations(IBlock $iblock): array
    {
        $pivots = [];
        $mainTable = $this->naming->toTableName($iblock->code);

        foreach ($iblock->properties as $property) {
            if (! $property->needsPivotTable()) {
                continue;
            }

            $pivotContent = $this->generatePivotMigration($property, $mainTable, $iblock);
            if ($pivotContent) {
                $propertyCode = $this->naming->toColumnName($property->code);

                $pivots[$propertyCode] = [
                    'table' => "{$mainTable}_{$propertyCode}",
                    'content' => $pivotContent,
                ];
            }
        }

        return $pivots;
    }

    /**
     * Generate single pivot table migration
     */
    private function generatePivotMigration(IBlockProperty $property, string $mainTable, IBlock $iblock): ?string
    {
        $columnName = $this->naming->toColumnName($property->code);
        $pivotTableName = "{$mainTable}_{$columnName}";

        // Related key is always singular form of column name + _id
        $relatedKey = Str::singular($columnName).'_id';

        // Determine related table
        $relatedTable = null;

        if ($property->isElementLink()) {
            if ($property->linkIblockCode) {
                $relatedTable = $this->naming->toTableName($property->linkIblockCode);
            } else {
                // Self-referential: links to same table
                $relatedTable = $mainTable;
            }
        } elseif ($property->isSectionLink()) {
            if ($property->linkIblockCode) {
                $relatedTable = $this->naming->toSectionTableName($property->linkIblockCode);
            } else {
                $relatedTable = $this->naming->toSectionTableName($iblock->code);
            }
            $relatedKey = 'section_id';
        } elseif ($property->isDirectory() && $property->directoryHlblock) {
            // Only generate if HLBlock was actually found during analysis
            $relatedTable = $this->naming->hlblockToTableName($property->directoryHlblock->tableName);
        }

        if (! $relatedTable) {
            return null;
        }

        $singularMainTable = Str::singular($mainTable);

        // Generate short index/constraint names (max 64 chars for MySQL)
        $shortPivotName = substr($pivotTableName, 0, 40);
        $uniqueIndexName = $shortPivotName.'_unique';
        $fk1Name = $shortPivotName.'_fk1';
        $fk2Name = $shortPivotName.'_fk2';

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$pivotTableName}', function (Blueprint \$table) {
            \$table->id();
            \$table->unsignedBigInteger('{$singularMainTable}_id');
            \$table->unsignedBigInteger('{$relatedKey}');
            \$table->integer('sort')->default(0);
            \$table->timestamps();

            \$table->foreign('{$singularMainTable}_id', '{$fk1Name}')
                ->references('id')->on('{$mainTable}')->cascadeOnDelete();
            \$table->foreign('{$relatedKey}', '{$fk2Name}')
                ->references('id')->on('{$relatedTable}')->cascadeOnDelete();

            \$table->unique(['{$singularMainTable}_id', '{$relatedKey}'], '{$uniqueIndexName}');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$pivotTableName}');
    }
};
PHP;
    }
}
