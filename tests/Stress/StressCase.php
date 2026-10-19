<?php
namespace fiber\Tests\Stress;

use PHPUnit\Framework\TestCase;

/**
 * Base of the stress tier: runs a scenario many times with seeded random timing and reports the seed
 * on failure. Env: FIBER_INTEROP_STRESS_ITERATIONS (default 200), FIBER_INTEROP_STRESS_SEED.
 * Without FIBER_INTEROP_STRESS_BOOTSTRAP (set by bin/fiber-interop-stress) the reference adapter is used.
 */
abstract class StressCase extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('FIBER_INTEROP_STRESS_BOOTSTRAP') === false && \fiber\Registry::$adapter === null) {
            if (!class_exists('Scope')) {
                require_once __DIR__ . '/../Support/ScopeStub.php';
            }
            require_once __DIR__ . '/../Support/FiberAdapter.php';
            \fiber\setup(new \fiber\Tests\Support\FiberAdapter());
        }
    }

    /** Runs $scenario($iteration) repeatedly; $scenario may use rsleep() and fail via exceptions. */
    protected function stress(callable $scenario): void
    {
        $n = (int) (getenv('FIBER_INTEROP_STRESS_ITERATIONS') ?: 200);
        $base = getenv('FIBER_INTEROP_STRESS_SEED') !== false ? (int) getenv('FIBER_INTEROP_STRESS_SEED') : random_int(1, 1 << 30);
        $guard = function_exists('pcntl_alarm') && function_exists('pcntl_async_signals');
        if ($guard) {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, static function () { throw new \RuntimeException('iteration exceeded 5 s (hang)'); });
        }
        for ($i = 0; $i < $n; $i++) {
            mt_srand($base + $i);
            $guard && pcntl_alarm(5);
            try {
                $scenario($i);
            } catch (\Throwable $e) {
                $guard && pcntl_alarm(0);
                $this->fail(sprintf("seed=%d (FIBER_INTEROP_STRESS_SEED=%d, iteration %d): %s: %s\n%s",
                    $base + $i, $base, $i, get_class($e), $e->getMessage(), $e->getTraceAsString()));
            }
            $guard && pcntl_alarm(0);
        }
        $this->addToAssertionCount(1);
    }

    /** Random sleep of 0..3 ms. */
    protected static function rsleep(int $maxMicros = 3000): void
    {
        \fiber\sleep(mt_rand(0, $maxMicros) / 1e6);
    }

    protected static function check(bool $cond, string $msg): void
    {
        if (!$cond) {
            throw new \RuntimeException('invariant violated: ' . $msg);
        }
    }
}
