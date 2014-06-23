<?php
// mailer.php -- HotCRP mail template manager
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// set HOTCRP_EOL
global $Opt;
if (!isset($Opt["postfixEOL"]) || !$Opt["postfixEOL"])
    define("HOTCRP_EOL", "\r\n");
else if ($Opt["postfixEOL"] === true)
    define("HOTCRP_EOL", PHP_EOL);
else
    define("HOTCRP_EOL", $Opt["postfixEOL"]);

class MailerState {
    var $unexpanded;
    var $tagless;
    var $tags;

    function MailerState() {
        $this->unexpanded = array();
        $this->tagless = array();
        $this->tags = array();
    }

    function nwarnings() {
        return count($this->unexpanded) + count($this->tagless);
    }

    function _unexpanded_warning() {
        $ispaper = false;
        $papertags = array("%NUMBER%" => 1, "%TITLE%" => 1, "%TITLEHINT%" => 1,
                           "%PAPER%" => 1, "%AUTHOR%" => 1, "%AUTHORS%" => 1,
                           "%REVIEWS%" => 1, "%COMMENTS%" => 1);
        foreach ($this->unexpanded as $t => $x)
            if (isset($papertags[$t]))
                $ispaper = true;

        $a = array_keys($this->unexpanded);
        natcasesort($a);
        for ($i = 0; $i < count($a); ++$i)
            $a[$i] = "<tt>" . htmlspecialchars($a[$i]) . "</tt>";

        if (count($this->unexpanded) == 1)
            $m = "Keyword-like string " . commajoin($a) . " was not recognized.";
        else
            $m = "Keyword-like strings " . commajoin($a) . " were not recognized.";
        if ($ispaper)
            $m .= "  (Paper-specific keywords like <code>%NUMBER%</code> weren’t recognized because this set of recipients is not linked to a paper collection.)";
        if (isset($this->unexpanded["%AUTHORVIEWCAPABILITY%"]))
            $m .= "  (Author view capabilities weren’t recognized because this mail isn’t meant for paper authors.)";
        return $m;
    }

    function warnings() {
        $e = array();
        if (count($this->unexpanded))
            $e[] = $this->_unexpanded_warning();
        if (count($this->tagless)) {
            $a = array_keys($this->tagless);
            sort($a, SORT_NUMERIC);
            $e[] = pluralx(count($this->tagless), "Paper") . " " . commajoin($a) . " did not have some requested tag values.";
        }
        return $e;
    }
}

class Mailer {

    const EXPAND_BODY = 0;
    const EXPAND_HEADER = 1;
    const EXPAND_EMAIL = 2;

    public static $mailHeaders = array("cc" => "Cc", "bcc" => "Bcc",
                                       "replyto" => "Reply-To");

    private $row;
    private $contact;
    private $permissionContact;
    private $_tagger = null;
    var $contacts;
    var $hideSensitive;
    var $hideReviews;
    var $reason;
    var $adminupdate;
    var $notes;
    var $rrow;
    private $reviewNumber;
    private $comment_row;
    public $capability;
    private $statistics = null;
    var $width;
    private $expansionType = null;
    private $mstate;

    function Mailer($row, $contact, $otherContact = null, $rest = array()) {
        $this->row = $row;
        $this->contact = $contact;
        $this->permissionContact = defval($rest, "permissionContact", $contact);
        $this->contacts = array($contact, defval($rest, "contact2", $otherContact), defval($rest, "contact3", null));
        $this->hideSensitive = defval($rest, "hideSensitive", false);
        $this->reason = defval($rest, "reason", "");
        $this->adminupdate = defval($rest, "adminupdate", false);
        $this->notes = defval($rest, "notes", "");
        $this->rrow = defval($rest, "rrow", null);
        $this->reviewNumber = defval($rest, "reviewNumber", "");
        $this->comment_row = defval($rest, "comment_row", null);
        $this->hideReviews = defval($rest, "hideReviews", false);
        $this->capability = defval($rest, "capability", null);
        $this->width = 75;
        $this->expansionType = null;
        if (isset($rest["mstate"]))
            $this->mstate = $rest["mstate"];
        else
            $this->mstate = new MailerState();
    }

