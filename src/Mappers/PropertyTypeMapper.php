<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Mappers;

use Atrium\Bitrix\Schema\HighloadBlockField;
use Atrium\Bitrix\Schema\IBlockProperty;

/**
 * Bitrix never enforced IS_REQUIRED at the storage layer — it is a form-level
 * flag — and existing rows routinely hold NULL for "required" properties. Making
 * the column NOT NULL from it produced hard 500s on create
 * ("Field 'photos' doesn't have a default value"). Columns are therefore always
 * nullable; the requirement is carried by the admin field's ->required(), which
 * is now enforced by real server-side validation.
 */
class PropertyTypeMapper
{
    /**
     * Map IBlock property to Laravel migration column type
     */
    public function toMigrationColumn(IBlockProperty $property): array
    {
        $type = $property->getFullType();

        // Handle multiple properties that need JSON storage
        if ($property->multiple && ! $property->needsPivotTable()) {
            return $this->getJsonColumn($property);
        }

        // Relations use foreign keys
        if ($property->isElementLink() || $property->isSectionLink()) {
            if ($property->multiple) {
                return ['type' => 'pivot', 'nullable' => true];
            }

            return [
                'type' => 'foreignId',
                'nullable' => true,
                'constrained' => false, // Will be set during generation
            ];
        }

        // Directory (HL block reference)
        if ($property->isDirectory()) {
            if ($property->multiple) {
                return ['type' => 'pivot', 'nullable' => true];
            }

            return [
                'type' => 'foreignId',
                'nullable' => true,
                'constrained' => false,
            ];
        }

        // Map by full type
        return match ($type) {
            'S:HTML' => ['type' => 'longText', 'nullable' => true],
            'S:Date' => ['type' => 'date', 'nullable' => true],
            'S:DateTime' => ['type' => 'datetime', 'nullable' => true],
            'S:map_yandex', 'S:map_google' => ['type' => 'json', 'nullable' => true],
            // SASDCheckbox* is used on both string and numeric properties.
            // Without the N branch, numeric checkboxes got a decimal column
            // and a number input in the admin.
            'S:SASDCheckbox', 'S:SASDCheckboxNum',
            'N:SASDCheckbox', 'N:SASDCheckboxNum' => ['type' => 'boolean', 'default' => false],
            'S:video' => ['type' => 'json', 'nullable' => true],
            'S:UserID' => ['type' => 'unsignedBigInteger', 'nullable' => true],
            'S:SASDPalette' => ['type' => 'string', 'nullable' => true, 'length' => 20],

            // Number type
            'N' => ['type' => 'decimal', 'nullable' => true, 'precision' => 18, 'scale' => 4],

            // List type
            'L' => ['type' => 'string', 'nullable' => true, 'length' => 100],

            // Everything else is a string of 255: plain strings, a single file and
            // whatever Bitrix adds one day. Links never get here: they returned above,
            // and so did a multiple file.
            default => ['type' => 'string', 'nullable' => true, 'length' => 255],
        };
    }

    /**
     * Map HL block field to Laravel migration column type
     */
    public function fieldToMigrationColumn(HighloadBlockField $field): array
    {
        // Handle multiple fields
        if ($field->multiple) {
            return [
                'type' => 'json',
                'nullable' => true,
            ];
        }

        return match ($field->userTypeId) {
            'string' => ['type' => 'string', 'nullable' => true, 'length' => 255],
            'integer' => ['type' => 'integer', 'nullable' => true],
            'double' => ['type' => 'decimal', 'nullable' => true, 'precision' => 18, 'scale' => 4],
            'boolean' => ['type' => 'boolean', 'default' => false],
            'datetime' => ['type' => 'datetime', 'nullable' => true],
            'date' => ['type' => 'date', 'nullable' => true],
            'file' => ['type' => 'string', 'nullable' => true, 'length' => 255],
            'enumeration' => ['type' => 'string', 'nullable' => true, 'length' => 100],
            'hlblock' => ['type' => 'foreignId', 'nullable' => true],
            'html' => ['type' => 'longText', 'nullable' => true],
            'url' => ['type' => 'string', 'nullable' => true, 'length' => 500],
            'address' => ['type' => 'longText', 'nullable' => true],
            'money' => ['type' => 'decimal', 'nullable' => true, 'precision' => 18, 'scale' => 2],
            default => ['type' => 'string', 'nullable' => true, 'length' => 255],
        };
    }

