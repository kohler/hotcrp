<?php
// o_pcconflicts.php -- HotCRP helper class for PC conflicts intrinsic
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PCConflicts_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->set_exists_condition(!!$this->conf->setting("sub_pcconf"));
    }
    /** @return array<int,int> */
    static private function paper_value_map(PaperInfo $prow) {
        return array_intersect_key($prow->conflict_types(), $prow->conf->pc_members());
    }
    /** @return array<int,?string> */
    static private function value_map(PaperValue $ov) {
        return array_combine($ov->value_list(), $ov->data_list());
    }
    function value_force(PaperValue $ov) {
        $vm = self::paper_value_map($ov->prow);
        /** @phan-suppress-next-line PhanTypeMismatchArgument */
        $ov->set_value_data(array_keys($vm), array_values($vm));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        $pcm = $this->conf->pc_members();
        $confset = $this->conf->conflict_types();
        $can_view_authors = $ps->user->allow_view_authors($ov->prow);
        $pcc = [];
        foreach (self::value_map($ov) as $k => $v) {
            if (($pc = $pcm[$k] ?? null) && Conflict::is_conflicted((int) $v)) {
                $ct = (int) $v;
                if (!$can_view_authors) {
                    // Sometimes users can see conflicts but not authors.
                    // Don't expose author-ness during that period.
                    $ct = Conflict::set_pinned(Conflict::pc_part($ct), false);
                    $ct = $ct ? : Conflict::GENERAL;
                } else if ($ct & CONFLICT_CONTACTAUTHOR) {
                    $ct = ($ct | CONFLICT_AUTHOR) & ~CONFLICT_CONTACTAUTHOR;
                }
                $pcc[$pc->email] = $confset->unparse_json($ct);
            }
        }
        return (object) $pcc;
    }
    function value_check(PaperValue $ov, Contact $user) {
        if ($this->conf->setting("sub_pcconf")
            && ($ov->prow->outcome <= 0 || !$user->can_view_decision($ov->prow))) {
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
                $ov->warning($this->conf->_("<5>You may have missed conflicts of interest with %#s. Please verify that all conflicts are correctly marked.", $pcs) . $this->conf->_(" Hover over “possible conflict” labels for more information."));
            }
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
        return true;
    }
    private function update_value_map(&$vm, $k, $v) {
        $vm[$k] = (($vm[$k] ?? 0) & ~CONFLICT_PCMASK) | $v;
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $vm = self::paper_value_map($prow);
        foreach ($prow->conf->pc_members() as $cid => $pc) {
            if (isset($qreq["has_pcconf:$cid"]) || isset($qreq["pcconf:$cid"])
                // XXX backward compat:
                || isset($qreq["has_pcc$cid"]) || isset($qreq["pcc$cid"])) {
                $ct = $qreq["pcconf:$cid"] ?? $qreq["pcc$cid"] ?? "0";
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

        $vm = self::paper_value_map($prow);
        foreach ($vm as &$v) {
            $v &= ~CONFLICT_PCMASK;
        }
        unset($v);

        $confset = $prow->conf->conflict_types();
        $pv = new PaperValue($prow, $this);
        foreach ($ja as $email => $v) {
            if (is_string($email)
                && (is_bool($v) || is_int($v) || is_string($v))) {
                $pc = $prow->conf->cached_user_by_email($email);
                if ($pc && $pc->primaryContactId) {
                    $pc = $prow->conf->cached_user_by_id($pc->primaryContactId);
                }
                if (!$pc || !$pc->isPC) {
                    $pv->msg("<0>Email address ‘{$email}’ does not match a PC member", MessageSet::WARNING);
                }
                $ct = $confset->parse_json($v);
                if ($ct === false) {
                    $pv->msg("<0>Invalid conflict type ‘{$v}’", MessageSet::WARNING);
                    $ct = Conflict::GENERAL;
                }
                if ($pc && $pc->isPC) {
                    $this->update_value_map($vm, $pc->contactId, $ct);
                }
            } else {
                return PaperValue::make_estop($prow, $this, "<0>Validation error");
            }
        }
        /** @phan-suppress-next-line PhanTypeMismatchArgument */
        $pv->set_value_data(array_keys($vm), array_values($vm));
        return $pv;
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        assert(!!$this->conf->setting("sub_pcconf"));
        $admin = $pt->user->can_administer($ov->prow);
        if (!$this->conf->setting("sub_pcconf")
            || ($pt->editable === "f" && !$admin)) {
            return;
        }

        $this->conf->ensure_cached_user_collaborators();
        $pcm = $this->conf->pc_members();
        if (empty($pcm)) {
            return;
        }

        $selectors = $this->conf->setting("sub_pcconfsel");
        $confset = $this->conf->conflict_types();
        $ctypes = [];
        if ($selectors) {
            $ctypes[0] = $confset->unparse_text(0);
            foreach ($confset->basic_conflict_types() as $ct) {
                $ctypes[$ct] = $confset->unparse_text($ct);
            }
            if ($admin) {
                $ctypes["xsep"] = null;
                $ct = Conflict::set_pinned(Conflict::GENERAL, true);
                $ctypes[$ct] = $confset->unparse_text($ct);
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

        $pt->print_editable_option_papt($this, null, ["id" => $this->formid]);
        echo '<div class="papev"><ul class="pc-ctable">';
        $readonly = !$this->test_editable($ov->prow);

        foreach ($pcm as $id => $p) {
            $pct = $ctmaps[0][$p->contactId] ?? 0;
            $ct = $ctmaps[1][$p->contactId] ?? 0;
            $pcconfmatch = false;
            '@phan-var false|array{string,list<string>} $pcconfmatch';
            if ($ov->prow->paperId && $pct < CONFLICT_AUTHOR) {
                $pcconfmatch = $ov->prow->potential_conflict_html($p, $pct <= 0);
            }

            $label = $pt->user->reviewer_html_for($p);
            if ($p->affiliation) {
                $label .= '<span class="pcconfaff">' . htmlspecialchars(UnicodeHelper::utf8_abbreviate($p->affiliation, 60)) . '</span>';
            }

            echo '<li class="ctelt"><div class="ctelti';
            if (!$selectors) {
                echo ' checki';
            }
            echo ' clearfix';
            if (Conflict::is_conflicted($pct)) {
                echo ' boldtag';
            }
            if ($pcconfmatch) {
                echo ' need-tooltip" data-tooltip-class="gray" data-tooltip="', str_replace('"', '&quot;', PaperInfo::potential_conflict_tooltip_html($pcconfmatch));
            }
            echo '"><label>';

            $js = ["id" => "pcconf:$id", "disabled" => $readonly];
            $hidden = "";
            if (Conflict::is_author($pct)
                || (!$admin && Conflict::is_pinned($pct))) {
                if ($selectors) {
                    echo '<span class="pcconf-editselector"><strong>';
                    if (Conflict::is_author($pct)) {
                        echo "Author";
                    } else if (Conflict::is_conflicted($pct)) {
                        echo "Conflict"; // XXX conflict type?
                    } else {
                        echo "Non-conflict";
                    }
                    echo '</strong></span>';
                } else {
                    echo '<span class="checkc">', Ht::checkbox(null, 1, Conflict::is_conflicted($pct), ["disabled" => true]), '</span>';
                }
                echo Ht::hidden("pcconf:$id", $pct, ["class" => "conflict-entry", "disabled" => true]);
            } else if ($selectors) {
                $xctypes = $ctypes;
                if (!isset($xctypes[$ct])) {
                    $xctypes[$ct] = $confset->unparse_text($ct);
                }
                $js["class"] = "conflict-entry";
                $js["data-default-value"] = $pct;
                echo '<span class="pcconf-editselector">',
                    Ht::select("pcconf:$id", $xctypes, $ct, $js),
                    '</span>';
            } else {
                $js["data-default-checked"] = Conflict::is_conflicted($pct);
                $js["data-range-type"] = "pcconf";
                $js["class"] = "uic js-range-click conflict-entry";
                $checked = Conflict::is_conflicted($ct);
                echo '<span class="checkc">',
                    Ht::checkbox("pcconf:$id", $checked ? $ct : Conflict::GENERAL, $checked, $js),
                    '</span>';
                $hidden = Ht::hidden("has_pcconf:$id", 1);
            }

            echo $label, "</label>", $hidden;
            if ($pcconfmatch) {
                echo $pcconfmatch[0];
            }
            echo "</div></li>";
        }
        echo "</ul></div></div>\n\n";
    }
    // XXX no render because paper strip
}
