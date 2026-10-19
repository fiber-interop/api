<?php
namespace fiber\Tests;

use fiber\NotConfiguredException;
use fiber\Registry;
use fiber\Tests\Support\FiberAdapter;
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
        \fiber\adapter();
    }

    public function testSameClassTwiceIsNoOp(): void
    {
        $first = new FiberAdapter();
        \fiber\setup($first);
        \fiber\setup(new FiberAdapter());
        $this->assertSame($first, \fiber\adapter());
    }

    public function testOtherClassThrows(): void
    {
        \fiber\setup(new FiberAdapter());
        $this->expectException(\LogicException::class);
        \fiber\setup($this->createStub(\fiber\Adapter::class));
    }

    public function testSingleCandidateResolvesLazily(): void
    {
        $made = 0;
        \fiber\register(function () use (&$made) { $made++; return new FiberAdapter(); }, 'vendor/one');
        $this->assertSame(0, $made);
        $this->assertInstanceOf(FiberAdapter::class, \fiber\adapter());
        \fiber\adapter();
        $this->assertSame(1, $made);
    }

    public function testTwoCandidatesThrowAtFirstUse(): void
    {
        \fiber\register(fn() => new FiberAdapter(), 'vendor/one');
        \fiber\register(fn() => new FiberAdapter(), 'vendor/two');
        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('vendor/one, vendor/two');
        \fiber\adapter();
    }

    public function testSetupBeatsCandidates(): void
    {
        \fiber\register(fn() => throw new \LogicException('must not be built'), 'vendor/one');
        \fiber\register(fn() => throw new \LogicException('must not be built'), 'vendor/two');
        $a = new FiberAdapter();
        \fiber\setup($a);
        $this->assertSame($a, \fiber\adapter());
    }
}
