<?php
// paperinfo.php -- HotCRP paper objects
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperContactInfo {
    public $conflict_type = 0;
    public $review_type = 0;
    public $review_submitted = null;
    public $review_needs_submit = 1;
    public $review_token_cid = null;
    public $topic_interest_score = null;

    function __construct() {
    }

    static function load($pid, $cid, $rev_tokens = null) {
        $result = null;
        if ($cid) {
            $review_matcher = array("PaperReview.contactId=$cid");
            if ($rev_tokens && count($rev_tokens))
                $review_matcher[] = "PaperReview.reviewToken in (" . join(",", $rev_tokens) . ")";
            $result = Dbl::qe_raw("select conflictType as conflict_type,
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
        Dbl::free($result);
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
    private $_prefs_array = null;
    private $_review_id_array = null;
    private $_conflicts;
    private $_conflicts_email;

    function __construct($p = null, $contact = null) {
        if ($p)
            foreach ($p as $k => $v)
                $this->$k = $v;
        if (isset($this->paperId))
            $this->paperId = (int) $this->paperId;
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
        return strlen($n) + max(0, $l);
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

    public function pc_can_become_reviewer() {
        global $Conf;
        if (!$Conf->check_track_review_sensitivity())
            return pcMembers();
        else {
            $pcm = array();
            foreach (pcMembers() as $cid => $pc)
                if ($pc->can_become_reviewer_ignore_conflict($this))
                    $pcm[$cid] = $pc;
            return $pcm;
        }
    }

    public function load_tags() {
        $result = Dbl::qe_raw("select group_concat(' ', tag, '#', tagIndex order by tag separator '') from PaperTag where paperId=$this->paperId group by paperId");
        $this->paperTags = "";
        if (($row = edb_row($result)) && $row[0] !== null)
            $this->paperTags = $row[0];
        Dbl::free($result);
    }

    public function has_tag($tag) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        return $this->paperTags !== ""
            && stripos($this->paperTags, " $tag#") !== false;
    }

    public function has_any_tag($tags) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        foreach ($tags as $tag)
            if (stripos($this->paperTags, " $tag#") !== false)
                return true;
        return false;
    }

    public function tag_value($tag) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        if ($this->paperTags !== ""
            && ($pos = stripos($this->paperTags, " $tag#")) !== false)
            return (int) substr($this->paperTags, $pos + strlen($tag) + 2);
        else
            return false;
    }

    public function all_tags_text() {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        return $this->paperTags;
    }

    public function tag_info_json(Contact $user) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        $tagger = new Tagger($user);
        $editable = $tagger->paper_editable($this);
        $viewable = $tagger->viewable($this->paperTags);
        $tags_view_html = $tagger->unparse_and_link($viewable, $this->paperTags, false, !$this->has_conflict($user));
        return (object) array("tags" => TagInfo::split($viewable),
                              "tags_edit_text" => $tagger->unparse($editable),
                              "tags_view_html" => $tags_view_html,
                              "tags_color" => TagInfo::color_classes($viewable));
    }

    private function load_topics() {
        $result = Dbl::qe_raw("select group_concat(topicId) from PaperTopic where paperId=$this->paperId");
        $row = edb_row($result);
        $this->topicIds = $row ? $row[0] : "";
        Dbl::free($result);
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

    public static function unparse_topics($topicIds, $interests, $comma) {
        global $Conf;
        if (!$topicIds)
            return "";
        if (!is_array($topicIds))
            $topicIds = explode(",", $topicIds);
        if ($interests !== null && !is_array($interests))
            $interests = explode(",", $interests);
        $out = array();
        $tmap = $Conf->topic_map();
        $tomap = $Conf->topic_order_map();
        $long = false;
        for ($i = 0; $i < count($topicIds); $i++) {
            $s = '<span class="topic' . ($interests ? $interests[$i] : 0);
            $tn = $tmap[$topicIds[$i]];
            if (strlen($tn) <= 50)
                $s .= ' nw">' . htmlspecialchars($tn);
            else {
                $long = true;
                $s .= '">' . htmlspecialchars($tn);
            }
            $out[$tomap[$topicIds[$i]]] = $s . "</span>";
        }
        ksort($out);
        if ($comma)
            return join(", ", $out);
        else if ($long)
            return '<p class="od">' . join('</p><p class="od">', $out) . '</p>';
        else
            return join(' <span class="sep">&nbsp;</span> ', $out);
    }

    public function topic_interest_score($contact) {
        if (is_int($contact)) {
            $pcm = pcMembers();
            $contact = @$pcm[$contact] ? : Contact::find_by_id($contact);
        }
        $interests = $contact->topic_interest_map();
        $score = 0;
        foreach ($this->topics() as $t)
            $score += (int) @$interests[$t];
        return $score;
    }

    public function conflicts($email = false) {
        if ($email ? !@$this->_conflicts_email : !isset($this->_conflicts)) {
            $this->_conflicts = array();
            if (!$email && isset($this->allConflictType)) {
                $vals = array();
                foreach (explode(",", $this->allConflictType) as $x)
                    $vals[] = explode(" ", $x);
            } else if (!$email)
                $vals = edb_rows(Dbl::qe("select contactId, conflictType from PaperConflict where paperId=$this->paperId"));
            else {
                $vals = edb_rows(Dbl::qe("select ContactInfo.contactId, conflictType, email from PaperConflict join ContactInfo using (contactId) where paperId=$this->paperId"));
                $this->_conflicts_email = true;
            }
            foreach ($vals as $v)
                if ($v[1] > 0) {
                    $row = (object) array("contactId" => (int) $v[0], "conflictType" => (int) $v[1]);
                    if (@$v[2])
                        $row->email = $v[2];
                    $this->_conflicts[$row->contactId] = $row;
                }
        }
        return $this->_conflicts;
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
        $result = Dbl::qe("select " . $Conf->query_all_reviewer_preference() . " from PaperReviewPreference where paperId=$this->paperId");
        $row = edb_row($result);
        $this->allReviewerPreference = $row ? $row[0] : null;
        unset($this->_prefs_array);
    }

    public function reviewer_preferences() {
        if (!property_exists($this, "allReviewerPreference"))
            $this->load_reviewer_preferences();
        if ($this->_prefs_array === null) {
            $x = array();
            if ($this->allReviewerPreference !== "" && $this->allReviewerPreference !== null) {
                $p = preg_split('/[ ,]/', $this->allReviewerPreference);
                for ($i = 0; $i < count($p); $i += 3) {
                    if (@$p[$i+1] != "0" || @$p[$i+2] != ".")
                        $x[(int) $p[$i]] = array((int) @$p[$i+1],
                                                 @$p[$i+2] == "." ? null : (int) @$p[$i+2]);
                }
            }
            $this->_prefs_array = $x;
        }
        return $this->_prefs_array;
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

    public function num_reviews_submitted() {
        if (!property_exists($this, "reviewCount")) {
            $rows = edb_rows(Dbl::qe("select count(*) from PaperReview where paperId=$this->paperId and reviewSubmitted>0"));
            $this->reviewCount = @$rows[0][0];
        }
        return (int) $this->reviewCount;
    }

    public function num_reviews_assigned() {
        if (!property_exists($this, "startedReviewCount")) {
            $rows = edb_rows(Dbl::qe("select count(*) from PaperReview where paperId=$this->paperId and (reviewSubmitted or reviewNeedsSubmit>0)"));
            $this->startedReviewCount = @$rows[0][0];
        }
        return (int) $this->startedReviewCount;
    }

    public function num_reviews_in_progress() {
        if (!property_exists($this, "inProgressReviewCount")) {
            if (@$this->reviewCount !== null && $this->reviewCount === @$this->startedReviewCount)
                $this->inProgressReviewCount = $this->reviewCount;
            else {
                $rows = edb_rows(Dbl::qe("select count(*) from PaperReview where paperId=$this->paperId and (reviewSubmitted or reviewNeedsSubmit>0) and reviewModified>0"));
                $this->inProgressReviewCount = @$rows[0][0];
            }
        }
        return (int) $this->inProgressReviewCount;
    }

    public function num_reviews_started($user) {
        if ($user->privChair || !$this->conflict_type($user))
            return $this->num_reviews_assigned();
        else
            return $this->num_reviews_in_progress();
    }

    private function load_scores(/* args */) {
        $args = func_get_args();
        $args = (count($args) == 1 ? $args[0] : $args);
        $req = array();
        for ($i = 0; $i < count($args); $i += 2)
            $req[] = "group_concat(" . $args[$i] . " order by reviewId) " . $args[$i + 1];
        $result = Dbl::qe("select " . join(", ", $req) . " from PaperReview where paperId=$this->paperId and reviewSubmitted>0");
        $row = $result ? $result->fetch_assoc() : null;
        foreach ($row ? : array() as $k => $v)
            $this->$k = $v;
        Dbl::free($result);
    }

    public function submitted_reviewers() {
        if (!property_exists($this, "reviewContactIds"))
            $this->load_scores("contactId", "reviewContactIds");
        return $this->reviewContactIds ? explode(",", $this->reviewContactIds) : array();
    }

    public function viewable_submitted_reviewers($contact, $forceShow) {
        if (!property_exists($this, "reviewContactIds"))
            $this->load_scores("contactId", "reviewContactIds");
        if ($this->reviewContactIds) {
            if ($contact->can_view_review($this, null, $forceShow))
                return explode(",", $this->reviewContactIds);
            else if ($this->review_type($contact))
                return array($contact->contactId);
        }
        return array();
    }

    private function review_cid_array($basek, $k) {
        if (!property_exists($this, $k) || !property_exists($this, "reviewContactIds"))
            $this->load_scores($basek, $k, "contactId", "reviewContactIds");
        if ($this->$k)
            return array_combine(explode(",", $this->reviewContactIds),
                                 explode(",", $this->$k));
        return array();
    }

    public function review_ordinals() {
        return $this->review_cid_array("reviewOrdinal", "reviewOrdinals");
    }

    public function review_ordinal($cid) {
        $o = $this->review_ordinals();
        return @$o[$cid];
    }

    public function submitted_review_types() {
        return $this->review_cid_array("reviewType", "reviewTypes");
    }

    public function scores($fid) {
        $fid = is_object($fid) ? $fid->id : $fid;
        return $this->review_cid_array($fid, "{$fid}Scores");
    }

    public function score($fid, $cid) {
        $s = $this->scores($fid);
        return @$s[$cid];
    }

    public function may_have_viewable_scores($field, $contact, $forceShow) {
        $field = is_object($field) ? $field : ReviewForm::field($field);
        return $contact->can_view_review($this, $field->view_score, $forceShow)
            || $this->review_type($contact);
    }

    public function viewable_scores($field, $contact, $forceShow) {
        $field = is_object($field) ? $field : ReviewForm::field($field);
        $view = $contact->can_view_review($this, $field->view_score, $forceShow);
        if ($view || $this->review_type($contact)) {
            $s = $this->scores($field->id);
            if ($view)
                return $s;
            else if (($my_score = @$s[$contact->contactId]) !== null)
                return array($contact->contactId => $my_score);
        }
        return null;
    }

    public function can_view_review_identity_of($cid, $contact, $forceShow = null) {
        global $Conf;
        if ($contact->can_administer($this, $forceShow)
            || $cid == $contact->contactId)
            return true;
        // load information needed to make the call
        if ($this->_review_id_array === null
            || ($contact->review_tokens() && !property_exists($this, "reviewTokens"))) {
            $need = array("contactId", "reviewContactIds",
                          "requestedBy", "reviewRequestedBys",
                          "reviewType", "reviewTypes");
            if ($Conf->review_blindness() == Conference::BLIND_OPTIONAL)
                array_push($need, "reviewBlind", "reviewBlinds");
            if ($contact->review_tokens())
                array_push($need, "reviewToken", "reviewTokens");
            for ($i = 1; $i < count($need); $i += 2)
                if (!property_exists($this, $need[$i])) {
                    $this->load_scores($need);
                    break;
                }
            for ($i = 0; $i < count($need); $i += 2) {
                $k = $need[$i + 1];
                $need[$i + 1] = explode(",", $this->$k);
            }
            $this->_review_id_array = array();
            for ($n = 0; $n < count($need[1]); ++$n) {
                $rrow = (object) array("reviewSubmitted" => 1);
                for ($i = 0; $i < count($need); $i += 2) {
                    $k = $need[$i];
                    $rrow->$k = $need[$i + 1][$n];
                }
                $this->_review_id_array[(int) $rrow->contactId] = $rrow;
            }
        }
        // call contact
        return ($rrow = @$this->_review_id_array[$cid])
            && $contact->can_view_review_identity($this, $rrow, $forceShow);
    }

    public function fetch_comments($where) {
        $result = Dbl::qe("select PaperComment.*, firstName reviewFirstName, lastName reviewLastName, email reviewEmail
            from PaperComment join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)
            where $where order by commentId");
        $comments = array();
        while (($c = CommentInfo::fetch($result, $this)))
            $comments[$c->commentId] = $c;
        Dbl::free($result);
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
