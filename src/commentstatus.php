<?php
// commentstatus.php -- HotCRP helper for saving comments
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

final class CommentStatus extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    private $viewer;        // acting/viewing user (may lack a contactId)
    /** @var ?Contact */
    private $user;          // resolved commenter (via review-accept capability)
    /** @var bool */
    private $notify = true;
    /** @var ?string */
    private $notify_reason;
    /** @var ?CommentInfo */
    private $crow;

    /** @var int */
    private $_status = 0;
    // transient orchestration state carried from prepare_save to execute_save
    /** @var bool */
    private $_has_change = false;
    /** @var bool */
    private $_displayed = false;
    /** @var bool */
    private $_no_autosearch = false;
    /** @var list<MentionPhrase> */
    private $_desired_mentions = [];

    const SSF_PREPARED = 1;
    const SSF_SAVED = 2;
    const SSF_ABORTED = 4;
    const SSF_DELETE = 8;
    const SSF_CREATE = 16;

    function __construct(Contact $viewer) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
    }

    /** @param bool $x
     * @return $this */
    function set_notify($x) {
        $this->notify = $x;
        return $this;
    }

    /** @param ?string $x
     * @return $this */
    function set_notify_reason($x) {
        $this->notify_reason = $x;
        return $this;
    }

    /** @return bool */
    function is_delete() {
        return ($this->_status & self::SSF_DELETE) !== 0;
    }

    /** @return bool */
    function has_change() {
        return $this->_has_change;
    }

    /** @return ?CommentInfo */
    function saved_crow() {
        return $this->crow;
    }

    /** Return true if this status's user administers the comment's submission.
     * Meaningful once `prepare_save()` has staged a comment.
     * @return bool */
    function can_user_manage() {
        return $this->crow
            && $this->crow->prow
            && $this->viewer->can_manage($this->crow->prow);
    }


    /** Stage the changes implied by `$req` onto `$crow`. No database writes.
     * @param array{text?:string|false,docs?:list<DocumentInfo>,tags?:?string,no_autosearch?:bool} $req
     * @return bool */
    function prepare_save(CommentInfo $crow, $req) {
        assert(($this->_status & self::SSF_PREPARED) === 0);
        $this->crow = $crow;

        // resolve the acting user to the commenter (e.g. via review-accept link)
        $user = $this->viewer;
        if (!$user->contactId) {
            $user = $this->viewer->reviewer_capability_user($crow->paperId);
        }
        if (!$user || !$user->contactId) {
            error_log("Comment::save({$crow->paperId}): no such user");
            return false;
        }
        $this->user = $user;

        if (!$this->_stage($req)) {
            return false;
        }
        $this->_status |= self::SSF_PREPARED;
        return true;
    }

    /** @param array $req
     * @return bool */
    private function _stage($req) {
        $crow = $this->crow;
        $user = $this->user;
        assert($crow->paperId > 0 && (!$crow->prow || $crow->prow->paperId === $crow->paperId));
        $crow->notifications = [];
        $this->_no_autosearch = $req["no_autosearch"] ?? false;
        $this->_has_change = false;

        $text = $req["text"] ?? null;
        if ($text === false) {
            $this->_status |= self::SSF_DELETE;
            // deleting a nonexistent comment is a hard no-op
            if ($crow->commentId === 0) {
                return false;
            }
            return true;
        }

        // a comment with no id yet is a fresh creation, not a set of edits
        if ($crow->commentId === 0) {
            $this->_status |= self::SSF_CREATE;
        }

        $old_ctype = $crow->base_prop("commentType");
        $ctype = $crow->requested_type($req) & CommentInfo::CT_DBMASK;
        $is_response = ($ctype & CommentInfo::CT_RESPONSE) !== 0;

        // tags
        if (!$is_response
            && isset($req["tags"])
            && !$user->act_author_view($crow->prow)) {
            $tagger = new Tagger($user);
            $ts = [];
            foreach (preg_split('/\s++/', $req["tags"]) as $tt) {
                if ($tt !== ""
                    && ($tt = $tagger->check($tt))) {
                    list($tag, $value) = Tagger::unpack($tt);
                    $ltag = strtolower($tag);
                    if (!str_ends_with($ltag, "response")) {
                        $ts[$ltag] = $tag . "#" . (float) $value;
                    }
                }
            }
            $ts = $this->conf->tags()->sort_array(array_values($ts));
            $crow->set_prop("commentTags", empty($ts) ? null : " " . join(" ", $ts));
        }

        // attachments (set_attachments updates the CT_HASDOC bit itself)
        $docs = $req["docs"] ?? [];
        $crow->set_prop("commentType", $ctype);
        $crow->set_attachments($docs);

        // notifications
        $this->_displayed = $displayed = ($ctype & CommentInfo::CT_DRAFT) === 0;

        // text, mentions
        $text = (string) $text;
        if (strlen($text) <= 32000) {
            $crow->set_prop("comment", $text);
            $crow->set_prop("commentOverflow", null);
        } else {
            $crow->set_prop("comment", UnicodeHelper::utf8_prefix($text, 200));
            $crow->set_prop("commentOverflow", $text);
        }
        $desired_mentions = CommentInfo::parse_mentions($user, $crow->prow, $text, $ctype);
        $this->_desired_mentions = $desired_mentions;
        $crow->set_data_prop("mentions", empty($desired_mentions) ? null : $desired_mentions);

        // more properties
        if (!$crow->commentId) {
            $crow->_old_prop["contactId"] = null;
            $crow->set_prop("contactId", $user->contactId);
            $crow->_old_prop["paperId"] = null;
            $crow->_old_prop["replyTo"] = null;
            $crow->_old_prop["commentRound"] = null;
            $crow->_old_prop["commentType"] = null;
        }
        // timeDisplayed, timeNotified
        if ($crow->timeModified >= Conf::$now) {
            Conf::advance_current_time($crow->timeModified);
        }
        if ($displayed) {
            if ($crow->timeNotified + 10800 < Conf::$now
                || (($ctype & CommentInfo::CT_RESPONSE) !== 0
                    && ($ctype & CommentInfo::CT_DRAFT) === 0
                    && ($old_ctype & CommentInfo::CT_DRAFT) !== 0)) {
                $crow->set_prop("timeNotified", Conf::$now);
            }
            // reset timeDisplayed if you change the comment type
            if ((!$crow->timeDisplayed || $crow->ordinal_missing())
                && ($text !== "" || $docs)) {
                $crow->set_prop("timeDisplayed", Conf::$now);
            }
        }

        $this->_has_change = $crow->prop_changed() || $crow->docs_changed();
        return true;
    }


    /** Commit the staged changes to the database.
     * @return bool */
    function execute_save() {
        if (($this->_status & (self::SSF_PREPARED | self::SSF_SAVED | self::SSF_ABORTED)) !== self::SSF_PREPARED) {
            throw new ErrorException("CommentStatus::execute_save called inappropriately");
        }
        $this->_status |= self::SSF_SAVED;
        return $this->is_delete() ? $this->_commit_delete() : $this->_commit();
    }

    /** @return bool */
    private function _commit() {
        // CommentInfo::save persists the row, ordinal, and attachments; the
        // orchestrator owns the change list, activity log, automatic-tag
        // recompute, and notifications.
        if (!$this->crow->save()) {
            return false;
        }
        if ($this->_has_change) {
            // record the change list and log while the property diff is still
            // live — commit_prop() clears it below
            $this->_log_save();
            if (!$this->_no_autosearch) {
                $this->conf->update_automatic_tags($this->crow->prow, SearchTerm::ABOUT_COMMENTS);
            }
        }
        // mark clean now that the staged changes are persisted
        $this->crow->commit_prop();
        return true;
    }

    /** Write the save activity log, naming the change list captured at prepare
     * time. Runs after CommentInfo::save so `commentId`/ordinal are assigned. */
    private function _log_save() {
        $crow = $this->crow;
        $ctype = $crow->commentType;
        $log = $crow->logid();
        if (($ctype & CommentInfo::CT_DRAFT) === 0
            && (!$crow->commentId || ($ctype & CommentInfo::CT_DRAFT) !== 0)) {
            $log .= " submitted";
        } else {
            $log .= $crow->commentId ? " edited" : " started";
            if (($ctype & CommentInfo::CT_DRAFT) !== 0) {
                $log .= " draft";
            }
        }
        $cl = $this->change_list(false);
        if (!empty($cl)) {
            $log .= ": " . join(", ", $cl);
        }
        $this->viewer->log_activity_for($crow->contactId ? : $this->viewer->contactId, $log, $crow->paperId);
    }

    /** @return bool */
    private function _commit_delete() {
        $ok = $this->crow->delete($this->viewer);
        if ($ok && !$this->_no_autosearch) {
            $this->conf->update_automatic_tags($this->crow->prow, SearchTerm::ABOUT_COMMENTS);
        }
        return $ok;
    }

    /** The fields established by this save (or `["delete"]`). Computed from the
     * live property diff, so it is meaningful from prepare_save until the save
     * is committed or aborted.
     *
     * A newly created comment is a creation, not a set of field changes. In
     * `$full` mode (the API view) it leads with `new` and reports its text and
     * `$full` mode (the API view) it leads with `new` and reports its text,
     * non-default topic, and visibility as appropriate; the non-full view (the
     * activity log) omits `new` and, for a fresh comment, its text, topic, and
     * visibility.
     * @param bool $full
     * @return list<string> */
    function change_list($full = false) {
        if ($this->is_delete()) {
            return ["delete"];
        }
        $crow = $this->crow;
        $creating = ($this->_status & self::SSF_CREATE) !== 0;
        $s = [];
        if ($full && $creating) {
            $s[] = "new";
        }
        // text: changed on an edit; present on a fresh comment (full only)
        if ($creating
            ? ($full && ($crow->comment !== "" || (string) $crow->commentOverflow !== ""))
            : ($crow->prop_changed("comment") || $crow->prop_changed("commentOverflow"))) {
            $s[] = "text";
        }
        // topic: changed on an edit; a fresh comment lists a non-default topic
        // (any non-review-thread bit) in full mode
        if ($creating
            ? ($full && ($crow->commentType & CommentInfo::CTM_TOPIC_NONREVIEW) !== 0)
            : ($crow->prop_changed("commentType")
               && (($crow->commentType ^ $crow->base_prop("commentType")) & CommentInfo::CTM_TOPIC_NONREVIEW) !== 0)) {
            $s[] = "topic";
        }
        // visibility: changed on an edit; always present on a fresh comment (full only)
        if ($creating
            ? $full
            : ((($crow->commentType ^ $crow->base_prop("commentType")) & CommentInfo::CTM_VIS) !== 0)) {
            $s[] = "visibility";
        }
        if ($crow->prop_changed("commentTags")) {
            $s[] = "tags";
        }
        if ($crow->docs_changed()) {
            $s[] = "attachments";
        }
        return $s;
    }

    /** Discard staged changes without writing them. */
    function abort_save() {
        if (($this->_status & (self::SSF_SAVED | self::SSF_ABORTED)) === 0) {
            $this->_status |= self::SSF_ABORTED;
            if ($this->crow) {
                $this->crow->abort_prop();
            }
        }
    }

    /** Send mention and follower notifications for a committed save. */
    function notify_followers() {
        if (!$this->notify
            || ($this->_status & self::SSF_SAVED) === 0
            || $this->is_delete()
            || !$this->_has_change
            || !$this->crow) {
            return;
        }
        $crow = $this->crow;
        $user = $this->user;
        if ($this->_displayed
            && $crow->commentId
            && !empty($this->_desired_mentions)) {
            $crow->inform_mentions($user, $this->_desired_mentions);
        }
        if ($crow->timeNotified === $crow->timeModified) {
            $crow->notify($user);
        }
    }
}
