<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/25-frame-cancel.php
// frame() is the current run(); cancel(frame()) ends every task of it, nested run()s included;
// on_failure() hears of a task's failure as it happens
use function fiber\{go, await, run, sleep, frame, cancel, on_failure};
use fiber\{Cancelled, CancelledException};

// cancelling the frame ends its tasks, the nested run's tasks, and the body itself (never a service: it has no frame)
$started = microtime(true);
$log = [];
run(function () use (&$log) {
    $frame = frame();
    echo "frame() is the same object each time: ", var_export($frame === frame(), true), "\n";
    go(function () use (&$log) {
        try {
            sleep(10);
        } catch (CancelledException $e) {
            $log[] = 'task: ' . $e->getMessage();
        }
    });
    go(function () use (&$log, $frame) {
        run(function () use (&$log, $frame) {
            echo "nested run() has a frame of its own: ", var_export(frame() !== $frame, true), "\n";
            go(function () use (&$log) {
                try {
                    sleep(10);
                } catch (CancelledException $e) {
                    $log[] = 'nested task: ' . $e->getMessage();
                }
            });
        });
    });
    go(function () use ($frame) {
        sleep(0.01);
        cancel($frame, new Cancelled('quit'));
        echo "cancel(frame()) returned in the task that called it\n";
        try {
            sleep(0);
        } catch (CancelledException $e) {
            echo "its own next wait threw: ", $e->getMessage(), "\n";
        }
    });
    try {
        sleep(10);
    } catch (CancelledException $e) {
        $log[] = 'body: ' . $e->getMessage();
    }
    // a task started in a cancelled frame ends at its first wait
    go(function () use (&$log) {
        $log[] = 'late task started';
        try {
            sleep(0);
            $log[] = 'late task slept';
        } catch (CancelledException $e) {
            $log[] = 'late task: ' . $e->getMessage();
        }
    });
    sleep(0.1);         // throws the reason: the frame is cancelled
    echo "not reached\n";
});
echo "run returned normally: its body ended with the frame's own cancellation\n";
sort($log);
echo implode("\n", $log), "\n";
echo "all ended well under a second: ", var_export(microtime(true) - $started < 0.5, true), "\n";

// on_failure(): the failure of a task nobody awaits, as it happens; here it ends the program
$started = microtime(true);
try {
    run(function () {
        on_failure(function (Throwable $e) {
            echo "on_failure: ", get_class($e), ": ", $e->getMessage(), "\n";
            cancel(frame());
        });
        go(function () { sleep(0.01); throw new RuntimeException('worker broke'); });
        go(function () {
            try {
                sleep(10);
            } finally {
                echo "the other task's finally ran\n";
            }
        });
        sleep(10);
    });
    echo "run returned\n";
} catch (RuntimeException $e) {
    echo "run rethrew the failure: ", $e->getMessage(), ", under a second: ", var_export(microtime(true) - $started < 0.5, true), "\n";
}

// a cancellation is not a failure; an awaited failure is not reported
run(function () {
    on_failure(fn(Throwable $e) => print("on_failure: " . $e->getMessage() . "\n"));
    $t = go(function () { sleep(10); });
    sleep(0.01);
    cancel($t);
    $failing = go(function () { sleep(0.01); throw new LogicException('awaited'); });
    try {
        await($failing);
    } catch (LogicException $e) {
        echo "awaited failure caught by the awaiter: ", $e->getMessage(), "\n";
    }
});
echo "run returned normally\n";

// outside run(): NotInRunException, as for every fiber\ function
try {
    frame();
} catch (fiber\NotInRunException $e) {
    echo "frame() outside run(): ", get_class($e), "\n";
}
