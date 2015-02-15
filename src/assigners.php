<?php
// assigners.php -- HotCRP helper classes for assignments
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class AssignmentItem implements ArrayAccess {
    public $before;
    public $after = null;
    public $lineno = null;
    public function __construct($before) {
        $this->before = $before;
    }
    public function offsetExists($offset) {
        $x = $this->after ? : $this->before;
        return isset($x[$offset]);
    }
    public function offsetGet($offset) {
        $x = $this->after ? : $this->before;
        return @$x[$offset];
    }
    public function offsetSet($offset, $value) {
    }
    public function offsetUnset($offset) {
    }
    public function existed() {
        return !!$this->before;
    }
    public function deleted() {
        return $this->after === false;
    }
}

class AssignmentState {
    private $st = array();
    private $types = array();
    private $extra = array();
    public $contact;
    public $tagger = null;
    public $lineno = null;
    public $defaults = array();
    public $prows = array();
    public function __construct($contact) {
        $this->contact = $contact;
    }
    public function load_type($type, $loader) {
        if (!isset($this->types[$type])) {
            $this->types[$type] = $loader->load_keys();
            $loader->load_state($this);
        }
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
    public function load($x) {
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
            if ($v !== null && @$x[$k] != $v)
                return false;
        }
        return true;
    }
    private function do_query_remove($item, $q, $remove, &$res) {
        if ($item
            && $item->after !== false
            && self::match($item->after ? : $item->before, $q)) {
            $res[] = $item->after ? : $item->before;
            if ($remove) {
                $item->after = false;
                $item->lineno = $this->lineno;
            }
        }
    }
    private function query_remove($q, $remove) {
        $res = array();
        foreach ($this->pid_keys($q) as $pid) {
            $st = $this->pidstate($pid);
            if (($k = $this->extract_key($q)))
                $this->do_query_remove(@$st->items[$k], $q, $remove, $res);
            else
                foreach ($st->items as $item)
                    $this->do_query_remove($item, $q, $remove, $res);
        }
        return $res;
    }
    public function query($q) {
        return $this->query_remove($q, false);
    }
    public function remove($q) {
        return $this->query_remove($q, true);
    }
    public function add($x) {
        $k = $this->extract_key($x);
        assert(!!$k);
        $st = $this->pidstate($x["pid"]);
        $item = @$st->items[$k];
        if (!$item)
            $item = $st->items[$k] = new AssignmentItem(false);
        $item->after = $x;
        $item->lineno = $this->lineno;
    }
    public function diff() {
        $diff = array();
        foreach ($this->st as $pid => $st) {
            foreach ($st->items as $item)
                if ((!$item->before && $item->after)
                    || ($item->before && $item->after === false)
                    || ($item->before && $item->after && !self::match($item->before, $item->after)))
                    @($diff[$pid][] = $item);
        }
        return $diff;
    }
    public function extra($key) {
        return @$this->extra[$key];
    }
    public function set_extra($key, $value) {
        $old = @$this->extra[$key];
        $this->extra[$key] = $value;
        return $old;
    }
}

class AssignerContacts {
    private $by_id = array();
    private $by_lemail = array();
    private $has_pc = false;
    static private $next_fake_id = -10;
    static public $query = "ContactInfo.contactId, firstName, lastName, unaccentedName, email, roles, contactTags";
    static public function make_none($email = null) {
        return (object) array("contactId" => 0, "roles" => 0, "email" => $email, "sorter" => "");
    }
    private function store($c) {
        if ($c && $c->contactId)
            $this->by_id[$c->contactId] = $c;
        if ($c && $c->email)
            $this->by_lemail[strtolower($c->email)] = $c;
        return $c;
    }
    private function store_pc() {
        foreach (pcMembers() as $p)
            $this->store($p);
        return ($this->has_pc = true);
    }
    public function make_id($cid) {
        global $Me;
        if (!$cid)
            return self::make_none();
        if (($c = @$this->by_id[$cid]))
            return $c;
        if ($Me && $Me->contactId > 0 && $cid == $Me->contactId)
            return $this->store($Me);
        if (!$this->has_pc && $this->store_pc() && ($c = @$this->by_id[$cid]))
            return $c;
        $result = Dbl::qe("select " . self::$query . " from ContactInfo where contactId=?", $cid);
        $c = $result ? $result->fetch_object() : null;
        if (!$c)
            $c = (object) array("contactId" => $cid, "roles" => 0, "email" => "unknown contact $cid", "sorter" => "");
        Dbl::free($result);
        return $this->store($c);
    }
    public function lookup_lemail($lemail) {
        global $Me;
        if (!$lemail)
            return self::make_none();
        if (($c = @$this->by_lemail[$lemail]))
            return $c;
        if ($Me && $Me->contactId > 0 && strcasecmp($lemail, $Me->email) == 0)
            return $this->store($Me);
        if (!$this->has_pc && $this->store_pc() && ($c = @$this->by_lemail[$lemail]))
            return $c;
        $result = Dbl::qe("select " . self::$query . " from ContactInfo where email=?", $email);
        $c = $result ? $result->fetch_object() : null;
        Dbl::free($result);
        return $this->store($c);
    }
    public function make_email($email) {
        $c = $this->lookup_lemail(strtolower($email));
        if (!$c) {
            $c = (object) array("contactId" => self::$next_fake_id, "roles" => 0, "email" => $email, "sorter" => $email);
            self::$next_fake_id -= 1;
            $c = $this->store($c);
        }
        return $c;
    }
    public function register_contact($c) {
        $lemail = strtolower($c->email);
        $cx = $this->lookup_lemail($lemail);
        if (!$cx || $cx->contactId < 0) {
            // XXX assume that never fails:
            $cx = Contact::find_by_email($c->email, array("firstName" => @$c->firstName, "lastName" => @$c->lastName), false);
            $cx = $this->store($cx);
        }
        return $cx;
    }
}

