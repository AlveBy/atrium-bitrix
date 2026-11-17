<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Generators;

use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Atrium\Bitrix\Schema\HighloadBlock;
use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Support\GeneratedPaths;
use Atrium\Bitrix\Support\NamingConvention;
use Illuminate\Database\ConnectionInterface;

class MigrationGenerator
{
    private NamingConvention $naming;

    private PropertyTypeMapper $typeMapper;

    private ElementMigrationGenerator $elementGenerator;

    private SectionMigrationGenerator $sectionGenerator;

    public function __construct(
        NamingConvention $naming,
        PropertyTypeMapper $typeMapper,
        ?ConnectionInterface $bitrix = null
    ) {
        $this->naming = $naming;
        $this->typeMapper = $typeMapper;
        $this->elementGenerator = new ElementMigrationGenerator($naming, $typeMapper);
        $this->sectionGenerator = new SectionMigrationGenerator($naming, $bitrix);
    }

    /**
     * One timestamp and a running number for every file of a run.
     *
     * Migrations run in file name order, and a table must exist before a
     * foreign key points at it. With a timestamp per iblock, a link table of
     * one iblock could come before the table of the iblock it links to, which
     * SQLite lets through and MySQL and PostgreSQL do not.
     */
    private ?string $timestamp = null;

    private int $sequence = 0;

    private function filename(string $tableName): string
    {
        $this->timestamp ??= now()->format('Y_m_d_His');

        return sprintf('%s_%04d_create_%s_table.php', $this->timestamp, ++$this->sequence, $tableName);
    }

    /**
     * The section table (if the iblock has sections), then the element table.
     */
    public function generateTablesForIBlock(IBlock $iblock): array
    {
        $migrations = [];

        if (! empty($iblock->sections)) {
            $migrations['sections'] = [
                'filename' => $this->filename($this->naming->toSectionTableName($iblock->code)),
                'content' => $this->sectionGenerator->generate($iblock),
            ];
        }

        $migrations['elements'] = [
            'filename' => $this->filename($this->naming->toTableName($iblock->code)),
            'content' => $this->elementGenerator->generate($iblock),
        ];

        return $migrations;
    }

    /**
     * Link tables of multiple relations.
     */
    public function generatePivotsForIBlock(IBlock $iblock): array
    {
        $migrations = [];

        foreach ($this->elementGenerator->generatePivotMigrations($iblock) as $key => $pivot) {
            $migrations["pivot_{$key}"] = [
                'filename' => $this->filename($pivot['table']),
                'content' => $pivot['content'],
            ];
        }

        return $migrations;
    }

    /**
     * Generate migration for Highload Block
     */
    public function generateForHlblock(HighloadBlock $hlblock): array
    {
        $tableName = $this->naming->hlblockToTableName($hlblock->tableName);

        $content = $this->generateHlblockMigration($hlblock, $tableName);

        return [
            'main' => [
                'filename' => $this->filename($tableName),
                'content' => $content,
            ],
        ];
    }

    /**
     * Generate HL block migration content
     */
    private function generateHlblockMigration(HighloadBlock $hlblock, string $tableName): string
    {
        $columns = [];

        foreach ($hlblock->fields as $field) {
            $columnName = $this->naming->ufFieldToColumnName($field->fieldName);
            $columnDef = $this->typeMapper->fieldToMigrationColumn($field);

            $columns[] = $this->buildColumnDefinition($columnName, $columnDef);
        }

        $columnsStr = implode("\n            ", $columns);

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
            {$columnsStr}
            \$table->timestamps();
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
     * Build column definition string
     */
    private function buildColumnDefinition(string $name, array $def): string
    {
        $type = $def['type'];
        $line = '$table->';

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

        $line .= ';';

        return $line;
    }

    /**
     * Get migrations directory path
     */
    public function getMigrationsPath(): string
    {
        return GeneratedPaths::migrations();
    }
}
