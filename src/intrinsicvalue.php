<?php
// intrinsicvalue.php -- HotCRP helper class for paper options
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Title_PaperOption extends PaperOption {
    function __construct($conf, $args) {
        parent::__construct($conf, $args);
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return (string) $ov->data();
    }
    function value_load_intrinsic(PaperValue $ov) {
        if ((string) $ov->prow->title !== "") {
            $ov->set_value_data([1], [$ov->prow->title]);
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->save_paperf("title", $ov->data());
        return true;
    }
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        return $this->parse_json_string($prow, $qreq->title, PaperOption::PARSE_STRING_CONVERT | PaperOption::PARSE_STRING_SIMPLIFY);
    }
    function parse_json(PaperInfo $prow, $j) {
        return $this->parse_json_string($prow, $j, PaperOption::PARSE_STRING_SIMPLIFY);
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        $this->echo_web_edit_text($pt, $ov, $reqov, ["no_format_description" => true]);
    }
    function render(FieldRender $fr, PaperValue $ov) {
        $fr->value = $ov->prow->title ? : "[No title]";
        $fr->value_format = $ov->prow->title_format();
    }
}

class Abstract_PaperOption extends PaperOption {
    function __construct($conf, $args) {
        parent::__construct($conf, $args);
        $this->set_required(!$conf->opt("noAbstract"));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return (string) $ov->data();
    }
    function value_load_intrinsic(PaperValue $ov) {
        if ((string) $ov->prow->abstract !== "") {
            $ov->set_value_data([1], [$ov->prow->abstract]);
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->save_paperf("abstract", $ov->data());
        return true;
    }
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        return $this->parse_json_string($prow, $qreq->abstract, PaperOption::PARSE_STRING_CONVERT | PaperOption::PARSE_STRING_TRIM);
    }
    function parse_json(PaperInfo $prow, $j) {
        return $this->parse_json_string($prow, $j, PaperOption::PARSE_STRING_TRIM);
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        if ((int) $this->conf->opt("noAbstract") !== 1) {
            $this->echo_web_edit_text($pt, $ov, $reqov);
        }
    }
    function render(FieldRender $fr, PaperValue $ov) {
        if ($fr->for_page()) {
            $fr->table->render_abstract($fr, $this);
        } else {
            $text = $ov->prow->abstract;
            if (trim($text) !== "") {
                $fr->value = $text;
                $fr->value_format = $ov->prow->abstract_format();
            } else if (!$this->conf->opt("noAbstract")
                       && $fr->verbose()) {
                $fr->set_text("[No abstract]");
            }
        }
    }
}

class Collaborators_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->set_exists_if(!!$this->conf->setting("sub_collab"));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return (string) $ov->data();
    }
    function value_load_intrinsic(PaperValue $ov) {
        if ((string) $ov->prow->collaborators !== "") {
            $ov->set_value_data([1], [$ov->prow->collaborators]);
        }
    }
    function value_check(PaperValue $ov, Contact $user) {
        if (!$this->value_present($ov)
            && ($ov->prow->outcome <= 0 || !$user->can_view_decision($ov->prow))) {
            $ov->warning($this->conf->_("Enter the authors’ external conflicts of interest. If none of the authors have external conflicts, enter “None”."));
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->save_paperf("collaborators", $ov->data());
        return true;
    }
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        $ov = $this->parse_json_string($prow, $qreq->collaborators, PaperOption::PARSE_STRING_CONVERT | PaperOption::PARSE_STRING_TRIM);
        return $ov->value ? $this->normalize_value($ov) : $ov;
    }
    function parse_json(PaperInfo $prow, $j) {
        $ov = $this->parse_json_string($prow, $j, PaperOption::PARSE_STRING_TRIM);
        return $ov->value ? $this->normalize_value($ov) : $ov;
    }
    private function normalize_value(PaperValue $ov) {
        $s = rtrim(cleannl($ov->data()));
        $fix = (string) AuthorMatcher::fix_collaborators($s);
        if ($s !== $fix) {
            $ov->warning("This field was changed to follow our required format. Please check that the result is what you expect.");
            $ov->set_value_data([1], [$fix]);
        }
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        if ($pt->editable !== "f" || $pt->user->can_administer($pt->prow)) {
            $this->echo_web_edit_text($pt, $ov, $reqov, ["no_format_description" => true, "no_spellcheck" => true]);
        }
    }
    // XXX no render because paper strip
}

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
    static function value_check($o, PaperValue $ov, Contact $user) {
        if ($o->id === DTYPE_SUBMISSION
            && !$o->conf->opt("noPapers")
            && !$o->value_present($ov)) {
            $ov->warning($o->conf->_("Entry required to complete submission."));
        }
        if ($o->id === PaperOption::AUTHORSID) {
            assert(isset($ov->anno["intrinsic"]));
            $msg1 = $msg2 = false;
            foreach ($ov->prow->author_list() as $n => $au) {
                if (strpos($au->email, "@") === false
                    && strpos($au->affiliation, "@") !== false) {
                    $msg1 = true;
                    $ov->warning_at("author" . ($n + 1), false);
                } else if ($au->firstName === "" && $au->lastName === ""
                           && $au->email === "" && $au->affiliation !== "") {
                    $msg2 = true;
                    $ov->warning_at("author" . ($n + 1), false);
                }
            }
            $max_authors = $o->conf->opt("maxAuthors");
            if (!$ov->prow->author_list()) {
                $ov->error("Entry required.");
                $ov->error_at("author1", false);
            }
            if ($max_authors > 0
                && count($ov->prow->author_list()) > $max_authors) {
                $ov->error($o->conf->_("Each submission can have at most %d authors.", $max_authors));
            }
            if ($msg1) {
                $ov->warning("You may have entered an email address in the wrong place. The first author field is for email, the second for name, and the third for affiliation.");
            }
            if ($msg2) {
                $ov->warning("Please enter a name and optional email address for every author.");
            }
        }
        if ($o->id === PaperOption::PCCONFID
            && $o->conf->setting("sub_pcconf")
            && ($ov->prow->outcome <= 0 || !$user->can_view_decision($ov->prow))) {
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
                $ov->warning($o->conf->_("You may have missed conflicts of interest with %s. Please verify that all conflicts are correctly marked.", commajoin($pcs, "and")) . $o->conf->_(" Hover over “possible conflict” labels for more information."));
            }
        }
    }
    static function parse_web($o, PaperInfo $prow, Qrequest $qreq) {
        // XXX
        $v = "";
        return PaperValue::make($prow, $o, 1, $v);
    }
    static function echo_web_edit($o, PaperTable $pt, $ov, $reqov) {
        if ($o->id === PaperOption::AUTHORSID) {
            $pt->echo_editable_authors($o);
        } else if ($o->id === PaperOption::ANONYMITYID) {
            $pt->echo_editable_anonymity($o);
        } else if ($o->id === PaperOption::CONTACTSID) {
            $pt->echo_editable_contact_author($o);
        } else if ($o->id === PaperOption::TOPICSID) {
            $pt->echo_editable_topics($o);
        } else if ($o->id === PaperOption::PCCONFID) {
            $pt->echo_editable_pc_conflicts($o);
        }
    }
}
