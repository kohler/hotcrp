<?php
// usersecurityevent.php -- HotCRP representation of signins, etc.
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class UserSecurityEvent {
    /** @var ?string */
    public $email;
    /** @var ?int */
    public $uindex;
    /** @var 0|1|2 */
    public $type;
    /** @var ?string */
    public $subtype;
    /** @var 0|1 */
    public $reason;
    /** @var bool */
    public $success;
    /** @var int */
    public $timestamp;

    const TYPE_PASSWORD = 0;
    const TYPE_OAUTH = 1;
    const TYPE_TOTP = 2;

    const REASON_SIGNIN = 0;
    const REASON_REAUTH = 1;

    /** @param string $email
     * @param 0|1|2 $type
     * @param 0|1 $reason
     * @return UserSecurityEvent */
    static function make($email, $type = 0, $reason = 0) {
        $use = new UserSecurityEvent;
        $use->email = $email;
        $use->type = $type;
        $use->reason = $reason;
        $use->success = true;
        $use->timestamp = Conf::$now;
        return $use;
    }

    /** @param string $email
     * @return $this */
    function set_email($email) {
        assert(!!$email);
        $this->email = $email;
        return $this;
    }

    /** @param 0|1 $reason
     * @return $this */
    function set_reason($reason) {
        $this->reason = $reason;
        return $this;
    }

    /** @param ?string $subtype
     * @return $this */
    function set_subtype($subtype) {
        $this->subtype = $subtype;
        return $this;
    }

    /** @param bool $success
     * @return $this */
    function set_success($success) {
        $this->success = $success;
        return $this;
    }

    /** @param array $x
     * @return UserSecurityEvent */
    static function make_array($x) {
        // See `etc/devel/sessions.md` for format information
        $use = new UserSecurityEvent;
        if (isset($x["e"])) {
            $use->email = $x["e"];
            $use->uindex = -1;
        } else {
            $use->uindex = $x["u"] ?? 0;
        }
        $use->type = $x["t"] ?? 0;
        $use->subtype = $x["s"] ?? null;
        $use->reason = $x["r"] ?? 0;
        $use->success = !($x["x"] ?? false);
        $use->timestamp = $x["a"];
        return $use;
    }

    /** @return array{a:int} */
    function as_array() {
        assert(($this->uindex ?? -1) >= 0 || $this->email);
        $x = [];
        if (($this->uindex ?? -1) < 0) {
            $x["e"] = $this->email;
        } else if ($this->uindex > 0) {
            $x["u"] = $this->uindex;
        }
        if ($this->type !== 0) {
            $x["t"] = $this->type;
        }
        if ($this->subtype !== null) {
            $x["s"] = $this->subtype;
        }
        if ($this->reason !== 0) {
            $x["r"] = $this->reason;
        }
        if (!$this->success) {
            $x["x"] = true;
        }
        $x["a"] = $this->timestamp;
        return $x;
    }


    /** @param Qsession $qs
     * @return Generator<UserSecurityEvent> */
    static function session_list($qs) {
        foreach ($qs->get("usec") ?? [] as $x) {
            yield UserSecurityEvent::make_array($x);
        }
    }

    /** @param string $email
     * @return Generator<UserSecurityEvent> */
    static function session_list_by_email(Qsession $qs, $email) {
        $uindex = Contact::session_index_by_email($qs, $email);
        foreach ($qs->get("usec") ?? [] as $x) {
            if (isset($x["e"])
                ? strcasecmp($x["e"], $email) !== 0
                : ($x["u"] ?? 0) !== $uindex) {
                continue;
            }
            yield UserSecurityEvent::make_array($x);
        }
    }

    /** @param string $email
     * @return ?UserSecurityEvent */
    static function session_latest_signin_by_email(Qsession $qs, $email) {
        $signin = null;
        foreach (self::session_list_by_email($qs, $email) as $use) {
            if ($use->reason === self::REASON_SIGNIN)
                $signin = $use;
        }
        return $signin;
    }


    function store(Qsession $qs) {
        assert(isset($this->email));
        $uindex = Contact::session_index_by_email($qs, $this->email);
        assert(($this->uindex ?? -1) < 0 || $this->uindex === $uindex);
        $this->uindex = $uindex;
        $this->timestamp = $this->timestamp ?? Conf::$now;

        $nusec = count($qs->get("usec") ?? []);
        $result = [];
        foreach (self::session_list($qs) as $use) {
            // skip old reauths
            if ($use->reason === self::REASON_REAUTH
                && $use->timestamp < Conf::$now - 86400) {
                continue;
            }
            // if lots of results, drop old failures
            if ($nusec >= 150
                && !$use->success
                && $use->timestamp < Conf::$now - 900) {
                continue;
            }
            // update uindex
            if ($use->uindex < 0
                && $this->uindex >= 0
                && strcasecmp($use->email, $this->email) === 0) {
                $use->uindex = $this->uindex;
            }
            // success clears out previous matches
            if ($this->success
                && ($this->uindex >= 0
                    ? $this->uindex === $use->uindex
                    : $use->email !== null && strcasecmp($this->email, $use->email) === 0)
                && $this->type === $use->type
                && $this->subtype === $use->subtype
                && $this->reason === $use->reason) {
                continue;
            }
            $result[] = $use->as_array();
        }

        // add self
        $result[] = $this->as_array();
        $qs->set("usec", $result);
    }


    /** @param Qsession $qs
     * @param list<string> $us */
    static private function session_user_set($qs, $us) {
        while (!empty($us) && $us[count($us) - 1] === "") {
            array_pop($us);
        }
        if (empty($us)) {
            $qs->unset("us");
            $qs->unset("u");
            return;
        }
        if (count($us) > 1) {
            $qs->set("us", $us);
        } else {
            $qs->unset("us");
        }
        $i = 0;
        while ($us[$i] === "") {
            ++$i;
        }
        $qs->set("u", $us[$i]);
    }

    /** @param Qsession $qs
     * @param string $email
     * @return int */
    static function session_user_add($qs, $email) {
        $us = Contact::session_emails($qs);
        $empty = null;
        for ($ui = 0; $ui !== count($us); ++$ui) {
            if ($us[$ui] === "") {
                $empty = $empty ?? $ui;
            } else if (strcasecmp($us[$ui], $email) === 0) {
                break;
            }
        }
        if ($ui === count($us) && $empty !== null) {
            $ui = $empty;
        }
        $us[$ui] = $email;
        self::session_user_set($qs, $us);
        return $ui;
    }

    /** @param Qsession $qs
     * @param string $email */
    static function session_user_remove($qs, $email) {
        $us = Contact::session_emails($qs);
        for ($ui = 0; $ui !== count($us); ++$ui) {
            if (strcasecmp($us[$ui], $email) === 0) {
                $us[$ui] = "";
                break;
            }
        }
        self::session_user_set($qs, $us);

        // remove now-irrelevant `usec` entries
        $usec = [];
        foreach ($qs->get("usec") ?? [] as $x) {
            if (isset($x["e"]) ? strcasecmp($x["e"], $email) === 0 : ($x["u"] ?? 0) === $ui) {
                continue;
            }
            $usec[] = $x;
        }
        $qs->set("usec", $usec);
    }
}
