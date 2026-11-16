<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Mappers;

use Atrium\Bitrix\Schema\HighloadBlockField;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Support\NamingConvention;

class FieldTypeMapper
{
    protected NamingConvention $naming;

    public function __construct(?NamingConvention $naming = null)
    {
        $this->naming = $naming ?? new NamingConvention;
    }

    /**
     * Map IBlock property to admin panel field class
     */
    public function toAdminField(IBlockProperty $property): array
    {
        $type = $property->getFullType();

        // Determine base field type
        $fieldClass = $this->mapPropertyToFieldClass($property);

        // Build field configuration - use snake_case for field names
        $config = [
            'class' => $fieldClass,
            'name' => $this->naming->toColumnName($property->code),
            'label' => $property->name,
            'required' => $property->isRequired,
            'multiple' => $property->multiple,
            'hint' => $property->hint,
        ];

        // Add type-specific configuration
        $config = array_merge($config, $this->getFieldConfig($property));

        return $config;
    }

    /**
     * Map HL block field to admin panel field class
     */
    public function hlFieldToAdminField(HighloadBlockField $field): array
    {
        $fieldClass = $this->mapHlFieldToFieldClass($field);

        $config = [
            'class' => $fieldClass,
            'name' => $field->getColumnName(),
            'label' => $field->fieldName,
            'required' => $field->mandatory,
            'multiple' => $field->multiple,
        ];

        // Add type-specific configuration
        $config = array_merge($config, $this->getHlFieldConfig($field));

        return $config;
    }

    /**
     * Map IBlock property to field class name
     */
    protected function mapPropertyToFieldClass(IBlockProperty $property): string
    {
        $type = $property->getFullType();

        // Handle multiple values with RepeatableField
        if ($property->multiple) {
            // Some types have their own multiple handling
            $multipleFieldClass = match ($type) {
                // Multiple HTML properties (e.g. per-day descriptions): Bitrix gives
                // each value its own editor. A single ContentField doesn't fit here:
                // it showed only the first value from the json and didn't let
                // you add a second one.
                'S:HTML' => 'RepeatableField',
                'S:Date' => 'RepeatableField',
                'S:DateTime' => 'RepeatableField',
                'S:directory' => 'BelongsToManyField',
                default => null,
            };

            if ($multipleFieldClass) {
                return $multipleFieldClass;
            }

            // Handle multiple by base type
            return match ($property->propertyType) {
                'S' => 'RepeatableField', // Multiple strings
                'N' => 'RepeatableField', // Multiple numbers
                'L' => 'MultiSelectField', // Multiple select from list
                'F' => 'GalleryField', // Multiple files
                'E' => 'BelongsToManyField', // Multiple element links
                'G' => 'TreeSelectField', // Section link (usually single but can be multiple)
                default => 'RepeatableField',
            };
        }

        // Handle by full type first (single values)
        $fieldClass = match ($type) {
            'S:HTML' => 'ContentField',
            'S:Date' => 'DateField',
            'S:DateTime' => 'DateTimeField',
            'S:map_yandex', 'S:map_google' => 'MapField',
            // SASDCheckboxNum is used on both string and numeric properties:
            // numeric (N) flag properties would otherwise turn into a number
            // input instead of a checkbox without the N branch.
            'S:SASDCheckbox', 'S:SASDCheckboxNum',
            'N:SASDCheckbox', 'N:SASDCheckboxNum' => 'CheckboxField',
            'S:video' => 'TextField', // Could be VideoField if implemented
            'S:UserID' => 'BelongsToField',
            'S:SASDPalette' => 'ColorField',
            'S:directory' => 'BelongsToField',
            default => null,
        };

        if ($fieldClass) {
            return $fieldClass;
        }

        // Handle by base type (single values)
        return match ($property->propertyType) {
            'S' => $property->rowCount && $property->rowCount > 1 ? 'TextareaField' : 'TextField',
            'N' => 'NumberField',
            // A single-value list with LIST_TYPE='C' is Bitrix's checkbox
            // (e.g. "Show on homepage"), not a dropdown with one option.
            'L' => $property->isSingleValueCheckbox() ? 'CheckboxField' : 'SelectField',
            'F' => 'ImageField',
            'E' => 'BelongsToField',
            'G' => 'TreeSelectField',
            default => 'TextField',
        };
    }

