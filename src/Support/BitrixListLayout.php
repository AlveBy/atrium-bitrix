<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * The set of list columns for elements, from the Bitrix admin.
 *
 * It sits next to the form layout, in b_user_option, but under CATEGORY='list'
 * and with the name `tbl_iblock_list_{md5("{IBLOCK_TYPE}.{ID}")}`. The value is
 * a serialized array: `columns` (column codes, comma-separated, in display
 * order), `by`/`order` (default sort) and `page_size`.
 *
 * Without it the generator used id/name/active plus "the first three
 * properties", which gave arbitrary columns instead of the ones (image, prices
 * and so on) that the old admin actually showed.
 */
final class BitrixListLayout
{
    /** Bitrix base columns => our fields. */
    private const BASE_COLUMNS = [
        'ID' => 'id',
        'NAME' => 'name',
        'CODE' => 'code',
        'ACTIVE' => 'active',
        'SORT' => 'sort',
        'TIMESTAMP_X' => 'updated_at',
        'DATE_CREATE' => 'created_at',
        'PREVIEW_PICTURE' => 'preview_picture',
        'DETAIL_PICTURE' => 'detail_picture',
        'PREVIEW_TEXT' => 'preview_text',
        'DATE_ACTIVE_FROM' => 'active_from',
        'DATE_ACTIVE_TO' => 'active_to',
    ];

    /**
     * @param  array<int,array{name:string,label:?string,property:bool}>  $columns
     */
    private function __construct(
        private readonly array $columns,
        private readonly ?string $sortBy,
        private readonly string $sortOrder,
        private readonly int $pageSize,
    ) {}

    /**
     * @param  array<int,string>  $propertyCodes  property ID => CODE
     */
    public static function load(
        ConnectionInterface $connection,
        string $iblockType,
        int $iblockId,
        array $propertyCodes,
        NamingConvention $naming
    ): self {
        $raw = $connection->table('b_user_option')
            ->where('CATEGORY', 'list')
            ->where('NAME', 'tbl_iblock_list_'.md5($iblockType.'.'.$iblockId))
            ->value('VALUE');

        $data = is_string($raw) ? @unserialize($raw) : null;
        $data = is_array($data) ? $data : [];

        $columns = [];

        foreach (explode(',', (string) ($data['columns'] ?? '')) as $code) {
            $code = trim($code);

            if ($code === '') {
                continue;
            }

            if (isset(self::BASE_COLUMNS[$code])) {
                $name = self::BASE_COLUMNS[$code];
                $columns[] = ['name' => $name, 'label' => null, 'property' => false];

                continue;
            }

            if (preg_match('/^PROPERTY_(\d+)$/', $code, $m)) {
                $propertyCode = $propertyCodes[$m[1]] ?? null;

                // The property may have been deleted from the iblock but not from the list settings.
                if ($propertyCode !== null) {
                    $columns[] = ['name' => $naming->toColumnName($propertyCode), 'label' => null, 'property' => true];
                }
            }
        }

        $by = trim((string) ($data['by'] ?? ''));

        return new self(
            $columns,
            $by !== '' ? (self::BASE_COLUMNS[strtoupper($by)] ?? strtolower($by)) : null,
            strtolower((string) ($data['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            (int) ($data['page_size'] ?? 0),
        );
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    /** @return array<int,array{name:string,label:?string,property:bool}> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function sortBy(): ?string
    {
        return $this->sortBy;
    }

    public function sortOrder(): string
    {
        return $this->sortOrder;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }
}