class Assigner {
    public $type;
    public $pid;
    public $contact;
    public $cid;
    static private $assigners = array();
    function __construct($type, $pid, $contact) {
        $this->type = $type;
        $this->pid = $pid;
        $this->contact = $contact;
        $this->cid = $contact ? $contact->contactId : null;
    }
    static function register($n, $a) {
        assert(!@self::$assigners[$n]);
        self::$assigners[$n] = $a;
    }
    static function assigner_names() {
        return array_keys(self::$assigners);
    }
    static function find($n) {
        return @self::$assigners[$n];
    }
    function allow($user) {
        return $user->privChair;
    }
    function contact_set() {
        return "pc";
    }
    function allow_special_contact($cclass) {
        return false;
    }
    function account(&$countbycid, $nrev) {
        $countbycid[$this->cid] = @+$countbycid[$this->cid] + 1;
    }
    function add_locks(&$locks) {
    }
}

class ReviewAssigner extends Assigner {
    private $rtype;
    private $round;
    private $oldtype;
    private $notify;
    function __construct($pid, $contact, $rtype, $round, $oldtype = 0, $notify = null) {
        global $reviewTypeName;
        parent::__construct($rtype ? strtolower($reviewTypeName[$rtype]) : "noreview", $pid, $contact);
        $this->rtype = $rtype;
        $this->round = $round;
        $this->oldtype = $oldtype;
        $this->notify = $notify;
    }
    function contact_set() {
        if ($this->rtype > REVIEW_EXTERNAL)
            return "pc";
        else if ($this->rtype == 0)
            return "reviewers";
        else
            return false;
    }
    function allow_special_contact($cclass) {
        return $this->rtype == 0 && $cclass != "none";
    }
    function load_keys() {
        return array("pid", "cid");
    }
    function load_state($state) {
        global $Conf;
        $result = Dbl::qe("select paperId, contactId, reviewType, reviewRound, reviewSubmitted from PaperReview");
        while (($row = edb_row($result))) {
            $round = $Conf->round_name($row[3], false);
            $state->load(array("type" => "review", "pid" => +$row[0], "cid" => +$row[1],
                               "_rtype" => $row[2], "_round" => $round,
                               "_rsubmitted" => $row[4] > 0));
        }
        Dbl::free($result);
    }
    function apply($pid, $contact, $req, $state) {
        $roundname = @$req["round"];
        if ($roundname === null && $this->rtype > 0 && @$state->defaults["round"])
            $roundname = $state->defaults["round"];
        if ($roundname && strcasecmp($roundname, "none") == 0)
            $roundname = "";
        else if ($roundname && (strcasecmp($roundname, "any") != 0 || $this->rtype > 0)) {
            if (($rerror = Conference::round_name_error($roundname)))
                return $rerror;
        } else
            $roundname = null;
        $rtype = $this->rtype;
        if ($rtype == REVIEW_EXTERNAL && $contact->is_pc_member())
            $rtype = REVIEW_PC;
        $state->load_type("review", $this);

        // remove existing review
        $revmatch = array("type" => "review", "pid" => +$pid,
                          "cid" => $contact ? $contact->contactId : null);
        if (!$rtype && @$req["round"] && $roundname !== null)
            $revmatch["_round"] = $roundname;
        $matches = $state->remove($revmatch);

        if ($rtype) {
            // add new review or reclassify old one
            $revmatch["_rtype"] = $rtype;
            if (count($matches) && $roundname === null)
                $roundname = $matches[0]["_round"];
            $revmatch["_round"] = $roundname;
            if (count($matches))
                $revmatch["_rsubmitted"] = $matches[0]["_rsubmitted"];
            if ($rtype == REVIEW_EXTERNAL && !count($matches)
                && @$state->defaults["extrev_notify"])
                $revmatch["_notify"] = $state->defaults["extrev_notify"];
            $state->add($revmatch);
        } else
            // do not remove submitted reviews
            foreach ($matches as $r)
                if ($r["_rsubmitted"])
                    $state->add($r);
    }
    function realize($item, $cmap, $state) {
        return new ReviewAssigner($item["pid"], $cmap->make_id($item["cid"]),
                                  $item->deleted() ? 0 : $item["_rtype"],
                                  $item["_round"],
                                  $item->existed() ? $item->before["_rtype"] : 0,
                                  $item->deleted() ? null : $item["_notify"]);
    }
    function unparse_display() {
        global $assignprefs;
        $t = Text::name_html($this->contact) . ' ';
        if ($this->rtype) {
            $t .= review_type_icon($this->rtype, true);
            if ($this->round)
                $t .= ' <span class="revround" title="Review round">'
                    . htmlspecialchars($this->round) . '</span>';
            if (@$assignprefs && ($pref = @$assignprefs["$this->pid:$this->cid"])
                && $pref !== "*")
                $t .= ' <span class="asspref' . ($pref > 0 ? 1 : -1)
                    . '">P' . decorateNumber($pref) . "</span>";
        } else
            $t = 'clear ' . $t . ' review';
        return $t;
    }
    function account(&$countbycid, $nrev) {
        $countbycid[$this->cid] = @+$countbycid[$this->cid] + 1;
        if ($this->cid > 0 && isset($nrev->any[$this->cid])) {
            $delta = $this->rtype ? 1 : -1;
            foreach (array($nrev, $nrev->pset) as $nnrev) {
                $nnrev->any[$this->cid] += ($this->rtype != 0) - ($this->oldtype != 0);
                $nnrev->pri[$this->cid] += ($this->rtype == REVIEW_PRIMARY) - ($this->oldtype == REVIEW_PRIMARY);
                $nnrev->sec[$this->cid] += ($this->rtype == REVIEW_SECONDARY) - ($this->oldtype == REVIEW_SECONDARY);
            }
        }
    }
    function add_locks(&$locks) {
        $locks["PaperReview"] = $locks["PaperReviewRefused"] = $locks["Settings"] = "write";
    }
    function execute($who) {
        global $Conf;
        $result = Dbl::qe("select contactId, paperId, reviewId, reviewType, reviewModified from PaperReview where paperId=$this->pid and contactId=$this->cid");
        $who->assign_review($this->pid, edb_orow($result), $this->cid, $this->rtype);
        Dbl::free($result);
        if ($this->notify) {
            $reviewer = Contact::find_by_id($this->cid);
            $prow = $Conf->paperRow(array("paperId" => $this->pid, "reviewer" => $this->cid), $reviewer);
            HotCRPMailer::send_to($reviewer, $this->notify, $prow);
        }
    }
}

