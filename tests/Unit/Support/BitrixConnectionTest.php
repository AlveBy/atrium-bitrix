<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Support;

use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Tests\SyntheticTestCase;

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

    public function test_iblocks_and_their_lookups(): void
    {
        $db = $this->connection();

        $this->assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['ID'], $db->getIBlocks()));
        $this->assertSame([1], array_map(static fn (array $r): int => (int) $r['ID'], $db->getIBlocks('content')));
        $this->assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['ID'], $db->getIBlocks('')), 'an empty type is no filter');
        $this->assertSame('Products', $db->getIBlock(2)['NAME'] ?? null);
        $this->assertNull($db->getIBlock(3));
        $this->assertSame(2, (int) ($db->getIBlockByCode('demo_products')['ID'] ?? 0));
        $this->assertNull($db->getIBlockByCode('missing'));
    }

    public function test_elements_are_paged_in_id_order(): void
    {
        $db = $this->connection();

        $this->assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['ID'], $db->getIBlockElements(2, 2, 0)));
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
        $this->bitrix()->enableQueryLog();
        $this->assertSame([], $db->getElementLinkTargetIblocks(2, 14, false));
        $this->assertSame([], array_filter(
            array_column($this->bitrix()->getQueryLog(), 'query'),
            static fn (string $sql): bool => str_contains($sql, 'PROPERTY_14'),
        ), 'a column that is not there is not asked for');

        // Empty strings and NULLs are no link.
        $this->bitrix()->table('b_iblock_element_prop_s2')->update(['PROPERTY_18' => '']);
        $this->assertSame([], $db->getElementLinkTargetIblocks(2, 18, false));

        // An iblock with neither table has nothing to count.
        $this->bitrix()->getSchemaBuilder()->drop('b_iblock_element_property');
        $this->assertSame([], $db->getElementLinkTargetIblocks(1, 2, true));
    }

    public function test_a_file_is_found_by_its_id(): void
    {
        $db = $this->connection();

        $this->assertSame('laptop.jpg', $db->getFile(1)['FILE_NAME'] ?? null);
        $this->assertNull($db->getFile(99));
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

        $this->assertSame(['Northwind'], array_column($db->getHighloadBlockData('b_hlbd_brands', 1, 0), 'UF_NAME'));
        $this->assertSame(['Contoso'], array_column($db->getHighloadBlockData('b_hlbd_brands', 1, 1), 'UF_NAME'));
        $this->assertSame([], $db->getHighloadBlockData('b_hlbd_none', 100, 0));
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
}
