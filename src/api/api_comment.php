<?php
// api_comment.php -- HotCRP comment API call
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class Comment_API_Status implements JsonSerializable {
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
    /** @var ?int */
    public $cid;

    /** @param int $message_count
     * @param bool $valid
     * @param list<string> $change_list
     * @param bool $conflict
     * @param ?int $pid
     * @param ?int $cid */
    function __construct($message_count, $valid = false, $change_list = [],
                         $conflict = false, $pid = null, $cid = null) {
        $this->message_count = $message_count;
        $this->valid = $valid;
        $this->change_list = $change_list;
        $this->conflict = $conflict;
        $this->pid = $pid;
        $this->cid = $cid;
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
        if ($this->cid !== null) {
            $u["cid"] = $this->cid;
        }
        return $u;
    }
}

class Comment_API extends MessageSet {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var ?int */
    private $qreq_pid;
    /** @var ?int */
    private $qreq_cid;
    /** @var ?ResponseRound */
    private $qreq_rrd;
    /** @var ?string */
    private $qreq_review_token;

    // request-global configuration
    /** @var bool */
    private $dry_run = false;
    /** @var bool */
    private $notify = true;
    /** @var ?int */
    private $if_unmodified_since;
    /** @var bool */
    private $single = false;
    /** @var DocumentLocator */
    private $docloc;

    // per-item accumulators (mirror Paper_API)
    /** @var list<Comment_API_Status> */
    private $status_list = [];
    /** @var list<?object> */
    private $comments = [];
    /** @var int */
    private $ncomments = 0;

    // current-item working state (reset per item)
    /** @var PaperInfo */
    private $prow;
    /** @var ?ResponseRound */
    private $rrd;
    /** @var int */
    private $item_message_count;
    /** @var int */
    private $status = 200;
    /** @var bool */
    private $stale = false;
    /** @var bool */
    private $ivalid = false;
    /** @var ?list<string> */
    private $change_list;
    /** @var string */
    private $uccmttype;
    /** @var string */
    private $lccmttype;


    function __construct(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        $this->conf = $user->conf;
        $this->user = $user;
        // parse Qrequest locators -- p, c, response -- throwing if malformed
        if ($prow) {
            $this->qreq_pid = $prow->paperId;
        } else if (isset($qreq->p)) {
            Conf::paper_error_json_result($qreq->annex("paper_whynot"))->complete();
        }
        $c = (string) $qreq->c;
        $response = (string) $qreq->response;
        if ($c === "") {
            // nothing
        } else if ($c === "new" || $c === "0") {
            $this->qreq_cid = 0;
        } else if (ctype_digit($c) && !str_starts_with($c, "0")) {
            $this->qreq_cid = stoi($c);
            if ($this->qreq_cid === null) {
                JsonResult::make_not_found_error("c", "<0>Comment not found")->complete();
            }
        } else if ($c === "response") {
            $response = "1";
        } else if (str_ends_with($c, "response")) {
            $response = substr($c, 0, -8);
        } else if (str_starts_with($c, "response")) {
            $response = substr($c, 8);
        } else {
            JsonResult::make_not_found_error("c", "<0>Comment not found")->complete();
        }
        if ($response !== "") {
            if ((string) $qreq->response !== ""
                && strcasecmp($response, $qreq->response) !== 0) {
                JsonResult::make_parameter_error("response", "<0>Parameter conflict with `c`")->complete();
            }
            $this->qreq_rrd = $this->conf->response_round($response);
            if (!$this->qreq_rrd) {
                JsonResult::make_not_found_error("response", "<0>Response not found")->complete();
            }
        }
        $this->qreq_review_token = $qreq->review_token;
    }

