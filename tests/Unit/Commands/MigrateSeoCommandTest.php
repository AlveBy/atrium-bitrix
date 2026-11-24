<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * migrate:seo: Bitrix iproperty templates into seo_meta of the migrated rows.
 */
final class MigrateSeoCommandTest extends SyntheticTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->generateSchema();
        $this->artisan('atrium:bitrix:migrate:data', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function seo(string $table, int $bitrixId): ?array
    {
        $id = DB::table($table)->where('bitrix_id', $bitrixId)->value('id');
        $row = DB::table('seo_meta')->where('seoable_id', $id)->where('seoable_type', 'like', '%'.($table === 'products' ? '\\Product' : '\\ProductSection'))->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @param  array<int,array{string,string,int,string}>  $rows  code, type, entity, template
     */
    private function iproperty(array $rows, int $iblock = 2): void
    {
        $this->bitrix()->table('b_iblock_iproperty')->delete();

        foreach ($rows as $i => [$code, $type, $entity, $template]) {
            $this->bitrix()->table('b_iblock_iproperty')->insert([
                'ID' => $i + 1, 'IBLOCK_ID' => $iblock, 'CODE' => $code, 'ENTITY_TYPE' => $type, 'ENTITY_ID' => $entity, 'TEMPLATE' => $template,
            ]);
        }
    }

    public function test_element_and_section_seo_are_written_once_confirmed(): void
    {
        $this->artisan('atrium:bitrix:migrate:seo')
            ->expectsOutput('Models with SEO support: 3')
            ->expectsOutput('Entities with SEO in Bitrix: 2')
            ->expectsConfirmation('Write SEO for 2 entities into seo_meta?', 'yes')
            ->expectsOutput('Written: 2')
            ->doesntExpectOutputToContain('Skipped')
            ->doesntExpectOutputToContain('Truncated')
            ->assertSuccessful();

        $laptop = $this->seo('products', 1);
        $this->assertSame('Laptop Pro — buy online', $laptop['title'] ?? null);
        $this->assertSame('A light and powerful laptop.', $laptop['description']);
        $this->assertSame('Laptop Pro, front', $laptop['preview_picture_alt']);
        $this->assertSame('laptop, pro', $laptop['tags']);

        $hardware = $this->seo('product_sections', 1);
        $this->assertSame('Hardware', $hardware['title'] ?? null);
        $this->assertNull($hardware['tags'], 'tags belong to elements; section 1 is not element 1');
    }

    public function test_declining_the_question_writes_nothing(): void
    {
        $this->artisan('atrium:bitrix:migrate:seo')
            ->expectsConfirmation('Write SEO for 2 entities into seo_meta?', 'no')
            ->expectsOutput('Cancelled.')
            ->doesntExpectOutputToContain('Written:')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('seo_meta')->count());
    }

    public function test_a_dry_run_counts_without_writing(): void
    {
        $this->artisan('atrium:bitrix:migrate:seo', ['--dry-run' => true])
            ->expectsOutput('--dry-run mode: the database is not modified.')
            ->expectsOutput('Written: 2')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('seo_meta')->count());
    }

    public function test_a_bitrix_without_seo_or_outside_the_iblocks_asked_for_has_nothing_to_migrate(): void
    {
        $this->artisan('atrium:bitrix:migrate:seo', ['--iblock' => ['1'], '--force' => true])
            ->expectsOutput('Bitrix has no SEO at the element or section level; nothing to migrate.')
            ->doesntExpectOutputToContain('Entities with SEO')
            ->assertSuccessful();

        $this->iproperty([
            ['ELEMENT_META_TITLE', 'B', 2, 'iblock level'],
            ['ELEMENT_META_TITLE', 'E', 2, ''],
        ]);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])
            ->expectsOutput('Bitrix has no SEO at the element or section level; nothing to migrate.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('seo_meta')->count());
    }

    public function test_only_the_iblocks_asked_for_are_taken(): void
    {
        $this->artisan('atrium:bitrix:migrate:seo', ['--iblock' => ['2'], '--force' => true])
            ->expectsOutput('Entities with SEO in Bitrix: 2')
            ->expectsOutput('Written: 2')
            ->assertSuccessful();
    }

    /**
     * @return iterable<string,array{string,?string}>
     */
    public static function templates(): iterable
    {
        yield 'the name macro' => ['{=this.Name}', 'Laptop Air'];
        yield 'the name macro with a suffix' => ['{=this.Name} 2026', 'Laptop Air 2026'];
        yield 'a value stored twice' => ['Licensed guidesLicensed guides', 'Licensed guides'];
        yield 'a Cyrillic value stored twice' => ['ГидыГиды', 'Гиды'];
        yield 'a repetition with a separator stays' => ['Georgia. Georgia.', 'Georgia. Georgia.'];
        yield 'two different halves stay' => ['abcd', 'abcd'];
        yield 'one character stays' => ['A', 'A'];
        yield 'two equal characters are one stored twice' => ['aa', 'a'];
        yield 'an odd length stays' => ['abcabcabc', 'abcabcabc'];
        yield 'surrounding spaces go' => ['  Spaced  ', 'Spaced'];
        yield 'only spaces is no value' => ['   ', null];
    }

    #[DataProvider('templates')]
    public function test_a_template_is_resolved_before_it_is_written(string $template, ?string $title): void
    {
        $this->iproperty([['ELEMENT_META_TITLE', 'E', 2, $template], ['ELEMENT_META_KEYWORDS', 'E', 2, 'laptop']]);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])->expectsOutput('Written: 1')->assertSuccessful();

        $seo = $this->seo('products', 2);
        $this->assertSame($title, $seo['title'] ?? null);
        $this->assertSame('laptop', $seo['keywords'] ?? null);
    }

    public function test_every_bitrix_code_lands_in_its_column(): void
    {
        $this->iproperty([
            ['ELEMENT_SOMETHING_ELSE', 'E', 2, 'ignored'],
            ['ELEMENT_PAGE_TITLE', 'E', 2, 'H1'],
            ['ELEMENT_PREVIEW_PICTURE_FILE_TITLE', 'E', 2, 'preview title'],
            ['ELEMENT_PREVIEW_PICTURE_FILE_NAME', 'E', 2, 'preview-file'],
            ['ELEMENT_DETAIL_PICTURE_FILE_ALT', 'E', 2, 'detail alt'],
            ['ELEMENT_DETAIL_PICTURE_FILE_TITLE', 'E', 2, 'detail title'],
            ['ELEMENT_DETAIL_PICTURE_FILE_NAME', 'E', 2, 'detail-file'],
            ['SECTION_PICTURE_FILE_ALT', 'S', 1, 'section alt'],
            ['SECTION_PICTURE_FILE_TITLE', 'S', 1, 'section title'],
            ['SECTION_PICTURE_FILE_NAME', 'S', 1, 'section-file'],
            ['SECTION_META_DESCRIPTION', 'S', 1, 'section description'],
        ]);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])->expectsOutput('Written: 2')->assertSuccessful();

        $element = $this->seo('products', 2);
        $this->assertSame('H1', $element['page_title'] ?? null);
        $this->assertSame('preview title', $element['preview_picture_title']);
        $this->assertSame('preview-file', $element['preview_picture_file_name']);
        $this->assertSame('detail alt', $element['detail_picture_alt']);
        $this->assertSame('detail title', $element['detail_picture_title']);
        $this->assertSame('detail-file', $element['detail_picture_file_name']);
        $this->assertNull($element['title']);
        $this->assertNull($element['tags'], 'element 2 has no tags in Bitrix');
        $this->assertNotContains('ignored', $element);

        $section = $this->seo('product_sections', 1);
        $this->assertSame('section alt', $section['preview_picture_alt'] ?? null);
        $this->assertSame('section title', $section['preview_picture_title']);
        $this->assertSame('section-file', $section['preview_picture_file_name']);
        $this->assertSame('section description', $section['description']);
    }

    public function test_an_entity_with_nothing_usable_is_not_written(): void
    {
        $this->iproperty([['ELEMENT_SOMETHING_ELSE', 'E', 2, 'ignored'], ['ELEMENT_META_TITLE', 'E', 3, ' ']]);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])
            ->expectsOutput('Entities with SEO in Bitrix: 2')
            ->expectsOutput('Written: 0')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('seo_meta')->count());
    }

    public function test_tags_alone_are_written_for_an_element(): void
    {
        $this->iproperty([['ELEMENT_SOMETHING_ELSE', 'E', 1, 'ignored']]);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])->expectsOutput('Written: 1')->assertSuccessful();

        $this->assertSame('laptop, pro', $this->seo('products', 1)['tags'] ?? null);
    }

    private function referenceWithSeo(): void
    {
        $namespace = self::NAMESPACE.'\\References';
        file_put_contents($this->workDir.'/models/References/Seoed.php', "<?php\nnamespace {$namespace};\nclass Seoed extends \\Illuminate\\Database\\Eloquent\\Model { use \\Atrium\\Admin\\Traits\\HasAdminSeo; }\n");
    }

    public function test_a_reference_model_with_seo_counts_too(): void
    {
        $this->referenceWithSeo();

        $this->artisan('atrium:bitrix:migrate:seo', ['--dry-run' => true])
            ->expectsOutput('Models with SEO support: 4')
            ->assertSuccessful();
    }

    public function test_the_reference_folder_is_read_when_the_model_folder_is_missing(): void
    {
        $this->referenceWithSeo();
        config(['atrium-bitrix.model_path' => $this->workDir.'/no-such-dir']);

        $this->artisan('atrium:bitrix:migrate:seo', ['--dry-run' => true])
            ->expectsOutput('Models with SEO support: 1')
            ->assertSuccessful();
    }

    public function test_namespaces_written_with_a_trailing_backslash_still_find_their_models(): void
    {
        $this->referenceWithSeo();
        config([
            'atrium-bitrix.model_namespace' => self::NAMESPACE.'\\',
            'atrium-bitrix.reference_namespace' => self::NAMESPACE.'\\References\\',
        ]);

        $this->artisan('atrium:bitrix:migrate:seo', ['--dry-run' => true])
            ->expectsOutput('Models with SEO support: 4')
            ->assertSuccessful();
    }

    public function test_the_progress_and_the_totals_are_set_apart_by_blank_lines(): void
    {
        Artisan::call('atrium:bitrix:migrate:seo', ['--force' => true]);

        $expected = <<<'TXT'
        Models with SEO support: 3
        Entities with SEO in Bitrix: 2
         0/2 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%
         2/2 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

        Written: 2

        TXT;

        // The bar redraws a step in between only when that step took long (a slow
        // database), so the steps between the first and the last are left out.
        $output = (string) preg_replace('#^ [1-9]\d*/2 \[[^\n]*\]\s+\d+%\n(?= 2/2 )#mu', '', Artisan::output());

        $this->assertSame($expected, $output);
    }

    public function test_a_title_is_cut_by_characters_not_by_bytes(): void
    {
        $this->iproperty([['ELEMENT_META_TITLE', 'E', 2, str_repeat('Я', 200).'!']]);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])
            ->doesntExpectOutputToContain('Truncated')
            ->assertSuccessful();

        $this->assertSame(str_repeat('Я', 200).'!', $this->seo('products', 2)['title'] ?? null);
    }

    public function test_values_too_long_for_their_column_are_cut(): void
    {
        $this->bitrix()->table('b_iblock_element')->where('ID', 2)->update(['TAGS' => str_repeat('t', 501)]);
        $this->iproperty([
            ['ELEMENT_META_TITLE', 'E', 2, str_repeat('Я', 255).'x'],
            ['ELEMENT_PAGE_TITLE', 'E', 2, str_repeat('p', 255)],
            ['ELEMENT_META_DESCRIPTION', 'E', 2, str_repeat('d', 999)],
        ]);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])
            ->expectsOutput('Truncated to the column length: 2 values')
            ->assertSuccessful();

        $seo = $this->seo('products', 2);
        $this->assertSame(str_repeat('Я', 255), $seo['title'] ?? null);
        $this->assertSame(str_repeat('p', 255), $seo['page_title']);
        $this->assertSame(str_repeat('t', 500), $seo['tags']);
        $this->assertSame(999, mb_strlen((string) $seo['description']), 'description has no limit');
    }

    public function test_entities_without_a_model_or_a_row_are_skipped_and_counted(): void
    {
        unlink($this->workDir.'/models/ProductSection.php');
        DB::table('products')->where('bitrix_id', 2)->delete();
        $this->iproperty([
            ['ELEMENT_META_TITLE', 'E', 1, 'kept'],
            ['ELEMENT_META_TITLE', 'E', 2, 'row gone'],
            ['SECTION_META_TITLE', 'S', 1, 'no model'],
            ['SECTION_META_TITLE', 'S', 3, 'no model either'],
        ]);
        $this->bitrix()->table('b_iblock_iproperty')->insert([
            'ID' => 9, 'IBLOCK_ID' => 7, 'CODE' => 'ELEMENT_META_TITLE', 'ENTITY_TYPE' => 'E', 'ENTITY_ID' => 1, 'TEMPLATE' => 'unknown iblock',
        ]);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])
            ->expectsOutput('Models with SEO support: 2')
            ->expectsOutput('Written: 1')
            ->expectsOutput('Skipped (no model for the table): 3 - product_sections, iblock 7 without code')
            ->expectsOutput('Skipped (element was not migrated, no row with this bitrix_id): 1')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('seo_meta')->count());
        $this->assertSame('kept', $this->seo('products', 1)['title'] ?? null);
    }

    public function test_the_counts_of_one_run_do_not_carry_over_to_the_next(): void
    {
        unlink($this->workDir.'/models/ProductSection.php');
        DB::table('products')->where('bitrix_id', 2)->delete();
        $this->bitrix()->table('b_iblock_element')->where('ID', 1)->update(['TAGS' => str_repeat('t', 501)]);
        $this->iproperty([
            ['ELEMENT_META_TITLE', 'E', 1, 'kept'],
            ['ELEMENT_META_TITLE', 'E', 2, 'row gone'],
            ['SECTION_META_TITLE', 'S', 1, 'no model'],
        ]);
        $this->bitrix()->table('b_iblock_iproperty')->insert([
            'ID' => 9, 'IBLOCK_ID' => 7, 'CODE' => 'ELEMENT_META_TITLE', 'ENTITY_TYPE' => 'E', 'ENTITY_ID' => 1, 'TEMPLATE' => 'unknown iblock',
        ]);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])
            ->expectsOutput('Written: 1')
            ->expectsOutput('Skipped (no model for the table): 2 - product_sections, iblock 7 without code')
            ->expectsOutput('Skipped (element was not migrated, no row with this bitrix_id): 1')
            ->expectsOutput('Truncated to the column length: 1 values')
            ->assertSuccessful();

        $this->iproperty([['SECTION_META_TITLE', 'S', 1, 'no model']]);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])
            ->expectsOutput('Written: 0')
            ->expectsOutput('Skipped (no model for the table): 1 - product_sections')
            ->doesntExpectOutputToContain('element was not migrated')
            ->doesntExpectOutputToContain('Truncated')
            ->assertSuccessful();
    }

    public function test_an_iblock_without_a_code_cannot_be_matched_to_a_model(): void
    {
        $this->bitrix()->table('b_iblock')->where('ID', 2)->update(['CODE' => '']);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])
            ->expectsOutput('Written: 0')
            ->expectsOutput('Skipped (no model for the table): 2 - iblock 2 without code')
            ->assertSuccessful();
    }

    public function test_only_eloquent_models_with_seo_in_the_model_folders_count(): void
    {
        $namespace = self::NAMESPACE;
        file_put_contents($this->workDir.'/models/Helper.php', "<?php\nnamespace {$namespace};\nclass Helper {}\n");
        file_put_contents($this->workDir.'/models/Plain.php', "<?php\nnamespace {$namespace};\nclass Plain extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        file_put_contents($this->workDir.'/models/NotAClass.php', "<?php\n");
        // Uses the trait, but is not a model: there is nothing to ask for its table.
        file_put_contents($this->workDir.'/models/NotAModel.php', "<?php\nnamespace {$namespace};\nclass NotAModel { use \\Atrium\\Admin\\Traits\\HasAdminSeo; }\n");

        // News, Product and ProductSection; the Brands reference has no SEO.
        $this->artisan('atrium:bitrix:migrate:seo', ['--dry-run' => true])
            ->expectsOutput('Models with SEO support: 3')
            ->assertSuccessful();
    }

    public function test_without_the_model_folder_no_entity_has_a_model(): void
    {
        config(['atrium-bitrix.model_path' => $this->workDir.'/no-such-dir']);

        $this->artisan('atrium:bitrix:migrate:seo', ['--dry-run' => true])
            ->expectsOutput('Models with SEO support: 0')
            ->expectsOutput('Written: 0')
            ->expectsOutput('Skipped (no model for the table): 2 - products, product_sections')
            ->assertSuccessful();
    }

    public function test_running_again_updates_the_seo_in_place(): void
    {
        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])->assertSuccessful();
        $this->bitrix()->table('b_iblock_iproperty')->where('ID', 1)->update(['TEMPLATE' => 'Laptop Pro — new title']);

        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true])->assertSuccessful();

        $this->assertSame(2, DB::table('seo_meta')->count());
        $this->assertSame('Laptop Pro — new title', $this->seo('products', 1)['title'] ?? null);
    }
}
