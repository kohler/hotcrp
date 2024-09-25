<?php
// notificationinfo.php -- HotCRP helper class for notifications
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class NotificationInfo {
    /** @var Contact */
    public $user;
    /** @var int */
    public $flags = 0;
    /** @var ?string */
    public $user_html;

    const CONTACT = 1;
    const FOLLOW = 2;
    const MENTION = 4;
    const SENT = 8;
    const CENSORED = 16;

    /** @param Contact $user
     * @param int $flags */
    function __construct($user, $flags) {
        $this->user = $user;
        $this->flags = $flags;
    }

    /** @param int $flags
     * @return bool */
    function has($flags) {
        return ($this->flags & $flags) === $flags;
    }

    /** @return bool */
    function sent() {
        return ($this->flags & self::SENT) !== 0;
    }
}
