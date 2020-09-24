<?php
// assignmentset.php -- HotCRP helper classes for assignments
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class AssignmentItem implements ArrayAccess {
    /** @var false|array */
    public $before;
    /** @var false|null|array */
    public $after;
    /** @var null|int|string */
    public $landmark;
    /** @param false|array $before */
    function __construct($before) {
        $this->before = $before;
    }
    /** @param string $offset
     * @return bool */
    function offsetExists($offset) {
        $x = $this->after ? : $this->before;
        return isset($x[$offset]);
    }
    /** @param string $offset */
    function offsetGet($offset) {
        $x = $this->after ? : $this->before;
        return $x[$offset] ?? null;
    }
    function offsetSet($offset, $value) {
        throw new Exception("invalid AssignmentItem::offsetSet");
    }
    function offsetUnset($offset) {
        throw new Exception("invalid AssignmentItem::offsetUnset");
    }
    /** @return bool */
    function existed() {
        return !!$this->before;
    }
    /** @return bool */
    function deleted() {
        return $this->after === false;
    }
    /** @return bool */
    function modified() {
        return $this->after !== null;
    }
    /** @param bool $pre
     * @param string $offset */
    function get($pre, $offset) {
        if ($pre || $this->after === null) {
            $x = $this->before;
        } else {
            $x = $this->after;
        }
        return $x ? $x[$offset] ?? null : null;
    }
    /** @param string $offset */
    function pre($offset) {
        return $this->before ? $this->before[$offset] ?? null : null;
    }
    /** @param string $offset */
    function post($offset) {
        $x = $this->after ?? $this->before;
        return $x ? $x[$offset] ?? null : null;
    }
    /** @param string $offset
     * @return bool */
    function differs($offset) {
        return $this->pre($offset) !== $this->post($offset);
    }
    function realize(AssignmentState $astate) {
        return call_user_func($astate->realizer($this->offsetGet("type")), $this, $astate);
    }
}

class AssignmentItemSet {
    /** @var array<int|string,AssignmentItem> */
    public $items = [];
}

class AssignmentState {
    /** @var array<int,AssignmentItemSet> */
    private $st = [];
    private $types = [];
    private $realizers = [];
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;     // executor
    /** @var Contact */
    public $reviewer; // default contact
    /** @var int */
    public $overrides = 0;
    /** @var int */
    public $flags = 0;
    /** @var AssignerContacts */
    private $cmap;
    /** @var ?array<int,Contact> */
    private $reviewer_users = null;
    /** @var string */
    public $filename = "";
    /** @var null|int|string */
    public $landmark;
    public $defaults = [];
    /** @var array<int,PaperInfo> */
    private $prows = [];
    private $pid_attempts = [];
    /** @var ?PaperInfo */
    private $placeholder_prow;
    /** @var list<object> */
    public $finishers = [];
    /** @var array<string,object> */
    public $finisher_map = [];
    public $paper_exact_match = true;
    /** @var list<MessageItem> */
    private $msgs = [];
    /** @var list<int> */
    private $nonexact_msgi = [];
    public $has_error = false;
    public $has_user_error = false;

    const ERROR_NONEXACT_MATCH = 4;

    const FLAG_CSV_CONTEXT = 1;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $this->reviewer = $user;
        $this->cmap = new AssignerContacts($this->conf, $this->user);
    }

    function mark_type($type, $keys, $realizer) {
        if (!isset($this->types[$type])) {
            $this->types[$type] = $keys;
            $this->realizers[$type] = $realizer;
            return true;
        } else {
            return false;
        }
    }
    function realizer($type) {
        return $this->realizers[$type];
    }
    /** @param int $pid
     * @return AssignmentItemSet */
    private function pidstate($pid) {
        if (!isset($this->st[$pid])) {
            $this->st[$pid] = new AssignmentItemSet;
        }
        return $this->st[$pid];
    }
    private function extract_key($x, $pid = null) {
        $tkeys = $this->types[$x["type"]];
        assert(!!$tkeys);
        $t = $x["type"];
        foreach ($tkeys as $k) {
            if (isset($x[$k])) {
                $t .= "`" . $x[$k];
            } else if ($pid !== null && $k === "pid") {
                $t .= "`" . $pid;
            } else {
                return false;
            }
        }
        return $t;
    }
    function load($x) {
        $st = $this->pidstate($x["pid"]);
        $k = $this->extract_key($x);
        assert($k && !isset($st->items[$k]));
        $st->items[$k] = new AssignmentItem($x);
    }

    /** @param array{pid?:int} $q
     * @return list<int> */
    private function pid_keys($q) {
        if (isset($q["pid"])) {
            return array($q["pid"]);
        } else {
            return array_keys($this->st);
        }
    }
    static private function match($x, $q) {
        foreach ($q as $k => $v) {
            if ($v !== null && ($x[$k] ?? null) !== $v)
                return false;
        }
        return true;
    }
    function query_items($q) {
        $res = [];
        foreach ($this->pid_keys($q) as $pid) {
            $st = $this->pidstate($pid);
            $k = $this->extract_key($q, $pid);
            foreach ($k ? [$st->items[$k] ?? null] : $st->items as $item) {
                if ($item
                    && !$item->deleted()
                    && self::match($item->after ? : $item->before, $q)) {
                    $res[] = $item;
                }
            }
        }
        return $res;
    }
    /** @return list<array> */
    function query($q) {
        $res = [];
        foreach ($this->query_items($q) as $item) {
            $res[] = $item->after ? : $item->before;
        }
        return $res;
    }
    /** @return list<array> */
    function query_unmodified($q) {
        $res = [];
        foreach ($this->query_items($q) as $item) {
            if (!$item->modified())
                $res[] = $item->before;
        }
        return $res;
    }
    function make_filter($key, $q) {
        $cf = [];
        foreach ($this->query($q) as $m) {
            $cf[$m[$key]] = true;
        }
        return $cf;
    }

    function remove($q) {
        $res = [];
        foreach ($this->query_items($q) as $item) {
            $res[] = $item->after ? : $item->before;
            $item->after = false;
            $item->landmark = $this->landmark;
        }
        return $res;
    }
    function remove_if($q, $predicate) {
        $res = [];
        foreach ($this->query_items($q) as $item) {
            if (!$predicate
                || call_user_func($predicate, $item->after ? : $item->before)) {
                $res[] = $item->after ? : $item->before;
                $item->after = false;
                $item->landmark = $this->landmark;
            }
        }
        return $res;
    }
    /** @return AssignmentItem */
    function add($x) {
        $k = $this->extract_key($x);
        assert(!!$k);
        $st = $this->pidstate($x["pid"]);
        if (!($item = $st->items[$k] ?? null)) {
            $item = $st->items[$k] = new AssignmentItem(false);
        }
        $item->after = $x;
        $item->landmark = $this->landmark;
        return $item;
    }

    /** @return array<int,list<AssignmentItem>> */
    function diff() {
        $diff = [];
        foreach ($this->st as $pid => $st) {
            foreach ($st->items as $item) {
                if ((!$item->before && $item->after)
                    || ($item->before && $item->after === false)
                    || ($item->before && $item->after && !self::match($item->before, $item->after)))
                    $diff[$pid][] = $item;
            }
        }
        return $diff;
    }

    /** @return list<int> */
    function paper_ids() {
        return array_keys($this->prows);
    }
    /** @param int $pid
     * @return ?PaperInfo */
    function prow($pid) {
        $p = $this->prows[$pid] ?? null;
        if (!$p && !isset($this->pid_attempts[$pid])) {
            $this->fetch_prows($pid);
            $p = $this->prows[$pid] ?? null;
        }
        return $p;
    }
    function add_prow(PaperInfo $prow) {
        $this->prows[$prow->paperId] = $prow;
    }
    /** @return array<int,PaperInfo> */
    function prows() {
        return $this->prows;
    }
    /** @param int|list<int> $pids */
    function fetch_prows($pids, $initial_load = false) {
        $pids = is_array($pids) ? $pids : array($pids);
        $fetch_pids = array();
        foreach ($pids as $p) {
            if (!isset($this->prows[$p]) && !isset($this->pid_attempts[$p]))
                $fetch_pids[] = $p;
        }
        assert($initial_load || empty($fetch_pids));
        if (!empty($fetch_pids)) {
            foreach ($this->user->paper_set(["paperId" => $fetch_pids]) as $prow) {
                $this->prows[$prow->paperId] = $prow;
            }
            foreach ($fetch_pids as $pid) {
                if (!isset($this->prows[$pid]))
                    $this->pid_attempts[$pid] = true;
            }
        }
    }
    /** @return PaperInfo */
    function placeholder_prow() {
        if ($this->placeholder_prow === null) {
            $this->placeholder_prow = new PaperInfo(["paperId" => -1], null, $this->conf);
        }
        return $this->placeholder_prow;
    }

    /** @return Contact */
    function user_by_id($cid) {
        return $this->cmap->user_by_id($cid);
    }
    /** @param list<int> $cids
     * @return list<Contact> */
    function users_by_id($cids) {
        return array_map(function ($cid) { return $this->user_by_id($cid); }, $cids);
    }
    /** @return ?Contact */
    function user_by_email($email, $create = false, $req = null) {
        return $this->cmap->user_by_email($email, $create, $req);
    }
    /** @return Contact */
    function none_user() {
        return $this->cmap->none_user();
    }
    /** @return array<int,Contact> */
    function pc_users() {
        return $this->cmap->pc_users();
    }
    /** @return array<int,Contact> */
    function reviewer_users() {
        if ($this->reviewer_users === null) {
            $this->reviewer_users = $this->cmap->reviewer_users($this->paper_ids());
        }
        return $this->reviewer_users;
    }
    function register_user(Contact $c) {
        return $this->cmap->register_user($c);
    }

    /** @param null|false|int|string $landmark
     * @param string $msg
     * @param 0|1|2 $status */
    function msg($landmark, $msg, $status) {
        if (is_string($landmark)) {
            $l = $landmark;
        } else if ($landmark === false) {
            $l = "";
        } else if ($landmark) {
            $l = ($this->filename === "" ? "line " : $this->filename . ":") . $landmark;
        } else {
            $l = $this->filename;
        }
        $n = count($this->msgs) - 1;
        if ($n >= 0
            && $this->msgs[$n]->field === $l
            && $this->msgs[$n]->message === $msg) {
            $this->msgs[$n]->status = max($this->msgs[$n]->status, $status);
        } else {
            $this->msgs[] = new MessageItem($l, $msg, $status);
        }
        if ($status === 2) {
            $this->has_error = true;
        }
    }
    /** @param string $msg */
    function warning($msg) {
        $this->msg($this->landmark, $msg, 1);
    }
    /** @param null|false|int|string $landmark
     * @param string $msg */
    function warning_at($landmark, $msg) {
        $this->msg($landmark, $msg, 1);
    }
    /** @param string $msg
     * @return false */
    function error($msg) {
        $this->msg($this->landmark, $msg, 2);
        return false;
    }
    /** @param string $msg
     * @return false */
    function user_error($msg) {
        $this->has_user_error = true;
        return $this->error($msg);
    }
    /** @param string $msg
     * @return false */
    function paper_error($msg) {
        if ($this->paper_exact_match) {
            $this->msg($this->landmark, $msg, 2);
        } else {
            $this->msg($this->landmark, $msg, 1);
            $this->nonexact_msgi[] = count($this->msgs) - 1;
        }
        return false;
    }

    /** @return bool */
    function has_messages() {
        return !empty($this->msgs);
    }
    /** @return list<MessageItem> */
    function message_list() {
        return $this->msgs;
    }
    function mark_matching_errors() {
        foreach ($this->nonexact_msgi as $i) {
            if ($this->msgs[$i]->status < 2) {
                $this->msgs[$i]->status = 2;
                $this->has_error = true;
            }
        }
        $this->nonexact_msgi = [];
    }
    function clear_messages() {
        $this->msgs = $this->nonexact_msgi = [];
        $this->has_error = $this->has_user_error = false;
    }
}

