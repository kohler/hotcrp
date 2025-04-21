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

    function __construct(Contact $user, $arg) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->sv = (new SettingValues($user))->set_link_json(true);
        if ((isset($arg["file"]) ? 1 : 0) + (empty($arg["_"]) ? 0 : 1) + (isset($arg["expr"]) ? 1 : 0) > 1) {
            throw new CommandLineException("Give at most one of `--file`, `--expr`, and FILE");
        } else if (isset($arg["file"])) {
            $this->filename = $arg["file"];
        } else if (isset($arg["expr"])) {
            $this->expr = $arg["expr"];
        } else if (!empty($arg["_"])) {
            $this->filename = $arg["_"][0];
        }
        $this->dry_run = isset($arg["dry-run"]);
        $this->diff = isset($arg["diff"]);
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
        if (($this->filter || $this->exclude)
            && ($this->filename || $this->expr)) {
            throw new CommandLineException("`--filter` and `--exclude` are incompatible with changing settings");
        }
    }

    /** @param bool $new
     * @return string */
    function output(SettingValues $sv, $new) {
        return json_encode($sv->all_jsonv(["new" => $new, "filter" => $this->filter, "exclude" => $this->exclude]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** @return int */
    function run() {
        if ($this->filename === null && $this->expr === null) {
            fwrite(STDOUT, $this->output($this->sv, false));
            return 0;
        }

        if ($this->diff) {
            $old_jsonstr = $this->output(new SettingValues($this->user), false);
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
        if ($fb === "" && !$this->diff) {
            if (empty($this->sv->changed_keys())) {
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
                assert($this->output($this->sv, false) === $old_jsonstr);
                $new_jsonstr = $this->output($this->sv, true);
            } else {
                $new_jsonstr = $this->output(new SettingValues($this->user), false);
            }
            $diff = $dmp->line_diff($old_jsonstr, $new_jsonstr);
            fwrite(STDOUT, $dmp->line_diff_toUnified($diff));
        }
        return $this->sv->has_error() ? 1 : 0;
    }

    /** @return Settings_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "dry-run,d Do not modify settings",
            "diff Write unified settings diff of changes",
            "expr:,e: =JSON Change settings via JSON",
            "file:,f: =FILE Change settings via FILE",
            "filter[] =EXPR Only include settings matching EXPR",
            "exclude[] =EXPR Exclude settings matching EXPR"
        )->description("Query or modify HotCRP settings in JSON format.
Usage: php batch/settings.php > JSONFILE
       php batch/settings.php FILE
       php batch/settings.php -e JSON")
         ->maxarg(1)
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Settings_Batch($conf->root_user(), $arg);
    }
}
