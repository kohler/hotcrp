<?php
// assigners.php -- HotCRP helper classes for assignments
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class AssignmentState {
    private $olds = array();
    private $news = array();
    private $loaded = array();
    private $extra = array();
    public $contact;
    public function __construct($contact) {
        $this->contact = $contact;
    }
    public function load_type($type, $loader) {
        if (!isset($this->loaded[$type])) {
            $this->loaded[$type] = $loader;
            $loader->load_state($this);
        }
    }
    public function load($x) {
        assert(isset($x["pid"]));
        @($this->olds[$x["pid"]][] = $x);
        @($this->news[$x["pid"]][] = $x);
    }
    private function pid_keys($q) {
        if (isset($q["pid"]))
            return array($q["pid"]);
        else
            return array_keys($this->news);
    }
    public function match($x, $q) {
        foreach ($q as $k => $v) {
            if ($v !== null && @$x[$k] != $v)
                return false;
        }
        return true;
    }
    public function match_keys($x, $y) {
        foreach ($x as $k => $v) {
            if ($k[0] != "_" && @$y[$k] != $v)
                return false;
        }
        return true;
    }
    private function query_remove($q, $remove) {
        $res = array();
        foreach ($this->pid_keys($q) as $pid) {
            $bypid =& $this->news[$pid];
            for ($i = 0; $i != count($bypid); ++$i)
                if ($this->match($bypid[$i], $q)) {
                    $res[] = $bypid[$i];
                    if ($remove) {
                        array_splice($bypid, $i, 1);
                        --$i;
                    }
                }
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
        assert(isset($x["pid"]));
        if (!isset($this->news[$x["pid"]]))
            $this->olds[$x["pid"]] = array();
        $this->news[$x["pid"]][] = $x;
    }
    public function diff() {
        $diff = array();
        foreach ($this->news as $pid => $newa) {
            foreach ($this->olds[$pid] as $ox) {
                for ($i = 0; $i != count($newa); ++$i)
                    if ($this->match_keys($ox, $newa[$i])) {
                        if (!$this->match($ox, $newa[$i]))
                            @($diff[$pid][] = array($ox, $newa[$i]));
                        array_splice($newa, $i, 1);
                        $i = false;
                        break;
                    }
                if ($i !== false)
                    @($diff[$pid][] = array($ox, null));
            }
            foreach ($newa as $nx)
                @($diff[$pid][] = array(null, $nx));
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
    private $byid = array();
    private $byemail = array();
    static private $next_fake_id = -10;
    static public function make_none($email = null) {
        return (object) array("contactId" => 0, "roles" => 0, "email" => $email, "sorter" => "");
    }
    public function store($c) {
        if ($c->contactId)
            $this->byid[$c->contactId] = $c;
        if ($c->email)
            $this->byemail[$c->email] = $c;
        return $c;
    }
    public function get_id($cid) {
        global $Conf;
        if (!$cid)
            return self::make_none();
        if (isset($this->byid[$cid]))
            return $this->byid[$cid];
        $result = $Conf->qe("select contactId, roles, email, firstName, lastName from ContactInfo where contactId='" . sqlq($cid) . "'");
        if (!($c = edb_orow($result)))
            $c = (object) array("contactId" => $cid, "roles" => 0, "email" => "unknown contact $cid", "sorter" => "");
        return $this->store($c);
    }
    public function get_email($email) {
        global $Conf;
        if (!$email)
            return self::make_none();
        if (isset($this->byemail[$email]))
            return $this->byemail[$email];
        $result = $Conf->qe("select contactId, roles, email, firstName, lastName from ContactInfo where email='" . sqlq($email) . "'");
        if (!($c = edb_orow($result))) {
            $c = (object) array("contactId" => self::$next_fake_id, "roles" => 0, "email" => $email, "sorter" => $email);
            self::$next_fake_id -= 1;
        }
        return $this->store($c);
    }
    public function email_registered($email) {
        return isset($this->byemail[$email]) && $this->byemail[$email]->contactId > 0;
    }
}

class Assigner {
    public $pid;
    public $contact;
    public $cid;
    static private $assigners = array();
    function __construct($pid, $contact) {
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
    private $type;
    private $round;
    private $oldtype;
    private $notify;
    function __construct($pid, $contact, $type, $round, $oldtype = 0, $notify = null) {
        parent::__construct($pid, $contact);
        $this->type = $type;
        $this->round = $round;
        $this->oldtype = $oldtype;
        $this->notify = $notify;
    }
    function contact_set() {
        if ($this->type > REVIEW_EXTERNAL)
            return "pc";
        else if ($this->type == 0)
            return "reviewers";
        else
            return false;
    }
    function allow_special_contact($cclass) {
        return $this->type == 0 && $cclass != "none";
    }
    function load_state($state) {
        global $Conf;
        $result = $Conf->qe("select paperId, contactId, reviewType, reviewRound, reviewSubmitted from PaperReview");
        while (($row = edb_row($result))) {
            $round = $Conf->round_name($row[3], false);
            $state->load(array("type" => "review", "pid" => $row[0], "cid" => $row[1],
                               "_rtype" => $row[2], "_round" => $round,
                               "_rsubmitted" => $row[4] > 0));
        }
    }
    function apply($pid, $contact, $req, $state, $defaults) {
        $rname = @$req["round"];
        if ($rname === null && $this->type > 0 && @$defaults["round"])
            $rname = $defaults["round"];
        if ($rname && strcasecmp($rname, "none") == 0)
            $rname = "";
        else if ($rname && (strcasecmp($rname, "any") != 0 || $this->type > 0)) {
            if (($rerror = Conference::round_name_error($rname)))
                return $rerror;
        } else
            $rname = null;
        $rtype = $this->type;
        if ($rtype == REVIEW_EXTERNAL && $contact->is_pc_member())
            $rtype = REVIEW_PC;
        $state->load_type("review", $this);

        // remove existing review
        $revmatch = array("type" => "review", "pid" => $pid,
                          "cid" => $contact ? $contact->contactId : null);
        if (!$rtype && @$req["round"] && $rname !== null)
            $revmatch["_round"] = $rname;
        $matches = $state->remove($revmatch);

        if ($rtype) {
            // add new review or reclassify old one
            $revmatch["_rtype"] = $rtype;
            if (count($matches) && @$req["round"] === null)
                $rname = $matches[0]["_round"];
            if ($rname !== null)
                $revmatch["_round"] = $rname;
            if (count($matches))
                $revmatch["_rsubmitted"] = $matches[0]["_rsubmitted"];
            if ($rtype == REVIEW_EXTERNAL && !count($matches)
                && @$defaults["extrev_notify"])
                $revmatch["_notify"] = $defaults["extrev_notify"];
            $state->add($revmatch);
        } else
            // do not remove submitted reviews
            foreach ($matches as $r)
                if ($r["_rsubmitted"])
                    $state->add($r);
    }
    function realize($old, $new, $cmap, $state) {
        $x = $new ? $new : $old;
        return new ReviewAssigner($x["pid"], $cmap->get_id($x["cid"]),
                                  $new ? $new["_rtype"] : 0, $x["_round"],
                                  $old ? $old["_rtype"] : 0,
                                  $new ? @$new["_notify"] : null);
    }
    function unparse_display() {
        global $assignprefs, $Conf;
        $t = Text::name_html($this->contact) . ' ';
        if ($this->type) {
            $t .= review_type_icon($this->type, true);
            if ($this->round)
                $t .= ' <span class="revround" title="Review round">'
                    . htmlspecialchars($this->round) . '</span>';
            $pref = @$assignprefs["$this->pid:$this->cid"];
            if ($pref !== "*" && $pref != 0)
                $t .= " <span class='asspref" . ($pref > 0 ? 1 : -1)
                    . "'>P" . decorateNumber($pref) . "</span>";
        } else
            $t = 'clear ' . $t . ' review';
        return $t;
    }
    function account(&$countbycid, $nrev) {
        $countbycid[$this->cid] = @+$countbycid[$this->cid] + 1;
        if ($this->cid > 0 && isset($nrev->any[$this->cid])) {
            $delta = $this->type ? 1 : -1;
            foreach (array($nrev, $nrev->pset) as $nnrev) {
                $nnrev->any[$this->cid] += ($this->type != 0) - ($this->oldtype != 0);
                $nnrev->pri[$this->cid] += ($this->type == REVIEW_PRIMARY) - ($this->oldtype == REVIEW_PRIMARY);
                $nnrev->sec[$this->cid] += ($this->type == REVIEW_SECONDARY) - ($this->oldtype == REVIEW_SECONDARY);
            }
        }
    }
    function add_locks(&$locks) {
        $locks["PaperReview"] = $locks["PaperReviewRefused"] = $locks["Settings"] = "write";
    }
    function execute($who) {
        global $Conf;
        $result = $Conf->qe("select contactId, paperId, reviewId, reviewType, reviewModified from PaperReview where paperId=$this->pid and contactId=$this->cid");
        $who->assign_paper($this->pid, edb_orow($result), $this->cid, $this->type);
        if ($this->notify) {
            $reviewer = Contact::find_by_id($this->cid);
            $prow = $Conf->paperRow(array("paperId" => $this->pid, "reviewer" => $this->cid), $reviewer);
            Mailer::send($this->notify, $prow, $reviewer);
        }
    }
}
class LeadAssigner extends Assigner {
    private $type;
    private $isadd;
    function __construct($pid, $contact, $type, $isadd) {
        parent::__construct($pid, $contact);
        $this->type = $type;
        $this->isadd = $isadd;
    }
    function allow_special_contact($cclass) {
        return !$this->isadd || $cclass == "none";
    }
    function load_state($state) {
        global $Conf;
        $result = $Conf->qe("select paperId, " . $this->type . "ContactId from Paper where " . $this->type . "ContactId!=0");
        while (($row = edb_row($result)))
            $state->load(array("type" => $this->type, "pid" => $row[0], "_cid" => $row[1]));
    }
    function apply($pid, $contact, $req, $state, $defaults) {
        $state->load_type($this->type, $this);
        $remcid = $this->isadd || !$contact->contactId ? null : $contact->contactId;
        $state->remove(array("type" => $this->type, "pid" => $pid, "_cid" => $remcid));
        if ($this->isadd && $contact->contactId)
            $state->add(array("type" => $this->type, "pid" => $pid, "_cid" => $contact->contactId));
    }
    function realize($old, $new, $cmap, $state) {
        $x = $new ? $new : $old;
        return new LeadAssigner($x["pid"], $cmap->get_id($x["_cid"]), $x["type"], !!$new);
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
        $locks["Paper"] = "write";
    }
    function execute($who) {
        global $Conf;
        if ($this->isadd)
            $Conf->qe("update Paper set " . $this->type . "ContactId=$this->cid where paperId=$this->pid");
        else if ($this->cid)
            $Conf->qe("update Paper set " . $this->type . "ContactId=0 where paperId=$this->pid and " . $this->type . "ContactId=$this->cid");
        else
            $Conf->qe("update Paper set " . $this->type . "ContactId=0 where paperId=$this->pid");
    }
}
class ConflictAssigner extends Assigner {
    private $ctype;
    function __construct($pid, $contact, $ctype) {
        parent::__construct($pid, $contact);
        $this->ctype = $ctype;
    }
    function allow_special_contact($cclass) {
        return $cclass == "conflict" || ($cclass == "any" && !$this->ctype);
    }
    function load_state($state) {
        global $Conf;
        $result = $Conf->qe("select paperId, contactId, conflictType from PaperConflict where conflictType>0");
        while (($row = edb_row($result)))
            $state->load(array("type" => "conflict", "pid" => $row[0], "cid" => $row[1], "_ctype" => $row[2]));
    }
    function apply($pid, $contact, $req, $state, $defaults) {
        $state->load_type("conflict", $this);
        $res = $state->remove(array("type" => "conflict", "pid" => $pid, "cid" => $contact->contactId));
        if (count($res) && $res[0]["_ctype"] >= CONFLICT_AUTHOR)
            $state->add($res[0]);
        else if ($this->ctype)
            $state->add(array("type" => "conflict", "pid" => $pid, "cid" => $contact->contactId, "_ctype" => $this->ctype));
    }
    function realize($old, $new, $cmap, $state) {
        $x = $new ? $new : $old;
        return new ConflictAssigner($x["pid"], $cmap->get_id($x["cid"]), $new ? $new["_ctype"] : 0);
    }
    function unparse_display() {
        global $Conf;
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
        global $Conf;
        if ($this->ctype)
            $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($this->pid,$this->cid,$this->ctype) on duplicate key update conflictType=values(conflictType)");
        else
            $Conf->qe("delete from PaperConflict where paperId=$this->pid and contactId=$this->cid");
    }
}
class TagAssigner extends Assigner {
    const TYPE = "tag";
    private $isadd;
    private $tag;
    private $index;
    private $tagger;
    function __construct($pid, $isadd, $tag, $index, $tagger = null) {
        parent::__construct($pid, null);
        $this->isadd = $isadd;
        $this->tag = $tag;
        $this->index = $index;
        $this->tagger = $tagger;
    }
    function allow_special_contact($cclass) {
        return true;
    }
    function load_state($state) {
        global $Conf;
        $result = $Conf->qe("select paperId, tag, tagIndex from PaperTag");
        while (($row = edb_row($result)))
            $state->load(array("type" => self::TYPE, "pid" => $row[0], "tag" => $row[1], "_index" => $row[2]));
    }
    function apply($pid, $contact, $req, $state, $defaults) {
        $state->load_type(self::TYPE, $this);
        if (!($tagger = $state->extra("tagger"))) {
            $tagger = new Tagger($state->contact);
            $state->set_extra("tagger", $tagger);
        }
        if (!($tag = @$req["tag"]))
            return "tag missing";
        else if (!($tag = $tagger->check($tag)))
            return $tagger->error_html;
        else if (!$state->contact->privChair
                 && $tagger->is_chair($tag))
            return "Tag “" . htmlspecialchars($tag) . "” can only be changed by the chair.";
        // index parsing
        $index = @$req["index"];
        if ($index === null)
            $index = @$req["value"];
        if (is_string($index))
            $index = trim(strtolower($index));
        if ($index === "clear")
            $index = "none";
        if ($index === "" || (!$this->isadd && ($index === "any" || $index === "all" || $index === "none")))
            $index = null;
        if ($index !== null && $index !== "none" && ($index = cvtint($index, null)) === null)
            return "Index “" . htmlspecialchars($req["index"]) . "” should be an integer.";
        // save assignment
        if ($this->isadd === "set" && !$state->set_extra("tag.$tag", true))
            $state->remove(array("type" => self::TYPE, "tag" => $tag));
        $state->remove(array("type" => self::TYPE, "pid" => $pid, "tag" => $tag, "_index" => ($this->isadd ? null : $index)));
        if ($this->isadd && $index !== "none")
            $state->add(array("type" => self::TYPE, "pid" => $pid, "tag" => $tag, "_index" => ($index ? $index : 0)));
    }
    function realize($old, $new, $cmap, $state) {
        $x = $new ? $new : $old;
        return new TagAssigner($x["pid"], true, $x["tag"], $new ? $x["_index"] : null,
                               $state->extra("tagger"));
    }
    function unparse_display() {
        global $Conf;
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
        global $Conf;
        if ($this->index === null)
            $this->tagger->save($this->pid, $this->tag, "d");
        else
            $this->tagger->save($this->pid, $this->tag . "#" . $this->index, "a");
    }
}
class PreferenceAssigner extends Assigner {
    const TYPE = "pref";
    private $pref;
    private $exp;
    function __construct($pid, $contact, $pref, $exp) {
        parent::__construct($pid, $contact);
        $this->pref = $pref;
        $this->exp = $exp;
    }
    function allow_special_contact($cclass) {
        return $cclass == "conflict";
    }
    function load_state($state) {
        global $Conf;
        $result = $Conf->qe("select paperId, contactId, preference, expertise from PaperReviewPreference");
        while (($row = edb_row($result)))
            $state->load(array("type" => self::TYPE, "pid" => $row[0], "cid" => $row[1], "_pref" => $row[2], "_exp" => $row[3]));
    }
    function apply($pid, $contact, $req, $state, $defaults) {
        $state->load_type(self::TYPE, $this);

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

        $state->remove(array("type" => self::TYPE, "pid" => $pid, "cid" => $contact->contactId ? : null));
        if ($ppref[0] || $ppref[1] !== null)
            $state->add(array("type" => self::TYPE, "pid" => $pid, "cid" => $contact->contactId ? : null, "_pref" => $ppref[0], "_exp" => $ppref[1]));
    }
    function realize($old, $new, $cmap, $state) {
        $x = $new ? $new : $old;
        return new PreferenceAssigner($x["pid"], $cmap->get_id($x["cid"]), $new ? $new["_pref"] : 0, $new ? $new["_exp"] : null);
    }
    function unparse_display() {
        if (!$this->cid)
            return "remove all preferences";
        return Text::name_html($this->contact) . " " . unparse_preference_span(array($this->pref, $this->exp));
    }
    function add_locks(&$locks) {
        $locks["PaperReviewPreference"] = "write";
    }
    function execute($who) {
        global $Conf;
        if (!$this->pref && $this->exp === null)
            Dbl::qe("delete from PaperReviewPreference where paperId=? and contactId=?", $this->pid, $this->cid);
        else
            Dbl::qe("insert into PaperReviewPreference
                set paperId=?, contactId=?, preference=?, expertise=?
                on duplicate key update preference=values(preference), expertise=values(expertise)",
                    $this->pid, $this->cid, $this->pref, $this->exp);
    }
}

Assigner::register("primary", new ReviewAssigner(0, null, REVIEW_PRIMARY, ""));
Assigner::register("secondary", new ReviewAssigner(0, null, REVIEW_SECONDARY, ""));
Assigner::register("pcreview", new ReviewAssigner(0, null, REVIEW_PC, ""));
Assigner::register("review", new ReviewAssigner(0, null, REVIEW_EXTERNAL, ""));
Assigner::register("extreview", new ReviewAssigner(0, null, REVIEW_EXTERNAL, ""));
Assigner::register("ext", new ReviewAssigner(0, null, REVIEW_EXTERNAL, ""));
Assigner::register("noreview", new ReviewAssigner(0, null, 0, ""));
Assigner::register("clearreview", new ReviewAssigner(0, null, 0, ""));
Assigner::register("lead", new LeadAssigner(0, null, "lead", true));
Assigner::register("nolead", new LeadAssigner(0, null, "lead", false));
Assigner::register("clearlead", new LeadAssigner(0, null, "lead", false));
Assigner::register("shepherd", new LeadAssigner(0, null, "shepherd", true));
Assigner::register("noshepherd", new LeadAssigner(0, null, "shepherd", false));
Assigner::register("clearshepherd", new LeadAssigner(0, null, "shepherd", false));
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
    private $filename;
    private $errors = array();
    private $my_conflicts = null;
    private $contact;
    private $override;
    private $papers;
    private $conflicts;
    private $astate;
    private $cmap;
    private $reviewer_set = false;

    function __construct($contact, $override) {
        global $Conf;
        $this->contact = $contact;
        $this->override = $override;
        $this->astate = new AssignmentState($contact);
        $this->cmap = new AssignerContacts;

        $this->papers = array();
        $result = $Conf->qe("select paperId, timeSubmitted, timeWithdrawn from Paper");
        while (($row = edb_row($result)))
            $this->papers[$row[0]] = ($row[1]>0 ? 1 : ($row[2]>0 ? -1 : 0));

        $this->conflicts = array();
        $this->astate->load_type("conflict", Assigner::find("conflict"));
        foreach ($this->astate->query(array("type" => "conflict")) as $x)
            @($this->conflicts[$x["pid"]][$x["cid"]] = true);
    }

    private function error($lineno, $message) {
        if ($this->filename)
            $this->errors[] = '<span class="lineno">'
                . htmlspecialchars($this->filename)
                . ':' . $lineno . ':</span> ' . $message;
        else
            $this->errors[] = $message;
        return false;
    }

    private static function req_user_html($req) {
        return Text::user_html_nolink(@$req["firstName"], @$req["lastName"], @$req["email"]);
    }

    private static function contacts_by($what) {
        global $Conf;
        $cb = array();
        foreach (edb_orows($Conf->qe("select contactId, email, firstName, lastName, roles from ContactInfo")) as $c)
            $cb[$c->$what] = $c;
        return $cb;
    }

    private function set_my_conflicts() {
        global $Conf;
        $this->my_conflicts = array();
        $result = $Conf->qe("select Paper.paperId, managerContactId from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId) where conflictType>0 and PaperConflict.contactId=" . $this->contact->contactId);
        while (($row = edb_row($result)))
            $this->my_conflicts[$row[0]] = ($row[1] ? $row[1] : true);
    }

    private static function apply_user_parts(&$req, $a) {
        foreach (array("firstName", "lastName", "email") as $i => $k)
            if (!@$req[$k] && @$a[$i])
                $req[$k] = $a[$i];
    }

    private function reviewer_set() {
        global $Conf;
        if ($this->reviewer_set === false) {
            $result = $Conf->qe("select ContactInfo.contactId, firstName, lastName, email, roles from ContactInfo left join PaperReview using (contactId) group by ContactInfo.contactId");
            $this->reviewer_set = array();
            while (($row = edb_orow($result)))
                $this->reviewer_set[$row->contactId] = $row;
        }
        return $this->reviewer_set;
    }

    private function lookup_users(&$req, $pc_by_email, $assigner, $csv) {
        global $Conf;

        // move all usable identification data to email, firstName, lastName
        foreach (array("first" => "firstName", "last" => "lastName",
                       "firstname" => "firstName", "lastname" => "lastName")
                 as $k1 => $k2)
            if (isset($req[$k1]) && !isset($req[$k2]))
                $req[$k2] = $req[$k1];
        if (isset($req["name"]))
            self::apply_user_parts($req, Text::split_name($req["name"]));
        if (isset($req["user"]) && strpos($req["user"], " ") === false) {
            if (!@$req["email"])
                $req["email"] = $req["user"];
        } else if (isset($req["user"]))
            self::apply_user_parts($req, Text::split_name($req["user"], true));

        // always have an email
        $first = @$req["firstName"];
        $last = @$req["lastName"];
        $email = trim(defval($req, "email", ""));
        $lemail = strtolower($email);
        if ($lemail)
            $special = $lemail;
        else if (!$first && $last && strpos(trim($last), " ") === false)
            $special = trim(strtolower($last));
        else
            $special = null;
        if ($special === "all")
            $special = "any";

        // check for precise email match on PC (common case)
        if ($email && ($contact = @$pc_by_email[$lemail]))
            return array($contact);
        // check for PC tag
        if (!$first && $special && (!$lemail || !$last)) {
            $tags = pcTags();
            $tag = $special[0] == "#" ? substr($special, 1) : $special;
            if (isset($tags[$tag]) || $tag === "pc") {
                $result = array();
                foreach ($pc_by_email as $pc)
                    if ($tag === "pc" || $pc->has_tag($tag))
                        $result[] = $pc;
                return $result;
            }
        }
        // perhaps missing contact is OK
        if (!$lemail && !$first && !$last && $assigner->allow_special_contact("missing"))
            return array(null);
        // perhaps "none" or "any" is OK
        if ($special === "none" || $special === "any") {
            if (!$assigner->allow_special_contact($special)) {
                $this->error($csv->lineno(), "“{$special}” not allowed here");
                return false;
            }
            return array((object) array("roles" => 0, "contactId" => null, "email" => $special, "sorter" => ""));
        } else if (($special === "ext" || $special === "external")
                   && $assigner->contact_set() === "reviewers") {
            $result = array();
            foreach ($this->reviewer_set() as $u)
                if (!$u->is_pc_member())
                    $result[] = $u;
            return $result;
        }
        // check PC list
        $cset = $assigner->contact_set();
        if ($cset === "pc")
            $cset = pcMembers();
        else if ($cset === "reviewers")
            $cset = $this->reviewer_set();
        if ($cset) {
            $cid = matchContact($cset, $first, $last, $email);
            if ($cid == -2)
                $this->error($csv->lineno(), "no user matches “" . self::req_user_html($req) . "”");
            else if ($cid <= 0)
                $this->error($csv->lineno(), "“" . self::req_user_html($req) . "” matches more than one user, use an email address to disambiguate");
            if ($cid <= 0)
                return false;
            return array($cset[$cid]);
        }
        // create contact
        if (!$email) {
            $this->error($csv->lineno(), "missing email address");
            return false;
        }
        $contact = $this->cmap->get_email($email);
        if ($contact->contactId < 0) {
            if (!validate_email($email)) {
                $this->error($csv->lineno(), "email address “" . htmlspecialchars($email) . "” is invalid");
                return false;
            }
            if (!isset($contact->firstName) && @$req["firstName"])
                $contact->firstName = $req["firstName"];
            if (!isset($contact->lastName) && @$req["lastName"])
                $contact->lastName = $req["lastName"];
        }
        return array($contact);
    }

    function parse($text, $filename = null, $defaults = null) {
        if ($defaults === null)
            $defaults = array();
        $this->filename = $filename;

        $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
        $csv->set_comment_chars("%#");
        if (!($req = $csv->next()))
            return $this->error($csv->lineno(), "empty file");

        // check for header
        if (array_search("paper", $req) === false
            && (($i = array_search("pid", $req)) !== false
                || ($i = array_search("paperId", $req)) !== false))
            $req[$i] = "paper";
        if (array_search("action", $req) !== false
            || array_search("assignment", $req) !== false
            || array_search("paper", $req) !== false)
            $csv->set_header($req);
        else {
            if (count($req) == 3
                && (!$req[2] || strpos($req[2], "@") !== false))
                $csv->set_header(array("paper", "name", "email"));
            else if (count($req) == 2)
                $csv->set_header(array("paper", "user"));
            else
                $csv->set_header(array("paper", "action", "user", "round"));
            $csv->unshift($req);
        }
        $has_action = array_search("action", $csv->header()) !== false
            || array_search("assignment", $csv->header()) !== false;
        if (!$has_action && array_search("tag", $csv->header()) !== false)
            $defaults["action"] = "tag";
        if (!$has_action && array_search("preference", $csv->header()) !== false)
            $defaults["action"] = "preference";
        if (!$has_action && !@$defaults["action"])
            return $this->error($csv->lineno(), "“assignment” column missing");
        if (array_search("paper", $csv->header()) === false)
            return $this->error($csv->lineno(), "“paper” column missing");
        if (!isset($defaults["action"]))
            $defaults["action"] = "<missing>";

        // set up PC mappings
        $pcm = pcMembers();
        $pc_by_email = array();
        foreach ($pcm as $pc) {
            $pc_by_email[strtolower($pc->email)] = $pc;
            $this->cmap->store($pc);
        }
        $searches = array();

        // parse file
        while (($req = $csv->next()) !== false) {
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
                    $this->error($csv->lineno(), "no papers match “" . htmlspecialchars($pfield) . "”");
                    continue;
                }
            } else {
                $this->error($csv->lineno(), "bad paper column");
                continue;
            }

            // check papers
            $npids = array();
            foreach ($pids as $p) {
                if (isset($this->papers[$p])
                    && ($this->papers[$p] > 0 || $this->override))
                    $npids[] = $p;
                else if (!isset($this->papers[$p]))
                    $this->error($csv->lineno(), "paper $p does not exist");
                else
                    $this->error($csv->lineno(), $this->papers[$p] < 0 ? "paper $p has been withdrawn" : "paper $p was never submitted");
            }

            // check action
            if (($action = @$req["assignment"]) === null
                && ($action = @$req["action"]) === null)
                $action = $defaults["action"];
            $action = strtolower(trim($action));
            if (!($assigner = Assigner::find($action))) {
                $this->error($csv->lineno(), "unknown action “" . htmlspecialchars($action) . "”");
                continue;
            }

            // clean user parts
            $contacts = $this->lookup_users($req, $pc_by_email, $assigner, $csv);
            if ($contacts === false)
                continue;

            // check conflicts and perform assignment
            foreach ($npids as $p)
                foreach ($contacts as $contact) {
                    if ($contact && @$contact->contactId > 0 && !$this->override
                        && @$this->conflict[$p][$contact->contactId]
                        && !$assigner->allow_special_contact("conflict"))
                        $this->error($csv->lineno(), Text::user_html_nolink($contact) . " has a conflict with paper #$p");
                    else if (($err = $assigner->apply($p, $contact, $req, $this->astate, $defaults)))
                        $this->error($csv->lineno(), $err);
                }
        }

        // create assigners for difference
        foreach ($this->astate->diff() as $pid => $difflist)
            foreach ($difflist as $diff) {
                $x = $diff[1] ? $diff[1] : $diff[0];
                $assigner = Assigner::find($x["type"]);
                $this->assigners[] = $assigner->realize($diff[0], $diff[1], $this->cmap, $this->astate);
            }
    }

    function echo_unparse_display($papersel = null) {
        global $Conf;
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
        $papers = join(" ", array_keys(AutoAssignmentPaperColumn::$info));
        $search = new PaperSearch($this->contact,
                                  array("t" => defval($_REQUEST, "t", "s"),
                                        "q" => $papers !== "" ? $papers : "NONE"));
        $plist = new PaperList($search);
        $plist->display .= " reviewers ";
        echo $plist->text("reviewers");

        echo "<div class='g'></div>";
        echo "<h3>Assignment summary</h3>\n";
        echo '<table class="pctb"><tr><td class="pctbcolleft"><table>';
        $colorizer = new Tagger;
        $pcdesc = array();
        foreach (pcMembers() as $cid => $pc) {
            $nnew = @+$countbycid[$cid];
            $color = $colorizer->color_classes($pc->all_contact_tags());
            $color = ($color ? ' class="' . $color . '"' : "");
            $c = "<tr$color><td class='pctbname pctbl'>"
                . Text::name_html($pc)
                . ": " . plural($nnew, "assignment")
                . "</td></tr><tr$color><td class='pctbnrev pctbl'>"
                . self::review_count_report($nrev, $pc, $nnew ? "After assignment:&nbsp;" : "");
            $pcdesc[] = $c . "</td></tr>\n";
        }
        $n = intval((count($pcdesc) + 2) / 3);
        for ($i = 0; $i < count($pcdesc); $i++) {
            if (($i % $n) == 0 && $i)
                echo "</table></td><td class='pctbcolmid'><table>";
            echo $pcdesc[$i];
        }
        echo "</table></td></tr></table>\n";
    }

    function report_errors() {
        global $Conf;
        if (count($this->errors)) {
            $Conf->errorMsg('Assignment errors: <div class="parseerr"><p>' . join("</p>\n<p>", $this->errors) . '</p></div> Please correct these errors and try again.');
            return true;
        } else
            return false;
    }

    function is_empty() {
        return count($this->assigners) == 0;
    }

    function execute() {
        global $Conf, $Now;
        if ($this->report_errors())
            return false;
        else if (!count($this->assigners)) {
            $Conf->warnMsg("Nothing to assign.");
            return false;
        }

        // create new contacts outside the lock
        $locks = array("ContactInfo" => "read", "PCMember" => "read",
                       "ChairAssistant" => "read", "Chair" => "read",
                       "ActionLog" => "write", "Paper" => "read",
                       "PaperConflict" => "read");
        foreach ($this->assigners as $assigner) {
            if ($assigner->contact->contactId < 0) {
                $c = $this->cmap->get_email($assigner->contact->email);
                if ($c->contactId < 0) {
                    // XXX assume that never fails:
                    $cc = Contact::find_by_email($c->email, array("firstName" => @$c->firstName, "lastName" => @$c->lastName), false);
                    $c = $this->cmap->store($cc);
                }
                $assigner->contact = $c;
                $assigner->cid = $c->contactId;
            }
            $assigner->add_locks($locks);
        }

        // execute assignments
        $tables = array();
        foreach ($locks as $t => $type)
            $tables[] = "$t $type";
        $Conf->qe("lock tables " . join(", ", $tables));

        foreach ($this->assigners as $assigner)
            $assigner->execute($this->contact);

        $Conf->qe("unlock tables");

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
        global $Conf;
        $nrev = (object) array("any" => array(), "pri" => array(), "sec" => array());
        foreach (pcMembers() as $id => $pc)
            $nrev->any[$id] = $nrev->pri[$id] = $nrev->sec[$id] = 0;

        $q = "select pc.contactId, group_concat(r.reviewType separator '')
                from PCMember pc
                left join PaperReview r on (r.contactId=pc.contactId)\n\t\t";
        if (!$papers)
            $q .= "left join Paper p on (p.paperId=r.paperId)
                where p.paperId is null or p.timeWithdrawn<=0";
        else {
            $q .= "where r.paperId" . sql_in_numeric_set($papers);
            $nrev->papers = $papers;
        }
        $result = $Conf->qe($q . " group by pc.contactId");
        while (($row = edb_row($result))) {
            $nrev->any[$row[0]] = strlen($row[1]);
            $nrev->pri[$row[0]] = preg_match_all("|" . REVIEW_PRIMARY . "|", $row[1], $matches);
            $nrev->sec[$row[0]] = preg_match_all("|" . REVIEW_SECONDARY . "|", $row[1], $matches);
        }
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
        global $Conf;
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
