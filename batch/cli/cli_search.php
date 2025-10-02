<?php
// cli_search.php -- Hotcrapi script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Search_CLIBatch implements CLIBatchCommand {
    /** @var ?string */
    public $q;
    /** @var ?string */
    public $t;
    /** @var bool */
    public $json;
    /** @var bool */
    public $post;
    /** @var list<string> */
    public $fields;
    /** @var string */
    public $format;
    /** @var bool */
    public $warn_missing;
    /** @var array<string,string> */
    public $param = [];
    /** @var ?string */
    public $action;
    /** @var bool */
    public $output_content_string = false;

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        if ($this->action === "actions") {
            return $this->run_actions($clib);
        } else if ($this->action === "fields") {
            return $this->run_fields($clib);
        } else if ($this->action) {
            return $this->run_action($clib);
        }
        return $this->run_get($clib);
    }

    /** @return bool */
    function live_action() {
        return $this->action && $this->action !== "actions" && $this->action !== "fields";
    }

    /** @return int */
    function run_get(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl();
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, "GET");
        if ($this->warn_missing) {
            $this->param["warn_missing"] = "1";
        }
        if (!empty($this->fields)) {
            $this->param["format"] = $this->format;
            $this->param["f"] = join(" ", $this->fields);
        }
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/search?" . http_build_query($this->param));
        if (!$clib->exec_api($curlh, null)) {
            return 1;
        }
        $j = $clib->content_json;
        if ($this->json) {
            $clib->set_json_output($j);
        } else if (!empty($j->fields) && $this->format === "json") {
            $clib->set_json_output($j->papers);
        } else if (!empty($j->fields)) {
            $csv = new CsvGenerator;
            $header = ["pid"];
            foreach ($j->fields as $fj) {
                $header[] = $fj->name;
            }
            $csv->select($header);
            foreach ($j->papers as $pj) {
                $csv->add_row((array) $pj);
            }
            $clib->set_output($csv->unparse());
        } else if (!empty($j->ids)) {
            $clib->set_output(join("\n", $j->ids) . "\n");
        }
        return 0;
    }

    /** @return int */
    function run_actions(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl();
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/searchactions");
        if (!$clib->exec_api($curlh, null)) {
            return 1;
        }
        if ($this->json) {
            $clib->set_json_output($clib->content_json->actions ?? []);
            return 0;
        }
        $x = [];
        foreach ($clib->content_json->actions ?? [] as $aj) {
            foreach (["get", "post"] as $method) {
                if (!($aj->$method ?? false)) {
                    continue;
                }
                if (isset($aj->title)) {
                    $t = str_replace("/", " → ", $aj->title);
                } else {
                    $t = $aj->description ?? "";
                }
                if (!empty($aj->parameters)) {
                    $t = ltrim("{$t}  [{$aj->parameters}]");
                }
                if ($t !== "") {
                    $x[] = sprintf("%-23s %-5s %s\n", $aj->name, $method, $t);
                } else {
                    $x[] = sprintf("%-23s %s\n", $aj->name, $method);
                }
            }
        }
        $clib->set_output(join("", $x));
        return 0;
    }

    /** @return int */
    function run_fields(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl();
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/displayfields");
        if (!$clib->exec_api($curlh, null)) {
            return 1;
        }
        if ($this->json) {
            $clib->set_json_output($clib->content_json->fields ?? []);
            return 0;
        }
        $x = [];
        foreach ($clib->content_json->fields ?? [] as $aj) {
            if (isset($aj->title)) {
                $t = $aj->title;
            } else if (isset($aj->description)) {
                $t = Ftext::as(0, $aj->description, 0);
            } else {
                $t = "";
            }
            $p = [];
            foreach ($aj->parameters ?? [] as $pj) {
                $p[] = $pj->name;
            }
            if (!empty($p)) {
                $t = ltrim("{$t}  [" . join(" ", $p) . "]");
            }
            if ($t !== "") {
                $x[] = sprintf("%-23s %s\n", $aj->name, $t);
            } else {
                $x[] = "{$aj->name}\n";
            }
        }
        $clib->set_output(join("", $x));
        return 0;
    }

    function skip_action(Hotcrapi_Batch $clib) {
        if ($clib->status_code >= 200 && $clib->status_code <= 299) {
            $this->output_content_string = true;
            return true;
        }
        return false;
    }

    /** @return int */
    function run_action(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl();
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, $this->post ? "POST" : "GET");
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/searchaction?" . http_build_query(["action" => $this->action] + $this->param));
        if (!$clib->exec_api($curlh, [$this, "skip_action"])) {
            return 1;
        }
        $clib->set_output($clib->content_string);
        return 0;
    }

    /** @return Search_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, Getopt $getopt, $arg) {
        $pcb = new Search_CLIBatch;
        $pcb->q = $pcb->param["q"] = $arg["q"] ?? "";
        $pcb->t = $arg["t"] ?? null;
        if ($pcb->t) {
            $pcb->param["t"] = $pcb->t;
        }
        $pcb->json = isset($arg["json"]);
        $pcb->post = isset($arg["post"]);
        $pcb->fields = $arg["f"] ?? [];
        $pcb->format = $arg["F"] ?? "csv";
        $pcb->warn_missing = isset($arg["warn-missing"]);
        $other_param = false;
        foreach ($arg["param"] ?? [] as $pstr) {
            if (($eq = strpos($pstr, "=")) === false) {
                throw new CommandLineException("Expected `--param NAME=VALUE`");
            }
            $pcb->param[substr($pstr, 0, $eq)] = substr($pstr, $eq + 1);
            $other_param = true;
        }

        $argv = $arg["_"];
        $argc = count($argv);

        $argi = 0;
        if ($argi < $argc && $argv[$argi] === "search") {
            ++$argi;
        } else if ($argi < $argc && $argv[$argi] === "help") {
            fwrite(STDOUT, $getopt->help("search"));
            exit(0);
        } else if ($argi < $argc) {
            $pcb->action = $argv[$argi];
            ++$argi;
        }

        while ($argi < $argc && ($eq = strpos($argv[$argi], "=")) !== false) {
            $pcb->param[substr($argv[$argi], 0, $eq)] = substr($argv[$argi], $eq + 1);
            $other_param = true;
            ++$argi;
        }

        if ($argi < $argc) {
            throw new CommandLineException("Too many arguments");
        } else if ($other_param && !$pcb->live_action()) {
            throw new CommandLineException("`--param` ignored");
        }

        return $pcb;
    }

    static function register(Hotcrapi_Batch $clib, Getopt $getopt) {
        $getopt->subcommand_description(
            "search",
            "Search HotCRP papers or perform search actions
Usage: php batch/hotcrapi.php search -q SEARCH [-f FIELD...]
       php batch/hotcrapi.php search fields
       php batch/hotcrapi.php search actions
       php batch/hotcrapi.php search ACTION [-P] -q SEARCH"
        )->long(
            "q:,query: =SEARCH !search Submission search",
            "t:,type: =TYPE !search Collection to search [viewable]",
            "json,j !search Output JSON response",
            "f[]+,field[]+ =FIELD !search Request additional display fields",
            "F:,format: !search Change display field format",
            "warn-missing !search Warn on missing IDs",
            "post,P !search Use POST method",
            "param[]+ =NAME=VALUE !search Set action parameters"
        );
        $clib->register_command("search", "Search_CLIBatch");
    }
}
