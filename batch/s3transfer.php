<?php

$arg = getopt("hakm:n:", array("help", "active", "kill", "name:", "match:"));
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/s3transfer.php [--active] [--kill] [-m MATCH]\n");
    exit(0);
}

require_once(dirname(__DIR__) . "/src/init.php");

$active = false;
if (isset($arg["a"]) || isset($arg["active"])) {
    $active = DocumentInfo::active_document_map($Conf);
}
$kill = isset($arg["k"]) || isset($arg["kill"]);
$match = false;
if (isset($arg["m"]) || isset($arg["match"])) {
    $match = new DocumentHashMatcher(isset($arg["m"]) ? $arg["m"] : $arg["match"]);
}

if (!$Conf->setting_data("s3_bucket")) {
    fwrite(STDERR, "* S3 is not configured for this conference\n");
    exit(1);
}

$result = $Conf->qe_raw("select paperStorageId, sha1 from PaperStorage where paperStorageId>1");
$sids = array();
while (($row = $result->fetch_row())) {
    if (!$match || $match->test_hash(Filer::hash_as_text($row[1])))
        $sids[] = (int) $row[0];
}
Dbl::free($result);

Filer::$no_touch = true;
$failures = 0;
foreach ($sids as $sid) {
    if ($active !== false && !isset($active[$sid])) {
        continue;
    }

    $result = $Conf->qe_raw("select paperStorageId, paperId, timestamp, mimetype,
        compression, sha1, documentType, filename, infoJson, paper
        from PaperStorage where paperStorageId=$sid");
    $doc = DocumentInfo::fetch($result, $Conf);
    Dbl::free($result);
    if ($doc->content === null && !$doc->load_docstore()) {
        continue;
    }
    $front = "[" . $Conf->unparse_time_log($doc->timestamp) . "] "
        . $doc->export_filename(DocumentInfo::ANY_MEMBER_FILENAME) . " ($sid)";

    $chash = $doc->content_binary_hash($doc->binary_hash());
    if ($chash !== $doc->binary_hash()) {
        $saved = $checked = false;
        error_log("$front: S3 upload cancelled: data claims checksum " . $doc->text_hash()
                  . ", has checksum " . Filer::hash_as_text($chash));
    } else {
        $saved = $checked = $doc->check_s3();
        if (!$saved) {
            $saved = $doc->store_s3();
        }
        if (!$saved) {
            usleep(500000);
            $saved = $doc->store_s3();
        }
    }

    if ($checked) {
        fwrite(STDOUT, "$front: " . $doc->s3_key() . " exists\n");
    } else if ($saved) {
        fwrite(STDOUT, "$front: " . $doc->s3_key() . " saved\n");
    } else {
        fwrite(STDOUT, "$front: SAVE FAILED\n");
        ++$failures;
    }
    if ($saved && $kill) {
        $Conf->qe_raw("update PaperStorage set paper=null where paperStorageId=$sid");
    }
}
if ($failures) {
    fwrite(STDERR, "Failed to save " . plural($failures, "document") . ".\n");
    exit(1);
}
