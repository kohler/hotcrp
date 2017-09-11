<?php
// assigners.php -- HotCRP helper classes for assignments
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

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
    public $prows = array();
    public $finishers = array();
    public $paper_exact_match = true;
    public $errors = [];

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $this->reviewer = $user;
        $this->cmap = new AssignerContacts($this->conf);
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
    private function query_items($q) {
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
        if (!($p = get($this->prows, $pid))) {
            $this->fetch_prows($pid);
            $p = $this->prows[$pid];
        }
        return $p;
    }
    function prows() {
        return $this->prows;
    }
    function fetch_prows($pids, $initial_load = false) {
        $pids = is_array($pids) ? $pids : array($pids);
        $fetch_pids = array();
        foreach ($pids as $p)
            if (!isset($this->prows[$p]))
                $fetch_pids[] = $p;
        assert($initial_load || empty($fetch_pids));
        if (!empty($fetch_pids)) {
            $result = $this->user->paper_result(["paperId" => $fetch_pids, "tags" => $this->conf->has_tracks()]);
            while ($result && ($prow = PaperInfo::fetch($result, $this->user)))
                $this->prows[$prow->paperId] = $prow;
            Dbl::free($result);
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
        $this->errors[] = [$message, true];
    }
    function paper_error($message) {
        $this->errors[] = [$message, $this->paper_exact_match];
    }
}

class AssignerContacts {
    private $conf;
    private $by_id = array();
    private $by_lemail = array();
    private $has_pc = false;
    private $none_user;
    static private $next_fake_id = -10;
    static public $query = "ContactInfo.contactId, firstName, lastName, unaccentedName, email, roles, contactTags";
    function __construct(Conf $conf) {
        global $Me;
        $this->conf = $conf;
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
                $cargs["password"] = "";
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
            foreach (["email", "firstName", "lastName", "affiliation", "password", "disabled"] as $k)
                if ($c->$k !== null)
                    $cargs[$k] = $c->$k;
            if ($cx->is_anonymous_user())
                $cargs["no_validate_email"] = true;
            $cx = Contact::create($this->conf, $cargs);
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
        $csvg->set_header($this->header, true);
        $csvg->set_selection($this->header);
        $csvg->add($this->data);
        return $csvg->unparse();
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
    function load_state(AssignmentState $state) {
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
    function account(AssignmentCountSet $delta) {
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
    static private $type_map = [
        "meta" => REVIEW_META,
        "primary" => REVIEW_PRIMARY, "pri" => REVIEW_PRIMARY,
        "secondary" => REVIEW_SECONDARY, "sec" => REVIEW_SECONDARY,
        "optional" => REVIEW_PC, "opt" => REVIEW_PC, "pc" => REVIEW_PC,
        "external" => REVIEW_EXTERNAL, "ext" => REVIEW_EXTERNAL
    ];
    static private $type_revmap = [
        REVIEW_EXTERNAL => "review", REVIEW_PC => "pcreview",
        REVIEW_SECONDARY => "secondary", REVIEW_PRIMARY => "primary",
        REVIEW_META => "metareview"
    ];
    static function parse_type($str) {
        $str = strtolower($str);
        if ($str === "review" || $str === ""
            || $str === "all" || $str === "any")
            return null;
        if (str_ends_with($str, "review"))
            $str = substr($str, 0, -6);
        return get(self::$type_map, $str, false);
    }
    static function unparse_type($rtype) {
        return get(self::$type_revmap, $rtype, "clearreview");
    }
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
            && ($this->oldtype = self::parse_type($targ0)) === false)
            $this->error = "Invalid reviewtype.";
        if ($targ1 !== null && $targ1 !== "" && $rtype != 0
            && ($this->newtype = self::parse_type($targ1)) === false)
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
    static private function rtype_name($rtype) {
        if ($rtype > 0)
            return strtolower(ReviewForm::$revtype_names[$rtype]);
        else
            return $rtype < 0 ? "review" : "clearreview";
    }
    function __construct($aj) {
        parent::__construct($aj->name);
        if ($aj->review_type)
            $this->rtype = (int) ReviewAssigner_Data::parse_type($aj->review_type);
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
            && get($state->defaults, "extrev_notify")
            && !$this->contact->is_anonymous_user())
            $this->notify = true;
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
        $aset->show_column("reviewers");
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
        if ($this->rtype > 0)
            $rname = strtolower(ReviewForm::$revtype_names[$this->rtype]);
        else
            $rname = "clear";
        $x = ["pid" => $this->pid, "action" => "{$rname}review",
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
    function account(AssignmentCountSet $deltarev) {
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
            && ($oldtype = ReviewAssigner_Data::parse_type($targ0)) === false)
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
    }
}


class Lead_AssignmentParser extends AssignmentParser {
    private $key;
    private $remove;
    function __construct($aj) {
        parent::__construct($aj->name);
        $this->key = $aj->type;
        $this->remove = $aj->remove;
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if ($this->key === "manager")
            return $state->user->privChair ? true : "You can’t change paper administrators.";
        else
            return parent::allow_paper($prow, $state);
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type($this->key, ["pid"], "Lead_Assigner::make"))
            return;
        $k = $this->key . "ContactId";
        foreach ($state->prows() as $prow) {
            if (($cid = +$prow->$k))
                $state->load(["type" => $this->key, "pid" => $prow->paperId, "_cid" => $cid]);
        }
    }
    function expand_any_user(PaperInfo $prow, &$req, AssignmentState $state) {
        if ($this->remove) {
            $m = $state->query(["type" => $this->key, "pid" => $prow->paperId]);
            $cids = array_map(function ($x) { return $x["_cid"]; }, $m);
            return $state->users_by_id($cids);
        } else
            return false;
    }
    function expand_missing_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return $this->expand_any_user($prow, $req, $state);
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        if ($this->remove || !$contact->contactId)
            return true;
        else if (!$contact->can_accept_review_assignment_ignore_conflict($prow)) {
            $verb = $this->key === "manager" ? "administer" : $this->key;
            return Text::user_html_nolink($contact) . " can’t $verb #{$prow->paperId}.";
        } else
            return AssignmentParser::unconflicted($prow, $contact, $state);
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        $remcid = null;
        if ($this->remove && $contact->contactId)
            $remcid = $contact->contactId;
        $state->remove(array("type" => $this->key, "pid" => $prow->paperId, "_cid" => $remcid));
        if (!$this->remove && $contact->contactId)
            $state->add(array("type" => $this->key, "pid" => $prow->paperId, "_cid" => $contact->contactId));
    }
}

