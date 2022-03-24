<?php
// t_dmpspeed.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

use dmp\diff_match_patch as diff_match_patch;

class DMPSpeed_Tester {
    function test_Random() {
        $words = preg_split('/\s+/', file_get_contents("/usr/share/dict/words"));
        $nwords = count($words);
        $differ = new diff_match_patch;
        $time = 0.0;

        for ($nt = 0; $nt !== 100000; ++$nt) {
            $nw = mt_rand(10, 3000);
            $w = [];
            for ($i = 0; $i !== $nw; ++$i) {
                $w[] = $words[mt_rand(0, $nwords - 1)];
                $w[] = mt_rand(0, 4) === 0 ? "\n" : " ";
            }
            $t0 = join("", $w);

            $nc = mt_rand(1, 24);
            $t1 = $t0;
            for ($i = 0; $i !== $nc; ++$i) {
                $type = mt_rand(0, 7);
                $len = mt_rand(1, 100);
                $pos = mt_rand(0, strlen($t1));
                if ($type < 3) {
                    $t1 = substr($t1, 0, $pos) . substr($t1, $pos + $len);
                } else {
                    $t1 = substr($t1, 0, $pos) . $words[mt_rand(0, $nwords - 1)] . " " . substr($t1, $pos);
                }
            }

            if ($nt % 1000 === 0) {
                fwrite(STDERR, "#{$nt}{{$time}} ");
            }
            $tm0 = microtime(true);
            $diff = $differ->diff($t0, $t1);
            $time += microtime(true) - $tm0;
            $o0 = $differ->diff_text1($diff);
            if ($o0 !== $t0) {
                fwrite(STDERR, "\n#{$nt}\n");
                fwrite(STDERR, "=== NO:\n$t0\n=== GOT:\n$o0\n");
                $c1 = $differ->diff_commonPrefix($t0, $o0);
                fwrite(STDERR, "=== NEAR {$c1}:\n" . substr($t0, $c1,100) . "\n=== GOT:\n" . substr($o0, $c1, 100) . "\n");
                assert(false);
            }
            $o1 = $differ->diff_text2($diff);
            if ($o1 !== $t1) {
                fwrite(STDERR, "\n#{$nt}\n");
                fwrite(STDERR, "=== NO OUT:\n$t1\n=== GOT:\n$o1\n");
                $c1 = $differ->diff_commonPrefix($t1, $o1);
                fwrite(STDERR, "=== NEAR {$c1} OUT:\n" . substr($t1, $c1,100) . "\n=== GOT:\n" . substr($o1, $c1, 100) . "\n");
                assert(false);
            }
        }
    }

    function test_Speedtest() {
        $text1 = file_get_contents("conf/speedtest1.txt");
        $text2 = file_get_contents("conf/speedtest2.txt");

        $dmp = new diff_match_patch;
        $dmp->Diff_Timeout = 0;

        // Execute one reverse diff as a warmup.
        $dmp->diff($text2, $text1);
        gc_collect_cycles();

        $us_start = microtime(true);
        $diff = $dmp->diff($text1, $text2);
        $us_end = microtime(true);

        file_put_contents("/tmp/x.html", $dmp->diff_prettyHtml($diff));
        fwrite(STDOUT, sprintf("Elapsed time: %.3f\n", $us_end - $us_start));
    }

    function test_SpeedtestSemantic($sz) {
        $dmp = new diff_match_patch;
        $dmp->Diff_Timeout = 0;
        $s1 = str_repeat("a", 50) . str_repeat("b", $sz) . str_repeat("c", 50);
        $s2 = str_repeat("a", 50) . str_repeat("b", 2 * $sz) . str_repeat("c", 50);

        $t0 = microtime(true);
        $diffs = $dmp->diff($s1, $s2);
        $t1 = microtime(true);
        $dmp->diff_cleanupSemantic($diffs);
        $t2 = microtime(true);

        fwrite(STDOUT, sprintf("Elapsed time: diff %.3f, cleanup %.3f\n", $t1-$t0, $t2-$t1));
    }
}
