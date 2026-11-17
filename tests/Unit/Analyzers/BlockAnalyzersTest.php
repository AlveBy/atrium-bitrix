<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Analyzers;

use Atrium\Bitrix\Analyzers\HighloadBlockAnalyzer;
use Atrium\Bitrix\Analyzers\IBlockAnalyzer;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Tests\SyntheticTestCase;

/**
 * The iblock and highload block analyzers read the made-up Bitrix database.
 */
final class BlockAnalyzersTest extends SyntheticTestCase
{
    private function iblocks(): IBlockAnalyzer
    {
        return new IBlockAnalyzer(new BitrixConnection);
    }

    private function hlblocks(): HighloadBlockAnalyzer
    {
        return new HighloadBlockAnalyzer(new BitrixConnection);
    }

    public function test_an_iblock_is_read_with_its_properties_sections_and_links(): void
    {
        $iblock = $this->iblocks()->analyze(2);

        $this->assertNotNull($iblock);
        $this->assertSame('demo_products', $iblock->code);
        $this->assertSame(2, $iblock->version);
        $this->assertSame(
            ['ARTICLE', 'PRICE', 'COLOR', 'ORDERABLE', 'FEATURES', 'PHOTOS', 'MANUAL', 'RELATED', 'CATEGORY', 'SPECS', 'RELEASED', 'CHECKED_AT', 'STORE', 'BRAND'],
            array_map(static fn (IBlockProperty $p): string => $p->code, $iblock->properties),
        );

        $color = $iblock->getPropertyByCode('COLOR');
        $this->assertNotNull($color);
        $this->assertSame(['silver', 'black'], array_column($color->enumValues, 'xmlId'));
        $this->assertSame([], $iblock->getPropertyByCode('ARTICLE')?->enumValues);

        // Both kinds of links know the code of the iblock they point at.
        $this->assertSame('demo_products', $iblock->getPropertyByCode('RELATED')?->linkIblockCode);
        $this->assertSame('demo_products', $iblock->getPropertyByCode('CATEGORY')?->linkIblockCode);

        // The directory property found its highload block.
        $brand = $iblock->getPropertyByCode('BRAND');
        $this->assertSame('Brands', $brand?->directoryHlblock?->name);

        // Sections in tree order, the child under its parent.
        $this->assertSame(['Hardware', 'Laptops', 'Software'], array_map(static fn ($s): string => $s->name, $iblock->sections));
        $this->assertSame(['Laptops'], array_map(static fn ($s): string => $s->name, $iblock->sections[0]->children));
        $this->assertSame([], $iblock->sections[2]->children);
    }

    public function test_an_unknown_iblock_is_null_and_a_known_one_is_read_once(): void
    {
        $analyzer = $this->iblocks();

        $this->assertNull($analyzer->analyze(99));
        $this->assertSame([], $analyzer->getAnalyzedIBlocks());

        $first = $analyzer->analyze(1);
        $this->bitrix()->table('b_iblock')->where('ID', 1)->update(['NAME' => 'Changed']);
        $this->assertSame($first, $analyzer->analyze(1));
        $this->assertSame('News', $analyzer->analyze(1)?->name);
        $this->assertSame([1 => $first], $analyzer->getAnalyzedIBlocks());
    }

    public function test_an_iblock_row_at_hand_is_analyzed_once(): void
    {
        $analyzer = $this->iblocks();
        $row = (new BitrixConnection)->getIBlock(1);
        $this->assertNotNull($row);

        $iblock = $analyzer->analyzeRow($row);

        $this->assertSame(1, $iblock->id);
        $this->assertSame('News', $iblock->name);
        $this->assertSame([1 => $iblock], $analyzer->getAnalyzedIBlocks());
        $this->assertSame($iblock, $analyzer->analyzeRow([...$row, 'NAME' => 'Changed']));
        $this->assertSame($iblock, $analyzer->analyze(1));
    }

    public function test_all_iblocks_or_those_of_one_type_are_analyzed(): void
    {
        $this->assertSame([1, 2], array_map(static fn ($i): int => $i->id, $this->iblocks()->analyzeAll()));
        $this->assertSame([2], array_map(static fn ($i): int => $i->id, $this->iblocks()->analyzeAll('catalog')));
        $this->assertSame([], $this->iblocks()->analyzeAll('nothing'));
    }

