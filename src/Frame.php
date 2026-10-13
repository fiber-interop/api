<?php
namespace async;

/**
 * @internal One run() (or one service task's detached group): the tasks started inside it.
 * run() drains it; a detached frame belongs to a service task and is never drained.
 */
final class Frame
{
    public int $pending = 0;
    /** @var list<TaskEntry> */
    public array $entries = [];
    public ?Deferred $idle = null;

    public function __construct(public readonly bool $detached = false) {}

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
        $entry = $this->detached ? null : new TaskEntry();
        $frame = $this;
        $this->pending++;
        $a = adapter();
        $task = $a->{$service ? 'service' : 'go'}(static function () use ($f, $frame, $entry) {
            $k = Frame::push($frame);
            try {
                return $f();
            } catch (\Throwable $e) {
                if ($entry === null || $entry->awaited) {
                    throw $e;
                }
                $entry->error = $e;
                $entry->failedAt = ++TaskEntry::$failures;
                // the enclosing run() or a later await() rethrows it
                return null;
            } finally {
                Frame::pop($k);
                $frame->finished();
            }
        });
        if ($entry !== null) {
            $this->entries[] = $entry;
            Registry::$tasks ??= new \WeakMap();
            Registry::$tasks[$task] = $entry;
        }
        return $task;
    }
}
