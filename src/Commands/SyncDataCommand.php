<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Admin\Cache\RecordCache;
use Atrium\Bitrix\Schema\IBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catch-up transfer: bring over from Bitrix what appeared or changed after
 * the first transfer.
 *
 * `atrium:bitrix:migrate:data` can only load into an empty table, with a bare
 * insert() and explicit ids. On a populated database it trips over duplicate
 * keys and silently misses edits. Meanwhile editors keep working in Bitrix
 * while the new site is being prepared, so the gap grows on its own.
 *
 * The rule that protects our own edits: an element is touched only if it
 * changed in Bitrix later than we wrote it on our side
 * (`TIMESTAMP_X > updated_at`). Whatever we changed locally and Bitrix did
 * not touch stays as is, which is exactly what content migrations written
 * on our side look like.
 *
 * Columns that must never be taken from Bitrix, even for a changed element,
 * are listed in `config/atrium-bitrix.php` => `sync_protected`.
 */
class SyncDataCommand extends MigrateDataCommand
{
    protected $signature = 'atrium:bitrix:sync
                            {--connection= : Name of the Bitrix database connection}
                            {--iblock=* : Only these iblocks}
                            {--chunk=100 : Chunk size when reading elements}
                            {--skip-files : Do not transfer images}
                            {--prune : Delete what no longer exists in Bitrix}
                            {--dry-run : Show the plan without writing anything}
                            {--audit : Compare everything regardless of edit dates (report only)}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Catch up the database: transfer new and changed data from Bitrix without overwriting local edits';

    private bool $dryRun = false;

    private bool $audit = false;

    /** @var array<int, array{0:string,1:int,2:int,3:int,4:int}> Rows of the summary table */
    private array $report = [];

    public function handle(): int
    {
        // Rows go in past Eloquent: the record cache is dropped once, at the end.
        return app(RecordCache::class)->bulk(fn (): int => $this->import(), everything: true);
    }

    private function import(): int
    {
        $this->info('Catch-up transfer from Bitrix');
        $this->info('==============================');
        $this->newLine();

        if (! $this->verifyConnection()) {
            return self::FAILURE;
        }

        $this->report = [];
        $this->jsonColumnsCache = [];
        $this->audit = (bool) $this->option('audit');
        $this->dryRun = $this->audit || (bool) $this->option('dry-run');
        $this->chunkSize = (int) $this->option('chunk');
        $this->skipFiles = (bool) $this->option('skip-files');

        if ($this->audit) {
            $this->warn('Full audit: checking all elements, not only those fresh in Bitrix.');
            $this->newLine();
        } elseif ($this->dryRun) {
            $this->warn('Dry run: nothing is written.');
            $this->newLine();
        }

        $iblockIds = $this->option('iblock') ?: $this->allIBlockIds();

        if (empty($iblockIds)) {
            $this->error('No iblocks found.');

            return self::FAILURE;
        }

        $analyzer = $this->getIBlockAnalyzer();

        foreach ($iblockIds as $iblockId) {
            $iblockId = (int) $iblockId;

            if ($this->shouldSkipIBlock($iblockId)) {
                continue;
            }

            $iblock = $analyzer->analyze($iblockId);

            if (! $iblock) {
                $this->warn("Iblock {$iblockId} not found");

                continue;
            }

            if (! empty($iblock->sections)) {
                $this->syncSections($iblock);
            }

            $this->syncElements($iblock);
        }

        $this->writeDeferredLinks();

        $this->newLine();
        $this->info('Total');
        $this->displayTable(
            ['Table', 'Added', 'Updated', 'Unchanged', 'Missing in Bitrix'],
            $this->report
        );

        return self::SUCCESS;
    }