    private function run_get_one(Qrequest $qreq, PaperInfo $prow) {
        // a GET with no `c`/`response` behaves like `GET /comments` (backward compat)
        if ($this->qreq_cid === null && $this->qreq_rrd === null) {
            return $this->run_get_multi($qreq, $prow);
        }
        // find comment; a `cid`/`response` mismatch reads as “not found” for GET
        $this->prow = $prow;
        $crow = $this->locate_target($this->qreq_cid, $this->qreq_rrd);
        if (!$crow
            || $this->stale
            || !$this->user->can_view_comment($prow, $crow, true)) {
            if ($this->qreq_rrd) {
                $k = isset($qreq->response) ? "response" : "c";
                if ($this->stale
                    && $this->user->can_view_submitted_review($prow)) {
                    return JsonResult::make_not_found_error($k, "<0>The response has changed");
                } else if ($crow && $this->user->can_manage($prow)) {
                    return JsonResult::make_permission_error($k, "<0>You aren’t allowed to view that response");
                }
                return JsonResult::make_not_found_error($k, "<0>Response not found");
            } else if ($crow && $this->user->can_manage($prow)) {
                return JsonResult::make_permission_error("c", "<0>You aren’t allowed to view that comment");
            }
            return JsonResult::make_not_found_error("c", "<0>Comment not found");
        }
        // export and return
        $no_content = friendly_boolean($qreq->content) === false;
        return new JsonResult(["ok" => true, "comment" => $crow->unparse_json($this->user, $no_content)]);
    }

    /** @return JsonResult */
    private function run_get_multi(Qrequest $qreq, ?PaperInfo $prow) {
        $no_content = friendly_boolean($qreq->content) === false;
        if (isset($qreq->q)) {
            if ($prow) {
                return JsonResult::make_parameter_error("p", "<0>Parameter conflict with `q`");
            }
            list($srch, $prows) = Paper_API::make_search($this->user, $qreq);
            $ml = $srch->message_list_with_default_field("q");
        } else if ($prow) {
            $prows = PaperInfoSet::make_singleton($prow);
            $ml = [];
        } else {
            return JsonResult::make_missing_error("q");
        }
        $comments = [];
        foreach ($prows as $prow) {
            foreach ($prow->viewable_comments($this->user) as $crow) {
                if (!$this->qreq_rrd
                    || ($crow->is_response() && $crow->commentRound === $this->qreq_rrd->id)) {
                    $comments[] = $crow->unparse_json($this->user, $no_content);
                }
            }
        }
        return new JsonResult([
            "ok" => true,
            "message_list" => $ml,
            "comments" => $comments
        ]);
    }


    /** @param 1|2 $mode
     * @return JsonResult */
    private function run_post(Qrequest $qreq, ?PaperInfo $prow, $mode) {
        // set parameters
        $this->set_post_param($qreq);
        $this->docloc = new DocumentLocator;

        // check Content-Type
        if (Mimetype::is_form($qreq->body_content_type() ?? Mimetype::FORM_DATA_TYPE)
            && !isset($qreq->json)
            && !isset($qreq->upload)) {
            // handle form-encoded data
            if (($mode & DocumentLocator::M_ONE) === 0) {
                return JsonResult::make_error(400, "<0>Unexpected content type");
            }
            return $this->run_post_form_data($qreq, $prow);
        }

        // extract uploaded JSON
        list($jp, $mode) = $this->docloc->parse_json_request($qreq, $mode);
        if ($mode === DocumentLocator::M_ONE) {
            return $this->run_post_single_json($prow, $jp);
        }
        return $this->run_post_multi_json($jp);
    }

    private function set_post_param(Qrequest $qreq) {
        $this->dry_run = friendly_boolean($qreq->dry_run) ?? false;
        $this->notify = friendly_boolean($qreq->notify) !== false;
        if (isset($qreq->if_unmodified_since)) {
            $t = Paper_API::parse_if_unmodified_since($qreq->if_unmodified_since, $this->conf);
            if ($t === false) {
                JsonResult::make_parameter_error("if_unmodified_since")->complete();
            }
            $this->if_unmodified_since = $t;
        }
    }

    /** `DELETE /{p}/comment` removes the comment on `$prow` selected by `c`,
     * reusing the save path with a forced `delete`. The `c` parameter must name
     * an existing comment. @return JsonResult */
    private function run_delete(Qrequest $qreq, PaperInfo $prow) {
        $this->prow = $prow;
        $this->single = true;
        $this->set_post_param($qreq);
        $this->reset_item();
        $crow = $this->post_target(null);
        if ($crow && $crow->commentId <= 0) {
            // `c` must name an existing comment, not create a new one
            $this->status = 404;
            $this->error_at("c", "<0>{$this->uccmttype} not found");
            $crow = null;
        }
        if ($crow) {
            $qreq->delete = "1";
            $this->execute_save($this->req_from_qreq($qreq), $crow);
        } else {
            $this->execute_fail();
        }
        return $this->post_result();
    }

