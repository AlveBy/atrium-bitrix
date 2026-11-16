<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Commands;

use Atrium\Bitrix\Support\FtpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DownloadUploadCommand extends Command
{
    protected $signature = 'atrium:bitrix:download:upload
                            {--dest=public/upload : Local destination directory}
                            {--host= : FTP host (default from BITRIX_FTP_HOST)}
                            {--user= : FTP user (default from BITRIX_FTP_USER)}
                            {--password= : FTP password (default from BITRIX_FTP_PASSWORD)}
                            {--root= : FTP root path (default from BITRIX_FTP_ROOT)}
                            {--skip-existing : Skip files that already exist locally}
                            {--from-db : Download from the b_file file list instead of walking the tree}';

    protected $description = 'Download all files from Bitrix /upload directory via FTP';

    protected FtpClient $ftp;

    protected string $dest;

    protected int $downloaded = 0;

    protected int $skipped = 0;

    protected int $failed = 0;

    public function handle(): int
    {
        $host = $this->option('host') ?: config('atrium-bitrix.ftp.host');
        $user = $this->option('user') ?: config('atrium-bitrix.ftp.user');
        $password = $this->option('password') ?: config('atrium-bitrix.ftp.password');
        $root = rtrim((string) ($this->option('root') ?: config('atrium-bitrix.ftp.root', '/')), '/');

        if (! $host || ! $user || ! $password) {
            $this->error('FTP credentials not configured. Set BITRIX_FTP_HOST, BITRIX_FTP_USER, BITRIX_FTP_PASSWORD in .env');

            return self::FAILURE;
        }

        $this->dest = rtrim(base_path($this->option('dest')), '/');

        $this->ftp = new FtpClient($host, $user, $password, $root);

        $this->info("Connecting to FTP: {$host}...");

        if (! $this->ftp->testConnection()) {
            $this->error('Could not connect to FTP. Check credentials.');

            return self::FAILURE;
        }

        $this->info('FTP connected.');
        $this->info("Destination: {$this->dest}");
        $this->newLine();

        File::ensureDirectoryExists($this->dest);

        if ($this->option('from-db')) {
            $this->downloadFromDatabase();
        } else {
            $this->info('Scanning and downloading /upload...');
            $this->newLine();

            $this->downloadDirectory('/upload');
        }

        $this->newLine();
        $this->info("Done! Downloaded: {$this->downloaded}, Skipped: {$this->skipped}, Failed: {$this->failed}");

        return self::SUCCESS;
    }

    /**
     * Fetch only what is missing, asking Bitrix for the file list.
     *
     * Walking the tree over FTP means one separate listing per directory
     * (tens of thousands on a large site), and a repeat download can take
     * over an hour. The very same files are listed in `b_file` (the media
     * library too: its `b_medialib_item.SOURCE_ID` points there), while
     * `resize_cache` and `tmp`, which make up most of the directories, are
     * not needed on the new site.
     */
    protected function downloadFromDatabase(): void
    {
        $files = DB::connection(config('atrium-bitrix.connection', 'bitrix'))
            ->table('b_file')
            ->select('SUBDIR', 'FILE_NAME')
            ->get();

        $this->info("Files listed in the database: {$files->count()}");

        $wanted = [];

        foreach ($files as $file) {
            $relative = trim((string) $file->SUBDIR, '/').'/'.$file->FILE_NAME;

            if (file_exists($this->dest.'/'.$relative)) {
                $this->skipped++;

                continue;
            }

            $wanted[] = $relative;
        }

        if (empty($wanted)) {
            $this->info('Everything is in place, nothing to download.');

            return;
        }

        $this->info('Missing locally: '.count($wanted));
        $this->newLine();

        foreach ($wanted as $relative) {
            $content = $this->ftp->getContents('/upload/'.$relative);

            if ($content === null) {
                $this->warn("  failed to download: {$relative}");
                $this->failed++;

                continue;
            }

            $localPath = $this->dest.'/'.$relative;
            File::ensureDirectoryExists(dirname($localPath));
            file_put_contents($localPath, $content);

            $this->line("  <info>+</info> {$relative}");
            $this->downloaded++;
        }
    }

    protected function downloadDirectory(string $remotePath): void
    {
        $entries = $this->ftp->listDirectoryDetailed($remotePath);

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $entryPath = rtrim($remotePath, '/').'/'.$name;
            $localPath = $this->dest.str_replace('/upload', '', $entryPath);

            if ($entry['type'] === 'dir') {
                File::ensureDirectoryExists($localPath);
                $this->downloadDirectory($entryPath);
            } elseif ($entry['type'] === 'file') {
                if ($this->option('skip-existing') && file_exists($localPath)) {
                    $this->skipped++;

                    continue;
                }

                $content = $this->ftp->getContents($entryPath);

                if ($content === null) {
                    $this->warn("  FAIL: {$entryPath}");
                    $this->failed++;

                    continue;
                }

                File::ensureDirectoryExists(dirname($localPath));
                file_put_contents($localPath, $content);
                $this->line("  <info>+</info> {$entryPath}");
                $this->downloaded++;
            }
        }
    }
}
