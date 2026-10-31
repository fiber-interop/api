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
}