    function _expandContact($contact, $out) {
        $r = Text::analyze_name($contact);
        if (is_object($contact) && defval($contact, "preferredEmail", "") != "")
            $r->email = $contact->preferredEmail;

        if ($out == "NAME" || $out == "CONTACT")
            $t = $r->name;
        else if ($out == "FIRST")
            $t = $r->firstName;
        else if ($out == "LAST")
            $t = $r->lastName;
        else
            $t = "";
        if ($t == "" && $out == "NAME" && $r->email
            && $this->expansionType != self::EXPAND_EMAIL)
            $t = $r->email;
        if ($t != "" && $this->expansionType == self::EXPAND_EMAIL
            && preg_match('#[\000-\037()[\]<>@,;:\\".]#', $t))
            $t = "\"" . addcslashes($t, '"\\') . "\"";

        $email = $r->email;
        if ($email == "" && $this->expansionType == self::EXPAND_EMAIL)
            $email = "<none>";
        if ($out == "EMAIL")
            $t = $email;
        else if ($out == "CONTACT" && $this->expansionType == self::EXPAND_EMAIL) {
            if ($t == "")
                $t = $email;
            else if ($email[0] == "<")
                $t .= " $email";
            else
                $t .= " <$email>";
        } else if ($out == "CONTACT" && $email != "")
            $t = ($t == "" ? $email : "$t <$email>");

        return $t;
    }

    private function tagger()  {
        if (!$this->_tagger)
            $this->_tagger = new Tagger($this->permissionContact);
        return $this->_tagger;
    }

    function expandvar($what, $isbool = false) {
        global $Conf, $ConfSiteSuffix, $Opt;
        $len = strlen($what);

        if ($len > 7 && substr($what, 0, 5) == "%OPT(" && substr($what, $len - 2) == ")%") {
            $inner = "%" . substr($what, 5, $len - 7) . "%";
            if ($isbool)
                return $this->expandvar($inner, true);
            else if (($yes = $this->expandvar($inner, true)))
                return $this->expandvar($inner, false);
            else
                return ($yes === null ? $what : "");
        }

        if ($len > 10 && substr($what, 0, 8) == "%URLENC(" && substr($what, $len - 2) == ")%") {
            $inner = "%" . substr($what, 8, $len - 10) . "%";
            $yes = $this->expandvar($inner, true);
            if ($isbool)
                return $yes;
            else if ($yes)
                return urlencode($this->expandvar($inner, false));
            else
                return ($yes === null ? $what : "");
        }

        if ($what == "%REVIEWDEADLINE%") {
            if (@$this->row->reviewType > 0)
                $rev = ($this->row->reviewType >= REVIEW_PC ? "pc" : "ext");
            else if (isset($this->row->roles))
                $rev = ($this->row->roles & Contact::ROLE_PCLIKE ? "pc" : "ext");
            else if ($Conf->setting("pcrev_soft") != $Conf->setting("extrev_soft")) {
                if ($isbool && ($Conf->setting("pcrev_soft") > 0) == ($Conf->setting("extrev_soft") > 0))
                    return $Conf->setting("pcrev_soft") > 0;
                else
                    return ($isbool ? null : $what);
            } else
                $rev = "ext";
            $what = "%DEADLINE(" . $rev . "rev_soft)%";
            $len = strlen($what);
        }
        if ($len > 12 && substr($what, 0, 10) == "%DEADLINE(" && substr($what, $len - 2) == ")%") {
            $inner = substr($what, 10, $len - 12);
            if ($isbool)
                return $Conf->setting($inner) > 0;
            else
                return $Conf->printableTimeSetting($inner);
        }

        if ($what == "%CONFNAME%") {
            $t = $Opt["longName"];
            if ($Opt["shortName"] && $Opt["shortName"] != $Opt["longName"])
                $t .= " (" . $Opt["shortName"] . ")";
            return $t;
        }
        if ($what == "%CONFSHORTNAME%")
            return $Opt["shortName"];
        if ($what == "%CONFLONGNAME%")
            return $Opt["longName"];
        if ($what == "%ADMIN%")
            return $this->_expandContact(Contact::site_contact(), "CONTACT");
        if ($what == "%ADMINNAME%")
            return $this->_expandContact(Contact::site_contact(), "NAME");
        if ($what == "%ADMINEMAIL%")
            return $this->_expandContact(Contact::site_contact(), "EMAIL");
        if ($what == "%URL%")
            return $Opt["paperSite"];
        else if ($len > 7 && substr($what, 0, 5) == "%URL(" && substr($what, $len - 2) == ")%") {
            $a = preg_split('/\s*,\s*/', substr($what, 5, $len - 7));
            for ($i = 0; $i < count($a); ++$i) {
                $a[$i] = $this->expand($a[$i], "urlpart");
                $a[$i] = preg_replace('/\&(?=\&|\z)/', "", $a[$i]);
            }
            return hoturl_absolute($a[0], isset($a[1]) ? $a[1] : "");
        }
        if ($what == "%PHP%")
            return $ConfSiteSuffix;
        if ($what == "%AUTHORVIEWCAPABILITY%" && @$Opt["disableCapabilities"])
            return "";
        if ($what == "%LOGINNOTICE%") {
            if (@$Opt["disableCapabilities"])
                return $this->expand(defval($Opt, "mailtool_loginNotice", "  To sign in, either click the link below or paste it into your web browser's location field.\n\n%LOGINURL%"), $isbool);
            else
                return "";
        }
        if (($what == "%NUMACCEPTED%" || $what == "%NUMSUBMITTED%")
            && $this->statistics === null) {
            $this->statistics = array(0, 0);
            $result = $Conf->q("select outcome, count(paperId) from Paper where timeSubmitted>0 group by outcome");
            while (($row = edb_row($result))) {
                $this->statistics[0] += $row[1];
                if ($row[0] > 0)
                    $this->statistics[1] += $row[1];
            }
        }
        if ($what == "%NUMSUBMITTED%")
            return $this->statistics[0];
        if ($what == "%NUMACCEPTED%")
            return $this->statistics[1];

        if (preg_match('/\A%((?:OTHER)?)(CONTACT|NAME|EMAIL|FIRST|LAST)(|[1-9]\d*)%\z/', $what, $m)
            && ($m[1] == "" || $m[3] == "")) {
            $which = ($m[1] == "" && $m[3] == "" ? 0 : ($m[1] == "" ? (int) $m[3] - 1 : 1));
            if (($c = defval($this->contacts, $which, null)))
                return $this->_expandContact($c, $m[2]);
        }

        // if no contact, this is a pre-expansion
        $external_password = isset($Opt["ldapLogin"]) || isset($Opt["httpAuthLogin"]);
        if (!$this->contact) {
            if ($what == "%PASSWORD%" && $external_password && $isbool)
                return false;
            else
                return ($isbool ? null : $what);
        }

        if ($what == "%LOGINURL%" || $what == "%LOGINURLPARTS%" || $what == "%PASSWORD%") {
            $password = null;
            if (!$external_password) {
                if (isset($this->contact->password_plaintext))
                    $password = ($this->hideSensitive ? "HIDDEN" : $this->contact->password_plaintext);
                else if ($this->contact->password_type != 0)
                    $password = false;
            }
            $loginparts = "";
            if (!isset($Opt["httpAuthLogin"]))
                $loginparts = "email=" . urlencode($this->contact->email) . ($password ? "&password=" . urlencode($password) : "");
            if ($what == "%LOGINURL%")
                return $Opt["paperSite"] . ($loginparts ? "/?" . $loginparts : "/");
            else if ($what == "%LOGINURLPARTS%")
                return $loginparts;
            else
                return ($isbool || $password !== null ? $password : "");
        }

        if ($what == "%REASON%")
            return $this->reason;
        if ($what == "%ADMINUPDATE%")
            return $this->adminupdate ? "An administrator performed this update. " : "";
        if ($what == "%NOTES%")
            return $this->notes;
        if ($what == "%CAPABILITY%")
            return ($isbool || $this->capability ? $this->capability : "");
        if ($what == "%NEWASSIGNMENTS%")
            return $this->get_new_assignments($this->contact);

        // rest is only there if we have a real paper
        if (!$this->row || defval($this->row, "paperId") <= 0) {
            if ($isbool)
                return false;
            $this->mstate->unexpanded[$what] = true;
            return $what;
        }

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
            if (!defval($this->permissionContact, "privSuperChair")
                && !$this->permissionContact->canViewAuthors($this->row, false))
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
                return $this->_expandContact($shep, "CONTACT");
            else if ($what == "%SHEPHERDNAME%")
                return $this->_expandContact($shep, "NAME");
            else
                return $this->_expandContact($shep, "EMAIL");
        }

