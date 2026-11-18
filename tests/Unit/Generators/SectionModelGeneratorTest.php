<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Generators;

use Atrium\Bitrix\Generators\SectionModelGenerator;
use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Tests\SyntheticTestCase;

/**
 * The section model: its form, with the user fields Bitrix keeps for sections
 * and the fields the project added.
 */
final class SectionModelGeneratorTest extends SyntheticTestCase
{
    private static function products(): IBlock
    {
        return IBlock::fromArray(['ID' => 2, 'CODE' => 'demo_products', 'NAME' => 'Products']);
    }

    public function test_the_user_fields_of_a_section_are_form_fields(): void
    {
        $model = (new SectionModelGenerator(new NamingConvention, $this->bitrix()))->generate(self::products());

        $this->assertStringContainsString('class ProductSection extends Model implements Administrable', $model);
        $this->assertStringContainsString("TextField::make('note')->label('Note for editors')->tab('params'),", $model);
        $this->assertStringContainsString("SelectField::make('view')->label('UF_VIEW')->options([['value' => 'tiles', 'label' => 'Tiles'], ['value' => 'list', 'label' => 'List']])->tab('params'),", $model);
    }

    public function test_without_a_bitrix_connection_a_section_has_only_the_base_fields(): void
    {
        $model = (new SectionModelGenerator(new NamingConvention))->generate(self::products());

        $this->assertStringContainsString('class ProductSection extends Model implements Administrable', $model);
        $this->assertStringContainsString("ImageField::make('detail_picture')->label(__('atrium::fields.standard.detail_picture'))->tab('content'),\n        ];", $model);
        $this->assertStringNotContainsString("'note'", $model);
    }

    public function test_a_field_the_project_added_goes_where_its_after_key_says(): void
    {
        config(['atrium-bitrix.extra_fields' => ['product_sections' => [
            'subtitle' => ['field' => "TextField::make('subtitle')", 'after' => 'name'],
            'banner' => ['field' => "ImageField::make('banner')", 'tab' => 'content', 'after' => 'no_such_field'],
            'footer' => ['field' => "TextField::make('footer')"],
        ]]]);

        $model = (new SectionModelGenerator(new NamingConvention))->generate(self::products());

        $this->assertStringContainsString("TextField::make('name')->label(__('atrium::fields.standard.name'))->required()->tab('params'),\n            TextField::make('subtitle')->tab('params'),\n            SlugField::make('code')", $model);
        $this->assertStringContainsString("->tab('content'),\n            ImageField::make('banner')->tab('content'),\n\n            // Parameters\n            TextField::make('footer')->tab('params'),\n        ];", $model);
    }

    public function test_the_whole_section_model_is_written_as_the_golden_file_says(): void
    {
        $this->bitrix()->table('b_user_field_lang')->where('USER_FIELD_ID', 1)->update(['EDIT_FORM_LABEL' => "Editor's note"]);
        $this->bitrix()->table('b_user_field_enum')->where('ID', 1)->update(['VALUE' => "Editor's tiles"]);
        config(['atrium-bitrix.extra_fields' => ['product_sections' => [
            'note' => ['field' => "CheckboxField::make('note')", 'tab' => 'content'],
            'after_note' => ['field' => "TextField::make('after_note')", 'after' => 'note', 'cast' => 'string'],
        ]]]);

        $model = (new SectionModelGenerator(new NamingConvention, $this->bitrix()))->generate(self::products());

        $this->assertStringEqualsFile(__DIR__.'/../../Fixtures/golden/ProductSection.txt', $model);
    }
}