class Lead_Assigner extends Assigner {
    private $description;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->description = $this->type === "manager" ? "administrator" : $this->type;
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        return new Lead_Assigner($item, $state);
    }
    function icon() {
        if ($this->type === "lead")
            return review_lead_icon();
        else if ($this->type === "shepherd")
            return review_shepherd_icon();
        else
            return "({$this->description})";
    }
    function unparse_description() {
        return $this->description;
    }
    function unparse_display(AssignmentSet $aset) {
        $aset->show_column($this->description);
        if (!$this->item->deleted())
            $aset->show_column("reviewers");
        $t = [];
        if ($this->item->existed())
            $t[] = '<del>' . $aset->user->reviewer_html_for($this->item->get(true, "_cid")) . " " . $this->icon() . '</del>';
        if (!$this->item->deleted())
            $t[] = '<ins>' . $aset->user->reviewer_html_for($this->contact) . " " . $this->icon() . '</ins>';
        return join(" ", $t);
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = ["pid" => $this->pid, "action" => $this->description];
        if ($this->item->deleted())
            $x["email"] = "none";
        else {
            $x["email"] = $this->contact->email;
            $x["name"] = $this->contact->name_text();
        }
        return $x;
    }
    function account(AssignmentCountSet $deltarev) {
        $k = $this->type;
        if ($k === "lead" || $k === "shepherd") {
            $deltarev->$k = true;
            if ($this->item->existed()) {
                $ct = $deltarev->ensure($this->item->get(true, "_cid"));
                ++$ct->ass;
                --$ct->$k;
            }
            if (!$this->item->deleted()) {
                $ct = $deltarev->ensure($this->cid);
                ++$ct->ass;
                ++$ct->$k;
            }
        }
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["Paper"] = $locks["Settings"] = "write";
    }
    function execute(AssignmentSet $aset) {
        $new_cid = $this->item->get(false, "_cid") ? : 0;
        $old_cid = $this->item->get(true, "_cid") ? : 0;
        $aset->conf->qe("update Paper set {$this->type}ContactId=? where paperId=? and {$this->type}ContactId=?", $new_cid, $this->pid, $old_cid);
        if ($new_cid)
            $aset->user->log_activity_for($new_cid, "Set {$this->description}", $this->pid);
        else
            $aset->user->log_activity("Clear {$this->description}", $this->pid);
        if ($this->type === "lead" || $this->type === "shepherd") {
            $aset->cleanup_callback("lead", function ($aset, $vals) {
                $aset->conf->update_paperlead_setting(min($vals));
            }, $new_cid ? 1 : 0);
        } else if ($this->type === "manager") {
            $aset->cleanup_callback("manager", function ($aset, $vals) {
                $aset->conf->update_papermanager_setting(min($vals));
            }, $new_cid ? 1 : 0);
        }
        $aset->cleanup_update_rights();
    }
}


class Conflict_AssignmentParser extends AssignmentParser {
    private $remove;
    private $iscontact;
    function __construct($aj) {
        parent::__construct("conflict");
        $this->remove = $aj->remove;
        $this->iscontact = $aj->iscontact;
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->user->can_administer($prow)
            && !$state->user->privChair
            && !$state->user->act_author_view($prow))
            return "You can’t administer #{$prow->paperId}.";
        else if (!$this->iscontact
                 && !$state->user->can_administer($prow)
                 && ($whyNot = $state->user->perm_update_paper($prow)))
            return whyNotText($whyNot, "edit");
        else
            return true;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("conflict", ["pid", "cid"], "Conflict_Assigner::make"))
            return;
        $result = $state->conf->qe("select paperId, contactId, conflictType from PaperConflict where conflictType>0 and paperId?a", $state->paper_ids());
        while (($row = edb_row($result)))
            $state->load(["type" => "conflict", "pid" => +$row[0], "cid" => +$row[1], "_ctype" => +$row[2]]);
        Dbl::free($result);
    }
    function expand_any_user(PaperInfo $prow, &$req, AssignmentState $state) {
        if ($this->remove) {
            $m = $state->query(["type" => "conflict", "pid" => $prow->paperId]);
            $cids = array_map(function ($x) { return $x["cid"]; }, $m);
            return $state->users_by_id($cids);
        } else
            return false;
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return $contact->contactId != 0;
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        $res = $state->remove(["type" => "conflict", "pid" => $prow->paperId, "cid" => $contact->contactId]);
        $admin = $state->user->can_administer($prow);
        if ($this->remove)
            $ct = 0;
        else if ($this->iscontact)
            $ct = CONFLICT_CONTACTAUTHOR;
        else {
            $ct = 1000;
            $cts = get($req, "conflicttype", get($req, "conflict"));
            if ($cts !== null && ($ct = Conflict::parse($cts, 1000)) === false)
                return "Bad conflict type.";
            if ($ct !== 1000)
                $ct = Conflict::constrain_editable($ct, $admin);
        }
        if (!empty($res)) {
            $old_ct = $res[0]["_ctype"];
            if ((!$this->iscontact && $old_ct >= CONFLICT_AUTHOR)
                || (!$this->iscontact
                    && $ct < CONFLICT_CHAIRMARK
                    && $old_ct == CONFLICT_CHAIRMARK
                    && !$admin)
                || ($this->iscontact
                    && $ct == 0
                    && $old_ct > 0
                    && $old_ct < CONFLICT_AUTHOR)
                || ($ct === 1000 && $old_ct > 0))
                $ct = $old_ct;
        }
        if ($ct === 1000)
            $ct = $admin ? CONFLICT_CHAIRMARK : CONFLICT_AUTHORMARK;
        if ($ct > 0)
            $state->add(["type" => "conflict", "pid" => $prow->paperId, "cid" => $contact->contactId, "_ctype" => $ct]);
    }
}

