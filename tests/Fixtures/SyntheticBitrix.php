<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * A small made-up 1C-Bitrix database: just the tables the migrator reads,
 * filled with invented content.
 *
 * - "News" (iblock 1, properties stored the old way, in one table) with a
 *   link to products that names no iblock;
 * - "Products" (iblock 2, properties in their own tables) with a section
 *   tree, properties of every type, section user fields, a saved form and
 *   list layout and its own captions;
 * - "Brands", a highload block the products refer to;
 * - SEO templates, tags, files and a media library.
 *
 * Built with the schema builder, so it runs on any database Laravel does.
 */
final class SyntheticBitrix
{
    public const PRODUCT_PROPERTIES = [
        // ID => [CODE, NAME, TYPE, USER_TYPE, MULTIPLE, extra]
        10 => ['ARTICLE', 'Article number', 'S', null, 'N', []],
        11 => ['PRICE', 'Price', 'N', null, 'N', []],
        12 => ['COLOR', 'Colour', 'L', null, 'N', ['LIST_TYPE' => 'L']],
        13 => ['ORDERABLE', 'Can be ordered', 'L', null, 'N', ['LIST_TYPE' => 'C']],
        14 => ['FEATURES', 'Features', 'S', null, 'Y', []],
        15 => ['PHOTOS', 'Photos', 'F', null, 'Y', ['FILE_TYPE' => 'jpg, gif, png, jpeg']],
        16 => ['MANUAL', 'Manual', 'F', null, 'N', ['FILE_TYPE' => 'pdf, doc']],
        17 => ['RELATED', 'Related products', 'E', null, 'Y', ['LINK_IBLOCK_ID' => 2]],
        18 => ['CATEGORY', 'Extra section', 'G', null, 'N', ['LINK_IBLOCK_ID' => 2]],
        19 => ['SPECS', 'Specifications', 'S', 'HTML', 'N', []],
        20 => ['RELEASED', 'Release date', 'S', 'Date', 'N', []],
        21 => ['CHECKED_AT', 'Checked at', 'S', 'DateTime', 'N', []],
        22 => ['STORE', 'Store on the map', 'S', 'map_yandex', 'N', []],
        23 => ['BRAND', 'Brand', 'S', 'directory', 'N', []],
    ];

    public static function install(Connection $db): void
    {
        self::createTables($db);
        self::seed($db);
    }

    private static function createTables(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();

        $schema->create('b_iblock_type', function (Blueprint $t): void {
            $t->string('ID')->primary();
            $t->char('SECTIONS', 1)->default('Y');
            $t->integer('SORT')->default(500);
        });

        $schema->create('b_iblock', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->string('IBLOCK_TYPE_ID');
            $t->string('CODE')->nullable();
            $t->string('NAME');
            $t->char('ACTIVE', 1)->default('Y');
            $t->integer('SORT')->default(500);
            $t->string('LIST_PAGE_URL')->nullable();
            $t->string('DETAIL_PAGE_URL')->nullable();
            $t->string('SECTION_PAGE_URL')->nullable();
            $t->integer('VERSION')->default(1);
            $t->string('XML_ID')->nullable();
        });

        $schema->create('b_iblock_messages', function (Blueprint $t): void {
            $t->integer('IBLOCK_ID');
            $t->string('MESSAGE_ID');
            $t->string('MESSAGE_TEXT')->nullable();
        });

