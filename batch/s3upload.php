<?php
require_once("src/init.php");

if (!$Conf->setting_data("s3_bucket"))
    die("* S3 is not configured for this conference\n");

$result = $Conf->qe("select paperStorageId from PaperStorage where paperStorageId>1");
$sids = array();
while (($row = edb_row($result)))
    $sids[] = (int) $row[0];

$failures = 0;
foreach ($sids as $sid) {
    $result = $Conf->qe("select paperStorageId, paperId, timestamp, mimetype,
        compression, sha1, documentType, filename, infoJson
        from PaperStorage where paperStorageId=$sid");
    $doc = $Conf->document_row($result, null);
    $checked = $doc->docclass->s3_check($doc);
    $saved = $checked || $doc->docclass->s3_store($doc, $doc);
    $front = "[" . $Conf->unparse_time_log($doc->timestamp) . "] "
        . HotCRPDocument::filename($doc) . " ($sid)";
    if ($checked)
        fwrite(STDOUT, "$front: already on S3\n");
    else if ($saved)
        fwrite(STDOUT, "$front: saved\n");
    else {
        fwrite(STDOUT, "$front: SAVE FAILED\n");
        ++$failures;
    }
}
if ($failures) {
    fwrite(STDERR, "Failed to save " . plural($failures, "document") . ".\n");
    exit(1);
}