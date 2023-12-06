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
    /** @var bool */
    public $help_param;
    /** @var bool */
    public $profile;

    /** @return list<string> */
    static function autoassigner_names(Conf $conf) {
        $aas = array_keys($conf->autoassigner_map());
        sort($aas, SORT_NATURAL | SORT_FLAG_CASE);
        return $aas;
    }

    function __construct(Contact $user, $arg, Getopt $getopt) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->quiet = isset($arg["quiet"]);
        $this->dry_run = isset($arg["dry-run"]);
        $this->help_param = isset($arg["help-param"]);
        $this->profile = isset($arg["profile"]);
        if (!isset($arg["a"]) && !empty($arg["_"])) {
            $arg["a"] = array_shift($arg["_"]);
        }
        $aaname = $arg["a"] ?? "";
        if ($aaname === "help") {
            fwrite(STDOUT, $getopt->help($arg));
            throw new CommandLineException("", $getopt, 0);
        }
        $gj = $aaname !== "" ? $this->conf->autoassigner($aaname) : null;
        if (!$gj) {
            throw (new CommandLineException($aaname === "" ? "Autoassigner not specified" : "Autoassigner `{$aaname}` not found"))->add_context("Valid choices are " . join(", ", self::autoassigner_names($this->conf)) . ".");
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
        } else if ($this->conf->can_pc_view_some_incomplete()) {
            $this->t = "active";
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
        if ($this->help_param) {
            $s = ["{$this->gj->name} parameters:\n"];
            foreach ($this->gj->parameters ?? [] as $p) {
                if (($px = Autoassigner::expand_parameter_help($p))) {
                    $arg = "  {$px->name}={$px->argname}" . ($px->required ? " *" : "");
                    $s[] = Getopt::format_help_line($arg, $px->description);
                }
            }
            $s[] = "\n";
            fwrite(STDOUT, join("", $s));
            return 0;
        }

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

        if ($this->profile) {
            fwrite(STDERR, json_encode($aa->profile) . "\n");
        }

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
            $assignset = (new AssignmentSet($this->user))->set_override_conflicts(true);
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

    static function helpcallback($arg, $getopt) {
        SiteLoader::read_main_options($arg["config"] ?? null, $arg["name"] ?? null);
        return prefix_word_wrap("  ", "Autoassigners are " . join(", ", self::autoassigner_names(new Conf(null, false))) . ".", 2);
    }

    /** @return Autoassign_Batch */
    static function make_args($argv) {
        $getopt = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "dry-run,d Do not perform assignment; output CSV instead.",
            "a:,autoassigner: =AA !",
            "q:,search: =QUERY Use papers matching QUERY [all].",
            "all Include all papers (default is submitted papers).",
            "u[],user[] =USER Include users matching USER (`-u -USER` excludes).",
            "c:,count: {n} =N Set `count` parameter to N.",
            "t:,type: =TYPE Set `type`/`rtype` parameter to TYPE.",
            "help-param Print parameters for AUTOASSIGNER.",
            "profile Print profile to standard error.",
            "quiet Don’t warn on empty assignment.",
            "help,h !"
        )->description("Run a HotCRP autoassigner.
Usage: php batch/autoassign.php [--dry-run] AUTOASSIGNER [PARAM=VALUE]...")
         ->helpopt("help")
         ->helpcallback("Autoassign_Batch::helpcallback")
         ->interleave(true);
        $arg = $getopt->parse($argv);
        // XXX bad pairs?

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Autoassign_Batch($conf->root_user(), $arg, $getopt);
    }
}
