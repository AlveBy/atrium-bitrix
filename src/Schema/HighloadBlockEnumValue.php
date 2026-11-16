<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Schema;

class HighloadBlockEnumValue
{
    public int $id;

    public int $fieldId;

    public string $value;

    public ?string $xmlId = null;

    public int $sort = 500;

    public bool $isDefault = false;

    public static function fromArray(array $data): self
    {
        $enum = new self;
        $enum->id = (int) $data['ID'];
        $enum->fieldId = (int) $data['USER_FIELD_ID'];
        $enum->value = $data['VALUE'];
        $enum->xmlId = $data['XML_ID'] ?? null;
        $enum->sort = (int) ($data['SORT'] ?? 500);
        $enum->isDefault = ($data['DEF'] ?? 'N') === 'Y';

        return $enum;
    }
}
