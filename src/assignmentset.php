<?php
// assignmentset.php -- HotCRP helper classes for assignments
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Assignable {
    /** @var string */
    public $type;
    /** @var int */
    public $pid;

    /** @return self */
    function fresh() {
        return new Assignable;
    }

    /** @param Assignable $q
     * @return bool */
    function match($q) {
        foreach (get_object_vars($q) as $k => $v) {
            if ($v !== null && $this->$k !== $v)
                return false;
        }
        return true;
    }

    /** @param Assignable $q
     * @return bool */
    function equals($q) {
        return $this->match($q);
    }
}

class AssignmentItem implements ArrayAccess, JsonSerializable {
    /** @var Assignable
     * @readonly */
    public $before;
    /** @var ?Assignable
     * @readonly */
    public $after;
    /** @var bool
     * @readonly */
    public $existed;
    /** @var bool
     * @readonly */
    public $deleted = false;
    /** @var null|int|string */
    public $landmark;
    /** @param Assignable $before
     * @param bool $existed */
    function __construct($before, $existed) {
        $this->before = $before;
        $this->existed = $existed;
    }
    /** @return string */
    function type() {
        return $this->before->type;
    }
    /** @return int */
    function pid() {
        return $this->before->pid;
    }
    #[\ReturnTypeWillChange]
    /** @param string $offset
     * @return bool */
    function offsetExists($offset) {
        $x = $this->after ?? $this->before;
        return isset($x->$offset);
    }
    #[\ReturnTypeWillChange]
    /** @param string $offset */
    function offsetGet($offset) {
        $x = $this->after ?? $this->before;
        return $x->$offset ?? null;
    }
    #[\ReturnTypeWillChange]
    function offsetSet($offset, $value) {
        throw new Exception("invalid AssignmentItem::offsetSet");
    }
    #[\ReturnTypeWillChange]
    function offsetUnset($offset) {
        throw new Exception("invalid AssignmentItem::offsetUnset");
    }
    /** @return bool */
    function existed() {
        return $this->existed;
    }
    /** @return bool */
    function deleted() {
        return $this->deleted;
    }
    /** @return bool */
    function edited() {
        return !!$this->after;
    }
    /** @return bool */
    function changed() {
        return $this->after
            && ($this->deleted
                ? $this->existed
                : !$this->existed || !$this->after->equals($this->before));
    }
    /** @param bool $pre
     * @param string $offset */
    function get($pre, $offset) {
        if (!$pre && $this->after) {
            return $this->after->$offset ?? null;
        } else {
            return $this->before->$offset ?? null;
        }
    }
    /** @param string $offset */
    function pre($offset) {
        return $this->before->$offset ?? null;
    }
    /** @param string $offset */
    function post($offset) {
        $x = $this->after ?? $this->before;
        return $x->$offset ?? null;
    }
    /** @param string $offset
     * @return bool */
    function differs($offset) {
        return $this->pre($offset) !== $this->post($offset);
    }
    /** @param null|int|string $landmark
     * @return Assignable
     * @suppress PhanAccessReadOnlyProperty */
    function delete_at($landmark) {
        $r = $this->after ?? clone $this->before;
        if ($this->existed) {
            $this->after = $this->before->fresh();
            $this->deleted = true;
        } else {
            $this->after = null;
        }
        $this->landmark = $landmark;
        return $r;
    }
    function realize(AssignmentState $astate) {
        return call_user_func($astate->realizer($this->offsetGet("type")), $this, $astate);
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $x = [
            "type" => $this->before->type,
            "pid" => $this->before->pid,
            "\$status" => $this->deleted
                ? "DELETED"
                : ($this->existed ? ($this->after ? "MODIFIED" : "UNCHANGED") : "INSERTED")
        ];
        foreach (get_object_vars($this->after ?? $this->before) as $k => $v) {
            if ($k !== "type" && $k !== "pid") {
                $x[$k] = $v;
            }
        }
        return $x;
    }
}

class AssignmentItemSet {
    /** @var array<int|string,AssignmentItem> */
    public $items = [];
}

