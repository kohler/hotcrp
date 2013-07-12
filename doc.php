<?php // -*- mode: php -*-
// doc -- HotCRP paper download page
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// A PHP script where execution is specified in .htaccess, so the requested
// paper is specified as a suffix to the request.  It may help automatic file
// naming work for some browsers.

require_once("Code/header.inc");
$Me->goIfInvalid();

// Determine the intended paper
$documentType = requestDocumentType($_REQUEST);
$need_docid = false;
$docid = null;

if (isset($_REQUEST["p"]))
    $paperId = rcvtint($_REQUEST["p"]);
else if (isset($_REQUEST["paperId"]))
    $paperId = rcvtint($_REQUEST["paperId"]);
else {
    $s = $orig_s = preg_replace(',\A/*,', "", $_SERVER["PATH_INFO"]);
    if (str_starts_with($s, $Opt["downloadPrefix"]))
        $s = substr($s, strlen($Opt["downloadPrefix"]));
    if (preg_match(',\Ap(?:aper)?([1-9]\d*)/+(.*)\z,', $s, $m)) {
        $paperId = $m[1];
        $s = $m[2];
        if (preg_match(',\A([^/]*)\.[^/]+\z,', $s, $m))
            $documentType = requestDocumentType($m[1], null);
        else if (preg_match(',\A([^/]+)/+(.*)\z,', $s, $m)) {
            $documentType = requestDocumentType($m[1], null);
            if ($documentType && ($o = paperOptions($documentType)) && $o->type == PaperOption::T_ATTACHMENTS) {
                $need_docid = false;
                $result = $Conf->q("select o.value from PaperOption o join PaperStorage s on (s.paperStorageId=o.value) where o.paperId=$paperId and o.optionId=$documentType and s.filename='" . sqlq($m[2]) . "'", "while searching for attachment");
                if (($row = edb_row($result)))
                    $docid = $row[0];
            }
        }
    } else if (preg_match(',\Apaper([1-9]\d*)-([^/]*)\.[^/]+\z,', $s, $m)) {
        $paperId = $m[1];
        $documentType = requestDocumentType($m[2], null);
    } else if (preg_match(',\A([-A-Za-z0-9_]*?)?-?([1-9]\d*)\.[^/]*\z,', $s, $m)) {
        $paperId = $m[2];
        $documentType = requestDocumentType($m[1], null);
    } else
        $documentType = null;
    if ($documentType === null)
        $Error = "Unknown document “" . htmlspecialchars($orig_s) . "”.";
}

// Security checks - people who can download all paperss
// are assistants, chairs & PC members. Otherwise, you need
// to be a contact person for that paper.
if (!isset($Error)
    && !($prow = $Conf->paperRow($paperId, $Me->contactId, $whyNot)))
    $Error = whyNotText($whyNot, "view");
if (!isset($Error) && !$Me->canDownloadPaper($prow, $whyNot))
    $Error = whyNotText($whyNot, "view");
if (!isset($Error) && $documentType > 0
    && !$Me->canViewPaperOption($prow, $documentType, true))
    $Error = "You don’t have permission to view this document.";
if (!isset($Error) && $need_docid && !$docid)
    $Error = "No such attachment.";

// Actually download paper.
if (!isset($Error)) {
    session_write_close();	// to allow concurrent clicks
    if ($Conf->downloadPaper($prow, rcvtint($_REQUEST["save"]) > 0, $documentType, $docid))
	exit;
}

// If we get here, there is an error.
$Conf->header("Download");
if (isset($Error))
    $Conf->errorMsg($Error);
$Conf->footer();
