<?php // -*- mode: php -*-
// doc -- HotCRP paper download page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// A PHP script where execution is specified in .htaccess, so the requested
// paper is specified as a suffix to the request.  It may help automatic file
// naming work for some browsers.

require_once("src/initweb.php");

function document_error($error) {
    global $Conf;
    $Conf->header("Download");
    if ($error)
        $Conf->errorMsg($error);
    $Conf->footer();
    exit;
}

// Determine the intended paper
function document_download() {
    global $Conf, $Me, $Opt;

    $documentType = HotCRPDocument::parse_dtype(@$_REQUEST["dt"]);
    if ($documentType === null)
        $documentType = @$_REQUEST["final"] ? DTYPE_FINAL : DTYPE_SUBMISSION;
    $attachment_filename = false;
    $docid = null;

    if (isset($_REQUEST["p"]))
        $paperId = cvtint(@$_REQUEST["p"]);
    else if (isset($_REQUEST["paperId"]))
        $paperId = cvtint(@$_REQUEST["paperId"]);
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
            list($paperId, $dtname, $attachment_filename) = array(intval($m[1]), $m[2], @$m[3]);
        else if (preg_match(',\A([A-Za-z_][-A-Za-z0-9_]*?)?-?(\d+)(?:\.[^/]+|/+(.*))\z,', $s, $m))
            list($paperId, $dtname, $attachment_filename) = array(intval($m[2]), $m[1], @$m[3]);
        if ($dtname !== null)
            $documentType = HotCRPDocument::parse_dtype($dtname ? : "paper");
        if ($documentType !== null && $attachment_filename) {
            $o = PaperOption::find($documentType);
            if (!$o || $o->type != "attachments")
                $documentType = null;
        }
    }

    if ($documentType === null)
        document_error("Unknown document “" . htmlspecialchars($orig_s) . "”.");

    $prow = $Conf->paperRow($paperId, $Me, $whyNot);
    if (!$prow)
        document_error(whyNotText($whyNot, "view"));
    else if (($whyNot = $Me->perm_view_pdf($prow)))
        document_error(whyNotText($whyNot, "view"));
    else if ($documentType > 0
             && !$Me->can_view_paper_option($prow, $documentType, true))
        document_error("You don’t have permission to view this document.");

    if ($attachment_filename) {
        $oa = $prow->option($documentType);
        foreach ($oa ? $oa->documents($prow) : array() as $doc)
            if ($doc->unique_filename == $attachment_filename)
                $docid = $doc;
        if (!$docid)
            document_error("No such attachment “" . htmlspecialchars($orig_s) . "”.");
    }

    // Actually download paper.
    session_write_close();      // to allow concurrent clicks
    if ($Conf->downloadPaper($prow, cvtint(@$_REQUEST["save"]) > 0, $documentType, $docid))
        exit;

    document_error(null);
}

document_download();
