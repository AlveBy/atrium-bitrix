<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Support\Facades\Artisan;

/**
 * generate:migrations and generate:models: what they pick, what they write,
 * and what they leave alone.
 */
final class GenerateCommandsTest extends SyntheticTestCase
{
    /**
     * @return list<string>
     */
    private function migrations(): array
    {
        return array_map(
            static fn (string $file): string => (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_\d{4}_/', '', $file),
            $this->generatedFiles('migrations'),
        );
    }

    /**
     * What a run prints, with the scratch folder and the file timestamps named
     * by placeholders so the whole transcript can be compared.
     *
     * @param  array<string, mixed>  $options
     */
    private function transcript(string $command, array $options): string
    {
        Artisan::call($command, $options);

        return (string) preg_replace(
            '/\d{4}_\d{2}_\d{2}_\d{6}_/',
            'STAMP_',
            str_replace($this->workDir, 'WORK', Artisan::output()),
        );
    }

    public function test_a_dry_run_of_migrations_lists_them_and_writes_nothing(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--dry-run' => true, '--no-interaction' => true])
            ->expectsOutput('Generating linked Highload Block migrations (referenced by IBlocks)...')
            ->expectsOutput('Highload Block: Brands')
            ->expectsOutputToContain('_0001_create_brands_table.php')
            ->expectsOutput('IBlock: News (demo_news)')
            ->expectsOutputToContain('  Would create [elements]: ')
            ->expectsOutput('IBlock: Products (demo_products)')
            ->expectsOutputToContain('  Would create [sections]: ')
            ->expectsOutput('Link tables')
            ->expectsOutputToContain('  Would create [demo_news.pivot_link_goods]: ')
            ->expectsOutput('  Skipping Brands (already generated as linked directory)')
            ->doesntExpectOutputToContain('Schema::create')
            ->doesntExpectOutputToContain('Created [')
            ->doesntExpectOutput('Run "php artisan migrate" to apply the migrations.')
            ->expectsOutput('Migration generation complete!')
            ->assertSuccessful();

        $this->assertSame([], $this->generatedFiles('migrations'));
    }

    public function test_a_verbose_dry_run_of_migrations_shows_their_code(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--dry-run' => true, '--iblock' => [1], '--hlblock' => [1], '-v' => true])
            ->expectsOutputToContain("Schema::create('news'")
            ->expectsOutputToContain("Schema::create('brands'")
            ->assertSuccessful();

        $this->assertSame([], $this->generatedFiles('migrations'));
    }

    public function test_migrations_are_written_for_every_block(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('  Created [main]: ')
            ->expectsOutputToContain('  Created [elements]: ')
            ->expectsOutputToContain('  Created [demo_products.pivot_related]: ')
            ->expectsOutput('Run "php artisan migrate" to apply the migrations.')
            ->assertSuccessful();

        $this->assertSame([
            'create_brands_table.php',
            'create_news_table.php',
            'create_product_sections_table.php',
            'create_products_table.php',
            'create_news_link_goods_table.php',
            'create_products_related_table.php',
        ], $this->migrations());
    }

    public function test_the_dry_run_report_of_migrations_is_laid_out_with_blank_lines_between_its_parts(): void
    {
        $expected = <<<'TXT'
        Bitrix Migration Generator
        ==========================

        Generating linked Highload Block migrations (referenced by IBlocks)...

        Highload Block: Brands
          Would create [main]: STAMP_0001_create_brands_table.php

        Generating IBlock migrations...

        IBlock: News (demo_news)
          Would create [elements]: STAMP_0002_create_news_table.php

        IBlock: Products (demo_products)
          Would create [sections]: STAMP_0003_create_product_sections_table.php
          Would create [elements]: STAMP_0004_create_products_table.php

        Link tables
          Would create [demo_news.pivot_link_goods]: STAMP_0005_create_news_link_goods_table.php
          Would create [demo_products.pivot_related]: STAMP_0006_create_products_related_table.php
        Generating Highload Block migrations...
          Skipping Brands (already generated as linked directory)

        Migration generation complete!

        TXT;

        $this->assertSame($expected, $this->transcript('atrium:bitrix:generate:migrations', [
            '--dry-run' => true, '--iblock' => [1, 2], '--hlblock' => [1],
        ]));
    }

    public function test_the_written_report_of_migrations_ends_with_the_hint_to_migrate(): void
    {
        $expected = <<<'TXT'
        Bitrix Migration Generator
        ==========================

        Generating IBlock migrations...

        IBlock: News (demo_news)
          Created [elements]: STAMP_0001_create_news_table.php

        Link tables
          Created [demo_news.pivot_link_goods]: STAMP_0002_create_news_link_goods_table.php
        Generating Highload Block migrations...
        Highload Block 99 not found

        Migration generation complete!

        Run "php artisan migrate" to apply the migrations.

        TXT;

        $this->assertSame($expected, $this->transcript('atrium:bitrix:generate:migrations', [
            '--force' => true, '--iblock' => [1], '--hlblock' => [99],
        ]));
    }

    public function test_a_verbose_dry_run_sets_the_code_of_each_migration_apart_by_blank_lines(): void
    {
        $out = $this->transcript('atrium:bitrix:generate:migrations', [
            '--dry-run' => true, '-v' => true, '--iblock' => [1], '--hlblock' => [99],
        ]);

        $this->assertStringContainsString("  Would create [elements]: STAMP_0001_create_news_table.php\n\n<?php\n\nuse Illuminate", $out);
        $this->assertStringContainsString("        Schema::dropIfExists('news');\n    }\n};\n\n\nLink tables\n", $out);
    }

    public function test_a_block_that_is_missing_or_skipped_does_not_stop_the_ones_after_it(): void
    {
        config(['atrium-bitrix.skip_iblocks' => [2]]);

        $this->artisan('atrium:bitrix:generate:migrations', ['--dry-run' => true, '--iblock' => [99, 2, 1], '--hlblock' => [98, 1]])
            ->expectsOutput('IBlock 99 not found')
            ->expectsOutput('Skipping IBlock 2 (in skip list)')
            ->expectsOutput('IBlock: News (demo_news)')
            ->expectsOutput('Highload Block 98 not found')
            ->expectsOutput('Highload Block: Brands')
            ->assertSuccessful();
    }

    public function test_a_directory_named_with_another_prefix_is_found_among_the_blocks_read_up_front(): void
    {
        $this->bitrix()->table('b_iblock_property')->where('CODE', 'BRAND')->update([
            'USER_TYPE_SETTINGS' => serialize(['size' => 1, 'TABLE_NAME' => 'theme_hlbd_brands']),
        ]);

        $this->artisan('atrium:bitrix:generate:migrations', ['--dry-run' => true, '--iblock' => [2], '--hlblock' => [99]])
            ->expectsOutput('Generating linked Highload Block migrations (referenced by IBlocks)...')
            ->expectsOutput('Highload Block: Brands')
            ->assertSuccessful();
    }

    public function test_blocks_asked_for_by_id_are_the_only_ones_generated(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--iblock' => [1, 99], '--hlblock' => [1, 98], '--force' => true])
            ->expectsOutput('IBlock 99 not found')
            ->expectsOutput('Generating Highload Block migrations...')
            ->expectsOutput('Highload Block 98 not found')
            ->doesntExpectOutputToContain('Products')
            ->doesntExpectOutputToContain('Available IBlocks:')
            ->assertSuccessful();

        $this->assertSame([
            'create_news_table.php',
            'create_news_link_goods_table.php',
            'create_brands_table.php',
        ], $this->migrations());
    }

    public function test_an_iblock_on_the_skip_list_is_left_out(): void
    {
        config(['atrium-bitrix.skip_iblocks' => [2]]);

        $this->artisan('atrium:bitrix:generate:migrations', ['--iblock' => [1, 2], '--hlblock' => [99], '--force' => true])
            ->expectsOutput('Skipping IBlock 2 (in skip list)')
            ->doesntExpectOutput('Skipping IBlock 1 (in skip list)')
            ->assertSuccessful();

        $this->assertSame(['create_news_table.php', 'create_news_link_goods_table.php'], $this->migrations());
    }

    public function test_the_blocks_typed_in_are_the_ones_generated(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--dry-run' => true])
            ->expectsOutput('Available IBlocks:')
            ->expectsTable(['ID', 'Code', 'Name', 'Type'], [
                [1, 'demo_news', 'News', 'content'],
                [2, 'demo_products', 'Products', 'catalog'],
            ])
            ->expectsQuestion('Enter IBlock IDs to process (comma-separated, or "all" for all)', ' 1, ,99')
            ->expectsOutput('IBlock 99 not found')
            ->expectsOutput('IBlock: News (demo_news)')
            ->doesntExpectOutput('IBlock: Products (demo_products)')
            ->expectsOutput('Available Highload Blocks:')
            ->expectsTable(['ID', 'Name', 'Table'], [[1, 'Brands', 'b_hlbd_brands']])
            ->expectsQuestion('Enter Highload Block IDs to process (comma-separated, or "all" for all)', '1')
            ->expectsOutput('Highload Block: Brands')
            ->assertSuccessful();
    }

    public function test_all_typed_in_any_case_takes_every_block(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--dry-run' => true])
            ->expectsQuestion('Enter IBlock IDs to process (comma-separated, or "all" for all)', 'ALL')
            ->expectsQuestion('Enter Highload Block IDs to process (comma-separated, or "all" for all)', 'All')
            ->expectsOutput('IBlock: News (demo_news)')
            ->expectsOutput('IBlock: Products (demo_products)')
            ->expectsOutput('  Skipping Brands (already generated as linked directory)')
            ->assertSuccessful();
    }

    public function test_an_empty_answer_takes_no_block(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--dry-run' => true])
            ->expectsQuestion('Enter IBlock IDs to process (comma-separated, or "all" for all)', '')
            ->expectsQuestion('Enter Highload Block IDs to process (comma-separated, or "all" for all)', '')
            ->doesntExpectOutput('Generating IBlock migrations...')
            ->doesntExpectOutput('Generating Highload Block migrations...')
            ->expectsOutput('Migration generation complete!')
            ->assertSuccessful();
    }

    public function test_a_bitrix_without_blocks_generates_nothing(): void
    {
        $this->bitrix()->table('b_iblock')->delete();
        $this->bitrix()->table('b_hlblock_entity')->delete();

        $this->artisan('atrium:bitrix:generate:migrations')
            ->expectsOutput('No IBlocks found in Bitrix database.')
            ->expectsOutput('No Highload Blocks found in Bitrix database.')
            ->doesntExpectOutput('Available IBlocks:')
            ->expectsOutput('Migration generation complete!')
            ->assertSuccessful();

        $this->artisan('atrium:bitrix:generate:models')
            ->expectsOutput('No IBlocks found in Bitrix database.')
            ->expectsOutput('No Highload Blocks found in Bitrix database.')
            ->expectsOutput('Model generation complete!')
            ->assertSuccessful();

        $this->assertSame([], $this->generatedFiles('migrations'));
        $this->assertSame([], $this->generatedFiles('models'));
    }

    public function test_an_existing_migration_is_kept_unless_confirmed(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--iblock' => [1], '--hlblock' => [99], '--force' => true])->assertSuccessful();
        $files = $this->generatedFiles('migrations');
        $news = $this->workDir.'/migrations/'.$files[0];
        file_put_contents($news, '<?php // edited by hand');

        $this->artisan('atrium:bitrix:generate:migrations', ['--iblock' => [1], '--hlblock' => [99]])
            ->expectsOutputToContain('  Migration for this table may already exist:')
            ->expectsOutput('    '.$files[0])
            ->expectsConfirmation('  Create new migration anyway?', 'no')
            ->expectsOutputToContain('  Skipped: ')
            ->expectsConfirmation('  Create new migration anyway?', 'yes')
            ->expectsOutputToContain('  Created [demo_news.pivot_link_goods]: ')
            ->assertSuccessful();

        $this->assertSame('<?php // edited by hand', file_get_contents($news));
    }

    public function test_a_migration_of_the_table_written_earlier_under_another_number_is_found(): void
    {
        $earlier = '2020_01_01_000000_0007_create_news_table.php';
        file_put_contents($this->workDir.'/migrations/'.$earlier, '<?php // earlier run');

        $this->artisan('atrium:bitrix:generate:migrations', ['--iblock' => [1], '--hlblock' => [99]])
            ->expectsOutputToContain('  Migration for this table may already exist:')
            ->expectsOutput('    '.$earlier)
            ->expectsConfirmation('  Create new migration anyway?', 'no')
            ->expectsOutputToContain('  Skipped: ')
            ->assertSuccessful();
    }

    public function test_a_migration_of_another_table_does_not_count_as_existing(): void
    {
        file_put_contents($this->workDir.'/migrations/2020_01_01_000000_0007_create_orders_table.php', '<?php');

        $this->artisan('atrium:bitrix:generate:migrations', ['--iblock' => [1], '--hlblock' => [99]])
            ->doesntExpectOutputToContain('may already exist')
            ->expectsOutputToContain('_0001_create_news_table.php')
            ->assertSuccessful();
    }

    public function test_a_dry_run_of_models_lists_them_and_writes_nothing(): void
    {
        $models = $this->workDir.'/models';

        $this->artisan('atrium:bitrix:generate:models', ['--dry-run' => true, '--no-interaction' => true])
            ->expectsOutput('Generating IBlock models...')
            ->expectsOutput('  Would create Element model: News')
            ->expectsOutput("  Path: {$models}/News.php")
            ->expectsOutput('  Would create Element model: Product')
            ->expectsOutput('  Would create Section model: ProductSection')
            ->expectsOutput("  Path: {$models}/ProductSection.php")
            ->expectsOutput('Generating linked directory models...')
            ->expectsOutput('Generating Highload Block models...')
            ->expectsOutputToContain('  Would create Reference model: Brands')
            ->expectsOutputToContain("  Path: {$models}/References/Brands.php")
            ->doesntExpectOutput('  Would create Section model: NewsSection')
            ->doesntExpectOutputToContain('class News')
            ->doesntExpectOutputToContain('  Created: ')
            ->expectsOutput('Model generation complete!')
            ->assertSuccessful();

        $this->assertSame([], $this->generatedFiles('models'));
    }

    public function test_a_verbose_dry_run_of_models_shows_their_code(): void
    {
        $this->artisan('atrium:bitrix:generate:models', ['--dry-run' => true, '--iblock' => [1], '--hlblock' => [1], '-v' => true])
            ->expectsOutputToContain('class News extends Model')
            ->expectsOutputToContain('class Brands extends Model')
            ->assertSuccessful();

        $this->assertSame([], $this->generatedFiles('models'));
    }

    public function test_models_asked_for_by_id_are_the_only_ones_written(): void
    {
        config(['atrium-bitrix.skip_iblocks' => [2]]);

        $this->artisan('atrium:bitrix:generate:models', ['--iblock' => [1, 2, 99], '--hlblock' => [1, 98], '--force' => true])
            ->expectsOutput('  Created: News')
            ->expectsOutput('  Path: '.$this->workDir.'/models/News.php')
            ->expectsOutput('Skipping IBlock 2 (in skip list)')
            ->expectsOutput('IBlock 99 not found')
            ->expectsOutput('  Created: Brands')
            ->expectsOutput('Highload Block 98 not found')
            ->doesntExpectOutput('Generating linked directory models...')
            ->assertSuccessful();

        $this->assertSame(['News.php', 'References/Brands.php'], $this->generatedFiles('models'));
        $this->assertStringContainsString('class News extends Model', (string) file_get_contents($this->workDir.'/models/News.php'));
    }

    public function test_an_existing_model_is_overwritten_only_when_confirmed(): void
    {
        $news = $this->workDir.'/models/News.php';
        file_put_contents($news, '<?php // edited by hand');

        $this->artisan('atrium:bitrix:generate:models', ['--iblock' => [1], '--hlblock' => [99]])
            ->expectsConfirmation('Model News already exists. Overwrite?', 'no')
            ->expectsOutput('  Skipped: News')
            ->doesntExpectOutput('  Created: News')
            ->assertSuccessful();

        $this->assertSame('<?php // edited by hand', file_get_contents($news));

        $this->artisan('atrium:bitrix:generate:models', ['--iblock' => [1], '--hlblock' => [99]])
            ->expectsConfirmation('Model News already exists. Overwrite?', 'yes')
            ->expectsOutput('  Created: News')
            ->assertSuccessful();

        $this->assertStringContainsString('class News extends Model', (string) file_get_contents($news));
    }
}
