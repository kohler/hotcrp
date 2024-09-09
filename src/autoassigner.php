<?php
// autoassigner.php -- HotCRP helper classes for autoassignment
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class AutoassignerElement {
    /** @var int */
    public $cid;
    /** @var int */
    public $pid;
    /** @var ?int */
    public $pref;
    /** @var ?int */
    public $exp;
    /** @var ?int */
    public $topicscore;
    /** @var null|int|float */
    public $cpref;
    /** @var int */
    public $pref_index = -1;
    /** @var int */
    public $eass = 0;

    /** @param int $cid
     * @param int $pid */
    function __construct($cid, $pid) {
        $this->cid = $cid;
        $this->pid = $pid;
    }
}

class AutoassignerUser {
    /** @var int
     * @readonly */
    public $cid;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var int */
    public $load = 0;
    /** @var int */
    public $max_load = PHP_INT_MAX;
    /** @var int|float */
    public $balance = 0;
    /** @var int */
    public $unhappiness = 0;
    /** @var int */
    public $pref_index_bound = -1;
    /** @var ?list<int> */
    public $work_list;
    /** @var int */
    public $ndesired = -1;
    /** @var int */
    public $nassigned = 0;

    /** @param Contact $user */
    function __construct(Contact $user) {
        assert($user->contactId > 0);
        $this->cid = $user->contactId;
        $this->user = $user;
    }
    /** @return bool */
    function work_list_complete() {
        return ($this->ndesired >= 0 && $this->nassigned >= $this->ndesired)
            || $this->load >= $this->max_load
            || $this->work_list === [];
    }
}

class AutoassignerPaper {
    /** @var int
     * @readonly */
    public $pid;
    /** @var int */
    public $ndesired = -1;
    /** @var int */
    public $nassigned = 0;
    /** @var bool */
    public $balance = false;
    /** @var list<AutoassignerElement> */
    public $ainfo = [];

    /** @param int $pid */
    function __construct($pid) {
        $this->pid = $pid;
    }
    /** @param int $min
     * @param ?int $max
     * @return int */
    function assignment_count($min, $max = null) {
        $max = $max ?? $min;
        $ct = 0;
        foreach ($this->ainfo as $ae) {
            if ($ae->eass >= $min && $ae->eass <= $max)
                ++$ct;
        }
        return $ct;
    }
}

class AutoassignerComputed {
}

class AutoassignerParameter {
    /** @var string */
    public $name;
    /** @var bool */
    public $required;
    /** @var string */
    public $argname;
    /** @var string */
    public $description;

    function __construct($name, $required, $argname, $description) {
        $this->name = $name;
        $this->required = $required;
        $this->argname = $argname;
        $this->description = $description;
    }
}

