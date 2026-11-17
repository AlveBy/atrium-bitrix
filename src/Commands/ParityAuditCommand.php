<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Bitrix\Mappers\FieldTypeMapper;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockPropertyEnum;
use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Support\BitrixFormLayout;
use Atrium\Bitrix\Support\BitrixListLayout;
use Atrium\Bitrix\Support\BitrixSites;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Support\ProjectFields;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Compares the Bitrix IBlock structure (source of truth) against the Laravel
 * models + schema, property by property.
 *
 * Answers the question "does every field that existed in the Bitrix admin
 * exist in ours" exhaustively, which a UI crawl cannot do.
 */
class ParityAuditCommand extends Command
{
    protected $signature = 'atrium:bitrix:parity
        {--connection= : Bitrix DB connection name}
        {--iblock=* : Limit to specific IBlock IDs}
        {--format=table : table|json|markdown}
        {--output= : Write the report to this file}
        {--only-problems : Hide rows that are fully OK}';

    protected $description = 'Audit field parity between Bitrix IBlocks and Laravel admin models';

    /** Status constants, ordered by severity (worst first). */
    public const S_NO_MODEL = 'NO_MODEL';

    public const S_NO_COLUMN = 'NO_COLUMN';

    public const S_NO_FIELD = 'NO_FIELD';

    public const S_NOT_FILLABLE = 'NOT_FILLABLE';

    public const S_TYPE = 'TYPE_MISMATCH';

    public const S_MULTIPLE = 'MULTIPLE_LOST';

    public const S_REQUIRED = 'REQUIRED_MISMATCH';

    public const S_ENUM = 'ENUM_MISMATCH';

    public const S_OK = 'OK';

    public const S_INACTIVE = 'INACTIVE_IN_BITRIX';

    protected BitrixConnection $bitrix;

    protected NamingConvention $naming;

    protected FieldTypeMapper $mapper;

    /** @var array<int,array<string,mixed>> */
    protected array $rows = [];

    /** @var array<int,array<string,mixed>> */
    protected array $iblockRows = [];

    /** @var array<int,array<string,mixed>> */
    protected array $extraRows = [];

    /** @var array<int,array<string,mixed>> */
    protected array $siteRows = [];

    /** A Bitrix default site in a folder: Atrium keeps its default site at the root. */
    public const S_DEFAULT_AT_ROOT = 'DEFAULT_AT_ROOT';

    /**
     * Bitrix base element fields that every IBlock has, mapped to the column
     * our migrator generates for them.
     */
    protected const BASE_FIELDS = [
        'NAME' => 'name',
        'CODE' => 'code',
        'ACTIVE' => 'active',
        'SORT' => 'sort',
        'ACTIVE_FROM' => 'active_from',
        'ACTIVE_TO' => 'active_to',
        'PREVIEW_PICTURE' => 'preview_picture',
        'PREVIEW_TEXT' => 'preview_text',
        'DETAIL_PICTURE' => 'detail_picture',
        'DETAIL_TEXT' => 'detail_text',
    ];

    public function handle(): int
    {
        $this->rows = $this->iblockRows = $this->extraRows = $this->siteRows = [];
        $this->bitrix = new BitrixConnection($this->option('connection'));
        $this->naming = new NamingConvention;
        $this->mapper = new FieldTypeMapper;

        try {
            $iblocks = $this->bitrix->getIBlocks();
        } catch (\Throwable $e) {
            $this->error('Cannot read the Bitrix database: '.$e->getMessage());
            $this->line('Check BITRIX_DB_DATABASE in .env and that the dump is imported.');

            return self::FAILURE;
        }

        $filter = array_map('intval', (array) $this->option('iblock'));
        if ($filter !== []) {
            $iblocks = array_filter($iblocks, static fn (array $i): bool => in_array((int) $i['ID'], $filter, true));
        }

        $this->info(sprintf('Auditing %d IBlocks against Laravel models…', count($iblocks)));

        foreach ($iblocks as $iblock) {
            $this->auditIBlock($iblock);
        }

        $this->auditSites();

        $exitCode = $this->report();

        $this->auditLayouts($iblocks);
        $this->auditListColumns($iblocks);
        $this->auditWidgets($iblocks);

        return $exitCode;
    }

