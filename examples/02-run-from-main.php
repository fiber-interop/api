<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/02-run-from-main.php
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

echo "run: ", run(function () { sleep(0.01); return "done"; }), "\n";
