<?php
// commentinfo.php -- HotCRP helper class for comments
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class CommentInfo {
    public $conf;
    public $prow;
    public $commentId = 0;
    public $paperId;
    public $contactId;
    public $timeModified;
    public $timeNotified;
    public $timeDisplayed;
    public $comment;
    public $commentType = COMMENTTYPE_REVIEWER;
    public $replyTo;
    public $ordinal;
    public $authorOrdinal;
    public $commentTags;
    public $commentRound;
    public $commentFormat;
    public $commentOverflow;

    static private $visibility_map = [
        COMMENTTYPE_ADMINONLY => "admin", COMMENTTYPE_PCONLY => "pc",
        COMMENTTYPE_REVIEWER => "rev", COMMENTTYPE_AUTHOR => "au"
    ];
    static private $visibility_revmap = [
        "admin" => COMMENTTYPE_ADMINONLY, "pc" => COMMENTTYPE_PCONLY,
        "p" => COMMENTTYPE_PCONLY, "rev" => COMMENTTYPE_REVIEWER,
        "r" => COMMENTTYPE_REVIEWER, "au" => COMMENTTYPE_AUTHOR,
        "a" => COMMENTTYPE_AUTHOR
    ];


    function __construct($x, PaperInfo $prow = null, Conf $conf = null) {
        $this->merge(is_object($x) ? $x : null, $prow, $conf);
    }

    private function merge($x, PaperInfo $prow = null, Conf $conf = null) {
        assert(($prow || $conf) && (!$prow || !$conf || $prow->conf === $conf));
        $this->conf = $prow ? $prow->conf : $conf;
        $this->prow = $prow;
        if ($x) {
            foreach ($x as $k => $v)
                $this->$k = $v;
        }
        $this->commentId = (int) $this->commentId;
        $this->paperId = (int) $this->paperId;
        $this->commentType = (int) $this->commentType;
        $this->commentRound = (int) $this->commentRound;
    }

    static function fetch($result, PaperInfo $prow = null, Conf $conf = null) {
        $cinfo = null;
        if ($result) {
            $cinfo = $result->fetch_object("CommentInfo", [null, $prow, $conf]);
        }
        if ($cinfo && !is_int($cinfo->commentId)) {
            $cinfo->merge(null, $prow, $conf);
        }
        return $cinfo;
    }

    static function make_response_template($round, PaperInfo $prow) {
        return new CommentInfo((object) ["commentType" => COMMENTTYPE_RESPONSE, "commentRound" => $round], $prow);
    }

    function set_prow(PaperInfo $prow) {
        assert(!$this->prow && $this->paperId === $prow->paperId && $this->conf === $prow->conf);
        $this->prow = $prow;
    }


    static function echo_script($prow) {
        global $Me;
        if (Ht::mark_stash("papercomment")) {
            $t = [];
            $crow = new CommentInfo(null, $prow, $prow->conf);
            $crow->commentType = COMMENTTYPE_RESPONSE;
            foreach ($prow->conf->resp_rounds() as $rrd) {
                $j = ["words" => $rrd->words];
                $crow->commentRound = $rrd->number;
                if ($Me->can_respond($prow, $crow)) {
                    if (($m = $rrd->instructions($prow->conf)) !== false) {
                        $j["instrux"] = $m;
                    }
                    if ($rrd->done) {
                        $j["done"] = $rrd->done;
                    }
                }
                $t[] = "papercomment.set_resp_round(" . json_encode($rrd->name) . "," . json_encode($j) . ")";
            }
            echo Ht::unstash_script(join(";", $t));
            Icons::stash_licon("ui_tag");
            Icons::stash_licon("ui_attachment");
            Icons::stash_licon("ui_trash");
        }
    }


    function is_response() {
        return ($this->commentType & COMMENTTYPE_RESPONSE) !== 0;
    }

    static private function commenttype_needs_ordinal($ctype) {
        return !($ctype & (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT))
            && ($ctype & COMMENTTYPE_VISIBILITY) != COMMENTTYPE_ADMINONLY;
    }

    private function ordinal_missing($ctype) {
        return self::commenttype_needs_ordinal($ctype)
            && !($ctype >= COMMENTTYPE_AUTHOR ? $this->authorOrdinal : $this->ordinal);
    }

    function unparse_ordinal() {
        $is_author = $this->commentType >= COMMENTTYPE_AUTHOR;
        $o = $is_author ? $this->authorOrdinal : $this->ordinal;
        if (self::commenttype_needs_ordinal($this->commentType) && $o) {
            return ($is_author ? "A" : "") . $o;
        } else {
            return null;
        }
    }

    function unparse_html_id() {
        $is_author = $this->commentType >= COMMENTTYPE_AUTHOR;
        $o = $is_author ? $this->authorOrdinal : $this->ordinal;
        if (self::commenttype_needs_ordinal($this->commentType) && $o) {
            return ($is_author ? "cA" : "c") . $o;
        } else if ($this->commentType & COMMENTTYPE_RESPONSE) {
            return $this->conf->resp_round_text($this->commentRound) . "response";
        } else {
            return "cx" . $this->commentId;
        }
    }

    private function commenter() {
        if (isset($this->reviewEmail)) {
            return (object) ["firstName" => get($this, "reviewFirstName"), "lastName" => get($this, "reviewLastName"), "email" => $this->reviewEmail];
        } else {
            return $this;
        }
    }

    function unparse_response_text() {
        if ($this->commentType & COMMENTTYPE_RESPONSE) {
            $rname = $this->conf->resp_round_name($this->commentRound);
            $t = $rname == "1" ? "Response" : "$rname Response";
            if ($this->commentType & COMMENTTYPE_DRAFT)
                $t = "Draft $t";
            return $t;
        } else {
            return null;
        }
    }

    static function group_by_identity($crows, Contact $viewer, $separateColors) {
        $known_cids = $result = [];
        foreach ($crows as $cr) {
            $cid = 0;
            if ($viewer->can_view_comment_identity($cr->prow, $cr)
                || $cr->unparse_commenter_pseudonym($viewer)) {
                $cid = $cr->contactId;
            }
            if ($cr->commentType & COMMENTTYPE_RESPONSE) {
                if (!empty($result))
                    $result[count($result) - 1][2] = ";";
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
                && ($color = $cr->conf->tags()->color_classes($tags))) {
                $include = true;
                $record = false;
            }
            if ($include) {
                $result[] = [$cr, 1, $connector];
                if ($record)
                    $known_cids[$cid] = count($result) - 1;
            } else {
                ++$result[$known_cids[$cid]][1];
            }
        }
        if (!empty($result)) {
            $result[count($result) - 1][2] = "";
        }
        return $result;
    }

    private function unparse_commenter_pseudonym(Contact $viewer) {
        if ($this->commentType & (COMMENTTYPE_RESPONSE | COMMENTTYPE_BYAUTHOR)) {
            return "Author";
        } else if ($this->commentType & COMMENTTYPE_BYSHEPHERD) {
            return "Shepherd";
        } else if (($rrow = $this->prow->review_of_user($this->contactId))
                   && $rrow->reviewOrdinal
                   && $viewer->can_view_review($this->prow, $rrow)) {
            return "Reviewer " . unparseReviewOrdinal($rrow->reviewOrdinal);
        } else {
            return false;
        }
    }

    function unparse_commenter_html(Contact $viewer) {
        if ($viewer->can_view_comment_identity($this->prow, $this)) {
            $n = Text::abbrevname_html($this->commenter());
        } else {
            $n = $this->unparse_commenter_pseudonym($viewer) ? : "anonymous";
        }
        if ($this->commentType & COMMENTTYPE_RESPONSE) {
            $n = "<i>" . $this->unparse_response_text() . "</i>"
                . ($n === "Author" ? "" : " ($n)");
        }
        return $n;
    }

    function unparse_commenter_text(Contact $viewer) {
        if ($viewer->can_view_comment_identity($this->prow, $this)) {
            $n = Text::abbrevname_text($this->commenter());
        } else {
            $n = $this->unparse_commenter_pseudonym($viewer) ? : "anonymous";
        }
        if ($this->commentType & COMMENTTYPE_RESPONSE) {
            $n = $this->unparse_response_text()
                . ($n === "Author" ? "" : " ($n)");
        }
        return $n;
    }

    function searchable_tags(Contact $viewer) {
        if ($this->commentTags
            && $viewer->can_view_comment_tags($this->prow, $this)) {
            return $this->conf->tags()->censor(TagMap::CENSOR_SEARCH, $this->commentTags, $viewer, $this->prow);
        } else {
            return null;
        }
    }

    function viewable_tags(Contact $viewer) {
        if ($this->commentTags
            && $viewer->can_view_comment_tags($this->prow, $this)) {
            return $this->conf->tags()->censor(TagMap::CENSOR_VIEW, $this->commentTags, $viewer, $this->prow);
        } else {
            return null;
        }
    }

    function viewable_nonresponse_tags(Contact $viewer) {
        if ($this->commentTags
            && $viewer->can_view_comment_tags($this->prow, $this)) {
            $tags = $this->conf->tags()->censor(TagMap::CENSOR_VIEW, $this->commentTags, $viewer, $this->prow);
            if ($this->commentType & COMMENTTYPE_RESPONSE) {
                $tags = preg_replace('{ \S*response(?:|#\S+)(?= |\z)}i', "", $tags);
            }
            return $tags;
        } else {
            return null;
        }
    }

    function has_tag($tag) {
        return $this->commentTags
            && stripos($this->commentTags, " {$tag}#") !== false;
    }

    function attachments() {
        if ($this->commentType & COMMENTTYPE_HASDOC) {
            return $this->prow->linked_documents($this->commentId, 0, 1024, $this);
        } else {
            return [];
        }
    }

    function attachment_ids() {
        return array_map(function ($doc) { return $doc->paperStorageId; },
                         $this->attachments());
    }

    function unparse_json(Contact $viewer) {
        if ($this->commentId
            ? !$viewer->can_view_comment($this->prow, $this, true)
            : !$viewer->can_comment($this->prow, $this)) {
            return false;
        }

        if ($this->commentId) {
            $cj = (object) [
                "pid" => $this->prow->paperId,
                "cid" => $this->commentId,
                "ordinal" => $this->unparse_ordinal(),
                "visibility" => self::$visibility_map[$this->commentType & COMMENTTYPE_VISIBILITY]
            ];
        } else {
            // placeholder for new comment
            $cj = (object) [
                "pid" => $this->prow->paperId,
                "is_new" => true,
                "editable" => true
            ];
        }

        if ($this->commentType & COMMENTTYPE_BLIND) {
            $cj->blind = true;
        }
        if ($this->commentType & COMMENTTYPE_DRAFT) {
            $cj->draft = true;
        }
        if ($this->commentType & COMMENTTYPE_RESPONSE) {
            $cj->response = $this->conf->resp_round_name($this->commentRound);
        } else if ($this->commentType & COMMENTTYPE_BYAUTHOR) {
            $cj->by_author = true;
        } else if ($this->commentType & COMMENTTYPE_BYSHEPHERD) {
            $cj->by_shepherd = true;
        }

        // exit now if new-comment skeleton
        if (!$this->commentId) {
            if (($token = $viewer->active_review_token_for($this->prow))) {
                $cj->review_token = encode_token($token);
            }
            return $cj;
        }

        // otherwise, viewable comment
        if ($viewer->can_comment($this->prow, $this)) {
            $cj->editable = true;
        } else if ($viewer->can_finalize_comment($this->prow, $this)) {
            $cj->submittable = true;
        }

        // tags
        if (($tags = $this->viewable_tags($viewer))) {
            $cj->tags = TagInfo::split($tags);
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
            if (!($this->commentType & (COMMENTTYPE_RESPONSE | COMMENTTYPE_BYAUTHOR))
                && $viewer->can_view_user_tags()
                && ($cuser = $this->conf->pc_member_by_id($this->contactId))) {
                $cj->author = $viewer->reviewer_html_for($cuser);
                $email = $cuser->email;
            } else {
                $commenter = $this->commenter();
                $cj->author = Text::name_html($commenter);
                $email = $commenter->email;
            }
            if (!$idable) {
                $cj->author_hidden = true;
            }
            if (!Contact::is_anonymous_email($email)) {
                $cj->author_email = $email;
            } else if ($viewer->review_tokens()
                       && ($rrows = $this->prow->reviews_of_user(-1, $viewer->review_tokens()))) {
                $cj->review_token = encode_token((int) $rrows[0]->reviewToken);
            }
        }
        if ((!$idable
             || ($this->commentType & (COMMENTTYPE_VISIBILITY | COMMENTTYPE_BLIND)) == (COMMENTTYPE_AUTHOR | COMMENTTYPE_BLIND))
            && ($p = $this->unparse_commenter_pseudonym($viewer))) {
            $cj->author_pseudonym = $p;
        }
        if ($this->timeModified > 0) {
            if ($idable_override) {
                $cj->modified_at = (int) $this->timeModified;
            } else {
                $cj->modified_at = $this->conf->obscure_time($this->timeModified);
                $cj->modified_at_obscured = true;
            }
            $cj->modified_at_text = $this->conf->unparse_time_point($cj->modified_at);
        }

        // text
        if ($viewer->can_view_comment_text($this->prow, $this)) {
            $cj->text = $this->commentOverflow ? : $this->comment;
        } else {
            $cj->text = false;
            $cj->word_count = count_words($this->commentOverflow ? : $this->comment);
        }

        // format
        $fmt = $this->commentFormat;
        if ($fmt === null) {
            $fmt = $this->conf->default_format;
        }
        if ($fmt) {
            $cj->format = (int) $fmt;
        }

        // attachments
        foreach ($this->attachments() as $doc) {
            $docj = $doc->unparse_json();
            if (isset($cj->editable)) {
                $docj->docid = $doc->paperStorageId;
            }
            $cj->docs[] = $docj;
        }

        return $cj;
    }

    function unparse_text(Contact $contact, $no_title = false) {
        if (!($this->commentType & COMMENTTYPE_RESPONSE)) {
            $ordinal = $this->unparse_ordinal();
            $x = "Comment" . ($ordinal ? " @$ordinal" : "");
        } else if (($rname = $this->conf->resp_round_text($this->commentRound))) {
            $x = "$rname Response";
        } else {
            $x = "Response";
        }
        if ($contact->can_view_comment_identity($this->prow, $this)) {
            $x .= " by " . Text::user_text($this->commenter());
        } else if (($p = $this->unparse_commenter_pseudonym($contact))
                   && ($p !== "Author" || !($this->commentType & COMMENTTYPE_RESPONSE))) {
            $x .= " by " . $p;
        }
        $x .= "\n" . str_repeat("-", 75) . "\n";
        if (!$no_title) {
            $prow = $this->prow;
            $x .= prefix_word_wrap("* ", "Paper: #{$prow->paperId} {$prow->title}", 2);
        }
        if (($tags = $this->viewable_nonresponse_tags($contact))) {
            $tagger = new Tagger($contact);
            $x .= prefix_word_wrap("* ", $tagger->unparse_hashed($tags), 2);
        }
        if (!$no_title || $tags) {
            $x .= "\n";
        }
        if ($this->commentOverflow) {
            $x .= $this->commentOverflow;
        } else {
            $x .= $this->comment;
        }
        return rtrim($x) . "\n";
    }

    function unparse_flow_entry(Contact $contact) {
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
        $idable = $contact->can_view_comment_identity($this->prow, $this);
        if ($idable || $contact->can_view_comment_time($this->prow, $this)) {
            $time = $this->conf->unparse_time($this->timeModified, false);
        } else {
            $time = $this->conf->unparse_time_obscure($this->conf->obscure_time($this->timeModified));
        }
        $t .= ' <span class="barsep">·</span> ' . $time;
        if ($idable) {
            $t .= ' <span class="barsep">·</span> <span class="hint">comment by</span> ' . $contact->reviewer_html_for($this->contactId);
        }
        return $t . "</small><br />"
            . htmlspecialchars(UnicodeHelper::utf8_abbreviate($this->commentOverflow ? : $this->comment, 300))
            . "</td></tr>";
    }


    private function save_ordinal($cmtid, $ctype, $Table, $LinkTable, $LinkColumn) {
        $okey = $ctype >= COMMENTTYPE_AUTHOR ? "authorOrdinal" : "ordinal";
        $q = "update $Table, (select coalesce(max($Table.$okey),0) maxOrdinal
    from $LinkTable
    left join $Table on ($Table.$LinkColumn=$LinkTable.$LinkColumn)
    where $LinkTable.$LinkColumn={$this->prow->$LinkColumn}
    group by $LinkTable.$LinkColumn) t
set $okey=(t.maxOrdinal+1) where commentId=$cmtid";
        $this->conf->qe($q);
    }

    function save($req, Contact $acting_contact) {
        global $Now;
        if (is_array($req)) {
            $req = (object) $req;
        }
        $Table = $this->prow->comment_table_name();
        $LinkTable = $this->prow->table_name();
        $LinkColumn = $this->prow->id_column();

        $contact = $acting_contact;
        if (!$contact->contactId) {
            $contact = $acting_contact->reviewer_capability_user($this->prow->paperId);
        }
        if (!$contact || !$contact->contactId) {
            error_log("Comment::save({$this->prow->paperId}): no such user");
            return false;
        }

        $req_visibility = get(self::$visibility_revmap, get($req, "visibility", ""));
        if ($req_visibility === null && $this->commentId) {
            $req_visibility = $this->commentType & COMMENTTYPE_VISIBILITY;
        }

        $is_response = !!($this->commentType & COMMENTTYPE_RESPONSE);
        $response_name = null;
        if ($is_response) {
            $ctype = COMMENTTYPE_RESPONSE | COMMENTTYPE_AUTHOR;
            if (!get($req, "submit")) {
                $ctype |= COMMENTTYPE_DRAFT;
            }
            $response_name = $this->conf->resp_round_name($this->commentRound);
        } else if ($contact->act_author_view($this->prow)) {
            if ($req_visibility === null)
                $req_visibility = COMMENTTYPE_AUTHOR;
            $ctype = $req_visibility | COMMENTTYPE_BYAUTHOR;
        } else {
            if ($req_visibility === null)
                $req_visibility = COMMENTTYPE_REVIEWER;
            $ctype = $req_visibility;
        }
        if ($is_response
            ? $this->prow->blind
            : $this->conf->is_review_blind(!!get($req, "blind"))) {
            $ctype |= COMMENTTYPE_BLIND;
        }
        if ($this->commentId
            ? $this->commentType & COMMENTTYPE_BYSHEPHERD
            : $contact->contactId == $this->prow->shepherdContactId) {
            $ctype |= COMMENTTYPE_BYSHEPHERD;
        }

        // tags
        if ($is_response) {
            $ctags = " response";
            if ($response_name != "1") {
                $ctags .= " {$response_name}response";
            }
        } else if (get($req, "tags")
                   && preg_match_all(',\S+,', $req->tags, $m)
                   && !$contact->act_author_view($this->prow)) {
            $tagger = new Tagger($contact);
            $ts = [];
            foreach ($m[0] as $tt) {
                if (($tt = $tagger->check($tt))
                    && !stri_ends_with($tt, "response")) {
                    list($tag, $value) = TagInfo::unpack($tt);
                    $ts[strtolower($tag)] = $tag . "#" . (float) $value;
                }
            }
            if (!empty($ts)) {
                $ctags = " " . join(" ", $this->conf->tags()->sort($ts));
            } else {
                $ctags = null;
            }
        } else {
            $ctags = null;
        }

        // attachments
        $docids = $old_docids = [];
        if (($docs = get($req, "docs"))) {
            $ctype |= COMMENTTYPE_HASDOC;
            $docids = array_map(function ($doc) { return $doc->paperStorageId; }, $docs);
        }
        if ($this->commentType & COMMENTTYPE_HASDOC) {
            $old_docids = array_map(function ($doc) { return $doc->paperStorageId; }, $this->attachments());
        }

        // notifications
        $displayed = !($ctype & COMMENTTYPE_DRAFT);

        // query
        if (($text = get($req, "text")) !== false) {
            $text = (string) $text;
        }
        $q = "";
        $qv = array();
        if ($text === false) {
            if ($this->commentId) {
                $change = true;
                $q = "delete from $Table where commentId=$this->commentId";
                $docids = [];
            }
        } else if (!$this->commentId) {
            $change = true;
            $qa = ["contactId, $LinkColumn, commentType, comment, commentOverflow, timeModified, replyTo"];
            $qb = [$contact->contactId, $this->prow->$LinkColumn, $ctype, "?", "?", $Now, 0];
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
            if ($is_response) {
                $qa[] = "commentRound";
                $qb[] = $this->commentRound;
            }
            if ($displayed) {
                $qa[] = "timeDisplayed, timeNotified";
                $qb[] = "$Now, $Now";
            }
            $q = "insert into $Table (" . join(", ", $qa) . ") select " . join(", ", $qb) . "\n";
            if ($is_response) {
                // make sure there is exactly one response
                $q .= " from (select $LinkTable.$LinkColumn, coalesce(commentId, 0) commentId
                from $LinkTable
                left join $Table on ($Table.$LinkColumn=$LinkTable.$LinkColumn and (commentType&" . COMMENTTYPE_RESPONSE . ")!=0 and commentRound=$this->commentRound)
                where $LinkTable.$LinkColumn={$this->prow->$LinkColumn} limit 1) t
        where t.commentId=0";
            }
        } else {
            $change = ($this->commentType >= COMMENTTYPE_AUTHOR) != ($ctype >= COMMENTTYPE_AUTHOR);
            if ($this->timeModified >= $Now) {
                $Now = $this->timeModified + 1;
            }
            // do not notify on updates within 3 hours
            $qa = "";
            if ($this->timeNotified + 10800 < $Now
                || (($ctype & COMMENTTYPE_RESPONSE)
                    && !($ctype & COMMENTTYPE_DRAFT)
                    && ($this->commentType & COMMENTTYPE_DRAFT))) {
                $qa .= ", timeNotified=$Now";
            }
            // reset timeDisplayed if you change the comment type
            if ((!$this->timeDisplayed || $this->ordinal_missing($ctype))
                && ($text !== "" || $docids)
                && $displayed) {
                $qa .= ", timeDisplayed=$Now";
            }
            $q = "update $Table set timeModified=$Now$qa, commentType=$ctype, comment=?, commentOverflow=?, commentTags=? where commentId=$this->commentId";
            if (strlen($text) <= 32000) {
                array_push($qv, $text, null);
            } else {
                array_push($qv, UnicodeHelper::utf8_prefix($text, 200), $text);
            }
            $qv[] = $ctags;
        }

        $result = $this->conf->qe_apply($q, $qv);
        if (!$result) {
            return false;
        }

        $cmtid = $this->commentId ? : $result->insert_id;
        if (!$cmtid) {
            return false;
        }

        // log
        $log = $is_response ? "Response $cmtid" : "Comment $cmtid";
        if ($is_response && $response_name != "1") {
            $log .= " ($response_name)";
        }
        if ($text === false) {
            $log .= " deleted";
        } else {
            $log .= $this->commentId ? " edited" : " added";
            if ($ctype & COMMENTTYPE_DRAFT) {
                $log .= " draft";
            }
            $ch = [];
            if ($this->commentId
                && $text !== ($this->commentOverflow ? : $this->comment)) {
                $ch[] = "text";
            }
            if ($this->commentId
                && ($ctype | COMMENTTYPE_DRAFT) !== ($this->commentType | COMMENTTYPE_DRAFT)) {
                $ch[] = "visibility";
            }
            if ($ctags !== $this->commentTags) {
                $ch[] = "tags";
            }
            if ($docids !== $old_docids) {
                $ch[] = "attachments";
            }
            if (!empty($ch)) {
                $log .= ": " . join(", ", $ch);
            }
        }
        $acting_contact->log_activity_for($this->contactId ? : $contact->contactId, $log, $this->prow->$LinkColumn);

        // update autosearch
        $this->conf->update_autosearch_tags($this->prow);

        // ordinal
        if ($text !== false && $this->ordinal_missing($ctype)) {
            $this->save_ordinal($cmtid, $ctype, $Table, $LinkTable, $LinkColumn);
        }

        // reload
        if ($text !== false) {
            $comments = $this->prow->fetch_comments("commentId=$cmtid");
            $this->merge($comments[$cmtid], $this->prow);
            if ($this->timeNotified == $this->timeModified) {
                $this->prow->notify_reviews([$this, "watch_callback"], $contact);
            }
        } else {
            $this->commentId = 0;
            $this->comment = "";
            $this->commentTags = null;
        }

        // document links
        if ($docids !== $old_docids) {
            if ($old_docids) {
                $this->conf->qe("delete from DocumentLink where paperId=? and linkId=? and linkType>=? and linkType<?", $this->prow->paperId, $this->commentId, 0, 1024);
            }
            if ($docids) {
                $qv = [];
                foreach ($docids as $i => $did) {
                    $qv[] = [$this->prow->paperId, $this->commentId, $i, $did];
                }
                $this->conf->qe("insert into DocumentLink (paperId,linkId,linkType,documentId) values ?v", $qv);
            }
            if ($old_docids) {
                $this->prow->mark_inactive_linked_documents();
            }
            $this->prow->invalidate_linked_documents();
        }

        return true;
    }

    function watch_callback($prow, $minic) {
        $ctype = $this->commentType;
        if ($minic->can_view_comment($prow, $this)
            // Don't send notifications about draft responses to the chair,
            // even though the chair can see draft responses.
            && (!($ctype & COMMENTTYPE_DRAFT) || $minic->act_author_view($prow))) {
            if (($ctype & COMMENTTYPE_RESPONSE) && ($ctype & COMMENTTYPE_DRAFT)) {
                $tmpl = "@responsedraftnotify";
            } else if ($ctype & COMMENTTYPE_RESPONSE) {
                $tmpl = "@responsenotify";
            } else {
                $tmpl = "@commentnotify";
            }
            HotCRPMailer::send_to($minic, $tmpl, ["prow" => $prow, "comment_row" => $this]);
        }
    }
}
