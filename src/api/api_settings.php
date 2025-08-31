<?php
// api_settings.php -- HotCRP settings API
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Settings_API {
    /** @param ?string $s
     * @return ?SearchExpr */
    static private function parse_filter($s) {
        if ($s === null || trim($s) === "") {
            return null;
        }
        $sp = new SearchParser($s);
        return $sp->parse_expression(SearchOperatorSet::simple_operators());
    }

    static function run(Contact $user, Qrequest $qreq) {
        $content = ["ok" => true];
        $reset = !!friendly_boolean($qreq->reset);
        $filter = self::parse_filter($qreq->filter);
        $exclude = self::parse_filter($qreq->exclude);
        if ($qreq->valid_post()) {
            if ($qreq->body_content_type() === Mimetype::JSON_TYPE) {
                $jtext = $qreq->body();
            } else if (($qf = $qreq->file("settings"))) {
                $jtext = $qf->content();
                if (!isset($qreq->filename)) {
                    $qreq->filename = $qf->name;
                }
            } else if (isset($qreq->settings)) {
                $jtext = $qreq->settings;
            } else {
                return JsonResult::make_missing_error("settings");
            }
            $sv = (new SettingValues($user))->set_use_req(true);
            if (!$sv->viewable_by_user()) {
                return JsonResult::make_permission_error();
            }
            $sv->set_si_filter($filter)->set_si_exclude($exclude);
            $sv->add_json_string($jtext, $qreq->filename);
            $sv->set_req("reset", $reset ? "1" : "");
            $sv->parse();
            $dry_run = friendly_boolean($qreq->dry_run ?? $qreq->dryrun /* XXX */);
            if ($dry_run) {
                $content["dry_run"] = true;
            } else {
                $sv->execute();
            }
            $content["ok"] = !$sv->has_error();
            $content["message_list"] = $sv->message_list();
            $content["valid"] = !$sv->has_error();
            $cl = [];
            foreach ($sv->changed_top_si() as $si) {
                $cl[] = $si->name;
            }
            $content["change_list"] = $cl;
            if ($sv->has_error()) {
                return new JsonResult($content);
            } else if ($dry_run) {
                $content["settings"] = $sv->all_jsonv(["new" => true]);
                return new JsonResult($content);
            }
        }
        $sv = new SettingValues($user);
        if (!$sv->viewable_by_user()) {
            return JsonResult::make_permission_error();
        }
        $sv->set_si_filter($filter)->set_si_exclude($exclude);
        $content["settings"] = $sv->all_jsonv(["reset" => $reset]);
        return new JsonResult($content);
    }

    /** @param SettingValues $sv
     * @param Si $si
     * @param array &$x */
    static private function export_si($sv, $si, &$x) {
        $x["type"] = $si->type;
        if ($si->subtype) {
            $x["subtype"] = $si->subtype;
        }
        if ($si->summary) {
            $x["summary"] = Ftext::ensure($si->summary, 0);
        }
        if ($si->description) {
            $x["description"] = Ftext::ensure($si->description, 0);
        }
        if (($je = $si->json_examples($sv)) !== null) {
            $x["values"] = $je;
        }
    }

    /** @param SettingInfoSet $si_set
     * @param string $pfx
     * @return list<object> */
    static private function components(SettingValues $sv, $si_set, $pfx) {
        $comp = [];
        foreach ($si_set->member_list($pfx) as $xsi) {
            if ($xsi->json_export()) {
                $x = ["name" => substr($xsi->name2, 1)];
                if (($t = $xsi->member_title($sv))) {
                    $x["title"] = "<0>{$t}";
                }
                self::export_si($sv, $xsi, $x);
                $comp[] = (object) $x;
            }
        }
        return $comp;
    }

    static function descriptions(Contact $user, Qrequest $qreq) {
        $si_set = $user->conf->si_set();
        $sv = new SettingValues($user);
        if (!$sv->viewable_by_user()) {
            return JsonResult::make_permission_error();
        }
        $si_set->ensure_descriptions();
        $m = [];
        foreach ($si_set->top_list() as $si) {
            if ($si->json_export()
                && ($si->has_title() || $si->description)) {
                $o = ["name" => $si->name];
                if (($t = $si->title($sv))) {
                    $o["title"] = "<0>{$t}";
                }
                self::export_si($sv, $si, $o);
                if (($dv = $si->initial_value($sv)) !== null) {
                    $o["default_value"] = $si->base_unparse_jsonv($dv, $sv);
                }
                if ($si->type === "oblist" || $si->type === "object") {
                    $pfx = $si->type === "oblist" ? "{$si->name}/1" : $si->name;
                    $comp = self::components($sv, $si_set, $pfx);
                    if (!empty($comp)) {
                        $o["components"] = $comp;
                    }
                }

                $m[] = $o;
            }
        }
        return new JsonResult(["ok" => true, "setting_descriptions" => $m]);
    }

    /** @param &$m array<string,list<object>>
     * @return callable(mixed):bool */
    static function make_field_library_collector(&$m) {
        $mn = 0;
        return function ($j) use (&$m, &$mn) {
            if (!is_string($j->legend ?? null)) {
                return false;
            }
            $name = $j->legend;
            ++$mn;
            if ($j->unique ?? false) {
                $name .= "\${$mn}";
            }
            $m[$name][] = $j;
            return true;
        };
    }

    /** @param XtParams $xtp
     * @param list<string> $defaults
     * @param ?string $optname
     * @return list<object> */
    static function make_field_library($xtp, $defaults, $optname) {
        $m = [];
        $f1 = self::make_field_library_collector($m);
        $f2 = function ($entry, $landmark) use ($f1, $xtp) {
            if (strpos($entry, "::") === false) {
                return false;
            }
            return call_user_func($entry, $xtp);
        };
        expand_json_includes_callback($defaults, $f1);
        if (($olist = $xtp->conf->opt($optname))) {
            expand_json_includes_callback($olist, $f1, $f2);
        }

        $l = [];
        foreach ($m as $name => $list) {
            if (($j = $xtp->search_list($list)))
                $l[] = $j;
        }

        usort($l, "Conf::xt_pure_order_compare");
        return $l;
    }

    static function submissionfieldlibrary(Contact $user, Qrequest $qreq) {
        $sv = new SettingValues($user);
        $xtp = new XtParams($user->conf, $user);
        $xtp->qreq = $qreq;
        $otmap = $user->conf->option_type_map();

        $samples = [];
        $osr = $sv->cs()->callable("Options_SettingParser");
        foreach (self::make_field_library($xtp, ["etc/submissionfieldlibrary.json"], "submissionFieldLibraries") as $samp) {
            if (!($otype = $otmap[$samp->type] ?? null)
                || !$xtp->check($otype->display_if ?? null, $otype)) {
                continue;
            }
            $samples[] = $osr->make_sample_json($sv, $samp);
        }

        return (new JsonResult([
            "ok" => true,
            "samples" => $samples,
            "types" => Options_SettingParser::make_types_json($otmap)
        ]))->set_pretty_print(true);
    }

    static function reviewfieldlibrary(Contact $user, Qrequest $qreq) {
        $xtp = new XtParams($user->conf, $user);
        $xtp->qreq = $qreq;
        return new JsonResult([
            "ok" => true,
            "samples" => self::make_field_library($xtp, ["etc/reviewfieldlibrary.json"], "reviewFieldLibraries"),
            "types" => ReviewForm_SettingParser::make_types_json($user->conf->review_field_type_map())
        ]);
    }
}