class AssignerContacts {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $viewer;
    /** @var array<int,Contact> */
    private $by_id = [];
    /** @var array<string,Contact> */
    private $by_lemail = [];
    /** @var bool */
    private $has_pc = false;
    /** @var ?Contact */
    private $none_user;
    static private $next_fake_id = -10;
    static public $query = "ContactInfo.contactId, firstName, lastName, unaccentedName, email, affiliation, collaborators, roles, contactTags";
    static public $cdb_query = "contactDbId, firstName, lastName, email, affiliation, collaborators, 0 roles, '' contactTags";
    function __construct(Conf $conf, Contact $viewer) {
        global $Me;
        $this->conf = $conf;
        $this->viewer = $viewer;
        if ($Me && $Me->contactId > 0 && $Me->conf === $conf) {
            $this->store($Me);
        }
    }
    private function store(Contact $c) {
        if ($c->contactId != 0) {
            if (isset($this->by_id[$c->contactId])) {
                return $this->by_id[$c->contactId];
            }
            $this->by_id[$c->contactId] = $c;
        }
        if ($c->email) {
            $this->by_lemail[strtolower($c->email)] = $c;
        }
        return $c;
    }
    private function ensure_pc() {
        if (!$this->has_pc) {
            foreach ($this->conf->pc_members() as $p) {
                $this->store($p);
            }
            $this->has_pc = true;
        }
    }
    /** @return Contact */
    function none_user() {
        if (!$this->none_user) {
            $this->none_user = new Contact(["contactId" => 0, "roles" => 0, "email" => "", "sorter" => ""], $this->conf);
        }
        return $this->none_user;
    }
    /** @param int $cid
     * @return Contact */
    function user_by_id($cid) {
        if (!$cid) {
            return $this->none_user();
        }
        if (($c = $this->by_id[$cid] ?? null)) {
            return $c;
        }
        $this->ensure_pc();
        if (($c = $this->by_id[$cid] ?? null)) {
            return $c;
        }
        $result = $this->conf->qe("select " . self::$query . " from ContactInfo where contactId=?", $cid);
        $c = Contact::fetch($result, $this->conf);
        if (!$c) {
            $c = new Contact(["contactId" => $cid, "roles" => 0, "email" => "unknown contact $cid"], $this->conf);
        }
        Dbl::free($result);
        return $this->store($c);
    }
    /** @param string $email
     * @param ?CsvRow $req
     * @return ?Contact */
    function user_by_email($email, $create = false, $req = null) {
        if (!$email) {
            return $this->none_user();
        }
        $lemail = strtolower($email);
        if (($c = $this->by_lemail[$lemail] ?? null)) {
            return $c;
        }
        $this->ensure_pc();
        if (($c = $this->by_lemail[$lemail] ?? null)) {
            return $c;
        }
        $result = $this->conf->qe("select " . self::$query . " from ContactInfo where email=?", $lemail);
        $c = Contact::fetch($result, $this->conf);
        Dbl::free($result);
        if (!$c && $create) {
            $is_anonymous = Contact::is_anonymous_email($email);
            assert(validate_email($email) || $is_anonymous);
            if (($cdb = $this->conf->contactdb()) && validate_email($email)) {
                $result = Dbl::qe($cdb, "select " . self::$cdb_query . " from ContactInfo where email=?", $lemail);
                $c = Contact::fetch($result, $this->conf);
                Dbl::free($result);
            }
            if (!$c) {
                $cargs = ["contactId" => 0, "roles" => 0, "email" => $email];
                foreach (["firstName", "lastName", "affiliation"] as $k) {
                    if ($req && $req[$k])
                        $cargs[$k] = $req[$k];
                }
                if ($is_anonymous) {
                    $cargs["firstName"] = "Jane Q.";
                    $cargs["lastName"] = "Public";
                    $cargs["affiliation"] = "Unaffiliated";
                    $cargs["disabled"] = true;
                }
                $c = new Contact($cargs, $this->conf);
            }
            $c->contactXid = $c->contactId = self::$next_fake_id--;
        }
        return $c ? $this->store($c) : null;
    }
    /** @return array<int,Contact> */
    function pc_users() {
        $this->ensure_pc();
        return $this->conf->pc_members();
    }
    /** @return array<int,Contact> */
    function reviewer_users($pids) {
        $rset = $this->pc_users();
        $result = $this->conf->qe("select " . AssignerContacts::$query . " from ContactInfo join PaperReview using (contactId) where (roles&" . Contact::ROLE_PC . ")=0 and paperId?a group by ContactInfo.contactId", $pids);
        while ($result && ($c = Contact::fetch($result, $this->conf))) {
            $rset[$c->contactId] = $this->store($c);
        }
        Dbl::free($result);
        return $rset;
    }
    /** @return Contact */
    function register_user(Contact $c) {
        if ($c->contactId >= 0) {
            return $c;
        }
        assert($this->by_id[$c->contactId] === $c);
        $cx = $this->by_lemail[strtolower($c->email)];
        if ($cx === $c) {
            // XXX assume that never fails:
            $cargs = [];
            foreach (["email", "firstName", "lastName", "affiliation", "disabled"] as $k) {
                if ($c->$k !== null)
                    $cargs[$k] = $c->$k;
            }
            $cx = Contact::create($this->conf, $this->viewer, $cargs, $cx->is_anonymous_user() ? Contact::SAVE_ANY_EMAIL : 0);
            $cx = $this->store($cx);
        }
        return $cx;
    }
}

