<?php /*{hotcrp Autoassign_Batch}*/
// autoassign.php -- HotCRP autoassignment script
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Autoassign_Batch::make_args($argv)->run());
}

class Autoassign_Batch {
    /** @var Conf */
    public $conf;
    /** @var Getopt */
    public $getopt;
    /** @var Contact */
    public $user;
    /** @var string */
    public $aaname;
    /** @var object */
    public $gj;
    /** @var array<string,string> */
    public $param = [];
    /** @var string */
    public $q = "";
    /** @var string */
    public $t;
    /** @var list<list<int>> */
    public $no_coassign = [];
    /** @var list<int> */
    public $pcc;
    /** @var bool */
    private $pcc_set = false;
    /** @var list<string> */
    public $users = [];
    /** @var bool */
    public $quiet = false;
    /** @var bool */
    public $dry_run = false;
    /** @var bool */
    public $minimal_dry_run = false;
    /** @var bool */
    public $help_param = false;
    /** @var bool */
    public $profile = false;
    /** @var bool */
    public $no_force = false;
    /** @var ?callable */
    public $detacher;
    /** @var ?TokenInfo */
    private $_jtok;

    /** @return list<string> */
    static function autoassigner_names(Conf $conf) {
        $aas = [];
        foreach ($conf->autoassigner_map() as $k => $v) {
            if (!str_starts_with($k, "__") && !str_starts_with($k, "\$"))
                $aas[] = $k;
        }
        sort($aas, SORT_NATURAL | SORT_FLAG_CASE);
        return $aas;
    }

    /** @param array<string,mixed> $arg
     * @param ?callable $detacher */
    function __construct(Conf $conf, $arg, Getopt $getopt, $detacher = null) {
        $this->conf = $conf;
        $this->getopt = $getopt;
        $this->detacher = $detacher;
        if (isset($arg["job"])) {
            $this->_jtok = Job_Token::claim($arg["job"], $this->conf, "Autoassign");
            $this->user = $this->_jtok->user() ?? $conf->root_user();
        } else {
            $this->user = $conf->root_user();
        }
        if ($this->conf->can_pc_view_some_incomplete()) {
            $this->t = "active";
        } else {
            $this->t = "s";
        }
        $this->pcc = array_keys($this->conf->pc_members());
        if ($this->_jtok) {
            try {
                $this->_jtok->update_use();
                $this->parse_arg($arg);
                $this->parse_arg($getopt->parse($this->_jtok->input("assign_argv") ?? [], 0));
                $this->complete_arg();
            } catch (CommandLineException $ex) {
                $ml = [];
                if (($s = rtrim($ex->getMessage())) !== "") {
                    $ml[] = MessageItem::error("<0>{$s}");
                }
                $this->report($ml, $ex->exitStatus);
            }
        } else {
            $this->parse_arg($arg);
            $this->complete_arg();
        }
    }

    /** @param iterable<MessageItem> $message_list
     * @param ?int $exit_status */
    private function report($message_list, $exit_status = null) {
        if ($this->_jtok) {
            if (!empty($message_list)) {
                $ml = $this->_jtok->data("message_list") ?? [];
                array_push($ml, ...$message_list);
                $this->_jtok->change_data("message_list", $ml);
            }
            if ($exit_status !== null) {
                $this->_jtok->change_data("exit_status", $exit_status)
                    ->change_data("status", "done");
            }
            $this->_jtok->update_use()->update();
        } else {
            $s = MessageSet::feedback_text($message_list);
            if (($exit_status ?? 0) !== 0) {
                $s .= $this->getopt->short_usage();
            }
            fwrite(STDERR, $s);
        }
        if ($exit_status !== null) {
            throw new CommandLineException("", $this->getopt, $exit_status);
        }
    }

    /** @param iterable<MessageItem> $message_list
     * @param int $exit_status
     * @return never */
    private function reportx($message_list, $exit_status) {
        $this->report($message_list, $exit_status);
        assert(false);
    }

