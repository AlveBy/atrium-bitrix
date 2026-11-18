<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Admin\Cache\RecordCache;
use Atrium\Admin\Models\MediaCollection;
use Atrium\Admin\Models\MediaItem;

/**
 * Migrates the Bitrix media library (fileman module) into media_library_*.
 *
 * The files themselves already sit in public/upload/medialibrary: they moved
 * together with the rest of the upload directory. What does not move on its
 * own is the very thing a library is for: the names. On disk the file names
 * are hashes (`fca426394b6268ee9e17876eba291f17.jpg`), while the human ones
 * ("Sunset over the bay.jpg") together with descriptions, keywords and the
 * collection layout live in b_medialib_item / b_medialib_collection. Without
 * them the library is thousands of nameless thumbnails that cannot be searched.
 *
 * Idempotent: a repeated run updates by bitrix_id and creates no duplicates.
 */
class MigrateMediaLibraryCommand extends AbstractBitrixCommand
{
    protected $signature = 'atrium:bitrix:migrate:medialibrary
                            {--connection= : Name of the Bitrix database connection}
                            {--dry-run : Show what would be migrated without writing anything}';

    protected $description = 'Migrate the Bitrix media library (collections and image names)';

    /** Extensions treated as images. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

    public function handle(): int
    {
        // Rows go in past Eloquent: the record cache is dropped once, at the end.
        return app(RecordCache::class)->bulk(fn (): int => $this->import(), everything: true);
    }

    private function import(): int
    {
        if (! $this->verifyConnection()) {
            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $bitrix = $this->getBitrixConnection()->getConnection();

        if (! $bitrix->getSchemaBuilder()->hasTable('b_medialib_item')) {
            $this->warn('The Bitrix database has no media library tables; nothing to migrate.');

            return self::SUCCESS;
        }

        $collections = $this->importCollections($bitrix, $dryRun);
        $this->importItems($bitrix, $collections, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @return array<int,int> collection ID in Bitrix => our ID
     */
    private function importCollections($bitrix, bool $dryRun): array
    {
        $rows = $bitrix->table('b_medialib_collection')
            ->where('ACTIVE', 'Y')
            ->orderBy('ID')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            if ($dryRun) {
                $this->line(sprintf('  collection #%d "%s"', $row->ID, $row->NAME));

                continue;
            }

            $collection = MediaCollection::updateOrCreate(
                ['bitrix_id' => $row->ID],
                [
                    'name' => (string) $row->NAME,
                    'description' => $row->DESCRIPTION ?: null,
                    'keywords' => $row->KEYWORDS ?: null,
                ]
            );

            $map[$row->ID] = $collection->id;
        }

        // Parents are set in a second pass: not all collections exist yet in the first.
        if (! $dryRun) {
            foreach ($rows as $row) {
                $parentBitrixId = $row->PARENT_ID;

                if ($parentBitrixId > 0 && isset($map[$parentBitrixId], $map[$row->ID])) {
                    MediaCollection::whereKey($map[$row->ID])
                        ->update(['parent_id' => $map[$parentBitrixId]]);
                }
            }
        }

        $this->info(sprintf('Collections: %d', count($rows)));

        return $map;
    }

    /**
     * @param  array<int,int>  $collections
     */
    private function importItems($bitrix, array $collections, bool $dryRun): void
    {
        $imported = 0;
        $missing = [];

        $query = $bitrix->table('b_medialib_item as i')
            ->join('b_file as f', 'f.ID', '=', 'i.SOURCE_ID')
            ->leftJoin('b_medialib_collection_item as ci', 'ci.ITEM_ID', '=', 'i.ID')
            ->orderBy('i.ID')
            ->select([
                'i.ID', 'i.NAME', 'i.DESCRIPTION', 'i.KEYWORDS', 'i.DATE_CREATE',
                'f.SUBDIR', 'f.FILE_NAME', 'f.ORIGINAL_NAME', 'f.CONTENT_TYPE', 'f.FILE_SIZE',
                'ci.COLLECTION_ID',
            ]);

        foreach ($query->cursor() as $row) {
            $url = '/upload/'.trim((string) $row->SUBDIR, '/').'/'.$row->FILE_NAME;

            // A link to a nonexistent file is worse than no entry at all:
            // an editor would pick the image and the site would get a broken src.
            if (! is_file(public_path($url))) {
                $missing[] = $url;

                continue;
            }

            if ($dryRun) {
                $imported++;

                continue;
            }

            $extension = strtolower(pathinfo((string) $row->FILE_NAME, PATHINFO_EXTENSION));

            $item = MediaItem::firstOrNew(['bitrix_id' => $row->ID]);
            $item->fill([
                'collection_id' => $row->COLLECTION_ID === null ? null : ($collections[$row->COLLECTION_ID] ?? null),
                'name' => $this->itemName($row),
                'description' => $row->DESCRIPTION ?: null,
                'keywords' => $row->KEYWORDS ?: null,
                'url' => $url,
                'disk' => null,
                'path' => null,
                'extension' => $extension,
                'mime_type' => $row->CONTENT_TYPE ?: null,
                'size' => $row->FILE_SIZE ?: null,
                'is_image' => in_array($extension, self::IMAGE_EXTENSIONS, true),
            ]);

            // Not fillable: the date is the one Bitrix has, not the day of the import.
            if ($row->DATE_CREATE !== null) {
                $item->created_at = $row->DATE_CREATE;
            }

            $item->save();

            $imported++;
        }

        $this->info(sprintf('Items: %d', $imported));

        if ($missing !== []) {
            $this->warn(sprintf('Skipped (file missing on disk): %d', count($missing)));

            foreach (array_slice($missing, 0, 10) as $url) {
                $this->line('  '.$url);
            }
        }

        if (! $dryRun) {
            $this->reportOrphans();
        }
    }

    /**
     * Display name.
     *
     * For a media library item NAME is what the editor saw in Bitrix. If it is
     * empty, fall back to the original name of the uploaded file, and only then
     * to the name on disk (a hash), which is useless but better than an empty string.
     */
    private function itemName(object $row): string
    {
        foreach ([$row->NAME, $row->ORIGINAL_NAME, $row->FILE_NAME] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return mb_substr($candidate, 0, 255);
            }
        }

        return 'Untitled';
    }

    /** How many of the site's images remain outside the library, just for information. */
    private function reportOrphans(): void
    {
        $total = MediaItem::count();

        $this->newLine();
        $this->info(sprintf('In the library: %d files, %d collections', $total, MediaCollection::count()));
    }
}
