<?php
// cli_settings.php -- Hotcrapi script for interacting with site APIs
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
    /** @var ?string */
    public $output;

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
        $clib->set_output_file($this->output);
        if ($this->save) {
            return $this->run_save($clib);
        }
        return $this->run_get($clib);
    }

    /** @return int */
    function run_get(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl("GET");
        curl_setopt($curlh, CURLOPT_URL, $this->url);
        if (!$clib->exec_api($curlh, null)) {
            return 1;
        }
        $clib->set_output_json($clib->content_json->settings);
        return 0;
    }

    /** @return int */
    function run_save(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl();
        $s = stream_get_contents($this->cf->stream);
        if ($s === false) {
            throw CommandLineException::make_file_error($this->cf->input_filename);
        }
        curl_setopt($curlh, CURLOPT_URL, $this->url);
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curlh, CURLOPT_POSTFIELDS, $s);
        curl_setopt($curlh, CURLOPT_HTTPHEADER, [
            "Content-Type: " . Mimetype::JSON_UTF8_TYPE, "Content-Length: " . strlen($s)
        ]);
        $ok = $clib->exec_api($curlh, null);
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
            $clib->set_output_json($clib->content_json->settings);
        }
        if ($clib->verbose) {
            fwrite(STDERR, $clib->content_string);
        }
        return $ok ? 0 : 1;
    }

    /** @return Settings_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, $arg) {
        $pcb = new Settings_CLIBatch;
        $argv = $arg["_"];
        $argc = count($argv);

        $mode = "fetch";
        $argi = 0;
        if ($argi < $argc
            && in_array($argv[$argi], ["fetch", "save", "test"], true)) {
            $mode = $argv[$argi];
            ++$argi;
        }

        $pcb->save = $mode === "save" || $mode === "test";
        if ($pcb->save) {
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
        }

        if ($argi < $argc) {
            throw new CommandLineException("Too many arguments");
        }

        $pcb->dry_run = isset($arg["dry-run"]) || $mode === "test";
        $pcb->filter = $arg["filter"] ?? null;
        $pcb->exclude = $arg["exclude"] ?? null;
        $pcb->output = $arg["output"] ?? null;
        return $pcb;
    }

    static function register(Hotcrapi_Batch $clib) {
        $clib->getopt->subcommand_description(
            "settings",
            "Retrieve or change HotCRP settings
Usage: php batch/hotcrapi.php settings [--filter F | --exclude F]
       php batch/hotcrapi.php settings save [-d] [-f FILE | -e JSON]
       php batch/hotcrapi.php settings test [-d] [-f FILE | -e JSON]"
        )->long(
            "filter: !settings =EXPR Only include settings matching EXPR",
            "exclude: !settings =EXPR Exclude settings matching EXPR",
            "dry-run,d !settings Don’t actually save changes",
            "file:,f: !settings =FILE Change settings using FILE",
            "expr:,e: !settings =JSON Change settings using JSON",
            "output:,o: !settings =FILE Write settings to FILE"
        );
        $clib->register_command("settings", "Settings_CLIBatch");
    }
}
