<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Generators;

use Atrium\Bitrix\Analyzers\IBlockAnalyzer;
use Atrium\Bitrix\Generators\ModelGenerator;
use Atrium\Bitrix\Mappers\FieldTypeMapper;
use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Tests\SyntheticTestCase;

/**
 * The model written with the form and the list as an editor saved them in
 * the made-up Bitrix database.
 */
final class ModelGeneratorLayoutTest extends SyntheticTestCase
{
    private function products(): string
    {
        $naming = new NamingConvention;
        $iblock = (new IBlockAnalyzer(new BitrixConnection))->analyze(2);
        $this->assertNotNull($iblock);

        return (new ModelGenerator($naming, new PropertyTypeMapper, new FieldTypeMapper($naming), $this->bitrix()))->generate($iblock);
    }

    public function test_a_column_the_project_added_is_listed_among_the_columns_of_the_saved_list(): void
    {
        config([
            'atrium-bitrix.extra_list_columns' => ['products' => ['featured']],
            'atrium-bitrix.extra_fields' => ['products' => ['featured' => ['column' => "BooleanColumn::make('featured')->label('Featured')"]]],
        ]);

        $this->assertStringContainsString(
            "            TextColumn::make('name')->label(__('atrium::fields.standard.name'))->sortable()->searchable(),\n"
            ."            BooleanColumn::make('featured')->label('Featured'),\n"
            ."            ImageColumn::make('preview_picture')",
            $this->products(),
        );
    }

    public function test_a_field_missing_from_the_saved_form_goes_to_the_first_tab_when_its_own_is_not_there(): void
    {
        $this->bitrix()->table('b_user_option')->where('NAME', 'form_element_2')->update(['VALUE' => serialize([
            'tabs' => 'edit1--#--Product--,--ACTIVE--#--Active--,--NAME--#--*Name--,--PROPERTY_10--#--Article number--;--',
        ])]);

        $model = $this->products();

        $this->assertStringContainsString("['name' => 'params', 'label' => 'Product'],\n        ];", $model);
        $this->assertStringContainsString("ImageField::make('preview_picture')->label(__('atrium::fields.standard.preview_picture'))->tab('params'),", $model);
        $this->assertStringContainsString("ContentField::make('detail_text')->label(__('atrium::fields.standard.detail_text'))->tab('params'),", $model);
        $this->assertStringNotContainsString("->tab('preview')", $model);
        $this->assertStringNotContainsString("->tab('detail')", $model);
        // what the form lists comes first, in its order; what it does not, after
        $this->assertLessThan(
            strpos($model, "TextField::make('name')"),
            strpos($model, "CheckboxField::make('active')"),
        );
        $this->assertLessThan(
            strpos($model, "ImageField::make('preview_picture')"),
            strpos($model, "TextField::make('article')"),
        );
    }

    public function test_the_tabs_of_the_saved_form_keep_their_order_and_the_ones_the_project_needs_join_them(): void
    {
        config(['atrium-bitrix.extra_fields' => ['products' => [
            'extra' => ['field' => "TextField::make('extra')", 'tab' => 'seo', 'after' => 'sort'],
        ]]]);
        $this->bitrix()->table('b_user_option')->where('NAME', 'form_element_2')->update(['VALUE' => serialize([
            'tabs' => 'edit1--#--Product--,--NAME--#--*Name--;--edit2--#--Categories--,--SECTIONS--#--Categories--;--',
        ])]);

        $model = $this->products();

        $this->assertStringContainsString(
            "            ['name' => 'params', 'label' => 'Product'],\n"
            ."            ['name' => 'seo', 'label' => __('atrium::fields.tabs.seo')],\n"
            ."            ['name' => 'sections', 'label' => 'Categories'],\n"
            .'        ];',
            $model,
        );
        $this->assertStringContainsString('// Categories', $model);
        $this->assertStringContainsString("// SEO\n            TextField::make('extra')->tab('seo'),", $model);
    }

    public function test_a_column_the_saved_list_names_twice_is_listed_once(): void
    {
        $this->bitrix()->table('b_user_option')->where('CATEGORY', 'list')->update(['VALUE' => serialize([
            'columns' => 'NAME,DATE_ACTIVE_FROM,PROPERTY_11,DATE_ACTIVE_FROM,PROPERTY_23',
        ])]);

        $model = $this->products();

        $this->assertSame(1, substr_count($model, "DateColumn::make('active_from')"));
        $this->assertStringContainsString("TextColumn::make('name')->label(__('atrium::fields.standard.name'))->sortable()->searchable(),\n            DateColumn::make('active_from')->label(__('atrium::fields.standard.active_from'))->sortable(),\n            TextColumn::make('price')->label('Price')->sortable(),\n            TextColumn::make('brand')->label('Brand')->sortable(),\n        ];", $model);
    }
}
