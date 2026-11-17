<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Schema;

class HighloadBlockField
{
    public int $id;

    public string $entityId;

    public string $fieldName;

    public string $userTypeId;        // string, integer, boolean, double, datetime, file, enumeration, hlblock

    public ?string $xmlId = null;

    public int $sort = 100;

    public bool $multiple = false;

    public bool $mandatory = false;

    public ?array $settings = null;

    // For enumeration type
    /** @var HighloadBlockEnumValue[] */
    public array $enumValues = [];

    // For hlblock type (reference to another HL block)
    public ?int $linkedHlblockId = null;

    public static function fromArray(array $data): self
    {
        $field = new self;
        $field->id = (int) $data['ID'];
        $field->entityId = $data['ENTITY_ID'];
        $field->fieldName = $data['FIELD_NAME'];
        $field->userTypeId = $data['USER_TYPE_ID'];
        $field->xmlId = $data['XML_ID'] ?? null;
        $field->sort = (int) ($data['SORT'] ?? 100);
        $field->multiple = ($data['MULTIPLE'] ?? 'N') === 'Y';
        $field->mandatory = ($data['MANDATORY'] ?? 'N') === 'Y';

        // Parse settings
        if (! empty($data['SETTINGS'])) {
            $settings = is_string($data['SETTINGS'])
                ? @unserialize($data['SETTINGS'])
                : $data['SETTINGS'];
            $field->settings = is_array($settings) ? $settings : null;

            // For hlblock reference
            if ($field->userTypeId === 'hlblock' && isset($field->settings['HLBLOCK_ID'])) {
                $field->linkedHlblockId = (int) $field->settings['HLBLOCK_ID'];
            }
        }

        return $field;
    }

    public function addEnumValue(HighloadBlockEnumValue $value): void
    {
        $this->enumValues[] = $value;
    }

    /**
     * Get column name for Laravel (without UF_ prefix)
     */
    public function getColumnName(): string
    {
        // Remove UF_ prefix and convert to snake_case
        $name = preg_replace('/^UF_/', '', $this->fieldName);

        return strtolower($name);
    }

    // Type checks
    public function isString(): bool
    {
        return $this->userTypeId === 'string';
    }

    public function isInteger(): bool
    {
        return $this->userTypeId === 'integer';
    }

    public function isDouble(): bool
    {
        return $this->userTypeId === 'double';
    }

    public function isBoolean(): bool
    {
        return $this->userTypeId === 'boolean';
    }

    public function isDateTime(): bool
    {
        return in_array($this->userTypeId, ['datetime', 'date']);
    }

    public function isDate(): bool
    {
        return $this->userTypeId === 'date';
    }

    public function isFile(): bool
    {
        return $this->userTypeId === 'file';
    }

    public function isEnum(): bool
    {
        return $this->userTypeId === 'enumeration';
    }

    public function isHlblockReference(): bool
    {
        return $this->userTypeId === 'hlblock';
    }

    public function isHtml(): bool
    {
        return $this->userTypeId === 'html';
    }

    public function isUrl(): bool
    {
        return $this->userTypeId === 'url';
    }

    public function isAddress(): bool
    {
        return $this->userTypeId === 'address';
    }

    public function isMoney(): bool
    {
        return $this->userTypeId === 'money';
    }

    /**
     * Get full type identifier
     */
    public function getFullType(): string
    {
        return $this->userTypeId;
    }
}
