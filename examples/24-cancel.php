<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/24-cancel.php
// cancel() makes a task's current or next wait throw the reason
use function fiber\{go, await, await_all, await_any, run, service, sleep, readable, writable, current, cancel};
use fiber\{Cancelled, CancelledException};

run(function () {
    // a sibling cancels a task sleeping for 10 s: it ends at once and its finally runs
    $started = microtime(true);
    $sleeper = go(function () {
        try {
            sleep(10);
            echo "sleep returned\n";
        } finally {
            echo "sleeper's finally ran\n";
        }
    });
    go(function () use ($sleeper) { sleep(0.01); cancel($sleeper); });
    try {
        await($sleeper);
        echo "await returned\n";
    } catch (CancelledException $e) {
        echo "await rethrew ", get_class($e), ": ", $e->getMessage(), "\n";
    }
    echo "ended well under a second: ", var_export(microtime(true) - $started < 0.5, true), "\n";

    // a task waiting for a pipe to become readable, cancelled with an own reason
    [$r, $w] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($r, false);
    $reader = go(function () use ($r) { readable($r); echo "readable returned\n"; });
    sleep(0.01);
    $reason = new Cancelled('reader stopped');
    cancel($reader, $reason);
    try {
        await($reader);
    } catch (CancelledException $e) {
        echo "reader: ", $e->getMessage(), ", the same object: ", var_export($e === $reason, true), "\n";
    }

    // cancelled right after go(), before or at its first wait
    $early = go(function () { sleep(10); echo "early task slept\n"; });
    cancel($early);
    try {
        await($early);
    } catch (CancelledException $e) {
        echo "cancelled before it waited: ", $e->getMessage(), "\n";
    }

    // a task cancelling itself; the reason is sticky
    $self = go(function () {
        cancel(current());
        echo "cancel(current()) returned\n";
        try {
            sleep(0);
            echo "sleep(0) returned\n";
        } catch (CancelledException $e) {
            echo "sleep(0) threw: ", $e->getMessage(), "\n";
        }
        try {
            sleep(0);
            echo "second sleep(0) returned\n";
        } catch (CancelledException $again) {
            echo "second sleep(0) threw the same reason: ", var_export($again === $e, true), "\n";
        }
        return 'caught and returned';
    });
    $result = await($self);
    echo "self-cancelled task: $result\n";

    // cancel() on a finished task is a no-op
    $done = go(fn() => 42);
    await($done);
    cancel($done);
    echo "finished task still gives: ", await($done), "\n";
});
echo "run returned normally\n";

// a cancelled task nobody awaits does not make run() throw
run(function () {
    $t = go(function () { sleep(10); });
    sleep(0.01);
    cancel($t);
});
echo "unawaited cancelled task: run returned normally\n";

// but an exception other than the reason is an ordinary unawaited failure
try {
    run(function () {
        $t = go(function () {
            try {
                sleep(10);
            } catch (CancelledException) {
                throw new RuntimeException('cleanup failed');
            }
        });
        sleep(0.01);
        cancel($t);
    });
    echo "run returned\n";
} catch (RuntimeException $e) {
    echo "run rethrew: ", $e->getMessage(), "\n";
}
