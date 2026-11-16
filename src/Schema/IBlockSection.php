<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Schema;

class IBlockSection
{
    public int $id;

    public int $iblockId;

    public ?int $parentId = null;

    public string $name;

    public ?string $code = null;

    public ?string $xmlId = null;

    public int $sort = 500;

    public bool $active = true;

    public int $depth = 0;

    public int $leftMargin = 0;

    public int $rightMargin = 0;

    public ?string $description = null;

    public ?string $descriptionType = null;

    public ?int $pictureId = null;

    public ?int $detailPictureId = null;

    /** @var IBlockSection[] */
    public array $children = [];

    public static function fromArray(array $data): self
    {
        $section = new self;
        $section->id = (int) $data['ID'];
        $section->iblockId = (int) $data['IBLOCK_ID'];
        $section->parentId = ! empty($data['IBLOCK_SECTION_ID']) ? (int) $data['IBLOCK_SECTION_ID'] : null;
        $section->name = $data['NAME'];
        $section->code = $data['CODE'] ?? null;
        $section->xmlId = $data['XML_ID'] ?? null;
        $section->sort = (int) ($data['SORT'] ?? 500);
        $section->active = ($data['ACTIVE'] ?? 'Y') === 'Y';
        $section->depth = (int) ($data['DEPTH_LEVEL'] ?? 0);
        $section->leftMargin = (int) ($data['LEFT_MARGIN'] ?? 0);
        $section->rightMargin = (int) ($data['RIGHT_MARGIN'] ?? 0);
        $section->description = $data['DESCRIPTION'] ?? null;
        $section->descriptionType = $data['DESCRIPTION_TYPE'] ?? null;
        $section->pictureId = ! empty($data['PICTURE']) ? (int) $data['PICTURE'] : null;
        $section->detailPictureId = ! empty($data['DETAIL_PICTURE']) ? (int) $data['DETAIL_PICTURE'] : null;

        return $section;
    }

    public function addChild(IBlockSection $child): void
    {
        $this->children[] = $child;
    }

    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }

    public function isRoot(): bool
    {
        return $this->parentId === null;
    }
}