    /** Save one comment from a plain form POST on `$prow`.
     * @return JsonResult */
    private function run_post_form_data(Qrequest $qreq, PaperInfo $prow) {
        $this->prow = $prow;
        $this->single = true;
        $this->reset_item();
        if (($crow = $this->post_target(null))
            && ($req = $this->req_from_qreq($qreq))) {
            $this->execute_save($req, $crow);
        } else {
            $this->execute_fail();
        }
        return $this->post_result();
    }

    /** Save one comment from a single JSON object on the URL's `$prow`, reusing
     * the batch item path (`post_json_item`) for the one item.
     * @param object $jp
     * @return JsonResult */
    private function run_post_single_json(PaperInfo $prow, $jp) {
        $this->prow = $prow;
        $this->single = true;
        $this->post_json_item($jp);
        return $this->post_result();
    }

    /** Process a cross-paper batch of comment objects, best-effort per item; each
     * item's messages are landmarked and the batch itself always returns HTTP 200.
     * @param list<object> $jps
     * @return JsonResult */
    private function run_post_multi_json($jps) {
        $this->single = false;
        foreach ($jps as $jp) {
            $this->post_json_item($jp);
        }
        return $this->post_result();
    }


    /** Reset the current-item working state before processing a batch item. */
    private function reset_item() {
        $this->rrd = null;
        $this->item_message_count = $this->message_count();
        $this->status = 200;
        $this->stale = false;
        $this->ivalid = false;
        $this->change_list = null;
    }

    /** Resolve one item's paper and target, then save it, recording the outcome
     * on `$this` (a saved comment via `execute_save`, or a failure via
     * `execute_fail`). Shared by the single-object and batch paths.
     * @param object $jp */
    private function post_json_item($jp) {
        $this->reset_item();
        if (($crow = $this->post_target($jp))
            && ($req = $this->req_from_json($jp))) {
            $this->execute_save($req, $crow);
        } else {
            $this->execute_fail();
        }
    }

    /** Resolve the item's submission into `$this->prow`: a per-item `pid` may
     * only confirm the URL's `p`, and an already-loaded matching paper is reused.
     * Shared by the single-object and batch paths.
     * @param object $jp
     * @return bool false if the paper could not be resolved (error recorded) */
    private function resolve_item_paper($jp) {
        $pid = $jp->pid ?? $this->qreq_pid;
        if (!is_int($pid) || $pid <= 0 || $pid > PaperInfo::PID_MAX) {
            $this->error_at("pid", $this->conf->_("<0>{Submission} ID required"));
            return false;
        }
        // a per-item `pid` may only confirm a URL-level `p`, never override it
        // (the single path pins the paper via the URL)
        if ($this->qreq_pid !== null && $pid !== $this->qreq_pid) {
            $this->error_at("pid", $this->conf->_("<0>{Submission} ID does not match"));
            return false;
        }
        // reuse the paper already in hand when it matches
        if (!$this->prow || $this->prow->paperId !== $pid) {
            $this->prow = $this->conf->paper_by_id($pid, $this->user);
        }
        if (($fr = $this->user->perm_view_paper($this->prow, null, $pid))) {
            $this->status = $fr->response_code();
            $fr->append_to($this, null, 2);
            return false;
        }
        return true;
    }

    /** @return ?CommentInfo */
    static private function find_comment(PaperInfo $prow, $query) {
        $cmts = $prow->fetch_comments($query);
        return $cmts[0] ?? null;
    }

    /** @param int $round
     * @return ?CommentInfo */
    static private function find_response_by_id(PaperInfo $prow, $round) {
        assert($round > 0);
        return self::find_comment($prow, "(commentType&" . CommentInfo::CT_RESPONSE . ")!=0 and commentRound={$round}");
    }

