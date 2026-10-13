<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/21-superglobals-per-scope.php
// $_GET is per scope; each scope sees its own
use function async\{go, await_all, run, sleep};

$root = $_GET;
run(function () {
    $task = function (string $name, float $delay) {
        $scope = new Scope();
        return $scope->run(fn() => go(function () use ($name, $delay) {
            $_GET = ['req' => $name];
            sleep($delay);
            echo "$name sees {$_GET['req']}\n";
            sleep($delay);
            echo "$name sees {$_GET['req']}\n";
        }));
    };
    await_all([$task('a', 0.01), $task('b', 0.015)]);
});
echo 'main sees root: ', $_GET === $root ? 'yes' : 'no', "\n";
