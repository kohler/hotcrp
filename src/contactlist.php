<?php
// contactlist.php -- HotCRP helper class for producing lists of contacts
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
require_once("$ConfSitePATH/src/baselist.php");

class ContactList extends BaseList {

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
    const FIELD_NUMSCORES = 11;

    public static $folds = array("topics", "aff", "tags", "collab");

    var $showHeader;
    var $sortField;
    var $reverseSort;
    var $sortable;
    var $count;
    var $any;
    var $contact;
    var $scoreMax;
    var $limit;
    public $have_folds = array();
    var $listNumber;
    var $contactLinkArgs;

    function __construct($contact, $sortable = true) {
        global $contactListFields;
        $this->showHeader = true;

        $s = ($sortable ? defval($_REQUEST, "sort", "") : "");
        $x = (strlen($s) ? $s[strlen($s)-1] : "");
        $this->reverseSort = ($x == "R");
        if ($x == "R" || $x == "N")
            $s = substr($s, 0, strlen($s) - 1);
        if (("x" . (int) $s) == ("x" . $s))
            $this->sortField = (int) $s;
        else
            $this->sortField = null;
        $this->sortable = $sortable;

        $this->contact = $contact;
        $this->contactLinkArgs = "";
        $this->listNumber = $contact->privChair;
    }

    function _normalizeField($fieldId) {
        if ($fieldId >= self::FIELD_SCORE && $fieldId < self::FIELD_SCORE + self::FIELD_NUMSCORES)
            return self::FIELD_SCORE;
        else
            return $fieldId;
    }

