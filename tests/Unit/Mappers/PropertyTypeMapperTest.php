<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Mappers;

use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Atrium\Bitrix\Schema\HighloadBlockField;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockPropertyEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PropertyTypeMapperTest extends TestCase
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

    private static function field(string $type, bool $multiple = false): HighloadBlockField
    {
        return HighloadBlockField::fromArray([
            'ID' => 1, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_FIELD', 'USER_TYPE_ID' => $type,
            'MULTIPLE' => $multiple ? 'Y' : 'N',
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
     * @return iterable<string,array{IBlockProperty,array<string,mixed>}>
     */
    public static function migrationColumns(): iterable
    {
        $string = ['type' => 'string', 'nullable' => true, 'length' => 255];
        $foreign = ['type' => 'foreignId', 'nullable' => true, 'constrained' => false];
        $pivot = ['type' => 'pivot', 'nullable' => true];
        $json = ['type' => 'json', 'nullable' => true];
        $checkbox = ['type' => 'boolean', 'default' => false];

        yield 'plain string' => [self::property('S'), $string];
        yield 'html' => [self::property('S', 'HTML'), ['type' => 'longText', 'nullable' => true]];
        yield 'date' => [self::property('S', 'Date'), ['type' => 'date', 'nullable' => true]];
        yield 'date and time' => [self::property('S', 'DateTime'), ['type' => 'datetime', 'nullable' => true]];
        yield 'yandex map' => [self::property('S', 'map_yandex'), $json];
        yield 'google map' => [self::property('S', 'map_google'), $json];
        yield 'string checkbox' => [self::property('S', 'SASDCheckbox'), $checkbox];
        yield 'string numeric checkbox' => [self::property('S', 'SASDCheckboxNum'), $checkbox];
        yield 'number checkbox' => [self::property('N', 'SASDCheckbox'), $checkbox];
        yield 'number numeric checkbox' => [self::property('N', 'SASDCheckboxNum'), $checkbox];
        yield 'video' => [self::property('S', 'video'), $json];
        yield 'user' => [self::property('S', 'UserID'), ['type' => 'unsignedBigInteger', 'nullable' => true]];
        yield 'palette' => [self::property('S', 'SASDPalette'), ['type' => 'string', 'nullable' => true, 'length' => 20]];
        yield 'number' => [self::property('N'), ['type' => 'decimal', 'nullable' => true, 'precision' => 18, 'scale' => 4]];
        yield 'list' => [self::property('L'), ['type' => 'string', 'nullable' => true, 'length' => 100]];
        yield 'single file' => [self::property('F'), $string];
        yield 'unknown user type falls back to a string' => [self::property('S', 'SomethingNew'), $string];
        yield 'unknown base type falls back to a string' => [self::property('X'), $string];
        yield 'element link' => [self::property('E'), $foreign];
        yield 'section link' => [self::property('G'), $foreign];
        yield 'directory' => [self::property('S', 'directory'), $foreign];
        yield 'multiple element links' => [self::property('E', null, true), $pivot];
        yield 'multiple section links' => [self::property('G', null, true), $pivot];
        yield 'multiple directory values' => [self::property('S', 'directory', true), $pivot];
        yield 'multiple strings' => [self::property('S', null, true), $json];
        yield 'multiple numbers' => [self::property('N', null, true), $json];
        yield 'multiple files' => [self::property('F', null, true), $json];
        yield 'multiple checkboxes are values, not a flag' => [self::property('S', 'SASDCheckbox', true), $json];
    }

    /**
     * @param  array<string,mixed>  $expected
     */
    #[DataProvider('migrationColumns')]
    public function test_a_property_gets_its_column(IBlockProperty $property, array $expected): void
    {
        $this->assertSame($expected, (new PropertyTypeMapper)->toMigrationColumn($property));
    }

    public function test_a_required_property_still_gets_a_nullable_column(): void
    {
        $column = (new PropertyTypeMapper)->toMigrationColumn(self::property('S', null, false, ['IS_REQUIRED' => 'Y']));

        $this->assertTrue($column['nullable']);
    }

    /**
     * @return iterable<string,array{HighloadBlockField,array<string,mixed>}>
     */
    public static function fieldColumns(): iterable
    {
        $string = ['type' => 'string', 'nullable' => true, 'length' => 255];

        yield 'string' => [self::field('string'), $string];
        yield 'integer' => [self::field('integer'), ['type' => 'integer', 'nullable' => true]];
        yield 'double' => [self::field('double'), ['type' => 'decimal', 'nullable' => true, 'precision' => 18, 'scale' => 4]];
        yield 'boolean' => [self::field('boolean'), ['type' => 'boolean', 'default' => false]];
        yield 'datetime' => [self::field('datetime'), ['type' => 'datetime', 'nullable' => true]];
        yield 'date' => [self::field('date'), ['type' => 'date', 'nullable' => true]];
        yield 'file' => [self::field('file'), $string];
        yield 'enumeration' => [self::field('enumeration'), ['type' => 'string', 'nullable' => true, 'length' => 100]];
        yield 'hlblock' => [self::field('hlblock'), ['type' => 'foreignId', 'nullable' => true]];
        yield 'html' => [self::field('html'), ['type' => 'longText', 'nullable' => true]];
        yield 'url' => [self::field('url'), ['type' => 'string', 'nullable' => true, 'length' => 500]];
        yield 'address' => [self::field('address'), ['type' => 'longText', 'nullable' => true]];
        yield 'money' => [self::field('money'), ['type' => 'decimal', 'nullable' => true, 'precision' => 18, 'scale' => 2]];
        yield 'unknown type' => [self::field('iblock_element'), $string];
        yield 'multiple string' => [self::field('string', true), ['type' => 'json', 'nullable' => true]];
        yield 'multiple boolean' => [self::field('boolean', true), ['type' => 'json', 'nullable' => true]];
    }

    /**
     * @param  array<string,mixed>  $expected
     */
    #[DataProvider('fieldColumns')]
    public function test_a_highload_field_gets_its_column(HighloadBlockField $field, array $expected): void
    {
        $this->assertSame($expected, (new PropertyTypeMapper)->fieldToMigrationColumn($field));
    }

    /**
     * @return iterable<string,array{IBlockProperty,?string}>
     */
    public static function casts(): iterable
    {
        yield 'number' => [self::property('N'), 'decimal:4'];
        yield 'date' => [self::property('S', 'Date'), 'date'];
        yield 'date and time' => [self::property('S', 'DateTime'), 'datetime'];
        yield 'string checkbox' => [self::property('S', 'SASDCheckbox'), 'boolean'];
        yield 'string numeric checkbox' => [self::property('S', 'SASDCheckboxNum'), 'boolean'];
        yield 'number checkbox' => [self::property('N', 'SASDCheckbox'), 'boolean'];
        yield 'number numeric checkbox' => [self::property('N', 'SASDCheckboxNum'), 'boolean'];
        yield 'yandex map' => [self::property('S', 'map_yandex'), 'array'];
        yield 'google map' => [self::property('S', 'map_google'), 'array'];
        yield 'video' => [self::property('S', 'video'), 'array'];
        yield 'multiple strings' => [self::property('S', null, true), 'array'];
        yield 'multiple files' => [self::property('F', null, true), 'array'];
        yield 'single file' => [self::property('F'), null];
        yield 'plain string' => [self::property('S'), null];
        yield 'html' => [self::property('S', 'HTML'), null];
        yield 'list' => [self::property('L'), null];
        yield 'single element link' => [self::property('E'), null];
        yield 'multiple element links live in a pivot' => [self::property('E', null, true), null];
        yield 'multiple directory values live in a pivot' => [self::property('S', 'directory', true), null];
    }

    #[DataProvider('casts')]
    public function test_a_property_gets_its_cast(IBlockProperty $property, ?string $expected): void
    {
        $this->assertSame($expected, (new PropertyTypeMapper)->toCast($property));
    }

    /**
     * @return iterable<string,array{HighloadBlockField,?string}>
     */
    public static function fieldCasts(): iterable
    {
        yield 'integer' => [self::field('integer'), 'integer'];
        yield 'double' => [self::field('double'), 'decimal:4'];
        yield 'money' => [self::field('money'), 'decimal:4'];
        yield 'boolean' => [self::field('boolean'), 'boolean'];
        yield 'datetime' => [self::field('datetime'), 'datetime'];
        yield 'date' => [self::field('date'), 'date'];
        yield 'string' => [self::field('string'), null];
        yield 'file' => [self::field('file'), null];
        yield 'multiple string' => [self::field('string', true), 'array'];
        yield 'multiple integer' => [self::field('integer', true), 'array'];
    }

    #[DataProvider('fieldCasts')]
    public function test_a_highload_field_gets_its_cast(HighloadBlockField $field, ?string $expected): void
    {
        $this->assertSame($expected, (new PropertyTypeMapper)->fieldToCast($field));
    }

    /**
     * @return iterable<string,array{IBlockProperty,bool}>
     */
    public static function fillables(): iterable
    {
        yield 'multiple element links' => [self::property('E', null, true), false];
        yield 'multiple section links' => [self::property('G', null, true), false];
        yield 'single element link' => [self::property('E'), true];
        yield 'single section link' => [self::property('G'), true];
        yield 'single file' => [self::property('F'), true];
        yield 'multiple files' => [self::property('F', null, true), true];
        yield 'multiple strings' => [self::property('S', null, true), true];
        yield 'multiple directory values' => [self::property('S', 'directory', true), true];
    }

    #[DataProvider('fillables')]
    public function test_only_multiple_links_are_kept_out_of_fillable(IBlockProperty $property, bool $expected): void
    {
        $this->assertSame($expected, (new PropertyTypeMapper)->isFillable($property));
    }

    public function test_only_highload_references_are_kept_out_of_fillable(): void
    {
        $mapper = new PropertyTypeMapper;

        $this->assertFalse($mapper->isFieldFillable(self::field('hlblock')));
        $this->assertFalse($mapper->isFieldFillable(self::field('hlblock', true)));
        $this->assertTrue($mapper->isFieldFillable(self::field('file')));
        $this->assertTrue($mapper->isFieldFillable(self::field('string')));
    }

    /**
     * @return iterable<string,array{IBlockProperty,bool,?string}>
     */
    public static function indexes(): iterable
    {
        yield 'nothing set' => [self::property('S'), false, null];
        yield 'filtrable' => [self::property('S', null, false, ['FILTRABLE' => 'Y']), true, 'index'];
        yield 'smart filter' => [self::property('N', null, false, ['SMART_FILTER' => 'Y']), true, 'index'];
        yield 'searchable string' => [self::property('S', null, false, ['SEARCHABLE' => 'Y']), true, 'fulltext'];
        yield 'searchable html' => [self::property('S', 'HTML', false, ['SEARCHABLE' => 'Y']), true, 'fulltext'];
        yield 'searchable number is not fulltext' => [self::property('N', null, false, ['SEARCHABLE' => 'Y']), true, null];
        yield 'searchable and filtrable number' => [self::property('N', null, false, ['SEARCHABLE' => 'Y', 'FILTRABLE' => 'Y']), true, 'index'];
        yield 'element link' => [self::property('E'), false, 'foreign'];
        yield 'section link' => [self::property('G'), false, 'foreign'];
        yield 'directory' => [self::property('S', 'directory'), false, 'foreign'];
        yield 'filtrable element link is still foreign' => [self::property('E', null, false, ['FILTRABLE' => 'Y']), true, 'foreign'];
    }

    #[DataProvider('indexes')]
    public function test_a_property_is_indexed_by_its_filter_flags(IBlockProperty $property, bool $indexed, ?string $type): void
    {
        $mapper = new PropertyTypeMapper;

        $this->assertSame($indexed, $mapper->shouldIndex($property));
        $this->assertSame($type, $mapper->getIndexType($property));
    }

    /**
     * @return iterable<string,array{IBlockProperty,list<string>}>
     */
    public static function rules(): iterable
    {
        yield 'optional string' => [self::property('S'), ['nullable']];
        yield 'required string' => [self::property('S', null, false, ['IS_REQUIRED' => 'Y']), ['required']];
        yield 'number' => [self::property('N'), ['nullable', 'numeric']];
        yield 'date' => [self::property('S', 'Date'), ['nullable', 'date']];
        yield 'date and time' => [self::property('S', 'DateTime'), ['nullable', 'date']];
        yield 'file' => [self::property('F'), ['nullable', 'file']];
        yield 'multiple files are not an array rule' => [self::property('F', null, true), ['nullable', 'file']];
        yield 'multiple strings' => [self::property('S', null, true), ['nullable', 'array']];
        yield 'list without values' => [self::property('L'), ['nullable']];
        yield 'list by codes, then ids' => [
            self::withEnums(self::property('L'), [['Yes', 'YES'], ['Maybe', null], ['No', '']]),
            ['nullable', 'in:YES,101,102'],
        ];
        // The list branch and the multiple branch both add 'array'.
        yield 'multiple list' => [
            self::withEnums(self::property('L', null, true), [['Yes', 'YES']]),
            ['nullable', 'array', 'array'],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('rules')]
    public function test_a_property_gets_its_validation_rules(IBlockProperty $property, array $expected): void
    {
        $this->assertSame($expected, (new PropertyTypeMapper)->getValidationRules($property));
    }

    /**
     * @return iterable<string,array{IBlockProperty,mixed}>
     */
    public static function defaults(): iterable
    {
        yield 'checkbox without a default' => [self::property('S', 'SASDCheckbox'), false];
        yield 'checkbox ignores its default' => [self::property('S', 'SASDCheckbox', false, ['DEFAULT_VALUE' => 'Y']), false];
        yield 'number' => [self::property('N', null, false, ['DEFAULT_VALUE' => '12.5']), 12.5];
        yield 'number zero' => [self::property('N', null, false, ['DEFAULT_VALUE' => '0']), 0.0];
        yield 'string' => [self::property('S', null, false, ['DEFAULT_VALUE' => '12.5']), '12.5'];
        yield 'no default' => [self::property('S'), null];
        yield 'number without a default' => [self::property('N'), null];
    }

    #[DataProvider('defaults')]
    public function test_a_property_gets_its_default_value(IBlockProperty $property, mixed $expected): void
    {
        $this->assertSame($expected, (new PropertyTypeMapper)->getDefaultValue($property));
    }
}
