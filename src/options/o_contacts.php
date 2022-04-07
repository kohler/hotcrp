<?php
// o_contacts.php -- HotCRP helper class for contacts intrinsic
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Contacts_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }
    function value_force(PaperValue $ov) {
        // $ov->value_list: contact IDs
        // $ov->data_list: emails
        // $ov->anno("users"): list<Author>
        // $ov->anno("bad_users"): list<Author>
        // NB fake papers start out with this user as contact
        $ca = $va = [];
        foreach ($ov->prow->contacts(true) as $cflt) {
            if ($cflt->contactId > 0) {
                $ca[] = $cflt;
                $va[$cflt->contactId] = $cflt->email;
            }
        }
        $ov->set_value_data(array_keys($va), array_values($va));
        $ov->set_anno("users", array_values($ca));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        $ca = [];
        foreach ($ov->anno("users") ?? [] as $c) {
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
            if (count($ov->value_list()) === 0
                && $ov->prow->paperId > 0
                && count($ov->prow->contacts()) > 0) {
                $ov->error($this->conf->_("<0>Each submission must have at least one contact"));
            }
            if (!$user->allow_administer($ov->prow)
                && $ov->prow->conflict_type($user) >= CONFLICT_CONTACTAUTHOR
                && self::ca_index($ov->anno("users"), $user->email) === false) {
                $ov->error($this->conf->_("<0>You can’t remove yourself from the submission’s contacts"));
                $ov->msg("<0>(Ask another contact to remove you.)", MessageSet::INFORM);
            }
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        // do not mark diff (will be marked later)
        $ps->clear_conflict_values(CONFLICT_CONTACTAUTHOR);
        foreach ($ov->anno("users") as $c) {
            $ps->update_conflict_value($c->email, CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR);
            if ($c->contactId === 0) {
                $ps->register_user($c);
            }
        }
        return true;
    }
    static function ca_index($ca, $email) {
        foreach ($ca as $i => $c) {
            if (strcasecmp($c->email, $email) === 0)
                return $i;
        }
        return false;
    }
    /** @param string $email
     * @return ?Author */
    function value_by_email(PaperValue $ov, $email) {
        $ca = $ov->anno("users") ?? [];
        $i = self::ca_index($ca, $email);
        return $i !== false ? $ca[$i] : null;
    }
    static private function apply_new_users(PaperValue $ov, $new_ca, &$ca) {
        $bad_ca = [];
        foreach ($new_ca as $c) {
            $c->contactId = 0;
            if (validate_email($c->email)) {
                $ca[] = $c;
            } else {
                if ($c->email === "" || strcasecmp($c->email, "Email") === 0) {
                    $ov->error("<0>Email address required");
                } else {
                    $ov->error("<0>Invalid email address ‘{$c->email}’");
                }
                if ($c->author_index) {
                    $ov->msg_at("contacts:{$c->author_index}", null, MessageSet::ERROR);
                }
                $bad_ca[] = $c;
            }
        }
        $ov->set_value_data(array_map(function ($c) { return $c->contactId; }, $ca),
                            array_map(function ($c) { return $c->email; }, $ca));
        $ov->set_anno("users", $ca);
        $ov->set_anno("bad_users", $bad_ca);
        $ov->set_anno("modified", true);
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $ov = PaperValue::make_force($prow, $this);
        $ca = $ov->anno("users");
        $new_ca = [];
        for ($n = 1; isset($qreq["contacts:email_$n"]); ++$n) {
            $email = trim($qreq["contacts:email_$n"]);
            $name = simplify_whitespace((string) $qreq["contacts:name_$n"]);
            $affiliation = simplify_whitespace((string) $qreq["contacts:affiliation_$n"]);
            if (($i = self::ca_index($ca, $email)) !== false) {
                if (!$qreq["contacts:active_$n"]
                    && ($ca[$i]->conflictType & CONFLICT_AUTHOR) === 0) {
                    array_splice($ca, $i, 1);
                }
            } else if (($email !== "" || $name !== "") && $qreq["contacts:active_$n"]) {
                $new_ca[] = $c = Author::make_keyed(["email" => $email, "name" => $name, "affiliation" => $affiliation]);
                $c->author_index = $n;
            }
        }
        self::apply_new_users($ov, $new_ca, $ca);
        return $ov;
    }
    function parse_json(PaperInfo $prow, $j) {
        $ov = PaperValue::make_force($prow, $this);
        $ca = $old_ca = $ov->anno("users");
        $new_ca = [];
        if (is_object($j) || is_associative_array($j)) {
            foreach ((array) $j as $k => $v) {
                $i = self::ca_index($ca, $k);
                if ($v === false) {
                    if ($i !== false
                        && ($ca[$i]->conflictType & CONFLICT_AUTHOR) === 0) {
                        array_splice($ca, $i, 1);
                    }
                } else if ($v === true
                           || (is_object($v) && strcasecmp($v->email ?? $k, $k) === 0)) {
                    if ($i === false) {
                        $a = $v === true ? [] : (array) $v;
                        $a["email"] = $k;
                        $new_ca[] = Author::make_keyed($a);
                    }
                } else {
                    return PaperValue::make_estop($prow, $this, "<0>Validation error");
                }
            }
        } else if (is_array($j)) {
            $ca = array_values(array_filter($ca, function ($au) {
                return ($au->conflictType & CONFLICT_AUTHOR) !== 0;
            }));
            foreach ($j as $v) {
                if (is_string($v)) {
                    $email = $v;
                } else if (is_object($v) && isset($v->email)) {
                    $email = $v->email;
                } else {
                    return PaperValue::make_estop($prow, $this, "<0>Validation error");
                }
                if (self::ca_index($ca, $email) !== false) {
                    // double mention -- do nothing
                } else if (($i = self::ca_index($old_ca, $email)) !== false) {
                    $ca[] = $old_ca[$i];
                } else {
                    $a = is_string($v) ? [] : (array) $v;
                    $a["email"] = $email;
                    $new_ca[] = Author::make_keyed($a);
                }
            }
        } else {
            return PaperValue::make_estop($prow, $this, "<0>Validation error");
        }
        self::apply_new_users($ov, $new_ca, $ca);
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
            . Ht::entry("contacts:email_$anum", $email, ["size" => 30, "placeholder" => "Email", "class" => $pt->control_class("contacts:email_$reqidx", "want-focus js-autosubmit uii js-email-populate"), "autocomplete" => "off", "data-default-value" => ""])
            . '  '
            . Ht::entry("contacts:name_$anum", $name, ["size" => 35, "placeholder" => "Name", "class" => "js-autosubmit", "autocomplete" => "off", "data-default-value" => ""])
            . $pt->messages_at("contacts:$reqidx")
            . $pt->messages_at("contacts:name_$reqidx")
            . $pt->messages_at("contacts:email_$reqidx")
            . '</div>';
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $contacts = $ov->anno("users") ?? [];
        foreach ($ov->prow->author_list() as $au) {
            if (!$this->value_by_email($ov, $au->email)
                && validate_email($au->email)) {
                $au->conflictType = CONFLICT_AUTHOR;
                $contacts[] = $au;
            }
        }
        usort($contacts, $this->conf->user_comparator());
        $readonly = !$this->test_editable($ov->prow);

        $pt->print_editable_option_papt($this, null, ["id" => "contacts", "for" => false]);
        echo '<div class="papev js-row-order"><div>';

        $cidx = 1;
        $foundreqau = [];
        foreach ($contacts as $au) {
            $foundreqau[] = $reqau = $this->value_by_email($reqov, $au->email);
            echo '<div class="',
                $reqau && $reqau->author_index
                    ? $pt->control_class("contacts:{$reqau->author_index}", "checki")
                    : "checki",
                '"><label><span class="checkc">',
                Ht::hidden("contacts:email_$cidx", $au->email);
            if (($au->contactId > 0 && ($au->conflictType & CONFLICT_AUTHOR) !== 0)
                || ($au->contactId === $pt->user->contactId && $ov->prow->paperId <= 0)) {
                echo Ht::hidden("contacts:active_$cidx", 1),
                    Ht::checkbox(null, 1, true, ["disabled" => true, "id" => false]);
            } else {
                echo Ht::checkbox("contacts:active_$cidx", 1, !!$reqau,
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

            $reqcontacts = array_merge($reqov->anno("users"), $reqov->anno("bad_users") ?? []);
            usort($reqcontacts, function ($a, $b) { return $a->author_index - $b->author_index; });
            foreach ($reqcontacts as $reqau) {
                if (!in_array($reqau, $foundreqau)) {
                    echo self::editable_newcontact_row($pt, $cidx, $reqov, $reqau);
                    ++$cidx;
                }
            }
            echo '</div><div class="ug">', Ht::button("Add contact", ["class" => "ui row-order-ui addrow"]), '</div>';
        }

        echo "</div></div>\n\n";
    }
    // XXX no render because paper strip
}
