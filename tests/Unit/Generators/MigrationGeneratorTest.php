<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Generators;

use Atrium\Bitrix\Generators\MigrationGenerator;
use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Atrium\Bitrix\Schema\HighloadBlock;
use Atrium\Bitrix\Schema\HighloadBlockField;
use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockSection;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The migrations of one block, from blocks made by hand: what the files are
 * called, what order they come in and what is in the Highload Block table.
 */
final class MigrationGeneratorTest extends TestCase
{
    private function generator(): MigrationGenerator
    {
        config(['atrium-bitrix.strip_prefixes' => [], 'atrium-bitrix.naming.mappings' => []]);

        return new MigrationGenerator(new NamingConvention, new PropertyTypeMapper);
    }

    private static function block(bool $sections = false, bool $pivot = false): IBlock
    {
        $iblock = IBlock::fromArray(['ID' => 1, 'CODE' => 'products', 'NAME' => 'Products']);

        if ($sections) {
            $iblock->addSection(IBlockSection::fromArray(['ID' => 1, 'IBLOCK_ID' => 1, 'NAME' => 'Top']));
        }

        if ($pivot) {
            $iblock->addProperty(IBlockProperty::fromArray([
                'ID' => 5, 'IBLOCK_ID' => 1, 'NAME' => 'Related', 'CODE' => 'RELATED', 'PROPERTY_TYPE' => 'E',
                'MULTIPLE' => 'Y',
            ]));
        }

        return $iblock;
    }

