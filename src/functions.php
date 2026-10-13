<?php
/**
 * fiber-interop/api: one surface for fiber-based concurrency.
 *
 * Global names (\go() etc.) are deliberately not declared; everything lives in async\.
 */
namespace async;

if (\function_exists('async\go')) {
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
            'fiber-interop adapter %s is already set up; refusing %s. Call async\setup() once with the adapter you want.',
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
            . '); call async\\setup() with the one you want.');
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
 * instead of suspending that fiber.
 */
function await(object $awaitable, float $timeout = \PHP_FLOAT_MAX): mixed
{
    Frame::current();
    $a = adapter();
    if (!$a->canAwait($awaitable) && !\method_exists($awaitable, 'then')) {
        throw new \TypeError(\get_debug_type($awaitable) . ' is not awaitable by ' . \get_class($a));
    }
    $entry = Registry::$tasks[$awaitable] ?? null;
    if ($entry === null) {
        return $a->await($awaitable, $timeout);
    }
    $entry->awaited = true;
    $result = $a->await($awaitable, $timeout);
    if ($entry->error !== null) {
        throw $entry->error;
    }
    return $result;
}

/**
 * Wait for every awaitable; returns the results with the same keys.
 *
 * All awaitables are allowed to settle; then the first exception (in key order) is rethrown.
 * The timeout covers the whole call; on expiry a TimeoutException is thrown and the tasks keep running.
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
            if ($e instanceof TimeoutException && \microtime(true) >= $deadline) {
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
 * else the first exception of a task nobody awaited, else returns $f's result. Nests freely and
 * behaves the same in {main} with no loop running as inside a task.
 */
function run(callable $f, mixed ...$args): mixed
{
    $frame = new Frame();
    $k = Frame::push($frame);
    Registry::$active[\spl_object_id($frame)] = $frame;
    $error = null;
    try {
        try {
            $result = await(go($f, ...$args));
        } catch (\Throwable $e) {
            $error = $e;
        }
        while ($frame->pending > 0) {
            $frame->idle = new Deferred();
            adapter()->await($frame->idle, \PHP_FLOAT_MAX);
        }
    } finally {
        unset(Registry::$active[\spl_object_id($frame)]);
        Frame::pop($k);
    }
    if ($error !== null) {
        throw $error;
    }
    foreach ($frame->entries as $entry) {
        if ($entry->error !== null && !$entry->awaited) {
            throw $entry->error;
        }
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

/** Suspend the current fiber for $seconds without blocking others. May throw CancelledException. */
function sleep(float $seconds): void
{
    Frame::current();
    adapter()->sleep($seconds);
}

/**
 * Wait until $resource is readable and return it, so `fread(readable($fp), 8192)` works.
 * Several fibers may wait on the same resource at once. Throws TimeoutException on timeout.
 */
function readable(mixed $resource, float $timeout = \PHP_FLOAT_MAX): mixed
{
    Frame::current();
    adapter()->readable($resource, $timeout);
    return $resource;
}

/** Wait until $resource is writable and return it. Same rules as readable(). */
function writable(mixed $resource, float $timeout = \PHP_FLOAT_MAX): mixed
{
    Frame::current();
    adapter()->writable($resource, $timeout);
    return $resource;
}