    /**
     * Map IBlock property to Laravel model cast
     */
    public function toCast(IBlockProperty $property): ?string
    {
        if ($property->multiple && ! $property->needsPivotTable()) {
            return 'array';
        }

        $type = $property->getFullType();

        return match ($type) {
            'N' => 'decimal:4',
            'S:Date' => 'date',
            'S:DateTime' => 'datetime',
            'S:SASDCheckbox', 'S:SASDCheckboxNum',
            'N:SASDCheckbox', 'N:SASDCheckboxNum' => 'boolean',
            'S:map_yandex', 'S:map_google' => 'array',
            'S:video' => 'array',
            'F' => $property->multiple ? 'array' : null,
            default => null,
        };
    }

    /**
     * Map HL block field to Laravel model cast
     */
    public function fieldToCast(HighloadBlockField $field): ?string
    {
        if ($field->multiple) {
            return 'array';
        }

        return match ($field->userTypeId) {
            'integer' => 'integer',
            'double', 'money' => 'decimal:4',
            'boolean' => 'boolean',
            'datetime' => 'datetime',
            'date' => 'date',
            default => null,
        };
    }

    /**
     * Get JSON column definition for multiple properties
     */
    protected function getJsonColumn(IBlockProperty $property): array
    {
        return [
            'type' => 'json',
            'nullable' => true,
        ];
    }

    /**
     * Get fillable attribute for property
     */
    public function isFillable(IBlockProperty $property): bool
    {
        // Whatever lives in a pivot table (multiple links, multiple directory values)
        // has no column of its own: the admin form names it after the relation and
        // AdminController::submittedData() takes it from the field list, not from
        // $fillable. Putting a relation name into $fillable would let fill() try to
        // store it as a column.
        // A SINGLE link, however, is a plain foreignId on the table itself
        // (e.g. a "Country" link). Excluding it from fillable produced a field
        // that is rendered and validated but silently never saved.
        if ($property->needsPivotTable()) {
            return false;
        }

        // File properties DO get a real column (see toMigrationColumn(): json when
        // multiple, string otherwise). Excluding them here left ImageField /
        // FileField / GalleryField values out of $fillable, so AdminController's
        // `$request->only($fillable)` silently discarded every upload on save.
        return true;
    }

    /**
     * Get fillable attribute for HL block field
     */
    public function isFieldFillable(HighloadBlockField $field): bool
    {
        // File fields get a real column, same as IBlock file properties — keeping
        // them out of $fillable meant uploads were discarded on save.

        // HL block references may need special handling
        if ($field->isHlblockReference()) {
            return false;
        }

        return true;
    }

    /**
     * Determine if property should have an index
     */
    public function shouldIndex(IBlockProperty $property): bool
    {
        return $property->filtrable || $property->smartFilter || $property->searchable;
    }

    /**
     * Get index type for property
     */
    public function getIndexType(IBlockProperty $property): ?string
    {
        if ($property->isElementLink() || $property->isSectionLink() || $property->isDirectory()) {
            return 'foreign';
        }

        if ($property->searchable && ($property->isString() || $property->isHtml())) {
            return 'fulltext';
        }

        if ($property->filtrable || $property->smartFilter) {
            return 'index';
        }

        return null;
    }

    /**
     * Get validation rules for property
     */
    public function getValidationRules(IBlockProperty $property): array
    {
        $rules = [];

        if ($property->isRequired) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        if ($property->isNumber()) {
            $rules[] = 'numeric';
        }

        if ($property->isDate() || $property->isDateTime()) {
            $rules[] = 'date';
        }

        if ($property->isFile()) {
            $rules[] = 'file';
        }

        if ($property->isList() && ! empty($property->enumValues)) {
            $values = array_map(fn ($e) => $e->xmlId ?: $e->id, $property->enumValues);
            if ($property->multiple) {
                $rules[] = 'array';
            } else {
                $rules[] = 'in:'.implode(',', $values);
            }
        }

        if ($property->multiple && ! $property->isFile()) {
            $rules[] = 'array';
        }

        return $rules;
    }

    /**
     * Get default value for property
     */
    public function getDefaultValue(IBlockProperty $property): mixed
    {
        if ($property->isCheckbox()) {
            return false;
        }

        if ($property->defaultValue !== null) {
            if ($property->isNumber()) {
                return (float) $property->defaultValue;
            }

            return $property->defaultValue;
        }

        return null;
    }
}
