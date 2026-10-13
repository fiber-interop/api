<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/01-go-await.php
// go() and await() return a task result
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

run(function () {
    $t = go(fn(int $a, int $b) => $a + $b, 2, 3);
    echo "result: ", await($t), "\n";
});
