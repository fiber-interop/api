<?php
namespace async\Tests\Stress;

use PHPUnit\Framework\Attributes\Group;
use function async\{await, go, run, sleep};

#[Group('stress')]
final class OrderingTest extends StressCase
{
    public function testFirstFailureWinsAndAllChildrenFinish(): void
    {
        $this->stress(function () {
            $order = 0;
            $completed = 0;
            $n = mt_rand(2, 6);
            $thrown = [];
            try {
                run(function () use ($n, &$order, &$completed, &$thrown) {
                    for ($i = 0; $i < $n; $i++) {
                        go(function () use ($i, &$order, &$completed, &$thrown) {
                            try {
                                self::rsleep();
                                self::rsleep();
                                $e = new \RuntimeException("child $i", ++$order);
                                $thrown[$e->getCode()] = $e;
                                throw $e;
                            } finally {
                                $completed++;
                            }
                        });
                    }
                });
                self::check(false, 'run() did not rethrow');
            } catch (\RuntimeException $e) {
                self::check($e === $thrown[1], "rethrown failure #{$e->getCode()}, expected the first (#1)");
            }
            self::check($completed === $n, 'run() returned before all children ran to completion');
        });
    }

    public function testRepeatableAwaitUnderContention(): void
    {
        $this->stress(function (int $iter) {
            $fail = $iter % 2 === 1;
            $runs = 0;
            $results = [];
            $r = mt_rand(3, 7);
            try {
                run(function () use ($fail, &$runs, &$results, $r) {
                    $task = go(function () use ($fail, &$runs) {
                        $runs++;
                        self::rsleep(); self::rsleep();
                        if ($fail) {
                            throw new \LogicException('boom');
                        }
                        return new \stdClass();
                    });
                    for ($i = 0; $i < $r; $i++) {
                        $timeout = mt_rand(0, 1) ? mt_rand(1, 5000) / 1e6 : \PHP_FLOAT_MAX;
                        go(function () use ($task, $timeout, $i, &$results) {
                            self::rsleep();
                            try {
                                $results[$i] = [$timeout, await($task, $timeout)];
                            } catch (\Throwable $e) {
                                $results[$i] = [$timeout, $e];
                            }
                        });
                    }
                });
            } catch (\LogicException) {
                // unawaited-failure rethrow when every awaiter timed out is allowed
            }
            self::check($runs === 1, "task ran $runs times");
            self::check(count($results) === $r, 'an awaiter vanished');
            $ref = null;
            foreach ($results as [$timeout, $v]) {
                if ($v instanceof \async\TimeoutException) {
                    self::check($timeout !== \PHP_FLOAT_MAX, 'awaiter without timeout timed out');
                    continue;
                }
                $ref ??= $v;
                self::check($v === $ref, 'awaiters saw different value/exception objects');
                self::check($fail ? $v instanceof \LogicException : $v instanceof \stdClass, 'wrong outcome kind');
            }
        });
    }
}
