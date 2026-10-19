<?php
namespace fiber\Tests\Stress;

use fiber\NotInRunException;
use fiber\Registry;
use PHPUnit\Framework\Attributes\Group;
use function fiber\{go, run, sleep};

#[Group('stress')]
final class FrameMembershipTest extends StressCase
{
    /**
     * A fiber the adapter did not create, built in one task and driven from a nested run in another, calls go().
     * Documented approximation: the child joins the innermost active run anywhere. Whatever it joins, run() must
     * not return while it is unfinished, and nothing hangs.
     */
    public function testForeignFiberChildIsNeverOrphaned(): void
    {
        $this->stress(function () {
            $seq = 0;
            $childDone = 0;
            $joinedInnermost = null;
            $innerRunId = null;
            $innerReturned = 0;
            $outerReturned = 0;
            run(function () use (&$seq, &$childDone, &$joinedInnermost, &$innerRunId, &$innerReturned) {
                $fiber = new \Fiber(function () use (&$seq, &$childDone, &$joinedInnermost) {
                    self::rsleep();
                    $joinedInnermost = spl_object_id(end(Registry::$active));
                    go(function () use (&$seq, &$childDone) {
                        self::rsleep();
                        self::rsleep();
                        $childDone = ++$seq;
                    });
                });
                $a = go(function () use ($fiber, &$seq, &$innerRunId, &$innerReturned) {
                    self::rsleep();
                    run(function () use ($fiber, &$innerRunId) {
                        $innerRunId = spl_object_id(end(Registry::$active));
                        self::rsleep();
                        $fiber->start();
                    });
                    $innerReturned = ++$seq;
                });
                go(function () { self::rsleep(); });
            });
            $outerReturned = ++$seq;
            self::check($childDone > 0, 'foreign-fiber child never ran');
            self::check($childDone < $outerReturned, 'outer run() returned before the foreign-fiber child finished');
            if ($joinedInnermost === $innerRunId) {
                self::check($childDone < $innerReturned, 'child joined the inner run() but it returned first');
            }
        });
    }

    /** A task that calls go() after its parent task returned still holds run() open; after the frame closed go() throws. */
    public function testLateSpawn(): void
    {
        $this->stress(function () {
            $parentReturned = false;
            $lateDone = false;
            $spawn = null;
            run(function () use (&$parentReturned, &$lateDone, &$spawn) {
                $spawn = static fn() => go(static fn() => 1);
                go(function () use (&$parentReturned, &$lateDone) {
                    go(function () use (&$parentReturned, &$lateDone) {
                        while (!$parentReturned) {
                            sleep(0.0003);
                        }
                        self::rsleep();
                        go(function () use (&$lateDone) {
                            self::rsleep();
                            $lateDone = true;
                        });
                    });
                    $parentReturned = true; // returns now; the run() frame stays open through its child
                });
            });
            self::check($lateDone, 'run() returned without waiting for a task spawned after its parent returned');
            try {
                $spawn();
                self::check(false, 'go() after the frame closed did not throw');
            } catch (NotInRunException) {
            }
        });
    }
}
