<?php
// cleandocstore.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

require_once(dirname(__DIR__) . "/src/siteloader.php");

$arg = Getopt::rest($argv, "hn:c:Vm:du:U:q", ["help", "name:", "count:", "verbose", "match:",
    "dry-run", "max-usage:", "min-usage:", "quiet", "silent", "keep-temp", "docstore"]);
foreach (["c" => "count", "V" => "verbose", "m" => "match", "d" => "dry-run",
          "u" => "max-usage", "U" => "min-usage", "q" => "quiet"] as $s => $l) {
    if (isset($arg[$s]) && !isset($arg[$l]))
        $arg[$l] = $arg[$s];
}
if (isset($arg["silent"])) {
    $arg["quiet"] = false;
}
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/cleandocstore.php [-c COUNT] [-V] [-m MATCH] [-d|--dry-run]
             [-u USAGEHI] [-U USAGELOW] [--keep-temp] [--docstore] [DOCSTORES...]\n");
    exit(0);
}
if (isset($arg["count"])) {
    if (ctype_digit($arg["count"])) {
        $arg["count"] = intval($arg["count"]);
    } else {
        fwrite(STDERR, "batch/cleandocstore.php: `-c` expects integer\n");
        exit(1);
    }
}
foreach (["max-usage", "min-usage"] as $k) {
    if (isset($arg[$k])) {
        if (is_numeric($arg[$k])
            && ($f = floatval($arg[$k])) >= 0
            && $f <= 1) {
            $arg[$k] = $f;
        } else {
            fwrite(STDERR, "batch/cleandocstore.php: `--{$k}` expects fraction between 0 and 1\n");
            exit(1);
        }
    }
}
if (($arg["max-usage"] ?? 1) < ($arg["min-usage"] ?? 0)) {
    fwrite(STDERR, "batch/cleandocstore.php: `--max-usage` cannot be less than `--min-usage`\n");
    exit(1);
}

require_once(SiteLoader::find("src/init.php"));

class Batch_CleanDocstore {
    /** @var list<?DocumentFileTree> */
    public $ftrees = [];

    function fparts_random_match() {
        $fmatches = [];
        for ($i = 0; $i !== count($this->ftrees); ++$i) {
            if (!($ftree = $this->ftrees[$i])) {
                continue;
            }
            $n = 0;
            for ($j = 0;
                 $n < 5 && $j < ($n ? 10 : 10000) && !$ftree->is_empty();
                 ++$j) {
                $fm = $ftree->random_match();
                if ($fm->is_complete()
                    && (($fm->treeid & 1) === 0
                        || max($fm->atime(), $fm->mtime()) < Conf::$now - 86400)) {
                    ++$n;
                    $fmatches[] = $fm;
                } else {
                    $ftree->hide($fm);
                }
            }
            if ($n === 0) {
                $this->ftrees[$i] = null;
            }
        }
        usort($fmatches, function ($a, $b) {
            // week-old temporary files should be removed first
            $at = $a->atime();
            $bt = $b->atime();
            if ($at === false || $bt === false) {
                return $at ? -1 : ($bt ? 1 : 0);
            }
            $aage = Conf::$now - $at;
            if ($a->treeid & 1) {
                $aage = $aage > 604800 ? 100000000 : $aage * 2;
            }
            $bage = Conf::$now - $bt;
            if ($b->treeid & 1) {
                $bage = $bage > 604800 ? 100000000 : $bage * 2;
            }
            return $bage <=> $aage;
        });
        if (empty($fmatches)) {
            return null;
        } else {
            $fm = $fmatches[0];
            $this->ftrees[$fm->treeid]->hide($fm);
            return $fm;
        }
    }

