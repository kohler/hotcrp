<?php
// fixdelegation.php -- HotCRP paper export script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Fileinfo_Batch::make_args($argv)->run());
}

class Fileinfo_Batch {
    /** @var list<string> */
    public $files = [];

    /** @return int */
    function run() {
        if (empty($this->files)) {
            $this->files[] = "-";
        }
        foreach ($this->files as $file) {
            if ($file === "-") {
                $content = stream_get_contents(STDIN);
            } else {
                $content = file_get_contents($file);
            }
            fwrite(STDOUT, sprintf("%-39s %s\n", $file, json_encode(Mimetype::content_info($content))));
        }
        return 0;
    }

    /** @param list<string> $argv
     * @return Fileinfo_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "help,h !"
        )->description("Report HotCRP-derived file info.
Usage: php batch/fileinfo.php FILES...")
         ->helpopt("help")
         ->parse($argv);
        $fib = new Fileinfo_Batch();
        $fib->files = $arg["_"];
        return $fib;
    }
}
