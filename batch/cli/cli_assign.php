<?php
// cli_assign.php -- Hotcrapi script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Assign_CLIBatch implements CLIBatchCommand {
    /** @var ?int */
    public $p;
    /** @var bool */
    public $dry_run;
    /** @var bool */
    public $summary;
    /** @var bool */
    public $json;
    /** @var Hotcrapi_File */
    public $cf;

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
    function run(Hotcrapi_Batch $clib) {
        $args = [];
        if (isset($this->p)) {
            $args["p"] = $this->p;
        }
        if ($this->dry_run) {
            $args["dry_run"] = 1;
        }
        if ($clib->quiet) {
            $args["quiet"] = 1;
        } else if ($this->summary) {
            $args["summary"] = 1;
        }

        $curlh = $clib->make_curl("POST");
        $upb = (new Upload_CLIBatch($this->cf))
            ->set_temporary(true)
            ->set_try_mimetypes(Mimetype::JSON_UTF8_TYPE, Mimetype::CSV_UTF8_TYPE)
            ->set_require_mimetype(true);
        if (($token = $upb->attach_or_execute($curlh, $clib))) {
            $args["upload"] = $token;
        } else if ($clib->has_error()) {
            return 1;
        }
        curl_setopt($curlh, CURLOPT_URL, $this->url_with($clib, $args));
        $ok = $clib->exec_api($curlh, null);

        $ml = $clib->message_list();
        $clib->clear_messages();

        $cj = $clib->content_json;
        $dry_run = $cj->dry_run ?? false;

        if ($this->json) {
            $clib->set_json_output($cj);
        } else if (!is_bool($cj->valid ?? false)) {
            $clib->error_at(null, "<0>Invalid server response");
            $ok = false;
        } else if (!$cj->valid) {
            $clib->error_at(null, "<0>Assignment has errors" . ($dry_run ? "" : ", changes not saved"));
            $ok = false;
        }
        $clib->append_list($ml);

        if ($clib->verbose) {
            fwrite(STDERR, $clib->content_string);
        }
        if (!$ok) {
            return 1;
        }

        $no_assignments = ($cj->assignments ?? null) === []
            || ($cj->assignment_pids ?? null) === [];
        if ($no_assignments) {
            $clib->success("<0>No changes");
        } else if (isset($cj->assignments)) {
            $csv = (new CsvGenerator)->select($cj->assignments_header);
            foreach ($cj->assignments as $aj) {
                $csv->add_row((array) $aj);
            }
            $clib->set_output($csv->unparse());
        } else {
            $clib->success(($cj->dry_run ?? false) ? "<0>Assignment valid" : "<0>Saved changes");
            if (isset($cj->assignment_actions)) {
                $clib->append_item(MessageItem::inform("<0>Action: " . join(" ", $cj->assignment_actions)));
                $clib->append_item(MessageItem::inform("<0>Paper: " . join(" ", $cj->assignment_pids)));
            }
        }
        return 0;
    }

    /** @return Assign_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, Getopt $getopt, $arg) {
        $pcb = new Assign_CLIBatch;
        if (isset($arg["p"])) {
            $pcb->p = $arg["p"];
        }
        $pcb->dry_run = isset($arg["dry-run"]);
        $pcb->summary = isset($arg["summary"]);
        $pcb->json = isset($arg["json"]);

        $argv = $arg["_"];
        $argc = count($argv);
        $argi = 0;

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

        if ($argi < $argc) {
            throw new CommandLineException("Too many arguments");
        }

        return $pcb;
    }

    static function register(Hotcrapi_Batch $clib, Getopt $getopt) {
        $getopt->subcommand_description(
            "assign",
            "Perform HotCRP assignments
Usage: php batch/hotcrapi.php assign [-p PID] [-d] [JSONFILE | CSVFILE]"
        )->long(
            "p:,paper: {n} =PID !assign Restrict assignments to PID",
            "dry-run,d !assign Don’t actually save changes",
            "summary !assign Request an assignment summary",
            "json,j !assign Output JSON response"
        );
        $clib->register_command("assign", "Assign_CLIBatch");
    }
}
