<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/23-current.php
// current() is the handle go() returned for the running task
use function fiber\{go, await, await_all, await_any, run, service, sleep, readable, writable, current};

echo "outside run(): ", var_export(current(), true), "\n";
run(function () {
    echo "run()'s task has a handle: ", var_export(current() !== null, true), "\n";
    $t = go(function () use (&$t) {
        sleep(0.01);
        return current() === $t;
    });
    echo "inside its task, after a suspension: ", var_export(await($t), true), "\n";
    $u = go(function () use (&$u) {
        $before = current();
        sleep(0.01);
        return $before === $u ? 'the handle' : ($before === null ? 'null' : 'another object');
    });
    echo "before its first suspension: ", await($u), "\n";
    $s = service(function () use (&$s) { sleep(0.01); return current() === $s; });
    echo "a service task too: ", var_export(await($s), true), "\n";
    try {
        await(current());
        echo "awaited itself\n";
    } catch (\LogicException $e) {
        echo "a task cannot await itself: LogicException\n";
    }
    // a task started inside another may await that task through the handle current() gave it
    $seen = new fiber\Deferred();
    $failing = go(function () use ($seen) {
        $me = current();
        go(function () use ($me, $seen) {
            try {
                await($me);
                $seen->resolve('returned normally');
            } catch (\RuntimeException $e) {
                $seen->resolve('saw ' . $e->getMessage());
            }
        });
        sleep(0.01);
        throw new \RuntimeException('boom');
    });
    echo "a watcher started inside a failing task: ", await($seen), "\n";
    try {
        await($failing);
    } catch (\RuntimeException $e) {
        echo "the task's own awaiter too: ", $e->getMessage(), "\n";
    }
    $d = new fiber\Deferred();
    go(function () use ($d) { sleep(0.01); $d->resolve('event'); });
    echo "Deferred resolved from another task: ", await($d), "\n";
    $r = new fiber\Deferred();
    $r->reject(new \RuntimeException('no'));
    try {
        await($r);
    } catch (\RuntimeException $e) {
        echo "Deferred rejected: ", $e->getMessage(), "\n";
    }
});
echo "outside again: ", var_export(current(), true), "\n";
