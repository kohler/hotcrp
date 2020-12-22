<?php
require_once(preg_replace('/\/batch\/[^\/]+/', '/src/siteloader.php', __FILE__));

$arg = Getopt::rest($argv, "hn:qrf:",
    ["help", "name:", "filter=f:", "quiet", "disable", "disable-users",
     "reviews", "match-title", "ignore-pid", "ignore-errors", "add-topics"]);
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    fwrite(STDOUT, "Usage: php batch/savepapers.php [-n CONFID] [OPTIONS] FILE

Options include:
  --quiet                Don't print progress information.
  --ignore-errors        Do not exit after first error.
  --disable-users        Newly created users are disabled.
  --match-title          Match papers by title if no `pid`.
  --ignore-pid           Ignore `pid` elements in JSON.
  --reviews              Save JSON reviews.
  --add-topics           Add undefined topics to conference.
  -f, --filter FUNCTION  Pass through FUNCTION.\n");
    exit(0);
}

require_once(SiteLoader::find("src/init.php"));

class BatchSavePapers {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var ReviewValues */
    public $tf;

    public $quiet = false;
    public $ignore_errors = false;
    public $ignore_pid = false;
    public $match_title = false;
    public $disable_users = false;
    public $reviews = false;
    public $add_topics = false;

    public $errprefix = "";
    public $filters = [];

    public $index = 0;
    public $nerrors = 0;
    public $nsuccesses = 0;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $this->user->set_overrides(Contact::OVERRIDE_CONFLICT | Contact::OVERRIDE_TIME);
        $this->tf = new ReviewValues($conf->review_form(), ["no_notify" => true]);
    }

    function set_args($arg) {
        $this->quiet = isset($arg["q"]) || isset($arg["quiet"]);
        $this->ignore_errors = isset($arg["ignore-errors"]);
        $this->ignore_pid = isset($arg["ignore-pid"]);
        $this->match_title = isset($arg["match-title"]);
        $this->disable_users = isset($arg["disable"]) || isset($arg["disable-users"]);
        $this->add_topics = isset($arg["add-topics"]);
        $this->reviews = isset($arg["r"]) || isset($arg["reviews"]);
        $fs = $arg["f"] ?? [];
        foreach (is_array($fs) ? $fs : [$fs] as $f) {
            if (($colon = strpos($f, ":")) !== false
                && $colon + 1 < strlen($f)
                && $f[$colon + 1] !== ":") {
                require_once(substr($f, 0, $colon));
                $f = substr($f, $colon + 1);
            }
            $this->filters[] = $f;
        }
    }

    function run_one($j) {
        global $ziparchive, $content_file_prefix;
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
                $j = call_user_func($f, $j, $this->conf, $ziparchive, $content_file_prefix);
        }
        if (!$j) {
            fwrite(STDERR, "{$pidtext}{$titletext}filtered out\n");
            return false;
        } else if (!$this->quiet) {
            fwrite(STDERR, "{$pidtext}{$titletext}: ");
        }

        $ps = new PaperStatus($this->conf, null, [
            "no_notify" => true,
            "disable_users" => $this->disable_users,
            "add_topics" => $this->add_topics,
            "content_file_prefix" => $content_file_prefix
        ]);
        $ps->set_allow_error_at("topics", true);
        $ps->set_allow_error_at("options", true);
        $ps->on_document_import("on_document_import");

        $pid = $ps->save_paper_json($j);
        if ($pid && str_starts_with($pidtext, "new")) {
            fwrite(STDERR, "-> #" . $pid . ": ");
            $pidtext = "#$pid";
        }
        if (!$this->quiet) {
            fwrite(STDERR, $pid ? "saved\n" : "failed\n");
        }
        // XXX does not change decision
        $prefix = $pidtext . ": ";
        foreach ($ps->landmarked_message_texts() as $msg) {
            fwrite(STDERR, $prefix . htmlspecialchars_decode($msg) . "\n");
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
                    $user_req = [
                        "firstName" => $this->tf->req["reviewerFirst"] ?? "",
                        "lastName" => $this->tf->req["reviewerLast"] ?? "",
                        "email" => $this->tf->req["reviewerEmail"],
                        "affiliation" => $this->tf->req["reviewerAffiliation"] ?? null,
                        "disabled" => $this->disable_users
                    ];
                    $user = Contact::create($this->conf, null, $user_req);
                    $this->tf->check_and_save($this->user, $prow, null);
                }
            }
            foreach ($this->tf->message_texts() as $te) {
                fwrite(STDERR, $prefix . htmlspecialchars_decode($te) . "\n");
            }
            $this->tf->clear_messages();
        }

        ++$this->nsuccesses;
        return true;
    }

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
            $jp = is_object($jp) ? (array) $jp : $jp;
            foreach (is_object($jp) ? get_object_vars($jp) : $jp as &$j) {
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
}

