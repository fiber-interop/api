<?php
namespace async;

/** Thrown by go(), await(), sleep() and the other waits when no async\run() is active. */
class NotInRunException extends \LogicException
{
    public function __construct(string $message = 'call inside async\run()')
    {
        parent::__construct($message);
    }
}