class AssignmentCount {
    public $ass = 0;
    public $rev = 0;
    public $meta = 0;
    public $pri = 0;
    public $sec = 0;
    public $lead = 0;
    public $shepherd = 0;
    function add(AssignmentCount $ct) {
        $xct = new AssignmentCount;
        foreach (["rev", "meta", "pri", "sec", "ass", "lead", "shepherd"] as $k) {
            $xct->$k = $this->$k + $ct->$k;
        }
        return $xct;
    }
}

class AssignmentCountSet {
    /** @var Conf */
    public $conf;
    /** @var array<int,AssignmentCount> */
    public $bypc = [];
    public $rev = false;
    public $lead = false;
    public $shepherd = false;
    function __construct(Conf $conf) {
        $this->conf = $conf;
    }
    /** @return AssignmentCount */
    function get($offset) {
        return $this->bypc[$offset] ?? new AssignmentCount;
    }
    /** @param int $offset
     * @return AssignmentCount */
    function ensure($offset) {
        if (!isset($this->bypc[$offset])) {
            $this->bypc[$offset] = new AssignmentCount;
        }
        return $this->bypc[$offset];
    }
    function load_rev() {
        $result = $this->conf->qe("select u.contactId, group_concat(r.reviewType separator '')
                from ContactInfo u
                left join PaperReview r on (r.contactId=u.contactId)
                left join Paper p on (p.paperId=r.paperId)
                where p.timeWithdrawn<=0 and p.timeSubmitted>0
                and u.roles!=0 and (u.roles&" . Contact::ROLE_PC . ")!=0
                group by u.contactId");
        while (($row = $result->fetch_row())) {
            $ct = $this->ensure((int) $row[0]);
            $ct->rev = strlen($row[1]);
            $ct->meta = substr_count($row[1], (string) REVIEW_META);
            $ct->pri = substr_count($row[1], (string) REVIEW_PRIMARY);
            $ct->sec = substr_count($row[1], (string) REVIEW_SECONDARY);
        }
        Dbl::free($result);
    }
    private function load_paperpc($type) {
        $result = $this->conf->qe("select {$type}ContactId, count(paperId)
                from Paper where timeWithdrawn<=0 and timeSubmitted>0
                group by {$type}ContactId");
        while (($row = $result->fetch_row())) {
            $ct = $this->ensure((int) $row[0]);
            $ct->$type = (int) $row[1];
        }
        Dbl::free($result);
    }
    function load_lead() {
        $this->load_paperpc("lead");
    }
    function load_shepherd() {
        $this->load_paperpc("shepherd");
    }
}

class AssignmentCsv {
    /** @var array<string,true> */
    private $fields = [];
    /** @var list<array<string,int|string>> */
    private $rows = [];
    /** @param array<string,int|string> $row */
    function add($row) {
        foreach ($row as $k => $v) {
            if ($v !== null)
                $this->fields[$k] = true;
        }
        $this->rows[] = $row;
    }
    /** @return int */
    function count() {
        return count($this->rows);
    }
    /** @param int $i
     * @return ?array<string,int|string> */
    function row($i) {
        return $this->rows[$i] ?? null;
    }
    /** @return CsvGenerator */
    function unparse_into(CsvGenerator $csvg) {
        return $csvg->select(array_keys($this->fields))->append($this->rows);
    }
    /** @return string */
    function unparse() {
        return $this->unparse_into(new CsvGenerator)->unparse();
    }
}

class AssignmentParser {
    /** @var string $type */
    public $type;
    function __construct($type) {
        $this->type = $type;
    }
    // Return a descriptor of the set of papers relevant for this action.
    // Returns `""` or `"none"`.
    /** @param CsvRow $req
     * @return ''|'none' */
    function paper_universe($req, AssignmentState $state) {
        return "";
    }
    // Optionally expand the set of interesting papers. Returns a search
    // expression, such as "ALL", or false.
    //
    // `expand_papers` is called for *all* actions before any actions are
    // processed further.
    /** @param CsvRow $req
     * @return string */
    function expand_papers($req, AssignmentState $state) {
        return (string) $req["paper"];
    }
    // Load relevant state from the database into `$state`.
    function load_state(AssignmentState $state) {
    }
    // Return `true` iff this user may perform this action class on this paper.
    // To indicate an error, call `$state->paper_error($html)`, or,
    // equivalently, return `$html`. This is called before the action is fully
    // parsed, so it may be appropriate to return `true` here and perform the
    // actual permission check later.
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->user->can_administer($prow)
            && !$state->user->privChair) {
            return "You can’t administer #{$prow->paperId}.";
        } else if ($prow->timeWithdrawn > 0) {
            return "#{$prow->paperId} has been withdrawn.";
        } else if ($prow->timeSubmitted <= 0) {
            return "#{$prow->paperId} is not submitted.";
        } else {
            return true;
        }
    }
    // Return a descriptor of the set of users relevant for this action.
    // Returns `"none"`, `"pc"`, `"reviewers"`, `"pc+reviewers"`, or `"any"`.
    /** @param CsvRow $req
     * @return 'none'|'pc'|'reviewers'|'pc+reviewers'|'any' */
    function user_universe($req, AssignmentState $state) {
        return "pc";
    }
    // Return a conservative approximation of the papers relevant for this
    // action, or `false` if such an approximation is difficult to compute.
    // The approximation is an array whose keys are paper IDs; a truthy value
    // for pid X means the action applies to paper X.
    //
    // The assignment logic calls `paper_filter` when an action is applied to
    // an unusually large number of papers, such as removing all reviews by a
    // specific user.
    /** @param CsvRow $req */
    function paper_filter($contact, $req, AssignmentState $state) {
        return false;
    }
    // Return the list of users corresponding to user `"any"` for this request,
    // or false if `"any"` is an invalid user.
    /** @param CsvRow $req */
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        return false;
    }
    // Return the list of users relevant for this request, whose user is not
    // specified, or false if an explicit user is required.
    /** @param CsvRow $req */
    function expand_missing_user(PaperInfo $prow, $req, AssignmentState $state) {
        return false;
    }
    // Return the list of users corresponding to `$user`, which is an anonymous
    // user (either `anonymous\d*` or `anonymous-new`), or false if a
    // non-anonymous user is required.
    /** @param CsvRow $req */
    function expand_anonymous_user(PaperInfo $prow, $req, $user, AssignmentState $state) {
        return false;
    }
    // Return true iff this action may be applied to paper `$prow` and user
    // `$contact`. Note that `$contact` might not be a true database user;
    // for instance, it might have `contactId == 0` (for user `"none"`)
    // or it might have a negative `contactId` (for a user that doesn’t yet
    // exist in the database).
    /** @param CsvRow $req */
    function allow_user(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        return false;
    }
    // Apply this action to `$state`. Return `true` iff the action succeeds.
    // To indicate an error, call `$state->error($html)`, or, equivalently,
    // return `$html`.
    /** @param CsvRow $req */
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        return true;
    }
}

