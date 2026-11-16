<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Generators;

use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Atrium\Bitrix\Schema\HighloadBlock;
use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Support\NamingConvention;
use Illuminate\Database\ConnectionInterface;

class MigrationGenerator
{
    protected NamingConvention $naming;

    protected PropertyTypeMapper $typeMapper;

    protected ElementMigrationGenerator $elementGenerator;

    protected SectionMigrationGenerator $sectionGenerator;

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
    protected ?string $timestamp = null;

    protected int $sequence = 0;

    protected function filename(string $tableName): string
    {
        $this->timestamp ??= date('Y_m_d_His');

        return sprintf('%s_%04d_create_%s_table.php', $this->timestamp, ++$this->sequence, $tableName);
    }

    /**
     * All migrations for an IBlock: its tables, then its link tables.
     *
     * When several iblocks link to each other, generate the tables of all of
     * them first (generateTablesForIBlock) and the link tables after
     * (generatePivotsForIBlock).
     */
    public function generateForIBlock(IBlock $iblock): array
    {
        return $this->generateTablesForIBlock($iblock) + $this->generatePivotsForIBlock($iblock);
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
        $className = $this->naming->toMigrationClassName($tableName);

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
    protected function generateHlblockMigration(HighloadBlock $hlblock, string $tableName): string
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
    protected function buildColumnDefinition(string $name, array $def): string
    {
        $type = $def['type'];
        $line = '$table->';

        switch ($type) {
            case 'string':
                $length = $def['length'] ?? 255;
                $line .= "string('{$name}', {$length})";
                break;

            case 'text':
                $line .= "text('{$name}')";
                break;

            case 'longText':
                $line .= "longText('{$name}')";
                break;

            case 'integer':
                $line .= "integer('{$name}')";
                break;

            case 'unsignedBigInteger':
                $line .= "unsignedBigInteger('{$name}')";
                break;

            case 'decimal':
                $precision = $def['precision'] ?? 18;
                $scale = $def['scale'] ?? 4;
                $line .= "decimal('{$name}', {$precision}, {$scale})";
                break;

            case 'boolean':
                $line .= "boolean('{$name}')";
                break;

            case 'date':
                $line .= "date('{$name}')";
                break;

            case 'datetime':
                $line .= "dateTime('{$name}')";
                break;

            case 'json':
                $line .= "json('{$name}')";
                break;

            case 'foreignId':
                $line .= "foreignId('{$name}')";
                break;

            default:
                $line .= "string('{$name}')";
        }

        if ($def['nullable'] ?? false) {
            $line .= '->nullable()';
        }

        if (isset($def['default'])) {
            $default = is_bool($def['default'])
                ? ($def['default'] ? 'true' : 'false')
                : "'{$def['default']}'";
            $line .= "->default({$default})";
        }

        $line .= ';';

        return $line;
    }

    /**
     * Get section migration filename
     */
    protected function getSectionMigrationFilename(IBlock $iblock): string
    {
        $tableName = $this->naming->toSectionTableName($iblock->code);

        return $this->naming->toMigrationFileName($tableName);
    }

    /**
     * Get element migration filename
     */
    protected function getElementMigrationFilename(IBlock $iblock): string
    {
        $tableName = $this->naming->toTableName($iblock->code);

        return $this->naming->toMigrationFileName($tableName);
    }

    /**
     * Get migrations directory path
     */
    public function getMigrationsPath(): string
    {
        $path = config('atrium-bitrix.migration_path');

        return is_string($path) && $path !== '' ? $path : database_path('migrations');
    }

    /**
     * Write migration to file
     */
    public function writeMigration(string $filename, string $content): string
    {
        $path = $this->getMigrationsPath().'/'.$filename;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Write all migrations for IBlock
     */
    public function writeIBlockMigrations(IBlock $iblock): array
    {
        $migrations = $this->generateForIBlock($iblock);
        $paths = [];

        foreach ($migrations as $key => $migration) {
            $paths[$key] = $this->writeMigration(
                $migration['filename'],
                $migration['content']
            );
        }

        return $paths;
    }

    /**
     * Write migration for Highload Block
     */
    public function writeHlblockMigration(HighloadBlock $hlblock): array
    {
        $migrations = $this->generateForHlblock($hlblock);
        $paths = [];

        foreach ($migrations as $key => $migration) {
            $paths[$key] = $this->writeMigration(
                $migration['filename'],
                $migration['content']
            );
        }

        return $paths;
    }
}
