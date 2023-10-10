<?php
// notificationinfo.php -- HotCRP helper class for notifications
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class NotificationInfo {
    /** @var Contact */
    public $user;
    /** @var int */
    public $types = 0;
    /** @var bool */
    public $sent = false;
    /** @var ?string */
    public $user_html;

    const CONTACT = 1;
    const FOLLOW = 2;
    const MENTION = 4;

    /** @param Contact $user
     * @param 0|1|2|4 $types */
    function __construct($user, $types) {
        $this->user = $user;
        $this->types = $types;
    }
}
