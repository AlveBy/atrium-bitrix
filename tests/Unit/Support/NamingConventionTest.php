<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Support;

use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NamingConventionTest extends TestCase
{
    private function naming(array $prefixes = ['mytheme_'], array $mappings = []): NamingConvention
    {
        config(['atrium-bitrix.strip_prefixes' => $prefixes, 'atrium-bitrix.naming.mappings' => $mappings]);

        return new NamingConvention;
    }

    /**
     * code => [table, model, section table, section model, url path].
     *
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string,5:string}>
     */
    public static function iblockCodes(): array
    {
        return [
            'plain' => ['news', 'news', 'News', 'news_sections', 'NewsSection', 'news'],
            'theme prefix' => ['mytheme_catalog', 'catalogs', 'Catalog', 'catalog_sections', 'CatalogSection', 'catalogs'],
            'theme prefix in capitals' => ['MYTHEME_catalog', 'catalogs', 'Catalog', 'catalog_sections', 'CatalogSection', 'catalogs'],
            'Bitrix iblock_ prefix' => ['iblock_products', 'products', 'Product', 'product_sections', 'ProductSection', 'products'],
            'Bitrix ib_ prefix' => ['ib_partners', 'partners', 'Partner', 'partner_sections', 'PartnerSection', 'partners'],
            'Bitrix content_ prefix' => ['content_articles', 'articles', 'Article', 'article_sections', 'ArticleSection', 'articles'],
            'theme then Bitrix prefix' => ['mytheme_content_faq', 'faqs', 'Faq', 'faq_sections', 'FaqSection', 'faqs'],
            'upper snake case' => ['LINK_SERVICES', 'link_services', 'LinkService', 'link_service_sections', 'LinkServiceSection', 'link-services'],
            'upper case word' => ['SERVICES', 'services', 'Service', 'service_sections', 'ServiceSection', 'services'],
            'camel case' => ['tourPrices', 'tour_prices', 'TourPrice', 'tour_price_sections', 'TourPriceSection', 'tour-prices'],
            'starts with a digit' => ['2024_events', 'iblock_2024_events', 'Iblock2024Event', 'iblock_2024_event_sections', 'Iblock2024EventSection', '2024-events'],
        ];
    }

    #[DataProvider('iblockCodes')]
    public function test_iblock_codes_become_tables_models_and_paths(string $code, string $table, string $model, string $sectionTable, string $sectionModel, string $urlPath): void
    {
        $naming = $this->naming();

        $this->assertSame($table, $naming->toTableName($code));
        $this->assertSame($model, $naming->toModelName($code));
        $this->assertSame($sectionTable, $naming->toSectionTableName($code));
        $this->assertSame($sectionModel, $naming->toSectionModelName($code));
        $this->assertSame($urlPath, $naming->toUrlPath($code));
    }

    public function test_mappings_win_over_the_rules(): void
    {
        $naming = $this->naming([], ['news' => ['table' => 'posts', 'model' => 'Post', 'url_path' => 'blog']]);

        $this->assertSame('posts', $naming->toTableName('news'));
        $this->assertSame('Post', $naming->toModelName('news'));
        $this->assertSame('post_sections', $naming->toSectionTableName('news'));
        $this->assertSame('PostSection', $naming->toSectionModelName('news'));
        $this->assertSame('blog', $naming->toUrlPath('news'));
        // Other codes are untouched.
        $this->assertSame('events', $naming->toTableName('events'));
    }

    public function test_a_mapping_of_only_the_table_leaves_the_model_to_the_rules(): void
    {
        $naming = $this->naming([], ['news' => ['table' => 'posts']]);

        $this->assertSame('posts', $naming->toTableName('news'));
        $this->assertSame('News', $naming->toModelName('news'));
        $this->assertSame('news', $naming->toUrlPath('news'));
    }

    public function test_mappings_can_be_added_later(): void
    {
        $naming = $this->naming([], ['a' => ['table' => 'b']]);
        $naming->addMapping('news', ['model' => 'Story']);

        $this->assertSame(['a' => ['table' => 'b'], 'news' => ['model' => 'Story']], $naming->getMappings());
        $this->assertSame('Story', $naming->toModelName('news'));
    }

    public function test_prefixes_that_are_not_strings_or_empty_are_ignored(): void
    {
        $naming = $this->naming(['', 5, null, 'demo_']);

        $this->assertSame('news', $naming->stripPrefixes('demo_news'));
        $this->assertSame('mytheme_news', $naming->stripPrefixes('mytheme_news'));
    }

    public function test_a_code_that_is_only_the_prefix_keeps_it(): void
    {
        $naming = $this->naming(['demo_']);

        $this->assertSame('demo_', $naming->stripPrefixes('demo_'));
        $this->assertSame('x', $naming->stripPrefixes('demo_x'));
        $this->assertSame('x', $naming->stripPrefixes('DEMO_x'));
    }

    public function test_only_the_first_matching_theme_prefix_is_cut(): void
    {
        $naming = $this->naming(['a_', 'b_']);

        $this->assertSame('b_news', $naming->stripPrefixes('a_b_news'));
        $this->assertSame('news', $naming->stripPrefixes('b_news'));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function columns(): array
    {
        return [
            'upper snake case' => ['LINK_SERVICES', 'link_services'],
            'property prefix dropped' => ['PROPERTY_PRICE', 'price'],
            'only at the start' => ['MY_PROPERTY_PRICE', 'my_property_price'],
            'numeric code' => ['PROPERTY_123', 'prop_123'],
            'bare number' => ['42', 'prop_42'],
            'digits and letters' => ['3D', '3d'],
            'camel case' => ['showOnMain', 'show_on_main'],
            'lower case' => ['price', 'price'],
        ];
    }

    #[DataProvider('columns')]
    public function test_property_codes_become_column_names(string $code, string $column): void
    {
        $this->assertSame($column, $this->naming()->toColumnName($code));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function namesToCodes(): array
    {
        return [
            'Latin words' => ['Show on main', 'show_on_main'],
            'Cyrillic' => ['Цена тура', 'tsena_tura'],
            'punctuation' => ['  Price, € / night!  ', 'price_night'],
            'starts with a digit' => ['3 stars', 'prop_3_stars'],
            'empty' => ['', ''],
            'only symbols' => ['—!?', ''],
            'Chinese' => ['价格', ''],
            'emoji' => ['🪑', ''],
            'already snake' => ['ALREADY_SNAKE', 'already_snake'],
        ];
    }

    #[DataProvider('namesToCodes')]
    public function test_a_code_is_made_from_a_property_name(string $name, string $code): void
    {
        $this->assertSame($code, $this->naming()->generateCodeFromName($name));
    }

    /**
     * @return array<string,array{0:string,1:bool,2:string}>
     */
    public static function relations(): array
    {
        return [
            'single' => ['LINK_SERVICES', false, 'linkService'],
            'multiple' => ['LINK_SERVICE', true, 'linkServices'],
            'single brand' => ['BRAND', false, 'brand'],
            'multiple goods' => ['PROPERTY_LINK_GOODS', true, 'linkGoods'],
        ];
    }

    #[DataProvider('relations')]
    public function test_relation_names_are_camel_case_and_counted(string $code, bool $multiple, string $relation): void
    {
        $this->assertSame($relation, $this->naming()->toRelationName($code, $multiple));
    }

    public function test_a_relation_is_single_by_default(): void
    {
        $this->assertSame('linkService', $this->naming()->toRelationName('LINK_SERVICES'));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function highloadTables(): array
    {
        return [
            'b_hlbd_ prefix' => ['b_hlbd_colors', 'colors'],
            'no prefix' => ['colors', 'colors'],
            'prefix only at the start' => ['x_b_hlbd_colors', 'x_b_hlbd_colors'],
            'camel case' => ['b_hlbd_colorReference', 'color_reference'],
            'starts with a digit' => ['b_hlbd_2colors', 'hlblock_2colors'],
        ];
    }

    #[DataProvider('highloadTables')]
    public function test_highload_tables_lose_their_prefix(string $table, string $expected): void
    {
        $this->assertSame($expected, $this->naming()->hlblockToTableName($table));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function highloadModels(): array
    {
        return [
            'plain' => ['Colors', 'Colors'],
            'hl_ prefix' => ['hl_colors', 'Colors'],
            'hlblock_ prefix in capitals' => ['HLBLOCK_Sizes', 'Sizes'],
            'upper snake' => ['COLOR_REFERENCE', 'ColorReference'],
            'starts with a digit' => ['hl_2colors', 'Hlblock2colors'],
        ];
    }

    #[DataProvider('highloadModels')]
    public function test_highload_names_become_models(string $name, string $model): void
    {
        $this->assertSame($model, $this->naming()->hlblockToModelName($name));
    }

    public function test_highload_mappings_win(): void
    {
        $naming = $this->naming([], ['b_hlbd_colors' => ['table' => 'palette'], 'Colors' => ['model' => 'Paint']]);

        $this->assertSame('palette', $naming->hlblockToTableName('b_hlbd_colors'));
        $this->assertSame('Paint', $naming->hlblockToModelName('Colors'));
        $this->assertSame('sizes', $naming->hlblockToTableName('b_hlbd_sizes'));
    }

    public function test_uf_fields_become_columns(): void
    {
        $naming = $this->naming();

        $this->assertSame('xml_id', $naming->ufFieldToColumnName('UF_XML_ID'));
        $this->assertSame('name', $naming->ufFieldToColumnName('UF_NAME'));
        $this->assertSame('my_uf_name', $naming->ufFieldToColumnName('MY_UF_NAME'));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function constants(): array
    {
        return [
            'words' => ['In stock', 'IN_STOCK'],
            'camel case' => ['inStock', 'IN_STOCK'],
            'Cyrillic' => ['Да', 'DA'],
            'Cyrillic words' => ['Под заказ', 'POD_ZAKAZ'],
            'starts with a digit' => ['3 days', 'VALUE_3_DAYS'],
            'symbols dropped' => ['50% off!', 'VALUE_50_OFF'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('constants')]
    public function test_enum_values_become_constant_names(string $value, string $constant): void
    {
        $this->assertSame($constant, $this->naming()->toConstantName($value));
    }

    public function test_pivot_tables_are_singular_and_sorted(): void
    {
        $naming = $this->naming();

        $this->assertSame('product_tag', $naming->toPivotTableName('tags', 'products'));
        $this->assertSame('product_tag', $naming->toPivotTableName('products', 'tags'));
        $this->assertSame('news_news', $naming->toPivotTableName('news', 'news'));
    }

    public function test_migration_class_and_file_names(): void
    {
        $naming = $this->naming();
        $this->travelTo(new \DateTimeImmutable('2026-03-04 05:06:07'));

        $this->assertSame('CreateProductsTable', $naming->toMigrationClassName('products'));
        $this->assertSame('AlterProductsTable', $naming->toMigrationClassName('products', 'alter'));
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_products_table\.php$/', $naming->toMigrationFileName('products'));
        $this->assertStringEndsWith('_alter_products_table.php', $naming->toMigrationFileName('products', 'alter'));
        $this->assertStringStartsWith(date('Y_m_d_'), $naming->toMigrationFileName('products'));
    }

    public function test_transliteration_covers_the_whole_alphabet_and_leaves_the_rest(): void
    {
        $naming = $this->naming();

        $this->assertSame('abvgdeyozhziyklmnoprstufhtschshschyeyuya', $naming->transliterate('абвгдеёжзийклмнопрстуфхцчшщъыьэюя'));
        $this->assertSame('ABVGDEYoZhZIYKLMNOPRSTUFHTsChShSchYEYuYa', $naming->transliterate('АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ'));
        $this->assertSame('Tour 3D — 价格 🪑', $naming->transliterate('Tour 3D — 价格 🪑'));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function slugs(): array
    {
        return [
            'words' => ['Laptop Pro 15', 'laptop-pro-15'],
            'Cyrillic' => ['Новый тур в Минск', 'novyy-tur-v-minsk'],
            'punctuation squeezed' => ['  Hello,   world!  ', 'hello-world'],
            'only symbols' => ['—!?', ''],
            'Chinese dropped' => ['Tour 价格', 'tour'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('slugs')]
    public function test_slugs(string $text, string $slug): void
    {
        $this->assertSame($slug, $this->naming()->toSlug($text));
    }
}
