<?php
// intrinsicvalue.php -- HotCRP helper class for paper options
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class IntrinsicValue {
    static function assign_intrinsic(PaperValue $ov) {
        if ($ov->id === DTYPE_SUBMISSION) {
            $ov->set_value_data([(int) $ov->prow->paperStorageId], [null]);
        } else if ($ov->id === DTYPE_FINAL) {
            $ov->set_value_data([(int) $ov->prow->finalPaperStorageId], [null]);
        } else {
            $ov->set_value_data([], []);
        }
    }
    static function value_check($o, PaperValue $ov, Contact $user) {
        if ($o->id === DTYPE_SUBMISSION
            && !$o->conf->opt("noPapers")
            && !$o->value_present($ov)
            && !$ov->prow->allow_absent()) {
            $ov->warning($o->conf->_("Entry required to complete submission."));
        }
        if ($o->id === PaperOption::AUTHORSID) {
            $msg1 = $msg2 = false;
            foreach ($ov->prow->author_list() as $n => $au) {
                if (strpos($au->email, "@") === false
                    && strpos($au->affiliation, "@") !== false) {
                    $msg1 = true;
                    $ov->msg_at("author" . ($n + 1), false, MessageSet::WARNING);
                } else if ($au->firstName === "" && $au->lastName === ""
                           && $au->email === "" && $au->affiliation !== "") {
                    $msg2 = true;
                    $ov->msg_at("author" . ($n + 1), false, MessageSet::WARNING);
                }
            }
            $max_authors = $o->conf->opt("maxAuthors");
            if (!$ov->prow->author_list()
                && !$ov->prow->allow_absent()) {
                $ov->msg_at("author1", false, MessageSet::ERROR);
            }
            if ($max_authors > 0
                && count($ov->prow->author_list()) > $max_authors) {
                $ov->estop($o->conf->_("Each submission can have at most %d authors.", $max_authors));
            }
            if ($msg1) {
                $ov->warning("You may have entered an email address in the wrong place. The first author field is for email, the second for name, and the third for affiliation.");
            }
            if ($msg2) {
                $ov->warning("Please enter a name and optional email address for every author.");
            }
        }
    }
    static function echo_web_edit($o, PaperTable $pt, $ov, $reqov) {
        if ($o->id === PaperOption::AUTHORSID) {
            $pt->echo_editable_authors($o);
        }
    }
}
