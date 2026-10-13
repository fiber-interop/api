# fiber-interop/api

One surface for fiber-based concurrency. Write a library once against `async\go()`, `async\await()`
and friends; it runs unchanged on phasync, Revolt (and therefore AMPHP and ReactPHP), and natively
on AMPHP and ReactPHP. PHP 8.1+.

```bash
composer require fiber-interop/api fiber-interop/revolt   # or /phasync, /amp, /react
```

```php
use function async\{go, await, await_all, sleep, readable};

$a = go(fn() => strtoupper('a'));
$b = go(function () { sleep(0.5); return 'b'; });
[$ra, $rb] = await_all([$a, $b], timeout: 2.0);

$line = fgets(readable($socket));
```

The global names (`\go()` and so on) are not taken; everything lives in `async\`.

## Functions

```php
namespace async;

function go(callable $f, mixed ...$args): object;
function await(object $awaitable, float $timeout = \PHP_FLOAT_MAX): mixed;
function await_all(array $awaitables, float $timeout = \PHP_FLOAT_MAX): array;
function await_any(array $awaitables, float $timeout = \PHP_FLOAT_MAX): mixed;
function run(callable $f, mixed ...$args): mixed;
function sleep(float $seconds): void;
function readable(mixed $resource, float $timeout = \PHP_FLOAT_MAX): mixed;
function writable(mixed $resource, float $timeout = \PHP_FLOAT_MAX): mixed;
function setup(Adapter $adapter): void;
function adapter(): Adapter;
```

- `go()` starts `$f(...$args)` concurrently and returns an **opaque awaitable**: pass it to
  `await()`, `await_all()` or `await_any()` and nothing else. Under phasync it is a `Fiber`, under
  Revolt/AMPHP/ReactPHP a future or promise; calling methods on it is not portable.
- `await()` returns the result or rethrows. It accepts the adapter's own awaitables, **any object
  with `then(callable, callable)`** (ReactPHP promises, Guzzle promises), and whatever else the
  adapter accepts. Several fibers may await the same thing at once.
- `await_all()` returns the results under the same keys. Every awaitable settles first, then the
  first exception (in key order) is rethrown.
- `await_any()` returns the first result to fulfil; it throws only if all reject (the first
  rejection's exception).
- `run($f, ...$args)` is `await(go($f, ...$args))` (no timeout; use `await(go(...), $t)`) and also works from plain `{main}` with no loop running.
- `sleep()` suspends cooperatively. `readable()`/`writable()` return the resource, so
  `fread(readable($fp), 8192)` works; several fibers may wait on the same stream.

Rules every adapter honours (each has a conformance example):

- **Timeout** throws an object implementing `async\TimeoutException` (`async\Timeout` if the loop
  has no own class). The awaited task keeps running and its later result is ignored.
- **Exceptions** thrown by a task are rethrown by `await()` unchanged. A promise rejected with a
  non-`Throwable` reason throws `async\RejectedException`; `getReason()` returns the raw reason.
- **Unawaited task exceptions** go to the loop's unhandled-error mechanism (Revolt's error handler,
  React's rejection handler, phasync's context handler). Never silently dropped.
- **Cancellation** is not in 1.0. `sleep()` and the waits may throw an object implementing
  `async\CancelledException` when the owning loop cancels the task.
- **Foreign fibers.** Waiting from a fiber the adapter did not create (and not `{main}`) does not
  suspend that fiber; the adapter runs its loop in place until the wait completes. Nested in-place
  waits across two different loops can order badly; adapters raise a clear error after the timeout
  rather than hang.

## Scope

`\Scope` (from [fiber-interop/scope](https://github.com/fiber-interop/scope), the native extension
plus a polyfill) is the identity of the operation (request, job) that code belongs to. `go()`
captures `\Scope::getCurrent()` and runs the task inside `$scope->run(...)`; adapters run timer and
IO callbacks in the scope they were queued from. The root scope is a process-wide singleton, so
"am I inside an operation" is `Scope::getCurrent() !== Scope::getRootScope()`. To start something
that outlives every request:

```php
$poller = \Scope::getRootScope()->run(fn() => async\go(function () { /* service */ }));
```

The api never names `Scope` in its own signatures.

## Choosing an adapter

Adapter packages register themselves as candidates from their Composer `files` autoload; nothing
is instantiated until the first api call:

```php
// src/register.php, listed under "autoload": {"files": [...]}
if (class_exists(React\EventLoop\Loop::class)) async\register(fn() => new FiberInterop\React\Adapter(), 'fiber-interop/react');
```

On first use an explicit `async\setup()` wins; otherwise a single candidate is built and used; two
or more throw `NotConfiguredException` naming them. So `composer require react/event-loop fiber-interop/react` needs no
code. With none installed, the first call throws `async\NotConfiguredException` listing the adapter
packages. To pick one explicitly, call `async\setup(new SomeAdapter())` early. `setup()` with an
adapter of the class already set is a no-op; one of another class throws `\LogicException`.

## Writing an adapter

Implement `async\Adapter`:

```php
interface Adapter {
    public function go(\Closure $f): object;         // schedule $f; return your awaitable
    public function canAwait(object $o): bool;       // true for your own awaitables
    public function await(object $o, float $timeout): mixed;  // also handle then-ables
    public function sleep(float $seconds): void;
    public function readable(mixed $resource, float $timeout): void;
    public function writable(mixed $resource, float $timeout): void;
}
```

- `go()` receives a closure already wrapped in the caller's scope; just schedule it.
- `await()` must accept objects with `then(callable, callable)` even when `canAwait()` says no,
  rethrow task exceptions unchanged, wrap non-`Throwable` rejections in `RejectedException`, and on
  timeout throw a `TimeoutException` and drop the late result. `$timeout === \PHP_FLOAT_MAX` means none.
- Every wait follows the in-place rule above. Queued callbacks run in the scope they were queued
  from (`$scope = \Scope::getCurrent()` at queue time, `$scope->run($cb)` when firing).
- Exceptions from tasks nobody awaits go to your loop's unhandled-error handler.
- Self-register from a `files` autoload entry, guarded by `class_exists()` on your loop.

`tests/Support/FiberAdapter.php` in this repository is a complete adapter on raw Fibers and
`stream_select` in about 250 lines; read it as the reference.

## Conformance

`examples/` holds one runnable file per function and rule; each prints deterministic output that
must match its `.expected` file under every adapter. Certify an adapter with a bootstrap that loads
the autoloader and registers it:

```php
<?php // conformance-bootstrap.php
require __DIR__ . '/vendor/autoload.php';
async\setup(new My\Adapter());
```

```bash
vendor/bin/fiber-interop-conformance conformance-bootstrap.php          # all examples
vendor/bin/fiber-interop-conformance conformance-bootstrap.php 08-await-thenable
```

Exit status 0 means identical output. The runner appends `error reported: yes|no` to each
example's output (yes when the example wrote to stderr or exited non-zero), which is how the
unhandled-exception rule is checked: only `16-unhandled-exception-reaches-handler` must say yes.
A single example runs by hand with `php -d auto_prepend_file=conformance-bootstrap.php examples/01-go-await.php`.

This package's own tests (`vendor/bin/phpunit`) run the set under the reference adapter.

## What the conformance set does not prove

- `await_all()`/`await_any()` are implemented in the api on top of `await()` and `go()`, so they
  test the adapter only indirectly.
- Queued-callback scope is observed through a task resumed by a timer and by IO; a fiber keeps its
  own scope, so an adapter that forgets to wrap its internal callbacks can still pass.
- Unhandled errors are only checked as "reported, not dropped" (stderr or exit status), not where.

## License

MIT
