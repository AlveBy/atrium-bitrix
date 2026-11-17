<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Generators;

use Atrium\Bitrix\Mappers\FieldTypeMapper;
use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Atrium\Bitrix\Schema\HighloadBlock;
use Atrium\Bitrix\Support\GeneratedPaths;
use Atrium\Bitrix\Support\NamingConvention;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\PsrPrinter;

class ReferenceModelGenerator
{
    private NamingConvention $naming;

    private PropertyTypeMapper $typeMapper;

    private FieldTypeMapper $fieldMapper;

    private string $namespace;

    public function __construct(
        NamingConvention $naming,
        PropertyTypeMapper $typeMapper,
        FieldTypeMapper $fieldMapper
    ) {
        $this->naming = $naming;
        $this->typeMapper = $typeMapper;
        $this->fieldMapper = $fieldMapper;
        $this->namespace = config('atrium-bitrix.reference_namespace', 'App\\Models\\References');
    }

    /**
     * Generate Eloquent model for Highload Block
     */
    public function generate(HighloadBlock $hlblock): string
    {
        $file = new PhpFile;
        $file->setStrictTypes();

        $className = $this->naming->hlblockToModelName($hlblock->name);
        $tableName = $this->naming->hlblockToTableName($hlblock->tableName);

        $namespace = $file->addNamespace($this->namespace);
        $this->addUseStatements($namespace, $hlblock);

        $class = $namespace->addClass($className);
        $class->setExtends('Illuminate\\Database\\Eloquent\\Model');

        // Add interface
        $class->addImplement('Atrium\\Admin\\Contracts\\ReferenceAdministrable');

        // Add trait
        $class->addTrait('Atrium\\Admin\\Traits\\InteractsWithAdmin');

        // Table name - no type hint for PHP 8.4 compatibility with Laravel
        $class->addProperty('table', $tableName)
            ->setProtected();

        // Fillable - no type hint for PHP 8.4 compatibility with Laravel
        $fillable = $this->getFillable($hlblock);
        $class->addProperty('fillable', $fillable)
            ->setProtected();

        // Casts - no type hint for PHP 8.4 compatibility with Laravel
        $casts = $this->getCasts($hlblock);
        if (! empty($casts)) {
            $class->addProperty('casts', $casts)
                ->setProtected();
        }

        // Relationships
        $this->addRelationships($class, $hlblock);

        // Admin methods
        $this->addAdminMethods($class, $hlblock);

        // Display value method
        $this->addDisplayValueMethod($class, $hlblock);

        $printer = new PsrPrinter;

        return $printer->printFile($file);
    }

    /**
     * Add use statements
     */
    private function addUseStatements(PhpNamespace $namespace, HighloadBlock $hlblock): void
    {
        $namespace->addUse('Illuminate\\Database\\Eloquent\\Model');
        $namespace->addUse('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo');
        $namespace->addUse('Atrium\\Admin\\Contracts\\ReferenceAdministrable');
        $namespace->addUse('Atrium\\Admin\\Traits\\InteractsWithAdmin');
        $namespace->addUse('Atrium\\Admin\\Fields\\TextField');
        $namespace->addUse('Atrium\\Admin\\Fields\\NumberField');
        $namespace->addUse('Atrium\\Admin\\Fields\\CheckboxField');
        $namespace->addUse('Atrium\\Admin\\Fields\\SelectField');
        $namespace->addUse('Atrium\\Admin\\Fields\\ImageField');
        $namespace->addUse('Atrium\\Admin\\Columns\\TextColumn');
        $namespace->addUse('Atrium\\Admin\\Columns\\BooleanColumn');
    }

    /**
     * Get fillable fields
     */
    private function getFillable(HighloadBlock $hlblock): array
    {
        $fillable = [];

        foreach ($hlblock->fields as $field) {
            if ($this->typeMapper->isFieldFillable($field)) {
                $fillable[] = $this->naming->ufFieldToColumnName($field->fieldName);
            }
        }

        return $fillable;
    }

    /**
     * Get casts
     */
    private function getCasts(HighloadBlock $hlblock): array
    {
        $casts = [];

        foreach ($hlblock->fields as $field) {
            $cast = $this->typeMapper->fieldToCast($field);
            if ($cast) {
                $columnName = $this->naming->ufFieldToColumnName($field->fieldName);
                $casts[$columnName] = $cast;
            }
        }

        return $casts;
    }