    /** @param string $msg */
    static private function my_error_log($msg) {
        error_log($msg);
        file_put_contents("/tmp/hotcrp.log", $msg, FILE_APPEND);
    }

    /** @param associative-array $arg */
    private function parse_arg($arg) {
        $this->quiet = $this->quiet || isset($arg["quiet"]);
        $this->minimal_dry_run = $this->minimal_dry_run || isset($arg["minimal-dry-run"]);
        $this->dry_run = $this->dry_run || $this->minimal_dry_run || isset($arg["dry-run"]);
        $this->help_param = $this->help_param || isset($arg["help-param"]);
        $this->profile = $this->profile || isset($arg["profile"]);
        $this->no_force = $this->no_force || isset($arg["no-force"]);
        if (isset($arg["autoassigner"])) {
            $this->aaname = $arg["autoassigner"];
        } else if (!empty($arg["_"])) {
            $this->aaname = array_shift($arg["_"]);
        }
        if (isset($arg["count"])) {
            $this->param["count"] = $arg["count"];
        }
        foreach ($arg["_"] ?? [] as $x) {
            if (($eq = strpos($x, "=")) > 0) {
                $this->param[substr($x, 0, $eq)] = substr($x, $eq + 1);
            } else if ($this->aaname === "help") {
                $this->help_param = true;
                $this->aaname = $x;
            } else {
                $this->report([MessageItem::error("<0>`NAME=VALUE` format expected for parameter arguments")], 3);
            }
        }
        $this->q = $arg["q"] ?? $this->q;
        if (isset($arg["all"])) {
            $this->t = "all";
        } else {
            $this->t = $arg["type"] ?? "s";
        }
        $pcc = $this->pcc;
        if (!empty($arg["u"])) {
            if (!str_starts_with($arg["u"][0], "-") && !$this->pcc_set) {
                $pcc = [];
            }
            $this->pcc_set = true;
        }
        foreach ($arg["u"] ?? [] as $utxt) {
            if (($neg = str_starts_with($utxt, "-"))) {
                $utxt = substr($utxt, 1);
            }
            $uids = $this->find_pc($utxt);
            if ($neg) {
                $pcc = array_diff($pcc, $uids);
            } else {
                $pcc = array_unique(array_merge($pcc, $uids));
            }
            $pcc = array_values($pcc);
        }
        $this->pcc = $pcc;
        foreach ($arg["disjoint"] ?? [] as $dtxt) {
            $l = [];
            foreach (explode(",", $dtxt) as $w) {
                $uids = $w === "" ? [] : $this->find_pc($w);
                if (count($uids) > 0) {
                    $l = array_merge($l, $uids);
                } else {
                    $l = [];
                    break;
                }
            }
            if (count($l) >= 2) {
                $this->no_coassign[] = array_values(array_unique($l));
            } else {
                $this->reportx([MessageItem::error("<0>`USER1,USER2[,...]` expected for `--disjoint`")], 3);
            }
        }
    }

    /** @param string $s
     * @return list<int> */
    private function find_pc($s) {
        $cs = new ContactSearch(ContactSearch::F_USER | ContactSearch::F_TAG | ContactSearch::F_PC | ContactSearch::F_USERID, $s, $this->user);
        return $cs->user_ids();
    }