class Conflict_Assigner extends Assigner {
    private $ctype;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->ctype = $item->get(false, "_ctype");
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        if ($item->deleted()
            && $item->get(true, "_ctype") >= CONFLICT_CONTACTAUTHOR) {
            $ncontacts = 0;
            foreach ($state->query(["type" => "conflict", "pid" => $item["pid"]]) as $m)
                if ($m["_ctype"] >= CONFLICT_CONTACTAUTHOR)
                    ++$ncontacts;
            if ($ncontacts == 0)
                throw new Exception("Each submission must have at least one contact.");
        }
        return new Conflict_Assigner($item, $state);
    }
    function unparse_description() {
        return "conflict";
    }
    private function icon($before) {
        $ctype = $this->item->get($before, "_ctype");
        if ($ctype >= CONFLICT_AUTHOR)
            return review_type_icon(-2);
        else if ($ctype > 0)
            return review_type_icon(-1);
        else
            return "";
    }
    function unparse_display(AssignmentSet $aset) {
        $aset->show_column("pcconf");
        $t = $aset->user->reviewer_html_for($this->contact);
        if ($this->item->deleted())
            $t = '<del>' . $t . ' ' . $this->icon(true) . '</del>';
        else if (!$this->item->existed())
            $t = '<ins>' . $t . ' ' . $this->icon(false) . '</ins>';
        else
            $t = $t . ' <del>' . $this->icon(true) . '</del> <ins>' . $this->icon(false) . '</ins>';
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        return [
            "pid" => $this->pid, "action" => $this->ctype ? "conflict" : "noconflict",
            "email" => $this->contact->email, "name" => $this->contact->name_text()
        ];
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperConflict"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if ($this->ctype)
            $aset->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($this->pid,$this->cid,$this->ctype) on duplicate key update conflictType=values(conflictType)");
        else
            $aset->conf->qe("delete from PaperConflict where paperId=$this->pid and contactId=$this->cid");
    }
}


class NextTagAssigner {
    private $tag;
    public $pidindex = array();
    private $first_index;
    private $next_index;
    private $isseq;
    function __construct($state, $tag, $index, $isseq) {
        $this->tag = $tag;
        $ltag = strtolower($tag);
        $res = $state->query(array("type" => "tag", "ltag" => $ltag));
        foreach ($res as $x)
            $this->pidindex[$x["pid"]] = (float) $x["_index"];
        asort($this->pidindex);
        if ($index === null) {
            $indexes = array_values($this->pidindex);
            sort($indexes);
            $index = count($indexes) ? $indexes[count($indexes) - 1] : 0;
            $index += ($isseq ? 1 : self::$value_increment_map[mt_rand(0, 9)]);
        }
        $this->first_index = $this->next_index = ceil($index);
        $this->isseq = $isseq;
    }
    private static $value_increment_map = array(1, 1, 1, 1, 1, 2, 2, 2, 3, 4);
    function next_index($isseq) {
        $index = $this->next_index;
        $this->next_index += ($isseq ? 1 : self::$value_increment_map[mt_rand(0, 9)]);
        return (float) $index;
    }
    function apply_finisher(AssignmentState $state) {
        if ($this->next_index == $this->first_index)
            return;
        $ltag = strtolower($this->tag);
        foreach ($this->pidindex as $pid => $index)
            if ($index >= $this->first_index && $index < $this->next_index) {
                $x = $state->query_unmodified(array("type" => "tag", "pid" => $pid, "ltag" => $ltag));
                if (!empty($x))
                    $item = $state->add(["type" => "tag", "pid" => $pid, "ltag" => $ltag,
                                         "_tag" => $this->tag,
                                         "_index" => $this->next_index($this->isseq),
                                         "_override" => true]);
            }
    }
}

