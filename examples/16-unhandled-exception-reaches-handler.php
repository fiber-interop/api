<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/16-unhandled-exception-reaches-handler.php
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

// a task exception nobody awaits must reach the adapter's error handler (reported by the runner)
echo "start\n";
go(function () { throw new RuntimeException("nobody awaits me"); });
sleep(0.05);
