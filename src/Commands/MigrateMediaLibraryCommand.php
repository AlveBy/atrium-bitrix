<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

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
        if (! $this->verifyConnection()) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
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
                ['bitrix_id' => (int) $row->ID],
                [
                    'name' => (string) $row->NAME,
                    'description' => $row->DESCRIPTION ?: null,
                    'keywords' => $row->KEYWORDS ?: null,
                ]
            );

            $map[(int) $row->ID] = $collection->id;
        }

        // Parents are set in a second pass: not all collections exist yet in the first.
        if (! $dryRun) {
            foreach ($rows as $row) {
                $parentBitrixId = (int) ($row->PARENT_ID ?? 0);

                if ($parentBitrixId > 0 && isset($map[$parentBitrixId], $map[(int) $row->ID])) {
                    MediaCollection::whereKey($map[(int) $row->ID])
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
            if (! is_file(public_path(ltrim($url, '/')))) {
                $missing[] = $url;

                continue;
            }

            if ($dryRun) {
                $imported++;

                continue;
            }

            $extension = strtolower(pathinfo((string) $row->FILE_NAME, PATHINFO_EXTENSION));

            MediaItem::updateOrCreate(
                ['bitrix_id' => (int) $row->ID],
                [
                    'collection_id' => $collections[(int) ($row->COLLECTION_ID ?? 0)] ?? null,
                    'name' => $this->itemName($row),
                    'description' => $row->DESCRIPTION ?: null,
                    'keywords' => $row->KEYWORDS ?: null,
                    'url' => $url,
                    'disk' => null,
                    'path' => null,
                    'extension' => $extension,
                    'mime_type' => $row->CONTENT_TYPE ?: null,
                    'size' => (int) ($row->FILE_SIZE ?: 0) ?: null,
                    'is_image' => in_array($extension, self::IMAGE_EXTENSIONS, true),
                    'created_at' => $row->DATE_CREATE,
                ]
            );

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
