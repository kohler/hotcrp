<?php
// usersecurityevent.php -- HotCRP representation of signins, etc.
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class UserSecurityEvent {
    /** @var ?string */
    public $email;
    /** @var ?int */
    public $uindex;
    /** @var 0|1|2 */
    public $type;
    /** @var ?string */
    public $subtype;
    /** @var 0|1|2 */
    public $reason;
    /** @var ?string */
    public $client_id;
    /** @var ?string */
    public $redirect_uri;
    /** @var ?string */
    public $scope;
    /** @var ?string */
    public $dbname;
    /** @var bool */
    public $success;
    /** @var int */
    public $timestamp;

    const TYPE_PASSWORD = 0;
    const TYPE_OAUTH = 1;
    const TYPE_TOTP = 2;

    const REASON_SIGNIN = 0;
    const REASON_REAUTH = 1;
    /** An authorization this account granted an OAuth client. This is not an
     * authentication and must never satisfy one: `AuthenticationChecker`
     * matches the authentication reasons positively, so this reason is
     * excluded by construction. */
    const REASON_OAUTH_CONFIRM = 2;

    /** Most OAuth confirmations kept in one session. */
    const MAX_OAUTH_CONFIRMATIONS = 200;

    /** How long an OAuth confirmation is worth keeping.
     *
     * Unlike `AuthenticationChecker::MAX_AGE_BOUND` this has no standard behind
     * it: an authorization is a decision that stands, not an authentication
     * whose freshness is being judged, and other providers keep grants until
     * they are revoked. Collection happens only when something else is stored,
     * so this is a target rather than a bound — what actually limits a
     * confirmation's life is the session's, and `MAX_OAUTH_CONFIRMATIONS`. */
    const OAUTH_CONFIRMATION_LIFETIME = 15552000; // 180 days

    /** @param string $email
     * @param 0|1|2 $type
     * @param 0|1|2 $reason
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

    /** @param 0|1|2 $reason
     * @return $this */
    function set_reason($reason) {
        $this->reason = $reason;
        return $this;
    }

    /** Record what this account authorized a client to do.
     *
     * `$client_id` identifies the client the user saw named; `$redirect_uri`
     * is where its codes may be delivered, which matters because a metadata
     * document client can change its redirect URIs under a stable id; and
     * `$scope` is what was granted, without which a replay could widen it.
     * `$dbname` is the conference the authorization was granted at, or null
     * for a cdb client, whose grant is cross-conference by design. A normal
     * client's token reaches one conference's submissions and reviews, so a
     * consent given at one must not answer for another.
     * @param string $client_id
     * @param string $redirect_uri
     * @param ?string $scope
     * @param ?string $dbname
     * @return $this */
    function set_oauth_confirmation($client_id, $redirect_uri, $scope, $dbname) {
        $this->reason = self::REASON_OAUTH_CONFIRM;
        $this->client_id = $client_id;
        $this->redirect_uri = $redirect_uri;
        $this->scope = $scope;
        $this->dbname = $dbname;
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
        $use->client_id = $x["ci"] ?? null;
        $use->redirect_uri = $x["ru"] ?? null;
        $use->scope = $x["sc"] ?? null;
        $use->dbname = $x["db"] ?? null;
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
        if ($this->client_id !== null) {
            $x["ci"] = $this->client_id;
        }
        if ($this->redirect_uri !== null) {
            $x["ru"] = $this->redirect_uri;
        }
        if ($this->scope !== null) {
            $x["sc"] = $this->scope;
        }
        if ($this->dbname !== null) {
            $x["db"] = $this->dbname;
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
     * @param bool $reverse
     * @return Generator<UserSecurityEvent> */
    static function session_list_by_email(Qsession $qs, $email, $reverse = false) {
        $uindex = Contact::session_index_by_email($qs, $email);
        $usec = $qs->get("usec") ?? [];
        $n = count($usec);
        for ($i = $reverse ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $reverse ? -1 : 1) {
            $x = $usec[$i];
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
        foreach (self::session_list_by_email($qs, $email, true) as $use) {
            if ($use->reason === self::REASON_SIGNIN)
                return $use;
        }
        return null;
    }


    function store(Qrequest $qreq) {
        assert(isset($this->email));
        $qs = $qreq->qsession();
        $uindex = Contact::session_index_by_email($qs, $this->email);
        assert(($this->uindex ?? -1) < 0 || $this->uindex === $uindex);
        $this->uindex = $uindex;
        $this->timestamp = $this->timestamp ?? Conf::$now;

        $nusec = count($qs->get("usec") ?? []);
        $result = [];
        $oauth_confirmation_indexes = [];
        foreach (self::session_list($qs) as $use) {
            // skip old reauths (they always expire after MAX_AGE_BOUND)
            if ($use->reason === self::REASON_REAUTH
                && $use->timestamp < Conf::$now - AuthenticationChecker::MAX_AGE_BOUND) {
                continue;
            }
            // collect stale confirmations; a caller must tolerate losing one
            // at any time, so this is collection rather than enforcement
            if ($use->reason === self::REASON_OAUTH_CONFIRM
                && $use->timestamp < Conf::$now - self::OAUTH_CONFIRMATION_LIFETIME) {
                continue;
            }
            // drop old failures; drop them quickly if lots of results
            if (!$use->success
                && $use->timestamp < Conf::$now - ($nusec >= 150 ? 900 : AuthenticationChecker::MAX_AGE_BOUND)) {
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
                && $this->reason === $use->reason
                && $this->client_id === $use->client_id
                && $this->dbname === $use->dbname) {
                continue;
            }
            // remember OAuth confirmation positions so we can trim
            if ($use->reason === self::REASON_OAUTH_CONFIRM) {
                $oauth_confirmation_indexes[] = count($result);
            }
            $result[] = $use->as_array();
        }

        // add self
        if ($this->reason === self::REASON_OAUTH_CONFIRM) {
            $oauth_confirmation_indexes[] = count($result);
        }
        $result[] = $this->as_array();

        // keep at most MAX_OAUTH_CONFIRMATIONS, dropping the oldest ones
        // (traverse backward to avoid having to account for shifts)
        for ($ri = count($oauth_confirmation_indexes) - self::MAX_OAUTH_CONFIRMATIONS - 1;
             $ri >= 0;
             --$ri) {
            array_splice($result, $oauth_confirmation_indexes[$ri], 1);
        }

        $qreq->set_gsession("usec", $result);
    }


    /** The authorization this account granted `$client_id`, or null.
     *
     * The match is exact on client, delivery destination, and scope. A consent
     * is for what the user was shown: a client that now wants a different
     * scope, or wants its codes delivered somewhere else, has to ask again.
     * @param string $email
     * @param string $client_id
     * @param string $redirect_uri
     * @param ?string $scope
     * @param ?string $dbname
     * @return ?UserSecurityEvent */
    static function session_oauth_confirmation(Qsession $qs, $email, $client_id,
                                               $redirect_uri, $scope, $dbname) {
        foreach (self::session_list_by_email($qs, $email, true) as $use) {
            if ($use->reason === self::REASON_OAUTH_CONFIRM
                && $use->success
                && $use->client_id === $client_id
                && $use->redirect_uri === $redirect_uri
                && $use->scope === $scope
                && $use->dbname === $dbname) {
                return $use;
            }
        }
        return null;
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
