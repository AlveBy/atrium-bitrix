<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Schema;

class IBlock
{
    public int $id;

    public string $code;

    public string $name;

    public string $iblockType;

    public int $version;

    public ?string $xmlId = null;

    public int $sort = 500;

    public bool $active = true;

    public ?string $listPageUrl = null;

    public ?string $detailPageUrl = null;

    public ?string $sectionPageUrl = null;

    /** @var IBlockProperty[] */
    public array $properties = [];

    /** @var IBlockSection[] */
    public array $sections = [];

    public static function fromArray(array $data): self
    {
        $iblock = new self;
        $iblock->id = (int) $data['ID'];
        $iblock->code = $data['CODE'] ?? 'IBLOCK_'.$data['ID'];
        $iblock->name = $data['NAME'];
        $iblock->iblockType = $data['IBLOCK_TYPE_ID'] ?? '';
        $iblock->version = (int) ($data['VERSION'] ?? 1);
        $iblock->xmlId = $data['XML_ID'] ?? null;
        $iblock->sort = (int) ($data['SORT'] ?? 500);
        $iblock->active = ($data['ACTIVE'] ?? 'Y') === 'Y';
        $iblock->listPageUrl = $data['LIST_PAGE_URL'] ?? null;
        $iblock->detailPageUrl = $data['DETAIL_PAGE_URL'] ?? null;
        $iblock->sectionPageUrl = $data['SECTION_PAGE_URL'] ?? null;

        return $iblock;
    }

    public function addProperty(IBlockProperty $property): void
    {
        $this->properties[] = $property;
    }

    public function addSection(IBlockSection $section): void
    {
        $this->sections[] = $section;
    }

    public function getPropertyByCode(string $code): ?IBlockProperty
    {
        foreach ($this->properties as $property) {
            if ($property->code === $code) {
                return $property;
            }
        }

        return null;
    }

    public function hasFileProperties(): bool
    {
        foreach ($this->properties as $property) {
            if ($property->isFile()) {
                return true;
            }
        }

        return false;
    }

    public function hasMultipleProperties(): bool
    {
        foreach ($this->properties as $property) {
            if ($property->multiple) {
                return true;
            }
        }

        return false;
    }

    public function getFileProperties(): array
    {
        return array_filter($this->properties, fn ($p) => $p->isFile());
    }

    public function getListProperties(): array
    {
        return array_filter($this->properties, fn ($p) => $p->isList());
    }

    public function getElementLinkProperties(): array
    {
        return array_filter($this->properties, fn ($p) => $p->isElementLink());
    }

    public function getSectionLinkProperties(): array
    {
        return array_filter($this->properties, fn ($p) => $p->isSectionLink());
    }

    public function getDirectoryProperties(): array
    {
        return array_filter($this->properties, fn ($p) => $p->isDirectory());
    }
}
