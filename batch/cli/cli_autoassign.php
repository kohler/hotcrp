<?php
// cli_autoassign.php -- Hotcrapi script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Autoassign_CLIBatch implements CLIBatchCommand {
    /** @var string */
    public $q;
    /** @var string */
    public $t;
    /** @var bool */
    public $dry_run;
    /** @var bool */
    public $minimal_dry_run;
    /** @var bool */
    public $summary;
    /** @var bool */
    public $json;
    /** @var bool */
    public $help = false;
    /** @var array<string,string> */
    public $param = [];
    /** @var list<string> */
    public $u = [];
    /** @var list<string> */
    public $disjoint = [];
    /** @var string */
    public $action;
    /** @var Getopt */
    public $getopt;

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        if ($this->action === "list" || $this->action === "autoassigners") {
            $this->help = false;
            return $this->run_list($clib, null);
        } else if ($this->help) {
            return $this->run_list($clib, $this->action);
        }
        return $this->run_post($clib);
    }

    /** @return int */
    function run_list(Hotcrapi_Batch $clib, $action) {
        $curlh = $clib->make_curl();
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/autoassigners");
        if (!$clib->exec_api($curlh, null)) {
            if ($this->help) {
                fwrite(STDOUT, $this->getopt->help("autoassign"));
                exit(0);
            }
            return 1;
        }
        if ($this->json) {
            $clib->set_output_json($clib->content_json->autoassigners ?? []);
            return 0;
        }
        $x = [];
        $found = false;
        $indent = $this->help ? "  " : "";
        $space = 25 - strlen($indent);
        foreach ($clib->content_json->autoassigners ?? [] as $aj) {
            if ($action && $aj->name !== $action) {
                continue;
            }
            $found = true;
            if (isset($aj->title)) {
                $t = $aj->title;
            } else if (isset($aj->description)) {
                $t = Ftext::as(0, $aj->description, 0);
            } else {
                $t = "";
            }
            if ($t === "") {
                $x[] = "{$indent}{$aj->name}\n";
            } else if ($action) {
                $x[] = "{$aj->name}\n  {$t}\n";
            } else {
                $x[] = sprintf("{$indent}%-{$space}s %s\n", $aj->name, $t);
            }
            if ($action) {
                $x[] = "\nParameters:\n";
                foreach ($aj->parameters ?? [] as $pj) {
                    $x[] = ViewOptionType::make($pj)->unparse_help_line();
                }
                $x[] = "\n";
            }
        }
        if ($action && !$found) {
            $clib->error_at(null, "Autoassigner not found");
        } else if ($this->help && !$action) {
            array_unshift($x, $this->getopt->help("autoassign"), "Autoassigners:\n");
        }
        $clib->set_output(join("", $x));
        return 0;
    }

    /** @param array<string,mixed> $args
     * @return string */
    private function url_with(Hotcrapi_Batch $clib, $args) {
        $url = "{$clib->site}/assign";
        if (($x = http_build_query($args)) !== "") {
            $url .= (strpos($url, "?") === false ? "?" : "&") . $x;
        }
        return $url;
    }

    /** @return int */
    function run_post(Hotcrapi_Batch $clib) {
        $args = [
            "autoassigner=" . urlencode($this->action),
            "q=" . urlencode($this->q),
            "t=" . urlencode($this->t)
        ];
        if ($this->minimal_dry_run) {
            $args[] = "minimal_dry_run=1";
        } else if ($this->dry_run) {
            $args[] = "dry_run=1";
        }
        foreach ($this->u as $u) {
            $args[] = "u%5B%5D=" . urlencode($u);
        }
        foreach ($this->disjoint as $dj) {
            $args[] = "disjoint%5B%5D=" . urlencode($dj);
        }
        foreach ($this->param as $k => $v) {
            $args[] = urlencode($k) . "=" . urlencode($v);
        }

        $curlh = $clib->make_curl("POST");
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/autoassign?" . join("&", $args));
        $ok = $clib->exec_api($curlh, null);

        if ($ok && isset($clib->content_json->job)) {
            $clib->set_progress_text_width($clib->columns() > 80 ? 60 : 40);
            $jobcli = (new Job_CLIBatch($clib->content_json->job))
                ->set_delay_first(true);
            $ok = $jobcli->run($clib);
        }

        if ($this->json) {
            $clib->set_output_json($clib->content_json);
        } else if ($ok && $clib->content_json->output) {
            $clib->set_output($clib->content_json->output);
        }
        return 0;
    }

    /** @return Autoassign_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, Getopt $getopt, $arg) {
        $pcb = new Autoassign_CLIBatch;
        $pcb->getopt = $getopt;
        $pcb->q = $arg["q"] ?? "";
        $pcb->t = $arg["t"] ?? "s";
        $pcb->dry_run = isset($arg["dry-run"]);
        $pcb->minimal_dry_run = isset($arg["minimal-dry-run"]);
        $pcb->summary = isset($arg["summary"]);
        $pcb->json = isset($arg["json"]);
        $pcb->u = $arg["u"] ?? [];
        $pcb->disjoint = $arg["disjoint"] ?? [];
        foreach ($arg["param"] ?? [] as $pstr) {
            if (($eq = strpos($pstr, "=")) === false) {
                throw new CommandLineException("Expected `--param NAME=VALUE`");
            }
            $pcb->param[substr($pstr, 0, $eq)] = substr($pstr, $eq + 1);
        }

        $argv = $arg["_"];
        $argc = count($argv);

        for ($argi = 0; $argi < $argc; ++$argi) {
            $arg = $argv[$argi];
            if (($eq = strpos($arg, "=")) !== false) {
                $pcb->param[substr($arg, 0, $eq)] = substr($arg, $eq + 1);
            } else if ($pcb->action === null) {
                if ($arg === "help") {
                    $pcb->help = true;
                } else {
                    $pcb->action = $arg;
                }
            } else {
                break;
            }
        }

        if ($argi < $argc) {
            throw new CommandLineException("Too many arguments");
        } else if (!$pcb->help && $pcb->action === null) {
            throw new CommandLineException("Missing `AUTOASSIGNER`");
        }

        return $pcb;
    }

    static function register(Hotcrapi_Batch $clib, Getopt $getopt) {
        $getopt->subcommand_description(
            "autoassign",
            "Perform HotCRP autoassignments
Usage: php batch/hotcrapi.php autoassign AUTOASSIGNER -q SEARCH [PARAM=VALUE...]
       php batch/hotcrapi.php autoassign help
       php batch/hotcrapi.php autoassign help AUTOASSIGNER"
        )->long(
            "q:,query: =SEARCH !autoassign Autoassignment papers",
            "t:,type: =TYPE !autoassign Collection to autoassign [s]",
            "dry-run,d !autoassign Don’t actually save changes",
            "minimal-dry-run !autoassign Like `--dry-run`, but outputs unsimplified assignment",
            "summary !autoassign Request an assignment summary",
            "json,j !autoassign Output JSON response",
            "u[]+ =USER !autoassign Users to consider for autoassignment",
            "disjoint[]+ =USER,USER !autoassign Users that should not be coassigned",
            "param[]+ =NAME=VALUE !autoassign Set autoassignment parameters"
        );
        $clib->register_command("autoassign", "Autoassign_CLIBatch");
    }
}
