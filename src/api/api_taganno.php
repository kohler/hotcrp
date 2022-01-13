<?php
// api_taganno.php -- HotCRP tag annotation API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class TagAnno_API {
    static function get(Contact $user, Qrequest $qreq) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE))) {
            return MessageItem::make_error_json($tagger->error_html());
        }
        $j = [
            "ok" => true,
            "tag" => $tag,
            "editable" => $user->can_edit_tag_anno($tag),
            "anno" => []
        ];
        $dt = $user->conf->tags()->add(Tagger::base($tag));
        foreach ($dt->order_anno_list() as $oa) {
            if ($oa->annoId !== null)
                $j["anno"][] = $oa;
        }
        return $j;
    }

    static function set(Contact $user, Qrequest $qreq) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE))) {
            return MessageItem::make_error_json($tagger->error_html());
        }
        if (!$user->can_edit_tag_anno($tag)) {
            return ["ok" => false, "error" => "Permission error"];
        }
        $reqanno = json_decode($qreq->anno ?? "");
        if (!is_object($reqanno) && !is_array($reqanno)) {
            return ["ok" => false, "error" => "Bad request"];
        }
        $q = $qv = $ml = $inserts = [];
        $next_annoid = $user->conf->fetch_value("select greatest(coalesce(max(annoId),0),0)+1 from PaperTagAnno where tag=?", $tag);
        // parse updates
        foreach (is_object($reqanno) ? [$reqanno] : $reqanno as $anno) {
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
            if (is_int($anno->annoid)) {
                $annoid = $anno->annoid;
            } else {
                $annoid = $next_annoid;
                ++$next_annoid;
                $q[] = "insert into PaperTagAnno (tag,annoId) values (?,?)";
                array_push($qv, $tag, $annoid);
            }
            if (isset($anno->heading) || isset($anno->legend)) {
                $q[] = "update PaperTagAnno set heading=?, annoFormat=null where tag=? and annoId=?";
                array_push($qv, $anno->legend ?? $anno->heading ?? null, $tag, $annoid);
            }
            if (isset($anno->tagval)) {
                $tagval = trim($anno->tagval);
                if ($tagval === "") {
                    $tagval = "0";
                }
                if (is_numeric($tagval)) {
                    $q[] = "update PaperTagAnno set tagIndex=? where tag=? and annoId=?";
                    array_push($qv, floatval($tagval), $tag, $annoid);
                } else {
                    $ml[] = new MessageItem("tagval_{$anno->annoid}", "Tag value should be a number", 2);
                }
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
        }
        // return results
        return self::get($user, $qreq);
    }
}
