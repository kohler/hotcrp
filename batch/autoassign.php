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
    /** @var list<array{int,int}> */
    public $no_coassign = [];
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
        if (!isset($arg["autoassigner"]) && !empty($arg["_"])) {
            $arg["autoassigner"] = array_shift($arg["_"]);
        }
        $aaname = $arg["autoassigner"] ?? "";
        if ($aaname === "help") {
            fwrite(STDOUT, $getopt->help($arg));
            throw new CommandLineException("", $getopt, 0);
        }
        $gj = $aaname !== "" ? $this->conf->autoassigner($aaname) : null;
        if (!$gj) {
            $ml = [];
            if ($aaname === "") {
                $ml[] = MessageItem::error("<0>Autoassigner required");
            } else {
                $ml[] = MessageItem::error("<0>Autoassigner `{$aaname}` not found");
            }
            $ml[] = MessageItem::inform("<0>Valid choices are " . join(", ", self::autoassigner_names($this->conf)) . ".");
            $this->report($ml);
            throw new CommandLineException;
        } else if (!is_string($gj->function)) {
            $this->report([MessageItem::error("<0>Invalid autoassigner `{$aaname}`")]);
            throw new CommandLineException;
        }
        $this->gj = $gj;
        $parameters = $this->gj->parameters ?? [];
        if (isset($arg["count"])) {
            $this->param["count"] = $arg["count"];
        }
        if (isset($arg["type"])) {
            $this->param["type"] = $arg["type"];
            if (in_array("rtype", $parameters) && !in_array("type", $parameters)) {
                $this->param["rtype"] = $arg["type"];
            }
        }
        foreach ($arg["_"] as $x) {
            if (($eq = strpos($x, "=")) === false) {
                $this->report([MessageItem::error("<0>`NAME=VALUE` format expected for parameter arguments")]);
                throw new CommandLineException;
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

        foreach ($arg["disjoint"] ?? [] as $dtxt) {
            if (($comma = strpos($dtxt, ",")) === false
                || ($uid1 = $this->find_pc(substr($dtxt, 0, $comma))) !== null
                || ($uid2 = $this->find_pc(substr($dtxt, $comma + 2))) !== null) {
                $this->report([MessageItem::error("<0>`USER1,USER2` expected for `--disjoint`")]);
                throw new CommandLineException;
            }
            $this->no_coassign[] = [$uid1, $uid2];
        }
    }

    /** @return int */
    private function find_pc($s) {
        $cs = new ContactSearch(ContactSearch::F_USER | ContactSearch::F_PC | ContactSearch::F_USERID, $s, $this->user);
        $uids = $cs->user_ids();
        return count($uids) === 1 ? $uids[0] : null;
    }

    /** @param iterable<MessageItem> $message_list */
    private function report($message_list) {
        fwrite(STDERR, MessageSet::feedback_text($message_list));
    }

    /** @return int */
    function execute() {
        $srch = new PaperSearch($this->user, ["q" => $this->q, "t" => $this->t]);
        if ($srch->has_problem()) {
            $this->report($srch->message_list());
        }
        $pids = $srch->paper_ids();
        if (empty($pids)) {
            $this->report([MessageItem::warning("<0>No papers match that search")]);
            return 1;
        }

        if (empty($this->pcc)) {
            $this->report([MessageItem::error("<0>No users match those requirements")]);
            return 1;
        }

        if (str_starts_with($this->gj->function, "+")) {
            $class = substr($this->gj->function, 1);
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            $aa = new $class($this->user, $this->pcc, $pids, $this->param, $this->gj);
        } else {
            $aa = call_user_func($this->gj->function, $this->user, $this->pcc, $pids, $this->param, $this->gj);
        }

        foreach ($this->no_coassign as $pair) {
            $aa->avoid_coassignment($pair[0], $pair[1]);
        }

        $this->report($aa->message_list());
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
                $this->report([MessageItem::warning("<0>Nothing to do")]);
            }
            return 0;
        }

        if ($this->dry_run) {
            fwrite(STDOUT, join("", $aa->assignments()));
            return 0;
        }

        $assignset = (new AssignmentSet($this->user))->set_override_conflicts(true);
        $assignset->parse(join("", $aa->assignments()));
        if ($assignset->has_error()) {
            $this->report($assignset->message_list());
            return 1;
        } else if ($assignset->is_empty()) {
            if (!$this->quiet) {
                $this->report([MessageItem::warning("<0>Autoassignment made no changes")]);
            }
            return 0;
        }

        $assignset->execute();
        if (!$this->quiet) {
            $pids = $assignset->assigned_pids();
            $pidt = $assignset->numjoin_assigned_pids(", #");
            $this->report([MessageItem::success("<0>Assigned " . join(", ", $assignset->assigned_types()) . " to " . plural_word($pids, "paper") . " #{$pidt}\n")]);
        }
        return 0;
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
        } else {
            return $this->execute();
        }
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
            "dry-run,d Do not perform assignment; output CSV instead",
            "autoassigner:,a: =AA !",
            "q:,search: =QUERY Use papers matching QUERY [all]",
            "all Include all papers (default is submitted papers)",
            "u[],user[] =USER Include users matching USER (`-u -USER` excludes)",
            "disjoint[],X[] =USER1,USER2 Don’t coassign users",
            "count:,c: {n} =N Set `count` parameter to N",
            "type:,t: =TYPE Set `type`/`rtype` parameter to TYPE",
            "help-param Print parameters for AUTOASSIGNER",
            "profile Print profile to standard error",
            "quiet Don’t warn on empty assignment",
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
