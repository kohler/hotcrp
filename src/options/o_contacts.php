<?php
// o_contacts.php -- HotCRP helper class for contacts intrinsic
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
        // $ov->anno("users"): list<Contact>
        // NB fake papers start out with this user as contact
        // NB only non-placeholder users
        $ca = $va = [];
        foreach ($ov->prow->conflict_list() as $cu) {
            if ($cu->conflictType < CONFLICT_AUTHOR
                || $cu->contactId <= 0
                || $cu->user->is_placeholder()) {
                continue;
            }
            $ca[] = $au = Author::make_user($cu->user);
            $au->conflictType = $cu->conflictType;
            $va[$cu->contactId] = $cu->user->email;
        }
        $ov->set_value_data(array_keys($va), array_values($va));
        $ov->set_anno("users", $ca);
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        $ca = [];
        foreach (self::users_anno($ov) as $u) {
            if ($u->contactId >= 0)
                $ca[$u->contactId] = $u;
        }
        foreach ($ov->value_list() as $uid) {
            if (!isset($ca[$uid]))
                $ps->conf->prefetch_user_by_id($uid);
        }
        $j = [];
        foreach ($ov->value_list() as $uid) {
            if (($u = $ca[$uid] ?? $ps->conf->user_by_id($uid, USER_SLICE)))
                $j[] = Author::unparse_nea_json_for($u);
        }
        return $j;
    }
    function value_check(PaperValue $ov, Contact $user) {
        if ($ov->anno("modified") && !$user->allow_administer($ov->prow)) {
            if ($ov->prow->conflict_type($user) >= CONFLICT_CONTACTAUTHOR
                && self::ca_index(self::users_anno($ov), $user->email) === false) {
                $ov->error($this->conf->_("<0>You can’t remove yourself from the submission’s contacts"));
                $ov->msg("<0>(Ask another contact to remove you.)", MessageSet::INFORM);
            } else if (empty($ov->value_list())
                       && $ov->prow->paperId > 0
                       && !empty($ov->prow->contact_list())) {
                $ov->error($this->conf->_("<0>Each submission must have at least one contact"));
            }
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        // do not mark diff (will be marked later)
        $ps->clear_conflict_values(CONFLICT_CONTACTAUTHOR);
        foreach (self::users_anno($ov) as $u) {
            $ps->update_conflict_value($u, CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR);
        }
        $ps->checkpoint_conflict_values();
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
                    $modified = $modified || $curau[$j]->is_placeholder();
                } else {
                    // only remove contacts on exact email match
                    // (removing by a non-primary email has no effect)
                    if ((($curau[$j]->conflictType ?? 0) & CONFLICT_AUTHOR) === 0
                        && strcasecmp($specau[$i]->email, $pemails[$i]) === 0) {
                        array_splice($curau, $j, 1);
                        $modified = true;
                    }
                }
            } else {
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
    static private function translate_qreq(Qrequest $qreq) {
        $n = 1;
        while (isset($qreq["contacts:email_{$n}"])) {
            $qreq["contacts:{$n}:email"] = $qreq["contacts:email_{$n}"];
            $qreq["contacts:{$n}:name"] = $qreq["contacts:name_{$n}"];
            $qreq["contacts:{$n}:affiliation"] = $qreq["contacts:affiliation_{$n}"];
            $qreq["contacts:{$n}:active"] = $qreq["contacts:active_{$n}"];
            ++$n;
        }
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        if (!isset($qreq["contacts:1:email"])) {
            self::translate_qreq($qreq);
        }
        $ov = PaperValue::make_force($prow, $this);
        // collect values
        $specau = $reqau = [];
        for ($n = 1; isset($qreq["contacts:{$n}:email"]); ++$n) {
            $email = trim($qreq["contacts:{$n}:email"]);
            $name = simplify_whitespace((string) $qreq["contacts:{$n}:name"]);
            $affiliation = simplify_whitespace((string) $qreq["contacts:{$n}:affiliation"]);
            $au = Author::make_keyed(["email" => $email, "name" => $name, "affiliation" => $affiliation]);
            // XXX has_contacts:{$n}:active
            $au->conflictType = $qreq["contacts:{$n}:active"] ? CONFLICT_CONTACTAUTHOR : 0;
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
    static private function echo_editable_newcontact_row(PaperTable $pt, $anum, $reqov, Author $au = null) {
        if ($anum === '$') {
            $name = $email = "";
        } else {
            $email = $au->email;
            $name = $au->name();
        }
        $reqidx = $au && $au->author_index ? $au->author_index : '$';
        $klass = "checki mt-1";
        echo '<div class="',
            ($reqov ? $reqov->message_set()->control_class("contacts:{$reqidx}", $klass) : $klass),
            '"><span class="checkc">',
            Ht::hidden("has_contacts:{$anum}:active", 1),
            Ht::checkbox("contacts:{$anum}:active", 1, true, ["data-default-checked" => false, "id" => false, "class" => "ignore-diff"]),
            '</span>',
            Ht::entry("contacts:{$anum}:email", $email, ["size" => 30, "placeholder" => "Email", "class" => $pt->control_class("contacts:{$reqidx}:email", "want-focus js-autosubmit uii js-email-populate mr-2"), "autocomplete" => "off", "data-default-value" => ""]),
            Ht::entry("contacts:{$anum}:name", $name, ["size" => 35, "placeholder" => "Name", "class" => "js-autosubmit", "autocomplete" => "off", "data-default-value" => ""]),
            $pt->messages_at("contacts:{$reqidx}"),
            $pt->messages_at("contacts:{$reqidx}:name"),
            $pt->messages_at("contacts:{$reqidx}:email"),
            '</div>';
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

        $pt->print_editable_option_papt($this, null, ["id" => "contacts", "for" => false]);
        echo '<div class="papev"><div id="contacts:container">';

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
                Ht::hidden("contacts:{$cidx}:email", $au->email);
            if (($au->contactId > 0
                 && ($au->conflictType & CONFLICT_AUTHOR) !== 0
                 && !$au->is_placeholder())
                || ($au->contactId === $pt->user->contactId
                    && $ov->prow->paperId <= 0)) {
                echo Ht::hidden("contacts:{$cidx}:active", 1),
                    Ht::checkbox(null, 1, true, ["disabled" => true, "id" => "contacts:{$cidx}:placeholder"]);
            } else {
                $dchecked = $au->contactId > 0 && $au->conflictType >= CONFLICT_AUTHOR;
                echo Ht::hidden("has_contacts:{$cidx}:active", 1),
                    Ht::checkbox("contacts:{$cidx}:active", 1, $rau ? $rau->conflictType !== 0 : $dchecked,
                        ["data-default-checked" => $dchecked, "id" => false]);
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

        foreach ($reqau as $rau) {
            self::echo_editable_newcontact_row($pt, $cidx, $reqov, $rau);
            ++$cidx;
        }
        echo '</div><template id="contacts:row-template" class="hidden">';
        self::echo_editable_newcontact_row($pt, '$', null, null);
        echo '</template><div class="ug">',
            Ht::button("Add contact", ["class" => "ui row-order-append", "data-rowset" => "contacts:container", "data-row-template" => "contacts:row-template"]),
            "</div></div></div>\n\n";
    }
    // XXX no render because paper strip
}
