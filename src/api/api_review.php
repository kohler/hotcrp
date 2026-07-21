<?php
// api_review.php -- HotCRP review API calls
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

/*preference for api/review

single review download
- p must be set
- r must be set

multiple review download
- p may be set
- q may be set
- one of p, q must be set
- rq may be set
- u may be set

single review upload
- p must be set
- r must be set (might be `new`)
- u may be set
- round may be set */

class Review_API_Status implements JsonSerializable {
    /** @var int */
    public $message_count;
    /** @var bool */
    public $valid;
    /** @var list<string> */
    public $change_list;
    /** @var bool */
    public $conflict;
    /** @var ?int */
    public $pid;
    /** @var ?string */
    public $rid;

    /** @param int $message_count
     * @param bool $valid
     * @param list<string> $change_list
     * @param bool $conflict
     * @param ?int $pid
     * @param ?string $rid */
    function __construct($message_count, $valid = false, $change_list = [],
                         $conflict = false, $pid = null, $rid = null) {
        $this->message_count = $message_count;
        $this->valid = $valid;
        $this->change_list = $change_list;
        $this->conflict = $conflict;
        $this->pid = $pid;
        $this->rid = $rid;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $u = ["valid" => $this->valid, "change_list" => $this->change_list];
        if ($this->conflict) {
            $u["conflict"] = $this->conflict;
        }
        if ($this->pid !== null) {
            $u["pid"] = $this->pid;
        }
        if ($this->rid !== null) {
            $u["rid"] = $this->rid;
        }
        return $u;
    }
}