    /** Locate the `(cid, rrd)` target on `$this->prow`, setting the resolved
     * response round and comment-type names. Marks the item `stale` when a
     * numbered target was replaced by another comment, or its response round
     * differs. Does not check view permission; shared by the GET and POST paths.
     * @param ?int $cid null (unspecified), 0 (new), or a positive comment ID
     * @param ?ResponseRound $rrd
     * @return ?CommentInfo the existing comment, or null if none matches */
    private function locate_target($cid, $rrd) {
        $crow = null;
        if ($cid > 0) {
            $crow = self::find_comment($this->prow, "commentId={$cid}");
        }
        if (!$crow && $rrd) {
            $crow = self::find_response_by_id($this->prow, $rrd->id);
        }

        // if `new` or a numeric ID was provided, it must match the actual comment
        // (always true unless `$rrd`)
        $this->rrd = $rrd;
        if ($crow) {
            assert($cid === $crow->commentId || $rrd);
            $this->rrd = $crow->response_round();
            if (($rrd && $this->rrd !== $rrd)
                || ($cid !== null && $cid !== $crow->commentId)) {
                $this->stale = true;
            }
        }

        // comment/response name
        if ($this->rrd) {
            if ($this->rrd->unnamed) {
                $this->uccmttype = "Response";
                $this->lccmttype = "response";
            } else {
                $this->uccmttype = $this->lccmttype = "{$this->rrd->name} response";
            }
        } else {
            $this->uccmttype = "Comment";
            $this->lccmttype = "comment";
        }
        return $crow;
    }

    /** Resolve a POST's target into the comment to save: an existing (viewable)
     * comment on `$this->prow`, or a fresh skeleton for a new one. A JSON object
     * `$jp` supplies `pid`/`cid`/`response` (`cid` absent, `"new"`, or a
     * nonnegative integer; `response` falsy or a round name; anything else is
     * invalid); a null `$jp` (form POST) names no target of its own. When no
     * target is named, the query `c`/`response` apply.
     * @param ?object $jp
     * @return ?CommentInfo the target comment or a new skeleton, null on error */
    private function post_target($jp) {
        if ($jp && !is_object($jp)) {
            $this->error_at(null, "<0>Expected object");
            return null;
        }

        // `object`: absent or "comment"
        if (($jp->object ?? "comment") !== "comment") {
            $this->error_at("object", "<0>Object type mismatch");
            return null;
        }

        // `pid`: absent or equal to `$this->prow->paperId`
        if ($jp && !$this->resolve_item_paper($jp)) {
            return null;
        }

        // `cid`: absent, "new", or a nonnegative integer (`id` is ignored)
        $cid = null;
        if (isset($jp->cid)) {
            if ($jp->cid === "new") {
                $cid = 0;
            } else if (is_int($jp->cid) && $jp->cid >= 0) {
                $cid = $jp->cid;
            } else {
                $this->error_at("cid", "<0>Parameter error");
                return null;
            }
            if ($this->qreq_cid !== null && $this->qreq_cid !== $cid) {
                $this->error_at("cid", "<0>Comment ID does not match `c`");
                return null;
            }
        }

        // `response`: falsy, or a nonempty string naming a response round
        $rrd = null;
        if ($cid === null
            && isset($jp->response)
            && $jp->response !== false
            && $jp->response !== ""
            && $this->qreq_cid === null
            && $this->qreq_rrd === null) {
            if (!is_string($jp->response)) {
                $this->error_at("response", "<0>Parameter error");
                return null;
            }
            $rrd = $this->conf->response_round($jp->response);
            if (!$rrd) {
                $this->status = 404;
                $this->error_at("response", "<0>Response not found");
                return null;
            }
        }

        // fall back to the query `c`/`response` when the object names no target
        if ($cid === null && $rrd === null) {
            $cid = $this->qreq_cid ?? ($this->qreq_rrd ? null : 0);
            $rrd = $this->qreq_rrd;
        }

        // load comment. Positive ID must exist; otherwise, fresh skeleton
        $crow = $this->locate_target($cid, $rrd);
        if (!$crow) {
            if ($cid > 0) {
                $this->status = 404;
                $this->error_at(null, "<0>{$this->uccmttype} not found");
                return null;
            }
            return $this->rrd !== null
                ? CommentInfo::make_response_template($this->rrd, $this->prow)
                : CommentInfo::make_new_template($this->user, $this->prow);
        }

        // return viewable target
        if ($this->user->can_view_comment($this->prow, $crow, true)) {
            return $crow;
        }

        // don't disclose a hidden comment's existence except to an administrator
        if ($this->user->can_manage($this->prow)) {
            $this->status = 403;
            $this->error_at(null, "<0>You aren’t allowed to view that {$this->lccmttype}");
        } else {
            $this->status = 404;
            $this->error_at(null, "<0>{$this->uccmttype} not found");
        }
        return null;
    }

