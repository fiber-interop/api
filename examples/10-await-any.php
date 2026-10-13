<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/10-await-any.php
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

echo await_any([
    go(function () { sleep(0.01); throw new RuntimeException("rejected"); }),
    go(function () { sleep(0.03); return "second wins"; }),
    go(function () { sleep(0.06); return "third"; }),
]), "\n";
try {
    await_any([go(function () { throw new RuntimeException("all rejected"); })]);
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
}
sleep(0.08);
