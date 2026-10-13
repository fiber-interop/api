<?php
namespace async;

/** @internal Holds the configured adapter. Tests reset it with `Registry::$adapter = null`. */
final class Registry
{
    public static ?Adapter $adapter = null;
}
