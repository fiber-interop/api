<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/18-run-is-structured.php
// run() waits for every task started inside it
use function fiber\{go, await, await_all, await_any, run, service, sleep, readable, writable};

// run() returns only after every task started inside it finished, awaited or not, and rethrows
// the exception of a child nobody awaited once the other children are done
run(function () {
    go(function () { sleep(0.02); echo "a done\n"; });
    go(function () { sleep(0.01); echo "b done\n"; });
    echo "f returns\n";
});
echo "run returned\n";
try {
    run(function () {
        go(function () { sleep(0.01); throw new RuntimeException("never awaited"); });
        go(function () { sleep(0.03); echo "sibling finished\n"; });
    });
} catch (RuntimeException $e) {
    echo "run rethrew: ", $e->getMessage(), "\n";
}
