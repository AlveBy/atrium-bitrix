<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Bitrix\Schema\IBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VerifyDataCommand extends AbstractBitrixCommand
{
    protected $signature = 'atrium:bitrix:verify
                            {--connection= : Database connection name}
                            {--iblock=* : Specific IBlock IDs to verify}
                            {--hlblock=* : Specific Highload Block IDs to verify}
                            {--sample=5 : Number of sample records to spot-check}
                            {--check-files : Verify file references exist on disk}
                            {--force : Skip interactive prompts}';

    protected $description = 'Verify migrated data by comparing Bitrix and Laravel databases';

    protected int $totalChecks = 0;

    protected int $passedChecks = 0;

    protected int $failedChecks = 0;

    public function handle(): int
    {
        $this->info('Bitrix Data Verification');
        $this->info('========================');
        $this->newLine();

        if (! $this->verifyConnection()) {
            return self::FAILURE;
        }

        $iblockIds = $this->option('iblock') ?: null;
        $hlblockIds = $this->option('hlblock') ?: null;

        // Select what to verify
        $hlblockIds = $hlblockIds ?: $this->selectHlblocks();
        $iblockIds = $iblockIds ?: $this->selectIBlocks();

        // Phase 1: Count verification
        $this->newLine();
        $this->info('=== Counts ===');
        $this->newLine();

        $countRows = [];
        $pivotRows = [];

        if (! empty($hlblockIds)) {
            [$hlCountRows] = $this->verifyHlblockCounts($hlblockIds);
            $countRows = array_merge($countRows, $hlCountRows);
        }

        if (! empty($iblockIds)) {
            [$ibCountRows, $ibPivotRows] = $this->verifyIBlockCounts($iblockIds);
            $countRows = array_merge($countRows, $ibCountRows);
            $pivotRows = array_merge($pivotRows, $ibPivotRows);
        }

        if (! empty($countRows)) {
            $this->table(
                ['#', 'Entity', 'Bitrix', 'Laravel', 'Match', 'Diff'],
                $countRows
            );
        }

        if (! empty($pivotRows)) {
            $this->newLine();
            $this->info('=== Pivot tables ===');
            $this->newLine();
            $this->table(
                ['#', 'Table', 'Bitrix', 'Laravel', 'Match'],
                $pivotRows
            );
        }

        // Phase 2: Spot-check records
        $sampleSize = (int) $this->option('sample');
        if ($sampleSize > 0 && ! empty($iblockIds)) {
            $this->newLine();
            $this->info('=== Record spot check ===');
            $this->newLine();
            $this->spotCheckIBlocks($iblockIds, $sampleSize);
        }

        // Phase 3: File check
        if ($this->option('check-files') && ! empty($iblockIds)) {
            $this->newLine();
            $this->info('=== File check ===');
            $this->newLine();
            $this->checkFiles($iblockIds);
        }

        // Summary
        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Checks: {$this->totalChecks} | OK: {$this->passedChecks} | Failed: {$this->failedChecks}");
        $this->newLine();

        return $this->failedChecks > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Verify Highload Block counts
     *
     * @return array{0: array}
     */
    protected function verifyHlblockCounts(array $hlblockIds): array
    {
        $analyzer = $this->getHlblockAnalyzer();
        $connection = $this->getBitrixConnection();
        $naming = $this->getNaming();

        $countRows = [];
        $index = 1;

        foreach ($hlblockIds as $hlblockId) {
            $hlblock = $analyzer->analyze($hlblockId);
            if (! $hlblock) {
                continue;
            }

            $laravelTable = $naming->hlblockToTableName($hlblock->tableName);

            if (! Schema::hasTable($laravelTable)) {
                $countRows[] = $this->makeCountRow($index++, $laravelTable, '?', 'TABLE NOT FOUND', false);

                continue;
            }

            $bitrixCount = $connection->getHighloadBlockDataCount($hlblock->tableName);
            $laravelCount = DB::table($laravelTable)->count();

            $countRows[] = $this->makeCountRow($index++, $laravelTable, $bitrixCount, $laravelCount);
        }

        return [$countRows];
    }

    /**
     * Verify IBlock counts (elements, sections, pivots)
     *
     * @return array{0: array, 1: array}
     */
    protected function verifyIBlockCounts(array $iblockIds): array
    {
        $analyzer = $this->getIBlockAnalyzer();
        $connection = $this->getBitrixConnection();
        $naming = $this->getNaming();

        $countRows = [];
        $pivotRows = [];
        $countIndex = 1;
        $pivotIndex = 1;

        foreach ($iblockIds as $iblockId) {
            if ($this->shouldSkipIBlock($iblockId)) {
                continue;
            }

            $iblock = $analyzer->analyze($iblockId);
            if (! $iblock) {
                continue;
            }

            $laravelTable = $naming->toTableName($iblock->code);

            // Element counts
            if (! Schema::hasTable($laravelTable)) {
                $countRows[] = $this->makeCountRow($countIndex++, $laravelTable, '?', 'TABLE NOT FOUND', false);

                continue;
            }

            $bitrixCount = $connection->getIBlockElementCount($iblock->id);
            $laravelCount = DB::table($laravelTable)->count();
            $countRows[] = $this->makeCountRow($countIndex++, $laravelTable, $bitrixCount, $laravelCount);

            // Section counts
            if (! empty($iblock->sections)) {
                $sectionTable = $naming->toSectionTableName($iblock->code);

                if (Schema::hasTable($sectionTable)) {
                    $bitrixSectionCount = count($connection->getIBlockSections($iblock->id));
                    $laravelSectionCount = DB::table($sectionTable)->count();
                    $countRows[] = $this->makeCountRow($countIndex++, "{$sectionTable} (sect.)", $bitrixSectionCount, $laravelSectionCount);
                }
            }

            // Pivot table counts
            foreach ($iblock->properties as $property) {
                if (! $property->needsPivotTable()) {
                    continue;
                }

                $columnName = $naming->toColumnName($property->code);
                $pivotTable = $laravelTable.'_'.$columnName;

                if (! Schema::hasTable($pivotTable)) {
                    $pivotRows[] = $this->makePivotRow($pivotIndex++, $pivotTable, '?', 'NOT FOUND');

                    continue;
                }

                $bitrixPivotCount = $connection->getMultiplePropertyValuesCount($iblock->id, $property->id);
                $laravelPivotCount = DB::table($pivotTable)->count();
                $pivotRows[] = $this->makePivotRow($pivotIndex++, $pivotTable, $bitrixPivotCount, $laravelPivotCount);
            }
        }

        return [$countRows, $pivotRows];
    }

    /**
     * Spot-check IBlock records
     */
    protected function spotCheckIBlocks(array $iblockIds, int $sampleSize): void
    {
        $analyzer = $this->getIBlockAnalyzer();
        $connection = $this->getBitrixConnection();
        $naming = $this->getNaming();

        foreach ($iblockIds as $iblockId) {
            if ($this->shouldSkipIBlock($iblockId)) {
                continue;
            }

            $iblock = $analyzer->analyze($iblockId);
            if (! $iblock) {
                continue;
            }

            $laravelTable = $naming->toTableName($iblock->code);

            if (! Schema::hasTable($laravelTable)) {
                continue;
            }

            $allIds = $connection->getIBlockElementIds($iblock->id);

            if (empty($allIds)) {
                continue;
            }

            // Pick sample IDs: first, last, and random middle ones
            $sampleIds = $this->pickSampleIds($allIds, $sampleSize);

            $this->info("  {$iblock->name} ({$laravelTable}): checking ".count($sampleIds).' records...');

            $mismatches = 0;

            foreach ($sampleIds as $elementId) {
                $bitrixElement = $connection->getIBlockElementById($elementId);
                if (! $bitrixElement) {
                    $this->recordCheck(false);
                    if ($this->output->isVerbose()) {
                        $this->warn("    ID {$elementId}: not found in Bitrix");
                    }
                    $mismatches++;

                    continue;
                }

                $laravelRow = DB::table($laravelTable)->where('bitrix_id', $elementId)->first();
                if (! $laravelRow) {
                    $this->recordCheck(false);
                    if ($this->output->isVerbose()) {
                        $this->warn("    ID {$elementId}: not found in Laravel (bitrix_id={$elementId})");
                    }
                    $mismatches++;

                    continue;
                }

                $laravelRow = (array) $laravelRow;
                $fieldMismatches = $this->compareBaseFields($bitrixElement, $laravelRow, $iblock);

                if (empty($fieldMismatches)) {
                    $this->recordCheck(true);
                    if ($this->output->isVerbose()) {
                        $this->line("    ID {$elementId}: OK");
                    }
                } else {
                    $this->recordCheck(false);
                    $mismatches++;
                    $this->warn("    ID {$elementId}: ".count($fieldMismatches).' mismatches');
                    if ($this->output->isVerbose()) {
                        foreach ($fieldMismatches as $field => $details) {
                            $this->line("      {$field}: Bitrix=\"{$details['bitrix']}\" vs Laravel=\"{$details['laravel']}\"");
                        }
                    }
                }
            }

            if ($mismatches === 0) {
                $this->info("    All {$sampleSize} records match");
            } else {
                $this->warn("    {$mismatches} of ".count($sampleIds).' with mismatches');
            }
        }
    }

    /**
     * Compare base fields between Bitrix element and Laravel row
     */
    protected function compareBaseFields(array $bitrixElement, array $laravelRow, IBlock $iblock): array
    {
        $mismatches = [];

        // Name
        if (($bitrixElement['NAME'] ?? '') !== ($laravelRow['name'] ?? '')) {
            $mismatches['name'] = [
                'bitrix' => $bitrixElement['NAME'] ?? '',
                'laravel' => $laravelRow['name'] ?? '',
            ];
        }

        // Code
        if (($bitrixElement['CODE'] ?? '') !== ($laravelRow['code'] ?? '')) {
            $mismatches['code'] = [
                'bitrix' => $bitrixElement['CODE'] ?? '',
                'laravel' => $laravelRow['code'] ?? '',
            ];
        }

        // Sort
        $bitrixSort = (int) ($bitrixElement['SORT'] ?? 500);
        $laravelSort = (int) ($laravelRow['sort'] ?? 500);
        if ($bitrixSort !== $laravelSort) {
            $mismatches['sort'] = [
                'bitrix' => $bitrixSort,
                'laravel' => $laravelSort,
            ];
        }

        // Active
        $bitrixActive = ($bitrixElement['ACTIVE'] ?? 'Y') === 'Y';
        $laravelActive = (bool) ($laravelRow['active'] ?? true);
        if ($bitrixActive !== $laravelActive) {
            $mismatches['active'] = [
                'bitrix' => $bitrixActive ? 'Y' : 'N',
                'laravel' => $laravelActive ? 'Y' : 'N',
            ];
        }

        // Section ID (only if iblock has sections)
        if (! empty($iblock->sections) && isset($laravelRow['section_id'])) {
            $bitrixSectionId = ! empty($bitrixElement['IBLOCK_SECTION_ID'])
                ? (int) $bitrixElement['IBLOCK_SECTION_ID']
                : null;
            $laravelSectionId = ! empty($laravelRow['section_id'])
                ? (int) $laravelRow['section_id']
                : null;

            if ($bitrixSectionId !== $laravelSectionId) {
                $mismatches['section_id'] = [
                    'bitrix' => $bitrixSectionId ?? 'null',
                    'laravel' => $laravelSectionId ?? 'null',
                ];
            }
        }

        // Preview text (compare trimmed)
        $bitrixPreview = trim($bitrixElement['PREVIEW_TEXT'] ?? '');
        $laravelPreview = trim($laravelRow['preview_text'] ?? '');
        if ($bitrixPreview !== $laravelPreview) {
            $mismatches['preview_text'] = [
                'bitrix' => Str::limit($bitrixPreview, 50),
                'laravel' => Str::limit($laravelPreview, 50),
            ];
        }

        // Detail text (compare trimmed, only check presence due to potential formatting differences)
        $bitrixDetail = trim($bitrixElement['DETAIL_TEXT'] ?? '');
        $laravelDetail = trim($laravelRow['detail_text'] ?? '');
        $bitrixHasDetail = $bitrixDetail !== '';
        $laravelHasDetail = $laravelDetail !== '';
        if ($bitrixHasDetail !== $laravelHasDetail) {
            $mismatches['detail_text'] = [
                'bitrix' => $bitrixHasDetail ? 'present ('.strlen($bitrixDetail).' chars)' : 'empty',
                'laravel' => $laravelHasDetail ? 'present ('.strlen($laravelDetail).' chars)' : 'empty',
            ];
        }

        return $mismatches;
    }

    /**
     * Check file references exist on disk
     */
    protected function checkFiles(array $iblockIds): void
    {
        $analyzer = $this->getIBlockAnalyzer();
        $naming = $this->getNaming();

        $fileColumns = ['preview_picture', 'detail_picture'];
        $totalFiles = 0;
        $missingFiles = 0;

        foreach ($iblockIds as $iblockId) {
            if ($this->shouldSkipIBlock($iblockId)) {
                continue;
            }

            $iblock = $analyzer->analyze($iblockId);
            if (! $iblock) {
                continue;
            }

            $laravelTable = $naming->toTableName($iblock->code);

            if (! Schema::hasTable($laravelTable)) {
                continue;
            }

            // Collect file columns (base + file properties)
            $columnsToCheck = [];
            foreach ($fileColumns as $col) {
                if (Schema::hasColumn($laravelTable, $col)) {
                    $columnsToCheck[] = $col;
                }
            }

            foreach ($iblock->properties as $property) {
                if ($property->isFile() && ! $property->multiple) {
                    $colName = $naming->toColumnName($property->code);
                    if (Schema::hasColumn($laravelTable, $colName)) {
                        $columnsToCheck[] = $colName;
                    }
                }
            }

            if (empty($columnsToCheck)) {
                continue;
            }

            $rows = DB::table($laravelTable)->select($columnsToCheck)->get();
            $tableMissing = 0;

            foreach ($rows as $row) {
                $row = (array) $row;
                foreach ($columnsToCheck as $col) {
                    $value = $row[$col] ?? null;
                    if (empty($value)) {
                        continue;
                    }

                    $totalFiles++;

                    // Check if file exists
                    $filePath = public_path(ltrim($value, '/'));
                    if (! file_exists($filePath)) {
                        $tableMissing++;
                        $missingFiles++;
                    }
                }
            }

            $tableTotal = $rows->count() * count($columnsToCheck);
            if ($tableMissing > 0) {
                $this->warn("  {$laravelTable}: {$tableMissing} files not found");
                $this->recordCheck(false);
            } else {
                $this->info("  {$laravelTable}: all files present");
                $this->recordCheck(true);
            }
        }

        $this->newLine();
        $this->info("  Total file references: {$totalFiles}, missing: {$missingFiles}");
    }

    /**
     * Pick sample IDs from array: first, last, and random middle ones
     */
    protected function pickSampleIds(array $allIds, int $sampleSize): array
    {
        if (count($allIds) <= $sampleSize) {
            return $allIds;
        }

        $sample = [];

        // Always include first and last
        $sample[] = $allIds[0];
        $sample[] = $allIds[count($allIds) - 1];

        // Fill remaining with random picks
        $middleIds = array_slice($allIds, 1, -1);
        $remaining = $sampleSize - 2;

        if ($remaining > 0 && ! empty($middleIds)) {
            $randomKeys = array_rand($middleIds, min($remaining, count($middleIds)));
            if (! is_array($randomKeys)) {
                $randomKeys = [$randomKeys];
            }
            foreach ($randomKeys as $key) {
                $sample[] = $middleIds[$key];
            }
        }

        return array_unique($sample);
    }

    /**
     * Create a count comparison row for the table
     */
    protected function makeCountRow(int $index, string $entity, int|string $bitrixCount, int|string $laravelCount, ?bool $forceResult = null): array
    {
        if ($forceResult === false) {
            $match = 'ERROR';
            $diff = '-';
            $this->recordCheck(false);
        } elseif (is_int($bitrixCount) && is_int($laravelCount)) {
            $diff = $bitrixCount - $laravelCount;
            $match = $diff === 0 ? 'OK' : 'MISMATCH';
            $this->recordCheck($diff === 0);
        } else {
            $diff = '-';
            $match = 'ERROR';
            $this->recordCheck(false);
        }

        return [$index, $entity, $bitrixCount, $laravelCount, $match, $diff];
    }

    /**
     * Create a pivot comparison row for the table
     */
    protected function makePivotRow(int $index, string $table, int|string $bitrixCount, int|string $laravelCount): array
    {
        if (is_int($bitrixCount) && is_int($laravelCount)) {
            $match = $bitrixCount === $laravelCount ? 'OK' : "MISMATCH ({$bitrixCount} vs {$laravelCount})";
            $this->recordCheck($bitrixCount === $laravelCount);
        } else {
            $match = 'ERROR';
            $this->recordCheck(false);
        }

        return [$index, $table, $bitrixCount, $laravelCount, $match];
    }

    /**
     * Record a check result
     */
    protected function recordCheck(bool $passed): void
    {
        $this->totalChecks++;
        if ($passed) {
            $this->passedChecks++;
        } else {
            $this->failedChecks++;
        }
    }
}