    /** Save the comment described by `$req` (via `save_comment`; compute-only on
     * a stale conflict), then snapshot the per-item outcome into the accumulators.
     * Mirrors `Paper_API::execute_save`.
     * @param array<string,mixed> $req
     * @param CommentInfo $crow an existing comment or a fresh skeleton */
    private function execute_save($req, $crow) {
        if (!$this->has_error_since($this->item_message_count)) {
            // optimistic-concurrency precondition (a fresh skeleton has
            // timeModified 0, so a new comment never trips this)
            if ($req["if_unmodified_since"] !== null
                && $req["if_unmodified_since"] < $crow->timeModified) {
                $this->stale = true;
            }

            // process the request. A stale edit still runs so its attempted
            // `change_list` is computed, but `save_comment` aborts rather than
            // committing (leaving `$crow` at the server's current version).
            $crow = $this->save_comment($req, $crow);
        }

        // report the conflict unless a more fundamental error intervened
        // (`save_comment` returns null when it records a hard error)
        if ($this->stale && $crow !== null) {
            $this->error_at("if_unmodified_since", "<5><strong>Edit conflict</strong>: The {$this->lccmttype} was edited concurrently");
        }

        $cid = $crow && $crow->commentId > 0 ? $crow->commentId : null;
        if ($cid !== null && !$this->dry_run) {
            $this->comments[] = $crow->unparse_json($this->user);
            ++$this->ncomments;
        } else {
            $this->comments[] = null;
        }
        $this->status_list[] = new Comment_API_Status(
            $this->message_count(),
            $this->ivalid, $this->change_list ?? [],
            $this->stale, $this->prow->paperId, $cid
        );
    }

    /** Build a save request's content from a form POST. Attachments are imported
     * later (in `save_comment`, once the target comment is known). The raw request
     * is kept in `docs_src`.
     * @return ?array<string,mixed> */
    private function req_from_qreq(Qrequest $qreq) {
        // a form POST must carry comment content
        if (!isset($qreq->text) && !friendly_boolean($qreq->delete)) {
            $this->error_at("text", "<0>Parameter error");
            return null;
        }
        $req = [
            "if_unmodified_since" => $this->if_unmodified_since,
            "docs_src" => $qreq,
            "review_token" => $qreq->review_token,
            "visibility" => $qreq->visibility,
            "topic" => $qreq->topic,
            "submit" => !friendly_boolean($qreq->draft),
            "blind" => friendly_boolean($qreq->blind)
        ];
        if (friendly_boolean($qreq->delete)) {
            $req["text"] = false;
            $req["docs"] = [];
            return $req;
        }
        $req["text"] = rtrim(cleannl((string) $qreq->text));
        $req["tags"] = $qreq->tags;
        return $req;
    }

    /** Build a save request's content from a comment object: its
     * `if_unmodified_since` and content fields (the target is resolved
     * separately, by `post_target`). Attachments are imported later (in
     * `save_comment`). The object is kept in `docs_src`.
     * @param object $jp
     * @return ?array<string,mixed> */
    private function req_from_json($jp) {
        // per-object precondition, defaulting to the batch-wide value
        $ius = $this->if_unmodified_since;
        if (isset($jp->if_unmodified_since)) {
            $ius = Paper_API::parse_if_unmodified_since($jp->if_unmodified_since, $this->conf);
            if ($ius === false) {
                $this->error_at("if_unmodified_since", "<0>Parameter error");
                return null;
            }
        }
        $req = [
            "if_unmodified_since" => $ius,
            "docs_src" => $jp,
            "review_token" => $jp->review_token ?? $this->qreq_review_token,
            "visibility" => $jp->visibility ?? null,
            "topic" => $jp->topic ?? null,
            "submit" => !friendly_boolean($jp->draft ?? null),
            "blind" => friendly_boolean($jp->blind ?? null)
        ];
        if (friendly_boolean($jp->delete ?? null)) {
            $req["text"] = false;
            $req["docs"] = [];
            return $req;
        }
        $req["text"] = isset($jp->text) ? rtrim(cleannl((string) $jp->text)) : "";
        if (isset($jp->tags)) {
            $req["tags"] = is_array($jp->tags) ? join(" ", $jp->tags) : $jp->tags;
        }
        return $req;
    }