class LeadAssigner extends Assigner {
    private $isadd;
    function __construct($type, $pid, $contact, $isadd) {
        parent::__construct($type, $pid, $contact);
        $this->isadd = $isadd;
    }
    function allow_special_contact($cclass) {
        return !$this->isadd || $cclass == "none";
    }
    function load_keys() {
        return array("pid");
    }
    function load_state($state) {
        $result = Dbl::qe("select paperId, " . $this->type . "ContactId from Paper where " . $this->type . "ContactId!=0");
        while (($row = edb_row($result)))
            $state->load(array("type" => $this->type, "pid" => +$row[0], "_cid" => +$row[1]));
        Dbl::free($result);
    }
    function apply($pid, $contact, $req, $state) {
        $state->load_type($this->type, $this);
        $remcid = $this->isadd || !$contact->contactId ? null : $contact->contactId;
        $state->remove(array("type" => $this->type, "pid" => $pid, "_cid" => $remcid));
        if ($this->isadd && $contact->contactId)
            $state->add(array("type" => $this->type, "pid" => $pid, "_cid" => $contact->contactId));
    }
    function realize($item, $cmap, $state) {
        return new LeadAssigner($item["type"], $item["pid"], $cmap->make_id($item["_cid"]),
                                !$item->deleted());
    }
    function unparse_display() {
        if (!$this->cid)
            return "remove $this->type";
        $t = Text::name_html($this->contact);
        if ($this->isadd)
            $t .= " ($this->type)";
        else
            $t = "remove $t as $this->type";
        return $t;
    }
    function add_locks(&$locks) {
        $locks["Paper"] = $locks["Settings"] = "write";
    }
    function execute($who) {
        $who->assign_paper_pc($this->pid, $this->type,
                              $this->isadd ? $this->cid : 0,
                              $this->isadd || !$this->cid ? array() : array("old_cid" => $this->cid));
    }
}

class ConflictAssigner extends Assigner {
    private $ctype;
    function __construct($pid, $contact, $ctype) {
        parent::__construct("conflict", $pid, $contact);
        $this->ctype = $ctype;
    }
    function allow_special_contact($cclass) {
        return $cclass == "conflict" || ($cclass == "any" && !$this->ctype);
    }
    function load_keys() {
        return array("pid", "cid");
    }
    function load_state($state) {
        $result = Dbl::qe("select paperId, contactId, conflictType from PaperConflict where conflictType>0");
        while (($row = edb_row($result)))
            $state->load(array("type" => "conflict", "pid" => +$row[0], "cid" => +$row[1], "_ctype" => +$row[2]));
        Dbl::free($result);
    }
    function apply($pid, $contact, $req, $state) {
        $state->load_type("conflict", $this);
        $res = $state->remove(array("type" => "conflict", "pid" => $pid, "cid" => $contact->contactId));
        if (count($res) && $res[0]["_ctype"] >= CONFLICT_AUTHOR)
            $state->add($res[0]);
        else if ($this->ctype)
            $state->add(array("type" => "conflict", "pid" => $pid, "cid" => $contact->contactId, "_ctype" => $this->ctype));
    }
    function realize($item, $cmap, $state) {
        return new ConflictAssigner($item["pid"], $cmap->make_id($item["cid"]),
                                    $item->deleted() ? 0 : $item["_ctype"]);
    }
    function unparse_display() {
        $t = Text::name_html($this->contact) . ' ';
        if ($this->ctype)
            $t .= review_type_icon(-1);
        else
            $t .= "(remove conflict)";
        return $t;
    }
    function add_locks(&$locks) {
        $locks["PaperConflict"] = "write";
    }
    function execute($who) {
        if ($this->ctype)
            Dbl::qe("insert into PaperConflict (paperId, contactId, conflictType) values ($this->pid,$this->cid,$this->ctype) on duplicate key update conflictType=values(conflictType)");
        else
            Dbl::qe("delete from PaperConflict where paperId=$this->pid and contactId=$this->cid");
    }
}

// index "next" => take a step
// index "seq" or "seqnext" => take a sequential step