class UserlessAssignmentParser extends AssignmentParser {
    function __construct($type) {
        parent::__construct($type);
    }
    function user_universe($req, AssignmentState $state) {
        return "none";
    }
    function allow_user(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        return true;
    }
}

class Assigner {
    /** @var AssignmentItem */
    public $item;
    /** @var string */
    public $type;
    /** @var int */
    public $pid;
    /** @var ?int */
    public $cid;
    /** @var ?Contact */
    public $contact;
    public $next_index;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        $this->item = $item;
        $this->type = $item["type"];
        $this->pid = $item["pid"];
        $this->cid = $item["cid"] ? : $item["_cid"];
        if ($this->cid) {
            $this->contact = $state->user_by_id($this->cid);
        }
    }
    function unparse_description() {
        return "";
    }
    function unparse_display(AssignmentSet $aset) {
        return "";
    }
    /** @return void */
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
    }
    function account(AssignmentSet $aset, AssignmentCountSet $delta) {
    }
    function add_locks(AssignmentSet $aset, &$locks) {
    }
    function execute(AssignmentSet $aset) {
    }
    function cleanup(AssignmentSet $aset) {
    }
}

class Null_AssignmentParser extends UserlessAssignmentParser {
    function __construct() {
        parent::__construct("none");
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        return true;
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        return true;
    }
}

class ReviewAssigner_Data {
    public $oldround;
    public $newround;
    public $explicitround = false;
    public $oldtype;
    public $newtype;
    public $creator = true;
    public $error = false;
    static function separate($key, $req, $state, $rtype) {
        $a0 = $a1 = trim((string) $req[$key]);
        $require_match = $rtype ? false : $a0 !== "";
        if ($a0 === "" && $rtype != 0) {
            $a0 = $a1 = $state->defaults[$key] ?? null;
        }
        if ($a0 !== null && ($colon = strpos($a0, ":")) !== false) {
            $a1 = (string) substr($a0, $colon + 1);
            $a0 = (string) substr($a0, 0, $colon);
            $require_match = true;
        }
        $a0 = is_string($a0) ? trim($a0) : $a0;
        $a1 = is_string($a1) ? trim($a1) : $a1;
        if (strcasecmp($a0, "any") === 0) {
            $a0 = null;
            $require_match = true;
        }
        if (strcasecmp($a1, "any") === 0) {
            $a1 = null;
            $require_match = true;
        }
        return [$a0, $a1, $require_match];
    }
    function __construct($req, AssignmentState $state, $rtype) {
        list($targ0, $targ1, $tmatch) = self::separate("reviewtype", $req, $state, $rtype);
        if ((string) $targ0 !== ""
            && $tmatch) {
            if (strcasecmp($targ0, "none") === 0) {
                $this->oldtype = 0;
            } else if (($this->oldtype = ReviewInfo::parse_type($targ0)) === false) {
                $this->error = "Invalid review type.";
            }
        }
        if ((string) $targ1 !== ""
            && $rtype != 0
            && ($this->newtype = ReviewInfo::parse_type($targ1)) === false) {
            $this->error = "Invalid review type.";
        }
        if ($this->newtype === null) {
            $this->newtype = $rtype;
        }

        list($rarg0, $rarg1, $rmatch) = self::separate("round", $req, $state, $this->newtype);
        if ((string) $rarg0 !== ""
            && $rmatch
            && ($this->oldround = $state->conf->sanitize_round_name($rarg0)) === false) {
            $this->error = Conf::round_name_error($rarg0);
        }
        if ((string) $rarg1 !== ""
            && $this->newtype != 0
            && ($this->newround = $state->conf->sanitize_round_name($rarg1)) === false) {
            $this->error = Conf::round_name_error($rarg1);
        }
        if ($rarg0 !== "" && $rarg1 !== null) {
            $this->explicitround = (string) $req["round"] !== "";
        }
        if ($rarg0 === "") {
            $rmatch = false;
        }

        if ($this->oldtype === null && $rtype > 0 && $rmatch) {
            $this->oldtype = $rtype;
        }
        $this->creator = !$tmatch && !$rmatch && $this->newtype != 0;
    }
    /** @return ReviewAssigner_Data */
    static function make($req, AssignmentState $state, $rtype) {
        if (!isset($req["_review_data"]) || !is_object($req["_review_data"])) {
            $req["_review_data"] = new ReviewAssigner_Data($req, $state, $rtype);
        }
        return $req["_review_data"];
    }
    /** @return bool */
    function might_create_review() {
        return $this->creator;
    }
}


class AssignmentSet {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var string */
    public $filename = "";
    /** @var list<Assigner> */
    private $assigners = [];
    /** @var array<int,int> */
    private $assigners_pidhead = [];
    private $enabled_pids = null;
    private $enabled_actions = null;
    /** @var AssignmentState */
    private $astate;
    private $searches = [];
    private $search_type = "s";
    private $unparse_search = false;
    private $unparse_columns = [];
    private $assignment_type;
    /** @var array<string,array{callable,mixed}> */
    private $cleanup_callbacks = [];
    private $cleanup_notify_tracker = [];
    private $qe_stager;

