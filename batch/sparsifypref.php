<?php
// sparsifypref.php -- HotCRP command-line search script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(SparsifyPref_Batch::make_args($argv)->run());
}

class SparsifyPref_Batch {
    /** @var CsvParser */
    private $csvp;
    /** @var list<CsvRow> */
    private $rows;
    /** @var int */
    private $plimit;
    /** @var int */
    private $ulimit;
    /** @var bool */
    private $renumber;

    function __construct($arg) {
        $this->plimit = $arg["p"] ?? PHP_INT_MAX;
        $this->ulimit = $arg["u"] ?? PHP_INT_MAX;
        $this->renumber = !isset($arg["no-renumber"]);

        $fname = $arg["_"][0] ?? "-";
        if ($fname !== "-") {
            if (!($f = @fopen($fname, "rb"))) {
                throw new CommandLineException("{$fname}: Cannot open file");
            }
        } else {
            $f = STDIN;
            $fname = "<stdin>";
        }

        $this->csvp = $csvp = new CsvParser($f);
        $req = $csvp->peek_list();
        if (empty($req)) {
            throw new CommandLineException("Empty file");
        }
        $epid = $eaction = $euser = $epref = null;
        foreach ($req as $i => $t) {
            if (in_array($t, ["pid", "paperid", "paper_id", "paper", "action", "email", "user", "preference", "pref", "revpref"], true)) {
                $csvp->set_header($req);
                $csvp->next_list();
                break;
            } else if (ctype_digit($t) && $epid === null) {
                $epid = $i;
            } else if (is_numeric($t)) {
                $epref = $i;
            } else if (strpos($t, "@") !== false) {
                $euser = $i;
            } else if ($t === "pref" || $t === "preference") {
                $eaction = $i;
            }
        }
        if (!$csvp->header()) {
            if ($epid === null || $epref === null || $euser === null) {
                throw new CommandLineException("Need CSV header");
            }
            $hdr = [];
            for ($i = 0; $i !== count($req); ++$i) {
                $hdr[] = "f{$i}";
            }
            $hdr[$epid] = "pid";
            $hdr[$epref] = "preference";
            $hdr[$euser] = "email";
            if ($eaction !== null) {
                $hdr[$eaction] = "action";
            }
            $csvp->set_header($hdr);
        }

        $csvp->add_synonym("paper", "pid", "paperid", "paper_id");
        $csvp->add_synonym("email", "user");
        $csvp->add_synonym("preference", "pref", "revpref");
        $pcol = $csvp->column("paper");
        $ecol = $csvp->column("email");
        $pfcol = $csvp->column("preference");

        while (($row = $csvp->next_row())) {
            $pv = trim($row[$pcol]);
            $ev = trim($row[$ecol]);
            $pfv = trim($row[$pfcol]) ? : "0";
            if (!ctype_digit($pv) || $pv === "0") {
                fwrite(STDERR, "{$fname}:{$csvp->lineno()}: Bad `paper`\n");
            } else if (!is_numeric($pfv)) {
                fwrite(STDERR, "{$fname}:{$csvp->lineno()}: Bad `preference`\n");
            } else {
                $row[$pcol] = (int) $pv;
                $row[$ecol] = $ev;
                $row[$pfcol] = (float) $pfv;
                $this->rows[] = $row;
            }
        }
    }

    private function strip($col1, $col2, $max) {
        usort($this->rows, function ($a, $b) use ($col1, $col2) {
            return $a[$col1] <=> $b[$col1] ? : $b[$col2] <=> $a[$col2];
        });
        $i = 0;
        while ($i !== count($this->rows)) {
            $match = $this->rows[$i][$col1];
            $j = $i + 1;
            while ($j !== count($this->rows)
                   && $this->rows[$j][$col1] === $match) {
                ++$j;
            }
            if ($j - $i <= $max) {
                $i = $j;
                continue;
            }
            // randomize selection among equal preferences
            if ($max > 0
                && $this->rows[$i + $max - 1][$col2] == $this->rows[$i + $max][$col2]) {
                $k = $i + $max - 1;
                $l = $i + $max + 1;
                $endpref = $this->rows[$k][$col2];
                while ($k > $i
                       && $this->rows[$k - 1][$col2] == $endpref) {
                    --$k;
                }
                while ($l < $j
                       && $this->rows[$l][$col2] === $endpref) {
                    ++$l;
                }
                for ($x = $k; $x < $i + $max; ++$x) {
                    $y = mt_rand($x, $l - 1);
                    $t = $this->rows[$x];
                    $this->rows[$x] = $this->rows[$y];
                    $this->rows[$y] = $t;
                }
            }
            array_splice($this->rows, $i + $max, $j - ($i + $max));
            $j = $i + $max;
            $i = $j;
        }
    }

    private function renumber() {
        $ucol = $this->csvp->column("email");
        $pfcol = $this->csvp->column("preference");
        usort($this->rows, function ($a, $b) use ($ucol, $pfcol) {
            return $a[$ucol] <=> $b[$ucol] ? : $a[$pfcol] <=> $b[$pfcol];
        });
        $i = 0;
        $n = count($this->rows);
        while ($i !== $n) {
            $match = $this->rows[$i][$ucol];
            $lowpref = floatval($this->rows[$i][$pfcol]);
            $delta = $lowpref > 0 ? 0 : -$lowpref + 1;
            while ($i !== $n && $this->rows[$i][$ucol] === $match) {
                if ($delta > 0) {
                    $this->rows[$i][$pfcol] += $delta;
                }
                ++$i;
            }
        }
    }

    /** @return int */
    function run() {
        $this->strip($this->csvp->column("paper"),
            $this->csvp->column("preference"),
            $this->plimit);
        $this->strip($this->csvp->column("email"),
            $this->csvp->column("preference"),
            $this->ulimit);
        if ($this->renumber) {
            $this->renumber();
        }

        $csvg = (new CsvGenerator)->set_header($this->csvp->header())
            ->set_stream(STDOUT);
        foreach ($this->rows as $r) {
            $csvg->add_row($r->as_list());
        }
        $csvg->flush();
        return 0;
    }

    /** @return SparsifyPref_Batch */
    static function make_args($argv) {
        global $Opt;
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config:,c: !",
            "p:,paper: =N {n} Keep at most N preferences per paper",
            "u:,user: =N {n} Keep at most N preferences per user",
            "no-renumber,N Do not renumber preferences to be greater than zero",
            "help,h"
        )->helpopt("help")
         ->description("Sparsify a HotCRP preference file provided as CSV.
Usage: php batch/sparsifypref.php [-p N] [-u N] [FILE] > FILE")
         ->maxarg(1)
         ->parse($argv);

        $Opt["__no_main"] = true;
        initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new SparsifyPref_Batch($arg);
    }
}
