<?php
// documentrequest.php -- HotCRP document request parsing
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DocumentRequest implements JsonSerializable {
    /** @var int */
    public $paperId;
    /** @var ?PaperInfo */
    public $prow;
    public $dtype;
    /** @var ?PaperOption */
    public $opt;
    public $linkid;
    /** @var ?string */
    public $attachment;
    public $docid;
    public $filters = [];
    public $req_filename;

    private function set_paperid($pid) {
        if (preg_match('/\A[-+]?\d+\z/', $pid)) {
            $this->paperId = intval($pid);
        } else {
            throw new Exception("Document not found [submission $pid]");
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
            if (str_starts_with($dtname, "comment-")
                && preg_match('/\Acomment-(?:c[aAxX]?\d+|(?:|[a-zA-Z](?:|[-a-zA-Z0-9]*))response)\z/', $dtname)) {
                $this->dtype = DTYPE_COMMENT;
                $this->linkid = substr($dtname, 8);
                break;
            } else if ((str_starts_with($dtname, "response") || str_ends_with($dtname, "response"))
                       && preg_match('/\A[-a-zA-Z0-9]*\z/', $dtname)) {
                $this->dtype = DTYPE_COMMENT;
                $this->linkid = $dtname;
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
            if ($this->opt) {
                $dtname = $this->opt->dtype_name();
            }
            if ($this->paperId < 0) {
                $this->req_filename = "[$dtname";
            } else if ($this->dtype === DTYPE_SUBMISSION) {
                $this->req_filename = "[submission #{$this->paperId}";
            } else if ($this->dtype === DTYPE_FINAL) {
                $this->req_filename = "[submission #{$this->paperId} final version";
            } else {
                $this->req_filename = "[#{$this->paperId} $dtname";
            }
            if ($this->attachment) {
                $this->req_filename .= " attachment " . $this->attachment;
            }
            $this->req_filename .= "]";
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

    function perm_view_document(Contact $user) {
        if ($this->paperId < 0) {
            $vis = $this->opt->visibility();
            if (($vis === PaperOption::VIS_ADMIN && !$user->privChair)
                || ($vis !== PaperOption::VIS_SUB && !$user->isPC)) {
                return $this->prow->make_whynot(["permission" => "field:view", "option" => $this->opt]);
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
        if ($this->linkid[0] === "x" || $this->linkid[0] === "X") {
            $cmtid = (int) substr($this->linkid, 1);
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
            return $this->prow->make_whynot(["documentNotFound" => $this->req_filename]);
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
