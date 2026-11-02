<?php
/**
 * fiber-interop/api: one surface for fiber-based concurrency.
 *
 * Global names (\go() etc.) are deliberately not declared; everything lives in fiber\.
 */
namespace fiber;

if (\function_exists('fiber\go')) {
    return;
}

/**
 * Install the adapter that serves these functions.
 *
 * Calling it again with an adapter of the same class is a no-op (the first one stays);
 * an adapter of another class throws \LogicException naming both.
 */
function setup(Adapter $adapter): void
{
    $current = Registry::$adapter;
    if ($current === null) {
        Registry::$adapter = $adapter;
    } elseif (\get_class($current) !== \get_class($adapter)) {
        throw new \LogicException(\sprintf(
            'fiber-interop adapter %s is already set up; refusing %s. Call fiber\setup() once with the adapter you want.',
            \get_class($current), \get_class($adapter)));
    }
}

/**
 * Offer an adapter as a candidate; called by adapter packages from their Composer `files` autoload.
 * Nothing is instantiated here. On first use, an explicit setup() wins; otherwise a single
 * candidate is instantiated via $factory; two or more make adapter() throw naming the packages.
 *
 * @param callable(): Adapter $factory
 * @param string $package Composer package name, used in messages
 */
function register(callable $factory, string $package): void
{
    Registry::$candidates[$package] = $factory;
}

/** The configured adapter, resolving a single registered candidate on first use. @throws NotConfiguredException */
function adapter(): Adapter
{
    if (Registry::$adapter !== null) {
        return Registry::$adapter;
    }
    $c = Registry::$candidates;
    if (\count($c) > 1) {
        throw new NotConfiguredException('Several fiber-interop adapters are installed (' . \implode(', ', \array_keys($c))
            . '); call fiber\\setup() with the one you want.');
    }
    if ($c === []) {
        throw new NotConfiguredException();
    }
    return Registry::$adapter = \current($c)();
}

/**
 * Start $f(...$args) concurrently and return an opaque awaitable.
 *
 * The awaitable may only be passed to await(), await_all() and await_any(); its concrete type
 * depends on the adapter (a Fiber, a Future, a promise) and calling methods on it is not portable.
 * The current \Scope is captured here and the task runs inside `$scope->run(...)`, so code in the
 * task sees the scope of the code that started it.
 * An exception from a task that nobody awaits goes to the adapter's unhandled-error mechanism.
 */
function go(callable $f, mixed ...$args): object
{
    $frame = Frame::current();
    $scope = \Scope::getCurrent();
    return $frame->start(static fn() => $scope->run($f, ...$args));
}

/**
 * Wait for $awaitable and return its result.
 *
 * Accepts awaitables from go(), any object with then(callable, callable), and whatever the
 * adapter's canAwait() accepts. Several fibers may await the same awaitable concurrently.
 * An exception thrown by the task is rethrown unchanged; a non-Throwable rejection reason is
 * wrapped in RejectedException. On timeout a TimeoutException is thrown; the task keeps running
 * and its later result is ignored.
 * From a fiber the adapter did not create (and not {main}) the adapter runs its loop in place
 * instead of suspending that fiber. In a cancelled task it throws the cancellation reason.
 */
function await(object $awaitable, float $timeout = \PHP_FLOAT_MAX): mixed
{
    Frame::current();
    $self = (Registry::$current[Frame::key()] ?? null)?->entry;
    if ($self?->cancelled !== null) {
        throw $self->cancelled;
    }
    $a = adapter();
    if (!$a->canAwait($awaitable) && !\method_exists($awaitable, 'then')) {
        throw new \TypeError(\get_debug_type($awaitable) . ' is not awaitable by ' . \get_class($a));
    }
    if ($awaitable === current()) {
        throw new \LogicException('await(): a task cannot await itself');
    }
    $entry = Registry::$tasks[$awaitable] ?? null;
    if ($entry !== null) {
        $entry->awaited = true;
    }
    if ($self !== null) {
        $self->waiting = true;
    }
    try {
        $result = $a->await($awaitable, $timeout);
    } finally {
        if ($self !== null) {
            $self->waiting = false;
        }
    }
    // An eager adapter runs a task's body before go() returns, so a task awaiting its parent's
    // current() handle from inside that body finds no entry yet; it is registered by the time
    // the await returns.
    $entry ??= Registry::$tasks[$awaitable] ?? null;
    if ($entry !== null) {
        $entry->awaited = true;
        if ($entry->error !== null) {
            throw $entry->error;
        }
    }
    return $result;
}

/**
 * The handle go() (or service()) returned for the task this code runs in, so that
 * `$h === current()` tells whether $h is this task; null in {main}, outside run(), and in a
 * fiber or coroutine the adapter did not create. The handle is as opaque as go()'s: pass it to
 * the await functions and compare it, nothing else.
 *
 * The api learns the handle when the adapter's go() returns. An adapter that runs a new task
 * before its go() returns (phasync, Swoole) implements Adapter::current() so the handle is also
 * known in that first stretch, before the task's first suspension.
 */
