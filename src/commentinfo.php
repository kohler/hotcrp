<?php
// commentinfo.php -- HotCRP helper class for comments
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

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
        if ($x)
            foreach ($x as $k => $v)
                $this->$k = $v;
        $this->commentId = (int) $this->commentId;
        $this->paperId = (int) $this->paperId;
        $this->commentType = (int) $this->commentType;
        $this->commentRound = (int) $this->commentRound;
        if ($this->commentType >= COMMENTTYPE_AUTHOR)
            $this->authorOrdinal = $this->ordinal;
    }

    static function fetch($result, PaperInfo $prow = null, Conf $conf = null) {
        $cinfo = null;
        if ($result)
            $cinfo = $result->fetch_object("CommentInfo", [null, $prow, $conf]);
        if ($cinfo && !is_int($cinfo->commentId))
            $cinfo->merge(null, $prow, $conf);
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
                    if (($m = $rrd->instructions($prow->conf)) !== false)
                        $j["instrux"] = $m;
                    if ($rrd->done)
                        $j["done"] = $rrd->done;
                }
                $t[] = "papercomment.set_resp_round(" . json_encode($rrd->name) . "," . json_encode($j) . ")";
            }
            echo Ht::unstash_script(join($t, ";"));
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
        if (self::commenttype_needs_ordinal($this->commentType) && $o)
            return ($is_author ? "A" : "") . $o;
        else
            return null;
    }

    function unparse_html_id() {
        $is_author = $this->commentType >= COMMENTTYPE_AUTHOR;
        $o = $is_author ? $this->authorOrdinal : $this->ordinal;
        if (self::commenttype_needs_ordinal($this->commentType) && $o)
            return ($is_author ? "cA" : "c") . $o;
        else if ($this->commentType & COMMENTTYPE_RESPONSE)
            return $this->conf->resp_round_text($this->commentRound) . "response";
        else
            return "cx" . $this->commentId;
    }

    private static function _user($x) {
        if (isset($x->reviewEmail))
            return (object) ["firstName" => get($x, "reviewFirstName"), "lastName" => get($x, "reviewLastName"), "email" => $x->reviewEmail];
        else
            return $x;
    }

    function user() {
        return self::_user($this);
    }

    function unparse_response_text() {
        if ($this->commentType & COMMENTTYPE_RESPONSE) {
            $rname = $this->conf->resp_round_name($this->commentRound);
            $t = $rname == "1" ? "Response" : "$rname Response";
            if ($this->commentType & COMMENTTYPE_DRAFT)
                $t = "Draft $t";
            return $t;
        } else
            return null;
    }

    static function group_by_identity($crows, Contact $user, $separateColors) {
        $known_cids = $result = [];
        foreach ($crows as $cr) {
            $cid = 0;
            if ($user->can_view_comment_identity($cr->prow, $cr)
                || $cr->unparse_user_pseudonym($user)) {
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
                && ($tags = $cr->viewable_tags($user))
                && ($color = $cr->conf->tags()->color_classes($tags))) {
                $include = true;
                $record = false;
            }
            if ($include) {
                $result[] = [$cr, 1, $connector];
                if ($record)
                    $known_cids[$cid] = count($result) - 1;
            } else
                ++$result[$known_cids[$cid]][1];
        }
        if (!empty($result))
            $result[count($result) - 1][2] = "";
        return $result;
    }

    private function unparse_user_pseudonym(Contact $user) {
        if ($this->commentType & (COMMENTTYPE_RESPONSE | COMMENTTYPE_BYAUTHOR)) {
            return "Author";
        } else if ($this->commentType & COMMENTTYPE_BYSHEPHERD) {
            return "Shepherd";
        } else if (($rrow = $this->prow->review_of_user($this->contactId))
                   && $rrow->reviewOrdinal
                   && $user->can_view_review($this->prow, $rrow)) {
            return "Reviewer " . unparseReviewOrdinal($rrow->reviewOrdinal);
        } else {
            return false;
        }
    }

    function unparse_user_html(Contact $user) {
        if ($user->can_view_comment_identity($this->prow, $this))
            $n = Text::abbrevname_html($this->user());
        else
            $n = $this->unparse_user_pseudonym($user) ? : "anonymous";
        if ($this->commentType & COMMENTTYPE_RESPONSE)
            $n = "<i>" . $this->unparse_response_text() . "</i>"
                . ($n === "Author" ? "" : " ($n)");
        return $n;
    }

    function unparse_user_text(Contact $user) {
        if ($user->can_view_comment_identity($this->prow, $this))
            $n = Text::abbrevname_text($this->user());
        else
            $n = $this->unparse_user_pseudonym($user) ? : "anonymous";
        if ($this->commentType & COMMENTTYPE_RESPONSE)
            $n = $this->unparse_response_text()
                . ($n === "Author" ? "" : " ($n)");
        return $n;
    }

    function viewable_tags(Contact $user) {
        if ($this->commentTags
            && $user->can_view_comment_tags($this->prow, $this))
            return $this->conf->tags()->strip_nonviewable($this->commentTags, $user, $this->prow);
        else
            return null;
    }

    function viewable_nonresponse_tags(Contact $user) {
        if ($this->commentTags
            && $user->can_view_comment_tags($this->prow, $this)) {
            $tags = $this->conf->tags()->strip_nonviewable($this->commentTags, $user, $this->prow);
            if ($this->commentType & COMMENTTYPE_RESPONSE)
                $tags = trim(preg_replace('{ \S*response(?:|#\S+)(?= |\z)}i', "", " $tags "));
            return $tags;
        } else
            return null;
    }

    function has_tag($tag) {
        return $this->commentTags
            && stripos($this->commentTags, " $tag ") !== false;
    }

    function attachments() {
        if ($this->commentType & COMMENTTYPE_HASDOC) {
            return $this->prow->linked_documents($this->commentId, 0, 1024);
        } else {
            return [];
        }
    }

    function attachment_ids() {
        return array_map(function ($doc) { return $doc->paperStorageId; },
                         $this->attachments());
    }

    function unparse_json(Contact $user) {
        if ($this->commentId
            ? !$user->can_view_comment($this->prow, $this, true)
            : !$user->can_comment($this->prow, $this)) {
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
            if (($token = $user->active_review_token_for($this->prow))) {
                $cj->review_token = encode_token($token);
            }
            return $cj;
        }

        // otherwise, viewable comment
        if ($user->can_comment($this->prow, $this)) {
            $cj->editable = true;
        } else if ($user->can_finalize_comment($this->prow, $this)) {
            $cj->submittable = true;
        }

        // tags
        if (($tags = $this->viewable_tags($user))) {
            $cj->tags = TagInfo::split($tags);
            if (($cc = $this->conf->tags()->color_classes($tags))) {
                $cj->color_classes = $cc;
            }
        }

        // identity and time
        $idable = $user->can_view_comment_identity($this->prow, $this);
        $idable_override = $idable
            || ($user->can_meaningfully_override($this->prow)
                && $user->call_with_overrides(Contact::OVERRIDE_CONFLICT, "can_view_comment_identity", $this->prow, $this));
        if ($idable || $idable_override) {
            $thisuser = $this->user();
            $cj->author = Text::user_html($thisuser);
            $cj->author_email = $user->email;
            if (!$idable) {
                $cj->author_hidden = true;
            }
            if (Contact::is_anonymous_email($cj->author_email)
                && $user->review_tokens()
                && ($rrows = $this->prow->reviews_of_user(-1, $user->review_tokens()))) {
                $cj->review_token = encode_token((int) $rrows[0]->reviewToken);
            }
        }
        if ((!$idable
             || ($this->commentType & (COMMENTTYPE_VISIBILITY | COMMENTTYPE_BLIND)) == (COMMENTTYPE_AUTHOR | COMMENTTYPE_BLIND))
            && ($p = $this->unparse_user_pseudonym($user))) {
            $cj->author_pseudonym = $p;
        }
        if ($this->timeModified > 0 && $idable_override) {
            $cj->modified_at = (int) $this->timeModified;
            $cj->modified_at_text = $this->conf->unparse_time_long($cj->modified_at);
        } else if ($this->timeModified > 0) {
            $cj->modified_at = $this->conf->obscure_time($this->timeModified);
            $cj->modified_at_text = $this->conf->unparse_time_obscure($cj->modified_at);
            $cj->modified_at_obscured = true;
        }

        // text
        if ($user->can_view_comment_text($this->prow, $this)) {
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
            $docj = $doc->unparse_json(["_comment" => $this]);
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
        } else if (($rname = $this->conf->resp_round_text($this->commentRound)))
            $x = "$rname Response";
        else
            $x = "Response";
        if ($contact->can_view_comment_identity($this->prow, $this)) {
            $x .= " by " . Text::user_text($this->user());
        } else if (($p = $this->unparse_user_pseudonym($contact))
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
        if (!$no_title || $tags)
            $x .= "\n";
        if ($this->commentOverflow)
            $x .= $this->commentOverflow;
        else
            $x .= $this->comment;
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
        if ($idable || $contact->can_view_comment_time($this->prow, $this))
            $time = $this->conf->parseableTime($this->timeModified, false);
        else
            $time = $this->conf->unparse_time_obscure($this->conf->obscure_time($this->timeModified));
        $t .= ' <span class="barsep">·</span> ' . $time;
        if ($idable)
            $t .= ' <span class="barsep">·</span> <span class="hint">comment by</span> ' . $contact->reviewer_html_for($this->contactId);
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

    function save($req, Contact $contact) {
        global $Now;
        if (is_array($req))
            $req = (object) $req;
        $Table = $this->prow->comment_table_name();
        $LinkTable = $this->prow->table_name();
        $LinkColumn = $this->prow->id_column();

        $req_visibility = get(self::$visibility_revmap, get($req, "visibility", ""));
        if ($req_visibility === null && $this->commentId)
            $req_visibility = $this->commentType & COMMENTTYPE_VISIBILITY;

        $is_response = !!($this->commentType & COMMENTTYPE_RESPONSE);
        if ($is_response) {
            $ctype = COMMENTTYPE_RESPONSE | COMMENTTYPE_AUTHOR;
            if (!get($req, "submit"))
                $ctype |= COMMENTTYPE_DRAFT;
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
            $ctags = " response ";
            if (($rname = $this->conf->resp_round_name($this->commentRound)) != "1")
                $ctags .= "{$rname}response ";
        } else if (get($req, "tags")
                   && preg_match_all(',\S+,', $req->tags, $m)
                   && !$contact->act_author_view($this->prow)) {
            $tagger = new Tagger($contact);
            $ctags = [];
            foreach ($m[0] as $tt)
                if (($tt = $tagger->check($tt, Tagger::NOVALUE))
                    && !stri_ends_with($tt, "response"))
                    $ctags[strtolower($tt)] = $tt;
            $tagger->sort($ctags);
            $ctags = count($ctags) ? " " . join(" ", $ctags) . " " : null;
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
        if (!$result)
            return false;

        $cmtid = $this->commentId ? : $result->insert_id;
        if (!$cmtid)
            return false;

        // log
        $contact->log_activity("Comment $cmtid " . ($text !== false ? "saved" : "deleted"), $this->prow->$LinkColumn);
        $this->conf->update_autosearch_tags($this->prow);

        // ordinal
        if ($text !== false && $this->ordinal_missing($ctype)) {
            $this->save_ordinal($cmtid, $ctype, $Table, $LinkTable, $LinkColumn);
        }

        // reload
        if ($text !== false) {
            $comments = $this->prow->fetch_comments("commentId=$cmtid");
            $this->merge($comments[$cmtid], $this->prow);
            if ($this->timeNotified == $this->timeModified)
                $this->prow->notify_reviews([$this, "watch_callback"], $contact);
        } else {
            $this->commentId = 0;
            $this->comment = "";
            $this->commentTags = null;
        }

        // document links
        if ($docids !== $old_docids) {
            if ($old_docids)
                $this->conf->qe("delete from DocumentLink where paperId=? and linkId=? and linkType>=? and linkType<?", $this->prow->paperId, $this->commentId, 0, 1024);
            if ($docids) {
                $qv = [];
                foreach ($docids as $i => $did)
                    $qv[] = [$this->prow->paperId, $this->commentId, $i, $did];
                $this->conf->qe("insert into DocumentLink (paperId,linkId,linkType,documentId) values ?v", $qv);
            }
            if ($old_docids)
                $this->prow->mark_inactive_linked_documents();
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
            if (($ctype & COMMENTTYPE_RESPONSE) && ($ctype & COMMENTTYPE_DRAFT))
                $tmpl = "@responsedraftnotify";
            else if ($ctype & COMMENTTYPE_RESPONSE)
                $tmpl = "@responsenotify";
            else
                $tmpl = "@commentnotify";
            HotCRPMailer::send_to($minic, $tmpl, $prow, ["comment_row" => $this]);
        }
    }
}
