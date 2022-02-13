<?php
// permissionproblem.php -- HotCRP helper class for permission errors
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PermissionProblem extends Exception
    implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    /** @var Conf */
    public $conf;
    /** @var array<string,mixed> */
    private $_a;
    /** @param ?array<string,mixed> $a */
    function __construct(Conf $conf, $a = null) {
        parent::__construct("HotCRP permission problem");
        $this->conf = $conf;
        $this->_a = $a ?? [];
    }
    #[\ReturnTypeWillChange]
    /** @param string $offset
     * @return bool */
    function offsetExists($offset) {
        return array_key_exists($offset, $this->_a);
    }
    #[\ReturnTypeWillChange]
    /** @param string $offset */
    function& offsetGet($offset) {
        $x = null;
        if (array_key_exists($offset, $this->_a)) {
            $x =& $this->_a[$offset];
        }
        return $x;
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
        return count($this->_a);
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->as_array();
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
    /** @param int $format
     * @return string */
    function unparse($format = 0) {
        $paperId = $this->_a["paperId"] ?? -1;
        $reviewId = $this->_a["reviewId"] ?? -1;
        $option = $this->_a["option"] ?? null;
        '@phan-var ?PaperOption $option';
        $ms = [];
        $quote = $format !== 5 ? function ($x) { return $x; } : "htmlspecialchars";
        if ($option) {
            $this->_a["option_title"]  =  $quote($option->title());
        }
        if (isset($this->_a["invalidId"])) {
            $id = $this->_a["invalidId"];
            $idname = $id === "paper" ? "submission" : $id;
            if (isset($this->_a["{$id}Id"])) {
                $ms[] = $this->conf->_("Invalid {$idname} ID “%s”.", $quote($this->_a["{$id}Id"]));
            } else {
                $ms[] = $this->conf->_("Invalid {$idname} ID.");
            }
        }
        if (isset($this->_a["missingId"])) {
            $id = $this->_a["missingId"];
            $idname = $id === "paper" ? "submission" : $id;
            $ms[] = $this->conf->_("Missing {$idname} ID.");
        }
        if (isset($this->_a["noPaper"])) {
            $ms[] = $this->conf->_("Submission #%d does not exist.", $paperId);
        }
        if (isset($this->_a["dbError"])) {
            $ms[] = $this->_a["dbError"];
        }
        if (isset($this->_a["administer"])) {
            $ms[] = $this->conf->_("You can’t administer submission #%d.", $paperId);
        }
        if (isset($this->_a["permission"])) {
            $ms[] = $this->conf->_c("eperm", "Permission error.", $this->_a["permission"], $paperId, $this->_a);
        }
        if (isset($this->_a["optionNotAccepted"])) {
            $ms[] = $this->conf->_("The %2\$s field is reserved for accepted submissions.", $paperId, $quote($option->title()));
        }
        if (isset($this->_a["documentNotFound"])) {
            $ms[] = $this->conf->_("No such document “%s”.", $quote($this->_a["documentNotFound"]));
        }
        if (isset($this->_a["signin"])) {
            $ms[] = $this->conf->_c("eperm", "You have been signed out.", $this->_a["signin"], $paperId);
        }
        if (isset($this->_a["withdrawn"])) {
            $ms[] = $this->conf->_("Submission #%d has been withdrawn.", $paperId);
        }
        if (isset($this->_a["notWithdrawn"])) {
            $ms[] = $this->conf->_("Submission #%d is not withdrawn.", $paperId);
        }
        if (isset($this->_a["notSubmitted"])) {
            $ms[] = $this->conf->_("Submission #%d is only a draft.", $paperId);
        }
        if (isset($this->_a["rejected"])) {
            $ms[] = $this->conf->_("Submission #%d was not accepted for publication.", $paperId);
        }
        if (isset($this->_a["reviewsSeen"])) {
            $ms[] = $this->conf->_("You can’t withdraw a submission after seeing its reviews.", $paperId);
        }
        if (isset($this->_a["decided"])) {
            $ms[] = $this->conf->_("The review process for submission #%d has completed.", $paperId);
        }
        if (isset($this->_a["updateSubmitted"])) {
            $ms[] = $this->conf->_("Submission #%d can no longer be updated.", $paperId);
        }
        if (isset($this->_a["notUploaded"])) {
            $ms[] = $this->conf->_("A PDF upload is required to submit.");
        }
        if (isset($this->_a["reviewNotSubmitted"])) {
            $ms[] = $this->conf->_("This review is not yet ready for others to see.");
        }
        if (isset($this->_a["reviewNotComplete"])) {
            $ms[] = $this->conf->_("Your own review for #%d is not complete, so you can’t view other people’s reviews.", $paperId);
        }
        if (isset($this->_a["responseNotReady"])) {
            $ms[] = $this->conf->_("The authors’ response is not yet ready for reviewers to view.");
        }
        if (isset($this->_a["reviewsOutstanding"])) {
            $ms[] = $this->conf->_("You will get access to the reviews once you complete your assigned reviews. If you can’t complete your reviews, please inform the organizers.");
            if ($format === 5) {
                $ms[] = $this->conf->_("<a href=\"%s\">List assigned reviews</a>", $this->conf->hoturl("search", "q=&amp;t=r"));
            }
        }
        if (isset($this->_a["reviewNotAssigned"])) {
            $ms[] = $this->conf->_("You are not assigned to review submission #%d.", $paperId);
        }
        if (isset($this->_a["deadline"])) {
            $dname = $this->_a["deadline"];
            if ($dname[0] === "s") {
                $open_dname = "sub_open";
            } else if ($dname[0] === "p" || $dname[0] === "e") {
                $open_dname = "rev_open";
            } else {
                $open_dname = false;
            }
            $start = $open_dname ? $this->conf->setting($open_dname) ?? -1 : 1;
            if ($dname === "extrev_chairreq") {
                $end_dname = $this->conf->review_deadline_name($this->_a["reviewRound"] ?? null, false, true);
            } else {
                $end_dname = $dname;
            }
            $end = $this->conf->setting($end_dname) ?? -1;
            if ($dname == "au_seerev") {
                $ms[] = $this->conf->_c("etime", "Action not available.", $dname, $paperId);
            } else if ($start <= 0 || $start == $end) {
                $ms[] = $this->conf->_c("etime", "Action not available.", $open_dname, $paperId);
            } else if ($start > 0 && Conf::$now < $start) {
                $ms[] = $this->conf->_c("etime", "Action not available until %3\$s.", $open_dname, $paperId, $this->conf->unparse_time($start));
            } else if ($end > 0 && Conf::$now > $end) {
                $ms[] = $this->conf->_c("etime", "Deadline passed.", $dname, $paperId, $this->conf->unparse_time($end));
            } else {
                $ms[] = $this->conf->_c("etime", "Action not available.", $dname, $paperId);
            }
        }
        if (isset($this->_a["override"])) {
            $ms[] = $this->conf->_("“Override deadlines” can override this restriction.");
        }
        if (isset($this->_a["blindSubmission"])) {
            $ms[] = $this->conf->_("Submission to this conference is blind.");
        }
        if (isset($this->_a["author"])) {
            $ms[] = $this->conf->_("You aren’t a contact for #%d.", $paperId);
        }
        if (isset($this->_a["conflict"])) {
            $ms[] = $this->conf->_("You have a conflict with #%d.", $paperId);
        }
        if (isset($this->_a["nonPC"])) {
            $ms[] = $this->conf->_("You aren’t a member of the PC for submission #%d.", $paperId);
        }
        if (isset($this->_a["externalReviewer"])) {
            $ms[] = $this->conf->_("External reviewers cannot view other reviews.");
        }
        if (isset($this->_a["differentReviewer"])) {
            $ms[] = $this->conf->_("You didn’t write this review, so you can’t change it.");
        }
        if (isset($this->_a["unacceptableReviewer"])) {
            $ms[] = $this->conf->_("That user can’t be assigned to review #%d.", $paperId);
        }
        if (isset($this->_a["clickthrough"])) {
            $ms[] = $this->conf->_("You can’t do that until you agree to the terms.");
        }
        if (isset($this->_a["otherTwiddleTag"])) {
            $ms[] = $this->conf->_("Tag #%s doesn’t belong to you.", $quote($this->_a["tag"]));
        }
        if (isset($this->_a["chairTag"])) {
            $ms[] = $this->conf->_("Tag #%s can only be changed by administrators.", $quote($this->_a["tag"]));
        }
        if (isset($this->_a["voteTag"])) {
            $ms[] = $this->conf->_("The voting tag #%s shouldn’t be changed directly. To vote for this paper, change the #~%1\$s tag.", $quote($this->_a["tag"]));
        }
        if (isset($this->_a["voteTagNegative"])) {
            $ms[] = $this->conf->_("Negative votes aren’t allowed.");
        }
        if (isset($this->_a["autosearchTag"])) {
            $ms[] = $this->conf->_("Tag #%s cannot be changed since the system sets it automatically.", $quote($this->_a["tag"]));
        }
        if (empty($ms)) {
            $ms[] = $this->conf->_c("eperm", "Permission error.", "unknown", $paperId);
        }
        // finish it off
        if (isset($this->_a["forceShow"])
            && $format === 5
            && Navigation::page() !== "api") {
            $ms[] = $this->conf->_("<a class=\"nw\" href=\"%s\">Override conflict</a>", $this->conf->selfurl(Qrequest::$main_request, ["forceShow" => 1]));
        }
        if (!empty($ms)
            && isset($this->_a["listViewable"])
            && $format === 5) {
            $ms[] = $this->conf->_("<a href=\"%s\">List the submissions you can view</a>", $this->conf->hoturl("search", "q="));
        }
        return join(" ", $ms);
    }
    /** @return string */
    function unparse_text() {
        return $this->unparse(0);
    }
    /** @return string */
    function unparse_html() {
        return $this->unparse(5);
    }
}