    /**
     * Compares list columns with the list settings in the Bitrix admin.
     *
     * The list is the first screen an editor opens, and its set of columns is
     * also configurable and stored in the database
     * (b_user_option, CATEGORY='list'). Before it was read, the generator
     * used "the first three properties".
     *
     * @param  array<int,array<string,mixed>>  $iblocks
     */
    protected function auditListColumns(array $iblocks): void
    {
        $rows = [];

        foreach ($iblocks as $iblock) {
            $iblockId = (int) $iblock['ID'];
            $modelClass = $this->resolveModel($iblock['CODE'] ?: null, $iblockId);

            if ($modelClass === null || ! method_exists($modelClass, 'getAdministrationListColumns')) {
                continue;
            }

            $codes = [];
            foreach ($this->bitrix->getIBlockProperties($iblockId) as $property) {
                $codes[(int) $property['ID']] = (string) $property['CODE'];
            }

            $layout = BitrixListLayout::load(
                $this->bitrix->getConnection(),
                (string) $iblock['IBLOCK_TYPE_ID'],
                $iblockId,
                $codes,
                $this->naming
            );

            if ($layout->isEmpty()) {
                continue;
            }

            $theirs = array_column($layout->columns(), 'name');
            $ours = array_map(static fn ($c) => $c->getName(), $modelClass::getAdministrationListColumns());

            // Columns we deliberately removed or added
            // (config/atrium-bitrix.php) are a decision, not a mismatch;
            // otherwise the audit would complain about them forever. The order
            // is computed by the same method as in the generator so the two
            // cannot drift apart.
            $table = (new $modelClass)->getTable();
            $hidden = ProjectFields::hiddenListColumnsFor($table);
            $added = ProjectFields::extraListColumnsFor($table);
            $theirs = ProjectFields::applyListOverrides($theirs, $table);

            $missing = array_diff($theirs, $ours);
            $extra = array_diff($ours, $theirs);
            $sameOrder = $theirs === $ours;

            $rows[] = [
                $iblock['CODE'] ?: ('iblock_'.$iblockId),
                count($theirs),
                $missing === [] ? '—' : implode(', ', $missing),
                $extra === [] ? '—' : implode(', ', $extra),
                $sameOrder ? 'yes' : 'no',
                self::describeOverrides($hidden, $added),
            ];
        }

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->info('List columns vs. list settings in Bitrix');
        $this->table(['IBlock', 'Columns', 'Missing here', 'Extra here', 'Same order', 'Decided otherwise'], $rows);

        $perfect = count(array_filter($rows, static fn (array $r): bool => $r[2] === '—' && $r[3] === '—' && $r[4] === 'yes'));
        $this->line(sprintf('Lists fully matching Bitrix: %d of %d', $perfect, count($rows)));
    }

    /**
     * @param  array<int,string>  $hidden
     * @param  array<int,string>  $extra
     */
    private static function describeOverrides(array $hidden, array $extra): string
    {
        $parts = [];

        if ($hidden !== []) {
            $parts[] = '−'.implode(', −', $hidden);
        }

        if ($extra !== []) {
            $parts[] = '+'.implode(', +', $extra);
        }

        return $parts === [] ? '—' : implode(' ', $parts);
    }

    /**
     * Compares widgets with the property settings in Bitrix.
     *
     * The main part of the report compares the model with what FieldTypeMapper
     * predicts, so the mapper effectively checks itself. Here it is the other
     * way round: take the raw property attributes (LIST_TYPE, FILE_TYPE) and
     * see whether we render it with the right control. This is how lists that
     * were checkboxes in Bitrix but dropdowns here were found.
     *
     * @param  array<int,array<string,mixed>>  $iblocks
     */
    protected function auditWidgets(array $iblocks): void
    {
        $rows = [];

        foreach ($iblocks as $iblock) {
            $iblockId = (int) $iblock['ID'];
            $modelClass = $this->resolveModel($iblock['CODE'] ?: null, $iblockId);

            if ($modelClass === null || ! method_exists($modelClass, 'getAdministrationFields')) {
                continue;
            }

            $fields = $this->adminFields($modelClass);

            foreach ($this->bitrix->getIBlockProperties($iblockId) as $raw) {
                if (($raw['ACTIVE'] ?? 'Y') !== 'Y') {
                    continue;
                }

                $property = IBlockProperty::fromArray($raw);
                $column = $this->naming->toColumnName($property->code);
                $field = null;

                foreach ([$column, Str::camel($column), Str::camel(Str::plural($column))] as $candidate) {
                    if (isset($fields[$candidate])) {
                        $field = $fields[$candidate];
                        break;
                    }
                }

                if ($field === null) {
                    continue;
                }

                // List options come from a separate query: fromArray() does not
                // fill them, and without them a "single-option list" cannot be told apart.
                $enumCount = $property->propertyType === 'L'
                    ? count($this->bitrix->getPropertyEnumValues((int) $raw['ID']))
                    : 0;

                $expected = $this->expectedWidget($property, (string) ($raw['LIST_TYPE'] ?? 'L'), (string) ($raw['FILE_TYPE'] ?? ''), $enumCount);

                if ($expected !== null && ! in_array($field['type'], $expected['types'], true)) {
                    $rows[] = [
                        $iblock['CODE'] ?: ('iblock_'.$iblockId),
                        $property->code,
                        $expected['bitrix'],
                        $field['type'],
                        implode('|', $expected['types']),
                    ];
                }
            }
        }

        $this->newLine();
        $this->info('Widgets vs. property settings in Bitrix');

        if ($rows === []) {
            $this->line('No mismatches.');

            return;
        }

        $this->table(['IBlock', 'Property', 'In Bitrix', 'Ours', 'Expected'], $rows);
    }

