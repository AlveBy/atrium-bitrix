<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * migrate:data beyond the full run: blocks that are missing, skipped or
 * empty, rows that cannot be written, files, and every kind of link table.
 */
final class MigrateDataCommandTest extends SyntheticTestCase
{
    public function test_missing_and_skipped_blocks_are_reported_and_passed_over(): void
    {
        config(['atrium-bitrix.skip_iblocks' => [1]]);

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [9], '--iblock' => [1, 99], '--force' => true])
            ->expectsOutput('Migrating Highload Block data...')
            ->expectsOutput('Highload Block 9 not found')
            ->expectsOutput('Migrating IBlock data...')
            ->expectsOutput('Skipping IBlock 1 (in skip list)')
            ->expectsOutput('IBlock 99 not found')
            ->expectsOutput('Data migration complete!')
            ->doesntExpectOutputToContain('Migrating elements')
            ->doesntExpectOutputToContain('Migrating: ')
            ->assertSuccessful();
    }

    public function test_an_iblock_outside_the_skip_list_is_migrated(): void
    {
        $this->withoutLinksToProducts();
        $this->generateSchema();
        config(['atrium-bitrix.skip_iblocks' => [2]]);

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [9], '--iblock' => [1], '--force' => true])
            ->doesntExpectOutputToContain('Skipping IBlock')
            ->expectsOutput('Migrating elements: News -> news')
            ->assertSuccessful();

        $this->assertSame(2, DB::table('news')->count());
    }

    public function test_an_empty_highload_block_has_nothing_to_migrate(): void
    {
        $this->bitrix()->table('b_hlbd_brands')->delete();

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [1], '--iblock' => [99], '--force' => true])
            ->expectsOutput('Migrating: Brands -> brands')
            ->expectsOutput('  No data to migrate')
            ->doesntExpectOutputToContain('Records to migrate')
            ->assertSuccessful();
    }

    public function test_rows_that_cannot_be_written_are_reported_one_by_one(): void
    {
        // No migrations ran: the brands table is not there.
        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [1], '--iblock' => [99], '--force' => true])
            ->expectsOutput('  Records to migrate: 2')
            ->expectsOutputToContain('  Error inserting row 1: ')
            ->expectsOutputToContain('  Error inserting row 2: ')
            ->expectsOutput('Data migration complete!')
            ->assertSuccessful();
    }

    public function test_highload_values_of_every_kind_are_converted(): void
    {
        $this->addBrandFields();
        $this->generateSchema();

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [1], '--iblock' => [99], '--force' => true, '--chunk' => 1])
            ->expectsOutput('  Records to migrate: 2')
            ->doesntExpectOutputToContain('Error inserting')
            ->assertSuccessful();

        $northwind = DB::table('brands')->where('id', 1)->first();
        $contoso = DB::table('brands')->where('id', 2)->first();
        $this->assertNotNull($northwind);
        $this->assertNotNull($contoso);
        $this->assertSame('Northwind', $northwind->name);
        $this->assertTrue((bool) $northwind->active);
        $this->assertFalse((bool) $contoso->active);
        $this->assertSame('/upload/iblock/g4h/hardware.png', $northwind->logo);
        $this->assertNull($contoso->logo);
        $this->assertSame(['tools', 'paint'], json_decode((string) $northwind->tags, true));
        $this->assertNull($contoso->tags, 'a broken list is dropped');
        $this->assertNull($northwind->sort, 'an empty value is left out');
        $this->assertSame(200, (int) $contoso->sort);
    }

    public function test_with_files_skipped_a_highload_file_keeps_its_id(): void
    {
        $this->addBrandFields();
        $this->generateSchema();

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [1], '--iblock' => [99], '--force' => true, '--skip-files' => true])
            ->assertSuccessful();

        $this->assertSame('4', (string) DB::table('brands')->where('id', 1)->value('logo'));
    }

    public function test_an_iblock_without_elements_has_nothing_to_migrate(): void
    {
        $this->generateSchema();
        $this->bitrix()->table('b_iblock_element')->where('IBLOCK_ID', 1)->delete();

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [9], '--iblock' => [1], '--force' => true])
            ->expectsOutput('Migrating elements: News -> news')
            ->expectsOutput('  No elements to migrate')
            ->doesntExpectOutputToContain('Elements to migrate')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('news')->count());
    }

    public function test_elements_already_there_are_reported_and_left_alone(): void
    {
        $this->skipOnPostgres();
        $this->generateSchema();
        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [9], '--iblock' => [1], '--force' => true])
            ->doesntExpectOutputToContain('Error inserting')
            ->assertSuccessful();
        $this->assertSame(2, DB::table('news')->count());
        $this->assertSame(2, DB::table('news_link_goods')->count());

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [9], '--iblock' => [1], '--force' => true])
            ->expectsOutputToContain('  Error inserting element 4: ')
            ->expectsOutputToContain('  Error inserting element 5: ')
            ->expectsOutput('Data migration complete!')
            ->assertSuccessful();
    }

    public function test_sections_already_there_are_reported_and_left_alone(): void
    {
        $this->skipOnPostgres();
        $this->generateSchema();
        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [1], '--iblock' => [2], '--force' => true])->assertSuccessful();

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [9], '--iblock' => [2], '--force' => true])
            ->expectsOutputToContain('  Error inserting section 1: ')
            ->expectsOutputToContain('  Error inserting section 3: ')
            ->expectsOutputToContain('  Error inserting element 1: ')
            ->assertSuccessful();

        $this->assertSame(3, DB::table('product_sections')->count());
        $this->assertSame(3, DB::table('products')->count());
    }

    public function test_pictures_of_sections_and_elements_become_paths(): void
    {
        $this->generateSchema();
        $this->bitrix()->table('b_iblock_section')->where('ID', 3)->update(['DETAIL_PICTURE' => 1]);
        $this->bitrix()->table('b_iblock_element')->where('ID', 2)->update(['DETAIL_PICTURE' => 3]);

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [1], '--iblock' => [2], '--force' => true, '--chunk' => 2])
            ->expectsOutput('  Elements to migrate: 3')
            ->assertSuccessful();

        $this->assertSame('/upload/iblock/g4h/hardware.png', DB::table('product_sections')->where('id', 1)->value('picture'));
        $this->assertNull(DB::table('product_sections')->where('id', 1)->value('detail_picture'));
        $this->assertNull(DB::table('product_sections')->where('id', 3)->value('picture'));
        $this->assertSame('/upload/iblock/a1b/laptop.jpg', DB::table('product_sections')->where('id', 3)->value('detail_picture'));
        $this->assertSame('/upload/iblock/a1b/laptop.jpg', DB::table('products')->where('id', 1)->value('preview_picture'));
        $this->assertNull(DB::table('products')->where('id', 1)->value('detail_picture'));
        $this->assertNull(DB::table('products')->where('id', 2)->value('preview_picture'));
        $this->assertSame('/upload/iblock/e3f/manual.pdf', DB::table('products')->where('id', 2)->value('detail_picture'));
        $this->assertSame(3, DB::table('products')->count(), 'read in pages of two');
    }

    public function test_with_files_skipped_no_picture_is_written(): void
    {
        $this->generateSchema();
        $this->bitrix()->table('b_iblock_section')->where('ID', 3)->update(['DETAIL_PICTURE' => 1]);
        $this->bitrix()->table('b_iblock_element')->where('ID', 2)->update(['DETAIL_PICTURE' => 3]);

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [1], '--iblock' => [2], '--force' => true, '--skip-files' => true])
            ->assertSuccessful();

        $this->assertSame([null, null, null], DB::table('product_sections')->orderBy('id')->pluck('picture')->all());
        $this->assertNull(DB::table('product_sections')->where('id', 3)->value('detail_picture'));
        $this->assertSame([null, null, null], DB::table('products')->orderBy('id')->pluck('preview_picture')->all());
        $this->assertNull(DB::table('products')->where('id', 2)->value('detail_picture'));
        $this->assertSame('3', (string) DB::table('products')->where('id', 1)->value('manual'), 'a file property keeps its id');
    }

    public function test_a_property_named_like_a_base_field_does_not_overwrite_it(): void
    {
        $this->bitrix()->table('b_iblock_property')->insert([
            'ID' => 3, 'IBLOCK_ID' => 1, 'NAME' => 'Sort order', 'CODE' => 'SORT', 'PROPERTY_TYPE' => 'N', 'SORT' => 300, 'MULTIPLE' => 'N',
        ]);
        $this->bitrix()->table('b_iblock_element_property')->insert([
            'ID' => 4, 'IBLOCK_PROPERTY_ID' => 3, 'IBLOCK_ELEMENT_ID' => 4, 'VALUE' => '999',
        ]);
        $this->withoutLinksToProducts();
        $this->generateSchema();

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [9], '--iblock' => [1], '--force' => true])
            ->doesntExpectOutputToContain('Error inserting')
            ->assertSuccessful();

        $this->assertSame(500, (int) DB::table('news')->where('id', 4)->value('sort'));
        $this->assertSame('Press office', DB::table('news')->where('id', 4)->value('source'));
    }

    public function test_links_to_sections_and_directory_records_fill_their_tables(): void
    {
        $this->addMultipleLinks();
        $this->generateSchema();

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [1], '--iblock' => [2], '--force' => true])
            ->doesntExpectOutputToContain('Error inserting')
            ->assertSuccessful();

        $this->assertSame(
            [['product_id' => 1, 'section_id' => 1, 'sort' => 0], ['product_id' => 1, 'section_id' => 3, 'sort' => 1]],
            $this->rows('products_sections_extra', ['product_id', 'section_id', 'sort']),
        );
        $northwind = (int) DB::table('brands')->where('xml_id', 'northwind')->value('id');
        $contoso = (int) DB::table('brands')->where('xml_id', 'contoso')->value('id');
        $this->assertSame(
            [['product_id' => 1, 'brand_id' => $northwind, 'sort' => 0], ['product_id' => 1, 'brand_id' => $contoso, 'sort' => 2]],
            $this->rows('products_brands', ['product_id', 'brand_id', 'sort'], 'brand_id'),
            'the unknown brand and the empty value are left out',
        );
        $this->assertSame(
            [['product_id' => 1, 'related_id' => 2], ['product_id' => 2, 'related_id' => 1]],
            $this->rows('products_related', ['product_id', 'related_id']),
            'an empty link is left out',
        );
    }

    public function test_a_link_table_that_cannot_be_written_is_passed_over(): void
    {
        $this->skipOnPostgres();
        $this->generateSchema();
        Schema::drop('products_related');

        $this->artisan('atrium:bitrix:migrate:data', ['--hlblock' => [9], '--iblock' => [2, 1], '--force' => true])
            ->doesntExpectOutputToContain('Error inserting')
            ->expectsOutput('Data migration complete!')
            ->assertSuccessful();

        $this->assertSame(3, DB::table('products')->count());
        $this->assertSame(2, DB::table('news_link_goods')->count(), 'the other links still arrive');
    }

    /**
     * A flag, a logo and a list of tags on the brands; Northwind has no sort.
     */
    private function addBrandFields(): void
    {
        $this->bitrix()->getSchemaBuilder()->table('b_hlbd_brands', function (Blueprint $t): void {
            $t->integer('UF_ACTIVE')->nullable();
            $t->integer('UF_LOGO')->nullable();
            $t->text('UF_TAGS')->nullable();
        });
        $this->bitrix()->table('b_user_field')->insert([
            ['ID' => 6, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_ACTIVE', 'USER_TYPE_ID' => 'boolean', 'SORT' => 400, 'MULTIPLE' => 'N'],
            ['ID' => 7, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_LOGO', 'USER_TYPE_ID' => 'file', 'SORT' => 500, 'MULTIPLE' => 'N'],
            ['ID' => 8, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_TAGS', 'USER_TYPE_ID' => 'string', 'SORT' => 600, 'MULTIPLE' => 'Y'],
        ]);
        $this->bitrix()->table('b_hlbd_brands')->where('ID', 1)->update(['UF_ACTIVE' => 1, 'UF_LOGO' => 4, 'UF_TAGS' => serialize(['tools', 'paint']), 'UF_SORT' => null]);
        $this->bitrix()->table('b_hlbd_brands')->where('ID', 2)->update(['UF_ACTIVE' => 0, 'UF_LOGO' => null, 'UF_TAGS' => 's:9:"broken']);
    }

    /**
     * Products get extra sections and several brands; Laptop Air an empty
     * related product.
     */
    private function addMultipleLinks(): void
    {
        $this->bitrix()->table('b_iblock_property')->insert([
            ['ID' => 24, 'IBLOCK_ID' => 2, 'NAME' => 'Also in', 'CODE' => 'SECTIONS_EXTRA', 'PROPERTY_TYPE' => 'G', 'USER_TYPE' => null, 'SORT' => 900, 'MULTIPLE' => 'Y', 'LINK_IBLOCK_ID' => 2, 'USER_TYPE_SETTINGS' => null],
            ['ID' => 25, 'IBLOCK_ID' => 2, 'NAME' => 'Brands', 'CODE' => 'BRANDS', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'directory', 'SORT' => 910, 'MULTIPLE' => 'Y', 'LINK_IBLOCK_ID' => null, 'USER_TYPE_SETTINGS' => serialize(['TABLE_NAME' => 'b_hlbd_brands'])],
        ]);
        $this->bitrix()->table('b_iblock_element_prop_m2')->insert([
            ['ID' => 7, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 24, 'VALUE' => '1'],
            ['ID' => 8, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 24, 'VALUE' => '3'],
            ['ID' => 9, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 25, 'VALUE' => 'northwind'],
            ['ID' => 10, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 25, 'VALUE' => 'gone'],
            ['ID' => 11, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 25, 'VALUE' => 'contoso'],
            ['ID' => 12, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 25, 'VALUE' => ''],
            ['ID' => 13, 'IBLOCK_ELEMENT_ID' => 3, 'IBLOCK_PROPERTY_ID' => 17, 'VALUE' => '0'],
        ]);
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string,int>>
     */
    private function rows(string $table, array $columns, ?string $orderBy = null): array
    {
        return DB::table($table)->orderBy('product_id')->orderBy($orderBy ?? $columns[1])->get($columns)
            ->map(fn (object $row): array => array_map('intval', (array) $row))
            ->all();
    }

    /**
     * News alone: links to products that are not migrated would break the
     * foreign key, and PostgreSQL then refuses the rest of the test.
     */
    private function withoutLinksToProducts(): void
    {
        $this->bitrix()->table('b_iblock_element_property')->where('IBLOCK_PROPERTY_ID', 2)->delete();
    }

    private function skipOnPostgres(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->markTestSkipped('PostgreSQL refuses every statement after a failed one in the test transaction, and the command goes on.');
        }
    }
}
