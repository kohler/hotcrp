<?php
// settings_cli.php -- Hotcrapi script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Settings_CLIBatch implements CLIBatchCommand {
    /** @var string */
    public $url;
    /** @var bool */
    public $save;
    /** @var bool */
    public $dry_run;
    /** @var ?string */
    public $filter;
    /** @var ?string */
    public $exclude;
    /** @var Hotcrapi_File */
    public $cf;

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        $args = [];
        if (!empty($this->filter)) {
            $args[] = "filter=" . urlencode($this->filter);
        }
        if (!empty($this->exclude)) {
            $args[] = "exclude=" . urlencode($this->exclude);
        }
        if ($this->dry_run) {
            $args[] = "dry_run=1";
        }
        $this->url = "{$clib->site}/settings"
            . (empty($args) ? "" : "?" . join("&", $args));
        if ($this->save) {
            return $this->run_save($clib);
        } else {
            return $this->run_get($clib);
        }
    }

    /** @return int */
    function run_get(Hotcrapi_Batch $clib) {
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($clib->curlh, CURLOPT_URL, $this->url);
        if (!$clib->exec_api(null)) {
            return 1;
        }
        $clib->set_json_output($clib->content_json->settings);
        return 0;
    }

    /** @return int */
    function run_save(Hotcrapi_Batch $clib) {
        $s = stream_get_contents($this->cf->stream);
        if ($s === false) {
            throw CommandLineException::make_file_error($this->cf->input_filename);
        }
        $mt = Mimetype::content_type($s);
        if ($mt !== Mimetype::ZIP_TYPE) {
            if (!preg_match('/\A\s*+\{/s', $s)) {
                throw new CommandLineException("{$this->cf->input_filename}: Expected ZIP or JSON");
            }
            $mt = Mimetype::JSON_TYPE . "; charset=utf-8";
        }
        curl_setopt($clib->curlh, CURLOPT_URL, $this->url);
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($clib->curlh, CURLOPT_POSTFIELDS, $s);
        curl_setopt($clib->curlh, CURLOPT_HTTPHEADER, [
            "Content-Type: {$mt}", "Content-Length: " . strlen($s)
        ]);
        $ok = $clib->exec_api(null);
        if (empty($clib->content_json->change_list)) {
            $clib->success("<0>No changes");
        } else if ($clib->content_json->dry_run ?? false) {
            $clib->success("<0>Would change " . commajoin($clib->content_json->change_list));
        } else {
            $clib->success("<0>Saved changes to " . commajoin($clib->content_json->change_list));
        }
        if ($clib->verbose) {
            fwrite(STDERR, $clib->content_string);
        }
        return $ok ? 0 : 1;
    }

    /** @return Settings_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, Getopt $getopt, $arg) {
        $pcb = new Settings_CLIBatch;
        $argv = $arg["_"];
        $argc = count($argv);

        $mode = "fetch";
        $argi = 0;
        if ($argi < $argc && in_array($argv[$argi], ["fetch", "save"])) {
            $mode = $argv[$argi];
            ++$argi;
        }
        $pcb->save = $mode === "save";

        if ($pcb->save) {
            if ($argi < $argc
                && preg_match('/\A[\[\{]/', $argv[$argi])
                && json_validate($argv[$argi])) {
                $pcb->cf = Hotcrapi_File::make_data($argv[$argi]);
                ++$argi;
            } else if ($argi < $argc) {
                $pcb->cf = Hotcrapi_File::make($argv[$argi]);
                ++$argi;
            } else {
                $pcb->cf = Hotcrapi_File::make("-");
            }
        }

        if ($argi < $argc) {
            throw new CommandLineException("Too many arguments");
        }

        $pcb->dry_run = isset($arg["dry-run"]);
        $pcb->filter = $arg["filter"] ?? null;
        $pcb->exclude = $arg["exclude"] ?? null;
        return $pcb;
    }

    static function register_options(Getopt $getopt) {
        $getopt->subcommand_description(
            "settings",
            "Retrieve or change HotCRP settings
Usage: php batch/hotcrapi.php settings [--filter F | --exclude F]
       php batch/hotcrapi.php settings save [-d] [-f FILE | -e JSON]"
        )->long(
            "filter: !settings =EXPR Only include settings matching EXPR",
            "exclude: !settings =EXPR Exclude settings matching EXPR",
            "dry-run,d !settings Don’t actually save changes",
            "file:,f: !settings =FILE Change settings using FILE",
            "expr:,e: !settings =JSON Change settings using JSON"
        );
    }
}
