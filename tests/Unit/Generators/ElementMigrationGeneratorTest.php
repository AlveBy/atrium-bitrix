<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Generators;

use Atrium\Bitrix\Generators\ElementMigrationGenerator;
use Atrium\Bitrix\Mappers\PropertyTypeMapper;
use Atrium\Bitrix\Schema\HighloadBlock;
use Atrium\Bitrix\Schema\IBlock;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Schema\IBlockSection;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The element table migration, written from blocks and properties made by hand.
 */
final class ElementMigrationGeneratorTest extends TestCase
{
    private function generator(): ElementMigrationGenerator
    {
        config(['atrium-bitrix.strip_prefixes' => [], 'atrium-bitrix.naming.mappings' => []]);

        return new ElementMigrationGenerator(new NamingConvention, new PropertyTypeMapper);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private static function property(string $code, string $type = 'S', ?string $userType = null, bool $multiple = false, array $extra = [], ?string $link = null, ?HighloadBlock $directory = null): IBlockProperty
    {
        $property = IBlockProperty::fromArray([
            'ID' => 5, 'IBLOCK_ID' => 1, 'NAME' => 'Field '.$code, 'CODE' => $code, 'PROPERTY_TYPE' => $type,
            'USER_TYPE' => $userType, 'MULTIPLE' => $multiple ? 'Y' : 'N', ...$extra,
        ]);
        $property->linkIblockCode = $link;
        $property->directoryHlblock = $directory;

        return $property;
    }

    private static function brands(): HighloadBlock
    {
        return HighloadBlock::fromArray(['ID' => 1, 'NAME' => 'Brands', 'TABLE_NAME' => 'b_hlbd_brands']);
    }

    private static function block(IBlockProperty ...$properties): IBlock
    {
        $iblock = IBlock::fromArray(['ID' => 1, 'CODE' => 'products', 'NAME' => 'Products']);
        foreach ($properties as $property) {
            $iblock->addProperty($property);
        }

        return $iblock;
    }

    /**
     * @return iterable<string,array{IBlockProperty,string}>
     */
    public static function columns(): iterable
    {
        yield 'string' => [self::property('FIELD'), "\$table->string('field', 255)->nullable()->comment('Field FIELD');"];
        yield 'list' => [self::property('FIELD', 'L'), "\$table->string('field', 100)->nullable()->comment('Field FIELD');"];
        yield 'number' => [self::property('FIELD', 'N'), "\$table->decimal('field', 18, 4)->nullable()->comment('Field FIELD');"];
        yield 'html' => [self::property('FIELD', 'S', 'HTML'), "\$table->longText('field')->nullable()->comment('Field FIELD');"];
        yield 'date' => [self::property('FIELD', 'S', 'Date'), "\$table->date('field')->nullable()->comment('Field FIELD');"];
        yield 'date and time' => [self::property('FIELD', 'S', 'DateTime'), "\$table->dateTime('field')->nullable()->comment('Field FIELD');"];
        yield 'map' => [self::property('FIELD', 'S', 'map_yandex'), "\$table->json('field')->nullable()->comment('Field FIELD');"];
        yield 'user' => [self::property('FIELD', 'S', 'UserID'), "\$table->unsignedBigInteger('field')->nullable()->comment('Field FIELD');"];
        yield 'checkbox' => [self::property('FIELD', 'N', 'SASDCheckbox'), "\$table->boolean('field')->default(false)->comment('Field FIELD');"];
        yield 'many values in one column' => [self::property('FIELD', 'S', null, true), "\$table->json('field')->nullable()->comment('Field FIELD');"];
        yield 'one linked element' => [self::property('FIELD', 'E'), "\$table->foreignId('field')->nullable()->comment('Field FIELD');"];
        yield 'a column named after the property name' => [self::property('PROPERTY_FIELD'), "\$table->string('field', 255)->nullable()->comment('Field PROPERTY_FIELD');"];
    }

    #[DataProvider('columns')]
    public function test_a_property_becomes_the_column_of_its_type(IBlockProperty $property, string $line): void
    {
        $migration = $this->generator()->generate(self::block($property));

        $this->assertStringContainsString("\n            {$line}\n", $migration);
    }

    public function test_the_base_structure_comes_around_the_properties(): void
    {
        $migration = $this->generator()->generate(self::block(self::property('FIELD')));

        $this->assertStringContainsString("Schema::create('products', function (Blueprint \$table) {", $migration);
        $this->assertStringContainsString("Schema::dropIfExists('products');", $migration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('bitrix_id')->nullable()->index();", $migration);
        $this->assertStringContainsString('// Property columns', $migration);
        $this->assertStringContainsString('$table->softDeletes();', $migration);
        $this->assertStringNotContainsString('// Section', $migration);
        $this->assertStringNotContainsString('// Foreign keys', $migration);
        $this->assertStringNotContainsString('// Indexes', $migration);
    }

    public function test_an_iblock_with_sections_gets_a_section_column(): void
    {
        $iblock = self::block();
        $iblock->addSection(IBlockSection::fromArray(['ID' => 1, 'IBLOCK_ID' => 1, 'NAME' => 'Top']));

        $this->assertStringContainsString(
            "// Section\n            \$table->foreignId('section_id')->nullable()->constrained('product_sections')->nullOnDelete();",
            $this->generator()->generate($iblock),
        );
    }

    public function test_properties_that_need_a_link_table_have_no_column(): void
    {
        $migration = $this->generator()->generate(self::block(
            self::property('RELATED', 'E', null, true, [], 'news'),
            self::property('FIELD'),
        ));

        $this->assertStringNotContainsString("'related'", $migration);
        $this->assertStringContainsString("\$table->string('field', 255)", $migration);
    }

    public function test_a_property_named_like_a_base_column_adds_nothing_of_its_own(): void
    {
        $migration = $this->generator()->generate(self::block(
            self::property('NAME', 'S', null, false, ['FILTRABLE' => 'Y']),
            self::property('SECTION_ID', 'G', null, false, [], 'products'),
            self::property('SORT', 'N', null, false, ['FILTRABLE' => 'Y']),
            self::property('OTHER_SORT', 'N', null, false, ['FILTRABLE' => 'Y']),
            self::property('THIRD_SORT', 'N', null, false, ['FILTRABLE' => 'Y']),
            self::property('LINKED', 'E', null, false, [], 'news'),
        ));

        $this->assertStringNotContainsString('Field NAME', $migration);
        $this->assertStringNotContainsString('Field SECTION_ID', $migration);
        $this->assertStringNotContainsString('Field SORT', $migration);
        $this->assertStringNotContainsString("foreign('section_id')", $migration);
        $this->assertStringNotContainsString("index('name')", $migration);
        $this->assertStringNotContainsString("index('sort')", $migration);
        $this->assertStringContainsString("// Indexes\n            \$table->index('other_sort');\n            \$table->index('third_sort');\n", $migration);
        $this->assertStringContainsString('Field OTHER_SORT', $migration);
        $this->assertStringContainsString("// Foreign keys\n            \$table->foreign('linked')->references('id')->on('news')->nullOnDelete();\n", $migration, 'a property after the reserved ones is still handled');
    }

    /**
     * @return iterable<string,array{IBlockProperty,?string}>
     */
    public static function foreignKeys(): iterable
    {
        yield 'an element' => [self::property('F', 'E', null, false, [], 'news'), "\$table->foreign('f')->references('id')->on('news')->nullOnDelete();"];
        yield 'an element of no known iblock' => [self::property('F', 'E'), null];
        yield 'a section' => [self::property('F', 'G', null, false, [], 'news'), "\$table->foreign('f')->references('id')->on('news_sections')->nullOnDelete();"];
        yield 'a section of no known iblock' => [self::property('F', 'G'), null];
        yield 'a directory' => [self::property('F', 'S', 'directory', false, [], null, self::brands()), "\$table->foreign('f')->references('id')->on('brands')->nullOnDelete();"];
        yield 'a directory that was not found' => [self::property('F', 'S', 'directory'), null];
        yield 'many elements live in a link table' => [self::property('F', 'E', null, true, [], 'news'), null];
        yield 'many sections live in a link table' => [self::property('F', 'G', null, true, [], 'news'), null];
        yield 'many of a directory live in a link table' => [self::property('F', 'S', 'directory', true, [], null, self::brands()), null];
        yield 'a plain string' => [self::property('F'), null];
    }

    #[DataProvider('foreignKeys')]
    public function test_a_link_to_a_known_table_gets_a_foreign_key(IBlockProperty $property, ?string $line): void
    {
        $migration = $this->generator()->generate(self::block($property));

        if ($line === null) {
            $this->assertStringNotContainsString('->foreign(', $migration);
            $this->assertStringNotContainsString('// Foreign keys', $migration);

            return;
        }

        $this->assertStringContainsString("// Foreign keys\n            {$line}\n", $migration);
    }

    public function test_every_foreign_key_is_written_one_to_a_line(): void
    {
        $migration = $this->generator()->generate(self::block(
            self::property('A', 'E', null, false, [], 'news'),
            self::property('B', 'G', null, false, [], 'news'),
        ));

        $this->assertStringContainsString(
            "// Foreign keys\n            \$table->foreign('a')->references('id')->on('news')->nullOnDelete();\n"
            ."            \$table->foreign('b')->references('id')->on('news_sections')->nullOnDelete();\n",
            $migration,
        );
    }

    /**
     * @return iterable<string,array{IBlockProperty,bool}>
     */
    public static function indexes(): iterable
    {
        yield 'filtrable' => [self::property('F', 'S', null, false, ['FILTRABLE' => 'Y']), true];
        yield 'in the smart filter' => [self::property('F', 'N', null, false, ['SMART_FILTER' => 'Y']), true];
        yield 'many values in json, filtrable' => [self::property('F', 'S', null, true, ['FILTRABLE' => 'Y']), true];
        yield 'searchable text is left to full text search' => [self::property('F', 'S', null, false, ['SEARCHABLE' => 'Y']), false];
        yield 'searchable and filtrable text is left to full text search' => [self::property('F', 'S', null, false, ['SEARCHABLE' => 'Y', 'FILTRABLE' => 'Y']), false];
        yield 'searchable number' => [self::property('F', 'N', null, false, ['SEARCHABLE' => 'Y']), false];
        yield 'filtrable element link has its foreign key' => [self::property('F', 'E', null, false, ['FILTRABLE' => 'Y']), false];
        yield 'filtrable section link has its foreign key' => [self::property('F', 'G', null, false, ['FILTRABLE' => 'Y']), false];
        yield 'filtrable directory has its foreign key' => [self::property('F', 'S', 'directory', false, ['FILTRABLE' => 'Y']), false];
        yield 'filtrable link table' => [self::property('F', 'E', null, true, ['FILTRABLE' => 'Y']), false];
        yield 'not in any filter' => [self::property('F'), false];
    }

    #[DataProvider('indexes')]
    public function test_a_property_in_a_filter_gets_an_index(IBlockProperty $property, bool $indexed): void
    {
        $migration = $this->generator()->generate(self::block($property));

        if ($indexed) {
            $this->assertStringContainsString("// Indexes\n            \$table->index('f');\n", $migration);
        } else {
            $this->assertStringNotContainsString('// Indexes', $migration);
            $this->assertStringNotContainsString("->index('f')", $migration);
        }
    }

    /**
     * @return iterable<string,array{IBlockProperty,?string,?string}>
     */
    public static function pivots(): iterable
    {
        yield 'elements of another iblock' => [self::property('RELATED', 'E', null, true, [], 'news'), 'news', 'related_id'];
        yield 'elements of the same iblock' => [self::property('RELATED', 'E', null, true), 'products', 'related_id'];
        yield 'sections of another iblock' => [self::property('RELATED', 'G', null, true, [], 'news'), 'news_sections', 'section_id'];
        yield 'sections of the same iblock' => [self::property('RELATED', 'G', null, true), 'product_sections', 'section_id'];
        yield 'values of a directory' => [self::property('RELATED', 'S', 'directory', true, [], null, self::brands()), 'brands', 'related_id'];
    }

    #[DataProvider('pivots')]
    public function test_a_multiple_link_gets_a_link_table(IBlockProperty $property, string $related, string $key): void
    {
        $pivots = $this->generator()->generatePivotMigrations(self::block($property));

        $this->assertSame(['related'], array_keys($pivots));
        $this->assertSame('products_related', $pivots['related']['table']);

        $content = $pivots['related']['content'];
        $this->assertStringContainsString("Schema::create('products_related', function (Blueprint \$table) {", $content);
        $this->assertStringContainsString("Schema::dropIfExists('products_related');", $content);
        $this->assertStringContainsString("\$table->unsignedBigInteger('product_id');", $content);
        $this->assertStringContainsString("\$table->unsignedBigInteger('{$key}');", $content);
        $this->assertStringContainsString("\$table->integer('sort')->default(0);", $content);
        $this->assertStringContainsString("\$table->foreign('product_id', 'products_related_fk1')\n                ->references('id')->on('products')->cascadeOnDelete();", $content);
        $this->assertStringContainsString("\$table->foreign('{$key}', 'products_related_fk2')\n                ->references('id')->on('{$related}')->cascadeOnDelete();", $content);
        $this->assertStringContainsString("\$table->unique(['product_id', '{$key}'], 'products_related_unique');", $content);
    }

    public function test_a_link_to_nowhere_gets_no_link_table(): void
    {
        $pivots = $this->generator()->generatePivotMigrations(self::block(
            self::property('BRANDS', 'S', 'directory', true),
            self::property('TAGS', 'S', null, true),
            self::property('ONE', 'E', null, false, [], 'news'),
            self::property('SOME', 'E', null, true, [], 'news'),
        ));

        $this->assertSame(['some'], array_keys($pivots));
    }

    public function test_names_of_constraints_are_cut_to_fit_the_database(): void
    {
        $property = self::property('A_RATHER_LONG_NAME_OF_A_PROPERTY_OF_MANY', 'E', null, true, [], 'news');

        $content = $this->generator()->generatePivotMigrations(self::block($property))['a_rather_long_name_of_a_property_of_many']['content'];

        // "products_a_rather_long_name_of_a_property_of_many" is cut to its first 40 letters
        $short = 'products_a_rather_long_name_of_a_propert';
        $this->assertSame(40, strlen($short));
        $this->assertStringContainsString("'{$short}_fk1'", $content);
        $this->assertStringContainsString("'{$short}_fk2'", $content);
        $this->assertStringContainsString("'{$short}_unique'", $content);
        $this->assertStringContainsString("Schema::create('products_a_rather_long_name_of_a_property_of_many'", $content);
    }
}
