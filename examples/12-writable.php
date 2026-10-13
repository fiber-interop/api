<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/12-writable.php
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

[$r, $w] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
echo "wrote: ", fwrite(writable($w), "abc"), "\n";