    /** Import the request's attachments now that the target comment is known.
     * A form request's descriptors come from `parse_qreq_prefix`; a JSON
     * request's from its `docs` value (missing/null retains the existing
     * attachments, `false` clears them, a list is authoritative).
     * @param array<string,mixed> $req
     * @param CommentInfo $xcrow
     * @return ?list<DocumentInfo> */
    private function import_docs($req, $xcrow) {
        $existing = $xcrow->commentId ? $xcrow->attachments()->as_list() : [];
        $src = $req["docs_src"];
        if ($src instanceof Qrequest) {
            $descriptors = Attachments_PaperOption::parse_qreq_prefix(
                $this->prow, $src, "attachment", DTYPE_COMMENT, $existing, $this
            );
        } else if (!isset($src->docs)) {
            return $existing;
        } else if ($src->docs === false) {
            return [];
        } else {
            $descriptors = is_array($src->docs) ? $src->docs : [$src->docs];
        }

        // a comment may retain only its own attachments by `docid`
        $di = (new DocumentImporter($this->prow, DTYPE_COMMENT, 0, $this, "docs"))
            ->set_allowed_docids($xcrow->attachment_ids())
            ->on_import([$this->docloc, "on_document_import"]);
        $docs = [];
        foreach ($descriptors as $dj) {
            if (($doc = $di->upload_document($dj))) {
                $docs[] = $doc;
            } else {
                $this->status = 400;
                return null;
            }
        }
        return $docs;
    }

    /** Save the comment described by `$req` onto `$xcrow` (an existing comment or
     * a fresh skeleton from `post_target`).
     * @param array<string,mixed> $req
     * @param CommentInfo $xcrow
     * @return ?CommentInfo */
    private function save_comment($req, $xcrow) {
        assert($xcrow->is_response() === ($this->rrd !== null));

        // check permission
        $newctype = $xcrow->requested_type($req);
        $whynot = $this->user->perm_edit_comment($this->prow, $xcrow, $newctype);
        if ($whynot) {
            $whynot->set("expand", true)->append_to($this, null, 2);
            return null;
        }

        // import attachments
        if ($req["text"] !== false
            && ($req["docs"] = $this->import_docs($req, $xcrow)) === null) {
            return null;
        }

        // empty
        if ($req["text"] === "" && empty($req["docs"])) {
            if (!$xcrow->commentId) {
                $this->error_at("text", "<0>Refusing to save empty {$this->lccmttype}");
                return null;
            } else {
                $req["text"] = false;
            }
        }
        if ($req["text"] === false && !$xcrow->commentId) {
            // deleting a nonexistent comment is a no-op
            return null;
        }

        // check for review token
        $suser = $this->user;
        if ($req["review_token"]
            && ($token = decode_token($req["review_token"], "V"))
            && in_array($token, $this->user->review_tokens(), true)
            && ($rrow = $this->prow->review_by_token($token))) {
            $suser = $this->conf->user_by_id($rrow->contactId);
        }

        // save (or, for a dry run, validate the change without committing)
        $cs = new CommentStatus($suser);
        $prepared = $cs->prepare_save($xcrow, $req);
        // a suppression request is honored only for an administrator of the
        // comment's submission
        $cs->set_notify($this->notify || !$cs->can_user_manage());
        if ($prepared) {
            $this->change_list = $cs->change_list(true);
        }

        if ($this->stale) {
            // an edit conflict: report the attempted diff, but do not commit;
            // `abort_save` reverts the in-memory changes so the response shows
            // the server's current version
            $cs->abort_save();
            return $xcrow;
        }

        if ($this->dry_run) {
            $cs->abort_save();
            $this->ivalid = $prepared;
            if (!$prepared) {
                $this->error_at(null, "<0>Error saving comment");
                return null;
            }
            return $xcrow;
        }

        if ($prepared && $cs->execute_save()) {
            $cs->notify_followers();
        } else if ($xcrow->is_response()
                   && ($ocrow = self::find_response_by_id($this->prow, (int) $xcrow->commentRound))) {
            // re-entering the same response is not an error;
            // conflict edits are
            if ($ocrow->comment !== $req["text"]
                || $ocrow->attachment_ids() != $xcrow->attachment_ids()) {
                $this->stale = true;
                return $ocrow;
            }
            $xcrow = $ocrow;
        } else {
            $this->error_at(null, "<0>Error saving comment");
            return null;
        }

        // a valid modification was committed
        $this->ivalid = true;

        // save success messages
        $this->append_item($this->save_success_message($xcrow));

        $aunames = $mentions = [];
        $mentions_missing = $mentions_censored = false;
        foreach ($xcrow->notifications ?? [] as $n) {
            if ($n->has(NotificationInfo::CONTACT | NotificationInfo::SENT)) {
                $aunames[] = $n->user->name_h(NAME_EB);
            }
            if ($n->has(NotificationInfo::MENTION)) {
                if ($n->sent()) {
                    $mentions[] = $n->user_html ?? $suser->reviewer_html_for($n->user);
                } else if ($xcrow->timeNotified === $xcrow->timeModified) {
                    $mentions_missing = true;
                }
            }
            if ($n->has(NotificationInfo::CENSORED)) {
                $mentions_censored = true;
            }
        }
        if ($aunames && !$this->prow->has_author($suser)) {
            if ($this->user->allow_view_authors($this->prow)) {
                $this->success($this->conf->_("<5>Notified {submission} contacts {:nblist}", $aunames));
            } else {
                $this->success($this->conf->_("<0>Notified {submission} contact(s)"));
            }
        }
        if ($mentions) {
            $this->success($this->conf->_("<5>Notified mentioned users {:nblist}", $mentions));
        }
        if ($mentions_missing) {
            $this->append_item(MessageItem::warning_note($this->conf->_("<0>Some mentioned users cannot currently see this comment, so they were not notified.")));
        }
        if ($mentions_censored) {
            $this->append_item(MessageItem::warning_note($this->conf->_("<0>Some notifications were censored to anonymize mentioned users.")));
        }
        return $xcrow;
    }

