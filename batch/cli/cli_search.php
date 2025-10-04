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
    /** @var ?string */
    public $action;
    /** @var bool */
    public $output_content_string = false;
    /** @var Getopt */
    public $getopt;

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        if ($this->help) {
            return $this->run_help($clib);
            return $this->run_actions($clib, $this->action);
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
            $clib->set_output($this->getopt->help("search")
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
        $curlh = $clib->make_curl();
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/searchactions");
        if (!$clib->exec_api($curlh, null)) {
            return 1;
        }
        if ($this->json) {
            $clib->set_output_json($clib->content_json->actions ?? []);
            return 0;
        }
        $x = [];
        if ($this->getopt && !$this->action) {
            $x[] = "Available search actions:\n";
        }
        foreach ($clib->content_json->actions ?? [] as $aj) {
            if ($this->action && $aj->name !== $this->action) {
                continue;
            }
            foreach (["get", "post"] as $method) {
                if (!($aj->$method ?? false)) {
                    continue;
                }
                $name = $aj->name . ($method === "post" ? " --post" : "");
                if (isset($aj->title)) {
                    $t = str_replace("/", " → ", $aj->title);
                } else {
                    $t = $aj->description ?? "";
                }
                if ($this->action) {
                    $x[] = $t !== "" ? "{$name}:\n  {$t}\n" : "{$name}\n";
                } else if ($t !== "") {
                    $x[] = sprintf("%-30s %s\n", $name, $t);
                } else {
                    $x[] = "{$name}\n";
                }
                if ($this->action && !empty($aj->parameters)) {
                    $x[] = "\nParameters:\n";
                    foreach ($aj->parameters as $pj) {
                        $x[] = ViewOptionType::make($pj)->unparse_help_line();
                    }
                    $x[] = "\n";
                }
            }
        }
        if ($this->getopt && $this->action && empty($x)) {
            $clib->error_at(null, "<0>No such action");
            return 1;
        } else if ($this->getopt && !$this->action) {
            $x[] = "\nUse `php batch/hotcrapi.php search help action ACTION` for action parameters.\n\n";
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
            $clib->set_output_json($clib->content_json->fields ?? []);
            return 0;
        }
        $x = [];
        if ($this->getopt && !$this->action) {
            $x[] = "Available display fields:\n";
        }
        foreach ($clib->content_json->fields ?? [] as $aj) {
            if ($this->action && $aj->name !== $this->action) {
                continue;
            }
            if (isset($aj->title)) {
                $t = $aj->title;
            } else if (isset($aj->description)) {
                $t = Ftext::as(0, $aj->description, 0);
            } else {
                $t = "";
            }
            if ($this->action) {
                $x[] = $t !== "" ? "{$aj->name}:\n  {$t}\n" : "{$aj->name}\n";
            } else if ($t !== "") {
                $x[] = sprintf("%-23s %s\n", $aj->name, $t);
            } else {
                $x[] = "{$aj->name}\n";
            }
            if ($this->action && !empty($aj->parameters)) {
                $x[] = "\nParameters:\n";
                foreach ($aj->parameters as $pj) {
                    $x[] = ViewOptionType::make($pj)->unparse_help_line();
                }
                $x[] = "\n";
            }
        }
        if ($this->getopt && $this->action && empty($x)) {
            $clib->error_at(null, "<0>No such field");
            return 1;
        } else if ($this->getopt && !$this->action) {
            $x[] = "\nUse `php batch/hotcrapi.php search help field FIELD` for field parameters.\n\n";
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

        for ($argi = 0; $argi < $argc; ++$argi) {
            $arg = $argv[$argi];
            if ($arg === "search") {
                // ignore
            } else if ($arg === "help" && $pcb->action === null) {
                $pcb->help = 1;
                $pcb->getopt = $getopt;
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

        return $pcb;
    }

    static function register(Hotcrapi_Batch $clib, Getopt $getopt) {
        $getopt->subcommand_description(
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
            "param[]+ =NAME=VALUE !search Set action parameters"
        );
        $clib->register_command("search", "Search_CLIBatch");
    }
}
