# fiber-interop/api

One surface for fiber-based concurrency. Write a library once against `fiber\go()`, `fiber\await()`
and friends; it runs unchanged on phasync, Revolt (and therefore AMPHP and ReactPHP), and natively
on AMPHP and ReactPHP. PHP 8.1+.

```bash
composer require fiber-interop/api fiber-interop/revolt   # or /phasync, /amp, /react
```

```php
use function fiber\{run, go, await, await_all, sleep, readable};

run(function () use ($socket) {
    $a = go(fn() => strtoupper('a'));
    $b = go(function () { sleep(0.5); return 'b'; });
    [$ra, $rb] = await_all([$a, $b], timeout: 2.0);

    $line = fgets(readable($socket));
});   // returns when everything started inside it has finished
```

The global names (`\go()` and so on) are not taken; everything lives in `fiber\`.

## Functions

```php
namespace fiber;

function go(callable $f, mixed ...$args): object;
function await(object $awaitable, float $timeout = \PHP_FLOAT_MAX): mixed;
function await_all(array $awaitables, float $timeout = \PHP_FLOAT_MAX): array;
function await_any(array $awaitables, float $timeout = \PHP_FLOAT_MAX): mixed;
function run(callable $f, mixed ...$args): mixed;
function service(callable $f, mixed ...$args): object;
function current(): ?object;
function cancel(object $task, ?CancelledException $reason = null): void;
function frame(): object;
function on_failure(\Closure $handler): void;
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
  finished**, awaited or not. It rethrows `$f`'s exception, else the first exception (by time of failure) of a child
  nobody awaited, else returns `$f`'s result. It works the same from plain `{main}` with no loop
  running and nested inside a task; a nested `run()` waits only for what was started inside it.
  There is no timeout on `run()`; use `await(go(...), $t)` inside it.
- `service($f, ...$args)` starts a task that does **not** count towards the enclosing `run()` and
  runs in the root scope, for process-wide helpers such as a curl poller. It must be called inside
  `run()`. It is driven only while some `run()` is active; what happens to it after the outermost
  `run()` returns is runtime-specific, so write services to be restartable. Its failures, and those
  of tasks it starts without awaiting, go to the adapter's unhandled-error handler.
- `current()` is the handle `go()` or `service()` returned for the task the calling code runs in,
  so `$h === current()` answers "is `$h` me?" (a component disposing itself from one of its own
  tasks must not wait for that task). It is `null` in `{main}`, outside `run()`, and in a fiber
  the adapter did not create. `await(current())` throws a `LogicException`: a task cannot wait
  for itself.
- `cancel($task, $reason)` cancels a task cooperatively (`$task` is a handle from `go()` or
  `service()`). If the task is suspended in a wait (`sleep`, `await`, `await_all`, `await_any`,
  `readable`, `writable`), that wait throws `$reason` inside it within one loop iteration; if it is
  not waiting right now (not started yet, running, or cancelling itself with `cancel(current())`),
  its next wait throws it. `finally` blocks run as usual. `$reason` defaults to a new
  `fiber\Cancelled` (an `\Exception` implementing `fiber\CancelledException`, message
  `Cancelled`). The task's body ends with that exception unless it catches it; `await()` on the
  task rethrows it, and `run()` counts a task that ended with exactly its reason (the same object)
  as finished normally, not as an unawaited failure. A task that catches the reason and throws
  something else fails as usual.
  - **Sticky.** Once cancelled, every later wait in that task throws the same reason again, so
    cleanup after catching it must not wait. Calling `cancel()` again keeps the first reason.
  - **Not children.** Only the task itself is cancelled, not the tasks it started; cancel those
    yourself (from a `finally`, which runs on cancellation), or cancel the frame (below).
  - `cancel()` on a finished task is a no-op. An object that is neither a task handle nor a frame
    is a `TypeError`.
  - An adapter without native cancellation makes `cancel()` throw `fiber\NotSupportedException`.
