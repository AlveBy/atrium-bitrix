<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Unit\Support;

use Atrium\Bitrix\Support\FtpClient;
use Atrium\Bitrix\Tests\Fixtures\FakeFtpServer;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

/**
 * The FTP client against a fake server on 127.0.0.1.
 */
final class FtpClientTest extends TestCase
{
    private static string $root;

    private static ?FakeFtpServer $server = null;

    /** This test's own folder on the server, so tests do not share files. */
    private string $site;

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
        if (self::$server === null) {
            $this->markTestSkipped('cURL has no FTP support here.');
        }

        $this->site = 'site-'.bin2hex(random_bytes(4));
        $this->put('index.php', '<?php // home');
        $this->put('upload/iblock/a1b/photo.jpg', 'JPEG');
        $this->put('upload/notes.txt', 'Привет');
        mkdir(self::$root.'/'.$this->site.'/upload/empty');
    }

    private function put(string $path, string $content): void
    {
        $file = self::$root.'/'.$this->site.'/'.$path;
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $content);
    }

    private function client(?string $root = null, string $password = 'secret'): FtpClient
    {
        return new FtpClient((string) self::$server?->address, 'bitrix', $password, $root ?? '/'.$this->site.'/');
    }

    public function test_a_file_is_downloaded_relative_to_the_root(): void
    {
        $client = $this->client();

        $this->assertSame('JPEG', $client->getContents('/upload/iblock/a1b/photo.jpg'));
        $this->assertSame('Привет', $client->getContents('upload/notes.txt'));
        $this->assertTrue($client->testConnection());
    }

    public function test_a_missing_file_is_null(): void
    {
        $this->assertNull($this->client()->getContents('/upload/none.jpg'));
        $this->assertNull($this->client()->getContents('/nowhere/none.jpg'));
    }

    public function test_wrong_credentials_fail_the_connection_test(): void
    {
        $client = $this->client('/'.$this->site, 'wrong');

        $this->assertFalse($client->testConnection());
        $this->assertNull($client->getContents('/upload/notes.txt'));
    }

    public function test_a_root_without_the_site_finds_no_index(): void
    {
        $this->assertFalse($this->client('/')->testConnection());
        $this->assertSame('<?php // home', $this->client('/')->getContents('/'.$this->site.'/index.php'));
    }

    public function test_an_unreachable_server_is_a_failed_connection_not_a_crash(): void
    {
        // Port 1 on the loopback: refused at once, no network involved.
        $client = new FtpClient('127.0.0.1:1', 'bitrix', 'secret');

        $this->assertFalse($client->testConnection());
        $this->assertSame([], $client->listDirectoryDetailed('/upload'));
    }

    public function test_contents_and_failures_are_cached_until_cleared(): void
    {
        $client = $this->client();

        $this->assertSame('Привет', $client->getContents('/upload/notes.txt'));
        $this->assertNull($client->getContents('/upload/later.txt'));

        $this->put('upload/notes.txt', 'changed');
        $this->put('upload/later.txt', 'now here');

        $this->assertSame('Привет', $client->getContents('/upload/notes.txt'));
        $this->assertNull($client->getContents('/upload/later.txt'));

        $client->clearCache();

        $this->assertSame('changed', $client->getContents('/upload/notes.txt'));
        $this->assertSame('now here', $client->getContents('/upload/later.txt'));
    }

    public function test_a_directory_is_listed_with_types_and_sizes(): void
    {
        $entries = $this->client()->listDirectoryDetailed('/upload');

        usort($entries, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $this->assertSame([
            ['name' => 'empty', 'type' => 'dir'],
            ['name' => 'iblock', 'type' => 'dir'],
            ['name' => 'notes.txt', 'type' => 'file', 'size' => 12],
        ], $entries);
    }

    public function test_listings_are_cached_by_path_until_cleared(): void
    {
        $client = $this->client();

        $this->assertCount(3, $client->listDirectoryDetailed('/upload'));
        $this->put('upload/new.txt', 'x');
        $this->assertCount(3, $client->listDirectoryDetailed('/upload/'), 'a trailing slash is the same directory');

        $client->clearCache();
        $this->assertCount(4, $client->listDirectoryDetailed('upload'));
    }

    public function test_an_empty_or_missing_directory_lists_nothing(): void
    {
        $client = $this->client();

        $this->assertSame([], $client->listDirectoryDetailed('/upload/empty'));
        $this->assertSame([], $client->listDirectoryDetailed('/upload/none'));
        $this->put('upload/empty/.listing', "   \r\n");
        $client->clearCache();
        $this->assertSame([], $client->listDirectoryDetailed('/upload/empty'));
    }

    public function test_every_line_format_of_a_listing_is_understood(): void
    {
        $this->put('upload/mixed/.listing', implode("\r\n", [
            'total 24',
            'drwxr-xr-x   2 ftp ftp     4096 Jan 01 12:00 .',
            'drwxr-xr-x   2 ftp ftp     4096 Jan 01 12:00 ..',
            'drwxr-xr-x   2 ftp ftp     4096 Jan 01 12:00 photos',
            '-rw-r--r--   1 ftp ftp     1234 Mar 15  2024 price list.pdf',
            'lrwxrwxrwx   1 ftp ftp        7 Jan 01 12:00 latest -> photos',
            '-rwsr-sr-t   1 ftp ftp        0 Jan 01 12:00 sticky.sh',
            '',
            'plain-name.txt',
            '12-01-24  10:00AM       <DIR>          Windows style',
        ]));

        $this->assertSame([
            ['name' => 'photos', 'type' => 'dir'],
            ['name' => 'price list.pdf', 'type' => 'file', 'size' => 1234],
            ['name' => 'latest -> photos', 'type' => 'link'],
            ['name' => 'sticky.sh', 'type' => 'file', 'size' => 0],
            ['name' => 'plain-name.txt', 'type' => 'file'],
        ], $this->client()->listDirectoryDetailed('/upload/mixed'));
    }
}