        $schema->create('b_iblock_property', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->integer('IBLOCK_ID');
            $t->string('NAME');
            $t->char('ACTIVE', 1)->default('Y');
            $t->integer('SORT')->default(500);
            $t->string('CODE')->nullable();
            $t->text('DEFAULT_VALUE')->nullable();
            $t->char('PROPERTY_TYPE', 1)->default('S');
            $t->integer('ROW_COUNT')->default(1);
            $t->integer('COL_COUNT')->default(30);
            $t->char('LIST_TYPE', 1)->default('L');
            $t->char('MULTIPLE', 1)->default('N');
            $t->string('FILE_TYPE')->nullable();
            $t->integer('LINK_IBLOCK_ID')->nullable();
            $t->char('IS_REQUIRED', 1)->default('N');
            $t->char('SEARCHABLE', 1)->default('N');
            $t->char('FILTRABLE', 1)->default('N');
            $t->char('WITH_DESCRIPTION', 1)->default('N');
            $t->string('USER_TYPE')->nullable();
            $t->text('USER_TYPE_SETTINGS')->nullable();
            $t->string('HINT')->nullable();
            $t->char('SMART_FILTER', 1)->nullable();
            $t->string('DISPLAY_TYPE')->nullable();
            $t->string('XML_ID')->nullable();
        });

        $schema->create('b_iblock_property_enum', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->integer('PROPERTY_ID');
            $t->string('VALUE');
            $t->char('DEF', 1)->default('N');
            $t->integer('SORT')->default(500);
            $t->string('XML_ID');
        });

        $schema->create('b_iblock_section', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->integer('IBLOCK_ID');
            $t->integer('IBLOCK_SECTION_ID')->nullable();
            $t->dateTime('TIMESTAMP_X')->nullable();
            $t->dateTime('DATE_CREATE')->nullable();
            $t->char('ACTIVE', 1)->default('Y');
            $t->integer('SORT')->default(500);
            $t->string('NAME');
            $t->integer('PICTURE')->nullable();
            $t->integer('DETAIL_PICTURE')->nullable();
            $t->integer('LEFT_MARGIN')->nullable();
            $t->integer('RIGHT_MARGIN')->nullable();
            $t->integer('DEPTH_LEVEL')->nullable();
            $t->text('DESCRIPTION')->nullable();
            $t->string('DESCRIPTION_TYPE')->default('text');
            $t->string('CODE')->nullable();
            $t->string('XML_ID')->nullable();
        });

        $schema->create('b_iblock_element', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->dateTime('TIMESTAMP_X')->nullable();
            $t->integer('MODIFIED_BY')->nullable();
            $t->dateTime('DATE_CREATE')->nullable();
            $t->integer('CREATED_BY')->nullable();
            $t->integer('IBLOCK_ID');
            $t->integer('IBLOCK_SECTION_ID')->nullable();
            $t->char('ACTIVE', 1)->default('Y');
            $t->dateTime('ACTIVE_FROM')->nullable();
            $t->dateTime('ACTIVE_TO')->nullable();
            $t->integer('SORT')->default(500);
            $t->string('NAME');
            $t->integer('PREVIEW_PICTURE')->nullable();
            $t->text('PREVIEW_TEXT')->nullable();
            $t->string('PREVIEW_TEXT_TYPE')->default('text');
            $t->integer('DETAIL_PICTURE')->nullable();
            $t->text('DETAIL_TEXT')->nullable();
            $t->string('DETAIL_TEXT_TYPE')->default('text');
            $t->string('CODE')->nullable();
            $t->string('TAGS')->nullable();
            $t->string('XML_ID')->nullable();
        });

        $schema->create('b_iblock_section_element', function (Blueprint $t): void {
            $t->integer('IBLOCK_SECTION_ID');
            $t->integer('IBLOCK_ELEMENT_ID');
        });

        // Properties stored the old way (iblock VERSION 1): one table for all.
        $schema->create('b_iblock_element_property', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->integer('IBLOCK_PROPERTY_ID');
            $t->integer('IBLOCK_ELEMENT_ID');
            $t->text('VALUE');
            $t->char('VALUE_TYPE', 4)->default('text');
            $t->integer('VALUE_ENUM')->nullable();
            $t->decimal('VALUE_NUM', 18, 4)->nullable();
            $t->string('DESCRIPTION')->nullable();
        });

        // VERSION 2: a column per single property, a table for multiple ones.
        $schema->create('b_iblock_element_prop_s2', function (Blueprint $t): void {
            $t->integer('IBLOCK_ELEMENT_ID')->primary();

            foreach (self::PRODUCT_PROPERTIES as $id => [, , $type, , $multiple]) {
                if ($multiple === 'Y') {
                    continue;
                }

                $type === 'N'
                    ? $t->decimal('PROPERTY_'.$id, 18, 4)->nullable()
                    : $t->text('PROPERTY_'.$id)->nullable();
                $t->string('DESCRIPTION_'.$id)->nullable();
            }
        });

        $schema->create('b_iblock_element_prop_m2', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->integer('IBLOCK_ELEMENT_ID');
            $t->integer('IBLOCK_PROPERTY_ID');
            $t->text('VALUE');
            $t->integer('VALUE_ENUM')->nullable();
            $t->decimal('VALUE_NUM', 18, 4)->nullable();
            $t->string('DESCRIPTION')->nullable();
        });

        $schema->create('b_iblock_iproperty', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->integer('IBLOCK_ID');
            $t->string('CODE');
            $t->char('ENTITY_TYPE', 1);
            $t->integer('ENTITY_ID');
            $t->text('TEMPLATE')->nullable();
        });

        $schema->create('b_file', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->string('MODULE_ID')->nullable();
            $t->integer('HEIGHT')->nullable();
            $t->integer('WIDTH')->nullable();
            $t->integer('FILE_SIZE')->nullable();
            $t->string('CONTENT_TYPE')->nullable();
            $t->string('SUBDIR');
            $t->string('FILE_NAME');
            $t->string('ORIGINAL_NAME')->nullable();
            $t->string('DESCRIPTION')->nullable();
        });

        $schema->create('b_user_option', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->integer('USER_ID')->nullable();
            $t->string('CATEGORY');
            $t->string('NAME');
            $t->text('VALUE');
            $t->char('COMMON', 1)->default('N');
        });

        $schema->create('b_user_field', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->string('ENTITY_ID');
            $t->string('FIELD_NAME');
            $t->string('USER_TYPE_ID');
            $t->string('XML_ID')->nullable();
            $t->integer('SORT')->default(100);
            $t->char('MULTIPLE', 1)->default('N');
            $t->char('MANDATORY', 1)->default('N');
            $t->text('SETTINGS')->nullable();
        });

        $schema->create('b_user_field_lang', function (Blueprint $t): void {
            $t->integer('USER_FIELD_ID');
            $t->string('LANGUAGE_ID');
            $t->string('EDIT_FORM_LABEL')->nullable();
        });

        $schema->create('b_user_field_enum', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->integer('USER_FIELD_ID');
            $t->string('VALUE');
            $t->char('DEF', 1)->default('N');
            $t->integer('SORT')->default(500);
            $t->string('XML_ID');
        });

        $schema->create('b_uts_iblock_2_section', function (Blueprint $t): void {
            $t->integer('VALUE_ID')->primary();
            $t->text('UF_NOTE')->nullable();
            $t->integer('UF_VIEW')->nullable();
        });

        $schema->create('b_hlblock_entity', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->string('NAME');
            $t->string('TABLE_NAME');
        });

        $schema->create('b_hlbd_brands', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->text('UF_NAME')->nullable();
            $t->text('UF_XML_ID')->nullable();
            $t->integer('UF_SORT')->nullable();
        });

        $schema->create('b_medialib_collection', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->string('NAME');
            $t->text('DESCRIPTION')->nullable();
            $t->char('ACTIVE', 1)->default('Y');
            $t->integer('PARENT_ID')->nullable();
            $t->string('KEYWORDS')->nullable();
        });

        $schema->create('b_medialib_item', function (Blueprint $t): void {
            $t->integer('ID')->primary();
            $t->string('NAME');
            $t->integer('SOURCE_ID');
            $t->text('DESCRIPTION')->nullable();
            $t->string('KEYWORDS')->nullable();
            $t->dateTime('DATE_CREATE')->nullable();
        });

        $schema->create('b_medialib_collection_item', function (Blueprint $t): void {
            $t->integer('COLLECTION_ID');
            $t->integer('ITEM_ID');
        });
    }

    private static function seed(Connection $db): void
    {
        $db->table('b_iblock_type')->insert([
            ['ID' => 'catalog', 'SECTIONS' => 'Y', 'SORT' => 100],
            ['ID' => 'content', 'SECTIONS' => 'Y', 'SORT' => 200],
        ]);

        $db->table('b_iblock')->insert([
            [
                'ID' => 1, 'IBLOCK_TYPE_ID' => 'content', 'CODE' => 'demo_news', 'NAME' => 'News',
                'ACTIVE' => 'Y', 'SORT' => 100, 'VERSION' => 1, 'XML_ID' => 'news',
                'LIST_PAGE_URL' => '#SITE_DIR#/news/', 'DETAIL_PAGE_URL' => '#SITE_DIR#/news/#ELEMENT_CODE#/',
                'SECTION_PAGE_URL' => null,
            ],
            [
                'ID' => 2, 'IBLOCK_TYPE_ID' => 'catalog', 'CODE' => 'demo_products', 'NAME' => 'Products',
                'ACTIVE' => 'Y', 'SORT' => 200, 'VERSION' => 2, 'XML_ID' => 'products',
                'LIST_PAGE_URL' => '#SITE_DIR#/products/', 'DETAIL_PAGE_URL' => '#SITE_DIR#/products/#SECTION_CODE#/#ELEMENT_CODE#/',
                'SECTION_PAGE_URL' => '#SITE_DIR#/products/#SECTION_CODE#/',
            ],
        ]);

        $db->table('b_iblock_messages')->insert([
            ['IBLOCK_ID' => 2, 'MESSAGE_ID' => 'ELEMENT_NAME', 'MESSAGE_TEXT' => 'Product'],
            ['IBLOCK_ID' => 2, 'MESSAGE_ID' => 'ELEMENTS_NAME', 'MESSAGE_TEXT' => 'Products'],
            ['IBLOCK_ID' => 2, 'MESSAGE_ID' => 'ELEMENT_ADD', 'MESSAGE_TEXT' => 'Add a product'],
            ['IBLOCK_ID' => 2, 'MESSAGE_ID' => 'SECTION_NAME', 'MESSAGE_TEXT' => 'Category'],
            ['IBLOCK_ID' => 2, 'MESSAGE_ID' => 'SECTIONS_NAME', 'MESSAGE_TEXT' => 'Categories'],
        ]);

        // News: the old storage, a source and a link to products with no iblock set.
        $db->table('b_iblock_property')->insert([
            ['ID' => 1, 'IBLOCK_ID' => 1, 'NAME' => 'Source', 'CODE' => 'SOURCE', 'PROPERTY_TYPE' => 'S', 'SORT' => 100, 'MULTIPLE' => 'N'],
            ['ID' => 2, 'IBLOCK_ID' => 1, 'NAME' => 'Products mentioned', 'CODE' => 'LINK_GOODS', 'PROPERTY_TYPE' => 'E', 'SORT' => 200, 'MULTIPLE' => 'Y'],
        ]);

        $sort = 100;

        foreach (self::PRODUCT_PROPERTIES as $id => [$code, $name, $type, $userType, $multiple, $extra]) {
            $db->table('b_iblock_property')->insert([
                'ID' => $id, 'IBLOCK_ID' => 2, 'NAME' => $name, 'CODE' => $code, 'PROPERTY_TYPE' => $type,
                'USER_TYPE' => $userType, 'MULTIPLE' => $multiple, 'SORT' => $sort += 10,
                'USER_TYPE_SETTINGS' => $code === 'BRAND' ? serialize(['size' => 1, 'TABLE_NAME' => 'b_hlbd_brands']) : null,
                ...$extra,
            ]);
        }

        $db->table('b_iblock_property_enum')->insert([
            ['ID' => 1, 'PROPERTY_ID' => 12, 'VALUE' => 'Silver', 'XML_ID' => 'silver', 'SORT' => 10, 'DEF' => 'Y'],
            ['ID' => 2, 'PROPERTY_ID' => 12, 'VALUE' => 'Black', 'XML_ID' => 'black', 'SORT' => 20, 'DEF' => 'N'],
            ['ID' => 3, 'PROPERTY_ID' => 13, 'VALUE' => 'YES', 'XML_ID' => 'Y', 'SORT' => 10, 'DEF' => 'N'],
        ]);

        // Sections: Hardware > Laptops, and Software.
        $db->table('b_iblock_section')->insert([
            ['ID' => 1, 'IBLOCK_ID' => 2, 'IBLOCK_SECTION_ID' => null, 'NAME' => 'Hardware', 'CODE' => 'hardware', 'SORT' => 100, 'LEFT_MARGIN' => 1, 'RIGHT_MARGIN' => 4, 'DEPTH_LEVEL' => 1, 'PICTURE' => 4, 'DESCRIPTION' => '<p>Things you can touch.</p>', 'DESCRIPTION_TYPE' => 'html', 'DATE_CREATE' => '2024-01-10 10:00:00', 'TIMESTAMP_X' => '2024-01-10 10:00:00'],
            ['ID' => 2, 'IBLOCK_ID' => 2, 'IBLOCK_SECTION_ID' => 1, 'NAME' => 'Laptops', 'CODE' => 'laptops', 'SORT' => 100, 'LEFT_MARGIN' => 2, 'RIGHT_MARGIN' => 3, 'DEPTH_LEVEL' => 2, 'PICTURE' => null, 'DESCRIPTION' => null, 'DESCRIPTION_TYPE' => 'text', 'DATE_CREATE' => '2024-01-10 10:05:00', 'TIMESTAMP_X' => '2024-01-10 10:05:00'],
            ['ID' => 3, 'IBLOCK_ID' => 2, 'IBLOCK_SECTION_ID' => null, 'NAME' => 'Software', 'CODE' => 'software', 'SORT' => 200, 'LEFT_MARGIN' => 5, 'RIGHT_MARGIN' => 6, 'DEPTH_LEVEL' => 1, 'PICTURE' => null, 'DESCRIPTION' => 'Things you cannot.', 'DESCRIPTION_TYPE' => 'text', 'DATE_CREATE' => '2024-01-10 10:10:00', 'TIMESTAMP_X' => '2024-01-10 10:10:00'],
        ]);

        // Section user fields: a plain text and a list.
        $db->table('b_user_field')->insert([
            ['ID' => 1, 'ENTITY_ID' => 'IBLOCK_2_SECTION', 'FIELD_NAME' => 'UF_NOTE', 'USER_TYPE_ID' => 'string', 'SORT' => 100],
            ['ID' => 2, 'ENTITY_ID' => 'IBLOCK_2_SECTION', 'FIELD_NAME' => 'UF_VIEW', 'USER_TYPE_ID' => 'enumeration', 'SORT' => 200],
            ['ID' => 3, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_NAME', 'USER_TYPE_ID' => 'string', 'SORT' => 100],
            ['ID' => 4, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_XML_ID', 'USER_TYPE_ID' => 'string', 'SORT' => 200],
            ['ID' => 5, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_SORT', 'USER_TYPE_ID' => 'integer', 'SORT' => 300],
        ]);
        $db->table('b_user_field_lang')->insert([
            ['USER_FIELD_ID' => 1, 'LANGUAGE_ID' => 'en', 'EDIT_FORM_LABEL' => 'Note for editors'],
        ]);
        $db->table('b_user_field_enum')->insert([
            ['ID' => 1, 'USER_FIELD_ID' => 2, 'VALUE' => 'Tiles', 'XML_ID' => 'tiles', 'SORT' => 10],
            ['ID' => 2, 'USER_FIELD_ID' => 2, 'VALUE' => 'List', 'XML_ID' => 'list', 'SORT' => 20],
        ]);
        $db->table('b_uts_iblock_2_section')->insert([
            ['VALUE_ID' => 1, 'UF_NOTE' => 'Keep it short', 'UF_VIEW' => 2],
        ]);

        $db->table('b_hlblock_entity')->insert(['ID' => 1, 'NAME' => 'Brands', 'TABLE_NAME' => 'b_hlbd_brands']);
        $db->table('b_hlbd_brands')->insert([
            ['ID' => 1, 'UF_NAME' => 'Northwind', 'UF_XML_ID' => 'northwind', 'UF_SORT' => 100],
            ['ID' => 2, 'UF_NAME' => 'Contoso', 'UF_XML_ID' => 'contoso', 'UF_SORT' => 200],
        ]);

        $db->table('b_file')->insert([
            ['ID' => 1, 'MODULE_ID' => 'iblock', 'SUBDIR' => 'iblock/a1b', 'FILE_NAME' => 'laptop.jpg', 'ORIGINAL_NAME' => 'laptop.jpg', 'CONTENT_TYPE' => 'image/jpeg', 'FILE_SIZE' => 10],
            ['ID' => 2, 'MODULE_ID' => 'iblock', 'SUBDIR' => 'iblock/c2d', 'FILE_NAME' => 'laptop-side.jpg', 'ORIGINAL_NAME' => 'side.jpg', 'CONTENT_TYPE' => 'image/jpeg', 'FILE_SIZE' => 10],
            ['ID' => 3, 'MODULE_ID' => 'iblock', 'SUBDIR' => 'iblock/e3f', 'FILE_NAME' => 'manual.pdf', 'ORIGINAL_NAME' => 'manual.pdf', 'CONTENT_TYPE' => 'application/pdf', 'FILE_SIZE' => 10],
            ['ID' => 4, 'MODULE_ID' => 'iblock', 'SUBDIR' => 'iblock/g4h', 'FILE_NAME' => 'hardware.png', 'ORIGINAL_NAME' => 'hardware.png', 'CONTENT_TYPE' => 'image/png', 'FILE_SIZE' => 10],
            ['ID' => 5, 'MODULE_ID' => 'fileman', 'SUBDIR' => 'medialibrary/i5j', 'FILE_NAME' => 'logo.png', 'ORIGINAL_NAME' => 'logo.png', 'CONTENT_TYPE' => 'image/png', 'FILE_SIZE' => 10],
        ]);

        // Products.
        $products = [
            [1, 'Laptop Pro', 'laptop-pro', 2, 1, 'Powerful, light.', '<p>A <b>laptop</b>.</p>', 'html', 'laptop, pro'],
            [2, 'Laptop Air', 'laptop-air', 2, null, 'Even lighter.', 'Plain text.', 'text', null],
            [3, 'Office Suite', 'office-suite', 3, null, null, null, 'text', null],
        ];

        foreach ($products as [$id, $name, $code, $section, $picture, $preview, $detail, $detailType, $tags]) {
            $db->table('b_iblock_element')->insert([
                'ID' => $id, 'IBLOCK_ID' => 2, 'IBLOCK_SECTION_ID' => $section, 'NAME' => $name, 'CODE' => $code,
                'ACTIVE' => $id === 3 ? 'N' : 'Y', 'SORT' => 100 * $id, 'PREVIEW_PICTURE' => $picture,
                'PREVIEW_TEXT' => $preview, 'DETAIL_TEXT' => $detail, 'DETAIL_TEXT_TYPE' => $detailType, 'TAGS' => $tags,
                'DATE_CREATE' => '2024-02-0'.$id.' 09:00:00', 'TIMESTAMP_X' => '2024-02-0'.$id.' 09:30:00',
                'ACTIVE_FROM' => $id === 1 ? '2024-02-01 00:00:00' : null,
            ]);
            $db->table('b_iblock_section_element')->insert(['IBLOCK_SECTION_ID' => $section, 'IBLOCK_ELEMENT_ID' => $id]);
        }

        $db->table('b_iblock_element_prop_s2')->insert([
            [
                'IBLOCK_ELEMENT_ID' => 1, 'PROPERTY_10' => 'LP-001', 'PROPERTY_11' => 1999.5, 'PROPERTY_12' => '1',
                'PROPERTY_13' => '3', 'PROPERTY_16' => '3', 'PROPERTY_18' => '3',
                'PROPERTY_19' => serialize(['TEXT' => '<ul><li>16 GB</li></ul>', 'TYPE' => 'HTML']),
                'PROPERTY_20' => '2024-03-15', 'PROPERTY_21' => '2024-03-15 12:30:00',
                'PROPERTY_22' => '53.9,27.56', 'PROPERTY_23' => 'northwind',
            ],
            [
                'IBLOCK_ELEMENT_ID' => 2, 'PROPERTY_10' => 'LA-002', 'PROPERTY_11' => 1299, 'PROPERTY_12' => '2',
                'PROPERTY_13' => null, 'PROPERTY_16' => null, 'PROPERTY_18' => null, 'PROPERTY_19' => null,
                'PROPERTY_20' => null, 'PROPERTY_21' => null, 'PROPERTY_22' => null, 'PROPERTY_23' => 'contoso',
            ],
            [
                'IBLOCK_ELEMENT_ID' => 3, 'PROPERTY_10' => 'OS-003', 'PROPERTY_11' => 99, 'PROPERTY_12' => null,
                'PROPERTY_13' => null, 'PROPERTY_16' => null, 'PROPERTY_18' => null, 'PROPERTY_19' => null,
                'PROPERTY_20' => null, 'PROPERTY_21' => null, 'PROPERTY_22' => null, 'PROPERTY_23' => null,
            ],
        ]);

        $db->table('b_iblock_element_prop_m2')->insert([
            ['ID' => 1, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 14, 'VALUE' => 'Backlit keyboard'],
            ['ID' => 2, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 14, 'VALUE' => 'Fingerprint reader'],
            ['ID' => 3, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 15, 'VALUE' => '1'],
            ['ID' => 4, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 15, 'VALUE' => '2'],
            ['ID' => 5, 'IBLOCK_ELEMENT_ID' => 1, 'IBLOCK_PROPERTY_ID' => 17, 'VALUE' => '2'],
            ['ID' => 6, 'IBLOCK_ELEMENT_ID' => 2, 'IBLOCK_PROPERTY_ID' => 17, 'VALUE' => '1'],
        ]);

        // News.
        $db->table('b_iblock_element')->insert([
            ['ID' => 4, 'IBLOCK_ID' => 1, 'NAME' => 'We opened a store', 'CODE' => 'store-opened', 'ACTIVE' => 'Y', 'SORT' => 500, 'PREVIEW_TEXT' => 'Come and see.', 'DATE_CREATE' => '2024-04-01 08:00:00', 'TIMESTAMP_X' => '2024-04-01 08:00:00'],
            ['ID' => 5, 'IBLOCK_ID' => 1, 'NAME' => 'New laptops', 'CODE' => 'new-laptops', 'ACTIVE' => 'Y', 'SORT' => 500, 'PREVIEW_TEXT' => 'Two of them.', 'DATE_CREATE' => '2024-04-02 08:00:00', 'TIMESTAMP_X' => '2024-04-02 08:00:00'],
        ]);
        $db->table('b_iblock_element_property')->insert([
            ['ID' => 1, 'IBLOCK_PROPERTY_ID' => 1, 'IBLOCK_ELEMENT_ID' => 4, 'VALUE' => 'Press office'],
            ['ID' => 2, 'IBLOCK_PROPERTY_ID' => 2, 'IBLOCK_ELEMENT_ID' => 5, 'VALUE' => '1'],
            ['ID' => 3, 'IBLOCK_PROPERTY_ID' => 2, 'IBLOCK_ELEMENT_ID' => 5, 'VALUE' => '2'],
        ]);

        // The products form as an editor arranged it, and the list columns.
        $tabs = implode('--;--', [
            'edit1--#--Product--,--ACTIVE--#--Active--,--NAME--#--*Name--,--CODE--#--Code--,--PROPERTY_10--#--Article number--,--PROPERTY_11--#--Price--,--PROPERTY_23--#--Brand--,--PROPERTY_12--#--Colour--,--PROPERTY_13--#--Can be ordered--,--PROPERTY_14--#--Features--,--PROPERTY_20--#--Release date--,--PROPERTY_21--#--Checked at--,--PROPERTY_22--#--Store on the map--,--SORT--#--Sorting',
            'edit5--#--Preview--,--PREVIEW_PICTURE--#--Picture--,--PREVIEW_TEXT--#--Text',
            'edit6--#--Details--,--DETAIL_TEXT--#--Text--,--PROPERTY_19--#--Specifications--,--PROPERTY_15--#--Photos--,--PROPERTY_16--#--Manual--,--PROPERTY_17--#--Related products--,--PROPERTY_18--#--Extra section',
            'edit14--#--SEO--,--IPROPERTY_TEMPLATES_ELEMENT_META_TITLE--#--Title',
            'edit2--#--Categories--,--SECTIONS--#--Categories',
        ]).'--;--';

        $db->table('b_user_option')->insert([
            ['ID' => 1, 'USER_ID' => null, 'CATEGORY' => 'form', 'NAME' => 'form_element_2', 'COMMON' => 'Y', 'VALUE' => serialize(['tabs' => $tabs])],
            ['ID' => 2, 'USER_ID' => 1, 'CATEGORY' => 'list', 'NAME' => 'tbl_iblock_list_'.md5('catalog.2'), 'COMMON' => 'N', 'VALUE' => serialize([
                'columns' => 'NAME,PREVIEW_PICTURE,PROPERTY_11,PROPERTY_23,ACTIVE,SORT,ID',
                'by' => 'sort', 'order' => 'asc', 'page_size' => 20,
            ])],
        ]);

        // SEO templates: an element, a section; tags live on the element.
        $db->table('b_iblock_iproperty')->insert([
            ['ID' => 1, 'IBLOCK_ID' => 2, 'CODE' => 'ELEMENT_META_TITLE', 'ENTITY_TYPE' => 'E', 'ENTITY_ID' => 1, 'TEMPLATE' => 'Laptop Pro — buy online'],
            ['ID' => 2, 'IBLOCK_ID' => 2, 'CODE' => 'ELEMENT_META_DESCRIPTION', 'ENTITY_TYPE' => 'E', 'ENTITY_ID' => 1, 'TEMPLATE' => 'A light and powerful laptop.'],
            ['ID' => 3, 'IBLOCK_ID' => 2, 'CODE' => 'ELEMENT_PREVIEW_PICTURE_FILE_ALT', 'ENTITY_TYPE' => 'E', 'ENTITY_ID' => 1, 'TEMPLATE' => 'Laptop Pro, front'],
            ['ID' => 4, 'IBLOCK_ID' => 2, 'CODE' => 'SECTION_META_TITLE', 'ENTITY_TYPE' => 'S', 'ENTITY_ID' => 1, 'TEMPLATE' => 'Hardware'],
        ]);

        $db->table('b_medialib_collection')->insert([
            ['ID' => 1, 'NAME' => 'Logos', 'DESCRIPTION' => 'Brand marks', 'ACTIVE' => 'Y', 'PARENT_ID' => null],
        ]);
        $db->table('b_medialib_item')->insert([
            ['ID' => 1, 'NAME' => 'Company logo', 'SOURCE_ID' => 5, 'DESCRIPTION' => 'Square', 'DATE_CREATE' => '2024-01-01 00:00:00'],
        ]);
        $db->table('b_medialib_collection_item')->insert([
            ['COLLECTION_ID' => 1, 'ITEM_ID' => 1],
        ]);
    }

    /**
     * The files b_file points at, under {$public}/upload.
     */
    public static function writeUploads(string $public): void
    {
        foreach ([
            'iblock/a1b/laptop.jpg', 'iblock/c2d/laptop-side.jpg', 'iblock/e3f/manual.pdf',
            'iblock/g4h/hardware.png', 'medialibrary/i5j/logo.png',
        ] as $path) {
            $file = $public.'/upload/'.$path;
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, 'x');
        }
    }
}
