<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Schema;

class IBlockPropertyEnum
{
    public int $id;

    public int $propertyId;

    public string $value;

    public ?string $xmlId = null;

    public int $sort = 500;

    public bool $isDefault = false;

    public static function fromArray(array $data): self
    {
        $enum = new self;
        $enum->id = (int) $data['ID'];
        $enum->propertyId = (int) $data['PROPERTY_ID'];
        $enum->value = $data['VALUE'];
        $enum->xmlId = $data['XML_ID'] ?? null;
        $enum->sort = (int) ($data['SORT'] ?? 500);
        $enum->isDefault = ($data['DEF'] ?? 'N') === 'Y';

        return $enum;
    }
}
