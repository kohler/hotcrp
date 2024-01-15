<?php
// saveusers.php -- HotCRP command-line user modification script
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(SaveUsers_Batch::make_args($argv)->run());
}

class SaveUsers_Batch {
    /** @var Conf */
    public $conf;
    /** @var UserStatus */
    public $ustatus;
    /** @var int */
    public $exit_status = 0;
    /** @var list<string> */
    public $jexpr = [];
    /** @var bool */
    public $quiet;
    /** @var string */
    public $filename;
    /** @var string */
    public $content;

    function __construct(Contact $user, $arg) {
        $this->conf = $user->conf;
        $this->ustatus = new UserStatus($user);
        $this->ustatus->notify = isset($arg["notify"]) && !isset($arg["no-notify"]);
        $this->ustatus->no_create = isset($arg["no-create"]);
        $this->ustatus->no_modify = isset($arg["no-modify"]);
        $this->quiet = isset($arg["quiet"]);

        if (isset($arg["expression"])) {
            $this->jexpr = $arg["expression"];
            $this->filename = "<expression>";
        } else if (isset($arg["user"])) {
            $j = (object) ["email" => $arg["user"]];
            if (isset($arg["roles"])) {
                $j->roles = $arg["roles"];
            }
            if (isset($arg["user-name"])) {
                $j->name = $arg["user-name"];
            }
            if (isset($arg["disable"])) {
                $j->disabled = true;
            } else if (isset($arg["enable"])) {
                $j->disabled = false;
            }
            $this->jexpr[] = json_encode($j);
            $this->filename = "<user>";
        } else if (($arg["_"][0] ?? "-") === "-") {
            $this->filename = "<stdin>";
            $this->content = stream_get_contents(STDIN);
        } else {
            $this->filename = $arg["_"][0];
            $this->content = file_get_contents_throw($this->filename);
        }
    }

    function parse_json($str) {
        $j = Json::try_decode($str);
        $ja = null;
        if (is_array($j)) {
            $ja = $j;
        } else if (is_object($j) && isset($j->email)) {
            $ja = [$j];
        } else if (is_object($j)) {
            $ja = [];
            foreach ((array) $j as $k => $jx) {
                $jx->email = $jx->email ?? $k;
                $ja[] = $jx;
            }
        } else {
            throw new CommandLineException("{$this->filename}: " . (Json::last_error_msg() ?? "JSON parse error"));
        }
        $csv = CsvParser::make_json($ja, true);
        $this->parse_csvp($csv);
    }

    function parse_csv($str) {
        $csv = new CsvParser(cleannl(convert_to_utf8($str)));
        $csv->set_comment_start("###");
        $line = $csv->next_list();
        if ($line !== null && preg_grep('/\Aemail\z/i', $line)) {
            $csv->set_header($line);
        } else {
            throw new CommandLineException("{$this->filename}: email field missing from CSV header");
        }
        $this->ustatus->add_csv_synonyms($csv);
        $this->parse_csvp($csv);
    }

    private function parse_csvp(CsvParser $csv) {
        while (($line = $csv->next_row())) {
            $this->ustatus->set_user(Contact::make($this->conf));
            $this->ustatus->clear_messages();
            $this->ustatus->csvreq = $line;
            $this->ustatus->jval = (object) ["id" => null];
            $this->ustatus->parse_csv_group("");
            if (($acct = $this->ustatus->save_user($this->ustatus->jval))) {
                if ($this->quiet) {
                    // print nothing
                } else if (empty($this->ustatus->diffs)) {
                    fwrite(STDOUT, "{$acct->email}: No changes\n");
                } else {
                    fwrite(STDOUT, "{$acct->email}: Changed " . join(", ", array_keys($this->ustatus->diffs)) . "\n");
                }
            } else {
                if ($this->ustatus->no_modify
                    && $this->ustatus->has_error_at("email_inuse")) {
                    $this->ustatus->msg_at("email_inuse", "Use `--modify` to modify existing users.", MessageSet::INFORM);
                }
                fwrite(STDERR, $this->ustatus->full_feedback_text());
                $this->exit_status = 1;
            }
        }
    }

    /** @return int */
    function run() {
        if (!empty($this->jexpr)) {
            foreach ($this->jexpr as $jexpr) {
                $this->parse_json($jexpr);
            }
        } else if (preg_match('/\A\s*[\[\{]/', $this->content)) {
            $this->parse_json($this->content);
        } else {
            $this->parse_csv($this->content);
        }
        return $this->exit_status;
    }

    static function make_args($argv) {
        $go = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "user:,u: =EMAIL Create or modify user EMAIL",
            "roles:,r: Set roles for `-u` user",
            "user-name:,uname: Set name for `-u` user",
            "disable Disable `-u` user",
            "enable Enable `-u` user",
            "expression[],expr[],e[] =JSON Create or modify users specified in JSON",
            "notify,N Send email notifications (off by default)",
            "no-notify,no-email !",
            "no-modify,create-only Only create new users, do not modify existing",
            "no-create,modify-only Only modify existing users, do not create new",
            "quiet,q Do not print changes"
        )->helpopt("help")
         ->description("Save HotCRP users as specified in JSON or CSV.
Usage: php batch/saveusers.php [OPTION]... [JSONFILE | CSVFILE]
       php batch/saveusers.php [OPTION]... -e JSON [-e JSON]...
       php batch/saveusers.php [OPTION]... -u EMAIL [--roles ROLES]
                               [--disable | --enable]")
         ->maxarg(1);
        $arg = $go->parse($argv);

        if ((!empty($arg["_"]) || isset($arg["expression"]))
            && isset($arg["user"])) {
            throw new CommandLineException("`-u` and `-e`/`FILE` are mutually exclusive", $go);
        } else if ((isset($arg["roles"]) || isset($arg["user-name"]) || isset($arg["disable"]) || isset($arg["enable"]))
                   && !isset($arg["user"])) {
            throw new CommandLineException("`-u` required for those options", $go);
        } else if (isset($arg["no-modify"]) && isset($arg["no-create"])) {
            throw new CommandLineException("`--no-modify --no-create` does nothing", $go);
        }

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new SaveUsers_Batch($conf->root_user(), $arg);
    }
}
