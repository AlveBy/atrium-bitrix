<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

use Illuminate\Support\Str;

/**
 * Where generated classes are written: the configured folder, or the one
 * PSR-4 implies for the namespace (App\Models → app/Models).
 */
final class GeneratedPaths
{
    public static function directory(string $configKey, string $namespace): string
    {
        $configured = config('atrium-bitrix.'.$configKey);

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $relative = str_replace('\\', '/', trim($namespace, '\\'));

        if (str_starts_with($relative, 'App/')) {
            return app_path(Str::after($relative, 'App/'));
        }

        return base_path($relative);
    }

    /**
     * Where generated migrations go: the configured folder or database/migrations.
     */
    public static function migrations(): string
    {
        $path = config('atrium-bitrix.migration_path');

        return is_string($path) && $path !== '' ? $path : database_path('migrations');
    }
}
