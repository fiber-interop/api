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
        Registry::$adapter = null;
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
}
