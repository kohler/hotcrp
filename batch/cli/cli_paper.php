<?php
// paper_cli.php -- HotCRP script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

// hotcli paper 10
// hotcli paper -q XXXX
// hotcli paper -e -q XXXX
// hotcli paper -e 10 FILE

class Paper_CLIBatch implements CLIBatchCommand {
    /** @var ?int */
    public $p;
    /** @var ?string */
    public $q;
    /** @var string */
    public $urlbase;
    /** @var bool */
    public $edit;
    /** @var bool */
    public $delete;
    /** @var bool */
    public $dry_run;
    /** @var bool */
    public $notify = true;
    /** @var bool */
    public $notify_authors = true;
    /** @var HotCLI_File */
    public $cf;

    /** @return int */
    function run(HotCLI_Batch $clib) {
        if (isset($this->p)) {
            $this->urlbase = "{$clib->site}/paper?p={$this->p}";
        } else {
            $this->urlbase = "{$clib->site}/papers?q=" . urlencode($this->q);
        }
        if (!$this->edit && !$this->delete) {
            return $this->run_get($clib);
        }
        if ($this->dry_run) {
            $this->urlbase .= "&dry_run=1";
        }
        if (!$this->notify) {
            $this->urlbase .= "&notify=0";
        }
        if (!$this->notify_authors) {
            $this->urlbase .= "&notify_authors=0";
        }
        if ($this->edit) {
            return $this->run_edit($clib);
        } else {
            return $this->run_delete($clib);
        }
    }

    /** @return int */
    function run_get(HotCLI_Batch $clib) {
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($clib->curlh, CURLOPT_URL, $this->urlbase);
        if (!$clib->exec_api(null)) {
            return 1;
        }
        $k = isset($this->p) ? "paper" : "papers";
        $clib->set_json_output($clib->content_json->$k);
        return 0;
    }

    /** @return int */
    function run_edit(HotCLI_Batch $clib) {
        if ($this->cf->size === null
            || $this->cf->size > $clib->chunk) {
            $upb = new Upload_CLIBatch($this->cf);
            $upload = $upb->execute($clib);
            if (!$upload) {
                return 1;
            }
            curl_setopt($clib->curlh, CURLOPT_URL, "{$this->urlbase}&upload=" . urlencode($upload));
            curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($clib->curlh, CURLOPT_POSTFIELDS, "");
        } else {
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
            curl_setopt($clib->curlh, CURLOPT_URL, $this->urlbase);
            curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($clib->curlh, CURLOPT_POSTFIELDS, $s);
            curl_setopt($clib->curlh, CURLOPT_HTTPHEADER, [
                "Content-Type: {$mt}", "Content-Length: " . strlen($s)
            ]);
        }
        $ok = $clib->exec_api(null);
        if ($this->p && isset($clib->content_json->valid)) {
            if (!$clib->content_json->valid) {
                $clib->error_at(null, "<0>Changes invalid");
            } else if (empty($clib->content_json->change_list)) {
                $clib->success("<0>No changes");
            } else if ($clib->content_json->dry_run ?? false) {
                $clib->success("<0>Would change " . commajoin($clib->content_json->change_list));
            } else {
                $clib->success("<0>Saved changes to " . commajoin($clib->content_json->change_list));
            }
        }
        if ($clib->verbose) {
            fwrite(STDERR, $clib->content_string);
        }
        if ($this->p && isset($clib->content_json->paper)) {
            $clib->set_json_output($clib->content_json->paper);
        }
        return $ok ? 0 : 1;
    }

    /** @return int */
    function run_delete(HotCLI_Batch $clib) {
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
    static function make_arg(HotCLI_Batch $clib, Getopt $getopt, $arg) {
        $pcb = new Paper_CLIBatch;
        $narg = 0;

        $parg = null;
        if (isset($arg["q"])) {
            $pcb->q = $arg["q"];
        } else if (isset($arg["p"])) {
            $parg = $arg["p"];
        } else if (count($arg["_"]) > $narg) {
            $parg = $arg["_"][$narg];
            ++$narg;
        } else {
            throw new CommandLineException("Submission ID missing", $getopt);
        }
        if ($parg !== null) {
            if (ctype_digit($parg)) {
                $pcb->p = intval($parg);
            } else {
                throw new CommandLineException("Invalid paper", $getopt);
            }
        }

        $pcb->delete = isset($arg["delete"]);
        $pcb->edit = isset($arg["e"]);
        if ($pcb->edit) {
            $pcb->cf = HotCLI_File::make($arg["_"][$narg] ?? "-");
            ++$narg;
        }
        $pcb->dry_run = isset($arg["dry-run"]);
        if (isset($arg["no-notify"])) {
            $pcb->notify = false;
        }
        if (isset($arg["no-notify-authors"])) {
            $pcb->notify_authors = false;
        }

        if ($pcb->delete && $pcb->edit) {
            throw new CommandLineException("`--delete` conflicts with `--edit`");
        }
        if ($pcb->delete && !$pcb->p) {
            throw new CommandLineException("`--delete` requires `-p`");
        }
        if (count($arg["_"]) > $narg) {
            throw new CommandLineException("Too many arguments");
        }

        return $pcb;
    }
}
