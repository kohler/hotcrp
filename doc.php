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

if (isset($_REQUEST["p"]))
    $paperId = rcvtint($_REQUEST["p"]);
else if (isset($_REQUEST["paperId"]))
    $paperId = rcvtint($_REQUEST["paperId"]);
else {
    $s = $orig_s = preg_replace(',\A/*,', "", $_SERVER["PATH_INFO"]);
    if (str_starts_with($s, $Opt["downloadPrefix"]))
        $s = substr($s, strlen($Opt["downloadPrefix"]));
    if (preg_match(',\Apaper([1-9]\d*)-(.*)\.(.+)\z,', $s, $m)) {
        $paperId = $m[1];
        $documentType = requestDocumentType($m[2], null);
    } else if (preg_match(',\A([-A-Za-z0-9_]*?)?-?([1-9]\d*)\..*\z,', $s, $m)) {
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
if (!isset($Error) && !$Me->canDownloadPaper($paperId, $whyNot))
    $Error = whyNotText($whyNot, "view");
if ($documentType > 0 && !$Me->canViewPaperOption($paperId, $documentType))
    $Error = "You don’t have permission to view this document.";

// Actually download paper.
if (!isset($Error)) {
    session_write_close();	// to allow concurrent clicks
    $result = $Conf->downloadPaper($paperId, rcvtint($_REQUEST["save"]) > 0, $documentType);
    if ($result === true)
	exit;
}

// If we get here, there is an error.
$Conf->header("Download Paper" . (isset($paperId) ? " #$paperId" : ""));
if (isset($Error))
    $Conf->errorMsg($Error);
$Conf->footer();