    /**
     * All Bitrix iblocks, unless narrowed down by an option.
     *
     * @return array<int, int>
     */
    private function allIBlockIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['ID'],
            $this->getBitrixConnection()->getIBlocks()
        );
    }

    private function syncSections(IBlock $iblock): void
    {
        $table = $this->getNaming()->toSectionTableName($iblock->code);

        if (! $this->tableIsReady($table)) {
            return;
        }

        $sections = $this->getBitrixConnection()->getIBlockSections($iblock->id);
        $existing = $this->existingRows($table);

        $added = $updated = $same = 0;

        foreach ($sections as $section) {
            $id = (int) $section['ID'];
            $data = [
                'id' => $id,
                'bitrix_id' => $id,
                'parent_id' => $section['IBLOCK_SECTION_ID'] ?: null,
                'name' => $section['NAME'],
                'code' => $section['CODE'],
                'sort' => $section['SORT'] ?? 500,
                'active' => ($section['ACTIVE'] ?? 'Y') === 'Y',
                'description' => $section['DESCRIPTION'],
                'depth' => $section['DEPTH_LEVEL'] ?? 0,
                'left_margin' => $section['LEFT_MARGIN'] ?? 0,
                'right_margin' => $section['RIGHT_MARGIN'] ?? 0,
                'updated_at' => $this->parseDate($section['TIMESTAMP_X'] ?? null) ?? now(),
            ];

            if (! $this->skipFiles) {
                if (! empty($section['PICTURE'])) {
                    $data['picture'] = $this->migrateFile($section['PICTURE']);
                }
                if (! empty($section['DETAIL_PICTURE'])) {
                    $data['detail_picture'] = $this->migrateFile($section['DETAIL_PICTURE']);
                }
            }

            if (! isset($existing[$id])) {
                $data['created_at'] = now();
                $this->insertRow($table, $data);
                $this->line("  <info>+</info> section {$id}: {$section['NAME']}");
                $added++;

                continue;
            }

            if (! $this->bitrixIsNewer($section['TIMESTAMP_X'] ?? null, $existing[$id]->updated_at ?? null)) {
                $same++;

                continue;
            }

            $changes = $this->changedColumns($table, $data, $existing[$id]);

            if (empty($changes)) {
                $same++;

                continue;
            }

            $this->updateRow($table, $id, $changes);
            $this->line("  <comment>~</comment> section {$id}: ".implode(', ', array_keys($changes)));
            $updated++;
        }

        $missing = $this->missingLocally($existing, $sections);
        $this->report[] = [$table, $added, $updated, $same, count($missing)];
    }

    private function syncElements(IBlock $iblock): void
    {
        $connection = $this->getBitrixConnection();
        $table = $this->getNaming()->toTableName($iblock->code);

        if (! $this->tableIsReady($table)) {
            return;
        }

        $total = $connection->getIBlockElementCount($iblock->id);

        if ($total === 0) {
            return;
        }

        $this->newLine();
        $this->info("{$iblock->name} -> {$table} ({$total})");

        $existing = $this->existingRows($table);

        $added = $updated = $same = 0;
        $seen = [];
        $offset = 0;

        while ($offset < $total) {
            $elements = $connection->getIBlockElements($iblock->id, $this->chunkSize, $offset);

            if (empty($elements)) {
                break;
            }

            foreach ($elements as $element) {
                $id = (int) $element['ID'];
                $seen[$id] = true;

                if (! isset($existing[$id])) {
                    $data = $this->transformElement($element, $iblock);
                    $this->insertRow($table, $data);

                    if (! $this->dryRun) {
                        $this->deferLinks($iblock, $table, $id);
                    }

                    $this->line("  <info>+</info> {$id}: {$element['NAME']}");
                    $added++;

                    continue;
                }

                if (! $this->bitrixIsNewer($element['TIMESTAMP_X'] ?? null, $existing[$id]->updated_at ?? null)) {
                    $same++;

                    continue;
                }

                $data = $this->transformElement($element, $iblock);
                $changes = $this->changedColumns($table, $data, $existing[$id]);

                if (empty($changes)) {
                    $same++;

                    continue;
                }

                $this->updateRow($table, $id, $changes);

                if (! $this->dryRun) {
                    $this->clearElementPivots($iblock, $table, $id);
                    $this->deferLinks($iblock, $table, $id);
                }

                $this->line("  <comment>~</comment> {$id}: {$element['NAME']}");
                $this->line('      '.implode(', ', array_keys($changes)));
                $updated++;
            }

            $offset += $this->chunkSize;
        }

        $missing = array_values(array_diff(array_keys($existing), array_keys($seen)));

        foreach ($missing as $id) {
            $name = $existing[$id]->name ?? '';

            if ($this->option('prune')) {
                $this->deleteRow($table, (int) $id);
                $this->line("  <fg=red>-</> {$id}: {$name}");
            } else {
                $this->warn("  no longer in Bitrix: {$id} {$name} (use --prune to delete)");
            }
        }

        $this->report[] = [$table, $added, $updated, $same, count($missing)];
    }

    /**
     * Did Bitrix change the element later than we wrote it locally?
     *
     * Our own edits push the local updated_at above Bitrix's TIMESTAMP_X, so
     * such an element is skipped by the transfer and the edit survives.
     */
    private function bitrixIsNewer(mixed $bitrixTimestamp, mixed $localUpdatedAt): bool
    {
        if ($this->audit) {
            return true;
        }

        if (empty($bitrixTimestamp)) {
            return false;
        }

        if (empty($localUpdatedAt)) {
            return true;
        }

        return strtotime((string) $bitrixTimestamp) > strtotime((string) $localUpdatedAt);
    }

    /**
     * Columns that really differ from what we have.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function changedColumns(string $table, array $data, object $current): array
    {
        $protected = $this->protectedColumns($table);
        $changes = [];

        // Compare what will actually be stored: even a plain string goes into a
        // json column quoted, and without this step it would always look changed.
        $data = $this->prepareDataForInsert($data, $table);

        foreach ($data as $column => $value) {
            if ($column === 'id' || $column === 'created_at') {
                continue;
            }

            if (! property_exists($current, $column)) {
                continue;
            }

            if (in_array($column, $protected, true)) {
                if ($this->valuesDiffer($value, $current->$column)) {
                    $this->warn("      {$column}: local edit kept, not taking it from Bitrix");
                }

                continue;
            }

            if ($this->valuesDiffer($value, $current->$column)) {
                $changes[$column] = $value;
            }
        }

        // A timestamp alone is no reason to touch the row.
        if (count($changes) === 1 && array_key_exists('updated_at', $changes)) {
            return [];
        }

        return $changes;
    }

    /**
     * Table columns that the transfer never overwrites.
     *
     * @return array<int, string>
     */
    private function protectedColumns(string $table): array
    {
        $protected = config('atrium-bitrix.sync_protected', []);

        return $protected[$table] ?? [];
    }

    /**
     * Comparison by meaning, not by type: the database returns everything as
     * strings, Bitrix returns whatever, and a naive !== would flag everything
     * as changed.
     */
    private function valuesDiffer(mixed $new, mixed $old): bool
    {
        $new = $this->normalize($new);
        $old = $this->normalize($old);

        if ($new === null || $old === null) {
            return $new !== $old;
        }

        // MySQL returns JSON in its own formatting (`["a", "b"]`) while
        // json_encode writes it without spaces, so a literal comparison would
        // flag everything stored in json columns as changed.
        $newJson = $this->asJson($new);
        $oldJson = $this->asJson($old);

        if ($newJson !== null && $oldJson !== null) {
            return $newJson !== $oldJson;
        }

        if (is_numeric($new) && is_numeric($old)) {
            return abs((float) $new - (float) $old) > 0.000001;
        }

        $newTime = $this->asTimestamp($new);
        $oldTime = $this->asTimestamp($old);

        if ($newTime !== null && $oldTime !== null) {
            return $newTime !== $oldTime;
        }

        return $new !== $old;
    }

    /** An empty string and NULL are the same for us, otherwise we get false differences. */
    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Bring JSON to a single form, to compare content rather than formatting. */
    private function asJson(string $value): ?string
    {
        if (! in_array($value[0] ?? '', ['[', '{', '"'], true)) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    private function asTimestamp(string $value): ?int
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value)) {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    /**
     * Current table contents, keyed by id.
     *
     * @return array<int, object>
     */
    private function existingRows(string $table): array
    {
        return DB::table($table)->get()->keyBy('id')->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $bitrixRows
     * @param  array<int, object>  $existing
     * @return array<int, int>
     */
    private function missingLocally(array $existing, array $bitrixRows): array
    {
        $bitrixIds = array_map(static fn (array $row): int => (int) $row['ID'], $bitrixRows);

        return array_values(array_diff(array_keys($existing), $bitrixIds));
    }

    /**
     * Remove the element's previous links: without it insertOrIgnore only adds,
     * and whatever was unlinked in Bitrix would stay attached on our side.
     */
    private function clearElementPivots(IBlock $iblock, string $table, int $elementId): void
    {
        $naming = $this->getNaming();
        $foreignKey = Str::singular($table).'_id';

        foreach ($iblock->properties as $property) {
            if (! $property->needsPivotTable()) {
                continue;
            }

            $pivotTable = $table.'_'.$naming->toColumnName($property->code);

            if (! $this->tableIsReady($pivotTable, quiet: true)) {
                continue;
            }

            DB::table($pivotTable)->where($foreignKey, $elementId)->delete();
        }
    }

    private function tableIsReady(string $table, bool $quiet = false): bool
    {
        if (DB::getSchemaBuilder()->hasTable($table)) {
            return true;
        }

        if (! $quiet) {
            $this->warn("Table {$table} does not exist, skipping");
        }

        return false;
    }

    /** @param array<string, mixed> $data */
    private function insertRow(string $table, array $data): void
    {
        if ($this->dryRun) {
            return;
        }

        DB::table($table)->insert($this->prepareDataForInsert($data, $table));
    }

    /** @param array<string, mixed> $changes */
    private function updateRow(string $table, int $id, array $changes): void
    {
        if ($this->dryRun) {
            return;
        }

        DB::table($table)->where('id', $id)->update($this->prepareDataForInsert($changes, $table));
    }

    private function deleteRow(string $table, int $id): void
    {
        if ($this->dryRun) {
            return;
        }

        DB::table($table)->where('id', $id)->delete();
    }
}
