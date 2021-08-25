<?php
require_once(dirname(__DIR__) . "/src/init.php");

$arg = getopt("hfn:", array("help", "force", "name:"));
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/killinactivedoc.php [--force]\n");
    exit(0);
}

$didmap = DocumentInfo::active_document_map($Conf);
$force = isset($arg["f"]) || isset($arg["force"]);

$result = $Conf->qe_raw("select paperStorageId, paperId, timestamp, mimetype,
        compression, sha1, documentType, filename, infoJson
        from PaperStorage where paperStorageId not in (" . join(",", array_keys($didmap)) . ")
        and paper is not null and paperStorageId>1 order by timestamp");
$killable = array();
while (($doc = DocumentInfo::fetch($result, $Conf)))
    $killable[$doc->paperStorageId] = "[" . $Conf->unparse_time_log($doc->timestamp)
        . "] " . $doc->export_filename() . " ($doc->paperStorageId)";

if (count($killable)) {
    fwrite(STDOUT, join("\n", $killable) . "\n");
    if (!$force) {
        fwrite(STDOUT, "\nKill " . plural(count($killable), "document") . "? (y/n) ");
        $x = fread(STDIN, 100);
        if (!preg_match('/\A[yY]/', $x)) {
            die("* Exiting\n");
        }
    }
    $Conf->qe_raw("update PaperStorage set paper=NULL where paperStorageId in ("
        . join(",", array_keys($killable)) . ")");
    fwrite(STDOUT, count($killable) . " documents killed.\n");
} else {
    fwrite(STDOUT, "Nothing to do\n");
}
