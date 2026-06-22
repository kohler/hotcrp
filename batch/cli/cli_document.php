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
    /** @var ?int */
    public $docid;
    /** @var ?string */
    public $file;
    /** @var bool */
    public $history = false;
    /** @var bool */
    public $active = false;
    /** @var bool */
    public $json;

    function skip_action(Hotcrapi_Batch $clib) {
        return $clib->status_code >= 200 && $clib->status_code <= 299;
    }

    /** @return int */
    private function run_history($param, Hotcrapi_Batch $clib) {
        if (!$this->active) {
            $param["history"] = 1;
        }
        $curlh = $clib->make_curl("GET");
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/documentlist?" . http_build_query($param));
        if (!$clib->exec_api($curlh, [$this, "skip_action"])) {
            return 1;
        } else if ($this->json) {
            $clib->set_output($clib->content_string);
            return 0;
        }
        $t = [];
        $docid = false;
        $mw = $dtlen = 0;
        foreach ($clib->content_json->document_history as $dj) {
            $docid = $docid || isset($dj->docid);
            $mw = min(max($mw, strlen($dj->mimetype)), 30);
            $dtlen = max($dtlen, strlen($dj->dt ?? ""));
        }
        $docid_entry = $docid ? " %4s" : "%s";
        $dt_entry = $dtlen ? " %-{$dtlen}s " : "%s";
        foreach ($clib->content_json->document_history as $dj) {
            $t[] = sprintf("%s{$dt_entry}{$docid_entry}  %s  %8s  %-{$mw}s  %s\n",
                $dj->active ?? false ? "*" : " ",
                $dj->dt ?? "",
                $dj->docid ?? "",
                date("Y-m-d\\TH:i:s", $dj->attached_at),
                unparse_byte_size_binary_f($dj->size),
                UnicodeHelper::utf8_char_abbreviate($dj->mimetype, 30),
                UnicodeHelper::utf8_char_abbreviate($dj->filename, 40, 10));
        }
        $clib->set_output(join("", $t));
        return 0;
    }

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        $param = [];
        if (isset($this->doc)) {
            $param["doc"] = $this->doc;
        } else {
            $param["p"] = $this->p;
            if (isset($this->dt) || (!$this->history && !$this->active)) {
                $param["dt"] = $this->dt ?? "0";
            }
        }
        if ($this->history || $this->active) {
            return $this->run_history($param, $clib);
        }

        if (isset($this->docid)) {
            $param["docid"] = $this->docid;
        } else if (isset($this->file)) {
            $param["file"] = $this->file;
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
        $pcb->dt = $arg["dt"] ?? null;
        $pcb->docid = $arg["docid"] ?? null;
        $pcb->file = $arg["file"] ?? null;
        $pcb->history = isset($arg["history"]);
        $pcb->active = isset($arg["list"]);
        $pcb->json = isset($arg["json"]);

        $argv = $arg["_"];
        $argc = count($argv);
        $argi = 0;

        if ($argi < $argc && $argv[$argi] === "history") {
            $pcb->history = true;
            ++$argi;
        } else if ($argi < $argc && $argv[$argi] === "list") {
            $pcb->active = true;
            ++$argi;
        }
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
Usage: php batch/hotcrapi.php document [DOC | -p PID -dt DT] > OUTPUT
       php batch/hotcrapi.php document [history | list] -p PID"
        )->long(
            "p:,paper: {n} =PID !document Set submission ID",
            "dt:,D: =DOCTYPE !document Set document type",
            "file: =FILE !document Set filename",
            "docid: {n} =DOCID !document Set document ID",
            "list,L !document List active documents",
            "history,H !document Fetch document history",
            "json,j !document Generate JSON output for history"
        );
        $clib->register_command("document", "Document_CLIBatch");
    }
}
