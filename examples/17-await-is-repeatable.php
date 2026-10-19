<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/17-await-is-repeatable.php
// an awaitable can be awaited repeatedly
// An awaitable is a value that becomes available, not a message that is consumed.
use function fiber\{go, await, await_all, await_any, run, sleep, readable, writable};

run(function () {
    run(function () {
        $t = go(function () { sleep(0.01); return 42; });
        $a = go(fn() => await($t));     // two concurrent awaiters
        $b = go(fn() => await($t));
        echo "concurrent: ", await($a), " ", await($b), "\n";
        echo "again: ", await($t), " ", await($t), "\n";

        $f = go(function () { sleep(0.01); throw new RuntimeException("once"); });
        $first = $second = null;
        try { await($f); } catch (RuntimeException $e) { $first = $e; }
        try { await($f); } catch (RuntimeException $e) { $second = $e; }
        echo "same exception object: ", ($first !== null && $first === $second) ? "yes" : "no", "\n";
    });
});
