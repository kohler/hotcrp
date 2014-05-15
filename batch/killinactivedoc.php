<?php
require_once("src/init.php");

$storageIds = $Conf->active_document_ids();

$result = $Conf->qe("select paperStorageId, paperId, timestamp, mimetype,
        compression, sha1, documentType, filename, infoJson
        from PaperStorage where paperStorageId not in (" . join(",", $storageIds) . ")
        and paper is not null order by timestamp");
$killable = array();
while (($doc = $Conf->document_row($result, null)))
    $killable[$doc->paperStorageId] = "[" . $Conf->unparse_time_log($doc->timestamp)
        . "] " . HotCRPDocument::filename($doc) . " ($doc->paperStorageId)";

if (count($killable)) {
    fwrite(STDOUT, join("\n", $killable) . "\n\nKill "
           . plural($killable, "document") . "? (y/n) ");
    $x = fread(STDIN, 100);
    if (!preg_match('/\A[yY]/', $x))
        die("* Exiting\n");
    $Conf->qe("update PaperStorage set paper=NULL where paperStorageId in ("
        . join(",", array_keys($killable)) . ")");
    fwrite(STDOUT, count($killable) . " documents killed.\n");
} else
    fwrite(STDOUT, "Nothing to do\n");
