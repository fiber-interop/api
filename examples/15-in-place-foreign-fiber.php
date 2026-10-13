<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/15-in-place-foreign-fiber.php
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

// a Fiber nobody's loop created: the adapter must not suspend it, it runs its loop in place
$f = new Fiber(function () {
    echo "inside: ", run(function () { sleep(0.01); return "done in place"; }), "\n";
    return "fiber returned";
});
$f->start();
echo "terminated without suspending: ", var_export($f->isTerminated(), true), " ", $f->getReturn(), "\n";
