<?php declare(strict_types=1);
// Run: php tests/run.php   Starts a real server with coroutine hooks on, then asserts over HTTP.

use Swoole\Coroutine\Http\Client;
use Swoole\Process;
use function Ziler\Swoole\{cors, emit, http, json, request};

require __DIR__ . '/../src/Swoole.php';
require __DIR__ . '/../src/Route.php';

const PORT = 19601;

$routes = sys_get_temp_dir() . '/ziler-routes-' . getmypid();
$say = fn(string $what) => "<?php return fn(\$p) => Ziler\\Swoole\\json(['route' => '$what', 'id' => \$p['id'] ?? null]);";

foreach ([
    'items/get.php' => $say('list'),
    'items/new.get.php' => $say('new'),
    'items/{id}.get.php' => $say('show'),
    'items/{id}.put.php' => $say('update'),
    'items/{id}/parts/post.php' => $say('part'),
    'notcallable.get.php' => '<?php return 1;',
] as $file => $code) {
    @mkdir(dirname("$routes/$file"), 0777, true);
    file_put_contents("$routes/$file", $code);
}

$server = new Process(function () use ($routes) {
    fclose(STDERR); // expected stack traces would drown the results
    $s = http(function () use ($routes) {
        cors('*');
        $path = request()->server['path_info'];
        switch ($path) {
            case '/pid': json(['pid' => getmypid()]); return;
            case '/slow':
                $before = request()->get['u'];
                usleep(200000); // yields: hooks are on
                json(['before' => $before, 'after' => request()->get['u']]);
                return;
            case '/typeerror': explode('-', request()->get['id']); return;
            case '/exit': exit(1);
            case '/twice': emit('first'); emit('second', 500); return;
            case '/secret': throw new RuntimeException('SQLSTATE password=hunter2');
            case '/child':
                $seen = '';
                go(function () use (&$seen) {
                    try { request(); $seen = 'leaked'; } catch (LogicException) { $seen = 'blocked'; }
                });
                json($seen);
                return;
        }
        Ziler\Route\files($routes);
        emit('Not found', 404);
    }, PORT, '127.0.0.1');
    $s->set(['worker_num' => 1, 'hook_flags' => SWOOLE_HOOK_ALL, 'log_level' => SWOOLE_LOG_NONE]);
    $s->start();
}, false, 0);
$server->start();
usleep(500000);

function call(string $path, string $method = 'GET'): array
{
    $c = new Client('127.0.0.1', PORT);
    $c->set(['timeout' => 3]);
    $c->setMethod($method);
    $c->execute($path);
    return [$c->statusCode, $c->body, $c->headers ?? []];
}

$failed = 0;
function check(string $name, mixed $got, mixed $want): void
{
    global $failed;
    $ok = $got === $want;
    $failed += $ok ? 0 : 1;
    echo $ok ? 'ok   ' : 'FAIL ', $name, $ok ? '' : ' got ' . var_export($got, true), "\n";
}

$route = fn(string $path, string $method = 'GET') => json_decode(call($path, $method)[1], true);

Swoole\Coroutine\run(function () use ($route) {
    $pid = json_decode(call('/pid')[1], true)['pid'];

    // Isolation: Siler hands Bob the response built from Alice's request here.
    $out = [];
    foreach (['alice', 'bob'] as $i => $u) {
        go(function () use (&$out, $u, $i) {
            usleep($i * 50000);
            $out[$u] = json_decode(call("/slow?u=$u")[1], true);
        });
    }
    usleep(800000);
    check('alice keeps her request across IO', $out['alice'] ?? null, ['before' => 'alice', 'after' => 'alice']);
    check('bob keeps his request across IO', $out['bob'] ?? null, ['before' => 'bob', 'after' => 'bob']);
    check('child coroutine cannot read a request', $route('/child'), 'blocked');

    // Errors cost one request, not the worker.
    check('TypeError gives 500', call('/typeerror?id[]=x')[0], 500);
    check('exit() gives 500', call('/exit')[0], 500);
    [$status, $body] = call('/secret');
    check('error details stay private', [$status, $body], [500, 'Internal Server Error']);
    check('worker survived all of it', json_decode(call('/pid')[1], true)['pid'], $pid);

    check('second emit ignored', array_slice(call('/twice'), 0, 2), [200, 'first']);
    [$status, , $headers] = call('/items', 'OPTIONS');
    check('OPTIONS is 204 with CORS', [$status, $headers['access-control-allow-origin'] ?? null], [204, '*']);

    // Routing
    check('list', $route('/items')['route'], 'list');
    check('trailing slash', $route('/items/')['route'], 'list');
    check('static beats param', $route('/items/new')['route'], 'new');
    check('param', $route('/items/42'), ['route' => 'show', 'id' => '42']);
    check('method selects file', $route('/items/42', 'PUT')['route'], 'update');
    check('nested param', $route('/items/42/parts', 'POST'), ['route' => 'part', 'id' => '42']);
    check('wrong method 404', call('/items/42/parts')[0], 404);
    check('unknown path 404', call('/nope')[0], 404);
    check('[A-z] junk rejected', call('/items/%5E')[0], 404);
    check('trailing newline rejected', call('/items/42%0a')[0], 404);
    check('double slash rejected', call('//items')[0], 404);
    check('traversal rejected', call('/items/../items')[0], 404);
    check('encoded path equals decoded', $route('/%69tems/42')['id'], '42');
    check('non-callable route is 500', call('/notcallable')[0], 500);
});

Process::kill($server->pid, SIGKILL);
exec('rm -rf ' . escapeshellarg($routes));
echo $failed ? "\n$failed failed\n" : "\nall passed\n";
exit($failed ? 1 : 0);
