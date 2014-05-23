<?php
// paperinfo.php -- HotCRP paper objects
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperContactInfo {
    public $conflict_type;
    public $review_type;
    public $review_submitted;
    public $review_needs_submit;

    function __construct() {
    }

    static function load($pid, $cid) {
        global $Conf;
        if ($cid)
            $result = $Conf->qe("select conflictType as conflict_type,
                reviewType as review_type,
                reviewSubmitted as review_submitted,
                reviewNeedsSubmit as review_needs_submit
                from (select $pid paperId) crap
                left join PaperConflict on (PaperConflict.paperId=crap.paperId and PaperConflict.contactId=$cid)
                left join PaperReview on (PaperReview.paperId=crap.paperId and PaperReview.contactId=$cid)");
        else
            $result = null;
        if (!$result || !($ci = $result->fetch_object("PaperContactInfo"))) {
            $ci = new PaperContactInfo;
            $ci->conflict_type = $ci->review_type = 0;
            $ci->review_needs_submit = 1;
        } else {
            $ci->conflict_type = (int) $ci->conflict_type;
            $ci->review_type = (int) $ci->review_type;
            $ci->review_submitted = (int) $ci->review_submitted;
            $ci->review_needs_submit = (int) $ci->review_needs_submit;
        }
        return $ci;
    }

    static function load_my($object) {
        $ci = new PaperContactInfo;
        if (property_exists($object, "conflictType"))
            $ci->conflict_type = (int) $object->conflictType;
        if (property_exists($object, "myReviewType"))
            $ci->review_type = (int) $object->myReviewType;
        if (property_exists($object, "myReviewSubmitted"))
            $ci->review_submitted = (int) $object->myReviewSubmitted;
        if (property_exists($object, "myReviewNeedsSubmit"))
            $ci->review_needs_submit = (int) $object->myReviewNeedsSubmit;
        return $ci;
    }
}

class PaperInfo {
    private $contact_info_ = array();

    function __construct($p = null, $contact = null) {
        if ($p)
            foreach ($p as $k => $v)
                $this->$k = $v;
        if ($contact && (property_exists($this, "conflictType")
                         || property_exists($this, "myReviewType"))) {
            $cid = is_object($contact) ? $contact->contactId : $contact;
            $this->assign_contact_info($this, $cid);
        }
    }

    static public function fetch($result, $contact) {
        $pi = $result ? $result->fetch_object("PaperInfo") : null;
        if ($pi && (property_exists($pi, "conflictType")
                    || property_exists($pi, "myReviewType"))) {
            if ($contact === true)
                $cid = property_exists($pi, "contactId") ? $pi->contactId : null;
            else
                $cid = is_object($contact) ? $contact->contactId : $contact;
            $pi->assign_contact_info($pi, $cid);
        }
        return $pi;
    }

    public function contact_info($contact = null) {
        global $Conf, $Me;
        if (!$contact)
            $contact = $Me->contactId;
        else if (is_object($contact))
            $contact = $contact->contactId;
        $ci = @$this->contact_info_[$contact];
        if (!$ci)
            $ci = $this->contact_info_[$contact] =
                PaperContactInfo::load($this->paperId, $contact);
        return $ci;
    }

    public function replace_contact_info_map($cimap) {
        $old_cimap = $this->contact_info_;
        $this->contact_info_ = $cimap;
        return $old_cimap;
    }

    public function assign_contact_info($row, $cid) {
        $this->contact_info_[$cid] = PaperContactInfo::load_my($row);
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
        global $Conf;
        $result = $Conf->qe("select group_concat(' ', tag, '#', tagIndex order by tag separator '') from PaperTag where paperId=$this->paperId group by paperId");
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
        global $Conf;
        $result = $Conf->qe("select group_concat(topicId) from PaperTopic where paperId=$this->paperId");
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
                if ($p[$i+1] != "0" || $p[$i+2] != ".")
                    $x[(int) $p[$i]] = array((int) $p[$i+1],
                                             $p[$i+2] == "." ? null : (int) $p[$i+2]);
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
}