- `frame()` is the frame of the calling code: the innermost `run()` it is inside, as an opaque
  handle (only `cancel()` takes it; it is the same object for every call inside that `run()`, and
  another one inside a nested `run()`). Outside `run()`: `NotInRunException`.
  `cancel(frame(), $reason)` cancels every task of that `run()` and of the `run()`s nested in it,
  each as `cancel($task)` does, with the same reason object, and keeps the frame cancelled: a task
  started in it afterwards throws the reason at its first wait. Services are not touched (they
  have no frame). The body of the `run()` is a task of the frame too, so it gets the reason at its
  next wait; a body that ends with exactly the frame's reason makes `run()` return normally
  (`null`), as a task ending with its own reason is not a failure. This is how a program ends
  everything it started from anywhere inside it. Example 25.
- `on_failure($handler)` tells `$handler(\Throwable $e)` of each failure of a task of the current
  frame that nobody has awaited, as it happens (in the failing task, before it ends), where
  `run()` alone would report it only once every task of the frame had ended. The failure is still
  rethrown by `run()` or by a later `await()` of the task; a cancellation (a task ending with its
  reason) is not a failure. One handler per frame, the last one set. A program that must not go
  on without one of its tasks (a screen whose background worker died) ends from the handler:
  `on_failure(fn() => cancel(frame()))`. On an adapter that runs a task's body eagerly (phasync),
  a task failing before its `go()` returns is one nobody could have awaited yet. Example 25.
- `fiber\NotSupportedException` (a `LogicException`) is thrown by any `fiber\` function whose
  feature the installed adapter has no native way to provide; the message names the adapter class
  and the feature (`Adapter X does not support cancel()`). Code that avoids the feature runs on that
  adapter unchanged.
- `fiber\Deferred` is the awaitable you settle yourself: `resolve($value)` or `reject($e)` once,
  from any task, and every `await()` of it, earlier or later, returns or throws. It is how code
  waits for an *event* (a stop request, data arriving, a process exiting) rather than for a task's
  result; `await_any()` and `run()` are built on it. It is a then-able, so it is awaitable under
  every adapter.
- `go`, `await`, `await_all`, `await_any`, `sleep`, `readable` and `writable` throw
  `fiber\NotInRunException` (a `LogicException`) outside `run()`.
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

- **Timeout** throws an object implementing `fiber\TimeoutException` (`fiber\Timeout` if the loop
  has no own class). The awaited task keeps running and its later result is ignored.
  A timeout is a deadline on waiting, not on execution: a task that never finishes holds its
  `run()` open until something calls `cancel()` on it. A timeout and a cancellation are different
  exceptions: a timeout never throws a `CancelledException`, and a cancellation never a
  `TimeoutException`.
- **Readiness** means the next operation will not block, as for `select()`: EOF, a peer close and
  an error all make `readable()` and `writable()` return (the following read or write returns
  `''`/`false` without blocking). A local `fclose()` of a resource while fibers wait on it also
  unblocks them, within 20 ms, with the wait returning normally; the next operation on the closed
  resource then errors without blocking. Adapters implement this by sweeping: while any stream wait
  is pending they check `is_resource()` on each waited stream at every loop iteration and from a
  10 ms periodic timer, wake the waiters of a gone resource and drop the watcher, so a closed
  resource is never handed to `stream_select()` or the loop. Stream filters cannot be used on
  waited streams (a filtered stream cannot be cast for `select()`).
- **Exceptions** thrown by a task are rethrown by `await()` unchanged. A promise rejected with a
  non-`Throwable` reason throws `fiber\RejectedException`; `getReason()` returns the raw reason.
- **Unawaited task exceptions** are rethrown by the enclosing `run()`. Those of `service()` tasks
  (which no `run()` owns) go to the loop's unhandled-error mechanism (Revolt's error handler,
  React's rejection handler, phasync's context handler). Never silently dropped.
- **Cancellation** (optional; without it `cancel()` throws `NotSupportedException`). Add
  `public function cancel(object $task, \fiber\CancelledException $reason): void` to the adapter
  (not in the interface, detected by `method_exists`). It must make the wait `$task` (a handle your
  `go()` or `service()` returned) is suspended in throw `$reason`, the same object, inside that
  task within one loop iteration, using the loop's own cancellation primitive: cancel the timer, IO
  watcher or awaiter subscription and resume the task with the exception. The api calls it only
  while the task is suspended in one of your `sleep()`, `await()`, `readable()` or `writable()`;
  it marks a task as waiting around every call it makes to those, records the reason itself and
  throws it from the task's next wait when the task is not waiting, so the adapter never has to
  remember a cancellation. If the wait has already completed but the task has not resumed yet, it
  may return normally; the api throws the reason from the next wait. `sleep()` and the waits may
  also throw an object implementing `fiber\CancelledException` when the loop itself cancels the
  task. Example 24 checks it; example 25 checks frame cancellation, which the api builds on it
  (one `cancel()` per waiting task of the frame).
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
$poller = \Scope::getRootScope()->run(fn() => fiber\go(function () { /* service */ }));
```

