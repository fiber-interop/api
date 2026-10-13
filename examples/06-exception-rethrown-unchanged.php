<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/06-exception-rethrown-unchanged.php
// task exceptions are rethrown unchanged
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

run(function () {
    final class MyError extends RuntimeException {}
    $thrown = new MyError("boom", 42);
    try {
        run(function () use ($thrown) { sleep(0.01); throw $thrown; });
    } catch (Throwable $e) {
        echo get_class($e), " ", $e->getMessage(), " ", $e->getCode(), " same=", var_export($e === $thrown, true), "\n";
    }
});