class Tag_AssignmentParser extends UserlessAssignmentParser {
    const NEXT = 1;
    const NEXTSEQ = 2;
    private $remove;
    private $isnext;
    function __construct($aj) {
        parent::__construct("tag");
        $this->remove = $aj->remove;
        if (!$this->remove && $aj->next)
            $this->isnext = $aj->next === "seq" ? self::NEXTSEQ : self::NEXT;
    }
    function expand_papers(&$req, AssignmentState $state) {
        return $this->isnext ? "ALL" : false;
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (($whyNot = $state->user->perm_change_some_tag($prow)))
            return whyNotText($whyNot, "change tag");
        else
            return true;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("tag", ["pid", "ltag"], "Tag_Assigner::make"))
            return;
        $result = $state->conf->qe("select paperId, tag, tagIndex from PaperTag where paperId?a", $state->paper_ids());
        while (($row = edb_row($result)))
            $state->load(["type" => "tag", "pid" => +$row[0], "ltag" => strtolower($row[1]), "_tag" => $row[1], "_index" => (float) $row[2]]);
        Dbl::free($result);
    }
    private function cannot_view_error(PaperInfo $prow, $tag, AssignmentState $state) {
        if ($prow->conflict_type($state->user))
            $state->paper_error("You have a conflict with #{$prow->paperId}.");
        else
            $state->paper_error("You can’t view that tag for #{$prow->paperId}.");
        return true;
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        if (!($tag = get($req, "tag")))
            return "Tag missing.";

        // index argument
        $xindex = get($req, "index");
        if ($xindex === null)
            $xindex = get($req, "value");
        if ($xindex !== null && ($xindex = trim($xindex)) !== "") {
            $tag = preg_replace(',\A(#?.+)(?:[=!<>]=?|#|≠|≤|≥)(?:|-?\d+(?:\.\d*)?|-?\.\d+|any|all|none|clear)\z,i', '$1', $tag);
            if (!preg_match(',\A(?:[=!<>]=?|#|≠|≤|≥),i', $xindex))
                $xindex = "#" . $xindex;
            $tag .= $xindex;
        }

        // tag parsing; see also PaperSearch::_check_tag
        $remove = $this->remove;
        if ($tag[0] === "-" && !$remove) {
            $remove = true;
            $tag = substr($tag, 1);
        } else if ($tag[0] === "+" && !$remove)
            $tag = substr($tag, 1);
        if ($tag[0] === "#")
            $tag = substr($tag, 1);
        $m = array(null, "", "", "", "");
        $xtag = $tag;
        if (preg_match(',\A(.*?)([=!<>]=?|#|≠|≤|≥)(.*?)\z,', $xtag, $xm))
            list($xtag, $m[3], $m[4]) = array($xm[1], $xm[2], strtolower($xm[3]));
        if (!preg_match(',\A(|[^#]*~)([a-zA-Z!@*_:.]+[-a-zA-Z0-9!@*_:.\/]*)\z,i', $xtag, $xm))
            return "Invalid tag “" . htmlspecialchars($xtag) . "”.";
        else if ($m[3] && $m[4] === "")
            return "Value missing.";
        else if ($m[3] && !preg_match(',\A([-+]?(?:\d+(?:\.\d*)?|\.\d+)|any|all|none|clear)\z,', $m[4]))
            return "Value must be a number.";
        else
            list($m[1], $m[2]) = array($xm[1], $xm[2]);
        if ($m[1] == "~" || strcasecmp($m[1], "me~") == 0)
            $m[1] = ($contact->contactId ? : $state->user->contactId) . "~";
        // ignore attempts to change vote tags
        if (!$m[1] && $state->conf->tags()->is_votish($m[2]))
            return false;

        // add and remove use different paths
        $remove = $remove || $m[4] === "none" || $m[4] === "clear";
        if (!$remove && strpos($tag, "*") !== false)
            return "Tag wildcards aren’t allowed when adding tags.";
        if ($remove)
            return $this->apply_remove($prow, $state, $m);

        // resolve twiddle portion
        if ($m[1] && $m[1] != "~~" && !ctype_digit(substr($m[1], 0, strlen($m[1]) - 1))) {
            $c = substr($m[1], 0, strlen($m[1]) - 1);
            $twiddlecids = ContactSearch::make_pc($c, $state->user, $state->reviewer)->ids;
            if (empty($twiddlecids))
                return "“" . htmlspecialchars($c) . "” doesn’t match a PC member.";
            else if (count($twiddlecids) > 1)
                return "“" . htmlspecialchars($c) . "” matches more than one PC member; be more specific to disambiguate.";
            $m[1] = $twiddlecids[0] . "~";
        }

        // resolve tag portion
        if (preg_match(',\A(?:none|any|all)\z,i', $m[2]))
            return "Tag “{$tag}” is reserved.";
        $tag = $m[1] . $m[2];

        // resolve index portion
        if ($m[3] && $m[3] != "#" && $m[3] != "=" && $m[3] != "==")
            return "“" . htmlspecialchars($m[3]) . "” isn’t allowed when adding tags.";
        if ($this->isnext)
            $index = $this->apply_next_index($prow->paperId, $tag, $state, $m);
        else
            $index = $m[3] ? cvtnum($m[4], 0) : null;

        // if you can't view the tag, you can't set the tag
        // (information exposure)
        if (!$state->user->can_view_tag($prow, $tag))
            return $this->cannot_view_error($prow, $tag, $state);

        // save assignment
        $ltag = strtolower($tag);
        if ($index === null
            && ($x = $state->query(["type" => "tag", "pid" => $prow->paperId, "ltag" => $ltag])))
            $index = $x[0]["_index"];
        $vtag = $state->conf->tags()->votish_base($tag);
        if ($vtag && $state->conf->tags()->is_vote($vtag) && !$index)
            $state->remove(["type" => "tag", "pid" => $prow->paperId, "ltag" => $ltag]);
        else
            $state->add(["type" => "tag", "pid" => $prow->paperId, "ltag" => $ltag,
                         "_tag" => $tag, "_index" => (float) $index]);
        if ($vtag)
            $this->account_votes($prow->paperId, $vtag, $state);
    }
    private function apply_next_index($pid, $tag, AssignmentState $state, $m) {
        $ltag = strtolower($tag);
        $index = cvtnum($m[3] ? $m[4] : null, null);
        // NB ignore $index on second & subsequent nexttag assignments
        if (!($fin = get($state->finishers, "seqtag $ltag")))
            $fin = $state->finishers["seqtag $ltag"] =
                new NextTagAssigner($state, $tag, $index, $this->isnext === self::NEXTSEQ);
        unset($fin->pidindex[$pid]);
        return $fin->next_index($this->isnext === self::NEXTSEQ);
    }
    private function apply_remove(PaperInfo $prow, AssignmentState $state, $m) {
        // resolve twiddle portion
        if ($m[1] && $m[1] != "~~" && !ctype_digit(substr($m[1], 0, strlen($m[1]) - 1))) {
            $c = substr($m[1], 0, strlen($m[1]) - 1);
            $twiddlecids = ContactSearch::make_pc($c, $state->user, $state->reviewer)->ids;
            if (empty($twiddlecids))
                return "“" . htmlspecialchars($c) . "” doesn’t match a PC member.";
            else if (count($twiddlecids) == 1)
                $m[1] = $twiddlecids[0] . "~";
            else
                $m[1] = "(?:" . join("|", $twiddlecids) . ")~";
        }

        // resolve tag portion
        $search_ltag = null;
        if (strcasecmp($m[2], "none") == 0)
            return;
        else if (strcasecmp($m[2], "any") == 0 || strcasecmp($m[2], "all") == 0) {
            $cid = $state->user->contactId;
            if ($state->user->privChair)
                $cid = $state->reviewer->contactId;
            if ($m[1])
                $m[2] = "[^~]*";
            else if ($state->user->privChair && $state->reviewer->privChair)
                $m[2] = "(?:~~|{$cid}~|)[^~]*";
            else
                $m[2] = "(?:{$cid}~|)[^~]*";
        } else {
            if (!preg_match(',[*(],', $m[1] . $m[2]))
                $search_ltag = strtolower($m[1] . $m[2]);
            $m[2] = str_replace("\\*", "[^~]*", preg_quote($m[2]));
        }

        // resolve index comparator
        if (preg_match(',\A(?:any|all|none|clear)\z,i', $m[4]))
            $m[3] = $m[4] = "";
        else {
            if ($m[3] == "#")
                $m[3] = "=";
            $m[4] = cvtint($m[4], 0);
        }

        // if you can't view the tag, you can't clear the tag
        // (information exposure)
        if ($search_ltag && !$state->user->can_view_tag($prow, $search_ltag))
            return $this->cannot_view_error($prow, $search_ltag, $state);

        // query
        $res = $state->query(array("type" => "tag", "pid" => $prow->paperId, "ltag" => $search_ltag));
        $tag_re = '{\A' . $m[1] . $m[2] . '\z}i';
        $vote_adjustments = array();
        foreach ($res as $x)
            if (preg_match($tag_re, $x["ltag"])
                && (!$m[3] || CountMatcher::compare($x["_index"], $m[3], $m[4]))
                && ($search_ltag
                    || $state->user->can_change_tag($prow, $x["ltag"], $x["_index"], null))) {
                $state->remove($x);
                if (($v = $state->conf->tags()->votish_base($x["ltag"])))
                    $vote_adjustments[$v] = true;
            }
        foreach ($vote_adjustments as $vtag => $v)
            $this->account_votes($prow->paperId, $vtag, $state);
    }
    private function account_votes($pid, $vtag, AssignmentState $state) {
        $res = $state->query(array("type" => "tag", "pid" => $pid));
        $tag_re = '{\A\d+~' . preg_quote($vtag) . '\z}i';
        $is_vote = $state->conf->tags()->is_vote($vtag);
        $total = 0.0;
        foreach ($res as $x)
            if (preg_match($tag_re, $x["ltag"]))
                $total += $is_vote ? (float) $x["_index"] : 1.0;
        $state->add(array("type" => "tag", "pid" => $pid, "ltag" => strtolower($vtag),
                          "_tag" => $vtag, "_index" => $total, "_vote" => true));
    }
}