The api never names `Scope` in its own signatures, and gives no scope functions of its own: `Scope`
is the global `\Scope`, not something in `fiber\`.

**Superglobals.** `$_GET`, `$_POST`, `$_COOKIE` and `$_FILES` are per scope: a new scope starts with
copies of its creator's, and assignments inside a scope stay inside it. Adapters keep this true by
calling `fiber_interop\scope\activate()` whenever the loop hands control to user code (see
[Per-scope superglobals](https://github.com/fiber-interop/scope#per-scope-superglobals-polyfill)).

## Choosing an adapter

Adapter packages register themselves as candidates from their Composer `files` autoload; nothing
is instantiated until the first api call:

```php
// src/register.php, listed under "autoload": {"files": [...]}
if (class_exists(React\EventLoop\Loop::class)) fiber\register(fn() => new FiberInterop\React\Adapter(), 'fiber-interop/react');
```

On first use an explicit `fiber\setup()` wins; otherwise a single candidate is built and used; two
or more throw `NotConfiguredException` naming them. So `composer require react/event-loop fiber-interop/react` needs no
code. With none installed, the first call throws `fiber\NotConfiguredException` listing the adapter
packages. To pick one explicitly, call `fiber\setup(new SomeAdapter())` early. `setup()` with an
adapter of the class already set is a no-op; one of another class throws `\LogicException`.

## Writing an adapter

Implement `fiber\Adapter`:

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
- Call `\fiber_interop\scope\activate()` (guard with `function_exists`) at every hand-off to user
  code: task start, after a suspension resumes, before a queued callback, and after the outermost
  wait returns to `{main}`. It is cheap when the scope has not changed and a no-op under the native
  extension. Example 21 checks it.
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
  coroutines), set `\fiber\Registry::$executionKey = fn(): ?object => ...` (internal, but stable)
  to return the object identifying the current unit, and give the scope package the same function
  via `fiber_interop\scope\set_execution_key_provider()`.
- **`current()`.** The api finds the running task's handle itself, from the execution unit, once
  your `go()` has returned. If your `go()` runs the new task before returning (phasync and Swoole
  do; Revolt and React queue it), the handle is unknown during the task's first stretch, until its
  first suspension. Cover it by adding `public function current(): ?object` to your adapter (not in
  the interface, detected by `method_exists`): return the handle your `go()` returned for the
  running unit, `null` for units you did not create. The reference adapter does this in four lines.
- **`cancel()`.** Optional, also detected by `method_exists`; see the cancellation rule above. The
  reference adapter keeps the suspended wait's settle callback on its task handle and calls it with
  the reason.
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
fiber\setup(new My\Adapter());
```

```bash
vendor/bin/fiber-interop-conformance conformance-bootstrap.php          # all examples
vendor/bin/fiber-interop-conformance conformance-bootstrap.php 08-await-thenable
```

Exit status 0 means identical output. The runner appends `error reported: yes|no` to each
example's output (yes when the example wrote to stderr or exited non-zero), which is how the
unhandled-exception rule is checked: only `16-unhandled-exception-reaches-handler` must say yes.
A single example runs by hand with `php -d auto_prepend_file=conformance-bootstrap.php examples/01-go-await.php`.

This package's own tests (`vendor/bin/phpunit`) run the set under the reference adapter, plus the stress tier (`--group stress` or `--exclude-group stress` to select). Adapter packages run the stress tier with `vendor/bin/fiber-interop-stress <bootstrap.php>`; a failure prints the seed, replay it with `FIBER_INTEROP_STRESS_SEED`.

## What the conformance set does not prove

- It tests deterministic interleavings only. Races, contention and randomised timing are covered
  by the separate stress tier (`tests/Stress`, run against an adapter with
  `bin/fiber-interop-stress <bootstrap.php>`), which checks invariants rather than fixed output.
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
