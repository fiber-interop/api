<?php
namespace fiber;

/** Thrown by await*, readable() and writable() when the timeout expires. The awaited task keeps running. */
interface TimeoutException extends \Throwable {}
