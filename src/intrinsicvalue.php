<?php
// intrinsicvalue.php -- HotCRP helper class for paper options
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class IntrinsicValue {
    static function assign_intrinsic(PaperValue $ov) {
        if ($ov->id === DTYPE_SUBMISSION) {
            $ov->set_value_data([$ov->prow->paperStorageId], [null]);
        } else if ($ov->id === DTYPE_FINAL) {
            $ov->set_value_data([$ov->prow->finalPaperStorageId], [null]);
        } else if ($ov->id === PaperOption::ANONYMITYID) {
            if ($ov->prow->blind) {
                $ov->set_value_data([1], [null]);
            } else {
                $ov->set_value_data([], []);
            }
        } else {
            $ov->set_value_data([], []);
        }
        $ov->anno["intrinsic"] = true;
    }
    static function value_messages($o, PaperValue $ov, MessageSet $ms) {
        if (($o->id === PaperOption::TITLEID
             || ($o->id === PaperOption::ABSTRACTID && !$o->conf->opt("noAbstract")))
            && !$o->value_present($ov)) {
            $ms->error_at($o->field_key(), "Entry required.");
        }
        if ($o->id === DTYPE_SUBMISSION
            && !$o->conf->opt("noPapers")
            && !$o->value_present($ov)) {
            $ms->warning_at($o->field_key(), $o->conf->_("Entry required to complete submission."));
        }
        if ($o->id === PaperOption::AUTHORSID) {
            assert(isset($ov->anno["intrinsic"]));
            $msg1 = $msg2 = false;
            foreach ($ov->prow->author_list() as $n => $au) {
                if (strpos($au->email, "@") === false
                    && strpos($au->affiliation, "@") !== false) {
                    $msg1 = true;
                    $ms->warning_at("author" . ($n + 1), null);
                } else if ($au->firstName === "" && $au->lastName === ""
                           && $au->email === "" && $au->affiliation !== "") {
                    $msg2 = true;
                    $ms->warning_at("author" . ($n + 1), null);
                }
            }
            $max_authors = $o->conf->opt("maxAuthors");
            if (!$ov->prow->author_list()) {
                $ms->error_at("authors", "Entry required.");
                $ms->error_at("author1", false);
            }
            if ($max_authors > 0
                && count($ov->prow->author_list()) > $max_authors) {
                $ms->error_at("authors", $o->conf->_("Each submission can have at most %d authors.", $max_authors));
            }
            if ($msg1) {
                $ms->warning_at("authors", "You may have entered an email address in the wrong place. The first author field is for email, the second for name, and the third for affiliation.");
            }
            if ($msg2) {
                $ms->warning_at("authors", "Please enter a name and optional email address for every author.");
            }
        }
        if ($o->id === PaperOption::COLLABORATORSID
            && $o->conf->setting("sub_collab")
            && !$o->value_present($ov)
            && ($ov->prow->outcome <= 0 || ($ms->user && !$ms->user->can_view_decision($ov->prow)))) {
            $ms->warning_at("collaborators", $o->conf->_("Enter the authors’ external conflicts of interest. If none of the authors have external conflicts, enter “None”."));
        }
        if ($o->id === PaperOption::PCCONFID
            && $o->conf->setting("sub_pcconf")
            && ($ov->prow->outcome <= 0 || ($ms->user && !$ms->user->can_view_decision($ov->prow)))) {
            assert(isset($ov->anno["intrinsic"]));
            $pcs = [];
            foreach ($o->conf->full_pc_members() as $p) {
                if (!$ov->prow->has_conflict($p)
                    && $ov->prow->potential_conflict($p)) {
                    $n = Text::name_html($p);
                    $pcs[] = Ht::link($n, "#pcc{$p->contactId}", ["class" => "uu"]);
                }
            }
            if (!empty($pcs)) {
                $ms->warning_at("pcconf", $o->conf->_("You may have missed conflicts of interest with %s. Please verify that all conflicts are correctly marked.", commajoin($pcs, "and")) . $o->conf->_(" Hover over “possible conflict” labels for more information."));
            }
        }
    }
    static function parse_web($o, PaperInfo $prow, Qrequest $qreq) {
        if ($o->id === PaperOption::TITLEID) {
            $v = $qreq->title;
        } else if ($o->id === PaperOption::ABSTRACTID) {
            $v = $qreq->abstract;
        } else if ($o->id === PaperOption::COLLABORATORSID) {
            $v = $qreq->collaborators;
        } else {
            // XXX
            $v = "";
        }
        return PaperValue::make($prow, $o, 1, $v);
    }
    static function echo_web_edit($o, PaperTable $pt, $ov, $reqov) {
        if ($o->id === PaperOption::TITLEID) {
            $o->echo_web_edit_text($pt, $ov, $reqov, ["no_format_description" => true]);
        } else if ($o->id === PaperOption::ABSTRACTID) {
            if ((int) $o->conf->opt("noAbstract") !== 1) {
                $o->echo_web_edit_text($pt, $ov, $reqov);
            }
        } else if ($o->id === PaperOption::AUTHORSID) {
            $pt->echo_editable_authors($o);
        } else if ($o->id === PaperOption::ANONYMITYID) {
            $pt->echo_editable_anonymity($o);
        } else if ($o->id === PaperOption::CONTACTSID) {
            $pt->echo_editable_contact_author($o);
        } else if ($o->id === PaperOption::TOPICSID) {
            $pt->echo_editable_topics($o);
        } else if ($o->id === PaperOption::PCCONFID) {
            $pt->echo_editable_pc_conflicts($o);
        } else if ($o->id === PaperOption::COLLABORATORSID) {
            if ($o->conf->setting("sub_collab")
                && ($pt->editable !== "f" || $pt->user->can_administer($pt->prow))) {
                $o->echo_web_edit_text($pt, $ov, $reqov, ["no_format_description" => true, "no_spellcheck" => true]);
            }
        }
    }
}
