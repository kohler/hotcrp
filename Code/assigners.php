<?php
// assigners.php -- HotCRP helper classes for assignments
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Assigner {
    public $pid;
    public $cid;
    public $contact;
    static private $assigners = array();
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
    function allow_conflict() {
        return false;
    }
    function allow_none() {
        return false;
    }
    function account(&$countbycid, $nrev) {
        $countbycid[$this->cid] = @+$countbycid[$this->cid] + 1;
    }
    function parse($req) {
        return false;
    }
}
class ReviewAssigner extends Assigner {
    private $type;
    private $round;
    function __construct($type) {
        $this->type = $type;
    }
    function parse($req) {
        $this->round = @$req["round"];
        if ($this->round && !preg_match('/\A[a-zA-Z0-9]+\z/', $this->round))
            return "review round “" . htmlspecialchars($this->round) . "” should contain only letters and numbers";
        if ($this->type == REVIEW_EXTERNAL
            && ($this->contact->roles & Contact::ROLE_PC))
            $this->type = REVIEW_PC;
        return false;
    }
    function require_pc() {
        return $this->type > REVIEW_EXTERNAL;
    }
    function allow_conflict() {
        return $this->type == 0;
    }
    function unparse_display($pcm) {
        global $assignprefs, $Conf;
        if (!($pc = @$pcm[$this->cid]))
            return null;
        $t = Text::name_html($pc) . ' ';
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
        $delta = $this->type ? 1 : -1;
        foreach (array($nrev, $nrev->pset) as $nnrev) {
            $nnrev->any[$this->cid] += $delta;
            if ($this->type == REVIEW_PRIMARY)
                $nnrev->pri[$this->cid] += $delta;
            else if ($this->type == REVIEW_SECONDARY)
                $nnrev->sec[$this->cid] += $delta;
        }
    }
    function execute($when) {
        global $Conf, $Me;
        $result = $Conf->qe("select contactId, paperId, reviewId, reviewType, reviewModified from PaperReview where paperId=$this->pid and contactId=$this->cid");
        $Me->assignPaper($this->pid, edb_orow($result), $this->contact, $this->type, $when);
    }
}
class LeadAssigner extends Assigner {
    private $type;
    private $isadd;
    function __construct($type, $isadd) {
        $this->type = $type;
        $this->isadd = $isadd;
    }
    function allow_none() {
        return true;
    }
    function unparse_display($pcm) {
        if (!$this->cid)
            return "remove $this->type";
        if (!($pc = @$pcm[$this->cid]))
            return null;
        $t = Text::name_html($pc);
        if ($this->isadd)
            $t .= " ($this->type)";
        else
            $t = "remove $t as $this->type";
        return $t;
    }
    function execute($when) {
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
    private $isadd;
    function __construct($isadd) {
        $this->isadd = $isadd;
    }
    function allow_conflict() {
        return true;
    }
    function unparse_display($pcm) {
        global $Conf;
        if (!($pc = @$pcm[$this->cid]))
            return null;
        $t = Text::name_html($pc) . ' ';
        if ($this->isadd)
            $t .= review_type_icon(-1);
        else
            $t .= '(remove conflict)';
        return $t;
    }
    function execute($when) {
        global $Conf;
        if ($this->isadd)
            $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($this->pid,$this->cid," . CONFLICT_CHAIRMARK . ") on duplicate key update conflictType=greatest(conflictType,values(conflictType))");
        else
            $Conf->qe("delete from PaperConflict where paperId=$this->pid and contactId=$this->cid and conflictType<" . CONFLICT_AUTHOR);
    }
}

Assigner::register("primary", new ReviewAssigner(REVIEW_PRIMARY));
Assigner::register("secondary", new ReviewAssigner(REVIEW_SECONDARY));
Assigner::register("pcreview", new ReviewAssigner(REVIEW_PC));
Assigner::register("review", new ReviewAssigner(REVIEW_EXTERNAL));
Assigner::register("noreview", new ReviewAssigner(0));
Assigner::register("lead", new LeadAssigner("lead", true));
Assigner::register("nolead", new LeadAssigner("lead", false));
Assigner::register("shepherd", new LeadAssigner("shepherd", true));
Assigner::register("noshepherd", new LeadAssigner("shepherd", false));
Assigner::register("conflict", new ConflictAssigner(true));
Assigner::register("noconflict", new ConflictAssigner(false));

class AssignmentSet {
    private $assigners = array();
    private $filename;
    private $errors = array();
    private $my_conflicts = null;
    private $override;
    private $papers;
    private $conflicts;

