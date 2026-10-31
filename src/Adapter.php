<?php
namespace fiber;

/**
 * What an event loop implements to serve the fiber\ functions.
 *
 * - go(): start $f concurrently and return an opaque awaitable. The api has already wrapped
 *   $f so it runs in the scope captured at go(); the adapter only schedules it.
 * - canAwait(): true for the adapter's own awaitables. Objects with then(callable, callable)
 *   must also be accepted by await() even when canAwait() returns false.
 * - await(): result of $o, or rethrow its exception unchanged; wrap non-Throwable rejection
 *   reasons in RejectedException; on timeout throw a TimeoutException (Timeout if the loop has
 *   none) and ignore the late result. $timeout is \PHP_FLOAT_MAX for "no timeout".
 * - sleep(), readable(), writable(): suspend the current fiber cooperatively.
 *
 * Every wait follows the in-place rule: if the current fiber is not one the adapter created
 * and is not {main}, never Fiber::suspend() it; run the loop in place until the wait is done.
 * Exceptions from tasks nobody awaits go to the loop's unhandled-error mechanism.
 * Callbacks the adapter queues (timers, IO) run in the \Scope they were queued from.
 *
 * Optional methods, not in the interface and detected with method_exists():
 * - current(): ?object: the handle go() or service() returned for the running unit, null for
 *   units the adapter did not create. Needed when go() runs the task before returning.
 * - cancel(object $task, CancelledException $reason): void: make the current wait of $task (a
 *   handle your go() or service() returned) throw $reason inside that task, using the loop's own
 *   cancellation primitive (cancel the timer, IO watcher or awaiter subscription and resume the
 *   task with the exception), within one loop iteration. The api calls it only while $task is
 *   suspended in one of your sleep(), await(), readable() or writable(); it records the reason
 *   itself and throws it from the next wait when the task is not waiting, so cancel() never needs
 *   to remember it. If that wait has already completed but the task has not resumed yet, letting
 *   it return normally is allowed (the api throws the reason from the next wait). Without it,
 *   fiber\cancel() throws NotSupportedException.
 */
interface Adapter
{
    public function go(\Closure $f): object;
    /** Like go(), but the task must not keep the loop alive: a loop-driving wait may return while it still runs. */
    public function service(\Closure $f): object;
    public function canAwait(object $o): bool;
    public function await(object $o, float $timeout): mixed;
    public function sleep(float $seconds): void;
    public function readable(mixed $resource, float $timeout): void;
    public function writable(mixed $resource, float $timeout): void;
}
