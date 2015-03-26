<?php
// hotcrpmailer.php -- HotCRP mail template manager
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class HotCRPMailer extends Mailer {

    protected $permissionContact = null;
    protected $contacts = array();

    protected $row = null;
    protected $rrow = null;
    protected $reviewNumber = "";
    protected $comment_row = null;
    protected $hideReviews = false;
    protected $newrev_since = false;

    protected $_tagger = null;
    protected $_statistics = null;
    protected $_tagless = array();
    protected $_tags = array();


    function __construct($recipient = null, $row = null, $rest = array()) {
        $this->reset($recipient, $row, $rest);
    }

    static private function make_reviewer_contact($x) {
        return (object) array("email" => @$x->reviewEmail, "firstName" => @$x->reviewFirstName, "lastName" => @$x->reviewLastName);
    }

    function reset($recipient = null, $row = null, $rest = array()) {
        global $Me, $Opt;
        parent::reset($recipient, $rest);
        $this->permissionContact = defval($rest, "permissionContact", $recipient);
        foreach (array("requester", "reviewer", "other") as $k)
            if (($v = @$rest[$k . "_contact"]))
                $this->contacts[$k] = $v;
        $this->row = $row;
        foreach (array("rrow", "reviewNumber", "comment_row", "hideReviews",
                       "newrev_since") as $k)
            $this->$k = @$rest[$k];
        if ($this->reviewNumber === null)
            $this->reviewNumber = "";
        // Infer reviewer contac from rrow/comment_row
        if (!@$this->contacts["reviewer"] && $this->rrow && @$this->rrow->reviewEmail)
            $this->contacts["reviewer"] = self::make_reviewer_contact($this->rrow);
        else if (!@$this->contacts["reviewer"] && $this->comment_row && @$this->comment_row->reviewEmail)
            $this->contacts["reviewer"] = self::make_reviewer_contact($this->comment_row);
        // Do not put passwords in email that is cc'd elsewhere
        if ((!$Me || !$Me->privChair || @$Opt["chairHidePasswords"])
            && (@$rest["cc"] || @$rest["bcc"])
            && (@$rest["sensitivity"] === null || @$rest["sensitivity"] === "display"))
            $this->sensitivity = "high";
    }


    // expansion helpers
    private function _expand_reviewer($type, $isbool) {
        global $Conf;
        if (!($c = @$this->contacts["reviewer"]))
            return false;
        if ($this->row
            && $this->rrow
            && $Conf->is_review_blind($this->rrow)
            && !@$this->permissionContact->privChair
            && (!isset($this->permissionContact->can_view_review_identity)
                || !$this->permissionContact->can_view_review_identity($this->row, $this->rrow, false))) {
            if ($isbool)
                return false;
            else if ($this->expansionType == self::EXPAND_EMAIL)
                return "<hidden>";
            else
                return "Hidden for blind review";
        }
        return $this->expand_user($c, $type);
    }

    private function tagger()  {
        if (!$this->_tagger)
            $this->_tagger = new Tagger($this->permissionContact);
        return $this->_tagger;
    }

    private function get_reviews() {
        global $Conf;
        if ($this->hideReviews)
            return "[Reviews are hidden since you have incomplete reviews of your own.]";

        $result = $Conf->qe("select PaperReview.*,
                ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
                from PaperReview
                join ContactInfo on (ContactInfo.contactId=PaperReview.contactId)
                where PaperReview.paperId=" . $this->row->paperId . " order by reviewOrdinal");
        $text = "";
        while (($row = edb_orow($result)))
            if ($row->reviewSubmitted) {
                $rf = ReviewForm::get($row);
                $text .= $rf->prettyTextForm($this->row, $row, $this->permissionContact, true) . "\n";
            }
        return $text;
    }

    private function get_comments($tag) {
        global $Conf;
        if ($this->hideReviews)
            return "";
        $crows = $this->comment_row ? array($this->comment_row) : $this->row->all_comments();
        if (!count($crows))
            return "";

        // save old au_seerev setting, and reset it so authors can see them.
        $old_au_seerev = $Conf->au_seerev_setting(AU_SEEREV_ALWAYS);

        $text = "";
        foreach ($crows as $crow)
            if ((!$tag || ($crow->commentTags && stripos($crow->commentTags, " $tag ") !== false))
                && $this->permissionContact->can_view_comment($this->row, $crow, false))
                $text .= $crow->unparse_text($this->permissionContact) . "\n";

        $Conf->au_seerev_setting($old_au_seerev);
        return $text;
    }

    private function get_new_assignments($contact) {
        global $Conf;
        $since = "";
        if ($this->newrev_since)
            $since = " and r.timeRequested>=$this->newrev_since";
        $result = $Conf->qe("select r.paperId, p.title
                from PaperReview r join Paper p using (paperId)
                where r.contactId=" . $contact->contactId . "
                and r.timeRequested>r.timeRequestNotified$since
                and r.reviewSubmitted is null and r.reviewNeedsSubmit!=0
                order by r.paperId");
        $text = "";
        while (($row = edb_row($result)))
            $text .= ($text ? "\n#" : "#") . $row[0] . " " . $row[1];
        return $text;
    }


    function infer_user_name($r, $contact) {
        // If user hasn't entered a name, try to infer it from author records
        if ($this->row && $this->row->paperId > 0) {
            $e1 = (string) @$contact->email;
            $e2 = (string) @$contact->preferredEmail;
            cleanAuthor($this->row);
            foreach ($this->row->authorTable as $au)
                if (($au[0] || $au[1]) && $au[2]
                    && (strcasecmp($au[2], $e1) == 0 || strcasecmp($au[2], $e2) == 0)) {
                    $r->firstName = $au[0];
                    $r->lastName = $au[1];
                    $r->name = trim("$au[0] $au[1]");
                    return;
                }
        }
    }

    function expandvar_generic($what, $isbool) {
        global $Conf, $Opt;
        if ($what == "%REVIEWDEADLINE%") {
            if ($this->row && @$this->row->reviewType > 0)
                $rev = ($this->row->reviewType >= REVIEW_PC ? "pc" : "ext");
            else if ($this->row && isset($this->row->roles))
                $rev = ($this->row->roles & Contact::ROLE_PCLIKE ? "pc" : "ext");
            else if ($Conf->setting("pcrev_soft") != $Conf->setting("extrev_soft")) {
                if ($isbool && ($Conf->setting("pcrev_soft") > 0) == ($Conf->setting("extrev_soft") > 0))
                    return $Conf->setting("pcrev_soft") > 0;
                else
                    return ($isbool ? null : $what);
            } else
                $rev = "ext";
            $what = "%DEADLINE(" . $rev . "rev_soft)%";
        }
        $len = strlen($what);
        if ($len > 12 && substr($what, 0, 10) == "%DEADLINE(" && substr($what, $len - 2) == ")%") {
            $inner = substr($what, 10, $len - 12);
            if ($isbool)
                return $Conf->setting($inner) > 0;
            else
                return $Conf->printableTimeSetting($inner);
        }

        if (($what == "%NUMACCEPTED%" || $what == "%NUMSUBMITTED%")
            && $this->_statistics === null) {
            $this->_statistics = array(0, 0);
            $result = $Conf->q("select outcome, count(paperId) from Paper where timeSubmitted>0 group by outcome");
            while (($row = edb_row($result))) {
                $this->_statistics[0] += $row[1];
                if ($row[0] > 0)
                    $this->_statistics[1] += $row[1];
            }
        }
        if ($what == "%NUMSUBMITTED%")
            return $this->_statistics[0];
        if ($what == "%NUMACCEPTED%")
            return $this->_statistics[1];

        if (preg_match('/\A%(OTHER|REQUESTER|REVIEWER)(CONTACT|NAME|EMAIL|FIRST|LAST)%\z/', $what, $m)) {
            if ($m[1] === "REVIEWER") {
                $x = $this->_expand_reviewer($m[2], $isbool);
                if ($x !== false || $isbool)
                    return $x;
            } else if (($c = @$this->contacts[strtolower($m[1])]))
                return $this->expand_user($c, $m[2]);
            else if ($isbool)
                return false;
        }

        if ($what == "%AUTHORVIEWCAPABILITY%" && @$Opt["disableCapabilities"])
            return "";

        return self::EXPANDVAR_CONTINUE;
    }

    function expandvar_recipient($what, $isbool) {
        global $Conf;
        if ($what == "%NEWASSIGNMENTS%")
            return $this->get_new_assignments($this->recipient);

        // rest is only there if we have a real paper
        if (!$this->row || defval($this->row, "paperId") <= 0)
            return self::EXPANDVAR_CONTINUE;

        if ($what == "%TITLE%")
            return $this->row->title;
        if ($what == "%TITLEHINT%") {
            if (($tw = titleWords($this->row->title)))
                return "\"$tw\"";
            else
                return "";
        }
        if ($what == "%NUMBER%" || $what == "%PAPER%")
            return $this->row->paperId;
        if ($what == "%REVIEWNUMBER%")
            return $this->reviewNumber;
        if ($what == "%AUTHOR%" || $what == "%AUTHORS%") {
            if (!@$this->permissionContact->is_site_contact
                && !$this->permissionContact->actAuthorView($this->row)
                && !$this->permissionContact->can_view_authors($this->row, false))
                return ($isbool ? false : "Hidden for blind review");
            cleanAuthor($this->row);
            return rtrim($this->row->authorInformation);
        }
        if ($what == "%AUTHORVIEWCAPABILITY%" && isset($this->row->capVersion)
            && $this->permissionContact->actAuthorView($this->row))
            return "cap=" . $Conf->capability_text($this->row, "a");
        if ($what == "%SHEPHERD%" || $what == "%SHEPHERDNAME%"
            || $what == "%SHEPHERDEMAIL%") {
            $pc = pcMembers();
            if (defval($this->row, "shepherdContactId") <= 0
                || !defval($pc, $this->row->shepherdContactId, null)) {
                if ($isbool)
                    return false;
                else if ($this->expansionType == self::EXPAND_EMAIL)
                    return "<none>";
                else
                    return "(no shepherd assigned)";
            }
            $shep = $pc[$this->row->shepherdContactId];
            if ($what == "%SHEPHERD%")
                return $this->expand_user($shep, "CONTACT");
            else if ($what == "%SHEPHERDNAME%")
                return $this->expand_user($shep, "NAME");
            else
                return $this->expand_user($shep, "EMAIL");
        }

        if ($what == "%REVIEWAUTHOR%" && @$this->contacts["reviewer"])
            return $this->_expand_reviewer("CONTACT", $isbool);
        if ($what == "%REVIEWS%")
            return $this->get_reviews();
        if ($what == "%COMMENTS%")
            return $this->get_comments(null);
        $len = strlen($what);
        if ($len > 12 && substr($what, 0, 10) == "%COMMENTS("
            && substr($what, $len - 2) == ")%") {
            if (($t = $this->tagger()->check(substr($what, 10, $len - 12), Tagger::NOVALUE)))
                return $this->get_comments($t);
        }

        if ($len > 12 && substr($what, 0, 10) == "%TAGVALUE("
            && substr($what, $len - 2) == ")%") {
            if (($t = $this->tagger()->check(substr($what, 10, $len - 12), Tagger::NOVALUE | Tagger::NOPRIVATE))) {
                if (!isset($this->_tags[$t])) {
                    $this->_tags[$t] = array();
                    $result = $Conf->qe("select paperId, tagIndex from PaperTag where tag='" . sqlq($t) . "'");
                    while (($row = edb_row($result)))
                        $this->_tags[$t][$row[0]] = $row[1];
                }
                $tv = defval($this->_tags[$t], $this->row->paperId);
                if ($isbool)
                    return $tv !== null;
                else if ($tv !== null)
                    return $tv;
                else {
                    $this->_tagless[$this->row->paperId] = true;
                    return "(none)";
                }
            }
        }

        return self::EXPANDVAR_CONTINUE;
    }


    protected function unexpanded_warning() {
        $m = parent::unexpanded_warning();
        foreach ($this->_unexpanded as $t => $x)
            if (preg_match(',\A%(?:NUMBER|TITLE|PAPER|AUTHOR|REVIEW|COMMENT),', $t))
                $m .= " Paper-specific keywords like <code>" . htmlspecialchars($t) . "</code> weren’t recognized because this set of recipients is not linked to a paper collection.";
        if (isset($this->_unexpanded["%AUTHORVIEWCAPABILITY%"]))
            $m .= " Author view capabilities weren’t recognized because this mail isn’t meant for paper authors.";
        return $m;
    }

    function nwarnings() {
        return count($this->_unexpanded) + count($this->_tagless);
    }

    function warnings() {
        $e = array();
        if (count($this->_unexpanded))
            $e[] = $this->unexpanded_warning();
        if (count($this->_tagless)) {
            $a = array_keys($this->_tagless);
            sort($a, SORT_NUMERIC);
            $e[] = pluralx(count($this->_tagless), "Paper") . " " . commajoin($a) . " did not have some requested tag values.";
        }
        return $e;
    }


    static function send_to($recipient, $template, $row, $rest = array()) {
        if (!defval($recipient, "disabled")) {
            $mailer = new HotCRPMailer($recipient, $row, $rest);
            if (($prep = $mailer->make_preparation($template, $rest)))
                self::send_preparation($prep);
        }
    }

    static function send_contacts($template, $row, $rest = array()) {
        global $Conf, $Me;

        $result = $Conf->qe("select ContactInfo.contactId,
                firstName, lastName, email, preferredEmail, password,
                roles, disabled, contactTags,
                conflictType, 0 myReviewType
                from ContactInfo join PaperConflict using (contactId)
                where paperId=$row->paperId and conflictType>=" . CONFLICT_AUTHOR . "
                group by ContactInfo.contactId");

        // must set the current conflict type in $row for each contact
        $contact_info_map = $row->replace_contact_info_map(null);

        $contacts = array();
        while (($contact = edb_orow($result))) {
            $row->assign_contact_info($contact, $contact->contactId);
            self::send_to(Contact::make($contact), $template, $row, $rest);
            $contacts[] = Text::user_html($contact);
        }

        $row->replace_contact_info_map($contact_info_map);
        if ($Me->allow_administer($row) && !$row->has_author($Me)
            && count($contacts)) {
            $endmsg = (isset($rest["infoMsg"]) ? ", " . $rest["infoMsg"] : ".");
            if (isset($rest["infoNames"]) && $Me->allow_administer($row))
                $contactsmsg = pluralx($contacts, "contact") . ", " . commajoin($contacts);
            else
                $contactsmsg = "contact(s)";
            $Conf->infoMsg("Sent email to paper #{$row->paperId}’s $contactsmsg$endmsg");
        }
    }

    static function send_reviewers($template, $row, $rest = array()) {
        global $Conf, $Me, $Opt;

        $result = $Conf->qe("select ContactInfo.contactId,
                firstName, lastName, email, preferredEmail, password,
                roles, disabled, contactTags,
                conflictType, reviewType myReviewType
                from ContactInfo
                join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.paperId=$row->paperId)
                left join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId and PaperConflict.paperId=$row->paperId)
                group by ContactInfo.contactId");

        if (!isset($rest["cc"]) && isset($Opt["emailCc"]))
            $rest["cc"] = $Opt["emailCc"];
        else if (!isset($rest["cc"]))
            $rest["cc"] = Text::user_email_to(Contact::site_contact());

        // must set the current conflict type in $row for each contact
        $contact_info_map = $row->replace_contact_info_map(null);

        $contacts = array();
        while (($contact = edb_orow($result))) {
            $row->assign_contact_info($contact, $contact->contactId);
            self::send_to(Contact::make($contact), $template, $row, $rest);
            $contacts[] = Text::user_html($contact);
        }

        $row->replace_contact_info_map($contact_info_map);
        if ($Me->allow_administer($row) && !$row->has_author($Me)
            && count($contacts)) {
            $endmsg = (isset($rest["infoMsg"]) ? ", " . $rest["infoMsg"] : ".");
            $Conf->infoMsg("Sent email to paper #{$row->paperId}’s " . pluralx($contacts, "reviewer") . ", " . commajoin($contacts) . $endmsg);
        }
    }

    static function send_manager($template, $row, $rest = array()) {
        if ($row && $row->managerContactId
            && ($c = Contact::find_by_id($row->managerContactId)))
            self::send_to($c, $template, $row, $rest);
        else
            self::send_to(Contact::site_contact(), $template, $row, $rest);
    }

}

// load mail templates, including local ones if any
global $ConfSitePATH, $Opt;
require_once("$ConfSitePATH/src/mailtemplate.php");
if ((@include "$ConfSitePATH/conf/mailtemplate-local.php") !== false
    || (@include "$ConfSitePATH/conf/mailtemplate-local.inc") !== false
    || (@include "$ConfSitePATH/Code/mailtemplate-local.inc") !== false)
    /* do nothing */;
if (@$Opt["mailtemplate_include"])
    read_included_options($ConfSitePATH, $Opt["mailtemplate_include"]);
