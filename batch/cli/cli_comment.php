<?php
// cli_comment.php -- Hotcrapi script for interacting with site comment APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Comment_CLIBatch implements CLIBatchCommand {
    /** @var ?int */
    public $p;
    /** @var ?string */
    public $c;
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
    /** @var ?Hotcrapi_File */
    public $cf;

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        // `/comment` addresses one comment: a delete, or the fetch of a specific
        // comment. Saves and listings use the batch `/comments`, which accepts a
        // lone object as a one-element batch; a `p` scopes it to one submission.
        if ($this->delete) {
            $single = true;
        } else if ($this->save) {
            $single = false;
        } else {
            $single = isset($this->c);
        }

        $this->urlbase = $single ? "{$clib->site}/comment" : "{$clib->site}/comments";
        $args = [];
        if (isset($this->p)) {
            $args[] = "p={$this->p}";
        } else if (isset($this->q)) {
            $args[] = "q=" . urlencode($this->q);
            if (isset($this->t)) {
                $args[] = "t=" . urlencode($this->t);
            }
        }
        if (isset($this->c)) {
            $args[] = "c=" . urlencode($this->c);
        }
        if ($this->save || $this->delete) {
            if ($this->dry_run) {
                $args[] = "dry_run=1";
            }
            if (!$this->notify) {
                $args[] = "notify=0";
            }
        }
        if (!empty($args)) {
            $this->urlbase .= "?" . join("&", $args);
        }
        if ($this->save) {
            return $this->run_save($clib);
        } else if ($this->delete) {
            return $this->run_delete($clib);
        }
        return $this->run_get($clib);
    }

    /** @param string $pid
     * @return bool */
    function valid_pid($pid) {
        return stoi($pid) !== null;
    }

    /** @return int */
    function run_get(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl("GET");
        curl_setopt($curlh, CURLOPT_URL, $this->urlbase);
        if (!$clib->exec_api($curlh, null)) {
            return 1;
        }
        $k = isset($this->c) ? "comment" : "comments";
        $clib->set_output_json($clib->content_json->$k ?? null);
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
        $this->report_save($clib);
        return $ok ? 0 : 1;
    }

    /** @return int */
    function run_delete(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl("POST");
        curl_setopt($curlh, CURLOPT_URL, $this->url_with(["delete" => 1]));
        $ok = $clib->exec_api($curlh, null);
        $this->report_save($clib);
        return $ok ? 0 : 1;
    }

    /** Report a single or batch save response: one summary line per comment,
     * with the item's messages indented beneath it. */
    private function report_save(Hotcrapi_Batch $clib) {
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
                $pfx = "#{$sobj->pid}";
                if (is_int($sobj->cid ?? null)) {
                    $pfx .= "c{$sobj->cid}";
                }
                $pfx .= ": ";
            } else if (!isset($this->p)) {
                $pfx = "<@{$idx}>: ";
            } else {
                $pfx = "";
            }
            if (!$sobj->valid) {
                $clib->error_at(null, "<0>{$pfx}Changes invalid");
            } else if (empty($sobj->change_list)) {
                $clib->success("<0>{$pfx}No changes");
            } else if ($cj->dry_run ?? false) {
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
        if (isset($clib->content_json->comment)) {
            $clib->set_output_json($clib->content_json->comment);
        } else if (isset($clib->content_json->comments)) {
            $clib->set_output_json($clib->content_json->comments);
        }
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

    /** @return Comment_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, $arg) {
        $ccb = new Comment_CLIBatch;
        $argv = $arg["_"];
        $argc = count($argv);

        $mode = "fetch";
        $argi = 0;
        if ($argi < $argc
            && in_array($argv[$argi], ["fetch", "save", "test", "delete"], true)) {
            $mode = $argv[$argi];
            ++$argi;
        }
        $ccb->delete = $mode === "delete";
        $ccb->save = $mode === "save" || $mode === "test";

        if ($argi < $argc && $ccb->valid_pid($argv[$argi])) {
            if (isset($arg["p"])) {
                throw new CommandLineException("`-p` specified twice", $clib->getopt);
            }
            $arg["p"] = $argv[$argi];
            ++$argi;
        }
        if (isset($arg["q"]) && isset($arg["p"])) {
            throw new CommandLineException("`-q` conflicts with `-p`", $clib->getopt);
        } else if (isset($arg["p"])) {
            if (!$ccb->valid_pid($arg["p"])) {
                throw new CommandLineException("Invalid `-p PID`", $clib->getopt);
            }
            $ccb->p = stoi($arg["p"]);
        } else if (isset($arg["q"])) {
            $ccb->q = $arg["q"];
            $ccb->t = $arg["t"] ?? null;
        }
        if (isset($arg["c"])) {
            if (!isset($ccb->p)) {
                throw new CommandLineException("`-c` requires `-p PID`", $clib->getopt);
            }
            $ccb->c = $arg["c"];
        }

        if ($ccb->delete) {
            if (!isset($ccb->p) || !isset($ccb->c)) {
                throw new CommandLineException("`comment delete` requires `-p PID` and `-c COMMENT`", $clib->getopt);
            }
        } else if (!$ccb->save && !isset($ccb->p) && !isset($ccb->q)) {
            throw new CommandLineException("Missing `-p PID` or `-q SEARCH`", $clib->getopt);
        }

        if ($ccb->save) {
            if ($argi < $argc
                && preg_match('/\A[\[\{]/', $argv[$argi])
                && json_validate($argv[$argi])) {
                $ccb->cf = Hotcrapi_File::make_data($argv[$argi]);
                ++$argi;
            } else if ($argi < $argc) {
                $ccb->cf = Hotcrapi_File::make($argv[$argi]);
                ++$argi;
            } else {
                $ccb->cf = Hotcrapi_File::make("-");
            }
            if (!empty($arg["F"])) {
                $ccb->cf = self::make_zip_with($ccb->cf, $arg["F"]);
            }
        }

        if ($argi < $argc) {
            throw new CommandLineException("Too many arguments");
        }

        $ccb->dry_run = isset($arg["dry-run"]) || $mode === "test";
        if (isset($arg["no-notify"])) {
            $ccb->notify = false;
        }
        return $ccb;
    }

    static function register(Hotcrapi_Batch $clib) {
        $clib->getopt->subcommand_description(
            "comment",
            "Retrieve or change HotCRP comments
Usage: php batch/hotcrapi.php comment [PID [-c COMMENT] | -q SEARCH]
       php batch/hotcrapi.php comment save [PID] [JSONFILE | ZIPFILE] [-F file...]
       php batch/hotcrapi.php comment delete PID -c COMMENT"
        )->long(
            "p:,paper: =PID !comment Submission ID",
            "c:,comment: =COMMENT !comment Comment selector (ID, `new`, or `response`)",
            "q:,query: =SEARCH !comment Comment search",
            "t:,type: =SCOPE !comment Scope of search [viewable]",
            "F[],file[] =FILE !comment Add attachment",
            "dry-run,d !comment Don’t actually save changes"
        );
        $clib->register_command("comment", "Comment_CLIBatch");
    }
}
