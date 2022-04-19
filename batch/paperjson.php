<?php
// paperjson.php -- HotCRP paper export script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(PaperJson_Batch::make_args($argv)->run());
}

class PaperJson_Batch {
    /** @var Contact */
    public $user;
    /** @var PaperSearch */
    public $search;
    /** @var bool */
    public $sitename;
    /** @var bool */
    public $single;
    /** @var bool */
    public $reviews;

    function __construct(Contact $user, $arg) {
        $t = $arg["t"] ?? "s";
        if (!in_array($t, PaperSearch::viewable_limits($user, $t))) {
            throw new CommandLineException("No search collection ‘{$t}’");
        }

        $this->user = $user;
        $this->search = new PaperSearch($user, ["q" => join(" ", $arg["_"]), "t" => $t]);
        $this->sitename = isset($arg["N"]);
        $this->single = isset($arg["1"]);
        $this->reviews = isset($arg["r"]);
    }

    /** @return int */
    function run() {
        $conf = $this->search->conf;
        if ($this->search->has_problem()) {
            fwrite(STDERR, $this->search->full_feedback_text());
        }

        $pj_first = [];
        if ($this->sitename) {
            if ($conf->opt("confid")) {
                $pj_first["sitename"] = $conf->opt("confid");
            }
            if ($conf->opt("siteclass")) {
                $pj_first["siteclass"] = $conf->opt("siteclass");
            }
        }

        $pset = $conf->paper_set([
            "paperId" => $this->search->paper_ids(), "topics" => true, "options" => true
        ]);

        $apj = [];
        $ps = new PaperStatus($conf, $this->user, ["hide_docids" => true]);
        $rf = $conf->review_form();
        foreach ($pset as $prow) {
            $pj1 = $ps->paper_json($prow);
            if ($this->reviews) {
                foreach ($prow->reviews_as_display() as $rrow) {
                    $pj1->reviews[] = $rf->unparse_review_json($this->user, $prow, $rrow);
                }
            }
            if (empty($pj_first)) {
                $apj[] = $pj1;
            } else {
                $apj[] = (object) ($pj_first + (array) $pj1);
            }
        }

        if ($this->single) {
            if (count($apj) > 1) {
                fwrite(STDERR, "batch/paperjson.php: Only printing first match\n");
            }
            if (!empty($apj)) {
                fwrite(STDOUT, json_encode($apj[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            }
        } else {
            fwrite(STDOUT, json_encode($apj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        }
        return 0;
    }

    /** @return PaperJson_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "r,reviews Include reviews in output",
            "N,sitename Include site name and class in output",
            "t:,type: =COLLECTION Search COLLECTION ‘s’ or ‘all’ [submitted]",
            "1,single Output first matching paper rather than an array",
            "help,h"
        )->description("Output a JSON file with papers matching SEARCH.
Usage: php batch/paperjson.php [-t all] [-1] [SEARCH...]")
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new PaperJson_Batch($conf->root_user(), $arg);
    }
}
