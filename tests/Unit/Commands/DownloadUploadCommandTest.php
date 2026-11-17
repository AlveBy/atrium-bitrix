<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Commands;

use Atrium\Bitrix\Tests\Fixtures\FakeFtpServer;
use Atrium\Bitrix\Tests\SyntheticTestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fetching /upload of the old site from a fake FTP server on 127.0.0.1.
 */
final class DownloadUploadCommandTest extends SyntheticTestCase
{
    private static string $root;

    private static ?FakeFtpServer $server = null;

    /** This test's site folder on the server. */
    private string $site;

    /** Where the files go, relative to base_path() as --dest expects. */
    private string $dest;

    public static function setUpBeforeClass(): void
    {
        if (FakeFtpServer::supported()) {
            self::$root = sys_get_temp_dir().'/atrium-ftp-'.bin2hex(random_bytes(4));
            mkdir(self::$root);
            self::$server = new FakeFtpServer(self::$root);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
            (new Filesystem)->deleteDirectory(self::$root);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$server === null) {
            $this->markTestSkipped('cURL has no FTP support here.');
        }

        $this->site = 'site-'.bin2hex(random_bytes(4));
        $this->remote('index.php', '<?php');
        $this->dest = str_repeat('../', substr_count(rtrim(base_path(), '/'), '/')).ltrim($this->workDir, '/').'/files';
    }

