<?php
// commentinfo.php -- HotCRP helper class for comments
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class CommentInfo {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var PaperInfo
     * @readonly */
    public $prow;
    /** @var int */
    public $paperId = 0;
    /** @var int */
    public $commentId = 0;
    /** @var int */
    public $contactId = 0;
    /** @var int */
    public $timeModified = 0;
    /** @var int */
    public $timeNotified = 0;
    /** @var int */
    public $timeDisplayed = 0;
    /** @var ?string */
    public $comment;
    /** @var int */
    public $commentType = 0;
    /** @var int */
    public $replyTo = 0;
    /** @var int */
    public $ordinal = 0;
    /** @var int */
    public $authorOrdinal = 0;
    /** @var ?string */
    public $commentTags;
    /** @var int */
    public $commentRound = 0;
    /** @var ?int */
    public $commentFormat;
    /** @var ?string */
    public $commentOverflow;
    /** @var ?string */
    public $commentData;
    /** @var ?object */
    private $_jdata;

    /** @var ?list<NotificationInfo> */
    public $notifications;
    /** @var ?list<MessageItem> */
    public $message_list;
    /** @var ?Contact */
    private $_commenter;

    const CT_DRAFT = 0x01;
    const CT_BLIND = 0x02;
    const CT_RESPONSE = 0x04;
    const CT_BYAUTHOR = 0x08;
    const CT_BYAUTHOR_MASK = 0x0C;
    const CT_BYSHEPHERD = 0x10;
    const CT_HASDOC = 0x20;
    const CT_TOPIC_PAPER = 0x40;
    const CT_TOPIC_REVIEW = 0x80; // only used internally, not in database
    const CT_TOPIC_MASK = 0xC0;
    const CT_BYADMINISTRATOR = 0x100;
    const CT_FROZEN = 0x4000;
    const CT_SUBMIT = 0x8000; // only used internally, not in database
    const CTVIS_ADMINONLY = 0x00000;
    const CTVIS_PCONLY = 0x10000;
    const CTVIS_REVIEWER = 0x20000;
    const CTVIS_AUTHOR = 0x30000;
    const CTVIS_MASK = 0xFFF0000; // no higher bits supported
    const CT_REALBITS = 0xFFF7F7F;

    static private $visibility_map = [
        0x00000 /* CTVIS_ADMINONLY */ => "admin",
        0x10000 /* CTVIS_PCONLY */ => "pc",
        0x20000 /* CTVIS_REVIEWER */ => "rev",
        0x30000 /* CTVIS_AUTHOR */ => "au"
    ];
    /** @var array<string,int> */
    static private $visibility_revmap = [
        "admin" => 0x00000 /* CTVIS_ADMINONLY */,
        "pc" => 0x10000 /* CTVIS_PCONLY */,
        "p" => 0x10000 /* CTVIS_PCONLY */,
        "rev" => 0x20000 /* CTVIS_REVIEWER */,
        "r" => 0x20000 /* CTVIS_REVIEWER */,
        "au" => 0x30000 /* CTVIS_AUTHOR */,
        "a" => 0x30000 /* CTVIS_AUTHOR */
    ];
    /** @var array<string,int> */
    static private $topic_revmap = [
        "paper" => 64 /* CT_TOPIC_PAPER */,
        "rev" => 0
    ];


    function __construct(PaperInfo $prow = null, Conf $conf = null) {
        assert(($prow || $conf) && (!$prow || !$conf || $prow->conf === $conf));
        if ($prow) {
            $this->conf = $prow->conf;
            $this->prow = $prow;
            $this->paperId = $this->paperId ? : $prow->paperId;
        } else {
            $this->conf = $conf;
        }
    }

    private function fetch_incorporate() {
        $this->commentId = (int) $this->commentId;
        $this->paperId = (int) $this->paperId;
        $this->contactId = (int) $this->contactId;
        $this->timeModified = (int) $this->timeModified;
        $this->timeNotified = (int) $this->timeNotified;
        $this->timeDisplayed = (int) $this->timeDisplayed;
        if ($this->commentType === null) {
            $this->commentType = self::CTVIS_REVIEWER;
        } else {
            $this->commentType = (int) $this->commentType;
        }
        $this->replyTo = (int) $this->replyTo;
        $this->ordinal = (int) $this->ordinal;
        $this->authorOrdinal = (int) $this->authorOrdinal;
        $this->commentRound = (int) $this->commentRound;
        if ($this->commentFormat !== null) {
            $this->commentFormat = (int) $this->commentFormat;
        }
    }

    /** @param Dbl_Result $result
     * @return ?CommentInfo */
    static function fetch($result, PaperInfo $prow = null, Conf $conf = null) {
        $cinfo = $result->fetch_object("CommentInfo", [$prow, $conf]);
        if ($cinfo) {
            $cinfo->fetch_incorporate();
        }
        return $cinfo;
    }

    /** @return CommentInfo */
    static function make_new_template(Contact $user, PaperInfo $prow) {
        $cinfo = new CommentInfo($prow);
        if (($ct = $user->add_comment_state($prow)) !== 0) {
            $ct |= $ct & self::CT_BYAUTHOR ? self::CTVIS_AUTHOR : self::CTVIS_REVIEWER;
            if ($ct & self::CT_TOPIC_REVIEW) {
                $ct &= ~self::CT_TOPIC_PAPER;
            }
            $cinfo->commentType = $cinfo->fix_type($ct | self::CT_BLIND);
        }
        return $cinfo;
    }

    /** @param ResponseRound $rrd
     * @return CommentInfo */
    static function make_response_template($rrd, PaperInfo $prow) {
        $cinfo = new CommentInfo($prow);
        $cinfo->commentType = $cinfo->fix_type(self::CT_RESPONSE);
        $cinfo->commentRound = $rrd->id;
        return $cinfo;
    }

    /** @param int $ctype
     * @return int */
    function fix_type($ctype) {
        if (($ctype & self::CT_RESPONSE) !== 0) {
            return self::CT_RESPONSE
                | self::CTVIS_AUTHOR
                | ($this->prow->blind ? self::CT_BLIND : 0)
                | ($ctype & (self::CT_DRAFT | self::CT_SUBMIT));
        } else if (($ctype & self::CT_BYAUTHOR_MASK) !== 0) {
            return self::CT_BYAUTHOR
                | ($this->prow->blind ? self::CT_BLIND : 0)
                | ($ctype & (self::CT_TOPIC_MASK | self::CTVIS_MASK | self::CT_SUBMIT));
        } else {
            $rb = $this->conf->review_blindness();
            if ($rb === Conf::BLIND_NEVER) {
                $ctype &= ~self::CT_BLIND;
            } else if ($rb !== Conf::BLIND_OPTIONAL) {
                $ctype |= self::CT_BLIND;
            }
            return $ctype & ~(self::CT_DRAFT | self::CT_BYAUTHOR_MASK);
        }
    }

    function set_prow(PaperInfo $prow) {
        assert(!$this->prow && $this->paperId === $prow->paperId && $this->conf === $prow->conf);
        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->prow = $prow;
    }


    /** @param PaperInfo $prow
     * @return string */
    static function script($prow) {
        if (Ht::mark_stash("papercomment")) {
            $t = [];
            $crow = new CommentInfo($prow);
            $crow->commentType = self::CT_RESPONSE;
            foreach ($prow->conf->response_rounds() as $rrd) {
                $j = ["wl" => $rrd->wordlimit];
                if ($rrd->hard_wordlimit >= $rrd->wordlimit) {
                    $j["hwl"] = $rrd->hard_wordlimit;
                }
                $crow->commentRound = $rrd->id;
                if (Contact::$main_user->can_edit_response($prow, $crow)) {
                    if (($m = $rrd->instructions($prow->conf)) !== false) {
                        $j["instrux"] = $m;
                    }
                    if ($rrd->done) {
                        $j["done"] = $rrd->done;
                    }
                }
                $t[] = "hotcrp.set_response_round(" . json_encode($rrd->name) . "," . json_encode($j) . ")";
            }
            Icons::stash_licon("ui_tag");
            Icons::stash_licon("ui_attachment");
            Icons::stash_licon("ui_trash");
            return Ht::unstash_script(join(";", $t));
        } else {
            return "";
        }
    }

    /** @param PaperInfo $prow */
    static function print_script($prow) {
        echo self::script($prow);
    }


    /** @return bool */
    function is_response() {
        return ($this->commentType & self::CT_RESPONSE) !== 0;
    }

    /** @return ?ResponseRound */
    function response_round() {
        if (($this->commentType & self::CT_RESPONSE) !== 0) {
            return $this->conf->response_round_by_id($this->commentRound);
        } else {
            return null;
        }
    }

    /** @param int $ctype
     * @return bool */
    static private function commenttype_needs_ordinal($ctype) {
        return ($ctype & (self::CT_RESPONSE | self::CT_DRAFT)) === 0
            && ($ctype & self::CTVIS_MASK) !== self::CTVIS_ADMINONLY;
    }

    /** @param int $ctype
     * @return bool */
    private function ordinal_missing($ctype) {
        return self::commenttype_needs_ordinal($ctype)
            && ($ctype >= self::CTVIS_AUTHOR ? $this->authorOrdinal : $this->ordinal) === 0;
    }

    /** @return ?string */
    function unparse_ordinal() {
        if (self::commenttype_needs_ordinal($this->commentType)) {
            if ($this->commentType >= self::CTVIS_AUTHOR) {
                if ($this->authorOrdinal !== 0) {
                    return "A{$this->authorOrdinal}";
                }
            } else {
                if ($this->ordinal !== 0) {
                    return "{$this->ordinal}";
                }
            }
        }
        return null;
    }

    /** @return string */
    function unparse_html_id() {
        if (($o = $this->unparse_ordinal()) !== null) {
            return "c{$o}";
        } else if (($rrd = $this->response_round()) !== null) {
            return $rrd->unnamed ? "response" : "{$rrd->name}response";
        } else {
            return "cx" . $this->commentId;
        }
    }

    /** @return int */
    function mtime(Contact $viewer) {
        if ($viewer->can_view_comment_time($this->prow, $this)) {
            return $this->timeModified;
        } else {
            return $this->conf->obscure_time($this->timeModified);
        }
    }

    /** @return object */
    private function make_data() {
        if ($this->_jdata === null) {
            $this->_jdata = json_decode($this->commentData ?? "{}") ?? (object) [];
        }
        return $this->_jdata;
    }

    /** @param ?string $key
     * @return mixed */
    function data($key = null) {
        if ($this->commentData === null) {
            return null;
        }
        $this->make_data();
        return $key === null ? $this->_jdata : ($this->_jdata->$key ?? null);
    }

    /** @param string $key */
    function set_data($key, $value) {
        $this->make_data();
        if ($value === null) {
            unset($this->_jdata->$key);
        } else {
            $this->_jdata->$key = $value;
        }
        $s = json_encode($this->_jdata);
        $this->commentData = $s === "{}" ? null : $s;
    }

    /** @return Contact */
    function commenter() {
        if ($this->_commenter === null) {
            $this->prow && $this->prow->ensure_reviewer_names();
            $this->_commenter = $this->conf->user_by_id($this->contactId, USER_SLICE)
                ?? Contact::make_deleted($this->conf, $this->contactId);
        }
        return $this->_commenter;
    }


    /** @return ?string */
    function unparse_response_text() {
        if (($rrd = $this->response_round())) {
            $t = $rrd->unnamed ? "Response" : "{$rrd->name} Response";
            if ($this->commentType & self::CT_DRAFT) {
                $t = "Draft {$t}";
            }
            return $t;
        } else {
            return null;
        }
    }

    /** @param list<CommentInfo> $crows
     * @param bool $separateColors
     * @return list<array{CommentInfo,int,string}> */
    static function group_by_identity($crows, Contact $viewer, $separateColors) {
        $known_cids = [];
        '@phan-var array<int,int> $known_cids';
        $result = [];
        '@phan-var list<array{CommentInfo,int,string}> $result';
        foreach ($crows as $cr) {
            $cid = 0;
            if ($viewer->can_view_comment_identity($cr->prow, $cr)
                || $cr->unparse_commenter_pseudonym($viewer)) {
                $cid = $cr->contactId;
            }
            if ($cr->commentType & self::CT_RESPONSE) {
                if (!empty($result)) {
                    $result[count($result) - 1][2] = ";";
                }
                $connector = ";";
                $known_cids = [];
                $include = true;
                $record = false;
            } else {
                $connector = ",";
                $include = !isset($known_cids[$cid]);
                $record = true;
            }
            if ($separateColors
                && ($tags = $cr->viewable_tags($viewer))
                && $cr->conf->tags()->color_classes($tags)) {
                $include = true;
                $record = false;
            }
            if ($include) {
                $result[] = [$cr, 1, $connector];
                if ($record) {
                    $known_cids[$cid] = count($result) - 1;
                }
            } else {
                ++$result[$known_cids[$cid]][1];
            }
        }
        if (!empty($result)) {
            $result[count($result) - 1][2] = "";
        }
        return $result;
    }

    /** @return ?string */
    private function unparse_commenter_pseudonym(Contact $viewer) {
        if (($this->commentType & self::CT_BYAUTHOR_MASK) !== 0) {
            return "Author";
        } else if (($this->commentType & (self::CTVIS_MASK | self::CT_BYSHEPHERD)) === (self::CTVIS_AUTHOR | self::CT_BYSHEPHERD)) {
            return "Shepherd";
        } else if (($rrow = $this->prow->review_by_user($this->contactId))
                   && $rrow->reviewOrdinal
                   && $viewer->can_view_review_assignment($this->prow, $rrow)) {
            return "Reviewer " . unparse_latin_ordinal($rrow->reviewOrdinal);
        } else if (($this->commentType & self::CT_BYSHEPHERD) !== 0) {
            return "Shepherd";
        } else if (($this->commentType & self::CT_BYADMINISTRATOR) !== 0) {
            return "Administrator";
        } else {
            return null;
        }
    }

    /** @return bool */
    private function commenter_may_be_pseudonymous() {
        if (($this->commentType & self::CTVIS_MASK) < self::CTVIS_PCONLY) {
            return false;
        } else if (($this->commentType & self::CT_BYAUTHOR_MASK) !== 0) {
            return $this->prow->blindness_state(true) > 0;
        } else if (($this->commentType & self::CTVIS_MASK) === self::CTVIS_AUTHOR) {
            return !$this->prow->author_user()->can_view_comment_identity($this->prow, $this);
        } else {
            return false;
        }
    }

    /** @return string */
    function unparse_commenter_html(Contact $viewer) {
        if ($viewer->can_view_comment_identity($this->prow, $this)) {
            $n = Text::nameo_h($this->commenter(), NAME_P|NAME_I);
        } else {
            $n = $this->unparse_commenter_pseudonym($viewer) ?? "anonymous";
        }
        if (($this->commentType & self::CT_RESPONSE) !== 0) {
            $n = "<i>" . $this->unparse_response_text() . "</i>"
                . ($n === "Author" ? "" : " ({$n})");
        }
        return $n;
    }

    /** @return string */
    function unparse_commenter_text(Contact $viewer) {
        if ($viewer->can_view_comment_identity($this->prow, $this)) {
            $n = Text::nameo($this->commenter(), NAME_P|NAME_I);
        } else {
            $n = $this->unparse_commenter_pseudonym($viewer) ?? "anonymous";
        }
        if (($this->commentType & self::CT_RESPONSE) !== 0) {
            $n = $this->unparse_response_text()
                . ($n === "Author" ? "" : " ({$n})");
        }
        return $n;
    }

    /** @return string */
    function all_tags_text() {
        $t = $this->commentTags ?? "";
        if ($this->commentRound !== 0) {
            if (($rrd = $this->conf->response_round_by_id($this->commentRound))) {
                $rname = $rrd->tag_name();
                $t = "{$t} response#0 {$rname}#0";
            } else {
                $t = "{$t} response#0";
            }
        }
        return $t;
    }

    /** @return ?string */
    function searchable_tags(Contact $viewer) {
        if (($tags = $this->all_tags_text()) !== ""
            && $viewer->can_view_comment_tags($this->prow, $this)) {
            return $this->conf->tags()->censor(TagMap::CENSOR_SEARCH, $tags, $viewer, $this->prow);
        } else {
            return null;
        }
    }

    /** @return ?string */
    function viewable_tags(Contact $viewer) {
        if (($tags = $this->all_tags_text()) !== ""
            && $viewer->can_view_comment_tags($this->prow, $this)) {
            return $this->conf->tags()->censor(TagMap::CENSOR_VIEW, $tags, $viewer, $this->prow);
        } else {
            return null;
        }
    }

    /** @return ?string */
    function viewable_nonresponse_tags(Contact $viewer) {
        if ($this->commentTags
            && $viewer->can_view_comment_tags($this->prow, $this)) {
            return $this->conf->tags()->censor(TagMap::CENSOR_VIEW, $this->commentTags, $viewer, $this->prow);
        } else {
            return null;
        }
    }

    /** @param string $tag
     * @return bool */
    function has_tag($tag) {
        return ($tags = $this->all_tags_text()) !== ""
            && stripos($tags, " {$tag}#") !== false;
    }

    /** @return bool */
    function has_attachments() {
        return ($this->commentType & self::CT_HASDOC) !== 0;
    }

    /** @return DocumentInfoSet */
    function attachments() {
        if (($this->commentType & self::CT_HASDOC) !== 0) {
            return $this->prow->linked_documents($this->commentId, DocumentInfo::LINKTYPE_COMMENT_BEGIN, DocumentInfo::LINKTYPE_COMMENT_END, $this);
        } else {
            return new DocumentInfoSet;
        }
    }

    /** @return list<int> */
    function attachment_ids() {
        return $this->attachments()->document_ids();
    }

    /** @param ?Contact $viewer
     * @param bool $censor_mentions
     * @param ?int $censor_mentions_after
     * @return string */
    function contents($viewer = null, $censor_mentions = false, $censor_mentions_after = null) {
        $t = $this->commentOverflow ?? $this->comment ?? "";
        if ($t === ""
            || !$censor_mentions
            || !($mx = $this->data("mentions"))
            || !is_array($mx)) {
            return $t;
        }
        $delta = 0;
        foreach ($mx as $m) {
            if (is_array($m)
                && count($m) >= 4
                && $m[3]
                && (!$viewer || $m[0] !== $viewer->contactId)
                && ($censor_mentions_after === null || $m[1] + $delta < $censor_mentions_after)) {
                $r = $this->prow->unparse_pseudonym($viewer, $m[0]) ?? "Anonymous";
                $t = substr_replace($t, "@{$r}", $m[1] + $delta, $m[2] - $m[1]);
                $delta += strlen($r) - ($m[2] - $m[1] - 1);
            }
        }
        return $t;
    }

    /** @param bool $editable
     * @return list<object> */
    function attachments_json($editable = false) {
        $docs = [];
        foreach ($this->attachments() as $doc) {
            $docj = $doc->unparse_json();
            if ($editable) {
                $docj->docid = $doc->paperStorageId;
            }
            $docs[] = $docj;
        }
        return $docs;
    }

    /** @return ?object */
    function unparse_json(Contact $viewer) {
        if ($this->commentId !== 0
            ? !$viewer->can_view_comment($this->prow, $this, true)
            : !$viewer->can_edit_comment($this->prow, $this)) {
            return null;
        }

        $rrd = $this->response_round();
        assert(!$rrd === !($this->commentType & self::CT_RESPONSE));

        if ($this->commentId !== 0) {
            $cj = (object) [
                "pid" => $this->prow->paperId,
                "cid" => $this->commentId,
                "ordinal" => $this->unparse_ordinal(),
                "visibility" => self::$visibility_map[$this->commentType & self::CTVIS_MASK]
            ];
        } else {
            // placeholder for new comment
            $cj = (object) [
                "pid" => $this->prow->paperId,
                "is_new" => true,
                "editable" => true
            ];
            if ($rrd
                && ($this->prow->timeSubmitted <= 0
                    || !$rrd->can_author_respond($this->prow, true))) {
                $cj->author_editable = false;
            }
        }

        // blindness, draftness, authorness, format
        if (($this->commentType & self::CT_BLIND) !== 0) {
            $cj->blind = true;
        }
        if (($this->commentType & self::CT_DRAFT) !== 0) {
            $cj->draft = true;
            if (!$this->prow->has_author($viewer)) {
                $cj->folded = true;
            }
        }
        if (($this->commentType & self::CT_RESPONSE) !== 0) {
            $cj->response = $rrd->name;
        } else if (($this->commentType & self::CT_BYAUTHOR_MASK) !== 0) {
            $cj->by_author = true;
        } else if (($this->commentType & self::CT_BYSHEPHERD) !== 0) {
            $cj->by_shepherd = true;
        }
        if (($this->commentType & (self::CT_TOPIC_REVIEW | self::CT_TOPIC_PAPER))
            === self::CT_TOPIC_PAPER) {
            $cj->topic = "paper";
        }
        if (($fmt = $this->commentFormat ?? $this->conf->default_format)) {
            $cj->format = $fmt;
        }

        // exit now if new-comment skeleton
        if ($this->commentId === 0) {
            if (($token = $viewer->active_review_token_for($this->prow))) {
                $cj->review_token = encode_token($token);
            }
            return $cj;
        }

        // otherwise, viewable comment
        if ($viewer->can_edit_comment($this->prow, $this)) {
            $cj->editable = true;
        }

        // tags
        if (($tags = $this->viewable_tags($viewer))) {
            $cj->tags = Tagger::split($tags);
            if (($cc = $this->conf->tags()->color_classes($tags))) {
                $cj->color_classes = $cc;
            }
        }

        // identity and time
        $idable = $viewer->can_view_comment_identity($this->prow, $this);
        $idable_override = $idable
            || ($viewer->allow_administer($this->prow)
                && $viewer->call_with_overrides(Contact::OVERRIDE_CONFLICT, "can_view_comment_identity", $this->prow, $this));
        if ($idable || $idable_override) {
            $cuser = $this->commenter();
            if (($this->commentType & self::CT_BYAUTHOR_MASK) === 0
                && $viewer->can_view_user_tags()) {
                $cj->author = $viewer->reviewer_html_for($cuser);
            } else {
                $cj->author = Text::nameo_h($cuser, NAME_P);
            }
            if (!$cuser->is_anonymous_user()) {
                $cj->author_email = $cuser->email;
            } else if ($viewer->review_tokens()
                       && ($rrows = $this->prow->reviews_by_user(-1, $viewer->review_tokens()))) {
                $cj->review_token = encode_token($rrows[0]->reviewToken);
            }
            if (!$idable) {
                $cj->author_hidden = true;
            }
        }
        if (($p = $this->unparse_commenter_pseudonym($viewer))) {
            $cj->author_pseudonym = $p;
        }
        if ($idable
            && $this->commenter_may_be_pseudonymous()) {
            $cj->author_pseudonymous = true;
        }
        if ($this->timeModified > 0) {
            if ($idable_override) {
                $cj->modified_at = $this->timeModified;
            } else {
                $cj->modified_at = $this->conf->obscure_time($this->timeModified);
                $cj->modified_at_obscured = true;
            }
            $cj->modified_at_text = $this->conf->unparse_time_point($cj->modified_at);
        }

        // contents
        if ($viewer->can_view_comment_contents($this->prow, $this)) {
            $cj->text = $this->contents($viewer, !$idable);
            if ($this->has_attachments()) {
                $cj->docs = $this->attachments_json($cj->editable ?? false);
            }
        } else {
            $cj->text = false;
            $cj->word_count = count_words($this->commentOverflow ?? $this->comment);
        }

        return $cj;
    }

    /** @param int $flags
     * @return string */
    function unparse_text(Contact $viewer, $flags = 0) {
        if (($rrd = $this->response_round())) {
            $x = $rrd->unnamed ? "Response" : "{$rrd->name} Response";
        } else {
            $ordinal = $this->unparse_ordinal();
            $x = "Comment" . ($ordinal ? " @{$ordinal}" : "");
        }
        $p = $this->unparse_commenter_pseudonym($viewer);
        $idable = $viewer->can_view_comment_identity($this->prow, $this);
        if ($idable) {
            $n = Text::nameo($this->commenter(), NAME_EB);
            $np = $this->commenter_may_be_pseudonymous();
            if ($p && $np) {
                $x .= " by {$p} [{$n}]";
            } else if ($p) {
                $x .= " by {$n} ({$p})";
            } else if ($np) {
                $x .= " [by {$n}]";
            } else {
                $x .= " by {$n}";
            }
        } else if ($p && ($p !== "Author" || ($this->commentType & self::CT_RESPONSE) !== 0)) {
            $x .= " by {$p}";
        }
        $ctext = $this->contents($viewer, !$idable);
        if ($rrd && $rrd->wordlimit > 0) {
            $nwords = count_words($ctext);
            $x .= " (" . plural($nwords, "word") . ")";
            if ($nwords > $rrd->wordlimit
                && ($flags & ReviewForm::UNPARSE_TRUNCATE) !== 0) {
                list($ctext, $overflow) = count_words_split($ctext, $rrd->wordlimit);
                if ($rrd->wordlimit === $rrd->hard_wordlimit) {
                    $ctext = rtrim($ctext) . "…\n- - - - - - - - - - - - - - Truncated for length - - - - - - - - - - - - - -\n";
                } else {
                    $ctext = rtrim($ctext) . "…\n- - - - - - - Truncated for length, more available on website - - - - - - -\n";
                }
            }
        }
        $x .= "\n" . str_repeat("-", 75) . "\n";
        $flowed = ($flags & ReviewForm::UNPARSE_FLOWED) !== 0;
        if (($flags & ReviewForm::UNPARSE_NO_TITLE) === 0) {
            $prow = $this->prow;
            $x .= prefix_word_wrap("* ", "Paper: #{$prow->paperId} {$prow->title}", 2, null, $flowed);
        }
        if (($tags = $this->viewable_nonresponse_tags($viewer))) {
            $tagger = new Tagger($viewer);
            $x .= prefix_word_wrap("* ", $tagger->unparse_hashed($tags), 2, null, $flowed);
        }
        if (($flags & ReviewForm::UNPARSE_NO_TITLE) === 0 || $tags) {
            $x .= "\n";
        }
        return rtrim($x . $ctext) . "\n";
    }

    /** @return string */
    function unparse_flow_entry(Contact $viewer) {
        // See also ReviewForm::reviewFlowEntry
        $a = '<a href="' . $this->conf->hoturl("paper", "p=$this->paperId#" . $this->unparse_html_id()) . '"';
        $t = '<tr class="pl"><td class="pl_eventicon">' . $a . ">"
            . Ht::img("comment48.png", "[Comment]", ["class" => "dlimg", "width" => 24, "height" => 24])
            . '</a></td><td class="pl_eventid pl_rowclick">'
            . $a . ' class="pnum">#' . $this->paperId . '</a></td>'
            . '<td class="pl_eventdesc pl_rowclick"><small>'
            . $a . ' class="ptitle">'
            . htmlspecialchars(UnicodeHelper::utf8_abbreviate($this->prow->title, 80))
            . "</a>";
        $idable = $viewer->can_view_comment_identity($this->prow, $this);
        if ($idable || $viewer->can_view_comment_time($this->prow, $this)) {
            $time = $this->conf->unparse_time($this->timeModified);
        } else {
            $time = $this->conf->unparse_time_obscure($this->conf->obscure_time($this->timeModified));
        }
        $t .= ' <span class="barsep">·</span> ' . $time;
        if ($idable) {
            $t .= ' <span class="barsep">·</span> <span class="hint">comment by</span> ' . $viewer->reviewer_html_for($this->contactId);
        }
        return $t . "</small><br>"
            . htmlspecialchars(UnicodeHelper::utf8_abbreviate($this->contents($viewer, !$idable, 300), 300))
            . "</td></tr>";
    }


    private function save_ordinal($cmtid, $ctype) {
        $okey = $ctype >= self::CTVIS_AUTHOR ? "authorOrdinal" : "ordinal";
        $q = "update PaperComment, (select coalesce(max(PaperComment.{$okey}),0) maxOrdinal
    from Paper
    left join PaperComment on (PaperComment.paperId=Paper.paperId)
    where Paper.paperId={$this->prow->paperId}
    group by Paper.paperId) t
set {$okey}=(t.maxOrdinal+1) where commentId={$cmtid}";
        $this->conf->qe($q);
    }

    /** @param array $req
     * @return int */
    function requested_type($req) {
        $ctype = $this->commentType;
        if (($ctype & self::CT_RESPONSE) !== 0) {
            if ($req["submit"] ?? null) {
                $ctype &= ~self::CT_DRAFT;
            } else {
                $ctype |= self::CT_DRAFT;
            }
        }
        if ($req["blind"] ?? null) {
            $ctype |= self::CT_BLIND;
        } else {
            $ctype &= ~self::CT_BLIND;
        }
        if (($x = self::$topic_revmap[$req["topic"] ?? ""] ?? null) !== null) {
            $ctype = ($ctype & ~self::CT_TOPIC_MASK) | $x;
        }
        if (($x = self::$visibility_revmap[$req["visibility"] ?? ""] ?? null) !== null) {
            $ctype = ($ctype & ~self::CTVIS_MASK) | $x;
        }
        return $this->fix_type($ctype);
    }

    /** @param array{docs?:list<DocumentInfo>,tags?:?string} $req
     * @return bool */
    function save_comment($req, Contact $acting_user) {
        $this->notifications = [];

        $user = $acting_user;
        if (!$user->contactId) {
            $user = $acting_user->reviewer_capability_user($this->prow->paperId);
        }
        if (!$user || !$user->contactId) {
            error_log("Comment::save({$this->prow->paperId}): no such user");
            return false;
        }

        $ctype = $this->requested_type($req) & self::CT_REALBITS;
        $is_response = ($ctype & self::CT_RESPONSE) !== 0;
        $response_name = $is_response ? $this->response_round()->name : null;

        // tags
        $expected_tags = $this->commentTags;
        if (!$is_response
            && ($req["tags"] ?? "")
            && preg_match_all('/\S+/', $req["tags"] ?? "", $m)
            && !$user->act_author_view($this->prow)) {
            $tagger = new Tagger($user);
            $ts = [];
            foreach ($m[0] as $tt) {
                if (($tt = $tagger->check($tt))
                    && !stri_ends_with($tt, "response")) {
                    list($tag, $value) = Tagger::unpack($tt);
                    $ts[strtolower($tag)] = $tag . "#" . (float) $value;
                }
            }
            if (!empty($ts)) {
                $ts = array_values($ts);
                $ctags = " " . join(" ", $this->conf->tags()->sort_array($ts));
            } else {
                $ctags = null;
            }
        } else {
            $ctags = null;
        }

        // attachments
        $old_docids = $this->attachment_ids();
        $docs = $req["docs"] ?? [];
        $ctype = $docs ? ($ctype | self::CT_HASDOC) : ($ctype & ~self::CT_HASDOC);

        // notifications
        $displayed = ($ctype & self::CT_DRAFT) === 0;

        // text
        $text = $req["text"] ?? null;
        if ($text !== false) {
            $text = (string) $text;
        }

        // query
        $q = "";
        $qv = [];
        if ($text === false) {
            if ($this->commentId) {
                $q = "delete from PaperComment where commentId={$this->commentId}";
            }
            $docs = [];
        } else if (!$this->commentId) {
            $qa = ["contactId, paperId, commentType, comment, commentOverflow, timeModified, replyTo"];
            $qb = [$user->contactId, $this->prow->paperId, $ctype, "?", "?", Conf::$now, 0];
            if (strlen($text) <= 32000) {
                array_push($qv, $text, null);
            } else {
                array_push($qv, UnicodeHelper::utf8_prefix($text, 200), $text);
            }
            if ($ctags !== null) {
                $qa[] = "commentTags";
                $qb[] = "?";
                $qv[] = $ctags;
            }
            if ($this->commentData !== null) {
                $qa[] = "commentData";
                $qb[] = "?";
                $qv[] = $this->commentData;
            }
            if ($is_response) {
                $qa[] = "commentRound";
                $qb[] = $this->commentRound;
            }
            if ($displayed) {
                $qa[] = "timeDisplayed, timeNotified";
                $qb[] = Conf::$now . ", " . Conf::$now;
            }
            $q = "insert into PaperComment (" . join(", ", $qa) . ") select " . join(", ", $qb) . "\n";
            if ($is_response) {
                // make sure there is exactly one response
                $q .= "from dual where not exists (select * from PaperComment where paperId={$this->prow->paperId} and (commentType&" . self::CT_RESPONSE . ")!=0 and commentRound={$this->commentRound})";
            }
        } else {
            if ($this->timeModified >= Conf::$now) {
                Conf::advance_current_time($this->timeModified);
            }
            // do not notify on updates within 3 hours
            $qa = "";
            if ($this->timeNotified + 10800 < Conf::$now
                || (($ctype & self::CT_RESPONSE) !== 0
                    && ($ctype & self::CT_DRAFT) === 0
                    && ($this->commentType & self::CT_DRAFT) !== 0)) {
                $qa .= ", timeNotified=" . Conf::$now;
            }
            // reset timeDisplayed if you change the comment type
            if ((!$this->timeDisplayed || $this->ordinal_missing($ctype))
                && ($text !== "" || $docs)
                && $displayed) {
                $qa .= ", timeDisplayed=" . Conf::$now;
            }
            $q = "update PaperComment set timeModified=" . Conf::$now . $qa . ", commentType={$ctype}, comment=?, commentOverflow=?, commentTags=?, commentData=? where commentId={$this->commentId}";
            if (strlen($text) <= 32000) {
                array_push($qv, $text, null);
            } else {
                array_push($qv, UnicodeHelper::utf8_prefix($text, 200), $text);
            }
            $qv[] = $ctags;
            $qv[] = $this->commentData;
        }

        $result = $this->conf->qe_apply($q, $qv);
        if (Dbl::is_error($result)) {
            return false;
        }

        $cmtid = $this->commentId ? : $result->insert_id;
        if (!$cmtid) {
            return false;
        }

        // check for document change
        $docs_differ = count($docs) !== count($old_docids);
        for ($i = 0; !$docs_differ && $i !== count($docs); ++$i) {
            $docs_differ = $docs[$i]->paperStorageId !== $old_docids[$i];
        }

        // log
        if ($is_response) {
            $x = $response_name == "1" ? "" : " ({$response_name})";
            $log = "Response {$cmtid}{$x}";
        } else {
            $x = ($ctype & self::CT_TOPIC_PAPER) !== 0 ? " on submission" : "";
            $log = "Comment {$cmtid}{$x}";
        }
        if ($text === false) {
            $log .= " deleted";
        } else {
            if (($ctype & self::CT_DRAFT) === 0
                && (!$this->commentId || ($this->commentType & self::CT_DRAFT) !== 0)) {
                $log .= " submitted";
            } else {
                $log .= $this->commentId ? " edited" : " started";
                if (($ctype & self::CT_DRAFT) === 0) {
                    $log .= " draft";
                }
            }
            $ch = [];
            if ($this->commentId
                && $text !== ($this->commentOverflow ?? $this->comment)) {
                $ch[] = "text";
            }
            if ($this->commentId
                && ($ctype | self::CT_DRAFT) !== ($this->commentType | self::CT_DRAFT)) {
                $ch[] = "visibility";
            }
            if ($ctags !== $expected_tags) {
                $ch[] = "tags";
            }
            if ($docs_differ) {
                $ch[] = "attachments";
            }
            if (!empty($ch)) {
                $log .= ": " . join(", ", $ch);
            }
        }
        $acting_user->log_activity_for($this->contactId ? : $user->contactId, $log, $this->prow->paperId);

        // update automatic tags
        $this->conf->update_automatic_tags($this->prow, "comment");

        // ordinal
        if ($text !== false && $this->ordinal_missing($ctype)) {
            $this->save_ordinal($cmtid, $ctype);
        }

        // reload contents
        if ($text !== false) {
            if (($cobject = $this->conf->fetch_first_object("select * from PaperComment where paperId={$this->prow->paperId} and commentId={$cmtid}"))) {
                foreach (get_object_vars($cobject) as $k => $v) {
                    $this->$k = $v;
                }
                $this->fetch_incorporate();
            } else {
                return false;
            }
        }

        // document links
        if ($docs_differ) {
            if ($old_docids) {
                $this->conf->qe("delete from DocumentLink where paperId=? and linkId=? and linkType>=? and linkType<?", $this->prow->paperId, $this->commentId, DocumentInfo::LINKTYPE_COMMENT_BEGIN, DocumentInfo::LINKTYPE_COMMENT_END);
            }
            $need_mark = !!$old_docids;
            if ($docs) {
                $qv = [];
                foreach ($docs as $i => $doc) {
                    $qv[] = [$this->prow->paperId, $this->commentId, $i, $doc->paperStorageId];
                    // just in case we're linking an inactive document
                    // (this should never happen, though, because upload
                    // marks as active explicitly)
                    $need_mark = $need_mark || $doc->inactive;
                }
                $this->conf->qe("insert into DocumentLink (paperId,linkId,linkType,documentId) values ?v", $qv);
            }
            if ($need_mark) {
                $this->prow->mark_inactive_linked_documents();
            }
            $this->prow->invalidate_linked_documents();
        }

        // delete if appropriate
        if ($text === false) {
            $this->commentId = 0;
            $this->comment = "";
            $this->commentTags = $this->commentData = $this->_jdata = null;
            return true;
        }

        // notify mentions and followers
        if ($displayed
            && $this->commentId
            && ($this->commentType & self::CTVIS_MASK) > self::CTVIS_ADMINONLY
            && strpos($text, "@") !== false) {
            $this->analyze_mentions($user);
        }

        if ($this->timeNotified === $this->timeModified) {
            $this->notify($user);
        }

        return true;
    }

    /** @param Contact $user
     * @param 1|2|4 $types
     * @return NotificationInfo */
    private function notification($user, $types) {
        foreach ($this->notifications as $n) {
            if ($n->user->contactId === $user->contactId) {
                $n->types |= $types;
                return $n;
            }
        }
        $this->notifications[] = $n = new NotificationInfo($user, $types);
        return $n;
    }

    /** @param Contact $user */
    private function analyze_mentions($user) {
        // enumerate desired mentions and save them
        $desired_mentions = [];
        $text = $this->commentOverflow ?? $this->comment;
        foreach (MentionParser::parse($text, ...Completion_API::mention_lists($user, $this->prow, $this->commentType & self::CTVIS_MASK, Completion_API::MENTION_PARSE)) as $mpx) {
            $named = $mpx[0] instanceof Contact || $mpx[0]->status !== Author::STATUS_ANONYMOUS_REVIEWER;
            $desired_mentions[] = [$mpx[0]->contactId, $mpx[1], $mpx[2], $named];
            $this->conf->prefetch_user_by_id($mpx[0]->contactId);
        }

        $old_data = $this->commentData;
        $this->set_data("mentions", empty($desired_mentions) ? null : $desired_mentions);
        if ($this->commentData !== $old_data) {
            $this->conf->qe("update PaperComment set commentData=? where paperId=? and commentId=?", $this->commentData, $this->paperId, $this->commentId);
        }

        // go over mentions, send email
        foreach ($desired_mentions as $mxm) {
            $mentionee = $this->conf->user_by_id($mxm[0], USER_SLICE);
            if (!$mentionee) {
                continue;
            }
            $notification = $this->notification($mentionee, NotificationInfo::MENTION);
            if ($notification->sent
                || $mentionee->is_dormant()
                || !$mentionee->can_view_comment($this->prow, $this)) {
                continue;
            }
            HotCRPMailer::send_to($mentionee, "@mentionnotify", [
                "prow" => $this->prow,
                "comment_row" => $this
            ]);
            if (!$mxm[3]) {
                $notification->user_html = htmlspecialchars(substr($text, $mxm[1] + 1, $mxm[2] - $mxm[1] - 1));
            }
        }
    }

    /** @return list<Contact> */
    function followers() {
        $ctype = $this->commentType;
        $nocheck = false;
        if (($ctype & self::CT_DRAFT) !== 0) {
            $cids = [];
            if (($ctype & self::CT_BYAUTHOR_MASK) !== 0) {
                foreach ($this->prow->contact_list() as $u) {
                    $cids[] = $u->contactId;
                }
            } else {
                $cids[] = $this->contactId;
            }
            $us = $this->prow->generic_followers($cids, "false");
        } else if (($ctype & self::CTVIS_MASK) === self::CTVIS_ADMINONLY) {
            $us = $this->prow->administrators();
            $nocheck = true;
        } else {
            $us = $this->prow->review_followers($ctype);
        }
        for ($i = 0; $i !== count($us); ) {
            if ($us[$i]->can_view_comment($this->prow, $this)
                && ($nocheck || $us[$i]->following_reviews($this->prow, $ctype))) {
                ++$i;
            } else {
                array_splice($us, $i, 1);
            }
        }
        return $us;
    }

    /** @param ?Contact $user */
    function notify($user) {
        $ctype = $this->commentType;
        $is_response = $this->is_response();
        if ($is_response && ($ctype & self::CT_DRAFT) !== 0) {
            $tmpl = "@responsedraftnotify";
        } else if ($is_response) {
            $tmpl = "@responsenotify";
        } else if (($ctype & self::CTVIS_MASK) === self::CTVIS_ADMINONLY) {
            $tmpl = "@admincommentnotify";
        } else {
            $tmpl = "@commentnotify";
        }
        $info = [
            "prow" => $this->prow,
            "comment_row" => $this,
            "combination_type" => 1
        ];
        $preps = [];
        foreach ($this->followers() as $minic) {
            if ($user && $minic->contactId === $user->contactId) {
                continue;
            }
            $is_author = $this->prow->has_author($minic);
            $notification = $this->notification($minic, $is_author ? NotificationInfo::CONTACT : NotificationInfo::FOLLOW);
            if ($notification->sent) {
                continue;
            }
            // prepare mail
            $p = HotCRPMailer::prepare_to($minic, $tmpl, $info);
            if (!$p) {
                continue;
            }
            // Don't combine preparations unless you can see all submitted
            // reviewer identities
            // XXX maybe should not combine preparations at all?
            if (!$this->prow->has_author($minic)
                && !$minic->can_view_review_identity($this->prow, null)) {
                $p->unique_preparation = true;
            }
            $preps[] = $p;
            $notification->sent = true;
        }
        HotCRPMailer::send_combined_preparations($preps);
    }
}
