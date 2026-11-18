<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Support\Facades\Artisan;

/**
 * analyze: the overview of the Bitrix structure, its filters and its JSON.
 */
final class AnalyzeBitrixCommandTest extends SyntheticTestCase
{
    private const IBLOCK_HEADERS = ['ID', 'Code', 'Name', 'Type', 'Ver', 'Props', 'Sections', 'Elements', 'Files', 'Relations'];

    private const NEWS_ROW = [1, 'demo_news', 'News', 'content', 1, 2, 0, 2, 'No', 1];

    private const PRODUCTS_ROW = [2, 'demo_products', 'Products', 'catalog', 2, 14, 3, 3, 'Yes', 3];

    private const HL_HEADERS = ['ID', 'Name', 'Table', 'Fields', 'Rows', 'Files'];

    public function test_the_overview_lists_every_block_without_details(): void
    {
        $this->artisan('atrium:bitrix:analyze')
            ->expectsOutput('Analyzing IBlocks...')
            ->expectsTable(self::IBLOCK_HEADERS, [self::NEWS_ROW, self::PRODUCTS_ROW])
            ->expectsOutput('Analyzing Highload Blocks...')
            ->expectsTable(self::HL_HEADERS, [[1, 'Brands', 'b_hlbd_brands', 3, 2, 'No']])
            ->doesntExpectOutputToContain('Properties for IBlock')
            ->doesntExpectOutputToContain('Fields for Highload Block')
            ->doesntExpectOutputToContain('"iblocks"')
            ->assertSuccessful();
    }

    public function test_verbose_output_adds_properties_and_fields(): void
    {
        $this->bitrix()->table('b_iblock_property')->where('ID', 1)->update(['IS_REQUIRED' => 'Y', 'NAME' => 'Source of the news, if any, given']);
        $this->bitrix()->table('b_user_field')->where('ID', 3)->update(['MULTIPLE' => 'Y', 'MANDATORY' => 'Y']);
        $this->bitrix()->table('b_user_field')->insert([
            'ID' => 9, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_PARENT', 'USER_TYPE_ID' => 'hlblock', 'SORT' => 400,
            'SETTINGS' => serialize(['HLBLOCK_ID' => 1]),
        ]);

        $this->artisan('atrium:bitrix:analyze', ['--iblock' => ['1'], '-v' => true])
            ->expectsTable(self::IBLOCK_HEADERS, [self::NEWS_ROW])
            ->expectsOutput('Properties for IBlock: News (demo_news)')
            ->expectsTable(['ID', 'Code', 'Name', 'Type', 'Multiple', 'Required', 'Relation'], [
                [1, 'SOURCE', 'Source of the news, if an', 'S', 'No', 'Yes', '-'],
                [2, 'LINK_GOODS', 'Products mentioned', 'E', 'Yes', 'No', 'E:2'],
            ])
            ->doesntExpectOutput('Properties for IBlock: Products (demo_products)')
            ->expectsTable(self::HL_HEADERS, [[1, 'Brands', 'b_hlbd_brands', 4, 2, 'No']])
            ->expectsOutput('Fields for Highload Block: Brands')
            ->expectsTable(['ID', 'Field Name', 'Type', 'Multiple', 'Required', 'Linked HL'], [
                [3, 'UF_NAME', 'string', 'Yes', 'Yes', '-'],
                [4, 'UF_XML_ID', 'string', 'No', 'No', '-'],
                [5, 'UF_SORT', 'integer', 'No', 'No', '-'],
                [9, 'UF_PARENT', 'hlblock', 'No', 'No', 1],
            ])
            ->assertSuccessful();
    }

    public function test_relations_of_every_kind_are_shown_and_counted(): void
    {
        $this->artisan('atrium:bitrix:analyze', ['--type' => 'catalog', '-v' => true])
            ->expectsTable(self::IBLOCK_HEADERS, [self::PRODUCTS_ROW])
            ->expectsOutputToContain('| 17 | RELATED    | Related products | E            | Yes      | No       | E:2               |')
            ->assertSuccessful();

        $output = $this->analyze(['--type' => 'catalog', '-v' => true]);
        $this->assertStringContainsString('| 18 | CATEGORY   | Extra section    | G            | No       | No       | G                 |', $output);
        $this->assertStringContainsString('| 23 | BRAND      | Brand            | S:directory  | No       | No       | Dir:b_hlbd_brands |', $output);
        $this->assertStringContainsString('| 15 | PHOTOS     | Photos           | F            | Yes      | No       | -                 |', $output);
    }

    public function test_an_iblock_without_a_code_gets_one_and_a_long_name_is_cut(): void
    {
        $this->bitrix()->table('b_iblock')->where('ID', 1)->update(['CODE' => null, 'NAME' => 'Новости компании и пресс-релизы за год']);

        $this->artisan('atrium:bitrix:analyze', ['--iblock' => ['1']])
            ->expectsTable(self::IBLOCK_HEADERS, [[1, 'IBLOCK_1', 'Новости компании и пресс-релиз', 'content', 1, 2, 0, 2, 'No', 1]])
            ->assertSuccessful();
    }

