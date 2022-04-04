<?php
// settings.php -- HotCRP settings script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Settings_Batch::make_args($argv)->run());
}

class Settings_Batch {
    /** @var Conf */
    public $conf;
    /** @var SettingValues */
    public $sv;
    /** @var string */
    public $filename;
    /** @var string */
    public $text;
    /** @var bool */
    public $dry_run;

    function __construct(Contact $user, $arg) {
        $this->conf = $user->conf;
        $this->sv = new SettingValues($user);
    }

    /** @return int */
    function run() {
        $j = [];
        foreach ($this->conf->si_set()->top_list() as $si) {
            if (($v = $this->sv->vjson($si)) !== null) {
                $j[$si->name] = $v;
            }
        }
        fwrite(STDOUT, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        $this->sv->apply_json_string('{"rf":[{
            "id": "t01",
            "name": "Paper summary...",
            "description": "SHIT SHIT SHIT",
            "order": 3,
            "visibility": "au",
            "required": true,
            "presence": "all",
            "exists_if": "",
            "display_space": 3,
            "$comment": "ADMKSANDFLAS"
        }]}');
        error_log($this->sv->full_feedback_text());
        $this->sv->execute();
        error_log("Updated " . join(" ", $this->sv->updated_fields()));
        return 0;
    }

    /** @return Settings_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !"
        )->description("XXX
Usage: php batch/settings.php")
         ->maxarg(1)
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Settings_Batch($conf->root_user(), $arg);
    }
}