    private function remote(string $path, string $content): void
    {
        $file = self::$root.'/'.$this->site.'/'.$path;
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $content);
    }

    private function local(string $path): string
    {
        return $this->workDir.'/files/'.$path;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function ftpOptions(array $options = []): array
    {
        return [
            '--host' => self::$server?->address,
            '--user' => 'bitrix',
            '--password' => 'secret',
            '--root' => '/'.$this->site,
            '--dest' => $this->dest,
            ...$options,
        ];
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function missingCredentials(): iterable
    {
        yield 'no host' => ['--host'];
        yield 'no user' => ['--user'];
        yield 'no password' => ['--password'];
    }

    #[DataProvider('missingCredentials')]
    public function test_without_full_credentials_nothing_is_fetched(string $missing): void
    {
        config(['atrium-bitrix.ftp' => []]);

        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions([$missing => '']))
            ->expectsOutput('FTP credentials not configured. Set BITRIX_FTP_HOST, BITRIX_FTP_USER, BITRIX_FTP_PASSWORD in .env')
            ->doesntExpectOutputToContain('Connecting to FTP')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->local(''));
    }

    public function test_wrong_credentials_stop_before_anything_is_written(): void
    {
        $this->remote('upload/notes.txt', 'text');

        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions(['--password' => 'wrong']))
            ->expectsOutput('Connecting to FTP: '.self::$server?->address.'...')
            ->expectsOutput('Could not connect to FTP. Check credentials.')
            ->doesntExpectOutput('FTP connected.')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->local(''));
    }

    public function test_a_root_without_the_site_is_a_failed_connection(): void
    {
        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions(['--root' => '/']))
            ->expectsOutput('Could not connect to FTP. Check credentials.')
            ->assertFailed();
    }

    public function test_the_whole_upload_tree_is_copied(): void
    {
        $this->remote('upload/iblock/a1b/photo.jpg', 'JPEG');
        $this->remote('upload/notes.txt', 'Привет');
        mkdir(self::$root.'/'.$this->site.'/upload/empty');
        $this->remote('upload/../outside.txt', 'not under upload');

        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions(['--dest' => $this->dest.'/']))
            ->expectsOutput('FTP connected.')
            ->expectsOutput('Destination: '.base_path($this->dest))
            ->expectsOutput('Scanning and downloading /upload...')
            ->expectsOutput('  + /upload/iblock/a1b/photo.jpg')
            ->expectsOutput('  + /upload/notes.txt')
            ->expectsOutput('Done! Downloaded: 2, Skipped: 0, Failed: 0')
            ->assertSuccessful();

        $this->assertSame('JPEG', file_get_contents($this->local('iblock/a1b/photo.jpg')));
        $this->assertSame('Привет', file_get_contents($this->local('notes.txt')));
        $this->assertDirectoryExists($this->local('empty'));
        $this->assertFileDoesNotExist($this->local('outside.txt'));
        $this->assertFileDoesNotExist($this->local('../outside.txt'));
    }

    public function test_credentials_and_root_come_from_the_config_when_not_given(): void
    {
        config(['atrium-bitrix.ftp' => [
            'host' => self::$server?->address, 'user' => 'bitrix', 'password' => 'secret', 'root' => '/'.$this->site.'/',
        ]]);
        $this->remote('upload/notes.txt', 'text');

        $this->artisan('atrium:bitrix:download:upload', ['--dest' => $this->dest])
            ->expectsOutput('Done! Downloaded: 1, Skipped: 0, Failed: 0')
            ->assertSuccessful();

        $this->assertSame('text', file_get_contents($this->local('notes.txt')));
    }

    public function test_an_unknown_root_in_the_config_falls_back_to_the_server_root(): void
    {
        config(['atrium-bitrix.ftp' => ['host' => self::$server?->address, 'user' => 'bitrix', 'password' => 'secret']]);
        file_put_contents(self::$root.'/index.php', '<?php');

        try {
            $this->artisan('atrium:bitrix:download:upload', ['--dest' => $this->dest])
                ->expectsOutput('FTP connected.')
                ->expectsOutput('Done! Downloaded: 0, Skipped: 0, Failed: 0')
                ->assertSuccessful();
        } finally {
            unlink(self::$root.'/index.php');
        }
    }

    public function test_existing_files_are_kept_when_told_to_skip_them(): void
    {
        $this->remote('upload/a.txt', 'new a');
        $this->remote('upload/b.txt', 'new b');
        mkdir($this->local(''), 0777, true);
        file_put_contents($this->local('a.txt'), 'old a');

        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions(['--skip-existing' => true]))
            ->doesntExpectOutput('  + /upload/a.txt')
            ->expectsOutput('  + /upload/b.txt')
            ->expectsOutput('Done! Downloaded: 1, Skipped: 1, Failed: 0')
            ->assertSuccessful();

        $this->assertSame('old a', file_get_contents($this->local('a.txt')));
        $this->assertSame('new b', file_get_contents($this->local('b.txt')));
    }

    public function test_existing_files_are_overwritten_by_default(): void
    {
        $this->remote('upload/a.txt', 'new a');
        mkdir($this->local(''), 0777, true);
        file_put_contents($this->local('a.txt'), 'old a');

        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions())
            ->expectsOutput('  + /upload/a.txt')
            ->expectsOutput('Done! Downloaded: 1, Skipped: 0, Failed: 0')
            ->assertSuccessful();

        $this->assertSame('new a', file_get_contents($this->local('a.txt')));
    }

    public function test_a_file_that_cannot_be_fetched_is_counted_and_links_are_left_alone(): void
    {
        $this->remote('upload/real.txt', 'real');
        $this->remote('upload/.listing', implode("\r\n", [
            '-rw-r--r--   1 ftp ftp        4 Jan 01 12:00 real.txt',
            '-rw-r--r--   1 ftp ftp        9 Jan 01 12:00 vanished.txt',
            'lrwxrwxrwx   1 ftp ftp        8 Jan 01 12:00 latest -> real.txt',
        ]));

        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions())
            ->expectsOutput('  FAIL: /upload/vanished.txt')
            ->expectsOutput('Done! Downloaded: 1, Skipped: 0, Failed: 1')
            ->assertSuccessful();

        $this->assertSame('real', file_get_contents($this->local('real.txt')));
        $this->assertFileDoesNotExist($this->local('vanished.txt'));
        $this->assertSame(['real.txt'], array_values(array_diff(scandir($this->local('')) ?: [], ['.', '..'])));
    }

    public function test_the_counts_of_one_run_do_not_carry_over_to_the_next(): void
    {
        $this->remote('upload/real.txt', 'real');
        $this->remote('upload/kept.txt', 'new');
        $this->remote('upload/.listing', implode("\r\n", [
            '-rw-r--r--   1 ftp ftp        4 Jan 01 12:00 real.txt',
            '-rw-r--r--   1 ftp ftp        3 Jan 01 12:00 kept.txt',
            '-rw-r--r--   1 ftp ftp        9 Jan 01 12:00 vanished.txt',
        ]));
        mkdir($this->local(''), 0777, true);
        file_put_contents($this->local('kept.txt'), 'old');

        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions(['--skip-existing' => true]))
            ->expectsOutput('Done! Downloaded: 1, Skipped: 1, Failed: 1')
            ->assertSuccessful();

        $this->remote('upload/.listing', '-rw-r--r--   1 ftp ftp        4 Jan 01 12:00 real.txt');

        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions())
            ->expectsOutput('Done! Downloaded: 1, Skipped: 0, Failed: 0')
            ->assertSuccessful();
    }

    public function test_an_empty_upload_directory_downloads_nothing(): void
    {
        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions())
            ->expectsOutput('Done! Downloaded: 0, Skipped: 0, Failed: 0')
            ->assertSuccessful();

        $this->assertDirectoryExists($this->local(''));
    }

    public function test_from_the_database_only_missing_files_are_fetched(): void
    {
        // b_file lists five files; one is here already, one is gone from the server.
        $this->remote('upload/iblock/a1b/laptop.jpg', 'laptop');
        $this->remote('upload/iblock/c2d/laptop-side.jpg', 'side');
        $this->remote('upload/iblock/e3f/manual.pdf', 'manual');
        $this->remote('upload/unlisted.txt', 'not in b_file');
        mkdir($this->local('medialibrary/i5j'), 0777, true);
        file_put_contents($this->local('medialibrary/i5j/logo.png'), 'local logo');
        $this->bitrix()->table('b_file')->where('ID', 1)->update(['SUBDIR' => '/iblock/a1b/']);

        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions(['--from-db' => true]))
            ->expectsOutput('Files listed in the database: 5')
            ->expectsOutput('Missing locally: 4')
            ->expectsOutput('  + iblock/a1b/laptop.jpg')
            ->expectsOutput('  + iblock/c2d/laptop-side.jpg')
            ->expectsOutput('  + iblock/e3f/manual.pdf')
            ->expectsOutput('  failed to download: iblock/g4h/hardware.png')
            ->doesntExpectOutput('Scanning and downloading /upload...')
            ->expectsOutput('Done! Downloaded: 3, Skipped: 1, Failed: 1')
            ->assertSuccessful();

        $this->assertSame('laptop', file_get_contents($this->local('iblock/a1b/laptop.jpg')));
        $this->assertSame('manual', file_get_contents($this->local('iblock/e3f/manual.pdf')));
        $this->assertSame('local logo', file_get_contents($this->local('medialibrary/i5j/logo.png')));
        $this->assertFileDoesNotExist($this->local('iblock/g4h/hardware.png'));
        $this->assertFileDoesNotExist($this->local('unlisted.txt'));
    }

    public function test_from_the_database_nothing_is_fetched_when_everything_is_in_place(): void
    {
        foreach ($this->bitrix()->table('b_file')->get() as $file) {
            @mkdir($this->local($file->SUBDIR), 0777, true);
            file_put_contents($this->local($file->SUBDIR.'/'.$file->FILE_NAME), 'here');
        }

        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions(['--from-db' => true]))
            ->expectsOutput('Everything is in place, nothing to download.')
            ->doesntExpectOutputToContain('Missing locally')
            ->expectsOutput('Done! Downloaded: 0, Skipped: 5, Failed: 0')
            ->assertSuccessful();
    }

    public function test_from_an_empty_file_table_nothing_is_fetched(): void
    {
        $this->bitrix()->table('b_file')->delete();

        $this->artisan('atrium:bitrix:download:upload', $this->ftpOptions(['--from-db' => true]))
            ->expectsOutput('Files listed in the database: 0')
            ->expectsOutput('Everything is in place, nothing to download.')
            ->expectsOutput('Done! Downloaded: 0, Skipped: 0, Failed: 0')
            ->assertSuccessful();
    }
}
