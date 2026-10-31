<?php
namespace fiber;

/** Thrown by sleep() and the waits in a task that cancel() (or the owning loop) cancelled. Loops implement it on their own exception class; fiber\Cancelled is the default. */
interface CancelledException extends \Throwable {}
