<?php
require_once("src/init.php");

$arg = getopt("hakn:", array("help", "active", "kill", "name:"));
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/s3upload.php [--active] [--kill]\n");
    exit(0);
}

$active = false;
if (isset($arg["a"]) || isset($arg["active"]))
    $active = array_flip($Conf->active_document_ids());
$kill = isset($arg["k"]) || isset($arg["kill"]);

if (!$Conf->setting_data("s3_bucket"))
    die("* S3 is not configured for this conference\n");

$result = $Conf->qe("select paperStorageId from PaperStorage where paperStorageId>1");
$sids = array();
while (($row = edb_row($result)))
    $sids[] = (int) $row[0];

$failures = 0;
foreach ($sids as $sid) {
    if ($active !== false && !isset($active[$sid]))
        continue;
    $result = $Conf->qe("select paperStorageId, paperId, timestamp, mimetype,
        compression, sha1, documentType, filename, infoJson
        from PaperStorage where paperStorageId=$sid");
    $doc = $Conf->document_row($result, null);
    $checked = $doc->docclass->s3_check($doc);
    $saved = $checked || $doc->docclass->s3_store($doc, $doc);
    $front = "[" . $Conf->unparse_time_log($doc->timestamp) . "] "
        . HotCRPDocument::filename($doc) . " ($sid)";
    if ($checked)
        fwrite(STDOUT, "$front: " . HotCRPDocument::s3_filename($doc) . " exists\n");
    else if ($saved)
        fwrite(STDOUT, "$front: " . HotCRPDocument::s3_filename($doc) . " saved\n");
    else {
        fwrite(STDOUT, "$front: SAVE FAILED\n");
        ++$failures;
    }
    if ($saved && $kill)
        $Conf->qe("update PaperStorage set paper=null where paperStorageId=$sid");
}
if ($failures) {
    fwrite(STDERR, "Failed to save " . plural($failures, "document") . ".\n");
    exit(1);
}
