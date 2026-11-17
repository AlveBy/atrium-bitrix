<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Bitrix\Analyzers\HighloadBlockAnalyzer;
use Atrium\Bitrix\Analyzers\IBlockAnalyzer;
use Atrium\Bitrix\Support\BitrixConnection;
use Atrium\Bitrix\Support\NamingConvention;
use Illuminate\Console\Command;

abstract class AbstractBitrixCommand extends Command
{
    protected ?BitrixConnection $bitrixConnection = null;

    protected ?IBlockAnalyzer $iblockAnalyzer = null;

    protected ?HighloadBlockAnalyzer $hlblockAnalyzer = null;

    protected ?NamingConvention $naming = null;

    /**
     * The IDs given in a repeatable option (--iblock=2 --iblock=5), as
     * integers: the command line hands them over as strings. null: none given.
     *
     * @return array<int,int>|null
     */
    protected function idsOption(string $name): ?array
    {
        return array_map('intval', $this->option($name)) ?: null;
    }

    /**
     * Get Bitrix connection
     */
    protected function getBitrixConnection(): BitrixConnection
    {
        if (! $this->bitrixConnection) {
            $connectionName = $this->option('connection') ?? config('atrium-bitrix.connection', 'bitrix');
            $this->bitrixConnection = new BitrixConnection($connectionName);
        }

        return $this->bitrixConnection;
    }

    /**
     * Get IBlock analyzer
     */
    protected function getIBlockAnalyzer(): IBlockAnalyzer
    {
        if (! $this->iblockAnalyzer) {
            $this->iblockAnalyzer = new IBlockAnalyzer(
                $this->getBitrixConnection(),
                $this->getHlblockAnalyzer()
            );
        }

        return $this->iblockAnalyzer;
    }

    /**
     * Get Highload Block analyzer
     */
    protected function getHlblockAnalyzer(): HighloadBlockAnalyzer
    {
        if (! $this->hlblockAnalyzer) {
            $this->hlblockAnalyzer = new HighloadBlockAnalyzer($this->getBitrixConnection());
        }

        return $this->hlblockAnalyzer;
    }

    /**
     * Get naming convention helper
     */
    protected function getNaming(): NamingConvention
    {
        if (! $this->naming) {
            $this->naming = new NamingConvention;
        }

        return $this->naming;
    }

    /**
     * Verify Bitrix connection
     */
    protected function verifyConnection(): bool
    {
        try {
            $connection = $this->getBitrixConnection();
            $connection->getConnection()->getPdo();

            return true;
        } catch (\Exception $e) {
            $this->error("Cannot connect to Bitrix database: {$e->getMessage()}");
            $this->newLine();
            $this->info('Please check your database configuration in config/database.php');
            $this->info('Make sure you have configured the "bitrix" connection:');
            $this->newLine();
            $this->line("'bitrix' => [");
            $this->line("    'driver' => 'mysql',");
            $this->line("    'host' => env('BITRIX_DB_HOST', '127.0.0.1'),");
            $this->line("    'database' => env('BITRIX_DB_DATABASE'),");
            $this->line("    'username' => env('BITRIX_DB_USERNAME'),");
            $this->line("    'password' => env('BITRIX_DB_PASSWORD'),");
            $this->line('],');

            return false;
        }
    }

    /**
     * Display table with data
     */
    protected function displayTable(array $headers, array $rows): void
    {
        $this->table($headers, $rows);
    }

    /**
     * Progress through items with callback
     */
    protected function withProgress(array $items, callable $callback): void
    {
        $bar = $this->output->createProgressBar(count($items));
        $bar->start();

        foreach ($items as $item) {
            $callback($item);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Ask for IBlock selection
     */
    protected function selectIBlocks(?array $iblockIds = null): array
    {
        $connection = $this->getBitrixConnection();
        $iblocks = $connection->getIBlocks();

        if (empty($iblocks)) {
            $this->warn('No IBlocks found in Bitrix database.');

            return [];
        }

        // If specific IDs provided, filter by them
        if ($iblockIds !== null) {
            $iblocks = array_filter($iblocks, fn ($ib) => in_array($ib['ID'], $iblockIds));

            return array_column($iblocks, 'ID');
        }

        // Show available IBlocks
        $this->info('Available IBlocks:');
        $rows = [];
        foreach ($iblocks as $iblock) {
            $rows[] = [
                $iblock['ID'],
                $iblock['CODE'] ?? '-',
                $iblock['NAME'],
                $iblock['IBLOCK_TYPE_ID'],
            ];
        }
        $this->displayTable(['ID', 'Code', 'Name', 'Type'], $rows);
        $this->newLine();

        // Nobody to ask (a script, CI, --no-interaction): take them all.
        if (! $this->input->isInteractive()) {
            return array_column($iblocks, 'ID');
        }

        $selection = (string) $this->ask('Enter IBlock IDs to process (comma-separated, or "all" for all)');

        if (strtolower($selection) === 'all') {
            return array_column($iblocks, 'ID');
        }

        return array_map('intval', array_filter(explode(',', $selection)));
    }

    /**
     * Ask for Highload Block selection
     */
    protected function selectHlblocks(?array $hlblockIds = null): array
    {
        $connection = $this->getBitrixConnection();
        $hlblocks = $connection->getHighloadBlocks();

        if (empty($hlblocks)) {
            $this->warn('No Highload Blocks found in Bitrix database.');

            return [];
        }

        // If specific IDs provided, filter by them
        if ($hlblockIds !== null) {
            $hlblocks = array_filter($hlblocks, fn ($hl) => in_array($hl['ID'], $hlblockIds));

            return array_column($hlblocks, 'ID');
        }

        // Show available Highload Blocks
        $this->info('Available Highload Blocks:');
        $rows = [];
        foreach ($hlblocks as $hlblock) {
            $rows[] = [
                $hlblock['ID'],
                $hlblock['NAME'],
                $hlblock['TABLE_NAME'],
            ];
        }
        $this->displayTable(['ID', 'Name', 'Table'], $rows);
        $this->newLine();

        // Nobody to ask (a script, CI, --no-interaction): take them all.
        if (! $this->input->isInteractive()) {
            return array_column($hlblocks, 'ID');
        }

        $selection = (string) $this->ask('Enter Highload Block IDs to process (comma-separated, or "all" for all)');

        if (strtolower($selection) === 'all') {
            return array_column($hlblocks, 'ID');
        }

        return array_map('intval', array_filter(explode(',', $selection)));
    }

    /**
     * Confirm action
     */
    protected function confirmAction(string $message): bool
    {
        // Not every command has --force; those without it always ask.
        if ($this->hasOption('force') && $this->input->getOption('force')) {
            return true;
        }

        return $this->confirm($message);
    }

    /**
     * Get skip list from config
     */
    protected function getSkipList(): array
    {
        return config('atrium-bitrix.skip_iblocks', []);
    }

    /**
     * Check if IBlock should be skipped
     */
    protected function shouldSkipIBlock(int $iblockId, ?string $code = null): bool
    {
        $skipList = $this->getSkipList();

        if (in_array($iblockId, $skipList)) {
            return true;
        }

        if ($code && in_array($code, $skipList)) {
            return true;
        }

        return false;
    }
}
