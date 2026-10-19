<?php
namespace fiber\Tests\Stress;

use PHPUnit\Framework\Attributes\Group;
use function fiber\{go, run, sleep};

#[Group('stress')]
final class NestedRunTest extends StressCase
{
    /** Each nested run() returns after ITS children only; sibling 0's children wait for every other nested run to return. */
    public function testNestedRunWaitsOnlyForOwnChildren(): void
    {
        $this->stress(function () {
            $n = mt_rand(3, 6);
            $finished = [];
            $returned = 0;
            run(function () use ($n, &$finished, &$returned) {
                for ($i = 0; $i < $n; $i++) {
                    go(function () use ($i, $n, &$finished, &$returned) {
                        self::rsleep();
                        run(function () use ($i, $n, &$finished, &$returned) {
                            for ($c = 0; $c < 3; $c++) {
                                go(function () use ($i, $c, $n, &$finished, &$returned) {
                                    if ($i === 0) {
                                        // waits for all other nested runs to have returned: deadlocks if they wait for us
                                        while ($returned < $n - 1) {
                                            sleep(0.0005);
                                        }
                                    } else {
                                        self::rsleep();
                                        self::rsleep();
                                    }
                                    $finished["$i.$c"] = true;
                                });
                            }
                        });
                        for ($c = 0; $c < 3; $c++) {
                            self::check(isset($finished["$i.$c"]), "nested run $i returned before its child $c finished");
                        }
                        if ($i !== 0) {
                            $returned++;
                        }
                    });
                }
            });
            self::check(count($finished) === $n * 3, 'not all children finished');
        });
    }
}
