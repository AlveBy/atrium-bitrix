<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * User fields of iblock sections (UF_*).
 *
 * Element properties live in b_iblock_property, but section fields are stored
 * separately: in b_user_field with an ENTITY_ID like `IBLOCK_{id}_SECTION`, and
 * their values in the table `b_uts_iblock_{id}_section`. The migrator used to
 * ignore them entirely, so neither the fields nor their data made it to the
 * new site.
 */
final class SectionUserFields
{
    /** @param array<int,array{name:string,column:string,type:string,multiple:bool,label:string,enum:array<int,array{code:string,label:string}>}> $fields */
    private function __construct(private readonly array $fields) {}

    /** Empty set, used when there is no Bitrix connection. */
    public static function empty(): self
    {
        return new self([]);
    }

    public static function load(ConnectionInterface $connection, int $iblockId, NamingConvention $naming): self
    {
        $rows = $connection->table('b_user_field')
            ->where('ENTITY_ID', 'IBLOCK_'.$iblockId.'_SECTION')
            ->orderBy('SORT')
            ->orderBy('ID')
            ->get();

        $fields = [];

        foreach ($rows as $row) {
            $label = $connection->table('b_user_field_lang')
                ->where('USER_FIELD_ID', $row->ID)
                ->value('EDIT_FORM_LABEL');

            $enum = [];

            if ($row->USER_TYPE_ID === 'enumeration') {
                foreach ($connection->table('b_user_field_enum')->where('USER_FIELD_ID', $row->ID)->orderBy('SORT')->get() as $option) {
                    // Key by XML_ID (e.g. table/list/price) rather than the numeric ID:
                    // IDs change on a repeated migration, the codes stay.
                    $enum[(int) $option->ID] = [
                        'code' => (string) ($option->XML_ID ?: $option->ID),
                        'label' => (string) $option->VALUE,
                    ];
                }
            }

            $fields[] = [
                'name' => (string) $row->FIELD_NAME,
                'column' => $naming->ufFieldToColumnName((string) $row->FIELD_NAME),
                'type' => (string) $row->USER_TYPE_ID,
                'multiple' => ($row->MULTIPLE ?? 'N') === 'Y',
                'label' => (string) ($label ?: $row->FIELD_NAME),
                'enum' => $enum,
            ];
        }

        return new self($fields);
    }

    /** @return array<int,array{name:string,column:string,type:string,multiple:bool,label:string,enum:array}> */
    public function all(): array
    {
        return $this->fields;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * Field values per section: [section_id => [column => value]].
     *
     * @return array<int,array<string,mixed>>
     */
    public function values(ConnectionInterface $connection, int $iblockId): array
    {
        if ($this->fields === []) {
            return [];
        }

        $table = 'b_uts_iblock_'.$iblockId.'_section';

        try {
            $rows = $connection->table($table)->get();
        } catch (\Throwable) {
            // Bitrix creates the values table only once such fields have been defined.
            return [];
        }

        $values = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $sectionId = (int) ($row['VALUE_ID'] ?? 0);

            if ($sectionId === 0) {
                continue;
            }

            foreach ($this->fields as $field) {
                $raw = $row[$field['name']] ?? null;

                if ($raw === null || $raw === '') {
                    continue;
                }

                $values[$sectionId][$field['column']] = $field['enum'] !== []
                    ? ($field['enum'][(int) $raw]['code'] ?? null)
                    : $raw;
            }
        }

        return $values;
    }
}
