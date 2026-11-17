<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Support;

use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Support\BitrixFormLayout;
use Atrium\Bitrix\Support\BitrixIblockMessages;
use Atrium\Bitrix\Support\BitrixListLayout;
use Atrium\Bitrix\Support\BitrixSites;
use Atrium\Bitrix\Support\GeneratedPaths;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Support\ProjectFields;
use Atrium\Bitrix\Support\SectionUserFields;
use Atrium\Bitrix\Tests\Fixtures\SyntheticBitrix;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the migrator reads from Bitrix besides the iblocks: saved form and
 * list layouts, captions, section fields, sites; and what the project adds.
 */
final class LayoutsTest extends SyntheticTestCase
{
    /** @return array<int,string> */
    private function propertyCodes(): array
    {
        return array_map(static fn (array $p): string => $p[0], SyntheticBitrix::PRODUCT_PROPERTIES);
    }

    private function saveForm(string $tabs, int $iblock = 2): void
    {
        $this->bitrix()->table('b_user_option')->where('CATEGORY', 'form')->delete();
        $this->bitrix()->table('b_user_option')->insert([
            'ID' => 50, 'CATEGORY' => 'form', 'NAME' => 'form_element_'.$iblock, 'COMMON' => 'Y', 'VALUE' => serialize(['tabs' => $tabs]),
        ]);
    }

    private function form(): ?BitrixFormLayout
    {
        return BitrixFormLayout::load($this->bitrix(), 2, $this->propertyCodes(), new NamingConvention);
    }

    public function test_the_saved_form_gives_tabs_order_and_places(): void
    {
        $form = $this->form();

        $this->assertNotNull($form);
        $this->assertSame([
            ['name' => 'params', 'label' => 'Product'],
            ['name' => 'preview', 'label' => 'Preview'],
            ['name' => 'detail', 'label' => 'Details'],
            ['name' => 'seo', 'label' => 'SEO'],
            ['name' => 'sections', 'label' => 'Categories'],
        ], $form->tabs());
        $this->assertSame('params', $form->tabFor('price'));
        $this->assertSame('detail', $form->tabFor('specs'));
        $this->assertSame('sections', $form->tabFor('section_id'));
        $this->assertNull($form->tabFor('meta_title'), 'the SEO templates are the admin\'s own');
        $this->assertNull($form->tabFor('nothing'));
        $this->assertSame(0, $form->positionOf('active'));
        $this->assertSame(1, $form->positionOf('name'));
        $this->assertSame(3, $form->positionOf('article'));
        $this->assertSame(PHP_INT_MAX, $form->positionOf('nothing'));
        $this->assertTrue($form->hasTab('seo'));
        $this->assertFalse($form->hasTab('edit1'));
    }

    public function test_service_rows_unknown_tabs_and_deleted_properties_are_skipped(): void
    {
        $this->saveForm(implode('--;--', [
            'edit1--#--Main--,--XML_ID--#--Ext--,--IBLOCK_ELEMENT_PROP_VALUE--#----,--SEO_ADDITIONAL--#--x--,--TAGS--#--Tags--,--NAME--#--Name--,--PROPERTY_999--#--Gone--,--PROPERTY_11--#--Price--,--NAME--#--Again--,----#--Blank--,--WEIRD--#--?',
            'cedit1--#--Custom tab--,--SORT--#--Sort',
            'edit5--#--   --,--PREVIEW_TEXT--#--Text',
            'edit6--#--Details--,--DETAIL_TEXT',
        ]));

        $form = $this->form();

        $this->assertNotNull($form);
        $this->assertSame([['name' => 'params', 'label' => 'Main'], ['name' => 'detail', 'label' => 'Details']], $form->tabs());
        $this->assertSame(0, $form->positionOf('name'));
        $this->assertSame(1, $form->positionOf('price'));
        $this->assertSame(2, $form->positionOf('detail_text'));
        $this->assertSame('params', $form->tabFor('name'), 'the first place of a field wins');
        $this->assertNull($form->tabFor('sort'), 'tabs of our own are not Bitrix\'s');
        $this->assertNull($form->tabFor('preview_text'), 'a tab without a caption is dropped');
    }

    /**
     * @return array<string,array{0:?string}>
     */
    public static function brokenForms(): array
    {
        return [
            'no saved form' => [null],
            'empty value' => [''],
            'not serialized' => ['tabs=edit1'],
            'no tabs key' => [serialize(['columns' => 'x'])],
            'tabs not a string' => [serialize(['tabs' => ['edit1']])],
            'only unknown tabs' => [serialize(['tabs' => 'cedit1--#--Mine--,--NAME--#--Name'])],
        ];
    }

