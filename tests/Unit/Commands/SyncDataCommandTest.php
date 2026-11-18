<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Commands\SyncDataCommand;
use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * sync: the catch-up transfer. New and changed rows come over, local edits
 * and protected columns stay, rows gone from Bitrix are reported or pruned.
 */
final class SyncDataCommandTest extends SyntheticTestCase
{
    private const HEADERS = ['Table', 'Added', 'Updated', 'Unchanged', 'Missing in Bitrix'];

    private const LATER = '2030-01-01 00:00:00';

    private function migrate(): void
    {
        $this->generateSchema();
        $this->artisan('atrium:bitrix:migrate:data', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    /**
     * @return iterable<string,array{array<string,bool>,string}>
     */
    public static function banners(): iterable
    {
        yield 'a plain run' => [[], "\n\n"];
        yield 'a dry run' => [['--dry-run' => true], "\nDry run: nothing is written.\n\n\n"];
        yield 'an audit' => [['--audit' => true], "\nFull audit: checking all elements, not only those fresh in Bitrix.\n\n\n"];
    }

    /**
     * @param  array<string,bool>  $options
     */
    #[DataProvider('banners')]
    public function test_the_report_is_laid_out_with_blank_lines_between_its_parts(array $options, string $banner): void
    {
        $this->migrate();
        $this->changeBitrix();

        Artisan::call('atrium:bitrix:sync', ['--force' => true, '--chunk' => 2] + $options);

        $expected = <<<'TXT'
        Catch-up transfer from Bitrix
        ==============================

        TXT;
        $expected .= $banner.<<<'TXT'
        News -> news (2)
          ~ section 3: name, updated_at, detail_picture
          + section 4: Tablets

        Products -> products (4)
          ~ 2: Laptop Air 2
              name, updated_at
          + 6: Tablet

        Total
        +------------------+-------+---------+-----------+-------------------+
        | Table            | Added | Updated | Unchanged | Missing in Bitrix |
        +------------------+-------+---------+-----------+-------------------+
        | news             | 0     | 0       | 2         | 0                 |
        | product_sections | 1     | 1       | 2         | 0                 |
        | products         | 1     | 1       | 2         | 0                 |
        +------------------+-------+---------+-----------+-------------------+

        TXT;

        $this->assertSame($expected, Artisan::output());
    }

    public function test_nothing_changed_in_bitrix_means_nothing_to_do(): void
    {
        $this->migrate();
        $before = DB::table('products')->orderBy('id')->get()->toArray();

        $this->artisan('atrium:bitrix:sync', ['--force' => true])
            ->doesntExpectOutputToContain('Dry run')
            ->doesntExpectOutputToContain('Full audit')
            ->expectsOutput('News -> news (2)')
            ->expectsOutput('Products -> products (3)')
            ->expectsTable(self::HEADERS, [
                ['news', 0, 0, 2, 0],
                ['product_sections', 0, 0, 3, 0],
                ['products', 0, 0, 3, 0],
            ])
            ->assertSuccessful();

        $this->assertEquals($before, DB::table('products')->orderBy('id')->get()->toArray());
    }

    public function test_the_summary_of_one_run_does_not_carry_over_to_the_next(): void
    {
        $this->migrate();

        $this->artisan('atrium:bitrix:sync', ['--force' => true])
            ->expectsTable(self::HEADERS, [
                ['news', 0, 0, 2, 0],
                ['product_sections', 0, 0, 3, 0],
                ['products', 0, 0, 3, 0],
            ])
            ->assertSuccessful();

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['1']])
            ->expectsTable(self::HEADERS, [
                ['news', 0, 0, 2, 0],
            ])
            ->assertSuccessful();
    }

