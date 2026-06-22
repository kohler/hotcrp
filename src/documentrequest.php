<?php
// documentrequest.php -- HotCRP document request parsing
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

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
    private $attachment;
    /** @var ?int */
    private $hash;
    /** @var ?int */
    private $at;
    /** @var ?int */
    private $docid;

    /** @var ?DocumentInfo */
    private $doc;
    /** @var ?int */
    private $active_count;
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
    /** @var null|int|string */
    private $_error_scope;

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
    function __construct($req, Contact $viewer, $path = null) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;

        $want_path = !isset($req["p"]) && !isset($req["paperId"]);
        if (!$want_path) {
            $key = isset($req["p"]) ? "p" : "paperId";
            if (!$this->set_paper_id((string) $req[$key], $key)) {
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
        } else if (!$want_path && $dtname && isset($req["file"])) {
            $this->attachment = $req["file"];
        }

        if ($want_path) {
            $path = $path ?? $req["doc"] ?? $req["file"] /* XXX backward compat */ ?? "";
            $this->req_filename = $path;
            if (str_starts_with($path, $this->conf->download_prefix)) {
                $path = substr($path, strlen($this->conf->download_prefix));
            }
            $pidstr = $dtname = "";
            $encattachment = null;
            if (preg_match('/\A(?:p|paper|sub|submission)(\d+)\/+(.*)\z/', $path, $m)) {
                $pidstr = $m[1];
                if (preg_match('/\A([^\/]+)\.[^\/]+\z/', $m[2], $mm)) {
                    $dtname = urldecode($mm[1]);
                } else if (preg_match('/\A([^\/]+)\/+(.*)\z/', $m[2], $mm)) {
                    $dtname = urldecode($mm[1]);
                    $encattachment = $mm[2];
                } else if (isset($req["dt"])) {
                    $dtname = $req["dt"];
                }
            } else if (preg_match('/\A(p|paper|sub|submission|final|)(\d+)-?([-A-Za-z0-9_]*)(?:|\.[^\/]+|\/+(.*))\z/', $path, $m)) {
                $pidstr = $m[2];
                $dtname = $m[3];
                if ($dtname === "" && $m[1] === "" && isset($req["dt"])) {
                    $dtname = $req["dt"];
                }
                $encattachment = $m[4] ?? null;
                if ($m[1] !== "") {
                    $base_dtname = $m[1] === "final" ? "final" : "paper";
                }
            } else if (preg_match('/\A([A-Za-z_][-A-Za-z0-9_]*?)?-?(\d+)(?:|\.[^\/]+|\/+(.*))\z/', $path, $m)) {
                $pidstr = $m[2];
                $dtname = $m[1];
                $encattachment = $m[3] ?? null;
            } else if ($this->paperId === null
                       && preg_match('/\A([^\/]+?)(?:|\.[^\/]+|\/+(.*)|)\z/', $path, $m)) {
                $pidstr = "-2";
                $dtname = $m[1];
                $encattachment = $m[2] ?? null;
            } else {
                $this->error_at("doc", "<0>Document ‘{:nonempty}’ not found", $this->req_filename);
                return;
            }
            if (!$this->set_paper_id($pidstr, "doc")) {
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
                $this->req_filename = "[{$n} file {$this->attachment}]";
            } else {
                $this->req_filename = "[{$n}]";
            }
        }

        if ($this->dtype === null
            || ($this->opt && $this->opt->nonpaper) !== ($this->paperId < 0)) {
            $this->error_at("doc", "<0>Document ‘{$this->req_filename}’ not found");
            return;
        }

        // look up paper
        $potential_prow = null;
        if ($req && $req instanceof Qrequest) {
            $potential_prow = $req->paper();
        }
        if ($potential_prow && $potential_prow->paperId === $this->paperId) {
            $this->prow = $potential_prow;
        } else if ($this->paperId < 0) {
            $this->prow = PaperInfo::make_placeholder($this->conf, -2);
        } else {
            $this->prow = $this->conf->paper_by_id($this->paperId, $viewer);
        }

        // check document permission
        if (($fr = $this->perm_view_document())) {
            $fr->append_to($this, $want_path ? "doc" : null, 2);
            if (isset($fr["scope"])) {
                $this->_error_status = 401;
                $this->_error_scope = $fr["scope"];
            } else if (isset($fr["permission"])) {
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
        $jr = JsonResult::make_message_list($this->_error_status, $this->message_list());
        if ($this->_error_status === 401
            && $this->_error_scope) {
            $jr->set_header($this->conf->www_authenticate_header("insufficient_scope", null, $this->_error_scope));
        }
        return $jr;
    }


    /** @return list<DocumentInfo> */
    function active() {
        if ($this->dtype < DTYPE_FINAL) {
            return $this->doc ? [$this->doc] : [];
        }
        return $this->prow->documents($this->dtype);
    }

    /** @return list<DocumentInfo> */
    function history() {
        $docs = $this->active();
        if ($this->active_count === null) {
            $this->active_count = count($docs);
        }
        if ($this->viewer->can_view_document_history($this->prow)) {
            $active_docids = [];
            foreach ($docs as $doc) {
                $active_docids[] = $doc->paperStorageId;
            }
            $result = $this->conf->qe("select paperId, paperStorageId, timestamp, timeReferenced, mimetype, sha1, filename, infoJson, size from PaperStorage where paperId=? and documentType=? and filterType is null and paperStorageId?A",
                $this->prow->paperId, $this->dtype, $active_docids);
            $inactive_docs = [];
            while (($doc = DocumentInfo::fetch($result, $this->conf, $this->prow))) {
                $inactive_docs[] = $doc;
            }
            Dbl::free($result);
            usort($inactive_docs, function ($da, $db) {
                $ta = $da->timeReferenced ?? $da->timestamp;
                $tb = $db->timeReferenced ?? $db->timestamp;
                return ($tb <=> $ta)
                    ? : ($da->paperStorageId <=> $db->paperStorageId);
            });
            array_push($docs, ...$inactive_docs);
        }
        return $docs;
    }

    /** @return int */
    function active_count() {
        if ($this->active_count === null) {
            if ($this->dtype < DTYPE_FINAL) {
                $this->active_count = $this->doc ? 1 : 0;
            } else {
                $this->active_count = count($this->prow->documents($this->dtype));
            }
        }
        return $this->active_count;
    }


    /** @param Qrequest $qreq */
    private function _apply_specific_version($qreq) {
        $this->cacheable = true;

        // parse version parameters
        $docid = null;
        if (isset($qreq->docid)) {
            $docid = stoi($qreq->docid) ?? 0;
            if ($docid <= 1) {
                $this->error_at("docid", "<0>Invalid document ID");
                $this->_error_status = 400;
                return;
            }
        }

        $dochash = $hashkey = null;
        if (isset($qreq->hash) || isset($qreq->version)) {
            $hashkey = isset($qreq->hash) ? "hash" : "version";
            $dochash = HashAnalysis::hash_as_binary(trim($qreq->$hashkey));
            if (!$dochash) {
                $this->error_at($hashkey, "<0>Invalid document hash");
                $this->_error_status = 400;
                return;
            }
        }

        // if document already set, check for version parameter conflicts
        if ($this->doc) {
            if ($docid && $this->doc->paperStorageId !== $docid) {
                $this->error_at("docid", "<0>Version conflict");
            }
            if ($dochash && $this->doc->sha1 !== $dochash) {
                $this->error_at($hashkey, "<0>Version conflict");
            }
            return;
        }

        // look up document
        if ($docid) {
            $doc = $this->prow->document($this->dtype, $docid, true);
        } else {
            $doc = $this->_apply_hash_version($dochash);
        }

        // check for errors
        $key = $docid ? "docid" : $hashkey;
        if (!$doc) {
            $this->error_at($key, "<0>Document version not found");
            $this->cacheable = false; // version might appear later
            return;
        }
        if ($doc->filterType) {
            $this->error_at($key, "<0>Document version not found");
            return;
        }
        if ($doc->documentType !== $this->dtype) {
            $this->error_at("dt", "<0>Version conflict");
            return;
        }
        if ($docid && $docid !== $doc->paperStorageId) {
            $this->error_at("docid", "<0>Version conflict");
            return;
        }
        if ($dochash && $dochash !== $doc->sha1) {
            $this->error_at($hashkey, "<0>Version conflict");
            return;
        }
        if (!$this->viewer->can_view_document_history($this->prow)
            && !$doc->is_active()) {
            $this->error_at($key, "<0>Document version not found");
            $this->cacheable = false; // user might gain ability to see history
            return;
        }

        $this->doc = $doc;
    }

    /** @param string $dochash
     * @return ?DocumentInfo */
    private function _apply_hash_version($dochash) {
        // multiple documents might have the same hash (because of metadata
        // like mimetype and filename); choose the active one, or if none is
        // active, the latest one
        foreach ($this->prow->documents($this->dtype) as $doc) {
            if ($doc->sha1 === $dochash)
                return $doc;
        }
        $result = $this->conf->qe("select " . $this->conf->document_query_fields() . " from PaperStorage where paperId=? and documentType=? and sha1=?",
            $this->prow->paperId, $this->dtype, $dochash);
        $docf = null;
        while (($doc = DocumentInfo::fetch($result, $this->conf, $this->prow))) {
            if (!$docf
                || ($doc->timeReferenced ?? $doc->timestamp) > ($docf->timeReferenced ?? $docf->timestamp))
                $docf = $doc;
        }
        $result->close();
        return $docf;
    }

    /** @param Qrequest $qreq
     * @return $this */
    function apply_version($qreq) {
        if ($this->has_error()) {
            return $this;
        }

        if (isset($qreq->docid)
            || isset($qreq->hash)
            || isset($qreq->version)) {
            $this->_apply_specific_version($qreq);
            return $this;
        }

        if ($this->doc || $this->dtype < DTYPE_FINAL || !isset($qreq->at)) {
            return $this;
        }

        $doctime = stoi($qreq->at) ?? $this->conf->parse_time($qreq->at);
        if (!$doctime) {
            $this->error_at("at", "<0>Invalid date");
            return $this;
        }
        foreach ($this->history() as $doc) {
            if ($doc->timestamp <= $doctime
                && (!$this->doc || $this->doc->timestamp < $doc->timestamp)) {
                $this->doc = $doc;
            }
        }
        if (!$this->doc) {
            $this->error_at("at", "<0>Version not found");
        }
        return $this;
    }

    /** @return ?DocumentInfo */
    function document() {
        if ($this->has_error()) {
            return null;
        }
        if (!$this->doc) {
            if ($this->attachment) {
                $this->doc = $this->prow->attachment($this->dtype, $this->attachment);
            } else {
                $this->doc = $this->prow->document($this->dtype, 0, true);
            }
            if (!$this->doc) {
                $this->error_at("doc", "<0>Document not found");
            }
        }
        return $this->doc;
    }

    /** @return ?DocumentInfo */
    function filtered_document() {
        if (!($doc = $this->document())) {
            return null;
        }
        foreach ($this->filters as $filter) {
            $doc = $filter->exec($doc) ?? $doc;
        }
        return $doc;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = [
            "req_filename" => $this->req_filename,
            "pid" => $this->paperId,
            "dt" => $this->dtype
        ];
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
