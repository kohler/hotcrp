<?php
// u_developer.php -- HotCRP Profile > Developer
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class Developer_UserInfo {
    /** @var UserStatus */
    private $us;
    /** @var ?TokenInfo */
    private $_new_token;
    /** @var list<array{int,bool,string}> */
    private $_delete_tokens = [];

    function __construct(UserStatus $us) {
        $this->us = $us;
    }

    function display_if() {
        if ($this->us->is_actas_self()) {
            return "dim";
        }
        return $this->us->is_auth_self();
    }

    function allow() {
        return $this->us->is_auth_self() && !$this->us->user->security_locked();
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

    /** @param ?Contact $user
     * @return list<TokenInfo> */
    static function active_bearer_tokens($user) {
        if (!$user) {
            return [];
        }
        $is_cdb = $user->is_cdb_user();
        $uid = $is_cdb ? $user->contactDbId : $user->contactId;
        if ($uid <= 0) {
            return [];
        }
        $dblink = $is_cdb ? $user->conf->contactdb() : $user->conf->dblink;
        $result = Dbl::qe($dblink, "select * from Capability where capabilityType=? and contactId=?", TokenInfo::BEARER, $uid);
        $toks = [];
        while (($tok = TokenInfo::fetch($result, $user->conf, $is_cdb))) {
            if ($tok->is_active())
                $toks[] = $tok;
        }
        Dbl::free($result);
        return $toks;
    }

    /** @param Contact $user
     * @return list<TokenInfo> */
    static function all_active_bearer_tokens($user) {
        $toks1 = self::active_bearer_tokens($user->cdb_user());
        return array_merge($toks1, self::active_bearer_tokens($user));
    }

    function print_bearer_tokens(UserStatus $us) {
        if (!$us->is_auth_self()) {
            $us->conf->warning_msg('<0>API tokens cannot be edited when acting as another user.');
            return false;
        }
        echo '<p class="w-text">API tokens let you access <a href="https://hotcrp.com/devel/api/">HotCRP’s API</a> programmatically. Supply a token using an HTTP <code>Authorization</code> header, as in “<code>Authorization: Bearer <em>token-name</em></code>”.</p>';
        $us->conf->warning_msg('<0>Treat tokens like passwords and keep them secret. Anyone who knows your tokens can access this site with your privileges.');
    }

    function print_current_bearer_tokens(UserStatus $us) {
        $toks = self::all_active_bearer_tokens($us->user);
        usort($toks, function ($a, $b) {
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
        if (!$us->is_auth_self()
            || $us->user->security_locked()
            || !$us->has_recent_authentication()) {
            return;
        }
        $dbid = $tok->is_cdb ? "A" : "L";
        $id = "{$tok->timeCreated}.{$dbid}." . substr($tok->salt, 0, 12);
        echo Ht::hidden("bearer_token/{$n}/id", $id),
            Ht::hidden("bearer_token/{$n}/delete", "", ["class" => "deleter", "data-default-value" => ""]),
            Ht::button(Icons::ui_use("trash"), [
                "class" => "ml-3 btn-licon-s ui js-profile-token-delete need-tooltip",
                "aria-label" => "Delete API token"
            ]);
        $us->mark_inputs_printed();
    }

    /** @param int $n */
    function print_bearer_token(UserStatus $us, TokenInfo $tok, $n) {
        $short_salt = substr($tok->salt, 0, 10) . (strlen($tok->salt) > 9 ? "…" : "");
        $note = $tok->data("note") ?? "";
        echo '<div class="f-i w-text"><div class="f-c">';
        if ($note !== "") {
            echo htmlspecialchars($note), ' <span class="barsep">·</span> ';
        }
        echo '<code>', $short_salt, '</code>';
        $this->print_bearer_token_deleter($us, $tok, $n);
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
            ' <span class="barsep">·</span> ',
            $tok->timeExpires > 0 ? "expires " . $conf->unparse_time_point($tok->timeExpires) : "never expires";
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
        if (!$us->is_auth_self()
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

        $us->print_field("bearer_token/new/scope", "Scope",
            Ht::entry("bearer_token/new/scope", $us->qreq["bearer_token/new/scope"] ?? "", [
                "size" => 30, "id" => "bearer_token/new/scope", "data-default-value" => "",
                "placeholder" => "all"
            ]) . '<div class="f-d">What rights should this token have?<br>Examples: ‘read’, ‘read paper:write’, ‘review:admin#10’, ‘submission:write?q=dec:no’</div>');

        $us->cs()->print_end_section();
    }

    function request_new_bearer_token(UserStatus $us) {
        assert($us->is_auth_self());
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
        if ($exp === "never") {
            $expiry = -1;
        } else {
            $expiry = (int) ((stonum($exp) ?? 30) * 86400);
        }

        $token = Authorization_Token::prepare_bearer($tuser, $expiry);
        $this->_new_token = $token;

        $note = simplify_whitespace($us->qreq["bearer_token/new/note"] ?? "");
        if ($note !== "") {
            $token->assign_data(["note" => $note]);
        }

        $scope = simplify_whitespace($us->qreq["bearer_token/new/scope"] ?? "");
        if ($scope !== ""
            && preg_match('/\A(?:[a-z][!\#-\x5b\x5d-~]*+\s*+)++\z/', $scope)) {
            $token->assign_data(["scope" => $scope]);
        }

        $exp = $us->qreq["bearer_token/new/expiration"] ?? "30";
        if ($exp === "never") {
            $token->set_invalid_at(0)->set_expires_at(0);
        } else {
            $expiry = (ctype_digit($exp) ? intval($exp) : 30) * 86400;
            $token->set_invalid_in($expiry)->set_expires_in($expiry + 604800);
        }
    }

    function save_new_bearer_token(UserStatus $us) {
        if ($this->_new_token === null) {
            return;
        }
        $this->_new_token->insert();
        if ($this->_new_token->stored()) {
            $us->diffs["API tokens"] = true;
        } else {
            $us->error_at(null, "<0>Error while creating new API token");
            $this->_new_token = null;
        }
    }

    function request_delete_bearer_tokens(UserStatus $us) {
        assert($us->is_auth_self());
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

    function save_delete_bearer_tokens(UserStatus $us) {
        if ($this->_delete_tokens === null) {
            return;
        }
        $toks = self::all_active_bearer_tokens($us->user);
        $deleteables = [];
        foreach ($toks as $tok) {
            foreach ($this->_delete_tokens as $dt) {
                if ($tok->timeCreated === $dt[0]
                    && $tok->is_cdb === $dt[1]
                    && str_starts_with($tok->salt, $dt[2])) {
                    $deleteables[] = $tok;
                }
            }
        }
        if (!empty($deleteables)
            && count($deleteables) <= count($this->_delete_tokens)) {
            foreach ($deleteables as $tok) {
                $tok->delete();
            }
            $us->diffs["API tokens"] = true;
        }
    }
}
