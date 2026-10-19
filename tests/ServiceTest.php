<?php
namespace fiber\Tests;

use PHPUnit\Framework\TestCase;

/** A service task does not keep the reference loop alive. */
final class ServiceTest extends TestCase
{
    public function testServiceDoesNotKeepLoopAlive(): void
    {
        $code = 'require ' . var_export(__DIR__ . '/bootstrap-reference.php', true) . ';'
            . '$t = microtime(true); fiber\run(function () { fiber\service(fn() => fiber\sleep(1.0)); });'
            . 'echo microtime(true) - $t < 0.5 ? "ok" : "slow";';
        $this->assertSame('ok', shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)));
    }
}
