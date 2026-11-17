<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Admin\Models\MediaCollection;
use Atrium\Admin\Models\MediaItem;
use Atrium\Admin\Models\Site;
use Atrium\Bitrix\Tests\Fixtures\SyntheticBitrix;
use Atrium\Bitrix\Tests\SyntheticTestCase;

/**
 * migrate:sites and migrate:medialibrary beyond the happy path of the full run.
 */
final class MigrateSitesAndMediaTest extends SyntheticTestCase
{
    public function test_without_sites_in_bitrix_the_atrium_sites_stay_as_they_are(): void
    {
        $this->bitrix()->table('b_lang')->delete();
        $before = Site::query()->orderBy('id')->get()->toArray();

        $this->artisan('atrium:bitrix:migrate:sites')
            ->expectsOutput('No sites in b_lang: the Atrium default site stays as it is.')
            ->doesntExpectOutputToContain('Bitrix LID')
            ->assertSuccessful();

        $this->assertSame($before, Site::query()->orderBy('id')->get()->toArray());
    }

    public function test_a_default_site_without_domains_keeps_the_atrium_ones(): void
    {
        $this->artisan('atrium:bitrix:migrate:sites')->assertSuccessful();
        Site::query()->where('is_default', true)->sole()->update(['domains' => ['keep.test']]);
        $this->bitrix()->table('b_lang_domain')->where('LID', 's1')->delete();
        $this->bitrix()->table('b_lang')->where('LID', 's1')->update(['SERVER_NAME' => null, 'NAME' => 'Renamed shop']);

        $this->artisan('atrium:bitrix:migrate:sites')->assertSuccessful();

        $main = Site::query()->where('is_default', true)->sole();
        $this->assertSame(['keep.test'], $main->domains);
        $this->assertSame('Renamed shop', $main->name);
    }

    public function test_a_site_atrium_refuses_is_reported_and_the_others_still_move(): void
    {
        $this->bitrix()->table('b_lang')->where('LID', 's2')->update(['DIR' => '/admin/']);

        $this->artisan('atrium:bitrix:migrate:sites')
            ->expectsOutput("s2: The folder admin is the panel's own address.")
            ->expectsTable(['Bitrix LID', 'Code', 'DIR', 'Folder', 'Domains'], [
                ['s1', 'main', '/', '/', 'example.com, www.example.com'],
                ['s3', 's3', '/site2/', '/site2/', '—'],
            ])
            ->assertFailed();

        $this->assertNull(Site::query()->where('code', 's2')->first());
        $this->assertSame('site2', Site::query()->where('code', 's3')->sole()->folder);
    }

    public function test_without_media_library_tables_nothing_is_migrated(): void
    {
        $this->bitrix()->getSchemaBuilder()->drop('b_medialib_item');

        $this->artisan('atrium:bitrix:migrate:medialibrary')
            ->expectsOutput('The Bitrix database has no media library tables; nothing to migrate.')
            ->doesntExpectOutputToContain('Collections:')
            ->assertSuccessful();

        $this->assertSame(0, MediaCollection::count());
    }

    /**
     * Logos holds the company logo; Icons, a child of Logos, holds an item
     * without a name; Archive is inactive; a third item has no file on disk.
     */
    private function seedLibrary(): void
    {
        SyntheticBitrix::writeUploads($this->workDir.'/public');

        $this->bitrix()->table('b_medialib_collection')->insert([
            ['ID' => 2, 'NAME' => 'Icons', 'DESCRIPTION' => '', 'ACTIVE' => 'Y', 'PARENT_ID' => 1, 'KEYWORDS' => 'small'],
            ['ID' => 3, 'NAME' => 'Archive', 'DESCRIPTION' => null, 'ACTIVE' => 'N', 'PARENT_ID' => null, 'KEYWORDS' => null],
            ['ID' => 4, 'NAME' => 'Orphans', 'DESCRIPTION' => null, 'ACTIVE' => 'Y', 'PARENT_ID' => 3, 'KEYWORDS' => null],
        ]);
        $this->bitrix()->table('b_medialib_item')->insert([
            ['ID' => 2, 'NAME' => '  ', 'SOURCE_ID' => 4, 'DESCRIPTION' => '', 'KEYWORDS' => '', 'DATE_CREATE' => '2024-02-01 00:00:00'],
            ['ID' => 3, 'NAME' => 'Gone', 'SOURCE_ID' => 99, 'DESCRIPTION' => null, 'KEYWORDS' => null, 'DATE_CREATE' => null],
            ['ID' => 4, 'NAME' => 'Manual', 'SOURCE_ID' => 3, 'DESCRIPTION' => null, 'KEYWORDS' => 'docs', 'DATE_CREATE' => null],
        ]);
        $this->bitrix()->table('b_file')->insert([
            'ID' => 99, 'MODULE_ID' => 'fileman', 'SUBDIR' => '/medialibrary/zz/', 'FILE_NAME' => 'gone.jpg',
            'ORIGINAL_NAME' => 'gone.jpg', 'CONTENT_TYPE' => 'image/jpeg', 'FILE_SIZE' => 0,
        ]);
        $this->bitrix()->table('b_medialib_collection_item')->insert([
            ['COLLECTION_ID' => 2, 'ITEM_ID' => 2],
            ['COLLECTION_ID' => 3, 'ITEM_ID' => 4],
        ]);
    }

