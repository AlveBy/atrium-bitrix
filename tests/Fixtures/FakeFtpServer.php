<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Bitrix\Tests\Fixtures;

use RuntimeException;

/**
 * Runs fake-ftp-server.php in a process of its own for one test.
 */
final class FakeFtpServer
{
    /** @var resource */
    private $process;

    /** @var array<int,resource> */
    private array $pipes;

    public readonly string $address;

    private readonly string $log;

    public function __construct(public readonly string $root, string $user = 'bitrix', string $password = 'secret')
    {
        $this->log = $root.'.commands';
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/fake-ftp-server.php', $root, $user, $password, $this->log],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('The fake FTP server did not start.');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        $address = trim((string) fgets($pipes[1]));

        if ($address === '') {
            $this->stop();

            throw new RuntimeException('The fake FTP server did not start: '.stream_get_contents($pipes[2]));
        }

        $this->address = $address;
    }

    public static function supported(): bool
    {
        return function_exists('curl_version') && in_array('ftp', curl_version()['protocols'] ?? [], true);
    }

    /**
     * The commands the server received since the last call, one per line.
     *
     * @return array<int,string>
     */
    public function commands(): array
    {
        $lines = is_file($this->log) ? (file($this->log, FILE_IGNORE_NEW_LINES) ?: []) : [];
        @unlink($this->log);

        return $lines;
    }

    public function stop(): void
    {
        @unlink($this->log);

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }
    }
}
