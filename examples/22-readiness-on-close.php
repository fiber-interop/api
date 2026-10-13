<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/22-readiness-on-close.php
// readable()/writable() return when the peer closes: readiness means the next operation will not block
use function async\{go, await, run, sleep, readable, writable};

run(function () {
    // (a) a reader waiting on A wakes when the peer B closes; the read does not block
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($a, false);
    stream_set_blocking($b, false);
    $closer = go(function () use ($b) {
        sleep(0.01);
        fclose($b);
    });
    readable($a, 5.0);
    echo "readable after peer close: yes\n";
    $data = fread($a, 8192);
    echo 'read after eof: ', $data === '' || $data === false ? 'empty' : 'data', "\n";
    await($closer);
    fclose($a);

    // (b) writable() on A returns once the peer is gone; the write fails instead of blocking
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($a, false);
    fclose($b);
    writable($a, 0.5);
    echo "writable after peer close: yes\n";
    try {
        $n = @fwrite($a, 'x');
        echo 'write failed: ', $n === false || $n === 0 ? 'yes' : 'no', "\n";
    } catch (Throwable) {
        echo "write failed: yes\n";
    }
    fclose($a);
});
