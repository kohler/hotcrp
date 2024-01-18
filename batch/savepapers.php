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
    public $silent = false;
    /** @var bool */
    public $ignore_errors = false;
    /** @var 0|1|2|3 */
    private $pidflags = 0;
    /** @var bool */
    public $disable_users = false;
    /** @var bool */
    public $any_content_file = false;
    /** @var bool */
    public $reviews = false;
    /** @var bool */
    public $add_topics = false;
    /** @var bool */
    public $skip_document_verify = false;
    /** @var bool */
    public $skip_document_content = false;
    /** @var bool */
    public $notify = false;
    /** @var bool */
    public $dry_run = false;
    /** @var bool */
    public $log = true;
    /** @var bool */
    public $json5 = false;

    /** @var string */
    public $errprefix = "";
    /** @var list<callable> */
    public $filters = [];

    /** @var ?ZipArchive */
    public $ziparchive;
    /** @var ?string */
    public $document_directory;
    /** @var ?list<callable> */
    private $callbacks;
    /** @var ?string */
    private $_ziparchive_json;

    /** @var PaperStatus */
    public $ps;
    /** @var int */
    public $nerrors = 0;
    /** @var int */
    public $nsuccesses = 0;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $this->user->set_overrides(Contact::OVERRIDE_CONFLICT);
        $this->tf = new ReviewValues($conf->review_form(), ["no_notify" => true]);
    }

    /** @return $this */
    function set_args($arg) {
        $this->quiet = isset($arg["q"]) || isset($arg["silent"]);
        $this->silent = isset($arg["silent"]);
        $this->ignore_errors = isset($arg["ignore-errors"]);
        if (isset($arg["ignore-pid"])) {
            $this->pidflags |= Paper_API::PIDFLAG_IGNORE_PID;
        }
        if (isset($arg["match-title"])) {
            $this->pidflags |= Paper_API::PIDFLAG_MATCH_TITLE;
        }
        $this->disable_users = isset($arg["disable-users"]);
        $this->any_content_file = isset($arg["any-content-file"]);
        $this->add_topics = isset($arg["add-topics"]);
        $this->reviews = isset($arg["r"]);
        $this->skip_document_verify = isset($arg["skip-document-verify"]);
        $this->skip_document_content = isset($arg["skip-document-content"]);
        $this->log = !isset($arg["no-log"]);
        $this->dry_run = isset($arg["dry-run"]);
        $this->notify = isset($arg["notify"]);
        $this->json5 = isset($arg["json5"]);
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
        list($this->document_directory, $this->_ziparchive_json) =
            Paper_API::analyze_zip_contents($this->ziparchive);
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
        if (!$this->_ziparchive_json) {
            return null;
        }
        if ($this->errprefix === "") {
            $this->errprefix = "<stdin>/{$this->_ziparchive_json}: ";
        } else {
            $this->errprefix = preg_replace('/: \z/', "/{$this->_ziparchive_json}: ", $this->errprefix);
        }
        return $this->ziparchive->getFromName($this->_ziparchive_json);
    }

    function on_document_import($docj, PaperOption $o) {
        if (!is_string($docj->content_file ?? null)
            || $docj instanceof DocumentInfo) {
            return;
        }
        if ($this->ziparchive) {
            $fname = $this->document_directory . $docj->content_file;
            return Paper_API::apply_zip_content_file($docj, $fname, $this->ziparchive, $o, $this->ps);
        } else if ($this->document_directory) {
            $docj->content_file = $this->document_directory . $docj->content_file;
        }
    }

    function add_callback($callback) { // for use by filters
        $this->callbacks[] = $callback;
    }

    function run_one($index, $j) {
        $pidish = Paper_API::analyze_json_pid($this->conf, $j, $this->pidflags);
        if (!$pidish) {
            fwrite(STDERR, "paper @{$index}: bad pid\n");
            ++$this->nerrors;
            return false;
        }
        $pidtext = is_int($pidish) ? "#{$pidish}" : "new paper @{$index}";

        $title = $titletext = "";
        if (isset($j->title) && is_string($j->title)) {
            $title = simplify_whitespace($j->title);
        }
        if ($title !== "") {
            $titletext = " (" . UnicodeHelper::utf8_abbreviate($title, 40) . ")";
        }

        foreach ($this->filters as $f) {
            if ($j)
                $j = call_user_func($f, $j, $this->conf, $this);
        }
        if (!$j) {
            fwrite(STDERR, "{$pidtext}{$titletext}filtered out\n");
            return false;
        } else if (!$this->quiet) {
            fwrite(STDERR, "{$pidtext}{$titletext}: ");
        }

        $this->ps = $this->ps ?? (new PaperStatus($this->user))
            ->set_disable_users($this->disable_users)
            ->set_any_content_file($this->any_content_file)
            ->set_notify($this->notify)
            ->set_skip_document_verify($this->skip_document_verify)
            ->set_skip_document_content($this->skip_document_content)
            ->on_document_import([$this, "on_document_import"]);

        if ($this->ps->prepare_save_paper_json($j)) {
            if ($this->dry_run) {
                $action = $this->ps->has_change() ? "changed" : "unchanged";
                $pid = true;
            } else {
                $this->ps->execute_save();
                $action = $this->ps->has_change() ? "saved" : "unchanged";
                $pid = $this->ps->paperId;
            }
        } else {
            $action = "failed";
            $pid = false;
        }
        if (!is_bool($pid) && $pidish === "new") {
            if (!$this->quiet) {
                fwrite(STDERR, "-> #{$pid}: ");
            }
            $pidtext = "#{$pid}";
        }
        $prefix = "{$pidtext}: ";
        if (!$this->quiet) {
            fwrite(STDERR, "{$action}\n");
        }
        // XXX does not change decision
        if (!$this->silent) {
            foreach ($this->ps->decorated_message_list() as $mi) {
                fwrite(STDERR, $prefix . $mi->message_as(0) . "\n");
            }
        }
        if (!$pid) {
            ++$this->nerrors;
            return false;
        }

        // XXX more validation here
        if ($pid && isset($j->reviews) && is_array($j->reviews) && $this->reviews && !$this->dry_run) {
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
                        "disablement" => $this->disable_users ? Contact::CF_UDISABLED : 0
                    ])->store();
                    $this->tf->check_and_save($this->user, $prow, null);
                }
            }
            if (!$this->silent) {
                foreach ($this->tf->message_list() as $mi) {
                    fwrite(STDERR, $prefix . $mi->message_as(0) . "\n");
                }
            }
            $this->tf->clear_messages();
        }

        if ($this->callbacks) {
            $prow = $this->conf->paper_by_id($pid, $this->user);
            while (!empty($this->callbacks)) {
                $cb = array_shift($this->callbacks);
                $cb($prow, $this);
            }
        }

        if ($this->ps->has_change() && $this->log && !$this->dry_run) {
            $this->ps->log_save_activity("via CLI");
        }
        ++$this->nsuccesses;
        return true;
    }

    /** @param list<object> &$jl */
    private function _run_main(&$jl) {
        // prefetch authors together (useful for big updates)
        $this->prefetch_authors($jl);

        if ($this->add_topics) {
            foreach ($this->conf->options()->form_fields() as $opt) {
                if ($opt instanceof Topics_PaperOption)
                    $opt->allow_new_topics(true);
            }
        }
        if ($this->silent) {
            foreach ($this->conf->options()->form_fields() as $opt) {
                if ($opt instanceof PCConflicts_PaperOption)
                    $opt->set_warn_missing(false);
            }
        }

        $this->conf->delay_logs();
        for ($index = 0; $index !== count($jl); ++$index) {
            $j = $jl[$index];
            $jl[$index] = null;
            $this->run_one($index, $j);
            if ($this->nerrors && !$this->ignore_errors) {
                break;
            }
            gc_collect_cycles();
            if ($index % 10 === 9) {
                $this->conf->release_logs();
                $this->conf->delay_logs();
            }
        }
        $this->conf->release_logs();
    }

    /** @param list<object> $jl */
    private function prefetch_authors($jl) {
        $potential_authors = [];
        foreach ($jl as $j) {
            if (isset($j->authors) && is_array($j->authors)) {
                foreach ($j->authors as $au) {
                    if (is_object($au)
                        && isset($au->email)
                        && is_string($au->email))
                        $potential_authors[] = $au->email;
                }
            }
        }
        $this->conf->resolve_primary_emails($potential_authors);
    }

    /** @return 0|1|2 */
    function run($content) {
        $j = $jparser = null;
        if (!$this->json5) {
            $j = json_decode($content);
        }
        if ($j === null) {
            $jparser = (new JsonParser)->flags($this->json5 ? JsonParser::JSON5 : 0);
            $j = $jparser->input($content)->decode();
        }
        if ($j === null) {
            fwrite(STDERR, "{$this->errprefix}invalid JSON: " . $jparser->last_error_msg() . "\n");
            ++$this->nerrors;
        } else if (!is_array($j) && !is_object($j)) {
            fwrite(STDERR, "{$this->errprefix}invalid JSON, expected array of objects\n");
            ++$this->nerrors;
        } else {
            $jl = is_object($j) ? [$j] : $j;
            $j = $content = null; // release references
            $this->_run_main($jl); // consumes `$jl`
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
            "dry-run,d Don’t actually save",
            "ignore-errors Don’t exit after first error",
            "disable-users,disable Disable newly created users",
            "notify,N Notify new users via email (off by default)",
            "any-content-file Allow any `content_file` in documents",
            "ignore-pid Ignore `pid` JSON elements",
            "match-title Match papers by title if no `pid`",
            "add-topics Add all referenced topics to conference",
            "skip-document-verify Do not verify document hashes",
            "skip-document-content Avoid storing document content",
            "json5,5 Allow JSON5 extensions",
            "q,quiet Don’t print progress information",
            "silent Don’t print progress information or submission errors",
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
