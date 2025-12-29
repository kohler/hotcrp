<?php
// api_assign.php -- HotCRP assignment API calls
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Assign_API {
    static function assign(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        $aset = new AssignmentSet($user);
        if (friendly_boolean($qreq->forceShow) !== false) {
            $aset->set_override_conflicts(true);
        }
        if ($prow) {
            $aset->enable_papers($prow);
        }

        // obtain assignments
        $ct = $qreq->body_content_type();
        $ct_form = Mimetype::is_form($ct);

        // check for uploaded file
        if ($ct_form && isset($qreq->upload)) {
            $updoc = DocumentInfo::make_capability($user->conf, $qreq->upload);
            if (!$updoc) {
                return JsonResult::make_missing_error("upload", "<0>Upload not found");
            }
            $ct = $updoc->mimetype;
            $ct_form = false;
        } else {
            $updoc = null;
        }

        // parse CSV upload or get JSON string
        $csvp = $jsonstr = $jsonfn = null;
        $errparam = null;
        if ($ct === "application/json") {
            if ($updoc) {
                $jsonstr = $updoc->content();
                $jsonfn = $updoc->filename;
            } else {
                $jsonstr = $qreq->body();
            }
        } else if ($ct === "text/csv" || $ct === "text/plain") {
            $fn = $updoc ? $updoc->content_file() : $qreq->body_file();
            $csvp = new CsvParser;
            $csvp->set_content(fopen($fn, "rb"));
            $aset->set_csv_context(true);
        } else if (isset($qreq->assignments)) {
            if (preg_match('/\A\s*[\[\{]/s', $qreq->assignments)) {
                $jsonstr = $qreq->assignments;
            } else {
                $csvp = new CsvParser;
                $csvp->set_content($qreq->assignments);
                $aset->set_csv_context(true);
            }
            $errparam = "assignments";
        }

        // parse JSON string
        if ($csvp === null) {
            if ($jsonstr === null || $jsonstr === false) {
                return JsonResult::make_missing_error("assignments");
            }
            $j = json_decode($jsonstr);
            if (!$j) {
                $jparser = (new JsonParser($jsonstr))->set_filename($jsonfn);
                if (friendly_boolean($qreq->json5)) {
                    $jparser->set_flags(JsonParser::JSON5);
                }
                $j = $jparser->decode();
                if ($jparser->last_error()) {
                    return JsonResult::make_message_list(MessageItem::error_at($errparam, "<0>Invalid JSON: " . $jparser->last_error_msg()));
                }
            }
            if (is_object($j)) {
                $j = [$j];
            } else if (!is_array($j)) {
                return JsonResult::make_message_list(MessageItem::error_at($errparam, "<0>Expected JSON array"));
            }
            $csvp = CsvParser::make_json($j);
        }

        // parse assignments
        $aset->parse($csvp);

        // perform them unless dry run
        $jr = self::complete($aset, $qreq);
        if (!$jr->get("dry_run")
            && !$aset->has_error()
            && $qreq->search) {
            Search_API::apply_search($jr, $user, $qreq, $qreq->search);
            // include tag information
            if (($ps = self::assigned_paper_info($user, $aset))) {
                $jr->set("papers", $ps);
            }
        }
        return $jr;
    }

    /** @return JsonResult */
    static function complete(AssignmentSet $aset, Qrequest $qreq) {
        $dry_run = friendly_boolean($qreq->dry_run);
        if (!$dry_run) {
            $aset->execute();
        }

        $jr = $aset->json_result();
        if ($dry_run) {
            $jr->set("dry_run", true);
        }
        $jr->set("valid", !$aset->has_error());
        if (!$aset->has_error()) {
            $jr->set("assignment_count", $aset->assignment_count());
        }

        if (!isset($qreq->format)) { // XXX backwards compat
            if (friendly_boolean($qreq->quiet)) {
                $qreq->format = "none";
            } else if (friendly_boolean($qreq->summary)) {
                $qreq->format = "summary";
            } else {
                $qreq->format = friendly_boolean($qreq->csv) ? "csv" : "json";
            }
        }

        $format = ViewOptionType::parse_enum($qreq->format ?? "none", "none|summary|csv|json");
        if ($format === null) {
            $jr->append_item(MessageItem::error_at("format", "<0>Unknown format"));
            $format = "none";
        }

        if ($format === "none"
            || (!$dry_run && $aset->has_error())) {
            // no additional information
        } else if ($format === "summary") {
            $jr->set("assignment_actions", $aset->assigned_types());
            $jr->set("assignment_pids", $aset->assigned_pids());
        } else if ($format === "csv") {
            $t = $aset->make_acsv()->unparse();
            $jr->set("output", $t);
            $jr->set("output_mimetype", "text/csv");
            $jr->set("output_size", strlen($t));
        } else {
            $acsv = $aset->make_acsv();
            $jr->set("assignment_header", $acsv->header());
            $jr->set("assignments", $acsv->rows());
        }
        return $jr;
    }

    static function assigned_paper_info(Contact $user, AssignmentSet $assigner) {
        $pids = [];
        foreach ($assigner->assignments() as $ai) {
            if ($ai instanceof Tag_Assigner) {
                $pids[$ai->pid] = ($pids[$ai->pid] ?? 0) | 1;
            } else if ($ai instanceof Decision_Assigner
                       || $ai instanceof Status_Assigner) {
                $pids[$ai->pid] = ($pids[$ai->pid] ?? 0) | 2;
            }
        }
        $p = [];
        foreach ($user->paper_set(["paperId" => array_keys($pids)]) as $pr) {
            $p[] = $tmr = new TagMessageReport;
            $tmr->pid = $pr->paperId;
            $pr->add_tag_info_json($tmr, $user);
            if (($pids[$pr->paperId] & 2) !== 0) {
                list($class, $name) = $pr->status_class_and_name($user);
                if ($class !== "ps-submitted") {
                    $tmr->status_html = "<span class=\"pstat {$class}\">" . htmlspecialchars($name) . "</span>";
                } else {
                    $tmr->status_html = "";
                }
            }
        }
        return $p;
    }

    static function assigners(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;
        $exs = [];
        $xtp = (new XtParams($conf, $user))->set_warn_deprecated(false);
        $amap = $conf->assignment_parser_map();

        foreach ($amap as $name => $list) {
            if (!($j = Completion_API::resolve_published($xtp, $name, $list, FieldRender::CFAPI))) {
                continue;
            }
            $rj = isset($j->alias) ? $xtp->search_name($amap, $name) : $j;
            $aj = ["name" => $name];
            if (isset($rj->group) && $rj->group !== $name) {
                $aj["group"] = $rj->group;
            }
            if (isset($rj->description_html)) {
                $aj["description"] = "<5>" . $rj->description_html;
            } else if (isset($rj->description)) {
                $aj["description"] = Ftext::ensure($rj->description, 0);
            }
            $vos = new ViewOptionSchema(...($rj->parameters ?? []));
            foreach ($vos as $vot) {
                if (!isset($vot->alias))
                    $aj["parameters"][] = $vot->unparse_export();
            }
            $exs[] = $aj;
        }
        return [
            "ok" => true,
            "assigners" => $exs
        ];
    }
}
