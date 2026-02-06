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
    /** @var int */
    public $help = 0;
    /** @var array<string,string> */
    public $param = [];
    /** @var array<string,string|CURLFile> */
    public $file_param = [];
    /** @var ?string */
    public $action;

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        if ($this->help) {
            return $this->run_help($clib);
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
        $curlh = $clib->make_curl("GET");
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
            $clib->set_output_json($j);
        } else if (!empty($j->fields) && $this->format === "json") {
            $clib->set_output_json($j->papers);
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
    function run_help(Hotcrapi_Batch $clib) {
        if ($this->help === 1) {
            $clib->set_output($clib->getopt->help("search")
                . "`php batch/hotcrapi.php search help fields` lists display fields.
`php batch/hotcrapi.php search help actions` lists search actions.
`php batch/hotcrapi.php search help field FIELD` shows field parameters.
`php batch/hotcrapi.php search help action ACTION` shows action parameters.
\n");
            return 0;
        } else if ($this->help === 2) {
            return $this->run_actions($clib);
        } else {
            return $this->run_fields($clib);
        }
    }

    /** @return int */
    function run_actions(Hotcrapi_Batch $clib) {
        $phb = new ParameterHelp_CLIBatch;
        $phb->subcommand = "search";
        $phb->api_endpoint = "searchactions";
        $phb->key = "actions";
        $phb->title = "Search action";
        $phb->action = $this->action;
        $phb->json = $this->json;
        if (!$this->action) {
            $phb->trailer = "`php batch/hotcrapi.php search help action ACTION` shows action parameters.\n";
        }
        $phb->expand_callback = function ($j) {
            $js = [];
            foreach (["get", "post"] as $method) {
                if (!($j->$method ?? false)) {
                    continue;
                }
                $jc = clone $j;
                if ($method === "post" ){
                    $jc->name .= " --post";
                }
                if (!isset($jc->description) && isset($jc->title)) {
                    $jc->description = str_replace("/", " → ", $jc->title);
                }
                unset($jc->title);
                $js[] = $jc;
            }
            return $js;
        };
        return $phb->run_help($clib);
    }

    /** @return int */
    function run_fields(Hotcrapi_Batch $clib) {
        $phb = new ParameterHelp_CLIBatch;
        $phb->subcommand = "search";
        $phb->api_endpoint = "displayfields";
        $phb->key = "fields";
        $phb->title = "Display field";
        $phb->action = $this->action;
        $phb->json = $this->json;
        if (!$this->action) {
            $phb->trailer = "`php batch/hotcrapi.php search help field NAME` shows field parameters.\n";
        }
        return $phb->run_help($clib);
    }

    function skip_action(Hotcrapi_Batch $clib) {
        return $clib->status_code >= 200 && $clib->status_code <= 299;
    }

    /** @return int */
    function run_action(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl();
        if (!$this->post && strlen(http_build_query($this->param)) > 6000) {
            $this->file_param = $this->param + $this->file_param;
            $this->param = [":method:" => "GET"];
            $this->post = true;
        } else if (!$this->post && $this->file_param) {
            $this->param[":method:"] = "GET";
            $this->post = true;
        }
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, $this->post ? "POST" : "GET");
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/searchaction?" . http_build_query(["action" => $this->action] + $this->param));
        if ($this->file_param) {
            curl_setopt($curlh, CURLOPT_POSTFIELDS, $this->file_param);
        }
        if (!$clib->exec_api($curlh, [$this, "skip_action"])) {
            return 1;
        }
        $clib->set_output($clib->content_string);
        return 0;
    }

    /** @return Search_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, $arg) {
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
                throw new CommandLineException("Expected `--param NAME=VALUE`", $clib->getopt);
            }
            $pcb->param[substr($pstr, 0, $eq)] = substr($pstr, $eq + 1);
            $other_param = true;
        }
        foreach ($arg["file-param"] ?? [] as $pstr) {
            if (($eq = strpos($pstr, "=")) === false) {
                throw new CommandLineException("Expected `--file-param NAME=VALUE`", $clib->getopt);
            } else if (!is_readable(substr($pstr, $eq + 1))) {
                throw new CommandLineException(substr($pstr, $eq + 1) . ": Count not read file", $clib->getopt);
            }
            $pcb->file_param[substr($pstr, 0, $eq)] = new CURLFile(substr($pstr, $eq + 1));
            $other_param = true;
        }

        $argv = $arg["_"];
        $argc = count($argv);

        for ($argi = 0; $argi < $argc; ++$argi) {
            $arg = $argv[$argi];
            if ($arg === "search") {
                // ignore
            } else if ($arg === "help" && $pcb->action === null) {
                $pcb->help = 1;
            } else if ($pcb->help <= 1 && ($arg === "action" || $arg === "actions")) {
                $pcb->help = 2;
            } else if ($pcb->help <= 1 && ($arg === "field" || $arg === "fields")) {
                $pcb->help = 3;
            } else if (($eq = strpos($argv[$argi], "=")) !== false) {
                $pcb->param[substr($arg, 0, $eq)] = substr($arg, $eq + 1);
                $other_param = true;
            } else if ($pcb->action === null) {
                $pcb->action = $arg;
            } else {
                break;
            }
        }

        if ($argi < $argc) {
            throw new CommandLineException("Too many arguments");
        } else if ($other_param && !$pcb->live_action()) {
            throw new CommandLineException("`--param` ignored");
        }

        unset($pcb->param["action"], $pcb->file_param["action"]);
        return $pcb;
    }

    static function register(Hotcrapi_Batch $clib) {
        $clib->getopt->subcommand_description(
            "search",
            "Search HotCRP papers or perform search actions
Usage: php batch/hotcrapi.php search -q SEARCH [-f FIELD...]
       php batch/hotcrapi.php search help [fields | actions]
       php batch/hotcrapi.php search help [field FIELD | action ACTION]
       php batch/hotcrapi.php search ACTION [-P] -q SEARCH"
        )->long(
            "q:,query: =SEARCH !search Submission search",
            "t:,type: =TYPE !search Collection to search [viewable]",
            "json,j !search Output JSON response",
            "f[]+,field[]+ =FIELD !search Request additional display fields",
            "F:,format: !search Change display field format",
            "warn-missing !search Warn on missing IDs",
            "post,P !search Use POST method",
            "param[]+ =NAME=VALUE !search Set action parameters",
            "file-param[]+ =NAME=FILE !search Set action parameter files"
        );
        $clib->register_command("search", "Search_CLIBatch");
    }
}
