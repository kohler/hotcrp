<?php
// assignmentset.php -- HotCRP helper classes for assignments
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

abstract class Assignable {
    /** @var int */
    public $pid;

    /** @return string */
    abstract function type();

    /** @return self */
    abstract function fresh();

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
        return $this->before->type();
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
    /** @param bool $pre
     * @param string $offset
     * @return int */
    function get_i($pre, $offset) {
        if (!$pre && $this->after) {
            return $this->after->$offset ?? null;
        } else {
            return $this->before->$offset ?? null;
        }
    }
    /** @param string $offset
     * @return int */
    function pre_i($offset) {
        return $this->before->$offset;
    }
    /** @param string $offset
     * @return int */
    function post_i($offset) {
        return ($this->after ?? $this->before)->$offset;
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
        return call_user_func($astate->realizer($this->type()), $this, $astate);
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $x = [
            "type" => $this->type(),
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
    /** @var int */
    private $stversion = 0;
    /** @var array<string,list<string>> */
    private $types = [];
    /** @var array<string,callable(AssignmentItem,AssignmentState):Assigner> */
    private $realizers = [];
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;     // executor
    /** @var Contact */
    public $reviewer; // default contact
    /** @var int */
    public $overrides = 0;
    /** @var bool */
    public $csv_context = false;
    /** @var int */
    public $potential_conflict_warnings = 0;
    /** @var bool */
    public $confirm_potential_conflicts = false;
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
    /** @var list<int> */
    private $pid_attempts = [];
    /** @var ?PaperInfo */
    private $placeholder_prow;
    /** @var bool */
    public $paper_exact_match = false;
    /** @var list<MessageItem> */
    private $nonexact_msgs = [];
    /** @var bool */
    public $has_user_error = false;
    private $callables = [];

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

    /** @param null|int|string|AssignmentItem $landmark
     * @return string */
    function landmark_near($landmark) {
        if (is_object($landmark)) {
            $landmark = $landmark->landmark;
        }
        if (is_string($landmark)) {
            return $landmark === "" ? null : $landmark;
        } else if ($this->filename === null) {
            return null;
        } else if ($landmark === null || $landmark === 0) {
            return $this->filename;
        } else if ($this->filename === "") {
            return "line {$landmark}";
        }
        return "{$this->filename}:{$landmark}";
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
        if (isset($this->types[$type])) {
            return false;
        }
        $this->types[$type] = $keys;
        $this->realizers[$type] = $realizer;
        return true;
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
        $t = $x->type();
        foreach ($this->types[$x->type()] as $k) {
            if (isset($x->$k)) {
                $t .= "`" . $x->$k;
            } else if ($pid !== null && $k === "pid") {
                $t .= "`" . $pid;
            } else {
                return null;
            }
        }
        assert($t !== $x->type());
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
                    && $item->type() === $q->type()
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

    /** @return int */
    function state_version() {
        return $this->stversion;
    }
    /** @template T
     * @param T $q
     * @return list<T> */
    function remove($q) {
        $res = [];
        foreach ($this->query_items($q) as $item) {
            $res[] = $item->delete_at($this->landmark);
            ++$this->stversion;
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
                ++$this->stversion;
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
        ++$this->stversion;
        return $item;
    }

    /** @return list<AssignmentItem> */
    function diff_list() {
        $diff = [];
        foreach ($this->st as $pid => $st) {
            foreach ($st->items as $item) {
                if ($item->changed())
                    $diff[] = $item;
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
        if (!$p) {
            assert(!empty($this->pid_attempts)
                   && ($this->pid_attempts[0] === -1
                       || in_array((int) $pid, $this->pid_attempts, true)));
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
    function fetch_prows($pids) {
        assert(empty($this->pid_attempts));
        $pids = is_array($pids) ? $pids : [$pids];
        if (!empty($this->prows)) {
            $pids = array_values(array_filter($pids, function ($p) {
                return !isset($this->prows[$p]);
            }));
        }
        if (!empty($pids)) {
            foreach ($this->user->paper_set(["paperId" => $pids]) as $prow) {
                $this->prows[$prow->paperId] = $prow;
            }
        }
        $this->pid_attempts = [0];
        foreach ($pids as $pid) {
            if (!isset($this->prows[$pid]))
                $this->pid_attempts[] = $pid;
        }
    }
    function fetch_all_prows() {
        assert(empty($this->prows) && empty($this->pid_attempts));
        foreach ($this->user->paper_set([]) as $prow) {
            $this->prows[$prow->paperId] = $prow;
        }
        $this->pid_attempts = [-1];
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
     * @return MessageItem
     * @deprecated */
    function msg_near($landmark, $msg, $status) {
        $l = $this->landmark_near($landmark);
        if (($mi = $this->back_message())
            && $mi->landmark === $l
            && $mi->message === $msg) {
            $this->change_item_status($mi, $status);
        } else {
            $mi = $this->append_item(new MessageItem($status, null, $msg));
            $mi->landmark = $l;
        }
        return $mi;
    }
    /** @param MessageItem $mi
     * @param null|int|string|AssignmentItem $landmark
     * @return MessageItem */
    function append_item_near($mi, $landmark = null) {
        $mi = $mi->with_landmark($this->landmark_near($landmark));
        if (($bmi = $this->back_message())
            && $bmi->landmark === $mi->landmark
            && $bmi->message === $mi->message) {
            $this->change_item_status($bmi, $mi->status);
            return $bmi;
        }
        return $this->append_item($mi);
    }
    /** @param MessageItem $mi
     * @return MessageItem */
    function append_item_here($mi) {
        return $this->append_item_near($mi, $this->landmark);
    }
    /** @param string $msg
     * @return void */
    function error($msg) {
        $this->append_item_here(MessageItem::error($msg));
    }
    /** @param string $msg
     * @return void */
    function warning($msg) {
        $this->append_item_here(MessageItem::warning($msg));
    }
    /** @param ?string $msg
     * @return void */
    function user_error($msg = null) {
        $this->has_user_error = true;
        $this->error($msg);
    }
    /** @param string $msg
     * @return void */
    function paper_error($msg) {
        if ($this->paper_exact_match) {
            $this->append_item_here(MessageItem::error($msg));
        } else {
            $this->nonexact_msgs[] = $this->append_item_here(MessageItem::warning($msg));
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

    /** @template T
     * @param class-string<T> $name
     * @return T */
    function callable($name, ...$args) {
        if (!isset($this->callables[$name])) {
            $this->callables[$name] = new $name($this, ...$args);
        }
        return $this->callables[$name];
    }

    function call_preapply_functions() {
        foreach ($this->callables as $k) {
            if ($k instanceof AssignmentPreapplyFunction)
                $k->preapply($this);
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
    /** @return list<string> */
    function header() {
        return array_keys($this->fields);
    }
    /** @return list<array<string,int|string>> */
    function rows() {
        return $this->rows;
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
    /** @param string|FailureReason $message */
    function __construct($message) {
        if ($message instanceof FailureReason) {
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

    // Initialize parser for a request.
    // The request passed to `set_req` is used for subsequent `user_universe`,
    // `paper_filter`, `expand_*user`, `allow_user`, `allow_paper`, and `apply`
    // calls. Returns false if `$req` is erroneous and should not be parsed
    // further.
    /** @param CsvRow $req
     * @return bool */
    function set_req($req, AssignmentState $state) {
        return true;
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
    /** @var AssignmentItem
     * @readonly */
    public $item;
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
        $this->pid = $item["pid"];
        $this->cid = $item["cid"] ? : $item["_cid"];
        if ($this->cid) {
            $this->contact = $state->user_by_id($this->cid);
        }
    }
    /** @return string */
    function type() {
        return $this->item->type();
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

    /** @param string $key
     * @return array{?string,?string,bool} */
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
    /** @var array<string,AssignmentParser> */
    private $parsers = [];
    /** @var ?array<string,true> */
    private $enabled_actions;
    /** @var int */
    private $request_count = 0;
    /** @var int */
    private $progress_phase = 0;
    /** @var ?int */
    private $progress_value;
    /** @var ?int */
    private $progress_max;
    /** @var list<callable> */
    private $progressf = [];
    /** @var list<Assigner> */
    private $assigners = [];
    /** @var array<int,int> */
    private $assigners_pidhead = [];
    /** @var int */
    private $executed = 0;
    /** @var AssignmentState */
    private $astate;
    /** @var array<string,PaperSearch> */
    private $searches = [];
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

    const PROGPHASE_PARSE = 1;
    const PROGPHASE_PREAPPLY = 2;
    const PROGPHASE_REALIZE = 3;
    const PROGPHASE_APPLY = 4;
    const PROGPHASE_UNPARSE = 5;
    const PROGPHASE_SAVE = 6;

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

    /** @param callable(AssignmentSet) $progressf
     * @return $this */
    function add_progress_function($progressf) {
        $this->progressf[] = $progressf;
        return $this;
    }

    /** @param ?array{int,?int} $valmax
     * @param ?CsvRow $row */
    private function notify_progress($valmax = null, $row = null) {
        if ($valmax !== null) {
            $this->progress_value = $valmax[0];
            $this->progress_max = $valmax[1];
        }
        foreach ($this->progressf as $progressf) {
            call_user_func($progressf, $this, $row);
        }
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
    /** @param bool $confirm
     * @return $this */
    function set_confirm_potential_conflicts($confirm) {
        $this->astate->confirm_potential_conflicts = $confirm;
        return $this;
    }

    /** @param string|list<string> $action
     * @return $this */
    function enable_actions($action) {
        assert(empty($this->assigners));
        if ($this->enabled_actions === null) {
            $this->enabled_actions = [];
        }
        foreach (is_array($action) ? $action : [$action] as $a) {
            if (($aparser = $this->assignment_parser($a)))
                $this->enabled_actions[$aparser->type] = true;
        }
        return $this;
    }

    /** @param int|PaperInfo|list<int|PaperInfo> $paper
     * @return $this */
    function enable_papers($paper) {
        assert(empty($this->assigners));
        $this->enabled_pids = $this->enabled_pids ?? [];
        foreach (is_array($paper) ? $paper : [$paper] as $p) {
            if ($p instanceof PaperInfo) {
                $this->astate->add_prow($p);
                $this->enabled_pids[$p->paperId] = true;
            } else {
                $this->enabled_pids[(int) $p] = true;
            }
        }
        return $this;
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
    /** @return int */
    function progress_phase() {
        return $this->progress_phase;
    }
    /** @return ?int */
    function progress_value() {
        return $this->progress_value;
    }
    /** @return ?int */
    function progress_max() {
        return $this->progress_max;
    }

    /** @return MessageSet */
    function message_set() {
        return $this->astate;
    }
    /** @param ?int $max
     * @return list<MessageItem> */
    function message_list($max = null) {
        $ml = $this->astate->message_list();
        if ($max !== null && count($ml) > $max) {
            $elided = count($ml) - $max + 1;
            array_splice($ml, $max - 1);
            $ml[] = MessageItem::warning_note($this->conf->_("<0>...{} more messages elided", $elided));
        }
        return $ml;
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
     * @return MessageItem
     * @deprecated
     * @suppress PhanDeprecatedFunction */
    function msg_near($landmark, $msg, $status) {
        return $this->astate->msg_near($landmark, $msg, $status);
    }
    /** @param MessageItem $mi
     * @param null|int|string|AssignmentItem $landmark
     * @return MessageItem */
    function append_item_near($mi, $landmark = null) {
        return $this->astate->append_item_near($mi, $landmark);
    }
    /** @param string $msg
     * @return void */
    function error($msg) {
        $this->astate->error($msg);
    }
    /** @param string $msg
     * @return void */
    function warning($msg) {
        $this->astate->warning($msg);
    }
    /** @param MessageItem $mi
     * @return void
     * @deprecated */
    function prepend_item($mi) {
        $this->astate->prepend_item($mi);
    }
    /** @param string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return $this
     * @deprecated */
    function prepend_msg($msg, $status) {
        $this->astate->prepend_item(new MessageItem($status, null, $msg));
        return $this;
    }
    /** @return string */
    function full_feedback_text() {
        return $this->astate->full_feedback_text();
    }
    /** @deprecated */
    function report_errors() {
        $this->feedback_msg(self::FEEDBACK_ASSIGN);
    }
    const FEEDBACK_ASSIGN = 0;
    const FEEDBACK_CHANGE = 1;
    const FEEDBACK_PROPOSE = 2;
    /** @param int $type */
    function feedback_msg($type) {
        $fml = [];
        if ($this->executed > 0) {
            if ($type === self::FEEDBACK_CHANGE) {
                $fml[] = MessageItem::success("<0>Changes saved");
            } else {
                $fml[] = MessageItem::success("<0>Assignments saved");
                if ($this->conf->setting("pcrev_assigntime") === $this->executed) {
                    $fml[] = MessageItem::inform("<5>You may want to " . $this->conf->hotlink("send mail about the new assignments", "mail", "template=newpcrev") . ".");
                }
            }
        } else if ($this->astate->has_error()) {
            if ($type === self::FEEDBACK_CHANGE) {
                $fml[] = MessageItem::error("<0>Changes not saved; please correct these errors and try again");
            } else if ($type === self::FEEDBACK_PROPOSE) {
                $fml[] = MessageItem::error("<0>Assignment cannot be saved due to errors");
            } else {
                $fml[] = MessageItem::error("<0>Assignments not saved due to errors");
            }
        } else if (empty($this->assigners)) {
            $fml[] = MessageItem::warning_note("<0>No changes");
        }
        if ($this->astate->has_message()) {
            $fml[] = $this->message_list(1000);
        }
        $this->conf->feedback_msg(...$fml);
    }
    /** @return JsonResult */
    function json_result() {
        if ($this->has_error()) {
            $status = $this->astate->has_user_error ? 200 : 403;
            return new JsonResult($status, ["ok" => false, "message_list" => $this->message_list(3000)]);
        } else if ($this->astate->has_message()) {
            return new JsonResult(["ok" => true, "message_list" => $this->message_list(3000)]);
        }
        return new JsonResult(["ok" => true]);
    }

    private static function req_user_text($req) {
        return Text::name($req["firstName"] ?? "", $req["lastName"] ?? "", $req["email"] ?? "", NAME_E);
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
                $text = "{$last}, {$first}";
            } else if ($first || $last) {
                $text = $first . $last;
            }
            if ($email) {
                $text = $text ? "{$text} <{$email}>" : "<{$email}>";
            }
            $ret = ContactSearch::make_cset($text, $this->astate->user, $cset);
            if (count($ret->user_ids()) === 1) {
                return $ret->users();
            } else if (count($ret->user_ids()) > 1) {
                $this->error("<0>‘" . self::req_user_text($req) . "’ matches more than one {$cset_text}");
                $this->astate->append_item_here(MessageItem::inform("<0>Use a full email address to disambiguate."));
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
            if (!($req = $csv->peek_list())) {
                $this->append_item_near(MessageItem::error("<0>Empty file"));
                return false;
            }
            if (self::is_csv_header($req)) {
                // found header
                $csv->set_header($req);
                $csv->next_list();
            } else if (!$def_action) {
                $this->append_item_near(MessageItem::error("<0>CSV header required"));
                $this->append_item_near(MessageItem::inform("<5>CSV assignment files must define ‘<code>action</code>’ and ‘<code>paper</code>’ columns. Add a CSV header line to tell me what the columns mean."));
                return false;
            } else if ($def_action === "settag") {
                $csv->set_header(["paper", "tag"]);
            } else if ($def_action === "preference") {
                $csv->set_header(["paper", "user", "preference"]);
            } else {
                $csv->set_header(["paper", "user"]);
            }
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
            $csv->add_synonym(...$ks);
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
                if (in_array($defaults[0], ["lead", "shepherd", "manager"], true)) {
                    $csv->add_synonym("user", $defaults[0]);
                }
            }
        }

        if ((!$has_action && !$def_action)
            || !$csv->has_column("paper")) {
            $this->append_item_near(MessageItem::error("<0>CSV must define “action” and “paper” columns"));
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
        if (preg_match('/\A\#\#\#\s*hotcrp_assign_display_search\s*(\S.*)\s*\z/', $line, $m)) {
            $this->unparse_search = $m[1];
        }
        if (preg_match('/\A\#\#\#\s*hotcrp_assign_show\s+(\w+)\s*\z/', $line, $m)) {
            $this->show_column($m[1]);
        }
    }

    /** @param string $pfield
     * @param bool $report_error
     * @return list<int> */
    private function collect_papers($pfield, $report_error) {
        $pfield = trim($pfield);
        if ($pfield === "") {
            if ($report_error) {
                $this->error("<0>Paper required");
            }
            return [];
        } else if ($pfield === "NONE") {
            return [];
        }

        if (ctype_digit($pfield) && ($pid = stoi($pfield)) > 0) {
            $pids = [$pid];
            $this->astate->paper_exact_match = true;
        } else if (preg_match('/\A[\d,\s]+\z/', $pfield)) {
            $pids = [];
            foreach (preg_split('/[,\s]+/', $pfield) as $txt) {
                if ($txt !== "" && ($pid = stoi($txt)) > 0) {
                    $pids[] = $pid;
                }
            }
            $this->astate->paper_exact_match = true;
        } else {
            $search = $this->searches[$pfield] ?? null;
            if ($search === null) {
                $search = $this->searches[$pfield] = new PaperSearch($this->user, ["q" => $pfield, "t" => $this->search_type, "reviewer" => $this->astate->reviewer]);
            }
            $pids = $search->sorted_paper_ids();
            if ($report_error && $search->has_problem()) {
                foreach ($search->message_list() as $mi) {
                    $this->astate->append_item($mi->with_landmark($this->astate->landmark()));
                }
            }
        }
        if (empty($pids) && $report_error) {
            $this->astate->warning("<0>No papers match ‘{$pfield}’");
        }

        // Implement paper restriction
        if ($this->enabled_pids !== null) {
            $npids = [];
            foreach ($pids as $pid) {
                if (isset($this->enabled_pids[$pid]))
                    $npids[] = $pid;
            }
            $pids = $npids;
        }

        return $pids;
    }

    /** @return ?AssignmentParser */
    private function assignment_parser($name) {
        if (!array_key_exists($name, $this->parsers)) {
            $this->parsers[$name] = $this->conf->assignment_parser($name, $this->user);
        }
        return $this->parsers[$name];
    }

    /** @return ?AssignmentParser */
    private function collect_parser($req) {
        if (($action = $req["action"]) === null) {
            $action = $this->astate->defaults["action"];
        }
        return $this->assignment_parser(strtolower(trim($action)));
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
    private function apply_req(?AssignmentParser $aparser, $req) {
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

        // reset search properties
        $this->astate->paper_exact_match = false;

        // check request
        if (!$aparser->set_req($req, $this->astate)) {
            return;
        }

        // parse paper
        $paper_universe = $aparser->paper_universe($req, $this->astate);
        if ($paper_universe !== "none") {
            $pids = $this->collect_papers((string) $req["paper"], true);
        } else {
            $pids = [];
        }
        if (empty($pids) && $paper_universe === "req") {
            return;
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

        // check conflicts and perform assignment
        $any_success = false;
        foreach ($pids as $p) {
            $prow = $this->astate->prow($p);
            if (!$prow) {
                $this->error("<5>" . $this->user->no_paper_whynot($p)->unparse_html());
                continue;
            }
            $ret = $this->apply_paper($prow, $contacts, $aparser, $req);
            if ($ret === 1) {
                $any_success = true;
            } else if ($ret < 0) {
                break;
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
            $allow = $allow ? : new AssignmentError($prow->failure_reason(["administer" => true]));
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

    /** @return CsvParser */
    function make_csv_parser() {
        return (new CsvParser)->add_comment_prefix("###", [$this, "parse_csv_comment"]);
    }

    /** @param CsvParser|string|list<string>|resource $text
     * @param ?string $filename
     * @param ?array<string,mixed> $defaults
     * @return $this */
    function parse($text, $filename = null, $defaults = null) {
        assert(empty($this->assigners));
        if ($text instanceof CsvParser) {
            $csv = $text;
            assert($filename === null || $text->filename() === $filename);
        } else {
            $csv = $this->make_csv_parser()
                ->set_type(CsvParser::TYPE_GUESS)
                ->set_content($text)
                ->set_filename($filename);
        }
        return $this->parse_csv($csv, $defaults);
    }

    /** @param ?array<string,mixed> $defaults
     * @return $this */
    function parse_csv(CsvParser $csv, $defaults = null) {
        assert(empty($this->assigners));
        $this->astate->set_filename($csv->filename());
        $this->astate->defaults = $defaults ?? [];

        if (!$this->install_csv_header($csv)) {
            return $this;
        }

        $has_landmark = $csv->has_column("landmark");
        $old_overrides = $this->user->set_overrides($this->astate->overrides);

        // parse file up to 2000 lines at a time
        $this->progress_phase = self::PROGPHASE_PARSE;
        while ($this->parse_batch($csv, $has_landmark)) {
            /* do nothing */
        }

        // call preapply functions and compute diff
        $this->progress_phase = self::PROGPHASE_PREAPPLY;
        $this->notify_progress([0, null]);
        $this->astate->call_preapply_functions();
        $difflist = $this->astate->diff_list();

        // create assigners for difference
        $this->progress_phase = self::PROGPHASE_REALIZE;
        $this->notify_progress([0, count($difflist)]);
        $this->assigners_pidhead = $pidtail = [];
        $progresscadence = 1000;
        foreach ($difflist as $item) {
            try {
                $this->astate->set_landmark($item->landmark);
                $a = $item->realize($this->astate);
                if (!$a) {
                    continue;
                }
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
            } catch (Exception $e) {
                if ($e->getMessage() !== "") {
                    $this->astate->error($e->getMessage());
                }
            }
            ++$this->progress_value;
            if ($this->progress_value % $progresscadence === 0) {
                $this->notify_progress();
            }
        }
        if ($this->progress_value % $progresscadence !== 0) {
            $this->astate->set_landmark($csv->lineno());
            $this->notify_progress();
        }

        $this->user->set_overrides($old_overrides);
        return $this;
    }

    /** @return bool */
    private function parse_batch(CsvParser $csv, $has_landmark) {
        set_time_limit(30);
        $rowlimit = 5000;
        $progresscadence = 1000;

        // read up to 5000 lines; read all their papers at once
        $reqs = $pids = $progress = [];
        $nrows = 0;
        while ($nrows < $rowlimit && ($req = $csv->next_row())) {
            if ($has_landmark) {
                $reqs[] = $req["landmark"] ?? $csv->lineno();
            } else {
                $reqs[] = $csv->lineno();
            }
            $reqs[] = $aparser = $this->collect_parser($req);
            $reqs[] = $req;

            ++$nrows;
            if ($nrows % $progresscadence === $progresscadence - 1) {
                $progress[] = [$csv->progress_value(), $csv->progress_max()];
            }

            // only collect papers in first batch
            if ($this->request_count !== 0) {
                continue;
            }

            if (!$aparser) {
                $ps = (string) $req["paper"];
            } else if ($aparser->paper_universe($req, $this->astate) === "none") {
                $ps = "NONE";
            } else {
                $ps = $aparser->expand_papers($req, $this->astate);
            }
            foreach ($this->collect_papers($ps, false) as $pid) {
                $pids[$pid] = true;
            }
        }

        // in first batch, load required papers
        if ($this->request_count === 0) {
            $this->astate->set_landmark($csv->lineno());
            if ($nrows < $rowlimit) {
                $this->astate->fetch_prows(array_keys($pids));
            } else if (isset($this->enabled_pids)) {
                $this->astate->fetch_prows(array_keys($this->enabled_pids));
            } else {
                $this->astate->fetch_all_prows();
            }
        }

        // apply assignment parsers
        $nreqs = count($reqs);
        $nrows = 0;
        for ($i = 0; $i !== $nreqs; $i += 3) {
            $this->astate->set_landmark($reqs[$i]);
            $this->apply_req($reqs[$i + 1], $reqs[$i + 2]);
            ++$this->request_count;
            ++$nrows;
            if ($nrows % $progresscadence === $progresscadence - 1) {
                $this->notify_progress(array_shift($progress));
            }
        }

        // complete
        if ($nrows === $rowlimit) {
            return true;
        }
        $this->notify_progress([$csv->progress_value(), $csv->progress_value()]);
        return false;
    }

    /** @return list<string> */
    function assigned_types() {
        $types = [];
        foreach ($this->assigners as $assigner) {
            $types[$assigner->type()] = true;
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
                    break;
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
                return strcmp($assigner1->type(), $assigner2->type());
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
        }
        return "";
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
            $q = "({$this->unparse_search}) THEN LEGEND:none {$q}";
        }
        foreach ($this->unparse_columns as $k => $v) {
            if ($v)
                $q .= " show:{$k}";
        }
        $pc->search_query = "{$q} show:autoassignment";

        return $pc;
    }

    function print_unparse_display() {
        $this->progress_phase = self::PROGPHASE_UNPARSE;
        $this->notify_progress([0, null]);
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

    /** @return bool */
    function execute() {
        assert($this->executed === 0);
        if ($this->has_error()) {
            return false;
        } else if (empty($this->assigners)) {
            return true;
        }

        // mark activity now to avoid DB errors later
        $this->user->mark_activity();
        $this->executed = Conf::$now;

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
            $tables[] = "{$t} {$type}";
        }
        $this->conf->qe("lock tables " . join(", ", $tables));

        $progresscadence = 1000;
        $this->progress_phase = self::PROGPHASE_SAVE;
        $this->progress_value = 0;
        $this->progress_max = count($this->assigners);
        foreach ($this->assigners as $assigner) {
            $assigner->execute($this);
            ++$this->progress_value;
            if ($this->progress_value % $progresscadence === 0) {
                set_time_limit(30);
                $this->notify_progress();
            }
        }
        if ($this->progress_value % $progresscadence !== 0) {
            $this->notify_progress();
        }

        if ($this->qe_stager) {
            call_user_func($this->qe_stager, null);
        }
        $this->conf->qe("unlock tables");

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
    function __construct(Contact $user, ?Contact $reviewer = null) {
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
                echo '<div class="assignment-summary mt-4"><h3>Summary</h3>',
                    '<div class="pc-ctable">', join("", $summary), "</div></div>\n";
            }
        }
    }
}