    /**
     * @return array{bitrix:string,types:array<int,string>}|null
     */
    protected function expectedWidget(IBlockProperty $property, string $listType, string $fileType, int $enumCount): ?array
    {
        // A list shown as checkboxes. A single option is a plain checkbox;
        // several options are a set of checkboxes (multiple) or radio buttons.
        if ($property->propertyType === 'L' && $listType === 'C') {
            if (! $property->multiple && $enumCount === 1) {
                return ['bitrix' => 'checkbox (list C, 1 option)', 'types' => ['checkbox']];
            }

            return $property->multiple
                ? ['bitrix' => 'checkboxes (list C, multiple)', 'types' => ['multiSelect', 'checkboxList']]
                : ['bitrix' => 'radio buttons (list C)', 'types' => ['select', 'radio']];
        }

        // File property: the allowed extensions decide whether these are images or documents.
        if ($property->propertyType === 'F' && $fileType !== '') {
            $documents = (bool) preg_match('/doc|pdf|xls|txt|rtf|ppt/i', $fileType);

            return [
                'bitrix' => $documents ? "files ({$fileType})" : 'images',
                'types' => $property->multiple ? ['gallery'] : ['image', 'file'],
            ];
        }

        return null;
    }

    /**
     * Compares the form (tabs and field order) with the Bitrix admin.
     *
     * A field being present does not mean the admin looks "like before": editors
     * compare the form line by line, and a field that moved to another tab or to
     * the other end of the list is as much a defect to them as a missing one.
     *
     * @param  array<int,array<string,mixed>>  $iblocks
     */
    protected function auditLayouts(array $iblocks): void
    {
        $rows = [];

        foreach ($iblocks as $iblock) {
            $iblockId = (int) $iblock['ID'];
            $code = $iblock['CODE'] ?: null;
            $modelClass = $this->resolveModel($code, $iblockId);

            if ($modelClass === null || ! method_exists($modelClass, 'getAdministrationFields')) {
                continue;
            }

            $propertyCodes = [];
            foreach ($this->bitrix->getIBlockProperties($iblockId) as $property) {
                $propertyCodes[(int) $property['ID']] = (string) $property['CODE'];
            }

            $layout = BitrixFormLayout::load($this->bitrix->getConnection(), $iblockId, $propertyCodes, $this->naming);

            if ($layout === null) {
                continue;
            }

            $theirTabs = array_column($layout->tabs(), 'label');
            $ourTabs = method_exists($modelClass, 'getAdminTabs')
                ? array_column($modelClass::getAdminTabs(), 'label')
                : [];

            $wrongTab = [];
            $sequence = [];

            foreach ($this->adminFields($modelClass) as $name => $field) {
                $key = $this->layoutKey($layout, $name);

                if ($key === null) {
                    continue;
                }

                $expectedTab = $layout->tabFor($key);
                $actualTab = empty($field['tab']) ? 'params' : $field['tab'];

                if ($expectedTab !== null && $expectedTab !== $actualTab) {
                    $wrongTab[] = $name;
                }

                $sequence[] = $layout->positionOf($key);
            }

            // Order is judged by positions: the sequence must be increasing.
            $sorted = $sequence;
            sort($sorted);
            $inPlace = 0;
            foreach ($sequence as $i => $position) {
                if (($sorted[$i] ?? null) === $position) {
                    $inPlace++;
                }
            }

            $total = count($sequence);
            $rows[] = [
                $code ?? ('iblock_'.$iblockId),
                implode(', ', $theirTabs),
                implode(', ', $ourTabs) === implode(', ', $theirTabs) ? '= same as Bitrix' : implode(', ', $ourTabs),
                $wrongTab === [] ? '—' : implode(', ', array_slice($wrongTab, 0, 4)).(count($wrongTab) > 4 ? '…' : ''),
                $total === 0 ? '—' : sprintf('%d/%d', $inPlace, $total),
            ];
        }

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->info('Form: tabs and field order (reference: the Bitrix admin layout)');
        $this->table(['IBlock', 'Tabs in Bitrix', 'Tabs here', 'Field on the wrong tab', 'Order'], $rows);

        $perfect = count(array_filter($rows, static fn (array $r): bool => $r[2] === '= same as Bitrix' && $r[3] === '—' && str_contains((string) $r[4], '/') && explode('/', (string) $r[4])[0] === explode('/', (string) $r[4])[1]));
        $this->line(sprintf('Forms fully matching Bitrix: %d of %d', $perfect, count($rows)));
    }

