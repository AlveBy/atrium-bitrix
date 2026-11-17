<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Admin\Cache\RecordCache;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The import commands write past Eloquent (query builder inserts), so no
 * event tells the record cache what changed: it is dropped whole at the end.
 */
final class ImportsDropTheRecordCacheTest extends SyntheticTestCase
{
    /**
     * @return array<string,array{string,array<string,mixed>}>
     */
    public static function commands(): array
    {
        return [
            'migrate:data' => ['atrium:bitrix:migrate:data', ['--force' => true, '--no-interaction' => true]],
            'migrate:medialibrary' => ['atrium:bitrix:migrate:medialibrary', []],
            'migrate:seo' => ['atrium:bitrix:migrate:seo', ['--force' => true]],
            'migrate:sites' => ['atrium:bitrix:migrate:sites', []],
            'sync' => ['atrium:bitrix:sync', ['--force' => true]],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    #[DataProvider('commands')]
    public function test_a_run_drops_the_whole_record_cache_even_when_it_imports_nothing(string $command, array $options): void
    {
        $this->generateSchema();
        // Nothing to import: Bitrix has no rows for any of the commands.
        foreach (['b_iblock_element', 'b_iblock_section', 'b_iblock_iproperty', 'b_lang', 'b_medialib_item'] as $table) {
            $this->bitrix()->table($table)->delete();
        }

        $versions = app(RecordCache::class)->versions();
        $before = $versions->get(['all'])['all'];

        $this->artisan($command, $options);

        $versions->reset();
        $this->assertNotSame($before, $versions->get(['all'])['all']);
    }
}
