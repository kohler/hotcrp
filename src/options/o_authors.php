<?php
// o_authors.php -- HotCRP helper class for authors intrinsic
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Authors_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
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
        foreach ($aulist as $auth) {
            $nreal += $auth->is_empty() ? 0 : 1;
        }
        if ($nreal === 0 && !$ov->prow->allow_absent()) {
            $ov->estop($this->conf->_("<0>Entry required"));
            $ov->msg_at("authors:1", null, MessageSet::ERROR);
        }
        $max_authors = $this->conf->opt("maxAuthors");
        if ($max_authors > 0 && $nreal > $max_authors) {
            $ov->estop($this->conf->_("<0>A submission may have at most %d authors", $max_authors));
        }

        $msg1 = $msg2 = false;
        foreach ($aulist as $n => $auth) {
            if (strpos($auth->email, "@") === false
                && strpos($auth->affiliation, "@") !== false) {
                $msg1 = true;
                $ov->msg_at("authors:" . ($n + 1), null, MessageSet::WARNING);
            } else if ($auth->firstName === ""
                       && $auth->lastName === ""
                       && $auth->email === ""
                       && $auth->affiliation !== "") {
                $msg2 = true;
                $ov->msg_at("authors:" . ($n + 1), null, MessageSet::WARNING);
            } else if ($auth->email !== ""
                       && !validate_email($auth->email)
                       && !$ov->prow->author_by_email($auth->email)) {
                $ov->estop(null);
                $ov->msg_at("authors:" . ($n + 1), "<0>Invalid email address ‘{$auth->email}’", MessageSet::ESTOP);
            }
        }
        if ($msg1) {
            $ov->warning("<0>You may have entered an email address in the wrong place. The first author field is for email, the second for name, and the third for affiliation");
        }
        if ($msg2) {
            $ov->warning("<0>Please enter a name and optional email address for every author");
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        // set property
        $authlist = $this->author_list($ov);
        $v = "";
        $emails = [];
        foreach ($authlist as $auth) {
            if (!$auth->is_empty()) {
                $v .= ($v === "" ? "" : "\n") . $auth->unparse_tabbed();
            }
            $emails[] = $auth->email;
        }
        if ($v === $ov->prow->authorInformation) {
            return true;
        }
        $ps->change_at($this);
        $ov->prow->set_prop("authorInformation", $v);

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
    static private function translate_qreq(Qrequest $qreq) {
        $n = 1;
        while (isset($qreq["authors:email_{$n}"]) || isset($qreq["auemail{$n}"])) {
            $qreq["authors:{$n}:email"] = $qreq["authors:email_{$n}"] ?? $qreq["auemail{$n}"];
            $qreq["authors:{$n}:name"] = $qreq["authors:name_{$n}"] ?? $qreq["auname{$n}"];
            $qreq["authors:{$n}:affiliation"] = $qreq["authors:affiliation_{$n}"] ?? $qreq["auaff{$n}"];
            ++$n;
        }
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
        if (!isset($qreq["authors:1:email"])) {
            self::translate_qreq($qreq);
        }
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

    private function editable_author_component_entry($pt, $n, $component, $au, $reqau, $ignore_diff, $readonly) {
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
        if ($readonly) {
            $js["readonly"] = true;
        }
        return Ht::entry("authors:{$n}:{$component}", $val, $js);
    }
    private function editable_authors_tr($pt, $n, $au, $reqau, $shownum, $readonly) {
        // on new paper, default to editing user as first author
        $ignore_diff = false;
        if ($n === 1
            && !$au
            && !$pt->user->can_administer($pt->prow)
            && (!$reqau || $reqau->nea_equals($pt->user))) {
            $reqau = new Author($pt->user);
            $ignore_diff = true;
        }

        $t = '<tr class="author-entry">';
        if ($shownum) {
            $t .= '<td class="rxcaption">' . $n . '.</td>';
        }
        $t .= '<td class="lentry">'
            . $this->editable_author_component_entry($pt, $n, "email", $au, $reqau, $ignore_diff, $readonly) . ' '
            . $this->editable_author_component_entry($pt, $n, "name", $au, $reqau, $ignore_diff, $readonly) . ' '
            . $this->editable_author_component_entry($pt, $n, "affiliation", $au, $reqau, $ignore_diff,$readonly);
        if (!$readonly) {
            $t .= ' <span class="nb btnbox aumovebox"><button type="button" class="ui need-tooltip row-order-ui moveup" aria-label="Move up" tabindex="-1">'
                . Icons::ui_triangle(0)
                . '</button><button type="button" class="ui need-tooltip row-order-ui movedown" aria-label="Move down" tabindex="-1">'
                . Icons::ui_triangle(2)
                . '</button><button type="button" class="ui need-tooltip row-order-ui delete" aria-label="Delete" tabindex="-1">✖</button></span>';
        }
        return $t . $pt->messages_at("authors:{$n}")
            . $pt->messages_at("authors:{$n}:email")
            . $pt->messages_at("authors:{$n}:name")
            . $pt->messages_at("authors:{$n}:affiliation")
            . '</td></tr>';
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $sb = $this->conf->submission_blindness();
        $title = $pt->edit_title_html($this);
        if ($sb === Conf::BLIND_ALWAYS) {
            $title .= ' <span class="n">(anonymous)</span>';
        } else if ($sb === Conf::BLIND_UNTILREVIEW) {
            $title .= ' <span class="n">(anonymous until review)</span>';
        }
        $pt->print_editable_option_papt($this, $title, ["id" => "authors"]);
        $readonly = !$this->test_editable($ov->prow);

        $max_authors = (int) $this->conf->opt("maxAuthors");
        $min_authors = $max_authors > 0 ? min(5, $max_authors) : 5;
        echo '<div class="papev"><table class="js-row-order">',
            '<tbody id="authors:container" class="need-row-order-autogrow" data-min-rows="', $min_authors, '" ',
            ($max_authors > 0 ? 'data-max-rows="' . $max_authors . '" ' : ''),
            'data-row-template="', htmlspecialchars($this->editable_authors_tr($pt, '$', null, null, $max_authors !== 1, $readonly)), '">';

        $aulist = $this->author_list($ov);
        $reqaulist = $this->author_list($reqov);
        $nreqau = count($reqaulist);
        while ($nreqau > 0 && $reqaulist[$nreqau-1]->is_empty()) {
            --$nreqau;
        }
        $nau = max($nreqau, count($aulist), $min_authors);
        if (($nau === $nreqau || $nau === count($aulist))
            && ($max_authors <= 0 || $nau + 1 <= $max_authors)) {
            ++$nau;
        }

        for ($n = 1; $n <= $nau; ++$n) {
            echo $this->editable_authors_tr($pt, $n, $aulist[$n-1] ?? null, $reqaulist[$n-1] ?? null, $max_authors !== 1, $readonly);
        }
        echo "</tbody></table></div></div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($fr->table) {
            $fr->table->render_authors($fr, $this);
        }
    }
}
