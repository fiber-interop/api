<?php
namespace fiber;

/** @internal Holds the configured adapter and the run() frames. Tests reset it with Registry::reset(). */
final class Registry
{
    public static ?Adapter $adapter = null;
    /** @var array<string, callable(): Adapter> package name => factory */
    public static array $candidates = [];

    /**
     * Returns the object identifying the current execution unit (null means "{main}"), the same
     * key the Scope polyfill uses. Default: Fiber::getCurrent(). An adapter whose tasks are not
     * PHP Fibers (Swoole coroutines) sets this, and should give the scope package the same function.
     * @var ?\Closure(): ?object
     */
    public static ?\Closure $executionKey = null;

    /** @var ?\WeakMap<object, list<Frame>> execution unit => its stack of frames, innermost last */
    public static ?\WeakMap $frames = null;
    /** @var array<int, Frame> every frame of a run() in progress, in the order they were pushed */
    public static array $active = [];
    /** @var ?\WeakMap<object, TaskEntry> task handle => its frame bookkeeping */
    public static ?\WeakMap $tasks = null;
    /** stands in for {main} as a WeakMap key */
    public static ?object $main = null;

    public static function reset(): void
    {
        self::$adapter = null;
        self::$candidates = [];
        self::$executionKey = null;
        self::$frames = self::$tasks = null;
        self::$active = [];
    }
}
