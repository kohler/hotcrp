<?php
// settings.php -- HotCRP settings script
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Settings_Batch::make_args($argv)->run());
}

class Settings_Batch {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var SettingValues */
    public $sv;
    /** @var ?string */
    public $filename;
    /** @var ?string */
    public $expr;
    /** @var string */
    public $text;
    /** @var bool */
    public $dry_run;
    /** @var bool */
    public $diff;
    /** @var ?SearchExpr */
    private $filter;
    /** @var ?SearchExpr */
    private $exclude;

    function __construct(Contact $user, $arg, Getopt $getopt) {
        $this->conf = $user->conf;
        $this->user = $user;

        $argv = $arg["_"];
        $argc = count($argv);
        $mode = "fetch";
        $argi = 0;
        if ($argi < $argc
            && in_array($argv[$argi], ["fetch", "save", "diff", "test"], true)) {
            $mode = $argv[$argi];
            ++$argi;
        }

        if ($argi < $argc
            && preg_match('/\A[\[\{]/', $argv[$argi])
            && json_validate($argv[$argi])) {
            if (!isset($arg["expr"])) {
                $arg["expr"] = $argv[$argi];
                ++$argi;
            }
        } else if ($argi < $argc
                   && !isset($arg["file"])) {
            $arg["file"] = $argv[$argi];
            ++$argi;
        }

        if ($argi < $argc) {
            throw new CommandLineException("Too many arguments", $getopt);
        } else if ((isset($arg["file"]) || isset($arg["expr"])) && $mode === "fetch") {
            throw new CommandLineException("`save` or `diff` mode required", $getopt);
        } else if (isset($arg["file"]) && isset($arg["expr"])) {
            throw new CommandLineException("`--file` and `--expr` conflict", $getopt);
        } else if (isset($arg["file"])) {
            $this->filename = $arg["file"];
        } else if (isset($arg["expr"])) {
            $this->expr = $arg["expr"];
        } else if ($mode !== "fetch") {
            $this->filename = "-";
        }

        $this->diff = $mode === "diff"
            || ($mode === "save" && isset($arg["diff"]));
        $this->dry_run = isset($arg["dry-run"])
            || ($mode === "diff" && !isset($arg["save"]))
            || $mode === "test";

        foreach ($arg["filter"] ?? [] as $s) {
            $sp = new SearchParser($s);
            $expr = $sp->parse_expression(SearchOperatorSet::simple_operators());
            if (!$expr) {
                // ignore it
            } else if ($this->filter) {
                $this->filter = SearchExpr::combine("or", $this->filter, $expr);
            } else {
                $this->filter = $expr;
            }
        }
        foreach ($arg["exclude"] ?? [] as $s) {
            $sp = new SearchParser($s);
            $expr = $sp->parse_expression(SearchOperatorSet::simple_operators());
            if (!$expr) {
                // ignore it
            } else if ($this->exclude) {
                $this->exclude = SearchExpr::combine("or", $this->exclude, $expr);
            } else {
                $this->exclude = $expr;
            }
        }

        $this->sv = $this->make_sv()
            ->set_si_filter($this->filter)
            ->set_si_exclude($this->exclude);
    }

    /** @return SettingValues */
    private function make_sv() {
        return (new SettingValues($this->user))
            ->set_link_json(true);
    }

    /** @param bool $new
     * @return string */
    function output(SettingValues $sv, $new) {
        return json_encode($sv->all_jsonv(["new" => $new]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** @return int */
    function run() {
        if ($this->filename === null && $this->expr === null) {
            fwrite(STDOUT, $this->output($this->sv, false));
            return 0;
        }

        if ($this->diff) {
            $old_jsonstr = $this->output($this->make_sv(), false);
        } else {
            $old_jsonstr = null;
        }

        if ($this->expr !== null) {
            $s = $this->expr;
            $fn = "<expr>";
        } else if ($this->filename === "-") {
            $s = stream_get_contents(STDIN);
            $fn = "<stdin>";
        } else {
            $s = file_get_contents_throw($this->filename);
            $fn = $this->filename;
        }
        if ($s === "" || $s === false) {
            throw new CommandLineException("{$fn}: Empty file");
        }
        $this->sv->add_json_string($s, $fn);

        $this->sv->parse();
        if (!$this->dry_run) {
            $this->sv->execute();
        }
        $fb = $this->sv->decorated_feedback_text();
        if ($fb === "" && !$this->dry_run) {
            if (empty($this->sv->saved_keys())) {
                $fb = "No changes\n";
            } else if ($this->dry_run) {
                $fb = "No errors\n";
            } else {
                $fb = "Settings saved\n";
            }
        }
        fwrite(STDERR, $fb);
        if ($this->diff) {
            $dmp = new dmp\diff_match_patch;
            $dmp->Line_Histogram = true;
            if ($this->dry_run) {
                $this->sv->set_si_filter(null)->set_si_exclude(null);
                assert($this->output($this->sv, false) === $old_jsonstr);
                $new_jsonstr = $this->output($this->sv, true);
            } else {
                $new_jsonstr = $this->output($this->make_sv(), false);
            }
            $diff = $dmp->line_diff($old_jsonstr, $new_jsonstr);
            fwrite(STDOUT, $dmp->line_diff_toUnified($diff));
        }
        return $this->sv->has_error() ? 1 : 0;
    }

    /** @return Settings_Batch */
    static function make_args($argv) {
        $getopt = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "save,s !",
            "diff !",
            "file:,f: =FILE Change settings using FILE",
            "expr:,e: =JSON Change settings using JSON",
            "dry-run,d Don’t actually save changes",
            "filter[] =EXPR Only include settings matching EXPR",
            "exclude[] =EXPR Exclude settings matching EXPR"
        )->description("Query or modify HotCRP settings in JSON format.
Usage: php batch/settings.php [OPTIONS] > JSONFILE
       php batch/settings.php save [-d] [FILE | -e JSON]
       php batch/settings.php diff [FILE | -e JSON]")
         ->helpopt("help")
         ->interleave(true);
        $arg = $getopt->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Settings_Batch($conf->root_user(), $arg, $getopt);
    }
}
