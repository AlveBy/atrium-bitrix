<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Commands\MigrateDataCommand;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * What the base of every command prints and asks, word for word.
 */
final class AbstractBitrixCommandTest extends SyntheticTestCase
{
    private BufferedOutput $output;

    /**
     * @param  list<string>  $answers  what the person at the keyboard types, one line each; null: nobody to ask
     */
    private function command(?array $answers = null): MigrateDataCommand
    {
        $command = $this->app->make(MigrateDataCommand::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput(['--force' => true], $command->getDefinition());
        $input->setInteractive($answers !== null);

        if ($answers !== null) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, implode("\n", $answers)."\n");
            rewind($stream);
            $input->setStream($stream);
        }

        $command->setInput($input);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle($input, $this->output));

        return $command;
    }

    private function invoke(object $command, string $method, mixed ...$args): mixed
    {
        return (fn () => $this->{$method}(...$args))->call($command);
    }

    public function test_progress_is_drawn_from_nothing_to_all_done_and_the_line_is_closed(): void
    {
        $seen = [];
        $this->invoke($this->command(), 'withProgress', ['a', 'b', 'c', 'd'], function (string $item) use (&$seen): void {
            $seen[] = $item;
        });

        $this->assertSame(['a', 'b', 'c', 'd'], $seen);
        $this->assertSame(
            " 0/4 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%\n 4/4 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%\n",
            $this->output->fetch(),
        );
    }

    public function test_the_list_of_iblocks_is_followed_by_a_blank_line_when_nobody_can_answer(): void
    {
        $ids = $this->invoke($this->command(), 'selectIBlocks');

        $this->assertSame([1, 2], array_values($ids));
        $this->assertSame(
            "Available IBlocks:\n"
            ."+----+---------------+----------+---------+\n"
            ."| ID | Code          | Name     | Type    |\n"
            ."+----+---------------+----------+---------+\n"
            ."| 1  | demo_news     | News     | content |\n"
            ."| 2  | demo_products | Products | catalog |\n"
            ."+----+---------------+----------+---------+\n"
            ."\n",
            $this->output->fetch(),
        );
    }

    public function test_the_list_of_highload_blocks_is_followed_by_a_blank_line_when_nobody_can_answer(): void
    {
        $ids = $this->invoke($this->command(), 'selectHlblocks');

        $this->assertSame([1], array_values($ids));
        $this->assertSame(
            "Available Highload Blocks:\n"
            ."+----+--------+---------------+\n"
            ."| ID | Name   | Table         |\n"
            ."+----+--------+---------------+\n"
            ."| 1  | Brands | b_hlbd_brands |\n"
            ."+----+--------+---------------+\n"
            ."\n",
            $this->output->fetch(),
        );
    }

    /**
     * @return iterable<string,array{string,list<int>}>
     */
    public static function answers(): iterable
    {
        yield 'all' => ['all', [1, 2]];
        yield 'ALL in capitals' => ['ALL', [1, 2]];
        yield 'one id' => ['2', [2]];
        yield 'a list' => ['1,2', [1, 2]];
        yield 'a list with a gap' => ['1,,2', [1, 2]];
        yield 'nothing typed' => ['', []];
    }

    /**
     * @param  list<int>  $expected
     */
    #[DataProvider('answers')]
    public function test_the_person_picks_iblocks_by_typing_ids_or_all(string $typed, array $expected): void
    {
        $command = $this->command([$typed]);

        $this->assertSame($expected, array_values($this->invoke($command, 'selectIBlocks')));
        $this->assertStringContainsString('Enter IBlock IDs to process (comma-separated, or "all" for all)', $this->output->fetch());
    }

    public function test_the_person_picks_highload_blocks_the_same_way(): void
    {
        $this->assertSame([1], array_values($this->invoke($this->command(['all']), 'selectHlblocks')));
        $this->assertSame([5, 6], array_values($this->invoke($this->command(['5,6']), 'selectHlblocks')));
        $this->assertSame([], array_values($this->invoke($this->command(['']), 'selectHlblocks')));
        $this->assertStringContainsString('Enter Highload Block IDs to process (comma-separated, or "all" for all)', $this->output->fetch());
    }

    public function test_an_unreachable_database_is_reported_with_blank_lines_around_the_directions(): void
    {
        $missing = $this->workDir.'/no-such-dir/bitrix.sqlite';
        config(['database.connections.gone' => ['driver' => 'sqlite', 'database' => $missing, 'prefix' => '']]);

        $this->assertSame(Command::FAILURE, Artisan::call('atrium:bitrix:analyze', ['--connection' => 'gone', '--no-interaction' => true]));

        $this->assertSame(
            "Bitrix Database Analyzer\n"
            ."========================\n"
            ."\n"
            ."Cannot connect to Bitrix database: Database file at path [{$missing}] does not exist. Ensure this is an absolute path to the database.\n"
            ."\n"
            ."Please check your database configuration in config/database.php\n"
            ."Make sure you have configured the \"bitrix\" connection:\n"
            ."\n"
            ."'bitrix' => [\n"
            ."    'driver' => 'mysql',\n"
            ."    'host' => env('BITRIX_DB_HOST', '127.0.0.1'),\n"
            ."    'database' => env('BITRIX_DB_DATABASE'),\n"
            ."    'username' => env('BITRIX_DB_USERNAME'),\n"
            ."    'password' => env('BITRIX_DB_PASSWORD'),\n"
            ."],\n",
            Artisan::output(),
        );
    }
}
