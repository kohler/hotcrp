<?php
// batch/collaboratordiff.php -- HotCRP script for updating collaborator lists
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(CollaboratorDiff_Batch::make_args($argv)->run());
}

class CollaboratorDiff_Batch {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var resource */
    public $file;
    /** @var bool */
    public $csv;
    /** @var bool */
    public $fix;
    /** @var bool */
    public $only_missing;
    /** @var bool */
    public $no_advisor;
    /** @var bool */
    public $alert;
    /** @var bool */
    public $alert_format;
    /** @var string */
    public $alert_name = "collaborators";
    /** @var bool */
    public $append;
    /** @var bool */
    public $sensitive;

    /** @var list<AuthorMatcher> */
    private $_com;
    /** @var TextPregexes */
    private $_corex;

    /** @var array<string,string> */
    private $new_co = [];
    /** @var list<bool> */
    private $used_uco;
    /** @var list<string> */
    private $unused_uco = [];

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->user = $conf->checked_user_by_email($arg["_"][0]);
        $this->csv = isset($arg["csv"]);
        $this->fix = isset($arg["fix"]);
        $this->only_missing = isset($arg["only-missing"]);
        $this->no_advisor = isset($arg["no-advisor"]);
        $this->alert = isset($arg["alert"]);
        if (isset($arg["alert"]) && $arg["alert"] !== false) {
            $this->alert_name = $arg["alert"];
        }
        $this->alert_format = isset($arg["alert-format"]);
        $this->append = isset($arg["append"]);
        $this->sensitive = !isset($arg["no-sensitive"]);

        if (count($arg["_"]) > 1 && $arg["_"][1] !== "-") {
            $this->file = fopen($arg["_"][1], "rb");
        } else {
            $this->file = STDIN;
        }
        if (!$this->file) {
            throw error_get_last_as_exception($arg["_"][1] . ": ");
        }

