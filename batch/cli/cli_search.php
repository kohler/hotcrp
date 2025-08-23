<?php
// search_cli.php -- Hotcrapi script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Search_CLIBatch implements CLIBatchCommand {
    /** @var string */
    public $url;
    /** @var bool */
    public $json;
    /** @var ?string */
    public $q;
    /** @var ?string */
    public $t;
    /** @var bool */
    public $actions;
    /** @var ?string */
    public $action;
    /** @var string */
    public $param;
    /** @var bool */
    public $output_content_string = false;

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        if ($this->actions) {
            return $this->run_actions($clib);
        }
        $args = ["q=" . urlencode($this->q)];
        if (!empty($this->t)) {
            $args[] = "t=" . urlencode($this->t);
        }
        $this->param = join("&", $args);
        if ($this->action) {
            return $this->run_get_action($clib);
        }
        return $this->run_get($clib);
    }

    /** @return int */
    function run_get(Hotcrapi_Batch $clib) {
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($clib->curlh, CURLOPT_URL, "{$clib->site}/search?{$this->param}");
        if (!$clib->exec_api(null)) {
            return 1;
        }
        if ($this->json) {
            $clib->set_json_output($clib->content_json->ids ?? []);
        } else if (!empty($clib->content_json->ids)) {
            $clib->set_output(join("\n", $clib->content_json->ids) . "\n");
        }
        return 0;
    }

    /** @return int */
    function run_actions(Hotcrapi_Batch $clib) {
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($clib->curlh, CURLOPT_URL, "{$clib->site}/searchactions");
        if (!$clib->exec_api(null)) {
            return 1;
        }
        if ($this->json) {
            $clib->set_json_output($clib->content_json->actions ?? []);
        } else {
            $x = [];
            foreach ($clib->content_json->actions ?? [] as $aj) {
                if (isset($aj->title) && ($p = strpos($aj->title, "/")) > 0) {
                    $t = substr($aj->title, $p + 1);
                } else {
                    $t = $aj->title ?? $aj->description ?? "";
                }
                if ($t !== "") {
                    $x[] = sprintf("%-23s %s\n", $aj->name, $t);
                } else {
                    $x[] = $aj->name . "\n";
                }
            }
            $clib->set_output(join("", $x));
        }
        return 0;
    }

    function skip_get_action(Hotcrapi_Batch $clib) {
        if ($clib->status_code >= 200 && $clib->status_code <= 299) {
            $this->output_content_string = true;
            return true;
        }
        return false;
    }

    /** @return int */
    function run_get_action(Hotcrapi_Batch $clib) {
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($clib->curlh, CURLOPT_URL, "{$clib->site}/searchaction?action=" . urlencode($this->action) . "&{$this->param}");
        if (!$clib->exec_api([$this, "skip_get_action"])) {
            return 1;
        }
        $clib->set_output($clib->content_string);
        return 0;
    }

    /** @return int */
    function run_save(Hotcrapi_Batch $clib) {
        $s = stream_get_contents($this->cf->stream);
        if ($s === false) {
            throw CommandLineException::make_file_error($this->cf->input_filename);
        }
        curl_setopt($clib->curlh, CURLOPT_URL, $this->url);
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($clib->curlh, CURLOPT_POSTFIELDS, $s);
        curl_setopt($clib->curlh, CURLOPT_HTTPHEADER, [
            "Content-Type: " . Mimetype::JSON_UTF8_TYPE, "Content-Length: " . strlen($s)
        ]);
        $ok = $clib->exec_api(null);
        if (empty($clib->content_json->change_list)) {
            if (!$clib->has_error()) {
                $clib->success("<0>No changes");
            }
        } else if ($clib->content_json->dry_run ?? false) {
            $clib->success("<0>Would change " . commajoin($clib->content_json->change_list));
        } else {
            $clib->success("<0>Saved changes to " . commajoin($clib->content_json->change_list));
        }
        if ($clib->output_file() !== null
            && isset($clib->content_json->settings)) {
            $clib->set_json_output($clib->content_json->settings);
        }
        if ($clib->verbose) {
            fwrite(STDERR, $clib->content_string);
        }
        return $ok ? 0 : 1;
    }

    /** @return Search_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, Getopt $getopt, $arg) {
        $pcb = new Search_CLIBatch;
        $pcb->q = $arg["q"] ?? "";
        $pcb->t = $arg["t"] ?? null;
        $pcb->json = isset($arg["json"]);

        $argv = $arg["_"];
        $argc = count($argv);

        $argi = 0;
        if ($argi < $argc && $argv[$argi] === "actions") {
            $pcb->actions = true;
            ++$argi;
        } else if ($argi < $argc && $argv[$argi] === "search") {
            ++$argi;
        } else if ($argi < $argc) {
            $pcb->action = $argv[$argi];
            ++$argi;
        }

        /*if ($pcb->save) {
            if ($argi < $argc
                && preg_match('/\A[\[\{]/', $argv[$argi])) {
                $pcb->cf = Hotcrapi_File::make_data($argv[$argi]);
                ++$argi;
            } else if ($argi < $argc) {
                $pcb->cf = Hotcrapi_File::make($argv[$argi]);
                ++$argi;
            } else {
                $pcb->cf = Hotcrapi_File::make("-");
            }
        }*/

        if ($argi < $argc) {
            throw new CommandLineException("Too many arguments");
        }

        return $pcb;
    }

    static function register(Hotcrapi_Batch $clib, Getopt $getopt) {
        $getopt->subcommand_description(
            "search",
            "Search HotCRP papers
Usage: php batch/hotcrapi.php search -q QUERY
       php batch/hotcrapi.php search actions
       php batch/hotcrapi.php search ACTION -q QUERY"
        )->long(
            "q:,query: =SEARCH !search Submission search",
            "t:,type: =TYPE !search Collection to search [viewable]",
            "json,j !search Output JSON results"
        );
        $clib->register_command("search", "Search_CLIBatch");
    }
}
