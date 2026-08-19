<?php
// t_userstatus.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class UserStatus_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var int
     * @readonly */
    public $raju_uid;
    /** @var int
     * @readonly */
    public $chris_uid;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->raju_uid = $this->conf->fetch_ivalue("select contactId from ContactInfo where email='raju@watson.ibm.com'");
        $this->chris_uid = $this->conf->fetch_ivalue("select contactId from ContactInfo where email='chris@w3.org'");
    }

    /** @param string $email
     * @param array<string,mixed> $req
     * @return array{Contact,Qrequest} */
    private function make_qreq_for($email, $req = []) {
        $u = $this->conf->fresh_user_by_email($email);
        $qreq = (new Qrequest("POST", $req))->approve_token();
        $qreq->set_qsession(new MemoryQsession);
        UserSecurityEvent::session_user_add($qreq->qsession(), $email);
        UserSecurityEvent::make($email)
            ->set_reason(UserSecurityEvent::REASON_REAUTH)
            ->store($qreq);
        $u = $u->activate($qreq, true);
        $qreq->set_user($u);
        return [$u, $qreq];
    }

    // ROLE_UNLISTEDPC is a PC role that stays off PC lists: `isPC`, tags, and
    // PC-level viewing all apply, but `pc_members()` and everything built on it
    // (conflict lists, PC selectors) skip the user
    function test_unlistedpc_role() {
        $us = new UserStatus($this->conf->root_user());
        $email = "unlisted@_.com";
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users", "pc");

        $acct = $us->save_user((object) ["email" => $email, "roles" => ["unlistedpc"]]);
        xassert(!!$acct, $us->full_feedback_text());
        xassert_eqq($acct->roles, Contact::ROLE_UNLISTEDPC);
        xassert_eqq($acct->isPC, true);
        xassert_eqq($acct->is_pclike(), true);
        xassert_eqq($acct->is_pc_member(), true);
        xassert_eqq($acct->is_listed_pc_member(), false);
        xassert_eqq($acct->privChair, false);

        $this->conf->invalidate_caches("users", "pc");
        xassert(isset($this->conf->pc_members()[$acct->contactId]));
        xassert(!isset($this->conf->listed_pc_members()[$acct->contactId]));
        xassert(isset($this->conf->pc_users()[$acct->contactId]));
        xassert(!!$this->conf->pc_member_by_id($acct->contactId));
        xassert(!!$this->conf->pc_user_by_id($acct->contactId));

        // an unlisted PC member is a PC member, so `#pc` matches; `#listedpc`
        // and `#unlistedpc` tell the two apart
        xassert_eqq($acct->has_tag("pc"), true);
        xassert_eqq($acct->has_tag("listedpc"), false);
        xassert_eqq($acct->has_tag("unlistedpc"), true);
        xassert_eqq($acct->tag_value("pc"), 0.0);
        xassert_eqq($acct->tag_value("listedpc"), null);
        xassert_eqq(Contact::all_user_tags_for($acct, Contact::CTFLAG_ROLES), " pc#0 unlistedpc#0");
        $acct = $us->save_user((object) ["email" => $email, "tags" => ["ztag"]]);
        xassert_eqq($acct->has_tag("ztag"), true);

        // round-trips through JSON and renders with a label
        xassert_eqq(UserStatus::unparse_roles_json($acct->roles), ["unlistedpc"]);
        xassert_str_contains(Contact::role_html_for($acct->roles), "unlisted PC");

        // `pc` and `unlistedpc` are exclusive in both directions
        $acct = $us->save_user((object) ["email" => $email, "roles" => ["pc"]]);
        xassert_eqq($acct->roles, Contact::ROLE_PC);
        $acct = $us->save_user((object) ["email" => $email, "roles" => ["unlistedpc"]]);
        xassert_eqq($acct->roles, Contact::ROLE_UNLISTEDPC);
        $acct = $us->save_user((object) ["email" => $email, "roles" => ["unlistedpc", "sysadmin"]]);
        xassert_eqq($acct->roles, Contact::ROLE_UNLISTEDPC | Contact::ROLE_ADMIN);

        // chair implies listed PC, so it clears the unlisted bit
        $acct = $us->save_user((object) ["email" => $email, "roles" => ["chair"]]);
        xassert_eqq($acct->roles, Contact::ROLE_PC | Contact::ROLE_CHAIR);

        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users", "pc");
    }

    // The address rule is the invariant everything else rests on, so the
    // boundary cases matter: a domain that merely ends in the reserved one is
    // not the reserved one.
    function test_bot_email() {
        foreach (["a@bot.invalid", "reviewbot@bot.invalid", "x@BOT.INVALID",
                  "x@agent.bot.invalid"] as $e) {
            xassert_eqq([$e, Contact::is_bot_email($e)], [$e, true]);
        }
        foreach (["x@notbot.invalid", "x@bot.invalidly", "x@bot.invalid.com",
                  "bot.invalid", "@bot.invalid", "x@example.org", "x@invalid",
                  "", "@", "bot.invalid@example.org"] as $e) {
            xassert_eqq([$e, Contact::is_bot_email($e)], [$e, false]);
        }
        // and every one of those addresses is unmailable and cdb-ineligible,
        // which is what the reserved domain buys
        foreach (["a@bot.invalid", "x@agent.bot.invalid"] as $e) {
            xassert(!Contact::is_plausible_email($e), $e);
            xassert(!Contact::cdb_allows_email($e), $e);
        }
    }

    // A bot account is an account: an unlisted PC member that a program drives.
    // The kind changes four things -- mail, credentials, the contact database,
    // and (elsewhere) review-identity visibility -- and nothing else.
    function test_bot_account() {
        $us = new UserStatus($this->conf->root_user());
        $email = "reviewbot@" . Contact::BOT_EMAIL_DOMAIN;
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users", "pc");

        $acct = $us->save_user((object) [
            "email" => $email, "bot" => true,
            "name" => "Reviewbot", "affiliation" => "Test Model 1"
        ]);
        xassert(!!$acct, $us->full_feedback_text());
        xassert($acct->is_bot());
        // unlisted PC by default: unlisted is what keeps it off PC listings and
        // out of the conflict selectors authors and PC members see
        xassert_eqq($acct->roles, Contact::ROLE_UNLISTEDPC);
        xassert_eqq($acct->is_listed_pc_member(), false);
        xassert_eqq($acct->is_pc_member(), true);
        // and being a bot is not a disablement: the account is enabled, it is
        // simply not a person
        xassert(!$acct->is_disabled());
        xassert(!$acct->is_dormant());

        // never mailed
        xassert(!$acct->can_receive_mail());
        // no interactive credentials, whatever is stored
        xassert(!$acct->can_reset_password());
        xassert(!$acct->check_password("anything"));
        xassert_eqq($acct->check_password_info("anything")["bot"] ?? null, true);
        // never in the contact database
        xassert_eqq($acct->update_cdb(), false);
        xassert(!$this->conf->fresh_cdb_user_by_email($email));

        // the flag survives a reload and round-trips through JSON
        $this->conf->invalidate_caches("users");
        $acct2 = $this->conf->checked_user_by_email($email);
        xassert($acct2->is_bot());
        $us2 = new UserStatus($this->conf->root_user());
        $us2->set_user($acct2);
        xassert_eqq($us2->user_json()->bot ?? null, true);

        // an edit that does not mention the flag leaves it alone, and does not
        // trip the reserved-domain check
        $acct3 = $us->save_user((object) ["email" => $email, "affiliation" => "Test Model 2"]);
        xassert(!!$acct3, $us->full_feedback_text());
        xassert($acct3->is_bot());
        xassert_eqq($acct3->affiliation, "Test Model 2");

        // the bit is set at creation and never after, in either direction
        $us4 = new UserStatus($this->conf->root_user());
        xassert(!$us4->save_user((object) ["email" => $email, "bot" => false]));
        xassert_neqq($us4->problem_status_at("bot"), 0);
        xassert($this->conf->checked_user_by_email($email)->is_bot());

        $hemail = "notabot@_.com";
        $this->conf->qe("delete from ContactInfo where email=?", $hemail);
        $us5 = new UserStatus($this->conf->root_user());
        $person = $us5->save_user((object) ["email" => $hemail]);
        xassert(!!$person);
        xassert(!$person->is_bot());
        $us6 = new UserStatus($this->conf->root_user());
        xassert(!$us6->save_user((object) ["email" => $hemail, "bot" => true]));
        xassert_neqq($us6->problem_status_at("bot"), 0);
        $this->conf->invalidate_caches("users");
        xassert(!$this->conf->checked_user_by_email($hemail)->is_bot());

        // the domain is reserved: no ordinary creation path may mint a bot
        $bemail = "squatter@" . Contact::BOT_EMAIL_DOMAIN;
        $us7 = new UserStatus($this->conf->root_user());
        xassert(!$us7->save_user((object) ["email" => $bemail]));
        xassert_neqq($us7->problem_status_at("email"), 0);
        xassert(!$this->conf->fresh_user_by_email($bemail));

        // Bots and `bot.invalid` are the same set, in both directions. That
        // biconditional is the invariant the rest rests on: `.invalid` is
        // already implausible, so mail suppression and contact-database
        // exclusion hold in every path that asks `is_plausible_email`, not only
        // in the paths that remember to ask about bots.
        $pemail = "otherbot@robots.acm.org";
        xassert(Contact::is_plausible_email($pemail));
        xassert(Contact::cdb_allows_email($pemail));
        $this->conf->qe("delete from ContactInfo where email=?", $pemail);
        $us8 = new UserStatus($this->conf->root_user());
        xassert(!$us8->save_user((object) ["email" => $pemail, "bot" => true]));
        xassert_neqq($us8->problem_status_at("email"), 0);
        xassert(!$this->conf->fresh_user_by_email($pemail));

        // Nothing can move an existing bot off the domain, because HotCRP has
        // no email-change path at all -- `ManageEmail_Page::go_changeemail`
        // answers “Email address changes are not supported” -- so the
        // biconditional only has to hold where accounts are created.

        // and a bot has no mailbox to prefer: `can_receive_mail` would take a
        // plausible `preferredEmail` over the account's own address
        $us10 = new UserStatus($this->conf->root_user());
        xassert(!$us10->save_user((object) [
            "email" => $email, "preferred_email" => "operator@robots.acm.org"
        ]));
        xassert_neqq($us10->problem_status_at("preferred_email"), 0);

        // The flag checks in `can_receive_mail` and `update_cdb` are defense in
        // depth rather than the invariant, so they are only reachable by
        // building a `Contact` that the save paths would refuse to store.
        $mem = Contact::make_email($this->conf, $pemail);
        xassert($mem->can_receive_mail());
        $mem->cflags |= Contact::CF_BOT;
        xassert(!$mem->can_receive_mail());

        foreach ([$email, $hemail, $pemail] as $e) {
            $this->conf->qe("delete from ContactInfo where email=?", $e);
        }
        $this->conf->invalidate_caches("users", "pc");
    }

    // A bot is never a recipient. It is enabled, so the disablement test that
    // filters bulk mail does not exclude it, and without an explicit rule every
    // reminder run would build mail that `can_receive_mail` then throws away.
    function test_bot_mail_recipients() {
        $conf = $this->conf;
        $email = "mailbot@" . Contact::BOT_EMAIL_DOMAIN;
        $conf->qe("delete from ContactInfo where email=?", $email);
        $conf->invalidate_caches("users", "pc");
        $bot = (new UserStatus($conf->root_user()))->save_user((object) [
            "email" => $email, "bot" => true, "name" => "Mailbot"
        ]);
        xassert(!!$bot);
        xassert_eqq($bot->roles, Contact::ROLE_UNLISTEDPC);

        // the recipient query names every PC member but not this one
        $chair = $conf->checked_user_by_email("chair@_.com");
        $emails_for = function ($type) use ($conf, $chair) {
            $mr = (new MailRecipients($chair))->set_recipients($type);
            $q = $mr->query(false);
            xassert(!!$q, $type);
            $out = [];
            $result = $conf->qe_raw($q);
            while (($row = $result->fetch_object())) {
                $out[] = $row->email;
            }
            $result->close();
            return $out;
        };
        foreach (["pc", "unlistedpc", "all"] as $type) {
            xassert(!in_array($email, $emails_for($type), true), $type);
        }
        // and the check is about bots, not about the role: an unlisted PC
        // member who is not a bot is still a recipient
        $hemail = "unlistedhuman@_.com";
        $conf->qe("delete from ContactInfo where email=?", $hemail);
        $conf->invalidate_caches("users", "pc");
        $hu = (new UserStatus($conf->root_user()))->save_user((object) [
            "email" => $hemail, "roles" => ["unlistedpc"]
        ]);
        xassert(!!$hu);
        xassert(in_array($hemail, $emails_for("unlistedpc"), true));

        // a direct send explains itself rather than dropping the recipient in
        // silence or blaming the address for looking fake
        $prep = new MailPreparation($conf, $bot);
        $ml = $prep->invalid_recipient_message_list();
        xassert_eqq(count($ml), 1);
        xassert_match($ml[0]->message ?? "", '/Bot account.*not notified/');
        xassert_str_contains($ml[0]->message ?? "", $email);
        // and it reads correctly for more than one. `{X:plural verb}` inverts
        // for verbs it does not know -- “do” pluralizes to “does” -- so this
        // message avoids a verb that has to agree
        $email2 = "mailbot2@" . Contact::BOT_EMAIL_DOMAIN;
        $conf->qe("delete from ContactInfo where email=?", $email2);
        $conf->invalidate_caches("users", "pc");
        $bot2 = (new UserStatus($conf->root_user()))->save_user((object) [
            "email" => $email2, "bot" => true
        ]);
        xassert(!!$bot2);
        $prep2 = new MailPreparation($conf, $bot);
        $prep2->add_recipient($bot2);
        $ml2 = $prep2->invalid_recipient_message_list();
        xassert_eqq(count($ml2), 1);
        xassert_match($ml2[0]->message ?? "", '/Bot account.*not notified/');
        xassert_str_contains($ml2[0]->message ?? "", "{$email} and {$email2}");
        $conf->qe("delete from ContactInfo where email=?", $email2);

        foreach ([$email, $hemail] as $e) {
            $conf->qe("delete from ContactInfo where email=?", $e);
        }
        $conf->invalidate_caches("users", "pc");
    }

    // A chair may hold a bot's credentials, and nobody else's. The rule that
    // protects is “an administrator cannot mint a credential that speaks as a
    // person”; a bot has no person to impersonate and no way to sign in and
    // mint one for itself, so someone has to.
    function test_bot_token_principal() {
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $email = "tokenbot@" . Contact::BOT_EMAIL_DOMAIN;
        $conf->qe("delete from ContactInfo where email=?", $email);
        $conf->invalidate_caches("users", "pc");
        $bot = (new UserStatus($conf->root_user()))->save_user((object) [
            "email" => $email, "bot" => true, "name" => "Tokenbot"
        ]);
        xassert(!!$bot);

        // both predicates: `display_if` decides whether the section appears,
        // `allow` whether its requests and saves run
        $developer_shown = function ($viewer, $user) {
            $us = new UserStatus($viewer);
            $us->set_user($user);
            $dev = new Developer_UserInfo($us);
            return [$dev->display_if(), $dev->allow()];
        };

        // the chair may, for a bot
        xassert_eqq($developer_shown($chair, $bot), [true, true]);
        // and may not, for a person -- this is the rule the exception is carved
        // out of, so it is the assertion that matters most here
        $human = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        xassert_eqq($developer_shown($chair, $human), [false, false]);
        // nor may a non-chair, for a bot
        xassert_eqq($developer_shown($human, $bot), [false, false]);
        // an account still holds its own
        xassert_eqq($developer_shown($human, $human), [true, true]);

        // *any* chair, not the one who made it: a bot belongs to the
        // conference, and no operation on one asks who created it
        $email2 = "otherchair@_.com";
        $conf->qe("delete from ContactInfo where email=?", $email2);
        $conf->invalidate_caches("users", "pc");
        $chair2 = (new UserStatus($conf->root_user()))->save_user((object) [
            "email" => $email2, "name" => "Other Chair", "roles" => ["chair"]
        ]);
        xassert(!!$chair2);
        xassert($chair2->privChair);
        xassert_eqq($developer_shown($chair2, $bot), [true, true]);
        // and a system administrator is a chair for this purpose
        $email3 = "sysadmin@_.com";
        $conf->qe("delete from ContactInfo where email=?", $email3);
        $conf->invalidate_caches("users", "pc");
        $admin = (new UserStatus($conf->root_user()))->save_user((object) [
            "email" => $email3, "name" => "Sys Admin", "roles" => ["sysadmin"]
        ]);
        xassert(!!$admin);
        $admin = $conf->checked_user_by_email($email3);
        // sysadmin only: no PC role bit, so this pins the rule to `privChair`
        // rather than to PC membership (`isPC` is true for any administrator)
        xassert_eqq($admin->roles, Contact::ROLE_ADMIN);
        xassert($admin->privChair);
        xassert_eqq($developer_shown($admin, $bot), [true, true]);
        $conf->qe("delete from ContactInfo where email?a", [$email2, $email3]);
        $conf->invalidate_caches("users", "pc");

        // a bot's token starts narrow: it is a credential nobody watches, held
        // by something that acts on its own
        xassert_eqq(Developer_UserInfo::default_token_scope($bot), "read review:write");
        xassert_eqq(Developer_UserInfo::default_token_scope($human), "");
        // and that default is a real scope, and a narrow one: it writes
        // reviews and reads, and does nothing else
        $ts = TokenScope::parse(Developer_UserInfo::default_token_scope($bot), null);
        xassert(!!$ts);
        xassert($ts->allows_some(TokenScope::S_REV_WRITE));
        xassert($ts->allows_some(TokenScope::S_SUB_READ));
        xassert(!$ts->allows_some(TokenScope::S_SUB_WRITE));
        xassert(!$ts->allows_some(TokenScope::S_TAG_WRITE));
        xassert(!$ts->allows_some(TokenScope::S_REV_ADMIN));

        $conf->qe("delete from ContactInfo where email=?", $email);
        $conf->invalidate_caches("users", "pc");
    }

    /** Two actions on the profile page are dead ends for a bot: mail is never
     * sent to one, and its reviews cannot be transferred. Neither is offered. */
    function test_bot_profile_dead_ends() {
        $conf = $this->conf;
        $email = "deadendbot@" . Contact::BOT_EMAIL_DOMAIN;
        $conf->qe("delete from ContactInfo where email=?", $email);
        $conf->invalidate_caches("users", "pc");
        $bot = (new UserStatus($conf->root_user()))->save_user((object) [
            "email" => $email, "bot" => true, "name" => "Deadend Bot"
        ]);
        xassert(!!$bot);
        // the branch this test is about is reached only for a reviewer, and an
        // unlisted PC member is one
        xassert($bot->is_reviewer());

        $chair = $conf->checked_user_by_email("chair@_.com");
        $render = function ($u) use ($chair) {
            $us = (new UserStatus($chair))->set_qreq((new Qrequest("GET"))->approve_token());
            $us->set_user($u);
            ob_start();
            $us->print_members("main");
            return ob_get_clean();
        };

        $html = $render($bot);
        xassert(!str_contains($html, "Transfer reviews"));
        xassert(preg_match('/js-send-user-accountinfo[^>]*disabled|disabled[^>]*js-send-user-accountinfo/', $html));

        // the control: a person who reviews is offered both
        $human = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        xassert($human->is_reviewer());
        $html = $render($human);
        xassert_str_contains($html, "Transfer reviews");
        xassert(!preg_match('/js-send-user-accountinfo[^>]*disabled|disabled[^>]*js-send-user-accountinfo/', $html));

        $conf->qe("delete from ContactInfo where email=?", $email);
        $conf->invalidate_caches("users", "pc");
    }

    /** A chair minting or revoking a bot's credential is an act on someone
     * else's account,
     * so it must land in the log with the chair as actor and the bot as
     * target, and it must name the token: “API tokens” alone cannot tell a
     * later reader which credential appeared or went away. */
    function test_bot_token_changes_are_logged() {
        $conf = $this->conf;
        $email = "logbot@" . Contact::BOT_EMAIL_DOMAIN;
        $conf->qe("delete from ContactInfo where email=?", $email);
        $conf->invalidate_caches("users", "pc");
        $bot = (new UserStatus($conf->root_user()))->save_user((object) [
            "email" => $email, "bot" => true, "name" => "Logbot"
        ]);
        xassert(!!$bot);

        list($chair, $qreq) = $this->make_qreq_for("chair@_.com", [
            "bearer_token/new/enable" => 1,
            "bearer_token/new/expiration" => "30",
            "bearer_token/new/note" => "test bot token"
        ]);
        $us = (new UserStatus($chair))->set_qreq($qreq);
        xassert($us->has_recent_authentication());
        $us->start_update();
        $us->set_user($bot);
        $us->request_group("");
        xassert($us->execute_update(), $us->full_feedback_text());

        // the token exists and belongs to the bot
        $ntok = $conf->fetch_ivalue("select count(*) from Capability where capabilityType=? and contactId=?",
                                    TokenInfo::BEARER, $bot->contactId);
        xassert_eqq($ntok, 1);

        // and the log names the chair as actor, the bot as target
        $row = Dbl::fetch_first_row($conf->qe("select contactId, destContactId, action from ActionLog where destContactId=? order by logId desc limit 1",
                                              $bot->contactId));
        xassert_eqq((int) $row[0], $chair->contactId);
        xassert_eqq((int) $row[1], $bot->contactId);
        // the log names the token, so a later reader can tell which credential
        // appeared — and the name is the one the profile screen shows
        $tok = TokenInfo::fetch($conf->qe("select * from Capability where capabilityType=? and contactId=?",
                                          TokenInfo::BEARER, $bot->contactId), $conf, false);
        xassert(!!$tok);
        xassert_eqq($row[2], "Account edited: API tokens [created {$tok->abbreviation()}]");
        // the twelve characters an access log keeps, so the two logs and the
        // profile screen name a token the same way
        xassert_eqq($tok->abbreviation(), substr($tok->salt, 0, 12));
        // and a personal token's identifier is inside them
        xassert_str_contains($tok->abbreviation(), Authorization_Token::grant_id($tok));
        xassert(!str_contains($row[2], $tok->salt));

        // revoking says so, and names the same token
        $dbid = $tok->is_cdb ? "A" : "L";
        list($chair2, $qreq2) = $this->make_qreq_for("chair@_.com", [
            "bearer_token/1/id" => "{$tok->timeCreated}.{$dbid}." . substr($tok->salt, 0, 12),
            "bearer_token/1/delete" => 1
        ]);
        $us2 = (new UserStatus($chair2))->set_qreq($qreq2);
        $us2->start_update();
        $us2->set_user($conf->checked_user_by_email($email));
        $us2->request_group("");
        xassert($us2->execute_update(), $us2->full_feedback_text());
        $row = Dbl::fetch_first_row($conf->qe("select contactId, destContactId, action from ActionLog where destContactId=? order by logId desc limit 1",
                                              $bot->contactId));
        xassert_eqq($row[2], "Account edited: API tokens [revoked {$tok->abbreviation()}]");
        // and the token is no longer usable
        $tok2 = TokenInfo::find($tok->salt, $conf);
        xassert(!$tok2 || !$tok2->is_active());

        $conf->qe("delete from Capability where contactId=?", $bot->contactId);
        $conf->qe("delete from ContactInfo where contactId=?", $bot->contactId);
        $conf->invalidate_caches("users", "pc");
    }

    function test_role_html_for() {
        xassert_eqq(Contact::role_html_for(0), "");
        xassert_eqq(Contact::role_html_for(Contact::ROLE_PC), '<span class="pcrole">PC</span>');
        xassert_eqq(Contact::role_html_for(Contact::ROLE_CHAIR), '<span class="pcrole">chair</span>');
        xassert_eqq(Contact::role_html_for(Contact::ROLE_PC | Contact::ROLE_CHAIR), '<span class="pcrole">chair</span>');
        xassert_eqq(Contact::role_html_for(Contact::ROLE_ADMIN), '<span class="pcrole">sysadmin</span>');
        xassert_eqq(Contact::role_html_for(Contact::ROLE_PC | Contact::ROLE_ADMIN), '<span class="pcrole">PC, sysadmin</span>');
        xassert_eqq(Contact::role_html_for(Contact::ROLE_UNLISTEDPC), '<span class="pcrole">unlisted PC</span>');
        xassert_eqq(Contact::role_html_for(Contact::ROLE_UNLISTEDPC | Contact::ROLE_ADMIN), '<span class="pcrole">unlisted PC, sysadmin</span>');
    }

    function test_unlistedpc_bulk_and_lists() {
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        $emails = ["unl1@_.com", "unl2@_.com"];
        $this->conf->qe("delete from ContactInfo where email?a", $emails);
        $this->conf->invalidate_caches("users", "pc");

        $us = new UserStatus($this->conf->root_user());
        $ids = [];
        foreach ($emails as $email) {
            $acct = $us->save_user((object) ["email" => $email]);
            xassert(!!$acct, $us->full_feedback_text());
            $ids[] = $acct->contactId;
        }
        $this->conf->invalidate_caches("users", "pc");
        xassert_eqq($this->conf->has_unlisted_pc_members(), false);
        $h = (new MailRecipients($chair))->recipient_selector_html("recip");
        xassert(!str_contains($h, "listedpc"));   // and so not "unlistedpc" either

        // bulk add to unlisted PC
        $ua = new UserActions($chair);
        $ua->add_unlistedpc($ids);
        xassert_eqq(count($ua->name_list("add_unlistedpc")), 2);
        $this->conf->invalidate_caches("users", "pc");
        xassert_eqq($this->conf->has_unlisted_pc_members(), true);
        foreach ($ids as $cid) {
            xassert(isset($this->conf->pc_members()[$cid]));
            xassert(!isset($this->conf->listed_pc_members()[$cid]));
        }
        // `pc_members` is a superset of `listed_pc_members`
        foreach ($this->conf->listed_pc_members() as $cid => $u) {
            xassert(isset($this->conf->pc_members()[$cid]));
        }
        xassert_eqq(count($this->conf->pc_members()),
                    count($this->conf->listed_pc_members()) + 2);

        // the user list can select them, and “full PC” covers both kinds
        $unl = (new ContactList($chair, false))->sorted_users("unlistedpc");
        $unlids = array_map(function ($u) { return $u->contactId; }, $unl);
        xassert_eqq(count($unlids), 2);
        foreach ($ids as $cid) {
            xassert_in_eqq($cid, $unlids);
        }
        $full = (new ContactList($chair, false))->sorted_users("fullpc");
        $fullids = array_map(function ($u) { return $u->contactId; }, $full);
        xassert_eqq(count($fullids), count($this->conf->pc_members()));
        foreach ($ids as $cid) {
            xassert_in_eqq($cid, $fullids);
        }
        // ...while the plain PC list does not
        $pl = (new ContactList($chair, false))->sorted_users("pc");
        foreach ($pl as $u) {
            xassert(!in_array($u->contactId, $ids, true));
        }

        // mail: `pc` covers both kinds, `listedpc`/`unlistedpc` split them
        $mr = new MailRecipients($chair);
        $h = $mr->recipient_selector_html("recip");
        xassert_str_contains($h, "\"listedpc\"");
        xassert_str_contains($h, "\"unlistedpc\"");
        $seen = [];
        foreach (["pc", "listedpc", "unlistedpc"] as $t) {
            $mr->set_recipients($t);
            $q = $mr->query(false);
            xassert(!!$q, $t);
            $cids = [];
            $result = $this->conf->qe_raw($q);
            while (($row = $result->fetch_object())) {
                $cids[] = (int) $row->contactId;
            }
            $result->close();
            $seen[$t] = $cids;
        }
        foreach ($ids as $cid) {
            xassert_in_eqq($cid, $seen["pc"]);
            xassert_in_eqq($cid, $seen["unlistedpc"]);
            xassert(!in_array($cid, $seen["listedpc"], true));
        }
        xassert_eqq(count($seen["unlistedpc"]), 2);
        xassert_eqq(count($seen["pc"]), count($seen["listedpc"]) + 2);

        // “Remove from PC” clears the unlisted role too
        $ua = new UserActions($chair);
        $ua->remove_pc($ids);
        xassert_eqq(count($ua->name_list("remove_pc")), 2);
        $this->conf->invalidate_caches("users", "pc");
        xassert_eqq($this->conf->has_unlisted_pc_members(), false);
        foreach ($ids as $cid) {
            $u = $this->conf->fresh_user_by_id($cid);
            xassert_eqq($u->roles, 0);
        }

        $this->conf->qe("delete from ContactInfo where email?a", $emails);
        $this->conf->invalidate_caches("users", "pc");
    }

    // unlisted PC members are PC-internal: visible to the PC and to managers,
    // invisible to everyone else even where the plain PC is public
    function test_unlistedpc_visibility() {
        $email = "unlvis@_.com";
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users", "pc");
        $us = new UserStatus($this->conf->root_user());
        $acct = $us->save_user((object) ["email" => $email, "roles" => ["unlistedpc"],
                                         "firstName" => "Unlisted", "lastName" => "Visitor"]);
        xassert(!!$acct, $us->full_feedback_text());
        $this->conf->invalidate_caches("users", "pc");
        $uid = $acct->contactId;
        $acct = $this->conf->checked_user_by_email($email);

        // every PC member gets a `pc_index`; conflict rendering indexes by it
        $idx = [];
        foreach ($this->conf->pc_members() as $id => $u) {
            xassert($u->pc_index !== null, "pc_index for {$u->email}");
            $idx[] = $u->pc_index;
        }
        xassert_eqq(count(array_unique($idx)), count($idx));

        $chair = $this->conf->checked_user_by_email("chair@_.com");
        $pc = $this->conf->checked_user_by_email("lixia@cs.ucla.edu");
        $au = $this->conf->checked_user_by_email("micke@cdt.luth.se");
        xassert(!$au->isPC);
        xassert($au->can_view_pc());          // the PC itself is public here

        foreach ([[$chair, true], [$pc, true], [$au, false]] as $t) {
            list($viewer, $sees) = $t;
            xassert_eqq(($viewer->viewable_roles_mask() & Contact::ROLE_UNLISTEDPC) !== 0, $sees, $viewer->email);

            // ContactSearch obeys the rule
            $ids = ContactSearch::make_pc("pc", $viewer)->user_ids();
            xassert_eqq(in_array($uid, $ids, true), $sees, $viewer->email);
            $anyids = (new ContactSearch(ContactSearch::F_PC | ContactSearch::F_TAG, "any", $viewer))->user_ids();
            xassert_eqq(in_array($uid, $anyids, true), $sees, $viewer->email);

            // ...as does the PC list stashed for the client
            $pcj = $this->conf->hotcrp_pc_json($viewer, 0);
            $names = array_map(function ($x) { return $x->name ?? ""; }, $pcj->pc);
            xassert_eqq(in_array("Unlisted Visitor", $names, true), $sees, $viewer->email);

            // ...and the role label
            $rh = Contact::role_html_for($acct->viewable_pc_roles($viewer));
            xassert_eqq($rh !== "", $sees, $viewer->email);
        }

        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users", "pc");
    }

    function test_edit_own_password() {
        list($u, $qreq) = $this->make_qreq_for("estrin@usc.edu");
        xassert_eqq($u->email, "estrin@usc.edu");
        $newpw = $u->check_password("maksdfnqw") ? "MAKsdfnqw" : "maksdfnqw";
        $qreq->upassword = $qreq->upassword2 = $newpw;

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        xassert($us->is_auth_self());
        xassert($us->has_recent_authentication());
        $us->request_group("");
        xassert_eqq($us->jval->new_password, $newpw);
        xassert($us->execute_update());

        xassert($us->user->check_password($newpw));
    }

    static function reauth_query($qreq, $email, $bound) {
        $x = false;
        foreach (UserSecurityEvent::session_list_by_email($qreq->qsession(), $email) as $use) {
            if ($use->type === UserSecurityEvent::TYPE_PASSWORD
                && $use->reason === UserSecurityEvent::REASON_REAUTH
                && $use->timestamp >= $bound)
                $x = $use->success;
        }
        return $x;
    }

    function test_edit_own_password_fail_no_recent_auth() {
        list($u, $qreq) = $this->make_qreq_for("estrin@usc.edu", [
            "upassword" => "maksdf", "upassword2" => "maksdf"
        ]);
        xassert_eqq($u->email, "estrin@usc.edu");
        $qreq->qsession()->set("usec", [["a" => Conf::$now - 40000, "r" => 1]]);
        xassert(self::reauth_query($qreq, "estrin@usc.edu", Conf::$now - 50000));
        xassert(!self::reauth_query($qreq, "estrin@usc.edu", Conf::$now - 20000));

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        xassert($us->is_auth_self());
        xassert(!$us->has_recent_authentication());
        $us->request_group("");
        xassert($us->execute_update());

        xassert(!$us->user->check_password("maksdf"));
    }

    #[RequireCdb(false)]
    function test_edit_other_password_chair() {
        if ($this->conf->contactdb()) {
            return;
        }

        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["upassword" => "maksdfnq!", "upassword2" => "maksdfnq!"]
        );
        xassert_eqq($u->email, "chair@_.com");
        xassert($u->can_edit_any_password());

        $ux = $this->conf->fresh_user_by_email("estrin@usc.edu");
        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($ux);
        $us->request_group("");
        xassert($us->execute_update());

        xassert($us->user->check_password("maksdfnq!"));
    }

    function test_edit_other_password_fail_nonchair() {
        list($u, $qreq) = $this->make_qreq_for(
            "floyd@ee.lbl.gov",
            ["upassword" => "maksdfnq11", "upassword2" => "maksdfnq11"]
        );
        xassert(!$u->can_edit_any_password());

        $ux = $this->conf->fresh_user_by_email("estrin@usc.edu");
        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($ux);
        $us->request_group("");
        $us->execute_update();

        xassert(!$us->user->check_password("maksdfnq11"));
    }

    function test_edit_actas_password_fail() {
        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["upassword" => "maksdfnqw11", "upassword2" => "maksdfnqw11",
             "actas" => "estrin@usc.edu"]
        );
        xassert_eqq($u->email, "estrin@usc.edu");

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        $us->request_group("");
        xassert($us->execute_update());

        xassert(!$us->user->check_password("maksdfnqw11"));
    }

    function test_anonymous_fail() {
        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["uemail" => "anonymous"]
        );

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->request_group("");
        xassert(!$us->execute_update());
    }

    private function delete_secondary($cdb, $email) {
        $db = $cdb ? $this->conf->contactdb() : $this->conf->dblink;
        $id = $cdb ? "contactDbId" : "contactId";
        if (($uid = Dbl::fetch_ivalue($db, "select {$id} from ContactInfo where email=?", $email)) > 0) {
            Dbl::qe($db, "delete from ContactInfo where {$id}=?", $uid);
            Dbl::qe($db, "delete from ContactPrimary where contactId=?", $uid);
        }
    }

    private function insert_secondary($cdb, $sec_email, $pri_id) {
        $db = $cdb ? $this->conf->contactdb() : $this->conf->dblink;
        $result = Dbl::qe($db, "insert into ContactInfo set email=?, password=' unset', primaryContactId=?",
            $sec_email, $pri_id);
        xassert_gt($result->insert_id ?? 0, 0);
        Dbl::qe($db, "insert into ContactPrimary set contactId=?, primaryContactId=?",
            $result->insert_id, $pri_id);
        return $result->insert_id;
    }

    function test_no_follow_primary() {
        $this->delete_secondary(false, "xvan@usc.edu");

        $van = $this->conf->user_by_email("van@ee.lbl.gov");
        xassert(!$van->isPC);
        $this->insert_secondary(false, "xvan@usc.edu", $van->contactId);
        $van->set_prop("cflags", $van->cflags | Contact::CF_PRIMARY);
        $van->save_prop();

        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["uemail" => "xvan@usc.edu", "firstName" => "Ximena"]
        );

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update();
        $us->request_group("");
        xassert($us->execute_update());

        xassert_eqq($us->user->email, "xvan@usc.edu");
        xassert_eqq($us->user->firstName, "Ximena");
    }

    function test_follow_primary() {
        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["uemail" => "xvan@usc.edu", "pctype" => "pc"]
        );

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->set_follow_primary(true);
        $us->start_update();
        $us->request_group("");
        xassert($us->execute_update());

        xassert_eqq($us->user->email, "van@ee.lbl.gov");
        xassert_eqq($us->user->isPC, true);
    }

    #[RequireCdb(true)]
    function test_follow_primary_cdb() {
        if (!($cdb = $this->conf->contactdb())) {
            return;
        }

        $this->delete_secondary(false, "yvan@usc.edu");
        $this->delete_secondary(true, "yvan@usc.edu");
        $c_van = $this->conf->cdb_user_by_email("van@ee.lbl.gov");
        $this->insert_secondary(true, "yvan@usc.edu", $c_van->contactDbId);
        $c_van->set_prop("cflags", $c_van->cflags | Contact::CF_PRIMARY);
        $c_van->save_prop();

        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["uemail" => "yvan@usc.edu", "pctype" => "chair"]
        );

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->set_follow_primary(true);
        $us->start_update();
        $us->request_group("");
        xassert($us->execute_update());

        xassert_eqq($us->user->email, "van@ee.lbl.gov");
        xassert_eqq($us->user->privChair, true);
    }

    function test_secondary_pc_not_followed() {
        $this->conf->qe("delete from ContactPrimary where contactId in (select contactId from ContactInfo where email like 'globert%')");
        $this->conf->qe("delete from ContactInfo where email like 'globert%'");

        $result = $this->conf->qe("insert into ContactInfo (firstName, lastName, email, affiliation, collaborators, password, cflags, roles) values
            ('Jimena', 'Globert', 'globert1@_.com', 'Brandeis', 'German Strawberries', '', 0, 1),
            ('Jimena', 'Globert', 'globert1p@_.com', 'Brandeis', 'German Strawberries', '', 0, 0)");
        xassert(!Dbl::is_error($result));
        $globert1 = $this->conf->fresh_user_by_email("globert1@_.com");
        $globert1p = $this->conf->fresh_user_by_email("globert1p@_.com");
        (new ContactPrimary)->link($globert1, $globert1p);
        xassert(!$globert1->has_tag("red"));

        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["uemail" => "globert1@_.com", "tags" => "red"]
        );

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->set_follow_primary(true);
        $us->start_update();
        $us->request_group("");
        xassert($us->execute_update());

        $globert1 = $this->conf->fresh_user_by_email("globert1@_.com");
        xassert($globert1->has_tag("red"));
    }

    function test_confactions_link() {
        ConfActions::link($this->conf, (object) ["u" => "raju@watson.ibm.com", "email" => "chris@w3.org"]);
        xassert_eqq($this->conf->fetch_ivalue("select primaryContactId from ContactInfo where contactId=?", $this->raju_uid), $this->chris_uid);
        (new ConfInvariants($this->conf))->check_users();
    }

    function test_confactions_unlink() {
        ConfActions::link($this->conf, (object) ["u" => "raju@watson.ibm.com"]);
        xassert_eqq($this->conf->fetch_ivalue("select primaryContactId from ContactInfo where contactId=?", $this->raju_uid), 0);
        (new ConfInvariants($this->conf))->check_users();
    }

    function test_confactions_relink() {
        ConfActions::link($this->conf, (object) ["u" => "raju@watson.ibm.com", "email" => "chris@w3.org"]);
        ConfActions::link($this->conf, (object) ["u" => "chris@w3.org", "email" => "raju@watson.ibm.com"]);
        xassert_eqq($this->conf->fetch_ivalue("select primaryContactId from ContactInfo where contactId=?", $this->chris_uid), $this->raju_uid);
        $this->conf->invalidate_caches("users");
        (new ConfInvariants($this->conf))->check_users();
    }

    #[RequireCdb(true)]
    function test_confactions_delay() {
        if (!($cdb = $this->conf->contactdb())) {
            return;
        }
        $this->delete_secondary(false, "rajuu@watson.edu");
        $this->delete_secondary(true, "rajuu@watson.edu");

        $u_raju = $this->conf->user_by_id($this->raju_uid)->cdb_user();
        $u_rajuu = $this->conf->fresh_user_by_email("rajuu@watson.edu");
        xassert(!!$u_raju);
        xassert(!$u_rajuu);
        $this->insert_secondary(true, "rajuu@watson.edu", $u_raju->contactDbId);
        $this->conf->qe("insert into Settings set name='confactions', value=1, data=?",
            "\x1e{\"action\":\"link\",\"u\":\"raju@watson.ibm.com\",\"email\":\"rajuu@watson.edu\"}\n"
            . "\x1e{\"action\":\"link\",\"u\":\"rajuu@watson.edu\",\"email\":\"raju@watson.ibm.com\"}\n");
        $this->conf->invalidate_caches("users");
        $this->conf->load_settings();
        $u_raju = $this->conf->fresh_user_by_id($this->raju_uid);
        $u_rajuu = $this->conf->fresh_user_by_email("rajuu@watson.edu");
        $u_chris = $this->conf->fresh_user_by_id($this->chris_uid);
        xassert(!!$u_raju);
        xassert(!!$u_rajuu);
        xassert(!!$u_chris);
        xassert_eqq($u_rajuu->primaryContactId, $u_raju->contactId);
        xassert_eqq($u_chris->primaryContactId, $u_raju->contactId);
        xassert_eqq($u_raju->primaryContactId, 0);
        xassert_eqq($u_raju->cflags & Contact::CF_PRIMARY, Contact::CF_PRIMARY);
        xassert_eqq($u_rajuu->cflags & Contact::CF_PRIMARY, 0);
        $this->conf->invalidate_caches("users");
        (new ConfInvariants($this->conf))->check_users();
    }

    private function cps_text($ids) {
        return json_encode(Dbl::fetch_iimap($this->conf->dblink, "select * from ContactPrimary where contactId?a or primaryContactId?a", $ids, $ids));
    }

    function test_relink_series() {
        $u1 = $this->conf->ensure_user_by_email("yue1@x.com");
        $u2 = $this->conf->ensure_user_by_email("yue2@x.com");
        $u3 = $this->conf->ensure_user_by_email("yue3@x.com");
        $ids = [$u1->contactId, $u2->contactId, $u3->contactId];
        //error_log("$u1->contactId $u2->contactId $u3->contactId");
        //error_log("$u1->primaryContactId $u2->primaryContactId $u3->primaryContactId " . $this->cps_text($ids));

        (new ContactPrimary($u1))->link($u2, $u1);
        //error_log(". $u1->primaryContactId $u2->primaryContactId $u3->primaryContactId " . $this->cps_text($ids));
        (new ContactPrimary($u1))->link($u1, $u3);
        //error_log(". $u1->primaryContactId $u2->primaryContactId $u3->primaryContactId " . $this->cps_text($ids));
        (new ContactPrimary($u1))->link($u2, $u1);
        //error_log(". $u1->primaryContactId $u2->primaryContactId $u3->primaryContactId " . $this->cps_text($ids));

        $u1 = $this->conf->fresh_user_by_id($u1->contactId);
        $u2 = $this->conf->fresh_user_by_id($u2->contactId);
        $u3 = $this->conf->fresh_user_by_id($u3->contactId);
        xassert_eqq($u1->cflags & Contact::CF_PRIMARY, Contact::CF_PRIMARY);
        xassert_eqq($u2->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($u3->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($u1->primaryContactId, 0);
        xassert_eqq($u2->primaryContactId, $u1->contactId);
        xassert_eqq($u3->primaryContactId, 0);
        (new ConfInvariants($this->conf))->check_users();
    }

    function test_create_disabled_primary() {
        $u1 = $this->conf->user_by_email("lina1@y.com");
        xassert(!$u1);
        $u1 = Contact::make_keyed($this->conf, ["email" => "lina1@y.com", "disablement" => Contact::CF_UDISABLED]);
        $u1->store();

        $u2 = $this->conf->user_by_email("lina2@y.com");
        xassert(!$u2);
        (new ContactPrimary($u1))->link($u1, Contact::make_email($this->conf, "lina2@y.com"));

        $u2 = $this->conf->fresh_user_by_email("lina2@y.com");
        xassert($u2->is_explicitly_disabled());

        $this->conf->qe("delete from ContactInfo where email='lina1@y.com' or email='lina2@y.com'");
        $this->conf->qe("delete from ContactPrimary where contactId?a", [$u1->contactId, $u2->contactId]);
    }

    function test_collaborators() {
        list($u, $qreq) = $this->make_qreq_for("estrin@usc.edu");
        $qreq->collaborators = "Judy Estrin (Packet Design, LLC)\n";
        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        xassert($us->is_auth_self());
        xassert($us->has_recent_authentication());
        $us->request_group("");
        xassert($us->execute_update());
        xassert_eqq($us->user->collaborators(), "Judy Estrin (Packet Design, LLC)");
    }

    function test_long_collaborators() {
        list($u, $qreq) = $this->make_qreq_for("estrin@usc.edu");
        $cl = join("\n", array_fill(0, 1024, "Judy Estrin (Packet Design, LLC)"));
        $qreq->collaborators = $cl;
        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        $us->request_group("");
        xassert($us->execute_update());
        xassert_eqq($us->user->collaborators(), $cl);
    }

    function test_collaborators_ifempty() {
        $u = $this->conf->fresh_user_by_email("estrin@usc.edu");
        $cl = $u->collaborators();
        xassert_gt(strlen($cl), 8192);
        $u->set_prop("collaborators", "hi", 1);
        xassert_eqq($u->collaborators(), $cl);
        $u->set_prop("collaborators", "hi", 2);
        xassert_eqq($u->collaborators(), $cl);
        $u->set_prop("collaborators", "hi", 0);
        xassert_eqq($u->collaborators(), "hi");
        $u->abort_prop();
    }

    /* CSV user saving.
       These pin the behavior of `UserStatus::parse_csv_main` and the CSV
       column vocabulary shared by `batch/saveusers.php` and
       `Profile > Bulk update`. */

    /** @var list<list<string>> */
    private $csv_diffs;
    /** @var list<string> */
    private $csv_feedback;
    /** @var ?UserStatus */
    private $csv_us;

    /** @param string $pattern */
    private function delete_users($pattern) {
        $this->conf->qe("delete from ContactInfo where email like ?", $pattern);
        if (($cdb = $this->conf->contactdb())) {
            Dbl::qe($cdb, "delete from ContactInfo where email like ?", $pattern);
        }
        $this->conf->invalidate_caches("users");
    }

    private function ensure_topics() {
        if (!$this->conf->has_topics()) {
            $this->conf->qe("insert into TopicArea (topicName) values ('Cloud computing'), ('Architecture'), ('Security'), ('Cloud networking')");
            $this->conf->save_refresh_setting("has_topics", 1);
        }
    }

    /** @return array<string,int> */
    private function topic_ids() {
        $tids = [];
        foreach ($this->conf->topic_set() as $id => $name) {
            $tids[$name] = $id;
        }
        return $tids;
    }

    /** Run CSV text through the same pipeline as `batch/saveusers.php` and
     * `Profile > Bulk update`. Returns the saved email for each data row, or
     * null for a row that failed; per-row diffs and feedback land in
     * `$csv_diffs` and `$csv_feedback`.
     * @param string $text
     * @param 0|1|2 $if_empty
     * @return list<?string> */
    private function csv_save($text, $if_empty = UserStatus::IF_EMPTY_NONE) {
        $csv = new CsvParser(cleannl($text));
        $csv->add_comment_prefix("###");
        $header = $csv->next_list();
        xassert($header !== null && !!preg_grep('/\Aemail\z/i', $header));
        $csv->set_header($header);

        $us = $this->csv_us = (new UserStatus($this->conf->root_user()))
            ->set_if_empty($if_empty);
        $us->add_csv_synonyms($csv);

        $this->csv_diffs = $this->csv_feedback = [];
        $emails = [];
        while (($line = $csv->next_row())) {
            $us->clear_messages();
            $us->start_update();
            $us->parse_csv($line);
            $ok = $us->execute_update();
            $emails[] = $ok ? $us->user->email : null;
            $this->csv_diffs[] = array_map("strval", array_keys($us->diffs));
            $this->csv_feedback[] = $us->full_feedback_text();
        }
        return $emails;
    }

    function test_csv_blank_means_unchanged() {
        $this->delete_users("csvt%");

        $r = $this->csv_save("email,name,affiliation,roles\n"
            . "csvt1@_.com,John Adams,UC Berkeley,pc\n");
        xassert_eqq($r, ["csvt1@_.com"]);
        $u = $this->conf->fresh_user_by_email("csvt1@_.com");
        xassert_eqq($u->firstName, "John");
        xassert_eqq($u->lastName, "Adams");
        xassert_eqq($u->affiliation, "UC Berkeley");
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC);

        // a blank cell means “leave this alone”, not “clear this”
        $r = $this->csv_save("email,name,affiliation,roles\ncsvt1@_.com,,,\n");
        xassert_eqq($r, ["csvt1@_.com"]);
        xassert_eqq($this->csv_diffs[0], []);
        $u = $this->conf->fresh_user_by_email("csvt1@_.com");
        xassert_eqq($u->firstName, "John");
        xassert_eqq($u->lastName, "Adams");
        xassert_eqq($u->affiliation, "UC Berkeley");
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC);

        // values are trimmed
        $this->csv_save("email,affiliation\ncsvt1@_.com,\"   Brandeis   \"\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt1@_.com")->affiliation, "Brandeis");
    }

    function test_csv_field_synonyms() {
        $this->delete_users("csvt2%");

        $r = $this->csv_save("email,given,surname,affiliation,city,province,zipcode,country,role,tag\n"
            . "csvt2@_.com,Quincy,Adams,Whitehouse,Washington,DC,20500,US,\"pc,chair\",red\n");
        xassert_eqq($r, ["csvt2@_.com"]);

        $u = $this->conf->fresh_user_by_email("csvt2@_.com");
        xassert_eqq($u->firstName, "Quincy");
        xassert_eqq($u->lastName, "Adams");
        xassert_eqq($u->affiliation, "Whitehouse");
        xassert_eqq($u->prop("city"), "Washington");
        xassert_eqq($u->prop("state"), "DC");
        xassert_eqq($u->prop("zip"), "20500");
        xassert_eqq($u->prop("country"), "US");
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC | Contact::ROLE_CHAIR);
        xassert($u->has_tag("red"));

        // the other spellings resolve to the same fields
        $this->csv_save("email,first_name,family_name,postal_code,region\n"
            . "csvt2@_.com,Johnny,Quince,02138,MA\n");
        $u = $this->conf->fresh_user_by_email("csvt2@_.com");
        xassert_eqq($u->firstName, "Johnny");
        xassert_eqq($u->lastName, "Quince");
        xassert_eqq($u->prop("zip"), "02138");
        xassert_eqq($u->prop("state"), "MA");
    }

    function test_csv_name_columns() {
        $this->delete_users("csvt3%");

        // `name` splits on the comma
        $this->csv_save("email,name\ncsvt3a@_.com,\"Adams, John Quincy\"\n");
        $u = $this->conf->fresh_user_by_email("csvt3a@_.com");
        xassert_eqq($u->firstName, "John Quincy");
        xassert_eqq($u->lastName, "Adams");

        // explicit first/last columns win over `name`
        $this->csv_save("email,name,firstName,lastName\n"
            . "csvt3b@_.com,Ignored Entirely,Millard,Fillmore\n");
        $u = $this->conf->fresh_user_by_email("csvt3b@_.com");
        xassert_eqq($u->firstName, "Millard");
        xassert_eqq($u->lastName, "Fillmore");

        // `user` supplies only components no other field gives
        $this->csv_save("email,user\ncsvt3c@_.com,\"Bill Bixby <other@_.com>\"\n");
        $u = $this->conf->fresh_user_by_email("csvt3c@_.com");
        xassert_eqq($u->firstName, "Bill");
        xassert_eqq($u->lastName, "Bixby");
        xassert_eqq($u->email, "csvt3c@_.com");

        $this->csv_save("email,user,firstName\ncsvt3d@_.com,\"Bill Bixby\",Lou\n");
        $u = $this->conf->fresh_user_by_email("csvt3d@_.com");
        xassert_eqq($u->firstName, "Lou");
        xassert_eqq($u->lastName, "Bixby");
    }

    function test_csv_address_multiline() {
        $this->delete_users("csvt4%");

        // interior blank and whitespace-only lines are dropped, not just
        // trailing ones (the CSV cell is trimmed before it gets here)
        $this->csv_save("email,address,city\n"
            . "csvt4@_.com,\"1 Main Street\n\n   \nApt 3\n\n\n\",Cambridge\n");
        $u = $this->conf->fresh_user_by_email("csvt4@_.com");
        xassert_eqq($u->prop("address"), ["1 Main Street", "Apt 3"]);
        xassert_eqq($u->prop("city"), "Cambridge");

        $this->csv_save("email,address1,address2,address3\ncsvt4b@_.com,1 Main Street,,Apt 3\n");
        $u = $this->conf->fresh_user_by_email("csvt4b@_.com");
        xassert_eqq($u->prop("address"), ["1 Main Street", "Apt 3"]);

        // changing one line reports a diff…
        $this->csv_save("email,address2\ncsvt4b@_.com,Apt 4\n");
        $u = $this->conf->fresh_user_by_email("csvt4b@_.com");
        xassert_eqq($u->prop("address"), ["1 Main Street", "Apt 4"]);
        xassert_eqq($this->csv_diffs[0], ["address"]);

        // …and re-saving the same value reports none
        $this->csv_save("email,address2\ncsvt4b@_.com,Apt 4\n");
        xassert_eqq($this->csv_diffs[0], []);

        // `city`, `state`, and `zip` are stored alongside `address` and
        // report under the same diff key
        $this->csv_save("email,city,state,zip\ncsvt4b@_.com,Cambridge,MA,02138\n");
        xassert_eqq($this->csv_diffs[0], ["address"]);
        $this->csv_save("email,city,state,zip\ncsvt4b@_.com,Cambridge,MA,02138\n");
        xassert_eqq($this->csv_diffs[0], []);

        // the whole-address and per-line forms cannot be mixed
        $r = $this->csv_save("email,address,address1\n"
            . "csvt4c@_.com,1 Main Street,Apt 3\n");
        xassert_eqq($r, [null]);
        xassert_str_contains($this->csv_feedback[0], "at most one of");
        xassert(!$this->conf->fresh_user_by_email("csvt4c@_.com"));

        // `address` is the whole address, so a shorter value replaces rather
        // than merging: no stale tail from the previous value
        $this->csv_save("email,address\ncsvt4d@_.com,\"1 Main Street\nApt 3\"\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt4d@_.com")->prop("address"),
            ["1 Main Street", "Apt 3"]);
        $this->csv_save("email,address\ncsvt4d@_.com,2 Oak Avenue\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt4d@_.com")->prop("address"),
            ["2 Oak Avenue"]);

        // …while a per-line update still merges with the stored lines
        $this->csv_save("email,address2\ncsvt4d@_.com,Suite 9\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt4d@_.com")->prop("address"),
            ["2 Oak Avenue", "Suite 9"]);
    }

    /** @return int */
    private function update_time($email) {
        return $this->conf->fresh_user_by_email($email)->prop("updateTime");
    }

    function test_address_no_op_leaves_update_time() {
        $this->delete_users("csvt16%");
        $this->csv_save("email,address1,address2,city\n"
            . "csvt16@_.com,1 Main Street,Apt 3,Cambridge\n");
        $t0 = $this->update_time("csvt16@_.com");
        xassert_eqq($t0, Conf::$now);

        Conf::advance_current_time(Conf::$now + 1);

        // re-saving identical data changes nothing — including `updateTime`,
        // which `PROP_DATA` props used to bump even when unchanged
        $this->csv_save("email,address1,address2,city\n"
            . "csvt16@_.com,1 Main Street,Apt 3,Cambridge\n");
        xassert_eqq($this->csv_diffs[0], []);
        xassert_eqq($this->update_time("csvt16@_.com"), $t0);

        // a real change does bump it
        $this->csv_save("email,city\ncsvt16@_.com,Somerville\n");
        xassert_eqq($this->csv_diffs[0], ["address"]);
        xassert_eqq($this->update_time("csvt16@_.com"), Conf::$now);
        xassert_gt(Conf::$now, $t0);
    }

    function test_csv_roles() {
        $this->delete_users("csvt5%");

        $this->csv_save("email,roles\ncsvt5@_.com,\"chair,sysadmin\"\n");
        $u = $this->conf->fresh_user_by_email("csvt5@_.com");
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE,
            Contact::ROLE_PC | Contact::ROLE_CHAIR | Contact::ROLE_ADMIN);

        // an absolute list replaces every role
        $this->csv_save("email,roles\ncsvt5@_.com,pc\n");
        xassert_eqq($this->csv_diffs[0], ["roles"]);
        xassert_eqq($this->conf->fresh_user_by_email("csvt5@_.com")->roles & Contact::ROLE_PCLIKE,
            Contact::ROLE_PC);

        // `+`/`-` adjust instead of replacing
        $this->csv_save("email,roles\ncsvt5@_.com,+sysadmin\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt5@_.com")->roles & Contact::ROLE_PCLIKE,
            Contact::ROLE_PC | Contact::ROLE_ADMIN);
        $this->csv_save("email,roles\ncsvt5@_.com,-pc\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt5@_.com")->roles & Contact::ROLE_PCLIKE,
            Contact::ROLE_ADMIN);

        // `none` clears
        $this->csv_save("email,roles\ncsvt5@_.com,none\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt5@_.com")->roles & Contact::ROLE_PCLIKE, 0);

        // an unknown role warns, and — since the list is absolute — clears roles
        $this->csv_save("email,roles\ncsvt5@_.com,chair\n");
        $r = $this->csv_save("email,roles\ncsvt5@_.com,reviewer\n");
        xassert_eqq($r, ["csvt5@_.com"]);
        xassert_str_contains($this->csv_feedback[0], "reviewer");
        xassert_eqq($this->conf->fresh_user_by_email("csvt5@_.com")->roles & Contact::ROLE_PCLIKE, 0);
    }

    function test_csv_tags() {
        $this->delete_users("csvt6%");

        $this->csv_save("email,roles,tags\ncsvt6@_.com,pc,\"red green#3\"\n");
        $u = $this->conf->fresh_user_by_email("csvt6@_.com");
        xassert($u->has_tag("red"));
        xassert_eqq($u->tag_value("green"), 3.0);

        // `tags` replaces the whole set
        $this->csv_save("email,tags\ncsvt6@_.com,blue\n");
        $u = $this->conf->fresh_user_by_email("csvt6@_.com");
        xassert($u->has_tag("blue"));
        xassert(!$u->has_tag("red"));

        // add/remove/change are incremental
        $this->csv_save("email,add_tag,remove_tag\ncsvt6@_.com,red,blue\n");
        $u = $this->conf->fresh_user_by_email("csvt6@_.com");
        xassert($u->has_tag("red"));
        xassert(!$u->has_tag("blue"));

        $this->csv_save("email,change_tags\ncsvt6@_.com,\"+blue -red\"\n");
        $u = $this->conf->fresh_user_by_email("csvt6@_.com");
        xassert($u->has_tag("blue"));
        xassert(!$u->has_tag("red"));

        // Role-ish automatic tags are accepted by `Tagger` but dropped by
        // `UserStatus::check_pc_tag`: the row succeeds and stores nothing.
        // Check `contactTags` directly — `has_tag` would answer for the
        // derived role rather than for what this CSV stored.
        $before = $this->conf->fresh_user_by_email("csvt6@_.com")->contactTags;
        $r = $this->csv_save("email,add_tags\ncsvt6@_.com,\"chair sysadmin pc enabled\"\n");
        xassert_eqq($r, ["csvt6@_.com"]);
        xassert_eqq($this->csv_diffs[0], []);
        xassert_eqq($this->conf->fresh_user_by_email("csvt6@_.com")->contactTags, $before);

        // reserved tags are rejected by `Tagger` instead, which fails the row
        $r = $this->csv_save("email,add_tags\ncsvt6@_.com,none\n");
        xassert_eqq($r, [null]);
        xassert_str_contains($this->csv_feedback[0], "reserved");
        xassert_eqq($this->conf->fresh_user_by_email("csvt6@_.com")->contactTags, $before);
    }

    function test_csv_follow() {
        $this->delete_users("csvt7%");

        $this->csv_save("email,roles,follow\ncsvt7@_.com,pc,\"review anyreview\"\n");
        $u = $this->conf->fresh_user_by_email("csvt7@_.com");
        xassert_eqq($u->defaultWatch & Contact::WATCH_REVIEW, Contact::WATCH_REVIEW);
        xassert_eqq($u->defaultWatch & Contact::WATCH_REVIEW_ALL, Contact::WATCH_REVIEW_ALL);

        // a plain list replaces
        $this->csv_save("email,follow\ncsvt7@_.com,review\n");
        $u = $this->conf->fresh_user_by_email("csvt7@_.com");
        xassert_eqq($u->defaultWatch & Contact::WATCH_REVIEW_ALL, 0);

        // `partial` keeps what isn't mentioned
        $this->csv_save("email,follow\ncsvt7@_.com,\"partial anyreview\"\n");
        $u = $this->conf->fresh_user_by_email("csvt7@_.com");
        xassert_eqq($u->defaultWatch & Contact::WATCH_REVIEW, Contact::WATCH_REVIEW);
        xassert_eqq($u->defaultWatch & Contact::WATCH_REVIEW_ALL, Contact::WATCH_REVIEW_ALL);

        // `none` clears
        $this->csv_save("email,follow\ncsvt7@_.com,none\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt7@_.com")->defaultWatch, 0);

        // unknown keywords warn but don't fail the row
        $r = $this->csv_save("email,follow\ncsvt7@_.com,\"review sillyness\"\n");
        xassert_eqq($r, ["csvt7@_.com"]);
        xassert_str_contains($this->csv_feedback[0], "sillyness");
    }

    function test_csv_topics() {
        $this->ensure_topics();
        $tids = $this->topic_ids();
        xassert(isset($tids["Architecture"]) && isset($tids["Security"])
                && isset($tids["Cloud computing"]));
        $this->delete_users("csvt8%");

        $this->csv_save("email,roles,topic: Architecture,topic: Security\n"
            . "csvt8@_.com,pc,high,-1\n");
        $ti = $this->conf->fresh_user_by_email("csvt8@_.com")->topic_interest_map();
        ksort($ti);
        xassert_eqq($ti, [$tids["Architecture"] => 2, $tids["Security"] => -1]);

        // `topic:` columns merge into existing interests by default…
        $this->csv_save("email,topic: Cloud computing\ncsvt8@_.com,low\n");
        $ti = $this->conf->fresh_user_by_email("csvt8@_.com")->topic_interest_map();
        ksort($ti);
        xassert_eqq($ti, [$tids["Cloud computing"] => -2,
                          $tids["Architecture"] => 2,
                          $tids["Security"] => -1]);

        // …a blank cell means no interest…
        $this->csv_save("email,topic: Architecture\ncsvt8@_.com,\n");
        $ti = $this->conf->fresh_user_by_email("csvt8@_.com")->topic_interest_map();
        xassert_eqq($ti[$tids["Architecture"]] ?? 0, 0);

        // …and `topic_override=no` applies only to users with no interests yet
        $this->csv_save("email,topic_override,topic: Security\ncsvt8@_.com,no,high\n");
        $ti = $this->conf->fresh_user_by_email("csvt8@_.com")->topic_interest_map();
        xassert_eqq($ti[$tids["Security"]] ?? 0, -1);

        $this->csv_save("email,roles,topic_override,topic: Security\ncsvt8b@_.com,pc,no,high\n");
        $ti = $this->conf->fresh_user_by_email("csvt8b@_.com")->topic_interest_map();
        xassert_eqq($ti[$tids["Security"]] ?? 0, 2);

        // an unrecognized topic column is collected in `unknown_topics`
        $this->csv_save("email,topic: Nonesuch\ncsvt8@_.com,high\n");
        xassert_eqq(array_keys($this->csv_us->unknown_topics ?? []), ["Nonesuch"]);
    }

    function test_csv_user_override() {
        $this->delete_users("csvt9%");

        $this->csv_save("email,name,affiliation\ncsvt9@_.com,Grover Cleveland,Buffalo\n");

        // by default an update overwrites nonempty profile fields
        $this->csv_save("email,name,affiliation\ncsvt9@_.com,Benjamin Harrison,Indianapolis\n");
        $u = $this->conf->fresh_user_by_email("csvt9@_.com");
        xassert_eqq($u->firstName, "Benjamin");
        xassert_eqq($u->affiliation, "Indianapolis");

        // `user_override=no` fills only fields that are currently empty
        $this->csv_save("email,name,affiliation,collaborators,user_override\n"
            . "csvt9@_.com,Grover Cleveland,Buffalo,None,no\n");
        $u = $this->conf->fresh_user_by_email("csvt9@_.com");
        xassert_eqq($u->firstName, "Benjamin");
        xassert_eqq($u->affiliation, "Indianapolis");
        xassert_eqq($u->collaborators(), "None");

        // `IF_EMPTY_PROFILE`, which is what Bulk update uses without the
        // “Override existing” checkbox, behaves the same way
        $this->csv_save("email,name,affiliation\ncsvt9@_.com,William McKinley,Canton\n",
            UserStatus::IF_EMPTY_PROFILE);
        $u = $this->conf->fresh_user_by_email("csvt9@_.com");
        xassert_eqq($u->firstName, "Benjamin");
        xassert_eqq($u->affiliation, "Indianapolis");
    }

    function test_csv_disabled_and_preferred_email() {
        $this->delete_users("csvt10%");

        $this->csv_save("email,disabled\ncsvt10@_.com,yes\n");
        xassert($this->conf->fresh_user_by_email("csvt10@_.com")->is_disabled());

        $this->csv_save("email,disabled\ncsvt10@_.com,no\n");
        xassert(!$this->conf->fresh_user_by_email("csvt10@_.com")->is_disabled());

        // `preferred_email` is parsed but only saved where the site allows it
        $this->csv_save("email,preferredemail\ncsvt10@_.com,elsewhere@_.com\n");
        $u = $this->conf->fresh_user_by_email("csvt10@_.com");
        if ($this->conf->allow_preferred_email()) {
            xassert_eqq($u->preferredEmail, "elsewhere@_.com");
        } else {
            xassert_eqq($u->preferredEmail, null);
        }
    }

    function test_csv_rows_are_independent() {
        $this->delete_users("csvt11%");

        $r = $this->csv_save("email,roles\n"
            . "not an email,pc\n"
            . "csvt11@_.com,pc\n");
        xassert_eqq($r, [null, "csvt11@_.com"]);
        xassert_str_contains($this->csv_feedback[0], "Invalid email address");
        xassert_eqq($this->csv_feedback[1], "");
        xassert_eqq($this->conf->fresh_user_by_email("csvt11@_.com")->roles & Contact::ROLE_PCLIKE,
            Contact::ROLE_PC);
    }

    function test_csv_comment_lines() {
        $this->delete_users("csvt12%");

        $r = $this->csv_save("email,roles\n"
            . "### this line is a comment\n"
            . "csvt12@_.com,pc\n");
        xassert_eqq($r, ["csvt12@_.com"]);
    }

    function test_csv_dash_clears() {
        $this->delete_users("csvt13%");

        $this->csv_save("email,roles,affiliation,collaborators,city,country,"
            . "tags,follow,address1,address2\n"
            . "csvt13@_.com,pc,Michigan,Bob Bell (MIT),Ann Arbor,US,"
            . "red,\"review anyreview\",1 Main Street,Apt 3\n");
        $u = $this->conf->fresh_user_by_email("csvt13@_.com");
        xassert_eqq($u->affiliation, "Michigan");
        xassert_eqq($u->prop("address"), ["1 Main Street", "Apt 3"]);

        // a lone dash clears; a blank cell still leaves the value alone
        $this->csv_save("email,affiliation,city,country,tags,follow,address2\n"
            . "csvt13@_.com,-,-,-,-,-,-\n");
        $u = $this->conf->fresh_user_by_email("csvt13@_.com");
        xassert_eqq($u->affiliation, "");
        xassert_eqq($u->prop("city"), null);
        xassert_eqq($u->prop("country"), null);
        xassert_eqq($u->contactTags, null);
        xassert_eqq($u->defaultWatch, 0);
        xassert_eqq($u->prop("address"), ["1 Main Street"]);
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC);

        // en dash and em dash mean the same thing
        $this->csv_save("email,collaborators\ncsvt13@_.com,–\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt13@_.com")->prop("collaborators"), null);
        $this->csv_save("email,affiliation\ncsvt13@_.com,Michigan\n");
        $this->csv_save("email,affiliation\ncsvt13@_.com,—\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt13@_.com")->affiliation, "");
    }

    function test_csv_dash_exceptions() {
        $this->delete_users("csvt14%");

        $this->csv_save("email,roles,disabled,lastName\n"
            . "csvt14@_.com,\"pc,sysadmin\",yes,Adams\n");
        $u = $this->conf->fresh_user_by_email("csvt14@_.com");
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC | Contact::ROLE_ADMIN);
        xassert($u->is_disabled());

        // `roles` and `disabled` ignore a dash — they have their own
        // `none`/`no` keywords, and clearing them isn't a text edit
        $this->csv_save("email,roles,disabled\ncsvt14@_.com,-,-\n");
        xassert_eqq($this->csv_diffs[0], []);
        $u = $this->conf->fresh_user_by_email("csvt14@_.com");
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC | Contact::ROLE_ADMIN);
        xassert($u->is_disabled());

        // `lastName` keeps a literal dash: it is a real surname placeholder
        $this->csv_save("email,lastName\ncsvt14@_.com,-\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt14@_.com")->lastName, "-");

        // `collaborators` of “None” is data, not a clear — that is what the
        // dash is for
        $this->csv_save("email,collaborators\ncsvt14@_.com,None\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt14@_.com")->prop("collaborators"), "None");
        $this->csv_save("email,collaborators\ncsvt14@_.com,-\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt14@_.com")->prop("collaborators"), null);
    }

    function test_qreq_address_lines() {
        $this->delete_users("csvt15%");
        $this->csv_save("email,address1,address2\ncsvt15@_.com,1 Main Street,Apt 3\n");

        // the web form submits every line, so it is authoritative: a blank
        // input clears that line rather than keeping the stored one
        list($viewer, $qreq) = $this->make_qreq_for("chair@_.com", [
            "addressLine1" => "2 Oak Avenue", "addressLine2" => "",
            "addressLine3" => "Floor 4", "city" => "Somerville",
            "state" => "MA", "zipCode" => "02143"
        ]);
        $us = (new UserStatus($viewer))->set_qreq($qreq);
        $us->start_update()->set_user($this->conf->fresh_user_by_email("csvt15@_.com"));
        $us->request_group("");
        xassert($us->execute_update());

        $u = $this->conf->fresh_user_by_email("csvt15@_.com");
        xassert_eqq($u->prop("address"), ["2 Oak Avenue", "Floor 4"]);
        xassert_eqq($u->prop("city"), "Somerville");
        xassert_eqq($u->prop("zip"), "02143");
    }

    /* Theme preference (dark mode). Stored in the `data` JSON blob via
       `Contact::$props`; null means “auto”, i.e. follow the OS setting. */

    function test_theme_prop() {
        $u = $this->conf->fresh_user_by_email("estrin@usc.edu");
        xassert_eqq($u->theme(), null);
        $u->set_prop("theme", "dark");
        xassert($u->prop_changed("theme"));
        xassert($u->save_prop());
        $u = $this->conf->fresh_user_by_email("estrin@usc.edu");
        xassert_eqq($u->prop("theme"), "dark");
        xassert_eqq($u->theme(), "dark");

        // `theme()` does not expose junk stored in the blob
        $u->set_prop("theme", "purple");
        xassert_eqq($u->prop("theme"), "purple");
        xassert_eqq($u->theme(), null);
        $u->abort_prop();

        // the empty string clears the preference
        $u->set_prop("theme", "");
        xassert($u->save_prop());
        $u = $this->conf->fresh_user_by_email("estrin@usc.edu");
        xassert_eqq($u->prop("theme"), null);
    }

    function test_qreq_theme() {
        list($u, $qreq) = $this->make_qreq_for("estrin@usc.edu", ["theme" => "dark"]);
        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        $us->request_group("");
        xassert_eqq($us->jval->theme, "dark");
        xassert($us->execute_update());
        xassert_eqq($us->diffs["theme"] ?? null, true);
        $u = $this->conf->fresh_user_by_email("estrin@usc.edu");
        xassert_eqq($u->theme(), "dark");

        // a self-save updates the global profile as well
        if ($this->conf->contactdb()) {
            $cdbu = $this->conf->fresh_cdb_user_by_email("estrin@usc.edu");
            xassert_eqq($cdbu->prop("theme"), "dark");
        }

        // an invalid value warns and leaves the setting alone
        list($u, $qreq) = $this->make_qreq_for("estrin@usc.edu", ["theme" => "purple"]);
        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        $us->request_group("");
        xassert($us->execute_update());
        xassert_str_contains($us->full_feedback_text(), "Theme");
        $u = $this->conf->fresh_user_by_email("estrin@usc.edu");
        xassert_eqq($u->theme(), "dark");

        // `auto` clears the preference
        list($u, $qreq) = $this->make_qreq_for("estrin@usc.edu", ["theme" => "auto"]);
        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        $us->request_group("");
        xassert($us->execute_update());
        $u = $this->conf->fresh_user_by_email("estrin@usc.edu");
        xassert_eqq($u->prop("theme"), null);
        if ($this->conf->contactdb()) {
            $cdbu = $this->conf->fresh_cdb_user_by_email("estrin@usc.edu");
            xassert_eqq($cdbu->prop("theme"), null);
        }
    }

    function test_csv_theme() {
        $this->delete_users("csvt17%");

        $this->csv_save("email,theme\ncsvt17@_.com,dark\n");
        $u = $this->conf->fresh_user_by_email("csvt17@_.com");
        xassert_eqq($u->theme(), "dark");

        // a blank cell leaves the preference alone
        $this->csv_save("email,theme\ncsvt17@_.com,\n");
        xassert_eqq($this->csv_diffs[0], []);
        xassert_eqq($this->conf->fresh_user_by_email("csvt17@_.com")->theme(), "dark");

        // values are case-insensitive; a change reports a `theme` diff
        $this->csv_save("email,theme\ncsvt17@_.com,Light\n");
        xassert_eqq($this->csv_diffs[0], ["theme"]);
        xassert_eqq($this->conf->fresh_user_by_email("csvt17@_.com")->theme(), "light");

        // a dash clears, as does `auto`
        $this->csv_save("email,theme\ncsvt17@_.com,-\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt17@_.com")->prop("theme"), null);
        $this->csv_save("email,theme\ncsvt17@_.com,dark\n");
        $this->csv_save("email,theme\ncsvt17@_.com,auto\n");
        xassert_eqq($this->conf->fresh_user_by_email("csvt17@_.com")->prop("theme"), null);

        // unknown values warn but don't fail the row or change the setting
        $this->csv_save("email,theme\ncsvt17@_.com,dark\n");
        $r = $this->csv_save("email,theme\ncsvt17@_.com,purple\n");
        xassert_eqq($r, ["csvt17@_.com"]);
        xassert_str_contains($this->csv_feedback[0], "Theme");
        xassert_eqq($this->conf->fresh_user_by_email("csvt17@_.com")->theme(), "dark");
    }

    function finalize() {
        $this->delete_users("csvt%");
        $emails = ["van@ee.lbl.gov", "raju@watson.ibm.com", "chris@w3.org"];
        $this->delete_secondary(false, "xvan@usc.edu");
        $this->delete_secondary(false, "yvan@usc.edu");
        $this->delete_secondary(false, "rajuu@watson.edu");
        $this->conf->qe("update ContactInfo set roles=0, cflags=cflags&~? where email?a", Contact::CF_PRIMARY, $emails);
        $this->conf->qe("delete from ContactPrimary where contactId?a", [$this->raju_uid, $this->chris_uid]);
        if (($cdb = $this->conf->contactdb())) {
            $this->delete_secondary(true, "xvan@usc.edu");
            $this->delete_secondary(true, "yvan@usc.edu");
            $this->delete_secondary(true, "rajuu@watson.edu");
            Dbl::qe($cdb, "update ContactInfo set cflags=cflags&~? where email?a", Contact::CF_PRIMARY, $emails);
            Dbl::qe($cdb, "delete from ContactPrimary where contactId in (select contactDbId from ContactInfo where email?a)", $emails);
        }
    }
}
