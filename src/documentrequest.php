<?php
// documentrequest.php -- HotCRP document request parsing
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class DocumentRequest {
    public $paperId;
    public $prow;
    public $dtype;
    public $opt;
    public $linkid;
    public $attachment;
    public $docid;
    public $filters = [];
    public $req_filename;

    private function set_paperid($pid) {
        if (preg_match('/\A[-+]?\d+\z/', $pid)) {
            $this->paperId = intval($pid);
        } else {
            throw new Exception("Document not found [submission $pid].");
        }
    }

    function __construct($req, $path, Conf $conf) {
        $want_path = false;
        if (isset($req["p"])) {
            $this->set_paperid($req["p"]);
        } else if (isset($req["paperId"])) {
            $this->set_paperid($req["paperId"]);
        } else {
            $want_path = true;
        }

        $dtname = null;
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
            $s = $this->req_filename = preg_replace(',\A/*,', "", $path);
            $dtname = null;
            if (str_starts_with($s, $conf->download_prefix)) {
                $s = substr($s, strlen($conf->download_prefix));
            }
            if (preg_match(',\A(?:p|paper|sub|submission)(\d+)/+(.*)\z,', $s, $m)) {
                $this->paperId = intval($m[1]);
                if (preg_match(',\A([^/]+)\.[^/]+\z,', $m[2], $mm)) {
                    $dtname = urldecode($mm[1]);
                } else if (preg_match(',\A([^/]+)/+(.*)\z,', $m[2], $mm)) {
                    $dtname = urldecode($mm[1]);
                    $this->attachment = urldecode($mm[2]);
                } else if (isset($req["dt"])) {
                    $dtname = $req["dt"];
                }
            } else if (preg_match(',\A(p|paper|sub|submission|final|)(\d+)-?([-A-Za-z0-9_]*)(?:|\.[^/]+|/+(.*))\z,', $s, $m)) {
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
            } else if (preg_match(',\A([A-Za-z_][-A-Za-z0-9_]*?)?-?(\d+)(?:|\.[^/]+|/+(.*))\z,', $s, $m)) {
                $this->paperId = intval($m[2]);
                $dtname = $m[1];
                if (isset($m[3])) {
                    $this->attachment = urldecode($m[3]);
                }
            } else if (preg_match(',\A([^/]+?)(?:|\.[^/]+|/+(.*)|)\z,', $s, $m)) {
                $this->paperId = -2;
                $dtname = $m[1];
                if (isset($m[2])) {
                    $this->attachment = urldecode($m[2]);
                }
            } else {
                throw new Exception("Document “{$this->req_filename}” not found.");
            }
        }

        // parse options and filters
        $this->opt = $this->dtype = null;
        while ((string) $dtname !== "" && $this->dtype === null) {
            if (str_starts_with($dtname, "comment-")
                && preg_match('{\Acomment-(?:c[aAxX]?\d+|(?:|[a-zA-Z](?:|[-a-zA-Z0-9]*))response)\z}', $dtname)) {
                $this->dtype = DTYPE_COMMENT;
                $this->linkid = substr($dtname, 8);
                break;
            } else if ((str_starts_with($dtname, "response") || str_ends_with($dtname, "response"))
                       && preg_match('{\A[-a-zA-Z0-9]*\z}', $dtname)) {
                $this->dtype = DTYPE_COMMENT;
                $this->linkid = $dtname;
                break;
            }
            if (($dtnum = cvtint($dtname, null)) !== null) {
                $this->opt = $conf->paper_opts->get($dtnum);
            } else if ($this->paperId >= 0) {
                $this->opt = $conf->paper_opts->find($dtname);
            } else {
                $this->opt = $conf->paper_opts->find_nonpaper($dtname);
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
            $dtname = substr($dtname, 0, strlen($dtname) - strlen($ff->name));
            if (str_ends_with($dtname, "-")) {
                $dtname = substr($dtname, 0, strlen($dtname) - 1);
            }
        }

        // if nothing found, use the base
        if ($this->dtype === null && (string) $dtname === "") {
            $this->opt = $conf->paper_opts->find($base_dtname);
            $this->dtype = $this->opt->id;
        } else if ($this->dtype === null) {
            throw new Exception("Document “{$dtname}” not found.");
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
                    if (($filter = FileFilter::find_by_name($filtername)))
                        $this->filters[] = $filter;
                    else
                        throw new Exception("Document filter “{$filter}” not found.");
                }
            }
        }

        if (!$want_path) {
            if ($this->opt) {
                $dtname = $this->opt->dtype_name();
            }
            if ($this->paperId < 0) {
                $this->req_filename = "[$dtype_name";
            } else if ($this->dtype === DTYPE_SUBMISSION) {
                $this->req_filename = "[submission #{$this->paperId}";
            } else if ($this->dtype === DTYPE_FINAL) {
                $this->req_filename = "[submission #{$this->paperId} final version";
            } else {
                $this->req_filename = "[#{$this->paperId} $dtype_name";
            }
            if ($this->attachment) {
                $this->req_filename .= " attachment " . $this->attachment;
            }
            $this->req_filename .= "]";
        }

        if ($this->dtype === null
            || ($this->opt && $this->opt->nonpaper) !== ($this->paperId < 0)) {
            throw new Exception("Document “{$this->req_filename}” not found.");
        }
    }

    function perm_view_document(Contact $user) {
        if ($this->paperId < 0) {
            $this->prow = new PaperInfo(["paperId" => -2], null, $user->conf);
            if (($this->opt->visibility === "admin" && !$user->privChair)
                || ($this->opt->visibility !== "all" && !$user->isPC)) {
                return $this->prow->make_whynot(["permission" => "view_option", "optionPermission" => $this->opt]);
            } else {
                return null;
            }
        }

        $this->prow = $user->conf->fetch_paper($this->paperId, $user);
        if (($whynot = $user->perm_view_paper($this->prow, false, $this->paperId))) {
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
        if (!$doc_crow) {
            return $this->prow->make_whynot(["documentNotFound" => $this->req_filename]);
        }

        foreach ($doc_crow->attachments() as $xdoc) {
            if ($xdoc->unique_filename === $this->attachment) {
                $this->docid = $xdoc->paperStorageId;
                break;
            }
        }
        if (!$this->docid) {
            return $this->prow->make_whynot(["documentNotFound" => $this->req_filename]);
        } else {
            return null;
        }
    }
}
