<?php
namespace async;

/**
 * @internal Minimal then-able used by await_any(); every adapter can await it because
 * await() must accept any object with then(callable, callable).
 */
final class Deferred
{
    private ?array $settled = null;
    private array $listeners = [];

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        $this->listeners[] = [$onFulfilled, $onRejected];
        if ($this->settled !== null) {
            $this->flush();
        }
    }

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