    function selector($fieldId, &$queryOptions) {
        global $Conf, $reviewScoreNames;
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
            $queryOptions['papers'] = true;
        if ($fieldId == self::FIELD_REVIEW_PAPERS)
            $queryOptions["repapers"] = $queryOptions["reviews"] = true;
        if ($fieldId == self::FIELD_AFFILIATION_ROW)
            $this->have_folds["aff"] = true;
        if ($fieldId == self::FIELD_TAGS)
            $this->have_folds["tags"] = true;
        if ($fieldId == self::FIELD_COLLABORATORS)
            $this->have_folds["collab"] = true;
        if (self::_normalizeField($fieldId) == self::FIELD_SCORE) {
            // XXX scoresOk
            $score = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
            $revViewScore = $this->contact->aggregated_view_score_bound();
            $f = ReviewForm::field($score);
            if ($f->view_score <= $revViewScore
                || !$f->has_options
                || !$this->contact->can_view_aggregated_review_identity())
                return false;
            $queryOptions["reviews"] = true;
            if (!isset($queryOptions["scores"]))
                $queryOptions["scores"] = array();
            $queryOptions["scores"][] = $score;
            $this->scoreMax[$score] = count($f->options);
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
        global $Conf, $reviewScoreNames;
        switch (self::_normalizeField($this->sortField)) {
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
        case self::FIELD_SCORE:
            $scoreName = $reviewScoreNames[$this->sortField - self::FIELD_SCORE];
            $scoreMax = $this->scoreMax[$scoreName];
            $scoresort = $Conf->session("pplscoresort", "A");
            if ($scoresort != "A" && $scoresort != "V" && $scoresort != "D")
                $scoresort = "A";
            foreach ($rows as $row) {
                $scoreinfo = new ScoreInfo(@$row->$scoreName);
                $row->_sort_info = $scoreinfo->sort_data($scoresort);
                $row->_sort_avg = $scoreinfo->average();
            }
            usort($rows, array($this, "_sortScores"));
            break;
        }
        if ($this->reverseSort)
            return array_reverse($rows);
        else
            return $rows;
    }

    function header($fieldId, $ordinal, $row = null) {
        global $reviewScoreNames;
        switch (self::_normalizeField($fieldId)) {
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
        case self::FIELD_SCORE: {
            $scoreName = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
            return ReviewForm::field($scoreName)->web_abbreviation();
        }
        default:
            return "&lt;$fieldId&gt;?";
        }
    }

    function content($fieldId, $row) {
        global $Conf, $reviewScoreNames;
        switch (self::_normalizeField($fieldId)) {
        case self::FIELD_NAME:
            $t = Text::name_html($row);
            if (trim($t) == "")
                $t = "[No name]";
            if ($this->contact->privChair)
                $t = "<a href=\"" . hoturl("profile", "u=" . urlencode($row->email) . $this->contactLinkArgs) . "\"" . ($row->disabled ? " class='uu'" : "") . ">$t</a>";
            if ($row->roles & Contact::ROLE_CHAIR)
                $t .= " <span class='pcrole'>(Chair)</span>";
            else if (($row->roles & (Contact::ROLE_ADMIN | Contact::ROLE_PC)) == (Contact::ROLE_ADMIN | Contact::ROLE_PC))
                $t .= " <span class='pcrole'>(PC, sysadmin)</span>";
            else if ($row->roles & Contact::ROLE_ADMIN)
                $t .= " <span class='pcrole'>(Sysadmin)</span>";
            else if (($row->roles & Contact::ROLE_PC)
                     && $this->limit != "pc")
                $t .= " <span class='pcrole'>(PC)</span>";
            if ($this->contact->privChair && $row->email != $this->contact->email)
                $t .= " <a href=\"" . hoturl("index", "actas=" . urlencode($row->email)) . "\">"
                    . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($row)))
                    . "</a>";
            if ($row->disabled)
                $t .= " <span class='hint'>(disabled)</span>";
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
            if (!$row->lastLogin)
                return "Never";
            return $Conf->printableTimeShort($row->lastLogin);
        case self::FIELD_SELECTOR:
        case self::FIELD_SELECTOR_ON:
            $this->any->sel = true;
            $c = "";
            if ($fieldId == self::FIELD_SELECTOR_ON)
                $c = " checked='checked'";
            return "<input type='checkbox' class='cb' name='pap[]' value='$row->contactId' tabindex='1' id='psel$this->count' onclick='rangeclick(event,this)' $c/>";
        case self::FIELD_HIGHTOPICS:
        case self::FIELD_LOWTOPICS:
            if (!defval($row, "topicIds"))
                return "";
            $wanthigh = ($fieldId == self::FIELD_HIGHTOPICS);
            $topics = array_combine(explode(",", $row->topicIds), explode(",", $row->topicInterest));
            $nt = $nti = array();
            foreach ($topics as $k => $v)
                if ($wanthigh ? $v > 0 : $v < 0) {
                    $nt[] = $k;
                    $nti[] = $v;
                }
            if (count($nt))
                return join(", ", PaperInfo::unparse_topics($nt, $nti));
            else
                return "";
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
            if ($this->limit == "auuns" || $this->limit == "all")
                $extra = "&amp;ls=" . urlencode("p/all/" . join(" ", $x));
            else
                $extra = "&amp;ls=" . urlencode("p/s/" . join(" ", $x));
            $m = array();
            foreach ($x as $v)
                $m[] = "<a href=\"" . hoturl("paper", "p=$v$extra") . "\">$v</a>";
            return join(", ", $m);
        case self::FIELD_REVIEW_PAPERS:
            if (!$row->paperIds)
                return "";
            $pids = explode(",", $row->paperIds);
            $rids = explode(",", $row->reviewIds);
            $ords = explode(",", $row->reviewOrdinals);
            $spids = $pids;
            sort($spids, SORT_NUMERIC);
            $extra = "&amp;ls=" . urlencode("p/s/" . join(" ", $spids));
            $m = array();
            for ($i = 0; $i != count($pids); ++$i) {
                if ($ords[$i])
                    $url = hoturl("paper", "p=" . $pids[$i] . "$extra#review" . $pids[$i] . unparseReviewOrdinal($ords[$i]));
                else
                    $url = hoturl("review", "p=" . $pids[$i] . "&amp;r=" . $rids[$i] . $extra);
                $m[$pids[$i]] = "<a href=\"$url\">" . $pids[$i] . "</a>";
            }
            ksort($m, SORT_NUMERIC);
            return join(", ", $m);
        case self::FIELD_TAGS:
            if (!$this->contact->isPC || !$row->contactTags)
                return "";
            $t = $row->contactTags;
            if (strpos($t, "~") !== false) {
                $t = str_replace(" " . $this->contact->contactId . "~", " ~", " $t ");
                if ($this->contact->privChair)
                    $t = trim(preg_replace('/ \d+~\S+/', '', $t));
                else
                    $t = trim(preg_replace('/ [\d~]+~\S+/', '', $t));
            }
            return trim($t);
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
        case self::FIELD_SCORE:
            if (!($row->roles & Contact::ROLE_PC)
                && !$this->contact->privChair
                && $this->limit != "req")
                return "";
            $scoreName = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
            $v = scoreCounts($row->$scoreName, $this->scoreMax[$scoreName]);
            $m = "";
            if ($v->n > 0)
                $m = ReviewForm::field($scoreName)->unparse_graph($v, 2, 0);
            return $m;
        default:
            return "";
        }
    }

    function addScores($a) {
        global $Conf;
        if ($this->contact->isPC) {
            $scores = $Conf->session("pplscores", 1);
            for ($i = 0; $i < self::FIELD_NUMSCORES; $i++)
                if ($scores & (1 << $i))
                    array_push($a, self::FIELD_SCORE + $i);
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
            return $this->addScores(array($listname, self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_RATINGS));
          case "ext":
          case "extsub":
            return $this->addScores(array($listname, self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_RATINGS, self::FIELD_REVIEW_PAPERS));
          case "req":
            return $this->addScores(array("req", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_LASTVISIT, self::FIELD_COLLABORATORS, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_RATINGS, self::FIELD_REVIEW_PAPERS));
          case "au":
          case "aurej":
          case "auacc":
          case "auuns":
            return array($listname, self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION_ROW, self::FIELD_LASTVISIT, self::FIELD_PAPERS, self::FIELD_COLLABORATORS);
          case "all":
            return array("all", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION_ROW, self::FIELD_LASTVISIT, self::FIELD_PAPERS, self::FIELD_COLLABORATORS);
          default:
            return null;
        }
    }

    function footer($ncol) {
        global $Conf;
        if ($this->count == 0)
            return "";

        $t = "  <tfoot class=\"pltable\"><tr class='pl_footrow'>\n    <td class='pl_footselector' style='vertical-align: top'>"
            . Ht::img("_.gif", "^^", array("class" => "placthook")) . "</td>\n";
        $t .= "    <td id='pplact' class='pl_footer linelinks1' colspan='" . ($ncol - 1) . "'><b>Select people</b> (or <a href='javascript:void papersel(true)'>select all " . $this->count . "</a>), then ";

        // Begin linelinks
        $types = array("nameemail" => "Names and emails");
        if ($this->contact->privChair)
            $types["pcinfo"] = "PC info";
        $t .= "<span class='lll1'><a href='#' onclick='return crpfocus(\"pplact\",1)'>Download</a></span><span class='lld1'><b>:</b> &nbsp;"
            . Ht::select("getaction", $types, null, array("id" => "pplact1_d"))
            . "&nbsp; " . Ht::submit("getgo", "Go", array("class" => "bsm"))
            . "</span>";

        $barsep = " <span class='barsep'>·</span> ";
        if ($this->contact->privChair) {
            $t .= $barsep;
            $t .= "<span class='lll3'><a href='#' onclick='return crpfocus(\"pplact\",3)'>Modify</a></span><span class='lld3'><b>:</b> &nbsp;";
            $t .= Ht::select("modifytype", array("disableaccount" => "Disable",
                                                 "enableaccount" => "Enable",
                                                 "resetpassword" => "Reset password",
                                                 "sendaccount" => "Send account information"),
                             null, array("id" => "pplact3_d"))
                . "&nbsp; " . Ht::submit("modifygo", "Go", array("class" => "bsm")) . "</span>";
        }

        return $t . "</td></tr></tfoot>\n";
    }

    function _rows($queryOptions) {
        global $Conf;

        $aulimit = (strlen($this->limit) >= 2 && $this->limit[0] == 'a' && $this->limit[1] == 'u');
        $pq = "select u.contactId,
        u.contactId as paperId,
        firstName, lastName, email, affiliation, roles, contactTags,
        voicePhoneNumber,
        u.collaborators, lastLogin, disabled";
        if (isset($queryOptions['topics']))
            $pq .= ",\n topicIds, topicInterest";
        if (isset($queryOptions["reviews"])) {
            $pq .= ",
        count(if(r.reviewNeedsSubmit<=0,r.reviewSubmitted,r.reviewId)) as numReviews,
        count(r.reviewSubmitted) as numReviewsSubmitted";
            if (isset($queryOptions["revratings"]))
                $pq .= ",\n     sum(r.numRatings) as numRatings,
        sum(r.sumRatings) as sumRatings";
        }
        if (isset($queryOptions["leads"]))
            $pq .= ",\n leadPaperIds, numLeads";
        if (isset($queryOptions["shepherds"]))
            $pq .= ",\n shepherdPaperIds, numShepherds";
        if (isset($queryOptions['scores']))
            foreach ($queryOptions['scores'] as $score)
                $pq .= ",\n\tgroup_concat(if(r.reviewSubmitted>0,r.$score,null)) as $score";
        if (isset($queryOptions["repapers"]))
            $pq .= ",\n\tgroup_concat(r.paperId) as paperIds,
        group_concat(r.reviewId) as reviewIds,
        group_concat(coalesce(r.reviewOrdinal,0)) as reviewOrdinals";
        else if (isset($queryOptions['papers']))
            $pq .= ",\n\tgroup_concat(PaperConflict.paperId) as paperIds";

        $pq .= "\n      from ContactInfo u\n";
        if (isset($queryOptions['topics']))
            $pq .= "    left join (select contactId, group_concat(topicId) as topicIds, group_concat(interest) as topicInterest
                from TopicInterest
                group by contactId) as ti on (ti.contactId=u.contactId)\n";
        if (isset($queryOptions["reviews"])) {
            $j = "left join";
            if ($this->limit == "re" || $this->limit == "req" || $this->limit == "ext" || $this->limit == "resub" || $this->limit == "extsub")
                $j = "join";
            $pq .= "    $j (select r.*";
            if (isset($queryOptions["revratings"]))
                $pq .= ", count(rating) as numRatings, sum(if(rating>0,1,0)) as sumRatings";
            $pq .= "\n\t\tfrom PaperReview r
                join Paper p on (p.paperId=r.paperId)";
            if (!$this->contact->privChair)
                $pq .= "\n\t\tleft join PaperConflict pc on (pc.paperId=p.paperId and pc.contactId=" . $this->contact->contactId . ")";
            if (isset($queryOptions["revratings"])) {
                $badratings = PaperSearch::unusableRatings($this->contact->privChair, $this->contact->contactId);
                $pq .= "\n\t\tleft join ReviewRating rr on (rr.reviewId=r.reviewId";
                if (count($badratings) > 0)
                    $pq .= " and not (rr.reviewId in (" . join(",", $badratings) . "))";
                $pq .= ")";
            }
            $jwhere = array();
            if ($this->limit == "req" || $this->limit == "ext" || $this->limit == "extsub")
                $jwhere[] = "r.reviewType=" . REVIEW_EXTERNAL;
            if ($this->limit == "req")
                $jwhere[] = "r.requestedBy=" . $this->contact->contactId;
            if (!$this->contact->privChair)
                $jwhere[] = "(pc.conflictType is null or pc.conflictType=0 or r.contactId=" . $this->contact->contactId . ")";
            $jwhere[] = "(p.timeWithdrawn<=0 or r.reviewSubmitted>0)";
            if (count($jwhere))
                $pq .= "\n\t\twhere " . join(" and ", $jwhere);
            if (isset($queryOptions["revratings"]))
                $pq .= "\n\t\tgroup by r.reviewId";
            $pq .= ") as r on (r.contactId=u.contactId)\n";
        }
        if (isset($queryOptions["leads"])) {
            $pq .= "    left join (select p.leadContactId, group_concat(p.paperId) as leadPaperIds, count(p.paperId) as numLeads\n\t\tfrom Paper p";
            $jwhere = array("leadContactId is not null");
            if (!$this->contact->privChair) {
                $pq .= "\n\t\tleft join PaperConflict pc on (pc.paperId=p.paperId and pc.contactId=" . $this->contact->contactId . ")";
                $jwhere[] = "(conflictType is null or conflictType=0)";
            }
            $pq .= "\n\t\twhere " . join(" and ", $jwhere)
                . "\n\t\tgroup by p.leadContactId) as lead on (lead.leadContactId=u.contactId)\n";
        }
        if (isset($queryOptions["shepherds"])) {
            $pq .= "    left join (select p.shepherdContactId, group_concat(p.paperId) as shepherdPaperIds, count(p.paperId) as numShepherds\n\t\tfrom Paper p";
            $jwhere = array("shepherdContactId is not null");
            if (!$this->contact->privChair
                && !$Conf->timePCViewDecision(true)) {
                $pq .= "\n\t\tleft join PaperConflict pc on (pc.paperId=p.paperId and pc.contactId=" . $this->contact->contactId . ")";
                $mywhere = "conflictType is null or conflictType=0";
                if ($Conf->timeAuthorViewDecision())
                    $mywhere .= " or conflictType>=" . CONFLICT_AUTHOR;
                $jwhere[] = "($mywhere)";
            }
            $pq .= "\n\t\twhere " . join(" and ", $jwhere)
                . "\n\t\tgroup by p.shepherdContactId) as shep on (shep.shepherdContactId=u.contactId)\n";
        }
        if ($aulimit)
            $pq .= "\tjoin PaperConflict on (PaperConflict.contactId=u.contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")\n";
        if ($this->limit == "au")
            $pq .= "\tjoin Paper on (Paper.paperId=PaperConflict.paperId and Paper.timeSubmitted>0)\n";
        if ($this->limit == "aurej")
            $pq .= "\tjoin Paper on (Paper.paperId=PaperConflict.paperId and Paper.outcome<0)\n";
        if ($this->limit == "auacc")
            $pq .= "\tjoin Paper on (Paper.paperId=PaperConflict.paperId and Paper.outcome>0)\n";
        if ($this->limit == "auuns")
            $pq .= "\tjoin Paper on (Paper.paperId=PaperConflict.paperId and Paper.timeSubmitted<=0)\n";
        if ($this->limit == "all")
            $pq .= "\tleft join PaperConflict on (PaperConflict.contactId=u.contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")\n";

        $mainwhere = array();
        if (isset($queryOptions["where"]))
            $mainwhere[] = $queryOptions["where"];
        if ($this->limit == "pc")
            $mainwhere[] = "(u.roles&" . Contact::ROLE_PC . ")!=0";
        if ($this->limit == "admin")
            $mainwhere[] = "(u.roles&" . (Contact::ROLE_ADMIN | Contact::ROLE_CHAIR) . ")!=0";
        if ($this->limit == "pcadmin" || $this->limit == "pcadminx")
            $mainwhere[] = "(u.roles&" . Contact::ROLE_PCLIKE . ")!=0";
        if (count($mainwhere))
            $pq .= "\twhere " . join(" and ", $mainwhere) . "\n";

        $pq .= "        group by u.contactId
        order by lastName, firstName, email";

        // make query
        $result = $Conf->qe($pq);
        if (!$result)
            return NULL;

        // fetch data
        $rows = array();
        while (($row = edb_orow($result)))
            $rows[] = $row;
        return $rows;
    }

    function table_html($listname, $url, $listtitle = "", $foldsession = null) {
        global $Conf, $contactListFields;

        // PC tags
        $queryOptions = array();
        if (substr($listname, 0, 3) == "pc:") {
            $queryOptions["where"] = "(u.contactTags like '% " . sqlq_for_like(substr($listname, 3)) . " %')";
            $listname = "pc";
        }

        // get paper list
        if (!($baseFieldId = $this->listFields($listname))) {
            $Conf->errorMsg("There is no people list query named '" . htmlspecialchars($listname) . "'.");
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
            $normFid = self::_normalizeField($fid);
            $fieldDef[$fid] = $contactListFields[$normFid];
            $acceptable_fields[$fid] = $acceptable_fields[$normFid] = true;
            if ($contactListFields[$normFid][1] == 1)
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
        $extrainfo = false;
        $ids = array();
        foreach ($srows as $row) {
            if (($this->limit == "resub" || $this->limit == "extsub")
                && $row->numReviewsSubmitted == 0)
                continue;

            $trclass = "k" . ($this->count % 2);
            if ($show_colors) {
                $tags = Contact::roles_all_contact_tags($row->roles, $row->contactTags);
                if (($c = TagInfo::color_classes($tags)))
                    $trclass .= " " . $c;
            }
            if ($row->disabled)
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
                    $tt .= "><h6>" . $this->header($fieldId, -1, $row)
                        . ":</h6> " . $d . "</div>";
                }

            if ($tt !== "") {
                $x = "  <tr class=\"plx $trclass\">";
                if ($firstcallout > 0)
                    $x .= "<td colspan=\"$firstcallout\"></td>";
                $tt = $x . "<td colspan=\"" . ($lastcallout - $firstcallout)
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
                $this->have_folds[$fold] = strpos(displayOptionsSet("ppldisplay"), " $fold ") !== false;
                $foldclasses[] = "fold" . ($k + 1) . ($this->have_folds[$fold] ? "o" : "c");
            }

        $x = "<table id=\"foldppl\" class=\"pltable plt_" . htmlspecialchars($listname);
        if ($foldclasses)
            $x .= " " . join(" ", $foldclasses);
        if ($foldclasses && $foldsession)
            $x .= "\" hotcrp_foldsession=\"$foldsession";
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
                        $x .= "    <th class=\"pl_$fdef[0]\"></th>\n";
                        continue;
                    }
                    $x .= "    <th class=\"pl_$fdef[0]\">";
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
                        $x .= "    <th class=\"pl_$fdef[0]\">"
                            . $this->header($fieldId, $ord++) . "</th>\n";
                    else if ($fdef[1] == 1)
                        $x .= "    <th class=\"pl_$fdef[0]\"></th>\n";
            }

            $x .= "  </tr></thead>\n";
        }

        reset($fieldDef);
        if (key($fieldDef) == self::FIELD_SELECTOR)
            $x .= $this->footer($ncol);

        $x .= "<tbody class=\"pltable\">" . $body . "</tbody></table>";

        if ($this->listNumber) {
            $l = SessionList::create("u/" . $this->limit, $ids,
                                     ($listtitle ? $listtitle : "Users"),
                                     hoturl_site_relative_raw("users", "t=$this->limit"));
            SessionList::change($this->listNumber, $l);
        }

        return $x;
    }

    function rows($listname) {
        // PC tags
        $queryOptions = array();
        if (substr($listname, 0, 3) == "pc:") {
            $queryOptions["where"] = "(u.contactTags like '% " . sqlq_for_like(substr($listname, 3)) . " %')";
            $listname = "pc";
        }

        // get paper list
        if (!($baseFieldId = $this->listFields($listname))) {
            $Conf->errorMsg("There is no people list query named '" . htmlspecialchars($listname) . "'.");
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
