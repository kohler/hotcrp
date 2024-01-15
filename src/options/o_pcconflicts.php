<?php
// o_pcconflicts.php -- HotCRP helper class for PC conflicts intrinsic
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class PCConflicts_PaperOption extends PaperOption {
    /** @var ?string */
    private $visible_if;
    /** @var ?SearchTerm */
    private $_visible_term;
    /** @var bool */
    private $selectors;
    /** @var bool */
    private $warn_missing = true;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        // XXX `final`
        // Presence for PC conflicts is special. The field is always present
        // (test_exists() always returns true), so that admins can set
        // conflicts. The presence/exists_if configuration affects *visibility*
        // instead.
        if (empty($this->conf->pc_members())) {
            $this->visible_if = "NONE";
        } else {
            $this->visible_if = $this->exists_condition();
        }
        $this->override_exists_condition(true);
        $this->selectors = !!($args->selectors ?? false);
    }
    /** @param bool $warn_missing */
    function set_warn_missing($warn_missing) {
        $this->warn_missing = $warn_missing;
    }
    /** @return array<int,int> */
    static private function paper_value_map(PaperInfo $prow) {
        return array_intersect_key($prow->conflict_types(), $prow->conf->pc_members());
    }
    /** @return array<int,?string> */
    static private function value_map(PaperValue $ov) {
        return array_combine($ov->value_list(), $ov->data_list());
    }
    /** @return bool */
    final function test_visible(PaperInfo $prow) {
        if ($this->visible_if === null) {
            return true;
        }
        if ($this->_visible_term === null) {
            $s = new PaperSearch($this->conf->root_user(), $this->visible_if);
            $this->_visible_term = $s->full_term();
        }
        return $this->_visible_term->test($prow, null);
    }
    function value_force(PaperValue $ov) {
        $vm = self::paper_value_map($ov->prow);
        /** @phan-suppress-next-line PhanTypeMismatchArgument */
        $ov->set_value_data(array_keys($vm), array_values($vm));
    }
    function value_export_json(PaperValue $ov, PaperExport $pex) {
        $pcm = $this->conf->pc_members();
        $confset = $this->conf->conflict_set();
        $can_view_authors = $pex->user->allow_view_authors($ov->prow);
        $pcc = [];
        foreach (self::value_map($ov) as $k => $v) {
            if (($pc = $pcm[$k] ?? null) && Conflict::is_conflicted((int) $v)) {
                $ct = (int) $v;
                if (!$can_view_authors) {
                    // Sometimes users can see conflicts but not authors.
                    // Don't expose author-ness during that period.
                    $ct = Conflict::set_pinned(Conflict::pc_part($ct), false);
                    $ct = $ct ? : Conflict::GENERAL;
                } else if (($ct & CONFLICT_CONTACTAUTHOR) !== 0) {
                    $ct = ($ct | CONFLICT_AUTHOR) & ~CONFLICT_CONTACTAUTHOR;
                }
                $pcc[$pc->email] = $confset->unparse_json($ct);
            }
        }
        return (object) $pcc;
    }
    function value_check(PaperValue $ov, Contact $user) {
        if ($this->test_visible($ov->prow)) {
            if ($this->warn_missing) {
                $this->_warn_missing_conflicts($ov, $user);
            }
        } else if ($user->act_author_view($ov->prow)) {
            $this->_warn_changes($ov);
        }
    }
    private function _warn_missing_conflicts(PaperValue $ov, Contact $user) {
        if ($ov->prow->outcome_sign > 0 && $user->can_view_decision($ov->prow)) {
            return;
        }
        $vm = self::value_map($ov);
        $pcs = [];
        $this->conf->ensure_cached_user_collaborators();
        foreach ($this->conf->pc_members() as $p) {
            if (($vm[$p->contactId] ?? 0) === 0 /* not MAXUNCONFLICTED */
                && $ov->prow->potential_conflict($p)) {
                $pcs[] = Ht::link($p->name_h(NAME_P), "#pcconf:{$p->contactId}");
            }
        }
        if (!empty($pcs)) {
            $ov->warning(Ftext::join_nonempty(" ", [
                $this->conf->_("<5>You may have missed conflicts of interest with {:list}. Please verify that all conflicts are correctly marked.", $pcs),
                $this->conf->_("<5>Hover over “possible conflict” labels for more information.")
            ]));
        }
    }
    private function _warn_changes(PaperValue $ov) {
        $vm = self::value_map($ov);
        $old_vm = self::paper_value_map($ov->prow);
        ksort($vm);
        ksort($old_vm);
        if ($vm !== $old_vm) {
            /** @phan-suppress-next-line PhanTypeMismatchArgument */
            $ov->set_value_data(array_keys($old_vm), array_values($old_vm));
            $ov->error("<0>Changes ignored");
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        // do not mark diff (will be marked later)
        $pcm = $this->conf->pc_members();
        if ($ov->prow->paperId > 0
            ? $ps->user->can_administer($ov->prow)
            : $ps->user->privChair) {
            $mask = CONFLICT_PCMASK;
        } else {
            $mask = CONFLICT_PCMASK & ~1;
        }
        foreach (self::value_map($ov) as $k => $v) {
            $ps->update_conflict_value($pcm[$k]->email, $mask, ((int) $v) & $mask);
        }
        $ps->checkpoint_conflict_values();
        return true;
    }
    private function update_value_map(&$vm, $k, $v) {
        $vm[$k] = (($vm[$k] ?? 0) & ~CONFLICT_PCMASK) | $v;
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $vm = self::paper_value_map($prow);
        foreach ($prow->conf->pc_members() as $cid => $pc) {
            if (isset($qreq["has_pcconf:{$cid}"]) || isset($qreq["pcconf:{$cid}"])) {
                $ct = $qreq["pcconf:{$cid}"] ?? "0";
                if (ctype_digit($ct) && $ct >= 0 && $ct <= 127) {
                    $this->update_value_map($vm, $cid, (int) $ct);
                }
            }
        }
        /** @phan-suppress-next-line PhanTypeMismatchArgument */
        return PaperValue::make_multi($prow, $this, array_keys($vm), array_values($vm));
    }
    function parse_json(PaperInfo $prow, $j) {
        $ja = [];
        if (is_object($j) || is_associative_array($j)) {
            foreach ((array) $j as $k => $v) {
                $ja[strtolower($k)] = $v;
            }
        } else if (is_array($j)) {
            foreach ($j as $x) {
                if (is_string($x)) {
                    $ja[strtolower($x)] = true;
                } else {
                    return PaperValue::make_estop($prow, $this, "<0>Validation error");
                }
            }
        } else {
            return PaperValue::make_estop($prow, $this, "<0>Validation error");
        }

        // parse conflicts
        $confset = $prow->conf->conflict_set();
        $pv = new PaperValue($prow, $this);
        $emails = [];
        $values = [];
        foreach ($ja as $email => $v) {
            if (is_string($email)
                && (is_bool($v) || is_int($v) || is_string($v))) {
                $ct = $confset->parse_json($v);
                if ($ct === false) {
                    $pv->msg("<0>Invalid conflict type ‘{$v}’", MessageSet::WARNING);
                    $ct = Conflict::GENERAL;
                }
                $emails[] = $email;
                $values[] = $ct;
            } else {
                return PaperValue::make_estop($prow, $this, "<0>Validation error");
            }
        }

        // look up primary emails
        $pemails = $this->conf->resolve_primary_emails($emails);

        // apply conflicts, save result
        $vm = self::paper_value_map($prow);
        foreach ($vm as &$v) {
            $v &= ~CONFLICT_PCMASK;
        }
        unset($v);
        for ($i = 0; $i !== count($emails); ++$i) {
            $pc = $prow->conf->user_by_email($pemails[$i], USER_SLICE);
            if ($pc && $pc->isPC) {
                $this->update_value_map($vm, $pc->contactId, $values[$i]);
            } else {
                $pv->msg("<0>Email address ‘{$emails[$i]}’ does not match a PC member", MessageSet::WARNING);
            }
        }
        /** @phan-suppress-next-line PhanTypeMismatchArgument */
        $pv->set_value_data(array_keys($vm), array_values($vm));
        return $pv;
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $admin = $pt->user->can_administer($ov->prow);
        if (!$this->test_visible($ov->prow)
            && !$pt->settings_mode) {
            return;
        }

        $this->conf->ensure_cached_user_collaborators();
        $pcm = $this->conf->pc_members();
        if (empty($pcm)
            && !$pt->settings_mode) {
            return;
        }

        $confset = $this->conf->conflict_set();
        $ctypes = [];
        if ($this->selectors) {
            $ctypes[0] = $confset->unparse_selector_text(0);
            foreach ($confset->basic_conflict_types() as $ct) {
                $ctypes[$ct] = $confset->unparse_selector_text($ct);
            }
            if ($admin) {
                $ctypes["xsep"] = null;
                $ct = Conflict::set_pinned(Conflict::GENERAL, true);
                $ctypes[$ct] = $confset->unparse_selector_text($ct);
            }
        }

        $ctmaps = [[], []];
        foreach ([$ov, $reqov] as $num => $value) {
            $vs = $value->value_list();
            $ds = $value->data_list();
            for ($i = 0; $i !== count($vs); ++$i) {
                $ctmaps[$num][$vs[$i]] = (int) $ds[$i];
            }
        }

        $pt->print_editable_option_papt($this, null, [
            "id" => $this->formid, "for" => false
        ]);
        echo '<div class="papev"><ul class="pc-ctable">';

        foreach ($pcm as $id => $p) {
            $pct = $ctmaps[0][$p->contactId] ?? 0;
            $ct = $ctmaps[1][$p->contactId] ?? 0;

            $name = $pt->user->reviewer_html_for($p);
            $potconf = null;
            if ($ov->prow->paperId && $pct < CONFLICT_AUTHOR) {
                $potconf = $ov->prow->potential_conflict_html($p, $pct <= 0);
            }

            echo '<li class="ctelt"><div class="ctelti clearfix',
                $this->selectors ? "" : " checki",
                Conflict::is_conflicted($pct) ? " tag-bold" : "";
            if ($potconf) {
                echo ' need-tooltip" data-tooltip-class="gray" data-tooltip="', str_replace('"', '&quot;', PaperInfo::potential_conflict_tooltip_html($potconf));
            }
            echo '">';

            $js = ["id" => "pcconf:{$id}"];
            $hidden = "";
            if (Conflict::is_author($pct)
                || (!$admin && Conflict::is_pinned($pct))) {
                if ($this->selectors) {
                    if (Conflict::is_author($pct)) {
                        $confx = "<strong>Author</strong>";
                    } else if (Conflict::is_conflicted($pct)) {
                        $confx = "<strong>Conflict</strong>"; // XXX conflict type?
                    } else {
                        $confx = "<strong>No conflict</strong>";
                    }
                } else {
                    $confx = Ht::checkbox(null, 1, Conflict::is_conflicted($pct), ["disabled" => true]);
                }
                $hidden = Ht::hidden("pcconf:{$id}", $pct, ["class" => "conflict-entry", "disabled" => true]);
            } else if ($this->selectors) {
                $xctypes = $ctypes;
                if (!isset($xctypes[$ct])) {
                    $xctypes[$ct] = $confset->unparse_selector_text($ct);
                }
                $js["class"] = "conflict-entry";
                $js["data-default-value"] = $pct;
                $confx = Ht::select("pcconf:{$id}", $xctypes, $ct, $js);
            } else {
                $js["data-default-checked"] = Conflict::is_conflicted($pct);
                $js["data-range-type"] = "pcconf";
                $js["class"] = "uic js-range-click conflict-entry";
                $checked = Conflict::is_conflicted($ct);
                $confx = Ht::checkbox("pcconf:{$id}", $checked ? $ct : Conflict::GENERAL, $checked, $js);
                $hidden = Ht::hidden("has_pcconf:{$id}", 1);
            }

            if ($this->selectors) {
                echo "<label for=\"pcconf:{$id}\">", $name, "</label>",
                    '<span class="pcconf-editselector">', $confx, '</span>';
            } else {
                echo "<label><span class=\"checkc\">", $confx, "</span>", $name, "</label>";
            }
            if ($p->affiliation) {
                echo '<span class="pcconfaff">' . htmlspecialchars(UnicodeHelper::utf8_abbreviate($p->affiliation, 60)) . '</span>';
            }
            if ($potconf) {
                echo $potconf->announce;
            }
            echo $hidden, "</div></li>";
        }

        if (empty($pcm)) { // only in settings mode
            echo '<li class="ctelt"><div class="ctelti"><em>(The PC has no members)</em></div></li>';
        }

        echo "</ul></div></div>\n\n";
    }
    /** @param FieldChangeSet $fcs */
    function strip_unchanged_qreq(PaperInfo $prow, Qrequest $qreq, $fcs) {
        foreach ($prow->conf->pc_members() as $cid => $pc) {
            if ($fcs->test("pcconf:{$cid}") === FieldChangeSet::UNCHANGED) {
                unset($qreq["has_pcconf:{$cid}"], $qreq["pcconf:{$cid}"]);
            }
        }
    }
    function render(FieldRender $fr, PaperValue $ov) {
        if (!$this->test_visible($ov->prow)) {
            return;
        }
        // XXX potential conflicts?
        $user = $fr->user ?? Contact::make($this->conf);
        $pcm = $this->conf->pc_members();
        $confset = $this->selectors ? $this->conf->conflict_set() : null;
        $names = [];
        foreach ($ov->prow->conflict_type_list() as $cflt) {
            if (Conflict::is_conflicted($cflt->conflictType)
                && ($p = $pcm[$cflt->contactId] ?? null)) {
                $t = $user->reviewer_html_for($p);
                if ($p->affiliation) {
                    $t .= " <span class=\"auaff\">(" . htmlspecialchars($p->affiliation) . ")</span>";
                }
                $ch = "";
                if (Conflict::is_author($cflt->conflictType)) {
                    $ch = "<strong>Author</strong>";
                } else if ($confset) {
                    $ch = $confset->unparse_html($cflt->conflictType);
                }
                if ($ch !== "") {
                    $t .= " - {$ch}";
                }
                $names[$p->pc_index] = "<li class=\"odname\">{$t}</li>";
            }
        }
        if (empty($names)) {
            $names[] = "<li class=\"odname\">None</li>";
        }
        ksort($names);
        $fr->set_html("<ul class=\"x namelist-columns\">" . join("", $names) . "</ul>");
    }

    function export_setting() {
        $sfs = parent::export_setting();
        $sfs->exists_if = $this->visible_if ?? "all";
        return $sfs;
    }
}