    public function test_new_and_changed_rows_come_over(): void
    {
        $this->migrate();
        $this->changeBitrix();

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--chunk' => 2])
            ->expectsOutput('  ~ section 3: name, updated_at, detail_picture')
            ->expectsOutput('  + section 4: Tablets')
            ->expectsOutput('Products -> products (4)')
            ->expectsOutput('  ~ 2: Laptop Air 2')
            ->expectsOutput('      name, updated_at')
            ->expectsOutput('  + 6: Tablet')
            ->expectsTable(self::HEADERS, [
                ['news', 0, 0, 2, 0],
                ['product_sections', 1, 1, 2, 0],
                ['products', 1, 1, 2, 0],
            ])
            ->assertSuccessful();

        $this->assertSame('Apps', DB::table('product_sections')->where('id', 3)->value('name'));
        $this->assertSame('/upload/iblock/a1b/laptop.jpg', DB::table('product_sections')->where('id', 3)->value('detail_picture'));
        $this->assertSame('Tablets', DB::table('product_sections')->where('id', 4)->value('name'));
        $this->assertSame('Hardware', DB::table('product_sections')->where('id', 1)->value('name'), 'touched, but nothing differs');
        $this->assertSame('Laptop Air 2', DB::table('products')->where('id', 2)->value('name'));
        $this->assertSame('Tablet', DB::table('products')->where('id', 6)->value('name'));
        $this->assertSame('TB-006', DB::table('products')->where('id', 6)->value('article'));
        $this->assertSame([[1, 2], [6, 1]], $this->related(), 'the old link of product 2 is gone, the new one of 6 is in');
    }

    public function test_a_dry_run_shows_the_plan_and_writes_nothing(): void
    {
        $this->migrate();
        $this->changeBitrix();
        $this->bitrix()->table('b_iblock_element')->where('ID', 3)->delete();
        $before = DB::table('products')->orderBy('id')->get()->toArray();

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--dry-run' => true, '--prune' => true])
            ->expectsOutput('Dry run: nothing is written.')
            ->expectsOutput('  + section 4: Tablets')
            ->expectsOutput('  ~ 2: Laptop Air 2')
            ->expectsOutput('  + 6: Tablet')
            ->expectsOutput('  - 3: Office Suite')
            ->expectsTable(self::HEADERS, [
                ['news', 0, 0, 2, 0],
                ['product_sections', 1, 1, 2, 0],
                ['products', 1, 1, 1, 1],
            ])
            ->assertSuccessful();

        $this->assertEquals($before, DB::table('products')->orderBy('id')->get()->toArray());
        $this->assertSame(3, DB::table('product_sections')->count());
        $this->assertSame([[1, 2], [2, 1]], $this->related());
    }

    public function test_the_audit_compares_everything_and_writes_nothing(): void
    {
        $this->migrate();
        // A local edit Bitrix knows nothing about: the date check would hide it.
        DB::table('products')->where('id', 1)->update(['name' => 'Edited here', 'updated_at' => self::LATER]);

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--audit' => true])
            ->expectsOutput('Full audit: checking all elements, not only those fresh in Bitrix.')
            ->doesntExpectOutput('Dry run: nothing is written.')
            ->expectsOutput('  ~ 1: Laptop Pro')
            ->expectsTable(self::HEADERS, [
                ['news', 0, 0, 2, 0],
                ['product_sections', 0, 0, 3, 0],
                ['products', 0, 1, 2, 0],
            ])
            ->assertSuccessful();

        $this->assertSame('Edited here', DB::table('products')->where('id', 1)->value('name'));
    }