class Tag_Assigner extends Assigner {
    private $tag;
    private $index;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->tag = $item["_tag"];
        $this->index = $item->get(false, "_index");
        if ($this->index == 0 && $item["_vote"])
            $this->index = null;
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        $prow = $state->prow($item["pid"]);
        // check permissions
        if (!$item["_vote"] && !$item["_override"]) {
            $whyNot = $state->user->perm_change_tag($prow, $item["ltag"],
                $item->get(true, "_index"), $item->get(false, "_index"));
            if ($whyNot) {
                if (get($whyNot, "otherTwiddleTag"))
                    return null;
                throw new Exception(whyNotText($whyNot, "tag"));
            }
        }
        return new Tag_Assigner($item, $state);
    }
    function unparse_description() {
        return "tag";
    }
    private function unparse_item($before) {
        $index = $this->item->get($before, "_index");
        return "#" . htmlspecialchars($this->item->get($before, "_tag"))
            . ($index ? "#$index" : "");
    }
    function unparse_display(AssignmentSet $aset) {
        $aset->show_column("tags");
        $t = [];
        if ($this->item->existed())
            $t[] = '<del>' . $this->unparse_item(true) . '</del>';
        if (!$this->item->deleted())
            $t[] = '<ins>' . $this->unparse_item(false) . '</ins>';
        return join(" ", $t);
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $t = $this->tag;
        if ($this->index === null)
            return ["pid" => $this->pid, "action" => "cleartag", "tag" => $t];
        else {
            if ($this->index)
                $t .= "#{$this->index}";
            return ["pid" => $this->pid, "action" => "tag", "tag" => $t];
        }
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperTag"] = "write";
        if ($this->index !== null && str_ends_with($this->tag, ":")
            && !$aset->conf->setting("has_colontag"))
            $locks["Settings"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if ($this->index === null)
            $aset->conf->qe("delete from PaperTag where paperId=? and tag=?", $this->pid, $this->tag);
        else
            $aset->conf->qe("insert into PaperTag set paperId=?, tag=?, tagIndex=? on duplicate key update tagIndex=values(tagIndex)", $this->pid, $this->tag, $this->index);
        if ($this->index !== null && str_ends_with($this->tag, ':')
            && !$aset->conf->setting("has_colontag"))
            $aset->conf->save_setting("has_colontag", 1);
        $aset->user->log_activity("Tag: " . ($this->index === null ? "-" : "+") . "#$this->tag" . ($this->index ? "#$this->index" : ""), $this->pid);
        $aset->cleanup_notify_tracker($this->pid);
    }
}


