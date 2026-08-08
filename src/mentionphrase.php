<?php
// mentionphrase.php -- HotCRP mentionable
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class MentionPhrase implements JsonSerializable {
    /** @var Contact|Author
     * @readonly */
    public $user;
    /** @var int
     * @readonly */
    public $flags;
    /** @var int
     * @readonly */
    public $pos1;
    /** @var int
     * @readonly */
    public $pos2;

    // NB order matters; at TF_AUTHOR or above the user has a specific
    // relationship to the paper *that the viewer can view*
    const TF_NAMED = 1;
    const TF_PC = 2;
    const TF_AUTHOR = 4;
    const TF_REVIEWER = 8;
    const TF_SHEPHERD = 16;
    const TF_COMMENTER = 32;

    /** @param Contact|Author $user
     * @param int $flags
     * @param int $pos1
     * @param int $pos2 */
    function __construct($user, $flags, $pos1 = 0, $pos2 = 0) {
        $this->user = $user;
        $this->flags = $flags;
        $this->pos1 = $pos1;
        $this->pos2 = $pos2;
    }

    /** @param int $pos1
     * @param int $pos2
     * @return MentionPhrase */
    function at($pos1, $pos2) {
        return new MentionPhrase($this->user, $this->flags, $pos1, $pos2);
    }

    /** @return bool */
    function named() {
        return ($this->flags & self::TF_NAMED) !== 0;
    }

    /** @return bool */
    function is_notification_viewable(Contact $viewer, CommentInfo $crow) {
        return ($this->flags & self::TF_NAMED) !== 0
            && ($this->flags >= self::TF_AUTHOR
                || (($this->flags & self::TF_PC) !== 0
                    && $viewer->can_view_conflicts($crow->prow)));
    }

    /** @param 0|1 $sliced
     * @return ?Contact */
    function user(Conf $conf, $sliced = 0) {
        if ($this->user instanceof Contact) {
            return $this->user;
        }
        return $conf->user_by_id($this->user->contactId, $sliced);
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return [$this->user->contactId, $this->pos1, $this->pos2, $this->named()];
    }
}
