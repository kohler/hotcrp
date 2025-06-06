<?php
// test_cli.php -- Hotcrapi script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Test_CLIBatch implements CLIBatchCommand {
    /** @var bool */
    private $email = false;
    /** @var bool */
    private $roles = false;

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($clib->curlh, CURLOPT_URL, "{$clib->site}/whoami");
        if (!$clib->exec_api(null)
            || !($clib->content_json->ok ?? false)) {
            return 1;
        }
        if (!$clib->quiet) {
            if ($this->email || $this->roles) {
                $t = $clib->content_json->email ?? null;
                if (!is_string($t)) {
                    $t = "__unknown__";
                }
                if ($this->roles && isset($clib->content_json->roles)) {
                    $t .= " [" . join(" ", $clib->content_json->roles) . "]";
                }
            } else {
                $t = "Success";
            }
            $clib->set_output("{$t}\n");
        }
        return 0;
    }

    /** @return Test_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, Getopt $getopt, $arg) {
        $tb = new Test_CLIBatch;
        $tb->email = isset($arg["email"]);
        $tb->roles = isset($arg["roles"]);
        return $tb;
    }

    static function register(Hotcrapi_Batch $clib, Getopt $getopt) {
        $getopt->subcommand_description(
            "test",
            "Test API connection
Usage: php batch/hotcrapi.php test"
        )->long(
            "j,json !test Output JSON",
            "email !test Output associated email",
            "roles !test Output associated email and roles"
        );
        $clib->register_command("test", "Test_CLIBatch");
    }
}
