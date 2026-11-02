<?php
namespace fiber;

/**
 * @internal One run() (or one service task's detached group): the tasks started inside it.
 * run() drains it; a detached frame belongs to a service task and is never drained. Code
 * outside the api sees a frame only as the opaque handle `fiber\frame()` returns, for `cancel()`.
 */
final class Frame
{
    public int $pending = 0;
    /** @var list<TaskEntry> */
    public array $entries = [];
    public ?Deferred $idle = null;
    /** The frame of the run() this run() is nested in (null: outermost, or detached). */
    public ?Frame $parent = null;
    /** @var array<int, Frame> the nested run()s in progress, by object id */
    public array $children = [];
    /** Set by cancel(): every task of the frame got it, and a task started later gets it at its first wait. */
    public ?CancelledException $cancelled = null;
    /** @var ?\Closure(\Throwable): void told of each failure of a task nobody has awaited, as it happens (fiber\on_failure()) */
    public ?\Closure $onFailure = null;

    public function __construct(public readonly bool $detached = false) {}

    /**
     * Cancel every task of this frame and of the run()s nested in it (not services: they have no
     * frame), each with $reason as `fiber\cancel()` does; the frame stays cancelled, so a task
     * started in it afterwards throws $reason at its first wait. Nothing happens twice.
     */
    public function cancel(CancelledException $reason): void
    {
        if ($this->cancelled !== null) {
            return;
        }
        $this->cancelled = $reason;
        $a = adapter();
        foreach ($this->entries as $entry) {
            if ($entry->done || $entry->cancelled !== null) {
                continue;
            }
            $entry->cancelled = $reason;
            $task = $entry->task?->get();
            if ($entry->waiting && $task !== null) {
                $a->cancel($task, $reason);
            }
        }
        foreach ($this->children as $child) {
            $child->cancel($reason);
        }
    }

    public function finished(): void
    {
        if (--$this->pending === 0 && $this->idle !== null) {
            $idle = $this->idle;
            $this->idle = null;
            $idle->settle(true, null);
        }
    }

    /** The current execution unit as a WeakMap key. */
    public static function key(): object
    {
        $k = Registry::$executionKey !== null ? (Registry::$executionKey)() : \Fiber::getCurrent();
        return $k ?? (Registry::$main ??= new \stdClass());
    }

    /**
     * The innermost frame of the current execution unit. A fiber the loop did not create (a
     * foreign fiber) has none; it is treated as inside the innermost run() in progress anywhere.
     */
    public static function current(): self
    {
        $stack = Registry::$frames[self::key()] ?? null;
        if ($stack) {
            return \end($stack);
        }
        if (Registry::$active) {
            return \end(Registry::$active);
        }
        throw new NotInRunException();
    }

    public static function push(self $frame): object
    {
        $k = self::key();
        $frames = Registry::$frames ??= new \WeakMap();
        $stack = $frames[$k] ?? [];
        $stack[] = $frame;
        $frames[$k] = $stack;
        return $k;
    }

    public static function pop(object $k): void
    {
        $stack = Registry::$frames[$k];
        \array_pop($stack);
        if ($stack) {
            Registry::$frames[$k] = $stack;
        } else {
            unset(Registry::$frames[$k]);
        }
    }

    /** Starts $f as a task of this frame; the task's own go() calls land in this frame too. */
    public function start(\Closure $f, bool $service = false): object
    {
        $entry = new TaskEntry();
        $entry->cancelled = $this->cancelled;      // (a frame cancelled already: the task ends at its first wait)
        $frame = $this;
        $this->pending++;
        $a = adapter();
        // current() finds the task's handle through its execution unit; the handle is known only
        // once the adapter's go() has returned, which an eager adapter does after the task's first
        // suspension (Adapter::current() covers that prefix). The entry is known from the start,
        // so the waits can see a cancellation.
        $handle = new \stdClass();
        $handle->task = null;
        $handle->entry = $entry;
        $task = $a->{$service ? 'service' : 'go'}(static function () use ($f, $frame, $entry, $handle) {
            $k = Frame::push($frame);
            Registry::$current ??= new \WeakMap();
            Registry::$current[$k] = $handle;
            try {
                return $f();
            } catch (\Throwable $e) {
                // a detached task's failure goes to the adapter, unless it is its own cancellation
                if ($entry->awaited || ($frame->detached && $e !== $entry->cancelled)) {
                    throw $e;
                }
                $entry->error = $e;
                $entry->failedAt = ++TaskEntry::$failures;
                // the enclosing run() or a later await() rethrows it; the frame's listener hears of it now
                if ($frame->onFailure !== null && $e !== $entry->cancelled) {
                    ($frame->onFailure)($e);
                }
                return null;
            } finally {
                $entry->done = true;
                unset(Registry::$current[$k]);
                Frame::pop($k);
                $frame->finished();
            }
        });
        $handle->task = $task;
        $entry->task = \WeakReference::create($task);
        if (!$this->detached) {
            $this->entries[] = $entry;
        }
        Registry::$tasks ??= new \WeakMap();
        Registry::$tasks[$task] = $entry;
        return $task;
    }
}