class TagAssigner extends Assigner {
    private $isadd;
    private $tag;
    private $index;
    private $tagger;
    function __construct($pid, $isadd, $tag, $index, $tagger = null) {
        parent::__construct("tag", $pid, null);
        $this->isadd = $isadd;
        $this->tag = $tag;
        $this->index = $index;
        $this->tagger = $tagger;
    }
    function allow($user) {
        return $user->isPC;
    }
    function allow_special_contact($cclass) {
        return true;
    }
    function load_keys() {
        return array("pid", "ltag");
    }
    function load_state($state) {
        $result = Dbl::qe("select paperId, tag, tagIndex from PaperTag");
        while (($row = edb_row($result)))
            $state->load(array("type" => $this->type, "pid" => +$row[0], "ltag" => strtolower($row[1]), "_tag" => $row[1], "_index" => +$row[2]));
        Dbl::free($result);
        $state->tagger = new Tagger($state->contact);
    }
    function apply($pid, $contact, $req, $state) {
        $state->load_type($this->type, $this);
        if (!($tag = @$req["tag"]))
            return "missing tag";

        // index argument
        $xindex = @$req["index"];
        if ($xindex === null)
            $xindex = @$req["value"];
        if ($xindex !== null && ($xindex = trim($xindex)) !== "") {
            $tag = preg_replace(',\A(#?.+)(?:[=!<>]=?|#|≠|≤|≥)(?:|-?\d+|any|all|none|clear)\z,i', '$1', $tag);
            if (!preg_match(',\A(?:[=!<>]=?|#|≠|≤|≥),i', $xindex))
                $xindex = "#" . $xindex;
            $tag .= $xindex;
        }

        // tag parsing; see also PaperSearch::_check_tag
        if (!preg_match(',\A#?(|[^#]*~)([a-zA-Z!@*_:.]+[-a-zA-Z0-9!@*_:.\/]*)(|[=<>]=?|!=|[#≠≤≥])(|-?\d+|any|all|none|clear)\z,i', $tag, $m)
            || ($m[3] && !$m[4]))
            return "Invalid tag “". htmlspecialchars($tag) . "”.";
        if ($m[1] == "~" || strcasecmp($m[1], "me~") == 0)
            $m[1] = ($contact && $contact->contactId ? : $state->contact->contactId) . "~";
        // ignore attempts to change vote tags
        if (!$m[1] && TagInfo::is_vote($m[2]))
            return;

        // add and remove use different paths
        $isadd = $this->isadd && $m[4] !== "none" && $m[4] !== "clear";
        if ($isadd && strpos($tag, "*") !== false)
            return "Tag wildcards aren’t allowed when adding tags.";
        if (!$isadd)
            return $this->apply_remove($pid, $contact, $req, $state, $m);

        // resolve twiddle portion
        if ($m[1] && $m[1] != "~~" && !ctype_digit(substr($m[1], 0, strlen($m[1]) - 1))) {
            $c = substr($m[1], 0, strlen($m[1]) - 1);
            $twiddlecids = ContactSearch::lookup_pc($c, $state->contact->contactId);
            if (count($twiddlecids) == 0)
                return "“" . htmlspecialchars($c) . "” doesn’t match a PC member.";
            else if (count($twiddlecids) > 1)
                return "“" . htmlspecialchars($c) . "” matches more than one PC member; be more specific to disambiguate.";
            $m[1] = $twiddlecids[0] . "~";
        }

        // resolve tag portion
        if (preg_match(',\A(?:none|any|all)\z,i', $m[2]))
            return "Tag “{$tag}” is reserved.";

        // resolve index portion
        if ($m[3] && $m[3] != "#" && $m[3] != "=" && $m[3] != "==")
            return "“" . htmlspecialchars($m[3]) . "” isn’t allowed when adding tags.";
        $index = $m[3] ? cvtint($m[4], 0) : 0;

        // save assignment
        $tag = $m[1] . $m[2];
        $ltag = strtolower($tag);
        if ($this->isadd === "set" && !$state->set_extra("tag.$ltag", true))
            $state->remove(array("type" => $this->type, "ltag" => $ltag));
        $state->add(array("type" => $this->type, "pid" => $pid, "ltag" => $ltag, "_tag" => $tag, "_index" => $index));
        if (($vtag = TagInfo::vote_base($tag)))
            $this->account_votes($pid, $vtag, $state);
    }
    private function apply_remove($pid, $contact, $req, $state, $m) {
        // resolve twiddle portion
        if ($m[1] && $m[1] != "~~" && !ctype_digit(substr($m[1], 0, strlen($m[1]) - 1))) {
            $c = substr($m[1], 0, strlen($m[1]) - 1);
            $twiddlecids = ContactSearch::lookup_pc($c, $state->contact->contactId);
            if (count($twiddlecids) == 0)
                return "“" . htmlspecialchars($c) . "” doesn’t match a PC member.";
            else if (count($twiddlecids) == 1)
                $m[1] = $twiddlecids[0] . "~";
            else
                $m[1] = "(?:" . join("|", $twiddlecids) . ")~";
        }

        // resolve tag portion
        if (strcasecmp($m[2], "none") == 0)
            return;
        else if (strcasecmp($m[2], "any") == 0 || strcasecmp($m[2], "all") == 0) {
            if ($m[1])
                $m[2] = "[^~]*";
            else if ($state->contact->privChair)
                $m[2] = "(?:~~|" . $state->contact->contactId . "~|)[^~]*";
            else
                $m[2] = "(?:" . $state->contact->contactId . "~|)[^~]*";
        } else
            $m[2] = str_replace("\\*", "[^~]*", preg_quote($m[2]));

        // resolve index comparator
        if (preg_match(',\A(?:any|all|none|clear)\z,i', $m[4]))
            $m[3] = $m[4] = "";
        else {
            if ($m[3] == "#")
                $m[3] = "=";
            $m[4] = cvtint($m[4], 0);
        }

        // query
        $tag = $m[1] . $m[2];
        $search_ltag = !preg_match(',[*(],', $tag) ? strtolower($tag) : null;
        $res = $state->query(array("type" => "tag", "pid" => $pid, "ltag" => $search_ltag));
        $tag_re = '{\A' . $tag . '\z}i';
        $vote_adjustments = array();
        foreach ($res as $x)
            if (preg_match($tag_re, $x["ltag"])
                && (!$m[3] || SearchReviewValue::compare($x["_index"], $m[3], $m[4]))
                && !TagInfo::is_vote($x["ltag"])) {
                $state->remove($x);
                if (($v = TagInfo::vote_base($x["ltag"])))
                    $vote_adjustments[$x["_tag"]] = true;
            }
        foreach ($vote_adjustments as $vtag => $v)
            $this->account_votes($pid, $vtag, $state);
    }
    private function account_votes($pid, $vtag, $state) {
        $res = $state->query(array("type" => "tag", "pid" => $pid));
        $tag_re = '{\A\d+~' . preg_quote($vtag) . '\z}i';
        $total = 0;
        foreach ($res as $x)
            if (preg_match($tag_re, $x["ltag"]))
                $total += $x["_index"];
        if ($total)
            $state->add(array("type" => $this->type, "pid" => $pid, "ltag" => strtolower($vtag), "_tag" => $vtag, "_index" => $total));
        else
            $state->remove(array("type" => $this->type, "pid" => $pid, "ltag" => strtolower($vtag)));
    }
    function realize($item, $cmap, $state) {
        $prow = $state->prows[$item["pid"]];
        $is_admin = $state->contact->can_administer($prow);
        $tag = $item["_tag"];
        // only admin can change chair-only tags
        if (!$is_admin && TagInfo::is_chair($tag))
            throw new Exception("Tag #" . htmlspecialchars($tag) . " can only be changed by administrators.");
        // not admin, change other twiddle tag => ignore for security
        if (!$is_admin && strpos($tag, "~") !== false) {
            $cid = $state->contact->contactId;
            if (substr($tag, 0, strlen($cid) + 1) != $cid . "~")
                return null;
        }
        // conflict, cannot set
        if (!$is_admin && !$state->contact->can_set_tags($prow))
            throw new Exception("You have a conflict with paper #$prow->paperId.");
        // actually assign
        return new TagAssigner($item["pid"], true, $item["_tag"],
                               $item->deleted() ? null : $item["_index"],
                               $state->tagger);
    }
    function unparse_display() {
        $t = "#" . htmlspecialchars($this->tag);
        if ($this->index === null)
            $t = "remove $t";
        else if ($this->index)
            $t = "add $t#" . $this->index;
        else
            $t = "add $t";
        return $t;
    }
    function add_locks(&$locks) {
        $locks["PaperTag"] = "write";
    }
    function execute($who) {
        if ($this->index === null)
            Dbl::qe("delete from PaperTag where paperId=? and tag=?", $this->pid, $this->tag);
        else
            Dbl::qe("insert into PaperTag set paperId=?, tag=?, tagIndex=? on duplicate key update tagIndex=values(tagIndex)", $this->pid, $this->tag, $this->index);
        $who->log_activity("Tag " . ($this->index === null ? "remove" : "set") . ": $this->tag", $this->pid);
    }
}

