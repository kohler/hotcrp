<?php
// rewindreviews.php -- HotCRP review export script
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(RewindReviews_Batch::run_args($argv));
}

class RewindReviews_Batch {
    /** @var Conf */
    public $conf;
    /** @var string */
    public $t;
    /** @var string */
    public $q;
    /** @var ?int */
    public $before;
    /** @var list<bool> */
    public $rfseen;
    /** @var list<string> */
    public $header;
    /** @var list<array> */
    public $output = [];
    /** @var CsvGenerator */
    public $csv;

    /** @param Conf $conf */
    function __construct($conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
    }

    function parse_arg($arg) {
        $this->t = $arg["t"] ?? "s";
        if (!in_array($this->t, PaperSearch::viewable_limits($this->user, $this->t), true)) {
            throw new CommandLineException("No search collection ‘{$this->t}’");
        }
        if (isset($arg["q"]) && !empty($arg["_"])) {
            throw new CommandLineException("Argument conflict with ‘-q’");
        }
        $this->q = $arg["q"] ?? join(" ", $arg["_"] ?? []);
        if (($t = $this->conf->parse_time($arg["before"])) === false) {
            throw new CommandLineException("‘--before’ requires a date");
        }
        $this->before = $t;
    }

    function output($stream) {
        if (!empty($this->output) && !$this->narrow) {
            foreach ($this->conf->all_review_fields() as $f) {
                if ($this->rfseen[$f->order]) {
                    $this->header[] = $f->name;
                }
            }
            $this->csv->set_keys($this->header);
            if (!$this->no_header) {
                $this->csv->set_header($this->header);
            }
            foreach ($this->output as $orow) {
                $this->csv->add_row($orow);
            }
        }

        @fwrite($stream, $this->csv->unparse());
    }

    function run() {
        $search = new PaperSearch($this->user, ["q" => $this->q, "t" => $this->t]);
        if ($search->has_problem()) {
            fwrite(STDERR, $search->full_feedback_text());
        }

        $pset = $this->conf->paper_set(["paperId" => $search->paper_ids()]);
        foreach ($search->sorted_paper_ids() as $pid) {
            $prow = $pset->get($pid);
            $prow->ensure_full_reviews();
            $prow->ensure_reviewer_names();
            $px = [
                "sitename" => $this->conf->opt("confid"),
                "siteclass" => $this->conf->opt("siteclass"),
                "pid" => $prow->paperId
            ];
            if ($this->fields) {
                $this->add_fields($prow, $px);
            }
            foreach ($this->comments ? $prow->viewable_reviews_and_comments($this->user) : $prow->reviews_as_display() as $xrow) {
                if ($xrow instanceof CommentInfo) {
                    if ($this->comments
                        && ($this->all_status || !($xrow->commentType & CommentInfo::CT_DRAFT))) {
                        $this->add_comment($prow, $xrow, $px);
                    }
                } else if ($xrow instanceof ReviewInfo) {
                    if ($this->before !== null) {
                        $xrow = $xrow->version_at($this->before);
                    }
                    if ($this->reviews
                        && $xrow !== null
                        && $xrow->reviewStatus >= ReviewInfo::RS_DRAFTED
                        && ($this->all_status || $xrow->reviewStatus >= ReviewInfo::RS_COMPLETED)) {
                        $this->add_review($prow, $xrow, $px);
                    }
                }
            }
        }
    }

    /** @return int */
    static function run_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "type:,t: =COLLECTION Search COLLECTION “s” (submitted) or “all” [s]",
            "query:,q: =SEARCH",
            "all,a Include all reviews, not just submitted ones",
            "before: =TIME Return reviews as of TIME"
        )->description("Output CSV containing all reviews for papers matching QUERY.
Usage: php batch/rewindreviews.php --before TIME [QUERY...]")
         ->helpopt("help")
         ->parse($argv);

        if (!isset($arg["before"])) {
            throw new CommandLineException("‘--before’ argument required");
        }

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        $fcsv = new RewindReviews_Batch($conf);
        $fcsv->parse_arg($arg);
        $fcsv->prepare($arg);
        $fcsv->output(STDOUT);
        return 0;
    }
}
