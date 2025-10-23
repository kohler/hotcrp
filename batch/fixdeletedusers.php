<?php
// fixdeletedusers.php -- HotCRP paper export script
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(FixDeletedUsers_Batch::make_args($argv)->run());
}

class FixDeletedUsers_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $dry_run;
    /** @var bool */
    public $verbose;
    /** @var list<object> */
    private $delus = [];

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->dry_run = isset($arg["d"]);
        $this->verbose = isset($arg["V"]);
    }

    function add_delu($delu) {
        $delu->contactId = (int) $delu->contactId;
        foreach ($this->delus as $ux) {
            if (($delu->contactId === 0 || $ux->contactId === $delu->contactId)
                && strcasecmp($ux->email, $delu->email) === 0) {
                if ($ux->deltype === "unknown" || $delu->deltype === "deleted") {
                    $ux->deltype = $delu->deltype;
                }
                return;
            }
        }
        if (!validate_email($delu->email)) {
            return;
        }
        $this->delus[] = $delu;
    }

    /** @return int */
    function run() {
        $delu = [];
        $result = $this->conf->qe("select *, 'unknown' deltype from DeletedContactInfo");
        while (($row = $result->fetch_object())) {
            $this->add_delu($row);
        }
        $result->close();

        $result = $this->conf->qe("select * from ActionLog where action like 'Account %' or action like 'Permanently %' or action like 'Merged %'");
        while (($row = $result->fetch_object())) {
            if (preg_match('/\AAccount (deleted|merged) (\S+)/', $row->action, $m)) {
                $this->add_delu((object) ["contactId" => $row->destContactId, "email" => $m[2], "deltype" => $m[1]]);
            } else if (preg_match('/\APermanently (deleted) account (\S+)/', $row->action, $m)) {
                $this->add_delu((object) ["contactId" => $row->destContactId, "email" => $m[2], "deltype" => $m[1]]);
            } else if (preg_match('/\AMerged account (\S+)/', $row->action, $m)
                       && !ctype_digit($m[1])) {
                $this->add_delu((object) ["contactId" => $row->destContactId, "email" => $m[1], "deltype" => "merged"]);
            }
        }
        $result->close();

        $us = [];
        $result = $this->conf->qe("select contactId, email, cflags from ContactInfo");
        while (($row = $result->fetch_object())) {
            $row->contactId = (int) $row->contactId;
            $row->cflags = (int) $row->cflags;
            $us[strtolower($row->email)] = $row;
        }
        $result->close();

        foreach ($this->delus as $du) {
            if ($du->deltype === "merged" || $du->contactId <= 0) {
                continue;
            }
            $pu = $us[strtolower($du->email)] ?? null;
            if ($pu === null) {
                if ($this->verbose) {
                    fwrite(STDOUT, "{$this->conf->dbname}: {$du->email} [{$du->deltype}]: not in database\n");
                }
                $q = Dbl::format_query($this->conf->dblink, "insert into ContactInfo
                    set contactId=?, email=?,
                    firstName=?, lastName=?, unaccentedName=?, affiliation=?,
                    cflags=?, password=?
                    on duplicate key update cflags=cflags",
                    $du->contactId, $du->email,
                    $du->firstName ?? "", $du->lastName ?? "", $du->unaccentedName ?? "", $du->affiliation ?? "",
                    Contact::CF_DELETED, "");
                if ($this->verbose) {
                    fwrite(STDOUT, "+ " . simplify_whitespace($q) . "\n");
                }
                if (!$this->dry_run) {
                    Dbl::qe_raw($this->conf->dblink, $q);
                }
            } else if ($pu->contactId !== $du->contactId) {
                fwrite(STDOUT, "{$this->conf->dbname}: {$du->email} [{$du->deltype}]: different uid\n");
            } else if ($pu->cflags === Contact::CF_PLACEHOLDER) {
                if ($this->verbose) {
                    fwrite(STDOUT, "{$this->conf->dbname}: {$du->email} [{$du->deltype}]: placeholder\n");
                }
                if ($du->deltype === "deleted") {
                    $q = Dbl::format_query($this->conf->dblink, "update ContactInfo
                        set cflags=? where contactId=? and cflags=?",
                        Contact::CF_DELETED, $du->contactId, Contact::CF_PLACEHOLDER);
                    if ($this->verbose) {
                        fwrite(STDOUT, "+ " . simplify_whitespace($q) . "\n");
                    }
                    if (!$this->dry_run) {
                        Dbl::qe_raw($this->conf->dblink, $q);
                    }
                }
            } else if (($pu->cflags & Contact::CF_DELETED) === 0) {
                fwrite(STDOUT, "{$this->conf->dbname}: {$du->email} [{$du->deltype}]: non-placeholder " . dechex($pu->cflags) . "\n");
            }
        }
        return 0;
    }

    /** @param list<string> $argv
     * @return FixDeletedUsers_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "d,dry-run",
            "V,verbose"
        )->description("Add tombstone records for deleted users to HotCRP database.
Usage: php batch/fixdeletedusers.php [-d]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new FixDeletedUsers_Batch($conf, $arg);
    }
}
