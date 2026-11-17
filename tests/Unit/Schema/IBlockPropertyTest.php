<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Schema;

use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockPropertyEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IBlockPropertyTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $extra
     */
    private function property(array $extra = []): IBlockProperty
    {
        return IBlockProperty::fromArray([
            'ID' => 7, 'IBLOCK_ID' => 3, 'NAME' => 'Price', 'CODE' => 'PRICE', 'PROPERTY_TYPE' => 'S', ...$extra,
        ]);
    }

    public function test_a_full_row_is_read_into_the_property(): void
    {
        $property = $this->property([
            'ID' => '12', 'IBLOCK_ID' => '4', 'LIST_TYPE' => 'C', 'USER_TYPE' => 'HTML', 'MULTIPLE' => 'Y',
            'IS_REQUIRED' => 'Y', 'LINK_IBLOCK_ID' => '9', 'SORT' => '42', 'ROW_COUNT' => '5', 'COL_COUNT' => '60',
            'DEFAULT_VALUE' => 'none', 'HINT' => 'In euro', 'SEARCHABLE' => 'Y', 'FILTRABLE' => 'Y',
            'SMART_FILTER' => 'Y', 'DISPLAY_TYPE' => 'F', 'WITH_DESCRIPTION' => 'Y',
        ]);

        $this->assertSame(12, $property->id);
        $this->assertSame(4, $property->iblockId);
        $this->assertSame('PRICE', $property->code);
        $this->assertSame('Price', $property->name);
        $this->assertSame('S', $property->propertyType);
        $this->assertSame('C', $property->listType);
        $this->assertSame('HTML', $property->userType);
        $this->assertTrue($property->multiple);
        $this->assertTrue($property->isRequired);
        $this->assertSame(9, $property->linkIblockId);
        $this->assertSame(42, $property->sort);
        $this->assertSame(5, $property->rowCount);
        $this->assertSame(60, $property->colCount);
        $this->assertSame('none', $property->defaultValue);
        $this->assertSame('In euro', $property->hint);
        $this->assertTrue($property->searchable);
        $this->assertTrue($property->filtrable);
        $this->assertTrue($property->smartFilter);
        $this->assertSame('F', $property->displayType);
        $this->assertTrue($property->withDescription);
        $this->assertNull($property->userTypeSettings);
    }

    public function test_a_minimal_row_falls_back_to_the_defaults(): void
    {
        $property = $this->property(['LIST_TYPE' => '', 'LINK_IBLOCK_ID' => 0, 'ROW_COUNT' => 0, 'COL_COUNT' => null]);

        $this->assertSame('L', $property->listType);
        $this->assertNull($property->userType);
        $this->assertFalse($property->multiple);
        $this->assertFalse($property->isRequired);
        $this->assertNull($property->linkIblockId);
        $this->assertSame(500, $property->sort);
        $this->assertNull($property->rowCount);
        $this->assertNull($property->colCount);
        $this->assertNull($property->defaultValue);
        $this->assertNull($property->hint);
        $this->assertFalse($property->searchable);
        $this->assertFalse($property->filtrable);
        $this->assertFalse($property->smartFilter);
        $this->assertNull($property->displayType);
        $this->assertFalse($property->withDescription);
        $this->assertNull($property->getDirectoryTableName());
    }

    public function test_flags_other_than_y_are_off(): void
    {
        $property = $this->property(['MULTIPLE' => 'y', 'IS_REQUIRED' => 'N', 'SEARCHABLE' => '1']);

        $this->assertFalse($property->multiple);
        $this->assertFalse($property->isRequired);
        $this->assertFalse($property->searchable);
    }

    public function test_serialized_settings_of_a_directory_name_its_table(): void
    {
        $property = $this->property([
            'USER_TYPE' => 'directory',
            'USER_TYPE_SETTINGS' => serialize(['size' => 1, 'TABLE_NAME' => 'b_hlbd_colors']),
        ]);

        $this->assertSame(['size' => 1, 'TABLE_NAME' => 'b_hlbd_colors'], $property->userTypeSettings);
        $this->assertSame('b_hlbd_colors', $property->getDirectoryTableName());
    }

    public function test_settings_given_as_an_array_are_kept_as_they_are(): void
    {
        $property = $this->property(['USER_TYPE' => 'directory', 'USER_TYPE_SETTINGS' => ['TABLE_NAME' => 'b_hlbd_sizes']]);

        $this->assertSame(['TABLE_NAME' => 'b_hlbd_sizes'], $property->userTypeSettings);
        $this->assertSame('b_hlbd_sizes', $property->getDirectoryTableName());
    }

    public function test_a_table_name_in_the_settings_of_anything_but_a_directory_is_ignored(): void
    {
        $property = $this->property(['USER_TYPE' => 'HTML', 'USER_TYPE_SETTINGS' => ['TABLE_NAME' => 'b_hlbd_colors']]);

        $this->assertNull($property->getDirectoryTableName());
    }

    public function test_a_directory_without_a_table_name_has_none(): void
    {
        $property = $this->property(['USER_TYPE' => 'directory', 'USER_TYPE_SETTINGS' => serialize(['size' => 1])]);

        $this->assertNull($property->getDirectoryTableName());
    }

    /**
     * @return array<string,array{0:string,1:string,2:int,3:string}>
     */
    public static function namesWithoutCode(): array
    {
        return [
            'Latin words' => ['Show on home page', '', 5, 'SHOW_ON_HOME_PAGE'],
            'Cyrillic lower case' => ['цена тура', '', 5, 'TSENA_TURA'],
            'Cyrillic capitals' => ['ЦЕНА', '', 5, 'TSENA'],
            'every soft and hard sign dropped' => ['Объём', '', 5, 'OBYOM'],
            'whole Cyrillic alphabet' => ['абвгдеёжзийклмнопрстуфхцчшщъыьэюя', '', 5, 'ABVGDEYOZHZIYKLMNOPRSTUFHTSCHSHSCHYEYUYA'],
            'whole capital alphabet' => ['АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ', '', 5, 'ABVGDEYOZHZIYKLMNOPRSTUFHTSCHSHSCHYEYUYA'],
            'punctuation squeezed to one underscore' => ['  Price, € / night!  ', '', 5, 'PRICE_NIGHT'],
            'empty name' => ['', '', 5, 'PROPERTY_5'],
            'missing code key falls back to the name' => ['Size', 'missing', 6, 'SIZE'],
            'only symbols' => ['—!?', '', 8, 'PROPERTY_8'],
            'Chinese has no transliteration' => ['价格', '', 9, 'PROPERTY_9'],
            'emoji only' => ['🪑', '', 10, 'PROPERTY_10'],
            'starts with a digit' => ['3D tour', '', 11, 'PROPERTY_11'],
            'digit inside is fine' => ['Tour 3D', '', 12, 'TOUR_3D'],
        ];
    }

    #[DataProvider('namesWithoutCode')]
    public function test_a_property_without_code_gets_one_from_its_name(string $name, string $code, int $id, string $expected): void
    {
        $row = ['ID' => $id, 'IBLOCK_ID' => 1, 'NAME' => $name, 'PROPERTY_TYPE' => 'S'];

        if ($code !== 'missing') {
            $row['CODE'] = $code;
        }

        $this->assertSame($expected, IBlockProperty::fromArray($row)->code);
    }

    public function test_a_code_wins_over_the_name(): void
    {
        $this->assertSame('my_code', $this->property(['CODE' => 'my_code', 'NAME' => 'Цена'])->code);
    }

    /**
     * @return array<string,array{0:string,1:?string,2:list<string>}>
     */
    public static function types(): array
    {
        return [
            'string' => ['S', null, ['isString']],
            'number' => ['N', null, ['isNumber']],
            'list' => ['L', null, ['isList']],
            'file' => ['F', null, ['isFile']],
            'element link' => ['E', null, ['isElementLink']],
            'section link' => ['G', null, ['isSectionLink']],
            'html' => ['S', 'HTML', ['isString', 'isHtml']],
            'date' => ['S', 'Date', ['isString', 'isDate']],
            'date and time' => ['S', 'DateTime', ['isString', 'isDateTime']],
            'directory' => ['S', 'directory', ['isString', 'isDirectory']],
            'yandex map' => ['S', 'map_yandex', ['isString', 'isMap']],
            'google map' => ['S', 'map_google', ['isString', 'isMap']],
            'checkbox' => ['S', 'SASDCheckbox', ['isString', 'isCheckbox']],
            'numeric checkbox' => ['N', 'SASDCheckboxNum', ['isNumber', 'isCheckbox']],
            'video' => ['S', 'video', ['isString', 'isVideo']],
            'user' => ['S', 'UserID', ['isString', 'isUser']],
            'palette' => ['S', 'SASDPalette', ['isString', 'isPalette']],
            'lower-case html is not html' => ['S', 'html', ['isString']],
            'unknown user type' => ['S', 'Whatever', ['isString']],
        ];
    }

    /**
     * @param  list<string>  $true
     */
    #[DataProvider('types')]
    public function test_each_type_check_answers_for_its_own_type_only(string $type, ?string $userType, array $true): void
    {
        $property = $this->property(['PROPERTY_TYPE' => $type, 'USER_TYPE' => $userType]);

        foreach ([
            'isString', 'isNumber', 'isList', 'isFile', 'isElementLink', 'isSectionLink', 'isHtml', 'isDate',
            'isDateTime', 'isDirectory', 'isMap', 'isCheckbox', 'isVideo', 'isUser', 'isPalette',
        ] as $check) {
            $this->assertSame(in_array($check, $true, true), $property->{$check}(), $check);
        }
    }

    public function test_the_full_type_joins_the_user_type(): void
    {
        $this->assertSame('S', $this->property()->getFullType());
        $this->assertSame('S:HTML', $this->property(['USER_TYPE' => 'HTML'])->getFullType());
        $this->assertSame('E', $this->property(['PROPERTY_TYPE' => 'E', 'USER_TYPE' => ''])->getFullType());
    }

    public function test_a_list_shown_as_checkboxes_with_one_value_is_a_tick_box(): void
    {
        $property = $this->property(['PROPERTY_TYPE' => 'L', 'LIST_TYPE' => 'C']);
        $this->assertTrue($property->isCheckboxList());
        $this->assertFalse($property->isSingleValueCheckbox(), 'no values yet');

        $property->addEnumValue(IBlockPropertyEnum::fromArray(['ID' => 1, 'PROPERTY_ID' => 7, 'VALUE' => 'YES', 'XML_ID' => 'Y']));
        $this->assertTrue($property->isSingleValueCheckbox());
        $this->assertCount(1, $property->enumValues);

        $property->addEnumValue(IBlockPropertyEnum::fromArray(['ID' => 2, 'PROPERTY_ID' => 7, 'VALUE' => 'NO', 'XML_ID' => 'N']));
        $this->assertFalse($property->isSingleValueCheckbox(), 'two values are a radio group');
    }

    public function test_a_multiple_tick_box_or_a_dropdown_is_not_a_tick_box(): void
    {
        $enum = IBlockPropertyEnum::fromArray(['ID' => 1, 'PROPERTY_ID' => 7, 'VALUE' => 'YES']);

        $multiple = $this->property(['PROPERTY_TYPE' => 'L', 'LIST_TYPE' => 'C', 'MULTIPLE' => 'Y']);
        $multiple->addEnumValue($enum);
        $this->assertTrue($multiple->isCheckboxList());
        $this->assertFalse($multiple->isSingleValueCheckbox());

        $dropdown = $this->property(['PROPERTY_TYPE' => 'L', 'LIST_TYPE' => 'L']);
        $dropdown->addEnumValue($enum);
        $this->assertFalse($dropdown->isCheckboxList());
        $this->assertFalse($dropdown->isSingleValueCheckbox());

        $string = $this->property(['PROPERTY_TYPE' => 'S', 'LIST_TYPE' => 'C']);
        $this->assertFalse($string->isCheckboxList());
    }

    /**
     * @return array<string,array{0:string,1:?string,2:string,3:bool}>
     */
    public static function pivots(): array
    {
        return [
            'multiple element link' => ['E', null, 'Y', true],
            'multiple section link' => ['G', null, 'Y', true],
            'multiple directory' => ['S', 'directory', 'Y', true],
            'single element link' => ['E', null, 'N', false],
            'single directory' => ['S', 'directory', 'N', false],
            'multiple string' => ['S', null, 'Y', false],
            'multiple file' => ['F', null, 'Y', false],
        ];
    }

    #[DataProvider('pivots')]
    public function test_only_multiple_links_need_a_pivot_table(string $type, ?string $userType, string $multiple, bool $pivot): void
    {
        $this->assertSame($pivot, $this->property(['PROPERTY_TYPE' => $type, 'USER_TYPE' => $userType, 'MULTIPLE' => $multiple])->needsPivotTable());
    }

    public function test_an_enum_row_is_read_with_defaults(): void
    {
        $enum = IBlockPropertyEnum::fromArray(['ID' => '3', 'PROPERTY_ID' => '7', 'VALUE' => 'Чёрный', 'XML_ID' => 'black', 'SORT' => '20', 'DEF' => 'Y']);
        $this->assertSame([3, 7, 'Чёрный', 'black', 20, true], [$enum->id, $enum->propertyId, $enum->value, $enum->xmlId, $enum->sort, $enum->isDefault]);

        $bare = IBlockPropertyEnum::fromArray(['ID' => 4, 'PROPERTY_ID' => 7, 'VALUE' => 'White']);
        $this->assertSame([null, 500, false], [$bare->xmlId, $bare->sort, $bare->isDefault]);
    }
}