class PreferenceAssigner extends Assigner {
    private $pref;
    private $exp;
    function __construct($pid, $contact, $pref, $exp) {
        parent::__construct("pref", $pid, $contact);
        $this->pref = $pref;
        $this->exp = $exp;
    }
    function allow_special_contact($cclass) {
        return $cclass == "conflict";
    }
    function load_keys() {
        return array("pid", "cid");
    }
    function load_state($state) {
        $result = Dbl::qe("select paperId, contactId, preference, expertise from PaperReviewPreference");
        while (($row = edb_row($result)))
            $state->load(array("type" => $this->type, "pid" => +$row[0], "cid" => +$row[1], "_pref" => +$row[2], "_exp" => +$row[3]));
        Dbl::free($result);
    }
    function apply($pid, $contact, $req, $state) {
        $state->load_type($this->type, $this);

        foreach (array("preference", "pref", "revpref") as $k)
            if (($pref = @$req[$k]) !== null)
                break;
        if ($pref === null)
            return "Missing preference";
        $pref = @trim($pref);
        if ($pref == "" || $pref == "none")
            $ppref = array(0, null);
        else if (($ppref = parse_preference($pref)) === null)
            return "Invalid preference “" . htmlspecialchars($pref) . "”";

        foreach (array("expertise", "revexp") as $k)
            if (($exp = @$req[$k]) !== null)
                break;
        if ($exp && ($exp = trim($exp)) !== "") {
            if (($pexp = parse_preference($exp)) === null || $pexp[0])
                return "Invalid expertise “" . htmlspecialchars($exp) . "”";
            $ppref[1] = $pexp[1];
        }

        $state->remove(array("type" => $this->type, "pid" => $pid, "cid" => $contact->contactId ? : null));
        if ($ppref[0] || $ppref[1] !== null)
            $state->add(array("type" => $this->type, "pid" => $pid, "cid" => $contact->contactId, "_pref" => $ppref[0], "_exp" => $ppref[1]));
    }
    function realize($item, $cmap, $state) {
        return new PreferenceAssigner($item["pid"], $cmap->make_id($item["cid"]),
                                      $item->deleted() ? 0 : $item["_pref"],
                                      $item->deleted() ? null : $item["_exp"]);
    }
    function unparse_display() {
        if (!$this->cid)
            return "remove all preferences";
        return Text::name_html($this->contact) . " " . unparse_preference_span(array($this->pref, $this->exp), true);
    }
    function add_locks(&$locks) {
        $locks["PaperReviewPreference"] = "write";
    }
    function execute($who) {
        if (!$this->pref && $this->exp === null)
            Dbl::qe("delete from PaperReviewPreference where paperId=? and contactId=?", $this->pid, $this->cid);
        else
            Dbl::qe("insert into PaperReviewPreference
                set paperId=?, contactId=?, preference=?, expertise=?
                on duplicate key update preference=values(preference), expertise=values(expertise)",
                    $this->pid, $this->cid, $this->pref, $this->exp);
    }
}

Assigner::register("pri", new ReviewAssigner(0, null, REVIEW_PRIMARY, ""));
Assigner::register("primary", new ReviewAssigner(0, null, REVIEW_PRIMARY, ""));
Assigner::register("sec", new ReviewAssigner(0, null, REVIEW_SECONDARY, ""));
Assigner::register("secondary", new ReviewAssigner(0, null, REVIEW_SECONDARY, ""));
Assigner::register("pcreview", new ReviewAssigner(0, null, REVIEW_PC, ""));
Assigner::register("review", new ReviewAssigner(0, null, REVIEW_EXTERNAL, ""));
Assigner::register("extreview", new ReviewAssigner(0, null, REVIEW_EXTERNAL, ""));
Assigner::register("ext", new ReviewAssigner(0, null, REVIEW_EXTERNAL, ""));
Assigner::register("noreview", new ReviewAssigner(0, null, 0, ""));
Assigner::register("clearreview", new ReviewAssigner(0, null, 0, ""));
Assigner::register("lead", new LeadAssigner("lead", 0, null, true));
Assigner::register("nolead", new LeadAssigner("lead", 0, null, false));
Assigner::register("clearlead", new LeadAssigner("lead", 0, null, false));
Assigner::register("shepherd", new LeadAssigner("shepherd", 0, null, true));
Assigner::register("noshepherd", new LeadAssigner("shepherd", 0, null, false));
Assigner::register("clearshepherd", new LeadAssigner("shepherd", 0, null, false));
Assigner::register("conflict", new ConflictAssigner(0, null, CONFLICT_CHAIRMARK));
Assigner::register("noconflict", new ConflictAssigner(0, null, 0));
Assigner::register("clearconflict", new ConflictAssigner(0, null, 0));
Assigner::register("tag", new TagAssigner(0, true, null, 0));
Assigner::register("settag", new TagAssigner(0, "set", null, 0));
Assigner::register("notag", new TagAssigner(0, false, null, 0));
Assigner::register("cleartag", new TagAssigner(0, false, null, 0));
Assigner::register("preference", new PreferenceAssigner(0, null, 0, null));
Assigner::register("pref", new PreferenceAssigner(0, null, 0, null));
Assigner::register("revpref", new PreferenceAssigner(0, null, 0, null));

class AssignmentSet {
    private $assigners = array();
    public $filename;
    private $errors_ = array();
    private $my_conflicts = null;
    private $contact;
    private $override;
    private $astate;
    private $cmap;
    private $reviewer_set = false;

    function __construct($contact, $override) {
        $this->contact = $contact;
        $this->override = $override;
        $this->astate = new AssignmentState($contact);
        $this->cmap = new AssignerContacts;
    }

