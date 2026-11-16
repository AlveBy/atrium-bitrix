<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Tests;

use Atrium\Admin\AtriumServiceProvider;
use Atrium\Bitrix\BitrixServiceProvider;
use Atrium\Bitrix\Tests\Fixtures\SyntheticBitrix;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Tighten\Ziggy\ZiggyServiceProvider;

/**
 * The whole move on a made-up Bitrix site: models, migrations, data, SEO and
 * the media library, then the parity report must have nothing to say.
 */
final class FullRunTest extends TestCase
{
    use RefreshDatabase;

    private const NAMESPACE = 'Atrium\\Bitrix\\Tests\\Generated';

    private string $workDir;

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            ZiggyServiceProvider::class,
            AtriumServiceProvider::class,
            BitrixServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->workDir = sys_get_temp_dir().'/atrium-bitrix-'.bin2hex(random_bytes(4));
        mkdir($this->workDir.'/models', 0777, true);
        mkdir($this->workDir.'/migrations', 0777, true);
        mkdir($this->workDir.'/public', 0777, true);

        $app->usePublicPath($this->workDir.'/public');

        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        // CI runs the suite against MySQL and PostgreSQL too; the migrator's
        // SQL has to work there, not just on SQLite.
        $target = env('DB_CONNECTION');
        $config->set('database.default', in_array($target, ['mysql', 'pgsql'], true) ? $target : 'testing');
        $config->set('database.connections.bitrix', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $config->set('atrium-bitrix.connection', 'bitrix');
        $config->set('atrium-bitrix.strip_prefixes', ['demo_']);
        $config->set('atrium-bitrix.model_namespace', self::NAMESPACE);
        $config->set('atrium-bitrix.reference_namespace', self::NAMESPACE.'\\References');
        $config->set('atrium-bitrix.model_path', $this->workDir.'/models');
        $config->set('atrium-bitrix.reference_path', $this->workDir.'/models/References');
        $config->set('atrium-bitrix.migration_path', $this->workDir.'/migrations');
        $config->set('atrium.auth.two_factor.enabled', false);
        $config->set('atrium.dashboard.timings.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        SyntheticBitrix::install(DB::connection('bitrix'));
        SyntheticBitrix::writeUploads($this->workDir.'/public');

        // The generated classes live outside Composer's autoloader.
        spl_autoload_register(function (string $class): void {
            if (! str_starts_with($class, self::NAMESPACE.'\\')) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen(self::NAMESPACE) + 1));
            $file = $this->workDir.'/models/'.$relative.'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    public function test_a_bitrix_site_moves_over_and_nothing_is_lost(): void
    {
        $this->artisan('atrium:bitrix:generate:migrations', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->artisan('migrate', ['--path' => $this->workDir.'/migrations', '--realpath' => true])->assertSuccessful();
        $this->artisan('atrium:bitrix:generate:models', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->artisan('atrium:bitrix:migrate:data', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->artisan('atrium:bitrix:migrate:seo', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->artisan('atrium:bitrix:migrate:medialibrary', ['--no-interaction' => true])->assertSuccessful();

        $report = $this->workDir.'/parity.json';
        $exit = Artisan::call('atrium:bitrix:parity', ['--format' => 'json', '--output' => $report]);
        $json = (string) file_get_contents($report);
        $output = Artisan::output();

        $this->assertSame([], $this->problems(json_decode($json, true)), $json);
        $this->assertSame(0, $exit, $output);

        // The form, list and widget audits are printed after the report.
        $this->assertStringContainsString('Forms fully matching Bitrix: 1 of 1', $output);
        $this->assertStringContainsString('Lists fully matching Bitrix: 1 of 1', $output);
        $this->assertMatchesRegularExpression('/Widgets vs\. property settings in Bitrix\s+No mismatches\./', $output);

        $this->assertDataArrived();

        $this->artisan('atrium:bitrix:analyze')->assertSuccessful();
        $this->artisan('atrium:bitrix:verify', ['--force' => true, '--check-files' => true, '--no-interaction' => true])->assertSuccessful();

        // Catch-up: an element edited in Bitrix after the move comes over again.
        DB::connection('bitrix')->table('b_iblock_element')->where('ID', 2)->update([
            'NAME' => 'Laptop Air 2',
            'TIMESTAMP_X' => now()->addDay()->format('Y-m-d H:i:s'),
        ]);
        $this->artisan('atrium:bitrix:sync', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertSame('Laptop Air 2', DB::table('products')->where('bitrix_id', 2)->value('name'));
        $this->assertSame('Laptop Pro', DB::table('products')->where('bitrix_id', 1)->value('name'));

        // A lost row makes the report fail, not just a lost field.
        DB::table('news')->where('bitrix_id', 4)->delete();
        $this->assertSame(1, Artisan::call('atrium:bitrix:parity'));
        $this->assertStringContainsString('SHORT by 1', Artisan::output());
    }

    /**
     * Spot checks on what the parity report does not look at: the values.
     */
    private function assertDataArrived(): void
    {
        $this->assertSame(3, DB::table('products')->count());
        $this->assertSame(2, DB::table('news')->count());

        $laptop = DB::table('products')->where('bitrix_id', 1)->first();
        $this->assertNotNull($laptop);
        $this->assertSame('laptop-pro', $laptop->code);
        $this->assertSame('LP-001', $laptop->article);
        $this->assertEquals(1999.5, $laptop->price);
        $this->assertSame('silver', $laptop->color);
        $this->assertSame('Y', $laptop->orderable);
        $this->assertSame(['Backlit keyboard', 'Fingerprint reader'], json_decode((string) $laptop->features, true));
        $this->assertSame((int) DB::table('brands')->where('xml_id', 'northwind')->value('id'), (int) $laptop->brand);
        $this->assertCount(2, (array) json_decode((string) $laptop->photos, true));
        $this->assertNotEmpty($laptop->manual);
        $this->assertFileExists(public_path(ltrim((string) $laptop->preview_picture, '/')));
        $this->assertStringContainsString('16 GB', (string) $laptop->specs);

        $section = DB::table('product_sections')->where('bitrix_id', 2)->first();
        $this->assertNotNull($section);
        $this->assertSame((int) $section->id, (int) $laptop->section_id);
        $this->assertSame(
            (int) DB::table('product_sections')->where('bitrix_id', 1)->value('id'),
            (int) $section->parent_id,
        );
        $this->assertSame('Keep it short', DB::table('product_sections')->where('bitrix_id', 1)->value('note'));

        $this->assertSame(2, DB::table('news_link_goods')->count());
        $this->assertSame(2, DB::table('products_related')->count());
        $this->assertSame(2, DB::table('brands')->count());

        $seo = DB::table('seo_meta')->where('seoable_id', $laptop->id)->where('title', 'Laptop Pro — buy online')->first();
        $this->assertNotNull($seo);
        $this->assertSame('A light and powerful laptop.', $seo->description);
        $this->assertSame('Laptop Pro, front', $seo->preview_picture_alt);
        $this->assertSame('laptop, pro', $seo->tags);

        $this->assertSame('Company logo', DB::table('media_library_items')->value('name'));
        $this->assertSame('Logos', DB::table('media_library_collections')->value('name'));
    }

    /**
     * @param  mixed  $report
     * @return array<int,mixed>
     */
    private function problems($report): array
    {
        $this->assertIsArray($report);

        $rows = array_merge($report['iblocks'], $report['properties'], $report['extra_fields']);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ! in_array($row['status'] ?? 'OK', ['OK', 'OK (empty)'], true),
        ));
    }
}
