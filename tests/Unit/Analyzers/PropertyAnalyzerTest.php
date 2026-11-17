<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Analyzers;

use Atrium\Bitrix\Analyzers\PropertyAnalyzer;
use Atrium\Bitrix\Schema\HighloadBlockEnumValue;
use Atrium\Bitrix\Schema\HighloadBlockField;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockPropertyEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PropertyAnalyzerTest extends TestCase
{
    private PropertyAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new PropertyAnalyzer;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @param  list<string>  $enum  XML_IDs
     */
    private static function property(string $type, ?string $userType = null, bool $multiple = false, array $extra = [], array $enum = []): IBlockProperty
    {
        $property = IBlockProperty::fromArray([
            'ID' => 1, 'IBLOCK_ID' => 1, 'NAME' => 'Field', 'CODE' => 'FIELD', 'PROPERTY_TYPE' => $type,
            'USER_TYPE' => $userType, 'MULTIPLE' => $multiple ? 'Y' : 'N', ...$extra,
        ]);

        foreach ($enum as $i => $xmlId) {
            $property->addEnumValue(IBlockPropertyEnum::fromArray(['ID' => $i + 1, 'PROPERTY_ID' => 1, 'VALUE' => $xmlId, 'XML_ID' => $xmlId]));
        }

        return $property;
    }

    /**
     * @param  list<string>  $enum
     */
    private static function field(string $type, bool $multiple = false, bool $mandatory = false, array $enum = []): HighloadBlockField
    {
        $field = HighloadBlockField::fromArray([
            'ID' => 1, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_COLOR_CODE', 'USER_TYPE_ID' => $type,
            'MULTIPLE' => $multiple ? 'Y' : 'N', 'MANDATORY' => $mandatory ? 'Y' : 'N',
        ]);

        foreach ($enum as $i => $xmlId) {
            $field->addEnumValue(HighloadBlockEnumValue::fromArray(['ID' => $i + 1, 'USER_FIELD_ID' => 1, 'VALUE' => $xmlId, 'XML_ID' => $xmlId]));
        }

        return $field;
    }

    /**
     * [type, user type, multiple, enum] => [relation, relation type, storage, cast, rules].
     *
     * @return array<string,array{0:string,1:?string,2:bool,3:list<string>,4:bool,5:?string,6:string,7:?string,8:list<string>}>
     */
    public static function properties(): array
    {
        return [
            'string' => ['S', null, false, [], false, null, 'string', null, ['nullable']],
            'multiple string' => ['S', null, true, [], false, null, 'json', 'array', ['nullable', 'array']],
            'number' => ['N', null, false, [], false, null, 'decimal', 'decimal:2', ['nullable', 'numeric']],
            'multiple number' => ['N', null, true, [], false, null, 'decimal', 'decimal:2', ['nullable', 'numeric', 'array']],
            'file' => ['F', null, false, [], false, null, 'file', null, ['nullable', 'file']],
            'multiple file' => ['F', null, true, [], false, null, 'file', 'array', ['nullable', 'file']],
            'html' => ['S', 'HTML', false, [], false, null, 'text', null, ['nullable']],
            'multiple html' => ['S', 'HTML', true, [], false, null, 'text', null, ['nullable', 'array']],
            'list' => ['L', null, false, ['red', 'blue'], false, null, 'string', null, ['nullable', 'in:red,blue']],
            'list without values' => ['L', null, false, [], false, null, 'string', null, ['nullable']],
            'multiple list' => ['L', null, true, ['red', 'blue'], false, null, 'json', 'array', ['nullable', 'array', 'array']],
            'multiple list without values' => ['L', null, true, [], false, null, 'json', 'array', ['nullable', 'array']],
            'element link' => ['E', null, false, [], true, 'belongsTo', 'foreign_key', null, ['nullable']],
            'multiple element link' => ['E', null, true, [], true, 'belongsToMany', 'pivot', null, ['nullable', 'array']],
            'section link' => ['G', null, false, [], true, 'belongsTo', 'foreign_key', null, ['nullable']],
            'multiple section link' => ['G', null, true, [], true, 'belongsToMany', 'pivot', null, ['nullable', 'array']],
            'directory' => ['S', 'directory', false, [], true, 'belongsTo', 'foreign_key', null, ['nullable']],
            'multiple directory' => ['S', 'directory', true, [], true, 'belongsToMany', 'pivot', null, ['nullable', 'array']],
            'date' => ['S', 'Date', false, [], false, null, 'date', 'date', ['nullable', 'date']],
            'date and time' => ['S', 'DateTime', false, [], false, null, 'datetime', 'datetime', ['nullable', 'date']],
            'checkbox' => ['S', 'SASDCheckbox', false, [], false, null, 'boolean', 'boolean', ['nullable']],
            'numeric checkbox' => ['N', 'SASDCheckboxNum', false, [], false, null, 'decimal', 'decimal:2', ['nullable', 'numeric']],
            'map' => ['S', 'map_yandex', false, [], false, null, 'json', 'array', ['nullable']],
            'google map' => ['S', 'map_google', false, [], false, null, 'json', 'array', ['nullable']],
            'video' => ['S', 'video', false, [], false, null, 'string', null, ['nullable']],
        ];
    }

    /**
     * @param  list<string>  $enum
     * @param  list<string>  $rules
     */
    #[DataProvider('properties')]
    public function test_a_property_is_analyzed_by_its_type(string $type, ?string $userType, bool $multiple, array $enum, bool $relation, ?string $relationType, string $storage, ?string $cast, array $rules): void
    {
        $property = self::property($type, $userType, $multiple, [], $enum);

        $this->assertSame($relation, $this->analyzer->isRelation($property));
        $this->assertSame($relationType, $this->analyzer->getRelationType($property));
        $this->assertSame($storage, $this->analyzer->getStorageType($property));
        $this->assertSame($cast, $this->analyzer->getCastType($property));
        $this->assertSame($rules, $this->analyzer->getValidationRules($property));
        $this->assertSame($property->needsPivotTable(), $this->analyzer->shouldUsePivotTable($property));
    }

    public function test_the_summary_of_a_property_puts_it_all_together(): void
    {
        $property = self::property('E', null, true, ['IS_REQUIRED' => 'Y', 'NAME' => 'Related', 'CODE' => 'RELATED']);

        $this->assertSame([
            'code' => 'RELATED',
            'name' => 'Related',
            'base_type' => 'E',
            'user_type' => null,
            'full_type' => 'E',
            'multiple' => true,
            'required' => true,
            'needs_pivot' => true,
            'is_relation' => true,
            'relation_type' => 'belongsToMany',
            'storage_type' => 'pivot',
            'cast_type' => null,
            'validation_rules' => ['required', 'array'],
        ], $this->analyzer->analyzeProperty($property));
    }

    public function test_a_required_property_is_required_not_nullable(): void
    {
        $this->assertSame(['required', 'numeric'], $this->analyzer->getValidationRules(self::property('N', null, false, ['IS_REQUIRED' => 'Y'])));
    }

    /**
     * @return array<string,array{0:string,1:bool,2:bool,3:?string}>
     */
    public static function indexes(): array
    {
        return [
            'filterable string' => ['S', true, false, 'index'],
            'smart filter number' => ['N', false, true, 'index'],
            'filterable element link' => ['E', true, false, 'foreign'],
            'filterable section link' => ['G', false, true, 'foreign'],
            'neither' => ['S', false, false, null],
        ];
    }

    #[DataProvider('indexes')]
    public function test_filterable_properties_get_an_index(string $type, bool $filtrable, bool $smart, ?string $index): void
    {
        $property = self::property($type, null, false, ['FILTRABLE' => $filtrable ? 'Y' : 'N', 'SMART_FILTER' => $smart ? 'Y' : 'N']);

        $this->assertSame($index, $this->analyzer->getRecommendedIndex($property));
    }

    public function test_a_filterable_directory_gets_a_foreign_index(): void
    {
        $this->assertSame('foreign', $this->analyzer->getRecommendedIndex(self::property('S', 'directory', false, ['FILTRABLE' => 'Y'])));
    }

    public function test_a_searchable_property_gets_a_fulltext_index_unless_it_is_filterable(): void
    {
        $this->assertSame('fulltext', $this->analyzer->getRecommendedIndex(self::property('S', null, false, ['SEARCHABLE' => 'Y'])));
        $this->assertSame('index', $this->analyzer->getRecommendedIndex(self::property('S', null, false, ['SEARCHABLE' => 'Y', 'FILTRABLE' => 'Y'])));
        $this->assertNull($this->analyzer->getRecommendedIndex(self::property('S')));
    }

    /**
     * [type, multiple, enum] => [storage, cast, rules].
     *
     * @return array<string,array{0:string,1:bool,2:list<string>,3:string,4:?string,5:list<string>}>
     */
    public static function fields(): array
    {
        return [
            'string' => ['string', false, [], 'string', null, ['nullable']],
            'multiple string' => ['string', true, [], 'json', 'array', ['nullable', 'array']],
            'file' => ['file', false, [], 'file', null, ['nullable', 'file']],
            'multiple file' => ['file', true, [], 'file', 'array', ['nullable', 'file']],
            'html' => ['html', false, [], 'text', null, ['nullable']],
            'enumeration' => ['enumeration', false, ['s', 'm'], 'string', null, ['nullable', 'in:s,m']],
            'enumeration without values' => ['enumeration', false, [], 'string', null, ['nullable']],
            'multiple enumeration' => ['enumeration', true, ['s', 'm'], 'json', 'array', ['nullable', 'array', 'array']],
            'hlblock' => ['hlblock', false, [], 'foreign_key', null, ['nullable']],
            'multiple hlblock' => ['hlblock', true, [], 'json', 'array', ['nullable', 'array']],
            'date' => ['date', false, [], 'date', 'date', ['nullable', 'date']],
            'datetime' => ['datetime', false, [], 'datetime', 'datetime', ['nullable', 'date']],
            'integer' => ['integer', false, [], 'integer', 'integer', ['nullable', 'integer']],
            'double' => ['double', false, [], 'decimal', 'decimal:2', ['nullable', 'numeric']],
            'boolean' => ['boolean', false, [], 'boolean', 'boolean', ['nullable', 'boolean']],
            'url' => ['url', false, [], 'string', null, ['nullable', 'url']],
            'multiple url' => ['url', true, [], 'json', 'array', ['nullable', 'url', 'array']],
        ];
    }

    /**
     * @param  list<string>  $enum
     * @param  list<string>  $rules
     */
    #[DataProvider('fields')]
    public function test_a_highload_field_is_analyzed_by_its_type(string $type, bool $multiple, array $enum, string $storage, ?string $cast, array $rules): void
    {
        $field = self::field($type, $multiple, false, $enum);

        $this->assertSame($storage, $this->analyzer->getFieldStorageType($field));
        $this->assertSame($cast, $this->analyzer->getFieldCastType($field));
        $this->assertSame($rules, $this->analyzer->getFieldValidationRules($field));
    }

    public function test_the_summary_of_a_field_puts_it_all_together(): void
    {
        $this->assertSame([
            'field_name' => 'UF_COLOR_CODE',
            'column_name' => 'color_code',
            'type' => 'hlblock',
            'multiple' => false,
            'mandatory' => true,
            'is_relation' => true,
            'storage_type' => 'foreign_key',
            'cast_type' => null,
            'validation_rules' => ['required'],
        ], $this->analyzer->analyzeField(self::field('hlblock', false, true)));

        $this->assertFalse($this->analyzer->analyzeField(self::field('string'))['is_relation']);
    }
}