    private function error($lineno, $message) {
        $e = array($this->filename, $lineno, $message);
        if (($n = count($this->errors_) - 1) >= 0
            && $this->errors_[$n][0] == $e[0]
            && $this->errors_[$n][1] == $e[1]
            && $this->errors_[$n][2] == $e[2])
            /* skip duplicate error */;
        else
            $this->errors_[] = $e;
        return false;
    }

    public function has_errors() {
        return count($this->errors_) > 0;
    }

    public function errors_html($linenos = false) {
        $es = array();
        foreach ($this->errors_ as $e) {
            $t = $e[2];
            if ($linenos && $e[0])
                $t = '<span class="lineno">' . htmlspecialchars($e[0])
                    . ':' . $e[1] . ':</span> ' . $t;
            $es[] = $t;
        }
        return $es;
    }

    public function errors_text($linenos = false) {
        $es = array();
        foreach ($this->errors_ as $e) {
            $t = htmlspecialchars_decode(preg_replace(',<(?:[^\'">]|\'[^\']*\'|"[^"]*")*>,', "", $e[2]));
            if ($linenos && $e[2])
                $t = $e[0] . ':' . $e[1] . ': ' . $t;
            $es[] = $t;
        }
        return $es;
    }

    public function report_errors() {
        global $Conf;
        if (count($this->errors_))
            $Conf->errorMsg('Assignment errors: <div class="parseerr"><p>' . join("</p>\n<p>", $this->errors_html(true)) . '</p></div> Please correct these errors and try again.');
    }

    private static function req_user_html($req) {
        return Text::user_html_nolink(@$req["firstName"], @$req["lastName"], @$req["email"]);
    }

    private static function contacts_by($what) {
        $cb = array();
        foreach (edb_orows(Dbl::qe("select " . AssignerContacts::$query . " from ContactInfo")) as $c)
            $cb[$c->$what] = $c;
        return $cb;
    }

    private function set_my_conflicts() {
        $this->my_conflicts = array();
        $result = Dbl::qe("select Paper.paperId, managerContactId from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId) where conflictType>0 and PaperConflict.contactId=" . $this->contact->contactId);
        while (($row = edb_row($result)))
            $this->my_conflicts[$row[0]] = ($row[1] ? $row[1] : true);
        Dbl::free($result);
    }

    private static function apply_user_parts(&$req, $a) {
        foreach (array("firstName", "lastName", "email") as $i => $k)
            if (!@$req[$k] && @$a[$i])
                $req[$k] = $a[$i];
    }

    private function reviewer_set() {
        if ($this->reviewer_set === false) {
            $result = Dbl::qe("select " . AssignerContacts::$query . " from ContactInfo left join PaperReview using (contactId) where (roles&" . Contact::ROLE_PC . ")!=0 or reviewId is not null group by ContactInfo.contactId");
            $this->reviewer_set = array();
            while (($row = edb_orow($result)))
                $this->reviewer_set[$row->contactId] = $row;
            Dbl::free($result);
        }
        return $this->reviewer_set;
    }

