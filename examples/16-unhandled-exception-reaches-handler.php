<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/16-unhandled-exception-reaches-handler.php
// unhandled service errors reach the error handler
use function fiber\{go, await, await_all, await_any, run, service, sleep, readable, writable};

// a failing service task (nobody awaits it, no run() owns it) must reach the adapter's error
// handler (reported by the runner). Whether run() then returns or the handler ends the process is
// the loop's own policy, so nothing is printed after run()
run(function () {
    echo "start\n";
    service(function () { throw new RuntimeException("nobody awaits me"); });
    sleep(0.05);
});
