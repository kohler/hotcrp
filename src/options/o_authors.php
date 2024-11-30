<?php
// o_authors.php -- HotCRP helper class for authors intrinsic
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Authors_PaperOption extends PaperOption {
    /** @var int */
    private $max_count;
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->max_count = $args->max ?? 0;
    }
    function author_list(PaperValue $ov) {
        return PaperInfo::parse_author_list($ov->data() ?? "");
    }
    function value_force(PaperValue $ov) {
        $ov->set_value_data([1], [$ov->prow->authorInformation]);
    }
    function value_export_json(PaperValue $ov, PaperExport $pex) {
        $contacts_ov = $ov->prow->option(PaperOption::CONTACTSID);
        $lemails = [];
        foreach ($contacts_ov->data_list() as $email) {
            $lemails[] = strtolower($email);
        }
        $au = [];
        foreach (PaperInfo::parse_author_list($ov->data() ?? "") as $auth) {
            $au[] = $j = (object) $auth->unparse_nea_json();
            if ($auth->email !== "" && in_array(strtolower($auth->email), $lemails)) {
                $j->contact = true;
            }
        }
        return $au;
    }

    function value_check(PaperValue $ov, Contact $user) {
        $aulist = $this->author_list($ov);
        $nreal = 0;
        $lemails = [];
        foreach ($aulist as $auth) {
            $nreal += $auth->is_empty() ? 0 : 1;
            $lemails[] = strtolower($auth->email);
        }
        if ($nreal === 0) {
            if (!$ov->prow->allow_absent()) {
                $ov->estop($this->conf->_("<0>Entry required"));
                $ov->msg_at("authors:1", null, MessageSet::ERROR);
            }
            return;
        }
        if ($this->max_count > 0 && $nreal > $this->max_count) {
            $ov->estop($this->conf->_("<0>A {submission} may have at most {max} authors", new FmtArg("max", $this->max_count)));
        }

        $req_orcid = $this->conf->opt("requireOrcid") ?? 0;
        if ($req_orcid === 2
            && ($ov->prow->outcome_sign <= 0
                || !$ov->prow->can_author_view_decision())) {
            $req_orcid = 0;
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
                $ov->msg_at("authors:{$n}", null, MessageSet::WARNING);
                continue;
            }
            if (strpos($auth->email, "@") === false
                && strpos($auth->affiliation, "@") !== false) {
                $msg_bademail = true;
                $ov->msg_at("authors:{$n}", null, MessageSet::WARNING);
            }
            if ($auth->email !== ""
                && !validate_email($auth->email)
                && !$ov->prow->author_by_email($auth->email)) {
                $ov->estop(null);
                $ov->msg_at("authors:{$n}", "<0>Invalid email address ‘{$auth->email}’", MessageSet::ESTOP);
                continue;
            }
            if ($req_orcid > 0) {
                if ($auth->email === "") {
                    $msg_missing = true;
                    $ov->msg_at("authors:{$n}:email", null, MessageSet::WARNING);
                } else if (!($u = $this->conf->user_by_email($auth->email))
                           || !$u->confirmed_orcid()) {
                    $msg_orcid[] = $auth->email;
                    $ov->msg_at("authors:{$n}", null, MessageSet::WARNING);
                }
            }
            if ($auth->email !== ""
                && ($n2 = array_search(strtolower($auth->email), $lemails)) !== $n - 1) {
                $msg_dupemail = true;
                $ov->msg_at("authors:{$n}:email", null, MessageSet::WARNING);
                $ov->msg_at("authors:" . ($n2 + 1) . ":email", null, MessageSet::WARNING);
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
            $ov->warning($this->conf->_("<5>Some authors have not configured their <a href=\"https://orcid.org\">ORCID iDs</a>"));
            $ov->msg($this->conf->_("<0>This site requests that authors provide ORCID iDs. Please ask {0:list} to sign in and update their profiles.", new FmtArg(0, $msg_orcid, 0)), MessageSet::INFORM);
        }
    }

    function value_save(PaperValue $ov, PaperStatus $ps) {
        // construct property
        $authlist = $this->author_list($ov);
        $d = "";
        $emails = [];
        foreach ($authlist as $auth) {
            if (!$auth->is_empty()) {
                $d .= ($d === "" ? "" : "\n") . $auth->unparse_tabbed();
            }
            $emails[] = $auth->email;
        }

        // check for change
        if ($d === $ov->prow->base_option($this->id)->data()) {
            return true;
        }
        $ps->change_at($this);
        $ov->prow->set_prop("authorInformation", $d);

        // set conflicts
        $ps->clear_conflict_values(CONFLICT_AUTHOR);
        $pemails = $this->conf->resolve_primary_emails($emails);
        foreach ($authlist as $i => $auth) {
            if ($auth->email === "") {
                continue;
            }
            if (strcasecmp($auth->email, $pemails[$i]) !== 0) {
                $ps->update_conflict_value($auth, CONFLICT_AUTHOR, CONFLICT_AUTHOR);
                $auth = clone $auth;
                $auth->email = $pemails[$i];
            }
            $cflags = CONFLICT_AUTHOR
                | ($ov->anno("contact:{$auth->email}") ? CONFLICT_CONTACTAUTHOR : 0);
            $ps->update_conflict_value($auth, $cflags, $cflags);
        }
        $ps->checkpoint_conflict_values();
        return true;
    }
    static private function expand_author(Author $au, PaperInfo $prow) {
        if ($au->email !== ""
            && ($aux = $prow->author_by_email($au->email))) {
            if ($au->firstName === "" && $au->lastName === "") {
                $au->firstName = $aux->firstName;
                $au->lastName = $aux->lastName;
            }
            if ($au->affiliation === "") {
                $au->affiliation = $aux->affiliation;
            }
        }
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $v = [];
        $auth = new Author;
        for ($n = 1; true; ++$n) {
            $email = $qreq["authors:{$n}:email"];
            $name = $qreq["authors:{$n}:name"];
            $aff = $qreq["authors:{$n}:affiliation"];
            if ($email === null && $name === null && $aff === null) {
                break;
            }
            $auth->email = $auth->firstName = $auth->lastName = $auth->affiliation = "";
            $name = simplify_whitespace($name ?? "");
            if ($name !== "" && $name !== "Name") {
                list($auth->firstName, $auth->lastName, $auth->email) = Text::split_name($name, true);
            }
            $email = simplify_whitespace($email ?? "");
            if ($email !== "" && $email !== "Email") {
                $auth->email = $email;
            }
            $aff = simplify_whitespace($aff ?? "");
            if ($aff !== "" && $aff !== "Affiliation") {
                $auth->affiliation = $aff;
            }
            // some people enter email in the affiliation slot
            if (strpos($aff, "@") !== false
                && validate_email($aff)
                && !validate_email($auth->email)) {
                $auth->affiliation = $auth->email;
                $auth->email = $aff;
            }
            self::expand_author($auth, $prow);
            $v[] = $auth->unparse_tabbed();
        }
        return PaperValue::make($prow, $this, 1, join("\n", $v));
    }
    function parse_json(PaperInfo $prow, $j) {
        if (!is_array($j) || is_associative_array($j)) {
            return PaperValue::make_estop($prow, $this, "<0>Validation error");
        }
        $v = $cemail = [];
        foreach ($j as $i => $auj) {
            if (is_object($auj) || is_associative_array($auj)) {
                $auth = Author::make_keyed($auj);
                $contact = $auj->contact ?? null;
            } else if (is_string($auj)) {
                $auth = Author::make_string($auj);
                $contact = null;
            } else {
                return PaperValue::make_estop($prow, $this, "<0>Validation error on author #" . ($i + 1));
            }
            self::expand_author($auth, $prow);
            $v[] = $auth->unparse_tabbed();
            if ($contact && $auth->email !== "") {
                $cemail[] = $auth->email;
            }
        }
        $ov = PaperValue::make($prow, $this, 1, join("\n", $v));
        foreach ($cemail as $email) {
            $ov->set_anno("contact:{$email}", true);
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
        }
        return Ht::entry("authors:{$n}:{$component}", $val, $js);
    }
    private function echo_editable_authors_line($pt, $n, $au, $reqau, $shownum) {
        // on new paper, default to editing user as first author
        $ignore_diff = false;
        if ($n === 1
            && !$au
            && !$pt->user->can_administer($pt->prow)
            && (!$reqau || $reqau->nea_equals($pt->user->populated_user()))) {
            $reqau = new Author($pt->user->populated_user());
            $ignore_diff = true;
        }

        echo '<div class="author-entry draggable d-flex">';
        if ($shownum) {
            echo '<div class="flex-grow-0"><button type="button" class="draghandle ui js-dropmenu-open ui-drag row-order-draghandle need-tooltip need-dropmenu" draggable="true" title="Click or drag to reorder" data-tooltip-anchor="e">&zwnj;</button></div>',
                '<div class="flex-grow-0 row-counter">', $n, '.</div>';
        }
        echo '<div class="flex-grow-1">',
            $this->editable_author_component_entry($pt, $n, "email", $au, $reqau, $ignore_diff), ' ',
            $this->editable_author_component_entry($pt, $n, "name", $au, $reqau, $ignore_diff), ' ',
            $this->editable_author_component_entry($pt, $n, "affiliation", $au, $reqau, $ignore_diff),
            $pt->messages_at("authors:{$n}"),
            $pt->messages_at("authors:{$n}:email"),
            $pt->messages_at("authors:{$n}:name"),
            $pt->messages_at("authors:{$n}:affiliation"),
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
            "id" => "authors", "for" => false
        ]);

        $min_authors = $this->max_count > 0 ? min(5, $this->max_count) : 5;

        $aulist = $this->author_list($ov);
        $reqaulist = $this->author_list($reqov);
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

        echo '<div class="papev">',
            '<div id="authors:container" class="js-row-order need-row-order-autogrow" data-min-rows="', $min_authors, '"',
            $this->max_count > 0 ? " data-max-rows=\"{$this->max_count}\"" : "",
            ' data-row-counter-digits="', $ndigits,
            '" data-row-template="authors:row-template">';
        for ($n = 1; $n <= $nau; ++$n) {
            $this->echo_editable_authors_line($pt, $n, $aulist[$n-1] ?? null, $reqaulist[$n-1] ?? null, $this->max_count !== 1);
        }
        echo '</div>';
        echo '<template id="authors:row-template" class="hidden">';
        $this->echo_editable_authors_line($pt, '$', null, null, $this->max_count !== 1);
        echo "</template></div></div>\n\n";
    }

    function field_fmt_context() {
        return [new FmtArg("max", $this->max_count)];
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($fr->want(FieldRender::CFPAGE)) {
            $fr->table->render_authors($fr, $this);
        } else {
            $names = ["<ul class=\"x namelist\">"];
            foreach ($this->author_list($ov) as $au) {
                $n = htmlspecialchars(trim("{$au->firstName} {$au->lastName}"));
                if ($au->email !== "") {
                    $ehtml = htmlspecialchars($au->email);
                    $e = "&lt;<a href=\"mailto:{$ehtml}\" class=\"q\">{$ehtml}</a>&gt;";
                } else {
                    $e = "";
                }
                $t = ($n === "" ? $e : $n);
                if ($au->affiliation !== "") {
                    $t .= " <span class=\"auaff\">(" . htmlspecialchars($au->affiliation) . ")</span>";
                }
                if ($n !== "" && $e !== "") {
                    $t .= " " . $e;
                }
                $names[] = "<li class=\"odname\">{$t}</li>";
            }
            $names[] = "</ul>";
            $fr->set_html(join("", $names));
        }
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
