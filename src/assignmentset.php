<?php
// assignmentset.php -- HotCRP helper classes for assignments
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class AssignmentItem implements ArrayAccess {
    public $before;
    public $after = null;
    public $lineno = null;
    function __construct($before) {
        $this->before = $before;
    }
    function offsetExists($offset) {
        $x = $this->after ? : $this->before;
        return isset($x[$offset]);
    }
    function offsetGet($offset) {
        $x = $this->after ? : $this->before;
        return isset($x[$offset]) ? $x[$offset] : null;
    }
    function offsetSet($offset, $value) {
    }
    function offsetUnset($offset) {
    }
    function existed() {
        return !!$this->before;
    }
    function deleted() {
        return $this->after === false;
    }
    function modified() {
        return $this->after !== null;
    }
    function get($before, $offset = null) {
        if ($offset === null)
            return $this->offsetGet($before);
        if ($before || $this->after === null)
            $x = $this->before;
        else
            $x = $this->after;
        return $x && isset($x[$offset]) ? $x[$offset] : null;
    }
    function get_before($offset) {
        return $this->get(true, $offset);
    }
    function differs($offset) {
        return $this->get(true, $offset) !== $this->get(false, $offset);
    }
    function realize(AssignmentState $astate) {
        return call_user_func($astate->realizer($this->offsetGet("type")), $this, $astate);
    }
}

class AssignmentState {
    private $st = array();
    private $types = array();
    private $realizers = [];
    public $conf;
    public $user;     // executor
    public $reviewer; // default contact
    public $overrides = 0;
    private $cmap;
    private $reviewer_users = null;
    public $lineno = null;
    public $defaults = array();
    private $prows = array();
    private $pid_attempts = array();
    public $finishers = array();
    public $paper_exact_match = true;
    public $errors = [];

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
        } else
            return false;
    }
    function realizer($type) {
        return $this->realizers[$type];
    }
    private function pidstate($pid) {
        if (!isset($this->st[$pid]))
            $this->st[$pid] = (object) array("items" => array());
        return $this->st[$pid];
    }
    private function extract_key($x, $pid = null) {
        $tkeys = $this->types[$x["type"]];
        assert($tkeys);
        $t = $x["type"];
        foreach ($tkeys as $k)
            if (isset($x[$k]))
                $t .= "`" . $x[$k];
            else if ($pid !== null && $k === "pid")
                $t .= "`" . $pid;
            else
                return false;
        return $t;
    }
    function load($x) {
        $st = $this->pidstate($x["pid"]);
        $k = $this->extract_key($x);
        assert($k && !isset($st->items[$k]));
        $st->items[$k] = new AssignmentItem($x);
        $st->sorted = false;
    }

    private function pid_keys($q) {
        if (isset($q["pid"]))
            return array($q["pid"]);
        else
            return array_keys($this->st);
    }
    static private function match($x, $q) {
        foreach ($q as $k => $v) {
            if ($v !== null && get($x, $k) !== $v)
                return false;
        }
        return true;
    }
    function query_items($q) {
        $res = [];
        foreach ($this->pid_keys($q) as $pid) {
            $st = $this->pidstate($pid);
            $k = $this->extract_key($q, $pid);
            foreach ($k ? [get($st->items, $k)] : $st->items as $item)
                if ($item && !$item->deleted()
                    && self::match($item->after ? : $item->before, $q))
                    $res[] = $item;
        }
        return $res;
    }
    function query($q) {
        $res = [];
        foreach ($this->query_items($q) as $item)
            $res[] = $item->after ? : $item->before;
        return $res;
    }
    function query_unmodified($q) {
        $res = [];
        foreach ($this->query_items($q) as $item)
            if (!$item->modified())
                $res[] = $item->before;
        return $res;
    }
    function make_filter($key, $q) {
        $cf = [];
        foreach ($this->query($q) as $m)
            $cf[$m[$key]] = true;
        return $cf;
    }

    function remove($q) {
        $res = [];
        foreach ($this->query_items($q) as $item) {
            $res[] = $item->after ? : $item->before;
            $item->after = false;
            $item->lineno = $this->lineno;
        }
        return $res;
    }
    function add($x) {
        $k = $this->extract_key($x);
        assert(!!$k);
        $st = $this->pidstate($x["pid"]);
        if (!($item = get($st->items, $k)))
            $item = $st->items[$k] = new AssignmentItem(false);
        $item->after = $x;
        $item->lineno = $this->lineno;
        return $item;
    }

    function diff() {
        $diff = array();
        foreach ($this->st as $pid => $st) {
            foreach ($st->items as $item)
                if ((!$item->before && $item->after)
                    || ($item->before && $item->after === false)
                    || ($item->before && $item->after && !self::match($item->before, $item->after)))
                    $diff[$pid][] = $item;
        }
        return $diff;
    }

    function paper_ids() {
        return array_keys($this->prows);
    }
    function prow($pid) {
        $p = get($this->prows, $pid);
        if (!$p && !isset($this->pid_attempts[$pid])) {
            $this->fetch_prows($pid);
            $p = get($this->prows, $pid);
        }
        return $p;
    }
    function add_prow(PaperInfo $prow) {
        $this->prows[$prow->paperId] = $prow;
    }
    function prows() {
        return $this->prows;
    }
    function fetch_prows($pids, $initial_load = false) {
        $pids = is_array($pids) ? $pids : array($pids);
        $fetch_pids = array();
        foreach ($pids as $p)
            if (!isset($this->prows[$p]) && !isset($this->pid_attempts[$p]))
                $fetch_pids[] = $p;
        assert($initial_load || empty($fetch_pids));
        if (!empty($fetch_pids)) {
            foreach ($this->user->paper_set($fetch_pids) as $prow)
                $this->prows[$prow->paperId] = $prow;
            foreach ($fetch_pids as $pid)
                if (!isset($this->prows[$pid]))
                    $this->pid_attempts[$pid] = true;
        }
    }

    function user_by_id($cid) {
        return $this->cmap->user_by_id($cid);
    }
    function users_by_id($cids) {
        return array_map(function ($cid) { return $this->user_by_id($cid); }, $cids);
    }
    function user_by_email($email, $create = false, $req = null) {
        return $this->cmap->user_by_email($email, $create, $req);
    }
    function none_user() {
        return $this->cmap->none_user();
    }
    function pc_users() {
        return $this->cmap->pc_users();
    }
    function reviewer_users() {
        if ($this->reviewer_users === null)
            $this->reviewer_users = $this->cmap->reviewer_users($this->paper_ids());
        return $this->reviewer_users;
    }
    function register_user(Contact $c) {
        return $this->cmap->register_user($c);
    }

    function error($message) {
        $this->errors[] = [$message, true, false];
    }
    function paper_error($message) {
        $this->errors[] = [$message, $this->paper_exact_match, false];
    }
    function user_error($message) {
        $this->errors[] = [$message, true, true];
    }
}

