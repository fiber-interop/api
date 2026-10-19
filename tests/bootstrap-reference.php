<?php
// Conformance bootstrap for the reference adapter (also usable with php -d auto_prepend_file=...).
require_once __DIR__ . '/../vendor/autoload.php';
if (!class_exists('Scope')) { // fiber-interop/scope not installed: use the local stand-in
    require_once __DIR__ . '/Support/ScopeStub.php';
}
require_once __DIR__ . '/Support/FiberAdapter.php';
fiber\setup(new fiber\Tests\Support\FiberAdapter());
