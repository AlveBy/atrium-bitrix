<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Generators;

use Atrium\Bitrix\Generators\SectionMigrationGenerator;
use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Tests\SyntheticTestCase;

/**
 * The section table migration: its tree columns and the user fields Bitrix
 * keeps for sections.
 */
final class SectionMigrationGeneratorTest extends SyntheticTestCase
{
    private static function products(): IBlock
    {
        return IBlock::fromArray(['ID' => 2, 'CODE' => 'demo_products', 'NAME' => 'Products']);
    }

    public function test_the_user_fields_of_a_section_become_columns(): void
    {
        $migration = (new SectionMigrationGenerator(new NamingConvention, $this->bitrix()))->generate(self::products());

        $this->assertStringContainsString("Schema::create('product_sections'", $migration);
        $this->assertStringContainsString("// Section user fields\n            \$table->text('note')->nullable(); // ", $migration);
        $this->assertMatchesRegularExpression("/\n            \\\$table->string\\('view', 50\\)->nullable\\(\\); \\/\\/ /", $migration);
    }

    public function test_a_section_without_user_fields_has_only_the_base_columns(): void
    {
        $migration = (new SectionMigrationGenerator(new NamingConvention, $this->bitrix()))
            ->generate(IBlock::fromArray(['ID' => 1, 'CODE' => 'demo_news', 'NAME' => 'News']));

        $this->assertStringContainsString("Schema::create('news_sections'", $migration);
        $this->assertStringNotContainsString('// Section user fields', $migration);
        $this->assertStringContainsString("\$table->string('detail_picture')->nullable();\n\n            // Nested Set columns", $migration);
    }

    public function test_without_a_bitrix_connection_there_are_no_user_fields(): void
    {
        $migration = (new SectionMigrationGenerator(new NamingConvention))->generate(self::products());

        $this->assertStringContainsString("Schema::create('product_sections'", $migration);
        $this->assertStringNotContainsString('// Section user fields', $migration);
        $this->assertStringNotContainsString("'note'", $migration);
    }
}
