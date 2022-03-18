<?php
// deletepapers.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(DeletePapers_Batch::make_args($argv)->run());
}

class DeletePapers_Batch {
    /** @var Contact */
    public $user;
    /** @var bool */
    public $yes;
    /** @var bool */
    public $quiet;
    /** @var string */
    public $q;

    function __construct(Contact $user, $arg) {
        $this->user = $user;
        $this->yes = isset($arg["yes"]);
        $this->quiet = isset($arg["quiet"]);
        $this->q = join(" ", $arg["_"]);
    }

    /** @return int */
    function run() {
        $search = new PaperSearch($this->user, ["t" => "all" , "q" => $this->q]);
        $ndeleted = 0;
        if (($pids = $search->paper_ids())) {
            foreach ($this->user->paper_set(["paperId" => $pids]) as $prow) {
                $pid = "#{$prow->paperId}";
                if ($prow->title !== "") {
                    $pid .= " (" . UnicodeHelper::utf8_abbreviate($prow->title, 40) . ")";
                }
                if (!$this->yes) {
                    $str = "";
                    while (!preg_match('/\A[ynq]/i', $str)) {
                        fwrite(STDERR, "Delete {$pid}? (y/n/q) ");
                        $str = fgets(STDIN);
                    }
                    $str = strtolower($str);
                    if (str_starts_with($str, "q")) {
                        return 1;
                    } else if (str_starts_with($str, "n")) {
                        continue;
                    }
                }
                if (!$this->quiet) {
                    fwrite(STDERR, "Deleting {$pid}\n");
                }
                if (!$prow->delete_from_database($this->user)) {
                    return 2;
                }
                ++$ndeleted;
            }
        } else if ($search->has_problem()) {
            fwrite(STDERR, $search->full_feedback_text());
        } else {
            fwrite(STDERR, "No matching submissions\n");
        }
        return $ndeleted > 0 ? 0 : 1;
    }

    /** @param list<string> $argv
     * @return DeletePapers_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "yes,y Assume yes (dangerous!)",
            "quiet,q Quiet"
        )->description("Delete submissions from HotCRP.
Usage: php batch/deletepapers.php [-n CONFID|--config CONFIG] [-y] SEARCH...")
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new DeletePapers_Batch($conf->root_user(), $arg);
    }
}