class AssignerContacts {
    private $conf;
    private $viewer;
    private $by_id = array();
    private $by_lemail = array();
    private $has_pc = false;
    private $none_user;
    static private $next_fake_id = -10;
    static public $query = "ContactInfo.contactId, firstName, lastName, unaccentedName, email, roles, contactTags";
    function __construct(Conf $conf, Contact $viewer) {
        global $Me;
        $this->conf = $conf;
        $this->viewer = $viewer;
        if ($Me && $Me->contactId > 0 && $Me->conf === $conf)
            $this->store($Me);
    }
    private function store(Contact $c) {
        if ($c->contactId != 0) {
            if (isset($this->by_id[$c->contactId]))
                return $this->by_id[$c->contactId];
            $this->by_id[$c->contactId] = $c;
        }
        if ($c->email)
            $this->by_lemail[strtolower($c->email)] = $c;
        return $c;
    }
    private function ensure_pc() {
        if (!$this->has_pc) {
            foreach ($this->conf->pc_members() as $p)
                $this->store($p);
            $this->has_pc = true;
        }
    }
    function none_user() {
        if (!$this->none_user)
            $this->none_user = new Contact(["contactId" => 0, "roles" => 0, "email" => "", "sorter" => ""], $this->conf);
        return $this->none_user;
    }
    function user_by_id($cid) {
        if (!$cid)
            return $this->none_user();
        if (($c = get($this->by_id, $cid)))
            return $c;
        $this->ensure_pc();
        if (($c = get($this->by_id, $cid)))
            return $c;
        $result = $this->conf->qe("select " . self::$query . " from ContactInfo where contactId=?", $cid);
        $c = Contact::fetch($result, $this->conf);
        if (!$c)
            $c = new Contact(["contactId" => $cid, "roles" => 0, "email" => "unknown contact $cid", "sorter" => ""], $this->conf);
        Dbl::free($result);
        return $this->store($c);
    }
    function user_by_email($email, $create = false, $req = null) {
        if (!$email)
            return $this->none_user();
        $lemail = strtolower($email);
        if (($c = get($this->by_lemail, $lemail)))
            return $c;
        $this->ensure_pc();
        if (($c = get($this->by_lemail, $lemail)))
            return $c;
        $result = $this->conf->qe("select " . self::$query . " from ContactInfo where email=?", $lemail);
        $c = Contact::fetch($result, $this->conf);
        Dbl::free($result);
        if (!$c && $create) {
            assert(validate_email($email) || preg_match('/\Aanonymous\d*\z/', $email));
            $cargs = ["contactId" => self::$next_fake_id, "roles" => 0, "email" => $email];
            foreach (["firstName", "lastName", "affiliation"] as $k)
                if ($req && get($req, $k))
                    $cargs[$k] = $req[$k];
            if (preg_match('/\Aanonymous\d*\z/', $email)) {
                $cargs["firstName"] = "Jane Q.";
                $cargs["lastName"] = "Public";
                $cargs["affiliation"] = "Unaffiliated";
                $cargs["disabled"] = 1;
            }
            $c = new Contact($cargs, $this->conf);
            self::$next_fake_id -= 1;
        }
        return $c ? $this->store($c) : null;
    }
    function pc_users() {
        $this->ensure_pc();
        return $this->conf->pc_members();
    }
    function reviewer_users($pids) {
        $rset = $this->pc_users();
        $result = $this->conf->qe("select " . AssignerContacts::$query . " from ContactInfo join PaperReview using (contactId) where (roles&" . Contact::ROLE_PC . ")=0 and paperId?a group by ContactInfo.contactId", $pids);
        while ($result && ($c = Contact::fetch($result, $this->conf)))
            $rset[$c->contactId] = $this->store($c);
        Dbl::free($result);
        return $rset;
    }
    function register_user(Contact $c) {
        if ($c->contactId >= 0)
            return $c;
        assert($this->by_id[$c->contactId] === $c);
        $cx = $this->by_lemail[strtolower($c->email)];
        if ($cx === $c) {
            // XXX assume that never fails:
            $cargs = [];
            foreach (["email", "firstName", "lastName", "affiliation", "disabled"] as $k)
                if ($c->$k !== null)
                    $cargs[$k] = $c->$k;
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
        foreach (["rev", "meta", "pri", "sec", "ass", "lead", "shepherd"] as $k)
            $xct->$k = $this->$k + $ct->$k;
        return $xct;
    }
}

class AssignmentCountSet {
    public $conf;
    public $bypc = [];
    public $rev = false;
    public $lead = false;
    public $shepherd = false;
    function __construct(Conf $conf) {
        $this->conf = $conf;
    }
    function get($offset) {
        return get($this->bypc, $offset) ? : new AssignmentCount;
    }
    function ensure($offset) {
        if (!isset($this->bypc[$offset]))
            $this->bypc[$offset] = new AssignmentCount;
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
        while (($row = edb_row($result))) {
            $ct = $this->ensure($row[0]);
            $ct->rev = strlen($row[1]);
            $ct->meta = substr_count($row[1], REVIEW_META);
            $ct->pri = substr_count($row[1], REVIEW_PRIMARY);
            $ct->sec = substr_count($row[1], REVIEW_SECONDARY);
        }
        Dbl::free($result);
    }
    private function load_paperpc($type) {
        $result = $this->conf->qe("select {$type}ContactId, count(paperId)
                from Paper where timeWithdrawn<=0 and timeSubmitted>0
                group by {$type}ContactId");
        while (($row = edb_row($result))) {
            $ct = $this->ensure($row[0]);
            $ct->$type = +$row[1];
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
    public $header = [];
    public $data = [];
    function add($row) {
        foreach ($row as $k => $v)
            if ($v !== null)
                $this->header[$k] = true;
        $this->data[] = $row;
    }
    function unparse() {
        $csvg = new CsvGenerator;
        return $csvg->select($this->header)->add($this->data)->unparse();
    }
}

class AssignmentParser {
    public $type;
    function __construct($type) {
        $this->type = $type;
    }
    function expand_papers(&$req, AssignmentState $state) {
        return false;
    }
    function load_state(AssignmentState $state) {
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->user->can_administer($prow)
            && !$state->user->privChair)
            return "You can’t administer #{$prow->paperId}.";
        else if ($prow->timeWithdrawn > 0)
            return "#$prow->paperId has been withdrawn.";
        else if ($prow->timeSubmitted <= 0)
            return "#$prow->paperId is not submitted.";
        else
            return true;
    }
    function contact_set(&$req, AssignmentState $state) {
        return "pc";
    }
    static function unconflicted(PaperInfo $prow, Contact $contact, AssignmentState $state) {
        return ($state->overrides & Contact::OVERRIDE_CONFLICT)
            || !$prow->has_conflict($contact);
    }
    function paper_filter($contact, &$req, AssignmentState $state) {
        return false;
    }
    function expand_any_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return false;
    }
    function expand_missing_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return false;
    }
    function expand_anonymous_user(PaperInfo $prow, &$req, $user, AssignmentState $state) {
        return false;
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return false;
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return true;
    }
}

class UserlessAssignmentParser extends AssignmentParser {
    function __construct($type) {
        parent::__construct($type);
    }
    function contact_set(&$req, AssignmentState $state) {
        return false;
    }
    function expand_any_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return [$state->none_user()];
    }
    function expand_missing_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return [$state->none_user()];
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return true;
    }
}

class Assigner {
    public $item;
    public $type;
    public $pid;
    public $contact;
    public $cid;
    public $next_index;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        $this->item = $item;
        $this->type = $item["type"];
        $this->pid = $item["pid"];
        $this->cid = $item["cid"] ? : $item["_cid"];
        if ($this->cid)
            $this->contact = $state->user_by_id($this->cid);
    }
    function unparse_description() {
        return "";
    }
    function unparse_display(AssignmentSet $aset) {
        return "";
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        return null;
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
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return true;
    }
}