    private function complete_arg() {
        $this->aaname = $this->aaname ?? "";
        if ($this->aaname === "help") {
            fwrite(STDOUT, $this->getopt->help());
            throw new CommandLineException("", $this->getopt, 0);
        }
        $gj = null;
        if ($this->aaname !== "" && !str_starts_with($this->aaname, "__")) {
            $gj = $this->conf->autoassigner($this->aaname);
        }
        if (!$gj) {
            $ml = [];
            if ($this->aaname === "") {
                $ml[] = MessageItem::error("<0>Autoassigner required");
            } else {
                $ml[] = MessageItem::error("<0>Autoassigner `{$this->aaname}` not found");
            }
            $ml[] = MessageItem::inform("<0>Valid choices are " . join(", ", self::autoassigner_names($this->conf)) . ".");
            $this->report($ml, 3);
        } else if (!is_string($gj->function)) {
            $this->report([MessageItem::error("<0>Invalid autoassigner `{$this->aaname}`")], 3);
        }
        $this->gj = $gj;
        if (isset($this->param["type"])) {
            $vos = Autoassigner::expand_parameters($this->conf, $gj->parameters ?? []);
            if (!$vos->has("type") && $vos->has("rtype")) {
                $this->param["rtype"] = $this->param["type"];
            }
        }
        if ($this->_jtok && $this->dry_run) {
            $this->_jtok->change_data("dry_run", true);
        }
    }

    function report_progress($progress) {
        if ($this->_jtok) {
            $this->_jtok->change_data("progress", $progress)
                ->update_use()
                ->update();
        }
        set_time_limit(240);
    }

    /** @return string */
    private function run_autoassigner() {
        // perform search; exit if no papers match
        $srch = new PaperSearch($this->user, ["q" => $this->q, "t" => $this->t]);
        $ml = $srch->message_list();
        $pids = $srch->paper_ids();
        if (empty($pids)) {
            $ml[] = MessageItem::warning("<0>No papers match that search");
            $this->report($ml, 1);
        } else if (empty($this->pcc)) {
            $ml[] = MessageItem::error("<0>No users match those requirements");
            $this->report($ml, 1);
        } else if ($srch->has_problem()) {
            $this->report($ml);
        }

        // construct autoassigner
        if (str_starts_with($this->gj->function, "+")) {
            $class = substr($this->gj->function, 1);
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            $aa = new $class($this->user, $this->gj);
        } else {
            $aa = call_user_func($this->gj->function, $this->user, $this->gj);
        }
        '@phan-var-force Autoassigner $aa';
        foreach ($this->no_coassign as $l) {
            $n = count($l);
            for ($i = 0; $i < $n - 1; ++$i) {
                for ($j = $i + 1; $j < $n; ++$j) {
                    $aa->avoid_coassignment($l[$i], $l[$j]);
                }
            }
        }
        foreach ($this->param as $k => $v) {
            $aa->set_option($k, $v);
        }
        $aa->set_user_ids($this->pcc);
        $aa->set_paper_ids($pids);
        $aa->configure();

        // report error or messages
        if ($aa->has_error()) {
            $this->change_data("valid", false);
            $this->report($aa->message_list(), 1);
        }
        $this->report($aa->message_list());

        // run autoassigner
        if ($this->detacher) {
            call_user_func($this->detacher, $this);
            $this->detacher = null;
        }
        $aa->add_progress_function([$this, "report_progress"]);
        $aa->run();

        if ($this->profile) {
            fwrite(STDERR, json_encode($aa->profile) . "\n");
        }

        // save assignment types and incomplete pids to token
        if ($this->_jtok && ($pids = $aa->incompletely_assigned_paper_ids())) {
            $this->_jtok->change_data("incomplete_pids", $pids); // will save soon
        }

        return join("", $aa->assignments());
    }

    private function set_output($text) {
        if ($this->_jtok) {
            $this->_jtok->set_output($text, "text/csv");
        } else {
            fwrite(STDOUT, $text);
        }
    }

    private function change_data($key, $value) {
        if ($this->_jtok) {
            $this->_jtok->change_data($key, $value);
        }
    }

