<?php
// commentstatus.php -- HotCRP helper for saving comments
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class CommentStatus extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;          // acting user (may lack a contactId)
    /** @var ?Contact */
    public $suser;         // resolved commenter (via review-accept capability)
    /** @var bool */
    private $notify = true;
    /** @var bool */
    private $notify_authors = true;
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
    /** @var array<string,true> */
    private $_diffs = [];

    const SSF_PREPARED = 1;
    const SSF_SAVED = 2;
    const SSF_ABORTED = 4;
    const SSF_DELETE = 8;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    /** @param bool $x
     * @return $this */
    function set_notify($x) {
        $this->notify = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_notify_authors($x) {
        $this->notify_authors = $x;
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


    /** Stage the changes implied by `$req` onto `$crow`. No database writes.
     * @param array{text?:string|false,docs?:list<DocumentInfo>,tags?:?string,no_autosearch?:bool} $req
     * @return bool */
    function prepare_save(CommentInfo $crow, $req) {
        assert(($this->_status & self::SSF_PREPARED) === 0);
        $this->crow = $crow;

        // resolve acting user to the commenter (e.g. via review-accept link)
        $suser = $this->user;
        if (!$suser->contactId) {
            $suser = $this->user->reviewer_capability_user($crow->paperId);
        }
        if (!$suser || !$suser->contactId) {
            error_log("Comment::save({$crow->paperId}): no such user");
            return false;
        }
        $this->suser = $suser;

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
        $user = $this->suser;
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
            $this->_diffs = ["delete" => true];
            return true;
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
        $this->_compute_diffs();
        return true;
    }

    /** Compute the change list from the staged (but not yet committed) property
     * diff, so `change_list()` is available before `save()` — e.g. for a
     * dry run. `commit_prop`/`abort_prop` would clobber these prop diffs, which
     * is why they are captured here into CommentStatus. */
    private function _compute_diffs() {
        $crow = $this->crow;
        // text and visibility are per-field diffs of an existing comment; a
        // newly created comment is not a set of changes, so they are omitted
        $editing = $crow->commentId !== 0;
        $diffs = [];
        if ($editing
            && ($crow->prop_changed("comment") || $crow->prop_changed("commentOverflow"))) {
            $diffs["text"] = true;
        }
        if ($editing
            && $crow->prop_changed("commentType")
            && (($crow->commentType ^ $crow->base_prop("commentType")) & CommentInfo::CTM_TOPIC_NONREVIEW) !== 0) {
            $diffs["thread"] = true;
        }
        if ($editing
            && $crow->prop_changed("commentType")
            && (($crow->commentType ^ $crow->base_prop("commentType")) & CommentInfo::CTM_VIS) !== 0) {
            $diffs["visibility"] = true;
        }
        if ($crow->prop_changed("commentTags")) {
            $diffs["tags"] = true;
        }
        if ($crow->docs_changed()) {
            $diffs["attachments"] = true;
        }
        $this->_diffs = $diffs;
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
        if (!empty($this->_diffs)) {
            $log .= ": " . join(", ", array_keys($this->_diffs));
        }
        $this->user->log_activity_for($crow->contactId ? : $this->user->contactId, $log, $crow->paperId);
    }

    /** @return bool */
    private function _commit_delete() {
        $ok = $this->crow->delete($this->user);
        if ($ok && !$this->_no_autosearch) {
            $this->conf->update_automatic_tags($this->crow->prow, SearchTerm::ABOUT_COMMENTS);
        }
        return $ok;
    }

    /** The fields changed by the committed save (or `["delete"]`). Meaningful
     * only after execute_save.
     * @return list<string> */
    function change_list() {
        return array_keys($this->_diffs);
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
        $user = $this->suser;
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