    public function test_local_edits_newer_than_bitrix_are_kept(): void
    {
        $this->migrate();
        DB::table('products')->where('id', 1)->update(['name' => 'Edited here', 'updated_at' => self::LATER]);
        $this->bitrix()->table('b_iblock_element')->where('ID', 1)->update(['NAME' => 'Renamed in Bitrix', 'TIMESTAMP_X' => '2029-01-01 00:00:00']);

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['2']])
            ->doesntExpectOutputToContain('~ 1:')
            ->expectsTable(self::HEADERS, [
                ['product_sections', 0, 0, 3, 0],
                ['products', 0, 0, 3, 0],
            ])
            ->assertSuccessful();

        $this->assertSame('Edited here', DB::table('products')->where('id', 1)->value('name'));
    }

    public function test_protected_columns_keep_the_local_value(): void
    {
        $this->migrate();
        config(['atrium-bitrix.sync_protected' => ['products' => ['name', 'code']]]);
        DB::table('products')->where('id', 1)->update(['name' => 'Our name']);
        $this->bitrix()->table('b_iblock_element')->where('ID', 1)->update([
            'NAME' => 'Their name', 'PREVIEW_TEXT' => 'New preview', 'TIMESTAMP_X' => self::LATER,
        ]);

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['2']])
            ->expectsOutput('      name: local edit kept, not taking it from Bitrix')
            ->doesntExpectOutputToContain('code: local edit kept')
            ->expectsOutput('  ~ 1: Their name')
            ->expectsOutput('      preview_text, updated_at')
            ->assertSuccessful();

        $this->assertSame('Our name', DB::table('products')->where('id', 1)->value('name'));
        $this->assertSame('New preview', DB::table('products')->where('id', 1)->value('preview_text'));
    }

    public function test_rows_gone_from_bitrix_are_reported_but_kept(): void
    {
        $this->migrate();
        $this->bitrix()->table('b_iblock_element')->where('ID', 3)->delete();
        $this->bitrix()->table('b_iblock_section')->where('ID', 3)->delete();

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['2']])
            ->expectsOutput('  no longer in Bitrix: 3 Office Suite (use --prune to delete)')
            ->doesntExpectOutputToContain('- 3:')
            ->expectsTable(self::HEADERS, [
                ['product_sections', 0, 0, 2, 1],
                ['products', 0, 0, 2, 1],
            ])
            ->assertSuccessful();

        $this->assertSame(3, DB::table('products')->count());
        $this->assertSame(3, DB::table('product_sections')->count());
    }

    public function test_pruning_deletes_rows_gone_from_bitrix(): void
    {
        $this->migrate();
        $this->bitrix()->table('b_iblock_element')->where('ID', 3)->delete();

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['2'], '--prune' => true])
            ->expectsOutput('  - 3: Office Suite')
            ->doesntExpectOutputToContain('no longer in Bitrix')
            ->assertSuccessful();

        $this->assertSame([1, 2], DB::table('products')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_without_iblocks_there_is_nothing_to_sync(): void
    {
        $this->bitrix()->table('b_iblock')->delete();

        $this->artisan('atrium:bitrix:sync', ['--force' => true])
            ->expectsOutput('No iblocks found.')
            ->doesntExpectOutput('Total')
            ->assertFailed();
    }

    public function test_skipped_missing_and_empty_iblocks_are_passed_over(): void
    {
        $this->migrate();
        config(['atrium-bitrix.skip_iblocks' => [2]]);
        $this->bitrix()->table('b_iblock_element')->where('IBLOCK_ID', 1)->delete();

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['2', '99', '1']])
            ->expectsOutput('Iblock 99 not found')
            ->doesntExpectOutputToContain('Products ->')
            ->doesntExpectOutputToContain('News ->')
            ->expectsTable(self::HEADERS, [])
            ->assertSuccessful();
    }

    public function test_tables_that_were_never_created_are_skipped(): void
    {
        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['2']])
            ->expectsOutput('Table product_sections does not exist, skipping')
            ->expectsOutput('Table products does not exist, skipping')
            ->assertSuccessful();
    }

    public function test_a_missing_link_table_does_not_stop_the_update(): void
    {
        $this->skipOnPostgres();
        $this->migrate();
        Schema::drop('products_related');
        $this->bitrix()->table('b_iblock_element')->where('ID', 2)->update(['NAME' => 'Laptop Air 2', 'TIMESTAMP_X' => self::LATER]);

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['2']])
            ->expectsOutput('  ~ 2: Laptop Air 2')
            ->doesntExpectOutputToContain('does not exist')
            ->assertSuccessful();

        $this->assertSame('Laptop Air 2', DB::table('products')->where('id', 2)->value('name'));
    }

    public function test_reading_stops_when_bitrix_returns_fewer_elements_than_it_counted(): void
    {
        $this->migrate();
        $command = $this->command(['--chunk' => '2']);
        $connection = new class('bitrix') extends BitrixConnection
        {
            public int $reads = 0;

            public function getIBlockElementCount(int $iblockId): int
            {
                return 10;
            }

            public function getIBlockElements(int $iblockId, int $limit = 100, int $offset = 0): array
            {
                $this->reads++;

                return parent::getIBlockElements($iblockId, $limit, $offset);
            }
        };
        $this->set($command, 'bitrixConnection', $connection);
        $this->set($command, 'chunkSize', 2);
        $this->set($command, 'skipFiles', true);

        $this->invoke($command, 'syncElements', $this->invoke($command, 'getIBlockAnalyzer')->analyze(2));

        $this->assertSame(3, $connection->reads, 'two pages and the empty one');
        $this->assertSame([['products', 0, 0, 3, 0]], $this->read($command, 'report'));
    }

    /**
     * What a run prints when Bitrix hands its rows over reshaped, as another
     * database driver or an older Bitrix would.
     *
     * @param  array<string,mixed>  $options
     * @param  callable(array<string,mixed>): array<string,mixed>  $reshape
     */
    private function runWithRowsReshaped(callable $reshape, array $options = []): string
    {
        $command = $this->command(['--force' => true, '--chunk' => '2'] + $options);
        $this->set($command, 'bitrixConnection', new class('bitrix', $reshape) extends BitrixConnection
        {
            /** @param callable(array<string,mixed>): array<string,mixed> $reshape */
            public function __construct(?string $name, private $reshape)
            {
                parent::__construct($name);
            }

            public function getIBlocks(?string $typeId = null): array
            {
                return array_map($this->reshape, parent::getIBlocks($typeId));
            }

            public function getIBlockSections(int $iblockId): array
            {
                return array_map($this->reshape, parent::getIBlockSections($iblockId));
            }

            public function getIBlockElements(int $iblockId, int $limit, int $offset): array
            {
                return array_map($this->reshape, parent::getIBlockElements($iblockId, $limit, $offset));
            }
        });

        $this->assertSame(0, $command->handle());

        return $this->output->fetch();
    }

    public function test_a_section_without_ordering_or_flags_gets_the_usual_defaults(): void
    {
        $this->migrate();
        $db = $this->bitrix();
        $db->table('b_iblock_section')->insert(['ID' => 5, 'IBLOCK_ID' => 2, 'NAME' => 'Bare', 'CODE' => 'bare', 'LEFT_MARGIN' => 8, 'RIGHT_MARGIN' => 9]);
        $db->table('b_iblock_section')->insert(['ID' => 6, 'IBLOCK_ID' => 2, 'NAME' => 'Hidden', 'CODE' => 'hidden', 'ACTIVE' => 'N', 'SORT' => 700, 'LEFT_MARGIN' => 10, 'RIGHT_MARGIN' => 11, 'DEPTH_LEVEL' => 3]);

        $this->runWithRowsReshaped(static function (array $row): array {
            if (($row['ID'] ?? null) === 5) {
                unset($row['SORT'], $row['ACTIVE'], $row['DEPTH_LEVEL'], $row['LEFT_MARGIN'], $row['RIGHT_MARGIN']);
            }

            return $row;
        }, ['--iblock' => ['2']]);

        $bare = DB::table('product_sections')->where('id', 5)->first();
        $this->assertSame([500, true, 0, 0, 0], [(int) $bare->sort, (bool) $bare->active, (int) $bare->depth, (int) $bare->left_margin, (int) $bare->right_margin]);
        $hidden = DB::table('product_sections')->where('id', 6)->first();
        $this->assertSame([700, false, 3, 10, 11], [(int) $hidden->sort, (bool) $hidden->active, (int) $hidden->depth, (int) $hidden->left_margin, (int) $hidden->right_margin]);
    }

    public function test_every_new_row_of_a_run_comes_over_with_its_own_id_and_picture(): void
    {
        $this->migrate();
        $db = $this->bitrix();
        $db->table('b_iblock_section')->insert(['ID' => 4, 'IBLOCK_ID' => 2, 'NAME' => 'Pictured', 'CODE' => 'pictured', 'PICTURE' => 2, 'LEFT_MARGIN' => 7, 'RIGHT_MARGIN' => 8, 'DEPTH_LEVEL' => 1]);
        $db->table('b_iblock_section')->insert(['ID' => 9, 'IBLOCK_ID' => 2, 'NAME' => 'Plain', 'CODE' => 'plain', 'LEFT_MARGIN' => 9, 'RIGHT_MARGIN' => 10, 'DEPTH_LEVEL' => 1]);
        $db->table('b_iblock_element')->insert([
            ['ID' => 6, 'IBLOCK_ID' => 2, 'NAME' => 'Sixth', 'CODE' => 'sixth', 'ACTIVE' => 'Y', 'SORT' => 600, 'TIMESTAMP_X' => self::LATER],
            ['ID' => 7, 'IBLOCK_ID' => 2, 'NAME' => 'Seventh', 'CODE' => 'seventh', 'ACTIVE' => 'Y', 'SORT' => 700, 'TIMESTAMP_X' => self::LATER],
        ]);

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['2']])->assertSuccessful();

        $this->assertSame([1, 2, 3, 4, 9], DB::table('product_sections')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([4, 9], DB::table('product_sections')->whereIn('id', [4, 9])->orderBy('id')->pluck('bitrix_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('/upload/iblock/c2d/laptop-side.jpg', DB::table('product_sections')->where('id', 4)->value('picture'));
        $this->assertNull(DB::table('product_sections')->where('id', 9)->value('picture'));
        $this->assertSame([1, 2, 3, 6, 7], DB::table('products')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_an_iblock_that_is_missing_or_skipped_does_not_stop_the_ones_after_it(): void
    {
        $this->migrate();
        config(['atrium-bitrix.skip_iblocks' => [1]]);

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['1', '99', '2']])
            ->expectsOutput('Iblock 99 not found')
            ->expectsOutput('Products -> products (3)')
            ->doesntExpectOutputToContain('News ->')
            ->assertSuccessful();
    }

    public function test_a_changed_row_keeps_its_links(): void
    {
        $this->migrate();
        $this->bitrix()->table('b_iblock_element')->where('ID', 1)->update(['NAME' => 'Laptop Pro 2', 'TIMESTAMP_X' => self::LATER]);

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['2']])
            ->expectsOutput('  ~ 1: Laptop Pro 2')
            ->assertSuccessful();

        $this->assertSame([[1, 2], [2, 1]], $this->related());
    }

    public function test_a_link_table_that_is_gone_does_not_stop_the_clearing_of_the_next_one(): void
    {
        $this->skipOnPostgres();
        $db = $this->bitrix();
        $db->table('b_iblock_property')->insert([
            'ID' => 24, 'IBLOCK_ID' => 2, 'NAME' => 'Also related', 'CODE' => 'ALSO_RELATED', 'PROPERTY_TYPE' => 'E',
            'MULTIPLE' => 'Y', 'SORT' => 999, 'LINK_IBLOCK_ID' => 2,
        ]);
        $db->table('b_iblock_element_prop_m2')->insert(['ID' => 30, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 24, 'VALUE' => '3']);
        $this->migrate();
        $this->assertSame(1, DB::table('products_also_related')->count());

        Schema::drop('products_related');
        $db->table('b_iblock_element_prop_m2')->where('ID', 30)->delete();
        $db->table('b_iblock_element')->where('ID', 1)->update(['NAME' => 'Laptop Pro 2', 'TIMESTAMP_X' => self::LATER]);

        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--iblock' => ['2']])
            ->expectsOutput('  ~ 1: Laptop Pro 2')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('products_also_related')->count(), 'the link removed in Bitrix is gone here too');
    }

    public function test_a_page_as_big_as_the_whole_iblock_is_the_only_one_read(): void
    {
        $this->migrate();
        $command = $this->command(['--chunk' => '3']);
        $connection = new class('bitrix') extends BitrixConnection
        {
            public int $reads = 0;

            public function getIBlockElements(int $iblockId, int $limit, int $offset): array
            {
                $this->reads++;

                return parent::getIBlockElements($iblockId, $limit, $offset);
            }
        };
        $this->set($command, 'bitrixConnection', $connection);
        $this->set($command, 'chunkSize', 3);
        $this->set($command, 'skipFiles', true);

        $this->invoke($command, 'syncElements', $this->invoke($command, 'getIBlockAnalyzer')->analyze(2));

        $this->assertSame(1, $connection->reads);
    }

    /**
     * @return iterable<string,array{mixed,mixed,bool}>
     */
    public static function values(): iterable
    {
        yield 'null and empty' => [null, '', false];
        yield 'blank and null' => ['  ', null, false];
        yield 'null and a value' => [null, 'x', true];
        yield 'a value and null' => ['x', null, true];
        yield 'true and 1' => [true, '1', false];
        yield 'false and 0' => [false, '0', false];
        yield 'true and 0' => [true, '0', true];
        yield 'a date and its text' => [new \DateTimeImmutable('2024-01-01 10:00:00'), '2024-01-01 10:00:00', false];
        yield 'a date and another' => [new \DateTimeImmutable('2024-01-01 10:00:00'), '2024-01-01 11:00:00', true];
        yield 'an array and its JSON with spaces' => [['a', 'b'], '["a", "b"]', false];
        yield 'an array of Cyrillic text and its JSON' => [['Ключ'], '["Ключ"]', false];
        yield 'two different lists' => ['["a"]', '["b"]', true];
        yield 'one object, formatted two ways' => ['{"a":1}', "{\n  \"a\": 1\n}", false];
        yield 'a quoted text and the bare text' => ['"x"', 'x', true];
        yield 'the same broken JSON' => ['[broken', '[broken', false];
        yield 'two broken JSONs' => ['[broken', '[other', true];
        yield 'a text only JSON could read as the same value' => ['TRUE', 'true', true];
        yield 'a bare JSON word and itself' => ['null', 'null', false];
        yield 'a price as text and as a number' => ['1999.50', 1999.5, false];
        yield 'numbers within a millionth' => ['1', '1.0000001', false];
        yield 'numbers apart' => ['1', '1.00001', true];
        yield 'a number and its text' => [5, '5', false];
        yield 'a date with a T and with a space' => ['2024-03-15T12:30:00', '2024-03-15 12:30:00', false];
        yield 'dates a minute apart' => ['2024-03-15 12:30:00', '2024-03-15 12:31:00', true];
        yield 'the same impossible date' => ['2024-13-45 99:99', '2024-13-45 99:99', false];
        yield 'two impossible dates' => ['2024-13-45 99:99', '2024-13-46 99:99', true];
        yield 'a date and a word' => ['2024-03-15 12:30:00', 'soon', true];
        yield 'texts that differ in spaces around' => [' text ', 'text', false];
        yield 'two texts' => ['a', 'b', true];
        yield 'a word and zero' => ['abc', '0', true];
        yield 'numbers exactly a millionth apart' => ['0', '0.000001', false];
        yield 'a date led by a weekday is only text' => ['Friday 2024-03-15 12:30:00', '2024-03-15 12:30:00', true];
        yield 'a date without a time is only text' => ['2024-03-15', '2024-03-15 00:00:00', true];
    }

    #[DataProvider('values')]
    public function test_values_are_compared_by_meaning(mixed $new, mixed $old, bool $differ): void
    {
        $command = $this->command();

        $this->assertSame($differ, $this->invoke($command, 'valuesDiffer', $new, $old));
        $this->assertSame($differ, $this->invoke($command, 'valuesDiffer', $old, $new), 'both ways');
    }

    /**
     * @return iterable<string,array{mixed,mixed,bool,bool}>
     */
    public static function timestamps(): iterable
    {
        yield 'Bitrix later' => ['2024-02-02 10:00:00', '2024-02-02 09:59:59', false, true];
        yield 'the same moment' => ['2024-02-02 10:00:00', '2024-02-02 10:00:00', false, false];
        yield 'ours later' => ['2024-02-02 10:00:00', '2024-02-02 10:00:01', false, false];
        yield 'no date in Bitrix' => [null, '2024-02-02 10:00:00', false, false];
        yield 'no date here' => ['2024-02-02 10:00:00', null, false, true];
        yield 'no date anywhere' => ['', '', false, false];
        yield 'the audit, ours later' => ['2024-02-02 10:00:00', '2024-02-02 10:00:01', true, true];
        yield 'the audit, no date in Bitrix' => [null, '2024-02-02 10:00:00', true, true];
    }

    #[DataProvider('timestamps')]
    public function test_bitrix_is_newer_only_when_it_changed_later(mixed $bitrix, mixed $local, bool $audit, bool $newer): void
    {
        $command = $this->command();
        $this->set($command, 'audit', $audit);

        $this->assertSame($newer, $this->invoke($command, 'bitrixIsNewer', $bitrix, $local));
    }

    public function test_changed_columns_leave_out_keys_unknown_columns_and_a_bare_timestamp(): void
    {
        config(['atrium-bitrix.sync_protected' => ['things' => ['code']]]);
        $command = $this->command();
        $this->set($command, 'jsonColumnsCache', ['things' => []]);
        $current = (object) ['id' => 1, 'name' => 'Same', 'code' => 'ours', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'];
        $data = ['id' => 2, 'name' => 'Same', 'code' => 'theirs', 'ghost' => 'x', 'created_at' => '2021-01-01 00:00:00', 'updated_at' => self::LATER];

        $this->assertSame([], $this->invoke($command, 'changedColumns', 'things', $data, $current));
        $this->assertStringContainsString('      code: local edit kept, not taking it from Bitrix', $this->output->fetch());

        $this->assertSame(
            ['name' => 'New', 'updated_at' => self::LATER],
            $this->invoke($command, 'changedColumns', 'things', ['name' => 'New'] + $data, $current),
        );
        $this->output->fetch();
        $this->assertSame(['name' => 'New'], $this->invoke($command, 'changedColumns', 'things', ['name' => 'New', 'code' => 'ours'], $current));
        $this->assertSame('', $this->output->fetch(), 'an unchanged protected column says nothing');
    }

    /**
     * Section 3 renamed with a picture, section 1 touched without a change,
     * a new section 4; product 2 renamed and unlinked, a new product 6.
     */
    private function changeBitrix(): void
    {
        $db = $this->bitrix();
        $db->table('b_iblock_section')->where('ID', 3)->update(['NAME' => 'Apps', 'DETAIL_PICTURE' => 1, 'TIMESTAMP_X' => self::LATER]);
        $db->table('b_iblock_section')->where('ID', 1)->update(['TIMESTAMP_X' => self::LATER]);
        $db->table('b_iblock_section')->insert([
            'ID' => 4, 'IBLOCK_ID' => 2, 'IBLOCK_SECTION_ID' => 1, 'NAME' => 'Tablets', 'CODE' => 'tablets', 'SORT' => 300,
            'LEFT_MARGIN' => 7, 'RIGHT_MARGIN' => 8, 'DEPTH_LEVEL' => 2, 'TIMESTAMP_X' => self::LATER,
        ]);
        $db->table('b_iblock_element')->where('ID', 2)->update(['NAME' => 'Laptop Air 2', 'TIMESTAMP_X' => self::LATER]);
        $db->table('b_iblock_element_prop_m2')->where('IBLOCK_ELEMENT_ID', 2)->where('IBLOCK_PROPERTY_ID', 17)->delete();
        $db->table('b_iblock_element')->insert([
            'ID' => 6, 'IBLOCK_ID' => 2, 'IBLOCK_SECTION_ID' => 4, 'NAME' => 'Tablet', 'CODE' => 'tablet', 'ACTIVE' => 'Y', 'SORT' => 600,
            'DATE_CREATE' => self::LATER, 'TIMESTAMP_X' => self::LATER,
        ]);
        $db->table('b_iblock_element_prop_s2')->insert(['IBLOCK_ELEMENT_ID' => 6, 'PROPERTY_10' => 'TB-006']);
        $db->table('b_iblock_element_prop_m2')->insert(['ID' => 20, 'IBLOCK_ELEMENT_ID' => 6, 'IBLOCK_PROPERTY_ID' => 17, 'VALUE' => '1']);
    }

    /**
     * @return list<array{int,int}>
     */
    private function related(): array
    {
        return DB::table('products_related')->orderBy('product_id')->get(['product_id', 'related_id'])
            ->map(fn (object $row): array => [(int) $row->product_id, (int) $row->related_id])
            ->all();
    }

    private function skipOnPostgres(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->markTestSkipped('PostgreSQL refuses every statement after a failed one in the test transaction, and the command goes on.');
        }
    }

    private BufferedOutput $output;

    /**
     * @param  array<string,mixed>  $options
     */
    private function command(array $options = []): SyncDataCommand
    {
        $command = $this->app->make(SyncDataCommand::class);
        $command->setLaravel($this->app);
        $command->setInput(new ArrayInput($options, $command->getDefinition()));
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));

        return $command;
    }

    private function invoke(object $command, string $method, mixed ...$args): mixed
    {
        return (fn () => $this->{$method}(...$args))->call($command);
    }

    private function set(object $command, string $property, mixed $value): void
    {
        (fn () => $this->{$property} = $value)->call($command);
    }

    private function read(object $command, string $property): mixed
    {
        return (fn () => $this->{$property})->call($command);
    }
}
