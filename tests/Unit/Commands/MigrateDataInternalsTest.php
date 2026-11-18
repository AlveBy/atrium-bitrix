<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Commands\MigrateDataCommand;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockPropertyEnum;
use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Tests\Fixtures\SyntheticBitrix;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The value conversions of migrate:data, one by one: what a Bitrix value
 * becomes in the Laravel column, and what is dropped.
 */
final class MigrateDataInternalsTest extends SyntheticTestCase
{
    private BufferedOutput $output;

    private function command(bool $skipFiles = false): MigrateDataCommand
    {
        $command = $this->app->make(MigrateDataCommand::class);
        $command->setLaravel($this->app);
        $command->setInput(new ArrayInput(['--force' => true], $command->getDefinition()));
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        $this->set($command, 'skipFiles', $skipFiles);
        $this->set($command, 'chunkSize', 100);

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

    /**
     * @param  array<string,mixed>  $extra
     */
    private static function property(string $type, ?string $userType = null, bool $multiple = false, array $extra = []): IBlockProperty
    {
        return IBlockProperty::fromArray([
            'ID' => 10, 'IBLOCK_ID' => 2, 'CODE' => 'VALUE', 'NAME' => 'Value', 'PROPERTY_TYPE' => $type,
            'USER_TYPE' => $userType, 'MULTIPLE' => $multiple ? 'Y' : 'N', ...$extra,
        ]);
    }

    private static function colour(bool $multiple = false): IBlockProperty
    {
        $property = self::property('L', null, $multiple);
        $property->enumValues = [
            IBlockPropertyEnum::fromArray(['ID' => 1, 'PROPERTY_ID' => 10, 'VALUE' => 'Silver', 'XML_ID' => 'silver']),
            IBlockPropertyEnum::fromArray(['ID' => 2, 'PROPERTY_ID' => 10, 'VALUE' => 'Black', 'XML_ID' => '']),
        ];

        return $property;
    }

    private function transform(IBlockProperty $property, mixed $value, bool $skipFiles = false): mixed
    {
        return $this->invoke($this->command($skipFiles), 'transformPropertyValue', $property, $value);
    }

    /**
     * @return iterable<string,array{mixed,mixed}>
     */
    public static function strings(): iterable
    {
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'blank' => ["  \t", null];
        yield 'text' => ['Plain', 'Plain'];
        yield 'text with spaces is kept as it is' => [' Plain ', ' Plain '];
        yield 'zero' => [0, 0];
        yield 'zero as text' => ['0', '0'];
        yield 'serialized text' => ['s:5:"Hello";', 'Hello'];
        yield 'serialized number' => ['i:42;', 42];
        yield 'serialized empty text' => ['s:0:"";', null];
        yield 'serialized blank text' => ['s:2:"  ";', null];
        yield 'serialized false' => ['b:0;', null];
        yield 'serialized true' => ['b:1;', true];
        yield 'serialized array' => ['a:2:{i:0;s:1:"x";i:1;s:1:"y";}', '["x","y"]'];
        yield 'broken serialized text stays text' => ['s:9:"oops', 's:9:"oops'];
        yield 'text that only looks serialized at the end' => ['note s:1:"x";', 'note s:1:"x";'];
        yield 'serialized object is dropped' => ['O:8:"stdClass":0:{}', null];
        yield 'array' => [['a', 'b'], '["a","b"]'];
        yield 'empty array' => [[], null];
        yield 'array of numbers and text' => [[1, 'x'], '[1,"x"]'];
        yield 'array of serialized values' => [['a:1:{i:0;s:1:"x";}', 'plain', 's:3:"abc";'], '[["x"],"plain","abc"]'];
        yield 'an array with keys keeps them' => [['k' => 'v'], '{"k":"v"}'];
        yield 'an array with keys loses them once a value is unserialized' => [['k' => 'i:7;', 'j' => 'x'], '[7,"x"]'];
        yield 'a number next to a serialized value' => [[1, 'i:7;'], '[1,7]'];
        yield 'a text that unserializes but does not look serialized' => ['N;', 'N;'];
        yield 'such a text next to a serialized value' => [['N;', 'i:7;'], '["N;",7]'];
        yield 'serialized values after a plain one' => [['plain', 'i:7;'], '["plain",7]'];
        yield 'array with a broken and a false value' => [['s:9:"broken', 'b:0;'], '["s:9:\"broken",false]'];
    }

    #[DataProvider('strings')]
    public function test_a_plain_property_value(mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, $this->transform(self::property('S'), $value));
    }

