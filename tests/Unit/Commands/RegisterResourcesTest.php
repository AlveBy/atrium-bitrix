<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Tests\SyntheticTestCase;

/**
 * generate:models lists the models it writes in config/atrium.php →
 * resources, so they are in the admin's menu without editing the config.
 */
final class RegisterResourcesTest extends SyntheticTestCase
{
    private const NEWS = '\\'.self::NAMESPACE.'\\News::class';

    private const PRODUCT = '\\'.self::NAMESPACE.'\\Product::class';

    private const PRODUCT_SECTION = '\\'.self::NAMESPACE.'\\ProductSection::class';

    private const BRANDS = '\\'.self::NAMESPACE.'\\References\\Brands::class';

    private function configFile(): string
    {
        return $this->workDir.'/config/atrium.php';
    }

    /**
     * The config as atrium:install publishes it, optionally with a change.
     */
    private function publishConfig(?callable $edit = null): string
    {
        $config = (string) file_get_contents(dirname(__DIR__, 4).'/admin/config/atrium.php');
        $config = $edit === null ? $config : $edit($config);
        file_put_contents($this->configFile(), $config);

        return $config;
    }

    private function config(): string
    {
        return (string) file_get_contents($this->configFile());
    }

    /**
     * The entries of the resources list, in order.
     *
     * @return list<string>
     */
    private function resources(): array
    {
        preg_match("/'resources' => \[\n(.*?)\n    \],/s", $this->config(), $match);

        return array_map('trim', explode("\n", $match[1]));
    }

    public function test_each_generated_model_is_listed_in_the_order_it_was_generated_above_the_users_own_entries(): void
    {
        $this->publishConfig(static fn (string $config): string => str_replace(
            "        // App\\Models\\Article::class,\n",
            "        // App\\Models\\Article::class,\n        \\App\\Models\\Page::class,\n",
            $config,
        ));

        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--no-interaction' => true])
            ->expectsOutput('Registering models in config/atrium.php...')
            ->expectsOutput('  Added: '.self::NAMESPACE.'\\News')
            ->expectsOutput('  Added: '.self::NAMESPACE.'\\Product')
            ->expectsOutput('  Added: '.self::NAMESPACE.'\\ProductSection')
            ->expectsOutput('  Added: '.self::NAMESPACE.'\\References\\Brands')
            ->doesntExpectOutputToContain('Already listed')
            ->doesntExpectOutputToContain('by hand')
            ->assertSuccessful();

        $this->assertSame([
            self::NEWS.',',
            self::PRODUCT.',',
            self::PRODUCT_SECTION.',',
            self::BRANDS.',',
            '// App\\Models\\Article::class,',
            '\\App\\Models\\Page::class,',
        ], $this->resources());
        $this->assertStringNotContainsString('NewsSection', $this->config());
    }

    public function test_a_second_run_lists_nothing_twice(): void
    {
        $this->publishConfig();

        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $once = $this->config();

        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--no-interaction' => true])
            ->expectsOutput('  Already listed: '.self::NAMESPACE.'\\News')
            ->expectsOutput('  Already listed: '.self::NAMESPACE.'\\References\\Brands')
            ->doesntExpectOutputToContain('  Added: ')
            ->assertSuccessful();

        $this->assertSame($once, $this->config());
        $this->assertSame(1, substr_count($this->config(), self::BRANDS));
    }

    public function test_a_directory_both_linked_and_asked_for_is_listed_once(): void
    {
        $this->publishConfig();

        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--iblock' => [2], '--hlblock' => [1]])
            ->expectsOutput('  Added: '.self::NAMESPACE.'\\References\\Brands')
            ->assertSuccessful();

        $this->assertSame([self::PRODUCT.',', self::PRODUCT_SECTION.',', self::BRANDS.',', '// App\\Models\\Article::class,'], $this->resources());
    }

    public function test_a_model_listed_through_a_use_import_is_not_added_again(): void
    {
        $this->publishConfig(static fn (string $config): string => str_replace(
            "    'resources' => [\n",
            "    'resources' => [\n        News::class,\n",
            $config,
        ));
        config(['atrium.resources' => [self::NAMESPACE.'\\News']]);

        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--iblock' => [1], '--hlblock' => [99]])
            ->expectsOutput('  Already listed: '.self::NAMESPACE.'\\News')
            ->doesntExpectOutputToContain('  Added: ')
            ->assertSuccessful();

        $this->assertStringNotContainsString(self::NEWS, $this->config());
    }

    public function test_a_model_commented_out_stays_out_of_the_menu(): void
    {
        $this->publishConfig(static fn (string $config): string => str_replace(
            "    'resources' => [\n",
            "    'resources' => [\n        // ".self::NEWS.",\n",
            $config,
        ));

        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--iblock' => [1, 2], '--hlblock' => [99]])
            ->expectsOutput('  Already listed: '.self::NAMESPACE.'\\News')
            ->expectsOutput('  Added: '.self::NAMESPACE.'\\Product')
            ->assertSuccessful();

        $this->assertSame([
            self::PRODUCT.',',
            self::PRODUCT_SECTION.',',
            self::BRANDS.',',
            '// '.self::NEWS.',',
            '// App\\Models\\Article::class,',
        ], $this->resources());
    }

