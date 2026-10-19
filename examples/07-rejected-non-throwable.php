<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/07-rejected-non-throwable.php
// non-Throwable rejection becomes RejectedException
use function fiber\{go, await, await_all, await_any, run, sleep, readable, writable};

run(function () {
    $p = new class { function then(callable $ok, callable $fail) { $fail("plain string"); } };
    try {
        await($p);
    } catch (fiber\RejectedException $e) {
        echo "RejectedException reason: ", var_export($e->getReason(), true), "\n";
    }
});