    #[DataProvider('brokenForms')]
    public function test_a_missing_or_broken_form_is_no_layout(?string $value): void
    {
        $this->bitrix()->table('b_user_option')->where('CATEGORY', 'form')->delete();

        if ($value !== null) {
            $this->bitrix()->table('b_user_option')->insert(['ID' => 50, 'CATEGORY' => 'form', 'NAME' => 'form_element_2', 'VALUE' => $value]);
        }

        $this->assertNull($this->form());
    }

    public function test_the_saved_list_gives_columns_sort_and_page_size(): void
    {
        $list = BitrixListLayout::load($this->bitrix(), 'catalog', 2, $this->propertyCodes(), new NamingConvention);

        $this->assertFalse($list->isEmpty());
        $this->assertSame([
            ['name' => 'name', 'label' => null, 'property' => false],
            ['name' => 'preview_picture', 'label' => null, 'property' => false],
            ['name' => 'price', 'label' => null, 'property' => true],
            ['name' => 'brand', 'label' => null, 'property' => true],
            ['name' => 'active', 'label' => null, 'property' => false],
            ['name' => 'sort', 'label' => null, 'property' => false],
            ['name' => 'id', 'label' => null, 'property' => false],
        ], $list->columns());
        $this->assertSame('sort', $list->sortBy());
        $this->assertSame('asc', $list->sortOrder());
        $this->assertSame(20, $list->pageSize());
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:list<string>,2:?string,3:string,4:int}>
     */
    public static function lists(): array
    {
        return [
            'sort by a base column, descending' => [['columns' => 'ID', 'by' => 'timestamp_x', 'order' => 'DESC', 'page_size' => '50'], ['id'], 'updated_at', 'desc', 50],
            'sort by something else' => [['columns' => 'NAME', 'by' => 'Custom_Field', 'order' => 'sideways'], ['name'], 'custom_field', 'asc', 0],
            'blanks and deleted properties dropped' => [['columns' => ' NAME , ,PROPERTY_999,UNKNOWN,PROPERTY_10', 'by' => '  '], ['name', 'article'], null, 'asc', 0],
            'no columns' => [['by' => 'id'], [], 'id', 'asc', 0],
        ];
    }

    /**
     * @param  array<string,mixed>  $saved
     * @param  list<string>  $columns
     */
    #[DataProvider('lists')]
    public function test_a_saved_list_is_read_however_it_was_saved(array $saved, array $columns, ?string $by, string $order, int $pageSize): void
    {
        $this->bitrix()->table('b_user_option')->where('CATEGORY', 'list')->update(['VALUE' => serialize($saved)]);

        $list = BitrixListLayout::load($this->bitrix(), 'catalog', 2, $this->propertyCodes(), new NamingConvention);

        $this->assertSame($columns, array_column($list->columns(), 'name'));
        $this->assertSame($by, $list->sortBy());
        $this->assertSame($order, $list->sortOrder());
        $this->assertSame($pageSize, $list->pageSize());
    }

    /**
     * @return array<string,array{0:string,1:?string}>
     */
    public static function missingLists(): array
    {
        return [
            'another iblock type' => ['content', null],
            'empty value' => ['catalog', ''],
            'not serialized' => ['catalog', 'columns=ID'],
        ];
    }

    #[DataProvider('missingLists')]
    public function test_a_missing_or_broken_list_is_empty(string $type, ?string $value): void
    {
        if ($value !== null) {
            $this->bitrix()->table('b_user_option')->where('CATEGORY', 'list')->update(['VALUE' => $value]);
        }

        $list = BitrixListLayout::load($this->bitrix(), $type, 2, $this->propertyCodes(), new NamingConvention);

        $this->assertTrue($list->isEmpty());
        $this->assertSame([], $list->columns());
        $this->assertNull($list->sortBy());
        $this->assertSame('asc', $list->sortOrder());
        $this->assertSame(0, $list->pageSize());
    }

    public function test_iblock_captions_are_read_and_blanks_dropped(): void
    {
        $this->bitrix()->table('b_iblock_messages')->insert(['IBLOCK_ID' => 1, 'MESSAGE_ID' => 'ELEMENT_ADD', 'MESSAGE_TEXT' => '   ']);

        $products = BitrixIblockMessages::load($this->bitrix(), 2);
        $this->assertSame('Add a product', $products->elementAdd());
        $this->assertSame('Product', $products->elementName());

        $news = BitrixIblockMessages::load($this->bitrix(), 1);
        $this->assertNull($news->elementAdd());
        $this->assertNull($news->elementName());

        $this->assertNull(BitrixIblockMessages::empty()->elementAdd());
        $this->assertNull(BitrixIblockMessages::empty()->elementName());
    }