    function execute() {
        // set initial properties
        $this->change_data("valid", true);

        // run autoassigner
        $aatext = $this->run_autoassigner();

        // exit if nothing to do
        if ($aatext === "") {
            if ($this->_jtok || (!$this->quiet && !$this->dry_run)) {
                $this->report([MessageItem::warning("<0>No changes")], 0);
            } else if (!$this->quiet) {
                fwrite(STDOUT, "# No changes\n");
            }
            return;
        }

        // exit if minimal dry run
        if ($this->minimal_dry_run) {
            $this->set_output($aatext);
            $this->report([], 0);
        }

        // parse assignment
        $aset = (new AssignmentSet($this->user))
            ->set_override_conflicts(!$this->no_force);
        $aset->parse($aatext);
        $this->report($aset->message_list());
        $aatext = "";  // reclaim memory

        // exit if error
        if ($aset->has_error()) {
            $this->change_data("valid", false);
            $this->report([], 1);
        }

        // exit if empty
        if ($aset->is_empty()) {
            $ml = $this->quiet ? [] : [MessageItem::warning("<0>No changes")];
            $this->report($ml, 0);
        }

        // mark assigned pids
        if ($this->_jtok) {
            $this->change_data("assignment_actions", $aset->assigned_types());
            $this->change_data("assignment_pids", $aset->assigned_pids());
        }

        // exit if dry run
        if ($this->dry_run) {
            $this->change_data("dry_run", true);
            $this->set_output($aset->make_acsv()->unparse());
            $this->report([], 0);
        }

        // execute assignment
        $aset->execute();
        $this->change_data("assigned", true);
        $ml = [];
        if (!$this->quiet) {
            $ml[] = MessageItem::success($this->conf->_(
                "<0>Assigned {types:list} to {submission} {pids:numlist#}",
                new FmtArg("types", $aset->assigned_types()),
                new FmtArg("pids", $aset->assigned_pids())
            ));
        }
        $this->set_output($aset->make_acsv()->unparse());
        $this->report($ml, 0);
    }

    /** @return int */
    function run() {
        if ($this->help_param) {
            $s = ["{$this->gj->name}:\n"];
            if ($this->gj->description) {
                $s[] = "  " . Ftext::as(0, $this->gj->description, 0) . "\n";
            }
            $s[] = "\nParameters:\n";
            $vos = Autoassigner::expand_parameters($this->conf, $this->gj->parameters ?? []);
            foreach ($vos->help_order() as $vot) {
                $s[] = $vot->unparse_help_line();
            }
            $s[] = "\n";
            fwrite(STDOUT, join("", $s));
        } else {
            $this->execute();
        }
        return 0;
    }

    static function helpcallback($arg, $getopt) {
        SiteLoader::read_main_options($arg["config"] ?? null, $arg["name"] ?? null);
        return prefix_word_wrap("  ", "Autoassigners are " . join(", ", self::autoassigner_names(new Conf(null, false))) . ".", 2);
    }

    /** @return Getopt */
    static function make_getopt() {
        return (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "job:,j: JOBID Run stored job",
            "dry-run,d Do not perform assignment; output CSV instead",
            "minimal-dry-run,unsorted-dry-run,D !",
            "autoassigner:,a: =AUTOASSIGNER !",
            "q:,search: =QUERY Use papers matching QUERY [all]",
            "type:,t: =TYPE Set search type [s]",
            "all Include all papers (default is submitted papers)",
            "u[],user[] =USER Include users matching USER (`-u -USER` excludes)",
            "disjoint[],X[] =USER1,USER2 Don’t coassign users",
            "count:,c: {n} =N Set `count` parameter to N",
            "no-force Do not override conflicts",
            "help-param Print parameters for AUTOASSIGNER",
            "profile Print profile to standard error",
            "quiet Don’t warn on empty assignment",
            "help,h !"
        )->description("Run a HotCRP autoassigner.
Usage: php batch/autoassign.php [--dry-run] AUTOASSIGNER [PARAM=VALUE]...")
         ->helpopt("help")
         ->helpcallback("Autoassign_Batch::helpcallback")
         ->interleave(true);
    }

    /** @param list<string> $argv
     * @param ?callable $detacher
     * @return Autoassign_Batch */
    static function make_args($argv, $detacher = null) {
        $getopt = self::make_getopt();
        $arg = $getopt->parse($argv);
        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Autoassign_Batch($conf, $arg, $getopt, $detacher);
    }
}
