<?php
// o_authors.php -- HotCRP helper class for authors intrinsic
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Authors_PaperOption extends PaperOption {
    /** @var int */
    private $max_count;

    // hard limit on authors per submission
    const MAX_AUTHORS = 1000;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->max_count = $args->max ?? 0;
    }
    /** @return list<Author> */
    static function author_list(PaperValue $ov) {
        return PaperInfo::parse_author_list($ov->data() ?? "");
    }
    /** @return int */
    private function effective_max_count() {
        return $this->max_count > 0 ? min($this->max_count, self::MAX_AUTHORS) : self::MAX_AUTHORS;
    }
    /** @return PaperValue */
    private function make_too_many(PaperInfo $prow) {
        return PaperValue::make_estop($prow, $this, $this->conf->_("<0>A {submission} may have at most {max} authors", new FmtArg("max", $this->effective_max_count())));
    }
    /** @return 0|1|2 */
    private function orcid_requirement(PaperInfo $prow) {
        $req = $this->conf->opt("requireOrcid") ?? 0;
        if ($req === 2
            && ($prow->outcome_sign <= 0
                || !$prow->can_author_view_decision())) {
            return 0;
        }
        return $req;
    }
    /** Return the account for author `$email`, importing its ORCID iD from
     * the contactdb if the local account lacks one.
     * @param string $email
     * @return Contact */
    private function author_user($email) {
        $u = $this->conf->user_by_email($email)
            ?? Contact::make_placeholder($this->conf, $email);
        if ($u->confirmed_orcid() === ""
            && ($cdbu = $u->cdb_user())) {
            $u->import_prop($cdbu, 1);
            if ($u->contactId > 0 && $u->prop_changed()) {
                $u->save_prop();
            }
        }
        return $u;
    }
    /** Return true if `$user` may record `$u`'s ORCID iD from the submission
     * form: `$u` lacks one, and is `$user` or has never signed in.
     * @return bool */
    private function can_set_author_orcid(Contact $u, Contact $user) {
        if ($u->decorated_orcid() !== "") {
            return false;
        } else if (strcasecmp($u->email, $user->email) === 0) {
            return true;
        }
        $cdbu = $u->cdb_user();
        return $u->is_placeholder() && (!$cdbu || $cdbu->is_placeholder());
    }
    /** Check the ORCID iD entered for author `$n`, if any. Return true if
     * the author will have a confirmed ORCID iD once the submission is saved.
     * @param int $n
     * @return bool */
    private function check_author_orcid(PaperValue $ov, $n, Author $auth, Contact $user) {
        $u = $this->author_user($auth->email);
        $input = ($ov->anno("orcid_input") ?? [])[$n] ?? "";
        if ($input === "") {
            return $u->confirmed_orcid() !== "";
        }
        $orcid = Contact::clean_orcid($input);
        if ($orcid === null) {
            $ov->append_item(MessageItem::error_at("authors:{$n}:orcid", "<0>Invalid ORCID iD"));
        } else if ($orcid === $u->orcid()) {
            // already recorded
        } else if ($this->can_set_author_orcid($u, $user)) {
            $save = $ov->anno("orcid_save") ?? [];
            $save[strtolower($auth->email)] = $orcid;
            $ov->set_anno("orcid_save", $save);
            return true;
        } else {
            $ov->append_item(MessageItem::warning_at("authors:{$n}:orcid", "<0>ORCID iD not recorded; this author must add it to their profile"));
        }
        return $u->confirmed_orcid() !== "";
    }
    /** Record ORCID iDs entered for authors (second save pass). */
    private function save_author_orcids(PaperValue $ov, PaperStatus $ps) {
        $saved = [];
        foreach ($ov->anno("orcid_save") ?? [] as $email => $orcid) {
            // the collation can match a different email; check the result
            $u = $this->conf->fresh_user_by_email($email);
            if (!$u
                || strcasecmp($u->email, $email) !== 0
                || !$this->can_set_author_orcid($u, $ps->user)) {
                continue;
            }
            $u->set_prop("orcid", $orcid, 2);
            if ($u->prop_changed()) {
                $u->save_prop();
                $u->export_prop(1);
                $this->conf->invalidate_user($u);
                $saved[] = $u->email;
            }
        }
        if (!empty($saved)) {
            $ps->append_item(MessageItem::success($this->conf->_("<0>Recorded ORCID iDs for {:list}", $saved)));
        }
    }
    /** Return author `$email`'s ORCID iD without changing any account, or
     * null if `$viewer` couldn't look up that account via api/user.
     * @param string $email
     * @return ?string */
    private function visible_account_orcid($email, Contact $viewer) {
        $u = $this->conf->user_by_email($email)
            ?? Contact::make_placeholder($this->conf, $email);
        $orcid = $u->orcid();
        if ($orcid === "" && ($cdbu = $u->cdb_user())) {
            $orcid = $cdbu->orcid();
        }
        if ($orcid === ""
            || strcasecmp($email, $viewer->email) === 0
            || ($viewer->can_lookup_user()
                && ($viewer->is_track_manager()
                    || $this->conf->opt("allowLookupUser")
                    || ($u->roles & $viewer->viewable_roles_mask() & Contact::ROLE_ANYPC) !== 0))) {
            return $orcid;
        }
        return null;
    }
    function value_force(PaperValue $ov) {
        $ov->set_value_data([1], [$ov->prow->authorInformation]);
    }
    function value_export_json(PaperValue $ov, PaperExport $pex) {
        $au = [];
        foreach (self::author_list($ov) as $auth) {
            $au[] = $auth->unparse_nea_json();
        }
        return $au;
    }

    function value_check(PaperValue $ov, Contact $user) {
        $admin = $user->allow_admin($ov->prow);
        $aulist = self::author_list($ov);
        $nreal = 0;
        $lemails = []; // lowercase email => first index
        foreach ($aulist as $i => $auth) {
            $nreal += $auth->is_empty() ? 0 : 1;
            $le = strtolower($auth->email);
            $lemails[$le] = $lemails[$le] ?? $i;
        }
        if ($nreal === 0) {
            if (!$ov->prow->allow_absent()
                && $ov->problem_status() < MessageSet::ESTOP) {
                $ov->estop($this->conf->_("<0>Entry required"));
                $ov->append_item(MessageItem::error_at("authors:1"));
            }
            return;
        }
        if ($nreal > $this->effective_max_count()) {
            $ov->estop($this->conf->_("<0>A {submission} may have at most {max} authors", new FmtArg("max", $this->effective_max_count())));
        }

        $req_orcid = $this->orcid_requirement($ov->prow);
        $base_authors = null;
        if ($req_orcid > 0
            && !$ov->has_error()) {
            $base_authors = self::author_list($ov->prow->base_option($this->id));
        }

        $msg_bademail = $msg_missing = $msg_dupemail = false;
        $msg_orcid = [];
        $n = 0;
        foreach ($aulist as $auth) {
            ++$n;
            if ($auth->is_empty()) {
                continue;
            }
            if ($auth->firstName === ""
                && $auth->lastName === ""
                && $auth->email === ""
                && $auth->affiliation !== "") {
                $msg_missing = true;
                $ov->append_item(MessageItem::warning_at("authors:{$n}"));
                continue;
            }
            if (strpos($auth->email, "@") === false
                && strpos($auth->affiliation, "@") !== false) {
                $msg_bademail = true;
                $ov->append_item(MessageItem::warning_at("authors:{$n}"));
            }
            if ($auth->email !== ""
                && !Contact::is_plausible_author_email($auth->email, $admin)
                && !$ov->prow->author_by_email($auth->email)) {
                $status = validate_email($auth->email) ? MessageSet::ERROR : MessageSet::ESTOP;
                $ov->append_item(new MessageItem($status, "authors"));
                $ov->append_item(new MessageItem($status, "authors:{$n}", "<0>Invalid email address ‘{$auth->email}’"));
                continue;
            }
            if ($req_orcid > 0) {
                $status = 1;
                if ($req_orcid === 1 && $ov->prow->want_submitted()) {
                    $status = 2;
                }
                if ($auth->email === "") {
                    $msg_missing = true;
                    $ov->append_item(new MessageItem($status, "authors:{$n}:email"));
                } else if (!$this->check_author_orcid($ov, $n, $auth, $user)) {
                    if (!Contact::is_bot_email($auth->email)) {
                        $msg_orcid[] = $auth->email;
                        $ov->append_item(new MessageItem($status, "authors:{$n}"));
                    }
                } else {
                    $status = 0;
                }
                if ($status !== 0
                    && $base_authors !== null
                    && ($auth->email === ""
                        ? !Author::find_match($auth, $base_authors)
                        : !Author::find_by_email($auth->email, $base_authors))) {
                    $base_authors = null;
                }
            }
            if ($auth->email !== ""
                && ($n2 = $lemails[strtolower($auth->email)]) !== $n - 1) {
                $msg_dupemail = true;
                $ov->append_item(MessageItem::warning_at("authors:{$n}:email"));
                $ov->append_item(MessageItem::warning_at("authors:" . ($n2 + 1) . ":email"));
            }
        }

        // only admins can remove bot authors
        if (!$admin) {
            foreach (self::author_list($ov->prow->base_option($this->id)) as $auth) {
                if (Contact::is_bot_email($auth->email)
                    && !isset($lemails[strtolower($auth->email)])) {
                    $ov->error($this->conf->_("<0>Only administrators can remove bot authors"));
                    break;
                }
            }
        }

        if ($msg_missing) {
            if ($req_orcid > 0) {
                $ov->warning("<0>Please enter a name and email address for every author");
            } else {
                $ov->warning("<0>Please enter a name and optional email address for every author");
            }
        }
        if ($msg_bademail) {
            $ov->warning("<0>You may have entered an email address in the wrong place. The first author field is for email, the second for name, and the third for affiliation");
        }
        if ($msg_dupemail) {
            $ov->warning("<0>The same email address has been used for different authors. This is usually an error");
        }
        if ($msg_orcid) {
            $status = 2;
            if ($req_orcid === 1 && !$ov->prow->want_submitted()) {
                $status = 1;
            }
            $ov->append_item(new MessageItem($status, "authors", $this->conf->_("<5>Some authors haven’t added an <a href=\"https://orcid.org\">ORCID iD</a> to their profiles")));
            $ov->inform($this->conf->_("<0>This site requires that all authors provide ORCID iDs. Please ask {0:list} to sign in and update their profiles.", new FmtArg(0, $msg_orcid, 0)));
        }
        if ($base_authors !== null) {
            $ov->append_item(MessageItem::success(null));
        }
    }

    function value_save(PaperValue $ov, PaperStatus $ps) {
        // second pass: record entered ORCID iDs once the save is committed
        if ($ps->save_status_prepared()) {
            $this->save_author_orcids($ov, $ps);
            return;
        }
        // construct property
        $authlist = self::author_list($ov);
        $d = "";
        foreach ($authlist as $auth) {
            if (!$auth->is_empty()) {
                $d .= ($d === "" ? "" : "\n") . $auth->unparse_tabbed();
            }
        }
        // apply change
        $changed = $d !== $ov->prow->base_option($this->id)->data();
        if ($changed) {
            $ov->prow->set_prop("authorInformation", $d);
        }
        // entered ORCID iDs are recorded on author accounts, which must exist
        if ($changed || $ov->anno("orcid_save")) {
            $this->value_save_conflict_values($ov, $ps);
        }
        if ($ov->anno("orcid_save")) {
            $ps->request_resave($this);
        }
    }
    function value_save_conflict_values(PaperValue $ov, PaperStatus $ps) {
        $explicit_contacts = [];
        $ps->clear_conflict_values(CONFLICT_AUTHOR);
        foreach (self::author_list($ov) as $i => $auth) {
            if (Contact::is_plausible_author_email($auth->email, true)) {
                $cflags = CONFLICT_AUTHOR;
                if ($ov->anno("contact:{$auth->email}")) {
                    $cflags |= CONFLICT_CONTACTAUTHOR;
                    $explicit_contacts[] = $auth;
                }
                $ps->update_conflict_value($auth, $cflags, $cflags);
            }
        }
        $ps->checkpoint_conflict_values();
        if (!empty($explicit_contacts)) {
            $ov->set_anno("explicit_contacts", $explicit_contacts);
        }
    }
    static private function expand_author(Author $au, PaperInfo $prow) {
        if ($au->email !== ""
            && ($aux = $prow->author_by_email($au->email))) {
            $au->merge($aux);
        }
    }
    /** @param list<Author> $authors
     * @return PaperValue */
    private function resolve_parse(PaperInfo $prow, $authors) {
        while (!empty($authors) && $authors[count($authors) - 1]->is_empty()) {
            array_pop($authors);
        }
        // reject lists over the hard limit before any per-author lookup;
        // value_check reports lists over a configured maximum
        $nreal = 0;
        foreach ($authors as $au) {
            $nreal += $au->is_empty() ? 0 : 1;
        }
        if ($nreal > self::MAX_AUTHORS) {
            return $this->make_too_many($prow);
        }
        $t = [];
        foreach ($authors as $au) {
            self::expand_author($au, $prow);
            $t[] = $au->unparse_tabbed();
        }
        return PaperValue::make($prow, $this, 1, join("\n", $t));
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $authors = $orcids = [];
        for ($n = 1; true; ++$n) {
            $email = $qreq["authors:{$n}:email"];
            $name = $qreq["authors:{$n}:name"];
            $aff = $qreq["authors:{$n}:affiliation"];
            $orcid = $qreq["authors:{$n}:orcid"];
            if ($email === null && $name === null && $aff === null && $orcid === null) {
                break;
            }
            if (($orcid = trim($orcid ?? "")) !== "") {
                $orcids[$n] = $orcid;
            }
            $auf = $aul = $aue = $aua = "";
            $name = simplify_whitespace($name ?? "");
            if ($name !== "" && $name !== "Name") {
                list($auf, $aul, $aue) = Text::split_name($name, true);
            }
            $email = simplify_whitespace($email ?? "");
            if ($email !== "" && $email !== "Email") {
                $aue = $email;
            }
            $aff = simplify_whitespace($aff ?? "");
            if ($aff !== "" && $aff !== "Affiliation") {
                $aua = $aff;
            }
            // some people enter email in the affiliation slot
            if (strpos($aff, "@") !== false
                && validate_email($aff)
                && !validate_email($aue)) {
                $tmp = $aue;
                $aue = $aua;
                $aua = $tmp;
            }
            $authors[] = Author::make_nae($auf, $aul, $aue, $aua);
        }
        $ov = $this->resolve_parse($prow, $authors);
        if (!empty($orcids)) {
            $ov->set_anno("orcid_input", $orcids);
        }
        return $ov;
    }
    function parse_json_user(PaperInfo $prow, $j, Contact $user) {
        if (!is_array($j) || !array_is_list($j)) {
            return PaperValue::make_estop($prow, $this, "<0>Validation error");
        }
        $authors = $cemail = $orcids = [];
        foreach ($j as $i => $auj) {
            if (is_object($auj) || (is_array($auj) && !array_is_list($auj))) {
                $auth = Author::make_keyed($auj);
                $contact = $auj->contact ?? null;
                $orcid = is_object($auj) ? ($auj->orcid ?? null) : ($auj["orcid"] ?? null);
                if (is_string($orcid) && ($orcid = trim($orcid)) !== "") {
                    $orcids[$i + 1] = $orcid;
                }
            } else if (is_string($auj)) {
                $auth = Author::make_string($auj);
                $contact = null;
            } else {
                return PaperValue::make_estop($prow, $this, "<0>Validation error on author #" . ($i + 1));
            }
            $authors[] = $auth;
            if ($contact && validate_email($auth->email)) {
                $cemail[] = $auth->email;
            }
        }
        $ov = $this->resolve_parse($prow, $authors);
        foreach ($cemail as $email) {
            $ov->set_anno("contact:{$email}", true);
        }
        if (!empty($orcids)) {
            $ov->set_anno("orcid_input", $orcids);
        }
        return $ov;
    }

    private function editable_author_component_entry($pt, $n, $component, $au, $reqau, $ignore_diff) {
        if ($component === "name") {
            $js = ["size" => "35", "placeholder" => "Name", "autocomplete" => "off", "aria-label" => "Author name"];
            $auval = $au ? $au->name(NAME_PARSABLE) : "";
            $val = $reqau ? $reqau->name(NAME_PARSABLE) : "";
        } else if ($component === "email") {
            $js = ["size" => "30", "placeholder" => "Email", "autocomplete" => "off", "aria-label" => "Author email"];
            $auval = $au ? $au->email : "";
            $val = $reqau ? $reqau->email : "";
        } else {
            $js = ["size" => "32", "placeholder" => "Affiliation", "autocomplete" => "off", "aria-label" => "Author affiliation"];
            $auval = $au ? $au->affiliation : "";
            $val = $reqau ? $reqau->affiliation : "";
        }

        $js["class"] = $pt->max_control_class(["authors:{$n}", "authors:{$n}:{$component}"], "need-autogrow js-autosubmit editable-author editable-author-{$component}" . ($ignore_diff ? " ignore-diff" : ""));
        if ($component === "email" && $pt->user->can_lookup_user()) {
            $js["class"] .= " uii js-email-populate";
        }
        if ($val !== $auval) {
            $js["data-default-value"] = $auval;
            if ($component !== "email" && $pt->prow->is_new()) {
                $js["data-populated-value"] = $val;
            }
        }
        return Ht::entry("authors:{$n}:{$component}", $val, $js);
    }
    /** @param ?string $reqorcid */
    private function editable_author_orcid_entry($pt, $n, $au, $reqau, $reqorcid) {
        $email = $reqau ? $reqau->email : ($au ? $au->email : "");
        $orcid = $email !== "" ? $this->visible_account_orcid($email, $pt->user) : "";
        $js = ["size" => "19", "placeholder" => "ORCID iD", "autocomplete" => "off", "spellcheck" => false, "aria-label" => "Author ORCID iD"];
        if ($orcid === null) {
            $js["placeholder"] = "ORCID iD on file";
            $orcid = "";
        }
        $val = $reqorcid ?? $orcid;
        $js["class"] = $pt->max_control_class(["authors:{$n}", "authors:{$n}:orcid"], "need-autogrow js-autosubmit editable-author editable-author-orcid");
        if ($val !== $orcid) {
            $js["data-default-value"] = $orcid;
        }
        return Ht::entry("authors:{$n}:orcid", $val, $js);
    }
    /** @param bool $show_orcid
     * @param ?string $reqorcid */
    private function echo_editable_authors_line($pt, $n, $au, $reqau, $shownum, $show_orcid, $reqorcid) {
        // on new paper, default to editing user as first author
        $ignore_diff = false;
        if ($n === 1
            && !$au
            && !$pt->user->is_admin($pt->prow)
            && (!$reqau || $reqau->nea_equals($pt->user->populated_user()))) {
            $reqau = Author::make_user($pt->user->populated_user());
            $ignore_diff = true;
        }

        echo '<div class="author-entry draggable d-flex">';
        if ($shownum) {
            echo '<div class="flex-grow-0"><button type="button" class="draghandle ui uikd js-dropmenu-button ui-drag row-order-draghandle need-tooltip need-dropmenu" draggable="true" title="Click or drag to reorder" data-tooltip-anchor="e" aria-haspopup="menu" aria-expanded="false">&zwnj;</button></div>',
                '<div class="flex-grow-0 row-counter">', $n, '.</div>';
        }
        echo '<div class="flex-grow-1">',
            $this->editable_author_component_entry($pt, $n, "email", $au, $reqau, $ignore_diff), ' ',
            $this->editable_author_component_entry($pt, $n, "name", $au, $reqau, $ignore_diff), ' ',
            $this->editable_author_component_entry($pt, $n, "affiliation", $au, $reqau, $ignore_diff),
            $show_orcid ? " " . $this->editable_author_orcid_entry($pt, $n, $au, $reqau, $reqorcid) : "",
            MessageSet::feedback_html($pt->message_list_for("authors:{$n}:*")),
            '</div></div>';
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $title = $pt->edit_title_html($this);
        $sb = $this->conf->submission_blindness();
        if ($sb !== Conf::BLIND_NEVER
            && $pt->prow->outcome_sign > 0
            && !$this->conf->setting("seedec_hideau")
            && $pt->prow->can_author_view_decision()) {
            $sb = Conf::BLIND_NEVER;
        }
        if ($sb === Conf::BLIND_ALWAYS) {
            $title .= ' <span class="n">(anonymous)</span>';
        } else if ($sb === Conf::BLIND_UNTILREVIEW) {
            $title .= ' <span class="n">(anonymous until review)</span>';
        }
        $pt->print_editable_option_papt($this, $title, [
            "id" => "authors", "for" => false, "fieldset" => true
        ]);

        $min_authors = $this->max_count > 0 ? min(5, $this->max_count) : 5;

        $aulist = self::author_list($ov);
        $reqaulist = self::author_list($reqov);
        $nreqau = count($reqaulist);
        while ($nreqau > 0 && $reqaulist[$nreqau-1]->is_empty()) {
            --$nreqau;
        }
        $nau = max($nreqau, count($aulist), $min_authors);
        if (($nau === $nreqau || $nau === count($aulist))
            && ($this->max_count <= 0 || $nau + 1 <= $this->max_count)) {
            ++$nau;
        }
        $ndigits = (int) ceil(log10($nau + 1));

        // ORCID iDs can be entered when the site requires them
        $show_orcid = $this->orcid_requirement($pt->prow) > 0;
        $reqorcids = $reqov !== $ov ? ($reqov->anno("orcid_input") ?? []) : null;
        if ($show_orcid) {
            $emails = [];
            foreach (array_merge($aulist, $reqaulist) as $au) {
                if ($au->email !== "") {
                    $emails[] = $au->email;
                    $this->conf->prefetch_cdb_user_by_email($au->email);
                }
            }
            $this->conf->prefetch_users_by_email($emails);
        }

        echo '<div class="papev">',
            '<div id="authors:container" class="need-row-order-autogrow';
        if ($pt->has_editable_pc_conflicts()) {
            echo ' uii js-update-potential-conflicts';
        }
        echo '" data-min-rows="', $min_authors,
            $this->max_count > 0 ? "\" data-max-rows=\"{$this->max_count}" : "",
            '" data-row-counter-digits="', $ndigits,
            '" data-row-template="authors:row-template">';
        for ($n = 1; $n <= $nau; ++$n) {
            $this->echo_editable_authors_line($pt, $n, $aulist[$n-1] ?? null, $reqaulist[$n-1] ?? null, $this->max_count !== 1,
                $show_orcid, $reqorcids === null ? null : ($reqorcids[$n] ?? ""));
        }
        echo '</div>';
        echo '<template id="authors:row-template" class="hidden">';
        $this->echo_editable_authors_line($pt, '$', null, null, $this->max_count !== 1, $show_orcid, null);
        echo "</template></div></fieldset>\n\n";
    }
    function print_web_edit_hidden(PaperTable $pt, $ov) {
        echo '<fieldset name="authors" role="none" hidden>';
        foreach (self::author_list($ov) as $i => $au) {
            $n = $i + 1;
            echo Ht::hidden("authors:{$n}:email", $au->email, ["disabled" => true]);
            if (($n = $au->name(NAME_PARSABLE)) !== "") {
                echo Ht::hidden("authors:{$n}:name", $n, ["disabled" => true]);
            }
            if ($au->affiliation !== "") {
                echo Ht::hidden("authors:{$n}:affiliation", $au->affiliation, ["disabled" => true]);
            }
        }
        echo '</fieldset>';
    }

    function field_fmt_context() {
        return [new FmtArg("max", $this->max_count)];
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($fr->want(FieldRender::CFPAGE)) {
            $fr->table->render_authors($fr, $this);
            return;
        }
        $names = ["<ul class=\"x namelist\">"];
        foreach (self::author_list($ov) as $au) {
            $names[] = '<li class="odname">' . $au->name_h(NAME_E | NAME_A) . '</li>';
        }
        $names[] = "</ul>";
        $fr->set_html(join("", $names));
    }

    function jsonSerialize() {
        $j = parent::jsonSerialize();
        if ($this->max_count > 0) {
            $j->max = $this->max_count;
        }
        return $j;
    }
    function export_setting() {
        $sfs = parent::export_setting();
        $sfs->max = $this->max_count;
        return $sfs;
    }
}
