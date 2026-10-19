<?php
namespace fiber;

/** Thrown by go(), await(), sleep() and the other waits when no fiber\run() is active. */
class NotInRunException extends \LogicException
{
    public function __construct(string $message = 'call inside fiber\run()')
    {
        parent::__construct($message);
    }
}
