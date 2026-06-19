<?php /*{hotcrp SearchAction_Batch}*/
// searchaction.php -- HotCRP search action script
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(SearchAction_Batch::make_args($argv)->run());
}

class SearchAction_Batch {
    /** @var Conf */
    public $conf;
    /** @var Getopt */
    public $getopt;
    /** @var Contact */
    public $user;
    /** @var string */
    public $actionname;
    /** @var array<string,string> */
    public $param = [];
    /** @var array<string,string> */
    public $file_param = [];
    /** @var bool */
    public $post = false;
    /** @var bool */
    public $forceShow = true;
    /** @var string */
    public $q = "";
    /** @var string */
    public $t = "";

    /** @param array<string,mixed> $arg */
    function __construct(Conf $conf, $arg, Getopt $getopt) {
        $this->conf = $conf;
        $this->getopt = $getopt;
        $this->user = $conf->root_user();
        $this->parse_arg($arg);
    }

    /** @param array<string,mixed> $arg */
    private function parse_arg($arg) {
        // request method and parameters
        $this->post = isset($arg["post"]);
        foreach ($arg["param"] ?? [] as $pstr) {
            if (($eq = strpos($pstr, "=")) === false) {
                $this->error_exit("<0>`--param NAME=VALUE` expected");
            }
            $this->param[substr($pstr, 0, $eq)] = substr($pstr, $eq + 1);
        }
        foreach ($arg["file-param"] ?? [] as $pstr) {
            if (($eq = strpos($pstr, "=")) === false) {
                $this->error_exit("<0>`--file-param NAME=FILE` expected");
            }
            $fn = substr($pstr, $eq + 1);
            if (!is_readable($fn)) {
                $this->error_exit("<0>{$fn}: Cannot read file");
            }
            $this->file_param[substr($pstr, 0, $eq)] = $fn;
        }
        if (!empty($this->file_param) && !$this->post) {
            $this->error_exit("<0>`--file-param` requires `--post`");
        }

        // action name and positional `NAME=VALUE` parameters
        foreach ($arg["_"] ?? [] as $x) {
            if (($eq = strpos($x, "=")) > 0) {
                $this->param[substr($x, 0, $eq)] = substr($x, $eq + 1);
            } else if (!isset($this->actionname)) {
                $this->actionname = $x;
            } else {
                $this->error_exit("<0>`NAME=VALUE` format expected for parameter arguments");
            }
        }
        if (!isset($this->actionname)) {
            $this->error_exit("<0>Search action required");
        }
        unset($this->param["action"], $this->file_param["action"]);

        // search
        $this->q = $arg["q"] ?? $this->q;
        $tx = $arg["t"] ?? $this->t;
        $this->t = PaperSearch::canonical_limit($tx, $this->user);
        if ($this->t === null) {
            $this->error_exit("<0>Search collection ‘{$tx}’ not found");
        }
        $this->forceShow = !isset($arg["no-force"]);
    }

    /** @param string $msg
     * @return never */
    private function error_exit($msg) {
        fwrite(STDERR, MessageSet::feedback_text([MessageItem::error($msg)]));
        throw new CommandLineException("", $this->getopt, 3);
    }

    /** @param iterable<MessageItem> $message_list */
    private function report($message_list) {
        if (($s = MessageSet::feedback_text($message_list)) !== "") {
            fwrite(STDERR, $s);
        }
    }

    /** @return int */
    function run() {
        if ($this->forceShow) {
            $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }

        // perform search
        $srch = new PaperSearch($this->user, ["q" => $this->q, "t" => $this->t]);
        $ssel = new SearchSelection($srch->sorted_paper_ids());
        if ($srch->has_problem()) {
            $this->report($srch->message_list());
        }

        // construct request. `--post` selects a POST action; `--file-param`
        // requires `--post`, since only POST actions read uploaded files.
        $method = $this->post ? "POST" : "GET";
        $qreq = Qrequest::make($method, array_merge($this->param, ["q" => $this->q, "t" => $this->t, "forceShow" => $this->forceShow ? "1" : null]))
            ->set_conf($this->conf)
            ->set_user($this->user)
            ->set_page("search");
        if ($method === "POST") {
            $qreq->approve_token();
        }
        foreach ($this->file_param as $name => $fn) {
            $qreq->set_file($name, [
                "name" => basename($fn),
                "type" => "application/octet-stream",
                "tmp_name" => $fn,
                "size" => filesize($fn)
            ]);
        }

        // look up and run search action
        $res = (new ListActionCall($this->user, ListAction::F_API))
            ->call($this->actionname, $qreq, $ssel)
            ->resolved_result();

        // emit output to STDOUT, avoiding `emit()`’s header/buffer handling
        if ($res instanceof Downloader) {
            $res->emit_to_stream(STDOUT);
            return 0;
        } else if ($res instanceof JsonResult) {
            if (isset($res->content["message_list"])) {
                $this->report($res->content["message_list"]);
            }
            fwrite(STDOUT, json_encode_db($res->content, JSON_PRETTY_PRINT) . "\n");
            return $res->status && $res->status >= 300 ? 1 : 0;
        } else if ($res instanceof Redirection) {
            fwrite(STDERR, "Search action wants redirection to {$res->url}\n");
            return 1;
        } else {
            fwrite(STDERR, "Search action produced no output\n");
            return 1;
        }
    }

    /** @return Getopt */
    static function make_getopt() {
        return (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "q:,query:,search: =SEARCH Use papers matching SEARCH",
            "t:,scope:,type: =SCOPE Scope of search [default]",
            "no-force Do not override administrator conflicts",
            "post,P Use POST method",
            "param[]+ =NAME=VALUE Set action parameters",
            "file-param[]+ =NAME=FILE Set action parameter files",
            "help,h !"
        )->description("Run a HotCRP search action.
Usage: php batch/searchaction.php [-q SEARCH] [-t SCOPE] [-P] ACTION [NAME=VALUE]...")
         ->helpopt("help")
         ->interleave(true);
    }

    /** @param list<string> $argv
     * @return SearchAction_Batch */
    static function make_args($argv) {
        $getopt = self::make_getopt();
        $arg = $getopt->parse($argv);
        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new SearchAction_Batch($conf, $arg, $getopt);
    }
}
