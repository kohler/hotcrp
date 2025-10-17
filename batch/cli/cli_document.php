<?php
// cli_document.php -- Hotcrapi script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Document_CLIBatch implements CLIBatchCommand {
    /** @var ?string */
    public $doc;
    /** @var ?int */
    public $p;
    /** @var ?string */
    public $dt;

    /** @param array<string,mixed> $args
     * @return string */
    private function url_with(Hotcrapi_Batch $clib, $args) {
        $url = "{$clib->site}/assign";
        if (($x = http_build_query($args)) !== "") {
            $url .= (strpos($url, "?") === false ? "?" : "&") . $x;
        }
        return $url;
    }

    function skip_action(Hotcrapi_Batch $clib) {
        return $clib->status_code >= 200 && $clib->status_code <= 299;
    }

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        $param = [];
        if (isset($this->doc)) {
            $param["doc"] = $this->doc;
        } else {
            $param["p"] = $this->p;
            $param["dt"] = $this->dt ?? "0";
        }

        $curlh = $clib->make_curl("GET");
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/document?" . http_build_query($param));
        if (!$clib->exec_api($curlh, [$this, "skip_action"])) {
            return 1;
        }
        $clib->set_output($clib->content_string);
        return 0;
    }

    /** @return Document_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, $arg) {
        $pcb = new Document_CLIBatch;
        $pcb->p = $arg["p"] ?? null;
        $pcb->dt = $arg["D"] ?? null;

        $argv = $arg["_"];
        $argc = count($argv);
        $argi = 0;

        if ($argi < $argc) {
            $pcb->doc = $argv[$argi];
            ++$argi;
        }

        if ($argi < $argc) {
            throw new CommandLineException("Too many arguments");
        } else if (!isset($pcb->doc) && !isset($pcb->p)) {
            throw new CommandLineException("Expected DOCNAME");
        }

        return $pcb;
    }

    static function register(Hotcrapi_Batch $clib) {
        $clib->getopt->subcommand_description(
            "document",
            "Fetch HotCRP documents
Usage: php batch/hotcrapi.php document DOCNAME > OUTPUT"
        )->long(
            "p:,paper: {n} =PID !document Set submission ID",
            "D:,dt: =DOCTYPE !document Set document type"
        );
        $clib->register_command("document", "Document_CLIBatch");
    }
}
