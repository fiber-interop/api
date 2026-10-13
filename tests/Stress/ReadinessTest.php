<?php
namespace async\Tests\Stress;

use PHPUnit\Framework\Attributes\Group;
use function async\{go, readable, run};

#[Group('stress')]
final class ReadinessTest extends StressCase
{
    public function testReadersContendingOnOneStream(): void
    {
        $this->stress(function () {
            [$r, $w] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            stream_set_blocking($r, false);
            stream_set_blocking($w, false);
            $k = mt_rand(2, 5);
            $m = mt_rand(5, 20);
            $written = '';
            $got = [];
            $timeouts = 0;
            run(function () use ($r, $w, $k, $m, &$written, &$got, &$timeouts) {
                go(function () use ($w, $m, &$written) {
                    for ($i = 0; $i < $m; $i++) {
                        $chunk = '';
                        for ($j = mt_rand(1, 40); $j > 0; $j--) {
                            $chunk .= chr(mt_rand(0, 255));
                        }
                        self::check(fwrite($w, $chunk) === strlen($chunk), 'short write');
                        $written .= $chunk;
                        self::rsleep();
                    }
                    fclose($w);
                });
                for ($i = 0; $i < $k; $i++) {
                    $quitter = $i === 0;
                    go(function () use ($r, $i, $quitter, &$got, &$timeouts) {
                        $got[$i] = '';
                        while (true) {
                            try {
                                readable($r, mt_rand(300, 2000) / 1e6);
                            } catch (\async\TimeoutException) {
                                $timeouts++;
                                if ($quitter) {
                                    return; // a reader giving up must not hurt the others
                                }
                                continue;
                            }
                            $d = fread($r, 8192); // may be '' : readiness is advisory
                            if ($d === '' || $d === false) {
                                if (feof($r)) {
                                    return;
                                }
                                continue;
                            }
                            $got[$i] .= $d;
                        }
                    });
                }
            });
            $all = implode('', $got);
            self::check(strlen($all) === strlen($written), sprintf('read %d bytes, wrote %d', strlen($all), strlen($written)));
            $a = count_chars($all, 1); $b = count_chars($written, 1);
            self::check($a == $b, 'bytes read differ from bytes written (duplicated or lost)');
            fclose($r);
        });
    }
}