class ReviewAssigner_Data {
    public $oldround = null;
    public $newround = null;
    public $explicitround = false;
    public $oldtype = null;
    public $newtype = null;
    public $creator = true;
    public $error = false;
    static function separate($key, $req, $state, $rtype) {
        $a0 = $a1 = trim(get_s($req, $key));
        $require_match = $rtype ? false : $a0 !== "";
        if ($a0 === "" && $rtype != 0)
            $a0 = $a1 = get($state->defaults, $key);
        if ($a0 !== null && ($colon = strpos($a0, ":")) !== false) {
            $a1 = (string) substr($a0, $colon + 1);
            $a0 = (string) substr($a0, 0, $colon);
            $require_match = true;
        }
        $a0 = is_string($a0) ? trim($a0) : $a0;
        $a1 = is_string($a1) ? trim($a1) : $a1;
        if (strcasecmp($a0, "any") == 0) {
            $a0 = null;
            $require_match = true;
        }
        if (strcasecmp($a1, "any") == 0) {
            $a1 = null;
            $require_match = true;
        }
        return [$a0, $a1, $require_match];
    }
    function __construct($req, AssignmentState $state, $rtype) {
        list($targ0, $targ1, $tmatch) = self::separate("reviewtype", $req, $state, $rtype);
        if ($targ0 !== null && $targ0 !== "" && $tmatch
            && ($this->oldtype = ReviewInfo::parse_type($targ0)) === false)
            $this->error = "Invalid reviewtype.";
        if ($targ1 !== null && $targ1 !== "" && $rtype != 0
            && ($this->newtype = ReviewInfo::parse_type($targ1)) === false)
            $this->error = "Invalid reviewtype.";
        if ($this->newtype === null)
            $this->newtype = $rtype;

        list($rarg0, $rarg1, $rmatch) = self::separate("round", $req, $state, $this->newtype);
        if ($rarg0 !== null && $rarg0 !== "" && $rmatch
            && ($this->oldround = $state->conf->sanitize_round_name($rarg0)) === false)
            $this->error = Conf::round_name_error($rarg0);
        if ($rarg1 !== null && $rarg1 !== "" && $this->newtype != 0
            && ($this->newround = $state->conf->sanitize_round_name($rarg1)) === false)
            $this->error = Conf::round_name_error($rarg1);
        if ($rarg0 !== "" && $rarg1 !== null)
            $this->explicitround = (string) get($req, "round") !== "";
        if ($rarg0 === "")
            $rmatch = false;
        if ($this->oldtype === null && $rtype > 0 && $rmatch)
            $this->oldtype = $rtype;

        $this->creator = !$tmatch && !$rmatch && $this->newtype != 0;
    }
    static function make(&$req, AssignmentState $state, $rtype) {
        if (!isset($req["_review_data"]) || !is_object($req["_review_data"]))
            $req["_review_data"] = new ReviewAssigner_Data($req, $state, $rtype);
        return $req["_review_data"];
    }
    function can_create_review() {
        return $this->creator;
    }
}

class Review_AssignmentParser extends AssignmentParser {
    private $rtype;
    function __construct(Conf $conf, $aj) {
        parent::__construct($aj->name);
        if ($aj->review_type)
            $this->rtype = (int) ReviewInfo::parse_type($aj->review_type);
        else
            $this->rtype = -1;
    }
    function load_state(AssignmentState $state) {
        if ($state->mark_type("review", ["pid", "cid"], "Review_Assigner::make"))
            self::load_review_state($state);
    }
    private function make_rdata(&$req, AssignmentState $state) {
        return ReviewAssigner_Data::make($req, $state, $this->rtype);
    }
    function contact_set(&$req, AssignmentState $state) {
        if ($this->rtype > REVIEW_EXTERNAL)
            return "pc";
        else if ($this->rtype == 0
                 || (($rdata = $this->make_rdata($req, $state))
                     && !$rdata->can_create_review()))
            return "reviewers";
        else
            return false;
    }
    static function load_review_state(AssignmentState $state) {
        $result = $state->conf->qe("select paperId, contactId, reviewType, reviewRound, reviewSubmitted from PaperReview where paperId?a", $state->paper_ids());
        while (($row = edb_row($result))) {
            $round = $state->conf->round_name($row[3]);
            $state->load(["type" => "review", "pid" => +$row[0], "cid" => +$row[1],
                          "_rtype" => +$row[2], "_round" => $round,
                          "_rsubmitted" => $row[4] > 0 ? 1 : 0]);
        }
        Dbl::free($result);
    }
    private function make_filter($fkey, $key, $value, &$req, AssignmentState $state) {
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->can_create_review())
            return null;
        return $state->make_filter($fkey, [
                "type" => "review", $key => $value,
                "_rtype" => $rdata->oldtype, "_round" => $rdata->oldround
            ]);
    }
    function paper_filter($contact, &$req, AssignmentState $state) {
        return $this->make_filter("pid", "cid", $contact->contactId, $req, $state);
    }
    function expand_any_user(PaperInfo $prow, &$req, AssignmentState $state) {
        $cf = $this->make_filter("cid", "pid", $prow->paperId, $req, $state);
        return $cf !== null ? $state->users_by_id(array_keys($cf)) : false;
    }
    function expand_missing_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return $this->expand_any_user($prow, $req, $state);
    }
    function expand_anonymous_user(PaperInfo $prow, &$req, $user, AssignmentState $state) {
        if (preg_match('/\A(?:new-?anonymous|anonymous-?new)\z/', $user)) {
            $suf = "";
            while (($u = $state->user_by_email("anonymous" . $suf))
                   && $state->query(["type" => "review", "pid" => $prow->paperId,
                                     "cid" => $u->contactId]))
                $suf = $suf === "" ? 2 : $suf + 1;
            $user = "anonymous" . $suf;
        }
        if (preg_match('/\Aanonymous\d*\z/', $user)
            && $c = $state->user_by_email($user, true, []))
            return [$c];
        else
            return false;
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        // User “none” is never allowed
        if (!$contact->contactId)
            return false;
        // PC reviews must be PC members
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->newtype >= REVIEW_PC && !$contact->is_pc_member())
            return Text::user_html_nolink($contact) . " is not a PC member and cannot be assigned a PC review.";
        // Conflict allowed if we're not going to assign a new review
        if ($this->rtype == 0
            || $prow->has_reviewer($contact)
            || !$rdata->can_create_review())
            return true;
        // Check whether review assignments are acceptable
        if ($contact->is_pc_member()
            && !$contact->can_accept_review_assignment_ignore_conflict($prow))
            return Text::user_html_nolink($contact) . " cannot be assigned to review #{$prow->paperId}.";
        // Check conflicts
        return AssignmentParser::unconflicted($prow, $contact, $state);
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->error)
            return $rdata->error;

        $revmatch = ["type" => "review", "pid" => $prow->paperId,
                     "cid" => $contact->contactId,
                     "_rtype" => $rdata->oldtype, "_round" => $rdata->oldround];
        $res = $state->remove($revmatch);
        assert(count($res) <= 1);

        if ($rdata->can_create_review() && empty($res)) {
            $revmatch["_round"] = $rdata->newround;
            $res[] = $revmatch;
        }
        if ($rdata->newtype && !empty($res)) {
            $m = $res[0];
            if (!$m["_rtype"] || $rdata->newtype > 0)
                $m["_rtype"] = $rdata->newtype;
            if (!$m["_rtype"] || $m["_rtype"] < 0)
                $m["_rtype"] = REVIEW_EXTERNAL;
            if ($m["_rtype"] == REVIEW_EXTERNAL
                && $state->conf->pc_member_by_id($m["cid"]))
                $m["_rtype"] = REVIEW_PC;
            if ($rdata->newround !== null && $rdata->explicitround)
                $m["_round"] = $rdata->newround;
            $state->add($m);
        } else if (!$rdata->newtype && !empty($res) && $res[0]["_rsubmitted"])
            // do not remove submitted reviews
            $state->add($res[0]);
        return true;
    }
}

