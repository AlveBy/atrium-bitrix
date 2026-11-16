<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Analyzers;

use Atrium\Bitrix\Schema\HighloadBlockField;
use Atrium\Bitrix\Schema\IBlockProperty;

class PropertyAnalyzer
{
    /**
     * Analyze property and determine its characteristics
     */
    public function analyzeProperty(IBlockProperty $property): array
    {
        return [
            'code' => $property->code,
            'name' => $property->name,
            'base_type' => $property->propertyType,
            'user_type' => $property->userType,
            'full_type' => $property->getFullType(),
            'multiple' => $property->multiple,
            'required' => $property->isRequired,
            'needs_pivot' => $property->needsPivotTable(),
            'is_relation' => $this->isRelation($property),
            'relation_type' => $this->getRelationType($property),
            'storage_type' => $this->getStorageType($property),
            'cast_type' => $this->getCastType($property),
            'validation_rules' => $this->getValidationRules($property),
        ];
    }

    /**
     * Analyze HL block field
     */
    public function analyzeField(HighloadBlockField $field): array
    {
        return [
            'field_name' => $field->fieldName,
            'column_name' => $field->getColumnName(),
            'type' => $field->userTypeId,
            'multiple' => $field->multiple,
            'mandatory' => $field->mandatory,
            'is_relation' => $field->isHlblockReference(),
            'storage_type' => $this->getFieldStorageType($field),
            'cast_type' => $this->getFieldCastType($field),
            'validation_rules' => $this->getFieldValidationRules($field),
        ];
    }

    /**
     * Check if property is a relation
     */
    public function isRelation(IBlockProperty $property): bool
    {
        return $property->isElementLink()
            || $property->isSectionLink()
            || $property->isDirectory();
    }

    /**
     * Get relation type for property
     */
    public function getRelationType(IBlockProperty $property): ?string
    {
        if (! $this->isRelation($property)) {
            return null;
        }

        if ($property->multiple) {
            return 'belongsToMany';
        }

        return 'belongsTo';
    }

    /**
     * Get storage type for property
     */
    public function getStorageType(IBlockProperty $property): string
    {
        // Files are stored as IDs, actual files handled separately
        if ($property->isFile()) {
            return 'file';
        }

        // HTML content
        if ($property->isHtml()) {
            return 'text';
        }

        // Lists are stored as enum values
        if ($property->isList()) {
            return $property->multiple ? 'json' : 'string';
        }

        // Relations
        if ($property->isElementLink() || $property->isSectionLink()) {
            return $property->multiple ? 'pivot' : 'foreign_key';
        }

        // Directories (HL block references)
        if ($property->isDirectory()) {
            return $property->multiple ? 'pivot' : 'foreign_key';
        }

        // Dates
        if ($property->isDate()) {
            return 'date';
        }

        if ($property->isDateTime()) {
            return 'datetime';
        }

        // Numbers
        if ($property->isNumber()) {
            return 'decimal';
        }

        // Checkboxes
        if ($property->isCheckbox()) {
            return 'boolean';
        }

        // Maps (coordinates)
        if ($property->isMap()) {
            return 'json';
        }

        // Default: string or json for multiple
        return $property->multiple ? 'json' : 'string';
    }

    /**
     * Get Laravel cast type for property
     */
    public function getCastType(IBlockProperty $property): ?string
    {
        if ($property->isNumber()) {
            return 'decimal:2';
        }

        if ($property->isCheckbox()) {
            return 'boolean';
        }

        if ($property->isDate()) {
            return 'date';
        }

        if ($property->isDateTime()) {
            return 'datetime';
        }

        if ($property->isHtml()) {
            return null; // No cast needed for HTML
        }

        if ($property->isList() && $property->multiple) {
            return 'array';
        }

        if ($property->isMap()) {
            return 'array';
        }

        if ($property->multiple && ! $this->isRelation($property)) {
            return 'array';
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

        if ($property->isList()) {
            $enumValues = array_column($property->enumValues, 'xmlId');
            if (! empty($enumValues)) {
                if ($property->multiple) {
                    $rules[] = 'array';
                } else {
                    $rules[] = 'in:'.implode(',', $enumValues);
                }
            }
        }

        if ($property->multiple && ! $property->isFile()) {
            $rules[] = 'array';
        }

        return $rules;
    }

    /**
     * Get storage type for HL block field
     */
    public function getFieldStorageType(HighloadBlockField $field): string
    {
        if ($field->isFile()) {
            return 'file';
        }

        if ($field->isHtml()) {
            return 'text';
        }

        if ($field->isEnum()) {
            return $field->multiple ? 'json' : 'string';
        }

        if ($field->isHlblockReference()) {
            return $field->multiple ? 'json' : 'foreign_key';
        }

        if ($field->isDate()) {
            return 'date';
        }

        if ($field->isDateTime()) {
            return 'datetime';
        }

        if ($field->isInteger()) {
            return 'integer';
        }

        if ($field->isDouble()) {
            return 'decimal';
        }

        if ($field->isBoolean()) {
            return 'boolean';
        }

        return $field->multiple ? 'json' : 'string';
    }

    /**
     * Get Laravel cast type for HL block field
     */
    public function getFieldCastType(HighloadBlockField $field): ?string
    {
        if ($field->isInteger()) {
            return 'integer';
        }

        if ($field->isDouble()) {
            return 'decimal:2';
        }

        if ($field->isBoolean()) {
            return 'boolean';
        }

        if ($field->isDate()) {
            return 'date';
        }

        if ($field->isDateTime()) {
            return 'datetime';
        }

        if ($field->isEnum() && $field->multiple) {
            return 'array';
        }

        if ($field->multiple) {
            return 'array';
        }

        return null;
    }

    /**
     * Get validation rules for HL block field
     */
    public function getFieldValidationRules(HighloadBlockField $field): array
    {
        $rules = [];

        if ($field->mandatory) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        if ($field->isInteger()) {
            $rules[] = 'integer';
        }

        if ($field->isDouble()) {
            $rules[] = 'numeric';
        }

        if ($field->isBoolean()) {
            $rules[] = 'boolean';
        }

        if ($field->isDate() || $field->isDateTime()) {
            $rules[] = 'date';
        }

        if ($field->isFile()) {
            $rules[] = 'file';
        }

        if ($field->isUrl()) {
            $rules[] = 'url';
        }

        if ($field->isEnum()) {
            $enumValues = array_column($field->enumValues, 'xmlId');
            if (! empty($enumValues)) {
                if ($field->multiple) {
                    $rules[] = 'array';
                } else {
                    $rules[] = 'in:'.implode(',', $enumValues);
                }
            }
        }

        if ($field->multiple && ! $field->isFile()) {
            $rules[] = 'array';
        }

        return $rules;
    }

    /**
     * Determine if property should be stored in main table or pivot
     */
    public function shouldUsePivotTable(IBlockProperty $property): bool
    {
        return $property->needsPivotTable();
    }

    /**
     * Get recommended index for property
     */
    public function getRecommendedIndex(IBlockProperty $property): ?string
    {
        if ($property->filtrable || $property->smartFilter) {
            if ($property->isElementLink() || $property->isSectionLink() || $property->isDirectory()) {
                return 'foreign';
            }

            return 'index';
        }

        if ($property->searchable) {
            return 'fulltext';
        }

        return null;
    }
}
