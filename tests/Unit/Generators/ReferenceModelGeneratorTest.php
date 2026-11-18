<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Generators;

use Atrium\Bitrix\Generators\ReferenceModelGenerator;
use Atrium\Bitrix\Mappers\FieldTypeMapper;
use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Atrium\Bitrix\Schema\HighloadBlock;
use Atrium\Bitrix\Schema\HighloadBlockEnumValue;
use Atrium\Bitrix\Schema\HighloadBlockField;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The model of a Highload Block that other models point at, from blocks
 * made by hand.
 */
final class ReferenceModelGeneratorTest extends TestCase
{
    /**
     * @param  list<array<string,mixed>>  $fields  FIELD_NAME, USER_TYPE_ID and optional MULTIPLE, MANDATORY, SETTINGS
     */
    private function model(array $fields, string $name = 'Brands'): string
    {
        config(['atrium-bitrix.strip_prefixes' => [], 'atrium-bitrix.naming.mappings' => []]);
        $naming = new NamingConvention;

        $hlblock = HighloadBlock::fromArray(['ID' => 1, 'NAME' => $name, 'TABLE_NAME' => 'b_hlbd_brands']);
        foreach ($fields as $i => $field) {
            $hlblock->addField($built = HighloadBlockField::fromArray(['ID' => $i + 1, 'ENTITY_ID' => 'HLBLOCK_1', ...$field]));

            if (isset($field['ENUM'])) {
                foreach ($field['ENUM'] as $j => $value) {
                    $built->addEnumValue(HighloadBlockEnumValue::fromArray(['ID' => $j + 1, 'USER_FIELD_ID' => $i + 1, 'VALUE' => $value, 'XML_ID' => null]));
                }
            }
        }

        return (new ReferenceModelGenerator($naming, new PropertyTypeMapper, new FieldTypeMapper($naming)))->generate($hlblock);
    }

    public function test_the_base_of_the_model_comes_from_the_block(): void
    {
        $model = $this->model([['FIELD_NAME' => 'UF_NAME', 'USER_TYPE_ID' => 'string']]);

        $this->assertStringContainsString("<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models\\References;\n", $model);
        $this->assertStringContainsString(
            "use Atrium\\Admin\\Columns\\BooleanColumn;\n"
            ."use Atrium\\Admin\\Columns\\DateColumn;\n"
            ."use Atrium\\Admin\\Columns\\ImageColumn;\n"
            ."use Atrium\\Admin\\Columns\\TextColumn;\n"
            ."use Atrium\\Admin\\Contracts\\ReferenceAdministrable;\n"
            ."use Atrium\\Admin\\Fields\\BelongsToField;\n"
            ."use Atrium\\Admin\\Fields\\BelongsToManyField;\n"
            ."use Atrium\\Admin\\Fields\\CheckboxField;\n"
            ."use Atrium\\Admin\\Fields\\ContentField;\n"
            ."use Atrium\\Admin\\Fields\\DateField;\n"
            ."use Atrium\\Admin\\Fields\\DateTimeField;\n"
            ."use Atrium\\Admin\\Fields\\GalleryField;\n"
            ."use Atrium\\Admin\\Fields\\ImageField;\n"
            ."use Atrium\\Admin\\Fields\\MultiSelectField;\n"
            ."use Atrium\\Admin\\Fields\\NumberField;\n"
            ."use Atrium\\Admin\\Fields\\SelectField;\n"
            ."use Atrium\\Admin\\Fields\\TextField;\n"
            ."use Atrium\\Admin\\Fields\\TextareaField;\n"
            ."use Atrium\\Admin\\Traits\\InteractsWithAdmin;\n"
            ."use Illuminate\\Database\\Eloquent\\Model;\n"
            ."use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;\n",
            $model,
        );
        $this->assertStringContainsString('class Brands extends Model implements ReferenceAdministrable', $model);
        $this->assertStringContainsString("protected \$table = 'brands';", $model);
        $this->assertStringContainsString("return 'Brands';", $model);
        $this->assertStringContainsString("protected \$fillable = ['name'];", $model);
        $this->assertStringNotContainsString('$casts', $model);
        $this->assertStringContainsString("TextColumn::make('id')->label('ID')->sortable(),\n            TextColumn::make('name')->label('Name')->sortable(),\n        ];", $model);
        $this->assertStringContainsString("TextField::make('name')->label('Name'),\n        ];", $model);
    }

