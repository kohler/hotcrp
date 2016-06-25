<?php // -*- mode: php -*-
// doc -- HotCRP paper download page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// A PHP script where execution is specified in .htaccess, so the requested
// paper is specified as a suffix to the request.  It may help automatic file
// naming work for some browsers.

require_once("src/initweb.php");

function document_error($status, $msg) {
    global $Conf;
    header("HTTP/1.1 $status");
    $Conf->header("Download", null, actionBar());
    $msg && Conf::msg_error($msg);
    $Conf->footer();
    exit;
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
        } else if (preg_match(',\A(p|paper|final|)(\d+)-?([-A-Za-z0-9_]*)(?:\.[^/]+|/+(.*))\z,', $s, $m)) {
            list($paperId, $dtname, $attachment_filename) = [intval($m[2]), $m[3], get($m, 4)];
            if ($m[1] === "final")
                $base_dtname = "final";
        } else if (preg_match(',\A([A-Za-z_][-A-Za-z0-9_]*?)?-?(\d+)(?:\.[^/]+|/+(.*))\z,', $s, $m))
            list($paperId, $dtname, $attachment_filename) = [intval($m[2]), $m[1], get($m, 3)];
        else if (preg_match(',\A([^/]+?)(?:\.[^/]+|/+(.*)|)\z,', $s, $m))
            list($paperId, $dtname, $attachment_filename) = [-2, $m[1], get($m, 2)];

        $documentType = null;
        while ($dtname !== null && $documentType === null) {
            if ($paperId < 0)
                $documentType = PaperOption::match_nonpaper($dtname);
            else
                $documentType = HotCRPDocument::parse_dtype($dtname ? : $base_dtname);
            if ($documentType !== null)
                break;
            $filter = null;
            foreach (FileFilter::all() as $ff)
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
        || !($o = PaperOption::find_document($documentType))
        || ($attachment_filename && !$o->has_attachments())
        || $o->nonpaper !== ($paperId < 0))
        document_error("404 Not Found", "Unknown document “" . htmlspecialchars($orig_s) . "”.");

    if ($o->nonpaper) {
        $prow = new PaperInfo(["paperId" => -2]);
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

    $doc = null;
    if ($attachment_filename) {
        $oa = $prow->option($documentType);
        foreach ($oa ? $oa->documents($prow) : array() as $xdoc)
            if ($xdoc->unique_filename == $attachment_filename)
                $doc = $xdoc;
    } else
        $doc = $prow->document($documentType);
    if (!$doc)
        document_error("404 Not Found", "No such " . ($attachment_filename ? "attachment" : "document") . " “" . htmlspecialchars($orig_s) . "”.");

    // pass through filters
    foreach ($filters as $filter)
        $doc = $filter->apply($doc, $prow) ? : $doc;

    // check for If-Not-Modified
    if ($doc->sha1 && function_exists("getallheaders")) {
        foreach (getallheaders() as $k => $v)
            if (strcasecmp($k, "If-None-Match") == 0
                && $v === "\"" . Filer::text_sha1($doc) . "\"") {
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
