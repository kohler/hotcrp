<?php
// pages/p_profile.php -- HotCRP profile management page
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Profile_Page {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var Qrequest
     * @readonly */
    public $qreq;

    /** @var Contact */
    public $user;
    /** @var UserStatus */
    public $ustatus;
    /** @var int */
    public $page_type = 0;
    /** @var string */
    public $topic;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;

        $this->user = $viewer;
        $this->ustatus = new UserStatus($viewer);
        $this->ustatus->set_qreq($qreq);
    }


    /** @return never */
    private function fail_user_search($text) {
        $action_bar = $this->viewer->privChair ? QuicklinksRenderer::make($this->qreq, "account") : "";
        Multiconference::fail($this->qreq, 404, [
            "title" => "Profile", "action_bar" => $action_bar
        ], $text);
    }

    /** @param string $u
     * @return Contact */
    private function handle_user_search($u) {
        if (!$this->viewer->privChair) {
            Multiconference::fail($this->qreq, 403, ["title" => "Profile"], "<5>Permission error: you can only access <a href=\"" . $this->conf->hoturl("profile", ["u" => null]) . "\">your own profile</a>");
        }

        $user = null;
        if (ctype_digit($u)) {
            $user = $this->conf->user_by_id(intval($u));
        } else if ($u === "" && $this->qreq->search) {
            $this->conf->redirect_hoturl("users");
        } else if (($user = $this->conf->user_by_email($u))) {
            // OK
        } else if ($this->qreq->search) {
            $cs = new ContactSearch(ContactSearch::F_USER, $u, $this->viewer);
            if ($cs->user_ids()) {
                $list = (new SessionList("u/all/" . urlencode($this->qreq->search), $cs->user_ids(), "“{$u}”"))
                    ->set_urlbase($this->conf->hoturl_raw("users", ["t" => "all"], Conf::HOTURL_SITEREL));
                $list->set_cookie($this->qreq);
                $user = $this->conf->user_by_id($cs->user_ids()[0]);
                $this->conf->redirect_hoturl("profile", ["u" => $user->email]);
            } else {
                $this->fail_user_search("<0>User matching ‘{$u}’ not found");
            }
        }
        if (!$user || $user->is_deleted()) {
            $this->fail_user_search("<0>User {$u} not found");
        }

        if (isset($this->qreq->profile_contactid)
            && $this->qreq->profile_contactid !== (string) $user->contactId) {
            if (isset($this->qreq->save) || isset($this->qreq->savebulk)) {
                $this->conf->error_msg("<0>Changes not saved; your session has changed since you last loaded this tab");
            }
            $this->conf->redirect_self($this->qreq, ["u" => $u]);
        }

        if ($user->contactId > 0 && $user->contactId === $this->viewer->contactId) {
            return $this->viewer;
        }
        return $user;
    }

    private function find_user() {
        // analyze URL and request
        if ($this->qreq->u === null && ($this->qreq->user || $this->qreq->contact)) {
            $this->qreq->u = $this->qreq->user ? : $this->qreq->contact;
        }
        if (($p = $this->qreq->path_component(0)) !== null) {
            if (in_array($p, ["", "me", "self", "new", "bulk"], true)
                || strpos($p, "@") !== false
                || !$this->ustatus->cs()->might_exist($p)) {
                if ($this->qreq->u === null) {
                    $this->qreq->u = urldecode($p);
                }
                if (($p = $this->qreq->path_component(1)) !== null
                    && $this->qreq->t === null) {
                    $this->qreq->t = $p;
                }
            } else if ($this->qreq->t === null) {
                $this->qreq->t = $p;
            }
        }

        // find requested user
        $u = $this->qreq->u ?? "me";
        if ($u === "me"
            || $u === "self"
            || $u === ""
            || ($this->viewer->has_email() && strcasecmp($u, $this->viewer->email) === 0)
            || ($this->viewer->contactId > 0 && $u === (string) $this->viewer->contactId)) {
            $user = $this->viewer;
        } else if ($this->viewer->privChair
                   && ($u === "new" || $u === "bulk")) {
            $user = Contact::make_placeholder($this->conf);
            $this->page_type = $u === "new" ? 1 : 2;
        } else {
            $user = $this->handle_user_search($u);
        }

        $this->user = $user;
    }


    /** @param UserStatus $ustatus
     * @return ?Contact */
    private function save_user($ustatus) {
        // check for missing fields
        UserStatus::normalize_name($ustatus->jval);

        // check email
        $uemail = $ustatus->jval->email ?? null;
        if (!$ustatus->user) {
            if (!$uemail) {
                $what = $this->conf->external_login() ? "Username" : "Email address";
                $ustatus->error_at("email", "<0>{$what} required");
                return null;
            } else if (($acct2 = $this->conf->fresh_user_by_email($uemail))) {
                $ustatus->jval->id = $acct2->contactId;
            } else if (!$this->conf->external_login() && !validate_email($uemail)) {
                $ustatus->error_at("email", "<0>Invalid email address");
                return null;
            }
        } else if ($uemail && strcasecmp($uemail, $ustatus->user->email) !== 0) {
            $ustatus->error_at("email", "<0>Email change ignored");
            $ustatus->inform_at("email", "<0>Use ‘Manage email’ to manage your accounts’ email addresses.");
        }

        // save account
        return $ustatus->execute_update() ? $ustatus->user : null;
    }


    /** @return \Generator<MessageItem> */
    private function decorated_message_list(MessageSet $msx, ?UserStatus $us = null) {
        $ms = (new MessageSet)->set_ignore_duplicates(MessageSet::IGNORE_DUPS_FIELD);
        foreach ($msx->message_list() as $mi) {
            if (($mi->field ?? "") !== ""
                && str_ends_with($mi->field, ":context")) {
                continue;
            }
            if ($us
                && $mi->field
                && $mi->message !== ""
                && ($l = $us->field_label($mi->field))
                && $ms->message_index($mi) === false
                && $mi->status !== MessageSet::INFORM) {
                $mi = clone $mi;
                $mi->message = "<5><a href=\"#{$mi->field}\">{$l}</a>: " . $mi->message_as(5);
            }
            $ms->append_item($mi);
        }
        foreach ($ms->message_list() as $mi) {
            yield $mi;
        }
    }

    /** @return MessageItem */
    private function linked_secondary_warning_note(UserStatus $ustatus, Contact $user) {
        return MessageItem::warning_note("<5>" . htmlspecialchars($ustatus->linked_secondary) . " is linked to primary account " . Ht::link(htmlspecialchars($user->email), $this->conf->hoturl("profile", ["u" => $user->email])));
    }

    /** @param string $text
     * @param string $filename */
    private function save_bulk($text, $filename) {
        $text = cleannl(convert_to_utf8($text));
        $ms = new MessageSet;
        $success = $nochanges = $notified = [];

        if (!preg_match('/\A[^\r\n]*(?:,|\A)(?:user|email)(?:[,\r\n]|\z)/', $text)
            && !preg_match('/\A[^\r\n]*,[^\r\n]*,/', $text)) {
            $tarr = CsvParser::split_lines($text);
            foreach ($tarr as &$t) {
                if (($t = trim($t)) && $t[0] !== "#" && $t[0] !== "%") {
                    $t = CsvGenerator::quote($t);
                }
                $t .= "\n";
            }
            unset($t);
            $text = join("", $tarr);
        }

        $csv = new CsvParser($text);
        $csv->set_filename($filename ? "{$filename}:" : "line ");
        $csv->add_comment_prefix("#")->add_comment_prefix("%");
        if (($line = $csv->peek_list())) {
            if (preg_grep('/\A(?:email|user)\z/i', $line)) {
                $csv->set_header($line);
                $csv->next_list();
            } else if (count($line) == 1) {
                $csv->set_header(["user"]);
            } else {
                // interpolate a likely header
                $hdr = [];
                for ($i = 0; $i < count($line); ++$i) {
                    if (validate_email($line[$i])
                        && array_search("email", $hdr) === false) {
                        $hdr[] = "email";
                    } else if (strpos($line[$i], " ") !== false
                               && array_search("name", $hdr) === false) {
                        $hdr[] = "name";
                    } else if (preg_match('/\A(?:pc|chair|sysadmin|admin)\z/i', $line[$i])
                               && array_search("roles", $hdr) === false) {
                        $hdr[] = "roles";
                    } else if (array_search("name", $hdr) !== false
                               && array_search("affiliation", $hdr) === false) {
                        $hdr[] = "affiliation";
                    } else {
                        $hdr[] = "unknown" . count($hdr);
                    }
                }
                $csv->set_header($hdr);
                $mi = $ms->warning_at(null, "<5>Header missing, assuming ‘<code>" . join(",", $hdr) . "</code>’");
                $mi->landmark = $csv->landmark();
            }
        }

        $ustatus = new UserStatus($this->viewer);
        $ustatus->no_deprivilege_self = true;
        if (!friendly_boolean($this->qreq->bulkoverride)) {
            $ustatus->set_if_empty(UserStatus::IF_EMPTY_PROFILE);
        }
        $ustatus->set_follow_primary(true);
        $ustatus->add_csv_synonyms($csv);

        while (($line = $csv->next_row())) {
            $ustatus->clear_messages();
            $ustatus->start_update();
            $ustatus->csvreq = $line;
            $ustatus->parse_csv_group("");
            $ustatus->set_notify(friendly_boolean($line["notify"]) ?? true);
            $saved_user = $this->save_user($ustatus);
            if ($saved_user) {
                $url = $this->conf->hoturl("profile", "u=" . urlencode($saved_user->email));
                $link = "<a class=\"nb\" href=\"{$url}\">" . $saved_user->name_h(NAME_E) . "</a>";
                if ($ustatus->linked_secondary) {
                    $ms->append_item($this->linked_secondary_warning_note($ustatus, $saved_user));
                }
                if ($ustatus->notified) {
                    $notified[] = $link;
                } else if (!empty($ustatus->diffs)) {
                    $success[] = $link;
                } else {
                    $nochanges[] = $link;
                }
            } else {
                $link = null;
            }
            foreach ($ustatus->message_list() as $mi) {
                $mi->landmark = $csv->landmark();
                if ($link !== null
                    && $mi->message !== ""
                    && $mi->status !== MessageSet::INFORM) {
                    $mi->message = "<5>" . $mi->message_as(5) . " (account {$link})";
                }
                $ms->append_item($mi);
            }
        }

        if (!empty($ustatus->unknown_topics)) {
            $ms->warning_at(null, $this->conf->_("<0>Unknown topics ignored ({:list})", array_keys($ustatus->unknown_topics)));
        }
        $ml1 = $ml2 = [];
        if (!empty($notified)) {
            $ml2[] = MessageItem::success($this->conf->_("<5>Accounts {:list} saved and notified", $notified));
        }
        if (!empty($success)) {
            $ml2[] = MessageItem::success($this->conf->_("<5>Accounts {:list} saved", $success));
        }
        if (empty($notified) && empty($success) && $ms->has_error()) {
            $ml1[] = MessageItem::error($this->conf->_("<0>Changes not saved; please correct these errors and try again"));
        }
        if ((!empty($notified) || !empty($success)) && !empty($nochanges)) {
            $ml2[] = MessageItem::warning_note($this->conf->_("<5>No changes to accounts {:list}", $nochanges));
        }
        if (empty($ml1) && empty($ml2) && !$ms->has_message()) {
            $ml1[] = MessageItem::warning_note("<0>No changes");
        }
        $this->conf->feedback_msg($ml1, $this->decorated_message_list($ms), $ml2);
        return !$ms->has_error();
    }


    private function handle_save() {
        assert($this->user->is_empty() === ($this->page_type !== 0));

        // prepare UserStatus
        $this->ustatus->start_update();
        $this->ustatus->no_deprivilege_self = true;
        if ($this->page_type === 0) {
            $this->ustatus->set_user($this->user);
        } else {
            $this->ustatus->set_if_empty(UserStatus::IF_EMPTY_MOST);
            $this->ustatus->set_notify(true);
            $this->ustatus->set_follow_primary(true);
        }

        // parse request
        $this->ustatus->request_group("");

        // save request
        $saved_user = $this->save_user($this->ustatus);

        // report messages
        $ml = [];
        $purl = $this->conf->hoturl("profile", ["u" => $saved_user ? $saved_user->email : null]);
        if ($this->ustatus->has_error()) {
            $ml[] = MessageItem::error("<0>Changes not saved; please correct the highlighted errors and try again");
        } else if ($this->ustatus->created && $this->ustatus->notified) {
            $ml[] = MessageItem::success("<5>Account " . Ht::link($saved_user->name_h(NAME_E), $purl) . " created and notified");
        } else if ($this->ustatus->created) {
            $ml[] = MessageItem::success("<5>Account " . Ht::link($saved_user->name_h(NAME_E), $purl) . " created, but not notified");
        } else {
            if ($this->ustatus->linked_secondary) {
                $ml[] = $this->linked_secondary_warning_note($this->ustatus, $saved_user);
            }
            if ($this->page_type !== 0) {
                $ml[] = MessageItem::warning_note("<5>User " . Ht::link($saved_user->name_h(NAME_E), $purl) . " already had an account on this site");
            }
            if ($this->page_type !== 0 || $this->user !== $this->viewer) {
                $diffs = " to " . commajoin(array_keys($this->ustatus->diffs));
            } else {
                $diffs = "";
            }
            if (empty($this->ustatus->diffs)) {
                if (!$this->ustatus->has_message_at("email_confirm")) {
                    $ml[] = MessageItem::warning_note("<0>No changes");
                }
            } else if ($this->ustatus->notified) {
                $ml[] = MessageItem::success("<0>Changes saved{$diffs} and user notified");
            } else {
                $ml[] = MessageItem::success("<0>Changes saved{$diffs}");
            }
        }
        $this->conf->feedback_msg($ml, $this->decorated_message_list($this->ustatus, $this->ustatus));

        // exit on error
        if ($this->ustatus->has_error()) {
            return;
        }

        // redirect on success
        if (isset($this->qreq->redirect)) {
            $this->conf->redirect();
        }
        $xcj = [];
        if ($this->page_type !== 0) {
            $roles = $this->ustatus->jval->roles ?? [];
            if (in_array("chair", $roles, true)) {
                $xcj["pctype"] = "chair";
            } else if (in_array("pc", $roles, true)) {
                $xcj["pctype"] = "pc";
            } else {
                $xcj["pctype"] = "none";
            }
            if (in_array("sysadmin", $roles, true)) {
                $xcj["ass"] = 1;
            }
            $xcj["contactTags"] = join(" ", $this->ustatus->jval->tags ?? []);
        }
        if ($this->ustatus->has_problem()) {
            $xcj["warning_fields"] = $this->ustatus->problem_fields();
        }
        $this->qreq->set_csession("profile_redirect", $xcj);
        if ($this->user !== $this->viewer && $this->page_type === 0) {
            $this->conf->redirect_self($this->qreq, ["u" => $this->user->email]);
        } else {
            $this->conf->redirect_self($this->qreq);
        }
    }

    private function handle_save_bulk() {
        if ($this->qreq->has_file("bulk")) {
            $text = $this->qreq->file_content("bulk");
            if ($text === false) {
                $this->conf->error_msg("<0>Internal error: cannot read uploaded file");
                return;
            }
            $filename = $this->qreq->file_filename("bulk");
        } else {
            $text = $this->qreq->bulkentry;
            $filename = "";
        }
        if (trim($text) !== "" && trim($text) !== "Enter users one per line") {
            if ($this->save_bulk($text, $filename)) {
                $this->conf->redirect_self($this->qreq);
            }
        } else {
            $this->conf->feedback_msg(MessageItem::warning_note("<0>No changes"));
        }
    }

    private function handle_delete() {
        $ua = new UserActions($this->viewer);
        $ua->delete($this->user);
        $ok = !empty($ua->name_list("deleted"));
        if ($ok) {
            $ua->append_item(MessageItem::success("<0>Account {:list} deleted", $ua->name_list("deleted")));
        }
        $this->conf->feedback_msg($ua);
        if ($ok) {
            $this->conf->redirect_hoturl("users", "t=all");
        }
    }

    function handle_request() {
        $this->find_user();
        if ($this->qreq->cancel) {
            $this->conf->redirect_self($this->qreq);
        } else if ($this->qreq->reauth
                   && $this->qreq->valid_post()) {
            if (!$this->ustatus->has_error()) {
                $this->conf->redirect_self($this->qreq);
            }
        } else if ($this->qreq->savebulk
                   && $this->page_type !== 0
                   && $this->qreq->valid_post()) {
            $this->handle_save_bulk();
        } else if ($this->qreq->save
                   && $this->qreq->valid_post()) {
            $this->handle_save();
        } else if ($this->qreq->delete
                   && $this->qreq->valid_post()) {
            $this->handle_delete();
        }
    }


    private function prepare_and_crosscheck() {
        // import properties from cdb
        if (($cdbu = $this->user->cdb_user())) {
            $this->user->import_prop($cdbu, 1);
            if ($this->user->prop_changed()) {
                $this->user->save_prop();
            }
        }

        // handle session, adjust request
        $this->qreq->unset_csession("freshlogin");
        if (($prdj = $this->qreq->csession("profile_redirect"))) {
            $this->qreq->unset_csession("profile_redirect");
            foreach ($prdj as $k => $v) {
                if ($k === "warning_fields") {
                    foreach ($v as $k) {
                        $this->ustatus->warning_at($k);
                    }
                } else {
                    $this->qreq->$k = $v;
                }
            }
        }
        if ($this->viewer->privChair
            && $this->page_type !== 0
            && empty($this->ustatus->jval->roles)) {
            if (in_array($this->qreq->role, ["pc", "chair"], true)) {
                $this->qreq->pctype = $this->qreq->role;
            } else if ($this->qreq->role === "sysadmin") {
                $this->qreq->ass = "1";
            }
        }

        // crosscheck
        if ($this->page_type === 0) {
            foreach ($this->ustatus->cs()->members("__crosscheck", "crosscheck_function") as $gj) {
                $this->ustatus->cs()->call_function($gj, $gj->crosscheck_function, $gj);
            }
        }
    }

    function print() {
        // canonicalize topic
        $this->ustatus->set_user($this->user);
        $reqtopic = $this->qreq->t ? : "main";
        if ($this->page_type === 0
            && $reqtopic !== "main"
            && !str_starts_with($reqtopic, "__")
            && ($g = $this->ustatus->cs()->canonical_group($reqtopic))) {
            $this->topic = $g;
        } else {
            $this->topic = "main";
        }
        if ($this->qreq->t
            && $this->qreq->t !== $this->topic
            && $this->qreq->is_get()) {
            $this->qreq->t = $this->topic === "main" ? null : $this->topic;
            $this->conf->redirect_self($this->qreq);
        }
        $this->ustatus->cs()->set_root($this->topic);

        // set session list
        if ($this->page_type === 0
            && ($list = SessionList::load_cookie($this->viewer, "u"))
            && $list->set_current_id($this->user->contactId)) {
            $this->qreq->set_active_list($list);
        }

        // check $use_req
        $use_req = (!$this->user->has_account_here() && isset($this->qreq->follow_review))
            || $this->ustatus->has_error();

        // maybe prepare & crosscheck
        $this->ustatus->user_json(); // set `$this->ustatus->jval`
        if (!$this->ustatus->has_error()
            && ($this->user->has_account_here()
                || !isset($this->qreq->follow_review))) {
            $this->prepare_and_crosscheck();
            $want_ustatus_message = true;
        } else {
            $want_ustatus_message = $this->conf->saved_messages_status() < 1;
        }

        // set title
        if ($this->page_type === 2) {
            $title = "Bulk update";
        } else if ($this->page_type === 1) {
            $title = "New account";
        } else if ($this->user === $this->viewer) {
            $title = "Profile";
            $this->qreq->set_annex("profile_self", true);
        } else {
            $title = $this->viewer->name_html_for($this->user) . " profile";
        }
        $this->qreq->print_header($title, "account", [
            "title_div" => "",
            "body_class" => "leftmenu",
            "action_bar" => QuicklinksRenderer::make($this->qreq, "account"),
            "save_messages" => true
        ]);

        // start form
        $form_params = [];
        if ($this->page_type === 2) {
            $form_params["u"] = "bulk";
        } else if ($this->page_type === 1) {
            $form_params["u"] = "new";
        } else if ($this->user !== $this->viewer) {
            $form_params["u"] = $this->user->email;
        }
        $form_params["t"] = $this->qreq->t;
        if (isset($this->qreq->ls)) {
            $form_params["ls"] = $this->qreq->ls;
        }
        echo Ht::form($this->conf->hoturl("=profile", $form_params), [
            "id" => "f-profile",
            "class" => "need-diff-check need-unload-protection",
            "data-user" => $this->page_type ? null : $this->user->email
        ]);

        // left menu
        echo '<div class="leftmenu-left">',
            '<nav class="leftmenu-menu collapsed" aria-label="Profile groups">',
            '<h1 class="leftmenu"><button type="button" class="q ui js-leftmenu">Account',
            '<span class="leftmenu-not-left">', aria_plus_expander("after"), '</span>',
            '</button></h1><ul class="leftmenu-list">';

        if ($this->viewer->privChair) {
            foreach ([["New account", "new"], ["Bulk update", "bulk"], ["Your profile", null]] as $t) {
                if (!$t[1] && $this->page_type === 0 && $this->user === $this->viewer) {
                    continue;
                }
                $active = $t[1] && $this->page_type === ($t[1] === "new" ? 1 : 2);
                echo '<li class="leftmenu-item font-italic',
                    $active ? ' active" aria-current="page' : ' ui js-click-child',
                    '">';
                if ($active) {
                    echo $t[0];
                } else {
                    echo Ht::link($t[0], $this->conf->selfurl($this->qreq, ["u" => $t[1], "t" => null]));
                }
                echo '</li>';
            }
        }

        if ($this->page_type === 0) {
            $first = $this->viewer->privChair;
            $cs = $this->ustatus->cs();
            foreach ($cs->members("", "title") as $gj) {
                $disabled = false;
                if (isset($gj->display_if)) {
                    $disp = $gj->display_if;
                    if (is_string($disp) && $disp !== "dim") {
                        $disp = $cs->call_function($gj, $disp);
                    }
                    if (!$disp) {
                        continue;
                    } else if ($disp === "dim") {
                        $disabled = true;
                    }
                }
                echo '<li class="leftmenu-item',
                    $first ? ' leftmenu-item-gap4' : '',
                    $gj->name === $this->topic ? ' active" aria-current="page' : ' ui js-click-child',
                    '">';
                $title = $gj->short_title ?? $gj->title;
                if ($gj->name === $this->topic) {
                    echo $title;
                } else {
                    $aextra = [];
                    if ($disabled) {
                        $aextra["class"] = "dim";
                    }
                    echo Ht::link($title, $this->conf->selfurl($this->qreq, ["t" => $gj->name]), $aextra);
                }
                echo '</li>';
                $first = false;
            }
        }

        echo '</ul>';

        if ($this->page_type === 0 || $this->page_type === 1) {
            $t = $this->page_type === 0 ? "Save changes" : "Create account";
            echo '<div class="leftmenu-if-left if-differs mt-5">',
                Ht::submit("save", $t, ["class" => "btn-primary"]), '</div>';
        }

        echo '</nav></div>',
            '<div class="leftmenu-content main-column">';

        if ($this->page_type === 2) {
            echo '<h2 class="leftmenu">Bulk update</h2>';
        } else {
            echo Ht::hidden("profile_contactid", $this->user->contactId);
            if (isset($this->qreq->redirect)) {
                echo Ht::hidden("redirect", $this->qreq->redirect);
            }

            echo '<div id="foldaccount" class="';
            if ($this->qreq->pctype === "chair"
                || $this->qreq->pctype === "pc"
                || (!isset($this->qreq->pctype)
                    && ($this->user->roles & Contact::ROLE_PC) !== 0)) {
                echo "fold1o fold2o";
            } else if ($this->qreq->ass
                       || (!isset($this->qreq->pctype)
                           && ($this->user->roles & Contact::ROLE_ADMIN) !== 0)) {
                echo "fold1c fold2o";
            } else {
                echo "fold1c fold2c";
            }
            echo "\">";

            echo '<h2 class="leftmenu">';
            if ($this->page_type === 1) {
                echo 'New account';
            } else {
                if ($this->user !== $this->viewer) {
                    echo $this->viewer->name_for("rn", $this->user), ' ';
                }
                echo htmlspecialchars($this->ustatus->cs()->get($this->topic)->title), ' ';
                if ($this->user->is_disabled()) {
                    echo '<span class="n dim user-disabled-marker">(disabled)</span>';
                }
            }
            echo '</h2>';
        }

        if ($this->ustatus->has_message() && $want_ustatus_message) {
            $this->conf->feedback_msg($this->decorated_message_list($this->ustatus, $this->ustatus));
        }

        $this->conf->report_saved_messages();

        if ($this->page_type === 2) {
            $this->ustatus->print_members("__bulk");
        } else {
            $this->print_topic();
            echo "</div>"; // foldaccount
        }

        echo "</div></form>",
            // include #f-reauth in case we need to reauthenticate
            '<form id="f-reauth" class="ui-submit js-reauth"></form>';

        if ($this->page_type === 0) {
            Ht::stash_script('$("#f-profile").awaken()');
        }
        $this->qreq->print_footer();
    }

    private function print_topic() {
        $this->ustatus->cs()->print_body_members($this->topic);
        if (!$this->ustatus->inputs_printed()
            || (!$this->ustatus->has_recent_authentication()
                && ($gj = $this->ustatus->cs()->get($this->topic))
                && ($gj->request_recent_authentication ?? false))) {
            return;
        }
        $this->ustatus->print_actions();
    }


    static function go(Contact $user, Qrequest $qreq) {
        if (!$user->is_signed_in()) {
            $user->escape();
        }

        $pp = new Profile_Page($user, $qreq);
        $pp->handle_request();
        $pp->print();
    }
}