    public function test_section_fields_are_read_with_labels_and_lists(): void
    {
        $fields = SectionUserFields::load($this->bitrix(), 2, new NamingConvention);

        $this->assertFalse($fields->isEmpty());
        $this->assertSame([
            ['name' => 'UF_NOTE', 'column' => 'note', 'type' => 'string', 'multiple' => false, 'label' => 'Note for editors', 'enum' => []],
            ['name' => 'UF_VIEW', 'column' => 'view', 'type' => 'enumeration', 'multiple' => false, 'label' => 'UF_VIEW', 'enum' => [
                1 => ['code' => 'tiles', 'label' => 'Tiles'],
                2 => ['code' => 'list', 'label' => 'List'],
            ]],
        ], $fields->all());

        // A list value is stored by its code, an unknown one as null; blanks are skipped.
        $this->bitrix()->table('b_uts_iblock_2_section')->insert([
            ['VALUE_ID' => 2, 'UF_NOTE' => '', 'UF_VIEW' => 9],
            ['VALUE_ID' => 0, 'UF_NOTE' => 'orphan', 'UF_VIEW' => 1],
            ['VALUE_ID' => 3, 'UF_NOTE' => null, 'UF_VIEW' => null],
        ]);
        $this->assertSame([1 => ['note' => 'Keep it short', 'view' => 'list'], 2 => ['view' => null]], $fields->values($this->bitrix(), 2));
    }

    public function test_a_list_option_without_code_is_keyed_by_its_id_and_multiple_is_read(): void
    {
        $this->bitrix()->table('b_user_field')->where('ID', 2)->update(['MULTIPLE' => 'Y']);
        $this->bitrix()->table('b_user_field_enum')->where('ID', 2)->update(['XML_ID' => '']);

        $view = SectionUserFields::load($this->bitrix(), 2, new NamingConvention)->all()[1];

        $this->assertTrue($view['multiple']);
        $this->assertSame(['code' => '2', 'label' => 'List'], $view['enum'][2]);
    }

    public function test_no_section_fields_means_no_values(): void
    {
        $none = SectionUserFields::load($this->bitrix(), 1, new NamingConvention);

        $this->assertTrue($none->isEmpty());
        $this->assertSame([], $none->all());
        $this->assertSame([], $none->values($this->bitrix(), 2));
        $this->assertTrue(SectionUserFields::empty()->isEmpty());
    }

    public function test_section_fields_without_their_values_table_have_no_values(): void
    {
        $this->bitrix()->getSchemaBuilder()->drop('b_uts_iblock_2_section');

        $fields = SectionUserFields::load($this->bitrix(), 2, new NamingConvention);

        $this->assertFalse($fields->isEmpty());
        $this->assertSame([], $fields->values($this->bitrix(), 2));
    }

    public function test_without_a_sites_table_there_are_no_sites(): void
    {
        $this->assertCount(3, (new BitrixSites(new BitrixConnection))->all());

        $this->bitrix()->getSchemaBuilder()->drop('b_lang');

        $this->assertSame([], (new BitrixSites(new BitrixConnection))->all());
    }

    public function test_generated_classes_go_where_configured_or_where_psr4_puts_them(): void
    {
        config(['atrium-bitrix.model_path' => '/tmp/models/']);
        $this->assertSame('/tmp/models', GeneratedPaths::directory('model_path', 'App\\Models'));

        config(['atrium-bitrix.model_path' => '']);
        $this->assertSame(app_path('Models'), GeneratedPaths::directory('model_path', 'App\\Models'));
        $this->assertSame(app_path('Models/References'), GeneratedPaths::directory('model_path', '\\App\\Models\\References\\'));

        config(['atrium-bitrix.model_path' => null]);
        $this->assertSame(base_path('Domain/Catalog'), GeneratedPaths::directory('model_path', 'Domain\\Catalog'));
        $this->assertSame(base_path('Application/Models'), GeneratedPaths::directory('model_path', 'Application\\Models'));

        config(['atrium-bitrix.model_path' => ['not', 'a', 'path']]);
        $this->assertSame(app_path('Models'), GeneratedPaths::directory('model_path', 'App\\Models'));
    }

