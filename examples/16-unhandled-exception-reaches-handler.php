<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/16-unhandled-exception-reaches-handler.php
use function async\{go, await, await_all, await_any, run, service, sleep, readable, writable};

// a failing service task (nobody awaits it, no run() owns it) must reach the adapter's error
// handler (reported by the runner); it does not make run() throw
run(function () {
    echo "start\n";
    service(function () { throw new RuntimeException("nobody awaits me"); });
    sleep(0.05);
});
echo "run returned\n";
