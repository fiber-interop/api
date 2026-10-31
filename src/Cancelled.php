<?php
namespace fiber;

/** The CancelledException cancel() throws in the task when no reason is given. */
class Cancelled extends \Exception implements CancelledException
{
    public function __construct(string $message = 'Cancelled')
    {
        parent::__construct($message);
    }
}
