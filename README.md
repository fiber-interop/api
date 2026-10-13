# fiber-interop/api

One surface for fiber-based concurrency. Write a library once against `async\go()`, `async\await()`
and friends; it runs unchanged on phasync, Revolt (and therefore AMPHP and ReactPHP), and natively
on AMPHP and ReactPHP. PHP 8.1+.

```bash
composer require fiber-interop/api fiber-interop/revolt   # or /phasync, /amp, /react
```

```php
use function async\{run, go, await, await_all, sleep, readable};

run(function () use ($socket) {
    $a = go(fn() => strtoupper('a'));
    $b = go(function () { sleep(0.5); return 'b'; });
    [$ra, $rb] = await_all([$a, $b], timeout: 2.0);

    $line = fgets(readable($socket));
});   // returns when everything started inside it has finished
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
function service(callable $f, mixed ...$args): object;
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
- `run($f, ...$args)` is the only entry point, and it is structured. It runs `$f` as a task and
  returns only when `$f` has returned **and every task started inside it, transitively, has
  finished**, awaited or not. It rethrows `$f`'s exception, else the first exception of a child
  nobody awaited, else returns `$f`'s result. It works the same from plain `{main}` with no loop
  running and nested inside a task; a nested `run()` waits only for what was started inside it.
  There is no timeout on `run()`; use `await(go(...), $t)` inside it.
- `service($f, ...$args)` starts a task that does **not** count towards the enclosing `run()` and
  runs in the root scope, for process-wide helpers such as a curl poller. It must be called inside
  `run()`. It is driven only while some `run()` is active; what happens to it after the outermost
  `run()` returns is runtime-specific, so write services to be restartable. Its failures, and those
  of tasks it starts without awaiting, go to the adapter's unhandled-error handler.
- `go`, `await`, `await_all`, `await_any`, `sleep`, `readable` and `writable` throw
  `async\NotInRunException` (a `LogicException`) outside `run()`.
- `sleep()` suspends cooperatively. `readable()`/`writable()` return the resource, so
  `fread(readable($fp), 8192)` works; several fibers may wait on the same stream.

Why `run()` is structured: a task only makes progress while something drives the event loop, and
`run()` is the one construct that guarantees the loop is driven for exactly as long as the code
inside it, and everything that code started, needs it. Making that the rule puts the lifetime of
concurrent work in the source code: a library can use concurrency internally, wrapped in its own
`run()`, without the application running a loop or even knowing one exists. It also removes every
`{main}` special case from adapters: there are no waits outside `run()`, nothing is left pending
when `run()` returns, and a failure nobody awaited has exactly one place to go, the `run()` that
started it.

Rules every adapter honours (each has a conformance example):

- **Timeout** throws an object implementing `async\TimeoutException` (`async\Timeout` if the loop
  has no own class). The awaited task keeps running and its later result is ignored.
- **Exceptions** thrown by a task are rethrown by `await()` unchanged. A promise rejected with a
  non-`Throwable` reason throws `async\RejectedException`; `getReason()` returns the raw reason.
- **Unawaited task exceptions** are rethrown by the enclosing `run()`. Those of `service()` tasks
  (which no `run()` owns) go to the loop's unhandled-error mechanism (Revolt's error handler,
  React's rejection handler, phasync's context handler). Never silently dropped.
- **Cancellation** is not in 1.0. `sleep()` and the waits may throw an object implementing
  `async\CancelledException` when the owning loop cancels the task.
- **Foreign fibers.** Waiting from a fiber the adapter did not create (and not `{main}`) does not
  suspend that fiber; the adapter runs its loop in place until the wait completes. Nested in-place
  waits across two different loops can order badly; adapters raise a clear error after the timeout
  rather than hang. A foreign fiber started inside a `run()` counts as inside it (see below).

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
    public function service(\Closure $f): object;    // like go(), but must not keep the loop alive
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
- `service()` tasks must not keep the loop alive on your behalf: when your loop-driving wait (the
  one wrapping the outermost `run()`) has what it waited for, return even if service tasks still
  run. What happens to them afterwards (left parked, or cancelled) is runtime-specific.
- Do not special-case `{main}`: the api only calls you inside `run()`. A wait from `{main}` happens
  only for a top-level `run()` and must drive the loop in place, exactly like a foreign fiber.
- Exceptions of api tasks inside a `run()` frame are caught by the api before they reach you when
  nobody has awaited the task yet; you see them only for awaited tasks and `service()` tasks.
- **Execution units.** The api keeps a stack of `run()` frames per execution unit, keyed by
  `Fiber::getCurrent()` (a shared object for `{main}`). If your tasks are not PHP Fibers (Swoole
  coroutines), set `\async\Registry::$executionKey = fn(): ?object => ...` (internal, but stable)
  to return the object identifying the current unit, and give the scope package the same function
  via `fiber_interop\scope\set_execution_key_provider()`.
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
- Frame membership of a fiber the adapter did not create is approximated: the api cannot observe
  `new Fiber`, so such a fiber with no frame of its own is treated as inside the innermost `run()`
  in progress anywhere in the process. With several `run()`s in flight in different tasks, its
  `go()` calls may join the wrong (but still active) `run()`. Only when no `run()` is active
  anywhere does it get `NotInRunException`. Example 15 covers the single-`run()` case only.
- `service()` lifetime after the outermost `run()` returns is runtime-specific and not tested;
  example 20 only checks that a service does not delay `run()`.

## License

MIT