    function __construct($override) {
        global $Conf;
        $this->override = $override;

        $this->papers = array();
        $result = $Conf->qe('select paperId, timeSubmitted, timeWithdrawn from Paper');
        while (($row = edb_row($result)))
            $this->papers[$row[0]] = ($row[1]>0 ? 1 : ($row[2]>0 ? -1 : 0));

        $this->conflicts = array();
        $result = $Conf->qe('select paperId, contactId from PaperConflict');
        while (($row = edb_row($result)))
            @($this->conflicts[$row[0]][$row[1]] = true);
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
        global $Conf, $Me;
        if ($Conf->sversion >= 51)
            $result = $Conf->qe("select p.paperId, p.managerContactId from Paper p join PaperConflict c on (c.paperId=p.paperId) where c.conflictType!=0 and c.contactId=$Me->cid");
        else
            $result = $Conf->qe("select paperId, 0 from PaperConflict where conflictType!=0 and contactId=$Me->cid");
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
        foreach ($pcm as $id => $pc)
            $pc_by_email[$pc->email] = $pc;
        $contact_by_email = null;

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
            $assigner = clone $assigner;
            $assigner->pid = $pid;

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
            else if ($email == "none") {
                if (!$assigner->allow_none()) {
                    $this->error($csv->lineno(), "“none” not allowed here");
                    continue;
                }
                $contact = (object) array("roles" => 0, "contactId" => 0, "sorter" => "");
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
                if (!$contact_by_email)
                    $contact_by_email = self::contacts_by("email");
                if (!$email) {
                    $this->error($csv->lineno(), "missing email address");
                    continue;
                } else if (($contact = $contact_by_email[$email]))
                    /* ok */;
                else if (!validateEmail($email)) {
                    $this->error($csv->lineno(), "email address “" . htmlspecialchars($email) . "” is invalid");
                    continue;
                } else
                    $contact = (object) array("roles" => 0, "contactId" => -1, "email" => $email, "firstName" => @$req["firstName"], "lastName" => @$req["lastName"]);
            }
            if (@$contact->contactId && !$this->override
                && @$this->conflict[$pid][$contact->contactId]
                && !$assigner->allow_conflict()) {
                $this->error($csv->lineno(), Text::user_html_nolink($contact) . " has a conflict with paper #$pid");
                continue;
            }
            $assigner->contact = $contact;
            $assigner->cid = $contact->contactId;

            // assign other
            if (($err = $assigner->parse($req, $contact)))
                $this->error($csv->lineno(), $err);
            else
                $this->assigners[] = $assigner;
        }
    }

    function echo_unparse_display($papersel = null) {
        global $Conf, $Me;
        if (!$papersel) {
            $papersel = array();
            foreach ($this->assigners as $assigner)
                $papersel[$assigner->pid] = true;
            $papersel = array_keys($papersel);
        }

        $pcm = pcMembers();
        $nrev = self::count_reviews();
        $nrev->pset = self::count_reviews($papersel);
        $this->set_my_conflicts();
        $countbycid = array();

        $bypaper = array();
        foreach ($this->assigners as $assigner)
            if (($text = $assigner->unparse_display($pcm))) {
                $c = $assigner->contact;
                if (!isset($c->sorter))
                    $c->sorter = trim("$c->firstName $c->lastName $c->email");
                arrayappend($bypaper[$assigner->pid], (object)
                            array("text" => $text, "sorter" => $c->sorter));
                $assigner->account($countbycid, $nrev);
            }

        AutoassignmentPaperColumn::$header = "Proposed assignment";
        AutoassignmentPaperColumn::$info = array();
        PaperColumn::register(new AutoassignmentPaperColumn);
        foreach ($bypaper as $pid => $list) {
            uasort($list, "_sort_pcMember");
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
        $search = new PaperSearch($Me,
                                  array("t" => defval($_REQUEST, "t", "s"),
                                        "q" => join(" ", array_keys(AutoassignmentPaperColumn::$info))));
        $plist = new PaperList($search);
        $plist->display .= " reviewers ";
        echo $plist->text("reviewers", $Me);

	echo "<div class='g'></div>";
	echo "<h3>Assignment summary</h3>\n";
	echo '<table class="pctb"><tr><td class="pctbcolleft"><table>';
	$colorizer = new Tagger;
	$pcdesc = array();
	foreach ($pcm as $cid => $pc) {
	    $nnew = @+$countbycid[$cid];
	    $color = $colorizer->color_classes($pc->contactTags);
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

    function execute() {
        global $Conf, $Now;
        if ($this->report_errors())
            return false;
        else if (!count($this->assigners)) {
            $Conf->warnMsg('Nothing to assign.');
            return false;
        }

        $Conf->qe("lock tables ContactInfo read, PCMember read, ChairAssistant read, Chair read, PaperReview write, PaperReviewRefused write, Paper write, PaperConflict write, ActionLog write, Settings write, PaperTag write");

        $pcm = pcMembers();
        $contact_by_email = array();
        foreach ($this->assigners as $assigner) {
            $c = $assigner->contact;
            if ($c->contactId < 0 && @$contact_by_email[$c->email])
                $c = $assigner->contact = $contact_by_email[$c->email];
            else if ($c->contactId < 0) {
                $cc = new Contact;
                $cc->load_by_email($c->email, array("firstName" => @$c->firstName, "lastName" => @$c->lastName), false);
                // XXX assume that never fails
                $c = $assigner->contact = $contact_by_email[$c->email] = $cc;
            }
            $assigner->contactId = $c->contactId;
            $assigner->execute($Now);
        }

        // confirmation message
        if ($Conf->sversion >= 46 && $Conf->setting("pcrev_assigntime") == $Now)
            $Conf->confirmMsg("Assignments saved! You may want to <a href=\"" . hoturl("mail", "template=newpcrev") . "\">send mail about the new assignments</a>.");
        else
            $Conf->confirmMsg("Assignments saved!");

        // clean up
        $Conf->qe("unlock tables");
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
