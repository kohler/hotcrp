<?php // -*- mode: php -*-
// doc -- HotCRP paper download page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// A PHP script where execution is specified in .htaccess, so the requested
// paper is specified as a suffix to the request.  It may help automatic file
// naming work for some browsers.

require_once("src/initweb.php");

// Determine the intended paper
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
        list($paperId, $dtname, $attachment_filename) = array(intval($m[1]), $m[2], $m[3]);
    else if (preg_match(',\A([A-Za-z_][-A-Za-z0-9_]*?)?-?(\d+)(?:\.[^/]+|/+(.*))\z,', $s, $m))
        list($paperId, $dtname, $attachment_filename) = array(intval($m[2]), $m[1], $m[3]);
    if ($dtname !== null)
        $documentType = HotCRPDocument::parse_dtype($dtname ? : "paper");
    if ($documentType !== null && $attachment_filename) {
        $o = PaperOption::find($documentType);
        if ($o && $o->type == "attachments") {
            $result = Dbl::q("select o.value from PaperOption o join PaperStorage s on (s.paperStorageId=o.value) where o.paperId=$paperId and o.optionId=$documentType and s.filename=?", $attachment_filename);
            if ($result && ($row = $result->fetch_row()))
                $docid = $row[0];
        } else
            $documentType = null;
    }
}

if (!isset($Error) && $documentType === null)
    $Error = "Unknown document “" . htmlspecialchars($orig_s) . "”.";
if (!isset($Error)
    && !($prow = $Conf->paperRow($paperId, $Me, $whyNot)))
    $Error = whyNotText($whyNot, "view");
if (!isset($Error) && ($whyNot = $Me->perm_view_pdf($prow)))
    $Error = whyNotText($whyNot, "view");
if (!isset($Error) && $documentType > 0
    && !$Me->can_view_paper_option($prow, $documentType, true))
    $Error = "You don’t have permission to view this document.";
if (!isset($Error) && $attachment_filename && !$docid)
    $Error = "No such attachment “" . htmlspecialchars($orig_s) . "”.";

// Actually download paper.
if (!isset($Error)) {
    session_write_close();      // to allow concurrent clicks
    if ($Conf->downloadPaper($prow, cvtint(@$_REQUEST["save"]) > 0, $documentType, $docid))
        exit;
}

// If we get here, there is an error.
$Conf->header("Download");
if (isset($Error))
    $Conf->errorMsg($Error);
$Conf->footer();
