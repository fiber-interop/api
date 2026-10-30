<?php
namespace fiber;

/**
 * An awaitable you settle yourself, from anywhere: resolve() or reject() it once, and every task
 * that await()s it (before or after) gets the value or the exception. It is the primitive for
 * waiting on an event rather than on a computation: a component's "stopped", a queue's "data
 * available", a program's "exited". Awaitable on every adapter, since await() takes any object
 * with then(callable, callable); the api's own await_any() and run() are built on it.
 *
 * ```php
 * $exited = new fiber\Deferred();
 * go(function () use ($exited) { sleep(1); $exited->resolve(0); });
 * $status = await($exited);                  // 0, after a second
 * ```
 */
final class Deferred
{
    private ?array $settled = null;
    private array $listeners = [];

    /** Settle with a value; every current and later awaiter receives it. Ignored once settled. */
    public function resolve(mixed $value = null): void
    {
        $this->settle(true, $value);
    }

    /** Settle with an exception; every current and later awaiter gets it thrown. Ignored once settled. */
    public function reject(\Throwable $e): void
    {
        $this->settle(false, $e);
    }

    public function isSettled(): bool
    {
        return $this->settled !== null;
    }

    /** The then-able contract await() relies on; an adapter or a promise library may call it directly. */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        $this->listeners[] = [$onFulfilled, $onRejected];
        if ($this->settled !== null) {
            $this->flush();
        }
    }

    /** @internal resolve()/reject() are the public names; kept for the api's own use */
    public function settle(bool $ok, mixed $value): void
    {
        if ($this->settled === null) {
            $this->settled = [$ok, $value];
            $this->flush();
        }
    }

    private function flush(): void
    {
        [$ok, $value] = $this->settled;
        $listeners = $this->listeners;
        $this->listeners = [];
        foreach ($listeners as $l) {
            if ($cb = $l[$ok ? 0 : 1]) {
                $cb($value);
            }
        }
    }
}
