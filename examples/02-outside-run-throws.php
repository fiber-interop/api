<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/02-outside-run-throws.php
use function async\{go, await, await_all, await_any, run, service, sleep, readable, writable};

// go, await and the waits throw NotInRunException outside run(); run() itself works from {main}
$settled = new class { public function then(?callable $ok = null, ?callable $err = null): void { $ok(1); } };
foreach (['go' => fn() => go(fn() => 1), 'await' => fn() => await($settled), 'sleep' => fn() => sleep(0.01)] as $name => $call) {
    try {
        $call();
        echo "$name: no exception\n";
    } catch (Throwable $e) {
        echo "$name: ", get_class($e), "\n";
    }
}
echo "run: ", run(function () { sleep(0.01); return "done"; }), "\n";
