<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Commands\VerifyDataCommand;
use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Tests\Fixtures\SyntheticBitrix;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * verify: counts, link tables, sampled records and files, compared between
 * Bitrix and what migrate:data wrote.
 *
 * Every test runs the command once: its counters outlive a run (a known bug).
 */
final class VerifyDataCommandTest extends SyntheticTestCase
{
    private const COUNT_HEADERS = ['#', 'Entity', 'Bitrix', 'Laravel', 'Match', 'Diff'];

    private function migrate(): void
    {
        $this->generateSchema();
        $this->artisan('atrium:bitrix:migrate:data', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    public function test_skipped_and_missing_blocks_are_not_checked(): void
    {
        config(['atrium-bitrix.skip_iblocks' => [1]]);

        $this->artisan('atrium:bitrix:verify', ['--iblock' => [1, 99], '--hlblock' => [9], '--check-files' => true])
            ->expectsOutput('=== Counts ===')
            ->expectsOutput('=== Record spot check ===')
            ->expectsOutput('=== File check ===')
            ->expectsOutput('  Total file references: 0, missing: 0')
            ->expectsOutput('Checks: 0 | OK: 0 | Failed: 0')
            ->doesntExpectOutputToContain('| Entity')
            ->doesntExpectOutput('=== Pivot tables ===')
            ->doesntExpectOutputToContain('checking')
            ->assertSuccessful();
    }

    public function test_tables_that_were_never_created_fail_the_check(): void
    {
        $this->artisan('atrium:bitrix:verify', ['--iblock' => [2], '--hlblock' => [1], '--check-files' => true])
            ->expectsTable(self::COUNT_HEADERS, [
                [1, 'brands', '?', 'TABLE NOT FOUND', 'ERROR', '-'],
                [1, 'products', '?', 'TABLE NOT FOUND', 'ERROR', '-'],
            ])
            ->doesntExpectOutputToContain('checking')
            ->doesntExpectOutputToContain('  products:')
            ->expectsOutput('Checks: 2 | OK: 0 | Failed: 2')
            ->assertFailed();
    }

    public function test_a_clean_migration_passes_every_check(): void
    {
        $this->migrate();
        SyntheticBitrix::writeUploads($this->workDir.'/public');

        $this->artisan('atrium:bitrix:verify', ['--iblock' => [2, 1], '--hlblock' => [1], '--sample' => 2, '--check-files' => true])
            ->expectsTable(self::COUNT_HEADERS, [
                [1, 'brands', 2, 2, 'OK', 0],
                [1, 'products', 3, 3, 'OK', 0],
                [2, 'product_sections (sect.)', 3, 3, 'OK', 0],
                [3, 'news', 2, 2, 'OK', 0],
            ])
            ->expectsTable(['#', 'Table', 'Bitrix', 'Laravel', 'Match'], [
                [1, 'products_related', 2, 2, 'OK'],
                [2, 'news_link_goods', 2, 2, 'OK'],
            ])
            ->expectsOutput('  Products (products): checking 2 records...')
            ->expectsOutputToContain('    All 2 records match')
            ->expectsOutput('  News (news): checking 2 records...')
            ->expectsOutput('  products: all files present')
            ->expectsOutput('  news: all files present')
            ->expectsOutput('  Total file references: 2, missing: 0')
            ->expectsOutput('Checks: 12 | OK: 12 | Failed: 0')
            ->doesntExpectOutputToContain('ID 1: OK')
            ->assertSuccessful();
    }

    public function test_every_difference_is_found_and_detailed_when_verbose(): void
    {
        $this->migrate();
        $this->tamper();

        $this->artisan('atrium:bitrix:verify', ['--iblock' => [2, 1], '--hlblock' => [1], '-v' => true])
            ->expectsTable(self::COUNT_HEADERS, [
                [1, 'brands', 2, 2, 'OK', 0],
                [1, 'products', 3, 2, 'MISMATCH', 1],
                [2, 'product_sections (sect.)', 3, 3, 'OK', 0],
                [3, 'news', 2, 2, 'OK', 0],
            ])
            ->expectsTable(['#', 'Table', 'Bitrix', 'Laravel', 'Match'], [
                [1, 'products_related', '?', 'NOT FOUND', 'ERROR'],
                [2, 'news_link_goods', 2, 1, 'MISMATCH (2 vs 1)'],
            ])
            ->expectsOutput('  Products (products): checking 3 records...')
            ->expectsOutput('    ID 1: 7 mismatches')
            ->expectsOutput('      name: Bitrix="Laptop Pro" vs Laravel="Changed"')
            ->expectsOutput('      code: Bitrix="laptop-pro" vs Laravel="changed"')
            ->expectsOutput('      sort: Bitrix="100" vs Laravel="7"')
            ->expectsOutput('      active: Bitrix="Y" vs Laravel="N"')
            ->expectsOutput('      section_id: Bitrix="2" vs Laravel="3"')
            ->expectsOutput('      preview_text: Bitrix="Powerful, light." vs Laravel="'.str_repeat('x', 50).'..."')
            ->expectsOutput('      detail_text: Bitrix="present (23 chars)" vs Laravel="empty"')
            ->expectsOutput('    ID 2: not found in Laravel (bitrix_id=2)')
            ->expectsOutput('    ID 3: OK')
            ->expectsOutput('    2 of 3 with mismatches')
            ->expectsOutput('  News (news): checking 2 records...')
            // It counts the sample asked for, not the records checked.
            ->expectsOutput('    All 5 records match')
            ->expectsOutput('Checks: 11 | OK: 6 | Failed: 5')
            ->assertFailed();
    }

    public function test_without_verbose_only_the_number_of_differences_is_shown(): void
    {
        $this->migrate();
        $this->tamper();

        $this->artisan('atrium:bitrix:verify', ['--iblock' => [2], '--hlblock' => [9]])
            ->expectsOutput('    ID 1: 7 mismatches')
            ->expectsOutput('    2 of 3 with mismatches')
            ->doesntExpectOutputToContain('Bitrix="Laptop Pro"')
            ->doesntExpectOutputToContain('not found in Laravel')
            ->doesntExpectOutputToContain('ID 3: OK')
            ->assertFailed();
    }

    public function test_a_lost_detail_text_and_texts_with_spaces(): void
    {
        $this->migrate();
        DB::table('products')->where('id', 2)->update(['preview_text' => "  Even lighter.\n", 'detail_text' => '']);
        DB::table('products')->where('id', 3)->update(['detail_text' => 'Added later']);
        DB::table('products')->where('id', 1)->update(['detail_text' => 'Rewritten, same presence', 'section_id' => null]);

        $this->artisan('atrium:bitrix:verify', ['--iblock' => [2], '--hlblock' => [9], '-v' => true])
            ->expectsOutput('    ID 1: OK')
            ->expectsOutput('    ID 2: 1 mismatches')
            ->expectsOutput('      detail_text: Bitrix="present (11 chars)" vs Laravel="empty"')
            ->expectsOutput('    ID 3: 1 mismatches')
            ->expectsOutput('      detail_text: Bitrix="empty" vs Laravel="present (11 chars)"')
            ->doesntExpectOutputToContain('preview_text:')
            ->doesntExpectOutputToContain('section_id:')
            ->assertFailed();
    }

    public function test_an_element_taken_out_of_its_section_in_bitrix(): void
    {
        $this->migrate();
        $this->bitrix()->table('b_iblock_element')->where('ID', 1)->update(['IBLOCK_SECTION_ID' => null]);

        $this->artisan('atrium:bitrix:verify', ['--iblock' => [2], '--hlblock' => [9], '-v' => true])
            ->expectsOutput('    ID 1: 1 mismatches')
            ->expectsOutput('      section_id: Bitrix="null" vs Laravel="2"')
            ->expectsOutput('    ID 2: OK')
            ->assertFailed();
    }

    public function test_an_iblock_emptied_on_both_sides_has_nothing_to_sample(): void
    {
        $this->migrate();
        $this->bitrix()->table('b_iblock_element')->where('IBLOCK_ID', 1)->delete();
        $this->bitrix()->table('b_iblock_element_property')->whereIn('IBLOCK_ELEMENT_ID', [4, 5])->delete();
        DB::table('news_link_goods')->delete();
        DB::table('news')->delete();

        $this->artisan('atrium:bitrix:verify', ['--iblock' => [1], '--hlblock' => [9]])
            ->expectsTable(self::COUNT_HEADERS, [[1, 'news', 0, 0, 'OK', 0]])
            ->expectsOutput('=== Record spot check ===')
            ->doesntExpectOutputToContain('checking')
            ->expectsOutput('Checks: 2 | OK: 2 | Failed: 0')
            ->assertSuccessful();
    }

    public function test_missing_files_are_counted_per_table(): void
    {
        $this->migrate();
        Schema::table('news', function (Blueprint $t): void {
            $t->dropColumn(['preview_picture', 'detail_picture']);
        });

        $this->artisan('atrium:bitrix:verify', ['--iblock' => [2, 1], '--hlblock' => [9], '--sample' => 0, '--check-files' => true])
            ->doesntExpectOutput('=== Record spot check ===')
            ->expectsOutput('  products: 2 files not found')
            ->doesntExpectOutputToContain('  news:')
            ->expectsOutput('  Total file references: 2, missing: 2')
            ->assertFailed();
    }

    public function test_an_element_gone_from_bitrix_during_the_check_is_a_failure(): void
    {
        $this->migrate();
        $command = $this->command(OutputInterface::VERBOSITY_VERBOSE);
        $this->set($command, 'bitrixConnection', new class('bitrix') extends BitrixConnection
        {
            public function getIBlockElementById(int $elementId): ?array
            {
                return $elementId === 1 ? null : parent::getIBlockElementById($elementId);
            }
        });

        $this->invoke($command, 'spotCheckIBlocks', [2], 5);

        $output = $this->output->fetch();
        $this->assertStringContainsString('    ID 1: not found in Bitrix', $output);
        $this->assertStringContainsString('    1 of 3 with mismatches', $output);
        $this->assertSame([3, 2, 1], [$this->read($command, 'totalChecks'), $this->read($command, 'passedChecks'), $this->read($command, 'failedChecks')]);
    }

    public function test_an_element_gone_from_bitrix_is_not_detailed_without_verbose(): void
    {
        $this->migrate();
        $command = $this->command(OutputInterface::VERBOSITY_NORMAL);
        $this->set($command, 'bitrixConnection', new class('bitrix') extends BitrixConnection
        {
            public function getIBlockElementById(int $elementId): ?array
            {
                return null;
            }
        });

        $this->invoke($command, 'spotCheckIBlocks', [2], 5);

        $output = $this->output->fetch();
        $this->assertStringNotContainsString('not found in Bitrix', $output);
        $this->assertStringContainsString('    3 of 3 with mismatches', $output);
        $this->assertSame(3, $this->read($command, 'failedChecks'));
    }

    public function test_counts_that_are_not_numbers_are_errors(): void
    {
        $command = $this->command(OutputInterface::VERBOSITY_NORMAL);

        $this->assertSame([1, 'x', '?', 3, 'ERROR', '-'], $this->invoke($command, 'makeCountRow', 1, 'x', '?', 3));
        $this->assertSame([2, 'y', 3, 3, 'ERROR', '-'], $this->invoke($command, 'makeCountRow', 2, 'y', 3, 3, false), 'forced');
        $this->assertSame([3, 'z', 3, 3, 'OK', 0], $this->invoke($command, 'makeCountRow', 3, 'z', 3, 3, true));
        $this->assertSame([4, 'p', 2, 'n/a', 'ERROR'], $this->invoke($command, 'makePivotRow', 4, 'p', 2, 'n/a'));
        $this->assertSame([4, 1, 3], [$this->read($command, 'totalChecks'), $this->read($command, 'passedChecks'), $this->read($command, 'failedChecks')]);
    }

    public function test_the_sample_keeps_the_first_and_last_ids(): void
    {
        $command = $this->command(OutputInterface::VERBOSITY_NORMAL);
        $ids = range(1, 10);

        $this->assertSame([1, 2, 3], $this->invoke($command, 'pickSampleIds', [1, 2, 3], 3), 'no more than asked for');
        $this->assertSame([1, 3], $this->invoke($command, 'pickSampleIds', [1, 2, 3], 2));
        $this->assertSame([1, 2], $this->invoke($command, 'pickSampleIds', [1, 2], 1), 'the first and last even for a sample of one');

        $three = $this->invoke($command, 'pickSampleIds', [1, 2, 3, 4, 5], 3);
        $this->assertCount(3, $three);
        $this->assertSame([1, 5], array_slice($three, 0, 2));
        $this->assertContains($three[2], [2, 3, 4]);

        $four = $this->invoke($command, 'pickSampleIds', $ids, 4);
        $this->assertCount(4, $four);
        $this->assertSame([1, 10], array_slice($four, 0, 2));
        $this->assertSame($four, array_unique($four));
        $this->assertSame([], array_diff($four, range(1, 10)));
        $this->assertSame([], array_intersect(array_slice($four, 2), [1, 10]), 'the middle comes from the middle');
    }

    private function tamper(): void
    {
        DB::table('products')->where('id', 1)->update([
            'name' => 'Changed', 'code' => 'changed', 'sort' => 7, 'active' => false, 'section_id' => 3,
            'preview_text' => str_repeat('x', 60), 'detail_text' => '',
        ]);
        DB::table('news_link_goods')->where('link_good_id', 2)->delete();
        Schema::drop('products_related');
        DB::table('products')->where('id', 2)->delete();
    }

    private BufferedOutput $output;

    private function command(int $verbosity): VerifyDataCommand
    {
        $command = $this->app->make(VerifyDataCommand::class);
        $command->setLaravel($this->app);
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $this->output = new BufferedOutput($verbosity);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));

        return $command;
    }

    private function invoke(object $command, string $method, mixed ...$args): mixed
    {
        return (fn () => $this->{$method}(...$args))->call($command);
    }

    private function set(object $command, string $property, mixed $value): void
    {
        (fn () => $this->{$property} = $value)->call($command);
    }

    private function read(object $command, string $property): mixed
    {
        return (fn () => $this->{$property})->call($command);
    }
}
