<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/14-scope-in-queued-callback.php
// queued callbacks resume tasks in their own Scope
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

run(function () {
    [$r, $w] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $s = new Scope();
    $t = $s->run(fn() => go(function () use ($s, $r) {
        sleep(0.01);
        echo "after timer: ", var_export(Scope::getCurrent() === $s, true), "\n";
        readable($r);
        echo "after io: ", var_export(Scope::getCurrent() === $s, true), "\n";
    }));
    go(function () use ($w) { sleep(0.02); fwrite($w, "x"); });
    await($t);
});