abstract class Autoassigner extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var array<int,AutoassignerUser>
     * @readonly */
    private $acs = [];
    /** @var array<int,AutoassignerPaper>
     * @readonly */
    private $aps = [];
    /** @var array<int,list<int>> */
    private $avoid_lists = [];
    /** @var bool */
    private $need_process_avoid_lists = false;
    /** @var array<int,array<int,AutoassignerElement>> */
    private $ainfo = [];
    /** @var list<string> */
    protected $ass = [];
    /** @var string */
    protected $ass_action = "";
    /** @var array<string,string|AutoassignerComputed> */
    private $ass_extra = [];
    /** @var bool */
    private $has_pref_index = false;
    /** @var int */
    protected $method = self::METHOD_MCMF;
    /** @var int */
    protected $balance = self::BALANCE_NEW;
    /** @var int */
    protected $review_gadget;
    /** @var int */
    public $assignment_cost = 100;
    /** @var int */
    public $paper_assignment_cost = 40;
    /** @var ?int */
    public $remove_assignment_cost = 60;
    /** @var int */
    public $preference_cost = 60;
    /** @var int */
    public $expertise_x_cost = -200;
    /** @var int */
    public $expertise_y_cost = -140;
    private $progressf = [];
    /** @var ?MinCostMaxFlow */
    protected $mcmf;
    /** @var ?string */
    protected $mcmf_round_descriptor; // for use in MCMF progress
    /** @var ?string */
    protected $mcmf_optimizing_for; // for use in MCMF progress
    /** @var ?float */
    protected $mcmf_max_cost;
    /** @var ?int */
    private $ndesired = -2;
    /** @var int */
    protected $nassigned = 0;
    /** @var array<string,int|float> */
    public $profile = ["maxflow" => 0, "mincost" => 0];

    const METHOD_MCMF = 0;
    const METHOD_RANDOM = 1;
    const METHOD_STUPID = 2;

    const BALANCE_NEW = 0;
    const BALANCE_ALL = 1;

    const REVIEW_GADGET_PLAIN = 0;
    const REVIEW_GADGET_EXPERTISE = 1;

    const EAVOIDASSIGN = 1;
    const ENOASSIGN = 2;
    const EOTHERASSIGN = 3; // order matters
    const EOLDASSIGN = 4;
    const ENEWASSIGN = 5;
    // EOTHERASSIGN: Prevents assignment, does not count for ensure
    // EOLDASSIGN: Prevents assignment, counts for ensure
    // ENEWASSIGN: Possibly reassignable, counts for ensure

    /** @var array<string,?int> */
    static private $known_costs = [
        "assignment" => 1, "paper_assignment" => 1, "remove_assignment" => null,
        "preference" => 1, "expertise_x" => null, "expertise_y" => null
    ];

    /** @param ?list<int> $pcids
     * @param list<int> $papersel */
    function __construct(Contact $user, $pcids, $papersel) {
        $this->conf = $user->conf;
        $this->user = $user;
        $pcids = $pcids ?? array_keys($this->conf->pc_members());
        foreach ($pcids as $cid) {
            if (($p = $this->conf->pc_member_by_id($cid))) {
                $this->acs[$cid] = new AutoassignerUser($p);
            }
        }
        foreach ($papersel as $pid) {
            $this->aps[$pid] = new AutoassignerPaper($pid);
        }
        if ($this->conf->opt("autoassignReviewGadget") === "expertise") {
            $this->review_gadget = self::REVIEW_GADGET_EXPERTISE;
        } else {
            $this->review_gadget = self::REVIEW_GADGET_PLAIN;
        }
        if (($jc = json_decode_object($this->conf->opt("autoassignCosts")))) {
            foreach (self::$known_costs as $n => $mv) {
                if (isset($jc->$n))
                    $this->{"{$n}_cost"} = $jc->$n;
            }
        }
    }

    /** @param int $cid1
     * @param int $cid2 */
    function avoid_coassignment($cid1, $cid2) {
        assert($cid1 > 0 && $cid2 > 0);
        if ($cid1 !== $cid2
            && !in_array($cid2, $this->avoid_lists[$cid1] ?? [])) {
            $this->avoid_lists[$cid1][] = $cid2;
            $this->avoid_lists[$cid2][] = $cid1;
        }
    }

    /** @param array<string,mixed> $args
     * @param string $field
     * @return ?non-empty-string */
    function extract_tag($args, $field) {
        $tag = trim((string) ($args[$field] ?? ""));
        if ($tag === "") {
            return null;
        }
        $tagger = new Tagger($this->user);
        $tag = $tagger->check($tag, Tagger::NOVALUE | Tagger::ALLOWNONE);
        if ($tag !== false) {
            return $tag;
        } else {
            $this->error_at($field, $tagger->error_ftext(true));
            return null;
        }
    }

    /** @param array<string,mixed> $args */
    function extract_balance_method($args) {
        if (($b = $args["balance"] ?? null) !== null) {
            if ($b === "all") {
                $this->balance = self::BALANCE_ALL;
            } else if ($b === "new") {
                $this->balance = self::BALANCE_NEW;
            } else {
                $this->error_at("balance", "<0>Balance should be ‘all’ or ‘new’");
            }
        }
        $bt = $this->extract_tag($args, "balance_offset_tag") ?? "";
        if ($bt !== "") {
            foreach ($this->acs as $ac) {
                if (($tv = $ac->user->tag_value($bt))) {
                    $ac->balance += $tv;
                }
            }
        }
        if (($m = $args["method"] ?? null) !== null) {
            if ($m === "default" || $m === "mincost") {
                $this->method = self::METHOD_MCMF;
            } else if ($m === "random") {
                $this->method = self::METHOD_RANDOM;
            } else if ($m === "stupid") {
                $this->method = self::METHOD_STUPID;
            } else {
                $this->error_at("method", "<0>Method should be ‘default’ or ‘random’");
            }
        }
    }

    /** @param array<string,mixed> $args
     * @param string $key
     * @param ?int $min
     * @return ?int */
    function extract_intval($args, $key, $min) {
        if (!isset($args[$key])) {
            return null;
        }
        $n = stoi($args[$key]);
        if ($n === null || ($min !== null && $n < $min)) {
            $this->error_at($key, $min !== null && $min >= 0 ? "<0>Positive whole number expected" : "<0>Whole number expected");
            return null;
        }
        return $n;
    }

    /** @param array<string,mixed> $args */
    function extract_max_load($args) {
        $ml = $this->extract_tag($args, "max_load_tag")
            ?? $this->conf->setting_data("autoassign_review_max_load_tag") ?? "";
        if ($ml !== "") {
            foreach ($this->acs as $ac) {
                $tv = $ac->user->tag_value($ml);
                if ($tv !== null && $tv >= 0) {
                    $ac->max_load = min((int) round($tv), $ac->max_load);
                }
            }
        }
        if (($m = $this->extract_intval($args, "max_load", 1)) !== null) {
            foreach ($this->acs as $ac) {
                $ac->max_load = min($m, $ac->max_load);
            }
        }
    }

    /** @param array<string,mixed> $args */
    function extract_gadget_costs($args) {
        $gadget = $args["gadget"] ?? null;
        if ($gadget === "default") {
            $gadget = null;
        } else if ($gadget !== "expertise" && $gadget !== "plain" && $gadget !== null) {
            $this->error_at("gadget", "<0>Review gadget should be ‘plain’ or ‘expertise’");
            $gadget = null;
        }
        $x = friendly_boolean($args["expertise"] ?? null);
        if ($gadget === "expertise"
            || ($gadget === null && $x === true)) {
            $this->review_gadget = self::REVIEW_GADGET_EXPERTISE;
        } else if ($gadget === "plain"
                   || ($gadget === null && $x === false)) {
            $this->review_gadget = self::REVIEW_GADGET_PLAIN;
        }
        foreach (self::$known_costs as $n => $mv) {
            $ncost = "{$n}_cost";
            if (($v = $this->extract_intval($args, $ncost, $mv)) !== null) {
                $this->$ncost = $v;
            }
        }
    }

    /** @param callable $progressf
     * @deprecated */
    function add_progress_handler($progressf) {
        $this->progressf[] = $progressf;
    }

    /** @param callable(string) $progressf
     * @return $this */
    function add_progress_function($progressf) {
        $this->progressf[] = $progressf;
        return $this;
    }

    /** @param string $action */
    function set_assignment_action($action) {
        $this->ass_action = $action;
    }

    /** @param string $column
     * @return null|string|AutoassignerComputed */
    function assignment_column($column) {
        $v = $this->ass_extra[$column] ?? null;
        if (is_string($v)) {
            $v = CsvGenerator::unquote(substr($v, 1));
        }
        return $v;
    }

    /** @param string $column
     * @param null|string $value */
    function set_assignment_column($column, $value) {
        assert(empty($this->ass) || $value === null || isset($this->ass_extra[$column]));
        if ($value === null) {
            if (empty($this->ass)) {
                unset($this->ass_extra[$column]);
            } else if (isset($this->ass_extra[$column])) {
                $this->ass_extra[$column] = ",";
            }
        } else {
            if (is_string($value)) {
                $value = "," . CsvGenerator::quote($value);
            }
            $this->ass_extra[$column] = $value;
        }
    }

    /** @param string $column */
    function set_computed_assignment_column($column) {
        assert(in_array($column, ["preference", "expertise", "topic_score", "unhappiness", "name"]));
        /** @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal */
        $this->set_assignment_column($column, new AutoassignerComputed);
    }


    /** @return list<int> */
    final function paper_ids() {
        return array_keys($this->aps);
    }

    /** @return list<int> */
    final function user_ids() {
        return array_keys($this->acs);
    }

    /** @return string */
    final function assignment_action() {
        return $this->ass_action;
    }

    /** @return int */
    final function user_count() {
        return count($this->acs);
    }

    /** @return array<int,AutoassignerUser> */
    final protected function aausers() {
        return $this->acs;
    }

    /** @param int $uid
     * @return ?AutoassignerUser */
    final protected function aauser($uid) {
        return $this->acs[$uid] ?? null;
    }

    /** @param int $uid
     * @param int $load */
    final protected function add_aauser_load($uid, $load) {
        if (($ac = $this->aauser($uid))) {
            $ac->load += $load;
        }
    }

    /** @param int $uid
     * @param int $balance */
    final protected function add_aauser_balance($uid, $balance) {
        if (($ac = $this->aauser($uid))) {
            $ac->balance += $balance;
        }
    }

    /** @return array<int,AutoassignerPaper> */
    final protected function aapapers() {
        return $this->aps;
    }

    /** @param int $pid
     * @return ?AutoassignerPaper */
    final protected function aapaper($pid) {
        return $this->aps[$pid] ?? null;
    }

    /** @param int $pid
     * @param int $ndesired */
    final protected function set_aapaper_ndesired($pid, $ndesired) {
        if (($ap = $this->aapaper($pid))) {
            $ap->ndesired = $ndesired;
            $this->ndesired = -2;
        }
    }

    final protected function make_ae() {
        gc_collect_cycles();
        $this->mark_progress("Initializing");
        $this->ainfo = [];
        foreach ($this->acs as $ac) {
            $alist = [];
            foreach ($this->aps as $ap) {
                $ap->ainfo[] = $alist[] = new AutoassignerElement($ac->cid, $ap->pid);
            }
            $this->ainfo[$ac->cid] = array_combine(array_keys($this->aps), $alist);
        }
        $this->has_pref_index = false;
    }

    /** @return array<int,array<int,AutoassignerElement>> */
    final protected function ae_map() {
        return $this->ainfo;
    }

    /** @param int $cid
     * @return array<int,AutoassignerElement> */
    final protected function ae_map_for($cid) {
        return $this->ainfo[$cid] ?? [];
    }

    /** @param int $cid
     * @param int $pid
     * @return ?AutoassignerElement */
    final protected function ae($cid, $pid) {
        return $this->ainfo[$cid][$pid] ?? null;
    }

    /** @param int $cid
     * @param int $pid
     * @param int $eass */
    final protected function load_assignment($cid, $pid, $eass) {
        if (($a = $this->ainfo[$cid][$pid] ?? null)
            && $a->eass !== $eass) {
            if ($eass === self::ENEWASSIGN) {
                ++$this->aps[$pid]->nassigned;
                ++$this->acs[$cid]->nassigned;
                ++$this->nassigned;
            } else if ($a->eass === self::ENEWASSIGN) {
                --$this->aps[$pid]->nassigned;
                --$this->acs[$cid]->nassigned;
                --$this->nassigned;
            }
            $a->eass = $eass;
        }
    }


    /** @return bool */
    function has_assignment() {
        return count($this->ass) > 1;
    }

    function sort_assignments() {
        if (count($this->ass) > 1) {
            $this->ass[0] = "\1" . $this->ass[0];
            natsort($this->ass);
            $this->ass[0] = substr($this->ass[0], 1);
        }
    }

    /** @return list<string> */
    function assignments() {
        return count($this->ass) > 1 ? $this->ass : [];
    }

    /** @return array<int,int> */
    function pc_unhappiness() {
        $u = [];
        foreach ($this->acs as $cid => $ac) {
            $u[$ac->cid] = $ac->unhappiness;
        }
        return $u;
    }

    function reset_desired_assignment_count() {
        $this->ndesired = -2;
    }

    /** @return int */
    function desired_assignment_count() {
        if ($this->ndesired === -2) {
            $nu = 0;
            foreach ($this->acs as $ac) {
                if ($ac->ndesired >= 0) {
                    $nu += min($ac->ndesired, max($ac->max_load - $ac->load, 0));
                } else {
                    $nu = PHP_INT_MAX;
                    break;
                }
            }
            $np = 0;
            foreach ($this->aps as $ap) {
                $np += max($ap->ndesired, 0);
            }
            $this->ndesired = min($nu, $np);
        }
        return $this->ndesired;
    }

    /** @return list<int> */
    function incompletely_assigned_paper_ids() {
        $badpids = [];
        foreach ($this->aps as $ap) {
            if ($ap->ndesired > $ap->nassigned)
                $badpids[] = $ap->pid;
        }
        return $badpids;
    }


    private function first_assignment() {
        $t = "paper,action,email";
        foreach ($this->ass_extra as $k => $v) {
            $t .= "," . CsvGenerator::quote($k);
        }
        $this->ass = [$t . "\n"];
    }

    /** @param AutoassignerElement $a
     * @param AutoassignerUser $ac
     * @param AutoassignerPaper $ap
     * @return string */
    private function assignment_suffix($a, $ac, $ap) {
        $t = "";
        foreach ($this->ass_extra as $k => $v) {
            if (is_string($v)) {
                $t .= $v;
            } else if ($k === "preference") {
                $t .= "," . ($a->pref ?? "");
            } else if ($k === "expertise") {
                $t .= "," . unparse_expertise($a->exp);
            } else if ($k === "topic_score") {
                $t .= "," . ($a->topicscore ?? "");
            } else if ($k === "unhappiness") {
                $t .= sprintf(",%.3f", $a->pref_index / $ac->pref_index_bound);
            } else if ($k === "name") {
                $t .= "," . CsvGenerator::quote($ac->user->name());
            } else {
                $t .= ",???";
            }
        }
        return $t . "\n";
    }

    /** @param null|int|AutoassignerUser $ac
     * @param int $pid */
    function assign1($ac, $pid) {
        assert($this->ass_action !== "");
        if (is_int($ac)) {
            $ac = $this->acs[$ac] ?? null;
        }
        if (!$ac || !($ap = $this->aps[$pid] ?? null)) {
            return;
        }
        $a = $this->ainfo[$ac->cid][$pid] ?? null;

        if (empty($this->ass)) {
            $this->first_assignment();
        }
        $this->ass[] = "{$pid},{$this->ass_action},{$ac->user->email}"
            . $this->assignment_suffix($a, $ac, $ap);

        ++$ac->load;
        ++$ac->balance;
        ++$ac->nassigned;
        ++$ap->nassigned;
        if ($a) {
            $a->eass = self::ENEWASSIGN;
            $ac->unhappiness += $a->pref_index;
        }
        foreach ($this->avoid_lists[$ac->cid] ?? [] as $cid2) {
            if (($a2 = $this->ainfo[$cid2][$pid] ?? null)) {
                $a2->eass = max($a2->eass, self::EAVOIDASSIGN);
            }
        }
        ++$this->nassigned;
    }

    /** @param null|int|AutoassignerUser $ac
     * @param int $pid */
    function unassign1($ac, $pid) {
        assert($this->ass_action !== "");
        if (is_int($ac)) {
            $ac = $this->acs[$ac] ?? null;
        }
        if (!$ac || !($ap = $this->aps[$pid] ?? null)) {
            return;
        }
        $a = $this->ainfo[$ac->cid][$pid] ?? null;
        if ($a === null || $a->eass !== self::ENEWASSIGN) {
            error_log("bad Autoassigner::unassign1");
            return;
        }

        if (empty($this->ass)) {
            $this->first_assignment();
        }
        $this->ass[] = "{$pid},clear{$this->ass_action},{$ac->user->email}"
            . $this->assignment_suffix($a, $ac, $ap);

        --$ac->load;
        --$ac->balance;
        --$ac->nassigned;
        --$ap->nassigned;
        $a->eass = self::ENOASSIGN;
        $ac->unhappiness -= $a->pref_index;
        if (!empty($this->avoid_lists[$ac->cid])) {
            $this->need_process_avoid_lists = true;
        }
        --$this->nassigned;
    }

    /** @param bool $first */
    protected function process_avoid_lists($first) {
        $avcids = array_keys($this->avoid_lists ?? []);
        foreach ($first ? [] : $avcids as $c1) {
            foreach ($this->ainfo[$c1] as $ae) {
                if ($ae->eass === self::EAVOIDASSIGN)
                    $ae->eass = 0;
            }
        }
        foreach ($avcids as $c1) {
            foreach ($this->ainfo[$c1] as $ae) {
                if ($ae->eass >= self::EOTHERASSIGN) {
                    foreach ($this->avoid_lists[$c1] as $c2) {
                        if (($a2 = $this->ainfo[$c2][$ae->pid] ?? null))
                            $a2->eass = max($a2->eass, self::EAVOIDASSIGN);
                    }
                }
            }
        }
        $this->need_process_avoid_lists = false;
    }


    /** @param string $status */
    protected function mark_progress($status) {
        foreach ($this->progressf as $progressf) {
            call_user_func($progressf, $status);
        }
    }


    protected function compute_pref_index() {
        if ($this->has_pref_index) {
            return;
        }
        foreach ($this->acs as $ac) {
            $pf = $this->ainfo[$ac->cid] ?? [];
            usort($pf, function ($a1, $a2) {
                return $a2->cpref <=> $a1->cpref;
            });
            $pgidx = -1;
            $last = null;
            foreach ($pf as $a) {
                if (!$last || $a->cpref != $last->cpref) {
                    ++$pgidx;
                    $last = $a;
                }
                $a->pref_index = $pgidx;
            }
            $ac->pref_index_bound = $pgidx + 1;
        }
        $this->has_pref_index = true;
    }

    /** @param bool $prefsort */
    protected function make_work_lists($prefsort) {
        foreach ($this->acs as $ac) {
            $apid = array_keys($this->ainfo[$ac->cid]);
            if ($prefsort) {
                $apref = [];
                foreach ($this->ainfo[$ac->cid] as $ap) {
                    $apref[] = $ap->pref_index;
                }
                $ashuffle = range(0, count($apref) - 1);
                shuffle($ashuffle);
                array_multisort($apref, SORT_DESC, $ashuffle, $apid);
            } else {
                shuffle($apid);
            }
            $ac->work_list = $apid;
        }
    }


    protected function load_review_refusals() {
        $result = $this->conf->qe("select contactId, paperId from PaperReviewRefused where paperId ?a", $this->paper_ids());
        while (($row = $result->fetch_row())) {
            if (($a = $this->ainfo[(int) $row[0]][(int) $row[1]] ?? null)) {
                $a->eass = self::ENOASSIGN;
            }
        }
        Dbl::free($result);
    }

    /** @return array */
    protected function review_query_options() {
        return [
            "paperId" => $this->paper_ids(), "reviewSignatures" => true,
            "topics" => true, "allReviewerPreference" => true,
            "allConflictType" => true,
            "tags" => $this->conf->check_track_sensitivity(Track::ASSREV)
        ];
    }

    /** @param PaperInfo $prow */
    protected function load_paper_reviews($prow) {
        foreach ($prow->reviews_as_list() as $rrow) {
            if (($a = $this->ae($rrow->contactId, $prow->paperId)))
                $a->eass = self::EOLDASSIGN;
        }
    }

    /** @param PaperInfo $prow */
    protected function load_paper_preferences_and_conflicts($prow) {
        foreach ($this->acs as $ac) {
            $a = $this->ainfo[$ac->cid][$prow->paperId];
            $pf = $prow->preference($ac->user);
            $a->pref = $pf->preference;
            $a->exp = $pf->expertise;
            $a->topicscore = $prow->topic_interest_score($ac->user);
            if ($a->eass < self::ENOASSIGN
                && ($prow->has_conflict($ac->user)
                    || !$ac->user->pc_track_assignable($prow))) {
                $a->eass = self::ENOASSIGN;
            }
            $a->cpref = max($a->pref, -1000) + ((float) $a->topicscore / 100.0);
        }
    }

    /** @param int $reviewtype */
    protected function load_review_preferences($reviewtype) {
        $time = microtime(true);
        $this->make_ae();
        $this->load_review_refusals();

        // then load preferences
        $nloaded = 0;
        $result = $this->conf->paper_result($this->review_query_options());
        while (($row = PaperInfo::fetch($result, null, $this->conf))) {
            $this->load_paper_reviews($row);
            $this->load_paper_preferences_and_conflicts($row);
            ++$nloaded;
            if ($nloaded % 50 === 0) {
                $this->mark_progress(sprintf("Loading reviewer preferences (%d%% done)", (int) ($nloaded * 100 / count($this->paper_ids()) + 0.5)));
            }
        }
        Dbl::free($result);
        gc_collect_cycles();

        $this->compute_pref_index();
        $this->process_avoid_lists(true);
        $this->profile["preferences"] = microtime(true) - $time;
    }

    /** @return bool */
    private function might_coassign() {
        return $this->ass_action !== "lead" && $this->ass_action !== "shepherd";
    }

    /** @param array<int,AutoassignerUser> $acs
     * @param callable(AutoassignerUser,AutoassignerUser):int $compar
     * @return ?AutoassignerUser */
    static protected function choose_aauser($acs, $compar) {
        $ac = null;
        $ninclass = 0;
        foreach ($acs as $acx) {
            $cmp = $ac ? $compar($acx, $ac) : -1;
            if ($cmp < 0) {
                $ninclass = 0;
                $ac = $acx;
            } else if ($cmp === 0) {
                ++$ninclass;
                if (mt_rand(0, $ninclass) === 0) {
                    $ac = $acx;
                }
            }
        }
        return $ac;
    }

    /** @param AutoassignerUser $ac */
    protected function assign_from_work_list($ac) {
        while (!empty($ac->work_list) && $ac->load < $ac->max_load) {
            $pid = array_pop($ac->work_list);
            // skip if not assignable
            if (!($ap = $this->aps[$pid] ?? null)
                || $ap->ndesired <= $ap->nassigned
                || (($a = $this->ainfo[$ac->cid][$pid] ?? null) && $a->eass !== 0)) {
                continue;
            }
            // make assignment
            $this->assign1($ac, $pid);
            // report progress
            if ($this->nassigned % 10 === 0) {
                $this->mark_progress(sprintf("Computing assignments (%d%% done)", (int) ($this->nassigned * 100 / $this->desired_assignment_count() + 0.5)));
            }
            break;
        }
    }

    // This assignment function assigns without considering preferences.
    private function assign_stupidly() {
        $this->make_work_lists(false);
        $acs = $this->acs;
        while (!empty($acs)) {
            // choose a least-balanced pc member
            $ac = self::choose_aauser($acs, function ($ac, $bc) {
                return $ac->balance <=> $bc->balance;
            });

            // choose a paper from the work list
            $this->assign_from_work_list($ac);

            // if have exhausted preferences, remove pc member
            if ($ac->work_list_complete()) {
                unset($acs[$ac->cid]);
            }
        }
    }

    private function assign_randomly() {
        $this->make_work_lists(true);
        $acs = $this->acs;
        while (!empty($acs)) {
            // choose a least-balanced pc member, min unhappiness
            $ac = self::choose_aauser($acs, function ($ac, $bc) {
                return $ac->balance <=> $bc->balance
                    ? : $ac->unhappiness <=> $bc->unhappiness;
            });

            // choose a paper from the work list
            $this->assign_from_work_list($ac);

            // if have exhausted preferences, remove pc member
            if ($ac->work_list_complete()) {
                unset($acs[$ac->cid]);
            }
        }
    }

    /** @param MinCostMaxFlow $mcmf */
    function mcmf_progress($mcmf, $what, $phaseno = 0, $nphases = 0) {
        if ($what <= MinCostMaxFlow::PMAXFLOW_DONE) {
            $ndesired = max($this->desired_assignment_count(), 1);
            $n = min(max($mcmf->current_flow(), 0), $ndesired);
            $this->mark_progress(sprintf("Preparing unoptimized assignment{$this->mcmf_round_descriptor} (%d%% done)", (int) ($n * 100 / $ndesired + 0.5)));
        } else {
            $x = [];
            $cost = $mcmf->current_cost();
            if (!$this->mcmf_max_cost) {
                $this->mcmf_max_cost = $cost;
            } else if ($cost < $this->mcmf_max_cost) {
                $x[] = sprintf("%.1f%% better", ((int) (($this->mcmf_max_cost - $cost) * 1000 / abs($this->mcmf_max_cost) + 0.5)) / 10);
            }
            $phasedescriptor = $nphases > 1 ? ", phase " . ($phaseno + 1) . "/" . $nphases : "";
            $this->mark_progress($this->mcmf_optimizing_for
                                 . $this->mcmf_round_descriptor . $phasedescriptor
                                 . ($x ? " (" . join(", ", $x) . ")" : ""));
        }
    }

    /** @param AutoassignerElement $a
     * @param AutoassignerUser $ac
     * @return int */
    private function mcmf_assignment_preference_cost($a, $ac) {
        return (int) ($a->pref_index * $this->preference_cost / $ac->pref_index_bound);
    }

    /** @param int $mcmf_round
     * @return bool */
    private function mcmf_assign_once($mcmf_round) {
        $m = new MinCostMaxFlow;
        $m->add_progress_function([$this, "mcmf_progress"]);
        $this->mcmf_max_cost = null;
        $this->mark_progress("Preparing assignment optimizer" . $this->mcmf_round_descriptor);
        $adjusting = ($this->remove_assignment_cost ?? 1000000) < 1000000
            && $mcmf_round === 0
            && $this->nassigned > 0;
        if ($this->need_process_avoid_lists) {
            $this->process_avoid_lists(false);
        }
        // paper nodes
        $nass = 0;
        foreach ($this->aps as $pid => $ap) {
            if ($ap->ndesired <= 0) {
                continue;
            }
            $m->add_node("p{$pid}", "p");
            $nfixed = $adjusting ? 0 : $ap->nassigned;
            if ($ap->balance) {
                for ($i = $nfixed; $i < $ap->ndesired; ++$i) {
                    $m->add_edge("p{$pid}", ".sink", 1, $this->paper_assignment_cost * $i);
                }
            } else {
                $m->add_edge("p{$pid}", ".sink", max($ap->ndesired - $nfixed, 0), 0);
            }
            if ($this->review_gadget == self::REVIEW_GADGET_EXPERTISE) {
                $m->add_node("p{$pid}x", "px");
                $m->add_node("p{$pid}y", "py");
                $m->add_node("p{$pid}xy", "pxy");
                $m->add_edge("p{$pid}x", "p{$pid}xy", 1, $this->expertise_x_cost);
                $m->add_edge("p{$pid}x", "p{$pid}y", $ap->ndesired, 0);
                $m->add_edge("p{$pid}y", "p{$pid}xy", 2, $this->expertise_y_cost);
                $m->add_edge("p{$pid}y", "p{$pid}", $ap->ndesired, 0);
                $m->add_edge("p{$pid}xy", "p{$pid}", 2, 0);
            }
            $nass += max($ap->ndesired - $nfixed, 0);
        }
        // user nodes
        $assperpc = ceil($nass / $this->user_count());
        $maxload = $assperpc;
        $minbalance = PHP_INT_MAX;
        foreach ($this->acs as $ac) {
            $maxload = max($maxload, $ac->load + $assperpc);
            $minbalance = min($minbalance, $ac->balance - $ac->nassigned);
        }
        foreach ($this->acs as $cid => $ac) {
            $m->add_node("u{$cid}", "u");
            $nfixed = $adjusting ? 0 : $ac->nassigned;
            if ($ac->ndesired >= 0) {
                $m->add_edge(".source", "u{$cid}", max($ac->ndesired - $nfixed, 0), 0);
            } else {
                $ldbase = $ac->load - $ac->nassigned;
                $balancebase = $ac->balance - $ac->nassigned - $minbalance;
                for ($i = $nfixed; $ldbase + $i < min($ac->max_load, $ac->load + $maxload); ++$i) {
                    $cost = $this->assignment_cost * ($balancebase + $i);
                    $m->add_edge(".source", "u{$cid}", 1, $cost);
                }
            }
        }
        // paper <-> contact map
        foreach ($this->aps as $pid => $ap) {
            if ($ap->ndesired <= 0) {
                continue;
            }
            foreach ($this->acs as $cid => $ac) {
                $a = $this->ainfo[$cid][$pid];
                if (($a->eass > 0 && $a->eass < self::ENEWASSIGN)
                    || ($a->eass === self::ENEWASSIGN && !$adjusting)
                    || ($a->eass === 0 && $ap->ndesired <= $ap->nassigned && !$adjusting)) {
                    continue;
                }
                if ($this->review_gadget == self::REVIEW_GADGET_EXPERTISE) {
                    if ($a->exp > 0) {
                        $dst = "p{$pid}x";
                    } else if ($a->exp === 0) {
                        $dst = "p{$pid}y";
                    } else {
                        $dst = "p{$pid}";
                    }
                } else {
                    $dst = "p{$pid}";
                }
                // see also mcmf_assign_results below
                $cost = $this->mcmf_assignment_preference_cost($a, $ac);
                if ($a->eass === self::ENEWASSIGN && $adjusting) {
                    $cost -= $this->remove_assignment_cost;
                    $m->add_edge("u{$cid}", $dst, 1, $cost, 0);
                } else {
                    $m->add_edge("u{$cid}", $dst, 1, $cost, $a->eass ? 1 : 0);
                }
            }
        }
        // run MCMF
        $this->mcmf = $m;
        $m->shuffle();
        $m->run();
        // make assignments
        $this->mark_progress("Completing assignment" . $this->mcmf_round_descriptor);
        $time = microtime(true);
        $changed = false;
        if ($m->infeasible) {
            $this->error_at(null, "<0>Internal error: Assignment infeasible");
        } else if ($m->current_flow() > 0) {
            $changed = $this->mcmf_apply($m, $adjusting);
        }
        $this->profile["maxflow"] = $m->maxflow_end_at - $m->maxflow_start_at;
        if ($m->mincost_start_at) {
            $this->profile["mincost"] = $m->mincost_end_at - $m->mincost_start_at;
        }
        $this->profile["memory"] = memory_get_usage();
        $this->mcmf = null;
        $this->profile["traverse"] = microtime(true) - $time;
        return $changed;
    }

    /** @param array<int,list<int>> $acp
     * @param int $cid
     * @param int $pid
     * @return bool */
    private function mcmf_assignment_conflicts($acp, $cid, $pid) {
        foreach ($this->avoid_lists[$cid] as $cid2) {
            if (in_array($pid, $acp[$cid2] ?? []))
                return true;
        }
        return false;
    }

    /** @param bool $adjusting
     * @return bool */
    private function mcmf_apply(MinCostMaxFlow $m, $adjusting) {
        // collect resulting assignments
        $changed = false;
        $acp = [];
        foreach ($this->aausers() as $cid => $ac) {
            foreach ($m->downstream("u{$cid}", "p") as $v) {
                $acp[$cid][] = (int) substr($v->name, 1);
            }
            if ($adjusting) {
                $pids = $acp[$cid] ?? [];
                foreach ($this->ainfo[$cid] as $a) {
                    if ($a->eass === self::ENEWASSIGN
                        && !in_array($a->pid, $pids)) {
                        $this->unassign1($ac, $a->pid);
                        $changed = true;
                    }
                }
            }
        }

        // make assignments that don’t violate constraints
        $nacp = [];
        foreach ($acp as $cid => $pids) {
            $careful = isset($this->avoid_lists[$cid]);
            foreach ($pids as $pid) {
                $a = $this->ainfo[$cid][$pid];
                if ($a->eass !== 0) {
                    continue;
                }
                if ($careful
                    && $this->mcmf_assignment_conflicts($acp, $cid, $pid)) {
                    $nacp[] = $a;
                } else {
                    $this->assign1($cid, $pid);
                    $changed = true;
                }
            }
        }
        if (empty($nacp)) {
            return $changed;
        }

        // sort constraint-violating assignments by cost;
        // make those that are still valid
        $nacp_cost = [];
        foreach ($nacp as $a) {
            $cost = $this->mcmf_assignment_preference_cost($a, $this->acs[$a->cid]);
            if ($this->review_gadget === self::REVIEW_GADGET_EXPERTISE) {
                if ($a->exp > 0) {
                    $cost += $this->expertise_x_cost;
                } else if ($a->exp === 0) {
                    $cost += $this->expertise_y_cost;
                }
            }
            $nacp_cost[] = $cost;
        }
        uksort($nacp, function ($i, $j) use ($nacp_cost) {
            return $nacp_cost[$i] <=> $nacp_cost[$j];
        });
        foreach ($nacp as $a) {
            if ($a->eass === 0) {
                $this->assign1($a->cid, $a->pid);
                $changed = true;
            }
        }
        return $changed;
    }

    private function assign_mcmf() {
        $this->mcmf_round_descriptor = "";
        $this->mcmf_optimizing_for = "Optimizing assignment for preferences and balance";
        $mcmf_round = 0;
        while ($this->mcmf_assign_once($mcmf_round)
               && $this->nassigned < $this->desired_assignment_count()) {
            ++$mcmf_round;
            $this->mcmf_round_descriptor = ", round " . ($mcmf_round + 1);
            gc_collect_cycles();
        }
    }

    protected function assign_method() {
        if ($this->method == self::METHOD_RANDOM) {
            $this->assign_randomly();
        } else if ($this->method == self::METHOD_STUPID) {
            $this->assign_stupidly();
        } else {
            $this->assign_mcmf();
        }
        gc_collect_cycles();
    }


    protected function finish_assignment() {
        $this->ainfo = [];
        foreach ($this->acs as $ac) {
            $ac->work_list = null;
        }
    }


    abstract function run();



    /** @param ?list<string> $parameters
     * @return list<AutoassignerParameter> */
    static function expand_parameters(Conf $conf, $parameters) {
        $result = [];
        foreach ($parameters ?? [] as $s) {
            if (!is_string($s)) {
                continue;
            } else if (str_starts_with($s, "\$")) {
                if (($gj = $conf->autoassigner(substr($s, 1)))) {
                    array_push($result, ...self::expand_parameters($conf, $gj->parameters ?? []));
                }
            } else if (($o = self::expand_parameter_help($s))) {
                $result[] = $o;
            }
        }
        return $result;
    }

    /** @param string $help
     * @return ?AutoassignerParameter */
    static function expand_parameter_help($help) {
        if (!preg_match('/\A(\??)(\S+)\s*(|\{\S*\})\s*(|[^{].*)\z/', $help, $m)) {
            return null;
        }
        if ($m[4] === "") {
            if ($m[2] === "count") {
                $m[3] = $m[3] ? : "{n}";
                $m[4] = "Number of assignments";
            } else if ($m[2] === "rtype") {
                $m[4] = "Review type";
            } else if ($m[2] === "method") {
                $m[4] = "Assignment method (default, random, stupid) [default]";
            } else if ($m[2] === "balance") {
                $m[4] = "Load-balancing method (all, new) [all]";
            } else if ($m[2] === "max_load") {
                $m[3] = $m[3] ? : "{n}";
                $m[4] = "Maximum load per PC";
            } else if ($m[2] === "max_load_tag") {
                $m[3] = $m[3] ? : "{tag}";
                $m[4] = "PC tag defining maximum load per PC";
            } else if ($m[2] === "round") {
                $m[4] = "Review round";
            }
        }
        if ($m[3] === "") {
            $argname = strtoupper($m[2]);
        } else {
            $argname = strtoupper(substr($m[3], 1, -1));
        }
        return new AutoassignerParameter($m[2], $m[1] === "", $argname, $m[4]);
    }

    /** @param string $name
     * @param list<AutoassignerParameter> $params
     * @return ?AutoassignerParameter */
    static function find_parameter($name, $params) {
        foreach ($params as $p) {
            if ($p->name === $name)
                return $p;
        }
        return null;
    }
}