    public function test_project_fields_are_read_from_the_config(): void
    {
        config(['atrium-bitrix.extra_fields' => [
            'news' => [
                'show_on_home' => ['cast' => 'boolean', 'field' => "CheckboxField::make('show_on_home')", 'tab' => 'preview', 'after' => 'active', 'column' => "BooleanColumn::make('show_on_home')"],
                'internal' => ['fillable' => false, 'cast' => ''],
                'rating' => ['field' => "NumberField::make('rating')"],
            ],
            'broken' => 'not an array',
        ]]);

        $this->assertSame(['show_on_home', 'rating'], ProjectFields::fillableFor('news'));
        $this->assertSame(['show_on_home' => 'boolean'], ProjectFields::castsFor('news'));
        $this->assertSame("BooleanColumn::make('show_on_home')", ProjectFields::listColumnLineFor('news', 'show_on_home'));
        $this->assertNull(ProjectFields::listColumnLineFor('news', 'rating'));
        $this->assertNull(ProjectFields::listColumnLineFor('news', 'nothing'));
        $this->assertSame([
            ['name' => 'show_on_home', 'line' => "CheckboxField::make('show_on_home')", 'tab' => 'preview', 'after' => 'active'],
            ['name' => 'rating', 'line' => "NumberField::make('rating')", 'tab' => 'params', 'after' => null],
        ], ProjectFields::adminFieldsFor('news'));

        foreach (['broken', 'missing'] as $table) {
            $this->assertSame([], ProjectFields::forTable($table));
            $this->assertSame([], ProjectFields::fillableFor($table));
            $this->assertSame([], ProjectFields::castsFor($table));
            $this->assertSame([], ProjectFields::adminFieldsFor($table));
        }

        config(['atrium-bitrix.extra_fields' => 'not an array']);
        $this->assertSame([], ProjectFields::forTable('news'));
    }

    public function test_link_targets_from_the_config(): void
    {
        config(['atrium-bitrix.element_link_targets' => [
            'news' => ['LINK_GOODS' => 'catalog', 'EMPTY' => '', 'NUMBER' => 5],
            'broken' => 'catalog',
        ]]);

        $this->assertSame('catalog', ProjectFields::elementLinkTargetFor('news', 'LINK_GOODS'));
        $this->assertNull(ProjectFields::elementLinkTargetFor('news', 'EMPTY'));
        $this->assertNull(ProjectFields::elementLinkTargetFor('news', 'NUMBER'));
        $this->assertNull(ProjectFields::elementLinkTargetFor('news', 'MISSING'));
        $this->assertNull(ProjectFields::elementLinkTargetFor('broken', 'LINK_GOODS'));
        $this->assertNull(ProjectFields::elementLinkTargetFor('other', 'LINK_GOODS'));

        config(['atrium-bitrix.element_link_targets' => null]);
        $this->assertNull(ProjectFields::elementLinkTargetFor('news', 'LINK_GOODS'));
    }

    /**
     * @return array<string,array{0:list<string>,1:list<string>,2:list<string>,3:list<string>}>
     */
    public static function listOverrides(): array
    {
        return [
            'nothing to change' => [['id', 'name', 'active'], [], [], ['id', 'name', 'active']],
            'hidden dropped' => [['id', 'name', 'active'], ['active', 'missing'], [], ['id', 'name']],
            'extra right after the name' => [['id', 'name', 'active'], [], ['phone', 'email'], ['id', 'name', 'phone', 'email', 'active']],
            'extra at the end without a name' => [['id', 'active'], [], ['phone'], ['id', 'active', 'phone']],
            'extra already there not repeated' => [['id', 'name', 'phone'], [], ['phone', 'email'], ['id', 'name', 'email', 'phone']],
            'hidden and added back' => [['id', 'name', 'active'], ['active'], ['active'], ['id', 'name', 'active']],
            'everything hidden' => [['id'], ['id'], [], []],
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $hidden
     * @param  list<string>  $extra
     * @param  list<string>  $expected
     */
    #[DataProvider('listOverrides')]
    public function test_list_overrides_hide_and_add_columns(array $columns, array $hidden, array $extra, array $expected): void
    {
        config([
            'atrium-bitrix.hidden_list_columns' => ['news' => $hidden, 'other' => ['id']],
            'atrium-bitrix.extra_list_columns' => ['news' => $extra, 'other' => ['x']],
        ]);

        $this->assertSame($expected, ProjectFields::applyListOverrides($columns, 'news'));
    }

    public function test_list_overrides_that_are_not_lists_change_nothing(): void
    {
        config(['atrium-bitrix.hidden_list_columns' => ['news' => 'id'], 'atrium-bitrix.extra_list_columns' => 'x']);
        $this->assertSame(['id', 'name'], ProjectFields::applyListOverrides(['id', 'name'], 'news'));
        $this->assertSame([], ProjectFields::hiddenListColumnsFor('news'));
        $this->assertSame([], ProjectFields::extraListColumnsFor('news'));

        config(['atrium-bitrix.hidden_list_columns' => ['news' => [1 => 7]], 'atrium-bitrix.extra_list_columns' => ['news' => [3 => 9]]]);
        $this->assertSame(['7'], ProjectFields::hiddenListColumnsFor('news'));
        $this->assertSame(['9'], ProjectFields::extraListColumnsFor('news'));
    }
}
