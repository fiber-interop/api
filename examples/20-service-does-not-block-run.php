<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/20-service-does-not-block-run.php
// a service() task does not delay run()
use function async\{go, await, await_all, await_any, run, service, sleep, readable, writable};

// a service task does not count towards run(): run() returns while it still sleeps
$t = microtime(true);
run(function () {
    service(function () { sleep(0.2); });
    sleep(0.01);
    echo "f done\n";
});
echo "run returned before the service woke: ", var_export(microtime(true) - $t < 0.15, true), "\n";
