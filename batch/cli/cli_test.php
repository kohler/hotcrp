<?php
// test_cli.php -- Hotcrapi script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Test_CLIBatch implements CLIBatchCommand {
    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($clib->curlh, CURLOPT_URL, "{$clib->site}/whoami");
        if (!$clib->exec_api(null)) {
            return 1;
        }
        if (($clib->content_json->ok ?? false)
            && is_string($clib->content_json->email ?? null)) {
            $t = $clib->content_json->email;
            if (isset($clib->content_json->roles)) {
                $t .= " [" . join(" ", $clib->content_json->roles) . "]";
            }
            $clib->set_output("{$t}\n");
            return 0;
        }
        return 1;
    }

    /** @return Test_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, Getopt $getopt, $arg) {
        return new Test_CLIBatch;
    }

    static function register_options(Getopt $getopt) {
        $getopt->subcommand_description(
            "test",
            "Test API connection
Usage: php batch/hotcrapi.php test"
        )->long(
            "j,json !test Output JSON"
        );
    }
}
