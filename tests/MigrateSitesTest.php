<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests;

use Atrium\Admin\AtriumServiceProvider;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\Sites;
use Atrium\Bitrix\BitrixServiceProvider;
use Atrium\Bitrix\Tests\Fixtures\SyntheticBitrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Tighten\Ziggy\ZiggyServiceProvider;

/**
 * Bitrix sites (b_lang) become Atrium sites, DIR becoming the folder: three
 * sites on one domain open where they did.
 */
final class MigrateSitesTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            ZiggyServiceProvider::class,
            AtriumServiceProvider::class,
            BitrixServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('app.locale', 'en');
        $target = env('DB_CONNECTION');
        $config->set('database.default', in_array($target, ['mysql', 'pgsql'], true) ? $target : 'testing');
        $config->set('database.connections.bitrix', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('atrium-bitrix.connection', 'bitrix');
    }

    protected function setUp(): void
    {
        parent::setUp();

        SyntheticBitrix::install(DB::connection('bitrix'));
    }

    private function site(string $code): Site
    {
        return Site::query()->where('code', $code)->sole();
    }

    public function test_dir_becomes_the_folder_and_the_domains_come_along(): void
    {
        $this->artisan('atrium:bitrix:migrate:sites')->assertSuccessful();

        // The Bitrix default site is the Atrium default site, at the root.
        $main = Site::query()->where('is_default', true)->sole();
        $this->assertSame('main', $main->code);
        $this->assertSame('Shop', $main->name);
        $this->assertNull($main->folder);
        $this->assertSame(['example.com', 'www.example.com'], $main->domains);
        // There is no Russian among the content languages: all of them stay.
        $this->assertNull($main->languages);

        $english = $this->site('s2');
        $this->assertSame('en', $english->folder);
        $this->assertSame(['example.com', 'www.example.com'], $english->domains);
        $this->assertSame(['en'], $english->languages);
        $this->assertTrue($english->active);

        $second = $this->site('s3');
        $this->assertSame('site2', $second->folder);
        $this->assertSame([], $second->domains);
        $this->assertFalse($second->active);

        $sites = app(Sites::class);
        $sites->flush();
        $this->assertSame('s2', $sites->forRequest('www.example.com', '/en/catalog')->code);
        $this->assertSame('main', $sites->forRequest('www.example.com', '/catalog')->code);

        // Again after a change in Bitrix: the same sites, updated.
        DB::connection('bitrix')->table('b_lang')->where('LID', 's3')->update(['ACTIVE' => 'Y', 'DIR' => '/second/']);
        $this->artisan('atrium:bitrix:migrate:sites')->assertSuccessful();
        $this->assertSame(3, Site::query()->count());
        $this->assertSame('second', $this->site('s3')->folder);
        $this->assertTrue($this->site('s3')->active);

        $this->assertSame([
            ['s1', 'OK'], ['s2', 'OK'], ['s3', 'OK'],
        ], $this->siteStatuses());
    }

    public function test_the_default_site_goes_first_whatever_its_sort_order(): void
    {
        DB::connection('bitrix')->table('b_lang')->where('LID', 's1')->update(['SORT' => 900]);

        $this->artisan('atrium:bitrix:migrate:sites')
            ->expectsTable(['Bitrix LID', 'Code', 'DIR', 'Folder', 'Domains'], [
                ['s1', 'main', '/', '/', 'example.com, www.example.com'],
                ['s2', 's2', '/en/', '/en/', 'example.com, www.example.com'],
                ['s3', 's3', '/site2/', '/site2/', '—'],
            ])
            ->assertSuccessful();
    }

    public function test_a_default_site_in_a_folder_stays_at_the_root(): void
    {
        DB::connection('bitrix')->table('b_lang')->where('LID', 's1')->update(['DIR' => '/ru/']);

        $this->artisan('atrium:bitrix:migrate:sites')
            ->expectsOutputToContain('The default site s1 sits in /ru/ in Bitrix; in Atrium the default site opens at the root.')
            ->assertSuccessful();

        $this->assertNull(Site::query()->where('is_default', true)->sole()->folder);
        $this->assertSame([['s1', 'DEFAULT_AT_ROOT'], ['s2', 'OK'], ['s3', 'OK']], $this->siteStatuses());
    }

    public function test_the_default_site_goes_first_whatever_its_place_in_bitrix(): void
    {
        DB::connection('bitrix')->table('b_lang')->where('LID', 's1')->update(['SORT' => 9]);

        $this->artisan('atrium:bitrix:migrate:sites')
            ->expectsTable(['Bitrix LID', 'Code', 'DIR', 'Folder', 'Domains'], [
                ['s1', 'main', '/', '/', 'example.com, www.example.com'],
                ['s2', 's2', '/en/', '/en/', 'example.com, www.example.com'],
                ['s3', 's3', '/site2/', '/site2/', '—'],
            ])
            ->assertSuccessful();
    }

    public function test_without_a_default_site_in_atrium_the_bitrix_one_is_made_with_its_code(): void
    {
        Site::query()->delete();
        DB::connection('bitrix')->table('b_lang')->where('LID', 's1')->update(['ACTIVE' => 'N']);

        $this->artisan('atrium:bitrix:migrate:sites')->assertSuccessful();

        $default = Site::query()->where('is_default', true)->sole();
        $this->assertSame('s1', $default->code);
        $this->assertTrue($default->active, 'the default site answers every address, so it is never switched off');
        $this->assertNull($default->folder);
        $this->assertSame(['s2', 's3'], Site::query()->where('is_default', false)->orderBy('code')->pluck('code')->all());
    }

    public function test_a_switched_off_default_site_is_switched_on_again(): void
    {
        Site::query()->where('is_default', true)->update(['active' => false]);

        $this->artisan('atrium:bitrix:migrate:sites')->assertSuccessful();

        $this->assertTrue(Site::query()->where('is_default', true)->sole()->active);
    }

    public function test_parity_reports_a_site_that_did_not_arrive(): void
    {
        $this->assertSame([['s1', 'DOMAINS_MISMATCH'], ['s2', 'NO_SITE'], ['s3', 'NO_SITE']], $this->siteStatuses());
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    private function siteStatuses(): array
    {
        $report = sys_get_temp_dir().'/atrium-parity-'.bin2hex(random_bytes(4)).'.json';

        try {
            Artisan::call('atrium:bitrix:parity', ['--format' => 'json', '--output' => $report]);
            $sites = json_decode((string) file_get_contents($report), true)['sites'];
        } finally {
            @unlink($report);
        }

        return array_map(static fn (array $row): array => [$row['lid'], $row['status']], $sites);
    }
}
