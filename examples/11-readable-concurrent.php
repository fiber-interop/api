<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/11-readable-concurrent.php
// several fibers wait readable() on one stream
use function fiber\{go, await, await_all, await_any, run, sleep, readable, writable};

run(function () {
    [$r, $w] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($r, false);
    run(function () use ($r, $w) {
        $waiters = [];
        $woke = [];
        foreach (["x", "y"] as $name) {
            $waiters[] = go(function () use ($r, $name, &$woke) { readable($r); $woke[] = $name; });
        }
        sleep(0.02);
        fwrite($w, "hello");
        await_all($waiters);
        sort($woke);                        // both woke; in no promised order
        foreach ($woke as $name) {
            echo "$name woke\n";
        }
        echo "read: ", fread(readable($r), 100), "\n";
        try { readable($r, 0.01); echo "unexpected\n"; } catch (fiber\TimeoutException) { echo "readable timeout\n"; }
    });
});
