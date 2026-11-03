<?php declare(strict_types=1);

namespace Ziler\Swoole;

use Closure;
use LogicException;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Throwable;

const REQUEST = 'ziler.request';
const RESPONSE = 'ziler.response';
const ENDED = 'ziler.ended';

/** Wraps a handler: per-coroutine request state, and a thrown error costs one request, not the worker. */
function http_handler(callable $handler): Closure
{
    return static function (Request $request, Response $response) use ($handler): void {
        $context = Coroutine::getContext();

        if ($context === null) {
            throw new LogicException('Handlers must run in a coroutine: leave enable_coroutine on.');
        }

        $context[REQUEST] = $request;
        $context[RESPONSE] = $response;
        $context[ENDED] = false;

        try {
            $handler($request, $response);
        } catch (Throwable $e) {
            error_log((string)$e);
            emit('Internal Server Error', 500);
        }
    };
}

function http(callable $handler, int $port = 9501, string $host = '0.0.0.0'): Server
{
    $server = new Server($host, $port);
    $server->on('request', http_handler($handler));

    return $server;
}

function request(): Request
{
    return current_context(REQUEST);
}

function response(): Response
{
    return current_context(RESPONSE);
}

/** Sends the response. Later calls for the same request are ignored. */
function emit(string $content, int $status = 200, array $headers = []): void
{
    if (current_context(ENDED)) {
        return;
    }

    $response = response();
    $response->status($status);

    foreach ($headers as $key => $value) {
        $response->header($key, $value);
    }

    Coroutine::getContext()[ENDED] = true;
    $response->end($content);
}

function json(mixed $data, int $status = 200, array $headers = []): void
{
    emit(
        json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $status,
        array_merge(['Content-Type' => 'application/json'], $headers)
    );
}

/** Sets CORS headers and answers OPTIONS with 204. */
function cors(
    string $origin = '*',
    string $headers = 'Content-Type, Authorization',
    string $methods = 'GET, POST, PUT, DELETE'
): void {
    $response = response();
    $response->header('Access-Control-Allow-Origin', $origin);
    $response->header('Access-Control-Allow-Headers', $headers);
    $response->header('Access-Control-Allow-Methods', $methods);

    if (request()->server['request_method'] === 'OPTIONS') {
        emit('', 204);
    }
}

/**
 * @internal
 * ponytail: child coroutines and timers fail loudly instead of inheriting the request;
 * walk Coroutine::getPcid() here if handlers start needing request() inside go().
 */
function current_context(string $key): mixed
{
    $context = Coroutine::getContext();

    if (!isset($context[$key])) {
        throw new LogicException('No HTTP request in this coroutine. Pass what you need into go() explicitly.');
    }

    return $context[$key];
}
