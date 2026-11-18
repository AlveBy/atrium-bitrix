<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\Site;

/**
 * The sites of a Bitrix installation (b_lang with its domains in
 * b_lang_domain) as Atrium sites: LID is the code, DIR the folder
 * (DIR "/" — no folder), the domains stay the domains, the server name
 * first.
 *
 * The Bitrix default site becomes the Atrium default site, whatever its
 * code: records already moved belong to it.
 */
final class BitrixSites
{
    public function __construct(private readonly BitrixConnection $bitrix) {}

    /**
     * @return array<int,array{lid:string,dir:string,default:bool,attributes:array<string,mixed>}>
     */
    public function all(): array
    {
        if (! $this->bitrix->tableExists('b_lang')) {
            return [];
        }

        $db = $this->bitrix->getConnection();
        $domains = [];

        if ($this->bitrix->tableExists('b_lang_domain')) {
            foreach ($db->table('b_lang_domain')->orderBy('LID')->get() as $row) {
                $domain = self::domain($row->DOMAIN);

                if ($domain !== '') {
                    $domains[$row->LID][] = $domain;
                }
            }
        }

        $sites = [];

        foreach ($db->table('b_lang')->orderBy('SORT')->orderBy('LID')->get() as $row) {
            $lid = $row->LID;
            $language = strtolower($row->LANGUAGE_ID);
            // The server name is the main domain in Bitrix: Atrium's first one.
            $own = array_unique($domains[$lid] ?? []);
            $main = self::domain($row->SERVER_NAME ?? '');
            usort($own, static fn (string $a, string $b): int => ($b === $main) <=> ($a === $main));

            $sites[] = [
                'lid' => $lid,
                'dir' => $row->DIR,
                'default' => $row->DEF === 'Y',
                'attributes' => [
                    'code' => strtolower($lid),
                    'name' => $row->NAME,
                    'folder' => Site::normalizeFolder($row->DIR),
                    'domains' => $own,
                    'active' => $row->ACTIVE === 'Y',
                    'sort' => $row->SORT,
                    'languages' => Language::query()->where('code', $language)->exists() ? [$language] : null,
                ],
            ];
        }

        return $sites;
    }

    /**
     * The Atrium site a Bitrix site was moved to.
     */
    public static function target(array $site): ?Site
    {
        return $site['default']
            ? Site::query()->where('is_default', true)->first()
            : Site::query()->where('code', $site['attributes']['code'])->first();
    }

    /**
     * "Example.com:443" → "example.com", as Atrium keeps it.
     */
    private static function domain(string $domain): string
    {
        return rtrim(preg_replace('#:\d+$#', '', strtolower(trim($domain))), '.');
    }
}
