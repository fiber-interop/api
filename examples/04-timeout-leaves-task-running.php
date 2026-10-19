<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/04-timeout-leaves-task-running.php
// await timeout throws; the task keeps running
// Inside a task (the portable case). From {main}, whether await() can return before every task has
// ended is runtime-specific: see the adapter's README.
use function fiber\{go, await, await_all, await_any, run, sleep, readable, writable};

run(function () {
    run(function () {
        $t = go(function () { sleep(0.1); echo "task finished\n"; return 1; });
        try {
            await($t, 0.03);
        } catch (fiber\TimeoutException $e) {
            echo "timeout implements fiber\\TimeoutException\n";
        }
        sleep(0.15);
        echo "after\n";
    });
});
