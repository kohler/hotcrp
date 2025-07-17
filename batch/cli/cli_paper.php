<?php
// paper_cli.php -- Hotcrapi script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Paper_CLIBatch implements CLIBatchCommand {
    /** @var ?int */
    public $p;
    /** @var ?string */
    public $q;
    /** @var ?string */
    public $t;
    /** @var string */
    public $urlbase;
    /** @var bool */
    public $save;
    /** @var bool */
    public $delete;
    /** @var bool */
    public $dry_run;
    /** @var bool */
    public $notify = true;
    /** @var bool */
    public $notify_authors = true;
    /** @var bool */
    public $disable_users = false;
    /** @var bool */
    public $add_topics = false;
    /** @var ?string */
    public $reason;
    /** @var Hotcrapi_File */
    public $cf;

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        $args = [];
        if (isset($this->p)) {
            $this->urlbase = "{$clib->site}/paper";
            $args[] = "p={$this->p}";
        } else {
            $this->urlbase = "{$clib->site}/papers";
            if (isset($this->q)) {
                $args[] = "q=" . urlencode($this->q);
                if (isset($this->t)) {
                    $args[] = "t=" . urlencode($this->t);
                }
            }
        }
        if ($this->save || $this->delete) {
            if ($this->dry_run) {
                $args[] = "dry_run=1";
            }
            if (!$this->notify) {
                $args[] = "notify=0";
            }
            if (!$this->notify_authors) {
                $args[] = "notify_authors=0";
            }
            if ($this->disable_users) {
                $args[] = "disable_users=1";
            }
            if ($this->add_topics) {
                $args[] = "add_topics=1";
            }
            if ((string) $this->reason !== "") {
                $args[] = "reason=" . urlencode($this->reason);
            }
        }
        if (!empty($args)) {
            $this->urlbase .= "?" . join("&", $args);
        }
        if ($this->save) {
            return $this->run_save($clib);
        } else if ($this->delete) {
            return $this->run_delete($clib);
        } else {
            return $this->run_get($clib);
        }
    }

    /** @param string $pid
     * @return bool */
    function valid_pid($pid) {
        return ($this->save && $pid === "new") || stoi($pid) !== null;
    }

    /** @return int */
    function run_get(Hotcrapi_Batch $clib) {
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($clib->curlh, CURLOPT_URL, $this->urlbase);
        if (!$clib->exec_api(null)) {
            return 1;
        }
        $k = isset($this->p) ? "paper" : "papers";
        $clib->set_json_output($clib->content_json->$k);
        return 0;
    }

    /** @param array<string,mixed> $args
     * @return string */
    private function url_with($args) {
        $url = $this->urlbase;
        if (($x = http_build_query($args)) !== "") {
            $url .= (strpos($url, "?") === false ? "?" : "&") . $x;
        }
        return $url;
    }

    /** @return int */
    function run_save(Hotcrapi_Batch $clib) {
        if ($this->cf->size === null
            || $this->cf->size > $clib->chunk) {
            $upb = (new Upload_CLIBatch($this->cf))->set_temporary(true);
            $upload = $upb->execute($clib);
            if (!$upload) {
                return 1;
            }
            curl_setopt($clib->curlh, CURLOPT_URL, $this->url_with(["upload" => $upload]));
            curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($clib->curlh, CURLOPT_POSTFIELDS, "");
        } else {
            $s = stream_get_contents($this->cf->stream);
            if ($s === false) {
                throw CommandLineException::make_file_error($this->cf->input_filename);
            }
            $mt = Mimetype::content_type($s);
            if ($mt !== Mimetype::ZIP_TYPE) {
                if (!preg_match('/\A\s*+[\[\{]/s', $s)) {
                    throw new CommandLineException("{$this->cf->input_filename}: Expected ZIP or JSON");
                }
                $mt = Mimetype::JSON_TYPE . "; charset=utf-8";
            }
            curl_setopt($clib->curlh, CURLOPT_URL, $this->urlbase);
            curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($clib->curlh, CURLOPT_POSTFIELDS, $s);
            curl_setopt($clib->curlh, CURLOPT_HTTPHEADER, [
                "Content-Type: {$mt}", "Content-Length: " . strlen($s)
            ]);
        }
        $ok = $clib->exec_api(null);

        $ml = $clib->message_list();
        $clib->clear_messages();

        $cj = $clib->content_json;
        $single = isset($cj->valid);
        if ($single) {
            $slist = [$cj];
        } else if (isset($cj->status_list)) {
            $slist = $cj->status_list;
        } else {
            $clib->error_at(null, "<0>Invalid server response");
            $slist = [];
        }

        foreach ($slist as $idx => $sobj) {
            if (is_int($sobj->pid ?? null)) {
                $pfx = "#{$sobj->pid}: ";
            } else if (!isset($this->p)) {
                $pfx = "<@{$idx}>: ";
            } else {
                $pfx = "";
            }
            if (!$sobj->valid) {
                $clib->error_at(null, "<0>{$pfx}Changes invalid");
            } else if (empty($sobj->change_list)) {
                $clib->success("<0>{$pfx}No changes");
            } else if ($sobj->dry_run ?? false) {
                $clib->success("<0>{$pfx}Would change " . commajoin($sobj->change_list));
            } else {
                $clib->success("<0>{$pfx}Saved changes to " . commajoin($sobj->change_list));
            }
            while (!empty($ml) && ($ml[0]->landmark === null || $ml[0]->landmark === $idx)) {
                $ml[0]->field = "  " . ($ml[0]->field ?? "");
                $ml[0]->landmark = null;
                $clib->append_item(array_shift($ml));
            }
        }

        $clib->append_list($ml);
        if ($clib->verbose) {
            fwrite(STDERR, $clib->content_string);
        }
        if (isset($clib->content_json->paper)) {
            $clib->set_json_output($clib->content_json->paper);
        } else if (isset($clib->content_json->papers)) {
            $clib->set_json_output($clib->content_json->papers);
        }
        return $ok ? 0 : 1;
    }

    /** @return int */
    function run_delete(Hotcrapi_Batch $clib) {
        curl_setopt($clib->curlh, CURLOPT_URL, $this->urlbase);
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "DELETE");
        $ok = $clib->exec_api(null);
        if (isset($clib->content_json->valid)) {
            if (!$clib->content_json->valid) {
                $clib->error_at(null, "<0>Delete invalid");
            } else if ($clib->content_json->dry_run ?? false) {
                $clib->error_at(null, "<0>Would delete #{$this->p}");
            } else {
                $clib->success("<0>Deleted #{$this->p}");
            }
        }
        if ($clib->verbose) {
            fwrite(STDERR, $clib->content_string);
        }
        return $ok ? 0 : 1;
    }

    /** @return Paper_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, Getopt $getopt, $arg) {
        $pcb = new Paper_CLIBatch;
        $argv = $arg["_"];
        $argc = count($argv);

        $mode = "fetch";
        $argi = 0;
        if ($argi < $argc
            && in_array($argv[$argi], ["fetch", "save", "test", "delete"], true)) {
            $mode = $argv[$argi];
            ++$argi;
        }
        $pcb->delete = $mode === "delete";
        $pcb->save = $mode === "save" || $mode === "test";

        if ($argi < $argc && $pcb->valid_pid($argv[$argi])) {
            if (isset($arg["p"])) {
                throw new CommandLineException("`-p` specified twice", $getopt);
            }
            $arg["p"] = $argv[$argi];
            ++$argi;
        }
        if (isset($arg["q"]) && isset($arg["p"])) {
            throw new CommandLineException("`-q` conflicts with `-p`", $getopt);
        } else if (isset($arg["p"])) {
            if (!$pcb->valid_pid($arg["p"])) {
                throw new CommandLineException("Invalid `-p PID`", $getopt);
            }
            $pcb->p = stoi($arg["p"]);
        } else if ($pcb->delete) {
            throw new CommandLineException("Missing `-p PID`", $getopt);
        } else if (isset($arg["q"])) {
            $pcb->q = $arg["q"];
            $pcb->t = $arg["t"] ?? null;
        } else if (!$pcb->save) {
            throw new CommandLineException("Missing `-p PID` or `-q SEARCH`", $getopt);
        }

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

        $pcb->dry_run = isset($arg["dry-run"]) || $mode === "test";
        if (isset($arg["no-notify"])) {
            $pcb->notify = false;
        }
        if (isset($arg["no-notify-authors"])) {
            $pcb->notify_authors = false;
        }
        $pcb->disable_users = isset($arg["disable-users"]);
        $pcb->add_topics = isset($arg["add-topics"]);
        $pcb->reason = $arg["reason"] ?? null;
        return $pcb;
    }

    static function register(Hotcrapi_Batch $clib, Getopt $getopt) {
        $getopt->subcommand_description(
            "paper",
            "Retrieve or change HotCRP submissions
Usage: php batch/hotcrapi.php paper [PID | -q SEARCH]
       php batch/hotcrapi.php paper save [JSONFILE | ZIPFILE]
       php batch/hotcrapi.php paper delete PID"
        )->long(
            "p:,paper: =PID !paper Submission ID",
            "q:,query: =SEARCH !paper Submission search",
            "t:,type: =TYPE !paper Collection to search [viewable]",
            "dry-run,d !paper Don’t actually save changes",
            "disable-users !paper Disable newly created users",
            "add-topics !paper Add all referenced topics to conference",
            "reason: !paper Reason for update (included in notifications)",
            "no-notify Don’t notify users",
            "no-notify-authors Don’t notify authors"
        );
        $clib->register_command("paper", "Paper_CLIBatch");
    }
}