    public function test_the_library_moves_with_its_tree_and_names(): void
    {
        $this->seedLibrary();

        $this->artisan('atrium:bitrix:migrate:medialibrary')
            ->expectsOutput('Collections: 3')
            ->expectsOutput('Items: 3')
            ->expectsOutput('Skipped (file missing on disk): 1')
            ->expectsOutput('  /upload/medialibrary/zz/gone.jpg')
            ->expectsOutput('In the library: 3 files, 3 collections')
            ->assertSuccessful();

        $logos = MediaCollection::query()->where('bitrix_id', 1)->sole();
        $icons = MediaCollection::query()->where('bitrix_id', 2)->sole();
        $orphans = MediaCollection::query()->where('bitrix_id', 4)->sole();
        $this->assertNull($logos->parent_id);
        $this->assertSame($logos->id, $icons->parent_id);
        $this->assertNull($icons->description);
        $this->assertSame('small', $icons->keywords);
        $this->assertNull($orphans->parent_id, 'the parent is inactive and was not moved');
        $this->assertNull(MediaCollection::query()->where('bitrix_id', 3)->first());

        $logo = MediaItem::query()->where('bitrix_id', 1)->sole();
        $this->assertSame('Company logo', $logo->name);
        $this->assertSame($logos->id, $logo->collection_id);
        $this->assertSame('/upload/medialibrary/i5j/logo.png', $logo->url);
        $this->assertSame('png', $logo->extension);
        $this->assertTrue($logo->is_image);
        $this->assertSame('Square', $logo->description);

        $unnamed = MediaItem::query()->where('bitrix_id', 2)->sole();
        $this->assertSame('hardware.png', $unnamed->name, 'an empty name falls back to the uploaded file name');
        $this->assertSame($icons->id, $unnamed->collection_id);
        $this->assertNull($unnamed->description);
        $this->assertNull($unnamed->keywords);

        $manual = MediaItem::query()->where('bitrix_id', 4)->sole();
        $this->assertFalse($manual->is_image);
        $this->assertSame('pdf', $manual->extension);
        $this->assertNull($manual->collection_id, 'its collection is inactive');
        $this->assertNull(MediaItem::query()->where('bitrix_id', 3)->first());
    }

    public function test_a_dry_run_of_the_library_lists_it_and_writes_nothing(): void
    {
        $this->seedLibrary();

        $this->artisan('atrium:bitrix:migrate:medialibrary', ['--dry-run' => true])
            ->expectsOutput('  collection #1 "Logos"')
            ->expectsOutput('  collection #2 "Icons"')
            ->doesntExpectOutput('  collection #3 "Archive"')
            ->expectsOutput('Collections: 3')
            ->expectsOutput('Items: 3')
            ->expectsOutput('Skipped (file missing on disk): 1')
            ->doesntExpectOutputToContain('In the library:')
            ->assertSuccessful();

        $this->assertSame(0, MediaCollection::count());
        $this->assertSame(0, MediaItem::count());
    }

    public function test_only_the_first_ten_missing_files_are_listed(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->bitrix()->table('b_file')->insert([
                'ID' => 100 + $i, 'MODULE_ID' => 'fileman', 'SUBDIR' => 'medialibrary/m', 'FILE_NAME' => "m{$i}.jpg",
                'ORIGINAL_NAME' => '', 'CONTENT_TYPE' => '', 'FILE_SIZE' => 0,
            ]);
            $this->bitrix()->table('b_medialib_item')->insert(['ID' => 100 + $i, 'NAME' => '', 'SOURCE_ID' => 100 + $i]);
        }

        $this->artisan('atrium:bitrix:migrate:medialibrary')
            ->expectsOutput('Skipped (file missing on disk): 12')
            ->expectsOutput('  /upload/medialibrary/m/m9.jpg')
            ->doesntExpectOutput('  /upload/medialibrary/m/m10.jpg')
            ->assertSuccessful();
    }

    public function test_an_item_with_no_name_at_all_is_untitled(): void
    {
        $this->bitrix()->table('b_file')->insert([
            'ID' => 50, 'MODULE_ID' => 'fileman', 'SUBDIR' => 'medialibrary', 'FILE_NAME' => '',
            'ORIGINAL_NAME' => ' ', 'CONTENT_TYPE' => '', 'FILE_SIZE' => 0,
        ]);
        $this->bitrix()->table('b_medialib_item')->insert(['ID' => 50, 'NAME' => '', 'SOURCE_ID' => 50]);
        mkdir($this->workDir.'/public/upload/medialibrary', 0777, true);
        // "/upload/medialibrary/" is a directory, not a file: the item is skipped.
        $this->artisan('atrium:bitrix:migrate:medialibrary')
            ->expectsOutput('  /upload/medialibrary/')
            ->assertSuccessful();

        // A file that exists, called a blank: every name is empty.
        file_put_contents($this->workDir.'/public/upload/medialibrary/ ', 'x');
        $this->bitrix()->table('b_file')->where('ID', 50)->update(['FILE_NAME' => ' ']);

        $this->artisan('atrium:bitrix:migrate:medialibrary')->expectsOutput('Items: 1')->assertSuccessful();

        $item = MediaItem::query()->where('bitrix_id', 50)->sole();
        $this->assertSame('Untitled', $item->name);
        $this->assertNull($item->mime_type);
        $this->assertNull($item->size);
        $this->assertSame('', $item->extension);
        $this->assertFalse($item->is_image);
    }
}
