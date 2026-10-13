<?php
namespace async\Tests\Support;

use async\Adapter;
use async\RejectedException;
use async\Timeout;

/**
 * Reference adapter for the conformance set: a single-threaded scheduler on raw Fibers,
 * timers and stream_select. Not shipped; proves the api needs no framework.
 */
final class FiberAdapter implements Adapter
{
    /** @var \WeakMap<\Fiber, Task> fibers this adapter created */
    private \WeakMap $own;
    /** @var list<\Closure> */
    private array $ready = [];
    /** @var array<int, array{float, \Closure}> */
    private array $timers = [];
    /** @var array<int, array{resource, \Closure}> */
    private array $readers = [];
    private array $writers = [];
    private int $nextId = 0;
    /** @var \Closure(\Throwable): void */
    public \Closure $errorHandler;

    public function __construct()
    {
        $this->own = new \WeakMap();
        $this->errorHandler = static function (\Throwable $e): void {
            fwrite(STDERR, 'Unhandled ' . get_class($e) . ': ' . $e->getMessage() . "\n");
        };
    }

    public function go(\Closure $f): object
    {
        $task = new Task();
        $fiber = new \Fiber(function () use ($f, $task) {
            $this->activate();
            try {
                $task->settle(true, $f());
            } catch (\Throwable $e) {
                $task->settle(false, $e);
                if (!$task->handled) {
                    // give awaiters queued in this tick a chance before reporting
                    $this->defer(function () use ($task, $e) { if (!$task->handled) ($this->errorHandler)($e); });
                }
            }
        });
        $this->own[$fiber] = $task;
        $this->defer(static fn() => $fiber->start());
        return $task;
    }

    /** Same as go(): wait() returns as soon as its own condition settles, so no task keeps it alive. */
    public function service(\Closure $f): object
    {
        return $this->go($f);
    }

    public function canAwait(object $o): bool
    {
        return $o instanceof Task;
    }

    public function await(object $o, float $timeout): mixed
    {
        if ($o instanceof Task) {
            $o->handled = true;
            return $this->wait(static function (\Closure $settle) use ($o) { $o->onSettle($settle); return null; }, $timeout);
        }
        return $this->wait(static function (\Closure $settle) use ($o) {
            $o->then(
                static fn($v) => $settle(true, $v),
                static fn($r) => $settle(false, $r instanceof \Throwable ? $r : new RejectedException($r)),
            );
            return null;
        }, $timeout);
    }

    public function sleep(float $seconds): void
    {
        $this->wait(function (\Closure $settle) use ($seconds) {
            $id = $this->addTimer($seconds, static fn() => $settle(true, null));
            return fn() => $this->cancelTimer($id);
        }, \PHP_FLOAT_MAX);
    }

    public function readable(mixed $resource, float $timeout): void
    {
        $this->waitStream($this->readers, $resource, $timeout);
    }

    public function writable(mixed $resource, float $timeout): void
    {
        $this->waitStream($this->writers, $resource, $timeout);
    }

    private function waitStream(array &$list, mixed $resource, float $timeout): void
    {
        $this->wait(function (\Closure $settle) use (&$list, $resource) {
            $id = $this->nextId++;
            $list[$id] = [$resource, $this->inScope(static fn() => $settle(true, null))];
            return static function () use (&$list, $id) { unset($list[$id]); };
        }, $timeout);
    }

