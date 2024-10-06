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
    private $can_unsubmit = false;
    /** @var bool */
    private $autosearch = true;

    /** @var array<string,mixed> */
    public $req;
    /** @var ?bool */
    public $req_json;

    /** @var ?int */
    public $reviewId;
    /** @var ?string */
    public $review_ordinal_id;

    /** @var ?string */
    private $text;
    /** @var ?int */
    private $textpos;
    /** @var ?string */
    private $filename;
    /** @var ?int */
    private $lineno;
    /** @var ?int */
    private $first_lineno;
    /** @var ?array<string,int> */
    private $field_lineno;
    /** @var ?int */
    private $garbage_lineno;

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
    private $accepted;
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
        $this->clear_req();
    }

    /** @param bool $x
     * @return $this */
    function set_notify($x) {
        $this->notify = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_can_unsubmit($x) {
        $this->can_unsubmit = $x;
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
        $this->textpos = 0;
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

    /** @return $this */
    function clear_req() {
        $this->req = [];
        return $this;
    }

    /** @return ?int */
    function req_pid() {
        $pid = $this->req["paperId"] ?? null;
        if (is_string($pid)) {
            $pid = stoi(trim($pid));
        }
        return $pid;
    }

    /** @param bool $x
     * @return $this */
    function set_req_override($x) {
        $this->req["override"] = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_req_ready($x) {
        $this->req["ready"] = $x;
        return $this;
    }

    /** @param false|'approved'|'submitted' $x
     * @return $this */
    function set_req_approval($x) {
        $this->req["approval"] = $x;
        return $this;
    }

    /** @return $this */
    function clear_req_vtag() {
        unset($this->req["if_vtag_match"]);
        return $this;
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
            if (($pid = $this->req_pid()) > 0) {
                $mi->landmark .= " (" . $this->conf->_("{submission} #{}", $pid) . ")";
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

    /** @return bool */
    function parse_text() {
        assert($this->text !== null && $this->finished === 0);
        $this->req_json = false;

        $this->first_lineno = $this->lineno + 1;
        $this->field_lineno = [];
        $this->garbage_lineno = null;

        $pos = $this->textpos;
        $len = strlen($this->text);

        $mode = 0;
        $nfields = 0;
        $field = null;
        $anyDirectives = 0;

        while ($pos !== $len) {
            $x = strpos($this->text, "\n", $pos);
            $epos = $x !== false ? $x + 1 : $len;
            $line = substr($this->text, $pos, $epos - $pos);

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
                } else if (preg_match('/\A==\+== (?:Paper|Submission) #?(\d+)/i', $line, $match)) {
                    if ($nfields > 0) {
                        break;
                    }
                    $this->req["paperId"] = intval($match[1]);
                    $this->req["blind"] = 1;
                    $this->first_lineno = $this->field_lineno["paperId"] = $this->lineno;
                } else if (preg_match('/\A==\+== Reviewer:\s*(.*?)\s*\z/', $line, $match)
                           && ($user = Text::split_name($match[1], true))
                           && $user[2]) {
                    $this->field_lineno["reviewerEmail"] = $this->lineno;
                    $this->req["reviewerFirst"] = $user[0];
                    $this->req["reviewerLast"] = $user[1];
                    $this->req["reviewerEmail"] = $user[2];
                } else if (preg_match('/\A==\+== (?:Paper|Submission) (Number|\#)\s*\z/i', $line)) {
                    if ($nfields > 0) {
                        break;
                    }
                    $field = "paperId";
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
                    while (substr($this->text, $epos, 6) === $linestart) {
                        $x = strpos($this->text, "\n", $epos + 6);
                        $epos2 = $x !== false ? $x + 1 : $len;
                        $xline = substr($this->text, $epos, $epos2 - $epos);
                        if (preg_match('/\A==[+*]==\s+(.*?)\s*\z/', $xline, $xmatch)) {
                            $match[1] .= " " . $xmatch[1];
                        }
                        $line .= $xline;
                        $epos = $epos2;
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

            $pos = $epos;
            ++$this->lineno;
        }

        $this->textpos = $pos;

        if ($nfields === 0 && $this->first_lineno === 1) {
            $this->rmsg(null, "<0>That didn’t appear to be a review form; I was not able to extract any information from it. Please check its formatting and try again.", self::ERROR);
        }

        if (isset($this->req["readiness"])) {
            $this->req["ready"] = strcasecmp(trim($this->req["readiness"]), "Ready") === 0;
        }
        if (isset($this->req["anonymity"])) {
            $this->req["blind"] = strcasecmp(trim($this->req["anonymity"]), "Open") !== 0;
        }
        return $nfields !== 0;
    }

    /** @param mixed $x
     * @return ?bool */
    static function parse_blind($x) {
        if (($v = friendly_boolean($x)) !== null) {
            return $v;
        } else if ($x === "blind" || $x === "anonymous") {
            return true;
        } else if ($x === "nonblind" || $x === "nonanonymous") {
            return false;
        } else {
            return null;
        }
    }

    /** @param mixed $x
     * @return ?bool */
    static function parse_ready($x) {
        if (($v = friendly_boolean($x)) !== null) {
            return $v;
        } else if ($x === "ready") {
            return true;
        } else if ($x === "unready" || $x === "draft") {
            return false;
        } else {
            return null;
        }
    }

    /** @param mixed $x
     * @return null|false|'approved'|'submitted' */
    static function parse_approval($x) {
        if (($v = friendly_boolean($x)) !== null) {
            return $v ? "approved" : false;
        } else if ($x === "approved" || $x === "submitted") {
            return $x;
        } else {
            return null;
        }
    }

    /** @return bool */
    function parse_json($j) {
        assert($this->text === null && $this->finished === 0);
        $this->req_json = true;

        if (!is_object($j) && !is_array($j)) {
            return false;
        }
        // XXX validate more
        // XXX status
        foreach ($j as $k => $v) {
            if ($k === "object") {
                if (($v ?? "review") !== "review") {
                    $this->rmsg("object", "<0>JSON does not represent a review", self::ERROR);
                    return false;
                }
            } else if ($k === "round") {
                if ($v === null || is_string($v)) {
                    $this->req["round"] = $v;
                }
            } else if ($k === "blind") {
                if (($b = self::parse_blind($v)) !== null) {
                    $this->req["blind"] = $b;
                }
            } else if ($k === "submitted" || $k === "ready") {
                if (($b = self::parse_ready($v)) !== null) {
                    $this->req["ready"] = $b;
                }
            } else if ($k === "approval") {
                if (($b = self::parse_approval($v)) !== null) {
                    $this->req["approval"] = $b;
                }
            } else if ($k === "draft") {
                if (is_bool($v)) {
                    $this->req["ready"] = !$v;
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
            } else if ($k === "given_name" || $k === "first" || $k === "firstName") {
                if (is_string($v)) {
                    $this->req["reviewerFirst"] = simplify_whitespace($v);
                }
            } else if ($k === "family_name" || $k === "last" || $k === "lastName") {
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
            $this->req["ready"] = true;
        }
        return !empty($this->req);
    }

    private const QREQ_IGNORE = 1;
    private const QREQ_HAS = 2;
    private const QREQ_BOOL = 3;
    private const QREQ_BLIND = 4;
    private const QREQ_READINESS = 5;
    private const QREQ_APPROVAL = 6;
    private const QREQ_UPDATE = 7;
    private const QREQ_READY = 8;
    private const QREQ_UNREADY = 9;
    private const QREQ_APPROVED = 10;
    private const QREQ_APPROVESUBMIT = 11;
    static private $qreq_special = [
        "r" => self::QREQ_IGNORE, "m" => self::QREQ_IGNORE,
        "post" => self::QREQ_IGNORE, "vtag" => self::QREQ_IGNORE,
        "forceShow" => self::QREQ_IGNORE, "default" => self::QREQ_IGNORE,
        "deletereview" => self::QREQ_IGNORE,

        "update" => self::QREQ_UPDATE, "savedraft" => self::QREQ_UNREADY,
        "submitreview" => self::QREQ_READY, "unsubmitreview" => self::QREQ_UNREADY,
        "approvesubreview" => self::QREQ_APPROVED,
        "approvesubmit" => self::QREQ_APPROVESUBMIT,
        "adoptreview" => self::QREQ_UPDATE, "adoptsubmit" => self::QREQ_UPDATE,
        "adoptdraft" => self::QREQ_UPDATE,

        "has_override" => self::QREQ_HAS, "override" => self::QREQ_BOOL,
        "has_blind" => self::QREQ_HAS, "blind" => self::QREQ_BLIND,
        "has_ready" => self::QREQ_HAS, "ready" => self::QREQ_READINESS,
        "approval" => self::QREQ_APPROVAL
    ];

    /** @return bool */
    function parse_qreq(Qrequest $qreq) {
        assert($this->text === null && $this->finished === 0);
        $this->req_json = false;

        $rf = $this->conf->review_form();
        $hasreqs = [];
        foreach ($qreq as $k => $v) {
            if (!is_scalar($v)) {
                /* skip */;
            } else if (($special = self::$qreq_special[$k] ?? 0) !== 0) {
                if ($special === self::QREQ_HAS) {
                    $this->req[substr($k, 4)] = $this->req[substr($k, 4)] ?? false;
                } else if ($special === self::QREQ_BOOL) {
                    if (($b = friendly_boolean($v)) !== null) {
                        $this->req[$k] = $b;
                    }
                } else if ($special === self::QREQ_BLIND) {
                    if (($b = self::parse_blind($v)) !== null) {
                        $this->req["blind"] = $b;
                    }
                } else if ($special === self::QREQ_READINESS) {
                    if (($b = self::parse_ready($v)) !== null) {
                        $this->req["ready"] = $b;
                    }
                } else if ($special === self::QREQ_UNREADY) {
                    $this->req["ready"] = false;
                } else if ($special === self::QREQ_READY) {
                    $this->req["ready"] = true;
                } else if ($special === self::QREQ_APPROVAL) {
                    if (($b = self::parse_approval($v)) !== null) {
                        $this->req["approval"] = $b;
                    }
                } else if ($special === self::QREQ_APPROVED) {
                    $this->req["approval"] = "approved";
                } else if ($special === self::QREQ_APPROVESUBMIT) {
                    $this->req["approval"] = "submitted";
                } else if ($special === self::QREQ_UPDATE) {
                    $this->req["ready"] = $this->req["ready"] ?? null;
                }
            } else if ($k === "p") {
                if (($pid = stoi($v) ?? -1) > 0) {
                    $this->req["paperId"] = $pid;
                }
            } else if ($k === "edit_version") {
                $this->req[$k] = stoi($v) ?? -1;
            } else if ($k === "if_vtag_match") {
                if (ctype_digit($v)) {
                    $this->req[$k] = intval($v);
                }
            } else if (str_starts_with($k, "has_")) {
                $hasreqs[] = substr($k, 4);
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
        return !empty($this->req);
    }

    /** @param ?string $msg */
    private function reviewer_error($msg) {
        $msg = $msg ?? $this->conf->_("<0>Can’t edit a review for {}", $this->req["reviewerEmail"]);
        $this->rmsg("reviewerEmail", $msg, self::ERROR);
    }

    /** @param ReviewInfo $rrow
     * @return bool */
    function check_vtag(ReviewInfo $rrow) {
        if (!isset($this->req["if_vtag_match"])
            || $this->req["if_vtag_match"] === $rrow->reviewTime) {
            return true;
        }
        $this->rmsg("if_vtag_match", "<5><strong>Edit conflict</strong>: The review changed since you last loaded this page", self::ERROR);
        $this->rmsg("if_vtag_match", "<0>Your changes were not saved, but you can check the form and save again.", self::INFORM);
        return false;
    }

    /** @return bool */
    function check_and_save(Contact $user, ?PaperInfo $prow, ?ReviewInfo $rrow = null) {
        assert(!$rrow || $rrow->paperId === $prow->paperId);
        $this->reviewId = $this->review_ordinal_id = null;

        // look up paper
        if (!$prow) {
            if (($pid = $this->req_pid()) === null) {
                $this->rmsg("paperId", $this->conf->_("<0>{Submission} ID required"), self::ERROR);
                $this->rmsg("paperId", $this->conf->_("<0>Enter the {submission} number in the right place and try again."), self::INFORM);
                return false;
            }
            $prow = $user->paper_by_id($pid);
            if (($whynot = $user->perm_view_paper($prow, false, $pid))) {
                $whynot->append_to($this, "paperId", self::ERROR);
                return false;
            }
        }
        $this->req["paperId"] = $this->req_pid() ?? $prow->paperId;
        if ($this->req["paperId"] !== $prow->paperId) {
            $this->rmsg("paperId", $this->conf->_("<0>{Submission} mismatch: expected #{}, form is for #{}", $prow->paperId, $this->req["paperId"]), self::ERROR);
            $this->rmsg("paperId", $this->conf->_("<0>It looks like you tried to upload a form intended for a different {submission}."), self::INFORM);
            return false;
        }

        // look up reviewer
        $reviewer = $user;
        if ($rrow) {
            if ($rrow->contactId !== $user->contactId) {
                $reviewer = $this->conf->user_by_id($rrow->contactId, USER_SLICE);
            }
        } else if (isset($this->req["reviewerEmail"])
                   && strcasecmp($this->req["reviewerEmail"], $user->email) !== 0) {
            // XXX create reviewer?
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
            if (($whynot = $user->perm_create_review($prow, $reviewer, $round))) {
                $whynot->append_to($this, null, self::ERROR);
                return false;
            }
            $new_rrid = $user->assign_review($prow->paperId, $reviewer, $reviewer->isPC ? REVIEW_PC : REVIEW_EXTERNAL, [
                "selfassign" => $reviewer === $user, "round_number" => $round
            ]);
            if (!$new_rrid) {
                $this->rmsg(null, "<0>Internal error while creating review", self::ERROR);
                return false;
            }
            $rrow = $prow->fresh_review_by_id($new_rrid);
        }

        // actually check review and save
        $ok = $this->_apply_req($user, $prow, $rrow, $new_rrid);
        if (!$ok) {
            $rrow->abort_prop();
            if ($new_rrid) {
                $user->assign_review($prow->paperId, $reviewer, 0);
            }
        }
        return $ok;
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
    private function _check_reviewer(ReviewInfo $rrow, $reqemail) {
        $reviewer = $rrow->reviewer();
        if (strcasecmp($reviewer->email, $reqemail) === 0) {
            return true;
        }
        // allow different email but same name
        $reqfirst = $this->req["reviewerFirst"] ?? "";
        $reqlast = $this->req["reviewerLast"] ?? "";
        $reqname = Text::name($reqfirst, $reqlast, "", 0);
        $revname = Text::nameo($reviewer, 0);
        if ($reqname !== "" && strcasecmp($reqname, $revname) === 0) {
            return true;
        }
        // otherwise complain
        $this->rmsg("reviewerEmail",
            $this->conf->_("<0>Reviewer conflict: review is for {}, but uploaded form is for {}", Text::nameo($reviewer, NAME_EB), Text::name($reqfirst, $reqlast, $reqemail, NAME_EB)),
            self::ERROR);
        if ($this->text !== null) {
            $this->rmsg("reviewerEmail",
                $this->conf->_("<5>To upload the form anyway, remove its ‘<code class=\"nw\">==+== Reviewer</code>’ section."),
                self::INFORM);
        }
        return false;
    }

    /** @param Contact $user
     * @param PaperInfo $prow
     * @param ReviewInfo $rrow
     * @param int $view_score
     * @param bool $allow_new_submit
     * @param bool $approvable
     * @return int */
    private function _compute_new_status($user, $prow, $rrow, $view_score,
                                         $allow_new_submit, $approvable) {
        $oldstatus = $rrow->reviewStatus;
        $minstatus = ReviewInfo::RS_EMPTY;
        if ($oldstatus >= ReviewInfo::RS_DELIVERED
            && (!$this->can_unsubmit
                || !$user->can_administer($prow))) {
            $minstatus = $oldstatus;
        } else if ($view_score > VIEWSCORE_EMPTY
                   || $rrow->reviewModified > 1) {
            $minstatus = ReviewInfo::RS_DRAFTED;
        } else if ($user->is_my_review($rrow)
                   || $oldstatus >= ReviewInfo::RS_ACKNOWLEDGED) { // XXX decline via this API?
            $minstatus = ReviewInfo::RS_ACKNOWLEDGED;
        }
        $ready = $this->req["ready"] ?? $oldstatus >= ReviewInfo::RS_DELIVERED;
        if (!$ready) {
            return $minstatus;
        }

        $maxstatus = ReviewInfo::RS_COMPLETED;
        if (!$allow_new_submit && $oldstatus < ReviewInfo::RS_DELIVERED) {
            $maxstatus = ReviewInfo::RS_DRAFTED;
        } else if ($rrow->subject_to_approval()) {
            $approval = $approvable ? $this->req["approval"] ?? null : null;
            if ($approval === "approved") {
                $maxstatus = ReviewInfo::RS_APPROVED;
            } else if ($approval !== "submitted") {
                $maxstatus = ReviewInfo::RS_DELIVERED;
            }
        }
        return max($maxstatus, $minstatus);
    }

    /** @return bool */
    private function _apply_req(Contact $user, PaperInfo $prow, ReviewInfo $rrow, $new_rrid) {
        assert($prow->paperId === $this->req["paperId"] && $rrow->paperId === $prow->paperId);
        $admin = $user->allow_administer($prow);
        $usedReviewToken = $user->active_review_token_for($prow, $rrow);
        $approvable = $user->can_approve_review($prow, $rrow);

        $oldstatus = $rrow->reviewStatus;
        $old_nonempty_view_score = $this->rf->nonempty_view_score($rrow);
        $rflags = $rrow->rflags;
        '@phan-var-force int $rflags';

        // can only edit reviews you own or administer
        if (!$user->is_owned_review($rrow)
            && !$user->can_administer($prow)) {
            $this->rmsg(null, "<0>You don’t have permission to edit this review", self::ERROR);
            return false;
        }

        // reviewer must match if provided
        if (isset($this->req["reviewerEmail"])
            && !$this->_check_reviewer($rrow, $this->req["reviewerEmail"])) {
            return false;
        }

        // version tag must match if provided
        if (!$this->check_vtag($rrow)) {
            return false;
        }

        // correct review type if necessary
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

        // process review fields
        $before_msgcount = $this->message_count();
        $view_score = VIEWSCORE_EMPTY;
        $any_fval = $any_fdiff = false;
        $allow_new_submit = true;
        $fmissing = [];
        $wc = 0;

        foreach ($this->rf->all_fields() as $f) {
            $exists = $f->test_exists($rrow);
            list($old_fval, $fval) = $this->fvalues($f, $rrow);
            if (!$exists && $old_fval === null) {
                continue;
            }
            if ($fval === false) {
                $this->rmsg($f->short_id, $this->conf->_("<0>{} cannot be ‘{}’", $f->name, UnicodeHelper::utf8_abbreviate(trim($this->req[$f->short_id]), 100)), self::ERROR);
                $fval = $old_fval;
                $allow_new_submit = false;
            }
            if ($f->value_present($fval)) {
                $any_fval = $any_fval || isset($this->req[$f->short_id]);
            } else if ($f->required
                       && $f->view_score >= VIEWSCORE_REVIEWERONLY) {
                // XXX required field editable only by administrator?
                $fval = $old_fval;
                $allow_new_submit = false;
                $fmissing[] = $f;
            }
            $fdiff = $fval !== $old_fval
                && (!is_string($fval) || $fval !== cleannl($old_fval ?? ""));
            if ($fdiff || !$rrow->reviewId) {
                $rrow->set_fval_prop($f, $fval, $fdiff);
            }
            if ($exists) {
                $any_fdiff = $any_fdiff || $fdiff;
                if ($f->include_word_count()) {
                    $wc += count_words($fval ?? "");
                }
                if ($view_score < $f->view_score && $fval !== null) {
                    $view_score = $f->view_score;
                }
            }
        }

        // blank uploaded forms are ignored
        if (!$any_fval && $this->text !== null) {
            $this->blank[] = "#{$prow->paperId}";
            return false;
        }

        // check editing allowed
        if ($any_fdiff || !$approvable) {
            if (($whynot = $user->perm_edit_review($prow, $rrow, Contact::EDIT_REVIEW_SUBMIT))) {
                $this->clear_messages_since($before_msgcount);
                $whynot->append_to($this, null, self::ERROR);
                return false;
            } else if ($admin
                       && !($this->req["override"] ?? false)
                       && !$this->conf->time_review($rrow->reviewRound, $rrow->reviewType, true)) {
                $this->clear_messages_since($before_msgcount);
                $this->rmsg(null, "<5>The <a href=\"" . $this->conf->hoturl("deadlines") . "\">deadline</a> for editing this review has passed", self::ERROR);
                $this->rmsg(null, "<0>Select “Override deadlines” and try again if you need to override the deadline.", self::INFORM);
                return false;
            }
        }

        // record change to review content
        if ($any_fdiff && $old_nonempty_view_score > VIEWSCORE_EMPTY) {
            $rflags |= ReviewInfo::RF_CONTENT_EDITED;
        }

        // upload must include all online edits
        if ($any_fdiff
            && $this->text !== null
            && $rrow->reviewId
            && $rrow->reviewEditVersion > ($this->req["edit_version"] ?? 0)) {
            $this->clear_messages_since($before_msgcount);
            $this->rmsg($this->first_lineno, "<5><strong>Edit conflict</strong>: This review has been edited online since you downloaded this offline form, so for safety I am not replacing the online version.", self::ERROR);
            $this->rmsg($this->first_lineno, "<5>To override your online edits, add a line “<code class=\"nw\">==+== Version {$rrow->reviewEditVersion}</code>” to your offline review form for paper #{$prow->paperId} and upload the form again.", self::INFORM);
            return false;
        }

        // warn about missing fields
        if ($fmissing) {
            $want_ready = $this->req["ready"] ?? $oldstatus >= ReviewInfo::RS_DELIVERED;
            $status = $want_ready ? self::ERROR : self::WARNING;
            foreach ($fmissing as $f) {
                $this->rmsg($f->short_id, $this->conf->_("<0>{}: Entry required", $f->name), $status);
            }
            if ($status === self::ERROR) {
                $this->rmsg("ready", $this->conf->_("<0>The review can’t be submitted until entries are provided for all required fields."), self::ERROR);
            }
        }

        // word count, edit version
        if ($any_fdiff) {
            $rrow->set_prop("reviewWordCount", $wc);
            assert(is_int($this->req["edit_version"] ?? 0)); // XXX sanity check
            if ($rrow->reviewId
                && ($this->req["edit_version"] ?? 0) > ($rrow->reviewEditVersion ?? 0)) {
                $rrow->set_prop("reviewEditVersion", $this->req["edit_version"]);
            }
        }

        // compute new status
        if ($view_score === VIEWSCORE_EMPTY) {
            // empty review: do not submit, adopt, or deliver
            if ($user->is_my_review($rrow)) {
                $newstatus = max($oldstatus, ReviewInfo::RS_ACKNOWLEDGED);
            } else {
                $newstatus = $oldstatus;
            }
        } else if (!($this->req["ready"] ?? $oldstatus >= ReviewInfo::RS_DELIVERED)
                   || ($oldstatus < ReviewInfo::RS_DELIVERED && !$allow_new_submit)) {
            // unready nonempty review is at least drafted
            if ($this->can_unsubmit
                && $user->can_administer($prow)) {
                $newstatus = ReviewInfo::RS_DRAFTED;
            } else {
                $newstatus = max($oldstatus, ReviewInfo::RS_DRAFTED);
            }
        } else if ($oldstatus >= ReviewInfo::RS_COMPLETED) {
            $newstatus = $oldstatus;
        } else if ($rrow->subject_to_approval()) {
            $approval = $user->can_approve_review($prow, $rrow) ? $this->req["approval"] ?? false : false;
            if (!$approval) {
                $newstatus = max($oldstatus, ReviewInfo::RS_DELIVERED);
            } else if ($approval === "approved") {
                $newstatus = ReviewInfo::RS_APPROVED;
            } else {
                $newstatus = ReviewInfo::RS_COMPLETED;
            }
        } else {
            $newstatus = ReviewInfo::RS_COMPLETED;
        }

        // new status #2
        $newstatus2 = $this->_compute_new_status($user, $prow, $rrow, $view_score, $allow_new_submit, $approvable);
        assert($newstatus === $newstatus2);

        // get the current time
        $now = max(time(), $rrow->reviewModified + 1);

        // set status-related fields
        if ($newstatus === ReviewInfo::RS_ACKNOWLEDGED
            && $rrow->reviewModified <= 0) {
            $rrow->set_prop("reviewModified", 1);
            $rflags |= ReviewInfo::RF_LIVE | ReviewInfo::RF_ACKNOWLEDGED;
        } else if ($newstatus >= ReviewInfo::RS_DRAFTED
                   && ($any_fdiff || $oldstatus <= ReviewInfo::RS_ACKNOWLEDGED)) {
            $rrow->set_prop("reviewModified", $now);
            $rflags |= ReviewInfo::RF_LIVE | ReviewInfo::RF_ACKNOWLEDGED | ReviewInfo::RF_DRAFTED;
        }
        if ($newstatus === ReviewInfo::RS_APPROVED) {
            if ($rrow->timeApprovalRequested >= 0) {
                $rrow->set_prop("timeApprovalRequested", -$now);
            }
            $rflags |= ReviewInfo::RF_DELIVERED | ReviewInfo::RF_APPROVED;
        } else if ($newstatus === ReviewInfo::RS_DELIVERED) {
            if ($rrow->timeApprovalRequested <= 0) {
                $rrow->set_prop("timeApprovalRequested", $now);
            }
            $rflags = ($rflags | ReviewInfo::RF_DELIVERED) & ~ReviewInfo::RF_APPROVED;
        } else if ($newstatus < ReviewInfo::RS_DELIVERED) {
            if ($rrow->timeApprovalRequested !== 0) {
                $rrow->set_prop("timeApprovalRequested", 0);
            }
            $rflags &= ~(ReviewInfo::RF_DELIVERED | ReviewInfo::RF_APPROVED);
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
        } else if ($oldstatus >= ReviewInfo::RS_APPROVED) {
            if ($rrow->reviewType === REVIEW_SECONDARY) {
                $rns = $this->conf->compute_secondary_review_needs_submit($rrow->paperId, $rrow->contactId);
                $rrow->set_prop("reviewNeedsSubmit", $rns ?? 1);
            } else {
                $rrow->set_prop("reviewNeedsSubmit", 1);
            }
        }
        if ($newstatus !== $oldstatus) {
            // Must mark view score to ensure database is modified
            $rrow->mark_prop_view_score(max($old_nonempty_view_score, VIEWSCORE_REVIEWERONLY));
        }

        // anonymity
        $reviewBlind = $this->conf->is_review_blind(!!($this->req["blind"] ?? null));
        if ($reviewBlind != $rrow->reviewBlind) {
            $rrow->set_prop("reviewBlind", $reviewBlind ? 1 : 0);
            $rrow->mark_prop_view_score(VIEWSCORE_REVIEWERONLY);
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
        if ($diffinfo->is_viewable()) {
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
        if ($rrow->requestedBy > 0
            && $oldstatus < ReviewInfo::RS_ACKNOWLEDGED
            && $newstatus >= ReviewInfo::RS_ACKNOWLEDGED
            && $newstatus < ReviewInfo::RS_DELIVERED) {
            $rrow->set_prop("timeRequestNotified", $now);
            $diffinfo->notify_requester = true;
        }

        // viewing fields
        if (($rflags & (ReviewInfo::RF_AUSEEN | ReviewInfo::RF_AUSEEN_PREVIOUS)) !== 0) {
            $rflags |= ReviewInfo::RF_AUSEEN_PREVIOUS;
        }
        if ($diffinfo->notify_author) {
            $rflags |= ReviewInfo::RF_AUSEEN;
        } else {
            $rflags &= ~ReviewInfo::RF_AUSEEN;
        }
        if ($newstatus >= ReviewInfo::RS_COMPLETED
            && $diffinfo->view_score() >= $author_view_score
            && $prow->can_author_view_submitted_review()) {
            $rflags |= ReviewInfo::RF_AUSEEN_LIVE;
        } else {
            $rflags &= ~ReviewInfo::RF_AUSEEN_LIVE;
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
            $result = $this->conf->qe_raw("lock tables PaperReview write, PaperReviewHistory write");
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
        $result = $rrow->save_prop();

        // unlock tables even if problem
        if ($locked) {
            $this->conf->qe_raw("unlock tables");
        }

        if ($result < 0) {
            if ($result === ReviewInfo::SAVE_PROP_CONFLICT) {
                $this->rmsg(null, "<0>Review was edited concurrently, please try again", self::ERROR);
            }
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
            $user->log_activity_for($rrow->contactId, $this->_log_message($rrow, $oldstatus, $newstatus, $diffinfo), $prow);
        }

        // if external, forgive the requester from finishing their review
        if ($rrow->reviewType < REVIEW_SECONDARY
            && $newstatus !== $oldstatus
            && $rrow->requestedBy > 0
            && $prow->review_type($rrow->requestedBy) === REVIEW_SECONDARY) {
            if ($newstatus >= ReviewInfo::RS_DELIVERED
                && $oldstatus < ReviewInfo::RS_DELIVERED) {
                $delta = 2;
            } else if ($oldstatus >= ReviewInfo::RS_DELIVERED
                       && $newstatus < ReviewInfo::RS_DELIVERED) {
                $delta = -1;
            } else if ($newstatus >= ReviewInfo::RS_ACKNOWLEDGED
                       && $oldstatus === ReviewInfo::RS_EMPTY) {
                $delta = 0;
            } else {
                $delta = null;
            }
            if ($delta !== null) {
                $this->conf->update_review_delegation($rrow->paperId, $rrow->requestedBy, $delta);
                $prow->invalidate_reviews();
            }
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
            $this->_notify($prow, $rrow, $diffinfo, $newstatus, $oldstatus, $reviewer, $user);
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
            } else if ($newstatus >= ReviewInfo::RS_DRAFTED) {
                $this->saved_draft[] = $what;
                $this->single_approval = +$rrow->timeApprovalRequested;
            } else {
                $this->accepted[] = $what;
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

    /** @param int $newstatus
     * @param int $oldstatus */
    private function _notify(PaperInfo $prow, ReviewInfo $rrow,
                             ReviewDiffInfo $diffinfo,
                             $newstatus, $oldstatus,
                             Contact $reviewer, Contact $user) {
        assert($this->notify);
        $info = [
            "prow" => $prow,
            "rrow" => $rrow,
            "reviewer_contact" => $reviewer,
            "combination_type" => 1
        ];
        if ($newstatus >= ReviewInfo::RS_COMPLETED
            && ($diffinfo->notify || $diffinfo->notify_author)) {
            if ($oldstatus < ReviewInfo::RS_COMPLETED) {
                $tmpl = "@reviewsubmit";
            } else {
                $tmpl = "@reviewupdate";
            }
            $always_combine = false;
            $diff_view_score = $diffinfo->view_score();
        } else if ($newstatus >= ReviewInfo::RS_DELIVERED
                   && $newstatus < ReviewInfo::RS_COMPLETED
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
        } else if ($newstatus >= ReviewInfo::RS_ACKNOWLEDGED
                   && $oldstatus < ReviewInfo::RS_ACKNOWLEDGED) {
            if ($rrow->requestedBy > 0
                && $rrow->requestedBy !== $rrow->contactId
                && $rrow->requestedBy !== $user->contactId
                && $rrow->reviewType <= REVIEW_PC
                && ($requser = $user->conf->user_by_id($rrow->requestedBy))) {
                HotCRPMailer::send_to($requser, "@acceptreviewrequest", [
                    "prow" => $prow, "reviewer_contact" => $reviewer
                ]);
            }
            return;
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
            // if not notifying authors, skip authors
            if (!$diffinfo->notify_author
                && $prow->has_author($minic)) {
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

    /** @param ReviewInfo $rrow
     * @param int $oldstatus
     * @param int $newstatus
     * @param ReviewDiffInfo $diffinfo */
    private function _log_message($rrow, $oldstatus, $newstatus, $diffinfo) {
        $actions = [];
        if ($diffinfo->fields()) {
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
        } else if ($newstatus === ReviewInfo::RS_ACKNOWLEDGED
                   && $oldstatus < ReviewInfo::RS_ACKNOWLEDGED) {
            $actions[] = "accepted";
        } else if (empty($log_actions)) {
            $actions[] = "updated";
        }
        $atext = join(", ", $actions);

        $stext = $newstatus === ReviewInfo::RS_DRAFTED ? " draft" : "";

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
        if ($this->accepted) {
            $this->_confirm_message(MessageSet::SUCCESS, "<5>Accepted review requests {:list}", $this->accepted);
            $confirm = true;
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
        foreach (["submitted", "updated", "approval_requested", "saved_draft", "accepted", "author_notified", "unchanged", "blank"] as $k) {
            if ($this->$k)
                $j[$k] = $this->$k;
        }
        return $j;
    }
}
