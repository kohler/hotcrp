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
    /** @var bool */
    public $images = true;
    /** @var bool */
    public $videos = true;
    /** @var bool */
    public $pdfs = true;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->verbose = isset($arg["verbose"]);
        if (isset($arg["images"]) || isset($arg["videos"]) || isset($arg["pdfs"])) {
            $this->images = $this->videos = $this->pdfs = false;
        }
        if (isset($arg["images"])) {
            $this->images = true;
        }
        if (isset($arg["videos"])) {
            $this->videos = true;
        }
        if (isset($arg["pdfs"])) {
            $this->pdfs = true;
        }
    }


    private function run_images() {
        $result = $this->conf->qe("select " . $this->conf->document_query_fields() . " from PaperStorage where mimetype like 'image/%'");
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
            $info = Mimetype::content_info(null, $doc->mimetype, $doc);
            $m = $doc->metadata() ?? (object) [];
            if (isset($info["width"]) && !isset($m->width)) {
                $doc->set_prop("width", $info["width"]);
            }
            if (isset($info["height"]) && !isset($m->height)) {
                $doc->set_prop("height", $info["height"]);
            }
            $upd = $doc->prop_update();
            $doc->save_prop();
            if ($this->verbose && !empty($upd)) {
                fwrite(STDERR, $doc->export_filename(null, DocumentInfo::ANY_MEMBER_FILENAME) . " [{$doc->filename} #{$doc->paperId}/{$doc->paperStorageId}]: " . (empty($upd) ? "-" : json_encode($upd)) . "\n");
            }
        }
    }

    private function run_videos() {
        $result = $this->conf->qe("select " . $this->conf->document_query_fields() . " from PaperStorage where mimetype like 'video/%'");
        $docs = [];
        while (($doc = DocumentInfo::fetch($result, $this->conf))) {
            $doc->analyze_content();
            $upd = $doc->prop_update();
            $doc->save_prop();
            if ($this->verbose && !empty($upd)) {
                fwrite(STDERR, $doc->export_filename(null, DocumentInfo::ANY_MEMBER_FILENAME) . " [{$doc->filename} #{$doc->paperId}/{$doc->paperStorageId}]: " . (empty($upd) ? "-" : json_encode($upd)) . "\n");
            }
        }
        Dbl::free($result);
    }

    private function run_pdf() {
        $result = $this->conf->qe("select " . $this->conf->document_query_fields() . " from PaperStorage where mimetype='application/pdf'");
        $docs = [];
        while (($doc = DocumentInfo::fetch($result, $this->conf))) {
            $docs[] = $doc;
        }
        Dbl::free($result);
        while (!empty($docs)) {
            $doc = array_pop($docs);
            $doc->npages();
        }
    }

    /** @return int */
    function run() {
        $this->images && $this->run_images();
        $this->videos && $this->run_videos();
        $this->pdfs && $this->run_pdf();
        return 0;
    }

    /** @param list<string> $argv
     * @return UpdateDocMetadata_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "verbose,V Be verbose.",
            "images Run on images.",
            "videos Run on videos.",
            "pdf Run on PDFs."
        )->description("Update HotCRP document metadata.
Usage: php batch/updatedocmetadata.php [-n CONFID | --config CONFIG]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new UpdateDocMetadata_Batch($conf, $arg);
    }
}
