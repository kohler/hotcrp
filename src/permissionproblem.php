<?php
// permissionproblem.php -- HotCRP helper class for permission errors
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PermissionProblem extends Exception
    implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $secondary;
    /** @var array<string,mixed> */
    private $_a;

    /** @param ?array<string,mixed> $a */
    function __construct(Conf $conf, $a = null) {
        parent::__construct("HotCRP permission problem");
        $this->conf = $conf;
        $this->_a = $a ?? [];
        $this->secondary = !!($this->_a["secondary"] ?? false);
    }

    #[\ReturnTypeWillChange]
    /** @param string $offset
     * @return bool */
    function offsetExists($offset) {
        return array_key_exists($offset, $this->_a);
    }

    #[\ReturnTypeWillChange]
    /** @param string $offset */
    function offsetGet($offset) {
        return $this->_a[$offset] ?? null;
    }

    #[\ReturnTypeWillChange]
    /** @param string $offset
     * @param mixed $value */
    function offsetSet($offset, $value) {
        $this->_a[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    /** @param string $offset */
    function offsetUnset($offset) {
        unset($this->_a[$offset]);
    }

    #[\ReturnTypeWillChange]
    /** @return Iterator<string,mixed> */
    function getIterator() {
        return new ArrayIterator($this->as_array());
    }

    /** @param string $offset
     * @return mixed */
    function get($offset, $default = null) {
        if (array_key_exists($offset, $this->_a)) {
            $default = $this->_a[$offset];
        }
        return $default;
    }

    /** @param string $offset
     * @return $this */
    function set($offset, $value) {
        $this->_a[$offset] = $value;
        return $this;
    }

    /** @param array<string,mixed> $a
     * @return $this */
    function merge($a) {
        foreach ($a as $k => $v) {
            $this->_a[$k] =$v;
        }
        return $this;
    }

    #[\ReturnTypeWillChange]
    /** @return int */
    function count() {
        $n = 0;
        foreach ($this->_a as $k => $v) {
            if (!in_array($k, ["paperId", "reviewId", "option", "override", "forceShow", "listViewable"]))
                ++$n;
        }
        return $n;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $a = $this->as_array();
        if (isset($a["option"])) {
            $opt = $a["option"];
            '@phan-var PaperOption $opt';
            $a["option"] = ["id" => $opt->id, "name" => $opt->name];
        }
        return $a;
    }

    /** @return array<string,mixed> */
    function as_array() {
        return $this->_a;
    }

    /** @param list<string> $offsets
     * @return PermissionProblem */
    function filter($offsets) {
        $pp = new PermissionProblem($this->conf);
        foreach ($this->_a as $k => $v) {
            if ($k === "paperId" || in_array($k, $offsets))
                $pp->_a[$k] = $v;
        }
        return $pp;
    }

    /** @return array{string,int,string,int} */
    private function deadline_info() {
        $dn = $this->_a["deadline"];
        if ($dn === "response") {
            $rrd = $this->conf->response_round_by_id($this->_a["commentRound"]);
            return ["response_open", $rrd->open, "response_done", $rrd->done];
        }

        if (str_starts_with($dn, "sub_")) {
            $odn = "sub_open";
        } else if (str_starts_with($dn, "rev_")
                   || str_starts_with($dn, "extrev_")
                   || str_starts_with($dn, "pcrev_")) {
            $odn = "rev_open";
        } else {
            $odn = null;
        }
        $start = $odn !== null ? $this->conf->setting($odn) ?? -1 : 1;

        if ($dn === "extrev_chairreq") {
            $dn = $this->conf->review_deadline_name($this->_a["reviewRound"] ?? null, false, true);
        }
        $end = $this->conf->setting($dn) ?? -1;
        return [$odn, $start, $dn, $end];
    }

    /** @param int $format
     * @return string */
    function unparse($format = 0) {
        $ms = $args = [];
        $paperId = $this->_a["paperId"] ?? -1;
        if ($paperId > 0) {
            $args[] = new FmtArg("pid", $paperId, 0);
        }
        $option = $this->_a["option"] ?? null;
        '@phan-var ?PaperOption $option';
        if ($option) {
            $args[] = new FmtArg("field", $option->title(), 0);
        }
        if (isset($this->_a["invalidId"])) {
            $id = $this->_a["invalidId"];
            $idname = $id === "paper" ? "submission" : $id;
            if (isset($this->_a["{$id}Id"])) {
                $ms[] = $this->conf->_("<0>Invalid {$idname} ID “{}”.", $this->_a["{$id}Id"]);
            } else {
                $ms[] = $this->conf->_("<0>Invalid {$idname} ID.");
            }
        }
        if (isset($this->_a["missingId"])) {
            $id = $this->_a["missingId"];
            $idname = $id === "paper" ? "submission" : $id;
            $ms[] = $this->conf->_("<0>Missing {$idname} ID.");
        }
        if ($this->_a["noPaper"] ?? false) {
            $ms[] = $this->conf->_("<0>Submission #{} does not exist.", $paperId);
        }
        if (isset($this->_a["dbError"])) {
            $ms[] = $this->_a["dbError"];
        }
        if ($this->_a["administer"] ?? false) {
            $ms[] = $this->conf->_("<0>You can’t administer submission #{}.", $paperId);
        }
        if (isset($this->_a["permission"])) {
            $ms[] = $this->conf->_i("permission_error", new FmtArg("action", $this->_a["permission"]), ...$args);
        }
        if ($this->_a["optionNonexistent"] ?? false) {
            $ms[] = $this->conf->_("<0>The {field} field is not present on submission #{pid}", ...$args);
        }
        if (isset($this->_a["documentNotFound"])) {
            $ms[] = $this->conf->_("<0>Document “{}” not found.", $this->_a["documentNotFound"]);
        }
        if (isset($this->_a["signin"])) {
            $url = $this->_a["signinUrl"] ?? $this->conf->hoturl_raw("signin");
            $ms[] = $this->conf->_i("signin_required", new FmtArg("url", $url, 0), new FmtArg("action", $this->_a["signin"]));
        }
        if ($this->_a["withdrawn"] ?? false) {
            $ms[] = $this->conf->_("<0>Submission #{} has been withdrawn.", $paperId);
        }
        if ($this->_a["notWithdrawn"] ?? false) {
            $ms[] = $this->conf->_("<0>Submission #{} is not withdrawn.", $paperId);
        }
        if ($this->_a["notSubmitted"] ?? false) {
            $ms[] = $this->conf->_("<0>Submission #{} is only a draft.", $paperId);
        }
        if ($this->_a["reviewsSeen"] ?? false) {
            $ms[] = $this->conf->_("<0>You can’t withdraw a submission after seeing its reviews.", $paperId);
        }
        if ($this->_a["decided"] ?? false) {
            $ms[] = $this->conf->_("<0>The review process for submission #{} has completed.", $paperId);
        }
        if ($this->_a["frozen"] ?? false) {
            $ms[] = $this->conf->_("<0>Submission #{} can no longer be edited.", $paperId);
        }
        if ($this->_a["notUploaded"] ?? false) {
            $ms[] = $this->conf->_("<0>A PDF upload is required to submit.");
        }
        if ($this->_a["reviewNotSubmitted"] ?? false) {
            $ms[] = $this->conf->_("<0>This review is not yet ready for others to see.");
        }
        if ($this->_a["reviewNotComplete"] ?? false) {
            $ms[] = $this->conf->_("<0>Your own review for #{} is not complete, so you can’t view other people’s reviews.", $paperId);
        }
        if ($this->_a["responseNonexistent"] ?? false) {
            $ms[] = $this->conf->_("<0>That response is not allowed on submission #{}.", $paperId);
        }
        if ($this->_a["responseNotReady"] ?? false) {
            $ms[] = $this->conf->_("<0>The authors’ response is not yet ready for reviewers to view.");
        }
        if ($this->_a["reviewsOutstanding"] ?? false) {
            $ms[] = $this->conf->_("<0>You will get access to the reviews once you complete your assigned reviews. If you can’t complete your reviews, please inform the organizers.");
            if ($format === 5) {
                $ms[] = $this->conf->_("<5><a href=\"{0:html}\">List assigned reviews</a>", $this->conf->hoturl_raw("search", ["q" => "", "t" => "r"]));
            }
        }
        if ($this->_a["reviewNotAssigned"] ?? false) {
            $ms[] = $this->conf->_("<0>You are not assigned to review submission #{}.", $paperId);
        }
        if (isset($this->_a["deadline"])) {
            list($odn, $start, $edn, $end) = $this->deadline_info();
            if ($edn == "au_seerev") {
                $ms[] = $this->conf->_c("etime", "<0>Action not available.", $edn, $paperId);
            } else if ($start <= 0 || $start == $end) {
                $ms[] = $this->conf->_c("etime", "<0>Action not available.", $odn, $paperId);
            } else if ($start > 0 && Conf::$now < $start) {
                $ms[] = $this->conf->_c("etime", "<0>Action not available until {2}.", $odn, $paperId, $this->conf->unparse_time($start));
            } else if ($end > 0 && Conf::$now > $end) {
                $ms[] = $this->conf->_c("etime", "<0>Deadline passed.", $edn, $paperId, $this->conf->unparse_time($end));
            } else {
                $ms[] = $this->conf->_c("etime", "<0>Action not available.", $edn, $paperId);
            }
        }
        if ($this->_a["override"] ?? false) {
            $ms[] = $this->conf->_("<0>“Override deadlines” can override this restriction.");
        }
        if ($this->_a["blindSubmission"] ?? false) {
            $ms[] = $this->conf->_("<0>Submission to this conference is blind.");
        }
        if ($this->_a["author"] ?? false) {
            $ms[] = $this->conf->_("<0>You aren’t a contact for #{}.", $paperId);
        }
        if ($this->_a["conflict"] ?? false) {
            $ms[] = $this->conf->_("<0>You have a conflict with #{}.", $paperId);
        }
        if ($this->_a["nonPC"] ?? false) {
            $ms[] = $this->conf->_("<0>You aren’t a member of the PC for submission #{}.", $paperId);
        }
        if ($this->_a["externalReviewer"] ?? false) {
            $ms[] = $this->conf->_("<0>External reviewers cannot view other reviews.");
        }
        if ($this->_a["differentReviewer"] ?? false) {
            if (isset($this->_a["commentId"])) {
                $ms[] = $this->conf->_("<0>You didn’t write this comment, so you can’t change it.");
            } else {
                $ms[] = $this->conf->_("<0>You didn’t write this review, so you can’t change it.");
            }
        }
        if ($this->_a["unacceptableReviewer"] ?? false) {
            $ms[] = $this->conf->_("<0>That user can’t be assigned to review #{}.", $paperId);
        }
        if ($this->_a["alreadyReviewed"] ?? false) {
            $ms[] = $this->conf->_("<0>You already have a review assignment for #{}.", $paperId);
        }
        if ($this->_a["clickthrough"] ?? false) {
            $ms[] = $this->conf->_("<0>You can’t do that until you agree to the terms.");
        }
        if ($this->_a["otherTwiddleTag"] ?? false) {
            $ms[] = $this->conf->_("<0>Tag #{} doesn’t belong to you.", $this->_a["tag"]);
        }
        if ($this->_a["chairTag"] ?? false) {
            $ms[] = $this->conf->_("<0>Tag #{} can only be changed by administrators.", $this->_a["tag"]);
        }
        if ($this->_a["voteTag"] ?? false) {
            $ms[] = $this->conf->_("<0>The voting tag #{0} shouldn’t be changed directly. To vote for this paper, change the #~{0} tag.", $this->_a["tag"]);
        }
        if ($this->_a["voteTagNegative"] ?? false) {
            $ms[] = $this->conf->_("<0>Negative votes aren’t allowed.");
        }
        if ($this->_a["autosearchTag"] ?? false) {
            $ms[] = $this->conf->_("<0>Tag #{} cannot be changed since the system sets it automatically.", $this->_a["tag"]);
        }
        if (empty($ms)) {
            $ms[] = $this->conf->_c("eperm", "<0>Permission error.", "unknown", $paperId);
        }
        // finish it off
        if (($this->_a["forceShow"] ?? false)
            && $format === 5
            && Navigation::get()->page !== "api"
            && Qrequest::$main_request) {
            $ms[] = $this->conf->_("<5><a class=\"nw\" href=\"{}\">Override conflict</a>", $this->conf->selfurl(Qrequest::$main_request, ["forceShow" => 1]));
        }
        if (!empty($ms)
            && ($this->_a["listViewable"] ?? false)
            && $format === 5) {
            $ms[] = $this->conf->_("<a href=\"{}\">List the submissions you can view</a>", $this->conf->hoturl("search", "q="));
        }
        $mx = [];
        foreach ($ms as $m) {
            $mx[] = Ftext::unparse_as($m, $format);
        }
        return join(" ", $mx);
    }

    /** @return string */
    function unparse_text() {
        return $this->unparse(0);
    }

    /** @return string */
    function unparse_html() {
        return $this->unparse(5);
    }

    /** @param ?string $field
     * @param 1|2|3 $status
     * @return Iterable<MessageItem> */
    function message_list($field, $status) {
        return [new MessageItem(null, "<5>" . $this->unparse_html(), $status)];
    }

    /** @param ?string $field
     * @param 1|2|3 $status */
    function append_to(MessageSet $ms, $field, $status) {
        foreach ($this->message_list($field, $status) as $mi) {
            $ms->append_item($mi);
        }
    }
}
