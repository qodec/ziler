<?php declare(strict_types=1);

namespace Ziler\Route;

use FilesystemIterator;
use InvalidArgumentException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use function Ziler\Swoole\request;

/**
 * Dispatches the current request to a file under $basePath, e.g. users/{id}.put.php => PUT /users/5.
 * The file returns a callable, which receives the regex matches. Returns its result, null when no route matches.
 */
function files(string $basePath): mixed
{
    // Built once per worker, then read-only, so requests can share them.
    static $tables = [];
    static $handlers = [];

    $tables[$basePath] ??= table($basePath);
    $server = request()->server;

    // path_info, so routing and any auth check that reads path_info judge the same decoded string.
    foreach ($tables[$basePath][strtolower($server['request_method'])] ?? [] as [$regex, $file]) {
        if (preg_match($regex, $server['path_info'], $params)) {
            $handler = $handlers[$file] ??= require $file;

            if (!is_callable($handler)) {
                throw new LogicException("$file must return a callable");
            }

            return $handler($params);
        }
    }

    return null;
}

/**
 * @internal
 * @return array<string, list<array{0: string, 1: string}>> method => [[regex, file], ...], static paths before {params}
 */
function table(string $basePath): array
{
    $root = realpath($basePath);

    if ($root === false) {
        throw new InvalidArgumentException("$basePath does not exist");
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files); // '{' sorts after letters and digits, so static paths match first

    $table = [];

    foreach ($files as $file) {
        $tokens = explode('.', str_replace('/', '.', trim(substr($file, strlen($root)), '/')));
        array_pop($tokens); // php
        $method = array_pop($tokens);
        $table[$method][] = [regex('/' . implode('/', $tokens)), $file];
    }

    return $table;
}

/** @internal D: a trailing newline must not satisfy $ */
function regex(string $path): string
{
    $pattern = preg_replace('/\\\\\{(\w+)\\\\\}/', '(?<$1>[A-Za-z0-9_-]+)', preg_quote($path, '#'));

    return "#^{$pattern}/?$#D";
}
