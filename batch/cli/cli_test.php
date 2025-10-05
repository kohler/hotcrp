<?php
// cli_test.php -- Hotcrapi script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Test_CLIBatch implements CLIBatchCommand {
    /** @var bool */
    private $email = false;
    /** @var bool */
    private $roles = false;
    /** @var bool */
    private $json = false;

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl();
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/whoami");
        if (!$clib->exec_api($curlh, null)
            || !($clib->content_json->ok ?? false)) {
            return 1;
        }
        if ($clib->quiet) {
            return 0;
        } else if ($this->json) {
            $clib->set_output_json($clib->content_json);
            return 0;
        }
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
        return 0;
    }

    /** @return Test_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, $arg) {
        $tb = new Test_CLIBatch;
        $tb->email = isset($arg["email"]);
        $tb->roles = isset($arg["roles"]);
        $tb->json = isset($arg["json"]);
        return $tb;
    }

    static function register(Hotcrapi_Batch $clib) {
        $clib->getopt->subcommand_description(
            "test",
            "Test API connection
Usage: php batch/hotcrapi.php test"
        )->long(
            "json,j !test Output JSON",
            "email !test Output associated email",
            "roles !test Output associated email and roles"
        );
        $clib->register_command("test", "Test_CLIBatch");
    }
}
