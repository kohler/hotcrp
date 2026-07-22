<?php
// reviewvalues.php -- HotCRP parsed review data
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ReviewValues extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ReviewForm
     * @readonly */
    public $rf;
    /** @var Contact
     * @readonly */
    public $user;

    /** @var bool */
    private $notify = true;
    /** @var bool */
    private $create_users = false;
    /** @var bool */
    private $disable_users = false;
    /** @var bool */
    private $can_unsubmit = false;
    /** @var bool */
    private $autosearch = true;
    /** @var ?Contact */
    private $reviewer;
    /** @var bool */
    private $require_reviewer = false;

    // current request
    /** @var array<string,mixed> */
    public $req;
    /** @var ?bool */
    public $req_json;
    /** @var ?int */
    public $reviewId;
    /** @var ?string */
    public $review_ordinal_id;

    // state staged by prepare_save for current request
    /** @var int */
    private $_save_status = 0;
    /** @var ?ReviewInfo */
    private $stage_rrow;
    /** @var ?Contact */
    private $stage_user;
    /** @var int */
    private $stage_oldstatus;
    /** @var int */
    private $stage_newstatus;

    // `_save_status` bits
    const SSF_PREPARED = 1;  // prepare_save staged a save awaiting execute/abort
    const SSF_LOCKED = 2;    // _apply_req is holding PaperReview/History write locks

    // textual review form
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

    // summary information about all reviews parsed in this file
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

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->rf = $this->conf->review_form();
        $this->user = $user;
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
    function set_create_users($x) {
        $this->create_users = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_disable_users($x) {
        $this->disable_users = $x;
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

    /** Set the reviewer for requests that do not name one. A request that names
     * a reviewer of its own (a JSON `reviewer_email`, a form’s `==+== Reviewer`
     * section) overrides this; a request that resolves to an existing review by
     * someone else conflicts with it.
     * @param ?Contact $u
     * @return $this */
    function set_reviewer($u) {
        $this->reviewer = $u;
        return $this;
    }

    /** If true, require each form to explicitly list its reviewer.
     * @param bool $x
     * @return $this */
    function set_require_reviewer($x) {
        $this->require_reviewer = $x;
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
     * @return MessageItem
     * @deprecated */
    function rmsg($field, $msg, $status) {
        return $this->rvmsg($status, $field, $msg);
    }

    /** @param int $status
     * @param int|string $field
     * @param string $msg
     * @return MessageItem */
    function rvmsg($status, $field, $msg) {
        if (is_int($field)) {
            $lineno = $field;
            $field = null;
        } else if ($field) {
            $lineno = $this->field_lineno[$field] ?? $this->lineno;
        } else {
            $lineno = $this->lineno;
        }
        $mi = $this->append_item(new MessageItem($status, $field, $msg));
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
            $this->rvmsg(self::WARNING, $this->garbage_lineno, "<0>Review form appears to begin with garbage; ignoring it.");
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
                    $this->rvmsg(self::ERROR, "confid", "<0>Ignoring review form, which appears to be for a different conference.");
                    $this->rvmsg(self::INFORM, "confid", "<5>(If this message is in error, replace the line that reads “<code>" . htmlspecialchars(rtrim($line)) . "</code>” with “<code>==+== " . htmlspecialchars($this->conf->short_name) . " " . $m[2] . "</code>” and upload again.)");
                    return false;
                } else if (preg_match('/\A==\+== Begin Review/i', $line)) {
                    if ($nfields > 0) {
                        break;
                    }
                } else if (preg_match('/\A==\+== (?:Paper|Submission) \#?(\d+)/i', $line, $match)) {
                    if ($nfields > 0) {
                        break;
                    } else if (($pid = stoi($match[1])) === null || $pid === 0) {
                        $this->rvmsg(self::ERROR, "paperId", "<0>Invalid submission ID");
                    } else {
                        $this->req["paperId"] = $pid;
                        $this->req["blind"] = 1;
                        $this->first_lineno = $this->field_lineno["paperId"] = $this->lineno;
                    }
                } else if (preg_match('/\A==\+== Reviewer:\s*(.*?)\s*\z/', $line, $match)
                           && ($un = Text::split_name($match[1], true))
                           && $un[2]) {
                    $this->field_lineno["reviewerEmail"] = $this->lineno;
                    $this->req["reviewerFirst"] = $un[0];
                    $this->req["reviewerLast"] = $un[1];
                    $this->req["reviewerEmail"] = $un[2];
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
                } else if (preg_match('/\A==\+== Version\s*(\d+)\s*\z/i', $line, $match)
                           && ($ev = stoi($match[1])) !== null) {
                    if (($this->req["edit_version"] ?? 0) < $ev) {
                        $this->req["edit_version"] = $ev;
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
                        $this->rvmsg(self::ERROR, null, "<0>Review field ‘{$match[1]}’ is not used for {$this->conf->short_name} reviews. Ignoring this section.");
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
            $this->rvmsg(self::ERROR, null, "<0>That didn’t appear to be a review form; I was not able to extract any information from it. Please check its formatting and try again.");
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
        }
        return null;
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
        }
        return null;
    }

    /** @param mixed $x
     * @return null|false|'approved'|'submitted' */
    static function parse_approval($x) {
        if (($v = friendly_boolean($x)) !== null) {
            return $v ? "approved" : false;
        } else if ($x === "approved" || $x === "submitted") {
            return $x;
        }
        return null;
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
                    $this->rvmsg(self::ERROR, $k, "<0>JSON does not represent a review");
                    return false;
                }
            } else if ($k === "pid") {
                if (is_int($v) && $v > 0) {
                    $this->req["paperId"] = $v;
                }
            } else if ($k === "rid") {
                if (is_int($v) || is_string($v)) {
                    $this->req["rid"] = $v;
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
            } else if ($k === "review_type" || $k === "reviewType") {
                if (is_int($v) || is_string($v)) {
                    $this->req["reviewType"] = $v;
                }
            } else if ($k === "edit_version") {
                if (is_int($v)) {
                    $this->req["edit_version"] = $v;
                }
            } else if ($k === "if_vtag_match") {
                if (is_int($v)) {
                    $this->req["if_vtag_match"] = $v;
                }
            } else if ($k === "if_unmodified_since") {
                if (is_int($v) || is_float($v)) {
                    $this->req["if_unmodified_since"] = $v;
                } else if (($t = $this->conf->parse_time($v, Conf::$now)) !== false
                           && $t >= 0) {
                    $this->req["if_unmodified_since"] = $t;
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
        "deletereview" => self::QREQ_IGNORE, "actas" => self::QREQ_IGNORE,
        "u" => self::QREQ_IGNORE, "t" => self::QREQ_IGNORE,
        "reviewer" => self::QREQ_IGNORE,

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
            } else if ($k === "review_type" || $k === "reviewType") {
                $this->req["reviewType"] = $v;
            } else if ($k === "edit_version") {
                $this->req[$k] = stoi($v) ?? -1;
            } else if ($k === "if_vtag_match" || $k === "if_unmodified_since") {
                if (ctype_digit($v) && ($t = stoi($v)) !== null) {
                    $this->req[$k] = $t;
                }
            } else if (str_starts_with($k, "has_")) {
                $hasreqs[] = substr($k, 4);
            } else if (($f = $rf->field($k) ?? $this->conf->find_review_field($k))
                       && !isset($this->req[$f->short_id])) {
                $this->req[$f->short_id] = $f->extract_qreq($qreq, $k);
            }
        }
        foreach ($hasreqs as $k) {
            if (($f = $rf->field($k) ?? $this->conf->find_review_field($k))
                && !isset($this->req[$f->short_id])) {
                $this->req[$f->short_id] = $f->extract_qreq_has($qreq);
            }
        }
        return !empty($this->req);
    }

    /** @param ?string $msg */
    private function reviewer_error($msg) {
        $msg = $msg ?? $this->conf->_("<0>Can’t edit a review for {}", $this->req["reviewerEmail"]);
        $this->rvmsg(self::ERROR, "reviewerEmail", $msg);
    }

    /** Resolve the requested `review_type`, if any.
     * @return int|false|null  null if none requested, false if invalid */
    private function requested_review_type() {
        if (!isset($this->req["reviewType"])) {
            return null;
        }
        $v = $this->req["reviewType"];
        if (is_int($v)) {
            return $v >= REVIEW_EXTERNAL && $v <= REVIEW_META ? $v : false;
        }
        $rt = ReviewInfo::parse_type($v, true);
        return is_int($rt) && $rt >= REVIEW_EXTERNAL ? $rt : false;
    }

    /** @param ReviewInfo $rrow
     * @return bool */
    function check_vtag(ReviewInfo $rrow) {
        // compare against the pre-save values: this may run after staging has
        // advanced reviewModified (so a conflict still reports the attempted diff)
        if (isset($this->req["if_vtag_match"])
            && $this->req["if_vtag_match"] !== $rrow->base_prop("reviewTime")) {
            $pk = "if_vtag_match";
        } else if (isset($this->req["if_unmodified_since"])
                   && $this->req["if_unmodified_since"] < $rrow->base_prop("reviewModified")) {
            $pk = "if_unmodified_since";
        } else {
            return true;
        }
        $this->rvmsg(self::ERROR, $pk, "<5><strong>Edit conflict</strong>: The review was edited concurrently");
        $this->rvmsg(self::INFORM, $pk, "<0>Your changes were not saved, but you can check the form and save again.");
        return false;
    }

    /** @return bool */
    function check_and_save(?PaperInfo $prow, ?ReviewInfo $rrow = null) {
        if (!$this->prepare_save($prow, $rrow)) {
            $this->abort_save();
            return false;
        }
        if (!$this->execute_save()) {
            $this->abort_save();
            return false;
        }
        return true;
    }

    /** Resolve the target review and stage the request onto it without
     * committing. A review that does not yet exist is staged onto an unsaved
     * assignable review (`assign_review_prop`) — no database row is created
     * here — so a `prepare_save` not followed by `execute_save` (i.e. a dry run)
     * never touches the database. On success the staged changes are available
     * via `change_list()`; a following `execute_save()` commits them, or
     * `abort_save()` reverts them.
     * @return bool true if the request staged cleanly */
    function prepare_save(?PaperInfo $prow, ?ReviewInfo $rrow = null) {
        assert(!$rrow || $rrow->paperId === $prow->paperId);
        $user = $this->user;
        $this->reviewId = $this->review_ordinal_id = null;
        $this->_save_status = 0;
        $this->stage_rrow = $this->stage_user = null;

        // look up paper
        if (!$prow) {
            if (($pid = $this->req_pid()) === null) {
                $this->rvmsg(self::ERROR, "paperId", $this->conf->_("<0>{Submission} ID required"));
                $this->rvmsg(self::INFORM, "paperId", $this->conf->_("<0>Enter the {submission} number in the right place and try again."));
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
            $this->rvmsg(self::ERROR, "paperId", $this->conf->_("<0>{Submission} ID does not match"));
            $this->rvmsg(self::INFORM, "paperId", $this->conf->_("<0>It looks like you tried to upload a form intended for a different {submission} (expected #{}, form is for #{}).", $prow->paperId, $this->req["paperId"]));
            return false;
        }

        // resolve an explicit review locator (`rid`): a specific ordinal/ID
        // selects the review (or confirms a passed one — a disagreement is a
        // mismatch), while `new`/empty leaves the reviewer's review to be found
        // or created below
        if (isset($this->req["rid"])) {
            $rloc = $prow->parse_ordinal_id($this->req["rid"]);
            if ($rloc === false) {
                $this->rvmsg(self::ERROR, "rid", "<0>Review not found");
                return false;
            } else if ($rloc !== 0) {
                $ridrow = $rloc < 0
                    ? $prow->review_by_ordinal(-$rloc)
                    : $prow->review_by_id($rloc);
                if (!$ridrow) {
                    $this->rvmsg(self::ERROR, "rid", "<0>Review not found");
                    return false;
                } else if ($rrow && $rrow->reviewId !== $ridrow->reviewId) {
                    $this->rvmsg(self::ERROR, "rid", "<0>Review ID does not match");
                    return false;
                } else if (($fr = $user->perm_view_review($prow, $ridrow))) {
                    // an unviewable review the caller may not know exists reads
                    // as `reviewNonexistent` (mirrors the URL `r` path)
                    $fr->append_to($this, "rid", self::ERROR);
                    return false;
                }
                $rrow = $rrow ?? $ridrow;
            }
        }

        // look up reviewer
        if (!$rrow
            && ($this->req["reviewerEmail"] ?? "") === ""
            && $this->require_reviewer) {
            $this->reviewer_error("<0>Reviewer missing");
            return false;
        }

        $reviewer = $this->reviewer ?? $this->user;
        if ($rrow
            && $rrow->contactId !== $user->contactId) {
            $reviewer = $this->conf->user_by_id($rrow->contactId, USER_SLICE);
        } else if (isset($this->req["reviewerEmail"])
                   && strcasecmp($this->req["reviewerEmail"], $reviewer->email) !== 0
                   && !($reviewer = $this->conf->user_by_email($this->req["reviewerEmail"]))) {
            if (!validate_email($this->req["reviewerEmail"])) {
                $this->rvmsg(self::ERROR, "reviewerEmail", "<0>Invalid email");
                return false;
            }
            $reviewer = Contact::make_keyed($this->conf, [
                "email" => $this->req["reviewerEmail"],
                "firstName" => $this->req["reviewerFirst"] ?? "",
                "lastName" => $this->req["reviewerLast"] ?? ""
            ]);
        }

        // an unknown reviewer is created only where the caller allows it. With
        // an existing `$rrow` no account is needed: `_apply_req` checks the
        // requested reviewer against the review instead.
        if (!$rrow && !$reviewer->has_account_here() && !$this->create_users) {
            if ($user->privChair) {
                $this->reviewer_error($this->conf->_("<0>User {} not found", $reviewer->email));
            } else {
                $this->reviewer_error($this->conf->_("<0>Can’t edit a review for {}", $reviewer->email));
            }
            return false;
        }

        // resolve a requested review type. Anyone may request the type the
        // reviewer would receive by default; only an administrator may request a
        // different type (e.g. primary/secondary/meta)
        $reqtype = $this->requested_review_type();
        if ($reqtype !== null) {
            if ($reqtype === false) {
                $this->rvmsg(self::ERROR, "reviewType", "<0>Invalid review type");
                return false;
            } else if ($rrow ? $reqtype === $rrow->reviewType : $reqtype === REVIEW_PC || $reqtype === REVIEW_EXTERNAL) {
                // the reviewer's default type — always allowed
            } else if (!$user->can_manage_reviews($prow)) {
                $this->rvmsg(self::ERROR, "reviewType", "<0>Only an administrator can set the review type");
                return false;
            } else if ($reqtype > REVIEW_PC && !$reviewer->is_pc_member()) {
                $uname = $reviewer->name(NAME_E);
                $this->rvmsg(self::ERROR, "reviewType", "<0>‘{$uname}’ is not a PC member and cannot be assigned a PC review");
                return false;
            }
        }

        // look up review
        if (!$rrow) {
            // Refuse before the lookup when the caller named someone else and
            // may not administer reviews: otherwise the refusal would differ
            // depending on whether that user reviews this submission, which
            // reveals reviewer identities. Either way the request is refused.
            if ($reviewer->contactId !== $user->contactId
                && !$user->can_manage_reviews($prow)) {
                $this->rvmsg(self::ERROR, null, "<0>You don’t have permission to edit this review");
                return false;
            }
            $rrow = $prow->fresh_review_by_user($reviewer);
        }
        if (!$rrow && $user === $reviewer && $user->review_tokens()) {
            $prow->ensure_full_reviews();
            if (($xrrows = $prow->reviews_by_user(-1, $user->review_tokens()))) {
                $rrow = $xrrows[0];
            }
        }

        // a review that does not yet exist is staged onto an unsaved assignable
        // review; the real database row is created later, by `execute_save`
        if (!$rrow) {
            $round = isset($this->req["round"]) ? (int) $this->conf->round_number($this->req["round"]) : null;
            if (($whynot = $user->perm_create_review($prow, $reviewer, $round))) {
                $whynot->append_to($this, null, self::ERROR);
                return false;
            }
            if (($reqtype ?? 0) > REVIEW_PC) {
                $rtype = $reqtype;
            } else {
                $rtype = $reviewer->isPC ? REVIEW_PC : REVIEW_EXTERNAL;
            }
            if (!$reviewer->has_account_here()) {
                if ($this->disable_users) {
                    $reviewer->cflags |= Contact::CF_UDISABLED;
                }
                $reviewer->store(0, $this->user);
            }
            $rrow = $user->assign_review_prop($prow->paperId, $reviewer, $rtype, [
                "selfassign" => $reviewer === $user, "round_number" => $round
            ]);
            if (!$rrow || $rrow->reviewType <= 0) {
                $this->rvmsg(self::ERROR, null, "<0>Internal error while creating review");
                return false;
            }
        } else if ($reqtype !== null && $reqtype !== $rrow->reviewType) {
            // `review_type` may pick the type of a new review, but not change an
            // existing one; use the assignment API for that
            $this->rvmsg(self::ERROR, "reviewType", "<0>Can’t change the type of an existing review through this API");
            return false;
        }

        // ensure the target review knows its paper, so the commit half (and
        // callers) can recover it from the review alone
        if (!$rrow->prow) {
            $rrow->set_prow($prow);
        }

        // stage the request (validation + property staging)
        if (!$this->_apply_req($prow, $rrow)) {
            return false;
        }
        $this->_save_status |= self::SSF_PREPARED;
        return true;
    }

    /** Commit a review staged by `prepare_save` (creating its database row if
     * necessary). Resets the prepared state.
     * @return bool */
    function execute_save() {
        assert(($this->_save_status & self::SSF_PREPARED) !== 0);
        $this->_save_status &= ~self::SSF_PREPARED;
        return $this->_commit_req();
    }

    /** Revert the changes staged by `prepare_save` without committing. A
     * would-be-new review was never inserted (creation happens in
     * `execute_save`), so there is nothing to delete; but tables locked by
     * `_apply_req` for ordinal assignment must be released. */
    function abort_save() {
        if (($this->_save_status & self::SSF_LOCKED) !== 0) {
            $this->conf->qe_raw("unlock tables");
        }
        if ($this->stage_rrow) {
            $this->stage_rrow->abort_prop();
        }
        $this->_save_status &= ~(self::SSF_PREPARED | self::SSF_LOCKED);
    }

    /** The paper resolved by the last `prepare_save` (from the passed paper or
     * the request's `paperId`), or null if it did not resolve.
     * @return ?PaperInfo */
    function saved_prow() {
        return $this->stage_rrow ? $this->stage_rrow->prow : null;
    }

    /** The list of changes staged by the last `prepare_save`, derived from the
     * review's diff. Must be read before `execute_save`/`abort_save`, which
     * clear the diff (so a dry run or a conflict can report it, but afterwards
     * it is empty).
     *
     * In `$full` mode, an object created by this save leads with `new`
     * (consistent across the paper, review, and comment APIs).
     * @param bool $full
     * @return list<string> */
    function change_list($full = false) {
        if (!$this->stage_rrow) {
            return [];
        }
        $diffinfo = $this->stage_rrow->prop_diff();
        $cl = [];
        if ($full && $this->stage_rrow->base_prop("reviewId") <= 0) {
            $cl[] = "new";
        }
        if ($this->stage_oldstatus !== $this->stage_newstatus) {
            $cl[] = "status";
        }
        if (array_key_exists("reviewBlind", $diffinfo->_old_prop)) {
            $cl[] = "blind";
        }
        foreach ($diffinfo->fields() as $f) {
            $cl[] = $f->short_id;
        }
        return $cl;
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
        // allow primaryContactId relationship
        $requser = $this->conf->user_by_email($reqemail, USER_SLICE);
        if ($requser
            && ($requser->primaryContactId === $reviewer->contactId
                || $requser->contactId === $reviewer->primaryContactId)) {
            return true;
        }
        // otherwise complain
        $revfull = Text::nameo($reviewer, NAME_EB);
        $reqfull = Text::name($reqfirst, $reqlast, $reqemail, NAME_EB);
        $this->rvmsg(self::ERROR, "reviewerEmail",
            $this->conf->_("<0>Reviewer conflict: review is for {}, but request names {}", $revfull, $reqfull));
        if ($this->text !== null) {
            $this->rvmsg(self::INFORM, "reviewerEmail",
                $this->conf->_("<5>To upload the form anyway, remove its ‘<code class=\"nw\">==+== Reviewer</code>’ section."));
        }
        return false;
    }

    /** @param PaperInfo $prow
     * @param ReviewInfo $rrow
     * @param int $view_score
     * @param bool $allow_new_submit
     * @param bool $approvable
     * @return int */
    private function _compute_new_status($prow, $rrow, $view_score,
                                         $allow_new_submit, $approvable) {
        $user = $this->user;
        $oldstatus = $rrow->reviewStatus;
        $olddelivered = $oldstatus >= ReviewInfo::RS_DELIVERED;
        $nonempty = $view_score > VIEWSCORE_EMPTY;
        if ($olddelivered
            && (!$this->can_unsubmit
                || !$user->can_manage_reviews($prow))) {
            $minstatus = $oldstatus;
        } else if ($nonempty
                   || $rrow->reviewModified > 1) {
            $minstatus = ReviewInfo::RS_DRAFTED;
        } else if ($user->is_my_review($rrow)
                   || $oldstatus >= ReviewInfo::RS_ACKNOWLEDGED) { // XXX decline via this API?
            $minstatus = ReviewInfo::RS_ACKNOWLEDGED;
        } else {
            $minstatus = ReviewInfo::RS_EMPTY;
        }

        if (!($this->req["ready"] ?? $olddelivered)
            || (!$allow_new_submit && !$olddelivered)) {
            return $minstatus;
        }

        if ($rrow->subject_to_approval()) {
            $approval = $approvable ? $this->req["approval"] ?? null : null;
            if ($approval === "submitted") {
                $maxstatus = ReviewInfo::RS_COMPLETED;
            } else if ($approval === "approved") {
                $maxstatus = ReviewInfo::RS_APPROVED;
            } else if ($nonempty) {
                $maxstatus = ReviewInfo::RS_DELIVERED;
            } else {
                $maxstatus = $oldstatus;
            }
        } else if ($nonempty) {
            $maxstatus = ReviewInfo::RS_COMPLETED;
        } else {
            $maxstatus = $oldstatus;
        }
        return max($maxstatus, $minstatus);
    }

    /** @return bool */
    private function _apply_req(PaperInfo $prow, ReviewInfo $rrow) {
        assert($prow->paperId === $this->req["paperId"] && $rrow->paperId === $prow->paperId);
        $user = $this->user;
        // set `stage_rrow` before staging any property, so that an error
        // return from any point below leaves a state `abort_save` can revert
        $this->stage_rrow = $rrow;
        $usedReviewToken = $user->active_review_token_for($prow, $rrow);
        $approvable = $user->can_approve_review($prow, $rrow);

        $oldstatus = $rrow->reviewStatus;
        $old_nonempty_view_score = $this->rf->nonempty_view_score($rrow);
        $rflags = $rrow->rflags;
        '@phan-var-force int $rflags';

        // can only edit reviews you own or administer
        if (!$user->is_owned_review($rrow)
            && !$user->can_manage_reviews($prow)) {
            $this->rvmsg(self::ERROR, null, "<0>You don’t have permission to edit this review");
            return false;
        }

        // reviewers must match if provided
        if ((isset($this->req["reviewerEmail"])
             && !$this->_check_reviewer($rrow, $this->req["reviewerEmail"]))
            || ($this->reviewer
                && !$this->_check_reviewer($rrow, $this->reviewer->email))) {
            return false;
        }

        // process review fields
        $before_msgcount = $this->message_count();
        $view_score = VIEWSCORE_EMPTY;
        $any_fval = $any_fdiff = false;
        $allow_new_submit = true;
        $want_ready = $this->req["ready"] ?? $oldstatus >= ReviewInfo::RS_DELIVERED;
        $fmissing = [];
        $wc = 0;

        foreach ($this->rf->all_fields() as $f) {
            $exists = $f->test_exists($rrow);
            list($old_fval, $fval) = $this->fvalues($f, $rrow);
            if (!$exists && $old_fval === null) {
                continue;
            }
            if ($fval === false) {
                $this->rvmsg(self::ERROR, $f->short_id, $this->conf->_("<0>{} cannot be ‘{}’", $f->name, UnicodeHelper::utf8_word_abbreviate(trim($this->req[$f->short_id]), 100)));
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
                $rrow->set_fval_prop($f, $fval);
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
        // (clickthrough not required to accept review or change status)
        if ($any_fdiff || !$approvable) {
            $erflags = $any_fdiff || $want_ready ? Contact::EDIT_REVIEW_SUBMIT : 0;
            if (($whynot = $user->perm_edit_review($prow, $rrow, $erflags))) {
                $this->clear_messages_since($before_msgcount);
                $whynot->append_to($this, null, self::ERROR);
                return false;
            } else if ($user->allow_admin($prow)
                       && !($this->req["override"] ?? false)
                       && !$this->conf->time_review($rrow->reviewRound, $rrow->reviewType, true)) {
                $this->clear_messages_since($before_msgcount);
                $this->rvmsg(self::ERROR, null, "<5>The " . $this->conf->hotlink("deadline", "deadlines") . " for editing this review has passed");
                $this->rvmsg(self::INFORM, null, "<0>Select “Override deadlines” and try again if you need to override the deadline.");
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
            $this->rvmsg(self::ERROR, $this->first_lineno, "<5><strong>Edit conflict</strong>: This review has been edited online since you downloaded this offline form, so for safety I am not replacing the online version.");
            $this->rvmsg(self::INFORM, $this->first_lineno, "<5>To override your online edits, add a line “<code class=\"nw\">==+== Version {$rrow->reviewEditVersion}</code>” to your offline review form for paper #{$prow->paperId} and upload the form again.");
            return false;
        }

        // warn about missing fields
        if ($fmissing) {
            $status = $want_ready ? self::ERROR : self::WARNING;
            foreach ($fmissing as $f) {
                $this->rvmsg($status, $f->short_id, $this->conf->_("<0>{}: Entry required", $f->name));
            }
            if ($status === self::ERROR) {
                $this->rvmsg(self::ERROR, "ready", $this->conf->_("<0>The review can’t be submitted until entries are provided for all required fields."));
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
        } else if (!$want_ready
                   || ($oldstatus < ReviewInfo::RS_DELIVERED && !$allow_new_submit)) {
            // unready nonempty review is at least drafted
            if ($this->can_unsubmit
                && $user->can_manage_reviews($prow)) {
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
        $newstatus2 = $this->_compute_new_status($prow, $rrow, $view_score, $allow_new_submit, $approvable);
        if ($newstatus !== $newstatus2) {
            error_log("{$this->conf->dbname}: #{$prow->paperId}/{$rrow->reviewId}: old status computation {$newstatus} ≠ new status computation {$newstatus2}");
            error_log("{$this->conf->dbname}: " . json_encode(["view_score" => $view_score, "allow_new_submit" => $allow_new_submit, "approvable" => $approvable, "old_status" => $rrow->reviewStatus, "mtime" => $rrow->reviewModified, "ready" => $this->req["ready"] ?? null]));
        }
        assert($newstatus === $newstatus2);

        // get the current time; include Conf::$now for tests
        $now = max(Conf::$now, time(), $rrow->reviewModified + 1);

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
            && $rrow->reviewSubmitted <= 0) {
            $rrow->set_prop("reviewSubmitted", $now);
            $rflags |= ReviewInfo::RF_SUBMITTED;
        } else if ($newstatus < ReviewInfo::RS_COMPLETED
                   && $rrow->reviewSubmitted > 0) {
            $rrow->set_prop("reviewSubmitted", 0);
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

        // capture the minimal commit state now, before the version-tag check, so
        // a conflict can still report the attempted change list (the list is
        // derived on demand from the staged diff, and unrecoverable once a commit
        // or abort clears it)
        $this->stage_rrow = $rrow;
        $this->stage_user = $user;
        $this->stage_oldstatus = $oldstatus;
        $this->stage_newstatus = $newstatus;

        // version tag / if_unmodified_since precondition. Checked here, after the
        // request is fully staged but before any database write (the ordinal lock
        // below is the first), so a conflict reports the attempted diff without
        // committing; abort_save then reverts the staged changes.
        if (!$this->check_vtag($rrow)) {
            return false;
        }

        // potentially assign a review ordinal and display time (requires table
        // locking since MySQL is stupid). The tables stay locked past the end of
        // staging: execute_save saves under the lock and releases it, while a
        // dry run's abort_save releases it without saving.
        $newordinal = false;
        if ((!$rrow->reviewId
             && $newsubmit
             && $diffinfo->view_score() >= VIEWSCORE_AUTHORDEC)
            || ($rrow->reviewId
                && !$rrow->reviewOrdinal
                && ($newsubmit || $rrow->reviewStatus >= ReviewInfo::RS_COMPLETED)
                && ($diffinfo->view_score() >= VIEWSCORE_AUTHORDEC
                    || $this->rf->nonempty_view_score($rrow) >= VIEWSCORE_AUTHORDEC))) {
            $result = $this->conf->qe_raw("lock tables PaperReview write, PaperReviewHistory write"
                . ($rrow->reviewId ? "" : ", IDReservation write"));
            if (Dbl::is_error($result)) {
                return false;
            }
            Dbl::free($result);
            $this->_save_status |= self::SSF_LOCKED;
            $max_ordinal = $this->conf->fetch_ivalue("select coalesce(max(reviewOrdinal), 0) from PaperReview where paperId=? group by paperId", $prow->paperId);
            $rrow->set_prop("reviewOrdinal", (int) $max_ordinal + 1);
            $newordinal = true;
        }
        if ($newordinal
            || (($newsubmit
                 || ($newstatus >= ReviewInfo::RS_APPROVED && $oldstatus < ReviewInfo::RS_APPROVED))
                && !$rrow->timeDisplayed)) {
            $rrow->set_prop("timeDisplayed", Conf::$now);
        }

        // finalize the staged rflags (the last staged property; `_commit_req`
        // only writes what was staged here)
        $rrow->set_prop("rflags", $rflags);
        return true;
    }

    /** Commit the review staged by the staging half of `_apply_req`, using the
     * captured `stage_*` state.
     * @return bool */
    private function _commit_req() {
        $rrow = $this->stage_rrow;
        $prow = $rrow->prow;
        $user = $this->stage_user;
        $diffinfo = $rrow->prop_diff();
        $oldstatus = $this->stage_oldstatus;
        $newstatus = $this->stage_newstatus;
        $newsubmit = $newstatus >= ReviewInfo::RS_COMPLETED
            && $oldstatus < ReviewInfo::RS_COMPLETED;

        // actually affect database (all properties, including any review
        // ordinal, were staged by `_apply_req`)
        $result = $rrow->save_prop();

        // release the tables `_apply_req` locked for ordinal assignment, if any
        if (($this->_save_status & self::SSF_LOCKED) !== 0) {
            $this->conf->qe_raw("unlock tables");
            $this->_save_status &= ~self::SSF_LOCKED;
        }

        if ($result < 0) {
            if ($result === ReviewInfo::SAVERET_CONFLICT) {
                $this->rvmsg(self::ERROR, null, "<0>Review was edited concurrently, please try again");
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
            $this->conf->update_automatic_tags($prow, SearchTerm::ABOUT_REVIEWS);
        }

        // potentially email chair, reviewers, and authors
        $reviewer = $user;
        if ($rrow->contactId !== $user->contactId) {
            $reviewer = $this->conf->user_by_id($rrow->contactId, USER_SLICE);
        }
        if ($this->notify) {
            $this->_notify($prow, $rrow, $diffinfo, $newstatus, $oldstatus, $reviewer);
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

        // log assignment or commit property
        if ($rrow->base_prop("reviewId") <= 0) {
            $rrow->commit_prop_assignment($user, ["no_autosearch" => true, "no_rights" => true]);
        } else {
            $rrow->commit_prop();
        }

        // log updates -- but not if review token is used
        if ($diffinfo->is_viewable()
            && !$user->active_review_token_for($prow, $rrow)) {
            $user->log_activity_for($rrow->contactId, $this->_log_message($rrow, $oldstatus, $newstatus, $diffinfo), $prow);
        }

        return true;
    }

    /** @param int $newstatus
     * @param int $oldstatus */
    private function _notify(PaperInfo $prow, ReviewInfo $rrow,
                             ReviewDiffInfo $diffinfo,
                             $newstatus, $oldstatus,
                             Contact $reviewer) {
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
            } else if ($rrow->requestedBy === $this->user->contactId) {
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
                && $rrow->requestedBy !== $this->user->contactId
                && $rrow->reviewType <= REVIEW_PC
                && ($requser = $this->conf->user_by_id($rrow->requestedBy))) {
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
            if ($minic->contactId === $this->user->contactId
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
            if (preg_match('/\A(\#?)(\d+)([A-Z]*)\z/', $x, $m)) {
                $x = $this->conf->hotlink($x, "paper", ["p" => $m[2], "#" => $m[3] ? "r{$m[2]}{$m[3]}" : null]);
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
        $this->append_item(new MessageItem($status, null, $t));
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
        }
        return MessageSet::SUCCESS;
    }

    function report() {
        $this->finished || $this->finish();
        if ($this->finished < 3) {
            $mis = $this->message_list();
            if ($this->text !== null && $this->has_problem()) {
                $errtype = $this->has_error() ? "errors" : "warnings";
                array_unshift($mis, MessageItem::inform($this->conf->_("<0>There were {$errtype} while parsing the uploaded review file.")));
            }
            if (($status = $this->summary_status()) !== MessageSet::PLAIN) {
                $this->conf->feedback_msg($mis, new MessageItem($status));
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
