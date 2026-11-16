<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Admin\Models\Site;
use Atrium\Bitrix\Support\BitrixSites;
use Illuminate\Validation\ValidationException;

/**
 * Bitrix sites (b_lang) become Atrium sites: the folder of a site is its
 * DIR, so several sites on one domain (/, /en/, /site2/) open where they
 * did. Run again after changes in Bitrix: sites are matched by code, the
 * default one with the default one.
 */
class MigrateSitesCommand extends AbstractBitrixCommand
{
    protected $signature = 'atrium:bitrix:migrate:sites
                            {--connection= : Database connection name}';

    protected $description = 'Move Bitrix sites (b_lang) with their folders and domains to Atrium';

    public function handle(): int
    {
        if (! $this->verifyConnection()) {
            return self::FAILURE;
        }

        $sites = (new BitrixSites($this->getBitrixConnection()))->all();

        if ($sites === []) {
            $this->info('No sites in b_lang: the Atrium default site stays as it is.');

            return self::SUCCESS;
        }

        // The default site first: a site that loses the default flag to it
        // may then be put into a folder.
        usort($sites, static fn (array $a, array $b): int => (int) $b['default'] <=> (int) $a['default']);

        $failed = false;
        $rows = [];

        foreach ($sites as $site) {
            $attributes = $site['attributes'];
            $target = BitrixSites::target($site) ?? new Site(['code' => $attributes['code']]);

            if ($site['default']) {
                // The Atrium default site answers every address nobody else
                // claims, so it stays at the root and keeps its code.
                if ($attributes['folder'] !== null) {
                    $this->warn("The default site {$site['lid']} sits in {$site['dir']} in Bitrix; in Atrium the default site opens at the root.");
                }

                unset($attributes['code']);
                $attributes['folder'] = null;
                $attributes['is_default'] = true;
                $attributes['active'] = true;

                if ($attributes['domains'] === []) {
                    unset($attributes['domains']);
                }
            } else {
                $attributes['is_default'] = false;
            }

            try {
                $target->fill($attributes)->save();
            } catch (ValidationException $e) {
                $failed = true;
                $this->error("{$site['lid']}: ".collect($e->errors())->flatten()->implode(' '));

                continue;
            }

            $rows[] = [$site['lid'], $target->code, $site['dir'], $target->folder === null ? '/' : '/'.$target->folder.'/', implode(', ', (array) $target->domains) ?: '—'];
        }

        $this->table(['Bitrix LID', 'Code', 'DIR', 'Folder', 'Domains'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
