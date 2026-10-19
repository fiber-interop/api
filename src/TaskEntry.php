<?php
namespace fiber;

/**
 * @internal A task registered in a run() frame. When it fails before anyone awaited it, the
 * exception is kept here instead of reaching the adapter (run() rethrows it, or a later await does).
 */
final class TaskEntry
{
    public bool $awaited = false;
    public ?\Throwable $error = null;
    /** Monotonic failure order, so run() can rethrow the exception that happened first. */
    public int $failedAt = 0;
    public static int $failures = 0;
}
