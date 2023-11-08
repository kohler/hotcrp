<?php
// t_dmpgitstress.php -- test HotCRP diff_match_patch on Git histories
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

use dmp\diff_match_patch as diff_match_patch;
use const dmp\DIFF_DELETE;
use const dmp\DIFF_INSERT;

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(__DIR__ . '/setup.php');
    if (ini_get_bytes("memory_limit") < (1 << 30)) {
        ini_set("memory_limit", "1G");
    }
    exit(DMPGitStress_Tester::make_args($argv)->run());
}

class DMPGitStress_Tester {
    /** @var list<string> */
    private $files;
    /** @var bool */
    private $quiet = false;
    /** @var bool */
    private $lines = true;
    /** @var dmp\diff_match_patch */
    private $dmp;

    function __construct($files, $arg = []) {
        $this->files = $files;
        $this->dmp = new diff_match_patch;
        if (isset($arg["histogram"])) {
            $this->dmp->Line_Histogram = true;
        }
        if (isset($arg["no-lines"])) {
            $this->lines = false;
        }
        if (isset($arg["quiet"])) {
            $this->quiet = true;
        }
    }

    /** @param string $file
     * @return Generator<string> */
    function versions($file) {
        $f = popen("git log -p --reverse -- " . escapeshellarg($file), "rb");

        $indiff = 0;
        $first = true;
        $adata = $bdata = [];
        '@phan-var-force list<string> $adata';
        $work = null;
        $la = $lb = $na = $nb = 0;
        $hash = null;

        while (($line = fgets($f)) !== false) {
            if ($line !== ""
                && $line[0] === "\\"
                && preg_match('/\A\\\\ No newline at end of file/', $line)
                && $work !== null) {
                if (str_ends_with($work, "\n")) {
                    $work = substr($work, 0, -1);
                }
                continue;
            }

            if ($work !== null) {
                assert($work[0] === "-" || $nb > 0);
                assert($work[0] === "+" || $na > 0);
                if ($work[0] !== "+" && $adata[$la] !== substr($work, 1)) {
                    // This likely happened because of a merge commit; give up
                    return;
                }
                assert($lb === count($bdata));
                if ($work[0] !== "-") {
                    $bdata[] = substr($work, 1);
                    ++$lb;
                    --$nb;
                }
                if ($work[0] !== "+") {
                    ++$la;
                    --$na;
                }
                $work = null;
            }

            if ($line === "") {
                assert(!$indiff);
            } else if ($line[0] === "@") {
                assert($na === 0 && $nb === 0 && $indiff >= 1);
                if (!preg_match('/\A@@ -(0|[1-9]\d*),(0|[1-9]\d*) \+(0|[1-9]\d*),(0|[1-9]\d*) /', $line, $m)) {
                    if (preg_match('/\A@@ -(0|[1-9]\d*),(0|[1-9]\d*) \+(0|[1-9]\d*)() /', $line, $m)) {
                        $m[4] = "1";
                    } else {
                        assert(false);
                    }
                }
                if ($m[1] === "0") {
                    assert($adata === []);
                    $nla = 0;
                } else {
                    $nla = intval($m[1]) - 1;
                }
                assert($la <= $nla && $nla <= count($adata));
                while ($la !== $nla) {
                    $bdata[] = $adata[$la];
                    ++$la;
                    ++$lb;
                }
                $na = intval($m[2]);
                assert($la + $na <= count($adata));
                assert($lb === intval($m[3]) - 1 || ($lb === 0 && $m[3] === "0"));
                $nb = intval($m[4]);
                $indiff = 2;
            } else if (($line[0] === "+" || $line[0] === "-" || $line[0] === " ")
                       && $indiff === 2) {
                $work = $line;
            } else if ($indiff === 1
                       && preg_match('/\A(?:deleted|new|index|rename|similarity|---|\+\+\+) /', $line)) {
                // OK
            } else {
                assert($na === 0 && $nb === 0);
                if ($indiff >= 1 || $first) {
                    $nla = count($adata);
                    while ($la !== $nla) {
                        $bdata[] = $adata[$la];
                        ++$la;
                    }
                    yield join("", $bdata);
                    $adata = $bdata;
                    $bdata = [];
                    $indiff = 0;
                    $first = false;
                }
                if ($line[0] === "d" && str_starts_with($line, "diff ")) {
                    $indiff = 1;
                    $la = $lb = 0;
                } else if ($line[0] === "c" && str_starts_with($line, "commit ")) {
                    $hash = trim(substr($line, 7));
                }
            }
        }

        $nla = count($adata);
        while ($la !== $nla) {
            $bdata[] = $adata[$la];
            ++$la;
        }
        yield join("", $bdata);

        pclose($f);
    }

