<?php
// savepapers.php -- HotCRP command-line paper modification script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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

    /** @return string */
    function set_file($file) {
        // allow uploading a whole zip archive
        $zipfile = null;
        if ($file === "-") {
            $content = stream_get_contents(STDIN);
            $this->errprefix = "";
        } else if (str_ends_with(strtolower($file), ".zip")) {
            $content = false;
            $this->ziparchive = new ZipArchive;
            $zipfile = $file;
            $this->errprefix = "{$file}: ";
        } else {
            $content = file_get_contents($file);
            $this->document_directory = dirname($file) . "/";
            $this->errprefix = "{$file}: ";
        }

        if (!$this->ziparchive
            && str_starts_with($content, "\x50\x4B\x03\x04")) {
            if (!($tmpdir = tempdir())) {
                throw new CommandLineException("{$this->errprefix}Cannot create temporary directory");
            } else if (file_put_contents("{$tmpdir}/data.zip", $content) !== strlen($content)) {
                throw new CommandLineException("{$this->errprefix}{$tmpdir}/data.zip: Cannot write file");
            }
            $this->ziparchive = new ZipArchive;
            $zipfile = "{$tmpdir}/data.zip";
            $this->document_directory = null;
        }

        if ($this->ziparchive) {
            if ($this->ziparchive->open($zipfile) !== true) {
                throw new CommandLineException("{$this->errprefix}Invalid zip");
            } else if ($this->ziparchive->numFiles == 0) {
                throw new CommandLineException("{$this->errprefix}Empty zipfile");
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
            // find "*-data.json" file
            $data_filename = $json_filename = [];
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
            } else if (count($data_filename) !== 1) {
                throw new CommandLineException("{$this->errprefix}Should contain exactly one `*-data.json` file");
            }
            $content = $this->ziparchive->getFromName($data_filename[0]);
            $this->errprefix = ($this->errprefix ? $file : "<stdin>") . "/" . $data_filename[0] . ": ";
        }

        if (is_string($content)) {
            return $content;
        } else {
            throw new CommandLineException("{$this->errprefix}Read error");
        }
    }

    function on_document_import($docj, PaperOption $o, PaperStatus $pstatus) {
        if (isset($docj->content_file)
            && is_string($docj->content_file)
            && $this->ziparchive) {
            $name = $docj->content_file;
            $content = $this->ziparchive->getFromName($name);
            if ($content === false) {
                $name = $this->document_directory . $docj->content_file;
                $content = $this->ziparchive->getFromName($name);
            }
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

        if (isset($j->pid) && is_int($j->pid) && $j->pid > 0) {
            $pidtext = "#{$j->pid}";
        } else if (!isset($j->pid) && isset($j->id) && is_int($j->id) && $j->id > 0) {
            $pidtext = "#{$j->id}";
        } else if (!isset($j->pid) && !isset($j->id)) {
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

        $ps = new PaperStatus($this->conf, null, [
            "disable_users" => $this->disable_users,
            "add_topics" => $this->add_topics,
            "content_file_prefix" => $this->document_directory
        ]);
        $ps->on_document_import([$this, "on_document_import"]);

        $pid = $ps->save_paper_json($j);
        if ($pid && str_starts_with($pidtext, "new")) {
            fwrite(STDERR, "-> #" . $pid . ": ");
            $pidtext = "#$pid";
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
                        "disabled" => $this->disable_users
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
        $jp = json_decode($content);
        if ($jp === null) {
            $jp = Json::decode($content); // our JSON decoder provides error positions
        }
        if ($jp === null) {
            fwrite(STDERR, "{$this->errprefix}invalid JSON: " . Json::last_error_msg() . "\n");
            ++$this->nerrors;
        } else if (!is_object($jp) && !is_array($jp)) {
            fwrite(STDERR, "{$this->errprefix}invalid JSON, expected array of objects\n");
            ++$this->nerrors;
        } else {
            foreach (is_object($jp) ? [$jp] : $jp as $j) {
                $this->run_one(clone $j);
                if ($this->nerrors && !$this->ignore_errors) {
                    break;
                }
                gc_collect_cycles();
            }
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
         ->otheropt(false)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        $bf = (new SavePapers_Batch($conf))->set_args($arg);
        $content = $bf->set_file(count($arg["_"]) ? $arg["_"][0] : "-");
        return $bf->run($content);
    }
}
