<?php
// notificationinfo.php -- HotCRP helper class for notifications
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class NotificationInfo {
    /** @var Contact */
    public $user;
    /** @var int */
    public $flags = 0;
    /** @var ?string */
    public $text;

    const CONTACT = 1;
    const FOLLOW = 2;
    const MENTION = 4;
    const ATTEMPTED = 8;
    const SENT = 16;
    const PRETEND_SENT = 32;
    const CENSORED = 64;

    /** @param Contact $user
     * @param int $flags */
    function __construct($user, $flags) {
        $this->user = $user;
        $this->flags = $flags;
    }

    /** @param int $flags
     * @return bool */
    function is_all($flags) {
        return ($this->flags & $flags) === $flags;
    }

    /** @param int $flags
     * @return bool */
    function is($flags) {
        return ($this->flags & $flags) !== 0;
    }

    /** @param int $flags
     * @return bool
     * @deprecated */
    function has($flags) {
        return $this->is_all($flags);
    }

    /** @return bool
     * @deprecated */
    function sent() {
        return ($this->flags & self::SENT) !== 0;
    }
}
