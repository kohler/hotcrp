<?php
// paperinfo.php -- HotCRP paper objects
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
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

class PaperInfo_Author {
    public $firstName;
    public $lastName;
    public $email;
    public $affiliation;
    public $contactId = null;

    public function __construct($line) {
        $a = explode("\t", $line);
        $this->firstName = count($a) > 0 ? $a[0] : null;
        $this->lastName = count($a) > 1 ? $a[1] : null;
        $this->email = count($a) > 2 ? $a[2] : null;
        $this->affiliation = count($a) > 3 ? $a[3] : null;
    }
    public function name() {
        if ($this->firstName && $this->lastName)
            return $this->firstName . " " . $this->lastName;
        else
            return $this->lastName ? : $this->firstName;
    }
    public function abbrevname_text() {
        if ($this->lastName) {
            $u = "";
            if ($this->firstName && ($u = Text::initial($this->firstName)) != "")
                $u .= " "; // non-breaking space
            return $u . $this->lastName;
        } else
            return $this->firstName ? : $this->email ? : "???";
    }
    public function abbrevname_html() {
        return htmlspecialchars($this->abbrevname_text());
    }
}

class PaperDocumentInfo {
    public $paperStorageId;
    public $paperId;
    public $documentType;
    public $timestamp;
    public $mimetype;
    public $mimetypeid;
    public $sha1;
    public $size;
    public $content;
    public $compression;
    public $filename;
    public $unique_filename;
    public $infoJson;
    public $infoJson_str;
    public $filterType;
    public $originalStorageId;
    public $docclass;
    public $is_partial = false;

    public function __construct($p = null) {
        $this->merge($p);
    }

    private function merge($p) {
        if ($p)
            foreach ($p as $k => $v)
                $this->$k = $v;
        $this->paperStorageId = (int) $this->paperStorageId;
        $this->paperId = (int) $this->paperId;
        $this->documentType = (int) $this->documentType;
        $this->timestamp = (int) $this->timestamp;
        $this->mimetypeid = (int) $this->mimetypeid;
        $this->size = (int) $this->size;
        if (is_string($this->infoJson))
            $this->infoJson_str = $this->infoJson;
        $this->infoJson = $this->infoJson ? json_decode($this->infoJson) : null;
        $this->filterType = $this->filterType ? (int) $this->filterType : null;
        $this->originalStorageId = $this->originalStorageId ? (int) $this->originalStorageId : null;
        $this->docclass = HotCRPDocument::get($this->documentType);

        // in modern versions sha1 is set at storage time; before it wasn't
        if ($this->paperStorageId > 1 && $this->sha1 == ""
            && $this->docclass->load_content($this)) {
            $this->sha1 = sha1($this->content, true);
            Dbl::q("update PaperStorage set sha1=? where paperStorageId=?", $this->sha1, $this->paperStorageId);
        }
    }

    static public function fetch($result) {
        $di = $result ? $result->fetch_object("PaperDocumentInfo") : null;
        if ($di && !is_int($di->paperStorageId))
            $di->merge(null);
        return $di;
    }
}

class PaperInfo {
    public $paperId;
    public $title;
    public $authorInformation;
    public $abstract;
    public $collaborators;
    public $timeSubmitted;
    public $timeWithdrawn;
    public $paperStorageId;
    public $managerContactId;
    public $paperFormat;

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

    function __construct($p = null, $contact = null) {
        $this->merge($p, $contact);
    }

