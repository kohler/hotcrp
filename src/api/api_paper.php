<?php
// api_paper.php -- HotCRP paper API call
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class Paper_API extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var bool */
    private $disable_users = false;
    /** @var ?ZipArchive */
    private $ziparchive;
    /** @var ?string */
    private $docdir;

    const PIDFLAG_IGNORE_PID = 1;
    const PIDFLAG_MATCH_TITLE = 2;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    static function run_get(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        $ml = [];
        if ($prow) {
            $pids = [$prow->paperId];
            $prows = PaperInfoSet::make_singleton($prow);
        } else if (isset($qreq->q)) {
            $srch = new PaperSearch($user, ["q" => $qreq->q, "t" => $qreq->t, "sort" => $qreq->sort]);
            $pids = $srch->sorted_paper_ids();
            $prows = $srch->conf->paper_set([
                "paperId" => $pids,
                "options" => true, "topics" => true, "allConflictType" => true
            ]);
            $ml = $srch->message_list();
        } else {
            return JsonResult::make_error(400, "<0>Bad request");
        }

        $pex = new PaperExport($user);
        if ($prow) {
            $result = $pex->paper_json($prow);
        } else {
            $result = [];
            foreach ($pids as $pid) {
                if (($p = $pex->paper_json($prows->get($pid))))
                    $result[] = $p;
            }
        }

        if (!$result) {
            return JsonResult::make_permission_error();
        }

        return ["ok" => true, "message_list" => $ml, "result" => $result];
    }

    /** @return array{string,?string} */
    static function analyze_zip_contents($zip) {
        // find common directory prefix
        $dirpfx = null;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($dirpfx === null) {
                $xslash = (int) strrpos($name, "/");
                $dirpfx = $xslash ? substr($name, 0, $xslash + 1) : "";
            }
            while ($dirpfx !== "" && !str_starts_with($name, $dirpfx)) {
                $xslash = (int) strrpos($dirpfx, "/", -1);
                $dirpfx = $xslash ? substr($dirpfx, 0, $xslash + 1) : "";
            }
            if ($dirpfx === "") {
                break;
            }
        }

        // find JSONs
        $datas = $jsons = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (!str_ends_with($name, ".json")
                || strpos($name, "/", strlen($dirpfx)) !== false
                || $name[strlen($dirpfx)] === ".") {
                continue;
            }
            $jsons[] = $name;
            if (preg_match('/\G(?:|.*[-_])data\.json\z/', $name, $m, 0, strlen($dirpfx))) {
                $datas[] = $name;
            }
        }

        if (count($datas) === 1) {
            return [$dirpfx, $datas[0]];
        } else if (count($jsons) === 1) {
            return [$dirpfx, $jsons[0]];
        } else {
            return [$dirpfx, null];
        }
    }

    private function run_post(Qrequest $qreq, PaperInfo $prow = null) {
        $ct = $qreq->body_content_type();
        if ($ct === "application/json") {
            $jsonstr = $qreq->body();
        } else if ($ct === "application/zip") {
            $this->ziparchive = new ZipArchive;
            $cf = $qreq->body_filename(".zip");
            if (!$cf) {
                return JsonResult::make_error(500, "<0>Cannot read uploaded content");
            }
            $ec = $this->ziparchive->open($cf);
            if ($ec !== true) {
                return JsonResult::make_error(400, "<0>Bad ZIP file (error " . json_encode($ec) . ")");
            }
            list($this->docdir, $jsonname) = self::analyze_zip_contents($this->ziparchive);
            if (!$jsonname) {
                return JsonResult::make_error(400, "<0>ZIP file lacks `data.json`");
            }
            $jsonstr = $this->ziparchive->getFromName($jsonname);
        } else {
            return JsonResult::make_error(400, "<0>POST data must be JSON or ZIP");
        }

        $jp = Json::try_decode($jsonstr);
        if ($jp === null) {
            return JsonResult::make_error(400, "<0>Invalid JSON: " . Json::last_error_msg());
        } else if (!is_array($jp) && !is_object($jp)) {
            return JsonResult::make_error(400, "<0>Expected array of objects");
        }

        if ($this->user->privChair) {
            if ($qreq->disable_users) {
                $this->disable_users = true;
            }
            if ($qreq->add_topics) {
                foreach ($this->conf->options()->form_fields() as $opt) {
                    if ($opt instanceof Topics_PaperOption)
                        $opt->allow_new_topics(true);
                }
            }
        }

        $any_errors = $any_successes = false;
        if (is_object($jp)) {
            $result = $this->run_one_post(0, $jp);
            $any_successes = !!$result;
            $any_errors = !$result;
        } else {
            $result = [];
            foreach ($jp as $index => &$j) {
                if ($any_errors) {
                    $result[] = null;
                } else {
                    $result[] = $ok = $this->run_one_post($index, $j);
                    $any_errors = $any_errors || !$ok;
                    $any_successes = $any_successes || $ok;
                }
                $j = null;
                gc_collect_cycles();
            }
        }

        return ["ok" => !$any_errors, "message_list" => $this->message_list(), "result" => $result];
    }

    /** @param object $j
     * @param 0|1|2|3 $pidflags
     * @return null|int|'new' */
    static function analyze_json_pid(Conf $conf, $j, $pidflags = 0) {
        if (($pidflags & self::PIDFLAG_IGNORE_PID) !== 0) {
            if (isset($j->pid)) {
                $j->__original_pid = $j->pid;
            }
            unset($j->pid, $j->id);
        }
        if (!isset($j->pid)
            && !isset($j->id)
            && ($pidflags & self::PIDFLAG_MATCH_TITLE) !== 0
            && is_string($j->title ?? null)) {
            $pids = Dbl::fetch_first_columns($conf->dblink, "select paperId from Paper where title=?", simplify_whitespace($j->title));
            if (count($pids) === 1) {
                $j->pid = (int) $pids[0];
            }
        }
        $pid = $j->pid ?? $j->id ?? null;
        if (is_int($pid) && $pid > 0) {
            return $pid;
        } else if ($pid === null || $pid === "new") {
            return "new";
        } else {
            return null;
        }
    }

    /** @param object $docj
     * @param string $filename
     * @return bool */
    static function apply_zip_content_file($docj, $filename, ZipArchive $zip,
                                           PaperOption $o, PaperStatus $pstatus) {
        $stat = $zip->statName($filename);
        if (!$stat) {
            $pstatus->error_at_option($o, "{$filename}: File not found");
            return false;
        }
        // use resources to store large files
        if ($stat["size"] > 50000000) {
            if (PHP_VERSION_ID >= 80200) {
                $content = $zip->getStreamIndex($stat["index"]);
            } else {
                $content = $zip->getStream($filename);
            }
        } else {
            $content = $zip->getFromIndex($stat["index"]);
        }
        if ($content === false) {
            $pstatus->error_at_option($o, "{$filename}: File not found");
            return false;
        }
        if (is_string($content)) {
            $docj->content = $content;
            $docj->content_file = null;
        } else {
            $docj->content_file = $content;
        }
        return true;
    }

    function on_document_import($docj, PaperOption $o, PaperStatus $pstatus) {
        if (is_string($docj->content_file ?? null)
            && $this->ziparchive) {
            return self::apply_zip_content_file($docj, $this->docdir . $docj->content_file, $this->ziparchive, $o, $pstatus);
        } else {
            unset($docj->content_file);
        }
    }

    private function run_one_post($index, $j) {
        $pidish = self::analyze_json_pid($this->conf, $j, 0);
        if (!$pidish) {
            $mi = $this->error_at(null, "Bad `pid`");
            $mi->landmark = "index {$index}";
            return false;
        }

        $ps = (new PaperStatus($this->user))
            ->set_disable_users($this->disable_users)
            ->set_any_content_file(true)
            ->on_document_import([$this, "on_document_import"]);
        $pid = $ps->save_paper_json($j);

        foreach ($ps->decorated_message_list() as $mi) {
            $mi->landmark = $pidish === "new" ? "index {$index}" : "#{$pidish}";
            $this->append_item($mi);
        }
        if (!$pid) {
            return false;
        }

        if ($ps->has_change()) {
            $ps->log_save_activity("via API");
        }
        $p = [
            "pid" => $pid,
            "title" => $ps->title,
            "changes" => $ps->changed_keys()
        ];
        if (($ps->save_status() & PaperStatus::SAVE_STATUS_NEW) !== 0) {
            $p["inserted"] = true;
        }
        if (($ps->save_status() & PaperStatus::SAVE_STATUS_SUBMIT) !== 0) {
            $p["status"] = "submitted";
        } else {
            $p["status"] = "draft";
        }
        return $p;
    }

    static function run(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if ($qreq->is_get()) {
            return self::run_get($user, $qreq, $prow);
        } else {
            $papi = new Paper_API($user);
            return $papi->run_post($qreq, $prow);
        }
    }
}
