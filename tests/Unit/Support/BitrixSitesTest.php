<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Support;

use Atrium\Admin\Models\Language;
use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Support\BitrixSites;
use Atrium\Bitrix\Tests\SyntheticTestCase;

final class BitrixSitesTest extends SyntheticTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bitrix()->table('b_lang')->delete();
        $this->bitrix()->table('b_lang_domain')->delete();
    }

    /**
     * @param  array<string,mixed>  $site
     */
    private function site(array $site): void
    {
        $this->bitrix()->table('b_lang')->insert($site + [
            'SORT' => 100, 'DEF' => 'N', 'ACTIVE' => 'Y', 'NAME' => 'Site', 'DIR' => '/', 'LANGUAGE_ID' => 'ru', 'SERVER_NAME' => null,
        ]);
    }

    private function domains(string $lid, string ...$domains): void
    {
        foreach ($domains as $domain) {
            $this->bitrix()->table('b_lang_domain')->insert(['LID' => $lid, 'DOMAIN' => $domain]);
        }
    }

    /**
     * @return array<int,array{lid:string,dir:string,default:bool,attributes:array<string,mixed>}>
     */
    private function all(): array
    {
        return (new BitrixSites(new BitrixConnection))->all();
    }

    public function test_a_site_keeps_what_bitrix_stores_and_the_code_is_its_lowercase_id(): void
    {
        $this->site(['LID' => 'S1', 'SORT' => 7, 'DEF' => 'Y', 'ACTIVE' => 'N', 'NAME' => 'Shop', 'DIR' => '/shop/']);

        $sites = $this->all();

        $this->assertCount(1, $sites);
        $this->assertSame('S1', $sites[0]['lid']);
        $this->assertSame('/shop/', $sites[0]['dir']);
        $this->assertTrue($sites[0]['default']);
        $this->assertSame([
            'code' => 's1',
            'name' => 'Shop',
            'folder' => 'shop',
            'domains' => [],
            'active' => false,
            'sort' => 7,
            'languages' => null,
        ], $sites[0]['attributes']);
    }

    public function test_only_the_default_flag_y_and_the_active_flag_y_count(): void
    {
        $this->site(['LID' => 's1', 'DEF' => 'N', 'ACTIVE' => 'Y']);

        $site = $this->all()[0];

        $this->assertFalse($site['default']);
        $this->assertTrue($site['attributes']['active']);
    }

    public function test_sites_come_by_sort_then_by_id(): void
    {
        $this->site(['LID' => 'b2', 'SORT' => 1]);
        $this->site(['LID' => 'a1', 'SORT' => 2]);
        $this->site(['LID' => 'a2', 'SORT' => 1]);

        $this->assertSame(['a2', 'b2', 'a1'], array_column($this->all(), 'lid'));
    }

    public function test_the_language_of_a_site_is_taken_when_the_panel_has_it(): void
    {
        Language::query()->create(['code' => 'ru', 'name' => 'Russian', 'active' => true, 'is_default' => false, 'sort' => 1]);
        $this->site(['LID' => 's1', 'LANGUAGE_ID' => 'RU']);
        $this->site(['LID' => 's2', 'LANGUAGE_ID' => 'de']);
        $this->site(['LID' => 's3', 'LANGUAGE_ID' => '']);

        $languages = array_map(static fn (array $site): mixed => $site['attributes']['languages'], $this->all());

        $this->assertSame([['ru'], null, null], $languages);
    }

    public function test_domains_are_cleaned_of_case_port_spaces_and_a_dot_and_listed_once(): void
    {
        $this->site(['LID' => 's1']);
        $this->site(['LID' => 's2']);
        $this->domains('s1', '  Shop.Example.COM.:8080  ', 'shop.example.com', 'plain.example.org.', '', ':80');
        $this->domains('s2', 'other.example.net');

        $sites = $this->all();

        $this->assertSame(['shop.example.com', 'plain.example.org'], $sites[0]['attributes']['domains']);
        $this->assertSame(['other.example.net'], $sites[1]['attributes']['domains']);
    }

    public function test_the_server_name_is_the_first_domain_and_the_rest_keep_their_order(): void
    {
        $this->site(['LID' => 's1', 'SERVER_NAME' => 'Main.example.com:443']);
        $this->site(['LID' => 's2', 'SERVER_NAME' => 'not-listed.example.com']);
        $this->site(['LID' => 's3']);
        $this->domains('s1', 'a.example.com', 'main.example.com', 'b.example.com');
        $this->domains('s2', 'c.example.com', 'd.example.com');
        $this->domains('s3', 'e.example.com', 'f.example.com');

        $domains = array_map(static fn (array $site): array => $site['attributes']['domains'], $this->all());

        $this->assertSame([
            ['main.example.com', 'a.example.com', 'b.example.com'],
            ['c.example.com', 'd.example.com'],
            ['e.example.com', 'f.example.com'],
        ], $domains);
    }

    public function test_without_a_domains_table_the_sites_have_no_domains(): void
    {
        $this->site(['LID' => 's1', 'SERVER_NAME' => 'main.example.com']);
        $this->bitrix()->getSchemaBuilder()->drop('b_lang_domain');

        $this->assertSame([], $this->all()[0]['attributes']['domains']);
    }
}
