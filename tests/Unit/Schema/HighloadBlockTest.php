<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Schema;

use Atrium\Bitrix\Schema\HighloadBlock;
use Atrium\Bitrix\Schema\HighloadBlockEnumValue;
use Atrium\Bitrix\Schema\HighloadBlockField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HighloadBlockTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $extra
     */
    private function field(string $type, array $extra = []): HighloadBlockField
    {
        return HighloadBlockField::fromArray([
            'ID' => 1, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_NAME', 'USER_TYPE_ID' => $type, ...$extra,
        ]);
    }

    public function test_a_field_row_is_read_with_its_settings(): void
    {
        $field = $this->field('hlblock', [
            'ID' => '5', 'XML_ID' => 'colour', 'SORT' => '30', 'MULTIPLE' => 'Y', 'MANDATORY' => 'Y',
            'SETTINGS' => serialize(['HLBLOCK_ID' => '4', 'DISPLAY' => 'LIST']),
        ]);

        $this->assertSame(5, $field->id);
        $this->assertSame('HLBLOCK_1', $field->entityId);
        $this->assertSame('UF_NAME', $field->fieldName);
        $this->assertSame('hlblock', $field->userTypeId);
        $this->assertSame('colour', $field->xmlId);
        $this->assertSame(30, $field->sort);
        $this->assertTrue($field->multiple);
        $this->assertTrue($field->mandatory);
        $this->assertSame(['HLBLOCK_ID' => '4', 'DISPLAY' => 'LIST'], $field->settings);
        $this->assertSame(4, $field->linkedHlblockId);
    }

    public function test_a_bare_field_row_takes_the_defaults(): void
    {
        $field = $this->field('string');

        $this->assertNull($field->xmlId);
        $this->assertSame(100, $field->sort);
        $this->assertFalse($field->multiple);
        $this->assertFalse($field->mandatory);
        $this->assertNull($field->settings);
        $this->assertNull($field->linkedHlblockId);
    }

    public function test_settings_given_as_an_array_are_kept_and_a_link_is_only_for_hlblock_fields(): void
    {
        $string = $this->field('string', ['SETTINGS' => ['HLBLOCK_ID' => 4]]);
        $this->assertSame(['HLBLOCK_ID' => 4], $string->settings);
        $this->assertNull($string->linkedHlblockId);

        $unlinked = $this->field('hlblock', ['SETTINGS' => ['DISPLAY' => 'LIST']]);
        $this->assertNull($unlinked->linkedHlblockId);
    }

    public function test_unreadable_settings_leave_the_field_without_any(): void
    {
        $field = $this->field('hlblock', ['SETTINGS' => 's:9:"broken']);

        $this->assertNull($field->settings);
        $this->assertNull($field->linkedHlblockId);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function columns(): array
    {
        return [
            'prefix dropped' => ['UF_COLOR_CODE', 'color_code'],
            'no prefix' => ['NAME', 'name'],
            'prefix only at the start' => ['MY_UF_FIELD', 'my_uf_field'],
            'lower-case prefix kept' => ['uf_name', 'uf_name'],
        ];
    }

    #[DataProvider('columns')]
    public function test_the_column_name_drops_the_uf_prefix(string $fieldName, string $column): void
    {
        $this->assertSame($column, $this->field('string', ['FIELD_NAME' => $fieldName])->getColumnName());
    }

    /**
     * @return array<string,array{0:string,1:list<string>}>
     */
    public static function types(): array
    {
        return [
            'string' => ['string', ['isString']],
            'integer' => ['integer', ['isInteger']],
            'double' => ['double', ['isDouble']],
            'boolean' => ['boolean', ['isBoolean']],
            'datetime' => ['datetime', ['isDateTime']],
            'date is also a date and time' => ['date', ['isDateTime', 'isDate']],
            'file' => ['file', ['isFile']],
            'enumeration' => ['enumeration', ['isEnum']],
            'hlblock' => ['hlblock', ['isHlblockReference']],
            'html' => ['html', ['isHtml']],
            'url' => ['url', ['isUrl']],
            'address' => ['address', ['isAddress']],
            'money' => ['money', ['isMoney']],
            'unknown' => ['crm', []],
        ];
    }

    /**
     * @param  list<string>  $true
     */
    #[DataProvider('types')]
    public function test_each_field_type_check_answers_for_its_own_type_only(string $type, array $true): void
    {
        $field = $this->field($type);

        foreach ([
            'isString', 'isInteger', 'isDouble', 'isBoolean', 'isDateTime', 'isDate', 'isFile', 'isEnum',
            'isHlblockReference', 'isHtml', 'isUrl', 'isAddress', 'isMoney',
        ] as $check) {
            $this->assertSame(in_array($check, $true, true), $field->{$check}(), $check);
        }

        $this->assertSame($type, $field->getFullType());
    }

    public function test_enum_values_are_read_and_collected(): void
    {
        $value = HighloadBlockEnumValue::fromArray(['ID' => '2', 'USER_FIELD_ID' => '9', 'VALUE' => 'Красный', 'XML_ID' => 'red', 'SORT' => '10', 'DEF' => 'Y']);
        $this->assertSame([2, 9, 'Красный', 'red', 10, true], [$value->id, $value->fieldId, $value->value, $value->xmlId, $value->sort, $value->isDefault]);

        $bare = HighloadBlockEnumValue::fromArray(['ID' => 3, 'USER_FIELD_ID' => 9, 'VALUE' => 'Blue', 'DEF' => 'N']);
        $this->assertSame([null, 500, false], [$bare->xmlId, $bare->sort, $bare->isDefault]);

        $field = $this->field('enumeration');
        $field->addEnumValue($value);
        $field->addEnumValue($bare);
        $this->assertSame([$value, $bare], $field->enumValues);
    }

    public function test_a_block_finds_its_fields_by_name_and_kind(): void
    {
        $block = HighloadBlock::fromArray(['ID' => '3', 'NAME' => 'Colors', 'TABLE_NAME' => 'b_hlbd_colors']);
        $this->assertSame([3, 'Colors', 'b_hlbd_colors', []], [$block->id, $block->name, $block->tableName, $block->fields]);
        $this->assertFalse($block->hasFileFields());
        $this->assertNull($block->getFieldByName('UF_NAME'));

        $name = $this->field('string', ['FIELD_NAME' => 'UF_NAME']);
        $file = $this->field('file', ['FIELD_NAME' => 'UF_FILE']);
        $kind = $this->field('enumeration', ['FIELD_NAME' => 'UF_KIND']);
        $block->addField($name);
        $block->addField($file);
        $block->addField($kind);

        $this->assertSame($file, $block->getFieldByName('UF_FILE'));
        $this->assertSame($name, $block->getFieldByName('UF_NAME'));
        $this->assertNull($block->getFieldByName('uf_name'));
        $this->assertTrue($block->hasFileFields());
        $this->assertSame([1 => $file], $block->getFileFields());
        $this->assertSame([2 => $kind], $block->getEnumFields());
    }

    public function test_a_block_with_no_file_field_says_so(): void
    {
        $block = HighloadBlock::fromArray(['ID' => 1, 'NAME' => 'Brands', 'TABLE_NAME' => 'b_hlbd_brands']);
        $block->addField($this->field('string'));
        $block->addField($this->field('integer'));

        $this->assertFalse($block->hasFileFields());
        $this->assertSame([], $block->getFileFields());
        $this->assertSame([], $block->getEnumFields());
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function blockNames(): array
    {
        return [
            'plain' => ['brands', 'Brands', 'brands'],
            'hl_ prefix' => ['hl_colors', 'Colors', 'colors'],
            'hlblock_ prefix' => ['hlblock_sizes', 'Sizes', 'sizes'],
            'b_hlbd_ prefix' => ['b_hlbd_marks', 'Marks', 'marks'],
            'prefix in capitals' => ['HL_Colors', 'Colors', 'colors'],
            'prefix only at the start' => ['my_hl_colors', 'My_hl_colors', 'my_hl_colors'],
            'mixed case kept in the model name' => ['ColorReference', 'ColorReference', 'colorreference'],
        ];
    }

    #[DataProvider('blockNames')]
    public function test_the_model_and_table_names_drop_the_prefix(string $name, string $model, string $table): void
    {
        $block = HighloadBlock::fromArray(['ID' => 1, 'NAME' => $name, 'TABLE_NAME' => 'b_hlbd_x']);

        $this->assertSame($model, $block->getModelName());
        $this->assertSame($table, $block->getLaravelTableName());
    }
}
