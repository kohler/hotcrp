<?php
// paperinfo.php -- HotCRP paper objects
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperContactInfo {
    public $paperId;
    public $contactId;
    public $conflict_type = 0;
    public $review_type = 0;
    public $review_submitted = 0;
    public $review_needs_submit = 1;
    public $review_token_cid = 0;

    static function make(PaperInfo $prow, $cid) {
        $ci = new PaperContactInfo;
        $ci->paperId = $prow->paperId;
        $ci->contactId = $cid;
        return $ci;
    }

    static function make_my(PaperInfo $prow, $cid, $object) {
        $ci = PaperContactInfo::make($prow, $cid);
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

    private function merge() {
        if (isset($this->paperId))
            $this->paperId = (int) $this->paperId;
        $this->contactId = (int) $this->contactId;
        $this->conflict_type = (int) $this->conflict_type;
        $this->review_type = (int) $this->review_type;
        $this->review_submitted = (int) $this->review_submitted;
        if ($this->review_needs_submit !== null)
            $this->review_needs_submit = (int) $this->review_needs_submit;
        else
            $this->review_needs_submit = 1;
        $this->review_token_cid = (int) $this->review_token_cid;
        if ($this->review_token_cid == $this->contactId)
            $this->review_token_cid = null;
    }

    static function load_into(PaperInfo $prow, $cid, $rev_tokens = null) {
        global $Me;
        $conf = $prow->conf;
        $pid = $prow->paperId;
        $result = null;
        $q = "select conflictType as conflict_type,
                reviewType as review_type,
                reviewSubmitted as review_submitted,
                reviewNeedsSubmit as review_needs_submit,
                PaperReview.contactId as review_token_cid";
        if ($prow->_row_set && !$rev_tokens) {
            $result = $conf->qe("$q, Paper.paperId paperId, $cid contactId
                from Paper
                left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$cid)
                left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$cid)
                where Paper.paperId?a", $prow->_row_set->pids());
            $found = false;
            $map = [];
            while ($result && ($ci = $result->fetch_object("PaperContactInfo"))) {
                $ci->merge();
                $map[$ci->paperId] = $ci;
            }
            Dbl::free($result);
            foreach ($prow->_row_set->all() as $row)
                $row->_add_contact_info($map[$row->paperId]);
            if ($prow->_get_contact_info($cid))
                return;
        }
        if ($cid && !$rev_tokens
            && (!$Me || ($Me->contactId != $cid
                         && ($Me->privChair || $Me->contactId == $prow->managerContactId)))
            && ($pcm = $conf->pc_members()) && isset($pcm[$cid])) {
            $cids = array_keys($pcm);
            $result = $conf->qe_raw("$q, $pid paperId, ContactInfo.contactId
                from (select $pid paperId) P
                join ContactInfo
                left join PaperReview on (PaperReview.paperId=$pid and PaperReview.contactId=ContactInfo.contactId)
                left join PaperConflict on (PaperConflict.paperId=$pid and PaperConflict.contactId=ContactInfo.contactId)
                where roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0");
        } else {
            $cids = [$cid];
            if ($cid) {
                $q = "$q, $pid paperId, $cid contactId
                from (select $pid paperId) P
                left join PaperReview on (PaperReview.paperId=P.paperId and (PaperReview.contactId=$cid";
                if ($rev_tokens)
                    $q .= " or PaperReview.reviewToken in (" . join(",", $rev_tokens) . ")";
                $result = $conf->qe_raw("$q))
                    left join PaperConflict on (PaperConflict.paperId=$pid and PaperConflict.contactId=$cid)");
            }
        }
        while ($result && ($ci = $result->fetch_object("PaperContactInfo"))) {
            $ci->merge();
            $prow->_add_contact_info($ci);
        }
        Dbl::free($result);
        foreach ($cids as $cid)
            if (!$prow->_get_contact_info($cid))
                $prow->_add_contact_info(PaperContactInfo::make($prow, $cid));
    }
}

class PaperInfo_Author {
    public $firstName;
    public $lastName;
    public $email;
    public $affiliation;
    public $contactId = null;

    function __construct($line) {
        $a = explode("\t", $line);
        $this->firstName = count($a) > 0 ? $a[0] : null;
        $this->lastName = count($a) > 1 ? $a[1] : null;
        $this->email = count($a) > 2 ? $a[2] : null;
        $this->affiliation = count($a) > 3 ? $a[3] : null;
    }
    function name() {
        if ($this->firstName && $this->lastName)
            return $this->firstName . " " . $this->lastName;
        else
            return $this->lastName ? : $this->firstName;
    }
    function abbrevname_text() {
        if ($this->lastName) {
            $u = "";
            if ($this->firstName && ($u = Text::initial($this->firstName)) != "")
                $u .= " "; // non-breaking space
            return $u . $this->lastName;
        } else
            return $this->firstName ? : $this->email ? : "???";
    }
    function abbrevname_html() {
        return htmlspecialchars($this->abbrevname_text());
    }
}

class PaperInfoSet {
    private $prows = [];
    private $by_pid = [];
    function __construct(PaperInfo $prow = null) {
        if ($prow)
            $this->add($prow);
    }
    function add(PaperInfo $prow) {
        assert(!$prow->_row_set);
        $this->prows[] = $prow;
        if (!isset($this->by_pid[$prow->paperId]))
            $this->by_pid[$prow->paperId] = $prow;
        $prow->_row_set = $this;
    }
    function all() {
        return $this->prows;
    }
    function pids() {
        return array_keys($this->by_pid);
    }
    function get($pid) {
        return get($this->by_pid, $pid);
    }
}

class PaperInfo {
    public $paperId;
    public $conf;
    public $title;
    public $authorInformation;
    public $abstract;
    public $collaborators;
    public $timeSubmitted;
    public $timeWithdrawn;
    public $paperStorageId;
    public $finalPaperStorageId;
    public $managerContactId;
    public $paperFormat;
    // $paperTags: DO NOT LIST (property_exists() is meaningful)
    // $optionIds: DO NOT LIST (property_exists() is meaningful)

    private $_contact_info = array();
    private $_contact_info_rights_version = 0;
    private $_author_array = null;
    private $_prefs_array = null;
    private $_review_id_array = null;
    private $_topics_array = null;
    private $_topic_interest_score_array = null;
    private $_option_array = null;
    private $_all_option_array = null;
    private $_document_array = null;
    private $_conflicts;
    private $_conflicts_email;
    private $_comment_array = null;
    private $_comment_skeleton_array = null;
    public $_row_set;

    function __construct($p = null, $contact = null, Conf $conf = null) {
        $this->merge($p, $contact, $conf);
    }

    private function merge($p, $contact, $conf) {
        global $Conf;
        assert($contact === null ? $conf !== null : $contact instanceof Contact);
        if ($contact)
            $conf = $contact->conf;
        $this->conf = $conf ? : $Conf;
        if ($p)
            foreach ($p as $k => $v)
                $this->$k = $v;
        $this->paperId = (int) $this->paperId;
        $this->managerContactId = (int) $this->managerContactId;
        if ($contact && (property_exists($this, "conflictType")
                         || property_exists($this, "myReviewType"))) {
            if ($contact === true)
                $cid = property_exists($this, "contactId") ? $this->contactId : null;
            else
                $cid = is_object($contact) ? $contact->contactId : $contact;
            $this->_contact_info_rights_version = Contact::$rights_version;
            $this->load_my_contact_info($cid, $this);
        }
        foreach (["paperTags", "optionIds"] as $k)
            if (property_exists($this, $k) && $this->$k === null)
                $this->$k = "";
    }

    static function fetch($result, $contact, Conf $conf = null) {
        $prow = $result ? $result->fetch_object("PaperInfo", [null, $contact, $conf]) : null;
        if ($prow && !is_int($prow->paperId))
            $prow->merge(null, $contact, $conf);
        return $prow;
    }

    static function table_name() {
        return "Paper";
    }

    static function id_column() {
        return "paperId";
    }

    static function comment_table_name() {
        return "PaperComment";
    }

    function initial_whynot() {
        return ["fail" => true, "paperId" => $this->paperId, "conf" => $this->conf];
    }


    static private function contact_to_cid($contact) {
        global $Me;
        if ($contact && is_object($contact))
            return $contact->contactId;
        else
            return $contact ? : $Me->contactId;
    }

    function _get_contact_info($cid) {
        return get($this->_contact_info, $cid);
    }

    function _add_contact_info(PaperContactInfo $ci) {
        $this->_contact_info[$ci->contactId] = $ci;
    }

    function contact_info($contact = null) {
        global $Me;
        $rev_tokens = null;
        if (!$contact || is_object($contact)) {
            $contact = $contact ? : $Me;
            $rev_tokens = $contact->review_tokens();
        }
        $cid = self::contact_to_cid($contact);
        if ($this->_contact_info_rights_version !== Contact::$rights_version) {
            $this->_contact_info = array();
            $this->_contact_info_rights_version = Contact::$rights_version;
        }
        if (!array_key_exists($cid, $this->_contact_info)) {
            if (!$rev_tokens && property_exists($this, "allReviewNeedsSubmit")) {
                $ci = PaperContactInfo::make($this, $cid);
                if (($c = get($this->conflicts(), $cid)))
                    $ci->conflict_type = $c->conflictType;
                $ci->review_type = $this->review_type($cid);
                $rs = $this->review_cid_int_array(false, "reviewSubmitted", "allReviewSubmitted");
                $ci->review_submitted = get($rs, $cid, 0);
                $rs = $this->review_cid_int_array(false, "reviewNeedsSubmit", "allReviewNeedsSubmit");
                $ci->review_needs_submit = get($rs, $cid, 1);
                $this->_contact_info[$cid] = $ci;
            } else
                PaperContactInfo::load_into($this, $cid, $rev_tokens);
        }
        return $this->_contact_info[$cid];
    }

    function replace_contact_info_map($cimap) {
        $old_cimap = $this->_contact_info;
        $this->_contact_info = $cimap;
        $this->_contact_info_rights_version = Contact::$rights_version;
        return $old_cimap;
    }

    function load_my_contact_info($cid, $object) {
        $this->_add_contact_info(PaperContactInfo::make_my($this, $cid, $object));
    }


    function pretty_text_title_indent($width = 75) {
        $n = "Paper #{$this->paperId}: ";
        $vistitle = UnicodeHelper::deaccent($this->title);
        $l = (int) (($width + 0.5 - strlen($vistitle) - strlen($n)) / 2);
        return strlen($n) + max(0, $l);
    }

    function pretty_text_title($width = 75) {
        $l = $this->pretty_text_title_indent($width);
        return prefix_word_wrap("Paper #{$this->paperId}: ", $this->title, $l);
    }

    function format_of($text, $check_simple = false) {
        return $this->conf->check_format($this->paperFormat, $check_simple ? $text : null);
    }

    function title_format() {
        return $this->format_of($this->title, true);
    }

    function abstract_format() {
        return $this->format_of($this->abstract, true);
    }

    function author_list() {
        if (!isset($this->_author_array)) {
            $this->_author_array = array();
            foreach (explode("\n", $this->authorInformation) as $line)
                if ($line != "")
                    $this->_author_array[] = new PaperInfo_Author($line);
        }
        return $this->_author_array;
    }

    function author_by_email($email) {
        foreach ($this->author_list() as $a)
            if (strcasecmp($a, $email) == 0)
                return $a;
        return null;
    }

    function parse_author_list() {
        $ai = "";
        foreach ($this->_author_array as $au)
            $ai .= $au->firstName . "\t" . $au->lastName . "\t" . $au->email . "\t" . $au->affiliation . "\n";
        return ($this->authorInformation = $ai);
    }

    function pretty_text_author_list() {
        $info = "";
        foreach ($this->author_list() as $au) {
            $info .= $au->name() ? : $au->email;
            if ($au->affiliation)
                $info .= " (" . $au->affiliation . ")";
            $info .= "\n";
        }
        return $info;
    }

    function conflict_type($contact = null) {
        $ci = $this->contact_info($contact);
        return $ci ? $ci->conflict_type : 0;
    }

    function has_conflict($contact) {
        return $this->conflict_type($contact) > 0;
    }

    function has_author($contact) {
        return $this->conflict_type($contact) >= CONFLICT_AUTHOR;
    }

    function can_author_view_decision() {
        return $this->conf->can_all_author_view_decision();
    }

    function review_type($contact) {
        $cid = self::contact_to_cid($contact);
        if ($this->_contact_info_rights_version === Contact::$rights_version
            && array_key_exists($cid, $this->_contact_info)) {
            $ci = $this->_contact_info[$cid];
            return $ci ? $ci->review_type : 0;
        }
        if (!isset($this->allReviewTypes) && isset($this->reviewTypes)
            && ($x = get($this->submitted_review_types(), $cid)) !== null)
            return $x;
        return get($this->all_review_types(), $cid);
    }

    function has_reviewer($contact) {
        return $this->review_type($contact) > 0;
    }

    function review_not_incomplete($contact = null) {
        $ci = $this->contact_info($contact);
        return $ci && $ci->review_type > 0
            && ($ci->review_submitted > 0 || $ci->review_needs_submit == 0);
    }

    function review_submitted($contact = null) {
        $ci = $this->contact_info($contact);
        return $ci && $ci->review_type > 0 && $ci->review_submitted > 0;
    }

    function pc_can_become_reviewer() {
        if (!$this->conf->check_track_review_sensitivity())
            return $this->conf->pc_members();
        else {
            $pcm = array();
            foreach ($this->conf->pc_members() as $cid => $pc)
                if ($pc->can_become_reviewer_ignore_conflict($this))
                    $pcm[$cid] = $pc;
            return $pcm;
        }
    }

    function load_tags() {
        $result = $this->conf->qe("select group_concat(' ', tag, '#', tagIndex order by tag separator '') from PaperTag where paperId=? group by paperId", $this->paperId);
        $this->paperTags = "";
        if (($row = edb_row($result)) && $row[0] !== null)
            $this->paperTags = $row[0];
        Dbl::free($result);
    }

    function has_tag($tag) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        return $this->paperTags !== ""
            && stripos($this->paperTags, " $tag#") !== false;
    }

    function has_any_tag($tags) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        foreach ($tags as $tag)
            if (stripos($this->paperTags, " $tag#") !== false)
                return true;
        return false;
    }

    function has_viewable_tag($tag, Contact $user, $forceShow = null) {
        $tags = $this->viewable_tags($user, $forceShow);
        return $tags !== "" && stripos(" " . $tags, " $tag#") !== false;
    }

    function tag_value($tag) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        if ($this->paperTags !== ""
            && ($pos = stripos($this->paperTags, " $tag#")) !== false)
            return (float) substr($this->paperTags, $pos + strlen($tag) + 2);
        else
            return false;
    }

    function all_tags_text() {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        return $this->paperTags;
    }

    function searchable_tags(Contact $user, $forceShow = null) {
        if ($user->allow_administer($this))
            return $this->all_tags_text();
        else
            return $this->viewable_tags($user, $forceShow);
    }

    function viewable_tags(Contact $user, $forceShow = null) {
        // see also Contact::can_view_tag()
        if ($user->can_view_most_tags($this, $forceShow))
            return Tagger::strip_nonviewable($this->all_tags_text(), $user);
        else if ($user->privChair && $user->can_view_tags($this, $forceShow))
            return Tagger::strip_nonsitewide($this->all_tags_text(), $user);
        else
            return "";
    }

    function editable_tags(Contact $user) {
        $tags = $this->viewable_tags($user);
        if ($tags !== "") {
            $privChair = $user->allow_administer($this);
            $etags = array();
            foreach (explode(" ", $tags) as $tag)
                if (!($tag === ""
                      || (($t = $this->conf->tags()->check_base($tag))
                          && ($t->vote
                              || $t->approval
                              || (!$privChair
                                  && (!$user->privChair || !$t->sitewide)
                                  && ($t->chair || $t->rank))))))
                    $etags[] = $tag;
            $tags = join(" ", $etags);
        }
        return $tags;
    }

    function add_tag_info_json($pj, Contact $user) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        $tagger = new Tagger($user);
        $editable = $this->editable_tags($user);
        $viewable = $this->viewable_tags($user);
        $tags_view_html = $tagger->unparse_and_link($viewable, false);
        $pj->tags = TagInfo::split($viewable);
        $pj->tags_edit_text = $tagger->unparse($editable);
        $pj->tags_view_html = $tags_view_html;
        if (($td = $tagger->unparse_decoration_html($viewable)))
            $pj->tag_decoration_html = $td;
        $pj->color_classes = $this->conf->tags()->color_classes($viewable);
    }

    private function load_topics() {
        $result = $this->conf->qe_raw("select group_concat(topicId) from PaperTopic where paperId=$this->paperId");
        $row = edb_row($result);
        $this->topicIds = $row ? $row[0] : "";
        Dbl::free($result);
    }

    function has_topics() {
        return count($this->topics()) > 0;
    }

    function topics() {
        if ($this->_topics_array === null) {
            if (!property_exists($this, "topicIds"))
                $this->load_topics();
            if (is_array($this->topicIds))
                $this->_topics_array = $this->topicIds;
            else {
                $this->_topics_array = array();
                if ($this->topicIds !== "" && $this->topicIds !== null) {
                    foreach (explode(",", $this->topicIds) as $t)
                        $this->_topics_array[] = (int) $t;
                    $tomap = $this->conf->topic_order_map();
                    usort($this->_topics_array, function ($a, $b) use ($tomap) {
                        return $tomap[$a] - $tomap[$b];
                    });
                }
            }
        }
        return $this->_topics_array;
    }

    function unparse_topics_text() {
        $tarr = $this->topics();
        if (!$tarr)
            return "";
        $out = [];
        $tmap = $this->conf->topic_map();
        foreach ($tarr as $t)
            $out[] = $tmap[$t];
        return join("; ", $out);
    }

    private static function render_topic($t, $i, $tmap, &$long) {
        $s = '<span class="topicsp topic' . ($i ? : 0);
        $tname = $tmap[$t];
        if (strlen($tname) <= 50)
            $s .= ' nw';
        else
            $long = true;
        return $s . '">' . htmlspecialchars($tname) . '</span>';
    }

    private static function render_topic_list(Conf $conf, $out, $comma, $long) {
        if ($comma)
            return join($conf->topic_separator(), $out);
        else if ($long)
            return '<p class="od">' . join('</p><p class="od">', $out) . '</p>';
        else
            return '<p class="topicp">' . join(' ', $out) . '</p>';
    }

    function unparse_topics_html($comma, Contact $interests_user = null) {
        if (!($topics = $this->topics()))
            return "";
        $out = array();
        $tmap = $this->conf->topic_map();
        $interests = $interests_user ? $interests_user->topic_interest_map() : array();
        $long = false;
        foreach ($topics as $t)
            $out[] = self::render_topic($t, get($interests, $t), $tmap, $long);
        return self::render_topic_list($this->conf, $out, $comma, $long);
    }

    static function unparse_topic_list_html(Conf $conf, $ti, $comma) {
        if (!$ti)
            return "";
        $out = array();
        $tmap = $conf->topic_map();
        $tomap = $conf->topic_order_map();
        $long = false;
        foreach ($ti as $t => $i)
            $out[$tomap[$t]] = self::render_topic($t, $i, $tmap, $long);
        ksort($out);
        return self::render_topic_list($conf, $out, $comma, $long);
    }

    private static $topic_interest_values = [-0.7071, -0.5, 0, 0.7071, 1];

    function topic_interest_score($contact) {
        $score = 0;
        if (is_int($contact))
            $contact = get($this->conf->pc_members(), $contact);
        if ($contact) {
            if ($this->_topic_interest_score_array === null)
                $this->_topic_interest_score_array = array();
            if (isset($this->_topic_interest_score_array[$contact->contactId]))
                $score = $this->_topic_interest_score_array[$contact->contactId];
            else {
                $interests = $contact->topic_interest_map();
                $topics = $this->topics();
                foreach ($topics as $t)
                    if (($j = get($interests, $t, 0))) {
                        if ($j >= -2 && $j <= 2)
                            $score += self::$topic_interest_values[$j + 2];
                        else if ($j > 2)
                            $score += sqrt($j / 2);
                        else
                            $score += -sqrt(-$j / 4);
                    }
                if ($score)
                    // * Strong interest in the paper's single topic gets
                    //   score 10.
                    $score = (int) ($score / sqrt(count($topics)) * 10 + 0.5);
                $this->_topic_interest_score_array[$contact->contactId] = $score;
            }
        }
        return $score;
    }

    function conflicts($email = false) {
        if ($email ? !$this->_conflicts_email : !isset($this->_conflicts)) {
            $this->_conflicts = array();
            if (!$email && isset($this->allConflictType)) {
                $vals = array();
                foreach (explode(",", $this->allConflictType) as $x)
                    $vals[] = explode(" ", $x);
            } else if (!$email)
                $vals = $this->conf->fetch_rows("select contactId, conflictType from PaperConflict where paperId=$this->paperId");
            else {
                $vals = $this->conf->fetch_rows("select ContactInfo.contactId, conflictType, email from PaperConflict join ContactInfo using (contactId) where paperId=$this->paperId");
                $this->_conflicts_email = true;
            }
            foreach ($vals as $v)
                if ($v[1] > 0) {
                    $row = (object) array("contactId" => (int) $v[0], "conflictType" => (int) $v[1]);
                    if (isset($v[2]) && $v[2])
                        $row->email = $v[2];
                    $this->_conflicts[$row->contactId] = $row;
                }
        }
        return $this->_conflicts;
    }

    function pc_conflicts($email = false) {
        return array_intersect_key($this->conflicts($email), $this->conf->pc_members());
    }

    function contacts($email = false) {
        $c = array();
        foreach ($this->conflicts($email) as $id => $cflt)
            if ($cflt->conflictType >= CONFLICT_AUTHOR)
                $c[$id] = $cflt;
        return $c;
    }

    function named_contacts() {
        $vals = Dbl::fetch_objects($this->conf->qe("select ContactInfo.contactId, conflictType, email, firstName, lastName, affiliation from PaperConflict join ContactInfo using (contactId) where paperId=$this->paperId and conflictType>=" . CONFLICT_AUTHOR));
        foreach ($vals as $v) {
            $v->contactId = (int) $v->contactId;
            $v->conflictType = (int) $v->conflictType;
        }
        return $vals;
    }

    private function load_reviewer_preferences() {
        $this->allReviewerPreference = $this->conf->fetch_value("select " . $this->conf->query_all_reviewer_preference() . " from PaperReviewPreference where paperId=$this->paperId");
        $this->_prefs_array = null;
    }

    function reviewer_preferences() {
        if (!property_exists($this, "allReviewerPreference"))
            $this->load_reviewer_preferences();
        if ($this->_prefs_array === null) {
            $x = array();
            if ($this->allReviewerPreference !== "" && $this->allReviewerPreference !== null) {
                $p = preg_split('/[ ,]/', $this->allReviewerPreference);
                for ($i = 0; $i + 2 < count($p); $i += 3) {
                    if ($p[$i+1] != "0" || $p[$i+2] != ".")
                        $x[(int) $p[$i]] = array((int) $p[$i+1], $p[$i+2] == "." ? null : (int) $p[$i+2]);
                }
            }
            $this->_prefs_array = $x;
        }
        return $this->_prefs_array;
    }

    function reviewer_preference($contact) {
        $cid = is_int($contact) ? $contact : $contact->contactId;
        $pref = get($this->reviewer_preferences(), $cid);
        return $pref ? : [0, null];
    }

    function options() {
        if ($this->_option_array === null)
            $this->_option_array = PaperOption::parse_paper_options($this, false);
        return $this->_option_array;
    }

    function option($id) {
        return get($this->options(), $id);
    }

    function all_options() {
        if ($this->_all_option_array === null)
            $this->_all_option_array = PaperOption::parse_paper_options($this, true);
        return $this->_all_option_array;
    }

    function all_option($id) {
        return get($this->all_options(), $id);
    }

    function invalidate_options() {
        $this->_option_array = $this->_all_option_array = null;
    }

    private function _add_documents($dids) {
        if ($this->_document_array === null)
            $this->_document_array = [];
        $result = $this->conf->qe("select paperStorageId, paperId, timestamp, mimetype, mimetypeid, sha1, documentType, filename, infoJson, size, filterType, originalStorageId from PaperStorage where paperId=? and paperStorageId?a", $this->paperId, $dids);
        $loaded_dids = [];
        while (($di = DocumentInfo::fetch($result, $this->conf, $this))) {
            $this->_document_array[$di->paperStorageId] = $di;
            $loaded_dids[] = $di->paperStorageId;
        }
        Dbl::free($result);
        // rarely might refer to a doc owned by a different paper
        if (count($loaded_dids) != count($dids)
            && ($dids = array_diff($dids, $loaded_dids))) {
            $result = $this->conf->qe("select paperStorageId, paperId, timestamp, mimetype, mimetypeid, sha1, documentType, filename, infoJson, size, filterType, originalStorageId from PaperStorage where paperStorageId?a", $dids);
            while (($di = DocumentInfo::fetch($result, $this->conf, $this)))
                $this->_document_array[$di->paperStorageId] = $di;
            Dbl::free($result);
        }
    }

    function document($dtype, $did = 0, $full = false) {
        if ($did <= 0) {
            if ($dtype == DTYPE_SUBMISSION)
                $did = $this->paperStorageId;
            else if ($dtype == DTYPE_FINAL)
                $did = $this->finalPaperStorageId;
            else if (($oa = $this->option($dtype)) && $oa->option->is_document())
                return $oa->document(0);
        }
        if ($did <= 1)
            return null;

        if ($this->_document_array !== null && isset($this->_document_array[$did]))
            return $this->_document_array[$did];

        if ((($dtype == DTYPE_SUBMISSION && $did == $this->paperStorageId && $this->finalPaperStorageId <= 0)
             || ($dtype == DTYPE_FINAL && $did == $this->finalPaperStorageId))
            && !$full) {
            $infoJson = get($this, $dtype == DTYPE_SUBMISSION ? "paper_infoJson" : "final_infoJson");
            return new DocumentInfo(["paperStorageId" => $did, "paperId" => $this->paperId, "documentType" => $dtype, "timestamp" => get($this, "timestamp"), "mimetype" => $this->mimetype, "sha1" => $this->sha1, "size" => get($this, "size"), "infoJson" => $infoJson, "is_partial" => true], $this->conf, $this);
        }

        if ($this->_document_array === null) {
            $x = [];
            if ($this->paperStorageId > 0)
                $x[] = $this->paperStorageId;
            if ($this->finalPaperStorageId > 0)
                $x[] = $this->finalPaperStorageId;
            foreach ($this->options() as $oa)
                if ($oa->option->has_document_storage())
                    $x = array_merge($x, $oa->unsorted_values());
            if ($did > 0)
                $x[] = $did;
            $this->_add_documents($x);
        }
        if ($did > 0 && !isset($this->_document_array[$did]))
            $this->_add_documents([$did]);
        return $did > 0 ? get($this->_document_array, $did) : null;
    }
    function is_joindoc(DocumentInfo $doc) {
        return $doc->paperStorageId > 1
            && (($doc->paperStorageId == $this->paperStorageId
                 && $this->finalPaperStorageId <= 0
                 && $doc->documentType == DTYPE_SUBMISSION)
                || ($doc->paperStorageId == $this->finalPaperStorageId
                    && $doc->documentType == DTYPE_FINAL));
    }

    function npages() {
        $doc = $this->document($this->finalPaperStorageId <= 0 ? DTYPE_SUBMISSION : DTYPE_FINAL);
        return $doc ? $doc->npages() : 0;
    }

    function num_reviews_submitted() {
        if (!property_exists($this, "reviewCount"))
            $this->reviewCount = $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=$this->paperId and reviewSubmitted>0");
        return (int) $this->reviewCount;
    }

    function num_reviews_assigned() {
        if (!property_exists($this, "startedReviewCount"))
            $this->startedReviewCount = $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=$this->paperId and (reviewSubmitted>0 or reviewNeedsSubmit>0)");
        return (int) $this->startedReviewCount;
    }

    function num_reviews_in_progress() {
        if (!property_exists($this, "inProgressReviewCount")) {
            if (isset($this->reviewCount) && isset($this->startedReviewCount) && $this->reviewCount === $this->startedReviewCount)
                $this->inProgressReviewCount = $this->reviewCount;
            else
                $this->inProgressReviewCount = $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=$this->paperId and (reviewSubmitted>0 or reviewModified>0)");
        }
        return (int) $this->inProgressReviewCount;
    }

    function num_reviews_started($user) {
        if ($user->privChair || !$this->conflict_type($user))
            return $this->num_reviews_assigned();
        else
            return $this->num_reviews_in_progress();
    }

    private function load_score_array($restriction, $args) {
        $req = array();
        for ($i = 0; $i < count($args); $i += 2)
            $req[] = "group_concat(" . $args[$i] . " order by reviewId) " . $args[$i + 1];
        $result = $this->conf->qe("select " . join(", ", $req) . " from PaperReview where paperId=$this->paperId and " . ($restriction ? "reviewSubmitted>0" : "true"));
        $row = $result ? $result->fetch_assoc() : null;
        foreach ($row ? : array() as $k => $v)
            $this->$k = $v;
        Dbl::free($result);
    }

    private function load_scores(/* args */) {
        $args = func_get_args();
        $this->load_score_array(true, count($args) == 1 ? $args[0] : $args);
    }

    private function review_cid_int_array($restriction, $basek, $k) {
        $ck = $restriction ? "reviewContactIds" : "allReviewContactIds";
        if (!property_exists($this, $ck)
            || (!empty($this->$ck) && !property_exists($this, $k)))
            $this->load_score_array($restriction, [$basek, $k, "contactId", $ck]);
        if (empty($this->$ck))
            return array();
        $ka = explode(",", $this->$ck);
        $va = json_decode("[" . $this->$k . "]", true); // json_decode produces int values
        return count($ka) == count($va) ? array_combine($ka, $va) : false;
    }

    function all_reviewers() {
        if (!property_exists($this, "allReviewContactIds"))
            $this->load_score_array(false, ["contactId", "allReviewContactIds"]);
        return json_decode("[" . ($this->allReviewContactIds ? : "") . "]");
    }

    function submitted_reviewers() {
        if (!property_exists($this, "reviewContactIds"))
            $this->load_score_array(true, ["contactId", "reviewContactIds"]);
        return json_decode("[" . ($this->reviewContactIds ? : "") . "]");
    }

    function viewable_submitted_reviewers($contact, $forceShow) {
        if (!property_exists($this, "reviewContactIds"))
            $this->load_scores("contactId", "reviewContactIds");
        if ($this->reviewContactIds) {
            if ($contact->can_view_review($this, null, $forceShow))
                return json_decode("[" . $this->reviewContactIds . "]");
            else if ($this->review_type($contact))
                return array($contact->contactId);
        }
        return array();
    }

    function all_review_ids() {
        return $this->review_cid_int_array(false, "reviewId", "allReviewIds");
    }

    function review_ordinals() {
        return $this->review_cid_int_array(true, "reviewOrdinal", "reviewOrdinals");
    }

    function review_ordinal($cid) {
        return get($this->review_ordinals(), $cid);
    }

    function all_review_types() {
        return $this->review_cid_int_array(false, "reviewType", "allReviewTypes");
    }

    function submitted_review_types() {
        return $this->review_cid_int_array(true, "reviewType", "reviewTypes");
    }

    private function _review_word_counts($restriction, $basek, $k, $count) {
        $a = $this->review_cid_int_array($restriction, $basek, $k);
        if ($a !== false || $count)
            return $a;
        $result = $this->conf->qe("select * from PaperReview where reviewWordCount is null and paperId=$this->paperId");
        $rf = $this->conf->review_form();
        $qs = [];
        while (($rrow = edb_orow($result)))
            $qs[] = "update PaperReview set reviewWordCount=" . $rf->word_count($rrow) . " where reviewId=" . $rrow->reviewId;
        Dbl::free($result);
        if (count($qs)) {
            $mresult = Dbl::multi_qe($this->conf->dblink, join(";", $qs));
            while (($result = $mresult->next()))
                Dbl::free($result);
            unset($this->reviewWordCounts, $this->allReviewWordCounts);
        }
        return $this->_review_word_counts($restriction, $basek, $k, $count + 1);
    }

    function submitted_review_word_counts() {
        return $this->_review_word_counts(true, "reviewWordCount", "reviewWordCounts", 0);
    }

    function all_review_word_counts() {
        return $this->_review_word_counts(false, "reviewWordCount", "allReviewWordCounts", 0);
    }

    function submitted_review_word_count($cid) {
        return get($this->submitted_review_word_counts(), $cid);
    }

    function all_review_rounds() {
        return $this->review_cid_int_array(false, "reviewRound", "allReviewRounds");
    }

    function submitted_review_rounds() {
        return $this->review_cid_int_array(true, "reviewRound", "reviewRounds");
    }

    function submitted_review_round($cid) {
        return get($this->submitted_review_rounds());
    }

    function review_round($cid) {
        if (!isset($this->allReviewRounds) && isset($this->reviewRounds)
            && ($x = get($this->submitted_review_rounds())) !== null)
            return $x;
        return get($this->all_review_rounds(), $cid);
    }

    function scores($fid) {
        $fid = is_object($fid) ? $fid->id : $fid;
        return $this->review_cid_int_array(true, $fid, "{$fid}Scores");
    }

    function score($fid, $cid) {
        return get($this->scores($fid), $cid);
    }

    function may_have_viewable_scores($field, Contact $contact, $forceShow) {
        $field = is_object($field) ? $field : $this->conf->review_field($field);
        return $contact->can_view_review($this, $field->view_score, $forceShow)
            || $this->review_type($contact);
    }

    function viewable_scores($field, Contact $contact, $forceShow) {
        $field = is_object($field) ? $field : $this->conf->review_field($field);
        $view = $contact->can_view_review($this, $field->view_score, $forceShow);
        if ($view || $this->review_type($contact)) {
            $s = $this->scores($field->id);
            if ($view)
                return $s;
            else if (($my_score = get($s, $contact->contactId)) !== null)
                return array($contact->contactId => $my_score);
        }
        return null;
    }

    function can_view_review_identity_of($cid, Contact $contact, $forceShow = null) {
        if ($contact->can_administer($this, $forceShow)
            || $cid == $contact->contactId)
            return true;
        // load information needed to make the call
        if ($this->_review_id_array === null
            || ($contact->review_tokens() && !property_exists($this, "reviewTokens"))) {
            $need = array("contactId", "reviewContactIds",
                          "requestedBy", "reviewRequestedBys",
                          "reviewType", "reviewTypes");
            if ($this->conf->review_blindness() == Conf::BLIND_OPTIONAL)
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
        return ($rrow = get($this->_review_id_array, $cid))
            && $contact->can_view_review_identity($this, $rrow, $forceShow);
    }

    static function fetch_comment_query() {
        return "select PaperComment.*,
            firstName reviewFirstName, lastName reviewLastName, email reviewEmail
            from PaperComment
            join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)";
    }

    function fetch_comments($extra_where = null) {
        $result = $this->conf->qe(self::fetch_comment_query()
            . " where paperId={$this->paperId}" . ($extra_where ? " and $extra_where" : "")
            . " order by commentId");
        $comments = array();
        while (($c = CommentInfo::fetch($result, $this, $this->conf)))
            $comments[$c->commentId] = $c;
        Dbl::free($result);
        return $comments;
    }

    function load_comments() {
        $row_set = $this->_row_set ? : new PaperInfoSet($this);
        $result = $this->conf->qe(self::fetch_comment_query()
            . " where paperId?a order by commentId", $row_set->pids());
        $comments = [];
        while (($c = CommentInfo::fetch($result, null, $this->conf))) {
            $c->set_prow($row_set->get($c->paperId));
            $comments[$c->paperId][$c->commentId] = $c;
        }
        Dbl::free($result);
        foreach ($row_set->all() as $prow)
            $prow->_comment_array = get($comments, $prow->paperId, []);
    }

    function all_comments() {
        if ($this->_comment_array === null)
            $this->load_comments();
        return $this->_comment_array;
    }

    function viewable_comments(Contact $user, $forceShow) {
        $crows = [];
        foreach ($this->all_comments() as $cid => $crow)
            if ($user->can_view_comment($this, $crow, $forceShow))
                $crows[$cid] = $crow;
        return $crows;
    }

    function all_comment_skeletons() {
        if ($this->_comment_skeleton_array !== null)
            return $this->_comment_skeleton_array;
        if ($this->_comment_array !== null
            || !property_exists($this, "commentSkeletonInfo"))
            return $this->all_comments();
        $this->_comment_skeleton_array = [];
        preg_match_all('/(\d+);(\d+);(\d+);(\d+);([^|]*)/', $this->commentSkeletonInfo, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $c = new CommentInfo((object) [
                    "commentId" => $m[1], "contactId" => $m[2],
                    "commentType" => $m[3], "commentRound" => $m[4],
                    "commentTags" => $m[5]
                ], $this, $this->conf);
            $this->_comment_skeleton_array[$c->commentId] = $c;
        }
        return $this->_comment_skeleton_array;
    }

    function viewable_comment_skeletons(Contact $user, $forceShow) {
        $crows = [];
        foreach ($this->all_comment_skeletons() as $cid => $crow)
            if ($user->can_view_comment($this, $crow, $forceShow))
                $crows[$cid] = $crow;
        return $crows;
    }


    function notify($notifytype, $callback, $contact) {
        $wonflag = ($notifytype << WATCHSHIFT_ON) | ($notifytype << WATCHSHIFT_ALLON);
        $wsetflag = $wonflag | ($notifytype << WATCHSHIFT_ISSET);

        $q = "select ContactInfo.contactId, firstName, lastName, email,
                password, contactTags, roles, defaultWatch,
                PaperReview.reviewType myReviewType,
                PaperReview.reviewSubmitted myReviewSubmitted,
                PaperReview.reviewNeedsSubmit myReviewNeedsSubmit,
                conflictType, watch, preferredEmail, disabled
        from ContactInfo
        left join PaperConflict on (PaperConflict.paperId=$this->paperId and PaperConflict.contactId=ContactInfo.contactId)
        left join PaperWatch on (PaperWatch.paperId=$this->paperId and PaperWatch.contactId=ContactInfo.contactId)
        left join PaperReview on (PaperReview.paperId=$this->paperId and PaperReview.contactId=ContactInfo.contactId)
        where watch is not null
        or conflictType>=" . CONFLICT_AUTHOR . "
        or reviewType is not null
        or (select commentId from PaperComment where paperId=$this->paperId and contactId=ContactInfo.contactId limit 1) is not null
        or (defaultWatch & " . ($notifytype << WATCHSHIFT_ALLON) . ")!=0";
        if ($this->managerContactId > 0)
            $q .= " or ContactInfo.contactId=" . $this->managerContactId;
        $q .= " order by conflictType"; // group authors together

        $result = $this->conf->qe_raw($q);
        $watchers = array();
        $lastContactId = 0;
        while ($result && ($row = Contact::fetch($result))) {
            if ($row->contactId == $lastContactId
                || ($contact && $row->contactId == $contact->contactId)
                || Contact::is_anonymous_email($row->email))
                continue;
            $lastContactId = $row->contactId;

            $w = $row->defaultWatch;
            if ($row->watch & $wsetflag)
                $w = $row->watch;
            if ($w & $wonflag)
                $watchers[$row->contactId] = $row;
        }
        Dbl::free($result);

        // save my current contact info map -- we are replacing it with another
        // map that lacks review token information and so forth
        $cimap = $this->replace_contact_info_map(null);

        foreach ($watchers as $minic) {
            $this->load_my_contact_info($minic->contactId, $minic);
            call_user_func($callback, $this, $minic);
        }

        $this->replace_contact_info_map($cimap);
    }
}