class Preference_AssignmentParser extends AssignmentParser {
    function __construct() {
        parent::__construct("pref");
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if ($prow->timeWithdrawn > 0)
            return "#$prow->paperId has been withdrawn.";
        else
            return true;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("pref", ["pid", "cid"], "Preference_Assigner::make"))
            return;
        $result = $state->conf->qe("select paperId, contactId, preference, expertise from PaperReviewPreference where paperId?a", $state->paper_ids());
        while (($row = edb_row($result)))
            $state->load(["type" => "pref", "pid" => +$row[0], "cid" => +$row[1], "_pref" => +$row[2], "_exp" => self::make_exp($row[3])]);
        Dbl::free($result);
    }
    function expand_any_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return array_filter($state->pc_users(),
            function ($u) use ($prow) {
                return $u->can_become_reviewer_ignore_conflict($prow);
            });
    }
    function expand_missing_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return $state->reviewer->isPC ? [$state->reviewer] : false;
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        if (!$contact->contactId)
            return false;
        else if ($contact->contactId !== $state->user->contactId
                 && !$state->user->can_administer($prow))
            return "Can’t change other users’ preferences for #{$prow->paperId}.";
        else if (!$contact->can_become_reviewer_ignore_conflict($prow)) {
            if ($contact->contactId !== $state->user->contactId)
                return Text::user_html_nolink($contact) . " can’t enter preferences for #{$prow->paperId}.";
            else
                return "Can’t enter preferences for #{$prow->paperId}.";
        } else
            return true;
    }
    static private function make_exp($exp) {
        return $exp === null ? "N" : +$exp;
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        foreach (array("preference", "pref", "revpref") as $k)
            if (($pref = get($req, $k)) !== null)
                break;
        if ($pref === null)
            return "Missing preference.";
        $pref = trim((string) $pref);
        if ($pref == "" || $pref == "none")
            $ppref = array(0, null);
        else if (($ppref = parse_preference($pref)) === null)
            return "Invalid preference “" . htmlspecialchars($pref) . "”.";

        foreach (array("expertise", "revexp") as $k)
            if (($exp = get($req, $k)) !== null)
                break;
        if ($exp && ($exp = trim($exp)) !== "") {
            if (($pexp = parse_preference($exp)) === null || $pexp[0])
                return "Invalid expertise “" . htmlspecialchars($exp) . "”.";
            $ppref[1] = $pexp[1];
        }

        $state->remove(array("type" => "pref", "pid" => $prow->paperId, "cid" => $contact->contactId));
        if ($ppref[0] || $ppref[1] !== null)
            $state->add(array("type" => "pref", "pid" => $prow->paperId, "cid" => $contact->contactId, "_pref" => $ppref[0], "_exp" => self::make_exp($ppref[1])));
    }
}

class Preference_Assigner extends Assigner {
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        return new Preference_Assigner($item, $state);
    }
    function unparse_description() {
        return "preference";
    }
    private function preference_data($before) {
        $p = [$this->item->get($before, "_pref"),
              $this->item->get($before, "_exp")];
        if ($p[1] === "N")
            $p[1] = null;
        return $p[0] || $p[1] !== null ? $p : null;
    }
    function unparse_display(AssignmentSet $aset) {
        if (!$this->cid)
            return "remove all preferences";
        $t = $aset->user->reviewer_html_for($this->contact);
        if (($p = $this->preference_data(true)))
            $t .= " <del>" . unparse_preference_span($p, true) . "</del>";
        if (($p = $this->preference_data(false)))
            $t .= " <ins>" . unparse_preference_span($p, true) . "</ins>";
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $p = $this->preference_data(false);
        $pref = $p ? unparse_preference($p[0], $p[1]) : "none";
        return ["pid" => $this->pid, "action" => "preference",
                "email" => $this->contact->email, "name" => $this->contact->name_text(),
                "preference" => $pref];
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperReviewPreference"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if (($p = $this->preference_data(false)))
            $aset->conf->qe("insert into PaperReviewPreference
                set paperId=?, contactId=?, preference=?, expertise=?
                on duplicate key update preference=values(preference), expertise=values(expertise)",
                    $this->pid, $this->cid, $p[0], $p[1]);
        else
            $aset->conf->qe("delete from PaperReviewPreference where paperId=? and contactId=?", $this->pid, $this->cid);
    }
}


class Decision_AssignmentParser extends UserlessAssignmentParser {
    private $remove;
    function __construct($aj) {
        parent::__construct("decision");
        $this->remove = $aj->remove;
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->user->can_set_decision($prow))
            return "You can’t change the decision for #{$prow->paperId}.";
        else
            return true;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("decision", ["pid"], "Decision_Assigner::make"))
            return;
        foreach ($state->prows() as $prow)
            $state->load(["type" => "decision", "pid" => $prow->paperId, "_decision" => +$prow->outcome]);
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        if (!$this->remove) {
            if (!isset($req["decision"]))
                return "Decision missing.";
            $dec = PaperSearch::matching_decisions($state->conf, $req["decision"]);
            if (count($dec) == 1)
                $dec = $dec[0];
            else if (empty($dec))
                return "No decisions match “" . htmlspecialchars($req["decision"]) . "”.";
            else
                return "More than one decision matches “" . htmlspecialchars($req["decision"]) . "”.";
        }
        $state->remove(["type" => "decision", "pid" => $prow->paperId]);
        if (!$this->remove && $dec)
            $state->add(["type" => "decision", "pid" => $prow->paperId, "_decision" => +$dec]);
    }
}

