<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Schema;

class HighloadBlock
{
    public int $id;

    public string $name;

    public string $tableName;

    /** @var HighloadBlockField[] */
    public array $fields = [];

    public static function fromArray(array $data): self
    {
        $hlblock = new self;
        $hlblock->id = (int) $data['ID'];
        $hlblock->name = $data['NAME'];
        $hlblock->tableName = $data['TABLE_NAME'];

        return $hlblock;
    }

    public function addField(HighloadBlockField $field): void
    {
        $this->fields[] = $field;
    }

    public function getFieldByName(string $name): ?HighloadBlockField
    {
        foreach ($this->fields as $field) {
            if ($field->fieldName === $name) {
                return $field;
            }
        }

        return null;
    }

    public function hasFileFields(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->isFile()) {
                return true;
            }
        }

        return false;
    }

    public function getFileFields(): array
    {
        return array_filter($this->fields, fn ($f) => $f->isFile());
    }

    public function getEnumFields(): array
    {
        return array_filter($this->fields, fn ($f) => $f->isEnum());
    }

    /**
     * Get Laravel model class name
     */
    public function getModelName(): string
    {
        // Convert table name to class name
        // e.g., "b_hlbd_colors" -> "Color"
        $name = $this->name;

        // Remove common prefixes
        $name = preg_replace('/^(hl_|hlblock_|b_hlbd_)/i', '', $name);

        return ucfirst($name);
    }

    /**
     * Get Laravel table name (without prefix)
     */
    public function getLaravelTableName(): string
    {
        // Convert to snake_case plural
        $name = $this->name;

        // Remove common prefixes
        $name = preg_replace('/^(hl_|hlblock_|b_hlbd_)/i', '', $name);

        return strtolower($name);
    }
}