    private function lookup_users(&$req, $assigner, $csv) {
        // move all usable identification data to email, firstName, lastName
        if (isset($req["name"]))
            self::apply_user_parts($req, Text::split_name($req["name"]));
        if (isset($req["user"]) && strpos($req["user"], " ") === false) {
            if (!@$req["email"])
                $req["email"] = $req["user"];
        } else if (isset($req["user"]))
            self::apply_user_parts($req, Text::split_name($req["user"], true));

        // extract email, first, last
        $first = @$req["firstName"];
        $last = @$req["lastName"];
        $email = trim((string) @$req["email"]);
        $lemail = strtolower($email);
        $special = null;
        if ($lemail)
            $special = $lemail;
        else if (!$first && $last && strpos(trim($last), " ") === false)
            $special = trim(strtolower($last));
        if ($special === "all")
            $special = "any";

        // check missing contact
        if (!$first && !$last && !$lemail) {
            if ($assigner->allow_special_contact("missing"))
                return array(null);
            else
                return $this->error($csv->lineno(), "User missing.");
        }

        // check special: "pc", "me", PC tag, "none", "any", "external"
        if ($special && !$first && (!$lemail || !$last)) {
            $ret = ContactSearch::lookup_special($special, $this->contact->contactId);
            if ($ret !== false)
                return $ret;
        }
        if ($special === "none" || $special === "any") {
            if (!$assigner->allow_special_contact($special))
                return $this->error($csv->lineno(), "“{$special}” not allowed here");
            return array((object) array("roles" => 0, "contactId" => null, "email" => $special, "sorter" => ""));
        }
        if (($special === "ext" || $special === "external")
            && $assigner->contact_set() === "reviewers") {
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
        $cset = $assigner->contact_set();
        if ($cset === "pc")
            $cset = pcMembers();
        else if ($cset === "reviewers")
            $cset = $this->reviewer_set();
        if ($cset) {
            if ($first && $last)
                $text = "$last, $first <$email>";
            else if ($first || $last)
                $text = "$last$first <$email>";
            else
                $text = "<$email>";
            $ret = ContactSearch::lookup_cset($text, $cset);
            if (count($ret) == 1)
                return $ret;
            if (count($ret) == 0)
                $this->error($csv->lineno(), "No user matches “" . self::req_user_html($req) . "”.");
            else
                $this->error($csv->lineno(), "“" . self::req_user_html($req) . "” matches more than one user, use a full email address to disambiguate.");
            return false;
        }

        // create contact
        if (!$email)
            return $this->error($csv->lineno(), "Missing email address");
        $contact = $this->cmap->make_email($email);
        if ($contact->contactId < 0) {
            if (!validate_email($email))
                return $this->error($csv->lineno(), "Email address “" . htmlspecialchars($email) . "” is invalid.");
            if (!isset($contact->firstName) && @$req["firstName"])
                $contact->firstName = $req["firstName"];
            if (!isset($contact->lastName) && @$req["lastName"])
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
                            "firstName", "firstname", "lastName", "lastname");
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
        if (!$has_action && !@$this->astate->defaults["action"])
            return $this->error($csv->lineno(), "“assignment” column missing");
        if (array_search("paper", $csv->header()) === false)
            return $this->error($csv->lineno(), "“paper” column missing");
        if (!isset($this->astate->defaults["action"]))
            $this->astate->defaults["action"] = "<missing>";
        return true;
    }

    function parse($text, $filename = null, $defaults = null, $alertf = null) {
        global $Conf;
        $this->filename = $filename;
        $this->astate->defaults = $defaults ? : array();
        $searches = array();

        $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
        $csv->set_comment_chars("%#");
        if (!($req = $csv->next()))
            return $this->error($csv->lineno(), "empty file");
        if (!$this->install_csv_header($csv, $req))
            return false;

        // parse file
        while (($req = $csv->next()) !== false) {
            $this->astate->lineno = $csv->lineno();
            if ($csv->lineno() % 100 == 0) {
                if ($alertf)
                    call_user_func($alertf, $this, $csv->lineno(), $req);
                set_time_limit(30);
            }

            // parse paper
            $pfield = @trim($req["paper"]);
            if ($pfield !== "" && ctype_digit($pfield))
                $pids = array(intval($pfield));
            else if ($pfield !== "") {
                if (!($pids = @$searches[$pfield])) {
                    $search = new PaperSearch($this->contact, $pfield);
                    $pids = $searches[$pfield] = $search->paperList();
                    foreach ($search->warnings as $w)
                        $this->error($csv->lineno(), $w);
                }
                if (!count($pids)) {
                    $this->error($csv->lineno(), "No papers match “" . htmlspecialchars($pfield) . "”");
                    continue;
                }
            } else {
                $this->error($csv->lineno(), "Bad paper column");
                continue;
            }

            // fetch missing papers
            $fetch_pids = array();
            foreach ($pids as $p)
                if (!isset($this->astate->prows[$p]))
                    $fetch_pids[] = $p;
            if (count($fetch_pids)) {
                $q = $Conf->paperQuery($this->contact, array("paperId" => $fetch_pids));
                $result = Dbl::qe_raw($q);
                while ($result && ($prow = PaperInfo::fetch($result, $this->contact)))
                    $this->astate->prows[$prow->paperId] = $prow;
                Dbl::free($result);
            }

            // check papers
            $npids = array();
            foreach ($pids as $p) {
                $prow = @$this->astate->prows[$p];
                if ($prow && ($prow->timeSubmitted > 0 || $this->override))
                    $npids[] = $p;
                else if (!$prow)
                    $this->error($csv->lineno(), "Paper $p does not exist");
                else if ($prow->timeWithdrawn > 0)
                    $this->error($csv->lineno(), "Paper $p has been withdrawn");
                else
                    $this->error($csv->lineno(), "Paper $p was never submitted");
            }

            // check action
            if (($action = @$req["assignment"]) === null
                && ($action = @$req["action"]) === null
                && ($action = @$req["type"]) === null)
                $action = $this->astate->defaults["action"];
            $action = strtolower(trim($action));
            if (!($assigner = Assigner::find($action))) {
                $this->error($csv->lineno(), "Unknown action “" . htmlspecialchars($action) . "”");
                continue;
            } else if (!$assigner->allow($this->contact)) {
                $this->error($csv->lineno(), "Permission error");
                continue;
            }

            // clean user parts
            $contacts = $this->lookup_users($req, $assigner, $csv);
            if ($contacts === false)
                continue;

            // check conflicts and perform assignment
            foreach ($npids as $p)
                foreach ($contacts as $contact) {
                    if ($contact && @$contact->contactId > 0 && !$this->override
                        && $this->astate->prows[$p]->has_conflict($contact)
                        && !$assigner->allow_special_contact("conflict"))
                        $this->error($csv->lineno(), Text::user_html_nolink($contact) . " has a conflict with paper #$p");
                    else if (($err = $assigner->apply($p, $contact, $req, $this->astate)))
                        $this->error($csv->lineno(), $err);
                }
        }
        if ($alertf)
            call_user_func($alertf, $this, $csv->lineno(), false);

        // create assigners for difference
        foreach ($this->astate->diff() as $pid => $difflist)
            foreach ($difflist as $item) {
                $assigner = Assigner::find($item["type"]);
                try {
                    if (($a = $assigner->realize($item, $this->cmap, $this->astate)))
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

    function echo_unparse_display($papersel = null) {
        if (!$papersel) {
            $papersel = array();
            foreach ($this->assigners as $assigner)
                $papersel[$assigner->pid] = true;
            $papersel = array_keys($papersel);
        }

        $nrev = self::count_reviews();
        $nrev->pset = self::count_reviews($papersel);
        $this->set_my_conflicts();
        $countbycid = array();

        $bypaper = array();
        foreach ($this->assigners as $assigner)
            if (($text = $assigner->unparse_display())) {
                $c = $assigner->contact;
                if ($c && !isset($c->sorter))
                    Contact::set_sorter($c);
                arrayappend($bypaper[$assigner->pid], (object)
                            array("text" => $text,
                                  "sorter" => $c ? $c->sorter : $text));
                $assigner->account($countbycid, $nrev);
            }

        AutoassignmentPaperColumn::$header = "Proposed assignment";
        AutoassignmentPaperColumn::$info = array();
        PaperColumn::register(new AutoassignmentPaperColumn);
        foreach ($bypaper as $pid => $list) {
            uasort($list, "Contact::compare");
            $t = "";
            foreach ($list as $x)
                $t .= ($t ? ", " : "") . '<span class="nowrap">'
                    . $x->text . '</span>';
            if (isset($this->my_conflicts[$pid])) {
                if ($this->my_conflicts[$pid] !== true)
                    $t = '<em>Hidden for conflict</em>';
                else
                    $t = PaperList::wrapChairConflict($t);
            }
            AutoassignmentPaperColumn::$info[$pid] = $t;
        }

        ksort(AutoassignmentPaperColumn::$info);
        $papers = join(" ", array_keys(AutoassignmentPaperColumn::$info));
        $search = new PaperSearch($this->contact,
                                  array("t" => defval($_REQUEST, "t", "s"),
                                        "q" => $papers !== "" ? $papers : "NONE"));
        $plist = new PaperList($search);
        $plist->display .= " reviewers ";
        echo $plist->text("reviewers");

        echo '<div class="g"></div>';
        echo "<h3>Assignment summary</h3>\n";
        echo '<table class="pctb"><tr><td class="pctbcolleft"><table>';
        $pcdesc = array();
        foreach (pcMembers() as $cid => $pc) {
            $nnew = @+$countbycid[$cid];
            $color = TagInfo::color_classes($pc->all_contact_tags());
            $color = ($color ? ' class="' . $color . '"' : "");
            $c = "<tr$color>" . '<td class="pctbname pctbl">'
                . Text::name_html($pc)
                . ": " . plural($nnew, "assignment")
                . "</td></tr><tr$color>" . '<td class="pctbnrev pctbl">'
                . self::review_count_report($nrev, $pc, $nnew ? "After assignment:&nbsp;" : "");
            $pcdesc[] = $c . "</td></tr>\n";
        }
        $n = intval((count($pcdesc) + 2) / 3);
        for ($i = 0; $i < count($pcdesc); $i++) {
            if (($i % $n) == 0 && $i)
                echo '</table></td><td class="pctbcolmid"><table>';
            echo $pcdesc[$i];
        }
        echo "</table></td></tr></table>\n";
    }

    function is_empty() {
        return count($this->assigners) == 0;
    }

    function execute($report_errors = false) {
        global $Conf, $Now;
        if (count($this->errors_) || !count($this->assigners)) {
            if ($report_errors && count($this->errors_))
                $this->report_errors();
            else if ($report_errors)
                $Conf->warnMsg("Nothing to assign.");
            return false;
        }

        // mark activity now to avoid DB errors later
        $this->contact->mark_activity();

        // create new contacts outside the lock
        $locks = array("ContactInfo" => "read", "Paper" => "read", "PaperConflict" => "read");
        $Conf->save_logs(true);
        foreach ($this->assigners as $assigner) {
            if ($assigner->contact && $assigner->contact->contactId < 0) {
                $assigner->contact = $this->cmap->register_contact($assigner->contact);
                $assigner->cid = $assigner->contact->contactId;
            }
            $assigner->add_locks($locks);
        }

        // execute assignments
        $tables = array();
        foreach ($locks as $t => $type)
            $tables[] = "$t $type";
        Dbl::qe("lock tables " . join(", ", $tables));

        foreach ($this->assigners as $assigner)
            $assigner->execute($this->contact);

        Dbl::qe("unlock tables");
        $Conf->save_logs(false);

        // confirmation message
        if ($Conf->setting("pcrev_assigntime") == $Now)
            $Conf->confirmMsg("Assignments saved! You may want to <a href=\"" . hoturl("mail", "template=newpcrev") . "\">send mail about the new assignments</a>.");
        else
            $Conf->confirmMsg("Assignments saved!");

        // clean up
        $Conf->updateRevTokensSetting(false);
        $Conf->update_paperlead_setting();
        return true;
    }

    static function count_reviews($papers = null) {
        $nrev = (object) array("any" => array(), "pri" => array(), "sec" => array());
        foreach (pcMembers() as $id => $pc)
            $nrev->any[$id] = $nrev->pri[$id] = $nrev->sec[$id] = 0;

        $q = "select c.contactId, group_concat(r.reviewType separator '')
                from ContactInfo c
                left join PaperReview r on (r.contactId=c.contactId)\n\t\t";
        if (!$papers)
            $q .= "left join Paper p on (p.paperId=r.paperId)
                where (p.paperId is null or p.timeWithdrawn<=0)";
        else {
            $q .= "where r.paperId" . sql_in_numeric_set($papers);
            $nrev->papers = $papers;
        }
        $result = Dbl::qe($q . " and (c.roles&" . Contact::ROLE_PC . ")!=0 group by c.contactId");
        while (($row = edb_row($result))) {
            $nrev->any[$row[0]] = strlen($row[1]);
            $nrev->pri[$row[0]] = preg_match_all("|" . REVIEW_PRIMARY . "|", $row[1], $matches);
            $nrev->sec[$row[0]] = preg_match_all("|" . REVIEW_SECONDARY . "|", $row[1], $matches);
        }
        Dbl::free($result);
        return $nrev;
    }

    private static function _review_count_link($count, $word, $pl, $prefix,
                                               $pc, $suffix) {
        $word = $pl ? plural($count, $word) : $count . "&nbsp;" . $word;
        if ($count == 0)
            return $word;
        return '<a class="qq" href="' . hoturl("search", "q=" . urlencode("$prefix:$pc->email$suffix"))
            . '">' . $word . "</a>";
    }

    private static function _review_count_report_one($nrev, $pc, $xq) {
        $na = defval($nrev->any, $pc->contactId, 0);
        $np = defval($nrev->pri, $pc->contactId, 0);
        $ns = defval($nrev->sec, $pc->contactId, 0);
        $t = self::_review_count_link($na, "review", true, "re", $pc, $xq);
        $x = array();
        if ($np != $na)
            $x[] = self::_review_count_link($np, "primary", false, "pri", $pc, $xq);
        if ($ns != 0 && $ns != $na && $np + $ns != $na)
            $x[] = self::_review_count_link($np, "secondary", false, "sec", $pc, $xq);
        if (count($x))
            $t .= " (" . join(", ", $x) . ")";
        return $t;
    }

    static function review_count_report($nrev, $pc, $prefix) {
        $row1 = self::_review_count_report_one($nrev, $pc, "");
        if (defval($nrev->pset->any, $pc->contactId, 0) != defval($nrev->any, $pc->contactId, 0)) {
            $row2 = "<span class=\"dim\">$row1 total</span>";
            $row1 = self::_review_count_report_one($nrev->pset, $pc, " " . join(" ", $nrev->pset->papers)) . " in selection";
        } else
            $row2 = "";
        if ($row2 != "" && $prefix)
            return "<table><tr><td>$prefix</td><td>$row1</td></tr><tr><td></td><td>$row2</td></tr></table>";
        else if ($row2 != "")
            return $row1 . "<br />" . $row2;
        else
            return $prefix . $row1;
    }
}


class AutoassignmentPaperColumn extends PaperColumn {
    static $header;
    static $info;
    public function __construct() {
        parent::__construct("autoassignment", Column::VIEW_ROW,
                            array("cssname" => "autoassignment"));
    }
    public function header($pl, $row, $ordinal) {
        return self::$header;
    }
    public function content_empty($pl, $row) {
        return !isset(self::$info[$row->paperId]);
    }
    public function content($pl, $row) {
        return self::$info[$row->paperId];
    }
}
