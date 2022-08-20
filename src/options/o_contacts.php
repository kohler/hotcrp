<?php
// o_contacts.php -- HotCRP helper class for contacts intrinsic
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Contacts_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }
    /** @param PaperValue $ov
     * @return list<Author> */
    static private function users_anno($ov) {
        return $ov->anno("users") ?? [];
    }
    /** @param list<Author> $ca
     * @param string $email
     * @return int|false */
    static function ca_index($ca, $email) {
        foreach ($ca as $i => $c) {
            if (strcasecmp($c->email, $email) === 0)
                return $i;
        }
        return false;
    }

    function value_force(PaperValue $ov) {
        // $ov->value_list: contact IDs
        // $ov->data_list: emails
        // $ov->anno("users"): list<Author>
        // $ov->anno("bad_users"): list<Author>
        // NB fake papers start out with this user as contact
        // NB only non-placeholder users
        $ca = $va = [];
        foreach ($ov->prow->conflicts(true) as $cflt) {
            if ($cflt->conflictType >= CONFLICT_AUTHOR
                && $cflt->contactId > 0
                && $cflt->disabled !== Contact::DISABLEMENT_PLACEHOLDER) {
                $ca[] = $cflt;
                $va[$cflt->contactId] = $cflt->email;
            }
        }
        $ov->set_value_data(array_keys($va), array_values($va));
        $ov->set_anno("users", array_values($ca));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        $ca = [];
        foreach (self::users_anno($ov) as $c) {
            if ($c->contactId >= 0)
                $ca[$c->contactId] = $c;
        }
        foreach ($ov->value_list() as $cid) {
            if (!isset($ca[$cid]))
                $ps->conf->prefetch_user_by_id($cid);
        }
        $j = [];
        foreach ($ov->value_list() as $cid) {
            if (($u = $ca[$cid] ?? $ps->conf->cached_user_by_id($cid)))
                $j[] = Author::unparse_nae_json_for($u);
        }
        return $j;
    }
    function value_check(PaperValue $ov, Contact $user) {
        if ($ov->anno("modified")) {
            if (!$user->allow_administer($ov->prow)
                && $ov->prow->conflict_type($user) >= CONFLICT_CONTACTAUTHOR
                && self::ca_index(self::users_anno($ov), $user->email) === false) {
                $ov->error($this->conf->_("<0>You can’t remove yourself from the submission’s contacts"));
                $ov->msg("<0>(Ask another contact to remove you.)", MessageSet::INFORM);
            } else if (empty($ov->value_list())
                       && $ov->prow->paperId > 0
                       && empty($ov->prow->contacts())) {
                $ov->error($this->conf->_("<0>Each submission must have at least one contact"));
            }
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        // do not mark diff (will be marked later)
        $ps->clear_conflict_values(CONFLICT_CONTACTAUTHOR);
        foreach (self::users_anno($ov) as $cflt) {
            $ps->update_conflict_value($cflt->email, CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR);
            if (!$cflt->contactId) {
                $ps->register_user($cflt);
            }
        }
        return true;
    }
    /** @param list<Author> $specau */
    private function apply_parsed_users(PaperValue $ov, $specau) {
        // look up primary emails
        $emails = [];
        foreach ($specau as $au) {
            $emails[] = $au->email;
        }
        $pemails = $this->conf->resolve_primary_emails($emails);
        // apply changes
        $curau = self::users_anno($ov);
        $modified = false;
        for ($i = 0; $i !== count($specau); ++$i) {
            $j = self::ca_index($curau, $pemails[$i]);
            if ($j !== false) {
                if ($specau[$i]->conflictType !== 0) {
                    $curau[$j]->author_index = $specau[$i]->author_index;
                    $modified = $modified
                        || (($curau[$j]->disabled ?? 0) & Contact::DISABLEMENT_PLACEHOLDER) !== 0;
                } else {
                    // only remove contacts on exact email match
                    // (removing by a non-primary email has no effect)
                    if ((($curau[$j]->conflictType ?? 0) & CONFLICT_AUTHOR) === 0
                        && strcasecmp($specau[$i]->email, $pemails[$i]) === 0) {
                        array_splice($curau, $j, 1);
                        $modified = true;
                    }
                }
            } else if ($j === false) {
                if ($specau[$i]->conflictType !== 0) {
                    $specau[$i]->email = $pemails[$i];
                    $curau[] = $specau[$i];
                    $modified = true;
                }
            }
        }
        // mark changes on value
        if ($modified) {
            $emails = $cids = [];
            foreach ($curau as $au) {
                $cids[] = $au->contactId;
                $emails[] = $au->email;
            }
            $ov->set_value_data($cids, $emails);
            $ov->set_anno("users", $curau);
            $ov->set_anno("modified", true);
        }
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $ov = PaperValue::make_force($prow, $this);
        // collect values
        $specau = $reqau = [];
        for ($n = 1; isset($qreq["contacts:email_{$n}"]); ++$n) {
            $email = trim($qreq["contacts:email_{$n}"]);
            $name = simplify_whitespace((string) $qreq["contacts:name_{$n}"]);
            $affiliation = simplify_whitespace((string) $qreq["contacts:affiliation_{$n}"]);
            $au = Author::make_keyed(["email" => $email, "name" => $name, "affiliation" => $affiliation]);
            $au->conflictType = $qreq["contacts:active_{$n}"] ? CONFLICT_CONTACTAUTHOR : 0;
            $au->author_index = $n;
            $reqau[] = $au;
            if (validate_email($email)) {
                $specau[] = $au;
            } else if ($email !== "") {
                $ov->msg_at("contacts:{$n}", "<0>Invalid email address ‘{$email}’", MessageSet::ERROR);
            } else if ($name !== "") {
                $ov->msg_at("contacts:{$n}", "<0>Email address required", MessageSet::ERROR);
            }
        }
        // apply specified values
        $this->apply_parsed_users($ov, $specau);
        $ov->set_anno("req_users", $reqau);
        return $ov;
    }
    function parse_json(PaperInfo $prow, $j) {
        $ov = PaperValue::make_force($prow, $this);
        // collect values
        $reqau = [];
        if (is_object($j) || is_associative_array($j)) {
            foreach ((array) $j as $k => $v) {
                if (is_bool($v)) {
                    $reqau[] = $au = Author::make_email($k);
                    $au->conflictType = $v ? CONFLICT_CONTACTAUTHOR : 0;
                } else if (is_object($v) && strcasecmp($v->email ?? $k, $k) === 0) {
                    $v->email = $k;
                    $reqau[] = $au = Author::make_keyed((array) $v);
                    $au->conflictType = ($v->contact ?? true) ? CONFLICT_CONTACTAUTHOR : 0;
                } else {
                    return PaperValue::make_estop($prow, $this, "<0>Validation error");
                }
            }
        } else if (is_array($j)) {
            foreach ($j as $x) {
                if (is_string($x)) {
                    $reqau[] = $au = Author::make_email($x);
                    $au->conflictType = CONFLICT_CONTACTAUTHOR;
                } else if (is_object($x) && is_string($x->email ?? null)) {
                    $reqau[] = $au = Author::make_keyed((array) $x);
                    $au->conflictType = ($x->contact ?? true) ? CONFLICT_CONTACTAUTHOR : 0;
                } else {
                    return PaperValue::make_estop($prow, $this, "<0>Validation error");
                }
            }
        } else {
            return PaperValue::make_estop($prow, $this, "<0>Validation error");
        }
        // check emails
        $specau = [];
        foreach ($reqau as $au) {
            if (validate_email($au->email)) {
                $specau[] = $au;
            } else if ($au->email !== "") {
                $ov->error("<0>Invalid email address ‘{$au->email}’");
            } else {
                $ov->error("<0>Email address required");
            }
        }
        // in JSON save (unlike web save), any unmentioned contacts are cleared
        foreach (self::users_anno($ov) as $au) {
            if (self::ca_index($reqau, $au->email) === false) {
                $specau[] = $au = Author::make_email($au->email);
                $au->conflictType = 0;
            }
        }
        // apply specified values
        $this->apply_parsed_users($ov, $specau);
        $ov->set_anno("req_users", $reqau);
        return $ov;
    }

    /** @param PaperValue $reqov */
    static private function editable_newcontact_row(PaperTable $pt, $anum, $reqov, Author $au = null) {
        if ($anum === '$') {
            $name = $email = "";
        } else {
            $email = $au->email;
            $name = $au->name();
        }
        $reqidx = $au && $au->author_index ? $au->author_index : '$';
        return '<div class="'
            . ($reqov ? $reqov->message_set()->control_class("contacts:$reqidx", "checki") : "checki")
            . '"><span class="checkc">'
            . Ht::checkbox("contacts:active_$anum", 1, true, ["data-default-checked" => false, "id" => false, "class" => "ignore-diff"])
            . '</span>'
            . Ht::entry("contacts:email_{$anum}", $email, ["size" => 30, "placeholder" => "Email", "class" => $pt->control_class("contacts:email_$reqidx", "want-focus js-autosubmit uii js-email-populate"), "autocomplete" => "off", "data-default-value" => ""])
            . '  '
            . Ht::entry("contacts:name_{$anum}", $name, ["size" => 35, "placeholder" => "Name", "class" => "js-autosubmit", "autocomplete" => "off", "data-default-value" => ""])
            . $pt->messages_at("contacts:{$reqidx}")
            . $pt->messages_at("contacts:name_{$reqidx}")
            . $pt->messages_at("contacts:email_{$reqidx}")
            . '</div>';
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $curau = self::users_anno($ov);
        foreach ($ov->prow->author_list() as $au) {
            if (self::ca_index($curau, $au->email) === false
                && validate_email($au->email)) {
                $au->conflictType = CONFLICT_AUTHOR;
                $curau[] = $au;
            }
        }
        usort($curau, $this->conf->user_comparator());
        $readonly = !$this->test_editable($ov->prow);

        $pt->print_editable_option_papt($this, null, ["id" => "contacts", "for" => false]);
        echo '<div class="papev js-row-order"><div>';

        $reqau = $reqov->anno("req_users") ?? [];
        '@phan-var list<Author> $reqau';

        $cidx = 1;
        foreach ($curau as $au) {
            $j = self::ca_index($reqau, $au->email);
            if ($j !== false) {
                $rau = $reqau[$j];
                array_splice($reqau, $j, 1);
            } else {
                $rau = null;
            }
            echo '<div class="',
                $rau && $rau->author_index
                    ? $pt->control_class("contacts:{$rau->author_index}", "checki")
                    : "checki",
                '"><label><span class="checkc">',
                Ht::hidden("contacts:email_{$cidx}", $au->email);
            if (($au->contactId > 0
                 && ($au->conflictType & CONFLICT_AUTHOR) !== 0
                 && (($au->disabled ?? 0) & Contact::DISABLEMENT_PLACEHOLDER) === 0)
                || ($au->contactId === $pt->user->contactId
                    && $ov->prow->paperId <= 0)) {
                echo Ht::hidden("contacts:active_{$cidx}", 1),
                    Ht::checkbox(null, 1, true, ["disabled" => true, "id" => false]);
            } else {
                echo Ht::checkbox("contacts:active_{$cidx}", 1, !$rau || $rau->conflictType !== 0,
                    ["data-default-checked" => $au->contactId > 0 && $au->conflictType >= CONFLICT_AUTHOR, "id" => false, "disabled" => $readonly]);
            }
            echo '</span>', Text::nameo_h($au, NAME_E);
            if (($au->conflictType & CONFLICT_AUTHOR) === 0
                && $ov->prow->paperId > 0) {
                echo ' (<em>non-author</em>)';
            }
            if ($pt->user->privChair
                && $au->contactId !== $pt->user->contactId) {
                echo ' ', actas_link($au);
            }
            echo '</label></div>';
            ++$cidx;
        }
        echo '</div>';

        if (!$readonly) {
            echo '<div data-row-template="',
                htmlspecialchars(self::editable_newcontact_row($pt, '$', null, null)),
                '">';
            foreach ($reqau as $rau) {
                echo self::editable_newcontact_row($pt, $cidx, $reqov, $rau);
                ++$cidx;
            }
            echo '</div><div class="ug">', Ht::button("Add contact", ["class" => "ui row-order-ui addrow"]), '</div>';
        }

        echo "</div></div>\n\n";
    }
    // XXX no render because paper strip
}