    public function test_a_link_to_any_iblock_finds_its_target_in_the_stored_values(): void
    {
        // News stores product IDs in a link property that names no iblock.
        $link = $this->iblocks()->analyze(1)?->getPropertyByCode('LINK_GOODS');

        $this->assertSame(2, $link?->linkIblockId);
        $this->assertSame('demo_products', $link?->linkIblockCode);
    }

    public function test_a_link_to_any_iblock_follows_the_project_config_first(): void
    {
        config(['atrium-bitrix.element_link_targets' => ['demo_news' => ['LINK_GOODS' => 'demo_news']]]);

        $link = $this->iblocks()->analyze(1)?->getPropertyByCode('LINK_GOODS');

        $this->assertSame(1, $link?->linkIblockId);
        $this->assertSame('demo_news', $link?->linkIblockCode);
    }

    public function test_a_configured_target_that_does_not_exist_falls_back_to_the_values(): void
    {
        config(['atrium-bitrix.element_link_targets' => ['demo_news' => ['LINK_GOODS' => 'no_such_iblock']]]);

        $link = $this->iblocks()->analyze(1)?->getPropertyByCode('LINK_GOODS');

        $this->assertSame(2, $link?->linkIblockId);
        $this->assertSame('demo_products', $link?->linkIblockCode);
    }

    public function test_values_pointing_at_two_iblocks_leave_the_link_open(): void
    {
        // Element 4 is a news item: now the values point at news and products.
        $this->bitrix()->table('b_iblock_element_property')->where('ID', 3)->update(['VALUE' => '4']);

        $link = $this->iblocks()->analyze(1)?->getPropertyByCode('LINK_GOODS');

        $this->assertNull($link?->linkIblockId);
        $this->assertNull($link?->linkIblockCode);
    }

    public function test_an_empty_link_to_any_iblock_stays_open(): void
    {
        $this->bitrix()->table('b_iblock_element_property')->where('IBLOCK_PROPERTY_ID', 2)->delete();

        $link = $this->iblocks()->analyze(1)?->getPropertyByCode('LINK_GOODS');

        $this->assertNull($link?->linkIblockId);
    }

    public function test_values_pointing_at_a_vanished_iblock_leave_the_link_open(): void
    {
        // The products still exist as elements, their iblock row does not.
        $this->bitrix()->table('b_iblock')->where('ID', 2)->delete();

        $link = $this->iblocks()->analyze(1)?->getPropertyByCode('LINK_GOODS');

        $this->assertNull($link?->linkIblockId);
        $this->assertNull($link?->linkIblockCode);
    }

    public function test_a_target_iblock_without_code_is_named_by_its_id(): void
    {
        $this->bitrix()->table('b_iblock')->where('ID', 2)->update(['CODE' => '']);

        $news = $this->iblocks()->analyze(1);
        $this->assertSame('IBLOCK_2', $news?->getPropertyByCode('LINK_GOODS')?->linkIblockCode);

        $products = $this->iblocks()->analyze(2);
        $this->assertSame('IBLOCK_2', $products?->getPropertyByCode('RELATED')?->linkIblockCode);
        $this->assertSame('IBLOCK_2', $products?->getPropertyByCode('CATEGORY')?->linkIblockCode);
    }

    public function test_a_link_to_a_missing_iblock_has_no_code(): void
    {
        $this->bitrix()->table('b_iblock_property')->where('ID', 17)->update(['LINK_IBLOCK_ID' => 77]);

        $related = $this->iblocks()->analyze(2)?->getPropertyByCode('RELATED');

        $this->assertSame(77, $related?->linkIblockId);
        $this->assertNull($related?->linkIblockCode);
    }

    public function test_a_directory_found_by_a_similar_table_name(): void
    {
        $this->bitrix()->table('b_iblock_property')->where('ID', 23)->update([
            'USER_TYPE_SETTINGS' => serialize(['TABLE_NAME' => 'other_hlbd_brands']),
        ]);

        $hlblocks = $this->hlblocks();
        $hlblocks->analyzeAll();
        $analyzer = new IBlockAnalyzer(new BitrixConnection, $hlblocks);

        $brand = $analyzer->analyze(2)?->getPropertyByCode('BRAND');

        $this->assertSame('b_hlbd_brands', $brand?->directoryHlblock?->tableName);
        $this->assertSame(['other_hlbd_brands'], array_keys($analyzer->getLinkedDirectories()));
    }

