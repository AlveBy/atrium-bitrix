<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Schema;

use DateTimeImmutable;

class IBlockElement
{
    public int $id;

    public int $iblockId;

    public ?int $sectionId = null;

    public string $name;

    public ?string $code = null;

    public ?string $xmlId = null;

    public int $sort = 500;

    public bool $active = true;

    public ?DateTimeImmutable $activeFrom = null;

    public ?DateTimeImmutable $activeTo = null;

    public ?string $previewText = null;

    public ?string $previewTextType = null;

    public ?int $previewPictureId = null;

    public ?string $detailText = null;

    public ?string $detailTextType = null;

    public ?int $detailPictureId = null;

    public ?string $tags = null;

    public DateTimeImmutable $dateCreate;

    public DateTimeImmutable $timestampX;

    public ?int $createdBy = null;

    public ?int $modifiedBy = null;

    /** @var array<string, mixed> */
    public array $properties = [];

    /** @var int[] */
    public array $sectionIds = [];

    public static function fromArray(array $data): self
    {
        $element = new self;
        $element->id = (int) $data['ID'];
        $element->iblockId = (int) $data['IBLOCK_ID'];
        $element->sectionId = ! empty($data['IBLOCK_SECTION_ID']) ? (int) $data['IBLOCK_SECTION_ID'] : null;
        $element->name = $data['NAME'];
        $element->code = $data['CODE'] ?? null;
        $element->xmlId = $data['XML_ID'] ?? null;
        $element->sort = (int) ($data['SORT'] ?? 500);
        $element->active = ($data['ACTIVE'] ?? 'Y') === 'Y';
        $element->previewText = $data['PREVIEW_TEXT'] ?? null;
        $element->previewTextType = $data['PREVIEW_TEXT_TYPE'] ?? null;
        $element->previewPictureId = ! empty($data['PREVIEW_PICTURE']) ? (int) $data['PREVIEW_PICTURE'] : null;
        $element->detailText = $data['DETAIL_TEXT'] ?? null;
        $element->detailTextType = $data['DETAIL_TEXT_TYPE'] ?? null;
        $element->detailPictureId = ! empty($data['DETAIL_PICTURE']) ? (int) $data['DETAIL_PICTURE'] : null;
        $element->tags = $data['TAGS'] ?? null;
        $element->createdBy = ! empty($data['CREATED_BY']) ? (int) $data['CREATED_BY'] : null;
        $element->modifiedBy = ! empty($data['MODIFIED_BY']) ? (int) $data['MODIFIED_BY'] : null;

        // Parse dates
        if (! empty($data['DATE_CREATE'])) {
            $element->dateCreate = new DateTimeImmutable($data['DATE_CREATE']);
        } else {
            $element->dateCreate = new DateTimeImmutable;
        }

        if (! empty($data['TIMESTAMP_X'])) {
            $element->timestampX = new DateTimeImmutable($data['TIMESTAMP_X']);
        } else {
            $element->timestampX = new DateTimeImmutable;
        }

        if (! empty($data['DATE_ACTIVE_FROM'])) {
            $element->activeFrom = new DateTimeImmutable($data['DATE_ACTIVE_FROM']);
        }

        if (! empty($data['DATE_ACTIVE_TO'])) {
            $element->activeTo = new DateTimeImmutable($data['DATE_ACTIVE_TO']);
        }

        return $element;
    }

    public function setProperty(string $code, mixed $value): void
    {
        $this->properties[$code] = $value;
    }

    public function getProperty(string $code): mixed
    {
        return $this->properties[$code] ?? null;
    }

    public function addSectionId(int $sectionId): void
    {
        if (! in_array($sectionId, $this->sectionIds, true)) {
            $this->sectionIds[] = $sectionId;
        }
    }

    public function isInMultipleSections(): bool
    {
        return count($this->sectionIds) > 1;
    }
}