class Review_Assigner extends Assigner {
    private $rtype;
    private $notify = false;
    private $unsubmit = false;
    private $token = false;
    static public $prefinfo = null;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->rtype = $item->get(false, "_rtype");
        $this->unsubmit = $item->get(true, "_rsubmitted") && !$item->get(false, "_rsubmitted");
        if (!$item->existed() && $this->rtype == REVIEW_EXTERNAL
            && !$this->contact->is_anonymous_user()
            && ($notify = get($state->defaults, "extrev_notify"))
            && Mailer::is_template($notify))
            $this->notify = $notify;
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        return new Review_Assigner($item, $state);
    }
    function unparse_description() {
        return "review";
    }
    private function unparse_item(AssignmentSet $aset, $before) {
        if (!$this->item->get($before, "_rtype"))
            return "";
        $t = $aset->user->reviewer_html_for($this->contact) . ' '
            . review_type_icon($this->item->get($before, "_rtype"),
                               !$this->item->get($before, "_rsubmitted"));
        if (($round = $this->item->get($before, "_round")))
            $t .= ' <span class="revround" title="Review round">'
                . htmlspecialchars($round) . '</span>';
        if (self::$prefinfo
            && ($cpref = get(self::$prefinfo, $this->cid))
            && ($pref = get($cpref, $this->pid)))
            $t .= unparse_preference_span($pref);
        return $t;
    }
    private function icon($before) {
        return review_type_icon($this->item->get($before, "_rtype"),
                                !$this->item->get($before, "_rsubmitted"));
    }
    function unparse_display(AssignmentSet $aset) {
        $t = $aset->user->reviewer_html_for($this->contact);
        if ($this->item->deleted())
            $t = '<del>' . $t . '</del>';
        if ($this->item->differs("_rtype") || $this->item->differs("_rsubmitted")) {
            if ($this->item->get(true, "_rtype"))
                $t .= ' <del>' . $this->icon(true) . '</del>';
            if ($this->item->get(false, "_rtype"))
                $t .= ' <ins>' . $this->icon(false) . '</ins>';
        } else if ($this->item["_rtype"])
            $t .= ' ' . $this->icon(false);
        if ($this->item->differs("_round")) {
            if (($round = $this->item->get(true, "_round")))
                $t .= ' <del><span class="revround" title="Review round">' . htmlspecialchars($round) . '</span></del>';
            if (($round = $this->item->get(false, "_round")))
                $t .= ' <ins><span class="revround" title="Review round">' . htmlspecialchars($round) . '</span></ins>';
        } else if (($round = $this->item["_round"]))
            $t .= ' <span class="revround" title="Review round">' . htmlspecialchars($round) . '</span>';
        if (!$this->item->existed() && self::$prefinfo
            && ($cpref = get(self::$prefinfo, $this->cid))
            && ($pref = get($cpref, $this->pid)))
            $t .= unparse_preference_span($pref);
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = ["pid" => $this->pid, "action" => ReviewInfo::unparse_assigner_action($this->rtype),
              "email" => $this->contact->email, "name" => $this->contact->name_text()];
        if (($round = $this->item["_round"]))
            $x["round"] = $this->item["_round"];
        if ($this->token)
            $x["review_token"] = encode_token($this->token);
        $acsv->add($x);
        if ($this->unsubmit)
            $acsv->add(["action" => "unsubmitreview", "pid" => $this->pid,
                        "email" => $this->contact->email, "name" => $this->contact->name_text()]);
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column("reviewers");
        if ($this->cid > 0) {
            $deltarev->rev = true;
            $ct = $deltarev->ensure($this->cid);
            ++$ct->ass;
            $oldtype = $this->item->get(true, "_rtype") ? : 0;
            $ct->rev += ($this->rtype != 0) - ($oldtype != 0);
            $ct->meta += ($this->rtype == REVIEW_META) - ($oldtype == REVIEW_META);
            $ct->pri += ($this->rtype == REVIEW_PRIMARY) - ($oldtype == REVIEW_PRIMARY);
            $ct->sec += ($this->rtype == REVIEW_SECONDARY) - ($oldtype == REVIEW_SECONDARY);
        }
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperReview"] = $locks["PaperReviewRefused"] = $locks["Settings"] = "write";
    }
    function execute(AssignmentSet $aset) {
        $extra = array();
        $round = $this->item->get(false, "_round");
        if ($round !== null && $this->rtype)
            $extra["round_number"] = (int) $aset->conf->round_number($round, true);
        if ($this->contact->is_anonymous_user()
            && (!$this->item->existed() || $this->item->deleted())) {
            $extra["token"] = true;
            $aset->cleanup_callback("rev_token", function ($aset, $vals) {
                $aset->conf->update_rev_tokens_setting(min($vals));
            }, $this->item->existed() ? 0 : 1);
        }
        $reviewId = $aset->user->assign_review($this->pid, $this->cid, $this->rtype, $extra);
        if ($this->unsubmit && $reviewId)
            $aset->user->unsubmit_review_row((object) ["paperId" => $this->pid, "contactId" => $this->cid, "reviewType" => $this->rtype, "reviewId" => $reviewId]);
        if (get($extra, "token") && $reviewId)
            $this->token = $aset->conf->fetch_ivalue("select reviewToken from PaperReview where paperId=? and reviewId=?", $this->pid, $reviewId);
    }
    function cleanup(AssignmentSet $aset) {
        if ($this->notify) {
            $reviewer = $aset->conf->user_by_id($this->cid);
            $prow = $aset->conf->paperRow(["paperId" => $this->pid], $reviewer);
            HotCRPMailer::send_to($reviewer, $this->notify, $prow);
        }
    }
}


class UnsubmitReview_AssignmentParser extends AssignmentParser {
    function __construct() {
        parent::__construct("unsubmitreview");
    }
    function load_state(AssignmentState $state) {
        if ($state->mark_type("review", ["pid", "cid"], "Review_Assigner::make"))
            Review_AssignmentParser::load_review_state($state);
    }
    function contact_set(&$req, AssignmentState $state) {
        return "reviewers";
    }
    function paper_filter($contact, &$req, AssignmentState $state) {
        return $state->make_filter("pid", ["type" => "review", "cid" => $contact->contactId, "_rsubmitted" => 1]);
    }
    function expand_any_user(PaperInfo $prow, &$req, AssignmentState $state) {
        $cf = $state->make_filter("cid", ["type" => "review", "pid" => $prow->paperId, "_rsubmitted" => 1]);
        return $state->users_by_id(array_keys($cf));
    }
    function expand_missing_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return $this->expand_any_user($prow, $req, $state);
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return $contact->contactId != 0;
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        // parse round and reviewtype arguments
        $rarg0 = trim(get_s($req, "round"));
        $oldround = null;
        if ($rarg0 !== "" && strcasecmp($rarg0, "any") != 0
            && ($oldround = $state->conf->sanitize_round_name($rarg0)) === false)
            return Conf::round_name_error($rarg0);
        $targ0 = trim(get_s($req, "reviewtype"));
        $oldtype = null;
        if ($targ0 !== ""
            && ($oldtype = ReviewInfo::parse_type($targ0)) === false)
            return "Invalid reviewtype.";

