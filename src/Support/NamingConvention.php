<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

use Illuminate\Support\Str;

class NamingConvention
{
    protected array $mappings = [];

    /** @var list<string> */
    protected array $prefixes = [];

    public function __construct()
    {
        $this->mappings = config('atrium-bitrix.naming.mappings', []);
        $this->prefixes = array_values(array_filter((array) config('atrium-bitrix.strip_prefixes', []), 'is_string'));
    }

    /**
     * The iblock code without the theme prefix (config strip_prefixes) and
     * Bitrix's own generic ones.
     */
    public function stripPrefixes(string $code): string
    {
        foreach ($this->prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with(strtolower($code), strtolower($prefix)) && strlen($code) > strlen($prefix)) {
                $code = substr($code, strlen($prefix));
                break;
            }
        }

        return (string) preg_replace('/^(iblock_|ib_|content_)/i', '', $code);
    }

    /**
     * Convert IBlock code to Laravel table name
     */
    public function toTableName(string $code): string
    {
        // Check for custom mapping
        if (isset($this->mappings[$code]['table'])) {
            return $this->mappings[$code]['table'];
        }

        $name = $this->stripPrefixes($code);

        // Normalize to snake_case first, then pluralize
        $snakeName = $this->normalizeToSnakeCase($name);
        $tableName = Str::plural($snakeName);

        // Ensure valid table name (prefix if starts with number)
        if (preg_match('/^[0-9]/', $tableName)) {
            $tableName = 'iblock_'.$tableName;
        }

        return $tableName;
    }

    /**
     * Convert IBlock code to Laravel model name
     */
    public function toModelName(string $code): string
    {
        // Check for custom mapping
        if (isset($this->mappings[$code]['model'])) {
            return $this->mappings[$code]['model'];
        }

        $name = $this->stripPrefixes($code);

        // Normalize to snake_case first, then convert to StudlyCase and singularize
        $snakeName = $this->normalizeToSnakeCase($name);
        $modelName = Str::studly(Str::singular($snakeName));

        // Ensure valid PHP class name (can't start with number)
        if (preg_match('/^[0-9]/', $modelName)) {
            $modelName = 'Iblock'.$modelName;
        }

        return $modelName;
    }

    /**
     * Convert IBlock code to section table name
     */
    public function toSectionTableName(string $code): string
    {
        $tableName = $this->toTableName($code);

        // If table is already plural, add _sections
        // e.g., products -> product_sections
        $singular = Str::singular($tableName);

        return $singular.'_sections';
    }

    /**
     * Convert IBlock code to section model name
     */
    public function toSectionModelName(string $code): string
    {
        $modelName = $this->toModelName($code);

        return $modelName.'Section';
    }

    /**
     * Convert property code to Laravel column name
     */
    public function toColumnName(string $code): string
    {
        // Remove PROPERTY_ prefix if exists
        $name = preg_replace('/^PROPERTY_/', '', $code);

        $result = $this->normalizeToSnakeCase($name);

        // If result is purely numeric (property had no CODE, only ID),
        // prefix with 'prop_' to make a valid column name
        if (preg_match('/^\d+$/', $result)) {
            $result = 'prop_'.$result;
        }

        return $result;
    }

    /**
     * Generate a column code from a property name (transliterate + snake_case).
     * Used as fallback when Bitrix property has no symbolic CODE.
     */
    public function generateCodeFromName(string $name): string
    {
        // Cyrillic capitals would come out as "Ts" + "ENA" and break into letters
        $lowered = preg_replace_callback(
            '/\p{Cyrillic}+/u',
            static fn (array $run): string => mb_strtolower($run[0]),
            $name,
        );

        // Words are what is left between everything that is not a Latin letter or a digit
        $words = preg_split('/[^a-zA-Z0-9]+/', $this->transliterate($lowered), -1, PREG_SPLIT_NO_EMPTY);

        $result = implode('_', array_map($this->normalizeToSnakeCase(...), $words));

        // Ensure doesn't start with a digit
        if (preg_match('/^\d/', $result)) {
            $result = 'prop_'.$result;
        }

        return $result;
    }

    /**
     * Convert property code to Laravel relationship method name
     */
    public function toRelationName(string $code, bool $multiple = false): string
    {
        $name = $this->toColumnName($code);

        // For single relations, use singular; for multiple, use plural
        if ($multiple) {
            return Str::camel(Str::plural($name));
        }

        return Str::camel(Str::singular($name));
    }

    /**
     * Convert Highload Block table name to Laravel table name
     */
    public function hlblockToTableName(string $tableName): string
    {
        // Check for custom mapping
        if (isset($this->mappings[$tableName]['table'])) {
            return $this->mappings[$tableName]['table'];
        }

        // Remove b_hlbd_ prefix
        $name = preg_replace('/^b_hlbd_/', '', $tableName);

        // Normalize and convert to snake_case
        $snakeName = $this->normalizeToSnakeCase($name);

        // Ensure valid table name (prefix if starts with number)
        if (preg_match('/^[0-9]/', $snakeName)) {
            $snakeName = 'hlblock_'.$snakeName;
        }

        return $snakeName;
    }

    /**
     * Convert Highload Block name to Laravel model name
     */
    public function hlblockToModelName(string $name): string
    {
        // Check for custom mapping
        if (isset($this->mappings[$name]['model'])) {
            return $this->mappings[$name]['model'];
        }

        // Remove common prefixes
        $cleanName = preg_replace('/^(hl_|hlblock_)/i', '', $name);

        // Normalize to snake_case first, then convert to StudlyCase
        $snakeName = $this->normalizeToSnakeCase($cleanName);
        $modelName = Str::studly($snakeName);

        // Ensure valid PHP class name (can't start with number)
        if (preg_match('/^[0-9]/', $modelName)) {
            $modelName = 'Hlblock'.$modelName;
        }

        return $modelName;
    }

    /**
     * Convert UF field name to Laravel column name
     */
    public function ufFieldToColumnName(string $fieldName): string
    {
        // Remove UF_ prefix
        $name = preg_replace('/^UF_/', '', $fieldName);

        return $this->normalizeToSnakeCase($name);
    }

    /**
     * Convert enum value to constant name
     */
    public function toConstantName(string $value): string
    {
        // Transliterate Russian characters
        $value = $this->transliterate($value);

        // Convert to SCREAMING_SNAKE_CASE
        $name = Str::upper(Str::snake($value));

        // Remove non-alphanumeric characters except underscore
        $name = preg_replace('/[^A-Z0-9_]/', '', $name);

        // Ensure starts with letter
        if (preg_match('/^[0-9]/', $name)) {
            $name = 'VALUE_'.$name;
        }

        return $name;
    }

    /**
     * Generate pivot table name for many-to-many relations
     */
    public function toPivotTableName(string $table1, string $table2): string
    {
        // Sort alphabetically for consistency
        $tables = [Str::singular($table1), Str::singular($table2)];
        sort($tables);

        return implode('_', $tables);
    }

    /**
     * Transliterate Russian characters to Latin
     */
    public function transliterate(string $text): string
    {
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

        return strtr($text, $transliteration);
    }

    /**
     * Convert IBlock code to URL path
     * E.g. news -> news, with strip_prefixes ['mytheme_']: mytheme_catalog -> catalogs
     */
    public function toUrlPath(string $code): string
    {
        // Check for custom mapping
        if (isset($this->mappings[$code]['url_path'])) {
            return $this->mappings[$code]['url_path'];
        }

        $name = $this->stripPrefixes($code);

        // Normalize to snake_case
        $snakeName = $this->normalizeToSnakeCase($name);

        // Convert to URL-friendly format (kebab-case, plural)
        $urlPath = str_replace('_', '-', Str::plural($snakeName));

        return $urlPath;
    }

    /**
     * Generate URL slug from text
     */
    public function toSlug(string $text): string
    {
        // Transliterate
        $slug = $this->transliterate($text);

        // Convert to lowercase
        $slug = Str::lower($slug);

        // Replace spaces and special characters with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Remove leading/trailing hyphens
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Add custom mapping
     */
    public function addMapping(string $code, array $mapping): void
    {
        $this->mappings[$code] = $mapping;
    }

    /**
     * Get all mappings
     */
    public function getMappings(): array
    {
        return $this->mappings;
    }

    /**
     * Normalize string to snake_case, handling UPPER_SNAKE_CASE correctly
     *
     * Bitrix uses UPPER_SNAKE_CASE for codes (e.g., "LINK_SERVICES")
     * This method correctly converts them to "link_services" instead of "l_i_n_k__s_e_r_v_i_c_e_s"
     */
    protected function normalizeToSnakeCase(string $name): string
    {
        // If already UPPER_SNAKE_CASE (all uppercase with underscores), just lowercase
        if (preg_match('/^[A-Z0-9_]+$/', $name) && str_contains($name, '_')) {
            return strtolower($name);
        }

        // If all uppercase without underscores (e.g., "SERVICES"), just lowercase
        if (preg_match('/^[A-Z0-9]+$/', $name)) {
            return strtolower($name);
        }

        // For mixed case (camelCase, PascalCase), use Str::snake()
        return Str::snake($name);
    }
}
