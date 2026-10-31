<?php
namespace fiber;

/**
 * Thrown by a fiber\ function whose feature the installed adapter has no native way to provide,
 * such as cancel() on an adapter without Adapter::cancel(). Code that avoids the feature runs fine.
 */
class NotSupportedException extends \LogicException {}
