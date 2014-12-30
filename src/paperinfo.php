<?php
// paperinfo.php -- HotCRP paper objects
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperContactInfo {
    public $conflict_type = 0;
    public $review_type = 0;
    public $review_submitted = null;
    public $review_needs_submit = 1;
    public $review_token_cid = null;

    function __construct() {
    }

    static function load($pid, $cid, $rev_tokens = null) {
        $result = null;
        if ($cid) {
            $review_matcher = array("PaperReview.contactId=$cid");
            if ($rev_tokens && count($rev_tokens))
                $review_matcher[] = "PaperReview.reviewToken in (" . join(",", $rev_tokens) . ")";
            $result = Dbl::raw_qe("select conflictType as conflict_type,
                reviewType as review_type,
                reviewSubmitted as review_submitted,
                reviewNeedsSubmit as review_needs_submit,
                PaperReview.contactId as review_token_cid
                from (select $pid paperId) crap
                left join PaperConflict on (PaperConflict.paperId=crap.paperId and PaperConflict.contactId=$cid)
                left join PaperReview on (PaperReview.paperId=crap.paperId and (" . join(" or ", $review_matcher) . "))");
        }
        if ($result && ($ci = $result->fetch_object("PaperContactInfo"))) {
            $ci->conflict_type = (int) $ci->conflict_type;
            $ci->review_type = (int) $ci->review_type;
            $ci->review_submitted = (int) $ci->review_submitted;
            $ci->review_needs_submit = (int) $ci->review_needs_submit;
            $ci->review_token_cid = (int) $ci->review_token_cid;
            if ($ci->review_token_cid == $cid)
                $ci->review_token_cid = null;
        } else
            $ci = new PaperContactInfo;
        return $ci;
    }

    static function load_my($object, $cid) {
        $ci = new PaperContactInfo;
        if (property_exists($object, "conflictType"))
            $ci->conflict_type = (int) $object->conflictType;
        if (property_exists($object, "myReviewType"))
            $ci->review_type = (int) $object->myReviewType;
        if (property_exists($object, "myReviewSubmitted"))
            $ci->review_submitted = (int) $object->myReviewSubmitted;
        if (property_exists($object, "myReviewNeedsSubmit"))
            $ci->review_needs_submit = (int) $object->myReviewNeedsSubmit;
        if (property_exists($object, "myReviewContactId")
            && $object->myReviewContactId != $cid)
            $ci->review_token_cid = (int) $object->myReviewContactId;
        return $ci;
    }
}

class PaperInfo {
    private $_contact_info = array();
    private $_score_array = array();
    private $_score_info = array();

    function __construct($p = null, $contact = null) {
        if ($p)
            foreach ($p as $k => $v)
                $this->$k = $v;
        if ($contact && (property_exists($this, "conflictType")
                         || property_exists($this, "myReviewType"))) {
            if ($contact === true)
                $cid = property_exists($this, "contactId") ? $this->contactId : null;
            else
                $cid = is_object($contact) ? $contact->contactId : $contact;
            $this->assign_contact_info($this, $cid);
        }
    }

    static public function fetch($result, $contact) {
        return $result ? $result->fetch_object("PaperInfo", array(null, $contact)) : null;
    }

    static public function table_name() {
        return "Paper";
    }

    static public function id_column() {
        return "paperId";
    }

    static public function comment_table_name() {
        return "PaperComment";
    }


    public function contact_info($contact = null) {
        global $Me;
        if (!$contact)
            $contact = $Me;
        $rev_tokens = null;
        if (is_object($contact)) {
            $rev_tokens = $contact->review_tokens();
            $contact = $contact->contactId;
        }
        $ci = @$this->_contact_info[$contact];
        if (!$ci)
            $ci = $this->_contact_info[$contact] =
                PaperContactInfo::load($this->paperId, $contact, $rev_tokens);
        return $ci;
    }

    public function replace_contact_info_map($cimap) {
        $old_cimap = $this->_contact_info;
        $this->_contact_info = $cimap;
        return $old_cimap;
    }

    public function assign_contact_info($row, $cid) {
        $this->_contact_info[$cid] = PaperContactInfo::load_my($row, $cid);
    }

    public function pretty_text_title_indent($width = 75) {
        $n = "Paper #{$this->paperId}: ";
        $vistitle = UnicodeHelper::deaccent($this->title);
        $l = (int) (($width + 0.5 - strlen($vistitle) - strlen($n)) / 2);
        return max(14, $l + strlen($n));
    }

    public function pretty_text_title($width = 75) {
        $l = $this->pretty_text_title_indent($width);
        return prefix_word_wrap("Paper #{$this->paperId}: ",
                                $this->title, $l) . "\n";
    }

    public function conflict_type($contact = null) {
        $ci = $this->contact_info($contact);
        return $ci ? $ci->conflict_type : 0;
    }

    public function has_conflict($contact = null) {
        return $this->conflict_type($contact) > 0;
    }

    public function has_author($contact = null) {
        return $this->conflict_type($contact) >= CONFLICT_AUTHOR;
    }

    public function review_type($contact = null) {
        $ci = $this->contact_info($contact);
        return $ci ? $ci->review_type : 0;
    }

    public function has_review($contact = null) {
        $ci = $this->contact_info($contact);
        return $ci && $ci->review_type > 0;
    }

    public function review_not_incomplete($contact = null) {
        $ci = $this->contact_info($contact);
        return $ci && $ci->review_type > 0
            && ($ci->review_submitted > 0 || $ci->review_needs_submit == 0);
    }

    public function review_submitted($contact = null) {
        $ci = $this->contact_info($contact);
        return $ci && $ci->review_type > 0 && $ci->review_submitted > 0;
    }

    public function load_tags() {
        $result = Dbl::raw_qe("select group_concat(' ', tag, '#', tagIndex order by tag separator '') from PaperTag where paperId=$this->paperId group by paperId");
        $this->paperTags = "";
        if (($row = edb_row($result)) && $row[0] !== null)
            $this->paperTags = $row[0];
    }

    public function has_tag($tag) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        return $this->paperTags !== ""
            && strpos($this->paperTags, " $tag#") !== false;
    }

    public function tag_value($tag) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        if ($this->paperTags !== ""
            && ($pos = strpos($this->paperTags, " $tag#")) !== false)
            return (int) substr($this->paperTags, $pos + strlen($tag) + 2);
        else
            return false;
    }

    public function all_tags_text() {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        return $this->paperTags;
    }

    private function load_topics() {
        $result = Dbl::raw_qe("select group_concat(topicId) from PaperTopic where paperId=$this->paperId");
        $row = edb_row($result);
        $this->topicIds = $row ? $row[0] : "";
    }

    public function topics() {
        if (!property_exists($this, "topicIds"))
            $this->load_topics();
        $x = array();
        if ($this->topicIds !== "" && $this->topicIds !== null)
            foreach (explode(",", $this->topicIds) as $topic)
                $x[] = (int) $topic;
        return $x;
    }

    public static function unparse_topics($topicIds, $interests = null) {
        global $Conf;
        if (!$topicIds)
            return array();
        if (!is_array($topicIds))
            $topicIds = explode(",", $topicIds);
        if ($interests !== null && !is_array($interests))
            $interests = explode(",", $interests);
        $out = array();
        $tmap = $Conf->topic_map();
        $tomap = $Conf->topic_order_map();
        for ($i = 0; $i < count($topicIds); $i++)
            $out[$tomap[$topicIds[$i]]] =
                '<span class="topic' . ($interests ? $interests[$i] : 0)
                . '">' . htmlspecialchars($tmap[$topicIds[$i]])
                . "</span>";
        ksort($out);
        return array_values($out);
    }

    public function conflicts($email = false) {
        global $Conf;
        if ($email ? !@$this->conflicts_email_ : !isset($this->conflicts_)) {
            $this->conflicts_ = array();
            if (!$email && isset($this->allConflictType)) {
                $vals = array();
                foreach (explode(",", $this->allConflictType) as $x)
                    $vals[] = explode(" ", $x);
            } else if (!$email)
                $vals = edb_rows($Conf->qe("select contactId, conflictType from PaperConflict where paperId=$this->paperId"));
            else {
                $vals = edb_rows($Conf->qe("select ContactInfo.contactId, conflictType, email from PaperConflict join ContactInfo using (contactId) where paperId=$this->paperId"));
                $this->conflicts_email_ = true;
            }
            foreach ($vals as $v)
                if ($v[1] > 0) {
                    $row = (object) array("contactId" => (int) $v[0], "conflictType" => (int) $v[1]);
                    if (@$v[2])
                        $row->email = $v[2];
                    $this->conflicts_[$row->contactId] = $row;
                }
        }
        return $this->conflicts_;
    }

    public function pc_conflicts($email = false) {
        return array_intersect_key($this->conflicts($email), pcMembers());
    }

    public function contacts($email = false) {
        $c = array();
        foreach ($this->conflicts($email) as $id => $conf)
            if ($conf->conflictType >= CONFLICT_AUTHOR)
                $c[$id] = $conf;
        return $c;
    }

    private function load_reviewer_preferences() {
        global $Conf;
        $result = $Conf->qe("select " . $Conf->query_all_reviewer_preference() . " from PaperReviewPreference where paperId=$this->paperId");
        $row = edb_row($result);
        $this->allReviewerPreference = $row ? $row[0] : null;
    }

    public function reviewer_preferences() {
        if (!property_exists($this, "allReviewerPreference"))
            $this->load_reviewer_preferences();
        $x = array();
        if ($this->allReviewerPreference !== "" && $this->allReviewerPreference !== null) {
            $p = preg_split('/[ ,]/', $this->allReviewerPreference);
            for ($i = 0; $i < count($p); $i += 3) {
                if (@$p[$i+1] != "0" || @$p[$i+2] != ".")
                    $x[(int) $p[$i]] = array((int) @$p[$i+1],
                                             @$p[$i+2] == "." ? null : (int) @$p[$i+2]);
            }
        }
        return $x;
    }

    public function options() {
        if (!property_exists($this, "option_array"))
            PaperOption::parse_paper_options($this);
        return $this->option_array;
    }

    public function option($id) {
        if (!property_exists($this, "option_array"))
            PaperOption::parse_paper_options($this);
        return @$this->option_array[$id];
    }

    static public function score_aggregate_field($fid) {
        if ($fid === "contactId")
            return "reviewContactIds";
        else if ($fid === "reviewType")
            return "reviewTypes";
        else
            return "{$fid}Scores";
    }

    public function load_scores($fids) {
        $fids = mkarray($fids);
        $req = array();
        foreach ($fids as $fid)
            $req[] = "group_concat($fid order by reviewId) " . self::score_aggregate_field($fid);
        $result = Dbl::qe("select " . join(", ", $req) . " from PaperReview where paperId=$this->paperId and reviewSubmitted>0");
        $row = null;
        if ($result)
            $row = $result->fetch_row();
        $row = $row ? : array();
        foreach ($fids as $i => $fid) {
            $k = self::score_aggregate_field($fid);
            $this->$k = @$row[$i];
        }
    }

    public function submitted_reviewers() {
        if (!property_exists($this, "reviewContactIds"))
            $this->load_scores("contactId");
        return $this->reviewContactIds ? explode(",", $this->reviewContactIds) : array();
    }

    public function submitted_review_types() {
        if (!property_exists($this, "reviewTypes"))
            $this->load_scores(array("reviewType", "contactId"));
        return $this->reviewTypes ? array_combine(explode(",", $this->reviewContactIds),
                                                  explode(",", $this->reviewTypes)) : array();
    }

    public function scores($fid) {
        $fname = "{$fid}Scores";
        if (!property_exists($this, $fname) || !property_exists($this, "reviewContactIds"))
            $this->load_scores(array($fid, "contactId"));
        return $this->$fname ? array_combine(explode(",", $this->reviewContactIds),
                                             explode(",", $this->$fname)) : array();
    }

    public function score($fid, $cid) {
        $s = $this->scores($fid);
        return $s[$cid];
    }

    public function fetch_comments($where) {
        $result = Dbl::qe("select PaperComment.*, firstName reviewFirstName, lastName reviewLastName, email reviewEmail
            from PaperComment join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)
            where $where order by commentId");
        $comments = array();
        while (($c = CommentInfo::fetch($result, $this)))
            $comments[$c->commentId] = $c;
        return $comments;
    }

    public function load_comments() {
        $this->comment_array = $this->fetch_comments("PaperComment.paperId=$this->paperId");
    }

    public function all_comments() {
        if (!property_exists($this, "comment_array"))
            $this->load_comments();
        return $this->comment_array;
    }
}
