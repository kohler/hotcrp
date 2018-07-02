<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");

$arg = getopt("hakn:", array("help", "active", "kill", "name:"));
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/s3transfer.php [--active] [--kill]\n");
    exit(0);
}

$active = false;
if (isset($arg["a"]) || isset($arg["active"]))
    $active = array_flip($Conf->active_document_ids());
$kill = isset($arg["k"]) || isset($arg["kill"]);

if (!$Conf->setting_data("s3_bucket")) {
    fwrite(STDERR, "* S3 is not configured for this conference\n");
    exit(1);
}

$result = $Conf->qe_raw("select paperStorageId from PaperStorage where paperStorageId>1");
$sids = array();
while (($row = edb_row($result)))
    $sids[] = (int) $row[0];

$failures = 0;
foreach ($sids as $sid) {
    if ($active !== false && !isset($active[$sid]))
        continue;

    $result = $Conf->qe_raw("select paperStorageId, paperId, timestamp, mimetype,
        compression, sha1, documentType, filename, infoJson, paper
        from PaperStorage where paperStorageId=$sid");
    $doc = DocumentInfo::fetch($result, $Conf);
    Dbl::free($result);
    if ($doc->content === null && !$doc->load_docstore())
        continue;
    $front = "[" . $Conf->unparse_time_log($doc->timestamp) . "] "
        . $doc->export_filename() . " ($sid)";

    $chash = $doc->content_binary_hash($doc->binary_hash());
    if ($chash !== $doc->binary_hash()) {
        $saved = $checked = false;
        error_log("$front: S3 upload cancelled: data claims checksum " . $doc->text_hash()
                  . ", has checksum " . Filer::hash_as_text($chash));
    } else {
        $saved = $checked = $doc->check_s3();
        if (!$saved)
            $saved = $doc->store_s3();
        if (!$saved) {
            sleep(0.5);
            $saved = $doc->store_s3();
        }
    }

    if ($checked)
        fwrite(STDOUT, "$front: " . $doc->s3_key() . " exists\n");
    else if ($saved)
        fwrite(STDOUT, "$front: " . $doc->s3_key() . " saved\n");
    else {
        fwrite(STDOUT, "$front: SAVE FAILED\n");
        ++$failures;
    }
    if ($saved && $kill)
        $Conf->qe_raw("update PaperStorage set paper=null where paperStorageId=$sid");
}
if ($failures) {
    fwrite(STDERR, "Failed to save " . plural($failures, "document") . ".\n");
    exit(1);
}
