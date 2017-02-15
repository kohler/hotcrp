<?php // -*- mode: php -*-
// doc -- HotCRP paper download page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

function document_error($status, $msg) {
    global $Conf;
    header("HTTP/1.1 $status");
    if (isset($_GET["fn"]))
        json_exit(["ok" => false, "error" => $msg ? : "Internal error."]);
    else {
        $Conf->header("Download", null, actionBar());
        $msg && Conf::msg_error($msg);
        $Conf->footer();
        exit;
    }
}

// Determine the intended paper
function document_download() {
    global $Conf, $Me;

    $documentType = HotCRPDocument::parse_dtype(req("dt"));
    if ($documentType === null)
        $documentType = req("final") ? DTYPE_FINAL : DTYPE_SUBMISSION;
    $attachment_filename = false;
    $docid = null;
    $filters = [];

    if (isset($_GET["p"]))
        $paperId = cvtint($_GET["p"]);
    else if (isset($_GET["paperId"]))
        $paperId = cvtint($_GET["paperId"]);
    else {
        $s = $orig_s = preg_replace(',\A/*,', "", Navigation::path());
        $dtname = null;
        $base_dtname = "paper";
        if (str_starts_with($s, $Conf->download_prefix))
            $s = substr($s, strlen($Conf->download_prefix));
        if (preg_match(',\A(?:p|paper|)(\d+)/+(.*)\z,', $s, $m)) {
            $paperId = intval($m[1]);
            if (preg_match(',\A([^/]+)\.[^/]+\z,', $m[2], $mm))
                $dtname = $mm[1];
            else if (preg_match(',\A([^/]+)/+(.*)\z,', $m[2], $mm))
                list($dtname, $attachment_filename) = array($m[1], $m[2]);
        } else if (preg_match(',\A(p|paper|final|)(\d+)-?([-A-Za-z0-9_]*)(?:|\.[^/]+|/+(.*))\z,', $s, $m)) {
            list($paperId, $dtname, $attachment_filename) = [intval($m[2]), $m[3], get($m, 4)];
            if ($m[1] === "final")
                $base_dtname = "final";
        } else if (preg_match(',\A([A-Za-z_][-A-Za-z0-9_]*?)?-?(\d+)(?:|\.[^/]+|/+(.*))\z,', $s, $m))
            list($paperId, $dtname, $attachment_filename) = [intval($m[2]), $m[1], get($m, 3)];
        else if (preg_match(',\A([^/]+?)(?:|\.[^/]+|/+(.*)|)\z,', $s, $m))
            list($paperId, $dtname, $attachment_filename) = [-2, $m[1], get($m, 2)];

        $documentType = null;
        while ($dtname !== null && $documentType === null) {
            if ($paperId < 0)
                $documentType = $Conf->paper_opts->match_nonpaper($dtname);
            else
                $documentType = HotCRPDocument::parse_dtype($dtname ? : $base_dtname);
            if ($documentType !== null)
                break;
            $filter = null;
            foreach (FileFilter::all_by_name() as $ff)
                if (str_ends_with($dtname, "-" . $ff->name) || $dtname === $ff->name) {
                    $filter = $ff;
                    break;
                }
            if (!$filter)
                break;
            array_unshift($filters, $filter);
            $dtname = substr($dtname, 0, strlen($dtname) - strlen($ff->name));
            if (str_ends_with($dtname, "-"))
                $dtname = substr($dtname, 0, strlen($dtname) - 1);
        }
        if (is_object($documentType))
            $documentType = $documentType->id;
    }

    if (isset($_GET["filter"])) {
        foreach (explode(" ", $_GET["filter"]) as $filtername)
            if ($filtername && ($filter = FileFilter::find_by_name($filtername)))
                $filters[] = $filter;
    }

    if ($documentType === null
        || !($o = $Conf->paper_opts->find_document($documentType))
        || ($attachment_filename && !$o->has_attachments())
        || $o->nonpaper !== ($paperId < 0))
        document_error("404 Not Found", "Unknown document “" . htmlspecialchars($orig_s) . "”.");

    if ($o->nonpaper) {
        $prow = new PaperInfo(["paperId" => -2], null, $Conf);
        if (($o->visibility === "admin" && !$Me->privChair)
            || ($o->visibility !== "all" && !$Me->isPC))
            document_error("403 Forbidden", "You don’t have permission to view this document.");
    } else {
        $prow = $Conf->paperRow($paperId, $Me, $whyNot);
        if (!$prow)
            document_error("404 Not Found", whyNotText($whyNot, "view"));
        else if (($whyNot = $Me->perm_view_pdf($prow)))
            document_error("403 Forbidden", whyNotText($whyNot, "view"));
        else if ($documentType > 0
                 && !$Me->can_view_paper_option($prow, $documentType, true))
            document_error("403 Forbidden", "You don’t have permission to view this document.");
    }

    // history
    if (isset($_GET["fn"]) && $_GET["fn"] === "history") {
        $docs = [];
        if ($o->has_attachments()) {
            if (($oa = $prow->option($documentType)))
                $docs = $oa->documents($prow);
        } else if (($doc = $prow->document($documentType, 0, true)))
            $docs = [$doc];

        $pjs = $actives = [];
        foreach ($docs as $doc) {
            $pj = ["sha1" => Filer::text_sha1($doc), "at" => (int) $doc->timestamp, "mimetype" => $doc->mimetype];
            if ($doc->size !== null)
                $pj["size"] = (int) $doc->size;
            if ($doc->filename)
                $pj["filename"] = $doc->filename;
            $pj["active"] = true;
            $actives[$doc->paperStorageId] = true;
            $pjs[] = $pj;
        }

        if ($Me->can_view_document_history($prow)) {
            $result = $Conf->qe("select paperStorageId, paperId, timestamp, mimetype, sha1, filename, infoJson, size from PaperStorage where paperId=? and documentType=? and filterType is null order by paperStorageId desc", $paperId, $documentType);
            while (($row = edb_orow($result))) {
                if (get($actives, $row->paperStorageId))
                    continue;
                $pj = ["sha1" => Filer::text_sha1($row), "at" => (int) $row->timestamp, "mimetype" => $row->mimetype];
                if ($row->size !== null)
                    $pj["size"] = (int) $row->size;
                if ($row->filename)
                    $pj["filename"] = $row->filename;
                $pjs[] = $pj;
            }
        }

        json_exit(["ok" => true, "result" => $pjs]);
    }

    $want_docid = $request_docid = 0;
    if (isset($_GET["version"])) {
        $version_sha1 = Filer::binary_sha1($_GET["version"]);
        if (!$version_sha1)
            document_error("404 Not Found", "No such version.");
        $want_docid = $Conf->fetch_ivalue("select max(paperStorageId) from PaperStorage where paperId=? and documentType=? and sha1=? and filterType is null", $paperId, $documentType, $version_sha1);
        if ($want_docid !== null && $Me->can_view_document_history($prow))
            $request_docid = $want_docid;
    }

    $doc = null;
    if ($attachment_filename) {
        $oa = $prow->option($documentType);
        foreach ($oa ? $oa->documents($prow) : array() as $xdoc)
            if ($xdoc->unique_filename == $attachment_filename)
                $doc = $xdoc;
    } else
        $doc = $prow->document($documentType, $request_docid);
    if ($want_docid !== 0 && (!$doc || $doc->paperStorageId != $want_docid))
        document_error("404 Not Found", "No such version.");
    else if (!$doc)
        document_error("404 Not Found", "No such " . ($attachment_filename ? "attachment" : "document") . " “" . htmlspecialchars($orig_s) . "”.");

    // pass through filters
    foreach ($filters as $filter)
        $doc = $filter->apply($doc, $prow) ? : $doc;

    // check for contents request
    if (isset($_GET["fn"]) && ($_GET["fn"] === "listing" || $_GET["fn"] === "consolidatedlisting")) {
        if (!$doc->docclass->is_archive($doc))
            json_exit(["ok" => false, "error" => "That file is not an archive."]);
        else if (($listing = $doc->docclass->archive_listing($doc)) === false)
            json_exit(["ok" => false, "error" => isset($doc->error) ? $doc->error_text : "Internal error."]);
        else {
            $listing = $doc->docclass->clean_archive_listing($listing);
            if ($_GET["fn"] == "consolidatedlisting")
                $listing = join(", ", $doc->docclass->consolidate_archive_listing($listing));
            json_exit(["ok" => true, "result" => $listing]);
        }
    }

    // check for If-Not-Modified
    if ($doc->sha1) {
        $ifnonematch = null;
        if (function_exists("getallheaders")) {
            foreach (getallheaders() as $k => $v)
                if (strcasecmp($k, "If-None-Match") == 0)
                    $ifnonematch = $v;
        } else
            $ifnonematch = get($_SERVER, "HTTP_IF_NONE_MATCH");
        if ($ifnonematch && $ifnonematch === "\"" . Filer::text_sha1($doc) . "\"") {
            header("HTTP/1.1 304 Not Modified");
            exit;
        }
    }

    // Actually download paper.
    session_write_close();      // to allow concurrent clicks
    $opts = ["attachment" => cvtint(req("save")) > 0];
    if ($doc->sha1 && ($x = req("sha1")) && $x === Filer::text_sha1($doc))
        $opts["cacheable"] = true;
    if ($Conf->download_documents([$doc], $opts))
        exit;

    document_error("500 Server Error", null);
}

document_download();
