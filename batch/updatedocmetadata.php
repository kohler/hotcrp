<?php
// updatedocmetadata.php -- HotCRP maintenance script
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(UpdateDocMetadata_Batch::make_args($argv)->run());
}

class UpdateDocMetadata_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $verbose;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->verbose = isset($arg["verbose"]);
    }


    private function run_images() {
        $result = $this->conf->qe("select " . PaperInfo::document_query() . " from PaperStorage where mimetype like 'image/%'");
        $docs = [];
        while (($doc = DocumentInfo::fetch($result, $this->conf))) {
            $docs[] = $doc;
        }
        Dbl::free($result);
        while (!empty($docs)) {
            $i = 1;
            $sz = $docs[0]->size();
            while ($i !== count($docs) && $sz < (64 << 20)) {
                $sz += $docs[$i]->size();
                ++$i;
            }
            $this->run_images_subset(array_splice($docs, 0, $i));
            if (!empty($docs)) {
                gc_collect_cycles();
            }
        }
    }

    /** @param list<DocumentInfo> $docs */
    private function run_images_subset($docs) {
        DocumentInfo::prefetch_content($docs, DocumentInfo::FLAG_NO_DOCSTORE);
        foreach ($docs as $doc) {
            $info = Mimetype::content_info($doc->content(), $doc->mimetype);
            $upd = [];
            $m = $doc->metadata() ?? (object) [];
            if (isset($info["width"]) && !isset($m->width)) {
                $doc->set_prop("width", $info["width"]);
            }
            if (isset($info["height"]) && !isset($m->height)) {
                $doc->set_prop("height", $info["height"]);
            }
            $doc->save_prop();
            if ($this->verbose && !empty($upd)) {
                fwrite(STDERR, $doc->export_filename() . " [{$doc->filename} #{$doc->paperId}/{$doc->paperStorageId}]: " . (empty($upd) ? "-" : json_encode($upd)) . "\n");
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
            "help,h !",
            "verbose,V Be verbose."
        )->description("Update HotCRP document metadata.
Usage: php batch/updatedocmetadata.php [-n CONFID | --config CONFIG]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new UpdateDocMetadata_Batch($conf, $arg);
    }
}