    /**
     * Add relationships
     */
    private function addRelationships(ClassType $class, HighloadBlock $hlblock): void
    {
        foreach ($hlblock->fields as $field) {
            if ($field->isHlblockReference() && $field->linkedHlblockId && ! $field->multiple) {
                $relationName = $this->naming->toRelationName(
                    $this->naming->ufFieldToColumnName($field->fieldName),
                    false
                );
                $columnName = $this->naming->ufFieldToColumnName($field->fieldName);

                // We'll use a generic name since we don't have the linked HL block info
                $class->addMethod($relationName)
                    ->setReturnType('BelongsTo')
                    ->addComment('@return BelongsTo Relationship to linked reference')
                    ->setBody("return \$this->belongsTo(self::class, '{$columnName}');");
            }
        }
    }

    /**
     * Add admin methods
     */
    private function addAdminMethods(ClassType $class, HighloadBlock $hlblock): void
    {
        // getAdminLabel
        $class->addMethod('getAdminLabel')
            ->setStatic()
            ->setReturnType('string')
            ->setBody("return '{$hlblock->name}';");

        // getAdministrationFields
        $fieldsBody = $this->generateFieldsMethod($hlblock);
        $class->addMethod('getAdministrationFields')
            ->setStatic()
            ->setReturnType('array')
            ->setBody($fieldsBody);

        // getAdministrationListColumns
        $columnsBody = $this->generateColumnsMethod($hlblock);
        $class->addMethod('getAdministrationListColumns')
            ->setStatic()
            ->setReturnType('array')
            ->setBody($columnsBody);
    }

    /**
     * Generate fields method body
     */
    private function generateFieldsMethod(HighloadBlock $hlblock): string
    {
        $lines = ['return ['];

        foreach ($hlblock->fields as $field) {
            $fieldConfig = $this->fieldMapper->hlFieldToAdminField($field);
            $fieldLine = $this->generateFieldLine($fieldConfig);
            if ($fieldLine) {
                $lines[] = "    {$fieldLine},";
            }
        }

        $lines[] = '];';

        return implode("\n", $lines);
    }

    /**
     * Generate single field line
     */
    private function generateFieldLine(array $config): string
    {
        $class = $config['class'];
        $name = $config['name'];
        $label = $config['label'];

        // Clean up label (remove UF_ prefix)
        $label = preg_replace('/^UF_/', '', $label);
        $label = ucfirst(strtolower(str_replace('_', ' ', $label)));

        $line = "{$class}::make('{$name}')->label('{$label}')";

        if (! empty($config['required'])) {
            $line .= '->required()';
        }

        if (! empty($config['options'])) {
            $optionsStr = var_export($config['options'], true);
            $optionsStr = str_replace(["\n", '  '], ['', ''], $optionsStr);
            $line .= "->options({$optionsStr})";
        }

        return $line;
    }

    /**
     * Generate columns method body
     */
    private function generateColumnsMethod(HighloadBlock $hlblock): string
    {
        $lines = ['return ['];
        $lines[] = "    TextColumn::make('id')->label('ID')->sortable(),";

        $columnCount = 0;
        foreach ($hlblock->fields as $field) {
            if ($columnCount >= 4) {
                break;
            }

            $columnConfig = $this->fieldMapper->hlFieldToListColumn($field);
            $class = $columnConfig['class'];
            $name = $columnConfig['name'];
            $label = preg_replace('/^UF_/', '', $field->fieldName);
            $label = ucfirst(strtolower(str_replace('_', ' ', $label)));

            $line = "    {$class}::make('{$name}')->label('{$label}')";

            if ($columnConfig['sortable']) {
                $line .= '->sortable()';
            }

            $lines[] = "{$line},";
            $columnCount++;
        }

        $lines[] = '];';

        return implode("\n", $lines);
    }

    /**
     * Add display value method (for dropdowns)
     */
    private function addDisplayValueMethod(ClassType $class, HighloadBlock $hlblock): void
    {
        // Try to find a name-like field
        $nameField = null;
        foreach ($hlblock->fields as $field) {
            $columnName = strtolower($this->naming->ufFieldToColumnName($field->fieldName));
            if (in_array($columnName, ['name', 'title', 'value', 'xml_id'])) {
                $nameField = $columnName;
                break;
            }
        }

        if (! $nameField && ! empty($hlblock->fields)) {
            // Use first string field
            foreach ($hlblock->fields as $field) {
                if ($field->isString()) {
                    $nameField = $this->naming->ufFieldToColumnName($field->fieldName);
                    break;
                }
            }
        }

        $nameField = $nameField ?: 'id';

        $class->addMethod('getDisplayValue')
            ->setReturnType('string')
            ->setBody("return (string) \$this->{$nameField};");

        // Add __toString
        $class->addMethod('__toString')
            ->setReturnType('string')
            ->setBody('return $this->getDisplayValue();');
    }

    /**
     * Get the generated file path
     */
    public function getFilePath(HighloadBlock $hlblock): string
    {
        $className = $this->naming->hlblockToModelName($hlblock->name);

        return GeneratedPaths::directory('reference_path', $this->namespace).'/'.$className.'.php';
    }
}