    /**
     * Core wait: $subscribe($settle) registers interest and may return a cancel closure.
     * Own fibers suspend; {main} and foreign fibers run the loop in place (the in-place rule).
     */
    private function wait(\Closure $subscribe, float $timeout): mixed
    {
        $fiber = \Fiber::getCurrent();
        $suspend = $fiber !== null && isset($this->own[$fiber]);
        $done = false;
        $result = null;
        $cancel = $timerId = null;
        $settle = function (bool $ok, mixed $value) use (&$done, &$result, &$cancel, &$timerId, $fiber, $suspend) {
            if ($done) {
                return;
            }
            $done = true;
            $result = [$ok, $value];
            if ($cancel) $cancel();
            if ($timerId !== null) $this->cancelTimer($timerId);
            if ($suspend && $fiber->isSuspended()) $this->defer(static fn() => $fiber->resume());
        };
        $cancel = $subscribe($settle);
        if (!$done && $timeout !== \PHP_FLOAT_MAX) {
            $timerId = $this->addTimer($timeout, static fn() => $settle(false, new Timeout(sprintf('Timed out after %.3fs', $timeout))));
        }
        if (!$done) {
            if ($suspend) {
                \Fiber::suspend();
            } else {
                while (!$done) {
                    if (!$this->ready && !$this->timers && !$this->readers && !$this->writers) {
                        throw new \LogicException('Deadlock: waiting with nothing scheduled');
                    }
                    $this->tick();
                }
            }
        }
        $this->activate(); // after a resume, or back in {main} after the in-place loop
        [$ok, $value] = $result;
        if (!$ok) {
            throw $value;
        }
        return $value;
    }

    private function inScope(\Closure $cb): \Closure
    {
        $scope = \Scope::getCurrent();
        return function (...$a) use ($scope, $cb) {
            $this->activate();
            return $scope->run($cb, ...$a);
        };
    }

    /** Hand-off to user code: make the live per-scope superglobals those of the current scope. */
    private function activate(): void
    {
        if (\function_exists('fiber_interop\scope\activate')) {
            \fiber_interop\scope\activate();
        }
    }

    private function defer(\Closure $cb): void
    {
        $this->ready[] = $cb;
    }

    private function addTimer(float $seconds, \Closure $cb): int
    {
        $id = $this->nextId++;
        $this->timers[$id] = [microtime(true) + $seconds, $this->inScope($cb)];
        return $id;
    }

    private function cancelTimer(int $id): void
    {
        unset($this->timers[$id]);
    }

    private function tick(): void
    {
        $ready = $this->ready;
        $this->ready = [];
        foreach ($ready as $cb) {
            $cb();
        }
        $now = microtime(true);
        $next = null;
        uasort($this->timers, static fn($a, $b) => $a[0] <=> $b[0]);
        foreach ($this->timers as $id => [$at, $cb]) {
            if ($at <= $now) {
                unset($this->timers[$id]);
                $cb();
            } else {
                $next = $at;
                break;
            }
        }
        if ($ready || $this->ready) { // callbacks just ran may have settled what the caller waits for
            $wait = 0;
        } elseif ($next !== null) {
            $wait = max(0, $next - microtime(true));
        } elseif (!$this->readers && !$this->writers) {
            $wait = 0;
        } else {
            $wait = null;
        }
        if ($this->readers || $this->writers) {
            $r = array_column($this->readers, 0);
            $w = array_column($this->writers, 0);
            $e = [];
            $sec = $wait === null ? null : (int) $wait;
            if (@stream_select($r, $w, $e, $sec, $wait === null ? 0 : (int) (($wait - (int) $wait) * 1e6)) > 0) {
                foreach ([[&$this->readers, $r], [&$this->writers, $w]] as [&$list, $hit]) {
                    foreach ($list as $id => [$res, $cb]) {
                        if (in_array($res, $hit, true)) {
                            $cb();
                        }
                    }
                }
            }
        } elseif ($wait > 0) {
            usleep((int) ($wait * 1e6));
        }
    }
}

/** @internal awaitable returned by FiberAdapter::go() */
final class Task
{
    public bool $handled = false;
    private ?array $result = null;
    private array $listeners = [];

    public function onSettle(\Closure $cb): void
    {
        $this->result === null ? $this->listeners[] = $cb : $cb(...$this->result);
    }

    public function settle(bool $ok, mixed $value): void
    {
        $this->result = [$ok, $value];
        foreach ($this->listeners as $cb) {
            $cb($ok, $value);
        }
        $this->listeners = [];
    }
}
