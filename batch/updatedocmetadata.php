<?php
// updatedocmetadata.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(UpdateDocMetadata_Batch::make_args($argv)->run());
}

class UpdateDocMetadata_Batch {
    /** @var Conf */
    public $conf;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
    }


    private function run_images() {
        $result = $this->conf->qe("select " . PaperInfo::document_query() . " from PaperStorage where mimetype like 'image/%'");
        $docs = [];
        while (($doc = DocumentInfo::fetch($result, $this->conf))) {
            $docs[] = $doc;
        }
        Dbl::free($result);
        DocumentInfo::prefetch_content($docs, DocumentInfo::FLAG_NO_DOCSTORE);
        foreach ($docs as $doc) {
            $info = Mimetype::content_info($doc->content(), $doc->mimetype);
            $upd = [];
            $m = $doc->metadata() ?? (object) [];
            if (isset($info["width"]) && !isset($m->width)) {
                $upd["width"] = $info["width"];
            }
            if (isset($info["height"]) && !isset($m->height)) {
                $upd["height"] = $info["height"];
            }
            if (!empty($upd)) {
                $doc->update_metadata($upd);
            }
        }
    }

    /** @return int */
    function run() {
        $this->run_images();
        return 0;
    }

    /** @param list<string> $argv
     * @return UpdateDocMetadata_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !"
        )->description("Update HotCRP document metadata.
Usage: php batch/updatedocmetadata.php [-n CONFID | --config CONFIG]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new UpdateDocMetadata_Batch($conf, $arg);
    }
}
