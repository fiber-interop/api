<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/13-scope-inherited-by-go.php
// go() tasks inherit the starter's Scope
use function fiber\{go, await, await_all, await_any, run, sleep, readable, writable};

run(function () {
    $root = Scope::getRootScope();
    echo "main is root: ", var_export(Scope::getCurrent() === $root, true), "\n";
    $s = new Scope();
    $t = $s->run(fn() => go(fn() => Scope::getCurrent() === $s));
    echo "task sees creator scope: ", var_export(await($t), true), "\n";
    echo "task from main sees root: ", var_export(run(fn() => Scope::getCurrent() === $root), true), "\n";
});
