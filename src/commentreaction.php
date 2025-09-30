<?php
// commentreaction.php -- HotCRP helper class for comment reactions
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class CommentReaction {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var int */
    public $reactionId = 0;
    /** @var int */
    public $commentId = 0;
    /** @var int */
    public $contactId = 0;
    /** @var string */
    public $emoji = "";
    /** @var int */
    public $timeModified = 0;
    
    /** @var ?Contact */
    private $_user;

    function __construct(?Conf $conf = null) {
        $this->conf = $conf;
    }

    /** @suppress PhanAccessReadOnlyProperty */
    private function _incorporate(Conf $conf) {
        $this->conf = $conf;
        $this->reactionId = (int) $this->reactionId;
        $this->commentId = (int) $this->commentId;
        $this->contactId = (int) $this->contactId;
        $this->timeModified = (int) $this->timeModified;
    }

    /** @param Dbl_Result $result
     * @return ?CommentReaction */
    static function fetch($result, Conf $conf) {
        $reaction = $result->fetch_object("CommentReaction");
        if ($reaction) {
            $reaction->_incorporate($conf);
        }
        return $reaction;
    }

    /** @return Contact */
    function user() {
        if ($this->_user === null) {
            $this->_user = $this->conf->user_by_id($this->contactId, USER_SLICE)
                ?? Contact::make_deleted($this->conf, $this->contactId);
        }
        return $this->_user;
    }

    /** @return string */
    function emoji_unicode() {
        static $emoji_map = null;
        if ($emoji_map === null) {
            $emoji_file = SiteLoader::find("scripts/emojicodes.json");
            if ($emoji_file) {
                $emoji_data = json_decode(file_get_contents($emoji_file), true);
                $emoji_map = $emoji_data["emoji"] ?? [];
            } else {
                $emoji_map = [];
            }
        }
        return $emoji_map[$this->emoji] ?? $this->emoji;
    }

    /** @param Conf $conf
     * @return list<string> */
    static function default_reaction_emojis(Conf $conf) {
        $custom_list = $conf->opt("commentReactionEmojis");
        if (is_string($custom_list)) {
            return array_map('trim', explode(',', $custom_list));
        }
        return ["thumbs_up", "thumbs_down", "heart", "laugh", "confused", "hooray", "rocket", "eyes"];
    }

    /** @param Contact $viewer
     * @param PaperInfo $prow
     * @param CommentInfo $comment
     * @return bool */
    function can_view_identity(Contact $viewer, PaperInfo $prow, CommentInfo $comment) {
        // Follow the same visibility rules as comment identity
        // We need to check if viewer can see the reactor's identity in context of this comment
        $reactor = $this->user();
        
        // If viewer can see comment identity in general, they can see reactor identity
        if ($viewer->can_view_comment_identity($prow, $comment)) {
            return true;
        }
        
        // If reactor is the same as comment author and viewer can see comment author identity
        if ($reactor->contactId === $comment->contactId 
            && $viewer->can_view_comment_identity($prow, $comment)) {
            return true;
        }
        
        // Follow same rules as comment pseudonyms
        return false;
    }

    /** @param Contact $viewer
     * @param PaperInfo $prow
     * @param CommentInfo $comment
     * @return string */
    function unparse_user_html(Contact $viewer, PaperInfo $prow, CommentInfo $comment) {
        if ($this->can_view_identity($viewer, $prow, $comment)) {
            return Text::nameo_h($this->user(), NAME_P);
        } else {
            // Use same pseudonym logic as comments
            if (($comment->commentType & CommentInfo::CTM_BYAUTHOR) !== 0) {
                return "Author";
            } else if (($rrow = $prow->review_by_user($this->contactId))
                       && $rrow->reviewOrdinal
                       && $viewer->can_view_review_assignment($prow, $rrow)) {
                return "Reviewer " . unparse_latin_ordinal($rrow->reviewOrdinal);
            } else {
                return "Anonymous";
            }
        }
    }

    /** @param Contact $acting_user
     * @param CommentInfo $comment
     * @return bool */
    function save(Contact $acting_user, CommentInfo $comment) {
        if (!$this->emoji || !$this->commentId || !$this->contactId) {
            return false;
        }

        $this->timeModified = Conf::$now;
        
        if ($this->reactionId > 0) {
            // Update existing reaction
            $result = $this->conf->qe("UPDATE CommentReaction SET emoji=?, timeModified=? WHERE reactionId=?",
                $this->emoji, $this->timeModified, $this->reactionId);
        } else {
            // Insert new reaction - check for duplicate first
            $existing = $this->conf->fetch_first_object(
                "SELECT reactionId FROM CommentReaction WHERE commentId=? AND contactId=? AND emoji=?",
                $this->commentId, $this->contactId, $this->emoji);
            
            if ($existing) {
                // Already exists, just update timestamp
                $this->reactionId = (int) $existing->reactionId;
                $result = $this->conf->qe("UPDATE CommentReaction SET timeModified=? WHERE reactionId=?",
                    $this->timeModified, $this->reactionId);
            } else {
                // Insert new
                $result = $this->conf->qe("INSERT INTO CommentReaction (commentId, contactId, emoji, timeModified) VALUES (?, ?, ?, ?)",
                    $this->commentId, $this->contactId, $this->emoji, $this->timeModified);
                if ($result && $result->insert_id) {
                    $this->reactionId = $result->insert_id;
                }
            }
        }

        if ($result && !Dbl::is_error($result)) {
            $acting_user->log_activity_for($this->contactId, "Comment {$this->commentId}: added reaction :{$this->emoji}:", $comment->paperId);
            return true;
        }
        return false;
    }

    /** @param Contact $acting_user
     * @param CommentInfo $comment
     * @return bool */
    function delete(Contact $acting_user, CommentInfo $comment) {
        if (!$this->reactionId) {
            return false;
        }

        $result = $this->conf->qe("DELETE FROM CommentReaction WHERE reactionId=?", $this->reactionId);
        if ($result && !Dbl::is_error($result)) {
            $acting_user->log_activity_for($this->contactId, "Comment {$this->commentId}: removed reaction :{$this->emoji}:", $comment->paperId);
            $this->reactionId = 0;
            return true;
        }
        return false;
    }

    /** @param int $commentId
     * @param Conf $conf
     * @return list<CommentReaction> */
    static function fetch_by_comment($commentId, Conf $conf) {
        $reactions = [];
        $result = $conf->qe("SELECT * FROM CommentReaction WHERE commentId=? ORDER BY emoji, timeModified", $commentId);
        while (($reaction = self::fetch($result, $conf))) {
            $reactions[] = $reaction;
        }
        Dbl::free($result);
        return $reactions;
    }
}
