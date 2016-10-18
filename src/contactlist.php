<?php
// contactlist.php -- HotCRP helper class for producing lists of contacts
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ContactList {

    const FIELD_SELECTOR = 1000;
    const FIELD_SELECTOR_ON = 1001;

    const FIELD_NAME = 1;
    const FIELD_EMAIL = 2;
    const FIELD_AFFILIATION = 3;
    const FIELD_LASTVISIT = 5;
    const FIELD_HIGHTOPICS = 6;
    const FIELD_LOWTOPICS = 7;
    const FIELD_REVIEWS = 8;
    const FIELD_PAPERS = 9;
    const FIELD_REVIEW_PAPERS = 10;
    const FIELD_AFFILIATION_ROW = 11;
    const FIELD_REVIEW_RATINGS = 12;
    const FIELD_LEADS = 13;
    const FIELD_SHEPHERDS = 14;
    const FIELD_TAGS = 15;
    const FIELD_COLLABORATORS = 16;
    const FIELD_SCORE = 50;

    public static $folds = array("topics", "aff", "tags", "collab");

    var $showHeader = true;
    var $sortField = null;
    var $reverseSort;
    var $sortable;
    var $count;
    var $any;
    var $contact;
    private $tagger;
    var $scoreMax;
    var $limit;
    public $have_folds = array();
    var $listNumber;
    var $contactLinkArgs;

    function __construct($contact, $sortable = true) {
        global $contactListFields;

        $s = ($sortable ? defval($_REQUEST, "sort", "") : "");
        $x = (strlen($s) ? $s[strlen($s)-1] : "");
        $this->reverseSort = ($x == "R");
        if ($x == "R" || $x == "N")
            $s = substr($s, 0, strlen($s) - 1);
        if ($s !== "")
            $this->sortField = $s;
        $this->sortable = $sortable;

        $this->contact = $contact;
        $this->tagger = new Tagger($this->contact);
        $this->contactLinkArgs = "";
        $this->listNumber = $contact->privChair;
    }

    function selector($fieldId, &$queryOptions) {
        global $Conf;
        if (!$this->contact->isPC
            && $fieldId != self::FIELD_NAME
            && $fieldId != self::FIELD_AFFILIATION
            && $fieldId != self::FIELD_AFFILIATION_ROW)
            return false;
        if ($fieldId == self::FIELD_HIGHTOPICS || $fieldId == self::FIELD_LOWTOPICS)
            $this->have_folds["topics"] = $queryOptions["topics"] = true;
        if ($fieldId == self::FIELD_REVIEWS)
            $queryOptions["reviews"] = true;
        if ($fieldId == self::FIELD_LEADS)
            $queryOptions["leads"] = true;
        if ($fieldId == self::FIELD_SHEPHERDS)
            $queryOptions["shepherds"] = true;
        if ($fieldId == self::FIELD_REVIEW_RATINGS) {
            if ($Conf->setting("rev_ratings") == REV_RATINGS_NONE)
                return false;
            $queryOptions["revratings"] = $queryOptions["reviews"] = true;
        }
        if ($fieldId == self::FIELD_PAPERS)
            $queryOptions["papers"] = true;
        if ($fieldId == self::FIELD_REVIEW_PAPERS)
            $queryOptions["repapers"] = $queryOptions["reviews"] = true;
        if ($fieldId == self::FIELD_AFFILIATION_ROW)
            $this->have_folds["aff"] = true;
        if ($fieldId == self::FIELD_TAGS)
            $this->have_folds["tags"] = true;
        if ($fieldId == self::FIELD_COLLABORATORS)
            $this->have_folds["collab"] = true;
        if (($f = $Conf->review_field($fieldId))) {
            // XXX scoresOk
            $revViewScore = $this->contact->aggregated_view_score_bound();
            if ($f->view_score <= $revViewScore
                || !$f->has_options
                || !$this->contact->can_view_aggregated_review_identity())
                return false;
            $queryOptions["reviews"] = true;
            if (!isset($queryOptions["scores"]))
                $queryOptions["scores"] = array();
            $queryOptions["scores"][] = $f->id;
            $this->scoreMax[$f->id] = count($f->options);
        }
        return true;
    }

    function _sortBase($a, $b) {
        $x = strcasecmp($a->lastName, $b->lastName);
        $x = $x ? $x : strcasecmp($a->firstName, $b->firstName);
        return $x ? $x : strcasecmp($a->email, $b->email);
    }

    function _sortEmail($a, $b) {
        return strcasecmp($a->email, $b->email);
    }

    function _sortAffiliation($a, $b) {
        $x = strcasecmp($a->affiliation, $b->affiliation);
        return $x ? $x : self::_sortBase($a, $b);
    }

    function _sortLastVisit($a, $b) {
        $x = $b->lastLogin - $a->lastLogin;
        return $x ? $x : self::_sortBase($a, $b);
    }

    function _sortReviews($a, $b) {
        $x = $b->numReviewsSubmitted - $a->numReviewsSubmitted;
        $x = $x ? $x : $b->numReviews - $a->numReviews;
        return $x ? $x : self::_sortBase($a, $b);
    }

    function _sortLeads($a, $b) {
        $x = $b->numLeads - $a->numLeads;
        return $x ? $x : self::_sortBase($a, $b);
    }

    function _sortShepherds($a, $b) {
        $x = $b->numShepherds - $a->numShepherds;
        return $x ? $x : self::_sortBase($a, $b);
    }

    function _sortReviewRatings($a, $b) {
        global $Conf;
        if ((int) $a->sumRatings != (int) $b->sumRatings)
            return ($a->sumRatings > $b->sumRatings ? -1 : 1);
        if ((int) $a->numRatings != (int) $b->numRatings)
            return ($a->numRatings > $b->numRatings ? 1 : -1);
        return self::_sortBase($a, $b);
    }

    function _sortPapers($a, $b) {
        $x = (int) $a->paperIds - (int) $b->paperIds;
        $x = $x ? $x : strcmp($a->paperIds, $b->paperIds);
        return $x ? $x : self::_sortBase($a, $b);
    }

    function _sortScores($a, $b) {
        $x = $b->_sort_info - $a->_sort_info;
        $x = $x ? : $b->_sort_avg - $a->_sort_avg;
        return $x ? ($x < 0 ? -1 : 1) : self::_sortBase($a, $b);
    }

    function _sort($rows) {
        global $Conf;
        switch ($this->sortField) {
        case self::FIELD_EMAIL:
            usort($rows, array($this, "_sortEmail"));
            break;
        case self::FIELD_AFFILIATION:
        case self::FIELD_AFFILIATION_ROW:
            usort($rows, array($this, "_sortAffiliation"));
            break;
        case self::FIELD_LASTVISIT:
            usort($rows, array($this, "_sortLastVisit"));
            break;
        case self::FIELD_REVIEWS:
            usort($rows, array($this, "_sortReviews"));
            break;
        case self::FIELD_LEADS:
            usort($rows, array($this, "_sortLeads"));
            break;
        case self::FIELD_SHEPHERDS:
            usort($rows, array($this, "_sortShepherds"));
            break;
        case self::FIELD_REVIEW_RATINGS:
            usort($rows, array($this, "_sortReviewRatings"));
            break;
        case self::FIELD_PAPERS:
        case self::FIELD_REVIEW_PAPERS:
            usort($rows, array($this, "_sortPapers"));
            break;
        default:
            if (($f = $Conf->review_field($this->sortField))) {
                $fieldId = $this->sortField;
                $scoreMax = $this->scoreMax[$fieldId];
                $scoresort = $Conf->session("scoresort", "A");
                if ($scoresort != "A" && $scoresort != "V" && $scoresort != "D")
                    $scoresort = "A";
                Contact::$allow_nonexistent_properties = true;
                foreach ($rows as $row) {
                    $scoreinfo = new ScoreInfo(@$row->$fieldId);
                    $row->_sort_info = $scoreinfo->sort_data($scoresort);
                    $row->_sort_avg = $scoreinfo->mean();
                }
                usort($rows, array($this, "_sortScores"));
                Contact::$allow_nonexistent_properties = false;
            }
            break;
        }
        if ($this->reverseSort)
            return array_reverse($rows);
        else
            return $rows;
    }

    function header($fieldId, $ordinal, $row = null) {
        global $Conf;
        switch ($fieldId) {
        case self::FIELD_NAME:
            return "Name";
        case self::FIELD_EMAIL:
            return "Email";
        case self::FIELD_AFFILIATION:
        case self::FIELD_AFFILIATION_ROW:
            return "Affiliation";
        case self::FIELD_LASTVISIT:
            return '<span class="hastitle" title="Includes paper changes, review updates, and profile changes">Last update</span>';
        case self::FIELD_HIGHTOPICS:
            return "High-interest topics";
        case self::FIELD_LOWTOPICS:
            return "Low-interest topics";
        case self::FIELD_REVIEWS:
            return "<span class='hastitle' title='\"1/2\" means 1 complete review out of 2 assigned reviews'>Reviews</span>";
        case self::FIELD_LEADS:
            return "Leads";
        case self::FIELD_SHEPHERDS:
            return "Shepherds";
        case self::FIELD_REVIEW_RATINGS:
            return "<span class='hastitle' title='Ratings of reviews'>Rating</a>";
        case self::FIELD_SELECTOR:
            return "";
        case self::FIELD_PAPERS:
            return "Papers";
        case self::FIELD_REVIEW_PAPERS:
            return "Assigned papers";
        case self::FIELD_TAGS:
            return "Tags";
        case self::FIELD_COLLABORATORS:
            return "Collaborators";
        default:
            if (($f = $Conf->review_field($fieldId)))
                return $f->web_abbreviation();
            else
                return "&lt;$fieldId&gt;?";
        }
    }

    function content($fieldId, $row) {
        global $Conf;
        switch ($fieldId) {
        case self::FIELD_NAME:
            $t = Text::name_html($row);
            if (trim($t) == "")
                $t = "[No name]";
            $t = '<span class="taghl">' . $t . '</span>';
            if ($this->contact->privChair)
                $t = "<a href=\"" . hoturl("profile", "u=" . urlencode($row->email) . $this->contactLinkArgs) . "\"" . ($row->disabled ? " class='uu'" : "") . ">$t</a>";
            if ($row->roles & Contact::ROLE_CHAIR)
                $t .= ' <span class="pcrole">(chair)</span>';
            else if (($row->roles & (Contact::ROLE_ADMIN | Contact::ROLE_PC)) == (Contact::ROLE_ADMIN | Contact::ROLE_PC))
                $t .= ' <span class="pcrole">(PC, sysadmin)</span>';
            else if ($row->roles & Contact::ROLE_ADMIN)
                $t .= ' <span class="pcrole">(sysadmin)</span>';
            else if (($row->roles & Contact::ROLE_PC)
                     && $this->limit != "pc")
                $t .= ' <span class="pcrole">(PC)</span>';
            if ($this->contact->privChair && $row->email != $this->contact->email)
                $t .= " <a href=\"" . hoturl("index", "actas=" . urlencode($row->email)) . "\">"
                    . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($row)))
                    . "</a>";
            if ($row->disabled && $this->contact->isPC)
                $t .= ' <span class="hint">(disabled)</span>';
            return $t;
        case self::FIELD_EMAIL:
            if (!$this->contact->isPC)
                return "";
            $e = htmlspecialchars($row->email);
            if (strpos($row->email, "@") === false)
                return $e;
            else
                return "<a href=\"mailto:$e\">$e</a>";
        case self::FIELD_AFFILIATION:
        case self::FIELD_AFFILIATION_ROW:
            return htmlspecialchars($row->affiliation);
        case self::FIELD_LASTVISIT:
            if (!$row->activity_at)
                return "Never";
            else if ($this->contact->privChair)
                return $Conf->unparse_time_short($row->activity_at);
            else
                return $Conf->unparse_time_obscure($row->activity_at);
        case self::FIELD_SELECTOR:
        case self::FIELD_SELECTOR_ON:
            $this->any->sel = true;
            $c = "";
            if ($fieldId == self::FIELD_SELECTOR_ON)
                $c = " checked='checked'";
            return "<input type='checkbox' class='cb' name='pap[]' value='$row->contactId' tabindex='1' id='psel$this->count' onclick='rangeclick(event,this)' $c/>";
        case self::FIELD_HIGHTOPICS:
        case self::FIELD_LOWTOPICS:
            if (!($topics = get($row, "topicInterest")))
                return "";
            $wanthigh = ($fieldId == self::FIELD_HIGHTOPICS);
            $nt = $nti = [];
            foreach (explode(",", $row->topicInterest) as $tandi)
                if (($pos = strpos($tandi, " "))
                    && ($v = (int) substr($tandi, $pos + 1))
                    && ($wanthigh ? $v > 0 : $v < 0)) {
                    $nt[] = (int) substr($tandi, 0, $pos);
                    $nti[] = $v;
                }
            if (empty($nt))
                return "";
            return PaperInfo::unparse_topic_list_html($Conf, $nt, $nti, true);
        case self::FIELD_REVIEWS:
            if (!$row->numReviews && !$row->numReviewsSubmitted)
                return "";
            $a1 = "<a href=\"" . hoturl("search", "t=s&amp;q=re:" . urlencode($row->email)) . "\">";
            if ($row->numReviews == $row->numReviewsSubmitted)
                return "$a1<b>$row->numReviewsSubmitted</b></a>";
            else
                return "$a1<b>$row->numReviewsSubmitted</b>/$row->numReviews</a>";
        case self::FIELD_LEADS:
            if (!$row->numLeads)
                return "";
            return "<a href=\"" . hoturl("search", "t=s&amp;q=lead:" . urlencode($row->email)) . "\">$row->numLeads</a>";
        case self::FIELD_SHEPHERDS:
            if (!$row->numShepherds)
                return "";
            return "<a href=\"" . hoturl("search", "t=s&amp;q=shepherd:" . urlencode($row->email)) . "\">$row->numShepherds</a>";
        case self::FIELD_REVIEW_RATINGS:
            if ((!$row->numReviews && !$row->numReviewsSubmitted)
                || !$row->numRatings)
                return "";
            $a = array();
            $b = array();
            if ($row->sumRatings > 0) {
                $a[] = $row->sumRatings . " positive";
                $b[] = "<a href=\"" . hoturl("search", "q=re:" . urlencode($row->email) . "+rate:%2B") . "\">+" . $row->sumRatings . "</a>";
            }
            if ($row->sumRatings < $row->numRatings) {
                $a[] = ($row->numRatings - $row->sumRatings) . " negative";
                $b[] = "<a href=\"" . hoturl("search", "q=re:" . urlencode($row->email) . "+rate:-") . "\">&minus;" . ($row->numRatings - $row->sumRatings) . "</a>";
            }
            return "<span class='hastitle' title='" . join(", ", $a) . "'>" . join(" ", $b) . "</span>";
        case self::FIELD_PAPERS:
            if (!$row->paperIds)
                return "";
            $x = explode(",", $row->paperIds);
            sort($x, SORT_NUMERIC);
            foreach ($x as &$v)
                $v = '<a href="' . hoturl("paper", "p=$v") . '">' . $v . '</a>';
            $ls = "p/s/";
            if ($this->limit == "auuns" || $this->limit == "all")
                $ls = "p/all/";
            $ls = htmlspecialchars($ls . urlencode("au:" . $row->email));
            return '<div class="has_hotcrp_list" data-hotcrp-list="' . $ls . '">'
                . join(", ", $x) . '</div>';
        case self::FIELD_REVIEW_PAPERS:
            if (!$row->paperIds)
                return "";
            $pids = explode(",", $row->paperIds);
            $rids = explode(",", $row->reviewIds);
            $ords = explode(",", $row->reviewOrdinals);
            $spids = $pids;
            sort($spids, SORT_NUMERIC);
            $m = array();
            for ($i = 0; $i != count($pids); ++$i) {
                if ($ords[$i])
                    $url = hoturl("paper", "p=" . $pids[$i] . "#r" . $pids[$i] . unparseReviewOrdinal($ords[$i]));
                else
                    $url = hoturl("review", "p=" . $pids[$i] . "&amp;r=" . $rids[$i]);
                $m[$pids[$i]] = "<a href=\"$url\">" . $pids[$i] . "</a>";
            }
            ksort($m, SORT_NUMERIC);
            $ls = htmlspecialchars("p/s/" . urlencode("re:" . $row->email));
            return '<div class="has_hotcrp_list" data-hotcrp-list="' . $ls . '">'
                . join(", ", $m) . '</div>';
        case self::FIELD_TAGS:
            if ($this->contact->isPC
                && ($tags = $row->viewable_tags($this->contact))) {
                $x = [];
                foreach (TagInfo::split($tags) as $t)
                    $x[] = '<a class="qq nw" href="' . hoturl("users", "t=%23" . TagInfo::base($t)) . '">' . $this->tagger->unparse_hashed($t) . '</a>';
                return join(" ", $x);
            }
            return "";
        case self::FIELD_COLLABORATORS:
            if (!$this->contact->isPC || !($row->roles & Contact::ROLE_PC))
                return "";
            $t = array();
            foreach (explode("\n", $row->collaborators) as $collab) {
                if (preg_match(',\A(.*?)\s*(\(.*\))\s*\z,', $collab, $m))
                    $t[] = '<span class="nw">' . htmlspecialchars($m[1])
                        . ' <span class="auaff">' . htmlspecialchars($m[2]) . '</span></span>';
                else if (($collab = trim($collab)) !== "" && strcasecmp($collab, "None"))
                    $t[] = '<span class="nw">' . htmlspecialchars($collab) . '</span>';
            }
            return join("; ", $t);
        default:
            $f = $Conf->review_field($fieldId);
            if (!$f)
                return "";
            if (!($row->roles & Contact::ROLE_PC)
                && !$this->contact->privChair
                && $this->limit != "req")
                return "";
            $v = scoreCounts($row->$fieldId, $this->scoreMax[$fieldId]);
            $m = "";
            if ($v->n > 0)
                $m = $f->unparse_graph($v, 2, 0);
            return $m;
        }
    }

    function addScores($a) {
        global $Conf;
        if ($this->contact->isPC) {
            foreach ($Conf->all_review_fields() as $f)
                if ($f->has_options
                    && strpos(displayOptionsSet("uldisplay"), " {$f->id} ") !== false)
                    array_push($a, $f->id);
            $this->scoreMax = array();
        }
        return $a;
    }

    function listFields($listname) {
        switch ($listname) {
        case "pc":
        case "admin":
        case "pcadmin":
            return $this->addScores(array($listname, self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_RATINGS, self::FIELD_LEADS, self::FIELD_SHEPHERDS));
        case "pcadminx":
            return array($listname, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS);
          case "re":
          case "resub":
            return $this->addScores(array($listname, self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_RATINGS));
          case "ext":
          case "extsub":
            return $this->addScores(array($listname, self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_RATINGS, self::FIELD_REVIEW_PAPERS));
          case "req":
            return $this->addScores(array("req", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_RATINGS, self::FIELD_REVIEW_PAPERS));
          case "au":
          case "aurej":
          case "auacc":
          case "auuns":
            return array($listname, self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION_ROW, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_PAPERS, self::FIELD_COLLABORATORS);
          case "all":
            return array("all", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION_ROW, self::FIELD_LASTVISIT, self::FIELD_TAGS, self::FIELD_PAPERS, self::FIELD_COLLABORATORS);
          default:
            return null;
        }
    }

    function footer($ncol, $hascolors) {
        global $Conf;
        if ($this->count == 0)
            return "";

        $t = "  <tfoot class=\"pltable" . ($hascolors ? " pltable_colored" : "")
            . "\"><tr class=\"pl_footrow\">\n    <td class=\"pl_footselector\">"
            . Ht::img("_.gif", "^^", "placthook")
            . "</td>\n  <td class=\"pl_footer\" colspan=\"" . ($ncol - 1) . '">'
            . '<div id="plact" class="linelinks1">'
            . '<a name="plact"><b>Select people</b></a> (or <a href="#" onclick="return papersel(true)">select all ' . $this->count . "</a>), then ";

        // Begin linelinks
        $types = array("nameemail" => "Names and emails");
        if ($this->contact->privChair)
            $types["pcinfo"] = "PC info";
        $nlll = 1;
        $t .= "<span class=\"lll{$nlll}\"><a class=\"lla{$nlll}\" href=\"#\" onclick=\"return crpfocus('plact',{$nlll})\">Download</a></span><span class=\"lld{$nlll}\"><b>:</b> &nbsp;"
            . Ht::select("getaction", $types, null, array("id" => "plact{$nlll}_d"))
            . "&nbsp; " . Ht::submit("getgo", "Go", array("class" => "bsm"))
            . "</span>";

        $barsep = " <span class='barsep'>·</span> ";
        if ($this->contact->privChair) {
            ++$nlll;
            $t .= $barsep;
            $t .= "<span class=\"lll{$nlll}\"><a class=\"lla{$nlll}\" href=\"#\" onclick=\"return crpfocus('plact',{$nlll})\">Tag</a></span><span class=\"lld{$nlll}\"><b>:</b> &nbsp;";
            $t .= Ht::select("tagtype", array("a" => "Add", "d" => "Remove", "s" => "Define"), @$_REQUEST["tagtype"])
                . ' &nbsp;tag(s) &nbsp;'
                . Ht::entry("tag", @$_REQUEST["tag"],
                            array("id" => "plact{$nlll}_d", "size" => 15,
                                  "onfocus" => "autosub('tagact',this)"))
                . ' &nbsp;' . Ht::submit("tagact", "Go") . '</span>';

            ++$nlll;
            $t .= $barsep;
            $t .= "<span class=\"lll{$nlll}\"><a class=\"lla{$nlll}\" href=\"#\" onclick=\"return crpfocus('plact',{$nlll})\">Modify</a></span><span class=\"lld{$nlll}\"><b>:</b> &nbsp;";
            $t .= Ht::select("modifytype", array("disableaccount" => "Disable",
                                                 "enableaccount" => "Enable",
                                                 "resetpassword" => "Reset password",
                                                 "sendaccount" => "Send account information"),
                             null, array("id" => "plact{$nlll}_d"))
                . "&nbsp; " . Ht::submit("modifygo", "Go", array("class" => "bsm")) . "</span>";
        }

        return $t . "</div></td></tr></tfoot>\n";
    }

    function _rows($queryOptions) {
        global $Conf;

        // XXX This section is a bit of a mess. We don't always obey the
        // visibility restrictions in Contact. Most of the time (but probably
        // not always) we are more restricted: for instance, conflicted PC
        // members never see reviewers for their papers, even if reviewing is
        // not anonymous. Hard to see this as worth fixing.

        // load conflicted papers
        $cfltpids = [];
        if (!$this->contact->privChair)
            $cfltpids = Dbl::fetch_first_columns($Conf->dblink, "select paperId from PaperConflict where contactId=?", $this->contact->contactId);
        $pid_restriction = "";
        if (!empty($pid_restriction))
            $pid_restriction = " and paperId not in (" . join(",", $cfltpids) . ")";

        $aulimit = (strlen($this->limit) >= 2 && $this->limit[0] == 'a' && $this->limit[1] == 'u');
        $rf = ["contactId"];
        $pq = "select u.contactId,
        firstName, lastName, email, affiliation, roles, contactTags,
        voicePhoneNumber, u.collaborators, lastLogin, disabled";
        if (isset($queryOptions['topics']))
            $pq .= ",\n    (select group_concat(topicId, ' ', interest) from TopicInterest where contactId=u.contactId) topicInterest";
        if (isset($queryOptions["reviews"])) {
            $rf[] = "count(if(reviewNeedsSubmit<=0,reviewSubmitted,reviewId)) numReviews";
            $rf[] = "count(reviewSubmitted) numReviewsSubmitted";
            $pq .= ", numReviews, numReviewsSubmitted";
        }
        if (isset($queryOptions["revratings"]))
            $pq .= ", numRatings, sumRatings";
        if (isset($queryOptions["leads"]))
            $pq .= ",\n    (select count(paperId) from Paper where leadContactId=u.contactId$pid_restriction) numLeads";
        if (isset($queryOptions["shepherds"]))
            $pq .= ",\n    (select count(paperId) from Paper where shepherdContactId=u.contactId$pid_restriction) numShepherds";
        if (isset($queryOptions['scores']))
            foreach ($queryOptions['scores'] as $score) {
                $rf[] = "group_concat(if(reviewSubmitted>0,$score,null)) $score";
                $pq .= ", $score";
            }
        if (isset($queryOptions["repapers"])) {
            $rf[] = "group_concat(r.paperId) paperIds";
            $rf[] = "group_concat(reviewId) reviewIds";
            $rf[] = "group_concat(coalesce(reviewOrdinal,0)) reviewOrdinals";
            $pq .= ", paperIds, reviewIds, reviewOrdinals";
        } else if (isset($queryOptions["papers"]))
            $pq .= ",\n\t(select group_concat(paperId) from PaperConflict where contactId=u.contactId and conflictType>=" . CONFLICT_AUTHOR . ") paperIds";

        $pq .= "\n      from ContactInfo u\n";
        if (isset($queryOptions["reviews"])) {
            $j = "left join";
            if ($this->limit == "re" || $this->limit == "req" || $this->limit == "ext" || $this->limit == "resub" || $this->limit == "extsub")
                $j = "join";
            $pq .= "    $j (select " . join(", ", $rf)
                . "\n\t\tfrom PaperReview r join Paper p on (p.paperId=r.paperId)";
            $jwhere = array();
            if ($this->limit == "req" || $this->limit == "ext" || $this->limit == "extsub")
                $jwhere[] = "r.reviewType=" . REVIEW_EXTERNAL;
            if ($this->limit == "req")
                $jwhere[] = "r.requestedBy=" . $this->contact->contactId;
            if ($pid_restriction)
                $jwhere[] = "(r.paperId not in (" . join(",", $cfltpids) . ") or r.contactId=" . $this->contact->contactId . ")";
            $jwhere[] = "(p.timeSubmitted>0 or r.reviewSubmitted>0)";
            if (count($jwhere))
                $pq .= "\n\t\twhere " . join(" and ", $jwhere);
            $pq .= " group by r.contactId) as r on (r.contactId=u.contactId)\n";
        }
        if (isset($queryOptions["revratings"])) {
            $pq .= "    left join (select PaperReview.contactId, count(rating) numRatings, sum(if(rating>0,1,0)) sumRatings
                from ReviewRating
                join PaperReview on (PaperReview.paperId=ReviewRating.paperId and PaperReview.reviewId=ReviewRating.reviewId)";
            $jwhere = [];
            if (($badratings = PaperSearch::unusableRatings($this->contact)))
                $jwhere[] = "ReviewRating.reviewId not in (" . join(",", $badratings) . ")";
            if ($pid_restriction)
                $jwhere[] = "ReviewRating.paperId not in (" . join(",", $cfltpids) . ")";
            if (!empty($jwhere))
                $pq .= "\n\t\twhere " . join(" and ", $jwhere);
            $pq .= "\n\t\tgroup by PaperReview.contactId) as rr on (rr.contactId=u.contactId)\n";
        }
        if ($aulimit) {
            $limitselect = "select contactId from PaperConflict join Paper on (Paper.paperId=PaperConflict.paperId) where conflictType>=" . CONFLICT_AUTHOR;
            if ($this->limit == "au")
                $limitselect .= " and timeSubmitted>0";
            else if ($this->limit == "aurej")
                $limitselect .= " and outcome<0";
            else if ($this->limit == "auacc")
                $limitselect .= " and outcome>0";
            else if ($this->limit == "auuns")
                $limitselect .= " and timeSubmitted<=0";
            $pq .= "join ($limitselect group by contactId) as au on (au.contactId=u.contactId)\n";
        }

        $mainwhere = array();
        if (isset($queryOptions["where"]))
            $mainwhere[] = $queryOptions["where"];
        if ($this->limit == "pc")
            $mainwhere[] = "u.roles!=0 and (u.roles&" . Contact::ROLE_PC . ")!=0";
        if ($this->limit == "admin")
            $mainwhere[] = "u.roles!=0 and (u.roles&" . (Contact::ROLE_ADMIN | Contact::ROLE_CHAIR) . ")!=0";
        if ($this->limit == "pcadmin" || $this->limit == "pcadminx")
            $mainwhere[] = "u.roles!=0 and (u.roles&" . Contact::ROLE_PCLIKE . ")!=0";
        if (count($mainwhere))
            $pq .= "\twhere " . join(" and ", $mainwhere) . "\n";

        $pq .= " order by lastName, firstName, email";

        // make query
        $result = $Conf->qe_raw($pq);
        if (!$result)
            return NULL;

        // fetch data
        Contact::$allow_nonexistent_properties = true;
        $rows = array();
        while (($row = Contact::fetch($result)))
            $rows[] = $row;
        Contact::$allow_nonexistent_properties = false;
        return $rows;
    }

    function table_html($listname, $url, $listtitle = "", $foldsession = null) {
        global $Conf, $contactListFields;

        // PC tags
        $listquery = $listname;
        $queryOptions = array();
        if (str_starts_with($listname, "#")) {
            $queryOptions["where"] = "(u.contactTags like " . Dbl::utf8ci("'% " . sqlq_for_like(substr($listname, 1)) . "#%'") . ")";
            $listquery = "pc";
        }

        // get paper list
        if (!($baseFieldId = $this->listFields($listquery))) {
            Conf::msg_error("There is no people list query named “" . htmlspecialchars($listquery) . "”.");
            return null;
        }
        $this->limit = array_shift($baseFieldId);

        // get field array
        $fieldDef = array();
        $acceptable_fields = array();
        $this->any = (object) array("sel" => false);
        $ncol = 0;
        foreach ($baseFieldId as $fid) {
            if ($this->selector($fid, $queryOptions) === false)
                continue;
            if (!($fieldDef[$fid] = @$contactListFields[$fid]))
                $fieldDef[$fid] = $contactListFields[self::FIELD_SCORE];
            $acceptable_fields[$fid] = true;
            if ($fieldDef[$fid][1] == 1)
                $ncol++;
        }

        // run query
        $rows = $this->_rows($queryOptions);
        if (!$rows || count($rows) == 0)
            return "No matching people";

        // list number
        if ($this->listNumber === true) {
            $this->listNumber = SessionList::allocate("u/" . $this->limit);
            $this->contactLinkArgs .= "&amp;ls=" . $this->listNumber;
        }

        // sort rows
        if (!@$acceptable_fields[$this->sortField])
            $this->sortField = null;
        $srows = $this->_sort($rows);

        // count non-callout columns
        $firstcallout = $lastcallout = null;
        $n = 0;
        foreach ($fieldDef as $fieldId => $fdef)
            if ($fdef[1] == 1) {
                if ($firstcallout === null && $fieldId < self::FIELD_SELECTOR)
                    $firstcallout = $n;
                if ($fieldId < self::FIELD_SCORE)
                    $lastcallout = $n + 1;
                ++$n;
            }
        $firstcallout = $firstcallout ? $firstcallout : 0;
        $lastcallout = ($lastcallout ? $lastcallout : $ncol) - $firstcallout;

        // collect row data
        $this->count = 0;
        $show_colors = $this->contact->isPC;

        $anyData = array();
        $body = '';
        $extrainfo = $hascolors = false;
        $ids = array();
        foreach ($srows as $row) {
            if (($this->limit == "resub" || $this->limit == "extsub")
                && $row->numReviewsSubmitted == 0)
                continue;

            $trclass = "k" . ($this->count % 2);
            if ($show_colors && ($m = $row->viewable_color_classes($this->contact))) {
                if (TagInfo::classes_have_colors($m)) {
                    $trclass = $m;
                    $hascolors = true;
                } else
                    $trclass .= " $m";
            }
            if ($row->disabled && $this->contact->isPC)
                $trclass .= " graytext";
            $this->count++;
            $ids[] = (int) $row->contactId;

            // First create the expanded callout row
            $tt = "";
            foreach ($fieldDef as $fieldId => $fdef)
                if ($fdef[1] >= 2
                    && ($d = $this->content($fieldId, $row)) !== "") {
                    $tt .= "<div";
                    //$t .= "  <tr class=\"pl_$fdef[0] pl_callout $trclass";
                    if ($fdef[1] >= 3)
                        $tt .= " class=\"fx" . ($fdef[1] - 2) . "\"";
                    $tt .= '><em class="plx">' . $this->header($fieldId, -1, $row)
                        . ":</em> " . $d . "</div>";
                }

            if ($tt !== "") {
                $x = "  <tr class=\"plx $trclass\">";
                if ($firstcallout > 0)
                    $x .= "<td colspan=\"$firstcallout\"></td>";
                $tt = $x . "<td class=\"plx\" colspan=\"" . ($lastcallout - $firstcallout)
                    . "\">" . $tt . "</td></tr>\n";
            }

            // Now the normal row
            $t = "  <tr class=\"pl $trclass\">\n";
            $n = 0;
            foreach ($fieldDef as $fieldId => $fdef)
                if ($fdef[1] == 1) {
                    $c = $this->content($fieldId, $row);
                    $t .= "    <td class=\"pl pl_$fdef[0]\"";
                    if ($n >= $lastcallout && $tt != "")
                        $t .= " rowspan=\"2\"";
                    $t .= ">" . $c . "</td>\n";
                    if ($c != "")
                        $anyData[$fieldId] = 1;
                    ++$n;
                }
            $t .= "  </tr>\n";

            $body .= $t . $tt;
        }

        $foldclasses = array();
        foreach (self::$folds as $k => $fold)
            if (@$this->have_folds[$fold] !== null) {
                $this->have_folds[$fold] = strpos(displayOptionsSet("uldisplay"), " $fold ") !== false;
                $foldclasses[] = "fold" . ($k + 1) . ($this->have_folds[$fold] ? "o" : "c");
            }

        $x = "<table id=\"foldul\" class=\"pltable pltable_full plt_" . htmlspecialchars($listquery);
        if ($foldclasses)
            $x .= " " . join(" ", $foldclasses);
        if ($foldclasses && $foldsession)
            $x .= "\" data-fold-session=\"$foldsession";
        $x .= "\">\n";

        if ($this->showHeader) {
            $x .= "  <thead class=\"pltable\">\n  <tr class=\"pl_headrow\">\n";
            $ord = 0;

            if ($this->sortable && $url) {
                $sortUrl = htmlspecialchars($url) . (strpos($url, "?") ? "&amp;" : "?") . "sort=";
                $q = '<a class="pl_sort" rel="nofollow" href="' . $sortUrl;
                foreach ($fieldDef as $fieldId => $fdef) {
                    if ($fdef[1] != 1)
                        continue;
                    else if (!isset($anyData[$fieldId])) {
                        $x .= "    <th class=\"pl pl_$fdef[0]\"></th>\n";
                        continue;
                    }
                    $x .= "    <th class=\"pl pl_$fdef[0]\">";
                    $ftext = $this->header($fieldId, $ord++);
                    if ($this->sortField == null && $fieldId == 1)
                        $this->sortField = $fieldId;
                    if ($fieldId == $this->sortField)
                        $x .= '<a class="pl_sort_def' . ($this->reverseSort ? "_rev" : "") . '" rel="nofollow" href="' . $sortUrl . $fieldId . ($this->reverseSort ? "N" : "R") . '">' . $ftext . "</a>";
                    else if ($fdef[2])
                        $x .= $q . $fieldId . "\">" . $ftext . "</a>";
                    else
                        $x .= $ftext;
                    $x .= "</th>\n";
                }

            } else {
                foreach ($fieldDef as $fieldId => $fdef)
                    if ($fdef[1] == 1 && isset($anyData[$fieldId]))
                        $x .= "    <th class=\"pl pl_$fdef[0]\">"
                            . $this->header($fieldId, $ord++) . "</th>\n";
                    else if ($fdef[1] == 1)
                        $x .= "    <th class=\"pl pl_$fdef[0]\"></th>\n";
            }

            $x .= "  </tr></thead>\n";
        }

        reset($fieldDef);
        if (key($fieldDef) == self::FIELD_SELECTOR)
            $x .= $this->footer($ncol, $hascolors);

        $x .= "<tbody class=\"pltable" . ($hascolors ? " pltable_colored" : "")
            . "\">" . $body . "</tbody></table>";

        if ($this->listNumber) {
            $l = SessionList::create("u/" . $listname, $ids,
                                     ($listtitle ? $listtitle : "Users"),
                                     hoturl_site_relative_raw("users", ["t" => $listname]));
            SessionList::change($this->listNumber, $l);
        }

        return $x;
    }

    function rows($listname) {
        // PC tags
        $queryOptions = array();
        if (str_starts_with($listname, "#")) {
            $queryOptions["where"] = "(u.contactTags like " . Dbl::utf8ci("'% " . sqlq_for_like(substr($listname, 1)) . "#%'") . ")";
            $listname = "pc";
        }

        // get paper list
        if (!($baseFieldId = $this->listFields($listname))) {
            Conf::msg_error("There is no people list query named “" . htmlspecialchars($listname) . "”.");
            return null;
        }
        $this->limit = array_shift($baseFieldId);

        // run query
        return $this->_rows($queryOptions);
    }

}