    public function test_fields_are_cast_and_labelled_by_their_type(): void
    {
        $model = $this->model([
            ['FIELD_NAME' => 'UF_FULL_NAME', 'USER_TYPE_ID' => 'string', 'MANDATORY' => 'Y'],
            ['FIELD_NAME' => 'UF_SORT', 'USER_TYPE_ID' => 'integer'],
            ['FIELD_NAME' => 'UF_ACTIVE', 'USER_TYPE_ID' => 'boolean'],
            ['FIELD_NAME' => 'UF_KIND', 'USER_TYPE_ID' => 'enumeration', 'ENUM' => ['Big']],
            ['FIELD_NAME' => 'UF_PARENT', 'USER_TYPE_ID' => 'hlblock'],
        ]);

        $this->assertStringContainsString("TextField::make('full_name')->label('Full name')->required(),", $model);
        $this->assertStringContainsString("NumberField::make('sort')->label('Sort'),", $model);
        $this->assertStringContainsString("SelectField::make('kind')->label('Kind')->options(array (0 => array ('value' => '1','label' => 'Big',),)),", $model);
        $this->assertStringContainsString("TextField::make('full_name')->label('Full name')->required(),\n            NumberField", $model);
        $this->assertStringContainsString("TextColumn::make('full_name')->label('Full name')->sortable(),", $model);
        $this->assertStringContainsString("protected \$fillable = ['full_name', 'sort', 'active', 'kind'];", $model);
        $this->assertStringContainsString("'sort' => 'integer'", $model);
        $this->assertStringContainsString("'active' => 'boolean'", $model);
        $this->assertStringNotContainsString("'full_name' =>", $model);
    }

    public function test_a_model_with_a_field_of_every_type_can_be_loaded_and_asked_for_its_fields(): void
    {
        $types = ['string', 'integer', 'double', 'boolean', 'datetime', 'date', 'file', 'enumeration', 'hlblock', 'html', 'url', 'address'];
        $fields = [];

        foreach ($types as $type) {
            $fields[] = ['FIELD_NAME' => 'UF_'.strtoupper($type), 'USER_TYPE_ID' => $type];
            $fields[] = ['FIELD_NAME' => 'UF_MANY_'.strtoupper($type), 'USER_TYPE_ID' => $type, 'MULTIPLE' => 'Y'];
        }

        $file = sys_get_temp_dir().'/atrium-reference-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($file, $this->model($fields, 'EveryTypeReference'));

        try {
            require $file;
        } finally {
            unlink($file);
        }

        $class = 'App\\Models\\References\\EveryTypeReference';
        $names = array_map(static fn (object $field): string => $field::class, $class::getAdministrationFields());

        $this->assertCount(count($fields), $names);
        $this->assertSame(
            ['BelongsToField', 'BelongsToManyField', 'CheckboxField', 'ContentField', 'DateField', 'DateTimeField', 'GalleryField', 'ImageField', 'MultiSelectField', 'NumberField', 'SelectField', 'TextField', 'TextareaField'],
            collect($names)->map(static fn (string $name): string => class_basename($name))->unique()->sort()->values()->all(),
        );
        $this->assertCount(5, $class::getAdministrationListColumns(), 'the id and at most four fields');
    }

