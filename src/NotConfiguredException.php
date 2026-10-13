<?php
namespace async;

/** No adapter is set up. */
class NotConfiguredException extends \LogicException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'No fiber-interop adapter is configured. Install one of '
            . 'fiber-interop/phasync, fiber-interop/revolt, fiber-interop/amp, fiber-interop/react, '
            . 'or call async\setup() with your own async\Adapter.');
    }
}
