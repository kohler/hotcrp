<?php
// api_taganno.php -- HotCRP tag annotation API calls
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class TagAnno_API {
    static function get(Contact $user, Qrequest $qreq) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE)))
            return ["ok" => false, "error" => $tagger->error_html];
        $j = ["ok" => true, "tag" => $tag, "editable" => $user->can_change_tag_anno($tag),
              "anno" => []];
        $dt = $user->conf->tags()->add(TagInfo::base($tag));
        foreach ($dt->order_anno_list() as $oa)
            if ($oa->annoId !== null)
                $j["anno"][] = $oa;
        return $j;
    }

    static function set(Contact $user, Qrequest $qreq) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE)))
            json_exit(["ok" => false, "error" => $tagger->error_html]);
        if (!$user->can_change_tag_anno($tag))
            json_exit(["ok" => false, "error" => "Permission error."]);
        if (!isset($qreq->anno)
            || ($reqanno = json_decode($qreq->anno)) === false
            || (!is_object($reqanno) && !is_array($reqanno)))
            json_exit(["ok" => false, "error" => "Bad request."]);
        $q = $qv = $errors = $errf = $inserts = [];
        $next_annoid = $user->conf->fetch_value("select greatest(coalesce(max(annoId),0),0)+1 from PaperTagAnno where tag=?", $tag);
        // parse updates
        foreach (is_object($reqanno) ? [$reqanno] : $reqanno as $anno) {
            if (!isset($anno->annoid)
                || (!is_int($anno->annoid) && !preg_match('/^n/', $anno->annoid)))
                json_exit(["ok" => false, "error" => "Bad request."]);
            if (isset($anno->deleted) && $anno->deleted) {
                if (is_int($anno->annoid)) {
                    $q[] = "delete from PaperTagAnno where tag=? and annoId=?";
                    array_push($qv, $tag, $anno->annoid);
                }
                continue;
            }
            if (is_int($anno->annoid))
                $annoid = $anno->annoid;
            else {
                $annoid = $next_annoid;
                ++$next_annoid;
                $q[] = "insert into PaperTagAnno (tag,annoId) values (?,?)";
                array_push($qv, $tag, $annoid);
            }
            if (isset($anno->heading)) {
                $q[] = "update PaperTagAnno set heading=?, annoFormat=null where tag=? and annoId=?";
                array_push($qv, $anno->heading, $tag, $annoid);
            }
            if (isset($anno->tagval)) {
                $tagval = trim($anno->tagval);
                if ($tagval === "")
                    $tagval = "0";
                if (is_numeric($tagval)) {
                    $q[] = "update PaperTagAnno set tagIndex=? where tag=? and annoId=?";
                    array_push($qv, floatval($tagval), $tag, $annoid);
                } else {
                    $errf["tagval_{$anno->annoid}"] = true;
                    $errors[] = "Tag value should be a number.";
                }
            }
        }
        // return error if any
        if (!empty($errors))
            json_exit(["ok" => false, "error" => join("<br />", $errors), "errf" => $errf]);
        // apply changes
        if (!empty($q)) {
            $mresult = Dbl::multi_qe_apply($user->conf->dblink, join(";", $q), $qv);
            $mresult->free_all();
        }
        // return results
        return self::get($user, $qreq);
    }
}
