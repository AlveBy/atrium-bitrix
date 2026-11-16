<?php

declare(strict_types=1);

namespace Atrium\Bitrix\Support;

/**
 * FTP client built on cURL.
 *
 * Provides methods for reading files and listing directories on an FTP server.
 */
class FtpClient
{
    protected string $host;

    protected string $user;

    protected string $password;

    protected string $root;

    /** @var array<string, ?string> File content cache */
    protected array $contentCache = [];

    /** @var array<string, array> Directory listing cache */
    protected array $listingCache = [];

    public function __construct(string $host, string $user, string $password, string $root = '/')
    {
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
        $this->root = rtrim($root, '/');
    }

    /**
     * Download a file from FTP. Returns its content or null.
     */
    public function getContents(string $remotePath): ?string
    {
        $cacheKey = $remotePath;

        if (array_key_exists($cacheKey, $this->contentCache)) {
            return $this->contentCache[$cacheKey];
        }

        $fullPath = $this->root.'/'.ltrim($remotePath, '/');
        $url = "ftp://{$this->host}".$fullPath;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => "{$this->user}:{$this->password}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $result = curl_exec($ch);
        $error = curl_errno($ch);
        curl_close($ch);

        $content = ($error !== 0 || $result === false) ? null : $result;
        $this->contentCache[$cacheKey] = $content;

        return $content;
    }

    /**
     * Get a detailed directory listing (parsed FTP LIST).
     *
     * Returns an array:
     * [
     *   ['name' => 'company', 'type' => 'dir'],
     *   ['name' => 'index.php', 'type' => 'file', 'size' => 1234],
     * ]
     *
     * @return array<int, array{name: string, type: string, size?: int}>
     */
    public function listDirectoryDetailed(string $path): array
    {
        $cacheKey = rtrim($path, '/').'/';

        if (isset($this->listingCache[$cacheKey])) {
            return $this->listingCache[$cacheKey];
        }

        $fullPath = $this->root.'/'.ltrim($path, '/');
        $url = "ftp://{$this->host}".rtrim($fullPath, '/').'/';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => "{$this->user}:{$this->password}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FTPLISTONLY => false,
            CURLOPT_CUSTOMREQUEST => 'LIST',
        ]);

        $result = curl_exec($ch);
        $error = curl_errno($ch);
        curl_close($ch);

        if ($error !== 0 || $result === false || trim($result) === '') {
            $this->listingCache[$cacheKey] = [];

            return [];
        }

        $entries = $this->parseFtpList($result);
        $this->listingCache[$cacheKey] = $entries;

        return $entries;
    }

    /**
     * Check the FTP connection.
     */
    public function testConnection(): bool
    {
        return $this->getContents('/index.php') !== null;
    }

    /**
     * Clear the cache.
     */
    public function clearCache(): void
    {
        $this->contentCache = [];
        $this->listingCache = [];
    }

    /**
     * Parse FTP LIST output.
     *
     * Unix format:
     * drwxr-xr-x  5 user group 4096 Jan 01 12:00 dirname
     * -rw-r--r--  1 user group 1234 Jan 01 12:00 filename.php
     *
     * @return array<int, array{name: string, type: string, size?: int}>
     */
    protected function parseFtpList(string $rawListing): array
    {
        $entries = [];
        $lines = explode("\n", trim($rawListing));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'total')) {
                continue;
            }

            // Unix format: drwxr-xr-x  5 user group 4096 Jan 01 12:00 name
            if (preg_match('/^([d\-l])([rwxsStT\-]{9})\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\w+\s+\d+\s+[\d:]+\s+(.+)$/', $line, $m)) {
                $name = $m[4];

                // Skip . and ..
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $type = match ($m[1]) {
                    'd' => 'dir',
                    'l' => 'link',
                    default => 'file',
                };

                $entry = [
                    'name' => $name,
                    'type' => $type,
                ];

                if ($type === 'file') {
                    $entry['size'] = (int) $m[3];
                }

                $entries[] = $entry;

                continue;
            }

            // Windows/simple format: file or directory name
            // Fallback: if nothing could be parsed, treat it as a file
            if (preg_match('/^(\S+)$/', $line, $m)) {
                $entries[] = [
                    'name' => $m[1],
                    'type' => 'file',
                ];
            }
        }

        return $entries;
    }
}
