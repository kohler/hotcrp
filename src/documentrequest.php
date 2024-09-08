<?php
// documentrequest.php -- HotCRP document request parsing
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DocumentRequest implements JsonSerializable {
    /** @var int */
    public $paperId;
    /** @var ?PaperInfo */
    public $prow;
    /** @var int */
    public $dtype;
    /** @var ?PaperOption */
    public $opt;
    /** @var ?string */
    private $linkid;
    /** @var ?string */
    public $attachment;
    /** @var ?int */
    public $docid;
    /** @var list<FileFilter> */
    public $filters = [];
    public $req_filename;

    private function set_paperid($pid) {
        if (preg_match('/\A[-+]?\d+\z/', $pid)) {
            $this->paperId = intval($pid);
        } else {
            throw new Exception("Document not found [submission {$pid}]");
        }
    }

    function __construct($req, $path, Contact $user) {
        $conf = $user->conf;
        $want_path = false;
        if (isset($req["p"])) {
            $this->set_paperid($req["p"]);
        } else if (isset($req["paperId"])) {
            $this->set_paperid($req["paperId"]);
        } else {
            $want_path = true;
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
            $dtname = "";
            if (str_starts_with($s, $conf->download_prefix)) {
                $s = substr($s, strlen($conf->download_prefix));
            }
            if (preg_match('/\A(?:p|paper|sub|submission)(\d+)\/+(.*)\z/', $s, $m)) {
                $this->paperId = intval($m[1]);
                if (preg_match('/\A([^\/]+)\.[^\/]+\z/', $m[2], $mm)) {
                    $dtname = urldecode($mm[1]);
                } else if (preg_match('/\A([^\/]+)\/+(.*)\z/', $m[2], $mm)) {
                    $dtname = urldecode($mm[1]);
                    $this->attachment = urldecode($mm[2]);
                } else if (isset($req["dt"])) {
                    $dtname = $req["dt"];
                }
            } else if (preg_match('/\A(p|paper|sub|submission|final|)(\d+)-?([-A-Za-z0-9_]*)(?:|\.[^\/]+|\/+(.*))\z/', $s, $m)) {
                $this->paperId = intval($m[2]);
                $dtname = $m[3];
                if ($dtname === "" && $m[1] === "" && isset($req["dt"])) {
                    $dtname = $req["dt"];
                }
                if (isset($m[4])) {
                    $this->attachment = urldecode($m[4]);
                }
                if ($m[1] !== "") {
                    $base_dtname = $m[1] === "final" ? "final" : "paper";
                }
            } else if (preg_match('/\A([A-Za-z_][-A-Za-z0-9_]*?)?-?(\d+)(?:|\.[^\/]+|\/+(.*))\z/', $s, $m)) {
                $this->paperId = intval($m[2]);
                $dtname = $m[1];
                if (isset($m[3])) {
                    $this->attachment = urldecode($m[3]);
                }
            } else if (preg_match('/\A([^\/]+?)(?:|\.[^\/]+|\/+(.*)|)\z/', $s, $m)) {
                $this->paperId = -2;
                $dtname = $m[1];
                if (isset($m[2])) {
                    $this->attachment = urldecode($m[2]);
                }
            } else {
                throw new Exception("Document ‘{$this->req_filename}’ not found");
            }
        }

        // parse options and filters
        $this->opt = $this->dtype = null;
        while ($dtname !== "" && $this->dtype === null) {
            if ((str_starts_with($dtname, "comment-")
                 && $this->check_comment_linkid($conf, substr($dtname, 8), 0))
                || (str_starts_with($dtname, "response")
                    && $this->check_comment_linkid($conf, substr($dtname, 8), 1))
                || (str_ends_with($dtname, "response")
                    && $this->check_comment_linkid($conf, substr($dtname, 0, -8), 2))) {
                $this->dtype = DTYPE_COMMENT;
                break;
            }
            if (($dtnum = stoi($dtname)) !== null) {
                $this->opt = $conf->option_by_id($dtnum);
            } else if ($this->paperId >= 0) {
                $this->opt = $conf->options()->find($dtname);
            } else {
                $this->opt = $conf->options()->find_nonpaper($dtname);
            }
            if ($this->opt !== null) {
                $this->dtype = $this->opt->id;
                break;
            }
            $filter = null;
            foreach (FileFilter::all_by_name($conf) as $ff) {
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
            $this->opt = $conf->options()->find($base_dtname);
            $this->dtype = $this->opt->id;
        } else if ($this->dtype === null) {
            throw new Exception("Document ‘{$dtname}’ not found");
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
                if ($filtername !== "") {
                    if (($filter = FileFilter::find_by_name($conf, $filtername))) {
                        $this->filters[] = $filter;
                    } else {
                        throw new Exception("Document filter ‘{$filtername}’ not found");
                    }
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
            throw new Exception("Document ‘{$this->req_filename}’ not found");
        }

        // look up paper
        if ($this->paperId < 0) {
            $this->prow = PaperInfo::make_placeholder($user->conf, -2);
        } else {
            $this->prow = $user->conf->paper_by_id($this->paperId, $user);
        }
    }

    /** @param string $dtname
     * @param 0|1|2 $reqtype
     * @return bool */
    private function check_comment_linkid(Conf $conf, $dtname, $reqtype) {
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
            if (($rrd = $conf->response_round($dtname))) {
                $this->linkid = $rrd->unnamed ? "response" : "{$rrd->name}response";
            } else {
                $this->linkid = "{$dtname}response"; // will not match
            }
            return true;
        } else {
            return false;
        }
    }

    function perm_view_document(Contact $user) {
        if ($this->paperId < 0) {
            $vis = $this->opt->visibility();
            if (($vis === PaperOption::VIS_ADMIN && !$user->privChair)
                || ($vis !== PaperOption::VIS_SUB && !$user->isPC)) {
                return $this->prow->failure_reason(["permission" => "field:view", "option" => $this->opt]);
            } else {
                return null;
            }
        } else if (($whynot = $user->perm_view_paper($this->prow, false, $this->paperId))) {
            return $whynot;
        } else if ($this->dtype === DTYPE_COMMENT) {
            return $this->perm_view_comment_document($user);
        } else if ($this->opt) {
            return $user->perm_view_option($this->prow, $this->opt);
        } else {
            return null;
        }
    }

    private function perm_view_comment_document(Contact $user) {
        $doc_crow = $cmtid = null;
        if (str_starts_with($this->linkid, "cx")
            && !str_ends_with($this->linkid, "response")) {
            $cmtid = intval(substr($this->linkid, 2));
        }
        foreach ($this->prow->viewable_comment_skeletons($user) as $crow) {
            if ($crow->unparse_html_id() === $this->linkid
                || $crow->commentId === $cmtid) {
                $doc_crow = $crow;
                break;
            }
        }
        if ($doc_crow
            && ($xdoc = $doc_crow->attachments()->document_by_filename($this->attachment))) {
            $this->docid = $xdoc->paperStorageId;
            return null;
        } else {
            return $this->prow->failure_reason(["documentNotFound" => $this->req_filename]);
        }
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
