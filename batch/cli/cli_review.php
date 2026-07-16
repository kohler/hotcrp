<?php
// cli_review.php -- Hotcrapi script for interacting with site review APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Review_CLIBatch implements CLIBatchCommand {
    /** @var ?int */
    public $p;
    /** @var ?string */
    public $r;
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
        // `/review` addresses one review: a delete, the modification of one
        // review on a submission, or the fetch of a specific review. Listings
        // and cross-submission batches use `/reviews`, which accepts a lone
        // object as a one-element batch.
        if ($this->delete) {
            $single = true;
        } else if ($this->save) {
            $single = isset($this->p);
        } else {
            $single = isset($this->r);
        }

        $this->urlbase = $single ? "{$clib->site}/review" : "{$clib->site}/reviews";
        $args = [];
        if (isset($this->p)) {
            $args[] = "p={$this->p}";
        } else if (isset($this->q)) {
            $args[] = "q=" . urlencode($this->q);
            if (isset($this->t)) {
                $args[] = "t=" . urlencode($this->t);
            }
        }
        if (isset($this->r)) {
            $args[] = "r=" . urlencode($this->r);
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
        $k = isset($this->r) ? "review" : "reviews";
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
        // a review upload is either a JSON review object/array or a plain-text
        // offline review form; there are no attachments
        $upb = (new Upload_CLIBatch($this->cf))
            ->set_temporary(true)
            ->set_try_mimetypes(Mimetype::JSON_UTF8_TYPE, Mimetype::TXT_TYPE)
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
        $curlh = $clib->make_curl("DELETE");
        curl_setopt($curlh, CURLOPT_URL, $this->urlbase);
        $ok = $clib->exec_api($curlh, null);
        $this->report_save($clib);
        return $ok ? 0 : 1;
    }

    /** Report a single or batch save response: one summary line per review,
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
            if (isset($sobj->rid) && is_string($sobj->rid)) {
                $pfx = "#{$sobj->rid}: ";
            } else if (is_int($sobj->pid ?? null)) {
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
        if (isset($clib->content_json->review)) {
            $clib->set_output_json($clib->content_json->review);
        } else if (isset($clib->content_json->reviews)) {
            $clib->set_output_json($clib->content_json->reviews);
        }
    }

    /** @return Review_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, $arg) {
        $rcb = new Review_CLIBatch;
        $argv = $arg["_"];
        $argc = count($argv);

        $mode = "fetch";
        $argi = 0;
        if ($argi < $argc
            && in_array($argv[$argi], ["fetch", "save", "test", "delete"], true)) {
            $mode = $argv[$argi];
            ++$argi;
        }
        $rcb->delete = $mode === "delete";
        $rcb->save = $mode === "save" || $mode === "test";

        if ($argi < $argc && $rcb->valid_pid($argv[$argi])) {
            if (isset($arg["p"])) {
                throw new CommandLineException("`-p` specified twice", $clib->getopt);
            }
            $arg["p"] = $argv[$argi];
            ++$argi;
        }
        if (isset($arg["q"]) && isset($arg["p"])) {
            throw new CommandLineException("`-q` conflicts with `-p`", $clib->getopt);
        } else if (isset($arg["p"])) {
            if (!$rcb->valid_pid($arg["p"])) {
                throw new CommandLineException("Invalid `-p PID`", $clib->getopt);
            }
            $rcb->p = stoi($arg["p"]);
        } else if (isset($arg["q"])) {
            $rcb->q = $arg["q"];
            $rcb->t = $arg["t"] ?? null;
        }
        if (isset($arg["r"])) {
            if (!isset($rcb->p)) {
                throw new CommandLineException("`-r` requires `-p PID`", $clib->getopt);
            }
            $rcb->r = $arg["r"];
        }

        if ($rcb->delete) {
            if (!isset($rcb->p) || !isset($rcb->r)) {
                throw new CommandLineException("`review delete` requires `-p PID` and `-r REVIEW`", $clib->getopt);
            }
        } else if (!$rcb->save && !isset($rcb->p) && !isset($rcb->q)) {
            throw new CommandLineException("Missing `-p PID` or `-q SEARCH`", $clib->getopt);
        }

        if ($rcb->save) {
            if ($argi < $argc
                && preg_match('/\A[\[\{]/', $argv[$argi])
                && json_validate($argv[$argi])) {
                $rcb->cf = Hotcrapi_File::make_data($argv[$argi]);
                ++$argi;
            } else if ($argi < $argc) {
                $rcb->cf = Hotcrapi_File::make($argv[$argi]);
                ++$argi;
            } else {
                $rcb->cf = Hotcrapi_File::make("-");
            }
        }

        if ($argi < $argc) {
            throw new CommandLineException("Too many arguments");
        }

        $rcb->dry_run = isset($arg["dry-run"]) || $mode === "test";
        if (isset($arg["no-notify"])) {
            $rcb->notify = false;
        }
        return $rcb;
    }

    static function register(Hotcrapi_Batch $clib) {
        $clib->getopt->subcommand_description(
            "review",
            "Retrieve or change HotCRP reviews
Usage: php batch/hotcrapi.php review [PID [-r REVIEW] | -q SEARCH]
       php batch/hotcrapi.php review save [PID [-r REVIEW]] [JSONFILE | TEXTFILE]
       php batch/hotcrapi.php review delete PID -r REVIEW"
        )->long(
            "p:,paper: =PID !review Submission ID",
            "r:,review: =REVIEW !review Review selector (ID, ordinal, or `new`)",
            "q:,query: =SEARCH !review Review search",
            "t:,type: =SCOPE !review Scope of search [viewable]",
            "dry-run,d !review Don’t actually save changes"
        );
        $clib->register_command("review", "Review_CLIBatch");
    }
}