    public function test_filters_that_match_nothing_say_so(): void
    {
        $this->artisan('atrium:bitrix:analyze', ['--type' => 'forum', '--hlblock' => ['9']])
            ->expectsOutput('No IBlocks found.')
            ->expectsOutput('No Highload Blocks found.')
            ->doesntExpectOutputToContain('| ID |')
            ->assertSuccessful();

        $this->artisan('atrium:bitrix:analyze', ['--iblock' => ['99'], '--hlblock' => ['1']])
            ->expectsOutput('No IBlocks found.')
            ->expectsTable(self::HL_HEADERS, [[1, 'Brands', 'b_hlbd_brands', 3, 2, 'No']])
            ->assertSuccessful();
    }

    public function test_an_empty_bitrix_has_nothing_to_show(): void
    {
        $this->bitrix()->table('b_iblock')->delete();
        $this->bitrix()->table('b_hlblock_entity')->delete();

        $this->artisan('atrium:bitrix:analyze')
            ->expectsOutput('No IBlocks found.')
            ->expectsOutput('No Highload Blocks found.')
            ->assertSuccessful();
    }

    public function test_a_highload_block_with_files_says_so(): void
    {
        $this->bitrix()->table('b_user_field')->insert([
            'ID' => 9, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_LOGO', 'USER_TYPE_ID' => 'file', 'SORT' => 400,
        ]);

        $this->artisan('atrium:bitrix:analyze', ['--iblock' => ['99']])
            ->expectsTable(self::HL_HEADERS, [[1, 'Brands', 'b_hlbd_brands', 4, 2, 'Yes']])
            ->assertSuccessful();
    }

    public function test_json_output_summarises_what_was_analysed(): void
    {
        $output = $this->analyze(['--iblock' => ['1'], '--hlblock' => ['1'], '--json' => true]);
        $json = json_decode(substr($output, (int) strpos($output, "\n{\n")), true);

        $this->assertSame(1, $json['iblocks']['iblocks_count']);
        $this->assertSame('demo_news', $json['iblocks']['iblocks'][0]['code']);
        $this->assertSame(['S' => 1, 'E' => 1], $json['iblocks']['iblocks'][0]['property_types']);
        $this->assertSame(1, $json['hlblocks']['hlblocks_count']);
        $this->assertSame('b_hlbd_brands', $json['hlblocks']['hlblocks'][0]['table_name']);
        $this->assertSame(2, $json['hlblocks']['hlblocks'][0]['rows_count']);
    }

    public function test_json_keeps_unicode_readable(): void
    {
        $this->bitrix()->table('b_iblock')->where('ID', 1)->update(['NAME' => 'Новости']);

        $output = $this->analyze(['--iblock' => ['1'], '--json' => true]);

        $this->assertStringContainsString('"name": "Новости"', $output);
        $this->assertStringContainsString("{\n    \"iblocks\": {", $output, 'pretty-printed');
    }

    public function test_the_whole_report_is_printed_in_order_with_blank_lines_between_its_parts(): void
    {
        $this->bitrix()->table('b_iblock_property')->where('ID', 1)->update(['NAME' => 'Источник новости, если он указан']);

        $output = $this->analyze(['--iblock' => ['1'], '--hlblock' => ['1'], '-v' => true]);

        $this->assertSame(<<<'TXT'
Bitrix Database Analyzer
========================

Analyzing IBlocks...
+----+-----------+------+---------+-----+-------+----------+----------+-------+-----------+
| ID | Code      | Name | Type    | Ver | Props | Sections | Elements | Files | Relations |
+----+-----------+------+---------+-----+-------+----------+----------+-------+-----------+
| 1  | demo_news | News | content | 1   | 2     | 0        | 2        | No    | 1         |
+----+-----------+------+---------+-----+-------+----------+----------+-------+-----------+


Properties for IBlock: News (demo_news)
+----+------------+---------------------------+------+----------+----------+----------+
| ID | Code       | Name                      | Type | Multiple | Required | Relation |
+----+------------+---------------------------+------+----------+----------+----------+
| 1  | SOURCE     | Источник новости, если он | S    | No       | No       | -        |
| 2  | LINK_GOODS | Products mentioned        | E    | Yes      | No       | E:2      |
+----+------------+---------------------------+------+----------+----------+----------+
Analyzing Highload Blocks...
+----+--------+---------------+--------+------+-------+
| ID | Name   | Table         | Fields | Rows | Files |
+----+--------+---------------+--------+------+-------+
| 1  | Brands | b_hlbd_brands | 3      | 2    | No    |
+----+--------+---------------+--------+------+-------+


Fields for Highload Block: Brands
+----+------------+---------+----------+----------+-----------+
| ID | Field Name | Type    | Multiple | Required | Linked HL |
+----+------------+---------+----------+----------+-----------+
| 3  | UF_NAME    | string  | No       | No       | -         |
| 4  | UF_XML_ID  | string  | No       | No       | -         |
| 5  | UF_SORT    | integer | No       | No       | -         |
+----+------------+---------+----------+----------+-----------+

TXT, $output);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function analyze(array $options): string
    {
        Artisan::call('atrium:bitrix:analyze', $options);

        return Artisan::output();
    }
}
