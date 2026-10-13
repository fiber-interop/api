<?php
// Conformance bootstrap for the reference adapter (also usable with php -d auto_prepend_file=...).
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Support/ScopeStub.php';
require_once __DIR__ . '/Support/FiberAdapter.php';
async\setup(new async\Tests\Support\FiberAdapter());