class Review_API extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;

    // request-global configuration
    /** @var bool */
    private $dry_run = false;
    /** @var bool */
    private $notify = true;
    /** @var bool */
    private $single = false;
    /** @var ?int */
    private $if_vtag_match;
    /** @var ?int */
    private $if_unmodified_since;
    /** @var ?string */
    private $qreq_r;

    // per-item accumulators (mirror Comment_API/Paper_API)
    /** @var list<Review_API_Status> */
    private $status_list = [];
    /** @var list<?object> */
    private $reviews = [];
    /** @var int */
    private $nreviews = 0;

    // current-item working state (reset per item)
    /** @var PaperInfo */
    private $prow;
    /** @var int */
    private $status = 200;
    /** @var bool */
    private $stale = false;
    /** @var bool */
    private $ivalid = false;
    /** @var ?list<string> */
    private $change_list;


    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    /** Parse the `format` request parameter, which selects how a GET renders
     * its reviews: `json` (the default), `text` (reviews as they appear in a
     * review download), or `form` (offline review forms, which `POST /review`
     * accepts back). The multiple-review endpoint also offers `textzip` and
     * `formzip`, which return one file per review in a ZIP archive.
     * @param bool $multi
     * @return array{'json'|'text'|'form',bool} */
    static private function parse_get_format(Qrequest $qreq, $multi) {
        $enum = $multi ? "json|text|form|textzip|formzip" : "json|text|form";
        $fmt = ViewOptionType::parse_enum($qreq->format ?? "json", $enum, true);
        if ($fmt === null) {
            JsonResult::make_parameter_error("format")->complete();
        }
        if (str_ends_with($fmt, "zip")) {
            return [substr($fmt, 0, -3), true];
        }
        return [$fmt, false];
    }

    /** @return JsonResult|Downloader */
    static function run_get_one(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if (!isset($qreq->p)) {
            return JsonResult::make_missing_error("p");
        } else if (!isset($qreq->r)) {
            return JsonResult::make_missing_error("r");
        }
        $fr = $prow ? $user->perm_view_paper($prow) : $qreq->annex("paper_whynot");
        if (!$prow || $fr) {
            return Conf::paper_error_json_result($fr);
        }
        // the single endpoint has no ZIP formats, so the container is never set
        list($fmt, ) = self::parse_get_format($qreq, false);

        $rloc = $prow->parse_ordinal_id($qreq->r);
        if ($rloc === false || $rloc === 0) {
            return JsonResult::make_parameter_error("r");
        } else if ($rloc < 0) {
            $rrow = $prow->review_by_ordinal(-$rloc);
        } else {
            $rrow = $prow->review_by_id($rloc);
        }
        $fr = $user->perm_view_review($prow, $rrow);
        if (!$rrow || $fr) {
            $fr = $fr ?? $prow->failure_reason(["reviewNonexistent" => true]);
            return new JsonResult($fr->response_code(), [
                "ok" => false, "message_list" => $fr->message_list(null, 2)
            ]);
        }

        if ($fmt !== "json") {
            return self::run_get_download($user, $qreq, [$rrow], $fmt, false);
        }

        $rj = (new PaperExport($user))->review_json($prow, $rrow);
        assert(!!$rj);
        if (friendly_boolean($qreq->download)) {
            return APIHelpers::make_json_download($user->conf, $rj,
                "review" . $rrow->unparse_ordinal_id());
        }
        return new JsonResult(["ok" => true, "review" => $rj]);
    }

    /** @return JsonResult|Downloader */
    static function run_get_multi(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        $ml = [];
        if (!isset($qreq->q)) {
            if (!isset($qreq->p)) {
                JsonResult::make_missing_error("q")->complete();
            }
            $fr = $prow ? $user->perm_view_paper($prow) : $qreq->annex("paper_whynot");
            if (!$prow || $fr) {
                Conf::paper_error_json_result($fr)->complete();
            }
            $srch = null;
            $prows = PaperInfoSet::make_singleton($prow);
        } else {
            list($srch, $prows) = Paper_API::make_search($user, $qreq);
            $ml = $srch->message_list_with_default_field("q");
        }

        if (isset($qreq->rq) && isset($qreq->u)) {
            JsonResult::make_parameter_error("rq", "<0>Supply at most one of `rq` and `u`")->complete();
        }
        list($fmt, $zip) = self::parse_get_format($qreq, true);

        $rst = null;
        if (isset($qreq->u)) {
            if (($u = $user->conf->user_by_email($qreq->u))) {
                $rsm = new ReviewSearchMatcher;
                $rsm->add_contact($u->contactId);
                $rst = new Review_SearchTerm($user, $rsm);
            } else {
                $rst = new False_SearchTerm;
            }
        } else if (isset($qreq->rq)) {
            $query = [
                "q" => $qreq->rq,
                "reviewer" => $qreq->reviewer ?? null
            ];
            $srch = new PaperSearch($user, $query);
            array_push($ml, ...$srch->message_list_with_default_field("rq"));
            $rst = $srch->main_term();
        }

        $rrows = [];
        foreach ($prows as $xprow) {
            foreach ($xprow->viewable_reviews_as_display($user) as $rrow) {
                if (!$rst || $rst->test($xprow, $rrow))
                    $rrows[] = $rrow;
            }
        }

        if ($fmt !== "json") {
            return self::run_get_download($user, $qreq, $rrows, $fmt, $zip);
        }

        $pex = new PaperExport($user);
        $rjs = [];
        foreach ($rrows as $rrow) {
            $rjs[] = $pex->review_json($rrow->prow, $rrow);
        }
        if (friendly_boolean($qreq->download)) {
            return APIHelpers::make_json_download($user->conf, $rjs, "reviews");
        }
        return new JsonResult([
            "ok" => true,
            "message_list" => $ml,
            "reviews" => $rjs
        ]);
    }

    /** Render `$rrows` as a text download rather than JSON.
     *
     * `text` renders each review as it appears in a review download; `form`
     * renders each as an offline review form (which `POST /review` accepts
     * back). Without `$zip` the reviews are concatenated into one text file;
     * with it, each review becomes its own file, named for its ordinal ID, in
     * a ZIP archive. An empty selection yields an empty download, matching
     * `format=json`’s empty `reviews` array. These renderings are attachments
     * unless `download=0` asks for an inline response.
     * @param list<ReviewInfo> $rrows
     * @param 'text'|'form' $fmt
     * @param bool $zip
     * @return JsonResult|Downloader */
    static private function run_get_download(Contact $user, Qrequest $qreq, $rrows, $fmt, $zip) {
        $conf = $user->conf;
        $rf = $conf->review_form();
        $isform = $fmt === "form";
        $attachment = friendly_boolean($qreq->download) !== false;

        if ($zip) {
            $dset = new DocumentInfoSet($conf->download_prefix . "reviews.zip");
            foreach ($rrows as $rrow) {
                $t = $isform
                    ? $rf->text_form_header(false) . $rf->text_form($rrow->prow, $rrow, $user) . "\n"
                    : $rf->unparse_text($rrow->prow, $rrow, $user);
                $fn = $conf->download_prefix . "review" . $rrow->unparse_ordinal_id() . ".txt";
                if (($doc = $dset->add_string_as($t, $fn))
                    && ($time = $rrow->mtime($user)) > 0) {
                    $doc->set_timestamp($time);
                }
            }
            $dopt = (new Downloader)->parse_qreq($qreq);
            $dopt->set_attachment($attachment);
            if (!$dset->prepare_download($dopt)) {
                return JsonResult::make_message_list(400, $dset->message_list());
            }
            return $dopt;
        }

        $t = [];
        if ($isform) {
            $t[] = $rf->text_form_header(count($rrows) > 1);
        }
        foreach ($rrows as $i => $rrow) {
            if ($isform) {
                $t[] = $rf->text_form($rrow->prow, $rrow, $user) . "\n";
            } else {
                if ($i !== 0) {
                    $t[] = "\n\n\n" . str_repeat("* ", 37) . "*\n\n\n\n";
                }
                $t[] = $rf->unparse_text($rrow->prow, $rrow, $user);
            }
        }

        $base = count($rrows) === 1
            ? "review" . $rrows[0]->unparse_ordinal_id()
            : "reviews";
        return $conf->make_text_downloader($base)
            ->set_attachment($attachment)
            ->set_content(join("", $t));
    }


    /** `POST /review` (M_ONE) addresses one review; `POST /reviews` (M_MULTI) is
     * a cross-paper batch. The paper comes from the URL `p` or, for JSON/text,
     * from the body (these endpoints are not `paper:true`, so an absent `p` is
     * not rejected up front).
     * @param 1|2|4 $mode
     * @return JsonResult */
    private function run_post(Qrequest $qreq, ?PaperInfo $prow, $mode) {
        // an explicit but unresolvable `p` is a hard error
        if (!$prow && isset($qreq->p)) {
            return Conf::paper_error_json_result($qreq->annex("paper_whynot"));
        }
        $one = ($mode & DocumentLocator::M_ONE) !== 0;

        $this->set_post_param($qreq);
        $this->qreq_r = isset($qreq->r) ? (string) $qreq->r : null;

        // an `upload` token resolves to a document whose mimetype selects the
        // format; otherwise the request body's content type does. An explicit
        // `json` parameter (like `upload`) is an inline JSON payload obeyed
        // regardless of the body, so it skips the text/form body paths.
        $docloc = new DocumentLocator;
        $updoc = $docloc->uploaded_document($qreq);
        $bct = $updoc ? $updoc->mimetype : ($qreq->body_content_type() ?? Mimetype::FORM_DATA_TYPE);

        if (!isset($qreq->json)) {
            // a text/plain body — or a text `upload` token — is a raw offline
            // review form
            if ($bct === Mimetype::TXT_TYPE) {
                if ($one && !$prow) {
                    return JsonResult::make_missing_error("p");
                }
                $content = $updoc ? (string) $updoc->content() : (string) $qreq->body();
                return $this->run_post_text($qreq, $prow, $content,
                    $updoc ? $updoc->filename : null);
            }

            // form-encoded content is a single review on the URL's paper (or,
            // with a `file` upload, offline review text)
            if (!$updoc
                && Mimetype::is_form($bct)
                && !isset($qreq->upload)) {
                if (!$one) {
                    return JsonResult::make_error(400, "<0>Unexpected content type");
                }
                if (!$prow) {
                    return JsonResult::make_missing_error("p");
                }
                return $this->run_post_form_data($qreq, $prow);
            }
        }

        // a JSON object is one review; a JSON array is a batch (reviews have no
        // search-match mode)
        list($jp, $jmode) = $docloc->parse_json_request($qreq, $mode & ~DocumentLocator::M_MATCH);
        if ($jmode === DocumentLocator::M_ONE) {
            return $this->run_post_single_json($prow, $jp);
        }
        return $this->run_post_multi_json($jp);
    }

    /** `DELETE /review` removes the review on `$prow` selected by `r` (an empty
     * `r` means the caller's own review). Only administrators may delete reviews;
     * `dry_run`, `if_vtag_match`, and `if_unmodified_since` behave as for `POST`.
     * @return JsonResult */
    private function run_delete(Qrequest $qreq, ?PaperInfo $prow) {
        if (!$prow) {
            return Conf::paper_error_json_result($qreq->annex("paper_whynot"));
        }
        $this->set_post_param($qreq);
        $this->prow = $prow;
        $this->single = true;
        $this->reset_item();

        // only administrators may delete reviews
        if (!$this->user->can_manage_reviews($prow)) {
            return JsonResult::make_permission_error(null, "<0>Only administrators can delete reviews");
        }

        // resolve the target review; empty/`0` addresses the caller's own review
        list($ok, $rrow) = $this->post_target(isset($qreq->r) ? (string) $qreq->r : "");
        if ($ok && !$rrow) {
            $rrow = $prow->review_by_user($this->user);
            if (!$rrow) {
                $this->status = 404;
                $this->error_at("r", "<0>Review not found");
                $ok = false;
            }
        }
        if (!$ok) {
            $this->execute_fail();
            return $this->post_result();
        }

        // concurrency preconditions (as for POST)
        $vtag_conflict = $this->if_vtag_match !== null
            && $this->if_vtag_match !== $rrow->reviewTime;
        $ius_conflict = $this->if_unmodified_since !== null
            && $this->if_unmodified_since < $rrow->reviewModified;
        $conflict = $vtag_conflict || $ius_conflict;
        if ($conflict) {
            $this->error_at($vtag_conflict ? "if_vtag_match" : "if_unmodified_since",
                $this->conf->_("<5><strong>Edit conflict</strong>: The review was edited concurrently"));
            $valid = false;
        } else if ($this->dry_run) {
            $valid = true;
        } else {
            $valid = $rrow->delete($this->user, ["no_rights" => true, "snapshot" => true]);
        }

        $this->status_list[] = new Review_API_Status($this->message_count(),
            $valid, ["delete"], $conflict, $prow->paperId, $rrow->unparse_ordinal_id());
        $this->reviews[] = null;
        return $this->post_result();
    }

    private function set_post_param(Qrequest $qreq) {
        $this->dry_run = friendly_boolean($qreq->dry_run) ?? false;
        $this->notify = friendly_boolean($qreq->notify) !== false;
        // batch-wide (and JSON single) concurrency preconditions; a review
        // object’s own `if_vtag_match`/`if_unmodified_since` overrides these per
        // item, as does a `new` locator (which forces `if_vtag_match=0`)
        if (isset($qreq->if_vtag_match)) {
            if (($v = stoi($qreq->if_vtag_match)) === null) {
                JsonResult::make_parameter_error("if_vtag_match")->complete();
            }
            $this->if_vtag_match = $v;
        }
        if (isset($qreq->if_unmodified_since)) {
            if (($t = Paper_API::parse_if_unmodified_since($qreq->if_unmodified_since, $this->conf)) === false) {
                JsonResult::make_parameter_error("if_unmodified_since")->complete();
            }
            $this->if_unmodified_since = $t;
        }
    }

    /** @return ReviewValues */
    private function make_rv() {
        return new ReviewValues($this->conf);
    }

    /** Save one review from a plain form POST on `$prow`, or — when the form
     * carries an uploaded review-form file — from that offline text.
     * @return JsonResult */
    private function run_post_form_data(Qrequest $qreq, PaperInfo $prow) {
        $this->prow = $prow;
        if ($qreq->has_file("file")) {
            return $this->run_post_text($qreq, $prow,
                $qreq->file_content("file"), $qreq->file_filename("file"));
        }
        $this->single = true;
        $this->reset_item();
        $rv = $this->make_rv();
        $rv->parse_qreq($qreq);
        $this->save_item($rv, $this->qreq_r);
        return $this->post_result();
    }

    /** Save reviews from an uploaded offline review form. `parse_text` may yield
     * several reviews (one per `==+== Paper` section). With a `$prow` (single
     * endpoint) only its reviews are saved and the rest are ignored; with no
     * `$prow` (batch endpoint) every review is saved on the paper it names. The
     * reviewer is taken from each form, so `prepare_save`'s permission checks
     * still gate who may be edited.
     * @param ?PaperInfo $prow
     * @param string $content
     * @param ?string $filename
     * @return JsonResult */
    private function run_post_text(Qrequest $qreq, ?PaperInfo $prow, $content, $filename) {
        $this->prow = $prow;
        $rv = (new ReviewValues($this->conf))->set_text(convert_to_utf8($content), $filename);
        $override = friendly_boolean($qreq->override) ?? false;
        $nmatch = $nother = 0;
        while ($rv->set_req_override($override)->parse_text()) {
            if ($prow === null || $rv->req_pid() === $prow->paperId) {
                ++$nmatch;
                $this->reset_item();
                $this->apply_precondition($rv, null);
                $this->execute_save($rv, null);
            } else {
                // ignore reviews for other submissions, dropping their messages
                ++$nother;
                $rv->clear_messages();
            }
            $rv->clear_req();
        }
        // merge any trailing parse messages (e.g. a garbage warning)
        foreach ($rv->message_list() as $mi) {
            $this->append_item($mi);
        }
        // the single endpoint collapses to one item; the batch endpoint uses the
        // list shape
        $this->single = $prow !== null && $nmatch <= 1;
        if ($nmatch === 0) {
            // record a failure item so the result reports `ok:false`
            if ($prow !== null && $nother > 0) {
                $this->error_at(null, $this->conf->_("<0>Uploaded form was not for this {submission}"));
            } else {
                $this->error_at(null, "<0>Uploaded file had no valid review forms");
            }
            $this->execute_fail();
        } else if ($prow !== null && $nother > 0) {
            $this->warning_at(null, $this->conf->_("<0>Reviews for other {submissions} ignored"));
        }
        return $this->post_result();
    }

    /** Save a batch of review objects, best-effort per item; each item names its
     * own paper (`pid`) and review (`rid`), resolved by `prepare_save`.
     * @param list<mixed> $jps
     * @return JsonResult */
    private function run_post_multi_json($jps) {
        $this->single = false;
        foreach ($jps as $jp) {
            $this->prow = null;
            $this->reset_item();
            if (!is_object($jp)) {
                $this->error_at(null, "<0>Expected object");
                $this->execute_fail();
                continue;
            }
            $rv = $this->make_rv();
            $rv->parse_json($jp);
            $this->apply_precondition($rv, $jp->rid ?? null);
            $this->execute_save($rv, null);
        }
        return $this->post_result();
    }

    /** Save one review from a single JSON object. The object may carry its own
     * `pid`/`rid`; `parse_json` feeds them into the request, and `prepare_save`
     * resolves the paper (from the URL `$prow` or that `pid`) and the review
     * (reconciling a body `rid` against the review the URL's `r` selects,
     * reporting a mismatch).
     * @param ?PaperInfo $prow
     * @param object $jp
     * @return JsonResult */
    private function run_post_single_json(?PaperInfo $prow, $jp) {
        $this->prow = $prow;
        $this->single = true;
        $this->reset_item();
        if (!is_object($jp)) {
            $this->error_at(null, "<0>Expected object");
            $this->execute_fail();
            return $this->post_result();
        }
        $rv = $this->make_rv();
        $rv->parse_json($jp);
        // the URL `r` selects the target review; a body `rid` (now in the
        // request) is reconciled against it by prepare_save
        list($ok, $rrow) = $this->post_target($this->qreq_r);
        if (!$ok) {
            $this->execute_fail();
        } else {
            $this->apply_precondition($rv, $jp->rid ?? $this->qreq_r);
            $this->execute_save($rv, $rrow);
        }
        return $this->post_result();
    }


    /** Reset the current-item working state before processing an item. */
    private function reset_item() {
        $this->status = 200;
        $this->stale = false;
        $this->ivalid = false;
        $this->change_list = null;
    }

    /** Resolve the item's target review from `$r` (empty/`0` means the acting
     * reviewer's review, creating it if needed; `new` additionally requires that
     * no such review exists yet; otherwise a review ordinal or ID on
     * `$this->prow`), then save it.
     * @param ReviewValues $rv
     * @param ?string $r */
    private function save_item($rv, $r) {
        list($ok, $rrow) = $this->post_target($r);
        if (!$ok) {
            $this->execute_fail();
            return;
        }
        $this->apply_precondition($rv, $r);
        $this->execute_save($rv, $rrow);
    }

    /** Resolve the item's concurrency precondition. The request's own
     * `if_vtag_match`/`if_unmodified_since` win; otherwise a `new` locator forces
     * `if_vtag_match=0`; otherwise the batch-wide default (from the request's
     * `if_vtag_match`/`if_unmodified_since` parameters), if any, applies.
     * @param ReviewValues $rv
     * @param mixed $r */
    private function apply_precondition($rv, $r) {
        $this->apply_require_new($rv, $r);
        // apply the batch-wide default only if the item set no precondition of
        // its own (and `new` did not force `if_vtag_match=0` above)
        if (!isset($rv->req["if_vtag_match"])
            && !isset($rv->req["if_unmodified_since"])) {
            if ($this->if_vtag_match !== null) {
                $rv->req["if_vtag_match"] = $this->if_vtag_match;
            }
            if ($this->if_unmodified_since !== null) {
                $rv->req["if_unmodified_since"] = $this->if_unmodified_since;
            }
        }
    }

    /** A locator of `new` requires that the review not already exist. Enforce it
     * through the version-tag precondition: a saved review always has a nonzero
     * reviewTime, so `if_vtag_match=0` conflicts iff one already exists. An
     * explicit precondition on the request wins.
     * @param ReviewValues $rv
     * @param mixed $r */
    private function apply_require_new($rv, $r) {
        if ((string) $r === "new"
            && !isset($rv->req["if_vtag_match"])
            && !isset($rv->req["if_unmodified_since"])) {
            $rv->req["if_vtag_match"] = 0;
        }
    }

    /** Resolve `$r` into the review to save on `$this->prow`.
     * @param ?string $r
     * @return array{bool,?ReviewInfo} */
    private function post_target($r) {
        $r = (string) $r;
        if ($r === "" || $r === "new" || $r === "0") {
            // the acting reviewer's review (ReviewValues finds or creates it)
            return [true, null];
        }
        if (!$this->prow) {
            // a specific review can only be addressed with a URL paper
            $this->error_at("r", "<0>{Submission} required");
            return [false, null];
        }
        $rloc = $this->prow->parse_ordinal_id($r);
        if ($rloc === false || $rloc === 0) {
            $this->status = 404;
            $this->error_at("r", "<0>Review not found");
            return [false, null];
        }
        $rrow = $rloc < 0
            ? $this->prow->review_by_ordinal(-$rloc)
            : $this->prow->review_by_id($rloc);
        if (!$rrow) {
            $this->status = 404;
            $this->error_at("r", "<0>Review not found");
            return [false, null];
        }
        // an unviewable review the caller may not know exists reads as
        // `reviewNonexistent` (404), so this never leaks its existence
        if (($fr = $this->user->perm_view_review($this->prow, $rrow))) {
            $this->status = $fr->response_code();
            $fr->append_to($this, "r", MessageSet::ERROR);
            return [false, null];
        }
        return [true, $rrow];
    }

    /** Save the review described by `$rv` onto `$rrow` (an existing review, or
     * null to find/create the reviewer's review), then snapshot the per-item
     * outcome into the accumulators. Mirrors `Comment_API::execute_save`.
     * @param ReviewValues $rv
     * @param ?ReviewInfo $rrow */
    private function execute_save($rv, $rrow) {
        // a notify suppression request is honored only for an administrator of
        // the paper (unknown until prepare_save when the paper came from `pid`)
        $manager = $this->prow && $this->user->can_manage_reviews($this->prow);
        $rv->set_notify($this->notify || !$manager);

        // stage the request; a conflict or validation error leaves it unprepared.
        // A conflict still stages the attempted change, so read change_list
        // unconditionally (it is empty when nothing was staged). prepare_save
        // resolves the paper (from `$this->prow` or the request's `paperId`) and
        // reports a missing/mismatched/forbidden paper.
        $prepared = $rv->prepare_save($this->user, $this->prow, $rrow);
        $this->stale = $rv->has_problem_at("if_vtag_match")
            || $rv->has_problem_at("if_unmodified_since");
        $this->change_list = $rv->change_list(true);

        if ($prepared && !$this->dry_run) {
            // commit
            $this->ivalid = $rv->execute_save();
        } else {
            // dry run, conflict, or validation failure: revert without saving
            $rv->abort_save();
            $this->ivalid = $prepared && $this->dry_run;
        }

        // merge the engine's messages into the response, then clear them so a
        // shared parser (multi-review text upload) starts each review fresh
        foreach ($rv->message_list() as $mi) {
            $this->append_item($mi);
        }
        $rv->clear_messages();

        $prow = $this->prow ?? $rv->saved_prow();
        $rid = null;
        if ($this->ivalid && !$this->dry_run && $rv->reviewId && $prow) {
            $rid = $rv->review_ordinal_id;
            $rrow2 = $prow->fresh_review_by_id($rv->reviewId);
            $this->reviews[] = (new PaperExport($this->user))->review_json($prow, $rrow2);
            ++$this->nreviews;
        } else {
            $this->reviews[] = null;
        }
        $this->status_list[] = new Review_API_Status(
            $this->message_count(),
            $this->ivalid, $this->change_list ?? [],
            $this->stale, $prow ? $prow->paperId : null, $rid
        );
    }

    /** Record a per-item resolution failure (no save attempted). */
    private function execute_fail() {
        $this->status_list[] = new Review_API_Status($this->message_count());
        $this->reviews[] = null;
    }

    /** Assemble the response; in single, most Review_API_Status fields are raised
     * to the top level (along with `ok` and `message_list`).
     * @return JsonResult */
    private function post_result() {
        // apply landmarks to messages
        $sli = 0;
        foreach ($this->message_list() as $i => $mi) {
            while ($sli < count($this->status_list)
                   && $this->status_list[$sli]->message_count <= $i) {
                ++$sli;
            }
            if ($sli < count($this->status_list) && $mi->landmark === null) {
                $mi->landmark = $sli;
            }
        }
        // generate result
        $ok = empty($this->status_list)
            || array_find($this->status_list, function ($x) { return !!$x->valid; });
        $status = $this->single ? $this->status : 200;
        if ($this->single && $status === 200 && $this->has_error()) {
            $status = 400;
        }
        $jr = new JsonResult($status, [
            "ok" => $ok,
            "message_list" => $this->message_list()
        ]);
        if ($this->dry_run) {
            $jr->content["dry_run"] = true;
        }
        if ($this->single) {
            // omit `pid`: the URL pins the paper
            foreach ($this->status_list[0]->jsonSerialize() as $k => $v) {
                if ($k !== "pid") {
                    $jr->content[$k] = $v;
                }
            }
            if ($this->nreviews > 0) {
                $jr->content["review"] = $this->reviews[0];
            }
        } else {
            $jr->content["status_list"] = $this->status_list;
            if (!$this->dry_run) {
                $jr->content["reviews"] = $this->reviews;
            }
        }
        return $jr;
    }


    /** @return JsonResult|Downloader */
    static private function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow, $mode) {
        $old_overrides = $user->overrides();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        try {
            if ($qreq->is_getlike()) {
                if ($mode === DocumentLocator::M_ONE) {
                    $jr = self::run_get_one($user, $qreq, $prow);
                } else {
                    $jr = self::run_get_multi($user, $qreq, $prow);
                }
            } else if ($qreq->method() === "DELETE") {
                $jr = (new Review_API($user))->run_delete($qreq, $prow);
            } else {
                $jr = (new Review_API($user))->run_post($qreq, $prow, $mode);
            }
        } catch (JsonCompletion $jc) {
            $jr = $jc->result;
        }
        $user->set_overrides($old_overrides);
        if ($jr instanceof JsonResult
            && ($jr->content["message_list"] ?? null) === []) {
            unset($jr->content["message_list"]);
        }
        return $jr;
    }

    /** @return JsonResult|Downloader */
    static function run_one(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        return self::run($user, $qreq, $prow, DocumentLocator::M_ONE);
    }

    /** @return JsonResult|Downloader */
    static function run_multi(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        return self::run($user, $qreq, $prow, DocumentLocator::M_MULTI);
    }


    /** @deprecated */
    static function reviewhistory(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        return ReviewMeta_API::reviewhistory($user, $qreq, $prow);
    }

    /** @deprecated */
    static function reviewrating(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        return ReviewMeta_API::reviewrating($user, $qreq, $prow);
    }

    /** @deprecated */
    static function reviewround(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        return ReviewMeta_API::reviewround($user, $qreq, $prow);
    }
}
