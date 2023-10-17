<?php
// rank.php -- HotCRP script for CSV access to PaperRank
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    date_default_timezone_set("GMT");
    exit(Rank_Batch::make_args($argv)->run());
}

class Rank_Batch {
    /** @var ?resource */
    public $in;
    /** @var resource */
    public $out = STDOUT;
    /** @var string */
    public $method;
    /** @var bool */
    public $gapless = true;
    /** @var bool */
    public $header = true;
    /** @var string */
    public $user_key = "user";
    /** @var string */
    public $item_key = "item";
    /** @var string */
    public $rank_key = "rank";
    /** @var ?string */
    public $ballot_key;
    /** @var int */
    public $user_idx = -1;
    /** @var int */
    public $item_idx = -1;
    /** @var int */
    public $rank_idx = -1;
    /** @var int */
    public $ballot_idx = -1;

    /** @param array<string,mixed> $arg */
    function __construct($arg) {
        if (isset($arg["m"])) {
            if (in_array($arg["m"], PaperRank::method_list())) {
                $this->method = $arg["m"];
            } else {
                throw new CommandLineException("No such `--method`");
            }
        } else {
            $this->method = (PaperRank::method_list())[0];
        }

        if (!empty($arg["_"])) {
            if (isset($arg["i"])) {
                throw new CommandLineException("Too many arguments");
            }
            $ifn = $arg["_"][0];
        } else if (isset($arg["i"])) {
            $ifn = $arg["i"];
        } else {
            $ifn = "-";
        }
        if ($ifn === "-") {
            $this->in = STDIN;
        } else {
            $this->in = @fopen($ifn, "rb");
        }
        if ($this->in === false) {
            throw error_get_last_as_exception("{$ifn}: ");
        }

        if (isset($arg["o"])) {
            $this->out = @fopen($arg["o"], "wb");
            if ($this->out === false) {
                throw error_get_last_as_exception($arg["o"] . ": ");
            }
        }

        if (isset($arg["gaps"])) {
            $this->gapless = false;
        }
        if (isset($arg["no-header"])) {
            $this->header = false;
            $this->user_idx = 0;
            $this->item_idx = 1;
            $this->rank_idx = 2;
        }
        foreach (["user", "item", "rank"] as $k) {
            if (($v = $arg["{$k}-key"] ?? null) !== null) {
                if (ctype_digit($v)) {
                    $this->{"{$k}_idx"} = intval($v);
                } else if ($this->header) {
                    $this->{"{$k}_key"} = $v;
                } else {
                    throw new CommandLineException("Given `--no-header`, `--{$k}-key` must be an integer");
                }
            }
        }
        if (($v = $arg["ballot"] ?? null) !== null) {
            if ($v === false) {
                $this->ballot_idx = 0;
            } else if (ctype_digit($v)) {
                $this->ballot_idx = intval($v);
            } else {
                $this->ballot_key = $v;
            }
        }
    }

    /** @return array{list<string>,list<array{int,int,int|float}>} */
    private function read_ranks() {
        $csv = new CsvParser($this->in);
        $row = $csv->next_row();
        if ($this->header) {
            $csv->set_header($row);
            foreach (["user", "item", "rank"] as $k) {
                if ($this->{"{$k}_idx"} >= 0)
                    $this->{"{$k}_key"} = $row[$this->{"{$k}_idx"}];
            }
            $row = $csv->next_row();
        }
        foreach (["user", "item", "rank"] as $k) {
            if ($this->{"{$k}_idx"} < 0
                && ($this->{"{$k}_idx"} = $csv->column($this->{"{$k}_key"})) < 0) {
                throw new CommandLineException(ucfirst($k) . " key `" . $this->{"{$k}_key"} . "` not in input");
            }
        }
        if ($this->user_idx === $this->rank_idx
            || $this->user_idx === $this->item_idx
            || $this->item_idx === $this->rank_idx) {
            throw new CommandLineException("`--user-key`, `--item-key`, and `--rank-key` must differ");
        }

        $ranklist = $umap = $imap = [];
        $ilist = [""];
        $nu = $ni = 1;

        while ($row) {
            $u = $row[$this->user_idx];
            $i = $row[$this->item_idx];
            $r = $row[$this->rank_idx];
            if ($u !== null && ($ui = $umap[$u] ?? null) === null) {
                $umap[$u] = $ui = $nu;
                ++$nu;
            }
            if ($i !== null && ($ii = $imap[$i] ?? null) === null) {
                $imap[$i] = $ii = $ni;
                $ilist[] = $i;
                ++$ni;
            }
            if ($u !== null && $i !== null && is_numeric($r)) {
                $ranklist[] = [$ui, $ii, floatval($r)];
            }
            $row = $csv->next_row();
        }

        return [$ilist, $ranklist];
    }

