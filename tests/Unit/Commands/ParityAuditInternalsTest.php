<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Admin\Models\Site;
use Atrium\Bitrix\Commands\ParityAuditCommand;
use Atrium\Bitrix\Mappers\FieldTypeMapper;
use Atrium\Bitrix\Schema\IBlockProperty;
use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Support\NamingConvention;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * parity, the parts the report test does not reach: each audit section run on
 * its own against iblocks and hand-written models made for the case.
 */
final class ParityAuditInternalsTest extends SyntheticTestCase
{
    /** Generated classes outlive a test: each test gets a namespace of its own. */
    private string $models;

    private string $namespace;

    protected function setUp(): void
    {
        parent::setUp();

        $unique = 'ParityInternals'.bin2hex(random_bytes(4));
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

    public function test_a_property_without_a_code_is_placed_by_the_code_made_from_its_name(): void
    {
        $this->iblock(20, 'demo_warranty', 'content');
        $this->bitrix()->table('b_iblock_property')->insert([
            'ID' => 40, 'IBLOCK_ID' => 20, 'NAME' => 'Warranty', 'CODE' => null, 'PROPERTY_TYPE' => 'S', 'SORT' => 100, 'MULTIPLE' => 'N', 'ACTIVE' => 'Y',
        ]);
        $this->form(20, 'edit1--#--Main--,--NAME--#--Name--,--PROPERTY_40--#--Warranty--;--');
        $this->model('Warranty', [
            ['name' => 'name', 'type' => 'text'],
            ['name' => 'warranty', 'type' => 'text'],
        ]);

        $this->artisan('atrium:bitrix:parity', ['--iblock' => ['20']])
            ->expectsTable(['IBlock', 'Tabs in Bitrix', 'Tabs here', 'Field on the wrong tab', 'Order'], [
                ['demo_warranty', 'Main', '', '—', '2/2'],
            ])
            ->assertFailed();
    }

    public function test_list_columns_are_compared_iblock_by_iblock(): void
    {
        $this->migrate();
        // 3 has no model, 7 (no code) matches, 8 lacks a column, 9 has them in another order,
        // 11 has a property with no code in its list.
        $this->model('Iblock7', [], columns: ['name', 'sort']);
        $this->model('Iblock8', [], columns: ['name']);
        $this->model('Iblock9', [], columns: ['sort', 'name']);
        $this->model('Iblock11', [], columns: ['name', 'warranty']);
        $this->property(70, 11, null, ['NAME' => 'Warranty']);
        foreach ([7, 8, 9] as $id) {
            $this->listSettings('content', $id, 'NAME,SORT');
        }
        $this->listSettings('content', 11, 'NAME,PROPERTY_70');

        $output = $this->printed('auditListColumns', [
            $this->iblockRow(3, null),
            $this->iblockRow(2, 'demo_products', 'catalog'),
            $this->iblockRow(7, ''),
            $this->iblockRow(8, 'iblock8'),
            $this->iblockRow(9, null),
            $this->iblockRow(11, null),
        ]);

        $this->assertSame(<<<'TXT'

            List columns vs. list settings in Bitrix
            +---------------+---------+--------------+------------+------------+-------------------+
            | IBlock        | Columns | Missing here | Extra here | Same order | Decided otherwise |
            +---------------+---------+--------------+------------+------------+-------------------+
            | demo_products | 7       | —            | —          | yes        | —                 |
            | iblock_7      | 2       | —            | —          | yes        | —                 |
            | iblock8       | 2       | sort         | —          | no         | —                 |
            | iblock_9      | 2       | —            | —          | no         | —                 |
            | iblock_11     | 2       | —            | —          | yes        | —                 |
            +---------------+---------+--------------+------------+------------+-------------------+
            Lists fully matching Bitrix: 3 of 5

            TXT, $output);
    }

    public function test_widgets_are_compared_property_by_property(): void
    {
        // 3 has no model, 4 a model with no admin fields, 12 (no code) the real thing.
        file_put_contents($this->models.'/Iblock4.php', "<?php\n\nnamespace {$this->namespace};\n\nclass Iblock4 extends \\Illuminate\\Database\\Eloquent\\Model\n{\n}\n");
        $this->model('Iblock12', [
            ['name' => 'old_list', 'type' => 'text'],
            ['name' => 'big_color', 'type' => 'select'],
            ['name' => 'bigColor', 'type' => 'multiSelect'],
            ['name' => 'manual', 'type' => 'text'],
            ['name' => 'agree', 'type' => 'select'],
            ['name' => 'photos', 'type' => 'select'],
            ['name' => 'plain', 'type' => 'text'],
        ]);
        // Switched off in Bitrix: not compared, and the ones after it still are.
        $this->property(101, 12, 'OLD_LIST', ['ACTIVE' => 'N', 'PROPERTY_TYPE' => 'L', 'LIST_TYPE' => 'C']);
        // Has no field: skipped, the next one is still compared.
        $this->property(102, 12, 'NO_FIELD_PROP', ['PROPERTY_TYPE' => 'L', 'LIST_TYPE' => 'C']);
        // Checkboxes, many; two fields could be its widget, the column name wins.
        $this->property(103, 12, 'BIG_COLOR', ['PROPERTY_TYPE' => 'L', 'LIST_TYPE' => 'C', 'MULTIPLE' => 'Y']);
        $this->enums(103, ['red', 'blue']);
        $this->property(104, 12, 'MANUAL', ['PROPERTY_TYPE' => 'F', 'FILE_TYPE' => 'pdf, doc']);
        // One option shown as checkboxes: a plain checkbox.
        $this->property(105, 12, 'AGREE', ['PROPERTY_TYPE' => 'L', 'LIST_TYPE' => 'C']);
        $this->enums(105, ['Y']);
        // The plural of the property code is the name of the field.
        $this->property(106, 12, 'PHOTO', ['PROPERTY_TYPE' => 'F', 'FILE_TYPE' => 'jpg, png', 'MULTIPLE' => 'Y']);
        // A plain string property is nothing to compare.
        $this->property(107, 12, 'PLAIN');

        $output = $this->printed('auditWidgets', [
            $this->iblockRow(3, null),
            $this->iblockRow(4, null),
            $this->iblockRow(12, ''),
        ]);

        $this->assertSame(<<<'TXT'

            Widgets vs. property settings in Bitrix
            +-----------+-----------+-------------------------------+--------+--------------------------+
            | IBlock    | Property  | In Bitrix                     | Ours   | Expected                 |
            +-----------+-----------+-------------------------------+--------+--------------------------+
            | iblock_12 | BIG_COLOR | checkboxes (list C, multiple) | select | multiSelect|checkboxList |
            | iblock_12 | MANUAL    | files (pdf, doc)              | text   | image|file               |
            | iblock_12 | AGREE     | checkbox (list C, 1 option)   | select | checkbox                 |
            | iblock_12 | PHOTO     | images                        | select | gallery                  |
            +-----------+-----------+-------------------------------+--------+--------------------------+

            TXT, $output);
    }

    public function test_no_widget_mismatch_is_said_in_one_line(): void
    {
        $this->assertSame("\nWidgets vs. property settings in Bitrix\nNo mismatches.\n", $this->printed('auditWidgets', [$this->iblockRow(3, null)]));
    }

    /**
     * @return iterable<string,array{string,string,string,string,int,?array{bitrix:string,types:list<string>}}>
     */
    public static function widgets(): iterable
    {
        yield 'a single option shown as checkboxes' => ['L', 'N', 'C', '', 1, ['bitrix' => 'checkbox (list C, 1 option)', 'types' => ['checkbox']]];
        yield 'several options, one value' => ['L', 'N', 'C', '', 2, ['bitrix' => 'radio buttons (list C)', 'types' => ['select', 'radio']]];
        yield 'no options, one value' => ['L', 'N', 'C', '', 0, ['bitrix' => 'radio buttons (list C)', 'types' => ['select', 'radio']]];
        yield 'checkboxes, many values' => ['L', 'Y', 'C', '', 2, ['bitrix' => 'checkboxes (list C, multiple)', 'types' => ['multiSelect', 'checkboxList']]];
        yield 'a single option, many values' => ['L', 'Y', 'C', '', 1, ['bitrix' => 'checkboxes (list C, multiple)', 'types' => ['multiSelect', 'checkboxList']]];
        yield 'a dropdown list' => ['L', 'N', 'L', '', 1, null];
        yield 'a string with the checkbox list type' => ['S', 'N', 'C', '', 1, null];
        yield 'one image' => ['F', 'N', '', 'jpg, png', 0, ['bitrix' => 'images', 'types' => ['image', 'file']]];
        yield 'many images' => ['F', 'Y', '', 'jpg', 0, ['bitrix' => 'images', 'types' => ['gallery']]];
        yield 'one document' => ['F', 'N', '', 'pdf', 0, ['bitrix' => 'files (pdf)', 'types' => ['image', 'file']]];
        yield 'documents in capitals' => ['F', 'Y', '', 'DOC, XLS', 0, ['bitrix' => 'files (DOC, XLS)', 'types' => ['gallery']]];
        yield 'text files' => ['F', 'N', '', 'txt', 0, ['bitrix' => 'files (txt)', 'types' => ['image', 'file']]];
        yield 'a file with no extensions named' => ['F', 'N', '', '', 0, null];
        yield 'a string with extensions' => ['S', 'N', '', 'jpg', 0, null];
    }

    /**
     * @param  ?array{bitrix:string,types:list<string>}  $expected
     */
    #[DataProvider('widgets')]
    public function test_the_widget_a_bitrix_property_asks_for(string $type, string $multiple, string $listType, string $fileType, int $options, ?array $expected): void
    {
        $this->enums(900, array_map(static fn (int $i): string => 'v'.$i, range(1, $options)));
        $property = IBlockProperty::fromArray(['ID' => 900, 'IBLOCK_ID' => 1, 'NAME' => 'Thing', 'CODE' => 'THING', 'PROPERTY_TYPE' => $type, 'MULTIPLE' => $multiple]);

        $this->assertSame($expected, $this->returned('expectedWidget', $property, $listType, $fileType));
    }

    public function test_forms_are_compared_tab_by_tab_and_field_by_field(): void
    {
        $main = 'edit1--#--Main--,--NAME--#--Name--,--SORT--#--Sort--,--ACTIVE--#--Active--;--edit5--#--Preview--,--PREVIEW_TEXT--#--Text--;--';
        $five = 'edit1--#--Main--,--NAME--#--Name--,--CODE--#--Code--,--ACTIVE--#--Active--,--SORT--#--Sort--,--ACTIVE_FROM--#--From--;--';
        $four = 'edit1--#--Main--,--NAME--#--Name--,--CODE--#--Code--,--ACTIVE--#--Active--,--SORT--#--Sort--;--';
        $one = 'edit1--#--Main--,--NAME--#--Name--;--';
        $this->form(30, $main);
        $this->form(31, $one);
        $this->form(32, $main);
        $this->form(33, $five);
        $this->form(34, $four);
        $this->form(35, $one);
        $same = ['Main', 'Preview'];
        $this->model('Iblock30', [
            ['name' => 'name', 'type' => 'text'],
            ['name' => 'sort', 'type' => 'number'],
            ['name' => 'active', 'type' => 'checkbox'],
            ['name' => 'preview_text', 'type' => 'content', 'tab' => 'preview'],
        ], $same);
        $this->model('Iblock31', [['name' => 'nothing_like_it', 'type' => 'text']], ['Main']);
        $this->model('Iblock32', [
            ['name' => 'sort', 'type' => 'number'],
            ['name' => 'name', 'type' => 'text'],
        ], $same);
        $moved = array_map(static fn (string $name): array => ['name' => $name, 'type' => 'text', 'tab' => 'preview'], ['name', 'code', 'active', 'sort', 'active_from']);
        $this->model('Iblock33', $moved, ['Main']);
        $this->model('Iblock34', array_slice($moved, 0, 4), ['Main']);
        $this->model('Iblock35', [['name' => 'name', 'type' => 'text']], ['Other']);

        $output = $this->printed('auditLayouts', array_map(fn (int $id): array => $this->iblockRow($id, null), [99, 30, 31, 32, 33, 34, 35]));

        $this->assertSame(<<<'TXT'

            Form: tabs and field order (reference: the Bitrix admin layout)
            +-----------+----------------+------------------+---------------------------+-------+
            | IBlock    | Tabs in Bitrix | Tabs here        | Field on the wrong tab    | Order |
            +-----------+----------------+------------------+---------------------------+-------+
            | iblock_30 | Main, Preview  | = same as Bitrix | —                         | 4/4   |
            | iblock_31 | Main           | = same as Bitrix | —                         | —     |
            | iblock_32 | Main, Preview  | = same as Bitrix | —                         | 0/2   |
            | iblock_33 | Main           | = same as Bitrix | name, code, active, sort… | 5/5   |
            | iblock_34 | Main           | = same as Bitrix | name, code, active, sort  | 4/4   |
            | iblock_35 | Main           | Other            | —                         | 1/1   |
            +-----------+----------------+------------------+---------------------------+-------+
            Forms fully matching Bitrix: 1 of 6

            TXT, $output);
    }

    public function test_one_iblock_is_audited_against_its_model(): void
    {
        $this->iblock(40, 'demo_probe', 'content');
        Schema::create('probes', function (Blueprint $t): void {
            $t->id();
            $t->string('NAME');
            $t->string('code');
        });
        $this->property(201, 40, 'BIG_NOTE', ['ACTIVE' => 'N']);
        $this->property(202, 40, 'PHOTO');
        $this->property(203, 40, 'GADGETS');
        $this->property(204, 40, 'BOOK');
        $this->model('Probe', [
            ['name' => 'name', 'type' => 'text'],
            ['name' => 'bigNote', 'type' => 'text'],
            ['name' => 'photo', 'type' => 'text'],
            ['name' => 'photos', 'type' => 'text', 'label' => 'Many photos'],
            ['name' => 'gadget', 'type' => 'text'],
            ['name' => 'books', 'type' => 'text'],
            ['name' => 'seo_title', 'type' => 'text'],
            ['name' => 'after_seo', 'type' => 'text'],
        ]);

        $report = $this->report(['--iblock' => ['40']]);

        $this->assertSame([
            'CODE' => ['code', 'NO_FIELD'],
            'ACTIVE' => ['active', 'NO_COLUMN'],
            'SORT' => ['sort', 'NO_COLUMN'],
            'PREVIEW_PICTURE' => ['preview_picture', 'NO_COLUMN'],
            'PREVIEW_TEXT' => ['preview_text', 'NO_COLUMN'],
            'DETAIL_PICTURE' => ['detail_picture', 'NO_COLUMN'],
            'DETAIL_TEXT' => ['detail_text', 'NO_COLUMN'],
            'BIG_NOTE' => ['bigNote', 'INACTIVE_IN_BITRIX'],
            'PHOTO' => ['photo', 'OK'],
            'GADGETS' => ['gadget', 'OK'],
            'BOOK' => ['books', 'OK'],
        ], array_map(static fn (array $row): array => [$row['field'], $row['status']], array_column($report['properties'], null, 'code')));
        $this->assertSame([
            'iblock' => 'demo_probe', 'iblockId' => 40, 'code' => 'SORT', 'name' => 'SORT', 'type' => 'base', 'multiple' => false, 'required' => false,
            'model' => 'Probe', 'field' => 'sort', 'status' => 'NO_COLUMN', 'detail' => 'Base IBlock field has no column', 'meta' => [],
        ], $report['properties'][2]);
        $this->assertSame([
            'iblock' => 'demo_probe', 'iblockId' => 40, 'code' => 'CODE', 'name' => 'CODE', 'type' => 'base', 'multiple' => false, 'required' => false,
            'model' => 'Probe', 'field' => 'code', 'status' => 'NO_FIELD', 'detail' => 'Column exists but is not editable in the admin', 'meta' => [],
        ], $report['properties'][0]);
        $this->assertSame([
            ['iblock' => 'demo_probe', 'model' => 'Probe', 'field' => 'photos', 'type' => 'text', 'label' => 'Many photos'],
            ['iblock' => 'demo_probe', 'model' => 'Probe', 'field' => 'after_seo', 'type' => 'text', 'label' => ''],
        ], $report['extra_fields']);
    }

    public function test_an_iblock_id_given_as_a_string_is_still_an_id(): void
    {
        [$command] = $this->prepared();
        $iblock = $this->iblockRow(3, null);

        (fn () => $this->auditIBlock($iblock))->call($command);

        $this->assertSame(
            [['iblock' => 'iblock_3', 'iblock_id' => 3, 'name' => 'Iblock 3', 'model' => '—', 'table' => '—', 'bitrix_elements' => 0, 'our_rows' => '—', 'status' => 'NO_MODEL']],
            (fn () => $this->iblockRows)->call($command),
        );
    }

    public function test_the_audit_takes_an_iblock_model_from_a_namespace_with_a_trailing_backslash(): void
    {
        $this->model('Iblock50', []);
        config(['atrium-bitrix.model_namespace' => $this->namespace.'\\']);

        $this->assertSame($this->namespace.'\\Iblock50', $this->returned('resolveModel', null, 50));
        $this->assertNull($this->returned('resolveModel', null, 51));
    }

    public function test_a_model_without_admin_fields_has_none_and_says_nothing(): void
    {
        file_put_contents($this->models.'/Bare.php', "<?php\n\nnamespace {$this->namespace};\n\nclass Bare extends \\Illuminate\\Database\\Eloquent\\Model\n{\n}\n");

        $this->assertSame([], $this->returned('adminFields', $this->namespace.'\\Bare'));
        $this->assertSame('', $this->printed('adminFields', $this->namespace.'\\Bare'));
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function report(array $options = []): array
    {
        $path = $this->workDir.'/parity.json';
        Artisan::call('atrium:bitrix:parity', ['--format' => 'json', '--output' => $path, ...$options]);

        return json_decode((string) file_get_contents($path), true);
    }

    public function test_at_most_five_missing_options_are_listed(): void
    {
        $this->enums(950, ['a', 'b', 'c', 'd', 'e', 'f']);
        $this->enums(951, ['a', 'b', 'c', 'd', 'e']);
        $this->enums(952, ['5']);

        $this->assertSame('Missing option(s): a, b, c, d, e (Bitrix has 6, we have 0)', $this->returned('compareEnum', $this->listProperty(950), ['options' => []]));
        $this->assertSame('Missing option(s): a, b, c, d, e (Bitrix has 5, we have 0)', $this->returned('compareEnum', $this->listProperty(951), []));
        // A value stored as a number is still the option the property has.
        $this->assertNull($this->returned('compareEnum', $this->listProperty(952), ['options' => [['value' => 5, 'label' => 'Five']]]));
        $this->assertNull($this->returned('compareEnum', $this->listProperty(953), ['options' => []]), 'a list with no options has nothing to compare');
    }

    public function test_sites_are_the_same_whatever_the_order_of_their_domains(): void
    {
        $this->migrate();
        $this->artisan('atrium:bitrix:migrate:sites')->assertSuccessful();
        // s1: Bitrix names www first, ours are stored in the other order.
        $this->bitrix()->table('b_lang')->where('LID', 's1')->update(['SERVER_NAME' => 'www.example.com']);
        Site::query()->where('code', 's1')->orWhere('is_default', true)->toBase()->update(['domains' => json_encode(['example.com', 'www.example.com'])]);
        // s2: Bitrix names example.com first, ours are stored the other way round.
        Site::query()->where('code', 's2')->toBase()->update(['domains' => json_encode(['www.example.com', 'example.com'])]);
        // s3 has no domains here at all.
        Site::query()->where('code', 's3')->toBase()->update(['domains' => null]);

        $sites = $this->report()['sites'];

        $this->assertSame(['s1' => 'OK', 's2' => 'OK', 's3' => 'OK'], array_column($sites, 'status', 'lid'));
    }

    public function test_the_json_report_is_indented_and_keeps_its_letters(): void
    {
        $path = $this->workDir.'/deeper/parity.json';

        $this->artisan('atrium:bitrix:parity', ['--format' => 'json', '--output' => $path, '--iblock' => ['2']])->assertFailed();

        $this->assertStringStartsWith("{\n    \"iblocks\": [\n        {\n            \"iblock\": \"demo_products\",", (string) file_get_contents($path));
        $this->assertStringContainsString("\n            \"model\": \"—\",\n", (string) file_get_contents($path));
    }

    public function test_the_summary_line_follows_the_report_after_a_blank_line(): void
    {
        $path = $this->workDir.'/summary.json';
        [$command, $output] = $this->prepared(['--format' => 'json', '--output' => $path]);

        $exit = (fn () => $this->report())->call($command);

        $this->assertSame(0, $exit);
        $this->assertSame("Report written to {$path}\n\nIBlocks: 0   Properties checked: 0   Problems: 0   Extra fields: 0\n", $output->fetch());
    }

    public function test_the_problem_count_is_green_when_there_are_none_and_red_when_there_are(): void
    {
        [$clean, $cleanOutput] = $this->prepared(decorated: true);
        [$dirty, $dirtyOutput] = $this->prepared(decorated: true);
        (fn () => $this->rows = [['iblock' => 'news', 'code' => 'X', 'type' => 'S', 'multiple' => false, 'required' => false, 'field' => null, 'status' => 'NO_COLUMN', 'detail' => '']])->call($dirty);

        $this->assertSame(0, (fn () => $this->report())->call($clean));
        $this->assertSame(1, (fn () => $this->report())->call($dirty));

        $this->assertStringContainsString("Problems: \e[32m0\e[39m", $cleanOutput->fetch());
        $this->assertStringContainsString("Problems: \e[31m1\e[39m", $dirtyOutput->fetch());
    }

    public function test_the_tables_of_a_report(): void
    {
        [$command, $output] = $this->prepared();
        (function (): void {
            $this->siteRows = [
                ['lid' => 's1', 'dir' => '/', 'domains' => 'a.test', 'site' => 'main', 'folder' => '/', 'our_domains' => 'b.test', 'status' => 'DOMAINS_MISMATCH'],
                ['lid' => 's2', 'dir' => '/en/', 'domains' => '', 'site' => null, 'folder' => null, 'our_domains' => null, 'status' => 'NO_SITE'],
                ['lid' => 's3', 'dir' => '/x/', 'domains' => 'c.test', 'site' => 'x', 'folder' => '/x/', 'our_domains' => '', 'status' => 'OK'],
            ];
            $this->iblockRows = [['iblock' => 'news', 'iblock_id' => 1, 'model' => 'News', 'table' => 'news', 'bitrix_elements' => 2, 'our_rows' => 2, 'status' => 'OK']];
        })->call($command);
        $problem = ['iblock' => 'news', 'code' => 'SOURCE', 'type' => 'S', 'multiple' => true, 'required' => false, 'field' => 'source', 'status' => 'MULTIPLE_LOST', 'detail' => 'Property is MULTIPLE in Bitrix but `text` holds one value, which is longer than sixty characters'];

        (fn () => $this->renderTable([$problem]))->call($command);

        $this->assertSame(<<<'TXT'

            Sites: Bitrix b_lang → Atrium
            +-----+------+---------+------+--------+--------------+------------------+
            | LID | DIR  | Domains | Site | Folder | Domains here | Status           |
            +-----+------+---------+------+--------+--------------+------------------+
            | s1  | /    | a.test  | main | /      | b.test       | DOMAINS_MISMATCH |
            | s2  | /en/ | —       | —    | —      | —            | NO_SITE          |
            | s3  | /x/  | c.test  | x    | /x/    | —            | OK               |
            +-----+------+---------+------+--------+--------------+------------------+

            IBlock → model mapping and data volume
            +--------+----+-------+-------+--------+------+--------+
            | IBlock | ID | Model | Table | Bitrix | Ours | Status |
            +--------+----+-------+-------+--------+------+--------+
            | news   | 1  | News  | news  | 2      | 2    | OK     |
            +--------+----+-------+-------+--------+------+--------+

            Field problems
            +--------+----------+-------------+---+---+--------+---------------+-----------------------------------------------------------------+
            | IBlock | Property | Bitrix type | M | R | Field  | Status        | Detail                                                          |
            +--------+----------+-------------+---+---+--------+---------------+-----------------------------------------------------------------+
            | news   | SOURCE   | S           | Y |   | source | MULTIPLE_LOST | Property is MULTIPLE in Bitrix but `text` holds one value, w... |
            +--------+----------+-------------+---+---+--------+---------------+-----------------------------------------------------------------+

            TXT, $output->fetch());

        [$bare, $bareOutput] = $this->prepared();
        (fn () => $this->renderTable([]))->call($bare);

        $this->assertSame(<<<'TXT'

            IBlock → model mapping and data volume
            +--------+----+-------+-------+--------+------+--------+
            | IBlock | ID | Model | Table | Bitrix | Ours | Status |
            +--------+----+-------+-------+--------+------+--------+

            TXT, $bareOutput->fetch());
    }

    private function listProperty(int $id): IBlockProperty
    {
        return IBlockProperty::fromArray(['ID' => $id, 'IBLOCK_ID' => 1, 'NAME' => 'List', 'CODE' => 'LST', 'PROPERTY_TYPE' => 'L']);
    }

    private function migrate(): void
    {
        $this->generateSchema();
        $this->artisan('atrium:bitrix:migrate:data', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function iblock(int $id, ?string $code, string $type, array $extra = []): void
    {
        $this->bitrix()->table('b_iblock')->insert([
            'ID' => $id, 'IBLOCK_TYPE_ID' => $type, 'CODE' => $code, 'NAME' => 'Iblock '.$id, 'ACTIVE' => 'Y', 'SORT' => 500 + $id, 'VERSION' => 1, ...$extra,
        ]);
    }

    /**
     * An iblock as getIBlocks() returns it, with the ID as the string a MySQL
     * driver gives.
     *
     * @return array<string,mixed>
     */
    private function iblockRow(int $id, ?string $code, string $type = 'content'): array
    {
        return ['ID' => (string) $id, 'CODE' => $code, 'IBLOCK_TYPE_ID' => $type, 'NAME' => 'Iblock '.$id];
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function property(int $id, int $iblock, ?string $code, array $extra = []): void
    {
        $this->bitrix()->table('b_iblock_property')->insert([
            'ID' => $id, 'IBLOCK_ID' => $iblock, 'NAME' => $code ?? 'Property '.$id, 'CODE' => $code, 'PROPERTY_TYPE' => 'S', 'SORT' => $id, 'MULTIPLE' => 'N', 'ACTIVE' => 'Y', ...$extra,
        ]);
    }

    /** @param  list<string>  $xmlIds */
    private function enums(int $propertyId, array $xmlIds): void
    {
        foreach ($xmlIds as $i => $xmlId) {
            $this->bitrix()->table('b_iblock_property_enum')->insert([
                'ID' => $propertyId * 100 + $i, 'PROPERTY_ID' => $propertyId, 'VALUE' => ucfirst($xmlId), 'XML_ID' => $xmlId, 'SORT' => 10 * ($i + 1), 'DEF' => 'N',
            ]);
        }
    }

    /** The form of an iblock as an editor arranged it. */
    private function form(int $iblockId, string $tabs): void
    {
        $this->bitrix()->table('b_user_option')->insert([
            'USER_ID' => null, 'CATEGORY' => 'form', 'NAME' => 'form_element_'.$iblockId, 'COMMON' => 'Y', 'VALUE' => serialize(['tabs' => $tabs]),
        ]);
    }

    /** The list settings of an iblock: column codes in order. */
    private function listSettings(string $type, int $iblockId, string $columns): void
    {
        $this->bitrix()->table('b_user_option')->insert([
            'USER_ID' => 1, 'CATEGORY' => 'list', 'NAME' => 'tbl_iblock_list_'.md5($type.'.'.$iblockId), 'COMMON' => 'N',
            'VALUE' => serialize(['columns' => $columns, 'by' => 'sort', 'order' => 'asc', 'page_size' => 20]),
        ]);
    }

    /**
     * A model whose admin fields are plain arrays.
     *
     * @param  list<array<string,mixed>>  $fields
     * @param  list<string>|null  $tabs  tab labels, when the model has tabs
     * @param  list<string>|null  $columns  list columns, when the model has a list
     */
    private function model(string $class, array $fields, ?array $tabs = null, ?array $columns = null): void
    {
        $fields = var_export($fields, true);
        $tabMethod = $tabs === null ? '' : 'public static function getAdminTabs(): array { return '.var_export(array_map(static fn (string $label): array => ['name' => 'params', 'label' => $label], $tabs), true).'; }';
        $columnMethod = $columns === null ? '' : 'public static function getAdministrationListColumns(): array { return array_map(static fn (string $name) => \\Atrium\\Admin\\Columns\\TextColumn::make($name), '.var_export($columns, true).'); }';

        file_put_contents($this->models.'/'.$class.'.php', <<<PHP
            <?php

            namespace {$this->namespace};

            class {$class} extends \\Illuminate\\Database\\Eloquent\\Model
            {
                {$tabMethod}

                {$columnMethod}

                public static function getAdministrationFields(): array
                {
                    return {$fields};
                }
            }
            PHP);
    }

    /**
     * The command with its input and output set up and the Bitrix connection
     * open, as handle() leaves it before the audit sections run.
     *
     * @param  array<string,string>  $options
     * @return array{ParityAuditCommand,BufferedOutput}
     */
    private function prepared(array $options = [], bool $decorated = false): array
    {
        $command = $this->app->make(ParityAuditCommand::class);
        $command->setLaravel($this->app);
        $command->setInput(new ArrayInput($options, $command->getDefinition()));
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $decorated);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        (function (): void {
            $this->bitrix = new BitrixConnection(null);
            $this->naming = new NamingConvention;
            $this->mapper = new FieldTypeMapper;
        })->call($command);

        return [$command, $output];
    }

    /**
     * Runs one private method and returns what it printed.
     */
    private function printed(string $method, mixed ...$args): string
    {
        [$command, $output] = $this->prepared();
        (fn () => $this->{$method}(...$args))->call($command);

        return $output->fetch();
    }

    private function returned(string $method, mixed ...$args): mixed
    {
        [$command] = $this->prepared();

        return (fn () => $this->{$method}(...$args))->call($command);
    }
}