    /**
     * Map HL block field to field class name
     */
    protected function mapHlFieldToFieldClass(HighloadBlockField $field): string
    {
        return match ($field->userTypeId) {
            'string' => 'TextField',
            'integer' => 'NumberField',
            'double', 'money' => 'NumberField',
            'boolean' => 'CheckboxField',
            'datetime' => 'DateTimeField',
            'date' => 'DateField',
            'file' => $field->multiple ? 'GalleryField' : 'ImageField',
            'enumeration' => $field->multiple ? 'MultiSelectField' : 'SelectField',
            'hlblock' => $field->multiple ? 'BelongsToManyField' : 'BelongsToField',
            'html' => 'ContentField',
            'url' => 'TextField',
            'address' => 'TextareaField',
            default => 'TextField',
        };
    }

    /**
     * Get field-specific configuration for IBlock property
     */
    protected function getFieldConfig(IBlockProperty $property): array
    {
        $config = [];

        // List properties - add options
        if ($property->isList() && ! empty($property->enumValues)) {
            $config['options'] = [];
            foreach ($property->enumValues as $enum) {
                $label = $enum->value;

                $config['options'][] = [
                    'value' => $enum->xmlId ?: (string) $enum->id,
                    'label' => $label,
                ];
            }

            // The checkbox stores the option code, not a boolean: public templates
            // compare against the string ($item->form_order === 'YES').
            if ($property->isSingleValueCheckbox()) {
                $config['checkedValue'] = $config['options'][0]['value'];
            }
        }

        // File properties
        if ($property->isFile()) {
            $config['accept'] = $this->getFileAcceptType($property);
            $config['maxSize'] = config('atrium.uploads.max_size', 5120);
        }

        // Element link - add relation config and model for BelongsToField
        if ($property->isElementLink() && $property->linkIblockId) {
            $config['relation'] = [
                'type' => 'element',
                'iblock_id' => $property->linkIblockId,
                'iblock_code' => $property->linkIblockCode,
            ];
            // Add model name for BelongsToField/BelongsToManyField
            if ($property->linkIblockCode) {
                $config['model'] = $this->naming->toModelName($property->linkIblockCode);
            }
        }

        // Section link (type G) - add model for TreeSelectField
        if ($property->isSectionLink()) {
            $config['relation'] = [
                'type' => 'section',
            ];
            // Add model name for TreeSelectField
            if ($property->linkIblockCode) {
                $config['model'] = $this->naming->toSectionModelName($property->linkIblockCode);
            }
        }

        // Directory (HL block)
        if ($property->isDirectory()) {
            $config['relation'] = [
                'type' => 'directory',
                'table_name' => $property->directoryTableName,
            ];
            if ($property->directoryHlblock) {
                $config['relation']['hlblock_id'] = $property->directoryHlblock->id;
                $config['relation']['hlblock_name'] = $property->directoryHlblock->name;
                // Add model name for BelongsToField/BelongsToManyField
                $config['model'] = 'References\\'.$this->naming->hlblockToModelName($property->directoryHlblock->name);
            }
        }

        // Number field precision
        if ($property->isNumber()) {
            $config['step'] = 0.0001;
        }

        // RepeatableField itemType for multiple values
        if ($property->multiple) {
            $type = $property->getFullType();
            $itemType = match ($type) {
                'S:Date' => 'date',
                'S:DateTime' => 'datetime',
                // Each value gets its own editor with a Text/HTML/Visual
                // switch, as in Bitrix.
                'S:HTML' => 'content',
                default => match ($property->propertyType) {
                    'N' => 'number',
                    'S' => 'text',
                    default => 'text',
                },
            };
            $config['itemType'] = $itemType;
        }

        // Map field type
        if ($property->isMap()) {
            $config['mapType'] = $property->userType === 'map_yandex' ? 'yandex' : 'google';
        }

        // Textarea rows
        if ($property->rowCount) {
            $config['rows'] = $property->rowCount;
        }

        // Default value
        if ($property->defaultValue !== null) {
            $config['default'] = $property->defaultValue;
        }

        return $config;
    }

