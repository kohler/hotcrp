<?php
// banaldocstore.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(BanalDocstore_Batch::make_args($argv)->run());
}

class BanalDocstore_Batch {
    /** @var Conf */
    public $conf;
    /** @var int */
    public $count;
    /** @var DocumentFileTree */
    public $fparts;
    /** @var CheckFormat */
    public $cf;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->count = $arg["count"] ?? 10;

        if (!($dp = $this->conf->docstore())) {
            throw new RuntimeException("Conference has no document store");
        }
        $matcher = new DocumentHashMatcher($arg["match"] ?? null);
        $matcher->set_extension(".pdf");
        $this->fparts = new DocumentFileTree($dp, $matcher);
        $this->cf = new CheckFormat($conf);
    }

    /** @return int */
    function run() {
        $done = 0;
        while ($this->count > 0) {
            $fm = null;
            for ($i = 0; !$fm && $i < 10; ++$i) {
                $fm = $this->fparts->first_match();
                if (!$fm->is_complete()) {
                    $this->fparts->hide($fm);
                    $fm = null;
                }
            }
            if (!$fm) {
                if ($done === 0) {
                    fwrite(STDERR, "No matching documents.\n");
                }
                break;
            }
            $this->fparts->hide($fm);

            error_log($fm->algohash);
            $this->cf->clear();
            $bj = $this->cf->run_banal($fm->fname);
            if (is_object($bj)) {
                $a = ["filename" => $fm->fname] + (array) $bj;
                unset($a["at"]);
            } else {
                $a = ["filename" => $fm->fname, "error" => $this->cf->banal_stderr];
            }
            $c = json_encode($a, JSON_PRETTY_PRINT) . "\n";
            $c = preg_replace_callback('<\[([ ,\n\d]+)\]>', function ($m) {
                return "[" . simplify_whitespace($m[1]) . "]";
            }, $c);
            $c = preg_replace_callback('<([\{\[,])\n {12,}>', function ($m) {
                return $m[1] . ($m[1] === "," ? " " : "");
            }, $c);
            $c = preg_replace('<\n {8,}([\}\]])>', '$1', $c);
            fwrite(STDOUT, $c);
            --$this->count;
            ++$done;
        }
        return $done !== 0 ? 0 : 1;
    }

    /** @param list<string> $argv
     * @return BanalDocstore_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "count:,c: {n} =N Run on up to N documents [10]",
            "match:,m: Only run on documents matching ARG"
        )->description("Run banal on documents from HotCRP’s document store.
Usage: php batch/banaldocstore.php [-n CONFID|--config CONFIG] [-c N]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new BanalDocstore_Batch($conf, $arg);
    }
}
