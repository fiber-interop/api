<?php
namespace fiber\Tests;

use fiber\Adapter;
use fiber\Cancelled;
use fiber\CancelledException;
use fiber\NotSupportedException;
use fiber\Registry;
use fiber\Tests\Support\FiberAdapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/FiberAdapter.php';

final class CancelTest extends TestCase
{
    protected function tearDown(): void
    {
        Registry::reset();
    }

    public function testAdapterWithoutCancelThrowsNotSupported(): void
    {
        $adapter = $this->createStub(Adapter::class);
        \fiber\setup($adapter);
        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('Adapter ' . get_class($adapter) . ' does not support cancel()');
        \fiber\cancel(new \stdClass());
    }

    public function testReferenceAdapterCancelsASleepNatively(): void
    {
        \fiber\setup(new FiberAdapter());
        $started = microtime(true);
        $caught = \fiber\run(function () {
            $t = \fiber\go(fn() => \fiber\sleep(10));
            \fiber\sleep(0.01);
            \fiber\cancel($t);
            try {
                \fiber\await($t);
            } catch (CancelledException $e) {
                return $e;
            }
            return null;
        });
        $this->assertInstanceOf(Cancelled::class, $caught);
        $this->assertSame('Cancelled', $caught->getMessage());
        $this->assertLessThan(0.5, microtime(true) - $started);
    }

    public function testCancelledServiceTaskIsNotReportedAndAwaitRethrows(): void
    {
        $adapter = new FiberAdapter();
        $reported = [];
        $adapter->errorHandler = static function (\Throwable $e) use (&$reported) { $reported[] = $e; };
        \fiber\setup($adapter);
        $reason = new Cancelled('stop');
        $caught = \fiber\run(function () use ($reason) {
            $s = \fiber\service(fn() => \fiber\sleep(10));
            \fiber\sleep(0.01);
            \fiber\cancel($s, $reason);
            \fiber\sleep(0.01);
            try {
                \fiber\await($s);
            } catch (CancelledException $e) {
                return $e;
            }
            return null;
        });
        $this->assertSame($reason, $caught);
        $this->assertSame([], $reported);
    }

    public function testCancelRejectsAnObjectThatIsNoTask(): void
    {
        \fiber\setup(new FiberAdapter());
        $this->expectException(\TypeError::class);
        \fiber\run(fn() => \fiber\cancel(new \stdClass()));
    }

    public function testFrameCancelEndsEveryTaskOfTheRunAndNestedRunsButNotServices(): void
    {
        \fiber\setup(new FiberAdapter());
        $log = [];
        $started = microtime(true);
        $result = \fiber\run(function () use (&$log) {
            $frame = \fiber\frame();
            $this->assertSame($frame, \fiber\frame());
            \fiber\go(function () use (&$log) {
                try { \fiber\sleep(10); } catch (CancelledException $e) { $log[] = 'task:' . $e->getMessage(); }
            });
            \fiber\go(function () use (&$log, $frame) {
                \fiber\run(function () use (&$log, $frame) {
                    $this->assertNotSame($frame, \fiber\frame());
                    \fiber\go(function () use (&$log) {
                        try { \fiber\sleep(10); } catch (CancelledException $e) { $log[] = 'nested:' . $e->getMessage(); }
                    });
                });
            });
            \fiber\go(function () use ($frame) { \fiber\sleep(0.01); \fiber\cancel($frame, new Cancelled('quit')); });
            try { \fiber\sleep(10); } catch (CancelledException $e) { $log[] = 'body:' . $e->getMessage(); }
            \fiber\go(function () use (&$log) {       // started in a cancelled frame
                try { \fiber\sleep(0); $log[] = 'late slept'; } catch (CancelledException $e) { $log[] = 'late:' . $e->getMessage(); }
            });
            \fiber\sleep(1);                          // the frame's reason: run() returns normally
            return 'not reached';
        });
        sort($log);
        $this->assertNull($result);
        $this->assertSame(['body:quit', 'late:quit', 'nested:quit', 'task:quit'], $log);
        $this->assertLessThan(0.5, microtime(true) - $started);
    }

    public function testFrameCancelIsIdempotentAndKeepsTheFirstReason(): void
    {
        \fiber\setup(new FiberAdapter());
        $seen = \fiber\run(function () {
            $first = new Cancelled('first');
            \fiber\cancel(\fiber\frame(), $first);
            \fiber\cancel(\fiber\frame(), new Cancelled('second'));
            try { \fiber\sleep(0); } catch (CancelledException $e) { return $e; }
            return null;
        });
        $this->assertSame('first', $seen?->getMessage());
    }

    public function testOnFailureHearsOfAnUnawaitedFailureAtOnceAndCanEndTheRun(): void
    {
        \fiber\setup(new FiberAdapter());
        $heard = [];
        $started = microtime(true);
        try {
            \fiber\run(function () use (&$heard) {
                \fiber\on_failure(function (\Throwable $e) use (&$heard) {
                    $heard[] = $e->getMessage();
                    \fiber\cancel(\fiber\frame());
                });
                \fiber\go(function () { \fiber\sleep(0.01); throw new \RuntimeException('worker broke'); });
                \fiber\go(fn() => \fiber\sleep(10));
                \fiber\sleep(10);
            });
            $this->fail('run() should rethrow the failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('worker broke', $e->getMessage());
        }
        $this->assertSame(['worker broke'], $heard);
        $this->assertLessThan(0.5, microtime(true) - $started);
    }

    public function testOnFailureIgnoresCancellationsAndAwaitedFailures(): void
    {
        \fiber\setup(new FiberAdapter());
        $heard = [];
        \fiber\run(function () use (&$heard) {
            \fiber\on_failure(function (\Throwable $e) use (&$heard) { $heard[] = $e->getMessage(); });
            $t = \fiber\go(fn() => \fiber\sleep(10));
            \fiber\sleep(0.01);
            \fiber\cancel($t);
            $failing = \fiber\go(function () { \fiber\sleep(0.01); throw new \LogicException('awaited'); });
            try { \fiber\await($failing); } catch (\LogicException) {}
        });
        $this->assertSame([], $heard);
    }

    public function testFrameOutsideRunThrows(): void
    {
        \fiber\setup(new FiberAdapter());
        $this->expectException(\fiber\NotInRunException::class);
        \fiber\frame();
    }
}
