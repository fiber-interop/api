<?php
namespace fiber;

/** The TimeoutException used when the adapter's loop has no native timeout exception. */
class Timeout extends \RuntimeException implements TimeoutException {}
