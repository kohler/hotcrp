<?php
// contactlist.php -- HotCRP helper class for producing lists of contacts
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

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

    public $conf;
    public $user;
    public $qreq;
    var $showHeader = true;
    var $sortField = null;
    var $reverseSort;
    var $sortable;
    var $count;
    var $any;
    private $tagger;
    var $scoreMax;
    var $limit;
    public $have_folds = array();
    var $contactLinkArgs;
    private $_cfltpids = null;

    function __construct(Contact $user, $sortable = true, $qreq = null) {
        global $contactListFields;

        $this->conf = $user->conf;
        $this->user = $user;
        if (!$qreq || !($qreq instanceof Qrequest))
            $qreq = new Qrequest("GET", $qreq);
        $this->qreq = $qreq;

        $s = ($sortable ? (string) $this->qreq->sort : "");
        $x = (strlen($s) ? $s[strlen($s)-1] : "");
        $this->reverseSort = ($x == "R");
        if ($x == "R" || $x == "N")
            $s = substr($s, 0, strlen($s) - 1);
        if ($s !== "")
            $this->sortField = $s;
        $this->sortable = $sortable;

        $this->contact = $user;
        $this->tagger = new Tagger($this->user);
        $this->contactLinkArgs = "";
    }

    function selector($fieldId, &$queryOptions) {
        if (!$this->user->isPC
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
            if ($this->conf->setting("rev_ratings") == REV_RATINGS_NONE)
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
        if (($f = $this->conf->review_field($fieldId))) {
            $revViewScore = $this->user->permissive_view_score_bound();
            if ($f->view_score <= $revViewScore || !$f->has_options)
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
        return strcasecmp($a->sorter, $b->sorter);
    }

    function _sortEmail($a, $b) {
        return strcasecmp($a->email, $b->email);
    }

    function _sortAffiliation($a, $b) {
        $x = strcasecmp($a->affiliation, $b->affiliation);
        return $x ? $x : $this->_sortBase($a, $b);
    }

    function _sortLastVisit($a, $b) {
        if ($a->activity_at != $b->activity_at)
            return $a->activity_at < $b->activity_at ? 1 : -1;
        else
            return $this->_sortBase($a, $b);
    }

    function _sortReviews($a, $b) {
        $x = $b->numReviewsSubmitted - $a->numReviewsSubmitted;
        $x = $x ? $x : $b->numReviews - $a->numReviews;
        return $x ? $x : $this->_sortBase($a, $b);
    }

    function _sortLeads($a, $b) {
        $x = $b->numLeads - $a->numLeads;
        return $x ? $x : $this->_sortBase($a, $b);
    }

    function _sortShepherds($a, $b) {
        $x = $b->numShepherds - $a->numShepherds;
        return $x ? $x : $this->_sortBase($a, $b);
    }

    function _sortReviewRatings($a, $b) {
        list($ag, $ab) = [(int) $a->numGoodRatings, (int) $a->numBadRatings];
        list($bg, $bb) = [(int) $b->numGoodRatings, (int) $b->numBadRatings];
        if ($ag - $ab != $bg - $bb)
            return $ag - $ab > $bg - $bb ? -1 : 1;
        if ($ag + $ab != $bg + $bb)
            return $ag + $ab < $bg + $bb ? -1 : 1;
        return $this->_sortBase($a, $b);
    }

    function _sortPapers($a, $b) {
        if (!$a->paperIds != !$b->paperIds)
            return $a->paperIds ? -1 : 1;
        $x = (int) $a->paperIds - (int) $b->paperIds;
        $x = $x ? $x : strcmp($a->paperIds, $b->paperIds);
        return $x ? $x : $this->_sortBase($a, $b);
    }

    function _sortScores($a, $b) {
        if (!($x = ScoreInfo::compare($b->_sort_info, $a->_sort_info, -1)))
            $x = ScoreInfo::compare($b->_sort_avg, $a->_sort_avg);
        return $x ? ($x < 0 ? -1 : 1) : $this->_sortBase($a, $b);
    }

    function _sort($rows) {
        switch ($this->sortField) {
        case self::FIELD_NAME:
            usort($rows, [$this, "_sortBase"]);
            break;
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
            if (($f = $this->conf->review_field($this->sortField))) {
                $fieldId = $this->sortField;
                $scoreMax = $this->scoreMax[$fieldId];
                $scoresort = $this->conf->session("scoresort", "A");
                if ($scoresort != "A" && $scoresort != "V" && $scoresort != "D")
                    $scoresort = "A";
                Contact::$allow_nonexistent_properties = true;
                foreach ($rows as $row) {
                    $scoreinfo = new ScoreInfo(get($row, $fieldId), true);
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
            if (($f = $this->conf->review_field($fieldId)))
                return $f->web_abbreviation();
            else
                return "&lt;$fieldId&gt;?";
        }
    }

    function content($fieldId, $row) {
        switch ($fieldId) {
        case self::FIELD_NAME:
            if ($this->sortField == $fieldId && $this->conf->sort_by_last)
                $t = Text::name_html($row, NameInfo::make_last_first());
            else
                $t = Text::name_html($row);
            if (trim($t) == "")
                $t = "[No name]";
            $t = '<span class="taghl">' . $t . '</span>';
            if ($this->user->privChair)
                $t = "<a href=\"" . hoturl("profile", "u=" . urlencode($row->email) . $this->contactLinkArgs) . "\"" . ($row->disabled ? " class='uu'" : "") . ">$t</a>";
            $role = $row->role_html();
            if ($role !== "" && ($this->limit !== "pc" || ($row->roles & Contact::ROLE_PCLIKE) !== Contact::ROLE_PC))
                $t .= " $role";
            if ($this->user->privChair && $row->email != $this->user->email)
                $t .= " <a href=\"" . hoturl("index", "actas=" . urlencode($row->email)) . "\">"
                    . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($row)))
                    . "</a>";
            if ($row->disabled && $this->user->isPC)
                $t .= ' <span class="hint">(disabled)</span>';
            return $t;
        case self::FIELD_EMAIL:
            if (!$this->user->isPC)
                return "";
            $e = htmlspecialchars($row->email);
            if (strpos($row->email, "@") === false)
                return $e;
            else
                return "<a href=\"mailto:$e\" class=\"mailto\">$e</a>";
        case self::FIELD_AFFILIATION:
        case self::FIELD_AFFILIATION_ROW:
            return htmlspecialchars($row->affiliation);
        case self::FIELD_LASTVISIT:
            if (!$row->activity_at)
                return "Never";
            else if ($this->user->privChair)
                return $this->conf->unparse_time_short($row->activity_at);
            else
                return $this->conf->unparse_time_obscure($row->activity_at);
        case self::FIELD_SELECTOR:
        case self::FIELD_SELECTOR_ON:
            $this->any->sel = true;
            $c = "";
            if ($fieldId == self::FIELD_SELECTOR_ON)
                $c = ' checked="checked"';
            return '<input type="checkbox" class="uix js-range-click" name="pap[]" value="' . $row->contactId . '" tabindex="1"' . $c . ' />';
        case self::FIELD_HIGHTOPICS:
        case self::FIELD_LOWTOPICS:
            if (!($topics = $row->topic_interest_map()))
                return "";
            if ($fieldId == self::FIELD_HIGHTOPICS)
                $nt = array_filter($topics, function ($i) { return $i > 0; });
            else
                $nt = array_filter($topics, function ($i) { return $i < 0; });
            return PaperInfo::unparse_topic_list_html($this->conf, $nt);
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
                || (!$row->numGoodRatings && !$row->numBadRatings))
                return "";
            $a = array();
            $b = array();
            if ($row->numGoodRatings > 0) {
                $a[] = $row->numGoodRatings . " positive";
                $b[] = "<a href=\"" . hoturl("search", "q=re:" . urlencode($row->email) . "+rate:good") . "\">+" . $row->numGoodRatings . "</a>";
            }
            if ($row->numBadRatings > 0) {
                $a[] = $row->numBadRatings . " negative";
                $b[] = "<a href=\"" . hoturl("search", "q=re:" . urlencode($row->email) . "+rate:bad") . "\">&minus;" . $row->numBadRatings . "</a>";
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
            return '<div class="has-hotlist" data-hotlist="' . $ls . '">'
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
            return '<div class="has-hotlist" data-hotlist="' . $ls . '">'
                . join(", ", $m) . '</div>';
        case self::FIELD_TAGS:
            if ($this->user->isPC
                && ($tags = $row->viewable_tags($this->user))) {
                $x = [];
                foreach (TagInfo::split($tags) as $t)
                    if ($t !== "pc#0")
                        $x[] = '<a class="qq nw" href="' . hoturl("users", "t=%23" . TagInfo::base($t)) . '">' . $this->tagger->unparse_hashed($t) . '</a>';
                return join(" ", $x);
            }
            return "";
        case self::FIELD_COLLABORATORS:
            if (!$this->user->isPC || !($row->roles & Contact::ROLE_PC))
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
            $f = $this->conf->review_field($fieldId);
            if (!$f
                || (!($row->roles & Contact::ROLE_PC)
                    && !$this->user->privChair
                    && $this->limit != "req")
                || (string) $row->$fieldId === "")
                return "";
            return $f->unparse_graph($row->$fieldId, 2, 0);
        }
    }

    function addScores($a) {
        if ($this->user->isPC) {
            foreach ($this->conf->all_review_fields() as $f)
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
        if ($this->count == 0)
            return "";
        $lllgroups = [];

        // Begin linelinks
        $types = array("nameemail" => "Names and emails");
        if ($this->user->privChair)
            $types["pcinfo"] = "PC info";
        $lllgroups[] = ["", "Download",
            Ht::select("getaction", $types, null, ["class" => "want-focus"])
            . "&nbsp; " . Ht::submit("getgo", "Go")];

        if ($this->user->privChair) {
            $lllgroups[] = ["", "Tag",
                Ht::select("tagtype", array("a" => "Add", "d" => "Remove", "s" => "Define"), $this->qreq->tagtype)
                . ' &nbsp;tag(s) &nbsp;'
                . Ht::entry("tag", $this->qreq->tag, ["size" => 15, "class" => "want-focus js-autosubmit", "data-autosubmit-type" => "tagact"])
                . ' &nbsp;' . Ht::submit("tagact", "Go")];

            $mods = ["disableaccount" => "Disable", "enableaccount" => "Enable"];
            if ($this->user->can_change_password(null))
                $mods["resetpassword"] = "Reset password";
            $mods["sendaccount"] = "Send account information";
            $lllgroups[] = ["", "Modify",
                Ht::select("modifytype", $mods, null, ["class" => "want-focus"])
                . "&nbsp; " . Ht::submit("modifygo", "Go")];
        }

        return "  <tfoot class=\"pltable" . ($hascolors ? " pltable_colored" : "")
            . "\">" . PaperList::render_footer_row(1, $ncol - 1,
                "<b>Select people</b> (or <a class=\"ui js-select-all\" href=\"\">select all {$this->count}</a>), then&nbsp; ",
                $lllgroups)
            . "</tfoot>\n";
    }

    private function _conflict_pids() {
        if ($this->_cfltpids === null)
            $this->_cfltpids = $this->user->hide_reviewer_identity_pids();
        return $this->_cfltpids;
    }

    private function _pid_restriction() {
        if (($cfltpids = $this->_conflict_pids()))
            return " and paperId not in (" . join(",", $cfltpids) . ")";
        else
            return "";
    }

    function _rows($queryOptions) {
        // XXX This section is a bit of a mess. We don't always obey the
        // visibility restrictions in Contact. Most of the time (but probably
        // not always) we are more restricted: for instance, conflicted PC
        // members never see reviewers for their papers, even if reviewing is
        // not anonymous. Hard to see this as worth fixing.

        $aulimit = (strlen($this->limit) >= 2 && $this->limit[0] == 'a' && $this->limit[1] == 'u');
        $rf = ["contactId"];
        $phone = $this->conf->sversion >= 186 ? "phone" : "voicePhoneNumber";
        $pq = "select u.contactId,
        firstName, lastName, email, affiliation, roles, contactTags,
        $phone phone, u.collaborators, lastLogin, disabled";
        if (isset($queryOptions["reviews"])) {
            $rf[] = "count(if(reviewNeedsSubmit=0,reviewSubmitted,reviewId)) numReviews";
            $rf[] = "count(reviewSubmitted) numReviewsSubmitted";
            $pq .= ", numReviews, numReviewsSubmitted";
        }
        if (isset($queryOptions["revratings"]))
            $pq .= ", numGoodRatings, numBadRatings";
        if (isset($queryOptions["leads"]))
            $pq .= ",\n    (select count(paperId) from Paper where leadContactId=u.contactId" . $this->_pid_restriction() . ") numLeads";
        if (isset($queryOptions["shepherds"]))
            $pq .= ",\n    (select count(paperId) from Paper where shepherdContactId=u.contactId" . $this->_pid_restriction() . ") numShepherds";
        if (isset($queryOptions['scores']))
            foreach ($queryOptions['scores'] as $score) {
                $rf[] = "group_concat(if(reviewSubmitted>0,$score,null)) $score";
                $pq .= ", $score";
            }
        if (isset($queryOptions["repapers"])) {
            $rf[] = "group_concat(r.paperId) paperIds";
            $rf[] = "group_concat(reviewId) reviewIds";
            $rf[] = "group_concat(reviewOrdinal) reviewOrdinals";
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
                $jwhere[] = "r.requestedBy=" . $this->user->contactId;
            if (($cfltpids = $this->_conflict_pids()))
                $jwhere[] = "(r.paperId not in (" . join(",", $cfltpids) . ") or r.contactId=" . $this->user->contactId . ")";
            $jwhere[] = "(p.timeSubmitted>0 or r.reviewSubmitted>0)";
            if (count($jwhere))
                $pq .= "\n\t\twhere " . join(" and ", $jwhere);
            $pq .= " group by r.contactId) as r on (r.contactId=u.contactId)\n";
        }
        if (isset($queryOptions["revratings"])) {
            $pq .= "    left join (select PaperReview.contactId,
                sum((rating&" . ReviewInfo::RATING_GOODMASK . ")!=0) numGoodRatings,
                sum((rating&" . ReviewInfo::RATING_BADMASK . ")!=0) numBadRatings
                from ReviewRating
                join PaperReview on (PaperReview.paperId=ReviewRating.paperId and PaperReview.reviewId=ReviewRating.reviewId)";
            $jwhere = [];
            if (($badratings = PaperSearch::unusableRatings($this->user)))
                $jwhere[] = "ReviewRating.reviewId not in (" . join(",", $badratings) . ")";
            if (($cfltpids = $this->_conflict_pids()))
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

        // make query
        $result = $this->conf->qe_raw($pq);
        if (!$result)
            return NULL;

        // fetch data
        Contact::$allow_nonexistent_properties = true;
        $rows = array();
        while (($row = Contact::fetch($result, $this->conf)))
            $rows[] = $row;
        Contact::$allow_nonexistent_properties = false;
        if (isset($queryOptions["topics"]))
            Contact::load_topic_interests($rows);
        return $rows;
    }

    function table_html($listname, $url, $listtitle = "", $foldsession = null) {
        global $contactListFields;

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
            if (!($fieldDef[$fid] = get($contactListFields, $fid)))
                $fieldDef[$fid] = $contactListFields[self::FIELD_SCORE];
            $acceptable_fields[$fid] = true;
            if ($fieldDef[$fid][1] == 1)
                $ncol++;
        }

        // run query
        $rows = $this->_rows($queryOptions);
        if (!$rows || count($rows) == 0)
            return "No matching people";

        // sort rows
        if (!$this->sortField || !get($acceptable_fields, $this->sortField))
            $this->sortField = self::FIELD_NAME;
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
        $show_colors = $this->user->isPC;

        $anyData = array();
        $body = '';
        $extrainfo = $hascolors = false;
        $ids = array();
        foreach ($srows as $row) {
            if (($this->limit == "resub" || $this->limit == "extsub")
                && $row->numReviewsSubmitted == 0)
                continue;

            $trclass = "k" . ($this->count % 2);
            if ($show_colors && ($k = $row->viewable_color_classes($this->user))) {
                if (str_ends_with($k, " tagbg")) {
                    $trclass = $k;
                    $hascolors = true;
                } else
                    $trclass .= " " . $k;
            }
            if ($row->disabled && $this->user->isPC)
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
            if (get($this->have_folds, $fold) !== null) {
                $this->have_folds[$fold] = strpos(displayOptionsSet("uldisplay"), " $fold ") !== false;
                $foldclasses[] = "fold" . ($k + 1) . ($this->have_folds[$fold] ? "o" : "c");
            }

        $x = "<table id=\"foldul\" class=\"pltable pltable_full plt_" . htmlspecialchars($listquery);
        if ($foldclasses)
            $x .= " " . join(" ", $foldclasses);
        if ($foldclasses && $foldsession) {
            $fs = [];
            foreach (self::$folds as $k => $fold) {
                $fs[$k + 1] = $foldsession . $fold;
            }
            $x .= "\" data-fold-session=\"" . htmlspecialchars(json_encode_browser($fs));
        }
        $x .= "\">\n";

        if ($this->showHeader) {
            $x .= "  <thead class=\"pltable\">\n  <tr class=\"pl_headrow\">\n";
            $ord = 0;

            if ($this->sortable && $url) {
                $sortUrl = $url . (strpos($url, "?") ? "&amp;" : "?") . "sort=";
                $q = '<a class="pl_sort" rel="nofollow" href="' . $sortUrl;
                foreach ($fieldDef as $fieldId => $fdef) {
                    if ($fdef[1] != 1)
                        continue;
                    else if (!isset($anyData[$fieldId])) {
                        $x .= "    <th class=\"pl plh pl_$fdef[0]\"></th>\n";
                        continue;
                    }
                    $x .= "    <th class=\"pl plh pl_$fdef[0]\">";
                    $ftext = $this->header($fieldId, $ord++);
                    if ($fieldId == $this->sortField)
                        $x .= '<a class="pl_sort pl_sorting' . ($this->reverseSort ? "_rev" : "_fwd") . '" rel="nofollow" href="' . $sortUrl . $fieldId . ($this->reverseSort ? "N" : "R") . '">' . $ftext . "</a>";
                    else if ($fdef[2])
                        $x .= $q . $fieldId . "\">" . $ftext . "</a>";
                    else
                        $x .= $ftext;
                    $x .= "</th>\n";
                }

            } else {
                foreach ($fieldDef as $fieldId => $fdef)
                    if ($fdef[1] == 1 && isset($anyData[$fieldId]))
                        $x .= "    <th class=\"pl plh pl_$fdef[0]\">"
                            . $this->header($fieldId, $ord++) . "</th>\n";
                    else if ($fdef[1] == 1)
                        $x .= "    <th class=\"pl plh pl_$fdef[0]\"></th>\n";
            }

            $x .= "  </tr></thead>\n";
        }

        reset($fieldDef);
        if (key($fieldDef) == self::FIELD_SELECTOR)
            $x .= $this->footer($ncol, $hascolors);

        $x .= "<tbody class=\"pltable" . ($hascolors ? " pltable_colored" : "");
        if ($this->user->privChair) {
            $l = new SessionList("u/" . $listname, $ids, $listtitle ? : "Users",
                                 hoturl_site_relative_raw("users", ["t" => $listname]));
            $x .= " has-hotlist\" data-hotlist=\"" . htmlspecialchars($l->info_string());
        }
        return $x . "\">" . $body . "</tbody></table>";
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