    /**
     * @return iterable<string,array{mixed,bool}>
     */
    public static function ticks(): iterable
    {
        yield 'Y' => ['Y', true];
        yield '1' => ['1', true];
        yield 'one' => [1, true];
        yield 'serialized true' => ['b:1;', true];
        yield 'N' => ['N', false];
        yield '0' => ['0', false];
        yield 'yes' => ['yes', false];
        yield 'two' => [2, false];
    }

    #[DataProvider('ticks')]
    public function test_a_checkbox_becomes_a_boolean(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, $this->transform(self::property('S', 'SASDCheckbox'), $value));
        $this->assertSame($expected, $this->transform(self::property('N', 'SASDCheckboxNum'), $value));
    }

    public function test_files_become_paths_and_unknown_files_are_dropped(): void
    {
        $photos = self::property('F', null, true);

        $this->assertSame('/upload/iblock/g4h/hardware.png', $this->transform(self::property('F'), '4'));
        $this->assertSame('["\/upload\/iblock\/a1b\/laptop.jpg","\/upload\/iblock\/c2d\/laptop-side.jpg"]', $this->transform($photos, ['1', '99', '2']));
        $this->assertNull($this->transform($photos, ['0', '99']), 'no file is left');
        $this->assertNull($this->transform($photos, []));
        $this->assertNull($this->transform(self::property('F'), '99'));
    }

    public function test_with_files_skipped_the_file_ids_are_kept(): void
    {
        $this->assertSame('4', $this->transform(self::property('F'), '4', skipFiles: true));
        $this->assertSame('["1","2"]', $this->transform(self::property('F', null, true), ['1', '2'], skipFiles: true));
    }

    public function test_a_directory_value_becomes_the_key_of_the_copied_record(): void
    {
        Schema::create('brands', function (Blueprint $t): void {
            $t->id();
            $t->string('xml_id');
        });
        DB::table('brands')->insert([['id' => 7, 'xml_id' => 'northwind'], ['id' => 8, 'xml_id' => '12']]);
        $brand = self::property('S', 'directory', false, ['USER_TYPE_SETTINGS' => serialize(['TABLE_NAME' => 'b_hlbd_brands'])]);

        $this->assertSame(7, (int) $this->transform($brand, 'northwind'));
        $this->assertSame(8, (int) $this->transform($brand, 12), 'a number is looked up as text');
        $this->assertNull($this->transform($brand, 'contoso'), 'not copied');
        $this->assertNull($this->transform($brand, ['northwind']), 'not a single value');
    }

    public function test_a_directory_value_without_its_table_is_dropped(): void
    {
        $this->assertNull($this->transform(self::property('S', 'directory'), 'northwind'), 'no table in the settings');

        $missing = self::property('S', 'directory', false, ['USER_TYPE_SETTINGS' => serialize(['TABLE_NAME' => 'b_hlbd_brands'])]);
        $this->assertFalse(Schema::hasTable('brands'));
        $this->assertNull($this->transform($missing, 'northwind'), 'the table was not created');
    }

    public function test_a_multiple_directory_is_left_to_its_link_table(): void
    {
        Schema::create('brands', function (Blueprint $t): void {
            $t->id();
            $t->string('xml_id');
        });
        DB::table('brands')->insert(['id' => 7, 'xml_id' => 'northwind']);
        $brands = self::property('S', 'directory', true, ['USER_TYPE_SETTINGS' => serialize(['TABLE_NAME' => 'b_hlbd_brands'])]);

        $this->assertSame('["northwind"]', $this->transform($brands, ['northwind']));
    }

    /**
     * @return iterable<string,array{mixed,mixed}>
     */
    public static function listValues(): iterable
    {
        yield 'a value with an XML_ID' => ['1', 'silver'];
        yield 'the id as a number' => [1, 'silver'];
        yield 'a value without an XML_ID gives its id' => ['2', '2'];
        yield 'an unknown value is kept' => ['9', '9'];
        yield 'an unknown word is kept' => ['red', 'red'];
    }

    #[DataProvider('listValues')]
    public function test_a_list_value_becomes_its_xml_id(mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, $this->transform(self::colour(), $value));
    }

    public function test_a_multiple_list_keeps_the_known_values_in_order(): void
    {
        $this->assertSame('["2","silver"]', $this->transform(self::colour(true), ['2', '9', '1']));
        $this->assertNull($this->transform(self::colour(true), ['9', '10']), 'nothing known');
    }

    /**
     * @return iterable<string,array{mixed,mixed}>
     */
    public static function htmlValues(): iterable
    {
        yield 'a single text' => [serialize(['TEXT' => '<b>Hi</b>', 'TYPE' => 'HTML']), '<b>Hi</b>'];
        yield 'a single blank text' => [serialize(['TEXT' => '  ', 'TYPE' => 'HTML']), null];
        yield 'a single empty text' => [serialize(['TEXT' => '', 'TYPE' => 'HTML']), null];
        yield 'several texts, the blank ones dropped' => [
            [serialize(['TEXT' => 'a', 'TYPE' => 'HTML']), serialize(['TEXT' => ' ', 'TYPE' => 'HTML']), serialize(['TEXT' => 'b', 'TYPE' => 'HTML']), 'junk', serialize(['TYPE' => 'HTML'])],
            '["a","b"]',
        ];
        yield 'a text that is a number' => [[serialize(['TEXT' => 5]), serialize(['TEXT' => ''])], '[5]'];
        yield 'nothing but blanks' => [[serialize(['TEXT' => '']), serialize(['TEXT' => ' '])], null];
        yield 'plain text is kept' => ['<p>raw</p>', '<p>raw</p>'];
    }

    #[DataProvider('htmlValues')]
    public function test_an_html_value_keeps_its_text(mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, $this->transform(self::property('S', 'HTML'), $value));
    }

    public function test_a_property_value_is_read_by_storage_version(): void
    {
        $command = $this->command();
        $property = self::property('S');

        $this->assertSame('x', $this->invoke($command, 'getPropertyValue', $property, ['PROPERTY_10' => 'x', 'VALUE' => 'y'], 2));
        $this->assertSame('y', $this->invoke($command, 'getPropertyValue', $property, ['PROPERTY_10' => 'x', 'VALUE' => 'y'], 1));
        $this->assertNull($this->invoke($command, 'getPropertyValue', $property, ['VALUE' => 'y'], 2));
        $this->assertNull($this->invoke($command, 'getPropertyValue', $property, ['PROPERTY_10' => 'x'], 1));
        $this->assertNull($this->invoke($command, 'getPropertyValue', $property, null, 2), 'no row of values');
        $this->assertNull($this->invoke($command, 'getPropertyValue', $property, null, 1));
    }

    /**
     * @return iterable<string,array{mixed,?string}>
     */
    public static function dates(): iterable
    {
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'the MySQL zero date' => ['0000-00-00 00:00:00', null];
        yield 'a date' => ['2024-03-15', '2024-03-15 00:00:00'];
        yield 'a date and time' => ['2024-03-15 12:30:45', '2024-03-15 12:30:45'];
        yield 'a Russian date' => ['15.03.2024 12:30', '2024-03-15 12:30:00'];
        yield 'not a date' => ['someday', null];
        yield 'a number' => [1710505845, null];
        yield 'an array' => [['2024-03-15'], null];
    }

    #[DataProvider('dates')]
    public function test_dates_are_read_safely(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, $this->invoke($this->command(), 'parseDate', $value));
    }

    public function test_a_file_is_found_by_its_id(): void
    {
        $command = $this->command();

        $this->assertNull($this->invoke($command, 'migrateFile', null));
        $this->assertNull($this->invoke($command, 'migrateFile', ''));
        $this->assertNull($this->invoke($command, 'migrateFile', '0'));
        $this->assertNull($this->invoke($command, 'migrateFile', 99), 'not in b_file');
        $this->assertSame('/upload/iblock/g4h/hardware.png', $this->invoke($command, 'migrateFile', '4'), 'kept for later without an upload path');
    }

    public function test_a_file_already_in_public_upload_is_used_where_it_is(): void
    {
        $this->app->useStoragePath($this->workDir.'/storage');
        SyntheticBitrix::writeUploads($this->workDir.'/public');
        SyntheticBitrix::writeUploads($this->workDir.'/source');
        config(['atrium-bitrix.upload_path' => $this->workDir.'/source/upload']);

        $this->assertSame('/upload/iblock/g4h/hardware.png', $this->invoke($this->command(), 'migrateFile', 4));
        $this->assertDirectoryDoesNotExist($this->workDir.'/storage/app');
    }

    public function test_a_file_from_the_upload_path_is_copied_to_storage(): void
    {
        $this->app->useStoragePath($this->workDir.'/storage');
        SyntheticBitrix::writeUploads($this->workDir.'/source');
        mkdir($this->workDir.'/public/upload', 0777, true);
        config(['atrium-bitrix.upload_path' => $this->workDir.'/source/upload']);
        $command = $this->command();

        $this->assertSame('/storage/uploads/iblock/a1b/laptop.jpg', $this->invoke($command, 'migrateFile', 1));
        $this->assertFileExists($this->workDir.'/storage/app/public/uploads/iblock/a1b/laptop.jpg');

        // The folder is there now; the file is copied again all the same.
        unlink($this->workDir.'/storage/app/public/uploads/iblock/a1b/laptop.jpg');
        $this->assertSame('/storage/uploads/iblock/a1b/laptop.jpg', $this->invoke($command, 'migrateFile', 1));
        $this->assertFileExists($this->workDir.'/storage/app/public/uploads/iblock/a1b/laptop.jpg');
    }

    public function test_a_file_missing_from_the_upload_path_is_kept_for_later(): void
    {
        $this->app->useStoragePath($this->workDir.'/storage');
        config(['atrium-bitrix.upload_path' => $this->workDir.'/source/upload']);

        $this->assertSame('/upload/iblock/a1b/laptop.jpg', $this->invoke($this->command(), 'migrateFile', 1));
        $this->assertDirectoryDoesNotExist($this->workDir.'/storage/app/public/uploads');
    }

    /**
     * @return iterable<string,array{mixed,bool}>
     */
    public static function jsonTexts(): iterable
    {
        yield 'an object' => ['{"a":1}', true];
        yield 'a list' => ['[1,2]', true];
        yield 'a quoted text' => ['"x"', true];
        yield 'a list with spaces around' => ['  [1]  ', true];
        yield 'a broken object' => ['{"a":', false];
        yield 'a word' => ['plain', false];
        yield 'a number as text' => ['5', false];
        yield 'empty' => ['', false];
        yield 'blank' => ['   ', false];
        yield 'a number' => [5, false];
        yield 'true' => [true, false];
    }

    #[DataProvider('jsonTexts')]
    public function test_json_is_recognised(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, $this->invoke($this->command(), 'isValidJson', $value));
    }

    public function test_scalars_for_json_columns_are_encoded(): void
    {
        $command = $this->command();
        $this->set($command, 'jsonColumnsCache', ['things' => ['data', 'tags', 'flag', 'count', 'list', 'empty']]);

        $this->assertSame([
            'data' => '"plain"',
            'tags' => '[1]',
            'flag' => 'true',
            'count' => '5',
            'list' => ['a'],
            'empty' => null,
            'name' => 'plain',
        ], $this->invoke($command, 'prepareDataForInsert', [
            'data' => 'plain', 'tags' => '[1]', 'flag' => true, 'count' => 5, 'list' => ['a'], 'empty' => null, 'name' => 'plain',
        ], 'things'));
    }

    public function test_json_columns_are_read_from_the_table_once(): void
    {
        Schema::create('things', function (Blueprint $t): void {
            $t->id();
            $t->json('data')->nullable();
            $t->jsonb('extra')->nullable();
            $t->string('name')->nullable();
        });
        $command = $this->command();
        // SQLite has no JSON type: Laravel makes such columns text.
        $expected = DB::connection()->getDriverName() === 'sqlite' ? [] : ['data', 'extra'];

        $this->assertSame($expected, array_values($this->invoke($command, 'getJsonColumns', 'things')));

        Schema::table('things', fn (Blueprint $t) => $t->json('more')->nullable());
        $this->assertSame($expected, array_values($this->invoke($command, 'getJsonColumns', 'things')), 'cached');
    }

    public function test_sections_that_vanished_since_the_analysis_are_reported(): void
    {
        $command = $this->command();
        $iblock = $this->invoke($command, 'getIBlockAnalyzer')->analyze(2);
        $this->bitrix()->table('b_iblock_section')->delete();

        $this->invoke($command, 'migrateSections', $iblock);

        $this->assertSame("\nMigrating sections: Products -> product_sections\n  No sections to migrate\n", $this->output->fetch());
    }

    public function test_rows_are_read_in_chunks_that_start_at_the_beginning_and_stop_at_the_end(): void
    {
        $this->generateSchema();
        $command = $this->command();
        $this->set($command, 'chunkSize', 1);
        $connection = new class('bitrix') extends BitrixConnection
        {
            /** @var list<string> */
            public array $reads = [];

            public function getHighloadBlockData(string $tableName, int $limit, int $offset): array
            {
                $this->reads[] = "{$tableName}:{$limit}:{$offset}";

                return parent::getHighloadBlockData($tableName, $limit, $offset);
            }

            public function getIBlockElements(int $iblockId, int $limit, int $offset): array
            {
                $this->reads[] = "iblock{$iblockId}:{$limit}:{$offset}";

                return parent::getIBlockElements($iblockId, $limit, $offset);
            }
        };
        $this->set($command, 'bitrixConnection', $connection);

        $this->invoke($command, 'migrateHlblocks', [1]);
        $this->set($command, 'chunkSize', 3);
        $this->invoke($command, 'migrateElements', $this->invoke($command, 'getIBlockAnalyzer')->analyze(2));

        $this->assertSame(['b_hlbd_brands:1:0', 'b_hlbd_brands:1:1', 'iblock2:3:0'], $connection->reads);
        $this->assertSame(2, DB::table('brands')->count());
        $this->assertSame(3, DB::table('products')->count());
    }

    public function test_only_elements_with_link_tables_are_set_aside_for_their_links(): void
    {
        $this->bitrix()->table('b_iblock_property')->insert([
            'ID' => 24, 'IBLOCK_ID' => 2, 'NAME' => 'Also in', 'CODE' => 'SECTIONS_EXTRA', 'PROPERTY_TYPE' => 'G',
            'SORT' => 900, 'MULTIPLE' => 'Y', 'LINK_IBLOCK_ID' => 2,
        ]);
        $command = $this->command();
        $analyzer = $this->invoke($command, 'getIBlockAnalyzer');
        $products = $analyzer->analyze(2);

        $this->invoke($command, 'deferLinks', $products, 'products', 1);
        $this->invoke($command, 'deferLinks', $products, 'products', 2);

        $this->assertSame([2 => ['iblock' => $products, 'table' => 'products', 'ids' => [1, 2]]], $this->read($command, 'deferredLinks'), 'once per element, however many link properties');

        $this->bitrix()->table('b_iblock_property')->where('ID', 2)->delete();
        $this->invoke($command, 'deferLinks', $analyzer->analyze(1), 'news', 4);

        $this->assertSame([2], array_keys($this->read($command, 'deferredLinks')), 'no link property, nothing to write later');
    }

    public function test_sections_and_elements_get_their_defaults_where_bitrix_has_no_value(): void
    {
        $this->generateSchema();
        $command = $this->command();
        $products = $this->invoke($command, 'getIBlockAnalyzer')->analyze(2);
        $this->set($command, 'bitrixConnection', new class('bitrix') extends BitrixConnection
        {
            public function getIBlockSections(int $iblockId): array
            {
                return [
                    ['ID' => 11, 'IBLOCK_SECTION_ID' => null, 'NAME' => 'Bare', 'CODE' => 'bare', 'DESCRIPTION' => null],
                    ['ID' => 12, 'IBLOCK_SECTION_ID' => 11, 'NAME' => 'Full', 'CODE' => 'full', 'DESCRIPTION' => 'd', 'SORT' => 7, 'ACTIVE' => 'N', 'DEPTH_LEVEL' => 2, 'LEFT_MARGIN' => 3, 'RIGHT_MARGIN' => 4],
                ];
            }
        });

        $this->invoke($command, 'migrateSections', $products);

        $rows = DB::table('product_sections')->orderBy('id')->get(['id', 'bitrix_id', 'parent_id', 'sort', 'active', 'depth', 'left_margin', 'right_margin'])
            ->map(fn (object $row): array => array_map(intval(...), (array) $row))->all();
        $this->assertSame([
            ['id' => 11, 'bitrix_id' => 11, 'parent_id' => 0, 'sort' => 500, 'active' => 1, 'depth' => 0, 'left_margin' => 0, 'right_margin' => 0],
            ['id' => 12, 'bitrix_id' => 12, 'parent_id' => 11, 'sort' => 7, 'active' => 0, 'depth' => 2, 'left_margin' => 3, 'right_margin' => 4],
        ], $rows);

        $bare = ['ID' => 9, 'IBLOCK_SECTION_ID' => null, 'NAME' => 'N', 'CODE' => 'n', 'PREVIEW_TEXT' => null, 'DETAIL_TEXT' => null, 'DATE_CREATE' => null, 'TIMESTAMP_X' => null];
        $data = $this->invoke($command, 'transformElement', $bare, $products);
        $this->assertSame([500, true, 'text', 'text'], [$data['sort'], $data['active'], $data['preview_text_type'], $data['detail_text_type']]);
        $this->assertEqualsWithDelta(time(), strtotime((string) $data['created_at']), 5, 'no date: now');
        $this->assertEqualsWithDelta(time(), strtotime((string) $data['updated_at']), 5);

        $full = [...$bare, 'SORT' => 3, 'ACTIVE' => 'N', 'PREVIEW_TEXT_TYPE' => 'html', 'DETAIL_TEXT_TYPE' => 'html', 'DATE_CREATE' => '2020-01-02 03:04:05', 'TIMESTAMP_X' => '2021-02-03 04:05:06'];
        $data = $this->invoke($command, 'transformElement', $full, $products);
        $this->assertSame([3, false, 'html', 'html', '2020-01-02 03:04:05', '2021-02-03 04:05:06'], [$data['sort'], $data['active'], $data['preview_text_type'], $data['detail_text_type'], $data['created_at'], $data['updated_at']]);
    }

    public function test_a_highload_row_keeps_its_id_and_reads_flags_of_every_kind(): void
    {
        $this->bitrix()->table('b_user_field')->insert(['ID' => 6, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_ACTIVE', 'USER_TYPE_ID' => 'boolean', 'SORT' => 400, 'MULTIPLE' => 'N']);
        $command = $this->command();
        $brands = $this->invoke($command, 'getHlblockAnalyzer')->analyze(1);

        $this->assertSame(['id' => 7], $this->invoke($command, 'transformHlblockRow', ['ID' => 7], $brands));
        $this->assertSame(['id' => 7, 'name' => 'X', 'active' => true], $this->invoke($command, 'transformHlblockRow', ['ID' => 7, 'UF_NAME' => 'X', 'UF_ACTIVE' => true], $brands));

        foreach ([['Y', true], ['1', true], [1, true], ['N', false], [0, false], [false, false]] as [$stored, $expected]) {
            $this->assertSame($expected, $this->invoke($command, 'transformHlblockRow', ['ID' => 7, 'UF_ACTIVE' => $stored], $brands)['active'], var_export($stored, true));
        }
    }

    public function test_the_values_of_a_version_one_element_are_found_by_property_and_the_unknown_are_left_out(): void
    {
        $this->bitrix()->table('b_iblock_element_property')->insert([
            ['ID' => 10, 'IBLOCK_PROPERTY_ID' => 2, 'IBLOCK_ELEMENT_ID' => 4, 'VALUE' => '2'],
            ['ID' => 11, 'IBLOCK_PROPERTY_ID' => 99, 'IBLOCK_ELEMENT_ID' => 4, 'VALUE' => 'orphan'],
            ['ID' => 12, 'IBLOCK_PROPERTY_ID' => 2, 'IBLOCK_ELEMENT_ID' => 4, 'VALUE' => '1'],
        ]);
        $command = $this->command();
        $news = $this->invoke($command, 'getIBlockAnalyzer')->analyze(1);

        $this->assertSame(['SOURCE' => 'Press office', 'LINK_GOODS' => ['2', '1']], $this->invoke($command, 'getPropertyValuesV1', 4, $news));
        $this->assertSame([], $this->invoke($command, 'getPropertyValuesV1', 77, $news));
    }

    public function test_the_given_ids_pick_the_blocks_without_asking(): void
    {
        $command = $this->command();

        $this->assertSame([2], array_values($this->invoke($command, 'selectIBlocks', [2, 99])));
        $this->assertSame([], array_values($this->invoke($command, 'selectIBlocks', [99])));
        $this->assertSame([1], array_values($this->invoke($command, 'selectHlblocks', [1])));
        $this->assertSame([], array_values($this->invoke($command, 'selectHlblocks', [5])));
        $this->assertStringNotContainsString('Available', $this->output->fetch());
    }

    public function test_an_iblock_can_be_skipped_by_its_code(): void
    {
        config(['atrium-bitrix.skip_iblocks' => ['demo_news', 7]]);
        $command = $this->command();

        $this->assertTrue($this->invoke($command, 'shouldSkipIBlock', 1, 'demo_news'));
        $this->assertTrue($this->invoke($command, 'shouldSkipIBlock', 7, 'other'));
        $this->assertFalse($this->invoke($command, 'shouldSkipIBlock', 2, 'demo_products'));
        $this->assertFalse($this->invoke($command, 'shouldSkipIBlock', 1, null));
        $this->assertFalse($this->invoke($command, 'shouldSkipIBlock', 1, ''));
    }
}