        // remove existing review
        $revmatch = ["type" => "review", "pid" => $prow->paperId,
                     "cid" => $contact->contactId,
                     "_rtype" => $oldtype, "_round" => $oldround, "_rsubmitted" => 1];
        $matches = $state->remove($revmatch);
        foreach ($matches as $r) {
            $r["_rsubmitted"] = 0;
            $state->add($r);
        }
        return true;
    }
}


class AssignmentSet {
    public $conf;
    public $user;
    public $filename;
    private $assigners = [];
    private $assigners_pidhead = [];
    private $enabled_pids = null;
    private $enabled_actions = null;
    private $msgs = array();
    private $has_error = false;
    private $has_user_error = false;
    private $my_conflicts = null;
    private $astate;
    private $searches = array();
    private $search_type = "s";
    private $unparse_search = false;
    private $unparse_columns = array();
    private $assignment_type;
    private $cleanup_callbacks;
    private $cleanup_notify_tracker;
    private $qe_stager;

    function __construct(Contact $user, $overrides = null) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->astate = new AssignmentState($user);
        $this->set_overrides($overrides);
    }

    function set_search_type($search_type) {
        $this->search_type = $search_type;
    }
    function set_reviewer(Contact $reviewer) {
        $this->astate->reviewer = $reviewer;
    }
    function set_overrides($overrides) {
        if ($overrides === null)
            $overrides = $this->user->overrides();
        else if ($overrides === true)
            $overrides = $this->user->overrides() | Contact::OVERRIDE_CONFLICT;
        if (!$this->user->privChair)
            $overrides &= ~Contact::OVERRIDE_CONFLICT;
        $this->astate->overrides = (int) $overrides;
    }

    function enable_actions($action) {
        assert(empty($this->assigners));
        if ($this->enabled_actions === null)
            $this->enabled_actions = [];
        foreach (is_array($action) ? $action : [$action] as $a)
            if (($aparser = $this->conf->assignment_parser($a, $this->user)))
                $this->enabled_actions[$aparser->type] = true;
    }

    function enable_papers($paper) {
        assert(empty($this->assigners));
        if ($this->enabled_pids === null)
            $this->enabled_pids = [];
        foreach (is_array($paper) ? $paper : [$paper] as $p)
            if ($p instanceof PaperInfo) {
                $this->astate->add_prow($p);
                $this->enabled_pids[] = $p->paperId;
            } else
                $this->enabled_pids[] = (int) $p;
    }

    function is_empty() {
        return empty($this->assigners);
    }

    function has_error() {
        return $this->has_error;
    }

    function clear_errors() {
        $this->msgs = [];
        $this->has_error = false;
        $this->has_user_error = false;
    }

    function msg($lineno, $msg, $status) {
        $l = ($this->filename ? $this->filename . ":" : "line ") . $lineno;
        $n = count($this->msgs) - 1;
        if ($n >= 0
            && $this->msgs[$n][0] === $l
            && $this->msgs[$n][1] === $msg)
            $this->msgs[$n][2] = max($this->msgs[$n][2], $status);
        else
            $this->msgs[] = [$l, $msg, $status];
        if ($status == 2)
            $this->has_error = true;
    }
    function error_at($lineno, $message) {
        $this->msg($lineno, $message, 2);
    }
    function error_here($message) {
        $this->msg($this->astate->lineno, $message, 2);
    }

    function errors_html($linenos = false) {
        $es = array();
        foreach ($this->msgs as $e) {
            $t = $e[1];
            if ($linenos && $e[0])
                $t = '<span class="lineno">' . htmlspecialchars($e[0]) . ':</span> ' . $t;
            if (empty($es) || $es[count($es) - 1] !== $t)
                $es[] = $t;
        }
        return $es;
    }
    function errors_div_html($linenos = false) {
        $es = $this->errors_html($linenos);
        if (empty($es))
            return "";
        else if ($linenos)
            return '<div class="parseerr"><p>' . join("</p>\n<p>", $es) . '</p></div>';
        else if (count($es) == 1)
            return $es[0];
        else
            return '<div><div class="mmm">' . join('</div><div class="mmm">', $es) . '</div></div>';
    }
    function errors_text($linenos = false) {
        $es = array();
        foreach ($this->msgs as $e) {
            $t = htmlspecialchars_decode(preg_replace(',<(?:[^\'">]|\'[^\']*\'|"[^"]*")*>,', "", $e[1]));
            if ($linenos && $e[0])
                $t = $e[0] . ': ' . $t;
            if (empty($es) || $es[count($es) - 1] !== $t)
                $es[] = $t;
        }
        return $es;
    }

    function report_errors() {
        if (!empty($this->msgs) && $this->has_error)
            Conf::msg_error('Assignment errors: ' . $this->errors_div_html(true) . ' Please correct these errors and try again.');
        else if (!empty($this->msgs))
            Conf::msg_warning('Assignment warnings: ' . $this->errors_div_html(true));
    }

    function json_result($linenos = false) {
        if ($this->has_error) {
            $jr = new JsonResult(403, ["ok" => false, "error" => $this->errors_div_html($linenos)]);
            if ($this->has_user_error) {
                $jr->status = 422;
                $jr->content["user_error"] = true;
            }
            return $jr;
        } else if (!empty($this->msgs)) {
            return new JsonResult(["ok" => true, "response" => $this->errors_div_html($linenos)]);
        } else {
            return new JsonResult(["ok" => true]);
        }
    }

    private static function req_user_html($req) {
        return Text::user_html_nolink(get($req, "firstName"), get($req, "lastName"), get($req, "email"));
    }

    private function set_my_conflicts() {
        $this->my_conflicts = array();
        $result = $this->conf->qe("select Paper.paperId, managerContactId from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId) where conflictType>0 and PaperConflict.contactId=?", $this->user->contactId);
        while (($row = edb_row($result)))
            $this->my_conflicts[$row[0]] = ($row[1] ? $row[1] : true);
        Dbl::free($result);
    }

    private static function apply_user_parts(&$req, $a) {
        foreach (array("firstName", "lastName", "email") as $i => $k)
            if (!get($req, $k) && get($a, $i))
                $req[$k] = $a[$i];
    }

    private function lookup_users(&$req, $assigner) {
        // move all usable identification data to email, firstName, lastName
        if (isset($req["name"]))
            self::apply_user_parts($req, Text::split_name($req["name"]));
        if (isset($req["user"]) && strpos($req["user"], " ") === false) {
            if (!get($req, "email"))
                $req["email"] = $req["user"];
        } else if (isset($req["user"]))
            self::apply_user_parts($req, Text::split_name($req["user"], true));

        // extract email, first, last
        $first = get($req, "firstName");
        $last = get($req, "lastName");
        $email = trim((string) get($req, "email"));
        $lemail = strtolower($email);
        $special = null;
        if ($lemail)
            $special = $lemail;
        else if (!$first && $last && strpos(trim($last), " ") === false)
            $special = trim(strtolower($last));
        $xspecial = $special;

        // check special: missing, "none", "any", "pc", "me", PC tag, "external"
        if ($special === "all" || $special === "any")
            return "any";
        else if ($special === "missing" || (!$first && !$last && !$lemail))
            return "missing";
        else if ($special === "none")
            return [$this->astate->none_user()];
        else if (preg_match('/\A(?:new-?)?anonymous(?:\d*|-?new)\z/', $special))
            return $special;
        if ($special && !$first && (!$lemail || !$last)) {
            $ret = ContactSearch::make_special($special, $this->astate->user);
            if ($ret->ids !== false)
                return $ret->contacts();
        }
        if (($special === "ext" || $special === "external")
            && $assigner->contact_set($req, $this->astate) === "reviewers") {
            $ret = array();
            foreach ($this->astate->reviewer_users() as $u)
                if (!$u->is_pc_member())
                    $ret[] = $u;
            return $ret;
        }

        // check for precise email match on existing contact (common case)
        if ($lemail && ($contact = $this->astate->user_by_email($email, false)))
            return array($contact);

        // check PC list
        $cset = $assigner->contact_set($req, $this->astate);
        $cset_text = "user";
        if ($cset === "pc") {
            $cset = $this->astate->pc_users();
            $cset_text = "PC member";
        } else if ($cset === "reviewers") {
            $cset = $this->astate->reviewer_users();
            $cset_text = "reviewer";
        }
        if ($cset) {
            $text = "";
            if ($first && $last)
                $text = "$last, $first";
            else if ($first || $last)
                $text = "$last$first";
            if ($email)
                $text .= " <$email>";
            $ret = ContactSearch::make_cset($text, $this->astate->user, $cset);
            if (count($ret->ids) == 1)
                return $ret->contacts();
            else if (empty($ret->ids))
                $this->error_here("No $cset_text matches “" . self::req_user_html($req) . "”.");
            else
                $this->error_here("“" . self::req_user_html($req) . "” matches more than one $cset_text, use a full email address to disambiguate.");
            return false;
        }

        // create contact
        if (!$email)
            return $this->error_here("Missing email address.");
        else if (!validate_email($email))
            return $this->error_here("Email address “" . htmlspecialchars($email) . "” is invalid.");
        else if (($u = $this->astate->user_by_email($email, true, $req)))
            return [$u];
        else
            return $this->error_here("Could not create user.");
    }

    static private function is_csv_header($req) {
        foreach (array("action", "assignment", "paper", "pid", "paperId") as $k)
            if (array_search($k, $req) !== false)
                return true;
        return false;
    }

    private function install_csv_header($csv, $req) {
        if (!self::is_csv_header($req)) {
            $csv->unshift($req);
            if (count($req) == 3
                && (!$req[2] || strpos($req[2], "@") !== false))
                $req = ["paper", "name", "email"];
            else if (count($req) == 2)
                $req = ["paper", "user"];
            else
                $req = ["paper", "action", "user", "round"];
        } else {
            $cleans = array("paper", "pid", "paper", "paperId",
                            "firstName", "first", "lastName", "last",
                            "firstName", "firstname", "lastName", "lastname",
                            "preference", "pref");
            for ($i = 0; $i < count($cleans); $i += 2)
                if (array_search($cleans[$i], $req) === false
                    && ($j = array_search($cleans[$i + 1], $req)) !== false)
                    $req[$j] = $cleans[$i];
        }

        $has_action = array_search("action", $req) !== false
            || array_search("assignment", $req) !== false;
        if (!$has_action && !isset($this->astate->defaults["action"])) {
            $defaults = $modifications = [];
            if (array_search("tag", $req) !== false)
                $defaults[] = "tag";
            if (array_search("preference", $req) !== false)
                $defaults[] = "preference";
            if (($j = array_search("lead", $req)) !== false) {
                $defaults[] = "lead";
                $modifications = [$j, "user"];
            }
            if (($j = array_search("shepherd", $req)) !== false) {
                $defaults[] = "shepherd";
                $modifications = [$j, "user"];
            }
            if (($j = array_search("decision", $req)) !== false) {
                $defaults[] = "decision";
                $modifications = [$j, "decision"];
            }
            if (count($defaults) == 1) {
                $this->astate->defaults["action"] = $defaults[0];
                for ($i = 0; $i < count($modifications); $i += 2)
                    $req[$modifications[$i]] = $modifications[$i + 1];
            }
        }
        $csv->set_header($req);

        if (!$has_action && !get($this->astate->defaults, "action"))
            return $this->error_at($csv->lineno(), "“assignment” column missing");
        else if (array_search("paper", $req) === false)
            return $this->error_at($csv->lineno(), "“paper” column missing");
        else {
            if (!isset($this->astate->defaults["action"]))
                $this->astate->defaults["action"] = "<missing>";
            return true;
        }
    }

    function hide_column($coldesc, $force = false) {
        if (!isset($this->unparse_columns[$coldesc]) || $force)
            $this->unparse_columns[$coldesc] = false;
    }

    function show_column($coldesc, $force = false) {
        if (!isset($this->unparse_columns[$coldesc]) || $force)
            $this->unparse_columns[$coldesc] = true;
    }

    function parse_csv_comment($line) {
        if (preg_match('/\A#\s*hotcrp_assign_display_search\s*(\S.*)\s*\z/', $line, $m))
            $this->unparse_search = $m[1];
        if (preg_match('/\A#\s*hotcrp_assign_show\s+(\w+)\s*\z/', $line, $m))
            $this->show_column($m[1]);
    }

    private function collect_papers($pfield, &$pids, $report_error) {
        $pfield = trim($pfield);
        if ($pfield !== "" && preg_match('/\A[\d,\s]+\z/', $pfield)) {
            $npids = [];
            foreach (preg_split('/[,\s]+/', $pfield) as $pid)
                $npids[] = intval($pid);
            $val = 2;
        } else if ($pfield !== "") {
            if (!isset($this->searches[$pfield])) {
                $search = new PaperSearch($this->user, ["q" => $pfield, "reviewer" => $this->astate->reviewer]);
                $this->searches[$pfield] = $search->paper_ids();
                if ($report_error)
                    foreach ($search->warnings as $w)
                        $this->error_here($w);
            }
            $npids = $this->searches[$pfield];
            $val = 1;
        } else {
            if ($report_error)
                $this->error_here("Bad paper column");
            return 0;
        }
        if (empty($npids) && $report_error)
            $this->msg($this->astate->lineno, "No papers match “" . htmlspecialchars($pfield) . "”", 1);

        // Implement paper restriction
        if ($this->enabled_pids !== null)
            $npids = array_intersect($npids, $this->enabled_pids);

        foreach ($npids as $pid)
            $pids[$pid] = $val;
        return $val;
    }

    private function collect_parser($req) {
        if (($action = get($req, "action")) === null
            && ($action = get($req, "assignment")) === null
            && ($action = get($req, "type")) === null)
            $action = $this->astate->defaults["action"];
        $action = strtolower(trim($action));
        return $this->conf->assignment_parser($action, $this->user);
    }

    private function expand_special_user($user, AssignmentParser $aparser, PaperInfo $prow, $req) {
        global $Now;
        if ($user === "any")
            $u = $aparser->expand_any_user($prow, $req, $this->astate);
        else if ($user === "missing") {
            $u = $aparser->expand_missing_user($prow, $req, $this->astate);
            if ($u === false || $u === null) {
                $this->astate->error("User required.");
                return false;
            }
        } else if (preg_match('/\A(?:new-?)?anonymous/', $user))
            $u = $aparser->expand_anonymous_user($prow, $req, $user, $this->astate);
        else
            $u = false;
        if ($u === false || $u === null)
            $this->astate->error("User “" . htmlspecialchars($user) . "” is not allowed here.");
        return $u;
    }

    private function apply($aparser, $req) {
        // parse paper
        $pids = [];
        $x = $this->collect_papers((string) get($req, "paper"), $pids, true);
        if (empty($pids))
            return false;
        $pfield_straight = $x == 2;
        $pids = array_keys($pids);

        // check action
        if (!$aparser)
            return $this->error_here("Unknown action.");
        if ($this->enabled_actions !== null
            && !isset($this->enabled_actions[$aparser->type]))
            return $this->error_here("Action " . htmlspecialchars($aparser->type) . " disabled.");
        $aparser->load_state($this->astate);

        // clean user parts
        $contacts = $this->lookup_users($req, $aparser);
        if ($contacts === false || $contacts === null)
            return false;

        // maybe filter papers
        if (count($pids) > 20
            && is_array($contacts)
            && count($contacts) == 1
            && $contacts[0]->contactId > 0
            && ($pf = $aparser->paper_filter($contacts[0], $req, $this->astate))) {
            $npids = [];
            foreach ($pids as $p)
                if (get($pf, $p))
                    $npids[] = $p;
            $pids = $npids;
        }

        // fetch papers
        $this->astate->fetch_prows($pids);
        $this->astate->errors = [];
        $this->astate->paper_exact_match = $pfield_straight;

        // check conflicts and perform assignment
        $any_success = false;
        foreach ($pids as $p) {
            assert(is_int($p));
            $prow = $this->astate->prow($p);
            if (!$prow) {
                $this->error_here("Submission #$p does not exist.");
                continue;
            }

            $err = $aparser->allow_paper($prow, $this->astate);
            if ($err !== true) {
                if (is_string($err))
                    $this->astate->paper_error($err);
                continue;
            }

            $this->encounter_order[$p] = $p;

            // expand “all” and “missing”
            $pusers = $contacts;
            if (!is_array($pusers)) {
                $pusers = $this->expand_special_user($pusers, $aparser, $prow, $req);
                if ($pusers === false || $pusers === null)
                    break;
            }

            foreach ($pusers as $contact) {
                $err = $aparser->allow_contact($prow, $contact, $req, $this->astate);
                if ($err === false) {
                    if (!$contact->contactId) {
                        $this->astate->error("User “none” is not allowed here. [{$contact->email}]");
                        break 2;
                    } else if ($prow->has_conflict($contact)) {
                        $err = Text::user_html_nolink($contact) . " has a conflict with #$p.";
                    } else {
                        $err = Text::user_html_nolink($contact) . " cannot be assigned to #$p.";
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
                    continue;
                }

                $any_success = true;
            }
        }

        foreach ($this->astate->errors as $e) {
            $this->msg($this->astate->lineno, $e[0], $e[1] || !$any_success ? 2 : 1);
            if ($e[2])
                $this->has_user_error = true;
        }
        return $any_success;
    }

    function parse($text, $filename = null, $defaults = null, $alertf = null) {
        assert(empty($this->assigners));
        $this->filename = $filename;
        $this->astate->defaults = $defaults ? : array();

        if ($text instanceof CsvParser)
            $csv = $text;
        else {
            $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
            $csv->set_comment_chars("%#");
            $csv->set_comment_function(array($this, "parse_csv_comment"));
        }
        if (!($req = $csv->header() ? : $csv->next()))
            return $this->error_at($csv->lineno(), "empty file");
        if (!$this->install_csv_header($csv, $req))
            return false;

        $old_overrides = $this->user->set_overrides($this->astate->overrides);

        // parse file, load papers all at once
        $lines = $pids = [];
        while (($req = $csv->next()) !== false) {
            $aparser = $this->collect_parser($req);
            $this->collect_papers((string) get($req, "paper"), $pids, false);
            if ($aparser
                && ($pfield = $aparser->expand_papers($req, $this->astate)))
                $this->collect_papers($pfield, $pids, false);
            $lines[] = [$csv->lineno(), $aparser, $req];
        }
        if (!empty($pids)) {
            $this->astate->lineno = $csv->lineno();
            $this->astate->fetch_prows(array_keys($pids), true);
        }

        // now parse assignment
        foreach ($lines as $i => $linereq) {
            $this->astate->lineno = $linereq[0];
            if ($i % 100 == 0) {
                if ($alertf)
                    call_user_func($alertf, $this, $linereq[0], $linereq[2]);
                set_time_limit(30);
            }
            $this->apply($linereq[1], $linereq[2]);
        }
        if ($alertf)
            call_user_func($alertf, $this, $csv->lineno(), false);

        // call finishers
        foreach ($this->astate->finishers as $fin)
            $fin->apply_finisher($this->astate);

        // create assigners for difference
        $this->assigners_pidhead = $pidtail = [];
        foreach ($this->astate->diff() as $pid => $difflist)
            foreach ($difflist as $item) {
                try {
                    if (($a = $item->realize($this->astate))) {
                        if ($a->pid > 0) {
                            $index = count($this->assigners);
                            if (isset($pidtail[$a->pid]))
                                $pidtail[$a->pid]->next_index = $index;
                            else
                                $this->assigners_pidhead[$a->pid] = $index;
                            $pidtail[$a->pid] = $a;
                        }
                        $this->assigners[] = $a;
                    }
                } catch (Exception $e) {
                    $this->error_at($item->lineno, $e->getMessage());
                }
            }

        $this->user->set_overrides($old_overrides);
    }

    function assigned_types() {
        $types = array();
        foreach ($this->assigners as $assigner)
            $types[$assigner->type] = true;
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
                if ($lpid >= 0 && $pid != $rpid + 1)
                    $xpids[] = $lpid == $rpid ? $lpid : "$lpid-$rpid";
                if ($lpid < 0 || $pid != $rpid + 1)
                    $lpid = $pid;
                $rpid = $pid;
            }
            if ($lpid >= 0)
                $xpids[] = $lpid == $rpid ? $lpid : "$lpid-$rpid";
            $pids = $xpids;
        }
        return $pids;
    }

    function type_description() {
        if ($this->assignment_type === null)
            foreach ($this->assigners as $assigner) {
                $desc = $assigner->unparse_description();
                if ($this->assignment_type === null
                    || $this->assignment_type === $desc)
                    $this->assignment_type = $desc;
                else
                    $this->assignment_type = "";
            }
        return $this->assignment_type;
    }

    function unparse_paper_assignment(PaperInfo $prow) {
        $assigners = [];
        for ($index = get($this->assigners_pidhead, $prow->paperId);
             $index !== null;
             $index = $assigner->next_index) {
            $assigners[] = $assigner = $this->assigners[$index];
            if ($assigner->contact && !isset($assigner->contact->sorter))
                Contact::set_sorter($assigner->contact, $this->conf);
        }
        usort($assigners, function ($assigner1, $assigner2) {
            $c1 = $assigner1->contact;
            $c2 = $assigner2->contact;
            if ($c1 && $c2)
                return strnatcasecmp($c1->sorter, $c2->sorter);
            else if ($c1 || $c2)
                return $c1 ? -1 : 1;
            else
                return strcmp($c1->type, $c2->type);
        });
        $t = "";
        foreach ($assigners as $assigner) {
            if (($text = $assigner->unparse_display($this))) {
                $t .= ($t ? ", " : "") . '<span class="nw">' . $text . '</span>';
            }
        }
        if (isset($this->my_conflicts[$prow->paperId])) {
            if ($this->my_conflicts[$prow->paperId] !== true)
                $t = '<em>Hidden for conflict</em>';
            else
                $t = PaperList::wrapChairConflict($t);
        }
        return $t;
    }
    function echo_unparse_display() {
        $this->set_my_conflicts();
        $deltarev = new AssignmentCountSet($this->conf);
        foreach ($this->assigners as $assigner)
            $assigner->account($this, $deltarev);

        $query = $this->assigned_pids(true);
        if ($this->unparse_search)
            $query_order = "(" . $this->unparse_search . ") THEN HEADING:none " . join(" ", $query);
        else
            $query_order = empty($query) ? "NONE" : join(" ", $query);
        foreach ($this->unparse_columns as $k => $v) {
            if ($v)
                $query_order .= " show:$k";
        }
        $query_order .= " show:autoassignment";
        $search = new PaperSearch($this->user, ["t" => "vis", "q" => $query_order, "reviewer" => $this->astate->reviewer]);
        $plist = new PaperList($search);
        $plist->add_column("autoassignment", new AutoassignmentPaperColumn($this));
        $plist->set_table_id_class("foldpl", "pltable_full");
        echo $plist->table_html("reviewers", ["nofooter" => 1]);

        if (count(array_intersect_key($deltarev->bypc, $this->conf->pc_members()))) {
            $summary = [];
            $tagger = new Tagger($this->user);
            $nrev = new AssignmentCountSet($this->conf);
            $deltarev->rev && $nrev->load_rev();
            $deltarev->lead && $nrev->load_lead();
            $deltarev->shepherd && $nrev->load_shepherd();
            foreach ($this->conf->pc_members() as $p)
                if ($deltarev->get($p->contactId)->ass) {
                    $t = '<div class="ctelt"><div class="ctelti';
                    if (($k = $p->viewable_color_classes($this->user)))
                        $t .= ' ' . $k;
                    $t .= '"><span class="taghl">' . $this->user->name_html_for($p) . "</span>: "
                        . plural($deltarev->get($p->contactId)->ass, "assignment")
                        . self::review_count_report($nrev, $deltarev, $p, "After assignment:&nbsp;")
                        . "<hr class=\"c\" /></div></div>";
                    $summary[] = $t;
                }
            if (!empty($summary))
                echo "<div class=\"g\"></div>\n",
                    "<h3>Summary</h3>\n",
                    '<div class="pc_ctable">', join("", $summary), "</div>\n";
        }
    }

    function unparse_csv() {
        $this->set_my_conflicts();
        $acsv = new AssignmentCsv;
        foreach ($this->assigners as $assigner)
            if (($x = $assigner->unparse_csv($this, $acsv))) {
                if (isset($x[0])) {
                    foreach ($x as $elt)
                        $acsv->add($elt);
                } else
                    $acsv->add($x);
            }
        $acsv->header = array_keys($acsv->header);
        return $acsv;
    }

    function prow($pid) {
        return $this->astate->prow($pid);
    }

    function execute($verbose = false) {
        global $Now;
        if ($this->has_error || empty($this->assigners)) {
            if ($verbose && !empty($this->msgs))
                $this->report_errors();
            else if ($verbose)
                $this->conf->warnMsg("Nothing to assign.");
            return !$this->has_error; // true means no errors
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
            if ($assigner->pid > 0)
                $pids[$assigner->pid] = true;
        }

        // execute assignments
        $tables = array();
        foreach ($locks as $t => $type)
            $tables[] = "$t $type";
        $this->conf->qe("lock tables " . join(", ", $tables));
        $this->cleanup_callbacks = $this->cleanup_notify_tracker = [];
        $this->qe_stager = null;

        foreach ($this->assigners as $assigner)
            $assigner->execute($this);

        if ($this->qe_stager)
            call_user_func($this->qe_stager, null);
        $this->conf->qe("unlock tables");
        $this->conf->save_logs(false);

        // confirmation message
        if ($verbose) {
            if ($this->conf->setting("pcrev_assigntime") == $Now)
                $this->conf->confirmMsg("Assignments saved! You may want to <a href=\"" . hoturl("mail", "template=newpcrev") . "\">send mail about the new assignments</a>.");
            else
                $this->conf->confirmMsg("Assignments saved!");
        }

        // clean up
        foreach ($this->assigners as $assigner)
            $assigner->cleanup($this);
        foreach ($this->cleanup_callbacks as $cb)
            call_user_func($cb[0], $this, $cb[1]);
        if (!empty($this->cleanup_notify_tracker)
            && $this->conf->opt("trackerCometSite"))
            MeetingTracker::contact_tracker_comet($this->conf, array_keys($this->cleanup_notify_tracker));
        if (!empty($pids))
            $this->conf->update_autosearch_tags(array_keys($pids));

        return true;
    }

    function stage_qe($query /* ... */) {
        $this->stage_qe_apply($query, array_slice(func_get_args(), 1));
    }
    function stage_qe_apply($query, $args) {
        if (!$this->qe_stager)
            $this->qe_stager = Dbl::make_multi_qe_stager($this->conf->dblink);
        call_user_func($this->qe_stager, $query, $args);
    }

    function cleanup_callback($name, $func, $arg = null) {
        if (!isset($this->cleanup_callbacks[$name]))
            $this->cleanup_callbacks[$name] = [$func, null];
        if (func_num_args() > 2)
            $this->cleanup_callbacks[$name][1][] = $arg;
    }
    function cleanup_update_rights() {
        $this->cleanup_callback("update_rights", "Contact::update_rights");
    }
    function cleanup_notify_tracker($pid) {
        $this->cleanup_notify_tracker[$pid] = true;
    }

    private static function _review_count_link($count, $word, $pl, $prefix, $pc) {
        $word = $pl ? plural($count, $word) : $count . "&nbsp;" . $word;
        if ($count == 0)
            return $word;
        return '<a class="qq" href="' . hoturl("search", "q=" . urlencode("$prefix:$pc->email"))
            . '">' . $word . "</a>";
    }

    private static function _review_count_report_one($ct, $pc) {
        $t = self::_review_count_link($ct->rev, "review", true, "re", $pc);
        $x = array();
        if ($ct->meta != 0)
            $x[] = self::_review_count_link($ct->meta, "meta", false, "meta", $pc);
        if ($ct->pri != $ct->rev && (!$ct->meta || $ct->meta != $ct->rev))
            $x[] = self::_review_count_link($ct->pri, "primary", false, "pri", $pc);
        if ($ct->sec != 0 && $ct->sec != $ct->rev && $ct->pri + $ct->sec != $ct->rev)
            $x[] = self::_review_count_link($ct->sec, "secondary", false, "sec", $pc);
        if (!empty($x))
            $t .= " (" . join(", ", $x) . ")";
        return $t;
    }

    static function review_count_report($nrev, $deltarev, $pc, $prefix) {
        $data = [];
        $ct = $nrev->get($pc->contactId);
        $deltarev && ($ct = $ct->add($deltarev->get($pc->contactId)));
        if (!$deltarev || $deltarev->rev)
            $data[] = self::_review_count_report_one($ct, $pc);
        if ($deltarev && $deltarev->lead)
            $data[] = self::_review_count_link($ct->lead, "lead", true, "lead", $pc);
        if ($deltarev && $deltarev->shepherd)
            $data[] = self::_review_count_link($ct->shepherd, "shepherd", true, "shepherd", $pc);
        return '<span class="pcrevsum">' . $prefix . join(", ", $data) . "</span>";
    }

    static function run($contact, $text, $forceShow = null) {
        $aset = new AssignmentSet($contact, $forceShow);
        $aset->parse($text);
        return $aset->execute();
    }
}


class AutoassignmentPaperColumn extends PaperColumn {
    private $aset;
    function __construct(AssignmentSet $aset) {
        parent::__construct($aset->conf, ["name" => "autoassignment", "row" => true, "className" => "pl_autoassignment"]);
        $this->aset = $aset;
    }
    function header(PaperList $pl, $is_text) {
        return "Assignment";
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $this->aset->unparse_paper_assignment($row);
    }
}