    /** @param string $a
     * @param string $b
     * @return bool */
    function test($a, $b) {
        try {
            $diffs = $this->dmp->diff($a, $b, $this->lines);

            if (false) {
                $na = $nb = 0;
                foreach ($diffs as $d) {
                    fwrite(STDERR, $d->unparse_op($d->op) . strlen($d->text) . " " . addcslashes($d->text, "\n") . "\n");
                    $d->op !== DIFF_INSERT && ($na += strlen($d->text));
                    $d->op !== DIFF_DELETE && ($nb += strlen($d->text));
                }
                fwrite(STDERR, ">$na $nb : " . strlen($a) . " " . strlen($b) . "\n");
            }
            $this->dmp->diff_validate($diffs, $a, $b);

            // validate that toHCDelta can create $b
            $hcdelta = $this->dmp->diff_toHCDelta($diffs);
            if (!is_valid_utf8($hcdelta)
                && is_valid_utf8($a)
                && is_valid_utf8($b)) {
                throw new dmp\diff_exception("diff_toHCDelta creates non-UTF-8");
            }

            $xdiffs = $this->dmp->diff_fromHCDelta($a, $hcdelta);
            $this->dmp->diff_validate($xdiffs, $a, $b);

            // validate that applyHCDelta can create $b
            if (($x = $this->dmp->diff_applyHCDelta($a, $hcdelta)) !== $b) {
                throw new dmp\diff_exception("incorrect diff_applyHCDelta", $b, $x);
            }

            return true;
        } catch (dmp\diff_exception $ex) {
            error_log("problem encoding delta: " . $ex->getMessage() . "\n" . $ex->getTraceAsString());
            file_put_contents("/tmp/hotcrp-baddiff.txt",
                "###### " . $ex->getMessage()
                . "\n====== " . strlen($a) . "\n" . $a
                . "\n====== " . strlen($b) . "\n" . $b . "\n\n",
                FILE_APPEND);
            if ($ex->expected !== null) {
                file_put_contents("/tmp/hotcrp-baddiff-expected.txt", $ex->expected);
                file_put_contents("/tmp/hotcrp-baddiff-actual.txt", $ex->actual);
            }
            return false;
        }
    }

    function run() {
        $tty = !$this->quiet && posix_isatty(STDERR);
        for ($fi = 0; $fi !== count($this->files); ) {
            $ts = [];
            $f = $this->files[$fi];
            ++$fi;
            foreach ($this->versions($f) as $t) {
                $ts[] = $t;
            }
            assert(count($ts) < 65536);
            if (!$this->quiet) {
                fwrite(STDERR, "$f (" . count($ts) . " versions)...");
            }
            $nsp = 0;
            $ok = true;
            $vs = [];
            while ($fi !== count($this->files)
                   && preg_match('/\A(0|[1-9]\d*):(0|[1-9]\d*)\z/', $this->files[$fi], $m)) {
                $vs[] = (intval($m[1]) << 16) | intval($m[2]);
                ++$fi;
            }
            if (empty($vs)) {
                for ($i = 0; $i !== count($ts); ++$i) {
                    for ($j = 0; $j !== count($ts); ++$j) {
                        $vs[] = ($i << 16) | $j;
                    }
                }
                shuffle($vs);
            }
            foreach ($vs as $v) {
                $v0 = $v >> 16;
                $v1 = $v & 65535;
                assert($v0 < count($ts) && $v1 < count($ts));
                if ($tty) {
                    if ($nsp !== 0) {
                        fwrite(STDERR, "\x1b[{$nsp}D\x1b[K");
                    }
                    $msg = " {$v0}:{$v1}";
                    fwrite(STDERR, $msg);
                    $nsp = strlen($msg);
                }
                $ok = $this->test($ts[$v0], $ts[$v1]) && $ok;
            }
            if (!$this->quiet) {
                if ($tty && $nsp !== 0) {
                    fwrite(STDERR, "\x1b[{$nsp}D\x1b[K");
                }
                fwrite(STDERR, $ok ? " ok\n" : " fail\n");
            }
        }
    }

    /** @param list<string> $argv
     * @return DMPGitStress_Tester */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "help,h !",
            "histogram,H Use histogram method for linewise diffs",
            "no-lines Do not use line mode"
        )->minarg(1)
         ->description("Stress-test HotCRP diff_match_patch on Git histories.
Usage: php test/t_dmpgitstress.php FILE...")
         ->helpopt("help")
         ->parse($argv);
        return new DMPGitStress_Tester($arg["_"], $arg);
    }
}
