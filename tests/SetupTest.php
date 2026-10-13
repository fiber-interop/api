<?php
namespace async\Tests;

use async\NotConfiguredException;
use async\Registry;
use async\Tests\Support\FiberAdapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/FiberAdapter.php';

final class SetupTest extends TestCase
{
    protected function tearDown(): void
    {
        Registry::reset();
    }

    public function testNotConfigured(): void
    {
        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('fiber-interop/revolt');
        \async\adapter();
    }

    public function testSameClassTwiceIsNoOp(): void
    {
        $first = new FiberAdapter();
        \async\setup($first);
        \async\setup(new FiberAdapter());
        $this->assertSame($first, \async\adapter());
    }

    public function testOtherClassThrows(): void
    {
        \async\setup(new FiberAdapter());
        $this->expectException(\LogicException::class);
        \async\setup($this->createStub(\async\Adapter::class));
    }

    public function testSingleCandidateResolvesLazily(): void
    {
        $made = 0;
        \async\register(function () use (&$made) { $made++; return new FiberAdapter(); }, 'vendor/one');
        $this->assertSame(0, $made);
        $this->assertInstanceOf(FiberAdapter::class, \async\adapter());
        \async\adapter();
        $this->assertSame(1, $made);
    }

    public function testTwoCandidatesThrowAtFirstUse(): void
    {
        \async\register(fn() => new FiberAdapter(), 'vendor/one');
        \async\register(fn() => new FiberAdapter(), 'vendor/two');
        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('vendor/one, vendor/two');
        \async\adapter();
    }

    public function testSetupBeatsCandidates(): void
    {
        \async\register(fn() => throw new \LogicException('must not be built'), 'vendor/one');
        \async\register(fn() => throw new \LogicException('must not be built'), 'vendor/two');
        $a = new FiberAdapter();
        \async\setup($a);
        $this->assertSame($a, \async\adapter());
    }
}
