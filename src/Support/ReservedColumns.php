<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

/**
 * Column names that every generated element table already defines as part of the
 * standard Bitrix IBlock base structure.
 *
 * A Bitrix property whose CODE collides with one of these (e.g. a form's
 * "Your name" property with CODE=NAME) must not produce a second column, a second
 * $fillable entry, a second admin field or a second list column — the base field
 * already occupies that name.
 *
 * This list is shared by ElementMigrationGenerator (which skips the column) and
 * ModelGenerator (which skips the fillable/cast/field/column). Keeping it in one
 * place stops the two from drifting apart, which is exactly how six models ended
 * up declaring the field 'name' twice.
 */
final class ReservedColumns
{
    /**
     * @var list<string>
     */
    public const NAMES = [
        'id', 'bitrix_id', 'name', 'code', 'sort', 'active',
        'active_from', 'active_to', 'preview_text', 'preview_text_type', 'preview_picture',
        'detail_text', 'detail_text_type', 'detail_picture', 'section_id',
        'created_at', 'updated_at', 'deleted_at',
    ];

    /**
     * Is this column name owned by the base element structure?
     */
    public static function has(string $column): bool
    {
        return in_array($column, self::NAMES, true);
    }
}