class AssignmentState extends MessageSet {
    /** @var array<int,AssignmentItemSet> */
    private $st = [];
    /** @var array<string,list<string>> */
    private $types = [];
    /** @var array<string,callable(AssignmentItem,AssignmentState):Assigner> */
    private $realizers = [];
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;     // executor
    /** @var Contact */
    public $reviewer; // default contact
    /** @var int */
    public $overrides = 0;
    /** @var bool */
    public $csv_context = false;
    /** @var int */
    public $potential_conflict_warnings = 0;
    /** @var AssignerContacts */
    private $cmap;
    /** @var ?array<int,Contact> */
    private $reviewer_users = null;
    /** @var ?string */
    private $filename;
    /** @var null|int|string */
    private $landmark;
    /** @var array<string,mixed> */
    public $defaults = [];
    /** @var array<int,PaperInfo> */
    private $prows = [];
    /** @var array<int,true> */
    private $pid_attempts = [];
    /** @var ?PaperInfo */
    private $placeholder_prow;
    /** @var bool */
    public $paper_exact_match = true;
    /** @var list<MessageItem> */
    private $nonexact_msgs = [];
    /** @var bool */
    public $has_user_error = false;
    /** @var array<string,AssignmentPreapplyFunction> */
    private $preapply_functions = [];

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $this->reviewer = $user;
        $this->cmap = new AssignerContacts($this->conf, $this->user);
        $this->set_want_ftext(true);
        $this->overrides = $user->overrides();
    }

    /** @param ?string $filename */
    function set_filename($filename) {
        $this->filename = $filename;
    }
    /** @param null|int|string $landmark */
    function set_landmark($landmark) {
        $this->landmark = $landmark;
    }
    /** @param null|int|string $landmark
     * @return string */
    function landmark_near($landmark) {
        if (is_string($landmark)) {
            return $landmark;
        } else if ($this->filename === null) {
            return "";
        } else if ($landmark === null || $landmark === 0) {
            return $this->filename;
        } else if ($this->filename === "") {
            return "line {$landmark}";
        } else {
            return "{$this->filename}:{$landmark}";
        }
    }
    /** @return string */
    function landmark() {
        return $this->landmark_near($this->landmark);
    }

    /** @param string $type
     * @param list<string> $keys
     * @param callable(AssignmentItem,AssignmentState):Assigner $realizer
     * @return bool */
    function mark_type($type, $keys, $realizer) {
        if (!isset($this->types[$type])) {
            $this->types[$type] = $keys;
            $this->realizers[$type] = $realizer;
            return true;
        } else {
            return false;
        }
    }
    /** @param string $type
     * @return callable(AssignmentItem,AssignmentState):Assigner */
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
    /** @param Assignable $x
     * @return ?string */
    private function extract_key($x, $pid = null) {
        $t = $x->type;
        foreach ($this->types[$x->type] as $k) {
            if (isset($x->$k)) {
                $t .= "`" . $x->$k;
            } else if ($pid !== null && $k === "pid") {
                $t .= "`" . $pid;
            } else {
                return null;
            }
        }
        assert($t !== $x->type);
        return $t;
    }
    /** @param Assignable $x */
    function load($x) {
        $st = $this->pidstate($x->pid);
        $k = $this->extract_key($x);
        if (!$k || isset($st->items[$k])) { // XXXX
            error_log(json_encode($k) . " / " . debug_string_backtrace());
        }
        assert($k && !isset($st->items[$k]));
        $st->items[$k] = new AssignmentItem($x, true);
    }

    /** @return list<int> */
    private function pid_keys($q) {
        '@phan-var-force Assignable $q';
        if (isset($q->pid)) {
            return [$q->pid];
        } else {
            return array_keys($this->st);
        }
    }
    const INCLUDE_DELETED = 1;
    /** @param 0|1 $include_deleted
     * @return list<AssignmentItem> */
    function query_items($q, $include_deleted = 0) {
        '@phan-var-force Assignable $q';
        $res = [];
        foreach ($this->pid_keys($q) as $pid) {
            $st = $this->pidstate($pid);
            $k = $this->extract_key($q, $pid);
            foreach ($k ? [$st->items[$k] ?? null] : $st->items as $item) {
                if ($item
                    && (!$item->deleted() || $include_deleted)
                    && $item->before->type === $q->type
                    && ($item->after ?? $item->before)->match($q)) {
                    $res[] = $item;
                }
            }
        }
        return $res;
    }
    /** @template T
     * @param T $q
     * @return list<T> */
    function query($q) {
        $res = [];
        foreach ($this->query_items($q) as $item) {
            $res[] = $item->after ?? $item->before;
        }
        return $res;
    }
    /** @template T
     * @param T $q
     * @return list<T> */
    function query_unedited($q) {
        $res = [];
        foreach ($this->query_items($q) as $item) {
            if (!$item->edited())
                $res[] = $item->before;
        }
        return $res;
    }
    /** @param Assignable $q
     * @return array */
    function make_filter($key, $q) {
        $cf = [];
        foreach ($this->query($q) as $m) {
            $cf[$m->$key] = true;
        }
        return $cf;
    }

    /** @template T
     * @param T $q
     * @return list<T> */
    function remove($q) {
        $res = [];
        foreach ($this->query_items($q) as $item) {
            $res[] = $item->delete_at($this->landmark);
        }
        return $res;
    }
    /** @template T
     * @param T $q
     * @param callable(T):bool $predicate
     * @return list<T> */
    function remove_if($q, $predicate) {
        $res = [];
        foreach ($this->query_items($q) as $item) {
            if (!$predicate
                || call_user_func($predicate, $item->after ?? $item->before)) {
                $res[] = $item->delete_at($this->landmark);
            }
        }
        return $res;
    }
    /** @param Assignable $x
     * @return AssignmentItem
     * @suppress PhanAccessReadOnlyProperty */
    function add($x) {
        $k = $this->extract_key($x);
        assert(!!$k);
        $st = $this->pidstate($x->pid);
        if (!($item = $st->items[$k] ?? null)) {
            $item = $st->items[$k] = new AssignmentItem($x->fresh(), false);
        }
        $item->after = $x;
        $item->deleted = false;
        $item->landmark = $this->landmark;
        return $item;
    }

    /** @return array<int,list<AssignmentItem>> */
    function diff() {
        $diff = [];
        foreach ($this->st as $pid => $st) {
            foreach ($st->items as $item) {
                if ($item->changed())
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
        $pids = is_array($pids) ? $pids : [$pids];
        $fetch_pids = [];
        foreach ($pids as $pid) {
            if (!isset($this->prows[$pid]) && !isset($this->pid_attempts[$pid]))
                $fetch_pids[] = $pid;
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
            $this->placeholder_prow = PaperInfo::make_placeholder($this->conf, -1);
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

    /** @param null|int|string $landmark
     * @param string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return MessageItem */
    function msg_near($landmark, $msg, $status) {
        $l = $this->landmark_near($landmark);
        if (($mi = $this->back_message())
            && $mi->landmark === $l
            && $mi->message === $msg) {
            $this->change_item_status($mi, $status);
        } else {
            $mi = $this->msg_at(null, $msg, $status);
            $mi->landmark = $l;
        }
        return $mi;
    }
    /** @param string $msg
     * @return void */
    function warning($msg) {
        $this->msg_near($this->landmark, $msg, 1);
    }
    /** @param string $msg
     * @return void */
    function error($msg) {
        $this->msg_near($this->landmark, $msg, 2);
    }
    /** @param string $msg
     * @return void */
    function user_error($msg) {
        $this->has_user_error = true;
        $this->error($msg);
    }
    /** @param string $msg
     * @return void */
    function paper_error($msg) {
        if ($this->paper_exact_match) {
            $this->msg_near($this->landmark, $msg, 2);
        } else {
            $this->nonexact_msgs[] = $this->msg_near($this->landmark, $msg, 1);
        }
    }
    function mark_matching_errors() {
        foreach ($this->nonexact_msgs as $mi) {
            $this->change_item_status($mi, 2);
        }
        $this->nonexact_msgs = [];
    }
    function clear_messages() {
        parent::clear_messages();
        $this->nonexact_msgs = [];
        $this->has_user_error = false;
    }

    /** @param string $name
     * @param AssignmentPreapplyFunction $hook
     * @return AssignmentPreapplyFunction */
    function register_preapply_function($name, $hook) {
        if (!isset($this->preapply_functions[$name])) {
            $this->preapply_functions[$name] = $hook;
        }
        return $this->preapply_functions[$name];
    }
    function call_preapply_functions() {
        foreach ($this->preapply_functions as $f) {
            $f->preapply($this);
        }
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

    function __construct(Conf $conf, Contact $viewer) {
        $this->conf = $conf;
        $this->viewer = $viewer;
        if (($user = Contact::$main_user)
            && $user->contactId > 0
            && $user->conf === $conf) {
            $this->store($user);
        }
        $this->conf->ensure_cached_user_collaborators();
        $this->by_id[0] = Contact::make($conf);
    }

    /** @return string */
    function user_query_fields() {
        return $this->conf->user_query_fields(Contact::SLICE_MINIMAL - Contact::SLICEBIT_COLLABORATORS, "ContactInfo.");
    }

    /** @return string */
    function contactdb_user_query_fields() {
        return $this->conf->contactdb_user_query_fields(Contact::SLICE_MINIMAL - Contact::SLICEBIT_COLLABORATORS);
    }

    /** @return Contact */
    private function store(Contact $u) {
        if ($u->contactId != 0) {
            if (isset($this->by_id[$u->contactId])) {
                return $this->by_id[$u->contactId];
            }
            $this->by_id[$u->contactId] = $u;
        }
        if ($u->email) {
            $this->by_lemail[strtolower($u->email)] = $u;
        }
        return $u;
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
        return $this->by_id[0];
    }
    /** @param int $cid
     * @return Contact */
    function user_by_id($cid) {
        if (!$cid) {
            return $this->none_user();
        }
        if (($u = $this->by_id[$cid] ?? null)) {
            return $u;
        }
        $this->ensure_pc();
        if (($u = $this->by_id[$cid] ?? null)) {
            return $u;
        }
        $result = $this->conf->qe("select " . $this->user_query_fields() . " from ContactInfo where contactId=?", $cid);
        $u = Contact::fetch($result, $this->conf)
            ?? Contact::make_keyed($this->conf, ["email" => "unknown contact {$cid}", "contactId" => $cid]);
        Dbl::free($result);
        return $this->store($u);
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
        $result = $this->conf->qe("select " . $this->user_query_fields() . " from ContactInfo where email=?", $lemail);
        $c = Contact::fetch($result, $this->conf);
        Dbl::free($result);
        if (!$c && $create) {
            $is_anonymous = Contact::is_anonymous_email($email);
            assert(validate_email($email) || $is_anonymous);
            if (($cdb = $this->conf->contactdb()) && validate_email($email)) {
                $result = Dbl::qe($cdb, "select " . $this->contactdb_user_query_fields() . " from ContactInfo where email=?", $lemail);
                $c = Contact::fetch($result, $this->conf);
                Dbl::free($result);
            }
            if (!$c) {
                $cargs = ["email" => $email];
                if ($req) {
                    $cargs["firstName"] = $req["firstName"] ?? "";
                    $cargs["lastName"] = $req["lastName"] ?? "";
                    $cargs["affiliation"] = $req["affiliation"] ?? "";
                }
                if ($is_anonymous) {
                    $cargs["firstName"] = "Jane Q.";
                    $cargs["lastName"] = "Public";
                    $cargs["affiliation"] = "Unaffiliated";
                    $cargs["disablement"] = Contact::CF_UDISABLED;
                }
                $c = Contact::make_keyed($this->conf, $cargs);
            }
            $c->contactId = $c->contactXid;
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
        $result = $this->conf->qe("select " . $this->user_query_fields() . " from ContactInfo join PaperReview using (contactId) where (roles&" . Contact::ROLE_PC . ")=0 and paperId?a and reviewType>0 group by ContactInfo.contactId", $pids);
        while ($result && ($c = Contact::fetch($result, $this->conf))) {
            $rset[$c->contactId] = $this->store($c);
        }
        Dbl::free($result);
        return $rset;
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

class AssignmentError extends Exception {
    /** @param string|PermissionProblem $message */
    function __construct($message) {
        if ($message instanceof PermissionProblem) {
            $message = "<5>" . $message->unparse_html();
        } else if ($message !== "" && !Ftext::is_ftext($message)) {
            error_log("not ftext {$message}: " . debug_string_backtrace());
        }
        parent::__construct($message);
    }
}

abstract class AssignmentParser {
    /** @var string $type */
    public $type;
    /** @param string $type */
    function __construct($type) {
        $this->type = $type;
    }
    // Return a descriptor of the set of papers relevant for this action.
    // `"req"`, the default, means call `apply` for each requested paper.
    // `"none"` means call `apply` exactly once with a placeholder paper.
    // `"reqpost"` means each requested paper, then once with a placeholder.
    /** @param CsvRow $req
     * @return 'req'|'none'|'reqpost' */
    function paper_universe($req, AssignmentState $state) {
        return "req";
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
    // To indicate an error, return an `AssignmentError` or simply `false`
    // (which means the user cannot administer the submission).
    // Called before the action is fully parsed, so it may be appropriate to
    // return `true` here and perform the full permission check later.
    /** @return bool|AssignmentError */
    abstract function allow_paper(PaperInfo $prow, AssignmentState $state);

    // Return a descriptor of the set of users relevant for this action.
    // Returns `"none"`, `"pc"`, `"reviewers"`, `"pc+reviewers"`, or `"any"`.
    /** @param CsvRow $req
     * @return 'none'|'pc'|'reviewers'|'pc+reviewers'|'any' */
    function user_universe($req, AssignmentState $state) {
        return "pc";
    }

    // Return a conservative approximation of the papers relevant for this
    // action, or `null` if such an approximation is difficult to compute.
    // The approximation is an array whose keys are paper IDs; a truthy value
    // for pid X means the action applies to paper X.
    //
    // The assignment logic calls `paper_filter` when an action is applied to
    // an unusually large number of papers, such as removing all reviews by a
    // specific user.
    /** @param CsvRow $req
     * @return ?array */
    function paper_filter($contact, $req, AssignmentState $state) {
        return null;
    }

    // Return the list of users corresponding to user `"any"` for this request,
    // or null if `"any"` is an invalid user.
    /** @param CsvRow $req
     * @return ?array<Contact> */
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        return null;
    }

    // Return the list of users relevant for this request, whose user is not
    // specified, or false if an explicit user is required.
    /** @param CsvRow $req
     * @return ?array<Contact> */
    function expand_missing_user(PaperInfo $prow, $req, AssignmentState $state) {
        return null;
    }

    // Return the list of users corresponding to `$user`, which is an anonymous
    // user (either `anonymous\d*` or `anonymous-new`), or null if a
    // non-anonymous user is required.
    /** @param CsvRow $req
     * @return ?array<Contact> */
    function expand_anonymous_user(PaperInfo $prow, $req, $user, AssignmentState $state) {
        return null;
    }

    // Return true iff this action may be applied to paper `$prow` and user
    // `$contact`. Note that `$contact` might not be a true database user;
    // for instance, it might have `contactId == 0` (for user `"none"`)
    // or it might have a negative `contactId` (for a user that doesn’t yet
    // exist in the database).
    /** @param CsvRow $req
     * @return bool|AssignmentError */
    abstract function allow_user(PaperInfo $prow, Contact $contact, $req, AssignmentState $state);

    // Apply this action to `$state`. Return `true` iff the action succeeds.
    // To indicate an error, call `$state->error($ftext)` and return `false`,
    // or, equivalently, return an `AssignmentError`.
    /** @param CsvRow $req
     * @return bool|AssignmentError */
    abstract function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state);
}

abstract class UserlessAssignmentParser extends AssignmentParser {
    /** @param string $type */
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
    /** @var ?int */
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
    /** @return string */
    function unparse_description() {
        return "";
    }
    /** @return string */
    function unparse_display(AssignmentSet $aset) {
        return "";
    }
    /** @return void */
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
    }
    /** @return void */
    function account(AssignmentSet $aset, AssignmentCountSet $delta) {
    }
    /** @return void */
    function add_locks(AssignmentSet $aset, &$locks) {
    }
    /** @return void */
    function execute(AssignmentSet $aset) {
    }
    /** @return void */
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
    /** @var ?int */
    public $oldround;
    /** @var ?int */
    public $newround;
    /** @var bool */
    public $explicitround = false;
    /** @var ?int */
    public $oldtype;
    /** @var ?int */
    public $newtype;
    /** @var bool */
    public $creator = true;
    /** @var ?string */
    public $error_ftext;

    /** @return array{?string,?string,bool} */
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
        if ($a0 !== null && strcasecmp($a0, "any") === 0) {
            $a0 = null;
            $require_match = true;
        }
        if ($a1 !== null && strcasecmp($a1, "any") === 0) {
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
            } else if (($this->oldtype = ReviewInfo::parse_type($targ0, true)) === false) {
                $this->error_ftext = "<0>Invalid review type ‘{$targ0}’";
            }
        }
        if ((string) $targ1 !== ""
            && $rtype != 0
            && ($this->newtype = ReviewInfo::parse_type($targ1, true)) === false) {
            $this->error_ftext = "<0>Invalid review type ‘{$targ1}’";
        }
        if ($this->newtype === null) {
            $this->newtype = $rtype;
        }

        list($rarg0, $rarg1, $rmatch) = self::separate("round", $req, $state, $this->newtype);
        if ((string) $rarg0 !== ""
            && $rmatch
            && ($this->oldround = $state->conf->round_number($rarg0)) === null) {
            $this->error_ftext = "<0>Review round ‘{$rarg0}’ not found";
        }
        if ((string) $rarg1 !== ""
            && $this->newtype != 0
            && ($this->newround = $state->conf->round_number($rarg1)) === null) {
            $this->error_ftext = "<0>Review round ‘{$rarg1}’ not found";
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

interface AssignmentPreapplyFunction {
    function preapply(AssignmentState $astate);
}


class AssignmentSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var string */
    private $search_type = "all";
    /** @var ?array<int,true> */
    private $enabled_pids;
    /** @var ?array<string,true> */
    private $enabled_actions;
    /** @var int */
    private $request_count = 0;
    /** @var list<callable> */
    private $progressf = [];
    /** @var list<Assigner> */
    private $assigners = [];
    /** @var array<int,int> */
    private $assigners_pidhead = [];
    /** @var AssignmentState */
    private $astate;
    /** @var array<string,list<int>> */
    private $searches = [];
    /** @var array<string,list<MessageItem>> */
    private $search_messages = [];
    /** @var ?string */
    private $unparse_search;
    /** @var array<string,bool> */
    private $unparse_columns = [];
    /** @var ?string */
    private $assignment_type;
    /** @var array<string,array{callable,mixed}> */
    private $_cleanup_callbacks = [];
    private $_cleanup_notify_tracker = [];
    private $qe_stager;

    function __construct(Contact $user, $overrides = null) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->astate = new AssignmentState($user);
        if ($overrides !== null) {
            // XXX backwards compat
            error_log(debug_string_backtrace());
            $this->set_overrides($overrides);
        }
    }

    /** @param callable(AssignmentSet,?CsvRow) $progressf
     * @return $this
     * @deprecated */
    function add_progress_handler($progressf) {
        $this->progressf[] = $progressf;
        return $this;
    }
    /** @param callable(AssignmentSet,?CsvRow) $progressf
     * @return $this */
    function add_progress_function($progressf) {
        $this->progressf[] = $progressf;
        return $this;
    }
    /** @param string $search_type
     * @return $this */
    function set_search_type($search_type) {
        $this->search_type = $search_type;
        return $this;
    }
    /** @return $this */
    function set_reviewer(Contact $reviewer) {
        $this->astate->reviewer = $reviewer;
        return $this;
    }
    /** @param int $overrides
     * @return $this */
    function set_overrides($overrides) {
        if ($overrides === null) { // XXX backward compat
            $overrides = $this->user->overrides();
        } else if ($overrides === true) { // XXX backward compat
            $overrides = $this->user->overrides() | Contact::OVERRIDE_CONFLICT;
        }
        $this->astate->overrides = (int) $overrides;
        return $this;
    }
    /** @return $this
     * @deprecated */
    function override_conflicts() {
        return $this->set_overrides($this->user->overrides() | Contact::OVERRIDE_CONFLICT);
    }
    /** @param bool $override
     * @return $this */
    function set_override_conflicts($override) {
        if ($override) {
            $this->astate->overrides |= Contact::OVERRIDE_CONFLICT;
        } else {
            $this->astate->overrides &= ~Contact::OVERRIDE_CONFLICT;
        }
        return $this;
    }
    /** @param bool $csv_context
     * @return $this */
    function set_csv_context($csv_context) {
        $this->astate->csv_context = $csv_context;
        return $this;
    }

    /** @param string|list<string> $action */
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
                $this->enabled_pids[$p->paperId] = true;
            } else {
                $this->enabled_pids[(int) $p] = true;
            }
        }
    }

    /** @return int */
    function overrides() {
        return $this->astate->overrides;
    }
    /** @return bool */
    function is_empty() {
        return empty($this->assigners);
    }
    /** @return string */
    function landmark() {
        return $this->astate->landmark();
    }

    /** @return MessageSet */
    function message_set() {
        return $this->astate;
    }
    /** @return bool */
    function has_message() {
        return $this->astate->has_message();
    }
    /** @return bool */
    function has_error() {
        return $this->astate->has_error();
    }
    /** @param null|int|string $landmark
     * @param string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return MessageItem */
    function msg_near($landmark, $msg, $status) {
        return $this->astate->msg_near($landmark, $msg, $status);
    }
    /** @param string $msg
     * @return void */
    function error($msg) {
        $this->astate->msg_near($this->astate->landmark(), $msg, 2);
    }
    /** @param string $msg
     * @return void */
    function warning($msg) {
        $this->astate->msg_near($this->astate->landmark(), $msg, 1);
    }

    /** @return list<MessageItem> */
    function message_list() {
        return $this->astate->message_list();
    }
    /** @param string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return $this */
    function prepend_msg($msg, $status) {
        $this->astate->prepend_msg($msg, $status);
        return $this;
    }
    /** @return string */
    function full_feedback_text() {
        return $this->astate->full_feedback_text();
    }
    function report_errors() {
        if ($this->astate->has_message()) {
            if ($this->astate->has_error()) {
                $this->astate->prepend_msg("<0>Changes not saved due to errors in the assignment", MessageSet::ERROR);
            }
            $this->conf->feedback_msg($this->astate->message_list());
        } else if (empty($this->assigners)) {
            $this->conf->feedback_msg([new MessageItem(null, "<0>No changes", MessageSet::WARNING_NOTE)]);
        }
    }
    /** @return JsonResult */
    function json_result() {
        if ($this->has_error()) {
            $status = $this->astate->has_user_error ? 200 : 403;
            return new JsonResult($status, ["ok" => false, "message_list" => $this->message_list()]);
        } else if ($this->astate->has_message()) {
            return new JsonResult(["ok" => true, "message_list" => $this->message_list()]);
        } else {
            return new JsonResult(["ok" => true]);
        }
    }

    private static function req_user_text($req) {
        return Text::name($req["firstName"], $req["lastName"], $req["email"], NAME_E);
    }

    private static function apply_user_parts($req, $a) {
        foreach (["firstName", "lastName", "email"] as $i => $k) {
            if (!$req[$k] && ($a[$i] ?? null)) {
                $req[$k] = $a[$i];
            }
        }
    }

    /** @return null|string|list<Contact> */
    private function lookup_users($req, AssignmentParser $aparser) {
        // check user universe
        $users = $aparser->user_universe($req, $this->astate);
        if ($users === "none") {
            return [$this->astate->none_user()];
        }

        // check for `userid`/`uid`
        if (($req["uid"] ?? "") !== "") {
            if (ctype_digit($req["uid"])
                && ($u = $this->astate->user_by_id($req["uid"]))) {
                return [$u];
            } else {
                $this->error("<0>User ID ‘" . $req["uid"] . "’ not found");
                return null;
            }
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
        $special = "";
        if ($lemail) {
            $special = $lemail;
        } else if (!$first && $last && strpos(trim($last), " ") === false) {
            $special = trim(strtolower($last));
        }

        // check special: missing, "none", "any", "pc", "me", PC tag, "external"
        if ($special === "any" || $special === "all") {
            return "any";
        } else if ($special === "missing" || (!$first && !$last && !$lemail)) {
            return "missing";
        } else if ($special === "none") {
            return [$this->astate->none_user()];
        } else if ($special !== ""
                   && preg_match('/\A(?:(anonymous\d*)|new-?anonymous|anonymous-?new)\z/', $special, $m)) {
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
        if ($lemail && ($u = $this->astate->user_by_email($email, false))) {
            return [$u];
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
                $this->error("<0>‘" . self::req_user_text($req) . "’ matches more than one {$cset_text}");
                $this->astate->msg_near($this->astate->landmark(), "<0>Use a full email address to disambiguate.", MessageSet::INFORM);
                return null;
            } else {
                $this->error("<0>" . ucfirst($cset_text) . " ‘" . self::req_user_text($req) . "’ not found");
                return null;
            }
        } else if ($email
                   && validate_email($email)
                   && ($u = $this->astate->user_by_email($email, true, $req))) {
            // create contact
            return [$u];
        } else {
            if (!$email) {
                $this->error("<0>Email address required");
            } else if (!validate_email($email)) {
                $this->error("<0>Email address ‘{$email}’ invalid");
            } else {
                $this->error("<0>Could not create user");
            }
            return null;
        }
    }

    /** @param list<string> $req
     * @return bool */
    static private function is_csv_header($req) {
        return !!preg_grep('/\A(?:action|assignment|paper|pid|paperid|id)\z/i', $req);
    }

    /** @param CsvParser $csv */
    private function install_csv_header($csv) {
        $had_header = !!$csv->header();
        $def_action = $this->astate->defaults["action"] ?? null;

        if (!$csv->header()) {
            if (!($req = $csv->next_list())) {
                $this->msg_near(null, "<0>Empty file", 2);
                return false;
            }
            if (self::is_csv_header($req)) {
                // found header
            } else if (!$def_action) {
                $this->msg_near(null, "<0>CSV header required", 2);
                $this->msg_near(null, "<5>CSV assignment files must define ‘<code>action</code>’ and ‘<code>paper</code>’ columns. Add a CSV header line to tell me what the columns mean.", MessageSet::INFORM);
                return false;
            } else {
                $csv->unshift($req);
                if ($def_action === "settag") {
                    $req = ["paper", "tag"];
                } else if ($def_action === "preference") {
                    $req = ["paper", "user", "preference"];
                } else {
                    $req = ["paper", "user"];
                }
            }
            $csv->set_header($req);
        }

        foreach ([["action", "assignment", "type"],
                  ["paper", "pid", "paperid", "paper_id", "id", "search"],
                  ["uid", "userid", "user_id"],
                  ["firstName", "firstname", "first_name", "first", "givenname", "given_name"],
                  ["lastName", "lastname", "last_name", "last", "surname", "familyname", "family_name"],
                  ["reviewtype", "review_type"],
                  ["round", "review_round"],
                  ["preference", "pref", "revpref"],
                  ["expertise", "prefexp"],
                  ["tag_value", "tagvalue", "value", "index"],
                  ["new_tag", "newtag"],
                  ["conflict", "conflict_type", "conflicttype"],
                  ["withdraw_reason", "reason"]] as $ks) {
            for ($i = 1; $i < count($ks) && !$csv->has_column($ks[0]); ++$i) {
                $csv->add_synonym($ks[0], $ks[$i]);
            }
        }

        $has_action = $csv->has_column("action");
        if (!$has_action && $def_action === null) {
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
                $def_action = $this->astate->defaults["action"] = $defaults[0];
                if (in_array($defaults[0], ["lead", "shepherd", "manager"])) {
                    $csv->add_synonym("user", $defaults[0]);
                }
            }
        }

        if ((!$has_action && !$def_action)
            || !$csv->has_column("paper")) {
            $this->msg_near(null, "<0>CSV must define “action” and “paper” columns", 2);
            return false;
        }

        $this->astate->defaults["action"] = $def_action ?? "<missing>";
        return true;
    }

    /** @param string $coldesc
     * @param bool $force */
    function hide_column($coldesc, $force = false) {
        if (!isset($this->unparse_columns[$coldesc]) || $force) {
            $this->unparse_columns[$coldesc] = false;
        }
    }

    /** @param string $coldesc
     * @param bool $force */
    function show_column($coldesc, $force = false) {
        if (!isset($this->unparse_columns[$coldesc]) || $force) {
            $this->unparse_columns[$coldesc] = true;
        }
    }

    /** @param string $line */
    function parse_csv_comment($line) {
        if (preg_match('/\A###\s*hotcrp_assign_display_search\s*(\S.*)\s*\z/', $line, $m)) {
            $this->unparse_search = $m[1];
        }
        if (preg_match('/\A###\s*hotcrp_assign_show\s+(\w+)\s*\z/', $line, $m)) {
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
                $this->error("<0>Paper required");
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
                $this->searches[$pfield] = $search->sorted_paper_ids();
                if ($search->has_problem()) {
                    $this->search_messages[$pfield] = $search->message_list();
                }
            }
            $npids = $this->searches[$pfield];
            if ($report_error) {
                foreach ($this->search_messages[$pfield] ?? [] as $mi) {
                    $this->astate->append_item($mi->with_landmark($this->astate->landmark()));
                }
            }
            $val = 1;
        }
        if (empty($npids) && $report_error) {
            $this->astate->warning("<0>No papers match ‘{$pfield}’");
        }

        // Implement paper restriction
        $all = $this->enabled_pids === null;
        foreach ($npids as $pid) {
            if ($all || isset($this->enabled_pids[$pid]))
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

    /** @return ?list<Contact> */
    private function expand_special_user($user, AssignmentParser $aparser, PaperInfo $prow, $req) {
        if ($user === "any") {
            $us = $aparser->expand_any_user($prow, $req, $this->astate);
        } else if ($user === "missing") {
            $us = $aparser->expand_missing_user($prow, $req, $this->astate);
            if ($us === null) {
                $this->astate->error("<0>User required");
                return null;
            }
        } else if (substr_compare($user, "anonymous", 0, 9) === 0) {
            $us = $aparser->expand_anonymous_user($prow, $req, $user, $this->astate);
        } else {
            $us = null;
        }
        if ($us === null) {
            $this->astate->error("<0>User ‘{$user}’ not allowed here");
        }
        return $us;
    }

    /** @param CsvRow $req
     * @return void */
    private function apply_req(AssignmentParser $aparser = null, $req) {
        // check action
        if (!$aparser) {
            if ($req["action"]) {
                $this->error("<0>Action ‘" . $req["action"] . "’ not found");
            } else {
                $this->error("<0>Action missing");
            }
            return;
        } else if ($this->enabled_actions !== null
                   && !isset($this->enabled_actions[$aparser->type])) {
            $this->error("<0>Action ‘{$aparser->type}’ not allowed");
            return;
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
                return;
            }
            $pfield_straight = $x === 2;
            $pids = array_keys($pidmap);
        }

        // load state
        $aparser->load_state($this->astate);

        // clean user parts
        $contacts = $this->lookup_users($req, $aparser);
        if ($contacts === null) {
            return;
        }

        // maybe filter papers
        if (count($pids) > 20
            && is_array($contacts)
            && count($contacts) == 1
            && $contacts[0]->contactId > 0
            && ($pf = $aparser->paper_filter($contacts[0], $req, $this->astate)) !== null) {
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
        $any_success = false;
        foreach ($pids as $p) {
            $prow = $this->astate->prow($p);
            if (!$prow) {
                $this->error("<5>" . $this->user->no_paper_whynot($p)->unparse_html());
            } else {
                $ret = $this->apply_paper($prow, $contacts, $aparser, $req);
                if ($ret === 1) {
                    $any_success = true;
                } else if ($ret < 0) {
                    break;
                }
            }
        }
        if ($paper_universe === "none" || $paper_universe === "reqpost") {
            $prow = $this->astate->placeholder_prow();
            $ret = $this->apply_paper($prow, $contacts, $aparser, $req);
            if ($ret === 1) {
                $any_success = true;
            }
        }

        if (!$any_success) {
            $this->astate->mark_matching_errors();
        }
    }

    /** @param list<Contact>|string $contacts
     * @param CsvRow $req
     * @return 0|1|-1 */
    private function apply_paper(PaperInfo $prow, $contacts, AssignmentParser $aparser, $req) {
        $allow = $aparser->allow_paper($prow, $this->astate);
        if ($allow !== true) {
            $allow = $allow ? : new AssignmentError($prow->make_whynot(["administer" => true]));
            $this->astate->paper_error($allow->getMessage());
            return 0;
        }

        // expand “all” and “missing”
        $pusers = $contacts;
        if (!is_array($pusers)) {
            $pusers = $this->expand_special_user($pusers, $aparser, $prow, $req);
            if ($pusers === null) {
                return -1;
            }
        }

        $ret = 0;
        foreach ($pusers as $contact) {
            $err = $aparser->allow_user($prow, $contact, $req, $this->astate);
            if ($err !== true) {
                if (!$err && !$contact->contactId) {
                    $this->astate->error("<0>User ‘none’ not allowed here");
                    return -1;
                } else if (!$err) {
                    $uname = $contact->name(NAME_E);
                    $problem = $prow->has_conflict($contact) ? "has a conflict with" : "cannot be assigned to";
                    $err = new AssignmentError("<0>{$uname} {$problem} #{$prow->paperId}");
                }
                $this->astate->paper_error($err->getMessage());
                continue;
            }

            $err = $aparser->apply($prow, $contact, $req, $this->astate);
            if ($err === true) {
                $ret = 1;
            } else if ($err) {
                $this->astate->error($err->getMessage());
            }
        }
        return $ret;
    }

    /** @param CsvParser|string|list<string> $text
     * @param ?string $filename
     * @param ?array<string,mixed> $defaults
     * @return $this */
    function parse($text, $filename = null, $defaults = null) {
        assert(empty($this->assigners));

        if ($text instanceof CsvParser) {
            $csv = $text;
            assert($filename === null || $csv->filename() === $filename);
        } else {
            $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
            $csv->set_comment_start("###");
            $csv->set_comment_function([$this, "parse_csv_comment"]);
            $csv->set_filename($filename);
        }
        $this->astate->set_filename($csv->filename());

        $this->astate->defaults = $defaults ?? [];

        if (!$this->install_csv_header($csv)) {
            return $this;
        }

        $has_landmark = $csv->has_column("landmark");
        $old_overrides = $this->user->set_overrides($this->astate->overrides);

        // parse file, load papers all at once
        $lines = $pids = [];
        while (($req = $csv->next_row())) {
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
            $this->astate->set_landmark($csv->lineno());
            $this->astate->fetch_prows(array_keys($pids), true);
        }

        // apply assignment parsers
        foreach ($lines as $linereq) {
            $this->astate->set_landmark($linereq[0]);
            ++$this->request_count;
            if ($this->request_count % 100 === 0) {
                foreach ($this->progressf as $progressf) {
                    call_user_func($progressf, $this, $linereq[2]);
                }
                set_time_limit(30);
            }
            $this->apply_req($linereq[1], $linereq[2]);
        }

        // call preapply functions
        $this->astate->call_preapply_functions();

        // create assigners for difference
        $this->assigners_pidhead = $pidtail = [];
        foreach ($this->astate->diff() as $difflist) {
            foreach ($difflist as $item) {
                try {
                    $this->astate->set_landmark($item->landmark);
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
                    if ($e->getMessage() !== "") {
                        $this->astate->error($e->getMessage());
                    }
                }
            }
        }

        $this->astate->set_landmark($csv->lineno());
        foreach ($this->progressf as $progressf) {
            call_user_func($progressf, $this, null);
        }
        $this->user->set_overrides($old_overrides);
        return $this;
    }

    /** @return list<string> */
    function assigned_types() {
        $types = [];
        foreach ($this->assigners as $assigner) {
            $types[$assigner->type] = true;
        }
        ksort($types);
        return array_keys($types);
    }

    /** @return list<int> */
    function assigned_pids() {
        $pids = array_keys($this->assigners_pidhead);
        sort($pids, SORT_NUMERIC);
        return $pids;
    }

    /** @return int */
    function assignment_count() {
        return count($this->assigners);
    }

    /** @return list<Assigner> */
    function assignments() {
        return $this->assigners;
    }

    /** @return int */
    function request_count() {
        return $this->request_count;
    }

    /** @param string $joiner
     * @return string */
    function numjoin_assigned_pids($joiner) {
        return join($joiner, unparse_numrange_list($this->assigned_pids()));
    }

    /** @return string */
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

    /** @param int $pid
     * @return string */
    function unparse_paper_assignment($pid) {
        $assigners = [];
        $index = $this->assigners_pidhead[$pid] ?? null;
        while ($index !== null) {
            $assigners[] = $this->assigners[$index];
            $index = $this->assigners[$index]->next_index;
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

    /** @return Assignment_PaperColumn */
    function unparse_paper_column() {
        $pc = new Assignment_PaperColumn($this->user, $this->astate->reviewer);

        foreach ($this->assigners_pidhead as $pid => $n) {
            if (($t = $this->unparse_paper_assignment($pid)) !== "") {
                $pc->content[$pid] = $t;
            }
        }

        $pc->change_counts = new AssignmentCountSet($this->user);
        foreach ($this->assigners as $assigner) {
            $assigner->account($this, $pc->change_counts);
        }

        // Compute query last; unparse/account may show columns
        $q = $this->numjoin_assigned_pids(" ") ? : "NONE";
        if ($this->unparse_search) {
            $q = "({$this->unparse_search}) THEN LEGEND:none $q";
        }
        foreach ($this->unparse_columns as $k => $v) {
            if ($v)
                $q .= " show:{$k}";
        }
        $pc->search_query = "{$q} show:autoassignment";

        return $pc;
    }

    function print_unparse_display() {
        Assignment_PaperColumn::print_unparse_display($this->unparse_paper_column());
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

    /** @param bool $verbose
     * @return bool */
    function execute($verbose = false) {
        if ($this->has_error() || empty($this->assigners)) {
            $verbose && $this->report_errors();
            return !$this->has_error(); // true means no errors
        }

        // mark activity now to avoid DB errors later
        $this->user->mark_activity();

        // create new contacts, collect pids
        $locks = ["ContactInfo" => "read", "Paper" => "read", "PaperConflict" => "read"];
        $this->conf->delay_logs();
        $pids = [];
        foreach ($this->assigners as $assigner) {
            if (($u = $assigner->contact) && $u->contactId < 0) {
                $u->store($u->is_anonymous_user() ? Contact::SAVE_ANY_EMAIL : 0, $this->user);
                $assigner->cid = $u->contactId;
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
            $this->conf->feedback_msg(
                MessageItem::success("<0>Assignments saved"),
                MessageItem::inform("<5>You may want to " . $this->conf->hotlink("send mail about the new assignments", "mail", "template=newpcrev") . ".")
            );
        } else if ($verbose) {
            $this->conf->success_msg("<0>Assignments saved");
        }

        // clean up
        foreach ($this->assigners as $assigner) {
            $assigner->cleanup($this);
        }
        foreach ($this->_cleanup_callbacks as $cb) {
            call_user_func($cb[0], $cb[1]);
        }
        if (!empty($pids)) {
            $this->conf->update_automatic_tags(array_keys($pids), $this->assigned_types());
        }
        if (!empty($this->_cleanup_notify_tracker)
            && $this->conf->opt("trackerCometSite")) {
            MeetingTracker::notify_tracker($this->conf, array_keys($this->_cleanup_notify_tracker));
        }
        $this->conf->release_logs();

        return true;
    }

    function stage_qe($query, ...$args) {
        $this->stage_qe_apply($query, $args);
    }
    function stage_qe_apply($query, $args) {
        if (!$this->qe_stager) {
            $this->qe_stager = Dbl::make_multi_qe_stager($this->conf->dblink);
        }
        call_user_func($this->qe_stager, $query, ...$args);
    }

    /** @param string $name
     * @param callable $func */
    function register_cleanup_function($name, $func, ...$args) {
        if (!isset($this->_cleanup_callbacks[$name])) {
            $this->_cleanup_callbacks[$name] = [$func, null];
        }
        if (!empty($args)) {
            assert(count($args) === 1);
            $this->_cleanup_callbacks[$name][1][] = $args[0];
        }
    }
    function register_update_rights() {
        $this->register_cleanup_function("update_rights", "Contact::update_rights");
    }
    /** @param int $pid */
    function register_notify_tracker($pid) {
        $this->_cleanup_notify_tracker[$pid] = true;
    }
}


class Assignment_PaperColumn extends PaperColumn {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var ?Contact */
    public $reviewer;
    /** @var string */
    public $search_query;
    /** @var array<int,string> */
    public $content = [];
    /** @var ?AssignmentCountSet */
    public $change_counts;
    function __construct(Contact $user, Contact $reviewer = null) {
        parent::__construct($user->conf, (object) ["name" => "autoassignment", "prefer_row" => true, "className" => "pl_autoassignment"]);
        $this->conf = $user->conf;
        $this->user = $user;
        $this->reviewer = $reviewer;
        $this->override = PaperColumn::OVERRIDE_IFEMPTY_LINK;
    }
    function header(PaperList $pl, $is_text) {
        return "Assignment";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !isset($this->content[$row->paperId])
            || !$pl->user->can_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $this->content[$row->paperId];
    }

    static function print_unparse_display(Assignment_PaperColumn $pc) {
        $search = new PaperSearch($pc->user, ["q" => $pc->search_query, "t" => "viewable", "reviewer" => $pc->reviewer]);
        $plist = new PaperList("reviewers", $search);
        $plist->add_column($pc);
        $plist->set_table_id_class("foldpl", null);
        $plist->set_table_decor(PaperList::DECOR_HEADER | PaperList::DECOR_FULLWIDTH);
        echo '<div class="pltable-fullw-container demargin">';
        $plist->print_table_html();
        echo '</div>';

        if (count(array_intersect_key($pc->change_counts->bypc, $pc->conf->pc_members()))) {
            $summary = [];
            $current_counts = AssignmentCountSet::load($pc->user, $pc->change_counts->has);
            $current_counts->add($pc->change_counts);
            foreach ($pc->conf->pc_members() as $p) {
                if ($pc->change_counts->get($p->contactId)->ass) {
                    $t = '<div class="ctelt"><div class="ctelti">'
                        . $pc->user->reviewer_html_for($p) . ": "
                        . plural($pc->change_counts->get($p->contactId)->ass, "assignment")
                        . $current_counts->unparse_counts_for($p, "After assignment: ")
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
}