    public function test_a_model_written_without_the_leading_backslash_counts_as_listed(): void
    {
        $this->publishConfig(static fn (string $config): string => str_replace(
            "    'resources' => [\n",
            "    'resources' => [\n        ".self::NAMESPACE."\\News::class,\n",
            $config,
        ));

        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--iblock' => [1], '--hlblock' => [99]])
            ->expectsOutput('  Already listed: '.self::NAMESPACE.'\\News')
            ->doesntExpectOutputToContain('  Added: ')
            ->assertSuccessful();
    }

    public function test_another_class_whose_name_contains_the_models_does_not_count_as_listed(): void
    {
        $this->publishConfig(static fn (string $config): string => str_replace(
            "    'resources' => [\n",
            "    'resources' => [\n        \\Vendor".self::NEWS.",\n        \\".self::NAMESPACE."\\NewsArchive::class,\n",
            $config,
        ));

        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--iblock' => [1], '--hlblock' => [99]])
            ->expectsOutput('  Added: '.self::NAMESPACE.'\\News')
            ->assertSuccessful();

        $this->assertSame([
            self::NEWS.',',
            '\\Vendor'.self::NEWS.',',
            '\\'.self::NAMESPACE.'\\NewsArchive::class,',
            '// App\\Models\\Article::class,',
        ], $this->resources());
    }

    public function test_a_model_kept_instead_of_overwritten_is_still_listed(): void
    {
        $this->publishConfig();
        file_put_contents($this->workDir.'/models/News.php', '<?php // edited by hand');

        $this->artisan('atrium:bitrix:generate:models', ['--iblock' => [1], '--hlblock' => [99]])
            ->expectsConfirmation('Model News already exists. Overwrite?', 'no')
            ->expectsOutput('  Added: '.self::NAMESPACE.'\\News')
            ->assertSuccessful();

        $this->assertSame([self::NEWS.',', '// App\\Models\\Article::class,'], $this->resources());
    }

    public function test_a_dry_run_tells_what_it_would_list_and_writes_nothing(): void
    {
        $published = $this->publishConfig(static fn (string $config): string => str_replace(
            "    'resources' => [\n",
            "    'resources' => [\n        ".self::PRODUCT.",\n",
            $config,
        ));

        $this->artisan('atrium:bitrix:generate:models', ['--dry-run' => true, '--no-interaction' => true])
            ->expectsOutput('Registering models in config/atrium.php...')
            ->expectsOutput('  Would add: '.self::NAMESPACE.'\\News')
            ->expectsOutput('  Already listed: '.self::NAMESPACE.'\\Product')
            ->expectsOutput('  Would add: '.self::NAMESPACE.'\\References\\Brands')
            ->doesntExpectOutputToContain('  Added: ')
            ->assertSuccessful();

        $this->assertSame($published, $this->config());
    }

    public function test_an_unpublished_config_gets_a_note_with_the_lines_to_add_and_no_file(): void
    {
        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--iblock' => [2], '--hlblock' => [99]])
            ->expectsOutput("config/atrium.php is not published (php artisan atrium:install). Add the models to its 'resources' by hand:")
            ->expectsOutput('    '.self::PRODUCT.',')
            ->expectsOutput('    '.self::PRODUCT_SECTION.',')
            ->expectsOutput('    '.self::BRANDS.',')
            ->doesntExpectOutput('Registering models in config/atrium.php...')
            ->expectsOutput('Model generation complete!')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->configFile());
        $this->assertSame(['Product.php', 'ProductSection.php', 'References/Brands.php'], $this->generatedFiles('models'));
    }

    public function test_an_unpublished_config_needs_no_note_when_the_loaded_one_lists_every_model(): void
    {
        config(['atrium.resources' => ['\\'.self::NAMESPACE.'\\News']]);

        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--iblock' => [1], '--hlblock' => [99]])
            ->doesntExpectOutputToContain('by hand')
            ->expectsOutput('  Already listed: '.self::NAMESPACE.'\\News')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->configFile());
    }

    public function test_a_config_without_a_resources_list_is_left_alone_with_a_note(): void
    {
        $published = $this->publishConfig(static fn (string $config): string => str_replace("'resources' => [", "'resources' => [ // modules", $config));

        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--iblock' => [1], '--hlblock' => [99]])
            ->expectsOutput("Could not find 'resources' in config/atrium.php. Add the models to it by hand:")
            ->expectsOutput('    '.self::NEWS.',')
            ->doesntExpectOutput('Registering models in config/atrium.php...')
            ->assertSuccessful();

        $this->assertSame($published, $this->config());
    }

    public function test_namespaces_come_from_the_migrator_config(): void
    {
        $this->publishConfig();
        config([
            'atrium-bitrix.model_namespace' => 'Site\\Content',
            'atrium-bitrix.reference_namespace' => 'Site\\Directories',
        ]);

        $this->artisan('atrium:bitrix:generate:models', ['--dry-run' => true, '--iblock' => [2], '--hlblock' => [99]])
            ->expectsOutput('  Would add: Site\\Content\\Product')
            ->expectsOutput('  Would add: Site\\Content\\ProductSection')
            ->expectsOutput('  Would add: Site\\Directories\\Brands')
            ->assertSuccessful();
    }
}
