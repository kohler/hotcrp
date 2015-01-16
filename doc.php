<?php // -*- mode: php -*-
// doc -- HotCRP paper download page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// A PHP script where execution is specified in .htaccess, so the requested
// paper is specified as a suffix to the request.  It may help automatic file
// naming work for some browsers.

require_once("src/initweb.php");

// Determine the intended paper
$documentType = HotCRPDocument::parse_dtype(@$_REQUEST["dt"]);
if ($documentType === null)
    $documentType = @$_REQUEST["final"] ? DTYPE_FINAL : DTYPE_SUBMISSION;
$need_docid = false;
$docid = null;

if (isset($_REQUEST["p"]))
    $paperId = cvtint(@$_REQUEST["p"]);
else if (isset($_REQUEST["paperId"]))
    $paperId = cvtint(@$_REQUEST["paperId"]);
else {
    $s = $orig_s = preg_replace(',\A/*,', "", Navigation::path());
    if (str_starts_with($s, $Opt["downloadPrefix"]))
        $s = substr($s, strlen($Opt["downloadPrefix"]));
    if (preg_match(',\Ap(?:aper)?([1-9]\d*)/+(.*)\z,', $s, $m)) {
        $paperId = $m[1];
        $s = $m[2];
        if (preg_match(',\A([^/]*)\.[^/]+\z,', $s, $m))
            $documentType = HotCRPDocument::parse_dtype($m[1]);
        else if (preg_match(',\A([^/]+)/+(.*)\z,', $s, $m)) {
            $documentType = HotCRPDocument::parse_dtype($m[1]);
            if ($documentType
                && ($o = PaperOption::find($documentType))
                && $o->type == "attachments") {
                $need_docid = false;
                $result = $Conf->q("select o.value from PaperOption o join PaperStorage s on (s.paperStorageId=o.value) where o.paperId=$paperId and o.optionId=$documentType and s.filename='" . sqlq($m[2]) . "'", "while searching for attachment");
                if (($row = edb_row($result)))
                    $docid = $row[0];
            }
        }
    } else if (preg_match(',\Apaper([1-9]\d*)-([^/]*)\.[^/]+\z,', $s, $m)) {
        $paperId = $m[1];
        $documentType = HotCRPDocument::parse_dtype($m[2]);
    } else if (preg_match(',\A([-A-Za-z0-9_]*?)?-?([1-9]\d*)\.[^/]*\z,', $s, $m)) {
        $paperId = $m[2];
        $documentType = HotCRPDocument::parse_dtype($m[1]);
    } else
        $documentType = null;
    if ($documentType === null)
        $Error = "Unknown document “" . htmlspecialchars($orig_s) . "”.";
}

if (!isset($Error)
    && !($prow = $Conf->paperRow($paperId, $Me, $whyNot)))
    $Error = whyNotText($whyNot, "view");
if (!isset($Error) && ($whyNot = $Me->perm_view_pdf($prow)))
    $Error = whyNotText($whyNot, "view");
if (!isset($Error) && $documentType > 0
    && !$Me->can_view_paper_option($prow, $documentType, true))
    $Error = "You don’t have permission to view this document.";
if (!isset($Error) && $need_docid && !$docid)
    $Error = "No such attachment.";

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
