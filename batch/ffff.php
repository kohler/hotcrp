<?php
// autoassign.php -- HotCRP autoassignment script
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Ffff_Batch::make_args($argv));
}

class Ffff_Batch {
    static function make_args($argv) {
        $conf = initialize_conf(null, null);
        $user = $conf->root_user();
        $prows = $user->paper_set(["finalized" => true]);
        $formula = new Formula("(#r2.manual or (count(OveMer>=0)<3) or (count(OveMer>=3)>=2) or (count(OveMer>=4)>=1) )");
        $formula->check($user);
        $function = $formula->compile_json_function();
        $state = $formula->make_state($user);

        foreach ($prows as $prow) {
            $state->run($prow);
        }

        if (1) {
            $t0 = microtime(true);
            $t = "";
            for ($i = 0; $i !== 100; ++$i) {
                foreach ($prows as $prow) {
                    $t .= $function($prow, null, $user) ? "1" : "0";
                }
            }
            $t1 = microtime(true);
            fwrite(STDOUT, sprintf("%.06f %s\n", $t1 - $t0, sha1($t)));
        }

        if (1) {
            $t2 = microtime(true);
            $stt = "";
            for ($i = 0; $i !== 100; ++$i) {
                foreach ($prows as $prow) {
                    $stt .= $state->run($prow) ? "1" : "0";
                }
            }
            $t3 = microtime(true);
            fwrite(STDOUT, sprintf("%.06f %s\n", $t3 - $t2, sha1($stt)));
        }

        if (1) {
            try {
                $t0 = microtime(true);
                $machine = FormulaMachine::make_formula($state);
                $stt = "";
                for ($i = 0; $i !== 100; ++$i) {
                    foreach ($prows as $prow) {
                        $stt .= $machine->execute($state, $prow) ? "1" : "0";
                    }
                }
                $t1 = microtime(true);
                fwrite(STDOUT, sprintf("%.06f %s\n", $t1 - $t0, sha1($stt)));
            } catch (Exception $err) {
                fwrite(STDERR, $err->getMessage() . $err->getTraceAsString() . "\n");
            }
        }
    }
}
