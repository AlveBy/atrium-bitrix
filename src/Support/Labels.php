<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

/**
 * Labels as PHP code for the generated models.
 *
 * What Bitrix stores (property names, tab captions from the saved form) is the
 * site's own text and goes in as is. Labels of the standard fields Bitrix
 * draws itself become translation keys of the admin, so each editor sees them
 * in their own interface language.
 */
final class Labels
{
    /** Our tab names => keys in atrium::fields.tabs. */
    private const TABS = [
        'params' => 'main',
        'preview' => 'preview',
        'detail' => 'detail',
        'seo' => 'seo',
        'sections' => 'sections',
        'content' => 'description',
    ];

    public static function standard(string $field): string
    {
        return "__('atrium::fields.standard.{$field}')";
    }

    public static function tab(string $name): string
    {
        return isset(self::TABS[$name])
            ? "__('atrium::fields.tabs.".self::TABS[$name]."')"
            : self::literal($name);
    }

    public static function literal(string $text): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $text)."'";
    }
}