    public function test_the_list_shows_at_most_four_fields_after_the_id(): void
    {
        $model = $this->model(array_map(
            static fn (string $name): array => ['FIELD_NAME' => 'UF_'.$name, 'USER_TYPE_ID' => $name === 'FLAG' ? 'boolean' : 'string'],
            ['A', 'FLAG', 'C', 'D', 'E'],
        ));

        $this->assertStringContainsString("TextColumn::make('d')->label('D')->sortable(),\n        ];", $model);
        $this->assertStringNotContainsString("TextColumn::make('e')", $model);
        $this->assertStringContainsString("BooleanColumn::make('flag')->label('Flag')->sortable(),", $model);
    }

    public function test_a_multiple_field_is_cast_to_an_array_and_not_sortable(): void
    {
        $model = $this->model([['FIELD_NAME' => 'UF_TAGS', 'USER_TYPE_ID' => 'string', 'MULTIPLE' => 'Y']]);

        $this->assertStringContainsString("'tags' => 'array'", $model);
        $this->assertStringContainsString("TextColumn::make('tags')->label('Tags'),", $model);
    }

    public function test_a_reference_to_another_block_is_a_relation(): void
    {
        $model = $this->model([['FIELD_NAME' => 'UF_PARENT', 'USER_TYPE_ID' => 'hlblock', 'SETTINGS' => serialize(['HLBLOCK_ID' => 3])]]);

        $this->assertStringContainsString("public function parent(): BelongsTo\n    {\n        return \$this->belongsTo(self::class, 'parent');", $model);
    }

    /**
     * @return iterable<string,array{array<string,mixed>}>
     */
    public static function noRelations(): iterable
    {
        yield 'the other block is not known' => [['FIELD_NAME' => 'UF_PARENT', 'USER_TYPE_ID' => 'hlblock']];
        yield 'many references are kept in a column' => [['FIELD_NAME' => 'UF_PARENT', 'USER_TYPE_ID' => 'hlblock', 'MULTIPLE' => 'Y', 'SETTINGS' => serialize(['HLBLOCK_ID' => 3])]];
        yield 'not a reference' => [['FIELD_NAME' => 'UF_PARENT', 'USER_TYPE_ID' => 'integer', 'SETTINGS' => serialize(['HLBLOCK_ID' => 3])]];
    }

    /**
     * @param  array<string,mixed>  $field
     */
    #[DataProvider('noRelations')]
    public function test_a_field_that_does_not_point_at_a_known_block_is_no_relation(array $field): void
    {
        $this->assertStringNotContainsString('belongsTo', $this->model([$field]));
    }

    /**
     * @return iterable<string,array{list<array<string,mixed>>,string}>
     */
    public static function displayValues(): iterable
    {
        $string = static fn (string $name): array => ['FIELD_NAME' => 'UF_'.$name, 'USER_TYPE_ID' => 'string'];
        $number = static fn (string $name): array => ['FIELD_NAME' => 'UF_'.$name, 'USER_TYPE_ID' => 'integer'];

        yield 'the name' => [[$string('OTHER'), $string('NAME')], 'name'];
        yield 'the title' => [[$string('OTHER'), $string('TITLE')], 'title'];
        yield 'the value' => [[$string('OTHER'), $string('VALUE')], 'value'];
        yield 'the code' => [[$string('OTHER'), $string('XML_ID')], 'xml_id'];
        yield 'the first of the usual names' => [[$string('XML_ID'), $string('NAME')], 'xml_id'];
        yield 'a usual name that is not a string' => [[$string('OTHER'), $number('NAME')], 'name'];
        yield 'the first string' => [[$number('SORT'), $string('OTHER'), $string('ANOTHER')], 'other'];
        yield 'no string at all' => [[$number('SORT')], 'id'];
        yield 'no fields' => [[], 'id'];
    }

    /**
     * @param  list<array<string,mixed>>  $fields
     */
    #[DataProvider('displayValues')]
    public function test_the_display_value_is_the_most_name_like_field(array $fields, string $column): void
    {
        $model = $this->model($fields);

        $this->assertStringContainsString("public function getDisplayValue(): string\n    {\n        return (string) \$this->{$column};", $model);
        $this->assertStringContainsString("public function __toString(): string\n    {\n        return \$this->getDisplayValue();", $model);
    }
}
