<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/15-in-place-foreign-fiber.php
use function async\{go, await, await_all, await_any, run, service, sleep, readable, writable};

// a Fiber nobody's loop created, started inside run(): it counts as inside that run(), and the
// adapter must not suspend it but run its loop in place
run(function () {
    $f = new Fiber(function () {
        sleep(0.01);
        echo "slept in place\n";
        echo "inside: ", run(function () { sleep(0.01); return "done in place"; }), "\n";
        return "fiber returned";
    });
    $f->start();
    echo "terminated without suspending: ", var_export($f->isTerminated(), true), " ", $f->getReturn(), "\n";
});