    /**
     * Our field name => key in the Bitrix layout.
     *
     * BelongsToManyField is named after the relation (linkGoods), while the
     * layout knows the column (link_goods). Without this translation, relation
     * fields would look like "Bitrix never had this".
     */
    protected function layoutKey(BitrixFormLayout $layout, string $fieldName): ?string
    {
        $candidates = [
            $fieldName,
            Str::snake($fieldName),
            Str::snake(Str::singular($fieldName)),
        ];

        foreach ($candidates as $candidate) {
            if ($layout->tabFor($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    protected function auditIBlock(array $iblock): void
    {
        $iblockId = (int) $iblock['ID'];
        $code = $iblock['CODE'] ?: null;
        $label = $code ?? ('iblock_'.$iblockId);

        $modelClass = $this->resolveModel($code, $iblockId);

        $elementCount = $this->bitrix->getIBlockElementCount($iblockId);

        if ($modelClass === null) {
            $this->iblockRows[] = [
                'iblock' => $label,
                'iblock_id' => $iblockId,
                'name' => $iblock['NAME'],
                'model' => '—',
                'table' => '—',
                'bitrix_elements' => $elementCount,
                'our_rows' => '—',
                'status' => self::S_NO_MODEL,
            ];
            $this->rows[] = $this->row($label, $iblockId, '*', $iblock['NAME'], '-', false, false, '—', '—', self::S_NO_MODEL, 'No Administrable model matches this IBlock');

            return;
        }

        /** @var class-string $modelClass */
        $model = new $modelClass;
        $table = $model->getTable();
        $columns = Schema::hasTable($table) ? array_map('strtolower', Schema::getColumnListing($table)) : [];
        $fillable = $model->getFillable();

        $fields = $this->adminFields($modelClass);
        $ourRows = Schema::hasTable($table) ? $modelClass::query()->count() : null;

        $this->iblockRows[] = [
            'iblock' => $label,
            'iblock_id' => $iblockId,
            'name' => $iblock['NAME'],
            'model' => class_basename($modelClass),
            'table' => $table,
            'bitrix_elements' => $elementCount,
            'our_rows' => $ourRows ?? '—',
            'status' => $this->dataStatus($elementCount, $ourRows),
        ];

        $matchedFieldNames = [];

        // --- Bitrix base element fields -------------------------------------
        foreach (self::BASE_FIELDS as $bitrixName => $column) {
            $hasColumn = in_array($column, $columns, true);
            $field = $fields[$column] ?? null;
            if ($field !== null) {
                $matchedFieldNames[] = $column;
            }

            if (! $hasColumn) {
                // active_from/active_to are optional in several IBlocks
                $status = in_array($column, ['active_from', 'active_to'], true) ? self::S_OK : self::S_NO_COLUMN;
                if ($status === self::S_NO_COLUMN) {
                    $this->rows[] = $this->row($label, $iblockId, $bitrixName, $bitrixName, 'base', false, false, class_basename($modelClass), $column, self::S_NO_COLUMN, 'Base IBlock field has no column');
                }

                continue;
            }

            if ($field === null) {
                $this->rows[] = $this->row($label, $iblockId, $bitrixName, $bitrixName, 'base', false, false, class_basename($modelClass), $column, self::S_NO_FIELD, 'Column exists but is not editable in the admin');
            }
        }

        // --- IBlock properties ----------------------------------------------
        foreach ($this->bitrix->getIBlockProperties($iblockId) as $raw) {
            $property = IBlockProperty::fromArray($raw);
            $this->loadEnumValues($property);
            $active = ($raw['ACTIVE'] ?? 'Y') === 'Y';

            $column = $this->naming->toColumnName($property->code);
            $relationSingular = $this->naming->toRelationName($property->code, false);
            $relationPlural = $this->naming->toRelationName($property->code, true);

            $candidates = array_unique([$column, $relationPlural, $relationSingular, Str::camel($column)]);

            $field = null;
            $matchedName = null;
            foreach ($candidates as $candidate) {
                if (isset($fields[$candidate])) {
                    $field = $fields[$candidate];
                    $matchedName = $candidate;
                    break;
                }
            }
            if ($matchedName !== null) {
                $matchedFieldNames[] = $matchedName;
            }

            $expected = $this->mapper->toAdminField($property);
            $expectedType = $this->fieldTypeFromClass($expected['class']);

            $meta = [
                'expected_type' => $expectedType,
                'actual_type' => $field['type'] ?? '—',
                'sort' => $property->sort,
            ];

            if (! $active) {
                $this->rows[] = $this->row($label, $iblockId, $property->code, $property->name, $property->getFullType(), $property->multiple, $property->isRequired, class_basename($modelClass), $matchedName ?? $column, self::S_INACTIVE, 'Property is disabled in Bitrix — absence here is expected', $meta);

                continue;
            }

            $isRelation = in_array($property->propertyType, ['E', 'G'], true) || $property->userType === 'directory';
            $hasColumn = in_array($column, $columns, true);

            if ($field === null) {
                // A relation may legitimately live in a pivot table rather than a column.
                $status = ($hasColumn || $isRelation) ? self::S_NO_FIELD : self::S_NO_COLUMN;
                $detail = $status === self::S_NO_COLUMN
                    ? "No column `{$column}` in `{$table}` and no admin field"
                    : 'Data is stored but there is no admin field to edit it';
                $this->rows[] = $this->row($label, $iblockId, $property->code, $property->name, $property->getFullType(), $property->multiple, $property->isRequired, class_basename($modelClass), $column, $status, $detail, $meta);

                continue;
            }

            // Field exists — grade it.
            $problems = [];

            if ($expectedType !== '' && $field['type'] !== $expectedType) {
                $problems[] = [self::S_TYPE, "Bitrix implies {$expectedType}, we render {$field['type']}"];
            }

            if ($property->multiple && ! $this->typeIsMultiple($field['type'])) {
                $problems[] = [self::S_MULTIPLE, "Property is MULTIPLE in Bitrix but `{$field['type']}` holds one value"];
            }

            if ($property->isRequired && empty($field['required'])) {
                $problems[] = [self::S_REQUIRED, 'Required in Bitrix, optional here'];
            }

            if ($property->propertyType === 'L') {
                $enumProblem = $this->compareEnum($property, $field);
                if ($enumProblem !== null) {
                    $problems[] = [self::S_ENUM, $enumProblem];
                }
            }

            // A non-relation field that is not fillable is silently dropped on save.
            if (! $isRelation && ! in_array($matchedName, $fillable, true) && $hasColumn) {
                $problems[] = [self::S_NOT_FILLABLE, "`{$matchedName}` is not in \$fillable — the value is discarded on save"];
            }

            if ($problems === []) {
                $this->rows[] = $this->row($label, $iblockId, $property->code, $property->name, $property->getFullType(), $property->multiple, $property->isRequired, class_basename($modelClass), $matchedName, self::S_OK, '', $meta);
            } else {
                foreach ($problems as [$status, $detail]) {
                    $this->rows[] = $this->row($label, $iblockId, $property->code, $property->name, $property->getFullType(), $property->multiple, $property->isRequired, class_basename($modelClass), $matchedName, $status, $detail, $meta);
                }
            }
        }

        // --- Reverse pass: our fields with no Bitrix counterpart -------------
        foreach ($fields as $name => $field) {
            if (in_array($name, $matchedFieldNames, true)) {
                continue;
            }
            // Fields the admin panel itself adds (SEO tab, section binding).
            if (str_starts_with($name, 'seo_') || str_starts_with($name, 'meta_') || in_array($name, ['section_id', 'canonical_url'], true)) {
                continue;
            }

            $this->extraRows[] = [
                'iblock' => $label,
                'model' => class_basename($modelClass),
                'field' => $name,
                'type' => $field['type'],
                'label' => $field['label'] ?? '',
            ];
        }
    }

    /**
     * Resolve the Administrable model for an IBlock code, tolerating models
     * that fatal while building their field list.
     */
    protected function resolveModel(?string $code, int $iblockId): ?string
    {
        $candidates = [];
        $namespace = rtrim((string) config('atrium-bitrix.model_namespace', 'App\\Models'), '\\');

        if ($code !== null) {
            $candidates[] = $namespace.'\\'.$this->naming->toModelName($code);
        }
        $candidates[] = $namespace.'\\Iblock'.$iblockId;

        foreach ($candidates as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @return array<string,array<string,mixed>> field name => serialized field
     */
    protected function adminFields(string $modelClass): array
    {
        if (! method_exists($modelClass, 'getAdministrationFields')) {
            return [];
        }

        try {
            $fields = $modelClass::getAdministrationFields();
        } catch (\Throwable $e) {
            $this->warn(sprintf('%s::getAdministrationFields() threw: %s', class_basename($modelClass), $e->getMessage()));

            return [];
        }

        $out = [];
        foreach ($fields as $field) {
            $array = is_array($field) ? $field : $field->toArray();
            $out[$array['name']] = $array;
        }

        return $out;
    }

    /**
     * The options of a list decide its widget: one option shown as checkboxes
     * is a plain checkbox, not a select.
     */
    protected function loadEnumValues(IBlockProperty $property): void
    {
        foreach ($this->bitrix->getPropertyEnumValues($property->id) as $enum) {
            $property->addEnumValue(IBlockPropertyEnum::fromArray($enum));
        }
    }

    /**
     * The widget type a short field class name (as the mapper gives it) stands for.
     */
    protected function fieldTypeFromClass(string $shortName): string
    {
        $class = $this->mapper->getFieldClassName($shortName);

        return (new $class)->getType();
    }

    protected function typeIsMultiple(string $type): bool
    {
        return in_array($type, ['gallery', 'repeatable', 'belongsToMany', 'multiSelect', 'treeSelect', 'content'], true);
    }

    /**
     * @return string|null problem description, or null when the enums agree
     */
    protected function compareEnum(IBlockProperty $property, array $field): ?string
    {
        $bitrixValues = [];
        foreach ($this->bitrix->getPropertyEnumValues($property->id) as $enum) {
            $bitrixValues[] = (string) ($enum['XML_ID'] ?: $enum['ID']);
        }

        if ($bitrixValues === []) {
            return null;
        }

        $ourValues = array_map(
            static fn ($o): string => (string) ($o['value'] ?? ''),
            $field['options'] ?? []
        );

        // A one-option list shown as a checkbox has no options: the option it
        // stands for is the value it stores when ticked.
        if (($field['checkedValue'] ?? null) !== null) {
            $ourValues[] = (string) $field['checkedValue'];
        }

        $missing = array_diff($bitrixValues, $ourValues);

        if ($missing === []) {
            return null;
        }

        return sprintf(
            'Missing option(s): %s (Bitrix has %d, we have %d)',
            implode(', ', array_slice($missing, 0, 5)),
            count($bitrixValues),
            count($ourValues)
        );
    }

    /**
     * Every Bitrix site (b_lang) against the Atrium sites: there, in the same
     * folder (DIR), on the same domains, as active.
     */
    protected function auditSites(): void
    {
        foreach ((new BitrixSites($this->bitrix))->all() as $site) {
            $expected = $site['attributes'];
            $target = BitrixSites::target($site);
            $folder = $site['default'] ? null : $expected['folder'];
            $problems = [];

            if ($target === null) {
                $status = 'NO_SITE';
            } else {
                if ($target->folder !== $folder) {
                    $problems[] = 'FOLDER_MISMATCH';
                }

                $ours = (array) $target->domains;
                sort($ours);
                $theirs = $expected['domains'];
                sort($theirs);

                if ($theirs !== [] && $ours !== $theirs) {
                    $problems[] = 'DOMAINS_MISMATCH';
                }

                if (! $site['default'] && $target->active !== $expected['active']) {
                    $problems[] = 'ACTIVE_MISMATCH';
                }

                $status = $problems[0] ?? ($site['default'] && $expected['folder'] !== null ? self::S_DEFAULT_AT_ROOT : self::S_OK);
            }

            $this->siteRows[] = [
                'lid' => $site['lid'],
                'dir' => $site['dir'],
                'domains' => implode(', ', $expected['domains']),
                'site' => $target?->code,
                'folder' => $target === null ? null : ($target->folder === null ? '/' : '/'.$target->folder.'/'),
                'our_domains' => $target === null ? null : implode(', ', (array) $target->domains),
                'status' => $status,
            ];
        }
    }

    protected function dataStatus(int $bitrixCount, ?int $ourCount): string
    {
        if ($ourCount === null) {
            return 'NO_TABLE';
        }
        if ($bitrixCount === 0 && $ourCount === 0) {
            return 'OK (empty)';
        }
        if ($ourCount === 0 && $bitrixCount > 0) {
            return 'NO_DATA';
        }
        if ($ourCount < $bitrixCount) {
            return sprintf('SHORT by %d', $bitrixCount - $ourCount);
        }

        return 'OK';
    }

    protected function row(
        string $iblock,
        int $iblockId,
        string $code,
        string $name,
        string $type,
        bool $multiple,
        bool $required,
        string $model,
        ?string $field,
        string $status,
        string $detail,
        array $meta = []
    ): array {
        return compact('iblock', 'iblockId', 'code', 'name', 'type', 'multiple', 'required', 'model', 'field', 'status', 'detail', 'meta');
    }

    protected function report(): int
    {
        $format = (string) $this->option('format');
        $problems = array_values(array_filter(
            $this->rows,
            static fn (array $r): bool => ! in_array($r['status'], [self::S_OK, self::S_INACTIVE], true)
        ));

        $content = match ($format) {
            'json' => json_encode([
                'iblocks' => $this->iblockRows,
                'properties' => $this->rows,
                'extra_fields' => $this->extraRows,
                'sites' => $this->siteRows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'markdown' => $this->renderMarkdown($problems),
            default => null,
        };

        if ($content !== null) {
            $path = $this->option('output') ?: storage_path('audit/bitrix-parity.'.($format === 'json' ? 'json' : 'md'));
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $content);
            $this->info("Report written to {$path}");
        } else {
            $this->renderTable($problems);
        }

        $this->newLine();
        $this->line(sprintf(
            'IBlocks: %d   Properties checked: %d   Problems: <fg=%s>%d</>   Extra fields: %d',
            count($this->iblockRows),
            count($this->rows),
            $problems === [] ? 'green' : 'red',
            count($problems),
            count($this->extraRows)
        ));

        // Rows that did not arrive are a loss too, not only missing fields.
        $dataLost = array_filter(
            $this->iblockRows,
            static fn (array $r): bool => ! in_array($r['status'], ['OK', 'OK (empty)', self::S_NO_MODEL], true)
        );

        foreach ($dataLost as $r) {
            $this->error(sprintf('%s → %s: %s', $r['iblock'], $r['table'] ?? '—', $r['status']));
        }

        $sitesLost = array_filter(
            $this->siteRows,
            static fn (array $r): bool => ! in_array($r['status'], [self::S_OK, self::S_DEFAULT_AT_ROOT], true)
        );

        foreach ($sitesLost as $r) {
            $this->error(sprintf('Site %s (%s) → %s: %s', $r['lid'], $r['dir'], $r['site'] ?? '—', $r['status']));
        }

        return $problems === [] && $dataLost === [] && $sitesLost === [] ? self::SUCCESS : self::FAILURE;
    }

    protected function renderTable(array $problems): void
    {
        if ($this->siteRows !== []) {
            $this->newLine();
            $this->line('<comment>Sites: Bitrix b_lang → Atrium</comment>');
            $this->table(
                ['LID', 'DIR', 'Domains', 'Site', 'Folder', 'Domains here', 'Status'],
                array_map(static fn (array $r): array => [
                    $r['lid'], $r['dir'], $r['domains'] ?: '—', $r['site'] ?? '—', $r['folder'] ?? '—', $r['our_domains'] ?: '—', $r['status'],
                ], $this->siteRows)
            );
        }

        $this->newLine();
        $this->line('<comment>IBlock → model mapping and data volume</comment>');
        $this->table(
            ['IBlock', 'ID', 'Model', 'Table', 'Bitrix', 'Ours', 'Status'],
            array_map(static fn (array $r): array => [
                $r['iblock'], $r['iblock_id'], $r['model'], $r['table'],
                $r['bitrix_elements'], $r['our_rows'], $r['status'],
            ], $this->iblockRows)
        );

        if ($problems !== []) {
            $this->newLine();
            $this->line('<comment>Field problems</comment>');
            $this->table(
                ['IBlock', 'Property', 'Bitrix type', 'M', 'R', 'Field', 'Status', 'Detail'],
                array_map(static fn (array $r): array => [
                    $r['iblock'], $r['code'], $r['type'],
                    $r['multiple'] ? 'Y' : '', $r['required'] ? 'Y' : '',
                    $r['field'] ?? '—', $r['status'], Str::limit($r['detail'], 60),
                ], $problems)
            );
        }
    }

    protected function renderMarkdown(array $problems): string
    {
        $byStatus = [];
        foreach ($problems as $row) {
            $byStatus[$row['status']][] = $row;
        }
        ksort($byStatus);

        $out = "# Field parity: Bitrix → Laravel\n\n";
        $out .= sprintf(
            "Iblocks: **%d** · properties checked: **%d** · mismatches: **%d** · extra fields on our side: **%d**\n\n",
            count($this->iblockRows),
            count($this->rows),
            count($problems),
            count($this->extraRows)
        );

        $out .= "## Summary by mismatch type\n\n| Status | Count | Meaning |\n|---|---|---|\n";
        $meanings = [
            self::S_NO_MODEL => 'No model corresponds to the iblock',
            self::S_NO_COLUMN => 'Neither a DB column nor a field exists: data was lost in the migration',
            self::S_NO_FIELD => 'The data exists, but there is nothing to edit it with in the admin',
            self::S_NOT_FILLABLE => 'The field is in the form but not in $fillable: the value is silently lost on save',
            self::S_TYPE => 'The widget type does not match the Bitrix property type',
            self::S_MULTIPLE => 'The property is multiple in Bitrix but single here',
            self::S_REQUIRED => 'Required in Bitrix, not required here',
            self::S_ENUM => 'The list of values does not match',
        ];
        foreach ($byStatus as $status => $rows) {
            $out .= sprintf("| `%s` | %d | %s |\n", $status, count($rows), $meanings[$status] ?? '');
        }

        if ($this->siteRows !== []) {
            $out .= "\n## Sites\n\n| LID | DIR | Domains | Site | Folder | Domains here | Status |\n|---|---|---|---|---|---|---|\n";
            foreach ($this->siteRows as $r) {
                $out .= sprintf(
                    "| %s | `%s` | %s | %s | `%s` | %s | %s |\n",
                    $r['lid'], $r['dir'], $r['domains'] ?: '—', $r['site'] ?? '—', $r['folder'] ?? '—', $r['our_domains'] ?: '—', $r['status']
                );
            }
        }

        $out .= "\n## Iblocks and data volume\n\n";
        $out .= "| IBlock | ID | Model | Table | Elements in Bitrix | Rows here | Status |\n|---|---|---|---|---|---|---|\n";
        foreach ($this->iblockRows as $r) {
            $out .= sprintf(
                "| %s | %d | %s | %s | %s | %s | %s |\n",
                $r['iblock'], $r['iblock_id'], $r['model'], $r['table'],
                $r['bitrix_elements'], $r['our_rows'], $r['status']
            );
        }

        foreach ($byStatus as $status => $rows) {
            $out .= sprintf("\n## %s (%d)\n\n", $status, count($rows));
            $out .= "| IBlock | Property | Name | Bitrix type | Mult. | Req. | Our field | Details |\n|---|---|---|---|---|---|---|---|\n";
            foreach ($rows as $r) {
                $out .= sprintf(
                    "| %s | `%s` | %s | `%s` | %s | %s | `%s` | %s |\n",
                    $r['iblock'], $r['code'], $r['name'], $r['type'],
                    $r['multiple'] ? 'yes' : '', $r['required'] ? 'yes' : '',
                    $r['field'] ?? '—', $r['detail']
                );
            }
        }

        if ($this->extraRows !== []) {
            $out .= sprintf("\n## Fields on our side that are not in Bitrix (%d)\n\n", count($this->extraRows));
            $out .= "This is not necessarily an error: some of them were added deliberately.\n\n";
            $out .= "| IBlock | Model | Field | Type | Label |\n|---|---|---|---|---|\n";
            foreach ($this->extraRows as $r) {
                $out .= sprintf("| %s | %s | `%s` | `%s` | %s |\n", $r['iblock'], $r['model'], $r['field'], $r['type'], $r['label']);
            }
        }

        return $out;
    }
}
