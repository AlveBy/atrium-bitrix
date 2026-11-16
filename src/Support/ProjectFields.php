<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

/**
 * Fields that do not exist in Bitrix but are needed by the project.
 *
 * The generator builds a model from an iblock and cannot know about such
 * columns, since they come from a separate migration. That used to mean any
 * regeneration silently dropped them, breaking whatever relied on them.
 *
 * Declare them once in config/atrium-bitrix.php and regeneration stops
 * being destructive.
 */
final class ProjectFields
{
    /**
     * @return array<string,array{cast?:string,field?:string,column?:string,tab?:string,after?:string,fillable?:bool}>
     */
    public static function forTable(string $table): array
    {
        $all = config('atrium-bitrix.extra_fields', []);

        return is_array($all) && isset($all[$table]) && is_array($all[$table]) ? $all[$table] : [];
    }

    /**
     * Column names to add to $fillable.
     *
     * @return array<int,string>
     */
    public static function fillableFor(string $table): array
    {
        $names = [];

        foreach (self::forTable($table) as $name => $spec) {
            if (($spec['fillable'] ?? true) === true) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array<string,string> column => cast
     */
    public static function castsFor(string $table): array
    {
        $casts = [];

        foreach (self::forTable($table) as $name => $spec) {
            if (! empty($spec['cast'])) {
                $casts[$name] = $spec['cast'];
            }
        }

        return $casts;
    }

    /**
     * Ready-made list column line for a project field.
     *
     * The generator takes the column label and type from the iblock property,
     * and project fields have no property, so without this the column would
     * be labelled with its raw name.
     */
    public static function listColumnLineFor(string $table, string $column): ?string
    {
        $spec = self::forTable($table)[$column] ?? null;

        return empty($spec['column']) ? null : (string) $spec['column'];
    }

    /**
     * The iblock targeted by a link property without LINK_IBLOCK_ID.
     *
     * In Bitrix an empty LINK_IBLOCK_ID means "any iblock": an element can be
     * picked from anywhere, and the property settings do not reveal the target.
     * Eloquent cannot do that, since a relation needs exactly one class. Where
     * the property has been filled in, the target is visible in the values;
     * where it has not, it can only be declared here.
     *
     * The key is the iblock code, the value maps property code => target iblock code.
     */
    public static function elementLinkTargetFor(string $iblockCode, string $propertyCode): ?string
    {
        $all = config('atrium-bitrix.element_link_targets', []);

        if (! is_array($all) || ! isset($all[$iblockCode]) || ! is_array($all[$iblockCode])) {
            return null;
        }

        $target = $all[$iblockCode][$propertyCode] ?? null;

        return is_string($target) && $target !== '' ? $target : null;
    }

    /**
     * Columns not shown in the list even though Bitrix showed them.
     *
     * @return array<int,string>
     */
    public static function hiddenListColumnsFor(string $table): array
    {
        $all = config('atrium-bitrix.hidden_list_columns', []);

        if (! is_array($all) || ! isset($all[$table]) || ! is_array($all[$table])) {
            return [];
        }

        return array_values(array_map('strval', $all[$table]));
    }

    /**
     * Columns added to the list on top of the Bitrix layout.
     *
     * @return array<int,string>
     */
    public static function extraListColumnsFor(string $table): array
    {
        $all = config('atrium-bitrix.extra_list_columns', []);

        if (! is_array($all) || ! isset($all[$table]) || ! is_array($all[$table])) {
            return [];
        }

        return array_values(array_map('strval', $all[$table]));
    }

    /**
     * Final set and order of list columns.
     *
     * A single method, because two consumers rely on this order: the model
     * generator and the `atrium:bitrix:parity` audit. If they diverged, the
     * audit would start complaining about what it prescribed itself.
     *
     * @param  array<int,string>  $columns  columns from the Bitrix layout
     * @return array<int,string>
     */
    public static function applyListOverrides(array $columns, string $table): array
    {
        $hidden = self::hiddenListColumnsFor($table);

        $result = array_values(array_filter(
            $columns,
            static fn (string $column): bool => ! in_array($column, $hidden, true)
        ));

        $extra = array_values(array_filter(
            self::extraListColumnsFor($table),
            static fn (string $column): bool => ! in_array($column, $result, true)
        ));

        if ($extra === []) {
            return $result;
        }

        // Right after the name: in a list of submissions what matters is who
        // left one, not the iblock's service fields.
        $at = array_search('name', $result, true);
        $at = $at === false ? count($result) : $at + 1;

        array_splice($result, $at, 0, $extra);

        return $result;
    }

    /**
     * Form field lines: [['name','line','tab','after'], …]
     *
     * @return array<int,array{name:string,line:string,tab:string,after:?string}>
     */
    public static function adminFieldsFor(string $table): array
    {
        $fields = [];

        foreach (self::forTable($table) as $name => $spec) {
            if (empty($spec['field'])) {
                continue;
            }

            $fields[] = [
                'name' => $name,
                'line' => (string) $spec['field'],
                'tab' => (string) ($spec['tab'] ?? 'params'),
                'after' => isset($spec['after']) ? (string) $spec['after'] : null,
            ];
        }

        return $fields;
    }
}