    /** @param DocumentFileTreeMatch $fm
     * @param bool $dry_run */
    private function check_match(Conf $conf, $fm, $dry_run) {
        $doc = new DocumentInfo([
            "sha1" => $fm->algohash,
            "mimetype" => Mimetype::type($fm->extension)
        ], $conf);
        $hashalg = $doc->hash_algorithm();
        if ($hashalg === false) {
            fwrite(STDERR, "{$fm->fname}: unknown hash\n");
            return false;
        }
        if (!$dry_run) {
            $chash = hash_file($hashalg, $fm->fname, true);
            if ($chash === false) {
                fwrite(STDERR, "{$fm->fname}: is unreadable\n");
                return false;
            } else if ($chash !== $doc->binary_hash_data()) {
                fwrite(STDERR, "{$fm->fname}: incorrect hash\n");
                fwrite(STDERR, "  data hash is " . $doc->hash_algorithm_prefix() . bin2hex($chash) . "\n");
                return false;
            }
        }
        if ($doc->check_s3()) {
            return true;
        } else {
            fwrite(STDERR, "{$fm->fname}: not on S3\n");
            return false;
        }
    }

    function run(Conf $conf, $arg) {
        // argument parsing
        $confdp = $conf->docstore();
        if (isset($arg["docstore"])) {
            echo $confdp ? $confdp . "\n" : "";
            return 0;
        } else if (!$confdp) {
            fwrite(STDERR, "batch/cleandocstore.php: Conference doesn't use docstore\n");
            return 1;
        }

        preg_match('{\A((?:/[^/%]*(?=/|\z))+)}', $confdp, $m);
        $usage_directory = $m[1];

        $count = $arg["count"] ?? 10;
        $verbose = isset($arg["verbose"]);
        $dry_run = isset($arg["dry-run"]);
        $keep_temp = isset($arg["keep-temp"]);
        $usage_threshold = null;
        $hash_matcher = new DocumentHashMatcher($arg["match"] ?? null);

        if (isset($arg["max-usage"]) || isset($arg["min-usage"])) {
            $ts = disk_total_space($usage_directory);
            $fs = disk_free_space($usage_directory);
            if ($ts === false || $fs === false) {
                fwrite(STDERR, "$usage_directory: cannot evaluate free space\n");
                return 1;
            } else if ($fs >= $ts * (1 - ($arg["max-usage"] ?? $arg["min-usage"]))) {
                if (!isset($arg["quiet"])) {
                    fwrite(STDOUT, $usage_directory . ": free space sufficient\n");
                }
                return 0;
            }
            $want_fs = $ts * (1 - ($arg["min-usage"] ?? $arg["max-usage"]));
            $usage_threshold = $want_fs - $fs;
            $count = $arg["count"] ?? 5000;
        }

        foreach (array_merge([$confdp], $arg["_"] ?? []) as $i => $dp) {
            if (!str_starts_with($dp, "/") || strpos($dp, "%") === false) {
                fwrite(STDERR, "batch/cleandocstore.php: Bad docstore pattern.\n");
                return 1;
            }
            $this->ftrees[] = new DocumentFileTree($dp, $hash_matcher, count($this->ftrees));
            if (!$keep_temp) {
                $this->ftrees[] = new DocumentFileTree(Filer::docstore_fixed_prefix($dp) . "tmp/%w", $hash_matcher, count($this->ftrees));
            } else {
                $this->ftrees[] = null;
            }
        }

        // actual run
        $ndone = $nsuccess = $bytesremoved = 0;
        while ($count > 0
               && ($usage_threshold === null || $bytesremoved < $usage_threshold)
               && ($fm = $this->fparts_random_match())) {
            if (($fm->treeid & 1) !== 0
                || $this->check_match($conf, $fm, $dry_run)) {
                $size = filesize($fm->fname);
                if ($dry_run || unlink($fm->fname)) {
                    if ($verbose) {
                        fwrite(STDOUT, "{$fm->fname}: " . ($dry_run ? "would remove\n" : "removed\n"));
                    }
                    ++$nsuccess;
                    $bytesremoved += $size;
                } else {
                    fwrite(STDERR, "{$fm->fname}: cannot remove\n");
                }
            }
            --$count;
            ++$ndone;
        }

        if (!isset($arg["quiet"])) {
            fwrite(STDOUT, $usage_directory . ": " . ($dry_run ? "would remove " : "removed ") . plural($nsuccess, "file") . ", " . plural($bytesremoved, "byte") . "\n");
        }
        if ($nsuccess == 0) {
            fwrite(STDERR, "Nothing to delete\n");
        }
        return $nsuccess && $nsuccess == $ndone ? 0 : 1;
    }
}

exit((new Batch_CleanDocstore)->run($Conf, $arg));
