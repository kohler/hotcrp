<?php
// apitoken.php -- HotCRP maintenance script for creating API (bearer) tokens
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(APIToken_Batch::make_args($argv)->run());
}

class APIToken_Batch {
    /** @var Conf */
    public $conf;
    /** @var string */
    private $subcommand;
    /** @var int */
    private $expiry;
    /** @var bool */
    private $cdb;
    /** @var string */
    private $note;
    /** @var string */
    private $scope;
    /** @var ?string */
    private $email;
    /** @var ?string */
    private $token;
    /** @var bool */
    private $active_only;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->subcommand = $arg["_subcommand"] ?? "create";
        $s = $arg["expiry"] ?? "30d";
        if (strcasecmp($s, "never") === 0) {
            $this->expiry = -1;
        } else if (($n = stonum($s)) !== null) {
            $this->expiry = (int) ($n * 86400);
        } else if (($n = SettingParser::parse_duration($s)) !== null) {
            $this->expiry = (int) $n;
        } else {
            throw new CommandLineException("Bad `--expiry`");
        }
        $this->cdb = isset($arg["cdb"]);
        $this->note = simplify_whitespace(convert_to_utf8($arg["note"] ?? ""));
        $this->scope = simplify_whitespace($arg["scope"] ?? "");
        if (!preg_match('/\A(?:[a-z][!\#-\x5b\x5d-~]*+\s*+)*+\z/', $this->scope)) {
            throw new CommandLineException("Bad `--scope`");
        }
        $this->active_only = isset($arg["active"]);
        if (!empty($arg["_"])) {
            if (strpos($arg["_"][0], "@") === false) {
                $this->token = $arg["_"][0];
            } else {
                $this->email = $arg["_"][0];
            }
        }
        if (isset($arg["user"]) && isset($this->email)) {
            throw new CommandLineException("Redundant `--user`");
        } else if (isset($arg["user"])) {
            $this->email = $arg["user"];
        }
        if (isset($arg["token"]) && isset($this->token)) {
            throw new CommandLineException("Redundant `--token`");
        } else if (isset($arg["token"])) {
            $this->token = $arg["token"];
        }
    }

    /** @return int */
    function run() {
        if ($this->subcommand === "list") {
            return $this->run_list();
        }
        return $this->run_create();
    }

    /** @return int */
    private function run_create() {
        if (isset($this->token)) {
            throw new CommandLineException("`{$this->token}` is not an email address");
        } else if (!isset($this->email)) {
            throw new CommandLineException("`--user` required");
        }
        if ($this->cdb) {
            $u = $this->conf->cdb_user_by_email($this->email);
        } else {
            $u = $this->conf->user_by_email($this->email);
        }
        if (!$u) {
            throw new CommandLineException("User not found");
        }
        $token = Authorization_Token::prepare_bearer($u, $this->expiry);
        if ($this->note !== "") {
            $token->change_data("note", $this->note);
        }
        if ($this->scope !== "") {
            $token->change_data("scope", $this->scope);
        }
        $token->insert();
        if (!$token->stored()) {
            throw new CommandLineException("Could not create token");
        }
        fwrite(STDOUT, $token->salt . "\n");
        return 0;
    }

    /** @return int */
    private function run_list() {
        if ($this->cdb && !$this->conf->contactdb()) {
            throw new CommandLineException("No contact database");
        }
        $toks = [];
        $found_user = !isset($this->email);
        foreach ($this->cdb ? [true] : [false, true] as $is_cdb) {
            $dblink = $is_cdb ? $this->conf->contactdb() : $this->conf->dblink;
            if (!$dblink) {
                continue;
            }
            $uid = null;
            if (isset($this->email)) {
                if ($is_cdb) {
                    $u = $this->conf->cdb_user_by_email($this->email);
                    $uid = $u ? $u->contactDbId : 0;
                } else {
                    $u = $this->conf->user_by_email($this->email);
                    $uid = $u ? $u->contactId : 0;
                }
                if ($uid <= 0) {
                    continue;
                }
                $found_user = true;
            }
            array_push($toks, ...$this->find_tokens($dblink, $is_cdb, $uid));
        }
        if (!$found_user) {
            throw new CommandLineException("User not found");
        }
        usort($toks, function ($a, $b) {
            return strcmp($a->email ?? "", $b->email ?? "")
                ? : (($a->timeCreated <=> $b->timeCreated)
                     ? : strcmp($a->salt, $b->salt));
        });
        foreach ($toks as $tok) {
            fwrite(STDOUT, $this->unparse_token($tok));
        }
        return 0;
    }

    /** @param \mysqli $dblink
     * @param bool $is_cdb
     * @param ?int $uid
     * @return list<TokenInfo> */
    private function find_tokens($dblink, $is_cdb, $uid) {
        $qf = ["capabilityType=?"];
        $qv = [TokenInfo::BEARER];
        if (isset($this->token)) {
            // bearer tokens look like `hct_...` (local) or `hcT_...` (cdb);
            // supply the prefix if the user left it off
            $t = $this->token;
            if (!str_starts_with($t, "hct_") && !str_starts_with($t, "hcT_")) {
                $t = ($is_cdb ? "hcT_" : "hct_") . $t;
            }
            $qf[] = "salt like ?";
            $qv[] = Dbl::escape_like($t) . "%";
        }
        if ($uid !== null) {
            $qf[] = "contactId=?";
            $qv[] = $uid;
        }
        $idcol = $is_cdb ? "contactDbId" : "contactId";
        $result = Dbl::qe_apply($dblink, "select *, (select email from ContactInfo where {$idcol}=Capability.contactId) email from Capability where " . join(" and ", $qf), $qv);
        $toks = [];
        while (($tok = TokenInfo::fetch($result, $this->conf, $is_cdb))) {
            if (!$this->active_only || $tok->is_active()) {
                $toks[] = $tok;
            }
        }
        Dbl::free($result);
        return $toks;
    }

    /** @return string */
    private function unparse_token(TokenInfo $tok) {
        $who = $tok->email() ?? ($tok->contactId > 0 ? "#{$tok->contactId}" : "(no user)");
        $f = [];
        if ($tok->is_cdb) {
            $f[] = "global";
        }
        $f[] = "created " . $this->conf->unparse_time_point($tok->timeCreated);
        $expiry = $tok->inactive_at();
        if ($expiry <= 0) {
            $f[] = "never expires";
        } else if ($expiry <= Conf::$now) {
            $f[] = "expired " . $this->conf->unparse_time_point($expiry);
        } else {
            $f[] = "valid until " . $this->conf->unparse_time_point($expiry);
        }
        if ($tok->useCount <= 0) {
            $f[] = "never used";
        } else if ($tok->timeUsed > 0) {
            $f[] = "used " . plural($tok->useCount, "time")
                . ", last " . $this->conf->unparse_time_point($tok->timeUsed);
        } else {
            $f[] = "used " . plural($tok->useCount, "time");
        }
        $scope = $tok->data("scope") ?? "";
        $f[] = $scope === "" ? "full scope" : "scope {$scope}";
        $note = $tok->data("note") ?? "";
        if ($note !== "") {
            $f[] = "note \"{$note}\"";
        }
        return "{$tok->salt}  {$who}\n    " . join(" · ", $f) . "\n";
    }

    /** @param list<string> $argv
     * @return APIToken_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "user:,u: =EMAIL Set user",
            "expiry:,expiration:,e: =DURATION Set expiration [30d]",
            "note: =NOTE Set note",
            "scope:,S: =SCOPE Set API scope",
            "token:,T: =TOKEN !list Search for TOKEN",
            "active !list Only show active tokens",
            "cdb Global token"
        )->description("Create and list HotCRP API tokens.
Usage: php batch/apitoken.php [-n CONFID|--config CONFIG] [create] [ARGS...] EMAIL
       php batch/apitoken.php [-n CONFID|--config CONFIG] list [ARGS...] [EMAIL|TOKEN]")
         ->helpopt("help")
         ->interleave(true)
         ->subcommand("create Create an API token",
                      "list List API tokens")
         ->maxarg(1)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new APIToken_Batch($conf, $arg);
    }
}
