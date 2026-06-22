<?php
// contactalerts.php -- HotCRP helper class for user messages
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ContactAlerts {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var list<object> */
    private $as = [];
    /** @var list<TokenInfo> */
    private $toks = [];
    /** @var array<string|int,?object> */
    private $changes = [];

    // an alert JSON has:
    // - token OR alertid
    // - message_list
    // - OPTIONAL scope
    // - OPTIONAL sensitive
    // - OPTIONAL dismissable
    // - OPTIONAL dismissed
    // - OPTIONAL name

    function __construct(Contact $u) {
        $this->conf = $u->conf;
        $this->user = $u;
        $this->load_alerts();
    }

    private function load_alerts() {
        foreach ($this->user->data("alerts") ?? [] as $a) {
            if (isset($a->expires_at) && $a->expires_at < Conf::$now) {
                $this->changes[$a->token ?? $a->alertid] = null;
            } else {
                $this->as[] = $a;
            }
        }
        $this->apply_changes();
    }

    function apply_changes() {
        if (!empty($this->changes)) {
            $this->user->modify_data([$this, "update_alerts"]);
            $this->changes = [];
            $this->load_alerts();
        }
    }

    function update_alerts($u) {
        $as = $aids = [];
        foreach ($u->data("alerts") ?? [] as $a) {
            $aid = $a->token ?? $a->alertid;
            if (array_key_exists($aid, $this->changes)) {
                $a = $this->changes[$aid];
            }
            if ($a) {
                $as[] = $a;
                $aids[] = $aid;
            }
        }
        foreach ($this->changes as $aid => $a) {
            if ($a && !in_array($aid, $aids, true)) {
                $as[] = $a;
            }
        }
        $u->set_data("alerts", empty($as) ? null : $as);
    }

    /** @return ?object */
    function find($alertid) {
        foreach ($this->as as $a) {
            if (($a->token ?? $a->alertid) == $alertid)
                return $a;
        }
        return null;
    }

    /** @return list<object> */
    function find_by_name($name) {
        $as = [];
        foreach ($this->as as $a) {
            if (($a->name ?? null) === $name && $name !== null)
                $as[] = $a;
        }
        return $as;
    }

    /** @return list<object> */
    function list() {
        return $this->as;
    }

    /** @param object $alert
     * @return list<MessageItem> */
    function message_list($alert) {
        $tok = isset($alert->token) ? $this->token($alert->token) : null;
        if ($tok) {
            $mlj = $tok->data("message_list");
        } else {
            $mlj = $alert->message_list ?? null;
        }
        $ml = [];
        foreach ($mlj ?? [] as $mj) {
            $ml[] = MessageItem::from_json($mj);
        }
        return $ml;
    }

    function append($alert, $iscdb = false) {
        if (!is_object($alert)
            || isset($alert->alertid)
            || isset($alert->token)
            || !is_array($alert->message_list ?? [])) {
            throw new Exception("bad ContactAlerts::append");
        }
        $iscdb = $iscdb || $this->user->is_cdb_user();
        if ($iscdb || strlen(json_encode($alert)) > 100) {
            $tok = new TokenInfo($this->conf, TokenInfo::ALERT);
            if ($iscdb && ($cdbu = $this->user->cdb_user())) {
                $tok->set_user_from($cdbu, true);
            } else {
                $tok->set_user_from($this->user, false);
            }
            if (is_int($alert->expires_at ?? null)) {
                $tok->set_expires_at($alert->expires_at);
            }
            $tok->assign_data($alert);
            $tok->set_token_pattern("hci{$tok->contactId}_[20]");
            $tok->insert();
            assert($tok->stored());
            $alert = clone $alert;
            unset($alert->message_list);
            $alert->token = $tok->salt;
            $this->toks[] = $tok;
        } else {
            $alert = clone $alert;
            $alert->alertid = base48_encode(random_bytes(10));
        }
        $this->changes[$alert->token ?? $alert->alertid] = $alert;
        $this->apply_changes();
    }

    function replace($alert) {
        if (!is_object($alert)
            || (!isset($alert->alertid) && !isset($alert->token))
            || !is_scalar($alert->alertid ?? "")
            || !is_string($alert->token ?? "")) {
            throw new Exception("bad ContactAlerts::replace");
        }
        $this->changes[$alert->token ?? $alert->alertid] = $alert;
        $this->apply_changes();
    }

    function dismiss($a) {
        if ($a->dismissed ?? false) {
            return;
        }
        $a = clone $a;
        $a->dismissed = true;
        if (!isset($a->expires_at) || $a->expires_at > Conf::$now + 604800 /* 1 week */) {
            $a->expires_at = Conf::$now + 604800;
            if (isset($a->token)
                && ($tok = $this->token($a->token))
                && (!$tok->timeExpires || $tok->timeExpires > $a->expires_at)) {
                $tok->set_expires_at($a->expires_at)->update();
            }
        }
        $this->replace($a);
    }

    function filter_scopes(...$scopes) {
        $as = [];
        foreach ($this->as as $a) {
            $scope = $a->scope ?? "home";
            $want = false;
            foreach (explode(" ", $scope) as $s) {
                if ($s === "all" || in_array($s, $scopes, true)) {
                    $as[] = $a;
                    break;
                }
            }
        }
        return $as;
    }

    /** @return list<array{string,int}> */
    function qreq_msg_content_list(Qrequest $qreq) {
        $scopes = [];
        if ($qreq->page() === "index") {
            $scopes[] = "home";
        } else if ($qreq->page() === "profile" && $qreq->annex("profile_self")) {
            $scopes[] = "profile";
        } else if ($qreq->page() === "search") {
            $scopes[] = "search";
        } else if ($qreq->page() === "paper" || $qreq->page() === "review" || $qreq->page() === "assign") {
            $scopes[] = "paper";
            if (($prow = $qreq->paper())) {
                $scopes[] = "paper-" . $prow->paperId;
            }
        }
        return $this->scoped_msg_content_list(...$scopes);
    }

    /** @return list<array{string,int}> */
    function scoped_msg_content_list(...$scopes) {
        $as = $salts = [];
        foreach ($this->filter_scopes(...$scopes) as $a) {
            if ((($a->sensitive ?? true)
                 && $this->user->is_actas_user()
                 && !$this->conf->opt("debugShowSensitiveEmail"))
                || ($a->dismissed ?? false)) {
                continue;
            }
            $as[] = $a;
            if (isset($a->token)) {
                $salts[] = $a->token;
            }
        }
        if (!empty($salts)) {
            $this->load_tokens($salts);
        }
        $mxs = [];
        foreach ($as as $a) {
            $ml = $this->message_list($a);
            $mx = Ht::fmt_feedback_msg_content($this->conf, $this->message_list($a));
            if (!$mx) {
                continue;
            }
            if (($a->dismissable ?? null) !== false) {
                $mx[0] = '<div class="d-flex">'
                    . preg_replace('/\A<ul class="feedback-list">/', '<ul class="feedback-list mb-0 flex-grow-1">', $mx[0])
                    . Ht::button("", ["class" => "btn-x btn-sm ui js-dismiss-alert need-tooltip ml-3 flex-grow-0", "aria-label" => "Delete this alert", "data-alertid" => $a->token ?? $a->alertid])
                    . '</div>';
            }
            $mxs[] = $mx;
        }
        if (!empty($this->changes)) {
            $this->apply_changes();
        }
        return $mxs;
    }

    /** @param string $salt
     * @param bool $no_load
     * @return ?TokenInfo */
    private function token($salt, $no_load = false) {
        foreach ($this->toks as $tok) {
            if ($tok->salt === $salt)
                return $tok;
        }
        if ($no_load) {
            return null;
        }
        $this->load_tokens([$salt]);
        return $this->token($salt, true);
    }

    /** @param list<string> $salts */
    private function load_tokens($salts) {
        $localsalts = $cdbsalts = [];
        foreach ($salts as $salt) {
            if (strpos($salt, "g_") !== false) {
                $cdbsalts[] = $salt;
            } else {
                $localsalts[] = $salt;
            }
        }
        $foundsalts = [];
        if (!empty($localsalts)) {
            $this->load_tokens_from($localsalts, $this->user->contactId, false, $foundsalts);
        }
        if (!empty($cdbsalts)
            && ($cdbu = $this->user->cdb_user())) {
            $this->load_tokens_from($cdbsalts, $cdbu->contactDbId, true, $foundsalts);
        }
        foreach (array_diff($salts, $foundsalts) as $salt) {
            $this->changes[$salt] = null;
        }
    }

    private function load_tokens_from($salts, $uid, $iscdb, &$found) {
        $dblink = $iscdb ? $this->conf->contactdb() : $this->conf->dblink;
        $result = Dbl::qe($dblink, "select * from Capability where capabilityType=? and contactId=? and salt?a",
            TokenInfo::ALERT, $uid, $salts);
        while (($tok = TokenInfo::fetch($result, $this->conf, $iscdb))) {
            if ($tok->is_active()) {
                $this->toks[] = $tok;
                $found[] = $tok->salt;
            }
        }
        $result->close();
    }
}
