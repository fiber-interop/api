<?php
namespace async;

/** A promise was rejected with a reason that is not a Throwable; getReason() returns it unchanged. */
class RejectedException extends \RuntimeException
{
    public function __construct(private mixed $reason)
    {
        parent::__construct('Promise rejected with non-Throwable reason of type ' . get_debug_type($reason));
    }

    public function getReason(): mixed
    {
        return $this->reason;
    }
}
