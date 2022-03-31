<?php
// saveusers.php -- HotCRP command-line user modification script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
    /** @var string */
    public $filename;
    /** @var string */
    public $content;

    function __construct(Contact $user, $arg) {
        $this->conf = $user->conf;
        $this->ustatus = new UserStatus($user);
        $this->ustatus->notify = isset($arg["notify"]) || !isset($arg["no-notify"]);
        $this->ustatus->no_create = isset($arg["no-create"]);
        $this->ustatus->no_modify = isset($arg["no-modify"]);

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

    function save_contact($key, $cj) {
        if (!isset($cj->email)
            && is_string($key)
            && validate_email($key)) {
            $cj->email = $key;
        }
        if (($acct = $this->ustatus->save_user($cj))) {
            if (empty($this->ustatus->diffs)) {
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

    function parse_json($str) {
        $j = json_decode($str);
        if (is_object($j)) {
            if (count((array) $j)
                && validate_email(array_keys((array) $j)[0])) {
                $j = (array) $j;
            } else {
                $j = [$j];
            }
        }
        if ($j === null || !is_array($j)) {
            if ($j === null) {
                Json::decode($str);
            }
            throw new CommandLineException("{$this->filename}: " . (Json::last_error_msg() ?? "JSON parse error"));
        }
        foreach ($j as $key => $cj) {
            $this->ustatus->set_user(Contact::make($this->conf));
            $this->ustatus->clear_messages();
            $this->save_contact($key, $cj);
        }
    }

    function parse_csv($str) {
        $csv = new CsvParser(cleannl(convert_to_utf8($str)));
        $csv->set_comment_chars("#%");
        $line = $csv->next_list();
        if ($line !== null && preg_grep('/\Aemail\z/i', $line)) {
            $csv->set_header($line);
        } else {
            throw new CommandLineException("{$this->filename}: email field missing from CSV header");
        }
        $this->ustatus->add_csv_synonyms($csv);
        while (($line = $csv->next_row())) {
            $this->ustatus->set_user(Contact::make($this->conf));
            $this->ustatus->clear_messages();
            $this->ustatus->csvreq = $line;
            $this->ustatus->jval = (object) ["id" => null];
            $this->ustatus->parse_csv_group("");
            $this->save_contact(null, $this->ustatus->jval);
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
            "user:,u: =EMAIL Create or modify user EMAIL.",
            "roles:,r: Set roles (`-u` only).",
            "user-name:,uname: Set user name (`-u` only).",
            "expression[],expr[],e[] =JSON Create or modify users specified in JSON.",
            "no-notify,no-email Do not send email notifications.",
            "notify !",
            "no-modify,create-only Only create new users, do not modify existing.",
            "no-create,modify-only Only modify existing users, do not create new."
        )->helpopt("help")
         ->description("Save HotCRP users as specified in JSON or CSV.
Usage: php batch/saveusers.php [OPTION]... [JSONFILE | CSVFILE]
       php batch/saveusers.php [OPTION]... -e JSON [-e JSON]...
       php batch/saveusers.php [OPTION]... -u EMAIL [--roles ROLES]")
         ->maxarg(1);
        $arg = $go->parse($argv);

        if ((!empty($arg["_"]) || isset($arg["expression"]))
            && isset($arg["user"])) {
            throw new CommandLineException("`-u` and `-e/FILE` are mutually exclusive", $go);
        } else if ((isset($arg["roles"]) || isset($arg["user-name"]))
                   && !isset($arg["user"])) {
            throw new CommandLineException("`-u` required for `--roles/--user-name`", $go);
        } else if (isset($arg["no-modify"]) && isset($arg["no-create"])) {
            throw new CommandLineException("`--no-modify --no-create` does nothing", $go);
        }

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new SaveUsers_Batch($conf->root_user(), $arg);
    }
}
