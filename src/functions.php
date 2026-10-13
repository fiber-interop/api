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

/** The configured adapter. @throws NotConfiguredException when none is set up. */
function adapter(): Adapter
{
    return Registry::$adapter ?? throw new NotConfiguredException();
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
    $scope = \Scope::getCurrent();
    return adapter()->go(static fn() => $scope->run($f, ...$args));
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
    $a = adapter();
    if (!$a->canAwait($awaitable) && !\method_exists($awaitable, 'then')) {
        throw new \TypeError(\get_debug_type($awaitable) . ' is not awaitable by ' . \get_class($a));
    }
    return $a->await($awaitable, $timeout);
}

/**
 * Wait for every awaitable; returns the results with the same keys.
 *
 * All awaitables are allowed to settle; then the first exception (in key order) is rethrown.
 * The timeout covers the whole call; on expiry a TimeoutException is thrown and the tasks keep running.
 */
function await_all(array $awaitables, float $timeout = \PHP_FLOAT_MAX): array
{
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
    $d = new Deferred();
    $pending = \count($awaitables);
    $first = null;
    foreach ($awaitables as $o) {
        go(static function () use ($o, $d, &$pending, &$first) {
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

/** await(go($f), $timeout). Valid from {main} with no loop running: the adapter runs its loop until done. */
function run(callable $f, float $timeout = \PHP_FLOAT_MAX): mixed
{
    return await(go($f), $timeout);
}

/** Suspend the current fiber for $seconds without blocking others. May throw CancelledException. */
function sleep(float $seconds): void
{
    adapter()->sleep($seconds);
}

/**
 * Wait until $resource is readable and return it, so `fread(readable($fp), 8192)` works.
 * Several fibers may wait on the same resource at once. Throws TimeoutException on timeout.
 */
function readable(mixed $resource, float $timeout = \PHP_FLOAT_MAX): mixed
{
    adapter()->readable($resource, $timeout);
    return $resource;
}

/** Wait until $resource is writable and return it. Same rules as readable(). */
function writable(mixed $resource, float $timeout = \PHP_FLOAT_MAX): mixed
{
    adapter()->writable($resource, $timeout);
    return $resource;
}