function current(): ?object
{
    $a = Registry::$adapter;
    if ($a !== null && \method_exists($a, 'current')) {
        $c = $a->current();
        if ($c !== null) {
            return $c;
        }
    }
    return (Registry::$current[Frame::key()] ?? null)?->task;
}

/**
 * Wait for every awaitable; returns the results with the same keys.
 *
 * All awaitables are allowed to settle; then the first exception (in key order) is rethrown.
 * The timeout covers the whole call; on expiry a TimeoutException is thrown and the tasks keep running.
 * Cancelling the calling task ends the wait at once with the reason; the tasks keep running.
 */
function await_all(array $awaitables, float $timeout = \PHP_FLOAT_MAX): array
{
    Frame::current();
    $deadline = $timeout === \PHP_FLOAT_MAX ? \PHP_FLOAT_MAX : \microtime(true) + $timeout;
    $results = [];
    $error = null;
    foreach ($awaitables as $k => $o) {
        try {
            $results[$k] = await($o, $deadline === \PHP_FLOAT_MAX ? \PHP_FLOAT_MAX : \max(0.0, $deadline - \microtime(true)));
        } catch (\Throwable $e) {
            if ($e instanceof TimeoutException && \microtime(true) >= $deadline
                || $e === (Registry::$current[Frame::key()] ?? null)?->entry->cancelled) {
                throw $e;
            }
            $error ??= $e;
        }
    }
    if ($error !== null) {
        throw $error;
    }
    return $results;
}

/**
 * Return the result of the first awaitable to fulfil.
 *
 * Rejections are ignored while any awaitable is still pending; if all reject, the exception of
 * the first to reject is thrown. On timeout a TimeoutException is thrown. The others keep running.
 */
function await_any(array $awaitables, float $timeout = \PHP_FLOAT_MAX): mixed
{
    if ($awaitables === []) {
        throw new \ValueError('await_any() needs at least one awaitable');
    }
    Frame::current();
    $racers = new Frame(true); // racers do not hold up run(); the awaitables themselves do
    $d = new Deferred();
    $pending = \count($awaitables);
    $first = null;
    foreach ($awaitables as $o) {
        $racers->start(static function () use ($o, $d, &$pending, &$first) {
            try {
                $d->settle(true, await($o));
            } catch (\Throwable $e) {
                $first ??= $e;
                if (--$pending === 0) {
                    $d->settle(false, $first);
                }
            }
        });
    }
    return await($d, $timeout);
}

/**
 * The only entry point, and it is structured: runs $f as a task, then waits until every task
 * started inside it (transitively, except service() tasks) has finished. Rethrows $f's exception,
 * else the first exception of a task nobody awaited, else returns $f's result. A task that ended
 * with the exact reason it was cancelled with counts as finished normally. Nests freely and
 * behaves the same in {main} with no loop running as inside a task.
 */
function run(callable $f, mixed ...$args): mixed
{
    $frame = new Frame();
    try {
        // nested in a task: a child frame, cancelled with its parent's
        $frame->parent = Frame::current();
        $frame->parent->children[\spl_object_id($frame)] = $frame;
        $frame->cancelled = $frame->parent->cancelled;
    } catch (NotInRunException) {
    }
    $k = Frame::push($frame);
    Registry::$active[\spl_object_id($frame)] = $frame;
    $error = null;
    try {
        try {
            $result = await(go($f, ...$args));
        } catch (\Throwable $e) {
            // the body ending with its frame's own cancellation is the frame ending as asked, not a failure
            if ($e !== $frame->cancelled) {
                $error = $e;
            }
            $result = null;
        }
        while ($frame->pending > 0) {
            $frame->idle = new Deferred();
            adapter()->await($frame->idle, \PHP_FLOAT_MAX);
        }
    } finally {
        unset(Registry::$active[\spl_object_id($frame)]);
        if ($frame->parent !== null) {
            unset($frame->parent->children[\spl_object_id($frame)]);
        }
        Frame::pop($k);
    }
    if ($error !== null) {
        throw $error;
    }
    $first = null;
    foreach ($frame->entries as $entry) {
        if ($entry->error !== null && !$entry->awaited && $entry->error !== $entry->cancelled && ($first === null || $entry->failedAt < $first->failedAt)) {
            $first = $entry;
        }
    }
    if ($first !== null) {
        throw $first->error;
    }
    return $result;
}

/**
 * Starts a task that does not count towards the enclosing run() and runs in the root scope: for
 * process-wide helpers such as a curl poller. Must be called inside run(). It is driven only while
 * some run() is active; what happens to it after the outermost run() returns is up to the runtime,
 * so write services to be restartable. Its failure (and that of tasks it starts and does not
 * await) goes to the adapter's unhandled-error handler.
 *
 * @throws NotInRunException outside run()
 */
