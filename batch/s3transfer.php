<?php
// s3transfer.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(S3Transfer_Batch::make_args($argv)->run());
}

class S3Transfer_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $active;
    /** @var bool */
    public $kill;
    /** @var string */
    public $match;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->active = isset($arg["active"]);
        $this->kill = isset($arg["kill"]);
        $this->match = $arg["match"] ?? "";
    }

    /** @return int */
    function run() {
        $activedocs = $this->active ? DocumentInfo::active_document_map($this->conf) : null;
        $matcher = $this->match !== "" ? new DocumentHashMatcher($this->match) : null;

        $result = $this->conf->qe_raw("select paperStorageId, sha1 from PaperStorage where paperStorageId>1");
        $dids = [];
        while (($row = $result->fetch_row())) {
            if (!$matcher || $matcher->test_hash(HashAnalysis::hash_as_text($row[1])))
                $dids[] = (int) $row[0];
        }
        Dbl::free($result);

        Filer::$no_touch = true;
        $failures = 0;
        foreach ($dids as $did) {
            if ($activedocs !== null && !isset($activedocs[$did])) {
                continue;
            }

            $result = $this->conf->qe_raw("select paperStorageId, paperId, timestamp, mimetype,
                compression, sha1, documentType, filename, infoJson, paper
                from PaperStorage where paperStorageId={$did}");
            $doc = DocumentInfo::fetch($result, $this->conf);
            Dbl::free($result);
            if (!$doc->ensure_content()) {
                continue;
            }

            $front = "[" . $this->conf->unparse_time_log($doc->timestamp) . "] "
                . $doc->export_filename(null, DocumentInfo::ANY_MEMBER_FILENAME) . " ({$did})";

            $chash = $doc->content_binary_hash($doc->binary_hash());
            if ($chash !== $doc->binary_hash()) {
                $saved = $checked = false;
                error_log("{$front}: S3 upload cancelled: data claims checksum {$doc->text_hash()}"
                          . ", has checksum " . HashAnalysis::hash_as_text($chash));
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
                fwrite(STDOUT, "{$front}: {$doc->s3_key()} exists\n");
            } else if ($saved) {
                fwrite(STDOUT, "{$front}: {$doc->s3_key()} saved\n");
            } else {
                fwrite(STDOUT, "{$front}: SAVE FAILED\n");
                ++$failures;
            }
            if ($saved && $this->kill) {
                $this->conf->qe_raw("update PaperStorage set paper=null where paperStorageId={$did}");
            }
        }
        if ($failures) {
            fwrite(STDERR, "Failed to save " . plural($failures, "document") . ".\n");
            return 1;
        } else {
            return 0;
        }
    }

    /** @param list<string> $argv
     * @return S3Transfer_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "help,h !",
            "name:,n: !",
            "config: !",
            "active,a Only transfer active documents (current versions)",
            "kill,k Remove transferred documents from database",
            "match:,m: =MATCH Transfer documents matching MATCH"
        )->description("Transfer submissions from local HotCRP storage to S3.
Usage: php batch/s3transfer.php [--active] [--kill] [-m MATCH]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        if (!$conf->s3_client()) {
            throw new ErrorException("S3 is not configured for this conference");
        }
        return new S3Transfer_Batch($conf, $arg);
    }
}
