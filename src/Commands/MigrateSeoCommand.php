<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Admin\Traits\HasAdminSeo;
use Atrium\Bitrix\Support\GeneratedPaths;
use Illuminate\Database\Eloquent\Model;

/**
 * Migrates SEO data from Bitrix into the polymorphic seo_meta table.
 *
 * None of the other migration commands did this: atrium:bitrix:migrate:data
 * pulls element fields and properties, but Bitrix keeps SEO separately, in
 * b_iblock_iproperty. As a result not a single SEO row reached the new site
 * (seo_meta stayed empty) even though Bitrix had META TITLE, DESCRIPTION,
 * KEYWORDS and picture ALT/TITLE values filled in.
 *
 * Only the element (E) and section (S) levels are taken. The iblock level (B)
 * is the default `{=this.Name}`, which is already implemented here as a
 * fallback to name, so storing it in the database would only duplicate it.
 */
class MigrateSeoCommand extends AbstractBitrixCommand
{
    protected $signature = 'atrium:bitrix:migrate:seo
                            {--connection= : Bitrix database connection name}
                            {--iblock=* : Only these IBlock IDs}
                            {--dry-run : Show what would be written without changing anything}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Migrate SEO (meta tags, picture ALT/TITLE, tags) from Bitrix into seo_meta';

    /**
     * Bitrix code tail (without the ELEMENT_/SECTION_ prefix) => seo_meta column.
     */
    private const COLUMN_MAP = [
        'META_TITLE' => 'title',
        'META_DESCRIPTION' => 'description',
        'META_KEYWORDS' => 'keywords',
        'PAGE_TITLE' => 'page_title',
        'PREVIEW_PICTURE_FILE_ALT' => 'preview_picture_alt',
        'PREVIEW_PICTURE_FILE_TITLE' => 'preview_picture_title',
        'PREVIEW_PICTURE_FILE_NAME' => 'preview_picture_file_name',
        'DETAIL_PICTURE_FILE_ALT' => 'detail_picture_alt',
        'DETAIL_PICTURE_FILE_TITLE' => 'detail_picture_title',
        'DETAIL_PICTURE_FILE_NAME' => 'detail_picture_file_name',
        // Sections: the section picture goes into the same columns as the preview picture.
        'PICTURE_FILE_ALT' => 'preview_picture_alt',
        'PICTURE_FILE_TITLE' => 'preview_picture_title',
        'PICTURE_FILE_NAME' => 'preview_picture_file_name',
    ];

    /** Columns declared as varchar in seo_meta; values are truncated to avoid database errors. */
    private const MAX_LENGTHS = [
        'title' => 255,
        'page_title' => 255,
        'og_title' => 255,
        'tags' => 500,
    ];

    /** @var array<string,class-string<Model>> table name => model class */
    private array $tableToModel = [];

    private int $written = 0;

    private int $skippedNoModel = 0;

    private int $skippedNoRow = 0;

    private int $truncated = 0;

    /** @var array<string,int> */
    private array $missingTables = [];

    public function handle(): int
    {
        if (! $this->verifyConnection()) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->written = $this->skippedNoModel = $this->skippedNoRow = $this->truncated = 0;
        $this->missingTables = [];
        $this->tableToModel = $this->buildTableToModelMap();
        $this->line(sprintf('Models with SEO support: %d', count($this->tableToModel)));

        $rows = $this->fetchSeoRows();

        if ($rows === []) {
            $this->warn('Bitrix has no SEO at the element or section level; nothing to migrate.');

            return self::SUCCESS;
        }

        $groups = $this->groupByEntity($rows);
        $this->line(sprintf('Entities with SEO in Bitrix: %d', count($groups)));

        if ($dryRun) {
            $this->warn('--dry-run mode: the database is not modified.');
        } elseif (! $this->confirmAction(sprintf('Write SEO for %d entities into seo_meta?', count($groups)))) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $iblockCodes = $this->iblockCodes();
        $tagsByElement = $this->fetchElementTags();

        $this->withProgress(array_values($groups), function (array $group) use ($iblockCodes, $tagsByElement, $dryRun): void {
            $this->applyGroup($group, $iblockCodes, $tagsByElement, $dryRun);
        });

        $this->newLine();
        $this->info(sprintf('Written: %d', $this->written));

        if ($this->skippedNoModel > 0) {
            $this->warn(sprintf('Skipped (no model for the table): %d - %s', $this->skippedNoModel, implode(', ', array_keys($this->missingTables))));
        }

        if ($this->skippedNoRow > 0) {
            $this->warn(sprintf('Skipped (element was not migrated, no row with this bitrix_id): %d', $this->skippedNoRow));
        }

        if ($this->truncated > 0) {
            $this->warn(sprintf('Truncated to the column length: %d values', $this->truncated));
        }

        return self::SUCCESS;
    }

