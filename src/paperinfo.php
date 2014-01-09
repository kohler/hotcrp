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
        $result = $Conf->qe("select conflictType as conflict_type,
		reviewType as review_type,
		reviewSubmitted as review_submitted,
		reviewNeedsSubmit as review_needs_submit
		from (select $pid paperId) crap
		left join PaperConflict on (PaperConflict.paperId=crap.paperId and PaperConflict.contactId=$cid)
		left join PaperReview on (PaperReview.paperId=crap.paperId and PaperReview.contactId=$cid)");
        if (!$result || !($ci = $result->fetch_object("PaperContactInfo"))) {
            $ci = new PaperContactInfo;
            $ci->conflict_type = $ci->review_type = 0;
            $ci->review_needs_submit = 1;
        }
        return $ci;
    }

    static function load_my($object) {
        $ci = new PaperContactInfo;
        if (property_exists($object, "conflictType"))
            $ci->conflict_type = $object->conflictType;
        if (property_exists($object, "myReviewType"))
            $ci->review_type = $object->myReviewType;
        if (property_exists($object, "myReviewSubmitted"))
            $ci->review_submitted = $object->myReviewSubmitted;
        if (property_exists($object, "myReviewNeedsSubmit"))
            $ci->review_needs_submit = $object->myReviewNeedsSubmit;
        return $ci;
    }
}

class PaperInfo {
    private $contact_info_ = array();

    function __construct($p = null, $contact = null) {
        global $Me;
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
        if (!isset($this->contact_info_[$contact]))
            $this->contact_info_[$contact] =
                PaperContactInfo::load($this->paperId, $contact);
        return $this->contact_info_[$contact];
    }

    public function replace_contact_info_map($cimap) {
        $old_cimap = $this->contact_info_;
        $this->contact_info_ = $cimap;
        return $old_cimap;
    }

    public function assign_contact_info($row, $cid = null) {
        $cid = $cid ? $cid : $row->contactId;
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
}
