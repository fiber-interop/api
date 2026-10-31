<?php
namespace fiber;

/**
 * @internal A task started through Frame::start(). When it fails before anyone awaited it, the
 * exception is kept here instead of reaching the adapter (run() rethrows it, or a later await does).
 */
final class TaskEntry
{
    public bool $awaited = false;
    public ?\Throwable $error = null;
    /** Monotonic failure order, so run() can rethrow the exception that happened first. */
    public int $failedAt = 0;
    public static int $failures = 0;
    /** Set by cancel(); every wait of the task throws it from then on. */
    public ?CancelledException $cancelled = null;
    /** True while the task is inside an adapter wait called by a fiber\ wait function. */
    public bool $waiting = false;
    public bool $done = false;
}
