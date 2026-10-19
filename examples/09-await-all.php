<?php
// Run: php -d auto_prepend_file=<your bootstrap> examples/09-await-all.php
// await_all returns results under the same keys
use function fiber\{go, await, await_all, await_any, run, sleep, readable, writable};

run(function () {
    print_r(await_all([
        "slow" => go(function () { sleep(0.03); return 1; }),
        "fast" => go(function () { sleep(0.01); return 2; }),
    ]));
    try {
        await_all([
            go(function () { sleep(0.01); throw new LogicException("first"); }),
            go(function () { sleep(0.03); echo "second settled\n"; return 3; }),
        ]);
    } catch (LogicException $e) {
        echo "caught ", $e->getMessage(), " after all settled\n";
    }
});
