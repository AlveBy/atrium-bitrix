<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

/*
 * A tiny FTP server for the tests: serves one directory on 127.0.0.1, one
 * connection at a time, just the commands cURL sends to fetch a file or list
 * a directory. Prints the address it listens on, then serves until killed.
 *
 *   php fake-ftp-server.php <root> <user> <password> [<command log>]
 *
 * A file called .listing in a directory is sent as that directory's LIST
 * output, so a test can feed the client any listing format it likes. With a
 * command log, every command line received is appended to that file.
 */

[, $root, $user, $password] = $argv;
$log = $argv[4] ?? null;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

if ($server === false) {
    fwrite(STDERR, $error.PHP_EOL);
    exit(1);
}

fwrite(STDOUT, stream_socket_get_name($server, false).PHP_EOL);
fflush(STDOUT);

while (true) {
    $control = @stream_socket_accept($server, -1);

    if ($control !== false) {
        serve($control, rtrim($root, '/'), $user, $password, $log);
        fclose($control);
    }
}

/**
 * @param  resource  $control
 */
function serve($control, string $root, string $user, string $password, ?string $log): void
{
    $say = static function (string $line) use ($control): void {
        fwrite($control, $line."\r\n");
    };

    $say('220 Fake FTP ready');
    $cwd = '/';
    $given = null;
    $loggedIn = false;
    /** @var resource|null $passive */
    $passive = null;

    while (($line = fgets($control)) !== false) {
        $line = rtrim($line, "\r\n");

        if ($log !== null && ! str_starts_with($line, 'PASS ')) {
            file_put_contents($log, $line.PHP_EOL, FILE_APPEND);
        }

        [$command, $argument] = array_pad(explode(' ', $line, 2), 2, '');
        $command = strtoupper($command);

        if (! $loggedIn && ! in_array($command, ['USER', 'PASS', 'QUIT'], true)) {
            $say('530 Please log in');

            continue;
        }

        $path = $argument === '' ? $cwd : resolve($cwd, $argument);
        $local = $root.$path;

        switch ($command) {
            case 'USER':
                $given = $argument;
                $say('331 Password please');
                break;
            case 'PASS':
                $loggedIn = $given === $user && $argument === $password;
                $say($loggedIn ? '230 Logged in' : '530 Login incorrect');
                break;
            case 'PWD':
                $say('257 "'.$cwd.'"');
                break;
            case 'CWD':
                if (is_dir($local)) {
                    $cwd = $path;
                    $say('250 OK');
                } else {
                    $say('550 No such directory');
                }
                break;
            case 'TYPE':
                $say('200 OK');
                break;
            case 'EPSV':
            case 'PASV':
                $passive = stream_socket_server('tcp://127.0.0.1:0');
                $port = (int) substr((string) strrchr((string) stream_socket_get_name($passive, false), ':'), 1);
                $say($command === 'EPSV'
                    ? "229 Entering Extended Passive Mode (|||{$port}|)"
                    : sprintf('227 Entering Passive Mode (127,0,0,1,%d,%d)', intdiv($port, 256), $port % 256));
                break;
            case 'SIZE':
                $say(is_file($local) ? '213 '.filesize($local) : '550 No such file');
                break;
            case 'RETR':
                if (! is_file($local) || $passive === null) {
                    $say('550 No such file');
                    break;
                }
                send($say, $passive, (string) file_get_contents($local));
                $passive = null;
                break;
            case 'LIST':
            case 'NLST':
                if (! is_dir($local) || $passive === null) {
                    $say('550 No such directory');
                    break;
                }
                send($say, $passive, listing($local));
                $passive = null;
                break;
            case 'QUIT':
                $say('221 Bye');

                return;
            default:
                $say('502 Not implemented');
        }
    }
}

/**
 * @param  resource  $passive
 */
function send(Closure $say, $passive, string $data): void
{
    $say('150 Opening data connection');
    $connection = stream_socket_accept($passive, 10);

    if ($connection !== false) {
        fwrite($connection, $data);
        fclose($connection);
    }

    fclose($passive);
    $say('226 Transfer complete');
}

function resolve(string $cwd, string $argument): string
{
    $path = str_starts_with($argument, '/') ? $argument : rtrim($cwd, '/').'/'.$argument;
    $parts = [];

    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..') {
            array_pop($parts);

            continue;
        }

        $parts[] = $part;
    }

    return '/'.implode('/', $parts);
}

function listing(string $dir): string
{
    if (is_file($dir.'/.listing')) {
        return (string) file_get_contents($dir.'/.listing');
    }

    $lines = [];

    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.listing') {
            continue;
        }

        $lines[] = is_dir($dir.'/'.$name)
            ? "drwxr-xr-x   2 ftp ftp     4096 Jan 01 12:00 {$name}"
            : sprintf('-rw-r--r--   1 ftp ftp %8d Jan 01 12:00 %s', filesize($dir.'/'.$name), $name);
    }

    return implode("\r\n", $lines)."\r\n";
}
