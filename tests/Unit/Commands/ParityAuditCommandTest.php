<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Admin\Fields\Field;
use Atrium\Admin\Models\Site;
use Atrium\Bitrix\Commands\ParityAuditCommand;
use Atrium\Bitrix\Mappers\FieldTypeMapper;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * parity: the Bitrix structure against the generated models, field by field,
 * plus forms, list columns, widgets, sites and data volume.
 */
final class ParityAuditCommandTest extends SyntheticTestCase
{
    /** Generated classes outlive a test: each test gets a namespace of its own. */
    private string $models;

    private string $namespace;

    protected function setUp(): void
    {
        parent::setUp();

        $unique = 'Parity'.bin2hex(random_bytes(4));
        $this->namespace = self::NAMESPACE.'\\'.$unique;
        $this->models = $this->workDir.'/models/'.$unique;
        mkdir($this->models);
        config([
            'atrium-bitrix.model_namespace' => $this->namespace,
            'atrium-bitrix.model_path' => $this->models,
            'atrium-bitrix.reference_namespace' => $this->namespace.'\\References',
            'atrium-bitrix.reference_path' => $this->models.'/References',
        ]);
    }

    private function migrate(): void
    {
        $this->generateSchema();
        $this->artisan('atrium:bitrix:migrate:data', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    public function test_an_unreadable_bitrix_database_stops_the_audit(): void
    {
        $missing = $this->workDir.'/no-such-dir/bitrix.sqlite';
        config(['database.connections.gone' => ['driver' => 'sqlite', 'database' => $missing, 'prefix' => '']]);

        $this->artisan('atrium:bitrix:parity', ['--connection' => 'gone'])
            ->expectsOutputToContain('Cannot read the Bitrix database: Database file at path ['.$missing.']')
            ->expectsOutput('Check BITRIX_DB_DATABASE in .env and that the dump is imported.')
            ->doesntExpectOutputToContain('Auditing')
            ->assertFailed();
    }

    public function test_a_migrated_site_matches_bitrix(): void
    {
        $this->migrate();
        $this->artisan('atrium:bitrix:migrate:sites')->assertSuccessful();

        $this->artisan('atrium:bitrix:parity')
            ->expectsOutput('IBlocks: 2   Properties checked: 16   Problems: 0   Extra fields: 0')
            ->doesntExpectOutputToContain('Field problems')
            ->doesntExpectOutputToContain('Site s')
            ->expectsOutput('Forms fully matching Bitrix: 1 of 1')
            ->expectsOutput('Lists fully matching Bitrix: 1 of 1')
            ->expectsOutput('No mismatches.')
            ->assertSuccessful();
    }

    public function test_without_models_every_iblock_is_a_problem(): void
    {
        $this->artisan('atrium:bitrix:parity')
            ->expectsOutput('Auditing 2 IBlocks against Laravel models…')
            ->expectsTable(['IBlock', 'ID', 'Model', 'Table', 'Bitrix', 'Ours', 'Status'], [
                ['demo_news', 1, '—', '—', 2, '—', 'NO_MODEL'],
                ['demo_products', 2, '—', '—', 3, '—', 'NO_MODEL'],
            ])
            ->expectsTable(['IBlock', 'Property', 'Bitrix type', 'M', 'R', 'Field', 'Status', 'Detail'], [
                ['demo_news', '*', '-', '', '', '—', 'NO_MODEL', 'No Administrable model matches this IBlock'],
                ['demo_products', '*', '-', '', '', '—', 'NO_MODEL', 'No Administrable model matches this IBlock'],
            ])
            ->expectsOutputToContain('IBlocks: 2   Properties checked: 2   Problems: ')
            ->doesntExpectOutputToContain('Form: tabs')
            ->doesntExpectOutputToContain('List columns vs.')
            ->expectsOutput('No mismatches.')
            ->assertFailed();
    }

    public function test_the_audit_can_be_limited_to_some_iblocks(): void
    {
        $this->artisan('atrium:bitrix:parity', ['--iblock' => ['2', '99']])
            ->expectsOutput('Auditing 1 IBlocks against Laravel models…')
            ->expectsTable(['IBlock', 'ID', 'Model', 'Table', 'Bitrix', 'Ours', 'Status'], [
                ['demo_products', 2, '—', '—', 3, '—', 'NO_MODEL'],
            ])
            ->assertFailed();
    }

    public function test_the_markdown_report_groups_the_problems(): void
    {
        $path = $this->workDir.'/report/parity.md';

        $this->artisan('atrium:bitrix:parity', ['--format' => 'markdown', '--output' => $path])
            ->expectsOutput("Report written to {$path}")
            ->doesntExpectOutputToContain('IBlock → model mapping')
            ->assertFailed();

        $md = (string) file_get_contents($path);
        $this->assertStringStartsWith("# Field parity: Bitrix → Laravel\n\nIblocks: **2** · properties checked: **2** · mismatches: **2** · extra fields on our side: **0**\n\n", $md);
        $this->assertStringContainsString("| `NO_MODEL` | 2 | No model corresponds to the iblock |\n", $md);
        $this->assertStringContainsString("## Sites\n\n| LID | DIR | Domains | Site | Folder | Domains here | Status |\n|---|---|---|---|---|---|---|\n", $md);
        $this->assertStringContainsString("| s3 | `/site2/` | — | — | `—` | — | NO_SITE |\n", $md);
        $this->assertStringContainsString("| s1 | `/` | example.com, www.example.com | main | `/` | localhost | DOMAINS_MISMATCH |\n", $md);
        $this->assertStringContainsString("| demo_products | 2 | — | — | 3 | — | NO_MODEL |\n", $md);
        $this->assertStringContainsString("\n## NO_MODEL (2)\n\n", $md);
        $this->assertStringContainsString("| demo_news | `*` | News | `-` |  |  | `—` | No Administrable model matches this IBlock |\n", $md);
        $this->assertStringNotContainsString('Fields on our side', $md);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function formats(): iterable
    {
        yield 'json' => ['json', 'bitrix-parity.json'];
        yield 'markdown' => ['markdown', 'bitrix-parity.md'];
    }

    #[DataProvider('formats')]
    public function test_a_report_without_a_path_goes_to_storage(string $format, string $file): void
    {
        $this->app->useStoragePath($this->workDir.'/storage');
        $path = $this->workDir.'/storage/audit/'.$file;

        $this->artisan('atrium:bitrix:parity', ['--format' => $format])
            ->expectsOutput("Report written to {$path}")
            ->assertFailed();

        $this->assertStringContainsString('demo_products', (string) file_get_contents($path));
    }

    public function test_drift_between_bitrix_and_the_models_is_graded(): void
    {
        $this->migrate();
        $this->drift();

        $report = $this->report();

        $this->assertSame([
            'PREVIEW_TEXT' => ['NO_COLUMN'],
            'SOURCE' => ['TYPE_MISMATCH', 'MULTIPLE_LOST', 'REQUIRED_MISMATCH', 'NOT_FILLABLE'],
            'LINK_GOODS' => ['OK'],
            'RATING' => ['NO_COLUMN'],
            'AUTHOR' => ['NO_FIELD'],
            'OLD' => ['INACTIVE_IN_BITRIX'],
        ], $this->statuses($report, 'demo_news'));

        $news = $this->find($report['properties'], ['iblock' => 'demo_news', 'code' => 'RATING']);
        $this->assertSame('No column `rating` in `news` and no admin field', $news['detail']);
        $this->assertSame('Data is stored but there is no admin field to edit it', $this->find($report['properties'], ['code' => 'AUTHOR'])['detail']);
        $this->assertSame('Base IBlock field has no column', $this->find($report['properties'], ['code' => 'PREVIEW_TEXT'])['detail']);
        // Many strings are a repeatable list in Bitrix; the model renders one text input.
        $source = $this->find($report['properties'], ['code' => 'SOURCE', 'status' => 'MULTIPLE_LOST']);
        $this->assertSame(['expected_type' => 'repeatable', 'actual_type' => 'text', 'sort' => 100], $source['meta']);
        $this->assertSame('Bitrix implies repeatable, we render text', $this->find($report['properties'], ['code' => 'SOURCE', 'status' => 'TYPE_MISMATCH'])['detail']);
        $this->assertSame('Property is MULTIPLE in Bitrix but `text` holds one value', $this->find($report['properties'], ['code' => 'SOURCE', 'status' => 'MULTIPLE_LOST'])['detail']);
        $this->assertSame('`source` is not in $fillable — the value is discarded on save', $this->find($report['properties'], ['code' => 'SOURCE', 'status' => 'NOT_FILLABLE'])['detail']);
        $this->assertSame('Property is disabled in Bitrix — absence here is expected', $this->find($report['properties'], ['code' => 'OLD'])['detail']);

        $colour = $this->find($report['properties'], ['code' => 'COLOR', 'status' => 'ENUM_MISMATCH']);
        $this->assertSame('Missing option(s): red, 5 (Bitrix has 4, we have 2)', $colour['detail']);
        // The checkbox lost its only option in Bitrix: that is a select now, and the enums have nothing to compare.
        $this->assertSame(['TYPE_MISMATCH'], $this->statuses($report, 'demo_products')['ORDERABLE']);
        $this->assertSame('Bitrix implies select, we render checkbox', $this->find($report['properties'], ['code' => 'ORDERABLE'])['detail']);

        $this->assertSame(
            [['iblock' => 'demo_products', 'model' => 'Product', 'field' => 'article', 'type' => 'text', 'label' => 'Article number']],
            $report['extra_fields'],
        );
        $this->assertSame('SHORT by 1', $this->find($report['iblocks'], ['iblock' => 'demo_news'])['status']);
        $this->assertSame('OK', $this->find($report['iblocks'], ['iblock' => 'demo_products'])['status']);
    }

    public function test_a_second_audit_reports_what_the_first_did(): void
    {
        $this->migrate();
        $this->drift();

        $first = $this->report();
        $second = $this->report();

        $this->assertNotSame([], $first['properties']);
        $this->assertNotSame([], $first['extra_fields']);
        $this->assertSame($first, $second);
    }

    public function test_drift_shows_in_the_table_report(): void
    {
        $this->migrate();
        $this->drift();
        $this->artisan('atrium:bitrix:parity')
            ->expectsOutputToContain('| demo_news     | SOURCE       | S           | Y | Y | source       | MULTIPLE_LOST     | Property is MULTIPLE in Bitrix but `text` holds one value       |')
            ->expectsOutputToContain('| demo_products | COLOR        | L           |   |   | color        | ENUM_MISMATCH     | Missing option(s): red, 5 (Bitrix has 4, we have 2)             |')
            ->expectsOutputToContain('IBlocks: 2   Properties checked: 22   Problems: 9   Extra fields: 1')
            ->expectsOutputToContain('| demo_news     | 1  | News    | news     | 2      | 1    | SHORT by 1 |')
            ->expectsOutput('demo_news → news: SHORT by 1')
            ->assertFailed();
    }

    public function test_the_markdown_report_lists_the_extra_fields(): void
    {
        $this->migrate();
        $this->drift();
        $path = $this->workDir.'/parity.md';

        Artisan::call('atrium:bitrix:parity', ['--format' => 'markdown', '--output' => $path]);
        $md = (string) file_get_contents($path);
        $this->assertStringContainsString("| `ENUM_MISMATCH` | 1 | The list of values does not match |\n", $md);
        $this->assertStringContainsString("| `NOT_FILLABLE` | 1 | The field is in the form but not in \$fillable: the value is silently lost on save |\n", $md);
        $this->assertLessThan(strpos($md, '| `NO_COLUMN`'), strpos($md, '| `MULTIPLE_LOST`'), 'statuses in alphabetical order');
        $this->assertStringContainsString("| demo_news | `SOURCE` | Source | `S` | yes | yes | `source` | Required in Bitrix, optional here |\n", $md);
        $this->assertStringContainsString("\n## Fields on our side that are not in Bitrix (1)\n\nThis is not necessarily an error: some of them were added deliberately.\n\n| IBlock | Model | Field | Type | Label |\n|---|---|---|---|---|\n| demo_products | Product | `article` | `text` | Article number |\n", $md);
        $this->assertStringContainsString("| demo_news | 1 | News | news | 2 | 1 | SHORT by 1 |\n", $md);
    }

    public function test_forms_lists_and_widgets_that_moved_away_from_bitrix(): void
    {
        $this->migrate();
        config([
            'atrium-bitrix.hidden_list_columns' => ['products' => ['sort']],
            'atrium-bitrix.extra_list_columns' => ['products' => ['code']],
        ]);
        // Four fields of the first tab now sit on the preview tab in Bitrix.
        $option = $this->bitrix()->table('b_user_option')->where('NAME', 'form_element_2')->value('VALUE');
        $tabs = unserialize((string) $option)['tabs'];
        $moved = '--,--PROPERTY_11--#--Price--,--PROPERTY_23--#--Brand--,--PROPERTY_12--#--Colour--,--PROPERTY_13--#--Can be ordered';
        $tabs = str_replace($moved, '', $tabs);
        $tabs = str_replace('edit5--#--Preview', 'edit5--#--Preview'.$moved, $tabs);
        $this->bitrix()->table('b_user_option')->where('NAME', 'form_element_2')->update(['VALUE' => serialize(['tabs' => $tabs])]);
        // The photos take one file now; the colour is a set of checkboxes.
        $this->bitrix()->table('b_iblock_property')->where('ID', 15)->update(['MULTIPLE' => 'N']);
        $this->bitrix()->table('b_iblock_property')->where('ID', 12)->update(['MULTIPLE' => 'Y', 'LIST_TYPE' => 'C']);
        $this->bitrix()->table('b_iblock_property')->where('ID', 1)->update(['ACTIVE' => 'N', 'LIST_TYPE' => 'C', 'PROPERTY_TYPE' => 'L']);

        $this->artisan('atrium:bitrix:parity')
            ->expectsOutputToContain('| demo_products | Product, Preview, Details, SEO, Categories | = same as Bitrix | price, brand, color, orderable | 13/22 |')
            ->expectsOutput('Forms fully matching Bitrix: 0 of 1')
            ->expectsTable(['IBlock', 'Columns', 'Missing here', 'Extra here', 'Same order', 'Decided otherwise'], [
                ['demo_products', 7, 'code', 'sort', 'no', '−sort +code'],
            ])
            ->expectsOutput('Lists fully matching Bitrix: 0 of 1')
            ->expectsTable(['IBlock', 'Property', 'In Bitrix', 'Ours', 'Expected'], [
                ['demo_products', 'COLOR', 'checkboxes (list C, multiple)', 'select', 'multiSelect|checkboxList'],
                ['demo_products', 'PHOTOS', 'images', 'gallery', 'image|file'],
            ])
            ->doesntExpectOutput('No mismatches.')
            ->assertFailed();
    }

    public function test_only_hidden_or_only_added_list_columns_are_described(): void
    {
        $this->migrate();
        config(['atrium-bitrix.hidden_list_columns' => ['products' => ['sort', 'id']]]);

        $this->artisan('atrium:bitrix:parity')
            ->expectsTable(['IBlock', 'Columns', 'Missing here', 'Extra here', 'Same order', 'Decided otherwise'], [
                ['demo_products', 5, '—', 'sort, id', 'no', '−sort, −id'],
            ])
            ->assertFailed();
    }

    public function test_models_that_fail_or_lack_admin_methods(): void
    {
        $this->handWrittenModels();
        $path = $this->workDir.'/parity.json';

        $this->artisan('atrium:bitrix:parity', ['--format' => 'json', '--output' => $path, '--iblock' => [3, 4, 5, 6]])
            ->expectsOutput('Auditing 4 IBlocks against Laravel models…')
            ->expectsOutputToContain('Note::getAdministrationFields() threw: boom')
            ->expectsTable(['IBlock', 'Tabs in Bitrix', 'Tabs here', 'Field on the wrong tab', 'Order'], [
                ['iblock_3', 'Main, Preview', '', 'sort', '0/2'],
                ['demo_plain', 'Main', '', '—', '1/1'],
                ['demo_notes', 'Main', '', '—', '—'],
            ])
            ->expectsOutput('Forms fully matching Bitrix: 0 of 3')
            ->assertFailed();

        $report = json_decode((string) file_get_contents($path), true);
        $this->assertSame(
            ['iblock' => 'iblock_3', 'iblock_id' => 3, 'name' => 'Legacy', 'model' => 'Iblock3', 'table' => 'iblock3s', 'bitrix_elements' => 0, 'our_rows' => '—', 'status' => 'NO_TABLE'],
            $this->find($report['iblocks'], ['iblock_id' => 3]),
        );
        $this->assertSame('OK (empty)', $this->find($report['iblocks'], ['iblock_id' => 4])['status']);
        $this->assertSame('NO_DATA', $this->find($report['iblocks'], ['iblock_id' => 5])['status']);
        $this->assertSame('NO_TABLE', $this->find($report['iblocks'], ['iblock_id' => 6])['status']);

        $noColumn = ['ACTIVE' => ['NO_COLUMN'], 'CODE' => ['NO_COLUMN'], 'DETAIL_PICTURE' => ['NO_COLUMN'], 'DETAIL_TEXT' => ['NO_COLUMN'], 'NAME' => ['NO_COLUMN'], 'PREVIEW_PICTURE' => ['NO_COLUMN'], 'PREVIEW_TEXT' => ['NO_COLUMN'], 'SORT' => ['NO_COLUMN']];
        $this->assertSame($noColumn, $this->sorted($this->statuses($report, 'iblock_3')), 'fields without a table');
        $this->assertSame($noColumn, $this->sorted($this->statuses($report, 'demo_notes')), 'no fields, no table');
        $this->assertSame(['NO_FIELD'], $this->statuses($report, 'demo_bare')['NAME'], 'a model without admin fields');
        $this->assertSame('Column exists but is not editable in the admin', $this->find($report['properties'], ['iblock' => 'demo_bare', 'code' => 'NAME'])['detail']);
        $this->assertArrayNotHasKey('NAME', $this->statuses($report, 'demo_plain'), 'an editable column is fine');

        // Fields the admin adds itself (SEO, section, canonical) are not extras.
        $this->assertSame(
            [
                ['iblock' => 'iblock_3', 'model' => 'Iblock3', 'field' => 'legacy_note', 'type' => 'text', 'label' => 'Note'],
                ['iblock' => 'iblock_3', 'model' => 'Iblock3', 'field' => 'unlabelled', 'type' => 'text', 'label' => ''],
                ['iblock' => 'demo_plain', 'model' => 'Plain', 'field' => 'plain_note', 'type' => 'text', 'label' => ''],
            ],
            $report['extra_fields'],
        );
    }

    public function test_a_site_switched_off_here_but_on_in_bitrix(): void
    {
        $this->migrate();
        $this->artisan('atrium:bitrix:migrate:sites')->assertSuccessful();
        Site::query()->where('code', 's2')->sole()->update(['active' => false]);

        $report = $this->report();

        $this->assertSame('ACTIVE_MISMATCH', $this->find($report['sites'], ['lid' => 's2'])['status']);
        $this->assertSame('OK', $this->find($report['sites'], ['lid' => 's3'])['status'], 'switched off on both sides');
    }

    public function test_a_site_moved_to_another_folder(): void
    {
        $this->migrate();
        $this->artisan('atrium:bitrix:migrate:sites')->assertSuccessful();
        Site::query()->where('code', 's3')->sole()->update(['folder' => 'elsewhere']);

        $report = $this->report();

        $this->assertSame('FOLDER_MISMATCH', $this->find($report['sites'], ['lid' => 's3'])['status']);
        $this->assertSame('/elsewhere/', $this->find($report['sites'], ['lid' => 's3'])['folder']);
        $this->assertSame('OK', $this->find($report['sites'], ['lid' => 's2'])['status']);
    }

    /**
     * @return iterable<string,array{int,?int,string}>
     */
    public static function volumes(): iterable
    {
        yield 'no table' => [3, null, 'NO_TABLE'];
        yield 'empty on both sides' => [0, 0, 'OK (empty)'];
        yield 'nothing arrived' => [3, 0, 'NO_DATA'];
        yield 'some arrived' => [3, 1, 'SHORT by 2'];
        yield 'all arrived' => [3, 3, 'OK'];
        yield 'more here' => [3, 4, 'OK'];
        yield 'rows here, none in Bitrix' => [0, 2, 'OK'];
    }

    #[DataProvider('volumes')]
    public function test_data_volume_status(int $bitrix, ?int $ours, string $expected): void
    {
        $this->assertSame($expected, $this->invoke('dataStatus', $bitrix, $ours));
    }

    public function test_the_widget_type_of_a_field_class(): void
    {
        $this->assertSame('text', $this->invoke('fieldTypeFromClass', 'TextField'));
        $this->assertSame('repeatable', $this->invoke('fieldTypeFromClass', 'RepeatableField'));
        $this->assertSame('belongsToMany', $this->invoke('fieldTypeFromClass', 'BelongsToManyField'));
    }

    /**
     * News: SOURCE is now required and multiple and left $fillable, three
     * properties were added, the preview text column is gone, a row is lost.
     * Products: a new colour (one without an XML_ID), ORDERABLE lost its
     * option, ARTICLE is gone from Bitrix.
     */
    private function drift(): void
    {
        $db = $this->bitrix();
        $db->table('b_iblock_property')->where('ID', 1)->update(['IS_REQUIRED' => 'Y', 'MULTIPLE' => 'Y']);
        $db->table('b_iblock_property')->insert([
            ['ID' => 3, 'IBLOCK_ID' => 1, 'NAME' => 'Rating', 'CODE' => 'RATING', 'PROPERTY_TYPE' => 'N', 'SORT' => 300, 'MULTIPLE' => 'N', 'ACTIVE' => 'Y'],
            ['ID' => 4, 'IBLOCK_ID' => 1, 'NAME' => 'Author', 'CODE' => 'AUTHOR', 'PROPERTY_TYPE' => 'E', 'SORT' => 400, 'MULTIPLE' => 'N', 'ACTIVE' => 'Y'],
            ['ID' => 5, 'IBLOCK_ID' => 1, 'NAME' => 'Old', 'CODE' => 'OLD', 'PROPERTY_TYPE' => 'S', 'SORT' => 500, 'MULTIPLE' => 'N', 'ACTIVE' => 'N'],
        ]);
        $db->table('b_iblock_property_enum')->insert([
            ['ID' => 4, 'PROPERTY_ID' => 12, 'VALUE' => 'Red', 'XML_ID' => 'red', 'SORT' => 30, 'DEF' => 'N'],
            ['ID' => 5, 'PROPERTY_ID' => 12, 'VALUE' => 'Blue', 'XML_ID' => '', 'SORT' => 40, 'DEF' => 'N'],
        ]);
        $db->table('b_iblock_property_enum')->where('PROPERTY_ID', 13)->delete();
        $db->table('b_iblock_property')->where('ID', 10)->delete();

        Schema::table('news', function (Blueprint $t): void {
            $t->dropColumn(['preview_text', 'active_to']);
        });
        DB::table('news_link_goods')->where('news_id', 5)->delete();
        DB::table('news')->where('id', 5)->delete();

        $file = $this->models.'/News.php';
        file_put_contents($file, str_replace("        'source',\n", '', (string) file_get_contents($file)));
    }

    /**
     * Iblock 3 (no code) has a model that describes its fields as arrays and
     * no table; iblock 4 a model without tabs and a form in Bitrix; iblock 5
     * a model with no admin methods at all; iblock 6 a model that throws.
     */
    private function handWrittenModels(): void
    {
        $this->bitrix()->table('b_iblock')->insert([
            ['ID' => 3, 'IBLOCK_TYPE_ID' => 'content', 'CODE' => null, 'NAME' => 'Legacy', 'ACTIVE' => 'Y', 'SORT' => 300, 'VERSION' => 1],
            ['ID' => 4, 'IBLOCK_TYPE_ID' => 'content', 'CODE' => 'demo_plain', 'NAME' => 'Plain', 'ACTIVE' => 'Y', 'SORT' => 400, 'VERSION' => 1],
            ['ID' => 5, 'IBLOCK_TYPE_ID' => 'content', 'CODE' => 'demo_bare', 'NAME' => 'Bare', 'ACTIVE' => 'Y', 'SORT' => 500, 'VERSION' => 1],
            ['ID' => 6, 'IBLOCK_TYPE_ID' => 'content', 'CODE' => 'demo_notes', 'NAME' => 'Notes', 'ACTIVE' => 'Y', 'SORT' => 600, 'VERSION' => 1],
        ]);
        $this->bitrix()->table('b_iblock_element')->insert([
            'ID' => 9, 'IBLOCK_ID' => 5, 'NAME' => 'Only one', 'ACTIVE' => 'Y', 'SORT' => 500,
        ]);
        $this->bitrix()->table('b_user_option')->insert([
            'ID' => 3, 'USER_ID' => null, 'CATEGORY' => 'form', 'NAME' => 'form_element_4', 'COMMON' => 'Y',
            'VALUE' => serialize(['tabs' => 'edit1--#--Main--,--NAME--#--Name--;--']),
        ]);
        // A form for the model that describes its fields as arrays, one for the model that throws.
        $this->bitrix()->table('b_user_option')->insert([
            ['ID' => 4, 'USER_ID' => null, 'CATEGORY' => 'form', 'NAME' => 'form_element_3', 'COMMON' => 'Y', 'VALUE' => serialize(['tabs' => 'edit1--#--Main--,--NAME--#--Name--;--edit5--#--Preview--,--SORT--#--Sort--;--'])],
            ['ID' => 5, 'USER_ID' => null, 'CATEGORY' => 'form', 'NAME' => 'form_element_6', 'COMMON' => 'Y', 'VALUE' => serialize(['tabs' => 'edit1--#--Main--,--NAME--#--Name--;--'])],
        ]);
        Schema::create('plains', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('bares', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });

        $namespace = $this->namespace;
        file_put_contents($this->models.'/Iblock3.php', <<<PHP
            <?php

            namespace {$namespace};

            class Iblock3 extends \\Illuminate\\Database\\Eloquent\\Model
            {
                public static function getAdministrationFields(): array
                {
                    return [
                        ['name' => 'legacy_note', 'type' => 'text', 'label' => 'Note'],
                        ['name' => 'unlabelled', 'type' => 'text'],
                        ['name' => 'seo_title', 'type' => 'text'],
                        ['name' => 'meta_keywords', 'type' => 'text'],
                        ['name' => 'section_id', 'type' => 'select'],
                        ['name' => 'canonical_url', 'type' => 'text'],
                        ['name' => 'sort', 'type' => 'number'],
                        ['name' => 'name', 'type' => 'text', 'tab' => 'params'],
                    ];
                }
            }
            PHP);
        file_put_contents($this->models.'/Plain.php', <<<PHP
            <?php

            namespace {$namespace};

            use Atrium\\Admin\\Fields\\TextField;

            class Plain extends \\Illuminate\\Database\\Eloquent\\Model
            {
                protected \$fillable = ['name'];

                public static function getAdministrationFields(): array
                {
                    return [
                        TextField::make('name'),
                        TextField::make('plain_note'),
                    ];
                }
            }
            PHP);
        file_put_contents($this->models.'/Bare.php', <<<PHP
            <?php

            namespace {$namespace};

            class Bare extends \\Illuminate\\Database\\Eloquent\\Model
            {
            }
            PHP);
        file_put_contents($this->models.'/Note.php', <<<PHP
            <?php

            namespace {$namespace};

            class Note extends \\Illuminate\\Database\\Eloquent\\Model
            {
                public static function getAdministrationFields(): array
                {
                    throw new \\RuntimeException('boom');
                }
            }
            PHP);
    }

    /**
     * @return array<string,mixed>
     */
    private function report(): array
    {
        $path = $this->workDir.'/parity.json';
        Artisan::call('atrium:bitrix:parity', ['--format' => 'json', '--output' => $path]);

        return json_decode((string) file_get_contents($path), true);
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,list<string>> property code => statuses, in report order
     */
    private function statuses(array $report, string $iblock): array
    {
        $out = [];
        foreach ($report['properties'] as $row) {
            if ($row['iblock'] === $iblock) {
                $out[$row['code']][] = $row['status'];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,list<string>>  $statuses
     * @return array<string,list<string>>
     */
    private function sorted(array $statuses): array
    {
        ksort($statuses);

        return $statuses;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $match
     * @return array<string,mixed>
     */
    private function find(array $rows, array $match): array
    {
        foreach ($rows as $row) {
            if (array_intersect_assoc($match, $row) === $match) {
                return $row;
            }
        }

        $this->fail('No row matches '.json_encode($match));
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $command = $this->app->make(ParityAuditCommand::class);
        $command->setLaravel($this->app);
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        return (function () use ($method, $args) {
            $this->mapper ??= new FieldTypeMapper;

            return $this->{$method}(...$args);
        })->call($command);
    }
}