    public function test_a_directory_with_an_unknown_table_has_no_block(): void
    {
        $this->bitrix()->table('b_iblock_property')->where('ID', 23)->update([
            'USER_TYPE_SETTINGS' => serialize(['TABLE_NAME' => 'nothing']),
        ]);

        $analyzer = $this->iblocks();
        $brand = $analyzer->analyze(2)?->getPropertyByCode('BRAND');

        $this->assertNull($brand?->directoryHlblock);
        $this->assertSame([], $analyzer->getLinkedDirectories());
    }

    public function test_the_summary_counts_what_each_iblock_holds(): void
    {
        $analyzer = $this->iblocks();
        $analyzer->analyzeAll();

        $summary = $analyzer->getSummary();

        $this->assertSame(2, $summary['iblocks_count']);
        $this->assertSame(1, $summary['directories_count']);
        $this->assertSame([
            'id' => 1, 'code' => 'demo_news', 'name' => 'News', 'type' => 'content', 'version' => 1,
            'properties_count' => 2, 'sections_count' => 0, 'elements_count' => 2, 'has_files' => false,
            'has_multiple' => true, 'property_types' => ['S' => 1, 'E' => 1],
        ], $summary['iblocks'][0]);
        $this->assertSame(14, $summary['iblocks'][1]['properties_count']);
        $this->assertSame(3, $summary['iblocks'][1]['sections_count']);
        $this->assertSame(3, $summary['iblocks'][1]['elements_count']);
        $this->assertTrue($summary['iblocks'][1]['has_files']);
        $this->assertSame([
            'S' => 2, 'N' => 1, 'L' => 2, 'F' => 2, 'E' => 1, 'G' => 1, 'S:HTML' => 1, 'S:Date' => 1,
            'S:DateTime' => 1, 'S:map_yandex' => 1, 'S:directory' => 1,
        ], $summary['iblocks'][1]['property_types']);
    }

    public function test_an_empty_summary(): void
    {
        $this->assertSame(['iblocks_count' => 0, 'directories_count' => 0, 'iblocks' => []], $this->iblocks()->getSummary());
    }

    public function test_a_highload_block_is_read_with_its_fields_and_enum_values(): void
    {
        $this->bitrix()->table('b_user_field')->where('ID', 5)->update(['USER_TYPE_ID' => 'enumeration']);
        $this->bitrix()->table('b_user_field_enum')->insert([
            ['ID' => 3, 'USER_FIELD_ID' => 5, 'VALUE' => 'Last', 'XML_ID' => 'last', 'SORT' => 20],
            ['ID' => 4, 'USER_FIELD_ID' => 5, 'VALUE' => 'First', 'XML_ID' => 'first', 'SORT' => 10],
        ]);

        $block = $this->hlblocks()->analyze(1);

        $this->assertNotNull($block);
        $this->assertSame('b_hlbd_brands', $block->tableName);
        $this->assertSame(['UF_NAME', 'UF_XML_ID', 'UF_SORT'], array_map(static fn ($f): string => $f->fieldName, $block->fields));
        $this->assertSame([], $block->fields[0]->enumValues);
        $this->assertSame(['first', 'last'], array_column($block->fields[2]->enumValues, 'xmlId'));
    }

    public function test_an_unknown_highload_block_is_null_and_a_known_one_is_read_once(): void
    {
        $analyzer = $this->hlblocks();

        $this->assertNull($analyzer->analyze(5));
        $this->assertNull($analyzer->analyzeByTableName('b_hlbd_none'));
        $this->assertSame([], $analyzer->getAnalyzedHlblocks());

        $block = $analyzer->analyze(1);
        $this->bitrix()->table('b_hlblock_entity')->delete();
        $this->assertSame($block, $analyzer->analyze(1));
        $this->assertSame($block, $analyzer->analyzeByTableName('b_hlbd_brands'));
        $this->assertSame([1 => $block], $analyzer->getAnalyzedHlblocks());
    }

