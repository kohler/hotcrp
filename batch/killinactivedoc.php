<?php
// killinactivedoc.php -- HotCRP paper export script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(KillInactiveDoc_Batch::make_args($argv)->run());
}

class KillInactiveDoc_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $force;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->force = isset($arg["force"]);
    }

    /** @return int */
    function run() {
        $didmap = DocumentInfo::active_document_map($this->conf);
        $result = $this->conf->qe_raw("select paperStorageId, paperId, timestamp, mimetype,
                compression, sha1, documentType, filename, infoJson
                from PaperStorage where paperStorageId not in (" . join(",", array_keys($didmap)) . ")
                and paper is not null and paperStorageId>1 order by timestamp");
        $killable = [];
        while (($doc = DocumentInfo::fetch($result, $this->conf))) {
            $killable[$doc->paperStorageId] = "[" . $this->conf->unparse_time_log($doc->timestamp)
                . "] " . $doc->export_filename() . " ({$doc->paperStorageId})";
        }

        if (count($killable)) {
            fwrite(STDOUT, join("\n", $killable) . "\n");
            if (!$this->force) {
                fwrite(STDERR, "\nKill " . plural(count($killable), "document") . "? (y/n) ");
                $x = fread(STDIN, 100);
                if (!preg_match('/\A[yY]/', $x)) {
                    fwrite(STDERR, "Exiting\n");
                    return 1;
                }
            }
            $this->conf->qe_raw("update PaperStorage set paper=NULL where paperStorageId in ("
                . join(",", array_keys($killable)) . ")");
            fwrite(STDOUT, plural(count($killable), "document") . " killed.\n");
        } else {
            fwrite(STDOUT, "Nothing to do\n");
        }
        return 0;
    }

    /** @param list<string> $argv
     * @return KillInactiveDoc_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "force,f"
        )->description("Remove inactive documents from HotCRP database.
Usage: php batch/killinactivedoc.php [-f]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new KillInactiveDoc_Batch($conf, $arg);
    }
}
