<?php
// paperexport.php -- HotCRP helper for reading/storing papers as JSON
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class PaperExport {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var bool
     * @readonly */
    public $use_ids = false;
    /** @var bool
     * @readonly */
    public $include_docids = false;
    /** @var bool
     * @readonly */
    public $include_content = false;
    /** @var list<callable> */
    private $_on_document_export = [];
    /** @var ?CheckFormat */
    private $_cf;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    /** @param bool $x
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_use_ids($x) {
        $this->use_ids = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_include_docids($x) {
        $this->include_docids = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_include_content($x) {
        $this->include_content = $x;
        return $this;
    }

    /** @param callable(object,DocumentInfo,int,PaperStatus):(?bool) $cb
     * @return $this */
    function on_document_export($cb) {
        $this->_on_document_export[] = $cb;
        return $this;
    }


    /** @return ?object */
    function document_json(DocumentInfo $doc = null) {
        if (!$doc) {
            return null;
        }

        $d = (object) [];
        if ($doc->paperStorageId > 0 && $this->include_docids) {
            $d->docid = $doc->paperStorageId;
        }
        if ($doc->mimetype) {
            $d->mimetype = $doc->mimetype;
        }
        if ($doc->has_hash()) {
            $d->hash = $doc->text_hash();
        }
        if ($doc->timestamp) {
            $d->timestamp = $doc->timestamp;
        }
        if (($sz = $doc->size()) >= 0) {
            $d->size = $sz;
        }
        if ($doc->filename) {
            $d->filename = $doc->filename;
        }
        if ($this->include_content
            && ($content = $doc->content()) !== false) {
            $d->content_base64 = base64_encode($content);
        }
        if ($doc->mimetype === "application/pdf") {
            $this->decorate_pdf_document_json($d, $doc);
        }
        foreach ($this->_on_document_export as $cb) {
            if (call_user_func($cb, $d, $doc, $doc->documentType, $this) === false)
                return null;
        }
        return count(get_object_vars($d)) ? $d : null;
    }

    /** @param object $d */
    private function decorate_pdf_document_json($d, DocumentInfo $doc) {
        if (($spec = $this->conf->format_spec($doc->documentType))
            && !$spec->is_empty()) {
            $this->_cf = $this->_cf ?? new CheckFormat($this->conf, CheckFormat::RUN_NEVER);
            if ($this->_cf->check_document($doc)) {
                if ($this->_cf->npages !== null) {
                    $d->pages = $this->_cf->npages;
                }
                if ($this->_cf->has_problem()) {
                    $d->format_status = $this->_cf->has_error() ? "error" : "warning";
                    $d->format = join(" ", $this->_cf->problem_fields());
                } else {
                    $d->format_status = "ok";
                }
            }
        } else if (($np = $doc->npages()) !== null) {
            $d->pages = $np;
        }
    }

    /** @param int|PaperInfo $prow */
    function paper_json($prow) {
        if (is_int($prow)) {
            $prow = $this->conf->paper_by_id($prow, $this->user, ["topics" => true, "options" => true]);
        }
        if (!$prow || !$this->user->can_view_paper($prow)) {
            return null;
        }

        $pj = (object) [];
        $pj->pid = (int) $prow->paperId;
        if (($sr = $prow->submission_round()) && !$sr->unnamed) {
            $pj->submission_class = $sr->tag;
        }

        foreach ($prow->form_fields() as $opt) {
            if ($this->user->can_view_option($prow, $opt)) {
                $ov = $prow->force_option($opt);
                $oj = $opt->value_export_json($ov, $this);
                if ($oj !== null) {
                    if ($this->use_ids) {
                        $pj->{$opt->field_key()} = $oj;
                    } else {
                        $pj->{$opt->json_key()} = $oj;
                    }
                }
            }
        }

        $submitted_status = "submitted";
        $dec = $prow->viewable_decision($this->user);
        if ($dec->id !== 0) {
            $pj->decision = $dec->name;
            if (($dec->catbits & DecisionInfo::CAT_YES) !== 0) {
                $submitted_status = "accept";
            } else if ($dec->catbits === DecisionInfo::CB_DESKREJECT) {
                $submitted_status = "desk_reject";
            } else if (($dec->catbits & DecisionInfo::CAT_NO) !== 0) {
                $submitted_status = "reject";
            }
        }

        if ($prow->timeWithdrawn > 0) {
            $pj->status = "withdrawn";
            $pj->withdrawn = true;
            $pj->withdrawn_at = (int) $prow->timeWithdrawn;
            if ($prow->withdrawReason) {
                $pj->withdraw_reason = $prow->withdrawReason;
            }
        } else if ($prow->timeSubmitted > 0) {
            $pj->status = $submitted_status;
            $pj->submitted = true;
        } else {
            $pj->status = "draft";
            $pj->draft = true;
        }
        if (($t = $prow->submitted_at())) {
            $pj->submitted_at = $t;
        }

        if ($prow->timeFinalSubmitted > 0) {
            $pj->final_submitted = true;
            $pj->final_submitted_at = $prow->timeFinalSubmitted;
        }

        return $pj;
    }
}