function service(callable $f, mixed ...$args): object
{
    Frame::current();
    $scope = \Scope::getRootScope();
    return (new Frame(true))->start(static fn() => $scope->run($f, ...$args), true);
}

/**
 * Cancel the task $task (a handle from go() or service()) cooperatively: its current wait (sleep,
 * await, await_all, await_any, readable, writable) throws $reason inside it within one loop
 * iteration, or, if it is not waiting right now, its next wait does. `finally` blocks run as usual.
 * The reason is sticky: every later wait in that task throws it again, so cleanup after catching
 * it must not wait. Only $task is cancelled, not the tasks it started. A finished task is left
 * alone, and so is one already cancelled (the first reason stays). await() on a cancelled task
 * rethrows what it ended with; run() counts a task that ended with exactly its reason as finished
 * normally. $reason defaults to a new fiber\Cancelled.
 *
 * @throws NotSupportedException when the adapter has no cancel()
 */
function cancel(object $task, ?CancelledException $reason = null): void
{
    $a = adapter();
    if (!\method_exists($a, 'cancel')) {
        throw new NotSupportedException(\sprintf('Adapter %s does not support cancel()', \get_class($a)));
    }
    if ($task instanceof Frame) {
        $task->cancel($reason ?? new Cancelled());
        return;
    }
    $entry = Registry::$tasks[$task] ?? null;
    if ($entry === null && $task === current()) {
        // an eager adapter's task cancelling itself before its go() has returned
        $entry = (Registry::$current[Frame::key()] ?? null)?->entry;
    }
    if ($entry === null) {
        throw new \TypeError(\get_debug_type($task) . ' is not a task started by fiber\go() or fiber\service(), nor a frame from fiber\frame()');
    }
    if ($entry->done || $entry->cancelled !== null) {
        return;
    }
    $entry->cancelled = $reason ?? new Cancelled();
    if ($entry->waiting) {
        $a->cancel($task, $entry->cancelled);
    }
}

/**
 * The frame of the calling code: the innermost run() it is inside, as an opaque handle for
 * `cancel()`. Cancelling a frame cancels every task started in that run() and in the run()s
 * nested in it (each as `cancel($task)` does), not its services, and a task started in it
 * afterwards throws the reason at its first wait; the run() then returns (or rethrows) once
 * they have unwound. The way a program ends everything it started: `cancel(frame())`.
 *
 * @throws NotInRunException outside run()
 */
function frame(): object
{
    return Frame::current();
}

/**
 * Tell $handler of each failure of a task of the current frame that nobody has awaited, as it
 * happens (in the failing task, before it ends), instead of only when run() ends. The failure
 * is still rethrown by run() (or by a later await of the task). A frame has one handler; the
 * last one set wins. A program that must not go on without one of its tasks cancels its frame
 * from here: `on_failure(fn() => cancel(frame()))`. Cancellations are not failures.
 *
 * @param \Closure(\Throwable): void $handler
 * @throws NotInRunException outside run()
 */
function on_failure(\Closure $handler): void
{
    Frame::current()->onFailure = $handler;
}

/** Suspend the current fiber for $seconds without blocking others. Throws the reason in a cancelled task. */
function sleep(float $seconds): void
{
    Frame::current();
    $self = (Registry::$current[Frame::key()] ?? null)?->entry;
    if ($self === null) {
        adapter()->sleep($seconds);
        return;
    }
    if ($self->cancelled !== null) {
        throw $self->cancelled;
    }
    $self->waiting = true;
    try {
        adapter()->sleep($seconds);
    } finally {
        $self->waiting = false;
    }
}

/**
 * Wait until $resource is readable and return it, so `fread(readable($fp), 8192)` works.
 * Several fibers may wait on the same resource at once. Throws TimeoutException on timeout, and
 * the reason in a cancelled task.
 */
function readable(mixed $resource, float $timeout = \PHP_FLOAT_MAX): mixed
{
    Frame::current();
    $self = (Registry::$current[Frame::key()] ?? null)?->entry;
    if ($self === null) {
        adapter()->readable($resource, $timeout);
        return $resource;
    }
    if ($self->cancelled !== null) {
        throw $self->cancelled;
    }
    $self->waiting = true;
    try {
        adapter()->readable($resource, $timeout);
    } finally {
        $self->waiting = false;
    }
    return $resource;
}

/** Wait until $resource is writable and return it. Same rules as readable(). */
function writable(mixed $resource, float $timeout = \PHP_FLOAT_MAX): mixed
{
    Frame::current();
    $self = (Registry::$current[Frame::key()] ?? null)?->entry;
    if ($self === null) {
        adapter()->writable($resource, $timeout);
        return $resource;
    }
    if ($self->cancelled !== null) {
        throw $self->cancelled;
    }
    $self->waiting = true;
    try {
        adapter()->writable($resource, $timeout);
    } finally {
        $self->waiting = false;
    }
    return $resource;
}
