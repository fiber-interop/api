<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/05-late-result-ignored.php
// a result arriving after a timeout is ignored
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

run(function () {
    $t = go(function () { sleep(0.05); return "late"; });
    try { await($t, 0.01); } catch (async\TimeoutException) { echo "timed out\n"; }
    sleep(0.1); // the late result must not surface here or anywhere
    echo "still fine\n";
    echo "awaited again: ", await($t), "\n";
});