    /**
     * Table => model class. Built from the actual getTable() rather than from
     * NamingConvention: for some entities the names diverge (countries →
     * Country, iblock_42s → Iblock42), and guessing here would silently lose data.
     *
     * @return array<string,class-string<Model>>
     */
    private function buildTableToModelMap(): array
    {
        $map = [];

        $models = rtrim((string) config('atrium-bitrix.model_namespace', 'App\\Models'), '\\');
        $references = rtrim((string) config('atrium-bitrix.reference_namespace', 'App\\Models\\References'), '\\');

        $dirs = [
            GeneratedPaths::directory('model_path', $models) => $models.'\\',
            GeneratedPaths::directory('reference_path', $references) => $references.'\\',
        ];

        foreach ($dirs as $dir => $namespace) {
            if (! is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.'/*.php') ?: [] as $file) {
                $class = $namespace.basename($file, '.php');

                if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                    continue;
                }

                // Without HasAdminSeo there is nowhere to write: the model has no seoMeta().
                if (! in_array(HasAdminSeo::class, class_uses_recursive($class), true)) {
                    continue;
                }

                $map[(new $class)->getTable()] = $class;
            }
        }

        return $map;
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchSeoRows(): array
    {
        $query = $this->getBitrixConnection()->getConnection()
            ->table('b_iblock_iproperty')
            ->whereIn('ENTITY_TYPE', ['E', 'S'])
            ->whereNotNull('TEMPLATE')
            ->where('TEMPLATE', '<>', '');

        $only = array_map('intval', (array) $this->option('iblock'));

        if ($only !== []) {
            $query->whereIn('IBLOCK_ID', $only);
        }

        return array_map(static fn ($row): array => (array) $row, $query->get()->all());
    }

    /**
     * Collect the per-row values into one record per entity.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,array<string,mixed>>
     */
    private function groupByEntity(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = $row['IBLOCK_ID'].':'.$row['ENTITY_TYPE'].':'.$row['ENTITY_ID'];

            $groups[$key] ??= [
                'iblock_id' => (int) $row['IBLOCK_ID'],
                'entity_type' => (string) $row['ENTITY_TYPE'],
                'entity_id' => (int) $row['ENTITY_ID'],
                'values' => [],
            ];

            $groups[$key]['values'][(string) $row['CODE']] = (string) $row['TEMPLATE'];
        }

        return $groups;
    }

    /** @return array<int,string> IBLOCK_ID => CODE */
    private function iblockCodes(): array
    {
        $codes = [];

        foreach ($this->getBitrixConnection()->getIBlocks() as $iblock) {
            $codes[(int) $iblock['ID']] = (string) ($iblock['CODE'] ?? '');
        }

        return $codes;
    }

    /**
     * Tags live not in iproperty but directly in b_iblock_element.TAGS.
     *
     * @return array<int,string>
     */
    private function fetchElementTags(): array
    {
        $rows = $this->getBitrixConnection()->getConnection()
            ->table('b_iblock_element')
            ->whereNotNull('TAGS')
            ->where('TAGS', '<>', '')
            ->get(['ID', 'TAGS']);

        $tags = [];

        foreach ($rows as $row) {
            $tags[(int) $row->ID] = (string) $row->TAGS;
        }

        return $tags;
    }

    /**
     * @param  array<string,mixed>  $group
     * @param  array<int,string>  $iblockCodes
     * @param  array<int,string>  $tagsByElement
     */
    private function applyGroup(array $group, array $iblockCodes, array $tagsByElement, bool $dryRun): void
    {
        $code = $iblockCodes[$group['iblock_id']] ?? '';

        if ($code === '') {
            $this->skippedNoModel++;
            $this->missingTables['iblock '.$group['iblock_id'].' without code'] = 1;

            return;
        }

        $naming = $this->getNaming();
        $table = $group['entity_type'] === 'S'
            ? $naming->toSectionTableName($code)
            : $naming->toTableName($code);

        $modelClass = $this->tableToModel[$table] ?? null;

        if ($modelClass === null) {
            $this->skippedNoModel++;
            $this->missingTables[$table] = ($this->missingTables[$table] ?? 0) + 1;

            return;
        }

        /** @var Model|null $record */
        $record = $modelClass::query()->where('bitrix_id', $group['entity_id'])->first();

        if ($record === null) {
            $this->skippedNoRow++;

            return;
        }

        $data = $this->buildSeoData($group, $record, $tagsByElement);

        if ($data === []) {
            return;
        }

        // Only models with HasAdminSeo are in the table map.
        if (! $dryRun && method_exists($record, 'updateSeoMeta')) {
            $record->updateSeoMeta($data);
        }

        $this->written++;
    }

    /**
     * @param  array<string,mixed>  $group
     * @param  array<int,string>  $tagsByElement
     * @return array<string,string>
     */
    private function buildSeoData(array $group, Model $record, array $tagsByElement): array
    {
        $data = [];

        foreach ($group['values'] as $bitrixCode => $template) {
            $suffix = preg_replace('/^(ELEMENT|SECTION)_/', '', $bitrixCode);
            $column = self::COLUMN_MAP[$suffix] ?? null;

            if ($column === null) {
                continue;
            }

            $value = self::undouble($this->resolveTemplate($template, $record));

            if ($value === '') {
                continue;
            }

            $data[$column] = $this->fit($column, $value);
        }

        if ($group['entity_type'] === 'E' && isset($tagsByElement[$group['entity_id']])) {
            $data['tags'] = $this->fit('tags', $tagsByElement[$group['entity_id']]);
        }

        return $data;
    }

    /**
     * The only macro that occurs in the data is {=this.Name}. It may come with
     * a suffix ("{=this.Name} 2026"), hence a replacement rather than dropping
     * the whole value.
     */
    private function resolveTemplate(string $template, Model $record): string
    {
        $name = (string) ($record->getAttribute('name') ?? '');

        return trim(str_replace('{=this.Name}', $name, $template));
    }

    /**
     * A value that Bitrix stored twice in a row.
     *
     * b_iblock_element_iprop really contains such values, e.g.
     * "Weather in June! Debunking myths!Weather in June! Debunking myths!" or
     * "Licensed guidesLicensed guides". It looks like text pasted into a field
     * that already had it; in search results such a title looks like a broken site.
     *
     * The string is split in half and the halves are compared. Cutting
     * anything on a guess is not acceptable, so the condition is strict:
     * exactly two identical pieces. Texts where the repetition is meaningful
     * ("Georgia. Georgia." with a separator) do not match.
     */
    private static function undouble(string $value): string
    {
        $length = mb_strlen($value);

        if ($length < 2 || $length % 2 !== 0) {
            return $value;
        }

        $half = mb_substr($value, 0, intdiv($length, 2));

        return $half.$half === $value ? $half : $value;
    }

    private function fit(string $column, string $value): string
    {
        $limit = self::MAX_LENGTHS[$column] ?? null;

        if ($limit !== null && mb_strlen($value) > $limit) {
            $this->truncated++;

            return mb_substr($value, 0, $limit);
        }

        return $value;
    }
}
