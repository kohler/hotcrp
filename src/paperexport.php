<?php
// paperexport.php -- HotCRP helper for reading/storing papers as JSON
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class PaperExport {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @deprecated
     * @readonly */
    public $user;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var bool
     * @readonly */
    public $use_ids = false;
    /** @var bool
     * @readonly */
    public $include_docids = false;
    /** @var bool
     * @readonly */
    public $include_content = false;
    /** @var bool
     * @readonly */
    public $include_permissions = true;
    /** @var bool
     * @readonly */
    public $include_ratings = true;
    /** @var bool
     * @readonly */
    public $override_ratings = false;
    /** @var list<callable> */
    private $_on_document_export = [];

    /** @var ?CheckFormat */
    private $_cf;
    /** @var ?ReviewForm */
    private $_rf;

    function __construct(Contact $viewer) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        /** @phan-suppress-next-line PhanDeprecatedProperty */
        $this->user = $viewer;
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

    /** @param bool $x
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_include_permissions($x) {
        $this->include_permissions = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_include_ratings($x) {
        $this->include_ratings = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_override_ratings($x) {
        $this->override_ratings = $x;
        return $this;
    }

    /** @param callable(object,DocumentInfo,int,PaperStatus):(?bool) $cb
     * @return $this */
    function on_document_export($cb) {
        $this->_on_document_export[] = $cb;
        return $this;
    }


    /** @return ?object */
    function document_json(?DocumentInfo $doc) {
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

    /** @param int|PaperInfo $prow
     * @return ?object */
    function paper_json($prow) {
        if (is_int($prow)) {
            $prow = $this->conf->paper_by_id($prow, $this->viewer, ["topics" => true, "options" => true]);
        }
        if (!$prow || !$this->viewer->can_view_paper($prow)) {
            return null;
        }

        $pj = (object) [
            "object" => "paper",
            "pid" => (int) $prow->paperId
        ];
        if (($sr = $prow->submission_round()) && !$sr->unnamed) {
            $pj->submission_class = $sr->tag;
        }

        foreach ($prow->form_fields() as $opt) {
            if ($this->viewer->can_view_option($prow, $opt)) {
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
        $dec = $prow->viewable_decision($this->viewer);
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

        if (($tlist = $prow->sorted_viewable_tags($this->viewer))) {
            $pj->tags = [];
            foreach (Tagger::split_unpack($tlist) as $tv) {
                $pj->tags[] = (object) ["tag" => $tv[0], "value" => $tv[1]];
            }
        }

        return $pj;
    }

    /** @return ?object */
    function review_json(PaperInfo $prow, ReviewInfo $rrow) {
        ReviewForm::check_review_author_seen($prow, $rrow, $this->viewer);
        $my_review = $this->viewer->is_my_review($rrow);

        $rj = [
            "object" => "review",
            "pid" => $prow->paperId,
            "rid" => $rrow->reviewId
        ];
        if ($rrow->reviewTime) {
            $rj["version"] = $rrow->reviewTime;
        }
        if ($rrow->reviewOrdinal) {
            $rj["ordinal"] = unparse_latin_ordinal($rrow->reviewOrdinal);
        }
        if ($this->viewer->can_view_review_meta($prow, $rrow)) {
            $rj["rtype"] = $rrow->reviewType;
            if (($round = $this->conf->round_name($rrow->reviewRound))) {
                $rj["round"] = $round;
            }
        }
        $rj["status"] = ReviewInfo::$status_names[$rrow->reviewStatus];
        if (!$rrow->reviewOrdinal && $rrow->reviewStatus < ReviewInfo::RS_DELIVERED) {
            $rj["draft"] = true;
        }
        if ($rrow->is_ghost()) {
            $rj["ghost"] = true;
        }
        if ($rrow->is_subreview()) {
            $rj["subreview"] = true;
        }
        if ($rrow->reviewBlind) {
            $rj["blind"] = true;
        }

        // identity
        if ($this->viewer->can_view_review_identity($prow, $rrow)) {
            $reviewer = $rrow->reviewer();
            $rj["reviewer"] = $this->viewer->reviewer_html_for($rrow);
            if (!Contact::is_anonymous_email($reviewer->email)) {
                $rj["reviewer_email"] = $reviewer->email;
            }
        }

        // permissions
        if ($this->include_permissions) {
            if ($this->viewer->can_edit_review($prow, $rrow)) {
                $rj["editable"] = true;
            }
            if ($this->viewer->active_review_token_for($prow, $rrow)) {
                $rj["review_token"] = encode_token((int) $rrow->reviewToken);
            }
            if ($my_review) {
                $rj["my_review"] = true;
            }
            if ($this->viewer->contactId === $rrow->requestedBy) {
                $rj["my_request"] = true;
            }
        }

        // time
        list($time, $obscured) = $rrow->mtime_info($this->viewer);
        if ($time > 0) {
            $rj["modified_at"] = $time;
            $rj["modified_at_text"] = $obscured ? $this->conf->unparse_time_obscure($time) : $this->conf->unparse_time_point($time);
        }

        // messages
        if ($rrow->message_list) {
            $rj["message_list"] = $rrow->message_list;
        }

        // ratings
        if ($this->include_ratings) {
            $this->_review_ratings_json($prow, $rrow, $rj);
        }

        // review text
        // (field UIDs always are uppercase so can't conflict)
        $bound = $this->viewer->view_score_bound($prow, $rrow);
        if (!$this->include_permissions) {
            $bound = max($bound, VIEWSCORE_REVIEWERONLY);
        }
        $hidden = [];
        $rf = $this->_rf ?? $this->conf->review_form();
        foreach ($rf->all_fields() as $fid => $f) {
            if ($f->view_score <= $bound) {
                continue;
            }
            $fval = $rrow->fields[$f->order];
            if ($f->test_exists($rrow)) {
                $rj[$f->uid()] = $f->unparse_json($fval);
            } else if ($fval !== null
                       && $this->include_permissions
                       && ($my_review || $this->viewer->can_administer($prow))) {
                $hidden[] = $f->uid();
            }
        }
        if (!empty($hidden)) {
            $rj["hidden_fields"] = $hidden;
        }

        if (($fmt = $this->conf->default_format)) {
            $rj["format"] = $fmt;
        }

        return (object) $rj;
    }

    /** @param PaperInfo $prow
     * @param ReviewInfo $rrow */
    private function _review_ratings_json($prow, $rrow, &$rj) {
        if ($rrow->has_ratings()
            && $this->viewer->can_view_review_ratings($prow, $rrow, $this->override_ratings)) {
            $rj["ratings"] = ReviewInfo::unparse_rating_json(...$rrow->ratings());
        }
        if ($this->include_permissions
            && $this->viewer->can_rate_review($prow, $rrow)) {
            $rj["user_rating"] = ReviewInfo::unparse_rating_json($rrow->rating_by_rater($this->viewer));
        }
    }

    /** @return object */
    function review_history_json(PaperInfo $prow, ReviewInfo $rrow, ReviewHistoryInfo $rhrow) {
        $rj = [
            "object" => "review_delta",
            "pid" => $prow->paperId,
            "rid" => $rrow->reviewId,
            "version" => $rhrow->reviewTime
        ];
        if ($this->viewer->can_view_review_meta($prow, $rrow)) {
            if ($rrow->reviewType !== $rhrow->reviewType) {
                $rj["rtype"] = $rhrow->reviewType;
            }
            if ($rrow->reviewRound !== $rhrow->reviewRound) {
                $rj["round"] = $this->conf->round_name($rhrow->reviewRound);
            }
        }
        $rstatus = ReviewInfo::rflags_review_status($rhrow->rflags);
        $rj["status"] = ReviewInfo::$status_names[$rstatus];
        // XXX modified_at
        // XXX modified_at_text

        if ($rhrow->revdelta === null
            || !is_array(($revdelta = json_decode($rhrow->revdelta, true)))) {
            return (object) $rj;
        }

        $bound = $this->viewer->view_score_bound($prow, $rrow);
        if (!$this->include_permissions) {
            $bound = max($bound, VIEWSCORE_REVIEWERONLY);
        }
        $rf = $this->_rf ?? $this->conf->review_form();
        foreach ($rf->all_fields() as $fid => $f) {
            if ($f->view_score <= $bound) {
                continue;
            }
            if (array_key_exists($f->short_id, $revdelta)) {
                $rj[$f->uid()] = $f->unparse_json($revdelta[$f->short_id]);
            } else if (array_key_exists("{$f->short_id}:p", $revdelta)) {
                $rj[$f->uid() . ":p"] = $revdelta["{$f->short_id}:p"];
            }
        }
        return (object) $rj;
    }
}