        $this->_com = $this->user->aucollab_matchers();
        $this->_corex = $this->user->aucollab_general_pregexes();
        $this->used_uco = array_fill(0, substr_count($this->user->collaborators(), "\n") + 1, false);
    }

    /** @param string $name
     * @param string $affiliation
     * @param string $rest */
    private function process_one($name, $affiliation, $rest) {
        if ($name === "" && $affiliation === "") {
            return;
        }
        if ($affiliation === "") {
            $affiliation = "unknown";
        } else if ($name === "") {
            $name = "All";
        }
        $na = "{$name} ({$affiliation})";
        if ($this->_corex->match($na)) {
            $au = Author::make_name_affiliation($name, $affiliation);
            foreach ($this->_com as $m) {
                if ($m->test($au, $m->is_nonauthor())) {
                    if ($m->author_index >= Author::USER_COLLABORATOR_INDEX
                        && $m->author_index <= Author::MAX_USER_COLLABORATOR_INDEX) {
                        $this->used_uco[$m->author_index - Author::USER_COLLABORATOR_INDEX] = true;
                    }
                    return;
                }
            }
        }
        if (!isset($this->new_co[$na])) {
            $this->new_co[$na] = $rest;
        }
    }

    private function process_string($collab) {
        if ($this->fix) {
            $collab = AuthorMatcher::fix_collaborators($collab);
        }
        foreach (explode("\n", $collab) as $line) {
            $this->process_one(...Author::split_string($line));
        }
    }

    private function process_csv($file) {
        $csvg = new CsvParser($file);
        $list = $csvg->peek_list();
        if (in_array("line", $list)
            || in_array("name", $list)
            || in_array("affiliation", $list)) {
            $csvg->next_list();
        } else if (!empty($list) && strpos($list[0], "@") !== false) {
            if (count($list) === 2) {
                $list = ["user_email", "line"];
            } else if (count($list) === 3) {
                $list = ["user_email", "name", "affiliation"];
            } else if (count($list) === 4) {
                $list = ["user_email", "name", "affiliation", "note"];
            } else {
                throw new CommandLineException("CSV format error");
            }
        } else {
            throw new CommandLineException("CSV lacks required fields `line`, `name`, `affiliation`");
        }
        $csvg->set_header($list);

        $emailp = array_search("user_email", $list);
        $linep = array_search("line", $list);
        $namep = array_search("name", $list);
        $affp = array_search("affiliation", $list);
        $notep = array_search("note", $list);
        $uemail = $this->user->email;
        while (($row = $csvg->next_row())) {
            if ($emailp !== false
                && strcasecmp(trim($row[$emailp]), $uemail) !== 0) {
                continue;
            }
            if ($linep !== false) {
                $this->process_one(...Author::split_string($row[$linep]));
            } else {
                $name = $namep === false ? "" : $row[$namep];
                $aff = $affp === false ? "" : $row[$affp];
                $note = $notep === false ? "" : $row[$notep];
                $this->process_one($name, $aff, $note);
            }
        }
    }

    private function finish() {
        $collator = $this->conf->collator();
        uksort($this->new_co, [$collator, "compare"]);

        $new_co = [];
        $last_com = null;
        foreach ($this->new_co as $na => $x) {
            $com = AuthorMatcher::make_collaborator_line($na);
            if ($last_com && $last_com->test($com, true)) {
                continue;
            }
            $new_co[$na] = $x;
            $last_com = $com;
        }
        $this->new_co = $new_co;

        if (!$this->only_missing) {
            $colist = null;
            foreach ($this->user->collaborator_generator() as $co) {
                $lineno = $co->author_index - Author::USER_COLLABORATOR_INDEX;
                if ($this->used_uco[$lineno]
                    || $co->test($this->user, false)) {
                    continue;
                }
                $colist = $colist ?? explode("\n", $this->user->collaborators());
                if (!$this->no_advisor) {
                    list($n, $a, $x) = Author::split_string($colist[$lineno]);
                    if ($x !== "" && preg_match('/(?:advisor|advisee)/i', $x)) {
                        continue;
                    }
                }
                $this->unused_uco[] = $colist[$lineno] . "\n";
            }
        }
    }

    private function write() {
        $any = false;
        $l = [];
        foreach ($this->new_co as $na => $x) {
            if (empty($l)) {
                $l[] = "# potentially missing collaborators\n";
            }
            $l[] = $x === "" ? "{$na}\n" : "{$na} - {$x}\n";
        }
        if (!empty($this->unused_uco)) {
            if (!empty($l)) {
                $l[] = "\n";
            }
            $l[] = "# potentially obsolete collaborators\n";
            $l = array_merge($l, $this->unused_uco);
        }
        fwrite(STDOUT, join("", $l));
    }

    private function make_alert() {
        $ca = new ContactAlerts($this->user);
        if (!$this->append && !$this->alert_format) {
            foreach ($ca->find_by_name($this->alert_name) as $a) {
                $ca->dismiss($a);
            }
        }

        if (empty($this->new_co) && empty($this->unused_uco)) {
            return;
        }

        $url = $this->conf->hoturl("profile", ["i" => $this->user->email, "#" => "collaborators"], Conf::HOTURL_NO_DEFAULTS | Conf::HOTURL_ABSOLUTE);
        $ml = [];
        $ml[] = MessageItem::urgent_note("<5>Your <a href=\"{$url}\">collaborators</a> may be out of date");
        $ml[] = MessageItem::inform("<0>Your collaborator list helps authors and administrators find potential conflicts, and it is important that you keep it up to date. This message lists some apparent discrepancies based on your recent submissions. This may include both missing collaborators (recent co-authors that aren’t currently listed as conflicts) and possibly-obsolete collaborators (listed collaborators that haven’t co-authored a paper with you recently).");
        $lis = [];
        foreach ($this->new_co as $na => $x) {
            $lis[] = "<dt>" . htmlspecialchars($na) . "</dt>";
            if ($x !== "") {
                $lis[] = "<dd class=\"font-italic\">" . htmlspecialchars($x) . "</dd>";
            }
        }
        if (!empty($this->new_co) && !empty($this->unused_uco)) {
            $lis[] = "</dl><dl class=\"swoosh\">";
        }
        foreach ($this->unused_uco as $line) {
            $lis[] = "<dt>" . htmlspecialchars(rtrim($line)) . "</dt>";
        }
        if (!empty($this->unused_uco)) {
            $lis[] = "<dd class=\"font-italic\">possibly obsolete current "
                . plural_word(count($this->unused_uco), "collaborator")
                . "</dd>";
        }
        $ml[] = MessageItem::inform("<5><dl class=\"swoosh\">" . join("", $lis) . "</dl>");

        if ($this->alert_format) {
            fwrite(STDOUT, MessageSet::feedback_text($ml));
            return;
        }

        $alert = (object) [
            "message_list" => $ml,
            "sensitive" => $this->sensitive,
            "expires_at" => Conf::$now + 86400 * 30,
            "name" => $this->alert_name,
            "scope" => "home profile#collaborators"
        ];
        $ca->append($alert);
    }

    function run() {
        if ($this->csv) {
            $this->process_csv($this->file);
        } else {
            $this->process_string(stream_get_contents($this->file));
        }
        $this->finish();
        if ($this->alert || $this->alert_format) {
            $this->make_alert();
        } else {
            $this->write();
        }
        return 0;
    }

    /** @return CollaboratorDiff_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "fix Repair input file format",
            "only-missing Do not list potentially obsolete collaborators",
            "csv Input file is CSV",
            "alert:: =NAME Output is alert for user [collaborators]",
            "alert-format Output alert format",
            "append Do not replace existing alerts",
            "no-advisor Do not special-case current advisor entries",
            "no-sensitive Do not mark alert as sensitive"
        )->description("Report missing collaborators based on input.
Usage: php batch/collaboratordiff.php EMAIL < COLLAB
COLLAB is in HotCRP collaborator format:
   NAME 1 (AFFILIATION) - REASON
   NAME 2 (AFFILIATION)
   NAME 3 (AFFILIATION) - REASON
   etc.")
         ->helpopt("help")
         ->minarg(1)
         ->maxarg(2)
         ->interleave(true)
         ->parse($argv);
        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new CollaboratorDiff_Batch($conf, $arg);
    }
}
