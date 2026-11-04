<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/20-service-does-not-block-run.php
// a service() task does not delay run()
use function fiber\{go, await, await_all, await_any, run, service, sleep, readable, writable};

// a service task does not count towards run(): run() returns while it still sleeps
$woke = false;
run(function () use (&$woke) {
    service(function () use (&$woke) { sleep(10); $woke = true; });
    sleep(0.01);
    echo "f done\n";
});
echo "run returned before the service woke: ", var_export(!$woke, true), "\n";
