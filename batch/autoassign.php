<?php
// autoassign.php -- HotCRP autoassignment script
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Autoassign_Batch::make_args($argv)->run());
}

class Autoassign_Batch {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var object */
    public $gj;
    /** @var array<string,string> */
    public $param = [];
    /** @var string */
    public $q;
    /** @var string */
    public $t;
    /** @var list<int> */
    public $pcc;
    /** @var list<string> */
    public $users;
    /** @var bool */
    public $quiet;
    /** @var bool */
    public $dry_run;

    function __construct(Contact $user, $arg) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->quiet = isset($arg["quiet"]);
        $this->dry_run = isset($arg["dry-run"]);
        if (!isset($arg["a"])) {
            throw new CommandLineException("An autoassigner must be specified with `-a`.\nValid choices are " . join(", ", array_keys($this->conf->autoassigner_map())));
        } else if (!($gj = $this->conf->autoassigner($arg["a"]))) {
            throw new CommandLineException("No such autoassigner `{$arg["a"]}`");
        } else if (!is_string($gj->function)) {
            throw new CommandLineException("Invalid autoassigner `{$arg["a"]}`");
        }
        $this->gj = $gj;
        $parameters = $this->gj->parameters ?? [];
        if (isset($arg["c"])) {
            $this->param["count"] = $arg["c"];
        }
        if (isset($arg["t"])) {
            $this->param["type"] = $arg["t"];
            if (in_array("rtype", $parameters) && !in_array("type", $parameters)) {
                $this->param["rtype"] = $arg["t"];
            }
        }
        foreach ($arg["_"] as $x) {
            if (($eq = strpos($x, "=")) === false) {
                throw new CommandLineException("Parameter arguments should follow `NAME=VALUE` syntax");
            }
            $this->param[substr($x, 0, $eq)] = substr($x, $eq + 1);
        }

        $this->q = $arg["q"] ?? "";
        if (isset($arg["all"])) {
            $this->t = "all";
        } else if ($this->conf->can_pc_view_incomplete()) {
            $this->t = "act";
        } else {
            $this->t = "s";
        }

        if (empty($arg["u"]) || str_starts_with($arg["u"][0], "-")) {
            $pcc = array_keys($this->conf->pc_members());
        } else {
            $pcc = [];
        }
        foreach ($arg["u"] ?? [] as $utxt) {
            if (($neg = str_starts_with($utxt, "-"))) {
                $utxt = substr($utxt, 1);
            }
            $cs = new ContactSearch(ContactSearch::F_USER | ContactSearch::F_TAG | ContactSearch::F_PC, $utxt, $this->user);
            if ($neg) {
                $pcc = array_diff($pcc, $cs->user_ids());
            } else {
                $pcc = array_unique(array_merge($pcc, $cs->user_ids()));
            }
            $pcc = array_values($pcc);
        }
        $this->pcc = $pcc;
    }

    /** @return int */
    function run() {
        $srch = new PaperSearch($this->user, ["q" => $this->q, "t" => $this->t]);
        if ($srch->has_problem()) {
            fwrite(STDERR, $srch->full_feedback_text());
        }
        $pids = $srch->paper_ids();
        if (empty($pids)) {
            throw new CommandLineException("No papers match that search");
        }

        if (empty($this->pcc)) {
            throw new CommandLineException("No users match those requirements");
        }

        if (str_starts_with($this->gj->function, "+")) {
            $class = substr($this->gj->function, 1);
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            $aa = new $class($this->user, $this->pcc, $pids, $this->param, $this->gj);
        } else {
            $aa = call_user_func($this->gj->function, $this->user, $this->pcc, $pids, $this->param, $this->gj);
        }

        fwrite(STDERR, $aa->full_feedback_text());
        if ($aa->has_error()) {
            return 1;
        }

        $aa->run();

        if (!$aa->has_assignment()) {
            if ($this->quiet) {
                // do nothing
            } else if ($this->dry_run) {
                fwrite(STDOUT, "# No changes\n");
            } else {
                fwrite(STDERR, "Nothing to do\n");
            }
        } else if ($this->dry_run) {
            fwrite(STDOUT, join("", $aa->assignments()));
        } else {
            $assignset = (new AssignmentSet($this->user))->override_conflicts();
            $assignset->parse(join("", $aa->assignments()));
            if ($assignset->has_error()) {
                fwrite(STDERR, $assignset->full_feedback_text());
                return 1;
            } else if ($assignset->is_empty()) {
                if (!$this->quiet) {
                    fwrite(STDERR, "Autoassignment makes no changes\n");
                }
            } else {
                $assignset->execute();
                if (!$this->quiet) {
                    $pids = $assignset->assigned_pids();
                    $pidt = $assignset->numjoin_assigned_pids(", #");
                    fwrite(STDERR, "Assigned "
                        . join(", ", $assignset->assigned_types())
                        . " to " . plural_word($pids, "paper") . " #" . $pidt . "\n");
                }
            }
        }
        return 0;
    }

    /** @return Autoassign_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "dry-run,d Do not perform assignment; output CSV instead.",
            "a:,autoassigner: =AA Use autoassigner AA.",
            "q:,search: =QUERY Use papers matching QUERY.",
            "all Search all papers (default is to search submitted papers).",
            "u[],user[] =USER Include users matching USER (`-USER` excludes).",
            "c:,count: {n} =N Set `count` parameter to N.",
            "t:,type: =TYPE Set `type`/`rtype` parameter to TYPE.",
            "quiet Don’t warn on empty assignment.",
            "help,h !"
        )->description("Run a HotCRP autoassigner.
Usage: php batch/autoassign.php [--dry-run] -a AA [PARAM=VALUE]...")
         ->helpopt("help")
         ->interleave(true)
         ->parse($argv);
        // XXX bad pairs?

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Autoassign_Batch($conf->root_user(), $arg);
    }
}