    /** @return array{list<string>,list<array{int,int,int|float}>} */
    private function read_ballot() {
        $csv = new CsvParser($this->in);
        $row = $csv->next_row();
        if ($this->header) {
            $csv->set_header($row);
            if ($this->ballot_idx >= 0) {
                $this->ballot_key = $row[$this->ballot_idx];
            }
            $row = $csv->next_row();
        }
        if ($this->ballot_idx < 0
            && ($this->ballot_idx = $csv->column($this->ballot_key)) < 0) {
            throw new CommandLineException("Ballot key `{$this->ballot_key}` not in input");
        }

        $ranklist = $imap = [];
        $ilist = [""];
        $nu = $ni = 1;

        while ($row) {
            $b = $row[$this->ballot_idx];
            if ($b !== null) {
                if (($comma = strpos($b, ",") !== false)) {
                    $bx = preg_split('/\s*,\s*/', $b);
                } else {
                    $bx = preg_split('/\s+/', trim($b));
                }
                if (!empty($bx)) {
                    $r = 1;
                    foreach ($bx as $i) {
                        if ($i !== "" && $i !== "-" && $i !== "_" && $i !== ".") {
                            if (($ii = $imap[$i] ?? null) === null) {
                                $imap[$i] = $ii = $ni;
                                $ilist[] = $i;
                                ++$ni;
                            }
                            $ranklist[] = [$nu, $ii, $r];
                        }
                        if ($i !== "" || $comma) {
                            ++$r;
                        }
                    }
                }
            }
            $row = $csv->next_row();
        }

        return [$ilist, $ranklist];
    }

    /** @return int */
    function run() {
        if ($this->ballot_idx >= 0 || $this->ballot_key !== null) {
            list($ilist, $ranklist) = $this->read_ballot();
        } else {
            list($ilist, $ranklist) = $this->read_ranks();
        }

        if (empty($ranklist)) {
            throw new CommandLineException("Nothing to do");
        }

        $rank = new PaperRank(range(1, count($ilist) - 1));
        $rank->set_gapless($this->gapless);
        foreach ($ranklist as $rx) {
            $rank->add_rank($rx[0], $rx[1], $rx[2]);
        }
        $rank->run($this->method);

        $rr = [];
        foreach ($rank->rank_map() as $ii => $ri) {
            $rr[] = [$ilist[$ii], $ri];
        }
        usort($rr, function ($a, $b) {
            if ($a[1] != $b[1]) {
                return $a[1] < $b[1] ? -1 : 1;
            } else {
                return strnatcasecmp($a[0], $b[0]);
            }
        });

        $csvg = (new CsvGenerator)->set_stream($this->out);
        if ($this->header) {
            $csvg->add_row([$this->item_key, $this->rank_key]);
        }
        /** @phan-suppress-next-line PhanTypeMismatchArgument */
        $csvg->append($rr);
        $csvg->flush();
        return 0;
    }

    /** @return Rank_Batch */
    static function make_args($argv) {
        $getopt = new Getopt;
        $arg = $getopt->long(
            "i:,input:,in: =CSV Read input from file",
            "o:,output:,out: =CSV Send output to file",
            "m:,method: =METHOD Set rank method [" . (PaperRank::method_list())[0] . "]",
            "gaps Include gaps in ranking",
            "no-header,N CSV does not have header",
            "user-key:,U:,uk: =KEY Set user key for CSV [user]",
            "item-key:,I:,ik: =KEY Set item key for CSV [item]",
            "rank-key:,R:,rk: =KEY Set rank key for CSV [rank]",
            "ballot::,ballot-key::,B::,bk:: =KEY Set ballot key for CSV",
            "help,h Print help"
        )->description("Run HotCRP rank algorithm on CSV input.
Usage: php batch/rank.php [-m METHOD] < CSV > CSV")
         ->helpopt("help")
         ->maxarg(1)
         ->parse($argv);
        return new Rank_Batch($arg);
    }
}
