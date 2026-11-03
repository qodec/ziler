# Ziler

The parts of [Siler](https://github.com/leocavalcante/siler) that a Swoole app uses, rewritten to be safe in a
long-running worker. Siler is archived and its last release is v1.7.9.

## Install

Add the repo to your app's `composer.json`, then run `composer update qodec/ziler`:

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/qodec/ziler" }],
    "require": { "qodec/ziler": "^0.1" }
}
```

## Use

```php
use Ziler\Swoole;
use Ziler\Route;

Swoole\http(function () {
    Swoole\cors('*');
    Route\files('api');
    Swoole\emit('Not found', 404); // ignored if a route already responded
})->start();
```

```php
// api/users/{id}.get.php  =>  GET /users/5
return fn(array $params) => Swoole\json(['id' => $params['id'], 'q' => Swoole\request()->get['q'] ?? null]);
```

API: `http`, `http_handler`, `request`, `response`, `emit`, `json`, `cors`, `Route\files`.

## Migrating from Siler

1. Replace `leocavalcante/siler` with `qodec/ziler` in `composer.json`, as above.
2. Change the imports. Call sites such as `Swoole\request()` stay as they are.

   ```bash
   grep -rlE '^\s*use Siler' . --include='*.php' --exclude-dir=vendor | xargs perl -pi -e \
     's/use Siler\\(Swoole|Route);/use Ziler\\$1;/; $_="" if /^\s*use Siler\\(Container|Http\\Request);/'
   ```

   That also deletes the `Container` and `Http\Request` imports, so first check that nothing calls them.

## What changes from Siler

| Siler | Here |
| --- | --- |
| Request and response live in one per-worker global, so concurrent requests can swap them | Stored per coroutine |
| A thrown `Error` or `exit` kills the worker and every request on it | Logged, client gets a generic 500, worker lives |
| Rescans the route directory on every request | Scanned once per worker |
| Routes on raw `request_uri` while apps often authorize on decoded `path_info` | Routes on `path_info` |
| `{id}` accepts `` ^ [ ] \ ` `` because of `[A-z]`; `$` accepts a trailing newline | `[A-Za-z0-9_-]+`, anchored with `D` |
| `$name`, `@name`, `{name:regex}`, `index` file names | Only `{name}`; add the others when a route needs them |
| `request()` in a child coroutine reads whichever request ran last | Throws `LogicException`; pass what you need into `go()` |

## Coroutine hooks

This library is safe with `hook_flags` on. **Your app may not be.** Static state such as a current-user class, a global
Eloquent Capsule, or one shared Redis connection will still leak or collide between requests once they can yield. Move
those to per-coroutine state and connection pools before you enable hooks.

## Test

```bash
php tests/run.php
```

Needs PHP 8.0+ and ext-swoole. It starts a real server on port 19601.