    public function test_a_highload_block_is_found_by_its_table(): void
    {
        $analyzer = $this->hlblocks();

        $this->assertSame(1, $analyzer->analyzeByTableName('b_hlbd_brands')?->id);
        $this->assertNull($analyzer->analyzeByTableName('b_hlbd_colors'));
    }

    public function test_a_highload_block_row_at_hand_is_analyzed_once(): void
    {
        $analyzer = $this->hlblocks();
        $row = (new BitrixConnection)->getHighloadBlock(1);
        $this->assertNotNull($row);

        $block = $analyzer->analyzeRow($row);

        $this->assertSame('b_hlbd_brands', $block->tableName);
        $this->assertSame([1 => $block], $analyzer->getAnalyzedHlblocks());
        $this->assertSame($block, $analyzer->analyzeRow([...$row, 'TABLE_NAME' => 'b_hlbd_changed']));
        $this->assertSame($block, $analyzer->analyze(1));
    }

    public function test_all_highload_blocks_and_their_summary(): void
    {
        $this->bitrix()->table('b_hlblock_entity')->insert(['ID' => 2, 'NAME' => 'Colors', 'TABLE_NAME' => 'b_hlbd_colors']);
        $this->bitrix()->table('b_user_field')->insert([
            ['ID' => 6, 'ENTITY_ID' => 'HLBLOCK_2', 'FIELD_NAME' => 'UF_FILE', 'USER_TYPE_ID' => 'file', 'SORT' => 100, 'SETTINGS' => null],
            ['ID' => 7, 'ENTITY_ID' => 'HLBLOCK_2', 'FIELD_NAME' => 'UF_BRAND', 'USER_TYPE_ID' => 'hlblock', 'SORT' => 200, 'SETTINGS' => serialize(['HLBLOCK_ID' => 1])],
            ['ID' => 8, 'ENTITY_ID' => 'HLBLOCK_2', 'FIELD_NAME' => 'UF_OTHER', 'USER_TYPE_ID' => 'hlblock', 'SORT' => 300, 'SETTINGS' => serialize(['HLBLOCK_ID' => 1])],
            ['ID' => 9, 'ENTITY_ID' => 'HLBLOCK_2', 'FIELD_NAME' => 'UF_LOOSE', 'USER_TYPE_ID' => 'hlblock', 'SORT' => 400, 'SETTINGS' => null],
        ]);

        $analyzer = $this->hlblocks();
        $all = $analyzer->analyzeAll();

        $this->assertSame([1, 2], array_map(static fn ($b): int => $b->id, $all));

        $summary = $analyzer->getSummary();
        $this->assertSame(2, $summary['hlblocks_count']);
        $this->assertSame([
            'id' => 1, 'name' => 'Brands', 'table_name' => 'b_hlbd_brands', 'fields_count' => 3, 'rows_count' => 2,
            'has_files' => false, 'field_types' => ['string' => 2, 'integer' => 1],
        ], $summary['hlblocks'][0]);
        // The colours table itself does not exist: no rows, no crash.
        $this->assertSame(0, $summary['hlblocks'][1]['rows_count']);
        $this->assertTrue($summary['hlblocks'][1]['has_files']);
        $this->assertSame(['file' => 1, 'hlblock' => 3], $summary['hlblocks'][1]['field_types']);
    }

    public function test_an_empty_highload_summary(): void
    {
        $this->assertSame(['hlblocks_count' => 0, 'hlblocks' => []], $this->hlblocks()->getSummary());
    }

    public function test_a_similar_table_name_matches_by_everything_after_the_first_word(): void
    {
        $analyzer = $this->hlblocks();
        $analyzer->analyzeAll();

        $this->assertSame('b_hlbd_brands', $analyzer->findBySimilarTableName('x_hlbd_brands')?->tableName);
        $this->assertSame('b_hlbd_brands', $analyzer->findBySimilarTableName('theme_brands')?->tableName);
        $this->assertNull($analyzer->findBySimilarTableName('theme_colors'));
        $this->assertNull($analyzer->findBySimilarTableName('brands'), 'one word has no suffix to match');
        $this->assertNull($this->hlblocks()->findBySimilarTableName('x_hlbd_brands'), 'only analyzed blocks are searched');
    }
}
