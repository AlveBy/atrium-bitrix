<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * Iblock captions from the Bitrix admin.
 *
 * Every iblock has a "Captions" tab in its settings where the site owner names
 * the element ("Product", "Service", "Vacancy"...) and sets the text of the
 * add button ("Add product", "Add service"...). A person has already written
 * these in the right grammatical form for the site language, so the code reads
 * them as they are instead of trying to inflect anything.
 *
 * Everything lives in b_iblock_messages: one row per key (ELEMENT_NAME,
 * ELEMENT_ADD, SECTION_NAME...).
 */
final class BitrixIblockMessages
{
    /** @param array<string,string> $messages Bitrix key => text */
    private function __construct(private readonly array $messages) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public static function load(ConnectionInterface $bitrix, int $iblockId): self
    {
        $rows = $bitrix->table('b_iblock_messages')
            ->where('IBLOCK_ID', $iblockId)
            ->pluck('MESSAGE_TEXT', 'MESSAGE_ID')
            ->all();

        $messages = [];

        foreach ($rows as $key => $text) {
            $text = trim((string) $text);

            if ($text !== '') {
                $messages[$key] = $text;
            }
        }

        return new self($messages);
    }

    /**
     * Text of the "Add element" button, as captioned in this iblock.
     */
    public function elementAdd(): ?string
    {
        return $this->messages['ELEMENT_ADD'] ?? null;
    }

    /**
     * What a single element is called in this iblock: "Product", "Service", "Vacancy".
     */
    public function elementName(): ?string
    {
        return $this->messages['ELEMENT_NAME'] ?? null;
    }
}
