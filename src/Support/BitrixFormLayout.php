<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * Layout of the element edit form from the Bitrix admin.
 *
 * Bitrix stores it in b_user_option (CATEGORY='form', NAME='form_element_{IBLOCK_ID}')
 * as a serialized array. The `tabs` string looks like this (captions are in the
 * site language):
 *
 *     edit1--#--Element--,--ACTIVE--#--Active--,--NAME--#--*Name--;--edit5--#--Preview--,--...
 *
 * That is: tabs are separated by `--;--`, items inside a tab by `--,--`, and
 * each item is a `CODE--#--Caption` pair. The first item of a tab is the tab
 * itself.
 *
 * This is the only reliable answer to "where was this field in the old admin":
 * field order and tab names are not stored anywhere else. Editors compare the
 * new admin with the old one line by line, so the form is generated from here
 * rather than by heuristics.
 */
final class BitrixFormLayout
{
    /**
     * Bitrix tab identifiers are stable; only the caption of the first tab
     * varies between iblocks.
     */
    private const TAB_IDS = [
        'edit1' => 'params',
        'edit5' => 'preview',
        'edit6' => 'detail',
        'edit14' => 'seo',
        'edit2' => 'sections',
    ];

    /** Bitrix base fields => our field names. */
    private const BASE_FIELDS = [
        'ACTIVE' => 'active',
        'ACTIVE_FROM' => 'active_from',
        'ACTIVE_TO' => 'active_to',
        'NAME' => 'name',
        'CODE' => 'code',
        'SORT' => 'sort',
        'PREVIEW_PICTURE' => 'preview_picture',
        'PREVIEW_TEXT' => 'preview_text',
        'DETAIL_PICTURE' => 'detail_picture',
        'DETAIL_TEXT' => 'detail_text',
        'SECTIONS' => 'section_id',
    ];

    /** @param array<int,array{name:string,label:string}> $tabs */
    private function __construct(
        private readonly array $tabs,
        /** @var array<string,string> our field name => machine tab name */
        private readonly array $fieldTabs,
        /** @var array<int,string> our fields in Bitrix order */
        private readonly array $order,
    ) {}

    /**
     * @param  array<int,string>  $propertyCodes  property ID => CODE
     */
    public static function load(ConnectionInterface $connection, int $iblockId, array $propertyCodes, NamingConvention $naming): ?self
    {
        $raw = $connection->table('b_user_option')
            ->where('CATEGORY', 'form')
            ->where('NAME', 'form_element_'.$iblockId)
            ->value('VALUE');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = @unserialize($raw);

        if (! is_array($data) || ! isset($data['tabs']) || ! is_string($data['tabs'])) {
            return null;
        }

        $tabs = [];
        $fieldTabs = [];
        $order = [];

        foreach (explode('--;--', $data['tabs']) as $tabChunk) {
            $items = explode('--,--', $tabChunk);
            $head = array_shift($items);
            $headParts = explode('--#--', (string) $head);
            $tabId = $headParts[0];
            $tabLabel = $headParts[1] ?? '';

            $tabName = self::TAB_IDS[$tabId] ?? null;

            if ($tabName === null || trim($tabLabel) === '') {
                continue;
            }

            $tabs[] = ['name' => $tabName, 'label' => trim($tabLabel)];

            foreach ($items as $item) {
                $code = explode('--#--', (string) $item)[0];
                $field = self::resolveField($code, $propertyCodes, $naming);

                if ($field === null || isset($fieldTabs[$field])) {
                    continue;
                }

                $fieldTabs[$field] = $tabName;
                $order[] = $field;
            }
        }

        return $tabs === [] ? null : new self($tabs, $fieldTabs, $order);
    }

    /**
     * Form row code => our field name. null means the row is ignored.
     *
     * @param  array<int,string>  $propertyCodes
     */
    private static function resolveField(string $code, array $propertyCodes, NamingConvention $naming): ?string
    {
        if (isset(self::BASE_FIELDS[$code])) {
            return self::BASE_FIELDS[$code];
        }

        if (preg_match('/^PROPERTY_(\d+)$/', $code, $m)) {
            // A saved layout may reference properties that were deleted from
            // the iblock. There is nothing to build a field from, so skip them.
            $propertyCode = $propertyCodes[$m[1]] ?? null;

            return $propertyCode === null ? null : $naming->toColumnName($propertyCode);
        }

        // Everything else has no field here: the SEO templates and tags (the
        // admin adds the SEO tab itself, HasAdminSeo), block separators, the
        // import code.
        return null;
    }

    /** @return array<int,array{name:string,label:string}> */
    public function tabs(): array
    {
        return $this->tabs;
    }

    /**
     * Tab for a field. null means the field was not in the Bitrix layout.
     */
    public function tabFor(string $field): ?string
    {
        return $this->fieldTabs[$field] ?? null;
    }

    /**
     * Position of the field in the Bitrix form; PHP_INT_MAX means it was not
     * there, and such fields go to the end of their tab.
     */
    public function positionOf(string $field): int
    {
        $index = array_search($field, $this->order, true);

        return $index === false ? PHP_INT_MAX : $index;
    }

    public function hasTab(string $name): bool
    {
        foreach ($this->tabs as $tab) {
            if ($tab['name'] === $name) {
                return true;
            }
        }

        return false;
    }
}
