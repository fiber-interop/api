<?php
namespace fiber\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs each conformance example under the reference FiberAdapter through bin/fiber-interop-conformance. */
final class ConformanceTest extends TestCase
{
    public static function examples(): iterable
    {
        foreach (glob(__DIR__ . '/../examples/*.php') as $file) {
            $name = basename($file, '.php');
            yield $name => [$name];
        }
    }

    #[DataProvider('examples')]
    public function testExample(string $name): void
    {
        $cmd = [PHP_BINARY, __DIR__ . '/../bin/fiber-interop-conformance', __DIR__ . '/bootstrap-reference.php', $name];
        $p = proc_open($cmd, [1 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $this->assertSame(0, proc_close($p), $out);
    }
}