        if ($what == "%REVIEWAUTHOR%" && $this->contacts[1]) {
            if ($Conf->is_review_blind($this->rrow)
                && defval($this->permissionContact, "privChair") <= 0
                && (!isset($this->permissionContact->canViewReviewerIdentity)
                    || !$this->permissionContact->canViewReviewerIdentity($this->row, $this->rrow, false))) {
                if ($isbool)
                    return false;
                else if ($this->expansionType == self::EXPAND_EMAIL)
                    return "<hidden>";
                else
                    return "Hidden for blind review";
            }
            return $this->_expandContact($this->contacts[1], "CONTACT");
        }

        if ($what == "%REVIEWS%")
            return $this->get_reviews(false);
        if ($what == "%COMMENTS%")
            return $this->get_comments(null);
        else if ($len > 12 && substr($what, 0, 10) == "%COMMENTS("
                 && substr($what, $len - 2) == ")%") {
            if (($t = $this->tagger()->check(substr($what, 10, $len - 12), Tagger::NOVALUE)))
                return $this->get_comments($t);
        }

        if ($len > 12 && substr($what, 0, 10) == "%TAGVALUE("
            && substr($what, $len - 2) == ")%") {
            if (($t = $this->tagger()->check(substr($what, 10, $len - 12), Tagger::NOVALUE | Tagger::NOPRIVATE))) {
                if (!isset($this->mstate->tags[$t])) {
                    $this->mstate->tags[$t] = array();
                    $result = $Conf->qe("select paperId, tagIndex from PaperTag where tag='" . sqlq($t) . "'");
                    while (($row = edb_row($result)))
                        $this->mstate->tags[$t][$row[0]] = $row[1];
                }
                $tv = defval($this->mstate->tags[$t], $this->row->paperId);
                if ($isbool)
                    return $tv !== null;
                else if ($tv !== null)
                    return $tv;
                else {
                    $this->mstate->tagless[$this->row->paperId] = true;
                    return "(none)";
                }
            }
        }

