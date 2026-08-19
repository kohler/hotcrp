<?php
// u_developer.php -- HotCRP Profile > Developer
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class Developer_UserInfo {
    /** @var UserStatus */
    private $us;
    /** @var ?TokenInfo */
    private $_new_token;
    /** @var ?list<TokenInfo> */
    private $_recent_tokens;
    /** @var list<array{int,bool,string}> */
    private $_delete_tokens = [];
    /** @var list<string> */
    private $_delete_grants = [];

    function __construct(UserStatus $us) {
        $this->us = $us;
    }

    /** Whether whoever is looking may hold this account's credentials.
     *
     * Normally only the account itself: an administrator must not be able to
     * mint a credential that speaks as a person. A bot is the exception, and
     * not because chairs deserve more power — because a bot has no person to
     * impersonate and no way to sign in and mint one for itself. Someone has to,
     * and the chair is who the conference already trusts with the account.
     * @return bool */
    private function is_token_principal() {
        // `$us->user` is null while a new account is being requested
        $u = $this->us->user;
        return $this->us->is_auth_self()
            || ($u && $u->is_bot() && $this->us->viewer->privChair);
    }

    function display_if() {
        if ($this->us->is_actas_self()) {
            return "dim";
        }
        return $this->is_token_principal();
    }

    function allow() {
        return $this->is_token_principal() && !$this->us->user->security_locked();
    }

    function request() {
        if ($this->allow()) {
            $this->us->request_group("developer");
        }
    }

    function save() {
        if ($this->allow()) {
            $this->us->save_members("developer");
        }
    }

    /** @param TokenInfo $tok
     * @return bool */
    static function is_recent($tok) {
        $t = $tok->inactive_at();
        return $t <= 0 || $t > Conf::$now - 5 * 86400;
    }

    /** @param ?Contact $user */
    private function _add_recent_tokens_at($user) {
        if (!$user) {
            // no contactdb, or no account there
            return;
        }
        $is_cdb = $user->is_cdb_user();
        $uid = $is_cdb ? $user->contactDbId : $user->contactId;
        if ($uid <= 0) {
            return;
        }
        $dblink = $is_cdb ? $user->conf->contactdb() : $user->conf->dblink;
        $result = Dbl::qe($dblink, "select * from Capability where capabilityType?a and contactId=?", [TokenInfo::BEARER, TokenInfo::OAUTHREFRESH], $uid);
        while (($tok = TokenInfo::fetch($result, $user->conf, $is_cdb))) {
            if (self::is_recent($tok))
                $this->_recent_tokens[] = $tok;
        }
        Dbl::free($result);
    }

    private function recent_tokens() {
        if ($this->_recent_tokens === null) {
            $this->_recent_tokens = [];
            $this->_add_recent_tokens_at($this->us->user);
            $this->_add_recent_tokens_at($this->us->user->cdb_user());
        }
        return $this->_recent_tokens;
    }

    /** Tokens this user made by hand. A token an OAuth client was issued
     * carries a `client_id`; it belongs to a grant, not to this list, and
     * deleting one there would achieve nothing—the client’s next refresh
     * replaces it.
     * @return list<TokenInfo> */
    function recent_bearer_tokens() {
        $toks = [];
        foreach ($this->recent_tokens() as $tok) {
            if ($tok->capabilityType === TokenInfo::BEARER
                && $tok->data("client_id") === null)
                $toks[] = $tok;
        }
        return $toks;
    }

    /** Return this user’s OAuth grants, one entry per client, most recently
     * used first. Every token of a grant is included: an access token, the
     * refresh token that renews it, and the spent links of the rotation chain,
     * which have to be revoked together or not at all.
     * @return list<object> */
    function recent_grants() {
        $toks = $this->recent_tokens();
        // Oldest first, so that “the last one wins” means the newest: one
        // `client_id` covers every authorization of that client, and the query
        // that produced these imposed no order. Tokens minted in the same
        // second tie, so break by salt: an arbitrary winner is fine, a
        // different winner on every page load is not.
        usort($toks, function ($a, $b) {
            return ($a->timeCreated <=> $b->timeCreated)
                ? : strcmp($a->salt, $b->salt);
        });
        $grants = [];
        foreach ($toks as $tok) {
            if (!$tok->is_active()
                || ($client_id = $tok->data("client_id")) === null) {
                continue;
            }
            $key = ($tok->is_cdb ? "A" : "L") . $client_id;
            $g = $grants[$key] ?? null;
            if (!$g) {
                $g = $grants[$key] = (object) [
                    "client_id" => $client_id, "is_cdb" => $tok->is_cdb,
                    "name" => null, "tokens" => [],
                    "timeCreated" => $tok->timeCreated, "timeUsed" => 0,
                    "scopes" => [], "max_scopes" => []
                ];
            }
            $g->tokens[] = $tok;
            $g->name = $tok->data("client_name") ?? $g->name;
            $g->timeCreated = min($g->timeCreated, $tok->timeCreated);
            $g->timeUsed = max($g->timeUsed, $tok->timeUsed);
            if ($tok->capabilityType === TokenInfo::BEARER) {
                // an access token records the scope actually granted
                $x = $tok->data("scope") ?? "";
                if (!in_array($x, $g->scopes, true)) {
                    $g->scopes[] = $x;
                }
            } else {
                // a refresh token records the scope *requested*; the grant is
                // that capped by the client, so this is an upper bound. It is
                // all there is once the access token’s row has been dropped,
                // which happens well before the refresh token’s.
                [, $x] = TokenScope::scope_str_split_openid($tok->data("scope"));
                if (!in_array($x, $g->max_scopes, true)) {
                    $g->max_scopes[] = $x;
                }
            }
        }
        $grants = array_values($grants);
        usort($grants, function ($a, $b) {
            return ($b->timeUsed <=> $a->timeUsed)
                ? : ($b->timeCreated <=> $a->timeCreated);
        });
        return $grants;
    }

    function print_bearer_tokens(UserStatus $us) {
        if (!$this->is_token_principal()) {
            $us->conf->warning_msg('<0>API tokens cannot be edited when acting as another user.');
            return false;
        }
        echo '<p class="w-text">API tokens let you access <a href="https://hotcrp.com/devel/api/">HotCRP’s API</a> programmatically. Supply a token using an HTTP <code>Authorization</code> header, as in “<code>Authorization: Bearer <em>token-name</em></code>”.</p>';
        if ($us->is_auth_self()) {
            $us->conf->warning_msg('<0>Treat tokens like passwords and keep them secret. Anyone who knows your tokens can access this site with your privileges.');
        } else {
            $us->conf->warning_msg('<0>Treat tokens like passwords and keep them secret. Anyone who knows this bot account’s tokens can act as it on this site. The bot cannot sign in; these tokens are the only way it reaches the site, and the only way to stop it is to delete them.');
        }
    }

    function print_current_bearer_tokens(UserStatus $us) {
        $toks = $this->recent_bearer_tokens();
        usort($toks, function ($a, $b) {
            $aa = $a->is_active();
            if ($aa !== $b->is_active()) {
                return $aa ? -1 : 1;
            }
            $au = floor($a->timeUsed / 86400);
            $bu = floor($b->timeUsed / 86400);
            if (($au > 0) !== ($bu > 0)) {
                return $au > 0 ? -1 : 1;
            }
            return ($bu <=> $au) ? : ($a->timeCreated <=> $b->timeCreated);
        });

        if (empty($toks)) {
            return;
        }
        Icons::stash_defs("trash");
        echo '<div class="mt-4">', Ht::unstash();
        $n = 1;
        foreach ($toks as $tok) {
            if ($tok->timeCreated >= Conf::$now - 10) {
                $this->print_fresh_bearer_token($us, $tok, $n);
            } else {
                $this->print_bearer_token($us, $tok, $n);
            }
            ++$n;
        }
        echo '</div>';
    }

    /** @param int $n */
    private function print_bearer_token_deleter(UserStatus $us, TokenInfo $tok, $n) {
        if (!$this->is_token_principal()
            || $us->user->security_locked()
            || !$us->has_recent_authentication()
            || !$tok->is_active()) {
            return;
        }
        $dbid = $tok->is_cdb ? "A" : "L";
        $id = "{$tok->timeCreated}.{$dbid}." . substr($tok->salt, 0, 12);
        echo Ht::hidden("bearer_token/{$n}/id", $id),
            Ht::hidden("bearer_token/{$n}/delete", "", ["class" => "deleter", "data-default-value" => ""]),
            Ht::button(Icons::ui_use("trash", "m"), [
                "class" => "ml-3 btn-licon-s ui js-profile-token-delete need-tooltip",
                "aria-label" => "Delete API token"
            ]);
        $us->mark_inputs_printed();
    }

    /** @param int $n */
    private function print_bearer_token(UserStatus $us, TokenInfo $tok, $n) {
        $active = $tok->is_active();
        $note = $tok->data("note") ?? "";
        echo '<div class="f-i w-text"><div class="f-c">';
        if ($note !== "") {
            echo htmlspecialchars($note), ' <span class="barsep">·</span> ';
        }
        $short_salt = '<code>' . $tok->abbreviation(TokenInfo::ABBREVIATION_ELLIPSIS) . '</code>';
        if ($active) {
            echo $short_salt;
            $this->print_bearer_token_deleter($us, $tok, $n);
        } else {
            echo '<del>', $short_salt, '</del>',
                '<em class="pl-3">(recently expired)</em>';
        }
        echo '</div>';
        $this->print_bearer_token_info($us->conf, $tok);
        echo '</div>';
    }

    private function print_bearer_token_info(Conf $conf, TokenInfo $tok) {
        $ts = TokenScope::parse($tok->data("scope") ?? "", null);
        if ($ts) {
            $tsu = htmlspecialchars(TokenScope::unparse($ts));
            if ($tsu === "read" || $tsu === "write" || $tsu === "admin") {
                echo $tsu, ' scope';
            } else {
                echo 'scope ', $tsu;
            }
        } else {
            echo 'full scope';
        }
        echo ' <span class="barsep">·</span> ',
            self::unparse_last_used($tok->timeUsed),
            ' <span class="barsep">·</span> ';
        $invt = $tok->inactive_at();
        if ($invt <= 0) {
            echo "never expires";
        } else if ($invt <= Conf::$now) {
            echo "expired ", $conf->unparse_time_point($invt);
        } else {
            echo "expires ", $conf->unparse_time_point($invt);
        }
    }

    /** @param int $n */
    function print_fresh_bearer_token(UserStatus $us, TokenInfo $tok, $n) {
        $note = $tok->data("note") ?? "";
        echo '<div class="form-section form-outline-section mb-4 tag-yellow">',
            '<div class="f-i w-text mb-0"><div class="f-c">';
        if ($note !== "") {
            echo htmlspecialchars($note), ' <span class="barsep">·</span> ';
        }
        echo '<code><strong>', $tok->salt, '</strong></code>';
        // $this->print_bearer_token_deleter($us, $tok, $n);
        echo '</div>',
            '<p class="feedback is-urgent-note">This is the new token you just created. Copy it now—you won’t be able to recover it later.</p>',
            '<p class="w-text mb-0">';
        $this->print_bearer_token_info($us->conf, $tok);
        echo '</p></div></div>';
    }

    function print_grants(UserStatus $us) {
        $grants = $this->recent_grants();
        if (empty($grants)) {
            return;
        }
        $us->print_start_section("Connected applications");
        echo '<p class="w-text">These applications may act on your behalf using ',
            'HotCRP’s API. Disconnecting one revokes every token it holds; ',
            'it can ask for your authorization again.</p>';
        Icons::stash_defs("trash");
        echo '<div class="mt-4">', Ht::unstash();
        $n = 1;
        foreach ($grants as $g) {
            $this->print_grant($us, $g, $n);
            ++$n;
        }
        echo '</div>';
    }

    /** @param object $g
     * @param int $n */
    private function print_grant(UserStatus $us, $g, $n) {
        $name = $g->name ?? $g->client_id;
        echo '<div class="f-i w-text"><div class="f-c">',
            htmlspecialchars($name);
        if ($this->is_token_principal()
            && !$us->user->security_locked()
            && $us->has_recent_authentication()) {
            echo Ht::hidden("grant/{$n}/id", ($g->is_cdb ? "A." : "L.") . $g->client_id),
                Ht::hidden("grant/{$n}/delete", "", ["class" => "deleter", "data-default-value" => ""]),
                Ht::button(Icons::ui_use("trash", "m"), [
                    "class" => "ml-3 btn-licon-s ui js-profile-token-delete need-tooltip",
                    "aria-label" => "Disconnect application"
                ]);
            $us->mark_inputs_printed();
        }
        echo '</div>';
        // the identifier the action log and the server's access log both name
        $gids = [];
        foreach ($g->tokens as $tok) {
            if (($gid = Authorization_Token::grant_id($tok))
                && !in_array($gid, $gids, true)) {
                $gids[] = $gid;
            }
        }
        if (!empty($gids)) {
            sort($gids);
            echo '<code>', htmlspecialchars(join(" ", $gids)), '</code>',
                ' <span class="barsep">·</span> ';
        }
        echo plural(count($g->tokens), "token"),
            ' <span class="barsep">·</span> ',
            self::unparse_grant_scope($g),
            ' <span class="barsep">·</span> ',
            self::unparse_last_used($g->timeUsed),
            ' <span class="barsep">·</span> authorized ',
            $us->conf->unparse_time_point($g->timeCreated),
            '</div>';
    }

    /** @param object $g
     * @return string */
    static private function unparse_grant_scope($g) {
        $approx = empty($g->scopes);
        $ss = $approx ? $g->max_scopes : $g->scopes;
        if (empty($ss)) {
            return "scope unknown";
        }
        $a = [];
        foreach ($ss as $s) {
            // `parse` returns null for `all`, which is full scope, and for the
            // empty string, which is no information at all; those must not
            // print the same thing
            $ts = $s === "" ? null : TokenScope::parse($s, null);
            $x = $s === "" ? "none" : ($ts ? TokenScope::unparse($ts) : "all");
            if (!in_array($x, $a, true)) {
                $a[] = $x;
            }
        }
        if (count($a) > 1) {
            $t = "scopes " . htmlspecialchars(join(", ", $a));
        } else if ($a[0] === "all") {
            $t = "full scope";
        } else if ($a[0] === "none") {
            // an upper bound of nothing is not approximate
            return "no API access";
        } else {
            $t = "scope " . htmlspecialchars($a[0]);
        }
        return $approx ? "at most {$t}" : $t;
    }

    function request_delete_grants(UserStatus $us) {
        for ($i = 1; isset($us->qreq["grant/{$i}/id"]); ++$i) {
            if (friendly_boolean($us->qreq["grant/{$i}/delete"])) {
                $this->_delete_grants[] = $us->qreq["grant/{$i}/id"];
            }
        }
    }

    function save_delete_grants(UserStatus $us) {
        if (empty($this->_delete_grants)) {
            return;
        }
        $gids = [];
        foreach ($this->recent_grants() as $g) {
            if (!in_array(($g->is_cdb ? "A." : "L.") . $g->client_id, $this->_delete_grants, true)) {
                continue;
            }
            foreach ($g->tokens as $tok) {
                if (!$tok->is_active()) {
                    continue;
                }
                $gid = Authorization_Token::grant_id($tok) ?? "<unknown>";
                if (!in_array($gid, $gids, true)) {
                    $gids[] = $gid;
                }
                // Keep the rows: a revoked refresh token presented later is the
                // replay signal, and the rotation chain is walked through them.
                $tok->set_invalid()
                    ->set_expires_in(Authorization_Token::BEARER_RETENTION)
                    ->update();
            }
        }
        if (!empty($gids)) {
            sort($gids);
            $us->diffs["connected applications"] = "revoked " . join(" ", $gids);
        }
    }

    static function unparse_last_used($time) {
        if ($time <= 0) {
            return "never used";
        } else if ($time + 31622400 < Conf::$now) { // 366 days
            return "last used more than a year ago";
        } else if ($time + 2592000 < Conf::$now) { // 30 days
            return "used in the last " . plural(min(ceil((Conf::$now - $time) / 2592000), 12), "month");
        } else if ($time + 86400 < Conf::$now) {
            return "used in the last " . plural(ceil((Conf::$now - $time) / 86400), "day");
        }
        return "used in the last day";
    }

    function print_new_bearer_token(UserStatus $us) {
        if (!$this->is_token_principal()
            || !$us->has_recent_authentication()) {
            return;
        } else if ($us->user->security_locked()) {
            $us->conf->warning_msg("<0>This account’s security settings are locked, so its API tokens cannot be changed.");
            return;
        }

        echo Ht::button("Add token", ["class" => "ui js-profile-token-add mt-4"]);

        $us->cs()->add_section_class("hidden");
        $us->print_start_section("New API token");
        echo Ht::hidden("bearer_token/new/enable", "", ["data-default-value" => ""]);

        $us->print_field("bearer_token/new/note", "Note",
            Ht::entry("bearer_token/new/note", $us->qreq["bearer_token/new/note"] ?? "", [
                "size" => 52, "id" => "bearer_token/new/note", "data-default-value" => "",
                "class" => "want-focus"
            ]) . '<div class="f-d">What’s this token for?</div>');

        $us->print_field("bearer_token/new/expiration", "Expiration",
            Ht::select("bearer_token/new/expiration", [
                "7" => "7 days",
                "30" => "30 days",
                "90" => "90 days",
                "never" => "No expiration"
            ], $us->qreq["bearer_token/new/expiration"] ?? "30", [
                "id" => "bearer_token/new/expiration", "data-default-value" => "30"
            ]));

        if ($us->conf->contactdb()) {
            $us->print_field("bearer_token/new/sites", "Site availability",
                Ht::select("bearer_token/new/sites", [
                    "all" => "All sites",
                    "here" => "This site only"
                ], $us->qreq["bearer_token/new/sites"] ?? "all", [
                    "id" => "bearer_token/new/sites", "data-default-value" => "all"
                ]));
        }

        $default_scope = self::default_token_scope($us->user);
        $us->print_field("bearer_token/new/scope", "Scope",
            Ht::entry("bearer_token/new/scope", $us->qreq["bearer_token/new/scope"] ?? $default_scope, [
                "size" => 30, "id" => "bearer_token/new/scope",
                "data-default-value" => $default_scope,
                "placeholder" => "all"
            ]) . '<div class="f-d">What rights should this token have?<br>Examples: ‘read’, ‘read paper:write’, ‘review:admin#10’, ‘submission:write?q=dec:no’</div>');

        $us->cs()->print_end_section();
    }

    /** The scope a new token starts with.
     *
     * A person's token defaults to their whole account, which they are watching.
     * A bot's is a credential nobody watches, held by something that acts on its
     * own, so it starts at what a reviewing bot needs and the chair widens it
     * deliberately. This is a default, not a limit.
     * @param ?Contact $user
     * @return string */
    static function default_token_scope($user) {
        return $user && $user->is_bot() ? "read review:write" : "";
    }

    function request_new_bearer_token(UserStatus $us) {
        assert($this->is_token_principal());
        $cdbu = $us->user->cdb_user();
        if (!$us->qreq["bearer_token/new/enable"]
            || $us->user->security_locked()
            || ($cdbu && $cdbu->security_locked())) {
            return;
        }

        $sites = $us->qreq["bearer_token/new/sites"] ?? "here";
        if ($sites === "all" && $cdbu) {
            $tuser = $cdbu;
        } else {
            $us->user->ensure_account_here();
            $tuser = $us->user;
        }

        $exp = $us->qreq["bearer_token/new/expiration"] ?? "30";
        if (($ndays = stonum($exp)) !== null) {
            $expiry = (int) ($ndays * 86400);
        } else {
            $expiry = (int) round(SettingParser::parse_duration($exp) ?? 30 * 86400);
        }
        $token = Authorization_Token::prepare_bearer($tuser, $expiry);
        $this->_new_token = $token;

        $note = simplify_whitespace(convert_to_utf8($us->qreq["bearer_token/new/note"] ?? ""));
        if ($note !== "") {
            $token->change_data("note", $note);
        }

        $scope = simplify_whitespace($us->qreq["bearer_token/new/scope"] ?? "");
        if ($scope !== ""
            && preg_match('/\A(?:[a-z][!\#-\x5b\x5d-~]*+\s*+)++\z/', $scope)) {
            $token->change_data("scope", $scope);
        }
    }

    function save_new_bearer_token(UserStatus $us) {
        if ($this->_new_token === null) {
            return;
        }
        $this->_new_token->insert();
        if ($this->_new_token->stored()) {
            self::add_token_diff($us, "created " . $this->_new_token->abbreviation());
        } else {
            $us->error_at(null, "<0>Error while creating new API token");
            $this->_new_token = null;
        }
    }

    function request_delete_bearer_tokens(UserStatus $us) {
        assert($this->is_token_principal());
        if ($us->user->security_locked()) {
            return;
        }
        for ($i = 1; isset($us->qreq["bearer_token/{$i}/id"]); ++$i) {
            if (preg_match('/\A(\d+)\.([AL])\.(hc[tT]_\w+)\z/', $us->qreq["bearer_token/{$i}/id"], $m)
                && friendly_boolean($us->qreq["bearer_token/{$i}/delete"])) {
                $this->_delete_tokens[] = [intval($m[1]), $m[2] === "A", $m[3]];
            }
        }
    }

    /** Record a token change for the action log. One save can both create and
     * revoke, so entries accumulate rather than replace.
     * @param string $text */
    static private function add_token_diff(UserStatus $us, $text) {
        $old = $us->diffs["API tokens"] ?? null;
        $us->diffs["API tokens"] = is_string($old) && $old !== ""
            ? "{$old}, {$text}" : $text;
    }

    function save_delete_bearer_tokens(UserStatus $us) {
        if ($this->_delete_tokens === null) {
            return;
        }
        $toks = $this->recent_bearer_tokens();
        $deleteables = [];
        foreach ($toks as $tok) {
            foreach ($this->_delete_tokens as $dt) {
                if ($tok->timeCreated === $dt[0]
                    && $tok->is_cdb === $dt[1]
                    && str_starts_with($tok->salt, $dt[2])
                    && $tok->is_active()) {
                    $deleteables[] = $tok;
                }
            }
        }
        if (!empty($deleteables)
            && count($deleteables) <= count($this->_delete_tokens)) {
            $abbrevs = [];
            foreach ($deleteables as $tok) {
                $tok->set_invalid()
                    ->set_expires_in(Authorization_Token::BEARER_RETENTION)
                    ->update();
                $abbrevs[] = $tok->abbreviation();
            }
            self::add_token_diff($us, "revoked " . join(" ", $abbrevs));
        }
    }
}
