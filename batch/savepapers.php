<?php
// savepapers.php -- HotCRP command-line paper modification script
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(SavePapers_Batch::run_args($argv));
}

class SavePapers_Batch {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var ReviewValues */
    public $tf;

    /** @var bool */
    public $quiet = false;
    /** @var bool */
    public $ignore_errors = false;
    /** @var bool */
    public $ignore_pid = false;
    /** @var bool */
    public $match_title = false;
    /** @var bool */
    public $disable_users = false;
    /** @var bool */
    public $reviews = false;
    /** @var bool */
    public $add_topics = false;
    /** @var bool */
    public $log = true;

    /** @var string */
    public $errprefix = "";
    /** @var list<callable> */
    public $filters = [];

    /** @var ?ZipArchive */
    public $ziparchive;
    /** @var ?string */
    public $document_directory;

    /** @var int */
    public $index = 0;
    /** @var int */
    public $nerrors = 0;
    /** @var int */
    public $nsuccesses = 0;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $this->user->set_overrides(Contact::OVERRIDE_CONFLICT | Contact::OVERRIDE_TIME);
        $this->tf = new ReviewValues($conf->review_form(), ["no_notify" => true]);
    }

    /** @return $this */
    function set_args($arg) {
        $this->quiet = isset($arg["q"]);
        $this->ignore_errors = isset($arg["ignore-errors"]);
        $this->ignore_pid = isset($arg["ignore-pid"]);
        $this->match_title = isset($arg["match-title"]);
        $this->disable_users = isset($arg["disable-users"]);
        $this->add_topics = isset($arg["add-topics"]);
        $this->reviews = isset($arg["r"]);
        $this->log = !isset($arg["no-log"]);
        if (isset($arg["z"])) {
            $this->set_zipfile($arg["z"]);
        }
        foreach ($arg["f"] ?? [] as $f) {
            if (($colon = strpos($f, ":")) !== false
                && $colon + 1 < strlen($f)
                && $f[$colon + 1] !== ":") {
                require_once(substr($f, 0, $colon));
                $f = substr($f, $colon + 1);
            }
            $this->filters[] = $f;
        }
        return $this;
    }

    /** @param string $file */
    function set_zipfile($file) {
        assert(!$this->ziparchive);
        $this->ziparchive = new ZipArchive;
        if ($this->ziparchive->open($file) !== true) {
            throw new CommandLineException("{$file}: Invalid zip");
        }
        // find common directory prefix
        $slashpos = strrpos($this->ziparchive->getNameIndex(0), "/");
        if ($slashpos === false || $slashpos === 0) {
            $dirprefix = "";
        } else {
            $dirprefix = substr($this->ziparchive->getNameIndex(0), 0, $slashpos + 1);
            for ($i = 1; $i < $this->ziparchive->numFiles; ++$i) {
                $name = $this->ziparchive->getNameIndex($i);
                while (!str_starts_with($name, $dirprefix)) {
                    $slashpos = strrpos($dirprefix, "/", -1);
                    if ($slashpos === false || $slashpos === 0) {
                        $dirprefix = "";
                    } else {
                        $dirprefix = substr($dirprefix, 0, $slashpos + 1);
                    }
                }
            }
        }
        $this->document_directory = $dirprefix;
    }

    /** @return string */
    function set_file($file) {
        // allow uploading a whole zip archive
        $content = null;
        $this->errprefix = "{$file}: ";
        if ($file === "-") {
            if (posix_isatty(STDIN)) {
                throw new CommandLineException("Cowardly refusing to read JSON from a terminal");
            }
            $content = stream_get_contents(STDIN);
            $this->errprefix = "";
        } else if (str_ends_with(strtolower($file), ".zip")) {
            $this->set_zipfile($file);
        } else {
            $content = file_get_contents($file);
            $this->document_directory = $this->document_directory ?? (dirname($file) . "/");
        }

        if (!$this->ziparchive
            && str_starts_with($content, "\x50\x4B\x03\x04")) {
            if (!($tmpdir = tempdir())) {
                throw new CommandLineException("{$this->errprefix}Cannot create temporary directory");
            } else if (file_put_contents("{$tmpdir}/data.zip", $content) !== strlen($content)) {
                throw new CommandLineException("{$this->errprefix}{$tmpdir}/data.zip: Cannot write file");
            }
            $this->set_zipfile("{$tmpdir}/data.zip");
            $content = null;
        }

        if ($content === null && $this->ziparchive) {
            $content = $this->default_content();
            if ($content === null) {
                throw new CommandLineException("{$this->errprefix}Should contain exactly one `*-data.json` file");
            }
        }

        if (is_string($content)) {
            return $content;
        } else {
            throw new CommandLineException("{$this->errprefix}Read error");
        }
    }

    /** @return ?string */
    function default_content() {
        if (!$this->ziparchive) {
            return null;
        }
        // find "*-data.json" file
        $data_filename = $json_filename = [];
        $dirprefix = $this->document_directory;
        for ($i = 0; $i < $this->ziparchive->numFiles; ++$i) {
            $filename = $this->ziparchive->getNameIndex($i);
            if (str_starts_with($filename, $dirprefix)
                && !str_starts_with($filename, "{$dirprefix}.")) {
                $dirname = substr($filename, strlen($dirprefix));
                if (preg_match('/\A[^\/]*(?:\A|[-_])data\.json\z/', $dirname)) {
                    $data_filename[] = $filename;
                }
                if (str_ends_with($dirname, ".json")) {
                    $json_filename[] = $filename;
                }
            }
        }
        if (count($data_filename) === 0 && count($json_filename) === 1) {
            $data_filename = $json_filename;
        }
        if (count($data_filename) === 1) {
            if ($this->errprefix === "") {
                $this->errprefix = "<stdin>/{$data_filename[0]}: ";
            } else {
                $this->errprefix = preg_replace('/: \z/', "/{$data_filename[0]}: ", $this->errprefix);
            }
            return $this->ziparchive->getFromName($data_filename[0]);
        }
        return null;
    }

    function on_document_import($docj, PaperOption $o, PaperStatus $pstatus) {
        if (isset($docj->content_file)
            && is_string($docj->content_file)
            && $this->ziparchive) {
            $name = $docj->content_file;
            $stat = $this->ziparchive->statName($name);
            if (!$stat) {
                $name = $this->document_directory . $docj->content_file;
                $stat = $this->ziparchive->statName($name);
            }
            if (!$stat) {
                $pstatus->error_at_option($o, "{$docj->content_file}: Could not read");
                return false;
            }
            // make room for large files in memory
            if ($stat["size"] > 50000000
                && $stat["size"] >= ini_get_bytes("memory_limit") * 0.3) {
                ini_set("memory_limit", floor($stat["size"] * 3.25 / (1 << 20)) . "M");
            }
            $content = $this->ziparchive->getFromIndex($stat["index"]);
            if ($content === false) {
                $pstatus->error_at_option($o, "{$docj->content_file}: Could not read");
                return false;
            }
            $docj->content = $content;
            $docj->content_file = null;
        }
    }

    function run_one($j) {
        ++$this->index;
        if ($this->ignore_pid) {
            if (isset($j->pid)) {
                $j->__original_pid = $j->pid;
            }
            unset($j->pid, $j->id);
        }
        if (!isset($j->pid) && !isset($j->id) && isset($j->title) && is_string($j->title)) {
            $pids = Dbl::fetch_first_columns("select paperId from Paper where title=?", simplify_whitespace($j->title));
            if (count($pids) == 1) {
                $j->pid = (int) $pids[0];
            }
        }

        $pid = $j->pid ?? $j->id ?? null;
        if (is_int($pid) && $pid > 0) {
            $pidtext = "#{$pid}";
        } else if ($pid === null || $pid === "new") {
            $pidtext = "new paper @{$this->index}";
        } else {
            fwrite(STDERR, "paper @{$this->index}: bad pid\n");
            ++$this->nerrors;
            return false;
        }

        $title = $titletext = "";
        if (isset($j->title) && is_string($j->title)) {
            $title = simplify_whitespace($j->title);
        }
        if ($title !== "") {
            $titletext = " (" . UnicodeHelper::utf8_abbreviate($title, 40) . ")";
        }

        foreach ($this->filters as $f) {
            if ($j)
                $j = call_user_func($f, $j, $this->conf, $this->ziparchive, $this->document_directory);
        }
        if (!$j) {
            fwrite(STDERR, "{$pidtext}{$titletext}filtered out\n");
            return false;
        } else if (!$this->quiet) {
            fwrite(STDERR, "{$pidtext}{$titletext}: ");
        }

        $ps = new PaperStatus($this->conf->root_user(), [
            "disable_users" => $this->disable_users,
            "add_topics" => $this->add_topics,
            "content_file_prefix" => $this->document_directory
        ]);
        $ps->on_document_import([$this, "on_document_import"]);

        $pid = $ps->save_paper_json($j);
        if ($pid && str_starts_with($pidtext, "new")) {
            fwrite(STDERR, "-> #{$pid}: ");
            $pidtext = "#{$pid}";
        }
        if (!$this->quiet) {
            fwrite(STDERR, $pid ? ($ps->has_change() ? "saved\n" : "unchanged\n") : "failed\n");
        }
        // XXX does not change decision
        $prefix = $pidtext . ": ";
        foreach ($ps->decorated_message_list() as $mi) {
            fwrite(STDERR, $prefix . $mi->message_as(0) . "\n");
        }
        if (!$pid) {
            ++$this->nerrors;
            return false;
        }

        // XXX more validation here
        if ($pid && isset($j->reviews) && is_array($j->reviews) && $this->reviews) {
            $prow = $this->conf->paper_by_id($pid, $this->user);
            foreach ($j->reviews as $reviewindex => $reviewj) {
                if (!$this->tf->parse_json($reviewj)) {
                    $this->tf->msg_at(null, "review #" . ($reviewindex + 1) . ": invalid review", MessageSet::ERROR);
                } else if (!isset($this->tf->req["reviewerEmail"])
                           || !validate_email($this->tf->req["reviewerEmail"])) {
                    $this->tf->msg_at(null, "review #" . ($reviewindex + 1) . ": invalid reviewer email " . htmlspecialchars($this->tf->req["reviewerEmail"] ?? "<missing>"), MessageSet::ERROR);
                } else {
                    $this->tf->req["override"] = true;
                    $this->tf->paperId = $pid;
                    $user = Contact::make_keyed($this->conf, [
                        "firstName" => $this->tf->req["reviewerFirst"] ?? "",
                        "lastName" => $this->tf->req["reviewerLast"] ?? "",
                        "email" => $this->tf->req["reviewerEmail"],
                        "affiliation" => $this->tf->req["reviewerAffiliation"] ?? null,
                        "disablement" => $this->disable_users ? Contact::DISABLEMENT_USER : 0
                    ])->store();
                    $this->tf->check_and_save($this->user, $prow, null);
                }
            }
            foreach ($this->tf->message_list() as $mi) {
                fwrite(STDERR, $prefix . $mi->message_as(0) . "\n");
            }
            $this->tf->clear_messages();
        }

        if ($ps->has_change() && $this->log) {
            $ps->log_save_activity($this->user, "save", "via CLI");
        }
        ++$this->nsuccesses;
        return true;
    }

    /** @return 0|1|2 */
    function run($content) {
        $jp = Json::try_decode($content);
        if ($jp === null) {
            fwrite(STDERR, "{$this->errprefix}invalid JSON: " . Json::last_error_msg() . "\n");
            ++$this->nerrors;
        } else if (!is_array($jp) && !is_object($jp)) {
            fwrite(STDERR, "{$this->errprefix}invalid JSON, expected array of objects\n");
            ++$this->nerrors;
        } else {
            if (is_object($jp)) {
                $jp = [$jp];
            }
            foreach ($jp as &$j) {
                $this->run_one($j);
                if ($this->nerrors && !$this->ignore_errors) {
                    break;
                }
                $j = null;
                gc_collect_cycles();
            }
            unset($j);
        }
        if ($this->nerrors) {
            return $this->ignore_errors && $this->nsuccesses ? 2 : 1;
        } else {
            return 0;
        }
    }

    /** @return int */
    static function run_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "r,reviews Save reviews as well as paper information",
            "f[],filter[] =FUNCTION Pass JSON through FUNCTION",
            "z:,zipfile: =FILE Read documents from FILE",
            "q,quiet Don’t print progress information",
            "ignore-errors Don’t exit after first error",
            "disable-users,disable Disable all newly created users",
            "ignore-pid Ignore `pid` JSON elements",
            "match-title Match papers by title if no `pid`",
            "add-topics Add all referenced topics to conference",
            "no-log Don’t modify the action log"
        )->helpopt("help")
         ->description("Change papers as specified by FILE, a JSON object or array of objects.
Usage: php batch/savepapers.php [OPTIONS] [FILE]")
         ->maxarg(1)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        $bf = (new SavePapers_Batch($conf))->set_args($arg);
        if (empty($arg["_"])) {
            $content = $bf->default_content() ?? $bf->set_file("-");
        } else {
            $content = $bf->set_file($arg["_"][0]);
        }
        return $bf->run($content);
    }
}
