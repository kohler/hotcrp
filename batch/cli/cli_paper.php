<?php
// cli_paper.php -- Hotcrapi script for interacting with site APIs
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
        $curlh = $clib->make_curl("GET");
        curl_setopt($curlh, CURLOPT_URL, $this->urlbase);
        if (!$clib->exec_api($curlh, null)) {
            return 1;
        }
        $k = isset($this->p) ? "paper" : "papers";
        $clib->set_output_json($clib->content_json->$k);
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
        $args = [];
        $curlh = $clib->make_curl("POST");
        $upb = (new Upload_CLIBatch($this->cf))
            ->set_temporary(true)
            ->set_try_mimetypes(Mimetype::ZIP_TYPE, Mimetype::JSON_UTF8_TYPE)
            ->set_require_mimetype(true);
        if (($token = $upb->attach_or_execute($curlh, $clib))) {
            $args["upload"] = $token;
        } else if ($clib->has_error()) {
            return 1;
        }
        curl_setopt($curlh, CURLOPT_URL, $this->url_with($args));
        $ok = $clib->exec_api($curlh, null);

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
            $clib->set_output_json($clib->content_json->paper);
        } else if (isset($clib->content_json->papers)) {
            $clib->set_output_json($clib->content_json->papers);
        }
        return $ok ? 0 : 1;
    }

    /** @return int */
    function run_delete(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl();
        curl_setopt($curlh, CURLOPT_URL, $this->urlbase);
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, "DELETE");
        $ok = $clib->exec_api($curlh, null);
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

    static function make_zip_with(Hotcrapi_File $cf, $files) {
        $cfz = Hotcrapi_File::make_zip();
        $cfz->zip_add_file("data.json", $cf);
        foreach ($files as $f) {
            if (($eq = strpos($f, "=")) !== false) {
                $fname = substr($f, 0, $eq);
                $fdest = substr($f, $eq + 1);
            } else if (($slash = strrpos($f, "/")) !== false) {
                $fname = substr($f, $slash + 1);
                $fdest = $f;
            } else {
                $fname = $fdest = $f;
            }
            if (!is_file($fdest) || !is_readable($fdest)) {
                throw new CommandLineException("{$fdest}: Not a regular file");
            }
            if (!Hotcrapi_File::zip_allow($fname)
                || str_ends_with($fname, "data.json")
                || str_starts_with($fname, ".")
                || $cfz->zip_contains($fname)) {
                throw new CommandLineException("{$fname}: Unacceptable upload name");
            }
            $cfz->zip_add_file($fname, $fdest);
        }
        $cfz->zip_complete();
        return $cfz;
    }

    /** @return Paper_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, $arg) {
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
                throw new CommandLineException("`-p` specified twice", $clib->getopt);
            }
            $arg["p"] = $argv[$argi];
            ++$argi;
        }
        if (isset($arg["q"]) && isset($arg["p"])) {
            throw new CommandLineException("`-q` conflicts with `-p`", $clib->getopt);
        } else if (isset($arg["p"])) {
            if (!$pcb->valid_pid($arg["p"])) {
                throw new CommandLineException("Invalid `-p PID`", $clib->getopt);
            }
            $pcb->p = stoi($arg["p"]);
        } else if ($pcb->delete) {
            throw new CommandLineException("Missing `-p PID`", $clib->getopt);
        } else if (isset($arg["q"])) {
            $pcb->q = $arg["q"];
            $pcb->t = $arg["t"] ?? null;
        } else if (!$pcb->save) {
            throw new CommandLineException("Missing `-p PID` or `-q SEARCH`", $clib->getopt);
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
            if (!empty($arg["F"])) {
                $pcb->cf = self::make_zip_with($pcb->cf, $arg["F"]);
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

    static function register(Hotcrapi_Batch $clib) {
        $clib->getopt->subcommand_description(
            "paper",
            "Retrieve or change HotCRP submissions
Usage: php batch/hotcrapi.php paper [PID | -q SEARCH]
       php batch/hotcrapi.php paper save [JSONFILE | ZIPFILE] [-F file...]
       php batch/hotcrapi.php paper delete PID"
        )->long(
            "p:,paper: =PID !paper Submission ID",
            "q:,query: =SEARCH !paper Submission search",
            "t:,type: =TYPE !paper Collection to search [viewable]",
            "F[],file[] =FILE !paper Add attachment",
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
