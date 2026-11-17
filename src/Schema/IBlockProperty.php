<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Schema;

class IBlockProperty
{
    public int $id;

    public int $iblockId;

    public string $code;

    public string $name;

    public string $propertyType;        // S, N, L, F, E, G

    public ?string $userType = null;    // HTML, Date, DateTime, directory, etc.

    public string $listType = 'L';      // L = dropdown list, C = checkboxes/radio buttons

    public bool $multiple = false;

    public bool $isRequired = false;

    public ?int $linkIblockId = null;   // For E type - linked IBlock ID

    public ?string $linkIblockCode = null;

    public ?array $userTypeSettings = null;

    public int $sort = 500;

    public ?int $rowCount = null;       // Row count for textarea

    public ?int $colCount = null;

    public ?string $defaultValue = null;

    public ?string $hint = null;        // Hint text

    public bool $searchable = false;    // Searchable

    public bool $filtrable = false;     // Filtrable

    public bool $smartFilter = false;   // Smart filter

    public ?string $displayType = null; // Display type in smart filter

    public bool $withDescription = false; // Show description field

    // For directories
    public ?string $directoryTableName = null;

    public ?HighloadBlock $directoryHlblock = null;

    // For lists (L type)
    /** @var IBlockPropertyEnum[] */
    public array $enumValues = [];

    public static function fromArray(array $data): self
    {
        $property = new self;
        $property->id = (int) $data['ID'];
        $property->iblockId = (int) $data['IBLOCK_ID'];
        $property->code = ! empty($data['CODE'])
            ? $data['CODE']
            : self::generateCodeFromName($data['NAME'] ?? '', (int) $data['ID']);
        $property->name = $data['NAME'];
        $property->propertyType = $data['PROPERTY_TYPE'];
        $property->listType = ! empty($data['LIST_TYPE']) ? (string) $data['LIST_TYPE'] : 'L';
        $property->userType = $data['USER_TYPE'] ?? null;
        $property->multiple = ($data['MULTIPLE'] ?? 'N') === 'Y';
        $property->isRequired = ($data['IS_REQUIRED'] ?? 'N') === 'Y';
        $property->linkIblockId = ! empty($data['LINK_IBLOCK_ID']) ? (int) $data['LINK_IBLOCK_ID'] : null;
        $property->sort = (int) ($data['SORT'] ?? 500);
        $property->rowCount = ! empty($data['ROW_COUNT']) ? (int) $data['ROW_COUNT'] : null;
        $property->colCount = ! empty($data['COL_COUNT']) ? (int) $data['COL_COUNT'] : null;
        $property->defaultValue = $data['DEFAULT_VALUE'] ?? null;
        $property->hint = $data['HINT'] ?? null;
        $property->searchable = ($data['SEARCHABLE'] ?? 'N') === 'Y';
        $property->filtrable = ($data['FILTRABLE'] ?? 'N') === 'Y';
        $property->smartFilter = ($data['SMART_FILTER'] ?? 'N') === 'Y';
        $property->displayType = $data['DISPLAY_TYPE'] ?? null;
        $property->withDescription = ($data['WITH_DESCRIPTION'] ?? 'N') === 'Y';

        // USER_TYPE_SETTINGS
        if (! empty($data['USER_TYPE_SETTINGS'])) {
            $settings = is_string($data['USER_TYPE_SETTINGS'])
                ? @unserialize($data['USER_TYPE_SETTINGS'])
                : $data['USER_TYPE_SETTINGS'];
            $property->userTypeSettings = is_array($settings) ? $settings : null;

            // For directories
            if ($property->userType === 'directory' && isset($property->userTypeSettings['TABLE_NAME'])) {
                $property->directoryTableName = $property->userTypeSettings['TABLE_NAME'];
            }
        }

        return $property;
    }

    // Type checks
    public function isString(): bool
    {
        return $this->propertyType === 'S';
    }

    public function isNumber(): bool
    {
        return $this->propertyType === 'N';
    }

    public function isList(): bool
    {
        return $this->propertyType === 'L';
    }

    public function isFile(): bool
    {
        return $this->propertyType === 'F';
    }

    public function isElementLink(): bool
    {
        return $this->propertyType === 'E';
    }

    public function isSectionLink(): bool
    {
        return $this->propertyType === 'G';
    }

    public function isHtml(): bool
    {
        return $this->userType === 'HTML';
    }

    public function isDate(): bool
    {
        return $this->userType === 'Date';
    }

    public function isDateTime(): bool
    {
        return $this->userType === 'DateTime';
    }

    public function isDirectory(): bool
    {
        return $this->userType === 'directory';
    }

    public function isMap(): bool
    {
        return in_array($this->userType, ['map_yandex', 'map_google']);
    }

    public function isCheckbox(): bool
    {
        return in_array($this->userType, ['SASDCheckbox', 'SASDCheckboxNum']);
    }

    /**
     * A list that Bitrix renders as checkboxes/radio buttons rather than a
     * dropdown (LIST_TYPE='C').
     */
    public function isCheckboxList(): bool
    {
        return $this->propertyType === 'L' && $this->listType === 'C';
    }

    /**
     * The classic Bitrix "tick box": a list with a single value displayed as
     * a checkbox. This is how flags such as "Show on the home page" are
     * usually defined.
     */
    public function isSingleValueCheckbox(): bool
    {
        return $this->isCheckboxList() && ! $this->multiple && count($this->enumValues) === 1;
    }

    public function isVideo(): bool
    {
        return $this->userType === 'video';
    }

    public function isUser(): bool
    {
        return $this->userType === 'UserID';
    }

    public function isPalette(): bool
    {
        return $this->userType === 'SASDPalette';
    }

    public function getDirectoryTableName(): ?string
    {
        return $this->directoryTableName;
    }

    public function addEnumValue(IBlockPropertyEnum $enum): void
    {
        $this->enumValues[] = $enum;
    }

    /**
     * Get full type identifier (for mapping)
     */
    public function getFullType(): string
    {
        if ($this->userType) {
            return $this->propertyType.':'.$this->userType;
        }

        return $this->propertyType;
    }

    /**
     * Check if property needs pivot table
     */
    public function needsPivotTable(): bool
    {
        if (! $this->multiple) {
            return false;
        }

        return $this->isElementLink() || $this->isSectionLink() || $this->isDirectory();
    }

    /**
     * Generate a symbolic CODE from property NAME (transliterate Russian → Latin, UPPER_SNAKE_CASE).
     * Used as fallback when Bitrix property has no CODE set.
     */
    protected static function generateCodeFromName(string $name, int $id): string
    {
        if ($name === '') {
            return 'PROPERTY_'.$id;
        }

        $transliteration = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
            'Е' => 'E', 'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
            'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
            'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
            'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts', 'Ч' => 'Ch',
            'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
            'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
        ];

        $transliterated = strtr($name, $transliteration);

        // Replace non-alphanumeric with underscores, uppercase
        $code = preg_replace('/[^a-zA-Z0-9]+/', '_', $transliterated);
        $code = strtoupper(trim($code, '_'));

        if ($code === '' || preg_match('/^\d/', $code)) {
            return 'PROPERTY_'.$id;
        }

        return $code;
    }
}