class Decision_Assigner extends Assigner {
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        return new Decision_Assigner($item, $state);
    }
    static function decision_html(Conf $conf, $dec) {
        if (!$dec) {
            $class = "pstat_sub";
            $dname = "No decision";
        } else {
            $class = $dec < 0 ? "pstat_decno" : "pstat_decyes";
            $dname = $conf->decision_name($dec) ? : "Unknown decision #$dec";
        }
        return "<span class=\"pstat $class\">" . htmlspecialchars($dname) . "</span>";
    }
    function unparse_display(AssignmentSet $aset) {
        $aset->show_column($this->description);
        $t = [];
        if ($this->item->existed())
            $t[] = '<del>' . self::decision_html($aset->conf, $this->item->get(true, "_decision")) . '</del>';
        $t[] = '<ins>' . self::decision_html($aset->conf, $this->item["_decision"]) . '</ins>';
        return join(" ", $t);
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = ["pid" => $this->pid, "action" => "decision"];
        if ($this->item->deleted())
            $x["decision"] = "none";
        else
            $x["decision"] = $aset->conf->decision_name($this->item["_decision"]);
        return $x;
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["Paper"] = "write";
    }
    function execute(AssignmentSet $aset) {
        global $Now;
        $dec = $this->item->deleted() ? 0 : $this->item["_decision"];
        $aset->conf->qe("update Paper set outcome=? where paperId=?", $dec, $this->pid);
        if ($dec > 0) {
            // accepted papers are always submitted
            $prow = $aset->prow($this->pid);
            if ($prow->timeSubmitted <= 0 && $prow->timeWithdrawn <= 0) {
                $aset->conf->qe("update Paper set timeSubmitted=$Now where paperId=?", $this->pid);
                $aset->cleanup_callback("papersub", function ($aset) {
                    $aset->conf->update_papersub_setting(1);
                });
            }
        }
        if ($dec > 0 || $this->item->get(true, "_decision") > 0)
            $aset->cleanup_callback("paperacc", function ($aset, $vals) {
                $aset->conf->update_paperacc_setting(min($vals));
            }, $dec > 0 ? 1 : 0);
    }
}



class AssignmentSet {
    public $conf;
    public $user;
    public $filename;
    private $assigners = array();
    private $enabled_pids = null;
    private $enabled_actions = null;
    private $msgs = array();
    private $has_error = false;
    private $my_conflicts = null;
    private $astate;
    private $searches = array();
    private $unparse_search = false;
    private $unparse_columns = array();
    private $assignment_type = null;
    private $cleanup_callbacks;
    private $cleanup_notify_tracker;

