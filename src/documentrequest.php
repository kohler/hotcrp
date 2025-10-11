<?php
// documentrequest.php -- HotCRP document request parsing
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class DocumentRequest extends MessageSet implements JsonSerializable {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var int
     * @readonly */
    public $paperId;
    /** @var ?PaperInfo
     * @readonly */
    public $prow;
    /** @var int
     * @readonly */
    public $dtype;
    /** @var ?PaperOption
     * @readonly */
    public $opt;
    /** @var ?string */
    private $linkid;
    /** @var ?string */
    public $attachment;
    /** @var ?DocumentInfo */
    private $doc;
    /** @var ?int */
    private $history_nactive;
    /** @var list<FileFilter>
     * @readonly */
    public $filters = [];
    /** @var string
     * @readonly */
    public $req_filename;
    /** @var bool */
    public $cacheable = false;
    /** @var int */
    private $_error_status = 404;

    /** @param string $s
     * @param string $field
     * @return bool
     * @suppress PhanAccessReadOnlyProperty */
    private function set_paper_id($s, $field) {
        $n = stoi($s);
        if ($n === null || $s !== trim($s) || ($n < 0 && $n !== -2)) {
            $this->error_at($field, "<0>Invalid {submission} ID ’{:nonempty}’", $s);
            return false;
        } else if ($this->paperId !== null && $n !== $this->paperId) {
            $this->error_at($field, "<0>{Submission} ID doesn’t match", null);
            return false;
        }
        $this->paperId = $n;
        return true;
    }

    /** @param array|Qrequest $req
     * @param ?string $path */
    function __construct($req, $path, Contact $viewer) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;

        $want_path = !isset($req["p"]) && !isset($req["paperId"]);
        if (!$want_path) {
            $key = isset($req["p"]) ? "p" : "paperId";
            if (!$this->set_paper_id($req[$key], $key)) {
                return;
            }
        }

        $dtname = "";
        $base_dtname = "paper";
        if (isset($req["dt"])) {
            $dtname = $req["dt"];
        } else if (isset($req["final"])) {
            $base_dtname = "final";
        }

        if (isset($req["attachment"])) {
            $this->attachment = $req["attachment"];
        }

        if ($want_path) {
            $s = $this->req_filename = preg_replace('/\A\/*/', "", $path);
            if (str_starts_with($s, $this->conf->download_prefix)) {
                $s = substr($s, strlen($this->conf->download_prefix));
            }
            $pidstr = $dtname = "";
            $encattachment = null;
            if (preg_match('/\A(?:p|paper|sub|submission)(\d+)\/+(.*)\z/', $s, $m)) {
                $pidstr = $m[1];
                if (preg_match('/\A([^\/]+)\.[^\/]+\z/', $m[2], $mm)) {
                    $dtname = urldecode($mm[1]);
                } else if (preg_match('/\A([^\/]+)\/+(.*)\z/', $m[2], $mm)) {
                    $dtname = urldecode($mm[1]);
                    $encattachment = $mm[2];
                } else if (isset($req["dt"])) {
                    $dtname = $req["dt"];
                }
            } else if (preg_match('/\A(p|paper|sub|submission|final|)(\d+)-?([-A-Za-z0-9_]*)(?:|\.[^\/]+|\/+(.*))\z/', $s, $m)) {
                $pidstr = $m[2];
                $dtname = $m[3];
                if ($dtname === "" && $m[1] === "" && isset($req["dt"])) {
                    $dtname = $req["dt"];
                }
                $encattachment = $m[4] ?? null;
                if ($m[1] !== "") {
                    $base_dtname = $m[1] === "final" ? "final" : "paper";
                }
            } else if (preg_match('/\A([A-Za-z_][-A-Za-z0-9_]*?)?-?(\d+)(?:|\.[^\/]+|\/+(.*))\z/', $s, $m)) {
                $pidstr = $m[2];
                $dtname = $m[1];
                $encattachment = $m[3] ?? null;
            } else if ($this->paperId === null
                       && preg_match('/\A([^\/]+?)(?:|\.[^\/]+|\/+(.*)|)\z/', $s, $m)) {
                $pidstr = "-2";
                $dtname = $m[1];
                $encattachment = $m[2] ?? null;
            } else {
                $this->error_at("file", "<0>Document ‘{:nonempty}’ not found", $this->req_filename);
                return;
            }
            if (!$this->set_paper_id($pidstr, "file")) {
                return;
            }
            if (isset($encattachment)) {
                $this->attachment = urldecode($encattachment);
            }
        }

        // parse options and filters
        $this->opt = $this->dtype = null;
        while ($dtname !== "" && $this->dtype === null) {
            if ((str_starts_with($dtname, "comment-")
                 && $this->check_comment_linkid(substr($dtname, 8), 0))
                || (str_starts_with($dtname, "response")
                    && $this->check_comment_linkid(substr($dtname, 8), 1))
                || (str_ends_with($dtname, "response")
                    && $this->check_comment_linkid(substr($dtname, 0, -8), 2))) {
                $this->dtype = DTYPE_COMMENT;
                break;
            }
            if (($dtnum = stoi($dtname)) !== null) {
                $this->opt = $this->conf->option_by_id($dtnum);
            } else if ($this->paperId >= 0) {
                $this->opt = $this->conf->options()->find($dtname);
            } else {
                $this->opt = $this->conf->options()->find_nonpaper($dtname);
            }
            if ($this->opt !== null) {
                $this->dtype = $this->opt->id;
                break;
            }
            $filter = null;
            foreach (FileFilter::all_by_name($this->conf) as $ff) {
                if (str_ends_with($dtname, "-" . $ff->name) || $dtname === $ff->name) {
                    $filter = $ff;
                    break;
                }
            }
            if (!$filter) {
                break;
            }
            array_unshift($this->filters, $filter);
            $dtname = substr($dtname, 0, strlen($dtname) - strlen($filter->name));
            if (str_ends_with($dtname, "-")) {
                $dtname = substr($dtname, 0, strlen($dtname) - 1);
            }
        }

        // if nothing found, use the base
        if ($this->dtype === null && $dtname === "") {
            $this->opt = $this->conf->options()->find($base_dtname);
            $this->dtype = $this->opt->id;
        } else if ($this->dtype === null) {
            $this->error_at("dt", "<0>Document class ‘{$dtname}’ not found");
            return;
        }

        // canonicalize response naming
        if ($this->dtype === DTYPE_COMMENT) {
            if (str_starts_with($this->linkid, "response")) {
                if (strlen($this->linkid) > 8) {
                    $this->linkid = substr($this->linkid, 0, $this->linkid[8] === "-" ? 9 : 8) . "response";
                }
            } else if (str_ends_with($this->linkid, "-response")) {
                $this->linkid = substr($this->linkid, 0, -9) . "response";
            }
        }

        if (isset($req["filter"])) {
            foreach (explode(" ", $req["filter"]) as $filtername) {
                if ($filtername === "") {
                    continue;
                } else if (($filter = FileFilter::find_by_name($this->conf, $filtername))) {
                    $this->filters[] = $filter;
                } else {
                    $this->error_at("filter", "<0>Document filter ‘{$filtername}’ not found");
                    return;
                }
            }
        }

        if (!$want_path) {
            $n = $this->opt ? $this->opt->dtype_name() : $dtname;
            if ($this->paperId >= 0) {
                if ($this->dtype === DTYPE_SUBMISSION) {
                    $n = "submission #{$this->paperId}";
                } else if ($this->dtype === DTYPE_FINAL) {
                    $n = "#{$this->paperId} final version";
                } else {
                    $n = "#{$this->paperId} {$n}";
                }
            }
            if ($this->attachment) {
                $this->req_filename = "[{$n} attachment {$this->attachment}]";
            } else {
                $this->req_filename = "[{$n}]";
            }
        }

        if ($this->dtype === null
            || ($this->opt && $this->opt->nonpaper) !== ($this->paperId < 0)) {
            $this->error_at("file", "<0>Document ‘{$this->req_filename}’ not found");
            return;
        }

        // look up paper
        if ($this->paperId < 0) {
            $this->prow = PaperInfo::make_placeholder($this->conf, -2);
        } else {
            $this->prow = $this->conf->paper_by_id($this->paperId, $viewer);
        }

        // check document permission
        if (($fr = $this->perm_view_document())) {
            $fr->append_to($this, $want_path ? "file" : null, 2);
            if (isset($fr["permission"])) {
                $this->_error_status = 403;
            }
        }
    }

    /** @param string $dtname
     * @param 0|1|2 $reqtype
     * @return bool */
    private function check_comment_linkid($dtname, $reqtype) {
        // `linkid` settings must match CommentInfo::unparse_html_id
        if ($reqtype === 0) {
            if (str_ends_with($dtname, "response")) {
                $dtname = substr($dtname, 0, -8);
                $reqtype = 2;
            } else if (preg_match('/\Ac([aAxX]?)([1-9]\d*)\z/', $dtname, $m)) {
                if ($m[1] === "a") {
                    $this->linkid = "cA" . $m[2];
                } else if ($m[1] === "X") {
                    $this->linkid = "cx" . $m[2];
                } else {
                    $this->linkid = $dtname;
                }
                return true;
            } else {
                return false;
            }
        }
        if ($reqtype === 1 && str_starts_with($dtname, "-")) {
            $dtname = substr($dtname, 1);
        } else if ($reqtype === 2 && str_ends_with($dtname, "-")) {
            $dtname = substr($dtname, 0, -1);
        }
        if (preg_match('/\A(?:|[a-zA-Z](?:[a-zA-Z0-9]|[-_][a-zA-Z0-9])*)\z/', $dtname)) {
            if (($rrd = $this->conf->response_round($dtname))) {
                $this->linkid = $rrd->unnamed ? "response" : "{$rrd->name}response";
            } else {
                $this->linkid = "{$dtname}response"; // will not match
            }
            return true;
        }
        return false;
    }

    /** @return ?FailureReason */
    private function perm_view_document() {
        $viewer = $this->viewer;
        if ($this->paperId < 0) {
            $vis = $this->opt->visibility();
            if (($vis === PaperOption::VIS_ADMIN && !$viewer->privChair)
                || ($vis !== PaperOption::VIS_SUB && !$viewer->isPC)) {
                return $this->prow->failure_reason(["permission" => "field:view", "option" => $this->opt]);
            }
            return null;
        } else if (($whynot = $viewer->perm_view_paper($this->prow, false, $this->paperId))) {
            return $whynot;
        } else if ($this->dtype === DTYPE_COMMENT) {
            return $this->perm_view_comment_document();
        } else if ($this->opt) {
            return $viewer->perm_view_option($this->prow, $this->opt);
        }
        return null;
    }

    /** @return ?FailureReason
     * @suppress PhanAccessReadOnlyProperty */
    private function perm_view_comment_document() {
        $doc_crow = $cmtid = null;
        if (str_starts_with($this->linkid, "cx")
            && !str_ends_with($this->linkid, "response")) {
            $cmtid = stoi(substr($this->linkid, 2));
        }
        foreach ($this->prow->viewable_comment_skeletons($this->viewer) as $crow) {
            if ($crow->unparse_html_id() === $this->linkid
                || $crow->commentId === $cmtid) {
                $doc_crow = $crow;
                break;
            }
        }
        if ($doc_crow
            && ($xdoc = $doc_crow->attachments()->document_by_filename($this->attachment))) {
            $this->doc = $xdoc;
            return null;
        }
        return $this->prow->failure_reason(["documentNotFound" => $this->req_filename]);
    }


    /** @return list<MessageItem> */
    function message_list() {
        $this->apply_fmt($this->conf);
        return parent::message_list();
    }

    /** @return int */
    function error_status() {
        return $this->has_error() ? $this->_error_status : 200;
    }

    /** @return JsonResult */
    function error_result() {
        return JsonResult::make_message_list($this->_error_status, $this->message_list());
    }


    /** @return list<DocumentInfo> */
    function history() {
        $docs = $this->prow->documents($this->dtype);
        $this->history_nactive = count($docs);
        if ($this->viewer->can_view_document_history($this->prow)
            && $this->dtype >= DTYPE_FINAL) {
            $active_docids = [];
            foreach ($docs as $doc) {
                $active_docids[] = $doc->paperStorageId;
            }
            $result = $this->conf->qe("select paperId, paperStorageId, timestamp, mimetype, sha1, filename, infoJson, size from PaperStorage where paperId=? and documentType=? and filterType is null and paperStorageId?A order by paperStorageId desc",
                $this->prow->paperId, $this->dtype, $active_docids);
            while (($doc = DocumentInfo::fetch($result, $this->conf, $this->prow))) {
                $docs[] = $doc;
            }
            Dbl::free($result);
        } else {
            error_log("fuck $this->dtype");
        }
        return $docs;
    }

    /** @return int */
    function history_nactive() {
        if ($this->history_nactive === null) {
            $this->history();
        }
        return $this->history_nactive;
    }

    /** @param Qrequest $qreq
     * @return $this */
    function apply_version($qreq) {
        if ($this->has_error() || $this->doc) {
            return $this;
        }

        // version checking
        $dochash = $doctime = null;
        if (isset($qreq->hash) || isset($qreq->version)) {
            $dochash = HashAnalysis::hash_as_binary(trim($qreq->hash ?? $qreq->version));
            $this->cacheable = true;
            if (!$dochash) {
                $this->error_at("hash", "<0>Invalid document hash");
                return $this;
            }
        } else if (isset($qreq->at)) {
            $doctime = stoi($qreq->at) ?? $this->conf->parse_time($qreq->at);
            if (!$doctime) {
                $this->error_at("at", "<0>Invalid date");
                return $this;
            }
        }
        if (($dochash || $doctime) && $this->dtype >= DTYPE_FINAL) {
            foreach ($this->history() as $doc) {
                if ($dochash
                    && $doc->binary_hash() === $dochash) {
                    $this->doc = $doc;
                    return;
                } else if ($doctime
                           && $doc->timestamp <= $doctime
                           && (!$this->doc || $this->doc->timestamp < $doc->timestamp)) {
                    $this->doc = $doc;
                }
            }
            if (!$this->doc) {
                $this->error_at($dochash ? "hash" : "at", "<0>Version not found");
            }
        }
        return $this;
    }

    /** @param ?Qrequest $qreq
     * @param bool $full
     * @return ?DocumentInfo */
    function document($qreq = null, $full = false) {
        if ($this->has_error()) {
            return null;
        } else if ($this->doc) {
            return $this->doc;
        }

        // look up document
        $docid = null;
        if ($qreq && isset($qreq->docid)) {
            $docid = stoi($qreq->docid);
            if ($docid === null || $docid <= 1) {
                $this->error_at("docid", "<0>Invalid document ID");
                return null;
            }
        }
        if ($this->attachment && !$docid) {
            $doc = $this->prow->attachment($this->dtype, $this->attachment);
        } else {
            $doc = $this->prow->document($this->dtype, $docid, $full);
        }
        if ($doc
            && $doc->paperStorageId > 1
            && $doc->documentType === $this->dtype
            && ($docid === null || $doc->paperStorageId === $docid)) {
            $this->doc = $doc;
            return $doc;
        }
        if ($docid) {
            $this->error_at("docid", "<0>Version not found");
        } else {
            $this->error_at("file", "<0>Document not found");
        }
        return null;
    }

    /** @param ?Qrequest $qreq
     * @param bool $full
     * @return ?DocumentInfo */
    function filtered_document($qreq = null, $full = false) {
        if (!($doc = $this->document($qreq, $full))) {
            return null;
        }
        foreach ($this->filters as $filter) {
            $doc = $filter->exec($doc) ?? $doc;
        }
        return $doc;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = ["req_filename" => $this->req_filename, "pid" => $this->paperId, "dtype" => $this->dtype];
        foreach (["linkid", "attachment", "docid"] as $k) {
            if ($this->$k !== null) {
                $j[$k] = $this->$k;
            }
        }
        foreach ($this->filters as $f) {
            $j["filters"][] = $f->name;
        }
        return $j;
    }
}
