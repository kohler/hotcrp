<?php
// u_developer.php -- HotCRP Profile > Developer
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Developer_UserInfo {
    /** @var ?TokenInfo */
    private $_new_token;
    /** @var list<array{int,bool,string}> */
    private $_delete_tokens = [];

    function request(UserStatus $us) {
        if ($us->is_auth_self() && !$us->user->security_locked()) {
            $us->request_group("developer");
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
        echo '<p class="w-text">API tokens let you access HotCRP’s API programmatically. Supply a token using an HTTP <code>Authorization</code> header, as in “<code>Authorization: Bearer <em>token-name</em></code>”.</p>';
        if ($us->is_auth_self()) {
            $us->conf->warning_msg('<0>Treat tokens like passwords and keep them secret. Anyone who knows your tokens can access this site with your privileges.');
        } else {
            $us->conf->warning_msg('<0>You can only create and delete API tokens when logged in to your own account.');
        }
    }

    function print_current_bearer_tokens(UserStatus $us) {
        $toks = self::all_active_bearer_tokens($us->user);
        usort($toks, function ($a, $b) {
            $au = floor($a->timeUsed / 86400);
            $bu = floor($b->timeUsed / 86400);
            if (($au > 0) !== ($bu > 0)) {
                return $au > 0 ? -1 : 1;
            } else {
                return ($bu <=> $au) ? : ($a->timeCreated <=> $b->timeCreated);
            }
        });

        if (!empty($toks)) {
            Icons::stash_defs("trash");
            echo Ht::unstash();
            $us->print_start_section("Active tokens");
            $n = 1;
            foreach ($toks as $tok) {
                if ($tok->timeCreated >= Conf::$now - 10) {
                    $this->print_fresh_bearer_token($us, $tok, $n);
                } else {
                    $this->print_bearer_token($us, $tok, $n);
                }
                ++$n;
            }
        }
    }

    /** @param int $n */
    private function print_bearer_token_deleter(UserStatus $us, TokenInfo $tok, $n) {
        if (!$us->is_auth_self() || $us->user->security_locked()) {
            return;
        }
        $dbid = $tok->is_cdb ? "A" : "L";
        $id = "{$tok->timeCreated}.{$dbid}." . substr($tok->salt, 0, 9);
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
        $data = json_decode($tok->data ?? "{}", true) ?? [];
        $note = $data["note"] ?? "";
        echo '<div class="f-i w-text"><label class="f-c">',
            $note === "" ? "[Unnamed token]" : htmlspecialchars($note);
        $this->print_bearer_token_deleter($us, $tok, $n);
        echo '</label>',
            '<code>', substr($tok->salt, 0, 10), strlen($tok->salt) > 9 ? "…" : "", '</code>',
            ' <span class="barsep">·</span> ',
            self::unparse_last_used($tok->timeUsed),
            ' <span class="barsep">·</span> ',
            $tok->timeExpires > 0 ? "expires " . $us->conf->unparse_time_point($tok->timeExpires) : "never expires",
            '</div>';
    }

    /** @param int $n */
    function print_fresh_bearer_token(UserStatus $us, TokenInfo $tok, $n) {
        $data = json_decode($tok->data ?? "{}", true) ?? [];
        $note = $data["note"] ?? "";
        echo '<div class="form-section form-outline-section mb-4 tag-yellow">',
            '<div class="f-i w-text mb-0"><label class="f-c">',
            $note === "" ? "[Unnamed token]" : htmlspecialchars($note),
            '</label>',
            '<p class="w-text mb-1"><code><strong>', $tok->salt, '</strong></code>';
        $this->print_bearer_token_deleter($us, $tok, $n);
        echo '</p>',
            '<p class="feedback is-urgent-note">This is the new token you just created. Copy it now—you won’t be able to recover it later.</p>',
            '<p class="w-text mb-0">',
            self::unparse_last_used($tok->timeUsed),
            ' <span class="barsep">·</span> ',
            $tok->timeExpires > 0 ? "expires " . $us->conf->unparse_time($tok->timeExpires) : "never expires",
            '</p></div></div>';
    }

    static function unparse_last_used($time) {
        if ($time <= 0) {
            return "never used";
        } else if ($time + 31622400 < Conf::$now) { // 366 days
            return "last used more than a year ago";
        } else if ($time + 2592000 < Conf::$now) { // 30 days
            return "last used within the last " . plural(min(ceil($time / 2592000), 12), "month");
        } else if ($time + 86400 < Conf::$now) {
            return "last used within the last " . plural(ceil($time / 86400), "day");
        } else {
            return "last used within the last day";
        }
    }

    function print_new_bearer_token(UserStatus $us) {
        if (!$us->is_auth_self()) {
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
            ]) . '<div class="f-h">What’s this token for?</div>');

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
            $us->print_field("bearer_token/new/sites", "Site scope",
                Ht::select("bearer_token/new/sites", [
                    "all" => "All sites",
                    "here" => "This site only"
                ], $us->qreq["bearer_token/new/sites"] ?? "all", [
                    "id" => "bearer_token/new/sites", "data-default-value" => "all"
                ]));
        }
        $us->cs()->print_end_section();
    }

    function request_new_bearer_token(UserStatus $us) {
        assert($us->allow_some_security());
        if (!$us->qreq["bearer_token/new/enable"]
            || $us->user->security_locked()) {
            return;
        }

        $this->_new_token = $token = new TokenInfo($us->conf, TokenInfo::BEARER);

        $note = simplify_whitespace($us->qreq["bearer_token/new/note"] ?? "");
        if ($note !== "") {
            $token->assign_data(["note" => $note]);
        }

        $exp = $us->qreq["bearer_token/new/expiration"] ?? "30";
        if ($exp === "never") {
            $token->set_invalid_at(0)->set_expires_at(0);
        } else {
            $expiry = (ctype_digit($exp) ? intval($exp) : 30) * 86400;
            $token->set_invalid_after($expiry)->set_expires_after($expiry + 604800);
        }

        $sites = $us->qreq["bearer_token/new/sites"] ?? "here";
        if ($sites === "all" && ($cdbu = $us->user->cdb_user())) {
            $token->set_contactdb(true);
            $token->contactId = $cdbu->contactDbId;
        } else {
            $us->user->ensure_account_here();
            $token->contactId = $us->user->contactId;
        }
    }

    function save_new_bearer_token(UserStatus $us) {
        if ($this->_new_token !== null) {
            $this->_new_token->set_token_pattern("hct_[30]");
            if ($this->_new_token->create() !== null) {
                $us->diffs["API tokens"] = true;
            } else {
                $us->error_at(null, "<0>Error while creating new API token");
                $this->_new_token = null;
            }
        }
    }

    function request_delete_bearer_tokens(UserStatus $us) {
        assert($us->allow_some_security());
        if ($us->user->security_locked()) {
            return;
        }
        for ($i = 1; isset($us->qreq["bearer_token/{$i}/id"]); ++$i) {
            if (preg_match('/\A(\d+)\.([AL])\.(hct_\w+)\z/', $us->qreq["bearer_token/{$i}/id"], $m)
                && $us->qreq["bearer_token/{$i}/delete"]) {
                $this->_delete_tokens[] = [intval($m[1]), $m[2] === "A", $m[3]];
            }
        }
    }

    function save_delete_bearer_tokens(UserStatus $us) {
        if ($this->_delete_tokens !== null) {
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
}
