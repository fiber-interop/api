<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/04-timeout-leaves-task-running.php
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

$t = go(function () { sleep(0.1); echo "task finished\n"; return 1; });
try {
    await($t, 0.03);
} catch (async\TimeoutException $e) {
    echo "timeout implements async\\TimeoutException\n";
}
sleep(0.15);
echo "after\n";
