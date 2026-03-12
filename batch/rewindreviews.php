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
    /** @var Contact */
    public $user;
    /** @var string */
    public $t;
    /** @var string */
    public $q;
    /** @var ?int */
    public $before;
    /** @var bool */
    public $earliest;

    /** @param Conf $conf */
    function __construct($conf, $arg) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
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
        $this->earliest = isset($arg["earliest"]);
    }

    function run() {
        $search = new PaperSearch($this->user, ["q" => $this->q, "t" => $this->t]);
        if ($search->has_problem()) {
            fwrite(STDERR, $search->full_feedback_text());
        }
        $revterm = ($search->main_term()->about() & SearchTerm::ABOUT_REVIEW)
            ? $search->main_term() : null;

        $pset = $this->conf->paper_set(["paperId" => $search->paper_ids()]);
        $rf = $this->conf->review_form();
        $stager = Dbl::make_multi_qe_stager($this->conf->dblink);
        foreach ($search->sorted_paper_ids() as $pid) {
            $prow = $pset->get($pid);
            $prow->ensure_full_reviews();
            $prow->ensure_reviewer_names();
            foreach ($prow->reviews_as_list() as $rrow) {
                if ($revterm && !$revterm->test($prow, $rrow)) {
                    continue;
                }
                $xrow = $rrow->version_at($this->before, $this->earliest);
                if (!$xrow || $xrow === $rrow) {
                    continue;
                }
                foreach ($rf->all_fields() as $f) {
                    $rrow->set_fval_prop($f, $xrow->finfoval($f), true);
                }
                if ($rrow->prop_changed()) {
                    $rrow->save_prop($stager);
                }
            }
        }
        $stager(null);
        return 0;
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
            "before: =TIME Return reviews as of TIME",
            "earliest,e Select earliest review in case of tie"
        )->description("Output CSV containing all reviews for papers matching QUERY.
Usage: php batch/rewindreviews.php --before TIME [QUERY...]")
         ->helpopt("help")
         ->parse($argv);

        if (!isset($arg["before"])) {
            throw new CommandLineException("‘--before’ argument required");
        }

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        $bp = new RewindReviews_Batch($conf, $arg);
        return $bp->run();
    }
}