    private static function brands(string ...$types): HighloadBlock
    {
        $hlblock = HighloadBlock::fromArray(['ID' => 1, 'NAME' => 'Brands', 'TABLE_NAME' => 'b_hlbd_brands']);

        foreach ($types as $i => $type) {
            $multiple = str_ends_with($type, '[]');
            $hlblock->addField(HighloadBlockField::fromArray([
                'ID' => $i + 1, 'ENTITY_ID' => 'HLBLOCK_1', 'FIELD_NAME' => 'UF_FIELD', 'USER_TYPE_ID' => rtrim($type, '[]'),
                'MULTIPLE' => $multiple ? 'Y' : 'N',
            ]));
        }

        return $hlblock;
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function columns(): iterable
    {
        yield 'string' => ['string', "\$table->string('field', 255)->nullable();"];
        yield 'integer' => ['integer', "\$table->integer('field')->nullable();"];
        yield 'double' => ['double', "\$table->decimal('field', 18, 4)->nullable();"];
        yield 'boolean' => ['boolean', "\$table->boolean('field')->default(false);"];
        yield 'date and time' => ['datetime', "\$table->dateTime('field')->nullable();"];
        yield 'date' => ['date', "\$table->date('field')->nullable();"];
        yield 'file' => ['file', "\$table->string('field', 255)->nullable();"];
        yield 'list' => ['enumeration', "\$table->string('field', 100)->nullable();"];
        yield 'another highload block' => ['hlblock', "\$table->foreignId('field')->nullable();"];
        yield 'html' => ['html', "\$table->longText('field')->nullable();"];
        yield 'url' => ['url', "\$table->string('field', 500)->nullable();"];
        yield 'address' => ['address', "\$table->longText('field')->nullable();"];
        yield 'money' => ['money', "\$table->decimal('field', 18, 2)->nullable();"];
        yield 'many values' => ['string[]', "\$table->json('field')->nullable();"];
        yield 'a type of its own' => ['something_new', "\$table->string('field', 255)->nullable();"];
    }

    #[DataProvider('columns')]
    public function test_a_field_becomes_the_column_of_its_type(string $type, string $line): void
    {
        $migration = $this->generator()->generateForHlblock(self::brands($type))['main']['content'];

        $this->assertStringContainsString("\n            {$line}\n", $migration);
    }

    public function test_the_table_of_a_highload_block_has_its_name_and_its_own_columns(): void
    {
        $migration = $this->generator()->generateForHlblock(self::brands('string', 'integer'))['main'];

        $this->assertStringContainsString("Schema::create('brands', function (Blueprint \$table) {\n            \$table->id();\n", $migration['content']);
        $this->assertStringContainsString("Schema::dropIfExists('brands');", $migration['content']);
        $this->assertStringContainsString("\$table->string('field', 255)->nullable();\n            \$table->integer('field')->nullable();\n            \$table->timestamps();", $migration['content']);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_0001_create_brands_table\.php$/', $migration['filename']);
    }

    public function test_the_tables_of_an_iblock_come_with_the_section_table_first(): void
    {
        $migrations = $this->generator()->generateTablesForIBlock(self::block(sections: true, pivot: true));

        $this->assertSame(['sections', 'elements'], array_keys($migrations));
        $this->assertStringContainsString("Schema::create('product_sections'", $migrations['sections']['content']);
        $this->assertStringContainsString("Schema::create('products'", $migrations['elements']['content']);
        $this->assertStringEndsWith('_0001_create_product_sections_table.php', $migrations['sections']['filename']);
        $this->assertStringEndsWith('_0002_create_products_table.php', $migrations['elements']['filename']);
    }

    public function test_an_iblock_without_sections_has_no_section_table(): void
    {
        $migrations = $this->generator()->generateTablesForIBlock(self::block());

        $this->assertSame(['elements'], array_keys($migrations));
        $this->assertStringEndsWith('_0001_create_products_table.php', $migrations['elements']['filename']);
    }

    public function test_link_tables_are_named_after_the_property(): void
    {
        $migrations = $this->generator()->generatePivotsForIBlock(self::block(pivot: true));

        $this->assertSame(['pivot_related'], array_keys($migrations));
        $this->assertStringContainsString("Schema::create('products_related'", $migrations['pivot_related']['content']);
        $this->assertStringEndsWith('_0001_create_products_related_table.php', $migrations['pivot_related']['filename']);
    }

    public function test_an_iblock_without_link_properties_has_no_link_tables(): void
    {
        $this->assertSame([], $this->generator()->generatePivotsForIBlock(self::block(sections: true)));
    }

    public function test_the_files_of_a_run_share_a_timestamp_and_count_on(): void
    {
        $generator = $this->generator();
        $files = [
            $generator->generateTablesForIBlock(self::block(sections: true))['elements']['filename'],
            $generator->generateForHlblock(self::brands())['main']['filename'],
            $generator->generatePivotsForIBlock(self::block(pivot: true))['pivot_related']['filename'],
        ];

        $prefixes = array_map(static fn (string $file): string => substr($file, 0, 17), $files);
        $this->assertCount(1, array_unique($prefixes));
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}$/', $prefixes[0]);
        $this->assertSame(
            ['0002_create_products_table.php', '0003_create_brands_table.php', '0004_create_products_related_table.php'],
            array_map(static fn (string $file): string => substr($file, 18), $files),
        );
    }

    public function test_a_second_run_gets_a_timestamp_of_its_own(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-03-04 05:06:07'));
        $first = $this->generator()->generateForHlblock(self::brands())['main']['filename'];
        $this->travelTo(new \DateTimeImmutable('2026-03-04 05:06:09'));
        $second = $this->generator()->generateForHlblock(self::brands())['main']['filename'];

        $this->assertSame('2026_03_04_050607_0001_create_brands_table.php', $first);
        $this->assertSame('2026_03_04_050609_0001_create_brands_table.php', $second);
    }

    public function test_one_run_keeps_its_timestamp_while_the_clock_moves(): void
    {
        $generator = $this->generator();
        $this->travelTo(new \DateTimeImmutable('2026-03-04 05:06:07'));
        $first = $generator->generateForHlblock(self::brands())['main']['filename'];
        $this->travelTo(new \DateTimeImmutable('2026-03-04 05:06:09'));
        $second = $generator->generateForHlblock(self::brands())['main']['filename'];

        $this->assertSame('2026_03_04_050607_0001_create_brands_table.php', $first);
        $this->assertSame('2026_03_04_050607_0002_create_brands_table.php', $second);
    }

    public function test_migrations_are_written_to_the_configured_folder(): void
    {
        config(['atrium-bitrix.migration_path' => '/tmp/somewhere']);

        $this->assertSame('/tmp/somewhere', $this->generator()->getMigrationsPath());
    }
}