$file = count($arg["_"]) ? $arg["_"][0] : "-";

// allow uploading a whole zip archive
global $ziparchive, $content_file_prefix;
$zipfile = $ziparchive = $content_file_prefix = null;

if ($file === "-") {
    $content = stream_get_contents(STDIN);
    $filepfx = "";
} else if (str_ends_with(strtolower($file), ".zip")) {
    $content = false;
    $ziparchive = new ZipArchive;
    $zipfile = $file;
    $filepfx = "$file: ";
} else {
    $content = file_get_contents($file);
    $filepfx = "$file: ";
    $content_file_prefix = dirname($file) . "/";
}
if (!$ziparchive && $content === false) {
    fwrite(STDERR, "{$filepfx}Read error\n");
    exit(1);
}

if (!$ziparchive && str_starts_with($content, "\x50\x4B\x03\x04")) {
    if (!($tmpdir = tempdir())) {
        fwrite(STDERR, "Cannot create temporary directory\n");
        exit(1);
    } else if (file_put_contents("$tmpdir/data.zip", $content) !== strlen($content)) {
        fwrite(STDERR, "$tmpdir/data.zip: Cannot write file\n");
        exit(1);
    }
    $ziparchive = new ZipArchive;
    $zipfile = "$tmpdir/data.zip";
    $content_file_prefix = null;
}
if ($ziparchive) {
    if ($ziparchive->open($zipfile) !== true) {
        fwrite(STDERR, "{$filepfx}Invalid zip\n");
        exit(1);
    } else if ($ziparchive->numFiles == 0) {
        fwrite(STDERR, "{$filepfx}Empty zipfile\n");
        exit(1);
    }
    // find common directory prefix
    $slashpos = strpos($ziparchive->getNameIndex(0), "/");
    if ($slashpos === false || $slashpos === 0) {
        $dirprefix = "";
    } else {
        $dirprefix = substr($ziparchive->getNameIndex(0), 0, $slashpos + 1);
        for ($i = 1; $i < $ziparchive->numFiles; ++$i) {
            if (!str_starts_with($ziparchive->getNameIndex($i), $dirprefix))
                $dirprefix = "";
        }
    }
    $content_file_prefix = $dirprefix;
    if ($content_file_prefix !== ""
        && !str_ends_with($content_file_prefix, "/")) {
        $content_file_prefix .= "/";
    }
    // find "*-data.json" file
    $data_filename = $json_filename = [];
    for ($i = 0; $i < $ziparchive->numFiles; ++$i) {
        $filename = $ziparchive->getNameIndex($i);
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
        fwrite(STDERR, "{$filepfx}Should contain exactly one `*-data.json` file\n");
        exit(1);
    }
    $data_filename = $data_filename[0];
    $content = $ziparchive->getFromName($data_filename);
    $filepfx = ($filepfx ? $file : "<stdin>") . "/" . $data_filename . ": ";
    if ($content === false) {
        fwrite(STDERR, "{$filepfx}Could not read\n");
        exit(1);
    }
}

function on_document_import($docj, PaperOption $o, PaperStatus $pstatus) {
    global $ziparchive, $content_file_prefix;
    if (isset($docj->content_file)
        && is_string($docj->content_file)
        && $ziparchive) {
        $name = $docj->content_file;
        $content = $ziparchive->getFromName($name);
        if ($content === false) {
            $name = $content_file_prefix . $docj->content_file;
            $content = $ziparchive->getFromName($name);
        }
        if ($content === false) {
            $pstatus->error_at_option($o, "{$docj->content_file}: Could not read");
            return false;
        }
        $docj->content = $content;
        $docj->content_file = null;
    }
}

$bf = new BatchSavePapers($Conf);
$bf->set_args($arg);
$bf->errprefix = $filepfx;
exit($bf->run($content));
