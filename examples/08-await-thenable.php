<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/08-await-thenable.php
use function async\{go, await, await_all, await_any, run, sleep, readable, writable};

// any object with then(callable, callable) is awaitable by every adapter
final class TinyPromise {
    private array $cbs = [];
    function then(callable $ok, callable $fail): void { $this->cbs[] = $ok; }
    function resolve($v): void { foreach ($this->cbs as $cb) $cb($v); }
}
$p = new TinyPromise();
go(function () use ($p) { sleep(0.01); $p->resolve("from then-able"); });
echo await($p), "\n";
