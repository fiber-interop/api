<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/19-nested-run.php
// nested run() waits only for its own tasks
use function fiber\{go, await, await_all, await_any, run, service, sleep, readable, writable};

// run() inside a task behaves the same, and waits only for what was started inside it
run(function () {
    go(function () { sleep(0.03); echo "outer child done\n"; });
    $r = run(function () {
        go(function () { sleep(0.01); echo "inner child done\n"; });
        return "inner f";
    });
    echo "nested run returned: $r\n";
    try {
        run(function () { go(function () { throw new LogicException("inner unawaited"); }); });
    } catch (LogicException $e) {
        echo "nested run rethrew: ", $e->getMessage(), "\n";
    }
});
echo "outer run returned\n";