    function __construct(Contact $user, $overrides = null) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->astate = new AssignmentState($user);
        $this->set_overrides($overrides);
    }

    /** @param string $search_type */
    function set_search_type($search_type) {
        $this->search_type = $search_type;
    }
    function set_reviewer(Contact $reviewer) {
        $this->astate->reviewer = $reviewer;
    }
    /** @param ?int|true $overrides */
    function set_overrides($overrides) {
        if ($overrides === null) {
            $overrides = $this->user->overrides();
        } else if ($overrides === true) {
            $overrides = $this->user->overrides() | Contact::OVERRIDE_CONFLICT;
        }
        if (!$this->user->privChair) {
            $overrides &= ~Contact::OVERRIDE_CONFLICT;
        }
        $this->astate->overrides = (int) $overrides;
    }
    /** @param int $flags */
    function set_flags($flags) {
        $this->astate->flags = $flags;
    }

    function enable_actions($action) {
        assert(empty($this->assigners));
        if ($this->enabled_actions === null) {
            $this->enabled_actions = [];
        }
        foreach (is_array($action) ? $action : [$action] as $a) {
            if (($aparser = $this->conf->assignment_parser($a, $this->user)))
                $this->enabled_actions[$aparser->type] = true;
        }
    }

    /** @param int|PaperInfo|list<int|PaperInfo> $paper */
    function enable_papers($paper) {
        assert(empty($this->assigners));
        if ($this->enabled_pids === null) {
            $this->enabled_pids = [];
        }
        foreach (is_array($paper) ? $paper : [$paper] as $p) {
            if ($p instanceof PaperInfo) {
                $this->astate->add_prow($p);
                $this->enabled_pids[] = $p->paperId;
            } else {
                $this->enabled_pids[] = (int) $p;
            }
        }
    }

    /** @return bool */
    function is_empty() {
        return empty($this->assigners);
    }

    /** @return bool */
    function has_messages() {
        return $this->astate->has_messages();
    }
    /** @return bool */
    function has_error() {
        return $this->astate->has_error;
    }
    /** @param null|false|int|string $landmark
     * @param string $msg
     * @return false */
    function error_at($landmark, $msg) {
        $this->astate->msg($landmark, $msg, 2);
        return false;
    }
    /** @param string $msg
     * @return false */
    function error_here($msg) {
        $this->astate->msg($this->astate->landmark, $msg, 2);
        return false;
    }
    /** @param null|false|int|string $landmark
     * @param string $msg
     * @return false */
    function warning_at($landmark, $msg) {
        $this->astate->msg($landmark, $msg, 1);
        return false;
    }
    /** @param string $msg
     * @return false */
    function warning_here($msg) {
        $this->astate->msg($this->astate->landmark, $msg, 2);
        return false;
    }
    function clear_errors() {
        $this->astate->clear_messages();
    }

    /** @return list<MessageItem> */
    function message_list() {
        return $this->astate->message_list();
    }
    /** @return list<string> */
    function messages_html($landmarks = false) {
        $es = [];
        foreach ($this->astate->message_list() as $mx) {
            $t = $mx->message;
            if ($landmarks && $mx->field) {
                $t = '<span class="lineno">' . htmlspecialchars($mx->field) . ':</span> ' . $t;
            }
            if (empty($es) || $es[count($es) - 1] !== $t) {
                $es[] = $t;
            }
        }
        return $es;
    }
    /** @return string */
    function messages_div_html($landmarks = false) {
        $es = $this->messages_html($landmarks);
        if (empty($es)) {
            return "";
        } else if ($landmarks) {
            return '<div class="parseerr"><p>' . join("</p>\n<p>", $es) . '</p></div>';
        } else if (count($es) == 1) {
            return $es[0];
        } else {
            return '<div><div class="mmm">' . join('</div><div class="mmm">', $es) . '</div></div>';
        }
    }
    /** @return list<string> */
    function message_texts($landmarks = false) {
        $es = [];
        foreach ($this->astate->message_list() as $mx) {
            $t = htmlspecialchars_decode(preg_replace(',<(?:[^\'">]|\'[^\']*\'|"[^"]*")*>,', "", $mx->message));
            if ($landmarks && $mx->field) {
                $t = $mx->field . ': ' . $t;
            }
            if (empty($es) || $es[count($es) - 1] !== $t) {
                $es[] = $t;
            }
        }
        return $es;
    }
    function report_errors() {
        if ($this->astate->has_messages() && $this->has_error()) {
            Conf::msg_error('Assignment errors: ' . $this->messages_div_html(true) . ' Please correct these errors and try again.');
        } else if ($this->astate->has_messages()) {
            Conf::msg_warning('Assignment warnings: ' . $this->messages_div_html(true));
        }
    }
    /** @return JsonResult */
    function json_result($landmarks = false) {
        if ($this->has_error()) {
            $jr = new JsonResult(403, ["ok" => false, "error" => $this->messages_div_html($landmarks)]);
            if ($this->astate->has_user_error) {
                $jr->status = 422;
                $jr->content["user_error"] = true;
            }
            return $jr;
        } else if ($this->astate->has_messages()) {
            return new JsonResult(["ok" => true, "response" => $this->messages_div_html($landmarks)]);
        } else {
            return new JsonResult(["ok" => true]);
        }
    }

    private static function req_user_html($req) {
        return Text::name_h($req["firstName"], $req["lastName"], $req["email"], NAME_E);
    }

    private static function apply_user_parts($req, $a) {
        foreach (["firstName", "lastName", "email"] as $i => $k) {
            if (!$req[$k] && ($a[$i] ?? null)) {
                $req[$k] = $a[$i];
            }
        }
    }

    /** @return string|false|list<Contact> */
    private function lookup_users($req, AssignmentParser $aparser) {
        // check user universe
        $users = $aparser->user_universe($req, $this->astate);
        if ($users === "none") {
            return [$this->astate->none_user()];
        }

        // move all usable identification data to email, firstName, lastName
        if (isset($req["name"])) {
            self::apply_user_parts($req, Text::split_name($req["name"]));
        }
        if (isset($req["user"])) {
            if (strpos($req["user"], " ") === false
                && strpos($req["user"], "@") !== false
                && !$req["email"]) {
                $req["email"] = $req["user"];
            } else {
                self::apply_user_parts($req, Text::split_name($req["user"], true));
            }
        }

        // extract email, first, last
        $first = $req["firstName"];
        $last = $req["lastName"];
        $email = trim((string) $req["email"]);
        $lemail = strtolower($email);
        $special = null;
        if ($lemail) {
            $special = $lemail;
        } else if (!$first && $last && strpos(trim($last), " ") === false) {
            $special = trim(strtolower($last));
        }
        $xspecial = $special;

        // check special: missing, "none", "any", "pc", "me", PC tag, "external"
        if ($special === "any" || $special === "all") {
            return "any";
        } else if ($special === "missing" || (!$first && !$last && !$lemail)) {
            return "missing";
        } else if ($special === "none") {
            return [$this->astate->none_user()];
        } else if (preg_match('/\A(?:(anonymous\d*)|new-?anonymous|anonymous-?new)\z/', $special, $m)) {
            return isset($m[1]) && $m[1] ? $m[1] : "anonymous-new";
        }
        if ($special && !$first && (!$lemail || !$last)) {
            $ret = ContactSearch::make_special($special, $this->astate->user);
            if (!$ret->has_error()) {
                return $ret->users();
            }
        }
        if (($special === "ext" || $special === "external")
            && $users === "reviewers") {
            $ret = [];
            foreach ($this->astate->reviewer_users() as $u) {
                if (!$u->is_pc_member())
                    $ret[] = $u;
            }
            return $ret;
        }

        // check for precise email match on existing contact (common case)
        if ($lemail && ($contact = $this->astate->user_by_email($email, false))) {
            return [$contact];
        }

        // check PC list
        if ($users === "pc") {
            $cset = $this->astate->pc_users();
            $cset_text = "PC member";
        } else if ($users === "reviewers") {
            $cset = $this->astate->reviewer_users();
            $cset_text = "reviewer";
        } else if ($users === "pc+reviewers") {
            $cset = $this->astate->pc_users() + $this->astate->reviewer_users();
            $cset_text = "PC/reviewer";
        } else {
            $cset = null;
            $cset_text = "user";
        }

        if ($cset) {
            $text = "";
            if ($first && $last) {
                $text = "$last, $first";
            } else if ($first || $last) {
                $text = "$last$first";
            }
            if ($email) {
                $text .= " <$email>";
            }
            $ret = ContactSearch::make_cset($text, $this->astate->user, $cset);
            if (count($ret->user_ids()) === 1) {
                return $ret->users();
            } else if (count($ret->user_ids()) > 1) {
                return $this->error_here("“" . self::req_user_html($req) . "” matches more than one $cset_text, use a full email address to disambiguate.");
            } else {
                return $this->error_here("No $cset_text matches “" . self::req_user_html($req) . "”.");
            }
        } else {
            // create contact
            if ($email
                && validate_email($email)
                && ($u = $this->astate->user_by_email($email, true, $req))) {
                return [$u];
            } else if (!$email) {
                return $this->error_here("Missing email address.");
            } else if (!validate_email($email)) {
                return $this->error_here("Email address “" . htmlspecialchars($email) . "” is invalid.");
            } else {
                return $this->error_here("Could not create user.");
            }
        }
    }

    static private function is_csv_header($req) {
        return !!preg_grep('/\A(?:action|assignment|paper|pid|paperid|id)\z/i', $req);
    }

    private function install_csv_header($csv) {
        if (!$csv->header()) {
            if (!($req = $csv->next_list())) {
                $this->error_at($csv->lineno(), "empty file");
                return false;
            }
            if (!self::is_csv_header($req)) {
                $csv->unshift($req);
                if (count($req) === 3
                    && (!$req[2] || strpos($req[2], "@") !== false)) {
                    $req = ["paper", "name", "email"];
                } else if (count($req) == 2) {
                    $req = ["paper", "user"];
                } else {
                    $req = ["paper", "action", "user", "round"];
                }
            }
            $csv->set_header($req);
        }

        foreach ([["action", "assignment", "type"],
                  ["paper", "pid", "paperid", "id", "search"],
                  ["firstName", "firstname", "first_name", "first", "givenname", "given_name"],
                  ["lastName", "lastname", "last_name", "last", "surname", "familyname", "family_name"],
                  ["reviewtype", "review_type"],
                  ["round", "review_round"],
                  ["preference", "pref", "revpref"],
                  ["expertise", "prefexp"],
                  ["tag_value", "tagvalue", "value", "index"],
                  ["conflict", "conflict_type", "conflicttype"],
                  ["withdraw_reason", "reason"]] as $ks) {
            for ($i = 1; $i < count($ks) && !$csv->has_column($ks[0]); ++$i) {
                $csv->add_synonym($ks[0], $ks[$i]);
            }
        }

        $has_action = $csv->has_column("action");
        if (!$has_action && !isset($this->astate->defaults["action"])) {
            $defaults = [];
            if ($csv->has_column("tag")) {
                $defaults[] = "tag";
            }
            if ($csv->has_column("preference")) {
                $defaults[] = "preference";
            }
            if ($csv->has_column("lead")) {
                $defaults[] = "lead";
            }
            if ($csv->has_column("shepherd")) {
                $defaults[] = "shepherd";
            }
            if ($csv->has_column("decision")) {
                $defaults[] = "decision";
            }
            if (count($defaults) == 1) {
                $this->astate->defaults["action"] = $defaults[0];
                if (in_array($defaults[0], ["lead", "shepherd", "manager"])) {
                    $csv->add_synonym("user", $defaults[0]);
                }
            }
        }

        if (!$has_action && !($this->astate->defaults["action"] ?? null)) {
            $this->error_at($csv->lineno(), "“action” column missing");
            return false;
        } else if (!$csv->has_column("paper")) {
            $this->error_at($csv->lineno(), "“paper” column missing");
            return false;
        } else {
            if (!isset($this->astate->defaults["action"])) {
                $this->astate->defaults["action"] = "<missing>";
            }
            return true;
        }
    }

    function hide_column($coldesc, $force = false) {
        if (!isset($this->unparse_columns[$coldesc]) || $force) {
            $this->unparse_columns[$coldesc] = false;
        }
    }

    function show_column($coldesc, $force = false) {
        if (!isset($this->unparse_columns[$coldesc]) || $force) {
            $this->unparse_columns[$coldesc] = true;
        }
    }

    function parse_csv_comment($line) {
        if (preg_match('/\A#\s*hotcrp_assign_display_search\s*(\S.*)\s*\z/', $line, $m)) {
            $this->unparse_search = $m[1];
        }
        if (preg_match('/\A#\s*hotcrp_assign_show\s+(\w+)\s*\z/', $line, $m)) {
            $this->show_column($m[1]);
        }
    }

    /** @param string $pfield
     * @param array<int,true> &$pids
     * @param bool $report_error
     * @return int */
    private function collect_papers($pfield, &$pids, $report_error) {
        $pfield = trim($pfield);
        if ($pfield === "") {
            if ($report_error) {
                $this->error_here("Missing paper.");
            }
            return 0;
        }
        if (preg_match('/\A[\d,\s]+\z/', $pfield)) {
            $npids = [];
            foreach (preg_split('/[,\s]+/', $pfield) as $pid) {
                if ($pid !== "") {
                    $npids[] = intval($pid);
                }
            }
            $val = 2;
        } else if ($pfield === "NONE") {
            return 1;
        } else {
            if (!isset($this->searches[$pfield])) {
                $search = new PaperSearch($this->user, ["q" => $pfield, "t" => $this->search_type, "reviewer" => $this->astate->reviewer]);
                $this->searches[$pfield] = $search->paper_ids();
                if ($report_error) {
                    foreach ($search->problem_texts() as $w) {
                        $this->error_here($w);
                    }
                }
            }
            $npids = $this->searches[$pfield];
            $val = 1;
        }
        if (empty($npids) && $report_error) {
            $this->astate->warning("No papers match “" . htmlspecialchars($pfield) . "”");
        }

        // Implement paper restriction
        if ($this->enabled_pids !== null) {
            $npids = array_intersect($npids, $this->enabled_pids);
        }

        foreach ($npids as $pid) {
            $pids[$pid] = true;
        }
        return $val;
    }

    /** @return ?AssignmentParser */
    private function collect_parser($req) {
        if (($action = $req["action"]) === null) {
            $action = $this->astate->defaults["action"];
        }
        $action = strtolower(trim($action));
        return $this->conf->assignment_parser($action, $this->user);
    }

    private function expand_special_user($user, AssignmentParser $aparser, PaperInfo $prow, $req) {
        if ($user === "any") {
            $u = $aparser->expand_any_user($prow, $req, $this->astate);
        } else if ($user === "missing") {
            $u = $aparser->expand_missing_user($prow, $req, $this->astate);
            if ($u === false || $u === null) {
                $this->astate->error("User required.");
                return false;
            }
        } else if (substr_compare($user, "anonymous", 0, 9) === 0) {
            $u = $aparser->expand_anonymous_user($prow, $req, $user, $this->astate);
        } else {
            $u = false;
        }
        if ($u === false || $u === null) {
            $this->astate->error("User “" . htmlspecialchars($user) . "” is not allowed here.");
        }
        return $u;
    }

    private function apply(AssignmentParser $aparser = null, $req) {
        // check action
        if (!$aparser) {
            if ($req["action"]) {
                return $this->error_here("Unknown action “" . htmlspecialchars($req["action"]) . "”.");
            } else {
                return $this->error_here("Missing action.");
            }
        }
        if ($this->enabled_actions !== null
            && !isset($this->enabled_actions[$aparser->type])) {
            return $this->error_here("Action " . htmlspecialchars($aparser->type) . " disabled.");
        }

        // parse paper
        $paper_universe = $aparser->paper_universe($req, $this->astate);
        if ($paper_universe === "none") {
            $pids = [];
            $pfield_straight = false;
        } else {
            $pidmap = [];
            $x = $this->collect_papers((string) $req["paper"], $pidmap, true);
            if (empty($pidmap)) {
                return false;
            }
            $pfield_straight = $x === 2;
            $pids = array_keys($pidmap);
        }

        // load state
        $aparser->load_state($this->astate);

        // clean user parts
        $contacts = $this->lookup_users($req, $aparser);
        if ($contacts === false || $contacts === null) {
            return false;
        }

        // maybe filter papers
        if (count($pids) > 20
            && is_array($contacts)
            && count($contacts) == 1
            && $contacts[0]->contactId > 0
            && ($pf = $aparser->paper_filter($contacts[0], $req, $this->astate))) {
            $npids = [];
            foreach ($pids as $p) {
                if ($pf[$p] ?? null)
                    $npids[] = $p;
            }
            $pids = $npids;
        }

        // fetch papers
        $this->astate->fetch_prows($pids);
        $this->astate->paper_exact_match = $pfield_straight;

        // check conflicts and perform assignment
        if ($paper_universe === "none") {
            $prow = $this->astate->placeholder_prow();
            $any_success = $this->apply_paper($prow, $contacts, $aparser, $req) === 1;
        } else {
            $any_success = false;
            foreach ($pids as $p) {
                $prow = $this->astate->prow($p);
                if (!$prow) {
                    $this->error_here(whyNotText($this->user->no_paper_whynot($p)));
                } else {
                    $ret = $this->apply_paper($prow, $contacts, $aparser, $req);
                    if ($ret === 1) {
                        $any_success = true;
                    } else if ($ret < 0) {
                        break;
                    }
                }
            }
        }

        if (!$any_success) {
            $this->astate->mark_matching_errors();
        }
        return $any_success;
    }

    /** @return 0|1|-1 */
    private function apply_paper(PaperInfo $prow, $contacts, AssignmentParser $aparser, $req) {
        $err = $aparser->allow_paper($prow, $this->astate);
        if ($err !== true) {
            if ($err === false) {
                $err = whyNotText($prow->make_whynot(["administer" => true]));
            }
            if (is_string($err)) {
                $this->astate->paper_error($err);
            }
            return 0;
        }

        // expand “all” and “missing”
        $pusers = $contacts;
        if (!is_array($pusers)) {
            $pusers = $this->expand_special_user($pusers, $aparser, $prow, $req);
            if ($pusers === false || $pusers === null) {
                return -1;
            }
        }

        $ret = 0;
        foreach ($pusers as $contact) {
            $err = $aparser->allow_user($prow, $contact, $req, $this->astate);
            if ($err === false) {
                if (!$contact->contactId) {
                    $this->astate->error("User “none” is not allowed here. [{$contact->email}]");
                    return -1;
                } else if ($prow->has_conflict($contact)) {
                    $err = $contact->name_h(NAME_E) . " has a conflict with #{$prow->paperId}.";
                } else {
                    $err = $contact->name_h(NAME_E) . " cannot be assigned to #{$prow->paperId}.";
                }
            }
            if ($err !== true) {
                if (is_string($err)) {
                    $this->astate->paper_error($err);
                }
                continue;
            }

            $err = $aparser->apply($prow, $contact, $req, $this->astate);
            if ($err !== true) {
                if (is_string($err)) {
                    $this->astate->error($err);
                }
            } else {
                $ret = 1;
            }
        }
        return $ret;
    }

    /** @param CsvParser|string|list<string> $text
     * @param string $filename */
    function parse($text, $filename = "", $defaults = null, $alertf = null) {
        assert(empty($this->assigners));
        $this->astate->filename = $this->filename = $filename;
        $this->astate->defaults = $defaults ? : [];

        if ($text instanceof CsvParser) {
            $csv = $text;
        } else {
            $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
            $csv->set_comment_chars("%#");
            $csv->set_comment_function(array($this, "parse_csv_comment"));
        }
        if (!$this->install_csv_header($csv)) {
            return false;
        }
        $has_landmark = $csv->has_column("landmark");

        $old_overrides = $this->user->set_overrides($this->astate->overrides);

        // parse file, load papers all at once
        $lines = $pids = [];
        while (($req = $csv->next_row()) !== false) {
            if (($aparser = $this->collect_parser($req))) {
                if ($aparser->paper_universe($req, $this->astate) === "none") {
                    $paper = "NONE";
                } else {
                    $paper = $aparser->expand_papers($req, $this->astate);
                }
            } else {
                $paper = (string) $req["paper"];
            }
            if ($has_landmark) {
                $landmark = $req["landmark"] ?? $csv->lineno();
            } else {
                $landmark = $csv->lineno();
            }
            $this->collect_papers($paper, $pids, false);
            $lines[] = [$landmark, $aparser, $req];
        }
        if (!empty($pids)) {
            $this->astate->landmark = $csv->lineno();
            $this->astate->fetch_prows(array_keys($pids), true);
        }

        // now apply assignment parsers
        foreach ($lines as $i => $linereq) {
            $this->astate->landmark = $linereq[0];
            if ($i % 100 == 0) {
                if ($alertf) {
                    call_user_func($alertf, $this, $linereq[0], $linereq[2]);
                }
                set_time_limit(30);
            }
            $this->apply($linereq[1], $linereq[2]);
        }
        if ($alertf) {
            call_user_func($alertf, $this, $csv->lineno(), false);
        }

        // call finishers
        foreach ($this->astate->finishers as $fin) {
            $fin->apply_finisher($this->astate);
        }

        // create assigners for difference
        $this->assigners_pidhead = $pidtail = [];
        foreach ($this->astate->diff() as $pid => $difflist) {
            foreach ($difflist as $item) {
                try {
                    $this->astate->landmark = $item->landmark;
                    if (($a = $item->realize($this->astate))) {
                        if ($a->pid > 0) {
                            $index = count($this->assigners);
                            if (isset($pidtail[$a->pid])) {
                                $pidtail[$a->pid]->next_index = $index;
                            } else {
                                $this->assigners_pidhead[$a->pid] = $index;
                            }
                            $pidtail[$a->pid] = $a;
                        }
                        $this->assigners[] = $a;
                    }
                } catch (Exception $e) {
                    $this->astate->error($e->getMessage());
                }
            }
        }

        $this->user->set_overrides($old_overrides);
    }

    function assigned_types() {
        $types = array();
        foreach ($this->assigners as $assigner) {
            $types[$assigner->type] = true;
        }
        ksort($types);
        return array_keys($types);
    }
    function assigned_pids($compress = false) {
        $pids = array_keys($this->assigners_pidhead);
        sort($pids, SORT_NUMERIC);
        if ($compress) {
            $xpids = array();
            $lpid = $rpid = -1;
            foreach ($pids as $pid) {
                if ($lpid >= 0 && $pid != $rpid + 1) {
                    $xpids[] = $lpid == $rpid ? $lpid : "$lpid-$rpid";
                }
                if ($lpid < 0 || $pid != $rpid + 1) {
                    $lpid = $pid;
                }
                $rpid = $pid;
            }
            if ($lpid >= 0) {
                $xpids[] = $lpid == $rpid ? $lpid : "$lpid-$rpid";
            }
            $pids = $xpids;
        }
        return $pids;
    }

    function type_description() {
        if ($this->assignment_type === null) {
            foreach ($this->assigners as $assigner) {
                $desc = $assigner->unparse_description();
                if ($this->assignment_type === null
                    || $this->assignment_type === $desc) {
                    $this->assignment_type = $desc;
                } else {
                    $this->assignment_type = "";
                }
            }
        }
        return $this->assignment_type;
    }

    function unparse_paper_assignment(PaperInfo $prow) {
        $assigners = [];
        for ($index = $this->assigners_pidhead[$prow->paperId] ?? null;
             $index !== null;
             $index = $assigner->next_index) {
            $assigners[] = $assigner = $this->assigners[$index];
        }
        usort($assigners, function ($assigner1, $assigner2) {
            $c1 = $assigner1->contact;
            $c2 = $assigner2->contact;
            '@phan-var ?Contact $c1';
            '@phan-var ?Contact $c2';
            if ($c1 && $c2 && $c1 !== $c2) {
                return call_user_func($this->conf->user_comparator(), $c1, $c2);
            } else if (!$c1 && $c2) {
                return 1;
            } else if ($c1 && !$c2) {
                return -1;
            } else {
                return strcmp($assigner1->type, $assigner2->type);
            }
        });
        $t = [];
        foreach ($assigners as $assigner) {
            if (($text = $assigner->unparse_display($this))) {
                $t[] = $text;
            }
        }
        if (!empty($t)) {
            return '<span class="nw">' . join(',</span> <span class="nw">', $t) . '</span>';
        } else {
            return "";
        }
    }
    function echo_unparse_display() {
        $deltarev = new AssignmentCountSet($this->conf);
        foreach ($this->assigners as $assigner) {
            $assigner->account($this, $deltarev);
        }

        $query = $this->assigned_pids(true);
        if ($this->unparse_search) {
            $query_order = "(" . $this->unparse_search . ") THEN HEADING:none " . join(" ", $query);
        } else {
            $query_order = empty($query) ? "NONE" : join(" ", $query);
        }
        foreach ($this->unparse_columns as $k => $v) {
            if ($v)
                $query_order .= " show:$k";
        }
        $query_order .= " show:autoassignment";
        $search = new PaperSearch($this->user, ["q" => $query_order, "t" => "viewable", "reviewer" => $this->astate->reviewer]);
        $plist = new PaperList("reviewers", $search);
        $plist->add_column("autoassignment", new AutoassignmentPaperColumn($this));
        $plist->set_table_id_class("foldpl", "pltable-fullw");
        echo $plist->table_html(["nofooter" => true]);

        if (count(array_intersect_key($deltarev->bypc, $this->conf->pc_members()))) {
            $summary = [];
            $tagger = new Tagger($this->user);
            $nrev = new AssignmentCountSet($this->conf);
            $deltarev->rev && $nrev->load_rev();
            $deltarev->lead && $nrev->load_lead();
            $deltarev->shepherd && $nrev->load_shepherd();
            foreach ($this->conf->pc_members() as $p) {
                if ($deltarev->get($p->contactId)->ass) {
                    $t = '<div class="ctelt"><div class="ctelti">'
                        . $this->user->reviewer_html_for($p) . ": "
                        . plural($deltarev->get($p->contactId)->ass, "assignment")
                        . self::review_count_report($nrev, $deltarev, $p, "After assignment:&nbsp;")
                        . "<hr class=\"c\" /></div></div>";
                    $summary[] = $t;
                }
            }
            if (!empty($summary)) {
                echo "<div class=\"g\"></div>\n",
                    "<h3>Summary</h3>\n",
                    '<div class="pc-ctable">', join("", $summary), "</div>\n";
            }
        }
    }

    /** @return AssignmentCsv */
    function make_acsv() {
        $acsv = new AssignmentCsv;
        foreach ($this->assigners as $assigner) {
            $assigner->unparse_csv($this, $acsv);
        }
        return $acsv;
    }

    /** @return ?PaperInfo */
    function prow($pid) {
        return $this->astate->prow($pid);
    }

    function execute($verbose = false) {
        if ($this->has_error() || empty($this->assigners)) {
            if ($verbose && $this->astate->has_messages()) {
                $this->report_errors();
            } else if ($verbose) {
                $this->conf->warnMsg("Nothing to assign.");
            }
            return !$this->has_error(); // true means no errors
        }

        // mark activity now to avoid DB errors later
        $this->user->mark_activity();

        // create new contacts, collect pids
        $locks = array("ContactInfo" => "read", "Paper" => "read", "PaperConflict" => "read");
        $this->conf->save_logs(true);
        $pids = [];
        foreach ($this->assigners as $assigner) {
            if (($u = $assigner->contact) && $u->contactId < 0) {
                $assigner->contact = $this->astate->register_user($u);
                $assigner->cid = $assigner->contact->contactId;
            }
            $assigner->add_locks($this, $locks);
            if ($assigner->pid > 0) {
                $pids[$assigner->pid] = true;
            }
        }

        // execute assignments
        $tables = [];
        foreach ($locks as $t => $type) {
            $tables[] = "$t $type";
        }
        $this->conf->qe("lock tables " . join(", ", $tables));

        foreach ($this->assigners as $assigner) {
            $assigner->execute($this);
        }

        if ($this->qe_stager) {
            call_user_func($this->qe_stager, null);
        }
        $this->conf->qe("unlock tables");

        // confirmation message
        if ($verbose && $this->conf->setting("pcrev_assigntime") == Conf::$now) {
            $this->conf->confirmMsg("Assignments saved! You may want to " . $this->conf->hotlink("send mail about the new assignments", "mail", "template=newpcrev") . ".");
        } else if ($verbose) {
            $this->conf->confirmMsg("Assignments saved!");
        }

        // clean up
        foreach ($this->assigners as $assigner) {
            $assigner->cleanup($this);
        }
        foreach ($this->cleanup_callbacks as $cb) {
            call_user_func($cb[0], $this, $cb[1]);
        }
        if (!empty($pids)) {
            $this->conf->update_autosearch_tags(array_keys($pids), $this->assigned_types());
        }
        if (!empty($this->cleanup_notify_tracker)
            && $this->conf->opt("trackerCometSite")) {
            MeetingTracker::contact_tracker_comet($this->conf, array_keys($this->cleanup_notify_tracker));
        }
        $this->conf->save_logs(false);

        return true;
    }

    function stage_qe($query /* ... */) {
        $this->stage_qe_apply($query, array_slice(func_get_args(), 1));
    }
    function stage_qe_apply($query, $args) {
        if (!$this->qe_stager) {
            $this->qe_stager = Dbl::make_multi_qe_stager($this->conf->dblink);
        }
        call_user_func($this->qe_stager, $query, $args);
    }

    /** @param string $name
     * @param callable $func */
    function cleanup_callback($name, $func, $arg = null) {
        if (!isset($this->cleanup_callbacks[$name])) {
            $this->cleanup_callbacks[$name] = [$func, null];
        }
        if (func_num_args() > 2) {
            $this->cleanup_callbacks[$name][1][] = $arg;
        }
    }
    function cleanup_update_rights() {
        $this->cleanup_callback("update_rights", "Contact::update_rights");
    }
    function cleanup_notify_tracker($pid) {
        $this->cleanup_notify_tracker[$pid] = true;
    }

    private static function _review_count_link($count, $word, $pl, $prefix, $pc) {
        $word = $pl ? plural($count, $word) : $count . "&nbsp;" . $word;
        if ($count == 0) {
            return $word;
        } else {
            return '<a class="qq" href="' . $pc->conf->hoturl("search", "q=" . urlencode("$prefix:$pc->email")) . '">' . $word . "</a>";
        }
    }

    private static function _review_count_report_one($ct, $pc) {
        $t = self::_review_count_link($ct->rev, "review", true, "re", $pc);
        $x = array();
        if ($ct->meta != 0) {
            $x[] = self::_review_count_link($ct->meta, "meta", false, "meta", $pc);
        }
        if ($ct->pri != $ct->rev && (!$ct->meta || $ct->meta != $ct->rev)) {
            $x[] = self::_review_count_link($ct->pri, "primary", false, "pri", $pc);
        }
        if ($ct->sec != 0 && $ct->sec != $ct->rev && $ct->pri + $ct->sec != $ct->rev) {
            $x[] = self::_review_count_link($ct->sec, "secondary", false, "sec", $pc);
        }
        if (!empty($x)) {
            $t .= " (" . join(", ", $x) . ")";
        }
        return $t;
    }

    static function review_count_report($nrev, $deltarev, $pc, $prefix) {
        $data = [];
        $ct = $nrev->get($pc->contactId);
        $deltarev && ($ct = $ct->add($deltarev->get($pc->contactId)));
        if (!$deltarev || $deltarev->rev) {
            $data[] = self::_review_count_report_one($ct, $pc);
        }
        if ($deltarev && $deltarev->lead) {
            $data[] = self::_review_count_link($ct->lead, "lead", true, "lead", $pc);
        }
        if ($deltarev && $deltarev->shepherd) {
            $data[] = self::_review_count_link($ct->shepherd, "shepherd", true, "shepherd", $pc);
        }
        return '<span class="pcrevsum">' . $prefix . join(", ", $data) . "</span>";
    }

    static function run($contact, $text, $forceShow = null) {
        $aset = new AssignmentSet($contact, $forceShow);
        $aset->parse($text);
        return $aset->execute();
    }
}


class AutoassignmentPaperColumn extends PaperColumn {
    /** @var AssignmentSet */
    private $aset;
    function __construct(AssignmentSet $aset) {
        parent::__construct($aset->conf, (object) ["name" => "autoassignment", "prefer_row" => true, "className" => "pl_autoassignment"]);
        $this->aset = $aset;
        $this->override = PaperColumn::OVERRIDE_IFEMPTY_LINK;
    }
    function header(PaperList $pl, $is_text) {
        return "Assignment";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_administer($row)
            && !($pl->user->overrides() & Contact::OVERRIDE_CONFLICT);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $t = $this->aset->unparse_paper_assignment($row);
        if ($t !== ""
            && ($pl->user->overrides() & Contact::OVERRIDE_CONFLICT)
            && !$pl->user->can_administer($row)) {
            $t = '<em>Hidden for conflict</em>';
        }
        return $t;
    }
}
