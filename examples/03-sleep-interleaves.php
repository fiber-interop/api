<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/03-sleep-interleaves.php
// sleep() interleaves tasks by duration
use function fiber\{go, await, await_all, await_any, run, sleep, readable, writable};

run(function () {
    run(function () {
        $a = go(function () { foreach ([1, 2, 3] as $i) { echo "a$i\n"; sleep(0.02); } });
        $b = go(function () { sleep(0.01); foreach ([1, 2, 3] as $i) { echo "b$i\n"; sleep(0.02); } });
        await_all([$a, $b]);
    });
});
