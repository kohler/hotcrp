<?php
// api_taganno.php -- HotCRP tag annotation API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class TagAnno_API {
    static function get(Contact $user, Qrequest $qreq) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE))) {
            return JsonResult::make_error(400, $tagger->error_ftext());
        }
        $dt = $user->conf->tags()->ensure(Tagger::tv_tag($tag));
        $anno = [];
        foreach ($dt->order_anno_list() as $oa) {
            if ($oa->annoId !== null)
                $anno[] = $oa;
        }
        $jr = new JsonResult([
            "ok" => true,
            "tag" => $tag,
            "editable" => $user->can_edit_tag_anno($tag),
            "anno" => $anno
        ]);
        if ($qreq->search) {
            Search_API::apply_search($jr, $user, $qreq, $qreq->search);
        }
        return $jr;
    }

    static function set(Contact $user, Qrequest $qreq) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE))) {
            return JsonResult::make_error(400, $tagger->error_ftext());
        }
        if (!$user->can_edit_tag_anno($tag)) {
            return ["ok" => false, "error" => "Permission error"];
        }
        $reqanno = json_decode($qreq->anno ?? "");
        if (!is_object($reqanno) && !is_array($reqanno)) {
            return ["ok" => false, "error" => "Bad request"];
        }

        $dt = $user->conf->tags()->ensure($tag);
        $anno_by_id = [];
        $next_annoid = 1;
        foreach ($dt->order_anno_list() as $anno) {
            $anno_by_id[$anno->annoId] = $anno;
            $next_annoid = max($anno->annoId + 1, $next_annoid);
        }

        $q = $qv = $ml = [];
        // parse updates
        foreach (is_object($reqanno) ? [$reqanno] : $reqanno as $annoindex => $anno) {
            if (!is_object($anno)
                || !isset($anno->annoid)
                || (!is_int($anno->annoid) && !preg_match('/^n/', $anno->annoid))) {
                return ["ok" => false, "error" => "Bad request"];
            }
            if (isset($anno->deleted) && $anno->deleted) {
                if (is_int($anno->annoid)) {
                    $q[] = "delete from PaperTagAnno where tag=? and annoId=?";
                    array_push($qv, $tag, $anno->annoid);
                }
                continue;
            }
            $annokey = $anno->key ?? $annoindex + 1;

            // annotation ID
            if (is_int($anno->annoid)) {
                $annoid = $anno->annoid;
            } else {
                $annoid = $next_annoid;
                ++$next_annoid;
                $q[] = "insert into PaperTagAnno (tag,annoId) values (?,?)";
                array_push($qv, $tag, $annoid);
            }

            // legend, tag value
            $qf = [];
            if (isset($anno->legend)) {
                $qf[] = "heading=?";
                $qv[] = $anno->legend;
            }
            if (isset($anno->tagval)) {
                $tagval = trim($anno->tagval);
                if ($tagval === "") {
                    $tagval = "0";
                }
                if (is_numeric($tagval)) {
                    $qf[] = "tagIndex=?";
                    $qv[] = floatval($tagval);
                } else {
                    $ml[] = new MessageItem("ta/{$annokey}/tagval", "Tag value should be a number", 2);
                }
            }

            // other properties
            $ij = null;
            foreach (["session_title", "time", "location", "session_chair"] as $k) {
                if (!property_exists($anno, $k)) {
                    continue;
                }
                if ($ij === null) {
                    $xanno = $anno_by_id[$annoid] ?? null;
                    if ($xanno && $xanno->infoJson) {
                        $ij = json_decode($xanno->infoJson, true);
                    }
                    $ij = $ij ?? [];
                }
                if ($anno->$k !== null
                    && ($k !== "session_chair" || $anno->$k !== "none")) {
                    $ij[$k] = $anno->$k;
                } else {
                    unset($ij[$k]);
                }
            }
            if ($ij !== null) {
                $qf[] = "infoJson=?";
                $qv[] = empty($ij) ? null : json_encode_db($ij);
            }

            if (!empty($qf)) {
                $q[] = "update PaperTagAnno set " . join(", ", $qf) . " where tag=? and annoId=?";
                array_push($qv, $tag, $annoid);
            }
        }
        // return error if any
        if (!empty($ml)) {
            return ["ok" => false, "message_list" => $ml];
        }
        // apply changes
        if (!empty($q)) {
            $mresult = Dbl::multi_qe_apply($user->conf->dblink, join(";", $q), $qv);
            $mresult->free_all();
            // ensure new annotations are returned
            $dt->invalidate_order_anno();
        }
        // return results
        return self::get($user, $qreq);
    }
}
