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
    global $Conf, $Me, $Opt;

    $documentType = HotCRPDocument::parse_dtype(req("dt"));
    if ($documentType === null)
        $documentType = req("final") ? DTYPE_FINAL : DTYPE_SUBMISSION;
    $attachment_filename = false;
    $docid = null;

    if (isset($_GET["p"]))
        $paperId = cvtint($_GET["p"]);
    else if (isset($_GET["paperId"]))
        $paperId = cvtint($_GET["paperId"]);
    else {
        $s = $orig_s = preg_replace(',\A/*,', "", Navigation::path());
        $documentType = $dtname = null;
        if (str_starts_with($s, $Opt["downloadPrefix"]))
            $s = substr($s, strlen($Opt["downloadPrefix"]));
        if (preg_match(',\Ap(?:aper)?(\d+)/+(.*)\z,', $s, $m)) {
            $paperId = intval($m[1]);
            if (preg_match(',\A([^/]+)\.[^/]+\z,', $m[2], $mm))
                $dtname = $mm[1];
            else if (preg_match(',\A([^/]+)/+(.*)\z,', $m[2], $mm))
                list($dtype, $attachment_filename) = array($m[1], $m[2]);
        } else if (preg_match(',\A(?:paper)?(\d+)-?([-A-Za-z0-9_]*)(?:\.[^/]+|/+(.*))\z,', $s, $m))
            list($paperId, $dtname, $attachment_filename) = [intval($m[1]), $m[2], get($m, 3)];
        else if (preg_match(',\A([A-Za-z_][-A-Za-z0-9_]*?)?-?(\d+)(?:\.[^/]+|/+(.*))\z,', $s, $m))
            list($paperId, $dtname, $attachment_filename) = [intval($m[2]), $m[1], get($m, 3)];
        else if (preg_match(',\A([^/]+?)(?:\.[^/]+|/+(.*))\z,', $s, $m)
                 && ($nonpaper_options = PaperOption::search_nonpaper($m[1]))
                 && count($nonpaper_options) == 1) {
            list($paperId, $attachment_filename) = [-2, get($m, 2)];
            $documentType = key($nonpaper_options);
        }
        if ($dtname !== null)
            $documentType = HotCRPDocument::parse_dtype($dtname ? : "paper");
    }

    if ($documentType === null
        || !($o = PaperOption::find_document($documentType))
        || ($attachment_filename && $o->type != "attachments")
        || $o->nonpaper !== ($paperId < 0))
        document_error("404 Not Found", "Unknown document “" . htmlspecialchars($orig_s) . "”.");

    if ($o->nonpaper) {
        $prow = new PaperInfo(["paperId" => -2, "optionIds" => $o->id . "#" . $Conf->setting($o->abbr, 0)]);
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

    // Actually download paper.
    session_write_close();      // to allow concurrent clicks
    if ($Conf->download_documents([$doc], cvtint($_GET["save"]) > 0))
        exit;

    document_error("500 Server Error", null);
}

document_download();
