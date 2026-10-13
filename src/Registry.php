<?php
namespace async;

/** @internal Holds the configured adapter. Tests reset it with Registry::reset(). */
final class Registry
{
    public static ?Adapter $adapter = null;
    /** @var array<string, callable(): Adapter> package name => factory */
    public static array $candidates = [];

    public static function reset(): void
    {
        self::$adapter = null;
        self::$candidates = [];
    }
}
