<?php
namespace async;

/** Thrown by sleep() and the waits when the owning loop cancels the task. Loops implement it on their own exception class. */
interface CancelledException extends \Throwable {}