    /** @return MessageItem */
    private function save_success_message(CommentInfo $xcrow) {
        if (!$xcrow->commentId) {
            $action = "deleted";
        } else if ($this->rrd
                   && ($xcrow->commentType & CommentInfo::CT_DRAFT) === 0) {
            $action = "submitted";
        } else {
            $action = "saved";
        }
        return MessageItem::success("<0>{$this->uccmttype} {$action}");
    }

    /** Record a per-item resolution failure (no save attempted). */
    private function execute_fail() {
        $this->status_list[] = new Comment_API_Status($this->message_count());
        $this->comments[] = null;
    }

    /** Assemble the response; in single, most Comment_API_Status fields are raised
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
            if ($this->ncomments > 0) {
                $jr->content["comment"] = $this->comments[0];
            }
        } else {
            $jr->content["status_list"] = $this->status_list;
            if (!$this->dry_run) {
                $jr->content["comments"] = $this->comments;
            }
        }
        return $jr;
    }


    /** @return JsonResult */
    static private function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow, $mode) {
        $old_overrides = $user->overrides();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        try {
            $capi = new Comment_API($user, $qreq, $prow);
            if ($qreq->is_getlike()) {
                if ($mode === DocumentLocator::M_ONE) {
                    $jr = $capi->run_get_one($qreq, $prow);
                } else {
                    $jr = $capi->run_get_multi($qreq, $prow);
                }
            } else if ($qreq->method() === "DELETE") {
                $jr = $capi->run_delete($qreq, $prow);
            } else {
                $jr = $capi->run_post($qreq, $prow, $mode);
            }
        } catch (JsonCompletion $jc) {
            $jr = $jc->result;
        }
        $user->set_overrides($old_overrides);
        if (($jr->content["message_list"] ?? null) === []) {
            unset($jr->content["message_list"]);
        }
        return $jr;
    }

    /** @return JsonResult */
    static function run_one(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        return self::run($user, $qreq, $prow, DocumentLocator::M_ONE);
    }

    /** @return JsonResult */
    static function run_multi(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        return self::run($user, $qreq, $prow, DocumentLocator::M_MULTI);
    }
}
