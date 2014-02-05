<?php
// assigners.php -- HotCRP helper classes for assignments
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class AssignmentState {
    private $olds = array();
    private $news = array();
    private $loaded = array();
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
    function require_pc() {
        return true;
    }
    function allow_contact_type($type) {
        return false;
    }
    function account(&$countbycid, $nrev) {
        $countbycid[$this->cid] = @+$countbycid[$this->cid] + 1;
    }
}
class ReviewAssigner extends Assigner {
    private $type;
    private $round;
    private $oldtype;
    function __construct($pid, $contact, $type, $round, $oldtype = 0) {
        parent::__construct($pid, $contact);
        $this->type = $type;
        $this->round = $round;
        $this->oldtype = $oldtype;
    }
    function require_pc() {
        return $this->type > REVIEW_EXTERNAL;
    }
    function allow_contact_type($type) {
        return $this->type == 0 && $type != "none";
    }
    function load_state($state) {
        global $Conf;
        $result = $Conf->qe("select paperId, contactId, reviewType, reviewRound from PaperReview");
        while (($row = edb_row($result))) {
            $round = "";
            if ($row[3] && isset($Conf->settings["rounds"][$row[3]]))
                $round = $Conf->settings["rounds"][$row[3]];
            $state->load(array("type" => "review", "pid" => $row[0], "cid" => $row[1],
                               "_rtype" => $row[2], "_round" => $round));
        }
    }
    function apply($pid, $contact, $req, $state) {
        $round = @$req["round"];
        if ($round && !preg_match('/\A[a-zA-Z0-9]+\z/', $round))
            return "review round “" . htmlspecialchars($round) . "” should contain only letters and numbers";
        $rtype = $this->type;
        if ($rtype == REVIEW_EXTERNAL && ($contact->roles & Contact::ROLE_PC))
            $rtype = REVIEW_PC;
        $state->load_type("review", $this);
        $r = $state->remove(array("type" => "review", "pid" => $pid, "cid" => $contact->contactId));
        if (!$round && count($r) && $r[0]["_round"])
            $round = $r[0]["_round"];
        $round = $round == "none" ? "" : $round;
        if ($rtype)
            $state->add(array("type" => "review", "pid" => $pid, "cid" => $contact->contactId,
                              "_rtype" => $rtype, "_round" => $round));
    }
    function realize($old, $new, $cmap) {
        $x = $new ? $new : $old;
        return new ReviewAssigner($x["pid"], $cmap->get_id($x["cid"]),
                                  $new ? $new["_rtype"] : 0, $x["_round"],
                                  $old ? $old["_rtype"] : 0);
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
    function execute($who, $when) {
        global $Conf;
        $result = $Conf->qe("select contactId, paperId, reviewId, reviewType, reviewModified from PaperReview where paperId=$this->pid and contactId=$this->cid");
        $who->assign_paper($this->pid, edb_orow($result), $this->cid, $this->type, $when);
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
    function allow_contact_type($type) {
        return $type == "none" || !$this->isadd;
    }
    function load_state($state) {
        global $Conf;
        $result = $Conf->qe("select paperId, " . $this->type . "ContactId from Paper where " . $this->type . "ContactId!=0");
        while (($row = edb_row($result)))
            $state->load(array("type" => $this->type, "pid" => $row[0], "_cid" => $row[1]));
    }
    function apply($pid, $contact, $req, $state) {
        $state->load_type($this->type, $this);
        $remcid = $this->isadd || !$contact->contactId ? null : $contact->contactId;
        $state->remove(array("type" => $this->type, "pid" => $pid, "_cid" => $remcid));
        if ($this->isadd && $contact->contactId)
            $state->add(array("type" => $this->type, "pid" => $pid, "_cid" => $contact->contactId));
    }
    function realize($old, $new, $cmap) {
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
    function execute($who, $when) {
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
    function allow_contact_type($type) {
        return $type == "conflict" || ($type == "any" && !$this->ctype);
    }
    function load_state($state) {
        global $Conf;
        $result = $Conf->qe("select paperId, contactId, conflictType from PaperConflict where conflictType>0");
        while (($row = edb_row($result)))
            $state->load(array("type" => "conflict", "pid" => $row[0], "cid" => $row[1], "_ctype" => $row[2]));
    }
    function apply($pid, $contact, $req, $state) {
        $state->load_type("conflict", $this);
        $res = $state->remove(array("type" => "conflict", "pid" => $pid, "cid" => $contact->contactId));
        if (count($res) && $res[0]["_ctype"] >= CONFLICT_AUTHOR)
            $state->add($res[0]);
        else if ($this->ctype)
            $state->add(array("type" => "conflict", "pid" => $pid, "cid" => $contact->contactId, "_ctype" => $this->ctype));
    }
    function realize($old, $new, $cmap) {
        $x = $new ? $new : $old;
        return new ConflictAssigner($x["pid"], $cmap->get_id($x["cid"]), $new ? $new["_ctype"] : 0);
    }
    function unparse_display() {
        global $Conf;
        $t = Text::name_html($this->contact) . ' ';
        if ($this->ctype)
            $t .= review_type_icon(-1);
        else
            $t .= '(remove conflict)';
        return $t;
    }
    function execute($who, $when) {
        global $Conf;
        if ($this->ctype)
            $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($this->pid,$this->cid,$this->ctype) on duplicate key update conflictType=values(conflictType)");
        else
            $Conf->qe("delete from PaperConflict where paperId=$this->pid and contactId=$this->cid");
    }
}

Assigner::register("primary", new ReviewAssigner(0, null, REVIEW_PRIMARY, ""));
Assigner::register("secondary", new ReviewAssigner(0, null, REVIEW_SECONDARY, ""));
Assigner::register("pcreview", new ReviewAssigner(0, null, REVIEW_PC, ""));
Assigner::register("review", new ReviewAssigner(0, null, REVIEW_EXTERNAL, ""));
Assigner::register("noreview", new ReviewAssigner(0, null, 0, ""));
Assigner::register("lead", new LeadAssigner(0, null, "lead", true));
Assigner::register("nolead", new LeadAssigner(0, null, "lead", false));
Assigner::register("shepherd", new LeadAssigner(0, null, "shepherd", true));
Assigner::register("noshepherd", new LeadAssigner(0, null, "shepherd", false));
Assigner::register("conflict", new ConflictAssigner(0, null, CONFLICT_CHAIRMARK));
Assigner::register("noconflict", new ConflictAssigner(0, null, 0));

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

    function __construct($contact, $override) {
        global $Conf;
        $this->contact = $contact;
        $this->override = $override;
        $this->astate = new AssignmentState;
        $this->cmap = new AssignerContacts;

        $this->papers = array();
        $result = $Conf->qe('select paperId, timeSubmitted, timeWithdrawn from Paper');
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
        if ($Conf->sversion >= 51)
            $result = $Conf->qe("select Paper.paperId, managerContactId from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId) where conflictType>0 and PaperConflict.contactId=" . $this->contact->contactId);
        else
            $result = $Conf->qe("select paperId, 0 from PaperConflict where conflictType>0 and contactId=" . $this->contact->contactId);
        $this->my_conflicts = array();
        while (($row = edb_row($result)))
            $this->my_conflicts[$row[0]] = ($row[1] ? $row[1] : true);
    }

    function parse($text, $filename = null, $defaults = null) {
        $this->filename = $filename;

        $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
        $csv->set_comment_chars("%#");
        if (!($req = $csv->next()))
            return $this->error($csv->lineno(), "empty file");

        // check for header
        if (array_search("action", $req) !== false
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
        if (array_search("action", $csv->header()) === false
            && (!$defaults || !@$defaults["action"]))
            return $this->error($csv->lineno(), "“action” column missing");
        if (array_search("paper", $csv->header()) === false)
            return $this->error($csv->lineno(), "“paper” column missing");

        // set up PC mappings
        $pcm = pcMembers();
        $pc_by_email = array();
        foreach ($pcm as $pc) {
            $pc_by_email[$pc->email] = $pc;
            $this->cmap->store($pc);
        }

        // parse file
        while (($req = $csv->next()) !== false) {
            // add defaults
            if ($defaults) {
                foreach ($defaults as $k => $v)
                    if (!isset($req[$k]))
                        $req[$k] = $v;
            }

            // check paper
            $pid = @trim($req["paper"]);
            if ($pid == "" || !ctype_digit($pid)) {
                $this->error($csv->lineno(), "bad paper column");
                continue;
            }
            $pid = intval($pid);
            if (!isset($this->papers[$pid])) {
                $this->error($csv->lineno(), "paper $pid does not exist");
                continue;
            } else if ($this->papers[$pid] <= 0 && !$this->override) {
                $this->error($csv->lineno(), $this->papers[$pid] < 0 ? "paper $pid has been withdrawn" : "paper $pid was never submitted");
                continue;
            }

            // check action
            if (($action = @$req["action"]) === null)
                $action = $default_action;
            $action = strtolower(trim($action));
            if (!($assigner = Assigner::find($action))) {
                $this->error($csv->lineno(), "unknown action “" . htmlspecialchars($req["action"]) . "”");
                continue;
            }

            // clean user parts
            foreach (array("first" => "firstName", "last" => "lastName")
                     as $k1 => $k2)
                if (isset($req[$k1]) && !isset($req[$k2]))
                    $req[$k2] = $req[$k1];
            if (!isset($req["email"]) && isset($req["user"])) {
                $a = Text::split_name($req["user"], true);
                foreach (array("firstName", "lastName", "email") as $i => $k)
                    if ($a[$i])
                        $req[$k] = $a[$i];
            } else if (isset($req["name"]) || isset($req["user"])) {
                $a = Text::split_name($req[isset($req["name"]) ? "name" : "user"]);
                foreach (array("firstName", "lastName") as $i => $n)
                    if ($a[$i] && !isset($req[$k]))
                        $req[$k] = $a[$i];
            }

            // check user
            $email = @trim($req["email"]);
            if ($email && ($contact = @$pc_by_email[$email]))
                /* ok */;
            else if ($email == "none" || $email == "any") {
                if (!$assigner->allow_contact_type($email)) {
                    $this->error($csv->lineno(), "“$email” not allowed here");
                    continue;
                }
                $contact = (object) array("roles" => 0, "contactId" => null, "email" => $email, "sorter" => "");
            } else if ($assigner->require_pc()) {
                $cid = matchContact($pcm, @$req["firstName"], @$req["lastName"], $email);
                if ($cid == -2)
                    $this->error($csv->lineno(), "no PC member matches “" . self::req_user_html($req) . "”");
                else if ($cid <= 0)
                    $this->error($csv->lineno(), "“" . self::req_user_html($req) . "” matches more than one PC member, give a full email address to disambiguate");
                if ($cid <= 0)
                    continue;
                $contact = $pcm[$cid];
            } else {
                if (!$email) {
                    $this->error($csv->lineno(), "missing email address");
                    continue;
                }
                $contact = $this->cmap->get_email($email);
                if ($contact->contactId < 0) {
                    if (!validateEmail($email)) {
                        $this->error($csv->lineno(), "email address “" . htmlspecialchars($email) . "” is invalid");
                        continue;
                    }
                    if (!isset($contact->firstName) && @$req["firstName"])
                        $contact->firstName = $req["firstName"];
                    if (!isset($contact->lastName) && @$req["lastName"])
                        $contact->lastName = $req["lastName"];
                }
            }
            if (@$contact->contactId > 0 && !$this->override
                && @$this->conflict[$pid][$contact->contactId]
                && !$assigner->allow_contact_type("conflict")) {
                $this->error($csv->lineno(), Text::user_html_nolink($contact) . " has a conflict with paper #$pid");
                continue;
            }

            // perform assignment
            if (($err = $assigner->apply($pid, $contact, $req, $this->astate)))
                $this->error($csv->lineno(), $err);
        }

        // create assigners for difference
        foreach ($this->astate->diff() as $pid => $difflist)
            foreach ($difflist as $diff) {
                $x = $diff[1] ? $diff[1] : $diff[0];
                $assigner = Assigner::find($x["type"]);
                $this->assigners[] = $assigner->realize($diff[0], $diff[1], $this->cmap);
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
                if (!isset($c->sorter))
                    Contact::set_sorter($c);
                arrayappend($bypaper[$assigner->pid], (object)
                            array("text" => $text, "sorter" => $c->sorter));
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
        $search = new PaperSearch($this->contact,
                                  array("t" => defval($_REQUEST, "t", "s"),
                                        "q" => join(" ", array_keys(AutoassignmentPaperColumn::$info))));
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

    function execute($when) {
        global $Conf;
        if ($this->report_errors())
            return false;
        else if (!count($this->assigners)) {
            $Conf->warnMsg('Nothing to assign.');
            return false;
        }

        // create new contacts outside the lock
        foreach ($this->assigners as $assigner)
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

        // execute assignments
        $Conf->qe("lock tables ContactInfo read, PCMember read, ChairAssistant read, Chair read, PaperReview write, PaperReviewRefused write, Paper write, PaperConflict write, ActionLog write, Settings write, PaperTag write");

        foreach ($this->assigners as $assigner)
            $assigner->execute($this->contact, $when);

        $Conf->qe("unlock tables");

        // confirmation message
        if ($Conf->sversion >= 46 && $Conf->setting("pcrev_assigntime") == $when)
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
        $result = $Conf->qe($q . " group by pc.contactId",
                            "while counting reviews");
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
