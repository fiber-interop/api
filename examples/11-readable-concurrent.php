<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/11-readable-concurrent.php
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

[$r, $w] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
stream_set_blocking($r, false);
run(function () use ($r, $w) {
    $waiters = [];
    foreach (["x", "y"] as $name) {
        $waiters[] = go(function () use ($r, $name) { readable($r); echo "$name woke\n"; });
    }
    sleep(0.02);
    fwrite($w, "hello");
    await_all($waiters);
    echo "read: ", fread(readable($r), 100), "\n";
    try { readable($r, 0.01); echo "unexpected\n"; } catch (async\TimeoutException) { echo "readable timeout\n"; }
});
