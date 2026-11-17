<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Support;

use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Database\Schema\Blueprint;

/**
 * The read-only queries against the made-up Bitrix database.
 */
final class BitrixConnectionTest extends SyntheticTestCase
{
    private function connection(): BitrixConnection
    {
        return new BitrixConnection;
    }

    public function test_the_connection_comes_from_the_config_or_is_named(): void
    {
        $this->assertSame('bitrix', $this->connection()->getConnectionName());
        $this->assertSame('bitrix', $this->connection()->getConnection()->getName());

        $named = new BitrixConnection('testing');
        $this->assertSame('testing', $named->getConnectionName());
        $this->assertSame('testing', $named->getConnection()->getName());
    }

    public function test_iblock_types_iblocks_and_their_lookups(): void
    {
        $db = $this->connection();

        $this->assertSame(['catalog', 'content'], array_map(static fn ($t): string => $t->ID, $db->getIBlockTypes()));
        $this->assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['ID'], $db->getIBlocks()));
        $this->assertSame([1], array_map(static fn (array $r): int => (int) $r['ID'], $db->getIBlocks('content')));
        $this->assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['ID'], $db->getIBlocks('')), 'an empty type is no filter');
        $this->assertSame('Products', $db->getIBlock(2)['NAME'] ?? null);
        $this->assertNull($db->getIBlock(3));
        $this->assertSame(2, (int) ($db->getIBlockByCode('demo_products')['ID'] ?? 0));
        $this->assertNull($db->getIBlockByCode('missing'));
    }

    public function test_enum_values_of_an_iblock_come_in_sort_order(): void
    {
        $this->bitrix()->table('b_iblock_property_enum')->where('ID', 3)->update(['SORT' => 5]);

        $values = $this->connection()->getIBlockEnumValues(2);

        $this->assertSame(['YES', 'Silver', 'Black'], array_column($values, 'VALUE'));
        $this->assertSame([], $this->connection()->getIBlockEnumValues(1));
    }

    public function test_elements_are_paged_in_id_order(): void
    {
        $db = $this->connection();

        $this->assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['ID'], $db->getIBlockElements(2, 2)));
        $this->assertSame([3], array_map(static fn (array $r): int => (int) $r['ID'], $db->getIBlockElements(2, 2, 2)));
        $this->assertSame([], $db->getIBlockElements(2, 2, 3));
        $this->assertSame(3, $db->getIBlockElementCount(2));
        $this->assertSame(0, $db->getIBlockElementCount(9));
        $this->assertSame([1, 2, 3], array_map('intval', $db->getIBlockElementIds(2)));
        $this->assertSame('Laptop Air', $db->getIBlockElementById(2)['NAME'] ?? null);
        $this->assertNull($db->getIBlockElementById(99));
    }

    public function test_property_values_of_both_storage_versions(): void
    {
        $db = $this->connection();

        $this->assertSame(['1', '2'], array_column($db->getElementPropertyValues(1, 5), 'VALUE'));
        $this->assertSame('LP-001', $db->getElementPropertyValuesV2(2, 1)['PROPERTY_10'] ?? null);
        $this->assertNull($db->getElementPropertyValuesV2(2, 99), 'no row');
        $this->assertNull($db->getElementPropertyValuesV2(1, 4), 'no table');
        $this->assertSame(
            ['Backlit keyboard', 'Fingerprint reader', '1', '2', '2'],
            array_column($db->getElementMultiplePropertyValues(2, 1), 'VALUE'),
        );
        $this->assertSame([], $db->getElementMultiplePropertyValues(1, 5), 'no table');
    }

    public function test_link_targets_are_counted_from_every_kind_of_storage(): void
    {
        $db = $this->connection();

        // Multiple, version 2: RELATED points at products twice.
        $this->assertSame([2 => 2], $db->getElementLinkTargetIblocks(2, 17, true));
        // Version 1: the news link stores two product IDs.
        $this->assertSame([2 => 2], $db->getElementLinkTargetIblocks(1, 2, true));
        // Single, version 2: a column of prop_s2.
        $this->bitrix()->table('b_iblock_element_prop_s2')->where('IBLOCK_ELEMENT_ID', 2)->update(['PROPERTY_18' => '4']);
        $this->assertSame([2 => 1, 1 => 1], $db->getElementLinkTargetIblocks(2, 18, false));
    }

    public function test_link_targets_are_ordered_by_how_many_linked_elements_they_hold(): void
    {
        $this->bitrix()->table('b_iblock_element')->insert(['ID' => 6, 'IBLOCK_ID' => 1, 'NAME' => 'Third news']);
        // A repeated value names the same element: it counts once.
        $this->bitrix()->table('b_iblock_element_property')->insert([
            ['ID' => 14, 'IBLOCK_PROPERTY_ID' => 2, 'IBLOCK_ELEMENT_ID' => 4, 'VALUE' => '6'],
            ['ID' => 10, 'IBLOCK_PROPERTY_ID' => 2, 'IBLOCK_ELEMENT_ID' => 4, 'VALUE' => '4'],
            ['ID' => 11, 'IBLOCK_PROPERTY_ID' => 2, 'IBLOCK_ELEMENT_ID' => 4, 'VALUE' => '5'],
            ['ID' => 12, 'IBLOCK_PROPERTY_ID' => 2, 'IBLOCK_ELEMENT_ID' => 4, 'VALUE' => '5'],
            ['ID' => 13, 'IBLOCK_PROPERTY_ID' => 2, 'IBLOCK_ELEMENT_ID' => 4, 'VALUE' => ''],
        ]);

        $this->assertSame([1 => 3, 2 => 2], $this->connection()->getElementLinkTargetIblocks(1, 2, true));
    }

    public function test_link_targets_ignore_empty_values_and_missing_storage(): void
    {
        $db = $this->connection();

        // A single property that has no column in prop_s2.
        $this->assertSame([], $db->getElementLinkTargetIblocks(2, 14, false));

        // Empty strings and NULLs are no link.
        $this->bitrix()->table('b_iblock_element_prop_s2')->update(['PROPERTY_18' => '']);
        $this->assertSame([], $db->getElementLinkTargetIblocks(2, 18, false));

        // An iblock with neither table has nothing to count.
        $this->bitrix()->getSchemaBuilder()->drop('b_iblock_element_property');
        $this->assertSame([], $db->getElementLinkTargetIblocks(1, 2, true));
    }

    public function test_element_sections_and_files(): void
    {
        $db = $this->connection();

        $this->assertSame([2], array_map('intval', $db->getElementSections(1)));
        $this->assertSame([], $db->getElementSections(99));
        $this->assertSame('laptop.jpg', $db->getFile(1)['FILE_NAME'] ?? null);
        $this->assertNull($db->getFile(99));
        $this->assertSame([], $db->getFiles([]));

        $files = $db->getFiles([3, 1, 99]);
        $this->assertEqualsCanonicalizing([1, 3], array_keys($files));
        $this->assertSame('manual.pdf', $files[3]['FILE_NAME']);
    }

    public function test_highload_blocks_their_fields_and_rows(): void
    {
        $db = $this->connection();

        $this->assertSame(['Brands'], array_column($db->getHighloadBlocks(), 'NAME'));
        $this->assertSame('Brands', $db->getHighloadBlock(1)['NAME'] ?? null);
        $this->assertNull($db->getHighloadBlock(2));
        $this->assertSame(1, (int) ($db->getHighloadBlockByTableName('b_hlbd_brands')['ID'] ?? 0));
        $this->assertNull($db->getHighloadBlockByTableName('b_hlbd_none'));
        $this->assertSame(['UF_NAME', 'UF_XML_ID', 'UF_SORT'], array_column($db->getHighloadBlockFields(1), 'FIELD_NAME'));
        $this->assertSame([], $db->getHighloadBlockFields(2));
        $this->assertSame(['Tiles', 'List'], array_column($db->getUserFieldEnumValues(2), 'VALUE'));

        $this->assertSame(['Northwind'], array_column($db->getHighloadBlockData('b_hlbd_brands', 1), 'UF_NAME'));
        $this->assertSame(['Contoso'], array_column($db->getHighloadBlockData('b_hlbd_brands', 1, 1), 'UF_NAME'));
        $this->assertSame([], $db->getHighloadBlockData('b_hlbd_none'));
        $this->assertSame(2, $db->getHighloadBlockDataCount('b_hlbd_brands'));
        $this->assertSame(0, $db->getHighloadBlockDataCount('b_hlbd_none'));
    }

    public function test_tables_and_columns(): void
    {
        $db = $this->connection();

        $this->assertTrue($db->tableExists('b_iblock'));
        $this->assertFalse($db->tableExists('b_iblock_element_prop_s1'));
        $this->assertSame(['IBLOCK_ID', 'MESSAGE_ID', 'MESSAGE_TEXT'], $db->getTableColumns('b_iblock_messages'));
    }

    public function test_multiple_values_are_counted_in_either_storage(): void
    {
        $db = $this->connection();

        $this->assertSame(2, $db->getMultiplePropertyValuesCount(2, 14));
        $this->assertSame(2, $db->getMultiplePropertyValuesCount(1, 2), 'version 1 keeps them in the shared table');
        $this->assertSame(0, $db->getMultiplePropertyValuesCount(2, 99));
    }

    public function test_seo_lookups_without_the_iproperty_table_are_null(): void
    {
        $this->bitrix()->getSchemaBuilder()->drop('b_iblock_iproperty');

        $this->assertNull($this->connection()->getElementSeo(2, 1));
        $this->assertNull($this->connection()->getSectionSeo(2, 1));
    }

    public function test_seo_lookups_miss_the_templates_bitrix_keeps(): void
    {
        // b_iblock_iproperty keeps ENTITY_TYPE/ENTITY_ID; ELEMENT_ID and
        // SECTION_ID live in b_iblock_element_iprop/b_iblock_section_iprop.
        // Element 1 and section 1 have templates, yet nothing is found
        // (MySQL would fail on the unknown column). Nothing calls these
        // lookups, so the bug never shows (reported).
        $this->assertNull($this->connection()->getElementSeo(2, 1));
        $this->assertNull($this->connection()->getSectionSeo(2, 1));
    }

    public function test_seo_lookups_find_the_row_of_their_element_or_section(): void
    {
        $schema = $this->bitrix()->getSchemaBuilder();
        $schema->drop('b_iblock_iproperty');
        $schema->create('b_iblock_iproperty', function (Blueprint $t): void {
            $t->integer('ID');
            $t->integer('IBLOCK_ID');
            $t->integer('ELEMENT_ID')->nullable();
            $t->integer('SECTION_ID')->nullable();
            $t->string('TEMPLATE');
        });
        $this->bitrix()->table('b_iblock_iproperty')->insert([
            ['ID' => 1, 'IBLOCK_ID' => 2, 'ELEMENT_ID' => 1, 'SECTION_ID' => null, 'TEMPLATE' => 'element'],
            ['ID' => 2, 'IBLOCK_ID' => 2, 'ELEMENT_ID' => null, 'SECTION_ID' => 1, 'TEMPLATE' => 'section'],
            ['ID' => 3, 'IBLOCK_ID' => 1, 'ELEMENT_ID' => 4, 'SECTION_ID' => 4, 'TEMPLATE' => 'news'],
        ]);
        $db = $this->connection();

        $this->assertSame('element', $db->getElementSeo(2, 1)['TEMPLATE'] ?? null);
        $this->assertSame('section', $db->getSectionSeo(2, 1)['TEMPLATE'] ?? null);
        $this->assertSame('news', $db->getElementSeo(1, 4)['TEMPLATE'] ?? null);
        $this->assertSame('news', $db->getSectionSeo(1, 4)['TEMPLATE'] ?? null);
        $this->assertNull($db->getElementSeo(2, 4), 'element 4 is in another iblock');
        $this->assertNull($db->getSectionSeo(2, 4), 'section 4 is in another iblock');
        $this->assertNull($db->getElementSeo(2, 2));
        $this->assertNull($db->getSectionSeo(2, 3));
    }
}
