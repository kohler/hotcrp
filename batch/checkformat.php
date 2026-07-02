<?php
// checkformat.php -- HotCRP batch format-checker script
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(CheckFormat_Batch::make_args($argv)->run());
}

class CheckFormat_Batch {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var array<string,string> */
    private $param;

    function __construct(Contact $user, $arg) {
        $this->conf = $user->conf;
        $this->user = $user;
        // Assemble the same request parameters accepted by the `formatcheck` API.
        $param = [];
        foreach (["p", "dt", "attachment", "hash", "at", "version"] as $k) {
            if (isset($arg[$k])) {
                $param[$k] = $arg[$k];
            }
        }
        if (isset($arg["final"])) {
            $param["final"] = "1";
        }
        if (isset($arg["soft"])) {
            $param["soft"] = "1";
        }
        if (isset($arg["detail"])) {
            $param["detail"] = "1";
        }
        if (!isset($param["p"]) && !empty($arg["_"])) {
            $param["p"] = $arg["_"][0];
        }
        $this->param = $param;
    }

    /** @return int */
    function run() {
        $qreq = (new Qrequest("GET", $this->param))->set_user($this->user);
        $jd = FormatCheck_API::run($this->user, $qreq);
        fwrite(STDOUT, json_encode($jd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        return ($jd["ok"] ?? false) ? 0 : 1;
    }

    /** @return CheckFormat_Batch */
    static function make_args($argv) {
        $getopt = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "user:,u: =EMAIL Act as user EMAIL [default chair]",
            "p:,paper: =PID Check submission PID",
            "dt:,type: =DTYPE Check document type DTYPE [default paper]",
            "final Check the final-version document",
            "attachment:,a: =NAME Check attachment NAME",
            "hash: =HASH Restrict to document with hash HASH",
            "at: =TIME Check document version stored at TIME",
            "version: =V Check document version V",
            "soft Run the checker only if a fresh result is not cached",
            "detail Include per-page-type page counts",
            "help,h !"
        )->description("Run the HotCRP format checker on a submission document and print JSON.
Usage: php batch/checkformat.php [-n CONFID] [-u EMAIL] -p PID [OPTIONS]")
         ->helpopt("help")
         ->maxarg(1);
        $arg = $getopt->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        $email = $arg["user"] ?? null;
        if ($email === null) {
            $user = $conf->root_user();
        } else if (!($user = $conf->user_by_email($email) ?? $conf->cdb_user_by_email($email))) {
            throw new CommandLineException("User {$email} not found");
        }
        return new CheckFormat_Batch($user, $arg);
    }
}
