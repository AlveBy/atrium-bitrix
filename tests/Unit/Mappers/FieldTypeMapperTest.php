<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Mappers;

use Atrium\Bitrix\Mappers\FieldTypeMapper;
use Atrium\Bitrix\Schema\HighloadBlock;
use Atrium\Bitrix\Schema\HighloadBlockEnumValue;
use Atrium\Bitrix\Schema\HighloadBlockField;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockPropertyEnum;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FieldTypeMapperTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $extra
     */
    private static function property(string $type, ?string $userType = null, bool $multiple = false, array $extra = []): IBlockProperty
    {
        return IBlockProperty::fromArray([
            'ID' => 5, 'IBLOCK_ID' => 1, 'NAME' => 'Field', 'CODE' => 'FIELD', 'PROPERTY_TYPE' => $type,
            'USER_TYPE' => $userType, 'MULTIPLE' => $multiple ? 'Y' : 'N', ...$extra,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private static function field(string $type, bool $multiple = false, array $extra = []): HighloadBlockField
    {
        return HighloadBlockField::fromArray([
            'ID' => 1, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_FIELD', 'USER_TYPE_ID' => $type,
            'MULTIPLE' => $multiple ? 'Y' : 'N', ...$extra,
        ]);
    }

    /**
     * @param  list<array{string,?string}>  $values  value and XML_ID
     */
    private static function withEnums(IBlockProperty $property, array $values): IBlockProperty
    {
        foreach ($values as $i => [$value, $xmlId]) {
            $property->addEnumValue(IBlockPropertyEnum::fromArray([
                'ID' => 100 + $i, 'PROPERTY_ID' => $property->id, 'VALUE' => $value, 'XML_ID' => $xmlId,
            ]));
        }

        return $property;
    }

    /**
     * @return iterable<string,array{IBlockProperty,string}>
     */
    public static function fieldClasses(): iterable
    {
        yield 'string' => [self::property('S'), 'TextField'];
        yield 'one-row string' => [self::property('S', null, false, ['ROW_COUNT' => 1]), 'TextField'];
        yield 'multi-row string' => [self::property('S', null, false, ['ROW_COUNT' => 2]), 'TextareaField'];
        yield 'html' => [self::property('S', 'HTML'), 'ContentField'];
        yield 'date' => [self::property('S', 'Date'), 'DateField'];
        yield 'date and time' => [self::property('S', 'DateTime'), 'DateTimeField'];
        yield 'yandex map' => [self::property('S', 'map_yandex'), 'MapField'];
        yield 'google map' => [self::property('S', 'map_google'), 'MapField'];
        yield 'string checkbox' => [self::property('S', 'SASDCheckbox'), 'CheckboxField'];
        yield 'string numeric checkbox' => [self::property('S', 'SASDCheckboxNum'), 'CheckboxField'];
        yield 'number checkbox' => [self::property('N', 'SASDCheckbox'), 'CheckboxField'];
        yield 'number numeric checkbox' => [self::property('N', 'SASDCheckboxNum'), 'CheckboxField'];
        yield 'video' => [self::property('S', 'video'), 'TextField'];
        yield 'user' => [self::property('S', 'UserID'), 'BelongsToField'];
        yield 'palette' => [self::property('S', 'SASDPalette'), 'ColorField'];
        yield 'directory' => [self::property('S', 'directory'), 'BelongsToField'];
        yield 'unknown user type on a string' => [self::property('S', 'SomethingNew'), 'TextField'];
        yield 'number' => [self::property('N'), 'NumberField'];
        yield 'dropdown list' => [self::property('L'), 'SelectField'];
        yield 'checkbox list with one value' => [self::withEnums(self::property('L', null, false, ['LIST_TYPE' => 'C']), [['Yes', 'YES']]), 'CheckboxField'];
        yield 'checkbox list with two values' => [self::withEnums(self::property('L', null, false, ['LIST_TYPE' => 'C']), [['A', 'A'], ['B', 'B']]), 'SelectField'];
        yield 'file' => [self::property('F'), 'ImageField'];
        yield 'element link' => [self::property('E'), 'BelongsToField'];
        yield 'section link' => [self::property('G'), 'TreeSelectField'];
        yield 'unknown base type' => [self::property('X'), 'TextField'];
        yield 'multiple html' => [self::property('S', 'HTML', true), 'RepeatableField'];
        yield 'multiple dates' => [self::property('S', 'Date', true), 'RepeatableField'];
        yield 'multiple dates and times' => [self::property('S', 'DateTime', true), 'RepeatableField'];
        yield 'multiple directory values' => [self::property('S', 'directory', true), 'BelongsToManyField'];
        yield 'multiple strings' => [self::property('S', null, true), 'RepeatableField'];
        yield 'multiple maps fall back to the string type' => [self::property('S', 'map_yandex', true), 'RepeatableField'];
        yield 'multiple numbers' => [self::property('N', null, true), 'RepeatableField'];
        yield 'multiple list' => [self::property('L', null, true), 'MultiSelectField'];
        yield 'multiple checkbox list' => [self::withEnums(self::property('L', null, true, ['LIST_TYPE' => 'C']), [['Yes', 'YES']]), 'MultiSelectField'];
        yield 'multiple files' => [self::property('F', null, true), 'GalleryField'];
        yield 'multiple element links' => [self::property('E', null, true), 'BelongsToManyField'];
        yield 'multiple section links' => [self::property('G', null, true), 'TreeSelectField'];
        yield 'multiple unknown base type' => [self::property('X', null, true), 'RepeatableField'];
    }

    #[DataProvider('fieldClasses')]
    public function test_a_property_gets_its_field_class(IBlockProperty $property, string $expected): void
    {
        $this->assertSame($expected, (new FieldTypeMapper)->toAdminField($property)['class']);
    }

    public function test_the_base_settings_of_a_property_are_carried_over(): void
    {
        $property = self::property('S', null, false, [
            'CODE' => 'TOUR_DURATION', 'NAME' => 'Длительность тура', 'IS_REQUIRED' => 'Y', 'HINT' => 'В днях',
        ]);

        $this->assertSame([
            'class' => 'TextField',
            'name' => 'tour_duration',
            'label' => 'Длительность тура',
            'required' => true,
            'multiple' => false,
            'hint' => 'В днях',
        ], (new FieldTypeMapper)->toAdminField($property));
    }

    public function test_an_optional_multiple_property_without_a_hint(): void
    {
        $config = (new FieldTypeMapper)->toAdminField(self::property('S', null, true));

        $this->assertFalse($config['required']);
        $this->assertTrue($config['multiple']);
        $this->assertNull($config['hint']);
        $this->assertSame('text', $config['itemType']);
    }

    public function test_the_naming_convention_given_is_used_for_names_and_models(): void
    {
        config(['atrium-bitrix.strip_prefixes' => ['demo_']]);
        $naming = new NamingConvention;
        config(['atrium-bitrix.strip_prefixes' => []]);
        $property = self::property('E', null, false, ['CODE' => 'LINK', 'LINK_IBLOCK_ID' => 9]);
        $property->linkIblockCode = 'demo_tours';

        $config = (new FieldTypeMapper($naming))->toAdminField($property);

        $this->assertSame('link', $config['name']);
        $this->assertSame('Tour', $config['model']);
        $this->assertSame('DemoTour', (new FieldTypeMapper)->toAdminField($property)['model'], 'a fresh convention reads the config of now');
    }

    public function test_list_options_take_the_code_or_else_the_id(): void
    {
        $property = self::withEnums(self::property('L'), [['Круиз', 'cruise'], ['Виза', null], ['Тур', '']]);

        $config = (new FieldTypeMapper)->toAdminField($property);

        $this->assertSame([
            ['value' => 'cruise', 'label' => 'Круиз'],
            ['value' => '101', 'label' => 'Виза'],
            ['value' => '102', 'label' => 'Тур'],
        ], $config['options']);
        $this->assertArrayNotHasKey('checkedValue', $config);
    }

    public function test_a_list_without_values_has_no_options(): void
    {
        $this->assertArrayNotHasKey('options', (new FieldTypeMapper)->toAdminField(self::property('L')));
    }

    public function test_a_single_tick_box_stores_the_code_of_its_value(): void
    {
        $property = self::withEnums(self::property('L', null, false, ['LIST_TYPE' => 'C']), [['Да', 'YES']]);

        $config = (new FieldTypeMapper)->toAdminField($property);

        $this->assertSame('CheckboxField', $config['class']);
        $this->assertSame('YES', $config['checkedValue']);
    }

    public function test_a_tick_box_without_a_code_stores_the_id(): void
    {
        $property = self::withEnums(self::property('L', null, false, ['LIST_TYPE' => 'C']), [['Да', null]]);

        $this->assertSame('100', (new FieldTypeMapper)->toAdminField($property)['checkedValue']);
    }

    /**
     * @return iterable<string,array{?array<string,mixed>,string}>
     */
    public static function fileTypes(): iterable
    {
        yield 'no settings' => [null, 'image/*'];
        yield 'empty file type' => [['FILE_TYPE' => ''], 'image/*'];
        yield 'image word' => [['FILE_TYPE' => 'Images'], 'image/*'];
        yield 'image extensions' => [['FILE_TYPE' => 'JPG, png'], 'image/*'];
        yield 'webp' => [['FILE_TYPE' => 'webp'], 'image/*'];
        yield 'gif' => [['FILE_TYPE' => 'gif'], 'image/*'];
        yield 'jpeg' => [['FILE_TYPE' => 'jpeg'], 'image/*'];
        yield 'pdf' => [['FILE_TYPE' => 'PDF'], '.pdf'];
        yield 'word documents' => [['FILE_TYPE' => 'doc, docx'], '.doc,.docx,.pdf'];
        yield 'images win over documents' => [['FILE_TYPE' => 'pdf, png'], 'image/*'];
        yield 'pdf wins over word' => [['FILE_TYPE' => 'docx, pdf'], '.pdf'];
        yield 'anything else is an image' => [['FILE_TYPE' => 'zip, rar'], 'image/*'];
    }

    /**
     * @param  array<string,mixed>|null  $settings
     */
    #[DataProvider('fileTypes')]
    public function test_a_file_property_accepts_what_its_settings_allow(?array $settings, string $accept): void
    {
        config(['atrium.uploads.max_size' => 2048]);
        $property = self::property('F', null, false, ['USER_TYPE_SETTINGS' => $settings]);

        $config = (new FieldTypeMapper)->toAdminField($property);

        $this->assertSame($accept, $config['accept']);
        $this->assertSame(2048, $config['maxSize']);
    }

    public function test_a_file_property_falls_back_to_the_default_upload_size(): void
    {
        config(['atrium.uploads' => []]);

        $this->assertSame(5120, (new FieldTypeMapper)->toAdminField(self::property('F'))['maxSize']);
    }

    public function test_a_non_file_property_has_no_upload_settings(): void
    {
        $config = (new FieldTypeMapper)->toAdminField(self::property('S'));

        $this->assertArrayNotHasKey('accept', $config);
        $this->assertArrayNotHasKey('maxSize', $config);
    }

    public function test_an_element_link_names_the_iblock_and_its_model(): void
    {
        $property = self::property('E', null, true, ['LINK_IBLOCK_ID' => 9]);
        $property->linkIblockCode = 'tours';

        $config = (new FieldTypeMapper)->toAdminField($property);

        $this->assertSame(['type' => 'element', 'iblock_id' => 9, 'iblock_code' => 'tours'], $config['relation']);
        $this->assertSame('Tour', $config['model']);
    }

    public function test_an_element_link_to_an_iblock_without_a_code_has_no_model(): void
    {
        $config = (new FieldTypeMapper)->toAdminField(self::property('E', null, false, ['LINK_IBLOCK_ID' => 9]));

        $this->assertSame(['type' => 'element', 'iblock_id' => 9, 'iblock_code' => null], $config['relation']);
        $this->assertArrayNotHasKey('model', $config);
    }

    public function test_an_element_link_to_any_iblock_has_no_relation(): void
    {
        $property = self::property('E');
        $property->linkIblockCode = 'tours';

        $config = (new FieldTypeMapper)->toAdminField($property);

        $this->assertArrayNotHasKey('relation', $config);
        $this->assertArrayNotHasKey('model', $config);
    }

    public function test_a_section_link_points_at_the_section_model(): void
    {
        $property = self::property('G', null, false, ['LINK_IBLOCK_ID' => 4]);
        $property->linkIblockCode = 'tours';

        $config = (new FieldTypeMapper)->toAdminField($property);

        $this->assertSame(['type' => 'section'], $config['relation']);
        $this->assertSame('TourSection', $config['model']);
    }

    public function test_a_section_link_without_an_iblock_code_has_no_model(): void
    {
        $config = (new FieldTypeMapper)->toAdminField(self::property('G'));

        $this->assertSame(['type' => 'section'], $config['relation']);
        $this->assertArrayNotHasKey('model', $config);
    }

    public function test_a_directory_points_at_its_reference_model(): void
    {
        $property = self::property('S', 'directory', false, ['USER_TYPE_SETTINGS' => ['TABLE_NAME' => 'b_hlbd_brands']]);
        $property->directoryHlblock = HighloadBlock::fromArray(['ID' => 3, 'NAME' => 'Brands', 'TABLE_NAME' => 'b_hlbd_brands']);

        $config = (new FieldTypeMapper)->toAdminField($property);

        $this->assertSame([
            'type' => 'directory', 'table_name' => 'b_hlbd_brands', 'hlblock_id' => 3, 'hlblock_name' => 'Brands',
        ], $config['relation']);
        $this->assertSame('References\\Brands', $config['model'], 'reference models keep the block name as it is');
    }

    public function test_a_directory_whose_highload_block_is_gone_keeps_only_the_table(): void
    {
        $property = self::property('S', 'directory', false, ['USER_TYPE_SETTINGS' => ['TABLE_NAME' => 'b_hlbd_gone']]);

        $config = (new FieldTypeMapper)->toAdminField($property);

        $this->assertSame(['type' => 'directory', 'table_name' => 'b_hlbd_gone'], $config['relation']);
        $this->assertArrayNotHasKey('model', $config);
    }

    public function test_a_number_has_a_fine_step_and_a_string_none(): void
    {
        $mapper = new FieldTypeMapper;

        $this->assertSame(0.0001, $mapper->toAdminField(self::property('N'))['step']);
        $this->assertArrayNotHasKey('step', $mapper->toAdminField(self::property('S')));
    }

    /**
     * @return iterable<string,array{IBlockProperty,string}>
     */
    public static function itemTypes(): iterable
    {
        yield 'dates' => [self::property('S', 'Date', true), 'date'];
        yield 'dates and times' => [self::property('S', 'DateTime', true), 'datetime'];
        yield 'html' => [self::property('S', 'HTML', true), 'content'];
        yield 'numbers' => [self::property('N', null, true), 'number'];
        yield 'strings' => [self::property('S', null, true), 'text'];
        yield 'files' => [self::property('F', null, true), 'text'];
        yield 'strings of another user type' => [self::property('S', 'video', true), 'text'];
    }

    #[DataProvider('itemTypes')]
    public function test_a_multiple_property_names_the_type_of_each_item(IBlockProperty $property, string $itemType): void
    {
        $this->assertSame($itemType, (new FieldTypeMapper)->toAdminField($property)['itemType']);
    }

    public function test_a_single_property_has_no_item_type(): void
    {
        $this->assertArrayNotHasKey('itemType', (new FieldTypeMapper)->toAdminField(self::property('S', 'Date')));
    }

    public function test_a_map_names_its_provider(): void
    {
        $mapper = new FieldTypeMapper;

        $this->assertSame('yandex', $mapper->toAdminField(self::property('S', 'map_yandex'))['mapType']);
        $this->assertSame('google', $mapper->toAdminField(self::property('S', 'map_google'))['mapType']);
        $this->assertArrayNotHasKey('mapType', $mapper->toAdminField(self::property('S')));
    }

    public function test_rows_and_default_value_are_carried_over_when_set(): void
    {
        $mapper = new FieldTypeMapper;

        $config = $mapper->toAdminField(self::property('S', null, false, ['ROW_COUNT' => 4, 'DEFAULT_VALUE' => '0']));
        $this->assertSame(4, $config['rows']);
        $this->assertSame('0', $config['default']);

        $config = $mapper->toAdminField(self::property('S'));
        $this->assertArrayNotHasKey('rows', $config);
        $this->assertArrayNotHasKey('default', $config);
    }

    /**
     * @return iterable<string,array{HighloadBlockField,string}>
     */
    public static function hlFieldClasses(): iterable
    {
        yield 'string' => [self::field('string'), 'TextField'];
        yield 'integer' => [self::field('integer'), 'NumberField'];
        yield 'double' => [self::field('double'), 'NumberField'];
        yield 'money' => [self::field('money'), 'NumberField'];
        yield 'boolean' => [self::field('boolean'), 'CheckboxField'];
        yield 'datetime' => [self::field('datetime'), 'DateTimeField'];
        yield 'date' => [self::field('date'), 'DateField'];
        yield 'file' => [self::field('file'), 'ImageField'];
        yield 'multiple file' => [self::field('file', true), 'GalleryField'];
        yield 'enumeration' => [self::field('enumeration'), 'SelectField'];
        yield 'multiple enumeration' => [self::field('enumeration', true), 'MultiSelectField'];
        yield 'hlblock' => [self::field('hlblock'), 'BelongsToField'];
        yield 'multiple hlblock' => [self::field('hlblock', true), 'BelongsToManyField'];
        yield 'html' => [self::field('html'), 'ContentField'];
        yield 'url' => [self::field('url'), 'TextField'];
        yield 'address' => [self::field('address'), 'TextareaField'];
        yield 'unknown' => [self::field('iblock_element'), 'TextField'];
    }

    #[DataProvider('hlFieldClasses')]
    public function test_a_highload_field_gets_its_field_class(HighloadBlockField $field, string $expected): void
    {
        $this->assertSame($expected, (new FieldTypeMapper)->hlFieldToAdminField($field)['class']);
    }

    public function test_the_base_settings_of_a_highload_field_are_carried_over(): void
    {
        $field = self::field('string', true, ['FIELD_NAME' => 'UF_NAME', 'MANDATORY' => 'Y']);

        $this->assertSame([
            'class' => 'TextField', 'name' => 'name', 'label' => 'UF_NAME', 'required' => true, 'multiple' => true,
        ], (new FieldTypeMapper)->hlFieldToAdminField($field));

        $config = (new FieldTypeMapper)->hlFieldToAdminField(self::field('string'));
        $this->assertFalse($config['required']);
        $this->assertFalse($config['multiple']);
    }

    public function test_highload_enumeration_options_take_the_code_or_else_the_id(): void
    {
        $field = self::field('enumeration');
        $field->addEnumValue(HighloadBlockEnumValue::fromArray(['ID' => 7, 'USER_FIELD_ID' => 1, 'VALUE' => 'Красный', 'XML_ID' => 'red']));
        $field->addEnumValue(HighloadBlockEnumValue::fromArray(['ID' => 8, 'USER_FIELD_ID' => 1, 'VALUE' => 'Синий', 'XML_ID' => '']));

        $this->assertSame([
            ['value' => 'red', 'label' => 'Красный'],
            ['value' => '8', 'label' => 'Синий'],
        ], (new FieldTypeMapper)->hlFieldToAdminField($field)['options']);

        $this->assertArrayNotHasKey('options', (new FieldTypeMapper)->hlFieldToAdminField(self::field('enumeration')));
    }

    public function test_a_highload_file_takes_images_up_to_the_upload_size(): void
    {
        config(['atrium.uploads.max_size' => 1024]);
        $config = (new FieldTypeMapper)->hlFieldToAdminField(self::field('file'));
        $this->assertSame('image/*', $config['accept']);
        $this->assertSame(1024, $config['maxSize']);

        config(['atrium.uploads' => []]);
        $this->assertSame(5120, (new FieldTypeMapper)->hlFieldToAdminField(self::field('file'))['maxSize']);

        $this->assertArrayNotHasKey('accept', (new FieldTypeMapper)->hlFieldToAdminField(self::field('string')));
    }

    public function test_a_highload_reference_names_the_linked_block(): void
    {
        $linked = self::field('hlblock', false, ['SETTINGS' => ['HLBLOCK_ID' => 4]]);

        $this->assertSame(['type' => 'hlblock', 'hlblock_id' => 4], (new FieldTypeMapper)->hlFieldToAdminField($linked)['relation']);
        $this->assertArrayNotHasKey('relation', (new FieldTypeMapper)->hlFieldToAdminField(self::field('hlblock')));
    }

    public function test_a_highload_number_has_a_step_by_its_kind(): void
    {
        $mapper = new FieldTypeMapper;

        $this->assertSame(0.01, $mapper->hlFieldToAdminField(self::field('money'))['step']);
        $this->assertSame(0.0001, $mapper->hlFieldToAdminField(self::field('double'))['step']);
        $this->assertArrayNotHasKey('step', $mapper->hlFieldToAdminField(self::field('integer')));
    }

    /**
     * @return iterable<string,array{IBlockProperty,string}>
     */
    public static function listColumns(): iterable
    {
        yield 'file' => [self::property('F'), 'ImageColumn'];
        yield 'checkbox' => [self::property('S', 'SASDCheckbox'), 'BooleanColumn'];
        yield 'yes and no' => [self::withEnums(self::property('L'), [['Yes', null], ['No', null]]), 'BooleanColumn'];
        yield 'no and yes in lower case' => [self::withEnums(self::property('L'), [[' no ', null], ['yes', null]]), 'BooleanColumn'];
        yield 'Y and N' => [self::withEnums(self::property('L'), [['N', null], ['Y', null]]), 'BooleanColumn'];
        yield 'да и нет' => [self::withEnums(self::property('L'), [['да', null], ['Нет', null]]), 'BooleanColumn'];
        yield 'Д и Н' => [self::withEnums(self::property('L'), [['Д', null], ['Н', null]]), 'BooleanColumn'];
        yield 'mixed languages are not yes and no' => [self::withEnums(self::property('L'), [['Да', null], ['No', null]]), 'TextColumn'];
        yield 'two other values' => [self::withEnums(self::property('L'), [['Red', null], ['Blue', null]]), 'TextColumn'];
        yield 'yes twice' => [self::withEnums(self::property('L'), [['Yes', null], ['Yes', null]]), 'TextColumn'];
        yield 'only yes' => [self::withEnums(self::property('L'), [['Yes', null]]), 'TextColumn'];
        yield 'yes, no and maybe' => [self::withEnums(self::property('L'), [['Yes', null], ['No', null], ['Maybe', null]]), 'TextColumn'];
        yield 'list without values' => [self::property('L'), 'TextColumn'];
        yield 'yes and no on a string is not a list' => [self::withEnums(self::property('S'), [['Yes', null], ['No', null]]), 'TextColumn'];
        yield 'date' => [self::property('S', 'Date'), 'DateColumn'];
        yield 'date and time' => [self::property('S', 'DateTime'), 'DateColumn'];
        yield 'string' => [self::property('S'), 'TextColumn'];
        yield 'number' => [self::property('N'), 'TextColumn'];
    }

    #[DataProvider('listColumns')]
    public function test_a_property_gets_its_list_column(IBlockProperty $property, string $expected): void
    {
        $this->assertSame($expected, (new FieldTypeMapper)->toListColumn($property)['class']);
    }

    public function test_a_list_column_is_sortable_unless_multiple_or_a_file(): void
    {
        $mapper = new FieldTypeMapper;

        $this->assertSame([
            'class' => 'TextColumn', 'name' => 'tour_name', 'label' => 'Тур', 'sortable' => true, 'searchable' => true,
        ], $mapper->toListColumn(self::property('S', null, false, ['CODE' => 'TOUR_NAME', 'NAME' => 'Тур', 'SEARCHABLE' => 'Y'])));

        $this->assertFalse($mapper->toListColumn(self::property('S', null, true))['sortable']);
        $this->assertFalse($mapper->toListColumn(self::property('F'))['sortable']);
        $this->assertFalse($mapper->toListColumn(self::property('S'))['searchable']);
    }

    /**
     * @return iterable<string,array{HighloadBlockField,string}>
     */
    public static function hlListColumns(): iterable
    {
        yield 'file' => [self::field('file'), 'ImageColumn'];
        yield 'boolean' => [self::field('boolean'), 'BooleanColumn'];
        yield 'date' => [self::field('date'), 'DateColumn'];
        yield 'datetime' => [self::field('datetime'), 'DateColumn'];
        yield 'string' => [self::field('string'), 'TextColumn'];
    }

    #[DataProvider('hlListColumns')]
    public function test_a_highload_field_gets_its_list_column(HighloadBlockField $field, string $expected): void
    {
        $this->assertSame($expected, (new FieldTypeMapper)->hlFieldToListColumn($field)['class']);
    }

    public function test_a_highload_list_column_is_sortable_unless_multiple_or_a_file(): void
    {
        $mapper = new FieldTypeMapper;

        $this->assertSame(
            ['class' => 'TextColumn', 'name' => 'title', 'label' => 'UF_TITLE', 'sortable' => true],
            $mapper->hlFieldToListColumn(self::field('string', false, ['FIELD_NAME' => 'UF_TITLE'])),
        );
        $this->assertFalse($mapper->hlFieldToListColumn(self::field('string', true))['sortable']);
        $this->assertFalse($mapper->hlFieldToListColumn(self::field('file'))['sortable']);
    }

    public function test_a_short_field_class_name_gets_its_namespace(): void
    {
        $mapper = new FieldTypeMapper;

        $this->assertSame('Atrium\\Admin\\Fields\\TextField', $mapper->getFieldClassName('TextField'));
    }
}
