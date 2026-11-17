<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Generators;

use Atrium\Bitrix\Generators\ModelGenerator;
use Atrium\Bitrix\Mappers\FieldTypeMapper;
use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Atrium\Bitrix\Schema\HighloadBlock;
use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockPropertyEnum;
use Atrium\Bitrix\Schema\IBlockSection;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The model of an iblock written from blocks made by hand, with no Bitrix
 * connection: relations, fillable, casts, the form and the list columns the
 * generator works out on its own.
 */
final class ModelGeneratorTest extends TestCase
{
    private function model(IBlock $iblock): string
    {
        config(['atrium-bitrix.strip_prefixes' => [], 'atrium-bitrix.naming.mappings' => []]);
        $naming = new NamingConvention;

        return (new ModelGenerator($naming, new PropertyTypeMapper, new FieldTypeMapper($naming)))->generate($iblock);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private static function property(string $code, string $type = 'S', ?string $userType = null, bool $multiple = false, array $extra = [], ?string $link = null, ?HighloadBlock $directory = null): IBlockProperty
    {
        $property = IBlockProperty::fromArray([
            'ID' => 5, 'IBLOCK_ID' => 1, 'NAME' => 'Field '.$code, 'CODE' => $code, 'PROPERTY_TYPE' => $type,
            'USER_TYPE' => $userType, 'MULTIPLE' => $multiple ? 'Y' : 'N', ...$extra,
        ]);
        $property->linkIblockCode = $link;
        $property->directoryHlblock = $directory;

        return $property;
    }

    private static function block(IBlockProperty ...$properties): IBlock
    {
        $iblock = IBlock::fromArray(['ID' => 1, 'CODE' => 'products', 'NAME' => 'Products']);
        foreach ($properties as $property) {
            $iblock->addProperty($property);
        }

        return $iblock;
    }

    private static function withSections(IBlock $iblock): IBlock
    {
        $iblock->addSection(IBlockSection::fromArray(['ID' => 1, 'IBLOCK_ID' => 1, 'NAME' => 'Top']));

        return $iblock;
    }

    private static function brands(): HighloadBlock
    {
        return HighloadBlock::fromArray(['ID' => 1, 'NAME' => 'Brands', 'TABLE_NAME' => 'b_hlbd_brands']);
    }

    /**
     * @return iterable<string,array{IBlockProperty,?string}>
     */
    public static function relations(): iterable
    {
        $belongsToMany = static fn (string $related, string $key): string => "return \$this->belongsToMany({$related}::class, 'products_f', 'product_id', '{$key}')";

        yield 'one element' => [self::property('F', 'E', null, false, [], 'news'), "public function f(): BelongsTo\n    {\n        return \$this->belongsTo(News::class, 'f');"];
        yield 'one element of any iblock' => [self::property('F', 'E'), "return \$this->belongsTo(self::class, 'f');"];
        yield 'many elements' => [self::property('F', 'E', null, true, [], 'news'), "public function fs(): BelongsToMany\n    {\n        ".$belongsToMany('News', 'f_id')."\n            ->withPivot('sort')\n            ->orderBy('pivot_sort');"];
        yield 'many elements of any iblock' => [self::property('F', 'E', null, true), $belongsToMany('self', 'f_id')];
        yield 'one section' => [self::property('F', 'G', null, false, [], 'news'), "public function f(): BelongsTo\n    {\n        return \$this->belongsTo(NewsSection::class, 'f');"];
        yield 'many sections' => [self::property('F', 'G', null, true, [], 'news'), "public function fs(): BelongsToMany\n    {\n        ".$belongsToMany('NewsSection', 'section_id').';'];
        yield 'a section of no known iblock' => [self::property('F', 'G'), null];
        yield 'one value of a directory' => [self::property('F', 'S', 'directory', false, [], null, self::brands()), "return \$this->belongsTo(\\App\\Models\\References\\Brands::class, 'f');"];
        yield 'many values of a directory' => [self::property('F', 'S', 'directory', true, [], null, self::brands()), "public function fs(): BelongsToMany\n    {\n        return \$this->belongsToMany(\\App\\Models\\References\\Brands::class, 'products_f', 'product_id', 'f_id')\n            ->withPivot('sort')\n            ->orderBy('pivot_sort');"];
        yield 'a directory known by its table only' => [self::property('F', 'S', 'directory', false, ['USER_TYPE_SETTINGS' => serialize(['TABLE_NAME' => 'b_hlbd_colors'])]), "return \$this->belongsTo(\\App\\Models\\References\\BHlbdColors::class, 'f');"];
        yield 'a directory nobody knows' => [self::property('F', 'S', 'directory'), "return \$this->belongsTo(\\App\\Models\\References\\Reference::class, 'f');"];
        yield 'a plain string' => [self::property('F'), null];
    }

    #[DataProvider('relations')]
    public function test_a_link_property_becomes_a_relation(IBlockProperty $property, ?string $code): void
    {
        $model = $this->model(self::block($property));

        if ($code === null) {
            $this->assertStringNotContainsString('BelongsTo', substr($model, strpos($model, 'class Product')));
            $this->assertStringNotContainsString('public function f', $model);

            return;
        }

        $this->assertStringContainsString($code, $model);
    }

    public function test_an_iblock_with_sections_has_a_section_relation_and_a_path_through_it(): void
    {
        $model = $this->model(self::withSections(self::block()));

        $this->assertStringContainsString("public function section(): BelongsTo\n    {\n        return \$this->belongsTo(ProductSection::class);", $model);
        $this->assertStringContainsString("        'section_id',\n", $model);
        $this->assertStringContainsString("TreeSelectField::make('section_id')->label(__('atrium::fields.standard.section'))->model(ProductSection::class)->tab('params'),", $model);
        $this->assertStringContainsString('$sectionPath = $this->section->getPathSlug();', $model);
    }

    public function test_an_iblock_without_sections_has_none_of_that(): void
    {
        $model = $this->model(self::block());

        $this->assertStringNotContainsString('function section()', $model);
        $this->assertStringNotContainsString("'section_id'", $model);
        $this->assertStringNotContainsString('TreeSelectField::make', $model);
        $this->assertStringContainsString("public function getParentPath(): string\n    {\n        return static::\$urlPath;", $model);
    }

    public function test_the_base_of_the_model_comes_from_the_iblock(): void
    {
        $model = $this->model(self::block());

        $this->assertStringContainsString('class Product extends Model implements Administrable', $model);
        $this->assertStringContainsString("protected static string \$urlPath = 'products';", $model);
        $this->assertStringContainsString("protected \$table = 'products';", $model);
        $this->assertStringContainsString("return 'Products';", $model);
        $this->assertStringNotContainsString('HasAdminFiles', $model);
        $this->assertStringNotContainsString('getAdminCreateLabel', $model);
        $this->assertStringContainsString("protected \$casts = ['active' => 'boolean', 'active_from' => 'datetime', 'active_to' => 'datetime', 'sort' => 'integer'];", $model);
    }

    public function test_an_iblock_with_files_uses_the_files_trait(): void
    {
        $model = $this->model(self::block(self::property('PHOTO', 'F')));

        $this->assertStringContainsString('use HasAdminFiles;', $model);
        $this->assertStringContainsString('use Atrium\\Admin\\Traits\\HasAdminFiles;', $model);
    }

    public function test_properties_are_fillable_and_cast_by_their_type(): void
    {
        $model = $this->model(self::block(
            self::property('PRICE', 'N'),
            self::property('RELEASED', 'S', 'Date'),
            self::property('TAGS', 'S', null, true),
            self::property('SOME', 'E', null, true, [], 'news'),
            self::property('NAME', 'S'),
            self::property('SORT', 'N'),
        ));

        $this->assertStringContainsString("        'detail_picture',\n        'price',\n        'released',\n        'tags',\n    ];", $model);
        $this->assertStringContainsString("        'sort' => 'integer',\n        'price' => 'decimal:4',\n        'released' => 'date',\n        'tags' => 'array',\n    ];", $model);
    }

    /**
     * @return iterable<string,array{IBlockProperty,string}>
     */
    public static function fields(): iterable
    {
        $news = self::property('F', 'E', null, false, ['LINK_IBLOCK_ID' => 3], 'news');

        yield 'a required string' => [self::property('F', 'S', null, false, ['IS_REQUIRED' => 'Y']), "TextField::make('f')->label('Field F')->required()->tab('params'),"];
        yield 'a string with a hint' => [self::property('F', 'S', null, false, ['HINT' => 'Keep it short']), "TextField::make('f')->label('Field F')->hint('Keep it short')->tab('params'),"];
        yield 'a text' => [self::property('F', 'S', null, false, ['ROW_COUNT' => 4]), "TextareaField::make('f')->label('Field F')->tab('params'),"];
        yield 'a label with a quote' => [self::property('F', 'S', null, false, ['NAME' => "Tom's"]), "TextField::make('f')->label('Tom\\'s')->tab('params'),"];
        yield 'many strings' => [self::property('F', 'S', null, true), "RepeatableField::make('f')->label('Field F')->itemType('text')->tab('params'),"];
        yield 'many numbers' => [self::property('F', 'N', null, true), "RepeatableField::make('f')->label('Field F')->itemType('number')->tab('params'),"];
        yield 'an element' => [$news, "BelongsToField::make('f')->label('Field F')->model(News::class)->tab('params'),"];
        yield 'many elements' => [self::property('F', 'E', null, true, ['LINK_IBLOCK_ID' => 3], 'news'), "BelongsToManyField::make('fs')->label('Field F')->model(News::class)->tab('params'),"];
        yield 'a section' => [self::property('F', 'G', null, false, [], 'news'), "TreeSelectField::make('f')->label('Field F')->model(NewsSection::class)->tab('params'),"];
        yield 'a directory' => [self::property('F', 'S', 'directory', false, [], null, self::brands()), "BelongsToField::make('f')->label('Field F')->model(References\\Brands::class)->tab('params'),"];
        yield 'a number that points at a directory is not a relation' => [self::property('F', 'N', 'directory', false, [], null, self::brands()), "NumberField::make('f')->label('Field F')->tab('params'),"];
        yield 'a list' => [self::withEnums(self::property('F', 'L'), ['Red', 'Black']), "SelectField::make('f')->label('Field F')->options(array (0 => array ('value' => '100','label' => 'Red',),1 => array ('value' => '101','label' => 'Black',),))->tab('params'),"];
        yield 'a list of one value shown as a tick box' => [self::withEnums(self::property('F', 'L', null, false, ['LIST_TYPE' => 'C']), ['Yes']), "CheckboxField::make('f')->label('Field F')->checkedValue('100')->tab('params'),"];
        yield 'a list of two values shown as boxes stays a list' => [self::withEnums(self::property('F', 'L', null, false, ['LIST_TYPE' => 'C']), ['Yes', 'No']), "SelectField::make('f')->label('Field F')->options("];
        yield 'many values of a list' => [self::withEnums(self::property('F', 'L', null, true), ['Red']), "MultiSelectField::make('f')->label('Field F')->options(array (0 => array ('value' => '100','label' => 'Red',),))->tab('params'),"];
    }

    /**
     * @param  list<string>  $values
     */
    private static function withEnums(IBlockProperty $property, array $values): IBlockProperty
    {
        foreach ($values as $i => $value) {
            $property->addEnumValue(IBlockPropertyEnum::fromArray([
                'ID' => 100 + $i, 'PROPERTY_ID' => $property->id, 'VALUE' => $value, 'XML_ID' => null,
            ]));
        }

        return $property;
    }

    #[DataProvider('fields')]
    public function test_a_property_becomes_the_form_field_of_its_type(IBlockProperty $property, string $line): void
    {
        $this->assertStringContainsString("\n            {$line}", $this->model(self::block($property)));
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function tabsOfFields(): iterable
    {
        yield 'preview text' => ['PREVIEW_NOTE', 'preview'];
        yield 'detail text' => ['DETAIL_NOTE', 'detail'];
        yield 'photos' => ['PHOTOS', 'detail'];
        yield 'gallery' => ['GALLERY', 'detail'];
        yield 'meta' => ['META_ROBOTS', 'seo'];
        yield 'seo title' => ['SEO_TITLE', 'seo'];
        yield 'seo of its own' => ['SEO_LOCAL', 'seo'];
        yield 'anything else' => ['ARTICLE', 'params'];
        yield 'looks like a preview but is not' => ['PREVIEWS', 'params'];
        yield 'looks like a detail but is not' => ['DETAILS', 'params'];
    }

    #[DataProvider('tabsOfFields')]
    public function test_a_field_goes_to_the_tab_its_name_hints_at(string $code, string $tab): void
    {
        $model = $this->model(self::block(self::property($code)));

        $column = strtolower($code);
        $this->assertMatchesRegularExpression("/TextField::make\\('{$column}'\\)[^\n]*->tab\\('{$tab}'\\),/", $model);
        $this->assertStringContainsString("['name' => '{$tab}', 'label' =>", $model);
    }

    public function test_without_a_saved_form_there_are_four_tabs_and_the_fields_are_grouped_under_them(): void
    {
        $model = $this->model(self::block(self::property('META_ROBOTS'), self::property('ARTICLE')));

        $this->assertStringContainsString(
            "return [\n            ['name' => 'params', 'label' => __('atrium::fields.tabs.main')],\n"
            ."            ['name' => 'preview', 'label' => __('atrium::fields.tabs.preview')],\n"
            ."            ['name' => 'detail', 'label' => __('atrium::fields.tabs.detail')],\n"
            ."            ['name' => 'seo', 'label' => __('atrium::fields.tabs.seo')],\n"
            .'        ];',
            $model,
        );
        $this->assertStringNotContainsString("'name' => 'sections'", $model);

        foreach (['Parameters', 'Preview', 'Details', 'SEO'] as $label) {
            $this->assertStringContainsString("// {$label}\n", $model);
        }
        // groups are kept apart by a blank line, and the first one has none before it
        $this->assertStringContainsString("return [\n            // Parameters\n", $model);
        $this->assertStringContainsString("->tab('params'),\n\n            // Preview\n", $model);
    }

    public function test_a_property_named_like_the_base_name_lends_it_its_label(): void
    {
        $model = $this->model(self::block(self::property('NAME', 'S', null, false, ['NAME' => 'Your name'])));

        $this->assertStringContainsString("TextField::make('name')->label('Your name')->required()->tab('params'),", $model);
        $this->assertStringContainsString("TextColumn::make('name')->label('Your name')->sortable()->searchable(),", $model);
        $this->assertSame(1, substr_count($model, "TextField::make('name')"));
    }

    public function test_a_property_named_like_the_base_name_without_a_label_leaves_the_standard_one(): void
    {
        $property = self::property('NAME');
        $property->name = "  \t";

        $model = $this->model(self::block($property));

        $this->assertStringContainsString("TextField::make('name')->label(__('atrium::fields.standard.name'))->required()", $model);
    }

    public function test_the_first_property_with_a_label_names_the_base_field(): void
    {
        $other = self::property('OTHER', 'S', null, false, ['NAME' => 'Other']);
        $blank = self::property('NAME');
        $blank->name = '';
        $named = self::property('NAME', 'S', null, false, ['NAME' => 'Full name']);

        $model = $this->model(self::block($other, $blank, $named));

        $this->assertStringContainsString("TextField::make('name')->label('Full name')->required()", $model);
    }

    public function test_the_list_shows_the_base_columns_and_the_first_properties(): void
    {
        $model = $this->model(self::block(
            self::property('SORT'),
            self::property('A'),
            self::property('A'),
            self::property('B', 'S', 'Date'),
            self::property('C', 'F'),
            self::property('D', 'S', null, true),
            self::property('E'),
        ));

        $this->assertStringContainsString(
            "return [\n"
            ."            TextColumn::make('id')->label(__('atrium::fields.standard.id'))->sortable(),\n"
            ."            TextColumn::make('name')->label(__('atrium::fields.standard.name'))->sortable()->searchable(),\n"
            ."            BooleanColumn::make('active')->label(__('atrium::fields.standard.active'))->sortable(),\n"
            ."            TextColumn::make('a')->label('Field A')->sortable(),\n"
            ."            DateColumn::make('b')->label('Field B')->sortable(),\n"
            ."            ImageColumn::make('c')->label('Field C'),\n"
            .'        ];',
            $model,
        );
    }

    public function test_the_list_stops_at_six_columns(): void
    {
        $model = $this->model(self::block(...array_map(
            static fn (string $code): IBlockProperty => self::property($code),
            ['A', 'B', 'C', 'D', 'E'],
        )));

        $this->assertStringContainsString("TextColumn::make('c')", $model);
        $this->assertStringNotContainsString("TextColumn::make('d')", $model);
    }

    public function test_columns_added_to_the_list_by_the_project_come_after_the_name(): void
    {
        config([
            'atrium-bitrix.extra_list_columns' => ['products' => ['created_at', 'preview_picture', 'active_from', 'preview_text', 'mystery', 'id']],
            'atrium-bitrix.hidden_list_columns' => ['products' => ['active']],
        ]);

        $model = $this->model(self::block());

        $this->assertStringContainsString(
            "return [\n"
            ."            TextColumn::make('id')->label(__('atrium::fields.standard.id'))->sortable(),\n"
            ."            TextColumn::make('name')->label(__('atrium::fields.standard.name'))->sortable()->searchable(),\n"
            ."            DateColumn::make('created_at')->label(__('atrium::fields.standard.created_at'))->sortable(),\n"
            ."            ImageColumn::make('preview_picture')->label(__('atrium::fields.standard.preview_picture')),\n"
            ."            DateColumn::make('active_from')->label(__('atrium::fields.standard.active_from'))->sortable(),\n"
            ."            TextColumn::make('preview_text')->label(__('atrium::fields.standard.preview_text'))->sortable(),\n"
            ."            TextColumn::make('mystery')->label('mystery')->sortable(),\n"
            .'        ];',
            $model,
        );
    }

    public function test_a_field_the_project_added_is_fillable_cast_shown_and_listed(): void
    {
        config([
            'atrium-bitrix.extra_list_columns' => ['products' => ['featured']],
            'atrium-bitrix.extra_fields' => ['products' => [
                'featured' => [
                    'cast' => 'boolean',
                    'field' => "CheckboxField::make('featured')->label('Featured')",
                    'column' => "BooleanColumn::make('featured')->label('Featured')",
                    'after' => 'sort',
                ],
                'secret' => ['fillable' => false, 'cast' => 'integer'],
                'closing' => ['field' => "TextField::make('closing')", 'after' => 'no_such_field'],
                'loose' => ['field' => "TextField::make('loose')"],
            ]],
        ]);

        $model = $this->model(self::block());

        $this->assertStringContainsString("        'detail_picture',\n        'featured',\n        'closing',\n        'loose',\n    ];", $model);
        $this->assertStringNotContainsString("'secret',", $model);
        $this->assertStringContainsString("'sort' => 'integer',\n        'featured' => 'boolean',\n        'secret' => 'integer',\n", $model);
        $this->assertStringContainsString("NumberField::make('sort')->label(__('atrium::fields.standard.sort'))->default(500)->tab('params'),\n            CheckboxField::make('featured')->label('Featured')->tab('params'),\n", $model);
        $this->assertStringContainsString("->tab('detail'),\n\n            // Parameters\n            TextField::make('closing')->tab('params'),\n            TextField::make('loose')->tab('params'),\n        ];", $model);
        $this->assertStringContainsString("            BooleanColumn::make('featured')->label('Featured'),\n", $model);
    }

    public function test_a_field_the_project_put_on_a_tab_adds_the_tab_in_its_place(): void
    {
        config(['atrium-bitrix.extra_fields' => ['products' => [
            'extra' => ['field' => "TextField::make('extra')", 'tab' => 'sections'],
        ]]]);

        $model = $this->model(self::block());

        $this->assertStringContainsString(
            "['name' => 'seo', 'label' => __('atrium::fields.tabs.seo')],\n            ['name' => 'sections', 'label' => __('atrium::fields.tabs.sections')],\n        ];",
            $model,
        );
        $this->assertStringContainsString("// Sections\n            TextField::make('extra')->tab('sections'),", $model);
    }
}