    function __construct(Contact $user, $overrides = null) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->astate = new AssignmentState($user);
        $this->set_overrides($overrides);
    }

    function set_reviewer(Contact $reviewer) {
        $this->astate->reviewer = $reviewer;
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
                $this->astate->prows[$p->paperId] = $p;
                $this->enabled_pids[] = $p->paperId;
            } else
                $this->enabled_pids[] = (int) $p;
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

    function is_empty() {
        return empty($this->assigners);
    }

    function has_error() {
        return $this->has_error;
    }

    function clear_errors() {
        $this->msgs = [];
        $this->has_error = false;
    }

    // error(message) OR error(lineno, message)
    function lmsg($lineno, $msg, $status) {
        $l = ($this->filename ? $this->filename . ":" : "line ") . $lineno;
        $n = count($this->msgs) - 1;
        if ($n >= 0 && $this->msgs[$n][0] == $l
            && $this->msgs[$n][1] == $msg)
            $this->msgs[$n][2] = max($this->msgs[$n][2], $status);
        else
            $this->msgs[] = [$l, $msg, $status];
        if ($status == 2)
            $this->has_error = true;
    }

    function error($message, $message1 = null) {
        if (is_int($message) && is_string($message1)) {
            $lineno = $message;
            $message = $message1;
        } else
            $lineno = $this->astate->lineno;
        $this->lmsg($lineno, $message, 2);
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
            Conf::msg_error('Assignment errors: <div class="parseerr"><p>' . join("</p>\n<p>", $this->errors_html(true)) . '</p></div> Please correct these errors and try again.');
        else if (!empty($this->msgs))
            Conf::msg_warning('Assignment warnings: <div class="parseerr"><p>' . join("</p>\n<p>", $this->errors_html(true)) . '</p></div>');
    }

    function json_result($linenos = false) {
        if ($this->has_error)
            return new JsonResult(403, ["ok" => false, "error" => '<div class="parseerr"><p>' . join("</p>\n<p>", $this->errors_html($linenos)) . '</p></div>']);
        else if (!empty($this->msgs))
            return new JsonResult(["ok" => true, "response" => '<div class="parseerr"><p>' . join("</p>\n<p>", $this->errors_html($linenos)) . '</p></div>']);
        else
            return new JsonResult(["ok" => true]);
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
            $ret = ContactSearch::make_special($special, $this->astate->user, $this->astate->reviewer);
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
            $ret = ContactSearch::make_cset($text, $this->astate->user, $this->astate->reviewer, $cset);
            if (count($ret->ids) == 1)
                return $ret->contacts();
            else if (empty($ret->ids))
                $this->error("No $cset_text matches “" . self::req_user_html($req) . "”.");
            else
                $this->error("“" . self::req_user_html($req) . "” matches more than one $cset_text, use a full email address to disambiguate.");
            return false;
        }

        // create contact
        if (!$email)
            return $this->error("Missing email address.");
        else if (!validate_email($email))
            return $this->error("Email address “" . htmlspecialchars($email) . "” is invalid.");
        else if (($u = $this->astate->user_by_email($email, true, $req)))
            return [$u];
        else
            return $this->error("Could not create user.");
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
            return $this->error($csv->lineno(), "“assignment” column missing");
        else if (array_search("paper", $req) === false)
            return $this->error($csv->lineno(), "“paper” column missing");
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
                $search = new PaperSearch($this->user, $pfield, $this->astate->reviewer);
                $this->searches[$pfield] = $search->paperList();
                if ($report_error)
                    foreach ($search->warnings as $w)
                        $this->error($w);
            }
            $npids = $this->searches[$pfield];
            $val = 1;
        } else {
            if ($report_error)
                $this->error("Bad paper column");
            return 0;
        }
        if (empty($npids) && $report_error)
            $this->error("No papers match “" . htmlspecialchars($pfield) . "”");

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
            return $this->error("Unknown action.");
        if ($this->enabled_actions !== null
            && !isset($this->enabled_actions[$aparser->type]))
            return $this->error("Action " . htmlspecialchars($aparser->type) . " disabled.");
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
            $prow = get($this->astate->prows, $p);
            if (!$prow) {
                $this->astate->error("Submission #$p does not exist.");
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
                    } else if ($prow->has_conflict($contact))
                        $err = Text::user_html_nolink($contact) . " has a conflict with #$p.";
                    else
                        $err = Text::user_html_nolink($contact) . " cannot be assigned to #$p.";
                }
                if (is_string($err))
                    $this->astate->paper_error($err);
                if ($err !== true)
                    continue;

                $err = $aparser->apply($prow, $contact, $req, $this->astate);
                if (is_string($err))
                    $this->astate->error($err);
                if (!$err)
                    $any_success = true;
            }
        }

        foreach ($this->astate->errors as $e)
            $this->lmsg($this->astate->lineno, $e[0], $e[1] || !$any_success ? 2 : 1);
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
            return $this->error($csv->lineno(), "empty file");
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
        foreach ($this->astate->diff() as $pid => $difflist)
            foreach ($difflist as $item) {
                try {
                    if (($a = $item->realize($this->astate)))
                        $this->assigners[] = $a;
                } catch (Exception $e) {
                    $this->error($item->lineno, $e->getMessage());
                }
            }

        $this->user->set_overrides($old_overrides);
    }

    function types_and_papers($compress_pids = false) {
        $types = array();
        $pids = array();
        foreach ($this->assigners as $assigner) {
            $types[$assigner->type] = true;
            if ($assigner->pid)
                $pids[$assigner->pid] = true;
        }
        ksort($types);
        ksort($pids, SORT_NUMERIC);
        $pids = array_keys($pids);
        if ($compress_pids) {
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
        return array(array_keys($types), $pids);
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

    function echo_unparse_display() {
        $this->set_my_conflicts();

        $bypaper = array();
        foreach ($this->assigners as $assigner)
            if (($text = $assigner->unparse_display($this))) {
                $c = $assigner->contact;
                if ($c && !isset($c->sorter))
                    Contact::set_sorter($c, $this->conf);
                arrayappend($bypaper[$assigner->pid], (object)
                            array("text" => $text,
                                  "sorter" => $c ? $c->sorter : $text));
            }

        AutoassignmentPaperColumn::$header = "Assignment";
        $assinfo = array();
        PaperColumn::register(new AutoassignmentPaperColumn);
        foreach ($bypaper as $pid => $list) {
            uasort($list, "Contact::compare");
            $t = "";
            foreach ($list as $x)
                $t .= ($t ? ", " : "") . '<span class="nw">'
                    . $x->text . '</span>';
            if (isset($this->my_conflicts[$pid])) {
                if ($this->my_conflicts[$pid] !== true)
                    $t = '<em>Hidden for conflict</em>';
                else
                    $t = PaperList::wrapChairConflict($t);
            }
            $assinfo[$pid] = $t;
        }

        ksort($assinfo);
        AutoassignmentPaperColumn::$info = $assinfo;

        if ($this->unparse_search)
            $query_order = "(" . $this->unparse_search . ") THEN HEADING:none " . join(" ", array_keys($assinfo));
        else
            $query_order = count($assinfo) ? join(" ", array_keys($assinfo)) : "NONE";
        foreach ($this->unparse_columns as $k => $v)
            if ($v)
                $query_order .= " show:$k";
        $query_order .= " show:autoassignment";
        $search = new PaperSearch($this->user, ["t" => get($_REQUEST, "t", "s"), "q" => $query_order], $this->astate->reviewer);
        $plist = new PaperList($search);
        $plist->set_table_id_class("foldpl", "pltable_full");
        echo $plist->table_html("reviewers", ["nofooter" => 1]);

        $deltarev = new AssignmentCountSet($this->conf);
        foreach ($this->assigners as $assigner)
            $assigner->account($deltarev);
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
            if (($row = $assigner->unparse_csv($this, $acsv)))
                $acsv->add($row);
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

        // create new contacts outside the lock
        $locks = array("ContactInfo" => "read", "Paper" => "read", "PaperConflict" => "read");
        $this->conf->save_logs(true);
        foreach ($this->assigners as $assigner) {
            if (($u = $assigner->contact) && $u->contactId < 0) {
                $assigner->contact = $this->astate->register_user($u);
                $assigner->cid = $assigner->contact->contactId;
            }
            $assigner->add_locks($this, $locks);
        }

        // execute assignments
        $tables = array();
        foreach ($locks as $t => $type)
            $tables[] = "$t $type";
        $this->conf->qe("lock tables " . join(", ", $tables));
        $this->cleanup_callbacks = $this->cleanup_notify_tracker = [];

        foreach ($this->assigners as $assigner)
            $assigner->execute($this);

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

        return true;
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
        return '<div class="pcrevsum">' . $prefix . join(", ", $data) . "</div>";
    }

    static function run($contact, $text, $forceShow = null) {
        $aset = new AssignmentSet($contact, $forceShow);
        $aset->parse($text);
        return $aset->execute();
    }
}


class AutoassignmentPaperColumn extends PaperColumn {
    static $header;
    static $info;
    function __construct() {
        parent::__construct(["name" => "autoassignment", "row" => true, "className" => "pl_autoassignment"]);
    }
    function header(PaperList $pl, $is_text) {
        return self::$header;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !isset(self::$info[$row->paperId]);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return self::$info[$row->paperId];
    }
}
