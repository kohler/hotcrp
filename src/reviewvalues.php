<?php
// reviewvalues.php -- HotCRP parsed review data
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ReviewValues extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ReviewForm
     * @readonly */
    public $rf;

    /** @var bool */
    private $notify = true;
    /** @var bool */
    private $allow_unsubmit = false;
    /** @var bool */
    private $autosearch = true;

    /** @var ?string */
    public $text;
    /** @var ?string */
    public $filename;
    /** @var ?int */
    public $lineno;
    /** @var ?int */
    private $first_lineno;
    /** @var ?array<string,int> */
    private $field_lineno;
    /** @var ?int */
    private $garbage_lineno;

    /** @var int */
    public $paperId;
    /** @var ?int */
    public $reviewId;
    /** @var ?string */
    public $review_ordinal_id;
    /** @var array<string,mixed> */
    public $req;
    /** @var ?bool */
    public $req_json;

    /** @var 0|1|2|3 */
    private $finished = 0;
    /** @var ?list<string> */
    private $submitted;
    /** @var ?list<string> */
    public $updated; // used in tests
    /** @var ?list<string> */
    private $approval_requested;
    /** @var ?list<string> */
    private $approved;
    /** @var ?list<string> */
    private $saved_draft;
    /** @var ?list<string> */
    private $author_notified;
    /** @var ?list<string> */
    public $unchanged;
    /** @var ?list<string> */
    private $unchanged_draft;
    /** @var ?int */
    private $single_approval;
    /** @var ?list<string> */
    private $blank;

    /** @param ReviewForm|Conf $rf */
    function __construct($rf) {
        if ($rf instanceof ReviewForm) {
            $this->conf = $rf->conf;
            $this->rf = $rf;
        } else {
            $this->conf = $rf;
            $this->rf = $this->conf->review_form();
        }
        $this->set_want_ftext(true);
    }

    /** @param bool $x
     * @return $this */
    function set_notify($x) {
        $this->notify = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_allow_unsubmit($x) {
        $this->allow_unsubmit = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_autosearch($x) {
        $this->autosearch = $x;
        return $this;
    }

    /** @param string $text
     * @param ?string $filename
     * @return $this */
    function set_text($text, $filename = null) {
        $this->text = $text;
        $this->filename = $filename;
        $this->lineno = 0;
        return $this;
    }

    /** @param ReviewForm|Conf $rf
     * @return ReviewValues
     * @deprecated */
    static function make_text($rf, $text, $filename = null) {
        return (new ReviewValues($rf))->set_text($text, $filename);
    }

    /** @param int|string $field
     * @param string $msg
     * @param int $status
     * @return MessageItem */
    function rmsg($field, $msg, $status) {
        if (is_int($field)) {
            $lineno = $field;
            $field = null;
        } else if ($field) {
            $lineno = $this->field_lineno[$field] ?? $this->lineno;
        } else {
            $lineno = $this->lineno;
        }
        $mi = $this->msg_at($field, $msg, $status);
        if ($this->filename) {
            $mi->landmark = "{$this->filename}:{$lineno}";
            if ($this->paperId) {
                $mi->landmark .= " (paper #{$this->paperId})";
            }
        }
        return $mi;
    }

    private function check_garbage() {
        if ($this->garbage_lineno) {
            $this->rmsg($this->garbage_lineno, "<0>Review form appears to begin with garbage; ignoring it.", self::WARNING);
        }
        $this->garbage_lineno = null;
    }

    function parse_text($override) {
        assert($this->text !== null && $this->finished === 0);

        $text = $this->text;
        $this->first_lineno = $this->lineno + 1;
        $this->field_lineno = [];
        $this->garbage_lineno = null;
        $this->req = [];
        $this->req_json = false;
        $this->paperId = 0;
        if ($override !== null) {
            $this->req["override"] = $override;
        }

        $mode = 0;
        $nfields = 0;
        $field = null;
        $anyDirectives = 0;

        while ($text !== "") {
            $pos = strpos($text, "\n");
            $line = ($pos === false ? $text : substr($text, 0, $pos + 1));
            ++$this->lineno;

            $linestart = substr($line, 0, 6);
            if ($linestart === "==+== " || $linestart === "==*== ") {
                // make sure we record that we saw the last field
                if ($mode && $field !== null && !isset($this->req[$field])) {
                    $this->req[$field] = "";
                }

                $anyDirectives++;
                if (preg_match('/\A==\+==\s+(.*?)\s+(Paper Review(?: Form)?s?)\s*\z/', $line, $m)
                    && $m[1] !== $this->conf->short_name) {
                    $this->check_garbage();
                    $this->rmsg("confid", "<0>Ignoring review form, which appears to be for a different conference.", self::ERROR);
                    $this->rmsg("confid", "<5>(If this message is in error, replace the line that reads “<code>" . htmlspecialchars(rtrim($line)) . "</code>” with “<code>==+== " . htmlspecialchars($this->conf->short_name) . " " . $m[2] . "</code>” and upload again.)", self::INFORM);
                    return false;
                } else if (preg_match('/\A==\+== Begin Review/i', $line)) {
                    if ($nfields > 0) {
                        break;
                    }
                } else if (preg_match('/\A==\+== Paper #?(\d+)/i', $line, $match)) {
                    if ($nfields > 0) {
                        break;
                    }
                    $this->paperId = intval($match[1]);
                    $this->req["blind"] = 1;
                    $this->first_lineno = $this->field_lineno["paperNumber"] = $this->lineno;
                } else if (preg_match('/\A==\+== Reviewer:\s*(.*?)\s*\z/', $line, $match)
                           && ($user = Text::split_name($match[1], true))
                           && $user[2]) {
                    $this->field_lineno["reviewerEmail"] = $this->lineno;
                    $this->req["reviewerFirst"] = $user[0];
                    $this->req["reviewerLast"] = $user[1];
                    $this->req["reviewerEmail"] = $user[2];
                } else if (preg_match('/\A==\+== Paper (Number|\#)\s*\z/i', $line)) {
                    if ($nfields > 0) {
                        break;
                    }
                    $field = "paperNumber";
                    $this->field_lineno[$field] = $this->lineno;
                    $mode = 1;
                    $this->req["blind"] = 1;
                    $this->first_lineno = $this->lineno;
                } else if (preg_match('/\A==\+== Submit Review\s*\z/i', $line)
                           || preg_match('/\A==\+== Review Ready\s*\z/i', $line)) {
                    $this->req["ready"] = true;
                } else if (preg_match('/\A==\+== Open Review\s*\z/i', $line)) {
                    $this->req["blind"] = 0;
                } else if (preg_match('/\A==\+== Version\s*(\d+)\s*\z/i', $line, $match)) {
                    if (($this->req["edit_version"] ?? 0) < intval($match[1])) {
                        $this->req["edit_version"] = intval($match[1]);
                    }
                } else if (preg_match('/\A==\+== Review Readiness\s*/i', $line)) {
                    $field = "readiness";
                    $mode = 1;
                } else if (preg_match('/\A==\+== Review Anonymity\s*/i', $line)) {
                    $field = "anonymity";
                    $mode = 1;
                } else if (preg_match('/\A(?:==\+== [A-Z]\.|==\*== )\s*(.*?)\s*\z/', $line, $match)) {
                    while (substr($text, strlen($line), 6) === $linestart) {
                        $pos = strpos($text, "\n", strlen($line));
                        $xline = ($pos === false ? substr($text, strlen($line)) : substr($text, strlen($line), $pos + 1 - strlen($line)));
                        if (preg_match('/\A==[+*]==\s+(.*?)\s*\z/', $xline, $xmatch)) {
                            $match[1] .= " " . $xmatch[1];
                        }
                        $line .= $xline;
                    }
                    if (($f = $this->conf->find_review_field($match[1]))) {
                        $field = $f->short_id;
                        $this->field_lineno[$field] = $this->lineno;
                        $nfields++;
                    } else {
                        $field = null;
                        $this->check_garbage();
                        $this->rmsg(null, "<0>Review field ‘{$match[1]}’ is not used for {$this->conf->short_name} reviews. Ignoring this section.", self::ERROR);
                    }
                    $mode = 1;
                } else {
                    $field = null;
                    $mode = 1;
                }
            } else if ($mode < 2 && (str_starts_with($line, "==-==") || ltrim($line) === "")) {
                /* ignore line */
            } else {
                if ($mode === 0) {
                    $this->garbage_lineno = $this->lineno;
                    $field = null;
                }
                if (str_starts_with($line, "\\==") && preg_match('/\A\\\\==[-+*]==/', $line)) {
                    $line = substr($line, 1);
                }
                if ($field !== null) {
                    $this->req[$field] = ($this->req[$field] ?? "") . $line;
                }
                $mode = 2;
            }

            $text = (string) substr($text, strlen($line));
        }

        if ($nfields === 0 && $this->first_lineno === 1) {
            $this->rmsg(null, "<0>That didn’t appear to be a review form; I was not able to extract any information from it. Please check its formatting and try again.", self::ERROR);
        }

        $this->text = $text;
        --$this->lineno;

        if (isset($this->req["readiness"])) {
            $this->req["ready"] = strcasecmp(trim($this->req["readiness"]), "Ready") === 0;
        }
        if (isset($this->req["anonymity"])) {
            $this->req["blind"] = strcasecmp(trim($this->req["anonymity"]), "Open") !== 0;
        }

        if ($this->paperId) {
            /* OK */
        } else if (isset($this->req["paperNumber"])
                   && ($pid = stoi(trim($this->req["paperNumber"])) ?? -1) > 0) {
            $this->paperId = $pid;
        } else if ($nfields > 0) {
            $this->rmsg("paperNumber", "<0>This review form doesn’t report which paper number it is for. Make sure you’ve entered the paper number in the right place and try again.", self::ERROR);
            $nfields = 0;
        }

        if ($nfields === 0 && $text) { // try again
            return $this->parse_text($override);
        } else {
            return $nfields !== 0;
        }
    }

    function parse_json($j) {
        assert($this->text === null && $this->finished === 0);
        if (!is_object($j) && !is_array($j)) {
            return false;
        }
        $this->req = [];
        $this->req_json = true;
        $this->paperId = 0; // XXX annoying that this field exists
        // XXX validate more
        foreach ($j as $k => $v) {
            if ($k === "round") {
                if ($v === null || is_string($v)) {
                    $this->req["round"] = $v;
                }
            } else if ($k === "blind") {
                if (is_bool($v)) {
                    $this->req["blind"] = $v ? 1 : 0;
                }
            } else if ($k === "submitted" || $k === "ready") {
                if (is_bool($v)) {
                    $this->req["ready"] = $v ? 1 : 0;
                }
            } else if ($k === "draft") {
                if (is_bool($v)) {
                    $this->req["ready"] = $v ? 0 : 1;
                }
            } else if ($k === "name" || $k === "reviewer_name") {
                if (is_string($v)) {
                    list($this->req["reviewerFirst"], $this->req["reviewerLast"]) = Text::split_name($v);
                }
            } else if ($k === "email" || $k === "reviewer_email") {
                if (is_string($v)) {
                    $this->req["reviewerEmail"] = trim($v);
                }
            } else if ($k === "affiliation" || $k === "reviewer_affiliation") {
                if (is_string($v)) {
                    $this->req["reviewerAffiliation"] = $v;
                }
            } else if ($k === "first" || $k === "firstName") {
                if (is_string($v)) {
                    $this->req["reviewerFirst"] = simplify_whitespace($v);
                }
            } else if ($k === "last" || $k === "lastName") {
                if (is_string($v)) {
                    $this->req["reviewerLast"] = simplify_whitespace($v);
                }
            } else if ($k === "edit_version") {
                if (is_int($v)) {
                    $this->req["edit_version"] = $v;
                }
            } else if ($k === "if_vtag_match") {
                if (is_int($v)) {
                    $this->req["if_vtag_match"] = $v;
                }
            } else if (($f = $this->conf->find_review_field($k))) {
                if (!isset($this->req[$f->short_id])) {
                    $this->req[$f->short_id] = $v;
                }
            }
        }
        if (!empty($this->req) && !isset($this->req["ready"])) {
            $this->req["ready"] = 1;
        }
        return !empty($this->req);
    }

    static private $ignore_web_keys = [
        "submitreview" => true, "savedraft" => true, "unsubmitreview" => true,
        "deletereview" => true, "r" => true, "m" => true, "post" => true,
        "forceShow" => true, "update" => true, "has_blind" => true,
        "adoptreview" => true, "adoptsubmit" => true, "adoptdraft" => true,
        "approvesubreview" => true, "default" => true, "vtag" => true
    ];

    /** @param bool $override
     * @return bool */
    function parse_qreq(Qrequest $qreq, $override) {
        assert($this->text === null && $this->finished === 0);
        $rf = $this->conf->review_form();
        $hasreqs = [];
        $this->req = [];
        $this->req_json = false;
        foreach ($qreq as $k => $v) {
            if (isset(self::$ignore_web_keys[$k]) || !is_scalar($v)) {
                /* skip */
            } else if ($k === "p") {
                $this->paperId = stoi($v) ?? -1;
            } else if ($k === "override") {
                $this->req["override"] = !!$v;
            } else if ($k === "edit_version") {
                $this->req[$k] = stoi($v) ?? -1;
            } else if ($k === "blind" || $k === "ready") {
                $this->req[$k] = is_bool($v) ? (int) $v : (stoi($v) ?? -1);
            } else if (str_starts_with($k, "has_")) {
                if ($k !== "has_blind" && $k !== "has_override" && $k !== "has_ready") {
                    $hasreqs[] = substr($k, 4);
                }
            } else if (($f = $rf->field($k) ?? $this->conf->find_review_field($k))
                       && !isset($this->req[$f->short_id])) {
                $this->req[$f->short_id] = $f->extract_qreq($qreq, $k);
            }
        }
        foreach ($hasreqs as $k) {
            if (($f = $rf->field($k) ?? $this->conf->find_review_field($k))) {
                $this->req[$f->short_id] = $this->req[$f->short_id] ?? "";
            }
        }
        if (empty($this->req)) {
            return false;
        }
        if ($qreq->has_blind) {
            $this->req["blind"] = $this->req["blind"] ?? 0;
        }
        if ($override) {
            $this->req["override"] = 1;
        }
        return true;
    }

    /** @param bool $ready
     * @return $this */
    function set_ready($ready) {
        $this->req["ready"] = $ready ? 1 : 0;
        return $this;
    }

    function set_approved() {
        $this->req["approvesubreview"] = $this->req["ready"] = 1;
    }

    /** @param ?string $msg */
    private function reviewer_error($msg) {
        $msg = $msg ?? $this->conf->_("<0>Can’t submit a review for {}", $this->req["reviewerEmail"]);
        $this->rmsg("reviewerEmail", $msg, self::ERROR);
    }

    /** @return bool */
    function check_and_save(Contact $user, ?PaperInfo $prow = null, ?ReviewInfo $rrow = null) {
        assert(!$rrow || $rrow->paperId === $prow->paperId);
        $this->reviewId = $this->review_ordinal_id = null;

        // look up paper
        if (!$prow) {
            if (!$this->paperId) {
                $this->rmsg("paperNumber", "<0>This review form doesn’t report which paper number it is for.  Make sure you’ve entered the paper number in the right place and try again.", self::ERROR);
                return false;
            }
            $prow = $user->paper_by_id($this->paperId);
            if (($whynot = $user->perm_view_paper($prow, false, $this->paperId))) {
                $this->rmsg("paperNumber", "<5>" . $whynot->unparse_html(), self::ERROR);
                return false;
            }
        }
        if ($this->paperId && $prow->paperId !== $this->paperId) {
            $this->rmsg("paperNumber", "<0>This review form is for paper #{$this->paperId}, not paper #{$prow->paperId}; did you mean to upload it here? I have ignored the form.", MessageSet::ERROR);
            return false;
        }
        $this->paperId = $prow->paperId;

        // look up reviewer
        $reviewer = $user;
        if ($rrow) {
            if ($rrow->contactId !== $user->contactId) {
                $reviewer = $this->conf->user_by_id($rrow->contactId, USER_SLICE);
            }
        } else if (isset($this->req["reviewerEmail"])
                   && strcasecmp($this->req["reviewerEmail"], $user->email) !== 0) {
            if (!($reviewer = $this->conf->user_by_email($this->req["reviewerEmail"]))) {
                $this->reviewer_error($user->privChair ? $this->conf->_("<0>User {} not found", $this->req["reviewerEmail"]) : null);
                return false;
            }
        }

        // look up review
        if (!$rrow) {
            $rrow = $prow->fresh_review_by_user($reviewer);
        }
        if (!$rrow && $user->review_tokens()) {
            $prow->ensure_full_reviews();
            if (($xrrows = $prow->reviews_by_user(-1, $user->review_tokens()))) {
                $rrow = $xrrows[0];
            }
        }

        // maybe create review
        $new_rrid = false;
        if (!$rrow) {
            $round = isset($this->req["round"]) ? (int) $this->conf->round_number($this->req["round"]) : null;
            if (($whyNot = $user->perm_create_review($prow, $reviewer, $round))) {
                if ($user !== $reviewer) {
                    $this->reviewer_error(null);
                }
                $this->reviewer_error("<5>" . $whyNot->unparse_html());
                return false;
            }
            $new_rrid = $user->assign_review($prow->paperId, $reviewer->contactId, $reviewer->isPC ? REVIEW_PC : REVIEW_EXTERNAL, [
                "selfassign" => $reviewer === $user, "round_number" => $round
            ]);
            if (!$new_rrid) {
                $this->rmsg(null, "<0>Internal error while creating review", self::ERROR);
                return false;
            }
            $rrow = $prow->fresh_review_by_id($new_rrid);
        }

        // check permission
        $whyNot = $user->perm_edit_review($prow, $rrow, true);
        if ($whyNot) {
            if ($user === $reviewer || $user->can_view_review_identity($prow, $rrow)) {
                $this->rmsg(null, "<5>" . $whyNot->unparse_html(), self::ERROR);
            } else {
                $this->reviewer_error(null);
            }
            return false;
        }

        // actually check review and save
        if ($this->check($rrow)) {
            return $this->_do_save($user, $prow, $rrow);
        } else {
            if ($new_rrid) {
                $user->assign_review($prow->paperId, $reviewer->contactId, 0);
            }
            return false;
        }
    }

    /** @param ReviewField $f
     * @param ReviewInfo $rrow
     * @return array{int|string,int|string} */
    private function fvalues($f, $rrow) {
        $v0 = $v1 = $rrow->fields[$f->order];
        if (isset($this->req[$f->short_id])) {
            $reqv = $this->req[$f->short_id];
            $v1 = $this->req_json ? $f->parse_json($reqv) : $f->parse($reqv);
        }
        return [$v0, $v1];
    }

    /** @return bool */
    private function check(ReviewInfo $rrow) {
        $submit = $this->req["ready"] ?? null;
        if (!$submit
            && !$this->allow_unsubmit
            && $rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
            $submit = $this->req["ready"] = 1;
        }

        $msgcount = $this->message_count();
        $missingfields = [];
        $unready = $anydiff = $anyvalues = false;

        foreach ($this->rf->forder as $fid => $f) {
            if (!isset($this->req[$fid])
                && (!$submit || !$f->test_exists($rrow))) {
                continue;
            }
            list($old_fval, $fval) = $this->fvalues($f, $rrow);
            if ($fval === false) {
                $this->rmsg($fid, $this->conf->_("<0>{} cannot be ‘{}’", $f->name, UnicodeHelper::utf8_abbreviate(trim($this->req[$fid]), 100)), self::WARNING);
                unset($this->req[$fid]);
                $unready = true;
                continue;
            }
            if ($f->required
                && !$f->value_present($fval)
                && $f->view_score >= VIEWSCORE_PC) {
                $missingfields[] = $f;
                $unready = $unready || $submit;
            }
            $anydiff = $anydiff
                || ($old_fval !== $fval
                    && (!is_string($fval) || cleannl($fval) !== cleannl($old_fval ?? "")));
            $anyvalues = $anyvalues
                || $f->value_present($fval);
        }

        if ($missingfields && $submit && $anyvalues) {
            foreach ($missingfields as $f) {
                $this->rmsg($f->short_id, $this->conf->_("<0>{}: Entry required", $f->name), self::WARNING);
            }
        }

        if ($rrow->reviewId && isset($this->req["reviewerEmail"])) {
            $reviewer = $rrow->reviewer();
            if (strcasecmp($reviewer->email, $this->req["reviewerEmail"]) !== 0
                && (!isset($this->req["reviewerFirst"])
                    || !isset($this->req["reviewerLast"])
                    || strcasecmp($this->req["reviewerFirst"], $reviewer->firstName) !== 0
                    || strcasecmp($this->req["reviewerLast"], $reviewer->lastName) !== 0)) {
                $name1 = Text::name($this->req["reviewerFirst"] ?? "", $this->req["reviewerLast"] ?? "", $this->req["reviewerEmail"], NAME_EB);
                $name2 = Text::nameo($reviewer, NAME_EB);
                $this->rmsg("reviewerEmail", "<0>The review form was meant for {$name1}, but this review belongs to {$name2}.", self::ERROR);
                $this->rmsg("reviewerEmail", "<5>If you want to upload the form anyway, remove the “<code class=\"nw\">==+== Reviewer</code>” line from the form.", self::INFORM);
                return false;
            }
        }

        if ($rrow->reviewId
            && $rrow->reviewEditVersion > ($this->req["edit_version"] ?? 0)
            && $anydiff
            && $this->text !== null) {
            $this->rmsg($this->first_lineno, "<0>This review has been edited online since you downloaded this offline form, so for safety I am not replacing the online version.", self::ERROR);
            $this->rmsg($this->first_lineno, "<5>If you want to override your online edits, add a line “<code class=\"nw\">==+== Version {$rrow->reviewEditVersion}</code>” to your offline review form for paper #{$this->paperId} and upload the form again.", self::INFORM);
            return false;
        }

        if ($unready) {
            if ($submit && $anyvalues) {
                $what = $this->req["approvesubreview"] ?? null ? "approved" : "submitted";
                $this->rmsg("ready", $this->conf->_("<0>This review can’t be {$what} until entries are provided for all required fields."), self::WARNING);
            }
            $this->req["ready"] = 0;
        }

        if ($this->has_error_since($msgcount)) {
            return false;
        } else if ($anyvalues || ($this->req["approvesubreview"] ?? null)) {
            return true;
        } else {
            $this->blank[] = "#" . $this->paperId;
            return false;
        }
    }

    /** @param int $newstatus
     * @param int $oldstatus */
    private function _do_notify(PaperInfo $prow, ReviewInfo $rrow,
                                $newstatus, $oldstatus,
                                Contact $reviewer, Contact $user) {
        assert($this->notify);
        $info = [
            "prow" => $prow,
            "rrow" => $rrow,
            "reviewer_contact" => $reviewer,
            "combination_type" => 1
        ];
        $diffinfo = $rrow->prop_diff();
        if ($newstatus >= ReviewInfo::RS_COMPLETED
            && ($diffinfo->notify || $diffinfo->notify_author)) {
            if ($oldstatus < ReviewInfo::RS_COMPLETED) {
                $tmpl = "@reviewsubmit";
            } else {
                $tmpl = "@reviewupdate";
            }
            $always_combine = false;
            $diff_view_score = $diffinfo->view_score();
        } else if ($newstatus < ReviewInfo::RS_COMPLETED
                   && $newstatus >= ReviewInfo::RS_DELIVERED
                   && ($diffinfo->fields() || $newstatus !== $oldstatus)) {
            if ($newstatus >= ReviewInfo::RS_APPROVED) {
                $tmpl = "@reviewapprove";
            } else if ($newstatus === ReviewInfo::RS_DELIVERED
                       && $oldstatus < ReviewInfo::RS_DELIVERED) {
                $tmpl = "@reviewapprovalrequest";
            } else if ($rrow->requestedBy === $user->contactId) {
                $tmpl = "@reviewpreapprovaledit";
            } else {
                $tmpl = "@reviewapprovalupdate";
            }
            $always_combine = true;
            $diff_view_score = null;
            $info["rrow_unsubmitted"] = true;
        } else {
            return;
        }

        $preps = [];
        foreach ($prow->review_followers(0) as $minic) {
            assert(($minic->overrides() & Contact::OVERRIDE_CONFLICT) === 0);
            // skip same user, dormant user, cannot view review
            if ($minic->contactId === $user->contactId
                || $minic->is_dormant()
                || !$minic->can_view_review($prow, $rrow, $diff_view_score)) {
                continue;
            }
            // if draft, skip unless author, requester, or explicitly interested
            if ($rrow->reviewStatus < ReviewInfo::RS_COMPLETED
                && $rrow->contactId !== $minic->contactId
                && $rrow->requestedBy !== $minic->contactId
                && ($minic->review_watch($prow, 0) & Contact::WATCH_REVIEW_EXPLICIT) === 0) {
                continue;
            }
            // prepare mail
            $p = HotCRPMailer::prepare_to($minic, $tmpl, $info);
            if (!$p) {
                continue;
            }
            // Don't combine preparations unless you can see all submitted
            // reviewer identities
            if (!$always_combine
                && !$prow->has_author($minic)
                && (!$prow->has_active_reviewer($minic)
                    || !$minic->can_view_review_identity($prow, null))) {
                $p->unique_preparation = true;
            }
            $preps[] = $p;
        }

        HotCRPMailer::send_combined_preparations($preps);
    }

    private function _do_save(Contact $user, PaperInfo $prow, ReviewInfo $rrow) {
        assert($this->paperId === $prow->paperId);
        assert($rrow->paperId === $prow->paperId);
        $old_reviewId = $rrow->reviewId;
        assert($rrow->reviewId > 0);
        $old_nonempty_view_score = $this->rf->nonempty_view_score($rrow);
        $oldstatus = $rrow->reviewStatus;
        $rflags = $rrow->rflags;
        '@phan-var-force int $rflags';
        $admin = $user->allow_administer($prow);
        $usedReviewToken = $user->active_review_token_for($prow, $rrow);

        // check whether we can review
        if (!$user->time_review($prow, $rrow)
            && (!isset($this->req["override"]) || !$admin)) {
            $this->rmsg(null, '<5>The <a href="' . $this->conf->hoturl("deadlines") . '">deadline</a> for entering this review has passed.', self::ERROR);
            if ($admin) {
                $this->rmsg(null, '<0>Select the “Override deadlines” checkbox and try again if you really want to override the deadline.', self::INFORM);
            }
            return false;
        }

        // check version match
        if (isset($this->req["if_vtag_match"])
            && $this->req["if_vtag_match"] !== $rrow->reviewTime) {
            $this->rmsg("if_vtag_match", "<0>Version mismatch", self::ERROR);
            return false;
        }

        // PC reviewers submit PC reviews, not external
        // XXX this seems weird; if usedReviewToken, then review row user
        // is never PC...
        if ($rrow->reviewId
            && $rrow->reviewType === REVIEW_EXTERNAL
            && $user->contactId === $rrow->contactId
            && $user->isPC
            && !$usedReviewToken) {
            $rrow->set_prop("reviewType", REVIEW_PC);
            $rflags = ($rflags & ~ReviewInfo::RFM_TYPES) | (1 << REVIEW_PC);
        }

        // review body
        $view_score = VIEWSCORE_EMPTY;
        $any_fval_diffs = false;
        $wc = 0;
        foreach ($this->rf->all_fields() as $f) {
            $exists = $f->test_exists($rrow);
            list($old_fval, $fval) = $this->fvalues($f, $rrow);
            if (!$exists && $old_fval === null) {
                continue;
            }
            if ($fval === false
                || ($rrow->reviewId > 0 && $f->required && !$f->value_present($fval))) {
                $fval = $old_fval;
            }
            $fval_diffs = $fval !== $old_fval
                && (!is_string($fval) || $fval !== cleannl($old_fval ?? ""));
            if ($fval_diffs || !$rrow->reviewId) {
                $rrow->set_fval_prop($f, $fval, $fval_diffs);
            }
            if ($exists) {
                $any_fval_diffs = $any_fval_diffs || $fval_diffs;
                if ($f->include_word_count()) {
                    $wc += count_words($fval ?? "");
                }
                if ($view_score < $f->view_score && $fval !== null) {
                    $view_score = $f->view_score;
                }
            }
        }

        // word count, edit version
        if ($any_fval_diffs) {
            $rrow->set_prop("reviewWordCount", $wc);
            assert(is_int($this->req["edit_version"] ?? 0)); // XXX sanity check
            if ($rrow->reviewId
                && ($this->req["edit_version"] ?? 0) > ($rrow->reviewEditVersion ?? 0)) {
                $rrow->set_prop("reviewEditVersion", $this->req["edit_version"]);
            }
        }

        // new status
        if ($view_score === VIEWSCORE_EMPTY) {
            // empty review: do not submit, adopt, or deliver
            $newstatus = max($oldstatus, ReviewInfo::RS_ACCEPTED);
        } else if (!($this->req["ready"] ?? null)) {
            // unready nonempty review is at least drafted
            $newstatus = max($oldstatus, ReviewInfo::RS_DRAFTED);
        } else if ($oldstatus < ReviewInfo::RS_COMPLETED) {
            $approvable = $rrow->subject_to_approval();
            if ($approvable && !$user->isPC) {
                $newstatus = max($oldstatus, ReviewInfo::RS_DELIVERED);
            } else if ($approvable && ($this->req["approvesubreview"] ?? null)) {
                $newstatus = ReviewInfo::RS_APPROVED;
            } else {
                $newstatus = ReviewInfo::RS_COMPLETED;
            }
        } else {
            $newstatus = $oldstatus;
        }

        // get the current time
        $now = max(time(), $rrow->reviewModified + 1);

        // set status-related fields
        if ($newstatus === ReviewInfo::RS_ACCEPTED
            && $rrow->reviewModified <= 0) {
            $rrow->set_prop("reviewModified", 1);
            $rflags |= ReviewInfo::RF_LIVE | ReviewInfo::RF_ACCEPTED;
        } else if ($newstatus >= ReviewInfo::RS_DRAFTED
                   && $any_fval_diffs) {
            $rrow->set_prop("reviewModified", $now);
            $rflags |= ReviewInfo::RF_LIVE | ReviewInfo::RF_ACCEPTED | ReviewInfo::RF_DRAFTED;
        }
        if ($newstatus === ReviewInfo::RS_DELIVERED
            && $rrow->timeApprovalRequested <= 0) {
            $rrow->set_prop("timeApprovalRequested", $now);
            $rflags |= ReviewInfo::RF_DELIVERED;
        } else if ($newstatus === ReviewInfo::RS_APPROVED
                   && $rrow->timeApprovalRequested >= 0) {
            $rrow->set_prop("timeApprovalRequested", -$now);
            $rflags |= ReviewInfo::RF_DELIVERED | ReviewInfo::RF_APPROVED;
        }
        if ($newstatus >= ReviewInfo::RS_COMPLETED
            && ($rrow->reviewSubmitted ?? 0) <= 0) {
            $rrow->set_prop("reviewSubmitted", $now);
            $rflags |= ReviewInfo::RF_SUBMITTED;
        } else if ($newstatus < ReviewInfo::RS_COMPLETED
                   && ($rrow->reviewSubmitted ?? 0) > 0) {
            $rrow->set_prop("reviewSubmitted", null);
            $rflags &= ~ReviewInfo::RF_SUBMITTED;
        }
        if ($newstatus >= ReviewInfo::RS_APPROVED) {
            $rrow->set_prop("reviewNeedsSubmit", 0);
        }
        if ($rrow->reviewId && $newstatus !== $oldstatus) {
            // Must mark view score to ensure database is modified
            $rrow->mark_prop_view_score($old_nonempty_view_score);
        }

        // anonymity
        $reviewBlind = $this->conf->is_review_blind(!!($this->req["blind"] ?? null));
        if (!$rrow->reviewId
            || $reviewBlind != $rrow->reviewBlind) {
            $rrow->set_prop("reviewBlind", $reviewBlind ? 1 : 0);
            $rrow->mark_prop_view_score(VIEWSCORE_ADMINONLY);
            $rflags = ($rflags & ~ReviewInfo::RF_BLIND) | ($reviewBlind ? ReviewInfo::RF_BLIND : 0);
        }

        // notification
        $notification_bound = $now - ReviewForm::NOTIFICATION_DELAY;
        $newsubmit = $newstatus >= ReviewInfo::RS_COMPLETED
            && $oldstatus < ReviewInfo::RS_COMPLETED;
        $author_view_score = $prow->can_author_view_decision()
            ? VIEWSCORE_AUTHORDEC
            : VIEWSCORE_AUTHOR;
        $diffinfo = $rrow->prop_diff();
        if (!$rrow->reviewId || $diffinfo->is_viewable()) {
            $rrow->set_prop("reviewViewScore", $view_score);
            // XXX distinction between VIEWSCORE_AUTHOR/VIEWSCORE_AUTHORDEC?
            if ($diffinfo->view_score() >= $author_view_score) {
                // Author can see modification.
                $rrow->set_prop("reviewAuthorModified", $now);
            } else if (!$rrow->reviewAuthorModified
                       && ($rrow->base_prop("reviewModified") ?? 0) > 1
                       && $old_nonempty_view_score >= $author_view_score) {
                // Author cannot see current modification; record last
                // modification they could see
                $rrow->set_prop("reviewAuthorModified", $rrow->base_prop("reviewModified"));
            }
            // do not notify on updates within 3 hours, except fresh submits
            if ($newstatus >= ReviewInfo::RS_COMPLETED
                && $diffinfo->view_score() > VIEWSCORE_REVIEWERONLY
                && $this->notify) {
                if (!$rrow->reviewNotified
                    || $rrow->reviewNotified < $notification_bound
                    || $newsubmit) {
                    $rrow->set_prop("reviewNotified", $now);
                    $diffinfo->notify = true;
                }
                if ((!$rrow->reviewAuthorNotified
                     || $rrow->reviewAuthorNotified < $notification_bound)
                    && $diffinfo->view_score() >= $author_view_score
                    && $prow->can_author_view_submitted_review()) {
                    $rrow->set_prop("reviewAuthorNotified", $now);
                    $diffinfo->notify_author = true;
                }
            }
        }

        // potentially assign review ordinal (requires table locking since
        // mySQL is stupid)
        $locked = $newordinal = false;
        if ((!$rrow->reviewId
             && $newsubmit
             && $diffinfo->view_score() >= VIEWSCORE_AUTHORDEC)
            || ($rrow->reviewId
                && !$rrow->reviewOrdinal
                && ($newsubmit || $rrow->reviewStatus >= ReviewInfo::RS_COMPLETED)
                && ($diffinfo->view_score() >= VIEWSCORE_AUTHORDEC
                    || $this->rf->nonempty_view_score($rrow) >= VIEWSCORE_AUTHORDEC))) {
            $result = $this->conf->qe_raw("lock tables PaperReview write");
            if (Dbl::is_error($result)) {
                return false;
            }
            Dbl::free($result);
            $locked = true;
            $max_ordinal = $this->conf->fetch_ivalue("select coalesce(max(reviewOrdinal), 0) from PaperReview where paperId=? group by paperId", $prow->paperId);
            $rrow->set_prop("reviewOrdinal", (int) $max_ordinal + 1);
            $newordinal = true;
        }
        if ($newordinal
            || (($newsubmit
                 || ($newstatus >= ReviewInfo::RS_APPROVED && $oldstatus < ReviewInfo::RS_APPROVED))
                && !$rrow->timeDisplayed)) {
            $rrow->set_prop("timeDisplayed", $now);
        }

        // actually affect database
        $rrow->set_prop("rflags", $rflags);
        $no_attempt = $rrow->reviewId > 0 && !$diffinfo->is_viewable();
        $result = $rrow->save_prop();

        // unlock tables even if problem
        if ($locked) {
            $this->conf->qe_raw("unlock tables");
        }

        if ($result < 0 || !$rrow->reviewId) {
            if ($result === ReviewInfo::SAVE_PROP_CONFLICT) {
                $this->rmsg(null, "<0>Review was edited concurrently, please try again", self::ERROR);
            }
            $rrow->abort_prop();
            return false;
        }

        // update caches
        $prow->update_rights();

        // look up review ID
        $this->req["reviewId"] = $rrow->reviewId;
        $this->reviewId = $rrow->reviewId;
        $this->review_ordinal_id = $rrow->unparse_ordinal_id();

        // XXX only used for assertion
        $new_rrow = $prow->fresh_review_by_id($rrow->reviewId);
        if ($new_rrow->reviewStatus !== $newstatus
            || $rrow->reviewStatus !== $newstatus) {
            error_log("{$this->conf->dbname}: review #{$prow->paperId}/{$new_rrow->reviewId} saved reviewStatus {$new_rrow->reviewStatus} (expected {$newstatus})");
        }
        assert($new_rrow->reviewStatus === $newstatus);

        // log updates -- but not if review token is used
        if (!$usedReviewToken && $diffinfo->is_viewable()) {
            $user->log_activity_for($rrow->contactId, $this->_log_message($rrow, $old_reviewId, $oldstatus, $newstatus, $diffinfo), $prow);
        }

        // log change
        if ($old_reviewId > 0 && $diffinfo->is_viewable()) {
            $diffinfo->save_history();
        }

        // if external, forgive the requester from finishing their review
        if ($rrow->reviewType < REVIEW_SECONDARY
            && $rrow->requestedBy
            && $newstatus >= ReviewInfo::RS_COMPLETED) {
            $this->conf->q_raw("update PaperReview set reviewNeedsSubmit=0 where paperId={$prow->paperId} and contactId={$rrow->requestedBy} and reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null");
        }

        // notify automatic tags
        if ($this->autosearch) {
            $this->conf->update_automatic_tags($prow, "review");
        }

        // potentially email chair, reviewers, and authors
        $reviewer = $user;
        if ($rrow->contactId !== $user->contactId) {
            $reviewer = $this->conf->user_by_id($rrow->contactId, USER_SLICE);
        }
        if ($this->notify) {
            $this->_do_notify($prow, $rrow, $newstatus, $oldstatus, $reviewer, $user);
        }

        // record what happened
        $what = "#{$prow->paperId}";
        if ($rrow->reviewOrdinal) {
            $what .= unparse_latin_ordinal($rrow->reviewOrdinal);
        }
        if ($newsubmit) {
            $this->submitted[] = $what;
        } else if ($newstatus === ReviewInfo::RS_DELIVERED
                   && $rrow->contactId === $user->contactId) {
            $this->approval_requested[] = $what;
        } else if ($newstatus === ReviewInfo::RS_APPROVED
                   && $oldstatus < $newstatus
                   && $rrow->contactId !== $user->contactId) {
            $this->approved[] = $what;
        } else if ($diffinfo->is_viewable()) {
            if ($newstatus >= ReviewInfo::RS_APPROVED) {
                $this->updated[] = $what;
            } else {
                $this->saved_draft[] = $what;
                $this->single_approval = +$rrow->timeApprovalRequested;
            }
        } else {
            $this->unchanged[] = $what;
            if ($newstatus < ReviewInfo::RS_APPROVED) {
                $this->unchanged_draft[] = $what;
                $this->single_approval = +$rrow->timeApprovalRequested;
            }
        }
        if ($diffinfo->notify_author) {
            $this->author_notified[] = $what;
        }

        return true;
    }

    /** @param ReviewInfo $rrow
     * @param int $old_reviewId
     * @param int $oldstatus
     * @param int $newstatus
     * @param ReviewDiffInfo $diffinfo */
    private function _log_message($rrow, $old_reviewId,
                                  $oldstatus, $newstatus, $diffinfo) {
        $actions = [];
        if (!$old_reviewId) {
            $actions[] = "started";
        } else if ($diffinfo->fields()) {
            $actions[] = "edited";
        }
        if ($newstatus >= ReviewInfo::RS_COMPLETED
            && $oldstatus < ReviewInfo::RS_COMPLETED) {
            $actions[] = "submitted";
        } else if ($newstatus === ReviewInfo::RS_APPROVED
                   && $oldstatus < ReviewInfo::RS_APPROVED) {
            $actions[] = "approved";
        } else if ($newstatus === ReviewInfo::RS_DELIVERED
                   && $oldstatus < ReviewInfo::RS_DELIVERED) {
            $actions[] = "delivered";
        } else if ($newstatus < ReviewInfo::RS_DELIVERED
                   && $oldstatus >= ReviewInfo::RS_DELIVERED) {
            $actions[] = "unsubmitted";
        } else if (empty($log_actions)) {
            $actions[] = "updated";
        }
        $atext = join(", ", $actions);

        $stext = $newstatus < ReviewInfo::RS_DELIVERED ? " draft" : "";

        $fields = [];
        foreach ($diffinfo->fields() as $f) {
            $t = $f->search_keyword();
            if (($fs = $f->unparse_search($rrow->fields[$f->order])) !== "") {
                $t .= ":{$fs}";
            }
            $fields[] = $t;
        }
        if (($wc = $this->rf->full_word_count($rrow)) !== null) {
            $fields[] = plural($wc, "word");
        }
        $ftext = empty($fields) ? "" : ": " . join(", ", $fields);

        return "Review {$rrow->reviewId} {$atext}{$stext}{$ftext}";
    }

    /** @param int $status
     * @param string $fmt
     * @param list<string> $info
     * @param null|'draft'|'approvable' $single */
    private function _confirm_message($status, $fmt, $info, $single = null) {
        $pids = [];
        foreach ($info as &$x) {
            if (preg_match('/\A(#?)(\d+)([A-Z]*)\z/', $x, $m)) {
                $url = $this->conf->hoturl("paper", ["p" => $m[2], "#" => $m[3] ? "r$m[2]$m[3]" : null]);
                $x = "<a href=\"{$url}\">{$x}</a>";
                $pids[] = $m[2];
            }
        }
        unset($x);
        if ($single === null && $this->text === null) {
            $single = "yes";
        }
        $t = $this->conf->_($fmt, $info, new FmtArg("single", $single));
        assert(str_starts_with($t, "<5>"));
        if (count($pids) > 1) {
            $pids = join("+", $pids);
            $t = "<5><span class=\"has-hotlist\" data-hotlist=\"p/s/{$pids}\">" . substr($t, 3) . "</span>";
        }
        $this->msg_at(null, $t, $status);
    }

    /** @return null|'approvable'|'draft' */
    private function _single_approval_state() {
        if ($this->text !== null || $this->single_approval < 0) {
            return null;
        } else if ($this->single_approval > 0) {
            return "approvable";
        } else {
            return "draft";
        }
    }

    function finish() {
        $confirm = false;
        if ($this->submitted) {
            $this->_confirm_message(MessageSet::SUCCESS, "<5>Submitted reviews {:list}", $this->submitted);
            $confirm = true;
        }
        if ($this->updated) {
            $this->_confirm_message(MessageSet::SUCCESS, "<5>Updated reviews {:list}", $this->updated);
            $confirm = true;
        }
        if ($this->approval_requested) {
            $this->_confirm_message(MessageSet::SUCCESS, "<5>Submitted reviews for approval {:list}", $this->approval_requested);
            $confirm = true;
        }
        if ($this->approved) {
            $this->_confirm_message(MessageSet::SUCCESS, "<5>Approved reviews {:list}", $this->approved);
            $confirm = true;
        }
        if ($this->saved_draft) {
            $this->_confirm_message(MessageSet::MARKED_NOTE, "<5>Saved draft reviews for submissions {:list}", $this->saved_draft, $this->_single_approval_state());
        }
        if ($this->author_notified) {
            $this->_confirm_message(MessageSet::MARKED_NOTE, "<5>Authors were notified about updated reviews {:list}", $this->author_notified);
        }
        $nunchanged = $this->unchanged ? count($this->unchanged) : 0;
        $nignoredBlank = $this->blank ? count($this->blank) : 0;
        if ($nunchanged + $nignoredBlank > 1
            || $this->text !== null
            || !$this->has_message()) {
            if ($this->unchanged) {
                $single = null;
                if ($this->unchanged === $this->unchanged_draft) {
                    $single = $this->_single_approval_state();
                }
                $this->_confirm_message(MessageSet::WARNING_NOTE, "<5>No changes to reviews {:list}", $this->unchanged, $single);
            }
            if ($this->blank) {
                $this->_confirm_message(MessageSet::WARNING_NOTE, "<5>Ignored blank reviews for {:list}", $this->blank);
            }
        }
        $this->finished = $confirm ? 2 : 1;
    }

    /** @return int */
    function summary_status() {
        $this->finished || $this->finish();
        if (!$this->has_message()) {
            return MessageSet::PLAIN;
        } else if ($this->has_error() || $this->has_problem_at("ready")) {
            return MessageSet::ERROR;
        } else if ($this->has_problem() || $this->finished === 1) {
            return MessageSet::WARNING;
        } else {
            return MessageSet::SUCCESS;
        }
    }

    function report() {
        $this->finished || $this->finish();
        if ($this->finished < 3) {
            $mis = $this->message_list();
            if ($this->text !== null && $this->has_problem()) {
                $errtype = $this->has_error() ? "errors" : "warnings";
                array_unshift($mis, new MessageItem(null, $this->conf->_("<0>There were {$errtype} while parsing the uploaded review file."), MessageSet::INFORM));
            }
            if (($status = $this->summary_status()) !== MessageSet::PLAIN) {
                $this->conf->feedback_msg($mis, new MessageItem(null, "", $status));
            }
            $this->finished = 3;
        }
    }

    function json_report() {
        $j = [];
        foreach (["submitted", "updated", "approval_requested", "saved_draft", "author_notified", "unchanged", "blank"] as $k) {
            if ($this->$k)
                $j[$k] = $this->$k;
        }
        return $j;
    }
}