    private function merge($p, $contact) {
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
            $this->assign_contact_info($this, $cid);
        }
        if (property_exists($this, "paperTags") && $this->paperTags === null)
            $this->paperTags = "";
    }

    static public function fetch($result, $contact) {
        $prow = $result ? $result->fetch_object("PaperInfo", [null, $contact]) : null;
        if ($prow && !is_int($prow->paperId))
            $prow->merge(null, $contact);
        return $prow;
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

    public function initial_whynot() {
        return ["fail" => true, "paperId" => $this->paperId];
    }


    static private function contact_to_cid($contact) {
        global $Me;
        if ($contact && is_object($contact))
            return $contact->contactId;
        else
            return $contact ? : $Me->contactId;
    }

    public function contact_info($contact = null) {
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
        if (!array_key_exists($cid, $this->_contact_info))
            $this->_contact_info[$cid] =
                PaperContactInfo::load($this->paperId, $cid, $rev_tokens);
        return $this->_contact_info[$cid];
    }

    public function replace_contact_info_map($cimap) {
        $old_cimap = $this->_contact_info;
        $this->_contact_info = $cimap;
        $this->_contact_info_rights_version = Contact::$rights_version;
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
        return prefix_word_wrap("Paper #{$this->paperId}: ", $this->title, $l);
    }

    public function format_of($text, $check_simple = false) {
        return Conf::check_format($this->paperFormat, $check_simple ? $text : null);
    }

    public function title_format() {
        return $this->format_of($this->title, true);
    }

    public function author_list() {
        if (!isset($this->_author_array)) {
            $this->_author_array = array();
            foreach (explode("\n", $this->authorInformation) as $line)
                if ($line != "")
                    $this->_author_array[] = new PaperInfo_Author($line);
        }
        return $this->_author_array;
    }

    public function author_by_email($email) {
        foreach ($this->author_list() as $a)
            if (strcasecmp($a, $email) == 0)
                return $a;
        return null;
    }

    public function parse_author_list() {
        $ai = "";
        foreach ($this->_author_array as $au)
            $ai .= $au->firstName . "\t" . $au->lastName . "\t" . $au->email . "\t" . $au->affiliation . "\n";
        return ($this->authorInformation = $ai);
    }

    public function pretty_text_author_list() {
        $info = "";
        foreach ($this->author_list() as $au) {
            $info .= $au->name() ? : $au->email;
            if ($au->affiliation)
                $info .= " (" . $au->affiliation . ")";
            $info .= "\n";
        }
        return $info;
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
        $cid = self::contact_to_cid($contact);
        if ($this->_contact_info_rights_version === Contact::$rights_version
            && array_key_exists($cid, $this->_contact_info)) {
            $ci = $this->_contact_info[$cid];
            return $ci ? $ci->review_type : 0;
        }
        if (!isset($this->allReviewTypes) && isset($this->reviewTypes)
            && ($x = get($this->submitted_review_types())) !== null)
            return $x;
        return get($this->all_review_types(), $cid);
    }

    public function has_review($contact = null) {
        return $this->review_type($contact) > 0;
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

    public function has_viewable_tag($tag, $user) {
        $tags = $this->viewable_tags($user);
        return $tags !== "" && stripos(" " . $tags, " $tag#") !== false;
    }

    public function tag_value($tag) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        if ($this->paperTags !== ""
            && ($pos = stripos($this->paperTags, " $tag#")) !== false)
            return (float) substr($this->paperTags, $pos + strlen($tag) + 2);
        else
            return false;
    }

    public function all_tags_text() {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        return $this->paperTags;
    }

    public function viewable_tags(Contact $user, $forceShow = null) {
        // see also Contact::can_view_tag()
        if ($user->can_view_most_tags($this, $forceShow))
            return Tagger::strip_nonviewable($this->all_tags_text(), $user);
        else if ($user->privChair && $user->can_view_tags($this, $forceShow))
            return Tagger::strip_nonsitewide($this->all_tags_text(), $user);
        else
            return "";
    }

    public function editable_tags(Contact $user) {
        $tags = $this->viewable_tags($user);
        if ($tags !== "") {
            $privChair = $user->allow_administer($this);
            $dt = TagInfo::defined_tags();
            $etags = array();
            foreach (explode(" ", $tags) as $t)
                if (!($t === ""
                      || (($v = $dt->check(TagInfo::base($t)))
                          && ($v->vote
                              || $v->approval
                              || (!$privChair
                                  && (!$user->privChair || !$v->sitewide)
                                  && ($v->chair || $v->rank))))))
                    $etags[] = $t;
            $tags = join(" ", $etags);
        }
        return $tags;
    }

    public function add_tag_info_json($pj, Contact $user) {
        if (!property_exists($this, "paperTags"))
            $this->load_tags();
        $tagger = new Tagger($user);
        $editable = $this->editable_tags($user);
        $viewable = $this->viewable_tags($user);
        $tags_view_html = $tagger->unparse_and_link($viewable, $this->paperTags, false);
        $pj->tags = TagInfo::split($viewable);
        $pj->tags_edit_text = $tagger->unparse($editable);
        $pj->tags_view_html = $tags_view_html;
        $pj->color_classes = TagInfo::color_classes($viewable);
    }

    private function load_topics() {
        $result = Dbl::qe_raw("select group_concat(topicId) from PaperTopic where paperId=$this->paperId");
        $row = edb_row($result);
        $this->topicIds = $row ? $row[0] : "";
        Dbl::free($result);
    }

    public function has_topics() {
        return count($this->topics()) > 0;
    }

    public function topics() {
        if ($this->_topics_array === null) {
            if (!property_exists($this, "topicIds"))
                $this->load_topics();
            if (is_array($this->topicIds))
                $this->_topics_array = $this->topicIds;
            else {
                $this->_topics_array = array();
                if ($this->topicIds !== "" && $this->topicIds !== null) {
                    global $Conf;
                    foreach (explode(",", $this->topicIds) as $t)
                        $this->_topics_array[] = (int) $t;
                    $tomap = $Conf->topic_order_map();
                    usort($this->_topics_array, function ($a, $b) use ($tomap) {
                        return $tomap[$a] - $tomap[$b];
                    });
                }
            }
        }
        return $this->_topics_array;
    }

    public function unparse_topics_text() {
        global $Conf;
        $tarr = $this->topics();
        if (!$tarr)
            return "";
        $out = [];
        $tmap = $Conf->topic_map();
        foreach ($tarr as $t)
            $out[] = $tmap[$t];
        return join($Conf->topic_separator(), $out);
    }

    private static function render_topic($t, $i, $tmap, &$long) {
        $s = '<span class="topic' . ($i ? : 0);
        $tname = $tmap[$t];
        if (strlen($tname) <= 50)
            $s .= ' nw';
        else
            $long = true;
        return $s . '">' . htmlspecialchars($tname) . '</span>';
    }

    private static function render_topic_list($out, $comma, $long) {
        global $Conf;
        if ($comma)
            return join($Conf->topic_separator(), $out);
        else if ($long)
            return '<p class="od">' . join('</p><p class="od">', $out) . '</p>';
        else
            return '<p class="topicp">' . join(' ', $out) . '</p>';
    }

    public function unparse_topics_html($comma, Contact $interests_user = null) {
        global $Conf;
        if (!($topics = $this->topics()))
            return "";
        $out = array();
        $tmap = $Conf->topic_map();
        $interests = $interests_user ? $interests_user->topic_interest_map() : array();
        $long = false;
        foreach ($topics as $t)
            $out[] = self::render_topic($t, get($interests, $t), $tmap, $long);
        return self::render_topic_list($out, $comma, $long);
    }

    public static function unparse_topic_list_html($topicIds, $interests, $comma) {
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
        for ($i = 0; $i < count($topicIds); $i++)
            $out[$tomap[$topicIds[$i]]] = self::render_topic($topicIds[$i], $interests ? $interests[$i] : 0, $tmap, $long);
        ksort($out);
        return self::render_topic_list($out, $comma, $long);
    }

    static public function make_topic_map($pids) {
        $result = Dbl::qe("select paperId, group_concat(topicId) as topicIds from PaperTopic where paperId ?a group by paperId", $pids);
        $topic_map = Dbl::fetch_map($result);
        foreach ($topic_map as $pid => &$t) {
            $t = explode(",", $t);
            foreach ($t as &$x)
                $x = (int) $x;
        }
        return $topic_map;
    }

    public function topic_interest_score($contact) {
        $score = 0;
        if (is_int($contact))
            $contact = get(pcMembers(), $contact);
        if ($contact) {
            if ($this->_topic_interest_score_array === null)
                $this->_topic_interest_score_array = array();
            if (isset($this->_topic_interest_score_array[$contact->contactId]))
                $score = $this->_topic_interest_score_array[$contact->contactId];
            else {
                $interests = $contact->topic_interest_map();
                foreach ($this->topics() as $t)
                    $score += (int) get($interests, $t);
                $this->_topic_interest_score_array[$contact->contactId] = $score;
            }
        }
        return $score;
    }

    public function conflicts($email = false) {
        if ($email ? !$this->_conflicts_email : !isset($this->_conflicts)) {
            $this->_conflicts = array();
            if (!$email && isset($this->allConflictType)) {
                $vals = array();
                foreach (explode(",", $this->allConflictType) as $x)
                    $vals[] = explode(" ", $x);
            } else if (!$email)
                $vals = Dbl::fetch_rows("select contactId, conflictType from PaperConflict where paperId=$this->paperId");
            else {
                $vals = Dbl::fetch_rows("select ContactInfo.contactId, conflictType, email from PaperConflict join ContactInfo using (contactId) where paperId=$this->paperId");
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

    public function named_contacts() {
        $vals = Dbl::fetch_objects("select ContactInfo.contactId, conflictType, email, firstName, lastName, affiliation from PaperConflict join ContactInfo using (contactId) where paperId=$this->paperId and conflictType>=" . CONFLICT_AUTHOR);
        foreach ($vals as $v) {
            $v->contactId = (int) $v->contactId;
            $v->conflictType = (int) $v->conflictType;
        }
        return $vals;
    }

    private function load_reviewer_preferences() {
        global $Conf;
        $this->allReviewerPreference = Dbl::fetch_value("select " . $Conf->query_all_reviewer_preference() . " from PaperReviewPreference where paperId=$this->paperId");
        $this->_prefs_array = null;
    }

    public function reviewer_preferences() {
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

    public function reviewer_preference($contact) {
        $pref = get($this->reviewer_preferences(), $contact->contactId);
        return $pref ? : [0, null];
    }

    public function options() {
        if ($this->_option_array === null)
            $this->_option_array = PaperOption::parse_paper_options($this, false);
        return $this->_option_array;
    }

    public function option($id) {
        return get($this->options(), $id);
    }

    public function all_options() {
        if ($this->_all_option_array === null)
            $this->_all_option_array = PaperOption::parse_paper_options($this, true);
        return $this->_all_option_array;
    }

    public function all_option($id) {
        return get($this->all_options(), $id);
    }

    public function invalidate_options() {
        $this->_option_array = $this->_all_option_array = null;
    }

    private function _add_documents($dids) {
        if ($this->_document_array === null)
            $this->_document_array = [];
        $result = Dbl::qe("select paperStorageId, $this->paperId paperId, timestamp, mimetype, mimetypeid, sha1, documentType, filename, infoJson, size, filterType, originalStorageId from PaperStorage where paperStorageId ?a", $dids);
        while (($di = PaperDocumentInfo::fetch($result)))
            $this->_document_array[$di->paperStorageId] = $di;
        Dbl::free($result);
    }

    public function document($dtype, $did = 0, $full = false) {
        if ($did <= 0) {
            if ($dtype == DTYPE_SUBMISSION)
                $did = $this->paperStorageId;
            else if ($dtype == DTYPE_FINAL)
                $did = $this->finalPaperStorageId;
            else if (($oa = $this->option($dtype)) && $oa->option->is_document())
                $did = $oa->value;
            else
                error_log("asked for nonexistent $dtype document, cannot find it (option " . var_export($oa, true) . ")");
        }
        if ($did <= 0)
            return null;

        if ($this->_document_array !== null && isset($this->_document_array[$did]))
            return $this->_document_array[$did];

        if ((($dtype == DTYPE_SUBMISSION && $did == $this->paperStorageId && $this->finalPaperStorageId <= 0)
             || ($dtype == DTYPE_FINAL && $did == $this->finalPaperStorageId))
            && !$full)
            return new PaperDocumentInfo(["paperStorageId" => $did, "paperId" => $this->paperId, "documentType" => $dtype, "timestamp" => get($this, "timestamp"), "mimetype" => get($this, "mimetype"), "sha1" => get($this, "sha1"), "size" => get($this, "size"), "is_partial" => true]);

        if ($this->_document_array === null) {
            $x = [];
            if ($this->paperStorageId > 0)
                $x[] = $this->paperStorageId;
            if ($this->finalPaperStorageId > 0)
                $x[] = $this->finalPaperStorageId;
            foreach ($this->options() as $oa)
                if ($oa->option->has_document_storage())
                    $x = array_merge($x, $oa->values);
            if ($did > 0)
                $x[] = $did;
            $this->_add_documents($x);
        }
        if ($did > 0 && !isset($this->_document_array[$did]))
            $this->_add_documents([$did]);
        return $did > 0 ? get($this->_document_array, $did) : null;
    }

    public function num_reviews_submitted() {
        if (!property_exists($this, "reviewCount"))
            $this->reviewCount = Dbl::fetch_ivalue("select count(*) from PaperReview where paperId=$this->paperId and reviewSubmitted>0");
        return (int) $this->reviewCount;
    }

    public function num_reviews_assigned() {
        if (!property_exists($this, "startedReviewCount"))
            $this->startedReviewCount = Dbl::fetch_ivalue("select count(*) from PaperReview where paperId=$this->paperId and (reviewSubmitted or reviewNeedsSubmit>0)");
        return (int) $this->startedReviewCount;
    }

    public function num_reviews_in_progress() {
        if (!property_exists($this, "inProgressReviewCount")) {
            if (isset($this->reviewCount) && isset($this->startedReviewCount) && $this->reviewCount === $this->startedReviewCount)
                $this->inProgressReviewCount = $this->reviewCount;
            else
                $this->inProgressReviewCount = Dbl::fetch_ivalue("select count(*) from PaperReview where paperId=$this->paperId and reviewSubmitted is null and reviewModified>0");
        }
        return (int) $this->inProgressReviewCount;
    }

    public function num_reviews_started($user) {
        if ($user->privChair || !$this->conflict_type($user))
            return $this->num_reviews_assigned();
        else
            return $this->num_reviews_in_progress();
    }

    private function load_score_array($restriction, $args) {
        $req = array();
        for ($i = 0; $i < count($args); $i += 2)
            $req[] = "group_concat(" . $args[$i] . " order by reviewId) " . $args[$i + 1];
        $result = Dbl::qe("select " . join(", ", $req) . " from PaperReview where paperId=$this->paperId and " . ($restriction ? "reviewSubmitted>0" : "true"));
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
        if (property_exists($this, $ck) && !$this->$ck)
            return array();
        if (!property_exists($this, $k) || !property_exists($this, $ck))
            $this->load_score_array($restriction, [$basek, $k, "contactId", $ck]);
        $ka = explode(",", $this->$ck);
        $va = json_decode("[" . $this->$k . "]", true);
        return count($ka) == count($va) ? array_combine($ka, $va) : false;
    }

    public function all_reviewers() {
        if (!property_exists($this, "allReviewContactIds"))
            $this->load_score_array(false, ["contactId", "allReviewContactIds"]);
        return json_decode("[" . ($this->allReviewContactIds ? : "") . "]");
    }

    public function submitted_reviewers() {
        if (!property_exists($this, "reviewContactIds"))
            $this->load_score_array(true, ["contactId", "reviewContactIds"]);
        return json_decode("[" . ($this->allReviewContactIds ? : "") . "]");
    }

    public function viewable_submitted_reviewers($contact, $forceShow) {
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

    public function all_review_ids() {
        return $this->review_cid_int_array(false, "reviewId", "allReviewIds");
    }

    public function review_ordinals() {
        return $this->review_cid_int_array(true, "reviewOrdinal", "reviewOrdinals");
    }

    public function review_ordinal($cid) {
        return get($this->review_ordinals(), $cid);
    }

    public function all_review_types() {
        return $this->review_cid_int_array(false, "reviewType", "allReviewTypes");
    }

    public function submitted_review_types() {
        return $this->review_cid_int_array(true, "reviewType", "reviewTypes");
    }

    private function _review_word_counts($restriction, $basek, $k, $count) {
        $a = $this->review_cid_int_array($restriction, $basek, $k);
        if ($a !== false || $count)
            return $a;
        $result = Dbl::qe("select * from PaperReview where reviewWordCount is null and paperId=$this->paperId");
        $rf = ReviewForm::get();
        $qs = [];
        while (($rrow = edb_orow($result)))
            $qs[] = "update PaperReview set reviewWordCount=" . $rf->word_count($rrow) . " where reviewId=" . $rrow->reviewId;
        Dbl::free($result);
        if (count($qs)) {
            $mresult = Dbl::multi_qe(join(";", $qs));
            while (($result = $mresult->next()))
                Dbl::free($result);
            unset($this->reviewWordCounts, $this->allReviewWordCounts);
        }
        return $this->_review_word_counts($restriction, $basek, $k, $count + 1);
    }

    public function submitted_review_word_counts() {
        return $this->_review_word_counts(true, "reviewWordCount", "reviewWordCounts", 0);
    }

    public function all_review_word_counts() {
        return $this->_review_word_counts(false, "reviewWordCount", "allReviewWordCounts", 0);
    }

    public function submitted_review_word_count($cid) {
        return get($this->submitted_review_word_counts(), $cid);
    }

    public function all_review_rounds() {
        return $this->review_cid_int_array(false, "reviewRound", "allReviewRounds");
    }

    public function submitted_review_rounds() {
        return $this->review_cid_int_array(true, "reviewRound", "reviewRounds");
    }

    public function submitted_review_round($cid) {
        return get($this->submitted_review_rounds());
    }

    public function review_round($cid) {
        if (!isset($this->allReviewRounds) && isset($this->reviewRounds)
            && ($x = get($this->submitted_review_rounds())) !== null)
            return $x;
        return get($this->all_review_rounds(), $cid);
    }

    public function scores($fid) {
        $fid = is_object($fid) ? $fid->id : $fid;
        return $this->review_cid_int_array(true, $fid, "{$fid}Scores");
    }

    public function score($fid, $cid) {
        return get($this->scores($fid), $cid);
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
            else if (($my_score = get($s, $contact->contactId)) !== null)
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
            if ($Conf->review_blindness() == Conf::BLIND_OPTIONAL)
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


    public function notify($notifytype, $callback, $contact) {
        global $Conf;

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
        left join PaperComment on (PaperComment.paperId=$this->paperId and PaperComment.contactId=ContactInfo.contactId)
        where watch is not null
        or conflictType>=" . CONFLICT_AUTHOR . "
        or reviewType is not null or commentId is not null
        or (defaultWatch & " . ($notifytype << WATCHSHIFT_ALL) . ")!=0";
        if ($this->managerContactId > 0)
            $q .= " or ContactInfo.contactId=" . $this->managerContactId;
        $q .= " order by conflictType";

        $result = Dbl::qe_raw($q);
        $watchers = array();
        $lastContactId = 0;
        while ($result && ($row = Contact::fetch($result))) {
            if ($row->contactId == $lastContactId
                || ($contact && $row->contactId == $contact->contactId)
                || Contact::is_anonymous_email($row->email))
                continue;
            $lastContactId = $row->contactId;

            if ($row->watch
                && ($row->watch & ($notifytype << WATCHSHIFT_EXPLICIT))) {
                if (!($row->watch & ($notifytype << WATCHSHIFT_NORMAL)))
                    continue;
            } else {
                if (!($row->defaultWatch & (($notifytype << WATCHSHIFT_NORMAL) | ($notifytype << WATCHSHIFT_ALL))))
                    continue;
            }

            $watchers[$row->contactId] = $row;
        }

        // save my current contact info map -- we are replacing it with another
        // map that lacks review token information and so forth
        $cimap = $this->replace_contact_info_map(null);

        foreach ($watchers as $minic) {
            $this->assign_contact_info($minic, $minic->contactId);
            call_user_func($callback, $this, $minic);
        }

        $this->replace_contact_info_map($cimap);
    }
}