global $contactListFields;
$contactListFields = array(
        ContactList::FIELD_SELECTOR => array('sel', 1, 0),
        ContactList::FIELD_SELECTOR_ON => array('sel', 1, 0),
        ContactList::FIELD_NAME => array('name', 1, 1),
        ContactList::FIELD_EMAIL => array('email', 1, 1),
        ContactList::FIELD_AFFILIATION => array('affiliation', 1, 1),
        ContactList::FIELD_AFFILIATION_ROW => array('affrow', 4, 0),
        ContactList::FIELD_LASTVISIT => array('lastvisit', 1, 1),
        ContactList::FIELD_HIGHTOPICS => array('topics', 3, 0),
        ContactList::FIELD_LOWTOPICS => array('topics', 3, 0),
        ContactList::FIELD_REVIEWS => array('revstat', 1, 1),
        ContactList::FIELD_REVIEW_RATINGS => array('revstat', 1, 1),
        ContactList::FIELD_PAPERS => array('papers', 1, 1),
        ContactList::FIELD_REVIEW_PAPERS => array('papers', 1, 1),
        ContactList::FIELD_SCORE => array('uscores', 1, 1),
        ContactList::FIELD_LEADS => array('revstat', 1, 1),
        ContactList::FIELD_SHEPHERDS => array('revstat', 1, 1),
        ContactList::FIELD_TAGS => array('tags', 5, 0),
        ContactList::FIELD_COLLABORATORS => array('collab', 6, 0)
        );