        if ($isbool)
            return false;
        else {
            $this->mstate->unexpanded[$what] = true;
            return $what;
        }
    }

    function _pushIf(&$ifstack, $text, $yes) {
        if ($yes !== false && $yes !== true && $yes !== null)
            $yes = (bool) $yes;
        if ($yes === true || $yes === null)
            array_push($ifstack, $yes);
        else
            array_push($ifstack, $text);
    }

    function _popIf(&$ifstack, &$text) {
        if (count($ifstack) == 0)
            return null;
        else if (($pop = array_pop($ifstack)) === true || $pop === null)
            return $pop;
        else {
            $text = $pop;
            return false;
        }
    }

    function _handleIf(&$ifstack, &$text, $cond, $haselse) {
        assert($cond || $haselse);
        if ($haselse) {
            $yes = $this->_popIf($ifstack, $text);
            if ($yes !== null)
                $yes = !$yes;
        } else
            $yes = true;
        if ($yes && $cond)
            $yes = $this->expandvar("%" . substr($cond, 1, strlen($cond) - 2) . "%", true);
        $this->_pushIf($ifstack, $text, $yes);
        return $yes;
    }

    function _expandConditionals($rest) {
        $text = "";
        $ifstack = array();

        while (preg_match('/\A(.*?)%(IF|ELSE?IF|ELSE|ENDIF)((?:\(\w+(?:\(\w+\))*\))?)%(.*)\z/s', $rest, $m)) {
            $text .= $m[1];
            $rest = $m[4];

            if ($m[2] == "IF" && $m[3] != "")
                $yes = $this->_handleIf($ifstack, $text, $m[3], false);
            else if (($m[2] == "ELSIF" || $m[2] == "ELSEIF") && $m[3] != "")
                $yes = $this->_handleIf($ifstack, $text, $m[3], true);
            else if ($m[2] == "ELSE" && $m[3] == "")
                $yes = $this->_handleIf($ifstack, $text, false, true);
            else if ($m[2] == "ENDIF" && $m[3] == "")
                $yes = $this->_popIf($ifstack, $text);
            else
                $yes = null;

            if ($yes === null)
                $text .= "%" . $m[2] . $m[3] . "%";
        }

        return $text . $rest;
    }

    private function get_reviews($finalized) {
        global $Conf;
        if ($this->hideReviews)
            return "[Reviews are hidden since you have incomplete reviews of your own.]";

        $result = $Conf->qe("select PaperReview.*,
                ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
                from PaperReview
                join ContactInfo on (ContactInfo.contactId=PaperReview.contactId)
                where PaperReview.paperId=" . $this->row->paperId . " order by reviewOrdinal", "while retrieving reviews");
        $rf = reviewForm();
        $text = "";
        while (($row = edb_orow($result)))
            if ($row->reviewSubmitted)
                $text .= $rf->prettyTextForm($this->row, $row, $this->permissionContact, true) . "\n";
        return $text;
    }

    private function get_comments($tag) {
        global $Conf;
        if ($this->hideReviews
            || ($tag && $Conf->sversion < 68)
            || ($this->comment_row && $tag
                && stripos($this->comment_row->commentTags, " $tag ") === false))
            return "";

        // save old au_seerev setting, and reset it so authors can see them.
        $old_au_seerev = $Conf->setting("au_seerev");
        $Conf->settings["au_seerev"] = AU_SEEREV_ALWAYS;

        if ($this->comment_row)
            $crows = array($this->comment_row);
        else {
            $where = "paperId=" . $this->row->paperId;
            if ($tag)
                $where .= " and commentTags like '% " . sqlq_for_like($tag) . " %'";
            $crows = $Conf->comment_rows($Conf->comment_query($where), $this->permissionContact);
        }

        $rf = reviewForm();
        $text = "";
        foreach ($crows as $crow)
            if ($this->permissionContact->canViewComment($this->row, $crow, false))
                $text .= $rf->prettyTextComment($this->row, $crow, $this->permissionContact) . "\n";

        $Conf->settings["au_seerev"] = $old_au_seerev;
        return $text;
    }

    private function get_new_assignments($contact) {
        global $Conf;
        $result = $Conf->qe("select r.paperId, p.title
                from PaperReview r join Paper p using (paperId)
                where r.contactId=" . $contact->contactId . "
                and r.timeRequested>r.timeRequestNotified
                and r.reviewSubmitted is null and r.reviewNeedsSubmit!=0
                order by r.paperId", "while retrieving assignments");
        $text = "";
        while (($row = edb_row($result)))
            $text .= ($text ? "\n#" : "#") . $row[0] . " " . $row[1];
        return $text;
    }

    function _lineexpand($line, $info, $indent, $width) {
        $text = "";
        while (preg_match('/^(.*?)(%\w+(?:|\([^\)]*\))%)(.*)$/s', $line, $m)) {
            $text .= $m[1] . $this->expandvar($m[2], false);
            $line = $m[3];
        }
        $text .= $line;
        return wordWrapIndent($text, $info, $indent, $width) . "\n";
    }

    function expand($text, $field = null) {
        if (is_array($text)) {
            $a = array();
            foreach ($text as $k => $t)
                $a[$k] = $this->expand($t, $k);
            return $a;
        }

        // leave early on empty string
        if ($text == "")
            return "";

        // width, expansion type based on field
        $oldExpansionType = $this->expansionType;
        $width = 100000;
        if ($field == "to" || $field == "cc" || $field == "bcc"
            || $field == "replyto")
            $this->expansionType = self::EXPAND_EMAIL;
        else if ($field != "body" && $field != "")
            $this->expansionType = self::EXPAND_HEADER;
        else {
            $this->expansionType = self::EXPAND_BODY;
            $width = $this->width;
        }

        // expand out %IF% and %ELSE% and %ENDIF%.  Need to do this first,
        // or we get confused with wordwrapping.
        $text = $this->_expandConditionals(cleannl($text));

        // separate text into lines
        $lines = explode("\n", $text);
        if (count($lines) && $lines[count($lines) - 1] === "")
            array_pop($lines);

        $text = "";
        $textstart = 0;
        for ($i = 0; $i < count($lines); ++$i) {
            $line = rtrim($lines[$i]);
            if ($line == "")
                $text .= "\n";
            else if (preg_match('/^%(?:REVIEWS|COMMENTS)(?:[(].*[)])?%$/', $line)) {
                if (($m = $this->expandvar($line, false)) != "")
                    $text .= $m . "\n";
            } else if (preg_match('/^([ \t][ \t]*.*?: )(%OPT\([\w()]+\)%)$/', $line, $m)) {

                if (($yes = $this->expandvar($m[2], true)))
                    $text .= wordWrapIndent($this->expandvar($m[2]), $m[1], tabLength($m[1], true), $width) . "\n";
                else if ($yes === null)
                    $text .= $line . "\n";
            } else if (preg_match('/^([ \t][ \t]*.*?: )(%\w+(?:|\([^\)]*\))%|\S+)\s*$/', $line, $m))
                $text .= $this->_lineexpand($m[2], $m[1], tabLength($m[1], true), $width);
            else if (strpos($line, '%') !== false)
                $text .= $this->_lineexpand($line, "", 0, $width);
            else
                $text .= wordWrapIndent($line, "", 0, $width) . "\n";
        }

        // lose newlines on header expansion
        if ($this->expansionType != self::EXPAND_BODY)
            $text = rtrim(preg_replace('/[\r\n\f\x0B]+/', ' ', $text));

        $this->expansionType = $oldExpansionType;
        return $text;
    }

    static function getTemplate($templateName, $default = false) {
        global $Conf, $mailTemplates;
        $m = $mailTemplates[$templateName];
        if (!$default && ($t = $Conf->setting_data("mailsubj_" . $templateName)) !== false)
            $m["subject"] = $t;
        if (!$default && ($t = $Conf->setting_data("mailbody_" . $templateName)) !== false)
            $m["body"] = $t;
        return $m;
    }

    function expandTemplate($templateName, $default = false) {
        return $this->expand(self::getTemplate($templateName, $default));
    }

    static function prepareToSend($template, $row, $contact,
                                  $otherContact = null, &$rest = array()) {
        global $Conf, $mailTemplates;

        // look up template
        if (is_string($template) && $template[0] == "@")
            $template = self::getTemplate(substr($template, 1));
        // add rest fields to template for expansion
        foreach (self::$mailHeaders as $f => $x)
            if (isset($rest[$f]))
                $template[$f] = $rest[$f];

        if (!isset($rest["emailTo"]) || !$rest["emailTo"])
            $emailTo = $contact;
        else if (is_string($rest["emailTo"]))
            $emailTo = (object) array("email" => $rest["emailTo"]);
        else
            $emailTo = $rest["emailTo"];
        if (!$emailTo || !$emailTo->email)
            return $Conf->errorMsg("no email in Mailer::send");

        // use preferredEmail if it's set
        if (defval($emailTo, "preferredEmail", "") != "") {
            $xemailTo = (object) array("email" => $emailTo->preferredEmail);
            foreach (array("firstName", "lastName", "name", "fullName") as $k)
                if (defval($emailTo, $k, "") != "")
                    $xemailTo->$k = $emailTo->$k;
            $emailTo = $xemailTo;
        }

        // expand the template
        $mailer = new Mailer($row, $contact, $otherContact, $rest);
        $m = $mailer->expand($template);
        $m["subject"] = substr(Mailer::mimeHeader("Subject: ", $m["subject"]), 9);
        $m["to"] = $emailTo->email;
        $m["allowEmail"] = $Conf->allowEmailTo($m["to"]);
        $hdr = Mailer::mimeEmailHeader("To: ", Text::user_email_to($emailTo));
        $m["fullTo"] = substr($hdr, 4);

        // parse headers
        $headers = "MIME-Version: 1.0" . HOTCRP_EOL . "Content-Type: text/plain; charset=utf-8" . HOTCRP_EOL . $hdr . HOTCRP_EOL;
        foreach (self::$mailHeaders as $n => $h)
            if (isset($m[$n]) && $m[$n] != "" && $m[$n] != "<none>") {
                $hdr = Mailer::mimeEmailHeader($h . ": ", $m[$n]);
                if ($hdr === false) {
                    if (isset($rest["error"]))
                        $rest["error"] = $n;
                    else
                        $Conf->errorMsg("$h &ldquo;<tt>" . htmlspecialchars($m[$n]) . "</tt>&rdquo; isn't a valid email list.");
                    return false;
                }
                $m[$n] = substr($hdr, strlen($h) + 2);
                $headers .= $hdr . HOTCRP_EOL;
            } else
                unset($m[$n]);
        $m["headers"] = $headers;

        return $m;
    }

    static function sendPrepared($preparation) {
        global $Conf, $Opt;
        if ($preparation["allowEmail"]) {
            // set sendmail parameters
            $extra = defval($Opt, "sendmailParam", "");
            if (isset($Opt["emailSender"])) {
                @ini_set("sendmail_from", $Opt["emailSender"]);
                if (!isset($Opt["sendmailParam"]))
                    $extra = "-f" . escapeshellarg($Opt["emailSender"]);
            }

            // try to extract a valid To: header
            $to = $preparation["to"];
            $headers = $preparation["headers"];
            $eollen = strlen(HOTCRP_EOL);
            if (($topos = strpos($headers, HOTCRP_EOL . "To: ")) !== false
                && ($nlpos = strpos($headers, HOTCRP_EOL, $topos + 1)) !== false
                && ($nlpos + $eollen == strlen($headers) || !ctype_space($headers[$nlpos + $eollen]))) {
                $tovalpos = $topos + $eollen + 4;
                $to = substr($headers, $tovalpos, $nlpos - $tovalpos);
                $headers = substr($headers, 0, $topos) . substr($headers, $nlpos);
            } else if ($topos !== false)
                $to = "";

            return mail($to, $preparation["subject"], $preparation["body"], $headers . "From: " . $Opt["emailFrom"], $extra);
        } else if (!$Opt["sendEmail"])
            return $Conf->infoMsg("<pre>" . htmlspecialchars("To: " . $preparation["to"] . "\n" . $preparation["headers"] . "Subject: " . $preparation["subject"] . "\n\n" . $preparation["body"]) . "</pre>");
    }

    static function send($template, $row, $contact, $otherContact = null, $rest = array()) {
        if (defval($contact, "disabled"))
            return;
        $preparation = self::prepareToSend($template, $row, $contact, $otherContact, $rest);
        if ($preparation)
            self::sendPrepared($preparation);
    }

    static function sendContactAuthors($template, $row, $otherContact = null, $rest = array()) {
        global $Conf, $Me, $mailTemplates;

        $qa = ($Conf->sversion >= 47 ? ", disabled" : "");
        $result = $Conf->qe("select ContactInfo.contactId,
                firstName, lastName, email, preferredEmail, password, roles$qa,
                conflictType, 0 myReviewType
                from ContactInfo join PaperConflict using (contactId)
                where paperId=$row->paperId and conflictType>=" . CONFLICT_AUTHOR . "
                group by ContactInfo.contactId", "while looking up contacts to send email");

        // must set the current conflict type in $row for each contact
        $contact_info_map = $row->replace_contact_info_map(null);

        $contacts = array();
        while (($contact = edb_orow($result))) {
            $row->assign_contact_info($contact, $contact->contactId);
            Mailer::send($template, $row, Contact::make($contact), $otherContact, $rest);
            $contacts[] = Text::user_html($contact);
        }

        $row->replace_contact_info_map($contact_info_map);
        if ($Me->allowAdminister($row) && !$row->has_author($Me)
            && count($contacts)) {
            $endmsg = (isset($rest["infoMsg"]) ? ", " . $rest["infoMsg"] : ".");
            if (isset($rest["infoNames"]) && $Me->allowAdminister($row))
                $contactsmsg = pluralx($contacts, "contact") . ", " . commajoin($contacts);
            else
                $contactsmsg = "contact(s)";
            $Conf->infoMsg("Sent email to paper #$row->paperId&rsquo;s $contactsmsg$endmsg");
        }
    }

    static function sendReviewers($template, $row, $otherContact = null, $rest = array()) {
        global $Conf, $Me, $Opt, $mailTemplates;

        $qa = ($Conf->sversion >= 47 ? ", disabled" : "");
        $result = $Conf->qe("select ContactInfo.contactId,
                firstName, lastName, email, preferredEmail, password, roles$qa,
                conflictType, reviewType myReviewType
                from ContactInfo
                join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.paperId=$row->paperId)
                left join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId and PaperConflict.paperId=$row->paperId)
                group by ContactInfo.contactId", "while looking up reviewers to send email");

        if (!isset($rest["cc"]) && isset($Opt["emailCc"]))
            $rest["cc"] = $Opt["emailCc"];
        else if (!isset($rest["cc"]))
            $rest["cc"] = Text::user_email_to(Contact::site_contact());

        // must set the current conflict type in $row for each contact
        $contact_info_map = $row->replace_contact_info_map(null);

        $contacts = array();
        while (($contact = edb_orow($result))) {
            $row->assign_contact_info($contact, $contact->contactId);
            Mailer::send($template, $row, Contact::make($contact), $otherContact, $rest);
            $contacts[] = Text::user_html($contact);
        }

        $row->replace_contact_info_map($contact_info_map);
        if ($Me->allowAdminister($row) && !$row->has_author($Me)
            && count($contacts)) {
            $endmsg = (isset($rest["infoMsg"]) ? ", " . $rest["infoMsg"] : ".");
            $Conf->infoMsg("Sent email to paper #$row->paperId&rsquo;s " . pluralx($contacts, "reviewer") . ", " . commajoin($contacts) . $endmsg);
        }
    }

    static function sendAdmin($template, $row, $otherContact = null, $rest = array()) {
        Mailer::send($template, $row, Contact::site_contact(), $otherContact, $rest);
    }


    /// Quote potentially non-ASCII header text a la RFC2047 and/or RFC822.
    static function mimeAppend(&$result, &$linelen, $str, $utf8) {
        if ($utf8) {
            // replace all special characters used by the encoder
            $str = str_replace(array('=',   '_',   '?',   ' '),
                               array('=3D', '=5F', '=3F', '_'), $str);
            // define nonsafe characters
            if ($utf8 > 1)
                $matcher = ',[^-0-9a-zA-Z!*+/=_],';
            else
                $matcher = ',[\x80-\xFF],';
            preg_match_all($matcher, $str, $m, PREG_OFFSET_CAPTURE);
            $xstr = "";
            $last = 0;
            foreach ($m[0] as $mx) {
                $xstr .= substr($str, $last, $mx[1] - $last)
                    . "=" . strtoupper(dechex(ord($mx[0])));
                $last = $mx[1] + 1;
            }
            $xstr .= substr($str, $last);
        } else
            $xstr = $str;

        // append words to the line
        while ($xstr != "") {
            $z = strlen($xstr);
            assert($z > 0);

            // add a line break
            $maxlinelen = ($utf8 ? 76 - 12 : 78);
            if (($linelen + $z > $maxlinelen && $linelen > 30)
                || ($utf8 && substr($result, strlen($result) - 2) == "?=")) {
                $result .= HOTCRP_EOL . " ";
                $linelen = 1;
                while (!$utf8 && $xstr !== "" && ctype_space($xstr[0])) {
                    $xstr = substr($xstr, 1);
                    --$z;
                }
            }

            // if encoding, skip intact UTF-8 characters;
            // otherwise, try to break at a space
            if ($utf8 && $linelen + $z > $maxlinelen) {
                $z = $maxlinelen - $linelen;
                if ($xstr[$z - 1] == "=")
                    $z -= 1;
                else if ($xstr[$z - 2] == "=")
                    $z -= 2;
                while ($z > 3
                       && $xstr[$z] == "="
                       && ($chr = hexdec(substr($xstr, $z + 1, 2))) >= 128
                       && $chr < 192)
                    $z -= 3;
            } else if ($linelen + $z > $maxlinelen) {
                $y = strrpos(substr($xstr, 0, $maxlinelen - $linelen), " ");
                if ($y > 0)
                    $z = $y;
            }

            // append
            if ($utf8)
                $astr = "=?utf-8?q?" . substr($xstr, 0, $z) . "?=";
            else
                $astr = substr($xstr, 0, $z);

            $result .= $astr;
            $linelen += strlen($astr);

            $xstr = substr($xstr, $z);
        }
    }

    static function mimeEmailHeader($header, $str) {
        if (preg_match('/[\r\n]/', $str))
            $str = simplify_whitespace($str);

        $text = $header;
        $linelen = strlen($text);

        // separate $str into emails, quote each separately
        while (true) {

            // try three types of match in turn:
            // 1. name <email> [RFC 822]
            $match = preg_match("/\\A[,\\s]*((?:(?:\"(?:[^\"\\\\]|\\\\.)*\"|[^\\s\\000-\\037()[\\]<>@,;:\\\\\".]+)\\s*?)*)\\s*<\\s*(.*?)\\s*>\\s*(.*)\\z/s", $str, $m);
            // 2. name including periods but no quotes <email> (canonicalize)
            if (!$match) {
                $match = preg_match("/\\A[,\\s]*((?:[^\\s\\000-\\037()[\\]<>@,;:\\\\\"]+\\s*?)*)\\s*<\\s*(.*?)\\s*>\\s*(.*)\\z/s", $str, $m);
                if ($match)
                    $m[1] = "\"$m[1]\"";
            }
            // 3. bare email
            if (!$match)
                $match = preg_match("/\\A[,\\s]*()<?\\s*([^\\s\\000-\\037()[\\]<>,;:\\\\\"]+)\\s*>?\\s*(.*)\\z/s", $str, $m);
            // otherwise, fail
            if (!$match)
                break;

            list($name, $email, $str) = array($m[1], $m[2], $m[3]);
            if (strpos($email, "@") !== false && !validate_email($email))
                return false;
            if ($str != "" && $str[0] != ",")
                return false;
            if ($email == "none" || $email == "hidden")
                continue;

            if ($text !== $header) {
                $text .= ", ";
                $linelen += 2;
            }

            // unquote any existing UTF-8 encoding
            if ($name != "" && $name[0] == "="
                && strcasecmp(substr($name, 0, 10), "=?utf-8?q?") == 0)
                $name = self::mimeHeaderUnquote($name);

            $utf8 = preg_match('/[\x80-\xFF]/', $name) ? 2 : 0;
            if ($name != "" && $name[0] == "\""
                && preg_match("/\\A\"([^\\\\\"]|\\\\.)*\"\\z/s", $name)) {
                if ($utf8)
                    self::mimeAppend($text, $linelen, substr($name, 1, -1), $utf8);
                else
                    self::mimeAppend($text, $linelen, $name, false);
            } else if ($utf8)
                self::mimeAppend($text, $linelen, $name, $utf8);
            else if (preg_match(',\A[-!#$%&\'*+/0-9=?A-Z^_`a-z{|}~ \t]*\z,', $name))
                self::mimeAppend($text, $linelen, $name, false);
            else {
                $name = preg_replace(',(?=[^-!#$%&\'*+/0-9=?A-Z^_`a-z{|}~ \t]),', '\\', $name);
                self::mimeAppend($text, $linelen, "\"$name\"", false);
            }

            if ($name == "")
                self::mimeAppend($text, $linelen, $email, false);
            else
                self::mimeAppend($text, $linelen, " <$email>", false);
        }

        if (!preg_match('/\A[\s,]*\z/', $str))
            return false;
        return $text;
    }

    static function mimeHeader($header, $str) {
        if (preg_match('/[\r\n]/', $str))
            $str = simplify_whitespace($str);

        $text = $header;
        $linelen = strlen($text);
        if (preg_match('/[\x80-\xFF]/', $str))
            self::mimeAppend($text, $linelen, $str, true);
        else
            self::mimeAppend($text, $linelen, $str, false);
        return $text;
    }

    static function chr_hexdec_callback($m) {
        return chr(hexdec($m[1]));
    }

    static function mimeHeaderUnquote($text) {
        if (strlen($text) > 2 && $text[0] == '=' && $text[1] == '?') {
            $out = '';
            while (preg_match('/\A=\?utf-8\?q\?(.*?)\?=(\r?\n )?/i', $text, $m)) {
                $f = str_replace('_', ' ', $m[1]);
                $out .= preg_replace_callback('/=([0-9A-F][0-9A-F])/',
                                              "Mailer::chr_hexdec_callback",
                                              $f);
                $text = substr($text, strlen($m[0]));
            }
            return $out . $text;
        } else
            return $text;
    }

}

// load mail templates, including local ones if any
global $ConfSitePATH;
require_once("$ConfSitePATH/src/mailtemplate.php");
if (file_exists("$ConfSitePATH/conf/mailtemplate-local.php"))
    require_once("$ConfSitePATH/conf/mailtemplate-local.php");
if (file_exists("$ConfSitePATH/conf/mailtemplate-local.inc"))
    require_once("$ConfSitePATH/conf/mailtemplate-local.inc");
if (file_exists("$ConfSitePATH/Code/mailtemplate-local.inc"))
    require_once("$ConfSitePATH/Code/mailtemplate-local.inc");
