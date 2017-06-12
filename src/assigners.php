<?php
// assigners.php -- HotCRP helper classes for assignments
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class AssignmentItem implements ArrayAccess {
    public $before;
    public $after = null;
    public $lineno = null;
    public $override = null;
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
            $offset = $before;
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
}

class AssignmentState {
    private $st = array();
    private $types = array();
    public $conf;
    public $contact;  // executor
    public $reviewer; // default contact
    public $override;
    public $lineno = null;
    public $defaults = array();
    public $prows = array();
    public $finishers = array();
    public $paper_exact_match = true;
    public $paper_limit = false;
    public $errors = [];
    function __construct(Contact $contact, $override) {
        $this->conf = $contact->conf;
        $this->contact = $this->reviewer = $contact;
        $this->override = $override;
    }
    function mark_type($type, $keys) {
        if (!isset($this->types[$type])) {
            $this->types[$type] = $keys;
            return true;
        } else
            return false;
    }
    private function pidstate($pid) {
        if (!isset($this->st[$pid]))
            $this->st[$pid] = (object) array("items" => array());
        return $this->st[$pid];
    }
    private function extract_key($x) {
        $tkeys = $this->types[$x["type"]];
        assert($tkeys);
        $t = $x["type"];
        foreach ($tkeys as $k)
            if (isset($x[$k]))
                $t .= "`" . $x[$k];
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
    private function do_query_remove($item, $q, $remove, &$res, $modified) {
        if ($item
            && !$item->deleted()
            && self::match($item->after ? : $item->before, $q)
            && ($modified === null || $item->modified() === $modified)) {
            $res[] = $item->after ? : $item->before;
            if ($remove) {
                $item->after = false;
                $item->lineno = $this->lineno;
                $item->override = $this->override;
            }
        }
    }
    private function query_remove($q, $remove, $modified) {
        $res = array();
        foreach ($this->pid_keys($q) as $pid) {
            $st = $this->pidstate($pid);
            if (($k = $this->extract_key($q)))
                $this->do_query_remove(get($st->items, $k), $q, $remove, $res, $modified);
            else
                foreach ($st->items as $item)
                    $this->do_query_remove($item, $q, $remove, $res, $modified);
        }
        return $res;
    }
    function query($q) {
        return $this->query_remove($q, false, null);
    }
    function make_filter($key, $q) {
        $cf = [];
        foreach ($this->query($q) as $m)
            $cf[$m[$key]] = true;
        return $cf;
    }
    function remove($q) {
        return $this->query_remove($q, true, null);
    }
    function query_before($q) {
        return $this->query_remove($q, false, false);
    }
    function add($x) {
        $k = $this->extract_key($x);
        assert(!!$k);
        $st = $this->pidstate($x["pid"]);
        if (!($item = get($st->items, $k)))
            $item = $st->items[$k] = new AssignmentItem(false);
        $item->after = $x;
        $item->lineno = $this->lineno;
        $item->override = $this->override;
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
    function fetch_prows($pids) {
        $pids = is_array($pids) ? $pids : array($pids);
        $fetch_pids = array();
        foreach ($pids as $p)
            if (!isset($this->prows[$p]))
                $fetch_pids[] = $p;
        if (!empty($fetch_pids)) {
            $result = $this->contact->paper_result(["paperId" => $fetch_pids, "tags" => $this->conf->has_tracks()]);
            while ($result && ($prow = PaperInfo::fetch($result, $this->contact)))
                $this->prows[$prow->paperId] = $prow;
            Dbl::free($result);
        }
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
    static private $next_fake_id = -10;
    static public $query = "ContactInfo.contactId, firstName, lastName, unaccentedName, email, roles, contactTags";
    function __construct(Conf $conf) {
        $this->conf = $conf;
    }
    private function make_none($email = null) {
        return new Contact(["contactId" => 0, "roles" => 0, "email" => $email, "sorter" => ""], $this->conf);
    }
    private function store($c) {
        if ($c && $c->contactId)
            $this->by_id[$c->contactId] = $c;
        if ($c && $c->email)
            $this->by_lemail[strtolower($c->email)] = $c;
        return $c;
    }
    private function store_pc() {
        foreach ($this->conf->pc_members() as $p)
            $this->store($p);
        return ($this->has_pc = true);
    }
    function make_id($cid) {
        global $Me;
        if (!$cid)
            return $this->make_none();
        if (($c = get($this->by_id, $cid)))
            return $c;
        if ($Me && $Me->contactId > 0 && $cid == $Me->contactId && $Me->conf === $this->conf)
            return $this->store($Me);
        if (!$this->has_pc && $this->store_pc() && ($c = get($this->by_id, $cid)))
            return $c;
        $result = $this->conf->qe("select " . self::$query . " from ContactInfo where contactId=?", $cid);
        $c = Contact::fetch($result, $this->conf);
        if (!$c)
            $c = new Contact(["contactId" => $cid, "roles" => 0, "email" => "unknown contact $cid", "sorter" => ""], $this->conf);
        Dbl::free($result);
        return $this->store($c);
    }
    function lookup_lemail($lemail) {
        global $Me;
        if (!$lemail)
            return $this->make_none();
        if (($c = get($this->by_lemail, $lemail)))
            return $c;
        if ($Me && $Me->contactId > 0 && strcasecmp($lemail, $Me->email) == 0 && $Me->conf === $this->conf)
            return $this->store($Me);
        if (!$this->has_pc && $this->store_pc() && ($c = get($this->by_lemail, $lemail)))
            return $c;
        $result = $this->conf->qe("select " . self::$query . " from ContactInfo where email=?", $lemail);
        $c = Contact::fetch($result, $this->conf);
        Dbl::free($result);
        return $this->store($c);
    }
    function make_email($email) {
        $c = $this->lookup_lemail(strtolower($email));
        if (!$c) {
            $c = new Contact(["contactId" => self::$next_fake_id, "roles" => 0, "email" => $email, "sorter" => $email], $this->conf);
            self::$next_fake_id -= 1;
            $c = $this->store($c);
        }
        return $c;
    }
    function register_contact($c) {
        $lemail = strtolower($c->email);
        $cx = $this->lookup_lemail($lemail);
        if (!$cx || $cx->contactId < 0) {
            // XXX assume that never fails:
            $cx = Contact::create($this->conf, ["email" => $c->email, "firstName" => get($c, "firstName"), "lastName" => get($c, "lastName")]);
            $cx = $this->store($cx);
        }
        return $cx;
    }
}

class AssignmentCount {
    public $ass = 0;
    public $rev = 0;
    public $pri = 0;
    public $sec = 0;
    public $lead = 0;
    public $shepherd = 0;
    function add(AssignmentCount $ct) {
        $xct = new AssignmentCount;
        foreach (["rev", "pri", "sec", "ass", "lead", "shepherd"] as $k)
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
            $ct->pri = preg_match_all("|" . REVIEW_PRIMARY . "|", $row[1], $matches);
            $ct->sec = preg_match_all("|" . REVIEW_SECONDARY . "|", $row[1], $matches);
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
        $this->header = $this->header + $row;
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
    static private $assigners = array();
    function __construct($type) {
        $this->type = $type;
    }
    static function register($n, $a) {
        assert(!get(self::$assigners, $n));
        self::$assigners[$n] = $a;
    }
    static function assigner_names() {
        return array_keys(self::$assigners);
    }
    static function find($n) {
        return get(self::$assigners, $n);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->contact->can_administer($prow, $state->override)
            && !$state->contact->privChair)
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
    function allow_special_contact($cclass, &$req, AssignmentState $state) {
        return false;
    }
    static function unconflicted(PaperInfo $prow, Contact $contact, AssignmentState $state) {
        return $state->override || !$prow->has_conflict($contact);
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return self::unconflicted($prow, $contact, $state);
    }
    function load_state(AssignmentState $state) {
    }
    function paper_filter($contact, &$req, AssignmentState $state) {
        return null;
    }
    function contact_filter($pid, &$req, AssignmentState $state) {
        return null;
    }
    function apply($pid, $contact, &$req, AssignmentState $state) {
    }
    function realize(AssignmentItem $item, $cmap, AssignmentState $state) {
        return null;
    }
}

class Assigner {
    public $item;
    public $type;
    public $pid;
    public $contact;
    public $cid;
    function __construct(AssignmentItem $item, AssignerContacts $cmap) {
        $this->item = $item;
        $this->type = $item["type"];
        $this->pid = $item["pid"];
        $this->cid = $item["cid"] ? : $item["_cid"];
        if ($this->cid)
            $this->contact = $cmap->make_id($this->cid);
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
    function notify_tracker() {
        return false;
    }
}

class Null_AssignmentParser extends AssignmentParser {
    function __construct() {
        parent::__construct("none");
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        return true;
    }
    function contact_set(&$req, AssignmentState $state) {
        return false;
    }
    function allow_special_contact($cclass, &$req, AssignmentState $state) {
        return true;
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return true;
    }
    function apply($pid, $contact, &$req, AssignmentState $state) {
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
    private static $type_map = [
        "primary" => REVIEW_PRIMARY, "pri" => REVIEW_PRIMARY,
        "secondary" => REVIEW_SECONDARY, "sec" => REVIEW_SECONDARY,
        "optional" => REVIEW_PC, "opt" => REVIEW_PC, "pc" => REVIEW_PC,
        "external" => REVIEW_EXTERNAL, "ext" => REVIEW_EXTERNAL
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
    static function separate($key, $req, $state, $rtype) {
        $a0 = $a1 = trim(get_s($req, $key));
        $require_match = $a0 !== "" && !$rtype;
        if ($a0 === "" && $rtype != 0)
            $a0 = $a1 = get($state->defaults, $key);
        if ($a0 !== null && ($colon = strpos($a0, ":")) !== false) {
            $a1 = substr($a0, $colon + 1);
            $a0 = substr($a0, 0, $colon);
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
        if ($this->oldtype === null && $rtype > 0 && $rmatch)
            $this->oldtype = $rtype;
        $this->explicitround = get($req, "round") !== null;

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
    function __construct($rtype) {
        parent::__construct(self::rtype_name($rtype));
        $this->rtype = $rtype;
    }
    function contact_set(&$req, AssignmentState $state) {
        if ($this->rtype > REVIEW_EXTERNAL)
            return "pc";
        else if ($this->rtype == 0
                 || (($rdata = ReviewAssigner_Data::make($req, $state, $this->rtype))
                     && !$rdata->can_create_review()))
            return "reviewers";
        else
            return false;
    }
    function allow_special_contact($cclass, &$req, AssignmentState $state) {
        return $this->rtype <= 0 && $cclass != "none";
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        // Conflict allowed if we're not going to assign a new review
        if ($this->rtype == 0 || $prow->has_reviewer($contact)
            || (($rdata = ReviewAssigner_Data::make($req, $state, $this->rtype))
                && !$rdata->can_create_review()))
            return true;
        // Check whether review assignments are acceptable
        if (!$contact->can_accept_review_assignment_ignore_conflict($prow))
            return Text::user_html_nolink($contact) . " cannot be assigned to review #{$prow->paperId}.";
        // Check conflicts
        return AssignmentParser::unconflicted($prow, $contact, $state);
    }
    static function load_review_state(AssignmentState $state) {
        $result = $state->conf->qe("select paperId, contactId, reviewType, reviewRound, reviewSubmitted from PaperReview");
        while (($row = edb_row($result))) {
            $round = $state->conf->round_name($row[3]);
            $state->load(["type" => "review", "pid" => +$row[0], "cid" => +$row[1],
                          "_rtype" => +$row[2], "_round" => $round,
                          "_rsubmitted" => $row[4] > 0 ? 1 : 0]);
        }
        Dbl::free($result);
    }
    function load_state(AssignmentState $state) {
        if ($state->mark_type("review", ["pid", "cid"]))
            self::load_review_state($state);
    }
    private function make_filter($fkey, $key, $value, &$req, AssignmentState $state) {
        $rdata = ReviewAssigner_Data::make($req, $state, $this->rtype);
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
    function contact_filter($pid, &$req, AssignmentState $state) {
        return $this->make_filter("cid", "pid", $pid, $req, $state);
    }
    function apply($pid, $contact, &$req, AssignmentState $state) {
        $rdata = ReviewAssigner_Data::make($req, $state, $this->rtype);
        if ($rdata->error)
            return $rdata->error;
        if (!$contact && $rdata->newtype)
            return "User missing.";

        $revmatch = ["type" => "review", "pid" => $pid,
                     "cid" => $contact ? $contact->contactId : null,
                     "_rtype" => $rdata->oldtype, "_round" => $rdata->oldround];
        $matches = $state->remove($revmatch);

        if ($rdata->newtype) {
            if ($rdata->can_create_review() && empty($matches)) {
                $revmatch["_round"] = $rdata->newround;
                $matches[] = $revmatch;
            }
            foreach ($matches as $m) {
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
            }
        } else
            // do not remove submitted reviews
            foreach ($matches as $r)
                if ($r["_rsubmitted"])
                    $state->add($r);
    }
    function realize(AssignmentItem $item, $cmap, AssignmentState $state) {
        return new Review_Assigner($item, $cmap, $state);
    }
}

class Review_Assigner extends Assigner {
    private $rtype;
    private $notify = null;
    private $unsubmit = false;
    static public $prefinfo = null;
    function __construct(AssignmentItem $item, AssignerContacts $cmap,
                         AssignmentState $state) {
        parent::__construct($item, $cmap);
        $this->rtype = $item->get(false, "_rtype");
        $this->unsubmit = $item->get(true, "_rsubmitted") && !$item->get(false, "_rsubmitted");
        if (!$item->existed() && $this->rtype == REVIEW_EXTERNAL)
            $this->notify = get($state->defaults, "extrev_notify");
    }
    function unparse_description() {
        return "review";
    }
    private function unparse_item(AssignmentSet $aset, $before) {
        if (!$this->item->get($before, "_rtype"))
            return "";
        $t = $aset->contact->reviewer_html_for($this->contact) . ' '
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
        $t = $aset->contact->reviewer_html_for($this->contact);
        if ($this->item->deleted())
            $t = '<del>' . $t . '</del>';
        if ($this->item->differs("_rtype") || $this->item->differs("_rsubmitted")) {
            if ($this->item->get(true, "_rtype"))
                $t .= ' <del>' . $this->icon(true) . '</del>';
            if ($this->item->get(false, "_rtype"))
                $t .= ' <ins>' . $this->icon(false) . '</ins>';
        } else if ($this->item->get("_rtype"))
            $t .= ' ' . $this->icon(false);
        if ($this->item->differs("_round")) {
            if (($round = $this->item->get(true, "_round")))
                $t .= ' <del><span class="revround" title="Review round">' . htmlspecialchars($round) . '</span></del>';
            if (($round = $this->item->get(false, "_round")))
                $t .= ' <ins><span class="revround" title="Review round">' . htmlspecialchars($round) . '</span></ins>';
        } else if (($round = $this->item->get("_round")))
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
            $x["round"] = $this->round;
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
        if ($round && $this->rtype)
            $extra["round_number"] = $aset->conf->round_number($round, true);
        $reviewId = $aset->contact->assign_review($this->pid, $this->cid, $this->rtype, $extra);
        if ($this->unsubmit && $reviewId)
            $aset->contact->unsubmit_review_row((object) ["paperId" => $this->pid, "contactId" => $this->cid, "reviewType" => $this->rtype, "reviewId" => $reviewId]);
    }
    function cleanup(AssignmentSet $aset) {
        if ($this->notify) {
            $reviewer = $aset->conf->user_by_id($this->cid);
            $prow = $aset->conf->paperRow(array("paperId" => $this->pid, "reviewer" => $this->cid), $reviewer);
            HotCRPMailer::send_to($reviewer, $this->notify, $prow);
        }
    }
}


class UnsubmitReview_AssignmentParser extends AssignmentParser {
    function __construct() {
        parent::__construct("unsubmitreview");
    }
    function contact_set(&$req, AssignmentState $state) {
        return "reviewers";
    }
    function allow_special_contact($cclass, &$req, AssignmentState $state) {
        return $cclass != "none";
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return true;
    }
    function load_state(AssignmentState $state) {
        if ($state->mark_type("review", ["pid", "cid"]))
            Review_AssignmentParser::load_review_state($state);
    }
    function paper_filter($contact, &$req, AssignmentState $state) {
        return $state->make_filter("pid", ["type" => "review", "cid" => $contact->contactId, "_rsubmitted" => 1]);
    }
    function contact_filter($pid, &$req, AssignmentState $state) {
        return $state->make_filter("cid", ["type" => "review", "pid" => $pid, "_rsubmitted" => 1]);
    }
    function apply($pid, $contact, &$req, AssignmentState $state) {
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
        $revmatch = ["type" => "review", "pid" => $pid,
                     "cid" => $contact ? $contact->contactId : null,
                     "_rtype" => $oldtype, "_round" => $oldround, "_rsubmitted" => 1];
        $matches = $state->remove($revmatch);
        foreach ($matches as $r) {
            $r["_rsubmitted"] = 0;
            $state->add($r);
        }
    }
}


class Lead_AssignmentParser extends AssignmentParser {
    private $isadd;
    private $key;
    function __construct($type, $isadd) {
        parent::__construct($type);
        $this->isadd = $isadd;
        $this->key = $type;
        if ($type === "administrator")
            $this->key = "manager";
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if ($this->type === "administrator")
            return $state->contact->privChair ? true : "You can’t change paper administrators.";
        else
            return parent::allow_paper($prow, $state);
    }
    function allow_special_contact($cclass, &$req, AssignmentState $state) {
        return !$this->isadd || $cclass == "none";
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        if (!$this->isadd)
            return true;
        else if (!$contact->can_accept_review_assignment_ignore_conflict($prow)) {
            $verb = $this->type === "administrator" ? "administer" : $this->type;
            return Text::user_html_nolink($contact) . " can’t $verb #{$prow->paperId}.";
        } else
            return AssignmentParser::unconflicted($prow, $contact, $state);
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type($this->type, ["pid"]))
            return;
        $result = $state->conf->qe("select paperId, {$this->key}ContactId from Paper where {$this->key}ContactId!=0");
        while (($row = edb_row($result)))
            $state->load(array("type" => $this->type, "pid" => +$row[0], "_cid" => +$row[1]));
        Dbl::free($result);
    }
    function apply($pid, $contact, &$req, AssignmentState $state) {
        $remcid = null;
        if (!$this->isadd && $contact && $contact->contactId)
            $remcid = $contact->contactId;
        $state->remove(array("type" => $this->type, "pid" => $pid, "_cid" => $remcid));
        if ($this->isadd && $contact->contactId)
            $state->add(array("type" => $this->type, "pid" => $pid, "_cid" => $contact->contactId));
    }
    function realize(AssignmentItem $item, $cmap, AssignmentState $state) {
        return new Lead_Assigner($item, $cmap);
    }
}

class Lead_Assigner extends Assigner {
    function __construct(AssignmentItem $item, AssignerContacts $cmap) {
        parent::__construct($item, $cmap);
    }
    function key() {
        return $this->type === "administrator" ? "manager" : $this->type;
    }
    function icon() {
        if ($this->type === "lead")
            return review_lead_icon();
        else if ($this->type === "shepherd")
            return review_shepherd_icon();
        else
            return "($this->type)";
    }
    function unparse_description() {
        return $this->type;
    }
    function unparse_display(AssignmentSet $aset) {
        $aset->show_column($this->type);
        if (!$this->item->deleted())
            $aset->show_column("reviewers");
        $t = [];
        if ($this->item->existed())
            $t[] = '<del>' . $aset->contact->reviewer_html_for($this->item->get(true, "_cid")) . " " . $this->icon() . '</del>';
        if (!$this->item->deleted())
            $t[] = '<ins>' . $aset->contact->reviewer_html_for($this->contact) . " " . $this->icon() . '</ins>';
        return join(" ", $t);
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = ["pid" => $this->pid, "action" => $this->type];
        if ($this->isadd) {
            $x["email"] = $this->contact->email;
            $x["name"] = $this->contact->name_text();
        } else
            $x["email"] = "none";
        return $x;
    }
    function account(AssignmentCountSet $deltarev) {
        $k = $this->type;
        if ($this->cid > 0 && ($k === "lead" || $k === "shepherd")) {
            $deltarev->$k = true;
            $ct = $deltarev->ensure($this->cid);
            ++$ct->ass;
            $ct->$k += $this->isadd ? 1 : -1;
        }
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["Paper"] = $locks["Settings"] = "write";
    }
    function execute(AssignmentSet $aset) {
        $aset->contact->assign_paper_pc($this->pid, $this->key(),
            $this->item->get(false, "_cid") ? : 0,
            ["old_cid" => $this->item->get(true, "_cid")]);
    }
}


class Conflict_AssignmentParser extends AssignmentParser {
    private $ctype;
    function __construct($ctype) {
        parent::__construct("conflict");
        $this->ctype = $ctype;
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->contact->can_administer($prow, $state->override)
            && !$state->contact->privChair)
            return "You can’t administer #{$prow->paperId}.";
        else
            return true;
    }
    function allow_special_contact($cclass, &$req, AssignmentState $state) {
        return $cclass == "any" && !$this->ctype;
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return true;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("conflict", ["pid", "cid"]))
            return;
        $result = $state->conf->qe("select paperId, contactId, conflictType from PaperConflict where conflictType>0");
        while (($row = edb_row($result)))
            $state->load(array("type" => "conflict", "pid" => +$row[0], "cid" => +$row[1], "_ctype" => +$row[2]));
        Dbl::free($result);
    }
    function apply($pid, $contact, &$req, AssignmentState $state) {
        $res = $state->remove(array("type" => "conflict", "pid" => $pid, "cid" => $contact->contactId));
        if (!empty($res) && $res[0]["_ctype"] >= CONFLICT_AUTHOR)
            $state->add($res[0]);
        else if ($this->ctype)
            $state->add(array("type" => "conflict", "pid" => $pid, "cid" => $contact->contactId, "_ctype" => $this->ctype));
    }
    function realize(AssignmentItem $item, $cmap, AssignmentState $state) {
        return new Conflict_Assigner($item, $cmap);
    }
}

class Conflict_Assigner extends Assigner {
    private $ctype;
    function __construct(AssignmentItem $item, AssignerContacts $cmap) {
        parent::__construct($item, $cmap);
        $this->ctype = $item->get(false, "_ctype");
    }
    function unparse_description() {
        return "conflict";
    }
    function unparse_display(AssignmentSet $aset) {
        $aset->show_column("pcconf");
        $t = $aset->contact->reviewer_html_for($this->contact) . ' ';
        if ($this->ctype)
            $t .= review_type_icon(-1);
        else
            $t .= "(remove conflict)";
        if (Review_Assigner::$prefinfo
            && ($cpref = get(Review_Assigner::$prefinfo, $this->cid))
            && ($pref = get($cpref, $this->pid)))
            $t .= unparse_preference_span($pref);
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
        return $index;
    }
    function apply_finisher(AssignmentState $state) {
        if ($this->next_index == $this->first_index)
            return;
        $ltag = strtolower($this->tag);
        foreach ($this->pidindex as $pid => $index)
            if ($index >= $this->first_index && $index < $this->next_index) {
                $x = $state->query_before(array("type" => "tag", "pid" => $pid, "ltag" => $ltag));
                if (!empty($x)) {
                    $item = $state->add(["type" => "tag", "pid" => $pid, "ltag" => $ltag,
                                         "_tag" => $this->tag,
                                         "_index" => $this->next_index($this->isseq)]);
                    $item->override = ALWAYS_OVERRIDE;
                }
            }
    }
}

class Tag_AssignmentParser extends AssignmentParser {
    const NEXT = 1;
    const NEXTSEQ = 2;
    private $isadd;
    function __construct($isadd) {
        parent::__construct("tag");
        $this->isadd = $isadd;
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (($whyNot = $state->contact->perm_change_some_tag($prow, $state->override)))
            return whyNotText($whyNot, "change tag");
        else
            return true;
    }
    function allow_special_contact($cclass, &$req, AssignmentState $state) {
        return true;
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return true;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("tag", ["pid", "ltag"]))
            return;
        $result = $state->conf->qe("select paperId, tag, tagIndex from PaperTag");
        while (($row = edb_row($result)))
            $state->load(array("type" => "tag", "pid" => +$row[0], "ltag" => strtolower($row[1]), "_tag" => $row[1], "_index" => +$row[2]));
        Dbl::free($result);
    }
    private function cannot_view_error(AssignmentState $state, $pid, $tag) {
        if ($state->prow($pid)->conflict_type($state->contact))
            $state->paper_error("You have a conflict with submission #$pid.");
        else
            $state->paper_error("You can’t view that tag for submission #$pid.");
        return true;
    }
    function apply($pid, $contact, &$req, AssignmentState $state) {
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
        $isadd = $this->isadd;
        if ($tag[0] === "-" && $isadd) {
            $isadd = false;
            $tag = substr($tag, 1);
        } else if ($tag[0] === "+" && $isadd)
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
            $m[1] = ($contact && $contact->contactId ? : $state->contact->contactId) . "~";
        // ignore attempts to change vote tags
        if (!$m[1] && $state->conf->tags()->is_votish($m[2]))
            return false;

        // add and remove use different paths
        $isadd = $isadd && $m[4] !== "none" && $m[4] !== "clear";
        if ($isadd && strpos($tag, "*") !== false)
            return "Tag wildcards aren’t allowed when adding tags.";
        if (!$isadd)
            return $this->apply_remove($pid, $contact, $state, $m);

        // resolve twiddle portion
        if ($m[1] && $m[1] != "~~" && !ctype_digit(substr($m[1], 0, strlen($m[1]) - 1))) {
            $c = substr($m[1], 0, strlen($m[1]) - 1);
            $twiddlecids = ContactSearch::make_pc($c, $state->reviewer)->ids;
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
        if ($this->isadd === self::NEXT || $this->isadd === self::NEXTSEQ)
            $index = $this->apply_next_index($pid, $tag, $state, $m);
        else
            $index = $m[3] ? cvtnum($m[4], 0) : null;

        // if you can't view the tag, you can't set the tag
        // (information exposure)
        if (!$state->contact->can_view_tag($state->prow($pid), $tag, $state->override))
            return $this->cannot_view_error($state, $pid, $tag);

        // save assignment
        $ltag = strtolower($tag);
        if ($index === null
            && ($x = $state->query(array("type" => "tag", "pid" => $pid, "ltag" => $ltag))))
            $index = $x[0]["_index"];
        $vtag = $state->conf->tags()->votish_base($tag);
        if ($vtag && $state->conf->tags()->is_vote($vtag) && !$index)
            $state->remove(array("type" => "tag", "pid" => $pid, "ltag" => $ltag));
        else
            $state->add(array("type" => "tag", "pid" => $pid, "ltag" => $ltag,
                              "_tag" => $tag, "_index" => $index ? : 0));
        if ($vtag)
            $this->account_votes($pid, $vtag, $state);
    }
    private function apply_next_index($pid, $tag, AssignmentState $state, $m) {
        $ltag = strtolower($tag);
        $index = cvtnum($m[3] ? $m[4] : null, null);
        // NB ignore $index on second & subsequent nexttag assignments
        if (!($fin = get($state->finishers, "seqtag $ltag")))
            $fin = $state->finishers["seqtag $ltag"] =
                new NextTagAssigner($state, $tag, $index, $this->isadd == self::NEXTSEQ);
        unset($fin->pidindex[$pid]);
        return $fin->next_index($this->isadd == self::NEXTSEQ);
    }
    private function apply_remove($pid, $contact, AssignmentState $state, $m) {
        $prow = $state->prow($pid);

        // resolve twiddle portion
        if ($m[1] && $m[1] != "~~" && !ctype_digit(substr($m[1], 0, strlen($m[1]) - 1))) {
            $c = substr($m[1], 0, strlen($m[1]) - 1);
            $twiddlecids = ContactSearch::make_pc($c, $state->reviewer)->ids;
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
            $cid = $state->contact->contactId;
            if ($state->contact->privChair)
                $cid = $state->reviewer->contactId;
            if ($m[1])
                $m[2] = "[^~]*";
            else if ($state->contact->privChair && $state->reviewer->privChair)
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
        if ($search_ltag && !$state->contact->can_view_tag($prow, $search_ltag, $state->override))
            return $this->cannot_view_error($state, $pid, $search_ltag);

        // query
        $res = $state->query(array("type" => "tag", "pid" => $pid, "ltag" => $search_ltag));
        $tag_re = '{\A' . $m[1] . $m[2] . '\z}i';
        $vote_adjustments = array();
        foreach ($res as $x)
            if (preg_match($tag_re, $x["ltag"])
                && (!$m[3] || CountMatcher::compare($x["_index"], $m[3], $m[4]))
                && ($search_ltag
                    || $state->contact->can_change_tag($prow, $x["ltag"], $x["_index"], null, $state->override))) {
                $state->remove($x);
                if (($v = $state->conf->tags()->votish_base($x["ltag"])))
                    $vote_adjustments[$v] = true;
            }
        foreach ($vote_adjustments as $vtag => $v)
            $this->account_votes($pid, $vtag, $state);
    }
    private function account_votes($pid, $vtag, AssignmentState $state) {
        $res = $state->query(array("type" => "tag", "pid" => $pid));
        $tag_re = '{\A\d+~' . preg_quote($vtag) . '\z}i';
        $is_vote = $state->conf->tags()->is_vote($vtag);
        $total = 0;
        foreach ($res as $x)
            if (preg_match($tag_re, $x["ltag"]))
                $total += $is_vote ? (float) $x["_index"] : 1;
        $state->add(array("type" => "tag", "pid" => $pid, "ltag" => strtolower($vtag),
                          "_tag" => $vtag, "_index" => $total, "_vote" => true));
    }
    function realize(AssignmentItem $item, $cmap, AssignmentState $state) {
        $prow = $state->prow($item["pid"]);
        // check permissions
        if (!$item["_vote"]) {
            $whyNot = $state->contact->perm_change_tag($prow, $item["ltag"],
                $item->get(true, "_index"), $item->get(false, "_index"),
                $item->override);
            if ($whyNot) {
                if (get($whyNot, "otherTwiddleTag"))
                    return null;
                throw new Exception(whyNotText($whyNot, "tag"));
            }
        }
        return new Tag_Assigner($item, $cmap);
    }
}

class Tag_Assigner extends Assigner {
    private $tag;
    private $index;
    function __construct(AssignmentItem $item, AssignerContacts $cmap) {
        parent::__construct($item, $cmap);
        $this->tag = $item["_tag"];
        $this->index = $item->get(false, "_index");
        if ($this->index == 0 && $item["_vote"])
            $this->index = null;
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
        $aset->contact->log_activity("Tag: " . ($this->index === null ? "-" : "+") . "#$this->tag" . ($this->index ? "#$this->index" : ""), $this->pid);
    }
    function notify_tracker() {
        return true;
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
    function allow_special_contact($cclass, &$req, AssignmentState $state) {
        if ($cclass === "any")
            return "pc";
        else if ($cclass === "missing" && $state->reviewer->isPC)
            return [$state->reviewer];
        else
            return false;
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        if ($state->contact->can_administer($prow, $state->override)) {
            if (!$contact->can_accept_review_assignment_ignore_conflict($prow))
                return Text::user_html_nolink($contact) . " can’t enter preferences for #{$prow->paperId}.";
            else
                return true;
        } else {
            if ($contact->contactId !== $state->contact->contactId)
                return "Can’t change other users’ preferences for #{$prow->paperId}.";
            else if (!$contact->can_become_reviewer_ignore_conflict($prow))
                return "Can’t enter preferences for #{$prow->paperId}.";
            else
                return true;
        }
    }
    static private function make_exp($exp) {
        return $exp === null ? "N" : +$exp;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type($this->type, ["pid", "cid"]))
            return;
        if ($state->paper_limit)
            $result = $state->conf->qe("select paperId, contactId, preference, expertise from PaperReviewPreference where paperId?a", $state->paper_ids());
        else
            $result = $state->conf->qe("select paperId, contactId, preference, expertise from PaperReviewPreference");
        while (($row = edb_row($result)))
            $state->load(array("type" => $this->type, "pid" => +$row[0], "cid" => +$row[1], "_pref" => +$row[2], "_exp" => self::make_exp($row[3])));
        Dbl::free($result);
    }
    function apply($pid, $contact, &$req, AssignmentState $state) {
        foreach (array("preference", "pref", "revpref") as $k)
            if (($pref = get($req, $k)) !== null)
                break;
        if ($pref === null)
            return "Missing preference";
        $pref = trim((string) $pref);
        if ($pref == "" || $pref == "none")
            $ppref = array(0, null);
        else if (($ppref = parse_preference($pref)) === null)
            return "Invalid preference “" . htmlspecialchars($pref) . "”";

        foreach (array("expertise", "revexp") as $k)
            if (($exp = get($req, $k)) !== null)
                break;
        if ($exp && ($exp = trim($exp)) !== "") {
            if (($pexp = parse_preference($exp)) === null || $pexp[0])
                return "Invalid expertise “" . htmlspecialchars($exp) . "”";
            $ppref[1] = $pexp[1];
        }

        $state->remove(array("type" => $this->type, "pid" => $pid, "cid" => $contact->contactId ? : null));
        if ($ppref[0] || $ppref[1] !== null)
            $state->add(array("type" => $this->type, "pid" => $pid, "cid" => $contact->contactId, "_pref" => $ppref[0], "_exp" => self::make_exp($ppref[1])));
    }
    function realize(AssignmentItem $item, $cmap, AssignmentState $state) {
        return new Preference_Assigner($item, $cmap);
    }
}

class Preference_Assigner extends Assigner {
    function __construct(AssignmentItem $item, AssignerContacts $cmap) {
        parent::__construct($item, $cmap);
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
        $t = $aset->contact->reviewer_html_for($this->contact);
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

AssignmentParser::register("none", new Null_AssignmentParser);
AssignmentParser::register("null", new Null_AssignmentParser);
AssignmentParser::register("pri", new Review_AssignmentParser(REVIEW_PRIMARY));
AssignmentParser::register("primary", new Review_AssignmentParser(REVIEW_PRIMARY));
AssignmentParser::register("primaryreview", new Review_AssignmentParser(REVIEW_PRIMARY));
AssignmentParser::register("sec", new Review_AssignmentParser(REVIEW_SECONDARY));
AssignmentParser::register("secondary", new Review_AssignmentParser(REVIEW_SECONDARY));
AssignmentParser::register("secondaryreview", new Review_AssignmentParser(REVIEW_SECONDARY));
AssignmentParser::register("pcreview", new Review_AssignmentParser(REVIEW_PC));
AssignmentParser::register("ext", new Review_AssignmentParser(REVIEW_EXTERNAL));
AssignmentParser::register("external", new Review_AssignmentParser(REVIEW_EXTERNAL));
AssignmentParser::register("extreview", new Review_AssignmentParser(REVIEW_EXTERNAL));
AssignmentParser::register("externalreview", new Review_AssignmentParser(REVIEW_EXTERNAL));
AssignmentParser::register("review", new Review_AssignmentParser(-1));
AssignmentParser::register("clearreview", new Review_AssignmentParser(0));
AssignmentParser::register("noreview", new Review_AssignmentParser(0));
AssignmentParser::register("unassignreview", new Review_AssignmentParser(0));
AssignmentParser::register("unsubmitreview", new UnsubmitReview_AssignmentParser);
AssignmentParser::register("lead", new Lead_AssignmentParser("lead", true));
AssignmentParser::register("nolead", new Lead_AssignmentParser("lead", false));
AssignmentParser::register("clearlead", new Lead_AssignmentParser("lead", false));
AssignmentParser::register("shepherd", new Lead_AssignmentParser("shepherd", true));
AssignmentParser::register("noshepherd", new Lead_AssignmentParser("shepherd", false));
AssignmentParser::register("clearshepherd", new Lead_AssignmentParser("shepherd", false));
AssignmentParser::register("administrator", new Lead_AssignmentParser("administrator", true));
AssignmentParser::register("noadministrator", new Lead_AssignmentParser("administrator", false));
AssignmentParser::register("clearadministrator", new Lead_AssignmentParser("administrator", false));
AssignmentParser::register("admin", new Lead_AssignmentParser("administrator", true));
AssignmentParser::register("noadmin", new Lead_AssignmentParser("administrator", false));
AssignmentParser::register("clearadmin", new Lead_AssignmentParser("administrator", false));
AssignmentParser::register("conflict", new Conflict_AssignmentParser(CONFLICT_CHAIRMARK));
AssignmentParser::register("noconflict", new Conflict_AssignmentParser(0));
AssignmentParser::register("clearconflict", new Conflict_AssignmentParser(0));
AssignmentParser::register("tag", new Tag_AssignmentParser(true));
AssignmentParser::register("settag", new Tag_AssignmentParser(true));
AssignmentParser::register("notag", new Tag_AssignmentParser(false));
AssignmentParser::register("cleartag", new Tag_AssignmentParser(false));
AssignmentParser::register("nexttag", new Tag_AssignmentParser(Tag_AssignmentParser::NEXT));
AssignmentParser::register("seqnexttag", new Tag_AssignmentParser(Tag_AssignmentParser::NEXTSEQ));
AssignmentParser::register("nextseqtag", new Tag_AssignmentParser(Tag_AssignmentParser::NEXTSEQ));
AssignmentParser::register("preference", new Preference_AssignmentParser);
AssignmentParser::register("pref", new Preference_AssignmentParser);
AssignmentParser::register("revpref", new Preference_AssignmentParser);

class AssignmentSet {
    public $conf;
    public $contact;
    public $filename;
    private $assigners = array();
    private $enabled_pids = null;
    private $enabled_actions = null;
    private $msgs = array();
    private $has_errors = false;
    private $my_conflicts = null;
    private $override_stack = array();
    private $astate;
    private $cmap;
    private $searches = array();
    private $reviewer_set = false;
    private $papers_encountered = array();
    private $unparse_search = false;
    private $unparse_columns = array();
    private $assignment_type = null;

    function __construct(Contact $contact, $override = null) {
        $this->conf = $contact->conf;
        $this->contact = $contact;
        if ($override === null)
            $override = $this->contact->is_admin_force();
        $this->astate = new AssignmentState($contact, $override);
        $this->cmap = new AssignerContacts($this->conf);
    }

    function set_reviewer(Contact $reviewer) {
        $this->astate->reviewer = $reviewer;
    }

    function enable_actions($action) {
        assert(empty($this->assigners));
        if ($this->enabled_actions === null)
            $this->enabled_actions = [];
        if (is_array($action)) {
            foreach ($action as $a)
                $this->enable_actions($a);
        } else if (($a = AssignmentParser::find($action)))
            $this->enabled_actions[$a->type] = true;
    }

    function enable_papers($paper) {
        assert(empty($this->assigners));
        if ($this->enabled_pids === null)
            $this->enabled_pids = [];
        if (is_array($paper)) {
            foreach ($paper as $p)
                $this->enable_papers($p);
        } else if ($paper instanceof PaperInfo) {
            $this->astate->prows[$paper->paperId] = $paper;
            $this->enabled_pids[$paper->paperId] = true;
        } else
            $this->enabled_pids[$paper] = true;
    }

    function push_override($override) {
        if ($override === null)
            $override = $this->contact->is_admin_force();
        $this->override_stack[] = $this->astate->override;
        $this->astate->override = $override;
    }

    function pop_override() {
        if (!empty($this->override_stack))
            $this->astate->override = array_pop($this->override_stack);
    }

    function is_empty() {
        return empty($this->assigners);
    }

    function has_error() {
        return $this->has_errors;
    }

    function clear_errors() {
        $this->msgs = [];
        $this->has_errors = false;
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
            $this->has_errors = true;
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
        if (!empty($this->msgs) && $this->has_errors)
            Conf::msg_error('Assignment errors: <div class="parseerr"><p>' . join("</p>\n<p>", $this->errors_html(true)) . '</p></div> Please correct these errors and try again.');
        else if (!empty($this->msgs))
            Conf::msg_warning('Assignment warnings: <div class="parseerr"><p>' . join("</p>\n<p>", $this->errors_html(true)) . '</p></div>');
    }

    private static function req_user_html($req) {
        return Text::user_html_nolink(get($req, "firstName"), get($req, "lastName"), get($req, "email"));
    }

    private function set_my_conflicts() {
        $this->my_conflicts = array();
        $result = $this->conf->qe("select Paper.paperId, managerContactId from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId) where conflictType>0 and PaperConflict.contactId=?", $this->contact->contactId);
        while (($row = edb_row($result)))
            $this->my_conflicts[$row[0]] = ($row[1] ? $row[1] : true);
        Dbl::free($result);
    }

    private static function apply_user_parts(&$req, $a) {
        foreach (array("firstName", "lastName", "email") as $i => $k)
            if (!get($req, $k) && get($a, $i))
                $req[$k] = $a[$i];
    }

    private function reviewer_set() {
        if ($this->reviewer_set === false) {
            $this->reviewer_set = array();
            foreach ($this->conf->pc_members() as $p)
                $this->reviewer_set[$p->contactId] = $p;
            $result = $this->conf->qe("select " . AssignerContacts::$query . " from ContactInfo join PaperReview using (contactId) where (roles&" . Contact::ROLE_PC . ")=0 group by ContactInfo.contactId");
            while ($result && ($row = Contact::fetch($result, $this->conf)))
                $this->reviewer_set[$row->contactId] = $row;
            Dbl::free($result);
        }
        return $this->reviewer_set;
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
        if ($special === "all")
            $special = "any";

        // check special: missing, "none", "any", "pc", "me", PC tag, "external"
        if (!$first && !$last && !$lemail)
            $special = "missing";
        if ($special === "none" || $special === "any" || $special === "missing") {
            $x = $assigner->allow_special_contact($special, $req, $this->astate);
            if (is_array($x))
                return $x;
            else if ($x === true) {
                if ($special === "missing")
                    return [null];
                else
                    return [(object) ["roles" => 0, "contactId" => null, "email" => $special, "sorter" => ""]];
            } else if (is_string($x))
                $special = $x;
            else
                return $this->error($special === "missing" ? "User missing." : "User “{$xspecial}” not allowed here.");
        }
        if ($special && !$first && (!$lemail || !$last)) {
            $ret = ContactSearch::make_special($special, $this->astate->reviewer);
            if ($ret->ids !== false)
                return $ret->contacts();
        }
        if (($special === "ext" || $special === "external")
            && $assigner->contact_set($req, $this->astate) === "reviewers") {
            $ret = array();
            foreach ($this->reviewer_set() as $u)
                if (!$u->is_pc_member())
                    $ret[] = $u;
            return $ret;
        }

        // check for precise email match on existing contact (common case)
        if ($lemail && ($contact = $this->cmap->lookup_lemail($lemail)))
            return array($contact);

        // check PC list
        $cset = $assigner->contact_set($req, $this->astate);
        if ($cset === "pc")
            $cset = $this->conf->pc_members();
        else if ($cset === "reviewers")
            $cset = $this->reviewer_set();
        if ($cset) {
            $text = "";
            if ($first && $last)
                $text = "$last, $first";
            else if ($first || $last)
                $text = "$last$first";
            if ($email)
                $text .= " <$email>";
            $ret = ContactSearch::make_cset($text, $this->astate->reviewer, $cset);
            if (count($ret->ids) == 1)
                return $ret->contacts();
            else if (empty($ret->ids))
                $this->error("No user matches “" . self::req_user_html($req) . "”.");
            else
                $this->error("“" . self::req_user_html($req) . "” matches more than one user, use a full email address to disambiguate.");
            return false;
        }

        // create contact
        if (!$email)
            return $this->error("Missing email address");
        $contact = $this->cmap->make_email($email);
        if ($contact->contactId < 0) {
            if (!validate_email($email))
                return $this->error("Email address “" . htmlspecialchars($email) . "” is invalid.");
            if (!isset($contact->firstName) && get($req, "firstName"))
                $contact->firstName = $req["firstName"];
            if (!isset($contact->lastName) && get($req, "lastName"))
                $contact->lastName = $req["lastName"];
        }
        return array($contact);
    }

    static private function is_csv_header($req) {
        foreach (array("action", "assignment", "paper", "pid", "paperId") as $k)
            if (array_search($k, $req) !== false)
                return true;
        return false;
    }

    private function install_csv_header($csv, $req) {
        if (!self::is_csv_header($req)) {
            if (count($req) == 3
                && (!$req[2] || strpos($req[2], "@") !== false))
                $csv->set_header(array("paper", "name", "email"));
            else if (count($req) == 2)
                $csv->set_header(array("paper", "user"));
            else
                $csv->set_header(array("paper", "action", "user", "round"));
            $csv->unshift($req);
        } else {
            $cleans = array("paper", "pid", "paper", "paperId",
                            "firstName", "first", "lastName", "last",
                            "firstName", "firstname", "lastName", "lastname",
                            "preference", "pref");
            for ($i = 0; $i < count($cleans); $i += 2)
                if (array_search($cleans[$i], $req) === false
                    && ($j = array_search($cleans[$i + 1], $req)) !== false)
                    $req[$j] = $cleans[$i];
            $csv->set_header($req);
        }

        $has_action = array_search("action", $csv->header()) !== false
            || array_search("assignment", $csv->header()) !== false;
        if (!$has_action && array_search("tag", $csv->header()) !== false)
            $this->astate->defaults["action"] = "tag";
        if (!$has_action && array_search("preference", $csv->header()) !== false)
            $this->astate->defaults["action"] = "preference";
        if (!$has_action && !get($this->astate->defaults, "action"))
            return $this->error($csv->lineno(), "“assignment” column missing");
        if (array_search("paper", $csv->header()) === false)
            return $this->error($csv->lineno(), "“paper” column missing");
        if (!isset($this->astate->defaults["action"]))
            $this->astate->defaults["action"] = "<missing>";
        return true;
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

    function parse($text, $filename = null, $defaults = null, $alertf = null) {
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

        // parse file, load papers all at once
        $lines = $pids = [];
        while (($req = $csv->next()) !== false) {
            $lines[] = [$csv->lineno(), $req];
            $this->collect_papers($req, $pids, false);
        }
        if (!empty($pids)) {
            $this->astate->lineno = $csv->lineno();
            $this->astate->fetch_prows(array_keys($pids));
        }
        if ($this->enabled_pids !== null)
            $this->astate->paper_limit = true;

        // now parse assignment
        foreach ($lines as $i => $linereq) {
            $this->astate->lineno = $linereq[0];
            if ($i % 100 == 0) {
                if ($alertf)
                    call_user_func($alertf, $this, $linereq[0], $linereq[1]);
                set_time_limit(30);
            }
            $this->apply($linereq[1]);
        }
        if ($alertf)
            call_user_func($alertf, $this, $csv->lineno(), false);

        $this->finish();
    }

    private function collect_papers($req, &$pids, $report_error) {
        $pfield = trim(get_s($req, "paper"));
        if ($pfield !== "" && ctype_digit($pfield)) {
            $npids = [intval($pfield)];
            $val = 2;
        } else if ($pfield !== "") {
            if (!isset($this->searches[$pfield])) {
                $search = new PaperSearch($this->contact, $pfield, $this->astate->reviewer);
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
            return;
        }
        if (empty($npids) && $report_error)
            $this->error("No papers match “" . htmlspecialchars($pfield) . "”");

        // Implement paper restriction
        if ($this->enabled_pids !== null)
            $npids = array_filter($npids, function ($pid) { return isset($this->enabled_pids[$pid]); });

        foreach ($npids as $pid)
            $pids[$pid] = $val;
    }

    function apply($req) {
        // parse paper
        $pids = [];
        $this->collect_papers($req, $pids, true);
        if (empty($pids))
            return false;
        $pfield_straight = join(",", array_values($pids)) === "2";
        $pids = array_keys($pids);

        // check action
        if (($action = get($req, "action")) === null
            && ($action = get($req, "assignment")) === null
            && ($action = get($req, "type")) === null)
            $action = $this->astate->defaults["action"];
        $action = strtolower(trim($action));
        if (!($assigner = AssignmentParser::find($action)))
            return $this->error("Unknown action “" . htmlspecialchars($action) . "”");
        if ($this->enabled_actions !== null
            && !isset($this->enabled_actions[$assigner->type]))
            return $this->error("Action “" . htmlspecialchars($action) . "” disabled");
        $assigner->load_state($this->astate);

        // clean user parts
        $contacts = $this->lookup_users($req, $assigner);
        if ($contacts === false)
            return false;
        $filter_contact = null;
        if (count($contacts) == 1 && $contacts[0] && $contacts[0]->contactId > 0)
            $filter_contact = $contacts[0];

        // maybe filter papers
        if (count($pids) > 20
            && $filter_contact
            && ($pf = $assigner->paper_filter($filter_contact, $req, $this->astate))) {
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

            $err = $assigner->allow_paper($prow, $this->astate);
            if ($err !== true) {
                if (is_string($err))
                    $this->astate->paper_error($err);
                continue;
            }

            $this->encounter_order[$p] = $p;

            $cf = null;
            if (count($contacts) > 1 || $filter_contact)
                $cf = $assigner->contact_filter($p, $req, $this->astate);

            foreach ($contacts as $contact) {
                if ($cf && $contact && $contact->contactId
                    && !get($cf, $contact->contactId))
                    continue;
                if ($contact && $contact->contactId > 0) {
                    $err = $assigner->allow_contact($prow, $contact, $req, $this->astate);
                    if ($err === false) {
                        if ($prow->has_conflict($contact))
                            $err = Text::user_html_nolink($contact) . " has a conflict with submission #$p.";
                        else
                            $err = Text::user_html_nolink($contact) . " cannot be assigned to submission #$p.";
                    }
                    if (is_string($err))
                        $this->astate->paper_error($err);
                    if ($err !== true)
                        continue;
                }
                $err = $assigner->apply($p, $contact, $req, $this->astate);
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

    function finish() {
        // call finishers
        foreach ($this->astate->finishers as $fin)
            $fin->apply_finisher($this->astate);

        // create assigners for difference
        foreach ($this->astate->diff() as $pid => $difflist)
            foreach ($difflist as $item) {
                $parser = AssignmentParser::find($item["type"]);
                try {
                    if (($a = $parser->realize($item, $this->cmap, $this->astate)))
                        $this->assigners[] = $a;
                } catch (Exception $e) {
                    $this->error($item->lineno, $e->getMessage());
                }
            }
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
        $search = new PaperSearch($this->contact, ["t" => get($_REQUEST, "t", "s"), "q" => $query_order], $this->astate->reviewer);
        $plist = new PaperList($search);
        $plist->set_table_id_class("foldpl", "pltable_full");
        echo $plist->table_html("reviewers", ["nofooter" => 1]);

        $deltarev = new AssignmentCountSet($this->conf);
        foreach ($this->assigners as $assigner)
            $assigner->account($deltarev);
        if (count(array_intersect_key($deltarev->bypc, $this->conf->pc_members()))) {
            $summary = [];
            $tagger = new Tagger($this->contact);
            $nrev = new AssignmentCountSet($this->conf);
            $deltarev->rev && $nrev->load_rev();
            $deltarev->lead && $nrev->load_lead();
            $deltarev->shepherd && $nrev->load_shepherd();
            foreach ($this->conf->pc_members() as $p)
                if ($deltarev->get($p->contactId)->ass) {
                    $t = '<div class="ctelt"><div class="ctelti';
                    if (($k = $p->viewable_color_classes($this->contact)))
                        $t .= ' ' . $k;
                    $t .= '"><span class="taghl">' . $this->contact->name_html_for($p) . "</span>: "
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

    function execute($verbose = false) {
        global $Now;
        if ($this->has_errors || empty($this->assigners)) {
            if ($verbose && !empty($this->msgs))
                $this->report_errors();
            else if ($verbose)
                $this->conf->warnMsg("Nothing to assign.");
            return !$this->has_errors; // true means no errors
        }

        // mark activity now to avoid DB errors later
        $this->contact->mark_activity();

        // create new contacts outside the lock
        $locks = array("ContactInfo" => "read", "Paper" => "read", "PaperConflict" => "read");
        $this->conf->save_logs(true);
        foreach ($this->assigners as $assigner) {
            if ($assigner->contact && $assigner->contact->contactId < 0) {
                $assigner->contact = $this->cmap->register_contact($assigner->contact);
                $assigner->cid = $assigner->contact->contactId;
            }
            $assigner->add_locks($this, $locks);
        }

        // execute assignments
        $tables = array();
        foreach ($locks as $t => $type)
            $tables[] = "$t $type";
        $this->conf->qe("lock tables " . join(", ", $tables));

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
        $this->conf->update_rev_tokens_setting(false);
        $this->conf->update_paperlead_setting();

        $pids = array();
        foreach ($this->assigners as $assigner) {
            $assigner->cleanup($this);
            if ($assigner->pid > 0 && $assigner->notify_tracker())
                $pids[$assigner->pid] = true;
        }
        if (!empty($pids) && $this->conf->opt("trackerCometSite"))
            MeetingTracker::contact_tracker_comet($this->conf, array_keys($pids));

        return true;
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
        if ($ct->pri != $ct->rev)
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
