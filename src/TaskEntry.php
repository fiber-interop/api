<?php
namespace async;

/**
 * @internal A task registered in a run() frame. When it fails before anyone awaited it, the
 * exception is kept here instead of reaching the adapter (run() rethrows it, or a later await does).
 */
final class TaskEntry
{
    public bool $awaited = false;
    public ?\Throwable $error = null;
}