    /**
     * Get field-specific configuration for HL block field
     */
    protected function getHlFieldConfig(HighloadBlockField $field): array
    {
        $config = [];

        // Enumeration - add options
        if ($field->isEnum() && ! empty($field->enumValues)) {
            $config['options'] = [];
            foreach ($field->enumValues as $enum) {
                $config['options'][] = [
                    'value' => $enum->xmlId ?: (string) $enum->id,
                    'label' => $enum->value,
                ];
            }
        }

        // File fields
        if ($field->isFile()) {
            $config['accept'] = 'image/*';
            $config['maxSize'] = config('atrium.uploads.max_size', 5120);
        }

        // HL block reference
        if ($field->isHlblockReference() && $field->linkedHlblockId) {
            $config['relation'] = [
                'type' => 'hlblock',
                'hlblock_id' => $field->linkedHlblockId,
            ];
        }

        // Number precision
        if ($field->isDouble() || $field->isMoney()) {
            $config['step'] = $field->isMoney() ? 0.01 : 0.0001;
        }

        return $config;
    }

    /**
     * Get file accept type based on property settings
     */
    protected function getFileAcceptType(IBlockProperty $property): string
    {
        // Check user type settings for file type restrictions
        if (! empty($property->userTypeSettings['FILE_TYPE'])) {
            $fileType = $property->userTypeSettings['FILE_TYPE'];

            // Common patterns
            if (stripos($fileType, 'image') !== false || preg_match('/jpg|jpeg|png|gif|webp/i', $fileType)) {
                return 'image/*';
            }

            if (stripos($fileType, 'pdf') !== false) {
                return '.pdf';
            }

            if (stripos($fileType, 'doc') !== false) {
                return '.doc,.docx,.pdf';
            }
        }

        // Default to images
        return 'image/*';
    }

    /**
     * Map property to list column configuration
     */
    public function toListColumn(IBlockProperty $property): array
    {
        $columnClass = match (true) {
            $property->isFile() => 'ImageColumn',
            $property->isCheckbox() || $this->isYesNoEnum($property) => 'BooleanColumn',
            $property->isDate() || $property->isDateTime() => 'DateColumn',
            default => 'TextColumn',
        };

        return [
            'class' => $columnClass,
            'name' => $this->naming->toColumnName($property->code),
            'label' => $property->name,
            'sortable' => ! $property->multiple && ! $property->isFile(),
            'searchable' => $property->searchable,
        ];
    }

    /**
     * Map HL field to list column configuration
     */
    public function hlFieldToListColumn(HighloadBlockField $field): array
    {
        $columnClass = match (true) {
            $field->isFile() => 'ImageColumn',
            $field->isBoolean() => 'BooleanColumn',
            $field->isDate() || $field->isDateTime() => 'DateColumn',
            default => 'TextColumn',
        };

        return [
            'class' => $columnClass,
            'name' => $field->getColumnName(),
            'label' => $field->fieldName,
            'sortable' => ! $field->multiple && ! $field->isFile(),
        ];
    }

    /**
     * Get full field class name with namespace
     */
    public function getFieldClassName(string $shortName): string
    {
        return "Atrium\\Admin\\Fields\\{$shortName}";
    }

    /**
     * Get full column class name with namespace
     */
    public function getColumnClassName(string $shortName): string
    {
        return "Atrium\\Admin\\Columns\\{$shortName}";
    }

    /**
     * Check if property is a YES/NO enumeration (for BooleanColumn)
     */
    protected function isYesNoEnum(IBlockProperty $property): bool
    {
        if (! $property->isList() || empty($property->enumValues)) {
            return false;
        }

        // Must have exactly 2 values
        if (count($property->enumValues) !== 2) {
            return false;
        }

        $values = array_map(
            fn ($enum) => mb_strtoupper(trim($enum->value)),
            $property->enumValues
        );

        // Check for YES/NO or Y/N or ДА/НЕТ patterns
        $yesNoPatterns = [
            ['YES', 'NO'],
            ['Y', 'N'],
            ['ДА', 'НЕТ'],
            ['Д', 'Н'],
        ];

        sort($values);

        foreach ($yesNoPatterns as $pattern) {
            sort($pattern);
            if ($values === $pattern) {
                return true;
            }
        }

        return false;
    }
}
