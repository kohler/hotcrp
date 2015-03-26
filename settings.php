<?php
// settings.php -- HotCRP chair-only conference settings management page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/reviewsetform.php");
if (!$Me->privChair)
    $Me->escape();

if (!isset($_REQUEST["group"])
    && preg_match(',\A/(\w+)\z,i', Navigation::path()))
    $_REQUEST["group"] = substr(Navigation::path(), 1);

$Highlight = $Conf->session("settings_highlight", array());
$Conf->save_session("settings_highlight", null);
$Error = $Warning = $Values = array();
$DateExplanation = "Date examples: “now”, “10 Dec 2006 11:59:59pm PST”, “2014-10-31 00:00 UTC-1100” <a href='http://php.net/manual/en/datetime.formats.php'>(more examples)</a>";
$TagStyles = "red|orange|yellow|green|blue|purple|gray|bold|italic|big|small|dim";

// read setting information
$SettingInfo = json_decode(file_get_contents("$ConfSitePATH/src/settinginfo.json"), true);

function handle_settinginfo($text, $f) {
    global $SettingInfo;
    $j = json_decode($text, true);
    if (is_array($j))
        $SettingInfo = array_replace_recursive($SettingInfo, $j);
    else if (json_last_error() !== JSON_ERROR_NONE)
        trigger_error("settinginfo_include($f) parse error: " . json_last_error_msg());
}

if (($settinginfo_include = @$Opt["settinginfo_include"])) {
    if (!is_array($settinginfo_include))
        $settinginfo_include = array($settinginfo_include);
    foreach ($settinginfo_include as $k => $si) {
        if (preg_match(',\A\s*\{\s*\",s', $si))
            handle_settinginfo($si, $k);
        else
            foreach (expand_includes($ConfSitePATH, $si) as $f)
                if (($x = file_get_contents($f)))
                    handle_settinginfo($x, $f);
    }
}

$SettingInfo = array_to_object_recursive($SettingInfo);

// maybe set $Opt["contactName"] and $Opt["contactEmail"]
Contact::site_contact();

$Group = defval($_REQUEST, "group");
if ($Group === "rev" || $Group === "review")
    $Group = "reviews";
if ($Group === "rfo")
    $Group = "reviewform";
if ($Group === "tracks")
    $Group = "tags";
if ($Group === "acc")
    $Group = "users";
if (array_search($Group, array("info", "users", "msg", "sub", "opt", "reviews", "reviewform", "tags", "dec")) === false) {
    if ($Conf->timeAuthorViewReviews())
        $Group = "dec";
    else if ($Conf->deadlinesAfter("sub_sub") || $Conf->time_review_open())
        $Group = "reviews";
    else
        $Group = "sub";
}
if ($Group == "users")
    require_once("src/contactlist.php");


function setting_info($n, $k = null) {
    global $SettingInfo;
    $x = @$SettingInfo->$n ? : (object) array();
    return $k ? @$x->$k : $x;
}

function setting_disabled($n) {
    global $SettingInfo;
    $x = @$SettingInfo->$n;
    return $x && @$x->disabled;
}

function parseGrace($v) {
    $t = 0;
    $v = trim($v);
    if ($v == "" || strtoupper($v) == "N/A" || strtoupper($v) == "NONE" || $v == "0")
        return -1;
    if (ctype_digit($v))
        return $v * 60;
    if (preg_match('/^\s*([\d]+):([\d.]+)\s*$/', $v, $m))
        return $m[1] * 60 + $m[2];
    if (preg_match('/^\s*([\d.]+)\s*d(ays?)?(?![a-z])/i', $v, $m)) {
        $t += $m[1] * 3600 * 24;
        $v = substr($v, strlen($m[0]));
    }
    if (preg_match('/^\s*([\d.]+)\s*h(rs?|ours?)?(?![a-z])/i', $v, $m)) {
        $t += $m[1] * 3600;
        $v = substr($v, strlen($m[0]));
    }
    if (preg_match('/^\s*([\d.]+)\s*m(in(ute)?s?)?(?![a-z])/i', $v, $m)) {
        $t += $m[1] * 60;
        $v = substr($v, strlen($m[0]));
    }
    if (preg_match('/^\s*([\d.]+)\s*s(ec(ond)?s?)?(?![a-z])/i', $v, $m)) {
        $t += $m[1];
        $v = substr($v, strlen($m[0]));
    }
    if (trim($v) == "")
        return $t;
    else
        return null;
}

function unparseGrace($v) {
    if ($v === null || $v <= 0 || !is_numeric($v))
        return "none";
    if ($v % 3600 == 0)
        return ($v / 3600) . " hr";
    if ($v % 60 == 0)
        return ($v / 60) . " min";
    return sprintf("%d:%02d", intval($v / 60), $v % 60);
}

function expandMailTemplate($name, $default) {
    global $null_mailer;
    if (!isset($null_mailer))
        $null_mailer = new HotCRPMailer(null, null, array("width" => false));
    return $null_mailer->expand_template($name, $default);
}

function unparse_setting_error($info, $text) {
    if (@$info->name)
        return "$info->name: $text";
    else
        return $text;
}

function parse_value($name, $info) {
    global $Conf, $Error, $Highlight, $Now, $Opt;

    if (!isset($_POST[$name])) {
        $xname = str_replace(".", "_", $name);
        if (isset($_POST[$xname]))
            $_POST[$name] = $_POST[$xname];
        else
            return null;
    }

    $v = trim($_POST[$name]);
    if (@$info->temptext && $info->temptext === $v)
        $v = "";
    $opt_value = null;
    if (substr($name, 0, 4) === "opt.")
        $opt_value = @$Opt[substr($name, 4)];

    if ($info->type === "checkbox")
        return $v != "";
    else if ($info->type === "cdate" && $v == "1")
        return 1;
    else if ($info->type === "date" || $info->type === "cdate"
             || $info->type === "ndate") {
        if ($v == "" || !strcasecmp($v, "N/A") || !strcasecmp($v, "same as PC")
            || $v == "0" || ($info->type !== "ndate" && !strcasecmp($v, "none")))
            return -1;
        else if (!strcasecmp($v, "none"))
            return 0;
        else if (($v = $Conf->parse_time($v)) !== false)
            return $v;
        else
            $err = unparse_setting_error($info, "Invalid date.");
    } else if ($info->type === "grace") {
        if (($v = parseGrace($v)) !== null)
            return intval($v);
        else
            $err = unparse_setting_error($info, "Invalid grace period.");
    } else if ($info->type === "int" || $info->type === "zint") {
        if (preg_match("/\\A[-+]?[0-9]+\\z/", $v))
            return intval($v);
        else
            $err = unparse_setting_error($info, "Should be a number.");
    } else if ($info->type === "string") {
        // Avoid storing the default message in the database
        if (substr($name, 0, 9) == "mailbody_") {
            $t = expandMailTemplate(substr($name, 9), true);
            $v = cleannl($v);
            if ($t["body"] == $v)
                return 0;
        }
        return ($v == "" && !$opt_value ? 0 : array(0, $v));
    } else if ($info->type === "simplestring") {
        $v = simplify_whitespace($v);
        return ($v == "" && !$opt_value ? 0 : array(0, $v));
    } else if ($info->type === "emailheader") {
        $v = MimeText::encode_email_header("", $v);
        if ($v !== false)
            return ($v == "" && !$opt_value ? 0 : array(0, MimeText::decode_header($v)));
        else
            $err = unparse_setting_error($info, "Invalid email header.");
    } else if ($info->type === "emailstring") {
        $v = trim($v);
        if ($v === "" && @$info->optional)
            return 0;
        else if (validate_email($v) || $v === $opt_value)
            return ($v == "" ? 0 : array(0, $v));
        else
            $err = unparse_setting_error($info, "Invalid email." . var_export($opt_value,true));
    } else if ($info->type === "htmlstring") {
        if (($v = CleanHTML::clean($v, $err)) === false)
            $err = unparse_setting_error($info, $err);
        else if (@$info->message_default
                 && $v === $Conf->message_default_html($info->message_default))
            return 0;
        else if ($v === $Conf->setting_data($name))
            return null;
        else
            return ($v == "" ? 0 : array(1, $v));
    } else if ($info->type === "radio") {
        foreach ($info->values as $allowedv)
            if ((string) $allowedv === $v)
                return $allowedv;
        $err = unparse_setting_error($info, "Parse error (unexpected value).");
    } else
        return $v;

    $Error[] = $err;
    $Highlight[$name] = true;
    return null;
}

function save_tags($set, $what) {
    global $Conf, $Values, $Error, $Highlight, $TagStyles;
    $tagger = new Tagger;

    if (!$set && $what == "tag_chair" && isset($_POST["tag_chair"])) {
        $vs = array();
        foreach (preg_split('/\s+/', $_POST["tag_chair"]) as $t)
            if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                $vs[$t] = true;
            else if ($t !== "") {
                $Error[] = "Chair-only tag: " . $tagger->error_html;
                $Highlight["tag_chair"] = true;
            }
        $v = array(count($vs), join(" ", array_keys($vs)));
        if (!isset($Highlight["tag_chair"])
            && ($Conf->setting("tag_chair") !== $v[0]
                || $Conf->setting_data("tag_chair") !== $v[1]))
            $Values["tag_chair"] = $v;
    }

    if (!$set && $what == "tag_vote" && isset($_POST["tag_vote"])) {
        $vs = array();
        foreach (preg_split('/\s+/', $_POST["tag_vote"]) as $t)
            if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR)) {
                if (preg_match('/\A([^#]+)(|#|#0+|#-\d*)\z/', $t, $m))
                    $t = $m[1] . "#1";
                $vs[] = $t;
            } else if ($t !== "") {
                $Error[] = "Voting tag: " . $tagger->error_html;
                $Highlight["tag_vote"] = true;
            }
        $v = array(count($vs), join(" ", $vs));
        if (!isset($Highlight["tag_vote"])
            && ($Conf->setting("tag_vote") != $v[0]
                || $Conf->setting_data("tag_vote") !== $v[1]))
            $Values["tag_vote"] = $v;
    }

    if ($set && $what == "tag_vote" && isset($Values["tag_vote"])) {
        // check allotments
        $pcm = pcMembers();
        foreach (preg_split('/\s+/', $Values["tag_vote"][1]) as $t) {
            if ($t === "")
                continue;
            $base = substr($t, 0, strpos($t, "#"));
            $allotment = substr($t, strlen($base) + 1);

            $result = $Conf->q("select paperId, tag, tagIndex from PaperTag where tag like '%~" . sqlq_for_like($base) . "'");
            $pvals = array();
            $cvals = array();
            $negative = false;
            while (($row = edb_row($result))) {
                $who = substr($row[1], 0, strpos($row[1], "~"));
                if ($row[2] < 0) {
                    $Error[] = "Removed " . Text::user_html($pcm[$who]) . "’s negative “{$base}” vote for paper #$row[0].";
                    $negative = true;
                } else {
                    $pvals[$row[0]] = defval($pvals, $row[0], 0) + $row[2];
                    $cvals[$who] = defval($cvals, $who, 0) + $row[2];
                }
            }

            foreach ($cvals as $who => $what)
                if ($what > $allotment) {
                    $Error[] = Text::user_html($pcm[$who]) . " already has more than $allotment votes for tag &ldquo;$base&rdquo;.";
                    $Highlight["tag_vote"] = true;
                }

            $q = ($negative ? " or (tag like '%~" . sqlq_for_like($base) . "' and tagIndex<0)" : "");
            $Conf->qe("delete from PaperTag where tag='" . sqlq($base) . "'$q");

            $q = array();
            foreach ($pvals as $pid => $what)
                $q[] = "($pid, '" . sqlq($base) . "', $what)";
            if (count($q) > 0)
                $Conf->qe("insert into PaperTag values " . join(", ", $q));
        }
    }

    if (!$set && $what == "tag_rank" && isset($_POST["tag_rank"])) {
        $vs = array();
        foreach (preg_split('/\s+/', $_POST["tag_rank"]) as $t)
            if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                $vs[] = $t;
            else if ($t !== "") {
                $Error[] = "Rank tag: " . $tagger->error_html;
                $Highlight["tag_rank"] = true;
            }
        if (count($vs) > 1) {
            $Error[] = "At most one rank tag is currently supported.";
            $Highlight["tag_rank"] = true;
        }
        $v = array(count($vs), join(" ", $vs));
        if (!isset($Highlight["tag_rank"])
            && ($Conf->setting("tag_rank") !== $v[0]
                || $Conf->setting_data("tag_rank") !== $v[1]))
            $Values["tag_rank"] = $v;
    }

    if (!$set && $what == "tag_color") {
        $vs = array();
        $any_set = false;
        foreach (explode("|", $TagStyles) as $k)
            if (isset($_POST["tag_color_" . $k])) {
                $any_set = true;
                foreach (preg_split('/,*\s+/', $_POST["tag_color_" . $k]) as $t)
                    if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                        $vs[] = $t . "=" . $k;
                    else if ($t !== "") {
                        $Error[] = ucfirst($k) . " color tag: " . $tagger->error_html;
                        $Highlight["tag_color_" . $k] = true;
                    }
            }
        $v = array(1, join(" ", $vs));
        if ($any_set && $Conf->setting_data("tag_color") !== $v[1])
            $Values["tag_color"] = $v;
    }

    if ($set)
        TagInfo::invalidate_defined_tags();
}

function save_topics($set) {
    global $Conf, $Values;
    if (!$set) {
        $Values["topics"] = true;
        return;
    }

    $tmap = $Conf->topic_map();
    foreach ($_POST as $k => $v)
        if ($k === "topnew") {
            $news = array();
            foreach (explode("\n", $v) as $n)
                if (($n = simplify_whitespace($n)) !== "")
                    $news[] = "('" . sqlq($n) . "')";
            if (count($news))
                $Conf->qe("insert into TopicArea (topicName) values " . join(",", $news));
        } else if (strlen($k) > 3 && substr($k, 0, 3) === "top"
                   && ctype_digit(substr($k, 3))) {
            $k = (int) substr($k, 3);
            $v = simplify_whitespace($v);
            if ($v == "") {
                $Conf->qe("delete from TopicArea where topicId=$k");
                $Conf->qe("delete from PaperTopic where topicId=$k");
                $Conf->qe("delete from TopicInterest where topicId=$k");
            } else if (isset($tmap[$k]) && $v != $tmap[$k] && !ctype_digit($v))
                $Conf->qe("update TopicArea set topicName='" . sqlq($v) . "' where topicId=$k");
        }
}


function option_request_to_json(&$new_opts, $id, $current_opts) {
    global $Conf;

    $name = simplify_whitespace(defval($_POST, "optn$id", ""));
    if (!isset($_POST["optn$id"]) && $id[0] != "n") {
        if (@$current_opts[$id])
            $new_opts[$id] = $current_opts[$id];
        return;
    } else if ($name == ""
               || @$_POST["optfp$id"] == "delete"
               || ($id[0] == "n" && ($name == "New option" || $name == "(Enter new option)")))
        return;

    $oarg = array("name" => $name, "id" => (int) $id, "req_id" => $id);
    if ($id[0] == "n") {
        $nextid = max($Conf->setting("next_optionid", 1), 1);
        foreach ($new_opts as $haveid => $o)
            $nextid = max($nextid, $haveid + 1);
        foreach ($current_opts as $haveid => $o)
            $nextid = max($nextid, $haveid + 1);
        $oarg["id"] = $nextid;
        $oarg["is_new"] = true;
    }

    if (@$_POST["optd$id"] && trim($_POST["optd$id"]) != "") {
        $t = CleanHTML::clean($_POST["optd$id"], $err);
        if ($t === false) {
            $Error[] = $err;
            $Highlight["optd$id"] = true;
        } else
            $oarg["description"] = $t;
    }

    if (($optvt = @$_POST["optvt$id"])) {
        if (($pos = strpos($optvt, ":")) !== false) {
            $oarg["type"] = substr($optvt, 0, $pos);
            if (preg_match('/:final/', $optvt))
                $oarg["final"] = true;
            if (preg_match('/:ds_(\d+)/', $optvt, $m))
                $oarg["display_space"] = (int) $m[1];
        } else
            $oarg["type"] = $optvt;
    } else
        $oarg["type"] = "checkbox";

    if (PaperOption::type_has_selector($oarg["type"])) {
        $oarg["selector"] = array();
        $seltext = trim(cleannl(defval($_POST, "optv$id", "")));
        if ($seltext == "") {
            $Error[] = "Enter selectors one per line.";
            $Highlight["optv$id"] = true;
        } else
            foreach (explode("\n", $seltext) as $t)
                $oarg["selector"][] = $t;
    }

    $oarg["visibility"] = defval($_POST, "optp$id", "rev");
    if (@$oarg["final"])
        $oarg["visibility"] = "rev";

    $oarg["position"] = (int) defval($_POST, "optfp$id", 1);

    if (@$_POST["optdt$id"] == "near_submission"
        || ($oarg["type"] == "pdf" && @$oarg["final"]))
        $oarg["near_submission"] = true;
    else if (@$_POST["optdt$id"] == "highlight")
        $oarg["highlight"] = true;

    $new_opts[$oarg["id"]] = new PaperOption($oarg);
}

function option_clean_form_positions($new_opts, $current_opts) {
    foreach ($new_opts as $id => $o) {
        $current_o = @$current_opts[$id];
        $o->old_position = ($current_o ? $current_o->position : $o->position);
    }
    for ($i = 0; $i < count($new_opts); ++$i) {
        $best = null;
        foreach ($new_opts as $id => $o)
            if (!@$o->position_set
                && (!$best
                    || (@$o->near_submission
                        && !@$best->near_submission)
                    || $o->position < $best->position
                    || ($o->position == $best->position
                        && $o->position != $o->old_position
                        && $best->position == $best->old_position)
                    || ($o->position == $best->position
                        && strcasecmp($o->name, $best->name) < 0)
                    || ($o->position == $best->position
                        && strcasecmp($o->name, $best->name) == 0
                        && strcmp($o->name, $best->name) < 0)))
                $best = $o;
        $best->position = $i + 1;
        $best->position_set = true;
    }
}

function save_options($set) {
    global $Conf, $Values, $Error, $Highlight;

    if (!$set) {
        $current_opts = PaperOption::option_list();

        // convert request to JSON
        $new_opts = array();
        foreach ($current_opts as $id => $o)
            option_request_to_json($new_opts, $id, $current_opts);
        foreach ($_POST as $k => $v)
            if (substr($k, 0, 4) == "optn"
                && !@$current_opts[substr($k, 4)])
                option_request_to_json($new_opts, substr($k, 4), $current_opts);

        // check abbreviations
        $optabbrs = array();
        foreach ($new_opts as $id => $o)
            if (preg_match('/\Aopt\d+\z/', $o->abbr)) {
                $Error[] = "Option name “" . htmlspecialchars($o->name) . "” is reserved. Please pick another option name.";
                $Highlight["optn$o->req_id"] = true;
            } else if (@$optabbrs[$o->abbr]) {
                $Error[] = "Multiple options abbreviate to “{$o->abbr}”. Please pick option names that abbreviate uniquely.";
                $Highlight["optn$o->req_id"] = $Highlight[$optabbrs[$o->abbr]->req_id] = true;
            } else
                $optabbrs[$o->abbr] = $o;

        if (count($Error) == 0)
            $Values["options"] = $new_opts;
        return;
    }

    $new_opts = $Values["options"];
    $current_opts = PaperOption::option_list();
    option_clean_form_positions($new_opts, $current_opts);

    $newj = (object) array();
    uasort($new_opts, array("PaperOption", "compare"));
    $nextid = $Conf->setting("next_optionid", 1);
    foreach ($new_opts as $id => $o) {
        $newj->$id = $o->unparse();
        $nextid = max($nextid, $id + 1);
    }
    $Conf->save_setting("next_optionid", $nextid);
    $Conf->save_setting("options", 1, count($newj) ? $newj : null);

    $deleted_ids = array();
    foreach ($current_opts as $id => $o)
        if (!@$new_opts[$id])
            $deleted_ids[] = $id;
    if (count($deleted_ids))
        $Conf->qe("delete from PaperOption where optionId in (" . join(",", $deleted_ids) . ")");

    // invalidate cached option list
    PaperOption::invalidate_option_list();
}

function save_decisions($set) {
    global $Conf, $Values, $Error, $Highlight;
    if (!$set) {
        $dec_revmap = array();
        foreach ($_POST as $k => &$dname)
            if (str_starts_with($k, "dec")
                && ($k === "decn" || ($dnum = cvtint(substr($k, 3), 0)))
                && ($k !== "decn" || trim($dname) !== "")) {
                $dname = simplify_whitespace($dname);
                if (($derror = Conference::decision_name_error($dname))) {
                    $Error[] = htmlspecialchars($derror);
                    $Highlight[$k] = true;
                } else if (isset($dec_revmap[strtolower($dname)])) {
                    $Error[] = htmlspecialchars("Decision name “{$dname}” was already used.");
                    $Highlight[$k] = true;
                } else
                    $dec_revmap[strtolower($dname)] = true;
            }
        unset($dname);

        if (@$_POST["decn"] && !@$_POST["decn_confirm"]) {
            $delta = (defval($_POST, "dtypn", 1) > 0 ? 1 : -1);
            $match_accept = (stripos($_POST["decn"], "accept") !== false);
            $match_reject = (stripos($_POST["decn"], "reject") !== false);
            if ($delta > 0 && $match_reject) {
                $Error[] = "You are trying to add an Accept-class decision that has “reject” in its name, which is usually a mistake.  To add the decision anyway, check the “Confirm” box and try again.";
                $Highlight["decn"] = true;
            } else if ($delta < 0 && $match_accept) {
                $Error[] = "You are trying to add a Reject-class decision that has “accept” in its name, which is usually a mistake.  To add the decision anyway, check the “Confirm” box and try again.";
                $Highlight["decn"] = true;
            }
        }

        $Values["decisions"] = true;
        return;
    }

    // mark all used decisions
    $decs = $Conf->decision_map();
    $update = false;
    foreach ($_POST as $k => $v)
        if (str_starts_with($k, "dec") && ($k = cvtint(substr($k, 3), 0))) {
            if ($v == "") {
                $Conf->qe("update Paper set outcome=0 where outcome=$k");
                unset($decs[$k]);
                $update = true;
            } else if ($v != $decs[$k]) {
                $decs[$k] = $v;
                $update = true;
            }
        }

    if (defval($_POST, "decn", "") != "") {
        $delta = (defval($_POST, "dtypn", 1) > 0 ? 1 : -1);
        for ($k = $delta; isset($decs[$k]); $k += $delta)
            /* skip */;
        $decs[$k] = $_POST["decn"];
        $update = true;
    }

    if ($update)
        $Conf->save_setting("outcome_map", 1, $decs);
}

function save_banal($set) {
    global $Conf, $Values, $Highlight, $Error, $Warning, $ConfSitePATH;
    if ($set)
        return true;
    if (!isset($_POST["sub_banal"])) {
        if (($t = $Conf->setting_data("sub_banal", "")) != "")
            $Values["sub_banal"] = array(0, $t);
        else
            $Values["sub_banal"] = null;
        return true;
    }

    // check banal subsettings
    $old_error_count = count($Error);
    $bs = array_fill(0, 6, "");
    if (($s = trim(defval($_POST, "sub_banal_papersize", ""))) != ""
        && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
        $ses = preg_split('/\s*,\s*|\s+OR\s+/i', $s);
        $sout = array();
        foreach ($ses as $ss)
            if ($ss != "" && CheckFormat::parse_dimen($ss, 2))
                $sout[] = $ss;
            else if ($ss != "") {
                $Highlight["sub_banal_papersize"] = true;
                $Error[] = "Invalid paper size.";
                $sout = null;
                break;
            }
        if ($sout && count($sout))
            $bs[0] = join(" OR ", $sout);
    }

    if (($s = trim(defval($_POST, "sub_banal_pagelimit", ""))) != ""
        && strcasecmp($s, "N/A") != 0) {
        if (($sx = cvtint($s, -1)) > 0)
            $bs[1] = $sx;
        else if (preg_match('/\A(\d+)\s*-\s*(\d+)\z/', $s, $m)
                 && $m[1] > 0 && $m[2] > 0 && $m[1] <= $m[2])
            $bs[1] = +$m[1] . "-" . +$m[2];
        else {
            $Highlight["sub_banal_pagelimit"] = true;
            $Error[] = "Page limit must be a whole number bigger than 0, or a page range such as <code>2-4</code>.";
        }
    }

    if (($s = trim(defval($_POST, "sub_banal_columns", ""))) != ""
        && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
        if (($sx = cvtint($s, -1)) >= 0)
            $bs[2] = ($sx > 0 ? $sx : $bs[2]);
        else {
            $Highlight["sub_banal_columns"] = true;
            $Error[] = "Columns must be a whole number.";
        }
    }

    if (($s = trim(defval($_POST, "sub_banal_textblock", ""))) != ""
        && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
        // change margin specifications into text block measurements
        if (preg_match('/^(.*\S)\s+mar(gins?)?/i', $s, $m)) {
            $s = $m[1];
            if (!($ps = CheckFormat::parse_dimen($bs[0]))) {
                $Highlight["sub_banal_pagesize"] = true;
                $Highlight["sub_banal_textblock"] = true;
                $Error[] = "You must specify a page size as well as margins.";
            } else if (strpos($s, "x") !== false) {
                if (!($m = CheckFormat::parse_dimen($s)) || !is_array($m) || count($m) > 4) {
                    $Highlight["sub_banal_textblock"] = true;
                    $Error[] = "Invalid margin definition.";
                    $s = "";
                } else if (count($m) == 2)
                    $s = array($ps[0] - 2 * $m[0], $ps[1] - 2 * $m[1]);
                else if (count($m) == 3)
                    $s = array($ps[0] - 2 * $m[0], $ps[1] - $m[1] - $m[2]);
                else
                    $s = array($ps[0] - $m[0] - $m[2], $ps[1] - $m[1] - $m[3]);
            } else {
                $s = preg_replace('/\s+/', 'x', $s);
                if (!($m = CheckFormat::parse_dimen($s)) || (is_array($m) && count($m) > 4)) {
                    $Highlight["sub_banal_textblock"] = true;
                    $Error[] = "Invalid margin definition.";
                } else if (!is_array($m))
                    $s = array($ps[0] - 2 * $m, $ps[1] - 2 * $m);
                else if (count($m) == 2)
                    $s = array($ps[0] - 2 * $m[1], $ps[1] - 2 * $m[0]);
                else if (count($m) == 3)
                    $s = array($ps[0] - 2 * $m[1], $ps[1] - $m[0] - $m[2]);
                else
                    $s = array($ps[0] - $m[1] - $m[3], $ps[1] - $m[0] - $m[2]);
            }
            $s = (is_array($s) ? CheckFormat::unparse_dimen($s) : "");
        }
        // check text block measurements
        if ($s && !CheckFormat::parse_dimen($s, 2)) {
            $Highlight["sub_banal_textblock"] = true;
            $Error[] = "Invalid text block definition.";
        } else if ($s)
            $bs[3] = $s;
    }

    if (($s = trim(defval($_POST, "sub_banal_bodyfontsize", ""))) != ""
        && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
        if (!is_numeric($s) || $s <= 0) {
            $Highlight["sub_banal_bodyfontsize"] = true;
            $Error[] = "Minimum body font size must be a number bigger than 0.";
        } else
            $bs[4] = $s;
    }

    if (($s = trim(defval($_POST, "sub_banal_bodyleading", ""))) != ""
        && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
        if (!is_numeric($s) || $s <= 0) {
            $Highlight["sub_banal_bodyleading"] = true;
            $Error[] = "Minimum body leading must be a number bigger than 0.";
        } else
            $bs[5] = $s;
    }

    while (count($bs) > 0 && $bs[count($bs) - 1] == "")
        array_pop($bs);

    // actually create setting
    if (count($Error) == $old_error_count) {
        $Values["sub_banal"] = array(1, join(";", $bs));
        $zoomarg = "";

        // Perhaps we have an old pdftohtml with a bad -zoom.
        for ($tries = 0; $tries < 2; ++$tries) {
            $cf = new CheckFormat();
            $s1 = $cf->analyzeFile("$ConfSitePATH/src/sample.pdf", "letter;2;;6.5inx9in;12;14" . $zoomarg);
            $e1 = $cf->errors;
            if ($s1 == 1 && ($e1 & CheckFormat::ERR_PAPERSIZE) && $tries == 0)
                $zoomarg = ">-zoom=1";
            else if ($s1 != 2 && $tries == 1)
                $zoomarg = "";
        }

        $Values["sub_banal"][1] .= $zoomarg;
        $e1 = $cf->errors;
        $s2 = $cf->analyzeFile("$ConfSitePATH/src/sample.pdf", "a4;1;;3inx3in;14;15" . $zoomarg);
        $e2 = $cf->errors;
        $want_e2 = CheckFormat::ERR_PAPERSIZE | CheckFormat::ERR_PAGELIMIT
            | CheckFormat::ERR_TEXTBLOCK | CheckFormat::ERR_BODYFONTSIZE
            | CheckFormat::ERR_BODYLEADING;
        if ($s1 != 2 || $e1 != 0 || $s2 != 1 || ($e2 & $want_e2) != $want_e2)
            $Warning[] = "Running the automated paper checker on a sample PDF file produced unexpected results.  Check that your <code>pdftohtml</code> package is up to date.  You may want to disable the automated checker for now. (Internal error information: $s1 $e1 $s2 $e2)";
    }
}

function save_tracks($set) {
    global $Values, $Error, $Warning, $Highlight;
    if ($set)
        return;
    $tagger = new Tagger;
    $tracks = (object) array();
    $missing_tags = false;
    for ($i = 1; isset($_POST["name_track$i"]); ++$i) {
        $trackname = trim($_POST["name_track$i"]);
        if ($trackname === "" || $trackname === "(tag)")
            continue;
        else if (!$tagger->check($trackname, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)
                 || ($trackname === "_" && $i != 1)) {
            if ($trackname !== "_")
                $Error[] = "Track name: " . $tagger->error_html;
            else
                $Error[] = "Track name “_” is reserved.";
            $Highlight["name_track$i"] = $Highlight["tracks"] = true;
            continue;
        }
        $t = (object) array();
        foreach (array("view", "viewpdf", "viewrev", "assrev", "unassrev") as $type)
            if (($ttype = defval($_POST, "${type}_track$i", "")) == "+"
                || $ttype == "-") {
                $ttag = trim(defval($_POST, "${type}tag_track$i", ""));
                if ($ttag === "" || $ttag === "(tag)") {
                    $Error[] = "Tag missing for track setting.";
                    $Highlight["${type}_track$i"] = $Highlight["tracks"] = true;
                } else if (($ttype == "+" && strcasecmp($ttag, "none") == 0)
                           || $tagger->check($ttag, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                    $t->$type = $ttype . $ttag;
                else {
                    $Error[] = $tagger->error_html;
                    $Highlight["${type}_track$i"] = $Highlight["tracks"] = true;
                }
            } else if ($ttype == "none")
                $t->$type = "+none";
        if (count((array) $t) || @$tracks->_)
            $tracks->$trackname = $t;
        if (@$t->viewpdf && $t->viewpdf != @$t->unassrev && @$t->unassrev != "+none")
            $Warning[] = ($trackname === "_" ? "Default track" : "Track “{$trackname}”") . ": Generally, a track that restricts PDF visibility should restrict the “self-assign papers” right in the same way.";
    }
    if (count((array) $tracks))
        $Values["tracks"] = array(1, json_encode($tracks));
    else
        $Values["tracks"] = null;
}

function save_rounds($set) {
    global $Conf, $Values, $Error, $Highlight;
    if ($set)
        return;
    else if (!isset($_POST["rev_roundtag"])) {
        $Values["rev_roundtag"] = null;
        return;
    }
    // round names
    $roundnames = $roundnames_set = array();
    $roundname0 = $round_deleted = null;
    $Values["rev_round_changes"] = array();
    for ($i = 0;
         isset($_POST["roundname_$i"]) || isset($_POST["deleteround_$i"]) || !$i;
         ++$i) {
        $rname = @trim($_POST["roundname_$i"]);
        if ($rname === "(no name)" || $rname === "default")
            $rname = "";
        if ((@$_POST["deleteround_$i"] || $rname === "") && $i) {
            $roundnames[] = ";";
            $Values["rev_round_changes"][] = array($i, 0);
            if ($round_deleted === null && !isset($_POST["roundname_0"])
                && $i < $_POST["oldroundcount"])
                $round_deleted = $i;
        } else if ($rname === "")
            /* ignore */;
        else if (($rerror = Conference::round_name_error($rname))) {
            $Error[] = $rerror;
            $Highlight["roundname_$i"] = true;
        } else if ($i == 0)
            $roundname0 = $rname;
        else if (@$roundnames_set[strtolower($rname)]) {
            $roundnames[] = ";";
            $Values["rev_round_changes"][] = array($i, $roundnames_set[strtolower($rname)]);
        } else {
            $roundnames[] = $rname;
            $roundnames_set[strtolower($rname)] = $i;
        }
    }
    if ($roundname0 && !@$roundnames_set[strtolower($roundname0)]) {
        $roundnames[] = $roundname0;
        $roundnames_set[strtolower($roundname0)] = count($roundnames);
    }
    if ($roundname0)
        array_unshift($Values["rev_round_changes"], array(0, $roundnames_set[strtolower($roundname0)]));

    // round deadlines
    foreach ($Conf->round_list() as $i => $rname) {
        $suffix = $i ? "_$i" : "";
        foreach (Conference::$review_deadlines as $k)
            $Values[$k . $suffix] = null;
    }
    $rtransform = array();
    if ($roundname0 && ($ri = $roundnames_set[strtolower($roundname0)])
        && !isset($_POST["pcrev_soft_$ri"])) {
        $rtransform[0] = "_$ri";
        $rtransform[$ri] = false;
    }
    if ($round_deleted) {
        $rtransform[$round_deleted] = "";
        if (!isset($rtransform[0]))
            $rtransform[0] = false;
    }
    for ($i = 0; $i < count($roundnames) + 1; ++$i)
        if ((isset($rtransform[$i])
             || ($i ? $roundnames[$i - 1] !== ";" : !isset($_POST["deleteround_0"])))
            && @$rtransform[$i] !== false) {
            $isuffix = $i ? "_$i" : "";
            if (($osuffix = @$rtransform[$i]) === null)
                $osuffix = $isuffix;
            $ndeadlines = 0;
            foreach (Conference::$review_deadlines as $k) {
                $v = parse_value($k . $isuffix, setting_info($k));
                $Values[$k . $osuffix] = $v < 0 ? null : $v;
                $ndeadlines += $v > 0;
            }
            if ($ndeadlines == 0 && $osuffix)
                $Values["pcrev_soft$osuffix"] = 0;
            foreach (array("pcrev_", "extrev_") as $k) {
                list($soft, $hard) = array("{$k}soft$osuffix", "{$k}hard$osuffix");
                if (!@$Values[$soft] && @$Values[$hard])
                    $Values[$soft] = $Values[$hard];
                else if (@$Values[$hard] && @$Values[$soft] > $Values[$hard]) {
                    $desc = $i ? ", round " . htmlspecialchars($roundnames[$i - 1]) : "";
                    $Error[] = setting_info("{$k}soft", "name") . $desc . ": Must come before " . setting_info("{$k}hard", "name") . ".";
                    $Highlight[$soft] = $Highlight[$hard] = true;
                }
            }
        }

    // round list (save after deadlines processing)
    while (count($roundnames) && $roundnames[count($roundnames) - 1] === ";")
        array_pop($roundnames);
    if (count($roundnames))
        $Values["tag_rounds"] = array(1, join(" ", $roundnames));
    else
        $Values["tag_rounds"] = null;

    // default round
    $t = trim($_POST["rev_roundtag"]);
    $Values["rev_roundtag"] = null;
    if ($t === "" || strtolower($t) === "(none)" || strtolower($t) === "(no name)" || strtolower($t) === "default")
        /* do nothing */;
    else if ($t === "#0") {
        if ($roundname0)
            $Values["rev_roundtag"] = array(1, $roundname0);
    } else if (preg_match('/^#[1-9][0-9]*$/', $t)) {
        $rname = @$roundnames[substr($t, 1) - 1];
        if ($rname && $rname !== ";")
            $Values["rev_roundtag"] = array(1, $rname);
    } else if (!($rerror = Conference::round_name_error($t)))
        $Values["rev_roundtag"] = array(1, $t);
    else {
        $Error[] = $rerror;
        $Highlight["rev_roundtag"] = true;
    }
}

function save_resp_rounds($set) {
    global $Conf, $Error, $Highlight, $Values;
    if ($set || !value_or_setting("resp_active"))
        return;
    $old_roundnames = $Conf->resp_round_list();
    $roundnames = array(1);
    $roundnames_set = array();

    if (isset($_POST["resp_roundname"])) {
        $rname = @trim($_POST["resp_roundname"]);
        if ($rname === "" || $rname === "none" || $rname === "1")
            /* do nothing */;
        else if (($rerror = Conference::resp_round_name_error($rname))) {
            $Error[] = $rerror;
            $Highlight["resp_roundname"] = true;
        } else {
            $roundnames[0] = $rname;
            $roundnames_set[strtolower($rname)] = 0;
        }
    }

    for ($i = 1; isset($_POST["resp_roundname_$i"]); ++$i) {
        $rname = @trim($_POST["resp_roundname_$i"]);
        if ($rname === "" && @$old_roundnames[$i])
            $rname = $old_roundnames[$i];
        if ($rname === "")
            continue;
        else if (($rerror = Conference::resp_round_name_error($rname))) {
            $Error[] = $rerror;
            $Highlight["resp_roundname_$i"] = true;
        } else if (@$roundnames_set[strtolower($rname)] !== null) {
            $Error[] = "Response round name “" . htmlspecialchars($rname) . "” has already been used.";
            $Highlight["resp_roundname_$i"] = true;
        } else {
            $roundnames[] = $rname;
            $roundnames_set[strtolower($rname)] = $i;
        }

        if (($v = parse_value("resp_open_$i", setting_info("resp_open"))) !== null)
            $Values["resp_open_$i"] = $v < 0 ? null : $v;
        if (($v = parse_value("resp_done_$i", setting_info("resp_done"))) !== null)
            $Values["resp_done_$i"] = $v < 0 ? null : $v;
        if (($v = parse_value("resp_words_$i", setting_info("resp_words"))) !== null)
            $Values["resp_words_$i"] = $v < 0 ? null : $v;
        if (($v = parse_value("msg.resp_instrux_$i", setting_info("msg.resp_instrux"))) !== null)
            $Values["msg.resp_instrux_$i"] = $v;
    }

    if (count($roundnames) > 1 || $roundnames[0] !== 1)
        $Values["resp_rounds"] = array(1, join(" ", $roundnames));
    else
        $Values["resp_rounds"] = 0;
}

function doSpecial($name, $set) {
    global $Values;
    if ($name == "tag_chair" || $name == "tag_vote"
        || $name == "tag_rank" || $name == "tag_color")
        save_tags($set, $name);
    else if ($name == "topics")
        save_topics($set);
    else if ($name == "options")
        save_options($set);
    else if ($name == "decisions")
        save_decisions($set);
    else if ($name == "reviewform") {
        if (!$set)
            $Values[$name] = true;
        else
            rf_update();
    } else if ($name == "banal")
        save_banal($set);
    else if ($name == "rev_roundtag")
        save_rounds($set);
    else if ($name == "tracks")
        save_tracks($set);
    else if ($name == "resp_rounds")
        save_resp_rounds($set);
}

function truthy($x) {
    return !($x === null || $x === 0 || $x === false
             || $x === "" || $x === "0" || $x === "false");
}

function account_value($name, $info) {
    global $Values, $Error, $Highlight;
    $xname = str_replace(".", "_", $name);
    if (@$info->type === "special")
        $has_value = truthy(@$_POST["has_$xname"]);
    else
        $has_value = isset($_POST[$xname])
            || ((@$info->type === "cdate" || @$info->type === "checkbox")
                && truthy(@$_POST["has_$xname"]));

    if ($has_value && (@$info->disabled || @$info->novalue
                       || !@$info->type || $info->type === "none"))
        /* ignore changes to disabled/novalue settings */;
    else if ($has_value && $info->type === "special")
        doSpecial($name, false);
    else if ($has_value) {
        $v = parse_value($name, $info);
        if ($v === null) {
            if ($info->type !== "cdate" && $info->type !== "checkbox")
                return;
            $v = 0;
        }
        if (!is_array($v) && $v <= 0 && $info->type !== "radio" && $info->type !== "zint")
            $Values[$name] = null;
        else
            $Values[$name] = $v;
        if (@$info->ifnonempty)
            $Values[$info->ifnonempty] = ($Values[$name] === null ? null : 1);
    }
}

function has_value($name) {
    global $Values;
    return array_key_exists($name, $Values);
}

function value($name, $default = null) {
    global $Conf, $Values;
    if (array_key_exists($name, $Values))
        return $Values[$name];
    else
        return $default;
}

function value_or_setting($name) {
    global $Conf, $Values;
    if (array_key_exists($name, $Values))
        return $Values[$name];
    else
        return $Conf->setting($name);
}

function value_or_setting_data($name) {
    global $Conf, $Values;
    if (array_key_exists($name, $Values))
        return is_array(@$Values[$name]) ? $Values[$name][1] : null;
    else
        return $Conf->setting_data($name);
}

if (isset($_REQUEST["update"]) && check_post()) {
    // parse settings
    foreach ($SettingInfo as $name => $info)
        account_value($name, $info);

    // check date relationships
    foreach (array("sub_reg" => "sub_sub", "final_soft" => "final_done")
             as $first => $second)
        if (!@$Values[$first] && @$Values[$second])
            $Values[$first] = $Values[$second];
        else if (@$Values[$second] && @$Values[$first] > $Values[$second]) {
            $Error[] = unparse_setting_error($SettingInfo->$first, "Must come before " . setting_info($second, "name") . ".");
            $Highlight[$first] = $Highlight[$second] = true;
        }
    if (array_key_exists("sub_sub", $Values))
        $Values["sub_update"] = $Values["sub_sub"];
    if (array_key_exists("opt.contactName", $Values)
        || array_key_exists("opt.contactEmail", $Values)) {
        $site_contact = Contact::site_contact();
        if (@$Opt["defaultSiteContact"]
            && @($Opt["contactName"] === $Values["opt.contactName"][1]))
            $Values["opt.contactName"] = null;
        if (@$Opt["defaultSiteContact"]
            && @($Opt["contactEmail"] === $Values["opt.contactEmail"][1]))
            $Values["opt.contactEmail"] = null;
    }
    if (@$Values["resp_active"])
        foreach (explode(" ", value_or_setting_data("resp_rounds")) as $i => $rname) {
            $isuf = $i ? "_$i" : "";
            if (@$Values["resp_open$isuf"] && @$Values["resp_done$isuf"]
                && $Values["resp_done$isuf"] >= $Now
                && $Values["resp_open$isuf"] > $Values["resp_done$isuf"]) {
                $Error[] = unparse_setting_error($SettingInfo->resp_open, "Must come before " . setting_info("resp_done", "name") . ".");
                $Highlight["resp_open$isuf"] = $Highlight["resp_done$isuf"] = true;
            }
        }

    // update 'papersub'
    if (isset($_POST["pc_seeall"])) {
        // see also conference.php
        $result = $Conf->q("select ifnull(min(paperId),0) from Paper where " . (defval($Values, "pc_seeall", 0) <= 0 ? "timeSubmitted>0" : "timeWithdrawn<=0"));
        if (($row = edb_row($result)) && $row[0] != $Conf->setting("papersub"))
            $Values["papersub"] = $row[0];
    }

    // warn on other relationships
    if (value("sub_freeze", -1) == 0
        && value("sub_open") > 0
        && value("sub_sub") <= 0)
        $Warning[] = "You have not set a paper submission deadline, but authors can update their submissions until the deadline.  This is sometimes unintentional.  You probably should (1) specify a paper submission deadline; (2) select “Authors must freeze the final version of each submission”; or (3) manually turn off “Open site for submissions” when submissions complete.";
    if (value("sub_open", 1) <= 0
        && $Conf->setting("sub_open") > 0
        && value_or_setting("sub_sub") <= 0)
        $Values["sub_close"] = $Now;
    foreach (array("pcrev_soft", "pcrev_hard", "extrev_soft", "extrev_hard")
             as $deadline)
        if (value($deadline) > $Now
            && value($deadline) != $Conf->setting($deadline)
            && value_or_setting("rev_open") <= 0) {
            $Warning[] = "Review deadline set. You may also want to open the site for reviewing.";
            $Highlight["rev_open"] = true;
            break;
        }
    if (value_or_setting("au_seerev") != AU_SEEREV_NO
        && $Conf->setting("pcrev_soft") > 0
        && $Now < $Conf->setting("pcrev_soft")
        && count($Error) == 0)
        $Warning[] = "Authors can now see reviews and comments although it is before the review deadline.  This is sometimes unintentional.";
    if (value("final_open")
        && (!value("final_done") || value("final_done") > $Now)
        && value_or_setting("seedec") != Conference::SEEDEC_ALL)
        $Warning[] = "The system is set to collect final versions, but authors cannot submit final versions until they know their papers have been accepted.  You should change the “Who can see paper decisions” setting to “<strong>Authors</strong>, etc.”";
    if (value("seedec") == Conference::SEEDEC_ALL
        && value_or_setting("au_seerev") == AU_SEEREV_NO)
        $Warning[] = "Authors can see decisions, but not reviews. This is sometimes unintentional.";
    if (has_value("msg.clickthrough_submit"))
        $Values["clickthrough_submit"] = null;

    // make settings
    if (count($Error) == 0 && count($Values) > 0) {
        $tables = "Settings write, TopicArea write, PaperTopic write, TopicInterest write, PaperOption write";
        if (array_key_exists("decisions", $Values)
            || array_key_exists("tag_vote", $Values))
            $tables .= ", Paper write";
        if (array_key_exists("tag_vote", $Values))
            $tables .= ", PaperTag write";
        if (array_key_exists("reviewform", $Values))
            $tables .= ", PaperReview write";
        $Conf->qe("lock tables $tables");

        // apply settings
        foreach ($Values as $n => $v)
            if (setting_info($n, "type") == "special")
                doSpecial($n, true);

        $dv = $aq = $av = array();
        foreach ($Values as $n => $v)
            if (!setting_info($n, "nodb")) {
                $dv[] = $n;
                if (substr($n, 0, 4) === "opt.") {
                    $okey = substr($n, 4);
                    $oldv = (array_key_exists($okey, $OptOverride) ? $OptOverride[$okey] : @$Opt[$okey]);
                    $Opt[$okey] = (is_array($v) ? $v[1] : $v);
                    if ($oldv === $Opt[$okey])
                        continue; // do not save value in database
                    else if (!array_key_exists($okey, $OptOverride))
                        $OptOverride[$okey] = $oldv;
                }
                if (is_array($v)) {
                    $aq[] = "(?, ?, ?)";
                    array_push($av, $n, $v[0], $v[1]);
                } else if ($v !== null) {
                    $aq[] = "(?, ?, null)";
                    array_push($av, $n, $v);
                }
            }
        if (count($dv))
            Dbl::qe_apply("delete from Settings where name?a", array($dv));
        if (count($aq))
            Dbl::qe_apply("insert into Settings (name, value, data) values " . join(",", $aq), $av);

        $Conf->qe("unlock tables");
        $Me->log_activity("Updated settings group '$Group'");
        $Conf->load_settings();

        // remove references to deleted rounds
        if (array_key_exists("rev_round_changes", $Values))
            foreach ($Values["rev_round_changes"] as $x)
                $Conf->qe("update PaperReview set reviewRound=$x[1] where reviewRound=$x[0]");

        // contactdb may need to hear about changes to shortName
        if (array_key_exists("opt.shortName", $Values)
            && @$Opt["contactdb_dsn"] && ($cdb = Contact::contactdb()))
            Dbl::ql($cdb, "update Conferences set shortName=? where dbName=?", $Opt["shortName"], $Opt["dbName"]);
    }

    // report errors
    $msgs = array();
    if (count($Error) > 0 || count($Warning) > 0) {
        $any_errors = false;
        foreach ($Error as $m)
            if ($m && $m !== true && $m !== 1)
                $msgs[] = $any_errors = $m;
        foreach ($Warning as $m)
            if ($m && $m !== true && $m !== 1)
                $msgs[] = "Warning: " . $m;
        $mt = '<div class="multimessage"><div>' . join('</div><div>', $msgs) . '</div></div>';
        if (count($msgs) && $any_errors)
            $Conf->errorMsg($mt);
        else if (count($msgs))
            $Conf->warnMsg($mt);
    }

    // update the review form in case it's changed
    ReviewForm::clear_cache();
    if (count($Error) == 0) {
        $Conf->save_session("settings_highlight", $Highlight);
        if (!count($msgs))
            $Conf->confirmMsg("Changes saved.");
        redirectSelf();
    }
} else if ($Group == "rfo")
    rf_update();
if (isset($_REQUEST["cancel"]) && check_post())
    redirectSelf();


function setting_js($name, $extra = array()) {
    global $Highlight;
    $x = array("id" => $name);
    if (setting_disabled($name))
        $x["disabled"] = true;
    foreach ($extra as $k => $v)
        $x[$k] = $v;
    if (@$Highlight[$name])
        $x["class"] = trim("setting_error " . (@$x["class"] ? : ""));
    return $x;
}

function setting_class($name) {
    global $Highlight;
    return @$Highlight[$name] ? "setting_error" : null;
}

function setting_label($name, $text, $islabel = null) {
    global $Highlight;
    if (@$Highlight[$name])
        $text = "<span class=\"setting_error\">$text</span>";
    if ($islabel !== false)
        $text = Ht::label($text, $islabel ? : $name);
    return $text;
}

function setting($name, $defval = null) {
    global $Error, $Conf;
    if (count($Error) > 0)
        return defval($_POST, $name, $defval);
    else
        return $Conf->setting($name, $defval);
}

function setting_data($name, $defval = "", $killval = "") {
    global $Error, $Conf;
    if (substr($name, 0, 4) === "opt.")
        return opt_data(substr($name, 4), $defval, $killval);
    else if (count($Error) > 0)
        $val = defval($_POST, $name, $defval);
    else
        $val = defval($Conf->settingTexts, $name, $defval);
    if ($val == $killval)
        $val = "";
    return $val;
}

function opt_data($name, $defval = "", $killval = "") {
    global $Error, $Opt;
    if (count($Error))
        $val = defval($_POST, "opt.$name", $defval);
    else
        $val = defval($Opt, $name, $defval);
    if ($val == $killval)
        $val = "";
    return $val;
}

function doCheckbox($name, $text, $tr = false, $js = null) {
    $x = setting($name);
    echo ($tr ? '<tr><td class="nw">' : ""),
        Ht::hidden("has_$name", 1),
        Ht::checkbox($name, 1, $x !== null && $x > 0, setting_js($name, array("onchange" => $js, "id" => "cb$name"))),
        "&nbsp;", ($tr ? "</td><td>" : ""),
        setting_label($name, $text, true),
        ($tr ? "</td></tr>\n" : "<br />\n");
}

function doRadio($name, $varr) {
    $x = setting($name);
    if ($x === null || !isset($varr[$x]))
        $x = 0;
    echo "<table>\n";
    foreach ($varr as $k => $text) {
        echo '<tr><td class="nw">', Ht::radio($name, $k, $k == $x, setting_js($name, array("id" => "{$name}_{$k}"))),
            "&nbsp;</td><td>";
        if (is_array($text))
            echo setting_label($name, $text[0], true), "<br /><small>", $text[1], "</small>";
        else
            echo setting_label($name, $text, true);
        echo "</td></tr>\n";
    }
    echo "</table>\n";
}

function doSelect($name, $nametext, $varr, $tr = false) {
    echo ($tr ? '<tr><td class="lcaption nw">' : ""),
        setting_label($name, $nametext),
        ($tr ? "</td><td class='lentry'>" : ": &nbsp;"),
        Ht::select($name, $varr, setting($name), setting_js($name)),
        ($tr ? "</td></tr>\n" : "<br />\n");
}

function render_entry($name, $v, $size = 30, $temptext = "") {
    return Ht::entry($name, $v, setting_js($name, array("size" => $size, "hottemptext" => $temptext)));
}

function doTextRow($name, $text, $v, $size = 30,
                   $capclass = "lcaption", $tempText = "") {
    global $Conf;
    $nametext = (is_array($text) ? $text[0] : $text);
    echo '<tr><td class="', $capclass, ' nw">', setting_label($name, $nametext),
        '</td><td class="lentry">', render_entry($name, $v, $size, $tempText);
    if (is_array($text) && @$text[2])
        echo $text[2];
    if (is_array($text) && @$text[1])
        echo "<br /><span class='hint'>", $text[1], "</span>";
    echo "</td></tr>\n";
}

function doEntry($name, $v, $size = 30, $temptext = "") {
    echo render_entry($name, $v, $size, $temptext);
}

function date_value($name, $temptext, $othername = null) {
    global $Conf, $Error;
    $x = setting($name);
    if ($x !== null && count($Error))
        return $x;
    if ($othername && setting($othername) == $x)
        return $temptext;
    if ($temptext !== "N/A" && $temptext !== "none" && $x === 0)
        return "none";
    else if ($x <= 0)
        return $temptext;
    else if ($x == 1)
        return "now";
    else
        return $Conf->parseableTime($x, true);
}

function doDateRow($name, $text, $othername = null, $capclass = "lcaption") {
    global $DateExplanation;
    if ($DateExplanation) {
        if (is_array($text))
            $text[1] = $DateExplanation . "<br />" . $text[1];
        else
            $text = array($text, $DateExplanation);
        $DateExplanation = "";
    }
    doTextRow($name, $text, date_value($name, "N/A", $othername), 30, $capclass, "N/A");
}

function doGraceRow($name, $text, $capclass = "lcaption") {
    global $GraceExplanation;
    if (!isset($GraceExplanation)) {
        $text = array($text, "Example: “15 min”");
        $GraceExplanation = true;
    }
    doTextRow($name, $text, unparseGrace(setting($name)), 15, $capclass, "none");
}

function doActionArea($top) {
    echo "<div class='aa'", ($top ? " style='margin-top:0'" : ""), ">",
        Ht::submit("update", "Save changes", array("class" => "bb")),
        " &nbsp;", Ht::submit("cancel", "Cancel"), "</div>";
}



// Accounts
function doAccGroup() {
    global $Conf, $Me;

    if (setting("acct_addr"))
        doCheckbox("acct_addr", "Collect users’ addresses and phone numbers");

    echo "<h3 class=\"settings g\">Program committee &amp; system administrators</h3>";

    echo "<p><a href='", hoturl("profile", "u=new&amp;role=pc"), "' class='button'>Create PC account</a> &nbsp;|&nbsp; ",
        "Select a user’s name to edit a profile.</p>\n";
    $pl = new ContactList($Me, false);
    echo $pl->text("pcadminx", hoturl("users", "t=pcadmin"));
}

// Messages
function do_message($name, $description, $type, $rows = 10, $hint = "") {
    global $Conf;
    $defaultname = $name;
    if (is_array($name))
        list($name, $defaultname) = $name;
    $default = $Conf->message_default_html($defaultname);
    $current = setting_data($name, $default);
    echo '<div class="fold', ($current == $default ? "c" : "o"),
        '" hotcrp_fold="yes">',
        '<div class="', ($type ? "f-cn" : "f-cl"),
        ' childfold" onclick="return foldup(this,event)">',
        '<a class="q" href="#" onclick="return foldup(this,event)">',
        expander(null, 0), setting_label($name, $description),
        '</a> <span class="f-cx fx">(HTML allowed)</span></div>',
        $hint,
        Ht::textarea($name, $current, setting_js($name, array("class" => "fx", "rows" => $rows, "cols" => 80))),
        '</div><div class="g"></div>', "\n";
}

function doInfoGroup() {
    global $Conf, $Opt;

    echo '<div class="f-c">', setting_label("opt.shortName", "Conference abbreviation"), "</div>\n";
    doEntry("opt.shortName", opt_data("shortName"), 20);
    echo '<div class="f-h">Examples: “HotOS XIV”, “NSDI \'14”</div>',
        '<div class="g"></div>', "\n";

    $long = opt_data("longName");
    if ($long == opt_data("shortName"))
        $long = "";
    echo "<div class='f-c'>", setting_label("opt.longName", "Conference name"), "</div>\n";
    doEntry("opt.longName", $long, 70, "(same as abbreviation)");
    echo '<div class="f-h">Example: “14th Workshop on Hot Topics in Operating Systems”</div>';


    echo '<div class="lg"></div>', "\n";

    echo '<div class="f-c">', setting_label("opt.contactName", "Name of site contact"), "</div>\n";
    doEntry("opt.contactName", opt_data("contactName", null, "Your Name"), 50);
    echo '<div class="g"></div>', "\n";

    echo "<div class='f-c'>", setting_label("opt.contactEmail", "Email of site contact"), "</div>\n";
    doEntry("opt.contactEmail", opt_data("contactEmail", null, "you@example.com"), 40);
    echo '<div class="f-h">The site contact is the contact point for users if something goes wrong. It defaults to the chair.</div>';


    echo '<div class="lg"></div>', "\n";

    echo '<div class="f-c">', setting_label("opt.emailReplyTo", "Reply-To field for email"), "</div>\n";
    doEntry("opt.emailReplyTo", opt_data("emailReplyTo"), 80, "(none)");
    echo '<div class="g"></div>', "\n";

    echo '<div class="f-c">', setting_label("opt.emailCc", "Default Cc for reviewer email"), "</div>\n";
    doEntry("opt.emailCc", opt_data("emailCc"), 80, "(none)");
    echo '<div class="f-h">This applies to email sent to reviewers and email sent using the <a href="', hoturl("mail"), '">mail tool</a>. It doesn’t apply to account-related email or email sent to submitters.</div>';
}

function doMsgGroup() {
    do_message("msg.home", "Home page message", 0);
    do_message("msg.clickthrough_submit", "Clickthrough submission terms", 0, 10,
               "<div class=\"hint fx\">Users must “accept” these terms to edit or submit a paper. Use HTML and include a headline, such as “&lt;h2&gt;Submission terms&lt;/h2&gt;”.</div>");
    do_message("msg.submit", "Submission message", 0, 5,
               "<div class=\"hint fx\">This message will appear on paper editing pages.</div>");
    do_message("msg.clickthrough_review", "Clickthrough reviewing terms", 0, 10,
               "<div class=\"hint fx\">Users must “accept” these terms to edit a review. Use HTML and include a headline, such as “&lt;h2&gt;Submission terms&lt;/h2&gt;”.</div>");
    do_message("msg.conflictdef", "Definition of conflict of interest", 0, 5);
    do_message("msg.revprefdescription", "Review preference instructions", 0, 20);
}

// Submissions
function doSubGroup() {
    global $Conf;

    doCheckbox('sub_open', '<b>Open site for submissions</b>');

    echo "<div class='g'></div>\n";
    echo "<strong>Blind submission:</strong> Are author names hidden from reviewers?<br />\n";
    doRadio("sub_blind", array(Conference::BLIND_ALWAYS => "Yes—submissions are anonymous",
                               Conference::BLIND_NEVER => "No—author names are visible to reviewers",
                               Conference::BLIND_UNTILREVIEW => "Blind until review—reviewers can see author names after submitting a review",
                               Conference::BLIND_OPTIONAL => "Depends—authors decide whether to expose their names"));

    echo "<div class='g'></div>\n<table>\n";
    doDateRow("sub_reg", "Registration deadline", "sub_sub");
    doDateRow("sub_sub", "Submission deadline");
    doGraceRow("sub_grace", 'Grace period');
    echo "</table>\n";

    echo "<div class='g'></div>\n<table id='foldpcconf' class='fold",
        (setting("sub_pcconf") ? "o" : "c"), "'>\n";
    doCheckbox("sub_pcconf", "Collect authors’ PC conflicts", true,
               "void fold('pcconf',!this.checked)");
    echo "<tr class='fx'><td></td><td>";
    doCheckbox("sub_pcconfsel", "Collect PC conflict types (“Advisor/student,” “Recent collaborator,” etc.)");
    echo "</td></tr>\n";
    doCheckbox("sub_collab", "Collect authors’ other collaborators as text", true);
    echo "</table>\n";

    if (is_executable("src/banal")) {
        echo "<div class='g'></div>",
            Ht::hidden("has_banal", 1),
            "<table id='foldbanal' class='", (setting("sub_banal") ? "foldo" : "foldc"), "'>";
        doCheckbox("sub_banal", "<strong>Automated format checker<span class='fx'>:</span></strong>", true, "void fold('banal',!this.checked)");
        echo "<tr class='fx'><td></td><td class='top'><table>";
        $bsetting = explode(";", preg_replace("/>.*/", "", $Conf->setting_data("sub_banal", "")));
        for ($i = 0; $i < 6; $i++)
            if (defval($bsetting, $i, "") == "")
                $bsetting[$i] = "N/A";
        doTextRow("sub_banal_papersize", array("Paper size", "Examples: “letter”, “A4”, “8.5in&nbsp;x&nbsp;14in”,<br />“letter OR A4”"), setting("sub_banal_papersize", $bsetting[0]), 18, "lxcaption", "N/A");
        doTextRow("sub_banal_pagelimit", "Page limit", setting("sub_banal_pagelimit", $bsetting[1]), 4, "lxcaption", "N/A");
        doTextRow("sub_banal_textblock", array("Text block", "Examples: “6.5in&nbsp;x&nbsp;9in”, “1in&nbsp;margins”"), setting("sub_banal_textblock", $bsetting[3]), 18, "lxcaption", "N/A");
        echo "</table></td><td><span class='sep'></span></td><td class='top'><table>";
        doTextRow("sub_banal_bodyfontsize", array("Minimum body font size", null, "&nbsp;pt"), setting("sub_banal_bodyfontsize", $bsetting[4]), 4, "lxcaption", "N/A");
        doTextRow("sub_banal_bodyleading", array("Minimum leading", null, "&nbsp;pt"), setting("sub_banal_bodyleading", $bsetting[5]), 4, "lxcaption", "N/A");
        doTextRow("sub_banal_columns", array("Columns", null), setting("sub_banal_columns", $bsetting[2]), 4, "lxcaption", "N/A");
        echo "</table></td></tr></table>";
    }

    echo "<hr class='hr' />\n";
    doRadio("sub_freeze", array(0 => "<strong>Authors can update submissions until the deadline</strong>", 1 => array("Authors must freeze the final version of each submission", "“Authors can update submissions until the deadline” is usually the best choice.  Freezing submissions can be useful when there is no submission deadline.")));

    echo "<div class='g'></div><table>\n";
    doCheckbox('pc_seeall', "PC can see <i>all registered papers</i> until submission deadline<br /><small>Check this box if you want to collect review preferences <em>before</em> most papers are submitted. After the submission deadline, PC members can only see submitted papers.</small>", true);
    echo "</table>";
}

// Submission options
function option_search_term($oname) {
    $owords = preg_split(',[^a-z_0-9]+,', strtolower(trim($oname)));
    for ($i = 0; $i < count($owords); ++$i) {
        $attempt = join("-", array_slice($owords, 0, $i + 1));
        if (count(PaperOption::search($attempt)) == 1)
            return $attempt;
    }
    return simplify_whitespace($oname);
}

function doOptGroupOption($o) {
    global $Conf, $Error;

    if (is_string($o))
        $o = new PaperOption(array("id" => $o,
                "name" => "(Enter new option)",
                "description" => "",
                "type" => "checkbox",
                "position" => count(PaperOption::option_list()) + 1));
    $id = $o->id;

    if (count($Error) > 0 && isset($_POST["optn$id"])) {
        $o = new PaperOption(array("id" => $id,
                "name" => $_POST["optn$id"],
                "description" => defval($_POST, "optd$id", ""),
                "type" => defval($_POST, "optvt$id", "checkbox"),
                "visibility" => defval($_POST, "optp$id", ""),
                "position" => defval($_POST, "optfp$id", 1),
                "highlight" => @($_POST["optdt$id"] == "highlight"),
                "near_submission" => @($_POST["optdt$id"] == "near_submission")));
        if ($o->has_selector())
            $o->selector = explode("\n", rtrim(defval($_POST, "optv$id", "")));
    }

    echo "<tr><td><div class='f-contain'>\n",
        "  <div class='f-i'>",
        "<div class='f-c'>",
        setting_label("optn$id", ($id === "n" ? "New option name" : "Option name")),
        "</div>",
        "<div class='f-e'>",
        Ht::entry("optn$id", $o->name, array("hottemptext" => "(Enter new option)", "size" => 50, "id" => "optn$id")),
        "</div>\n",
        "  <div class='f-i'>",
        "<div class='f-c'>",
        setting_label("optd$id", "Description"),
        "</div>",
        "<div class='f-e'>",
        Ht::textarea("optd$id", $o->description, array("rows" => 2, "cols" => 50, "id" => "optd$id")),
        "</div>",
        "</div></td>";

    if ($id !== "n") {
        echo "<td style='padding-left: 1em'><div class='f-i'>",
            "<div class='f-c'>Example search</div>",
            "<div class='f-e'>";
        $oabbrev = option_search_term($o->name);
        if ($o->has_selector() && count($o->selector) > 1
            && $o->selector[1] !== "")
            $oabbrev .= "#" . strtolower(simplify_whitespace($o->selector[1]));
        if (strstr($oabbrev, " ") !== false)
            $oabbrev = "\"$oabbrev\"";
        echo "“<a href=\"", hoturl("search", "q=opt:" . urlencode($oabbrev)), "\">",
            "opt:", htmlspecialchars($oabbrev), "</a>”",
            "</div></div></td>";
    }

    echo "</tr>\n  <tr><td colspan='2'><table id='foldoptvis$id' class='fold2c fold3o'><tr>";

    echo "<td class='pad'><div class='f-i'><div class='f-c'>",
        setting_label("optvt$id", "Type"), "</div><div class='f-e'>";

    $optvt = $o->type;
    if ($optvt == "text" && @$o->display_space > 3)
        $optvt .= ":ds_" . $o->display_space;
    if (@$o->final)
        $optvt .= ":final";

    $show_final = $Conf->collectFinalPapers();
    foreach (PaperOption::option_list() as $ox)
        $show_final = $show_final || @$ox->final;

    $otypes = array();
    if ($show_final)
        $otypes["xxx1"] = array("optgroup", "Options for submissions");
    $otypes["checkbox"] = "Checkbox";
    $otypes["selector"] = "Selector";
    $otypes["radio"] = "Radio buttons";
    $otypes["numeric"] = "Numeric";
    $otypes["text"] = "Text";
    if ($o->type == "text" && @$o->display_space > 3 && $o->display_space != 5)
        $otypes[$optvt] = "Multiline text";
    else
        $otypes["text:ds_5"] = "Multiline text";
    $otypes["pdf"] = "PDF";
    $otypes["slides"] = "Slides";
    $otypes["video"] = "Video";
    $otypes["attachments"] = "Attachments";
    if ($show_final) {
        $otypes["xxx2"] = array("optgroup", "Options for accepted papers");
        $otypes["pdf:final"] = "Alternate final version";
        $otypes["slides:final"] = "Final slides";
        $otypes["video:final"] = "Final video";
    }
    echo Ht::select("optvt$id", $otypes, $optvt, array("onchange" => "do_option_type(this)", "id" => "optvt$id")),
        "</div></div></td>";
    $Conf->footerScript("do_option_type(\$\$('optvt$id'),true)");

    echo "<td class='fn2 pad'><div class='f-i'><div class='f-c'>",
        setting_label("optp$id", "Visibility"), "</div><div class='f-e'>",
        Ht::select("optp$id", array("admin" => "Administrators only", "rev" => "Visible to PC and reviewers", "nonblind" => "Visible if authors are visible"), $o->visibility, array("id" => "optp$id")),
        "</div></div></td>";

    echo "<td class='pad'><div class='f-i'><div class='f-c'>",
        setting_label("optfp$id", "Form order"), "</div><div class='f-e'>";
    $x = array();
    // can't use "foreach (PaperOption::option_list())" because caller
    // uses cursor
    for ($n = 0; $n < count(PaperOption::option_list()); ++$n)
        $x[$n + 1] = ordinal($n + 1);
    if ($id === "n")
        $x[$n + 1] = ordinal($n + 1);
    else
        $x["delete"] = "Delete option";
    echo Ht::select("optfp$id", $x, $o->position, array("id" => "optfp$id")),
        "</div></div></td>";

    echo "<td class='pad fn3'><div class='f-i'><div class='f-c'>",
        setting_label("optdt$id", "Display"), "</div><div class='f-e'>";
    echo Ht::select("optdt$id", array("normal" => "Normal",
                                      "highlight" => "Prominent",
                                      "near_submission" => "Near submission"),
                    $o->display_type(), array("id" => "optdt$id")),
        "</div></div></td>";

    if (isset($otypes["pdf:final"]))
        echo "<td class='pad fx2'><div class='f-i'><div class='f-c'>&nbsp;</div><div class='f-e hint' style='margin-top:0.7ex'>(Set by accepted authors during final version submission period)</div></div></td>";

    echo "</tr></table>";

    $rows = 3;
    if (PaperOption::type_has_selector($optvt) && count($o->selector)) {
        $value = join("\n", $o->selector) . "\n";
        $rows = max(count($o->selector), 3);
    } else
        $value = "";
    echo "<div id='foldoptv$id' class='", (PaperOption::type_has_selector($optvt) ? "foldo" : "foldc"),
        "'><div class='fx'>",
        "<div class='hint' style='margin-top:1ex'>Enter choices one per line.  The first choice will be the default.</div>",
        Ht::textarea("optv$id", $value, setting_js("optv$id", array("rows" => $rows, "cols" => 50))),
        "</div></div>";

    echo "</div></td></tr>\n";
}

function doOptGroup() {
    global $Conf, $Error;

    echo "<h3 class=\"settings\">Submission options</h3>\n";
    echo "Options are selected by authors at submission time.  Examples have included “PC-authored paper,” “Consider this paper for a Best Student Paper award,” and “Allow the shadow PC to see this paper.”  The “option name” should be brief (“PC paper,” “Best Student Paper,” “Shadow PC”).  The optional description can explain further and may use XHTML.  ";
    echo "Add options one at a time.\n";
    echo "<div class='g'></div>\n",
        Ht::hidden("has_options", 1),
        "<table>";
    $sep = "";
    $all_options = array_merge(PaperOption::option_list()); // get our own iterator
    foreach ($all_options as $o) {
        echo $sep;
        doOptGroupOption($o);
        $sep = "<tr><td colspan='2'><hr class='hr' /></td></tr>\n";
    }

    echo $sep;

    doOptGroupOption("n");

    echo "</table>\n";


    // Topics
    // load topic interests
    $qinterest = $Conf->query_topic_interest();
    $result = $Conf->q("select topicId, if($qinterest>0,1,0), count(*) from TopicInterest where $qinterest!=0 group by topicId, $qinterest>0");
    $interests = array();
    $ninterests = 0;
    while (($row = edb_row($result))) {
        if (!isset($interests[$row[0]]))
            $interests[$row[0]] = array();
        $interests[$row[0]][$row[1]] = $row[2];
        $ninterests += ($row[2] ? 1 : 0);
    }

    echo "<h3 class=\"settings g\">Topics</h3>\n";
    echo "Enter topics one per line.  Authors select the topics that apply to their papers; PC members use this information to find papers they'll want to review.  To delete a topic, delete its name.\n";
    echo "<div class='g'></div>",
        Ht::hidden("has_topics", 1),
        "<table id='newtoptable' class='", ($ninterests ? "foldo" : "foldc"), "'>";
    echo "<tr><th colspan='2'></th><th class='fx'><small>Low</small></th><th class='fx'><small>High</small></th></tr>";
    $td1 = '<td class="lcaption">Current</td>';
    foreach ($Conf->topic_map() as $tid => $tname) {
        if (count($Error) > 0 && isset($_POST["top$tid"]))
            $tname = $_POST["top$tid"];
        echo '<tr>', $td1, '<td class="lentry">',
            Ht::entry("top$tid", $tname, array("size" => 40, "style" => "width:20em")),
            '</td>';

        $tinterests = defval($interests, $tid, array());
        echo '<td class="fx rpentry">', (@$tinterests[0] ? '<span class="topic-2">' . $tinterests[0] . "</span>" : ""), "</td>",
            '<td class="fx rpentry">', (@$tinterests[1] ? '<span class="topic2">' . $tinterests[1] . "</span>" : ""), "</td>";

        if ($td1 !== "<td></td>") {
            // example search
            echo "<td class='llentry' style='vertical-align:top' rowspan='40'><div class='f-i'>",
                "<div class='f-c'>Example search</div>";
            $oabbrev = strtolower($tname);
            if (strstr($oabbrev, " ") !== false)
                $oabbrev = "\"$oabbrev\"";
            echo "“<a href=\"", hoturl("search", "q=topic:" . urlencode($oabbrev)), "\">",
                "topic:", htmlspecialchars($oabbrev), "</a>”",
                "<div class='hint'>Topic abbreviations are also allowed.</div>";
            if ($ninterests)
                echo "<a class='hint fn' href=\"#\" onclick=\"return fold('newtoptable')\">Show PC interest counts</a>",
                    "<a class='hint fx' href=\"#\" onclick=\"return fold('newtoptable')\">Hide PC interest counts</a>";
            echo "</div></td>";
        }
        echo "</tr>\n";
        $td1 = "<td></td>";
    }
    echo '<tr><td class="lcaption top" rowspan="40">New<br><span class="hint">Enter one topic per line.</span></td><td class="lentry top">',
        Ht::textarea("topnew", count($Error) ? @$_POST["topnew"] : "", array("cols" => 40, "rows" => 2, "style" => "width:20em")),
        '</td></tr></table>';
}

// Reviews
function echo_round($rnum, $nameval, $review_count, $deletable) {
    global $Conf, $Error;
    $rname = "roundname_$rnum";
    if (count($Error) && $rnum !== '$')
        $nameval = (string) @$_POST[$rname];

    $default_rname = "default";
    if ($nameval === "(new round)" || $rnum === '$')
        $default_rname = "(new round)";
    echo '<div class="mg" hotroundnum="', $rnum, '"><div>',
        setting_label($rname, "Round"), ' &nbsp;',
        render_entry($rname, $nameval, 12, $default_rname);
    echo '<div class="inb" style="min-width:7em;margin-left:2em">';
    if ($rnum !== '$' && $review_count)
        echo '<a href="', hoturl("search", "q=" . urlencode("round:" . ($rnum ? $Conf->round_name($rnum, false) : "none"))), '">(', plural($review_count, "review"), ')</a>';
    echo '</div>';
    if ($deletable)
        echo '<div class="inb" style="padding-left:2em">',
            Ht::hidden("deleteround_$rnum", ""),
            Ht::js_button("Delete round", "review_round_settings.kill(this)"),
            '</div>';
    echo '</div>';

    // deadlines
    $entrysuf = $rnum ? "_$rnum" : "";
    if ($rnum === '$' && count($Conf->round_list()))
        $dlsuf = "_" . (count($Conf->round_list()) - 1);
    else if ($rnum !== '$' && $rnum)
        $dlsuf = "_" . $rnum;
    else
        $dlsuf = "";
    echo '<table style="margin-left:3em">';
    echo '<tr><td>', setting_label("pcrev_soft$entrysuf", "PC deadline"), ' &nbsp;</td>',
        '<td class="lentry" style="padding-right:3em">',
        render_entry("pcrev_soft$entrysuf", date_value("pcrev_soft$dlsuf", "none"), 28, "none"),
        '</td><td class="lentry">', setting_label("pcrev_hard$entrysuf", "Hard deadline"), ' &nbsp;</td><td>',
        render_entry("pcrev_hard$entrysuf", date_value("pcrev_hard$dlsuf", "none"), 28, "none"),
        '</td></tr>';
    echo '<tr><td>', setting_label("extrev_soft$entrysuf", "External deadline"), ' &nbsp;</td>',
        '<td class="lentry" style="padding-right:3em">',
        render_entry("extrev_soft$entrysuf", date_value("extrev_soft$dlsuf", "same as PC", "pcrev_soft$dlsuf"), 28, "same as PC"),
        '</td><td class="lentry">', setting_label("extrev_hard$entrysuf", "Hard deadline"), ' &nbsp;</td><td>',
        render_entry("extrev_hard$entrysuf", date_value("extrev_hard$dlsuf", "same as PC", "pcrev_hard$dlsuf"), 28, "same as PC"),
        '</td></tr>';
    echo '</table></div>', "\n";
}

function doRevGroup() {
    global $Conf, $Error, $Highlight, $DateExplanation, $TagStyles;

    doCheckbox("rev_open", "<b>Open site for reviewing</b>");
    doCheckbox("cmt_always", "Allow comments even if reviewing is closed");

    echo "<div class='g'></div>\n";
    doCheckbox('pcrev_any', "PC members can review <strong>any</strong> submitted paper");

    echo "<div class='g'></div>\n";
    echo "<strong>Review anonymity:</strong> Are reviewer names hidden from authors?<br />\n";
    doRadio("rev_blind", array(Conference::BLIND_ALWAYS => "Yes—reviews are anonymous",
                               Conference::BLIND_NEVER => "No—reviewer names are visible to authors",
                               Conference::BLIND_OPTIONAL => "Depends—reviewers decide whether to expose their names"));

    echo "<div class='g'></div>\n";
    doCheckbox('rev_notifychair', 'Notify PC chairs of newly submitted reviews by email');


    // Deadlines
    echo "<h3 id=\"rounds\" class=\"settings g\">Deadlines &amp; rounds</h3>\n";
    $date_text = $DateExplanation;
    $DateExplanation = "";
    echo '<p class="hint">Reviews are due by the deadline, but <em>cannot be modified</em> after the hard deadline. Most conferences don’t use hard deadlines for reviews.<br />', $date_text, '</p>';

    $rounds = $Conf->round_list();
    if (count($Error) > 0) {
        for ($i = 1; isset($_POST["roundname_$i"]); ++$i)
            $rounds[$i] = @$_POST["deleteround_$i"] ? ";" : trim(@$_POST["roundname_$i"]);
    }

    // prepare round selector
    $round_value = trim(setting_data("rev_roundtag"));
    $current_round_value = $Conf->setting_data("rev_roundtag", "");
    if ($round_value === "" || strtolower($round_value) === "(none)" || strtolower($round_value) === "(no name)"
        || strtolower($round_value) === "default" || $round_value === "#0")
        $round_value = "#0";
    else if (($round_number = $Conf->round_number($round_value, false))
             || ($round_number = $Conf->round_number($current_round_value, false)))
        $round_value = "#" . $round_number;
    else
        $round_value = $selector[$current_round_value] = $current_round_value;

    $round_map = edb_map(Dbl::ql("select reviewRound, count(*) from PaperReview group by reviewRound"));

    $print_round0 = true;
    if ($round_value !== "#0" && $round_value !== ""
        && $current_round_value !== ""
        && (!count($Error) || isset($_POST["roundname_0"]))
        && !$Conf->round0_defined())
        $print_round0 = false;

    $selector = array();
    if ($print_round0)
        $selector["#0"] = "default";
    for ($i = 1; $i < count($rounds); ++$i)
        if ($rounds[$i] !== ";")
            $selector["#$i"] = (object) array("label" => $rounds[$i], "id" => "rev_roundtag_$i");

    echo '<div id="round_container"', (count($selector) == 1 ? ' style="display:none"' : ''), '>',
        '<table id="rev_roundtag_table"><tr><td class="lxcaption">',
        setting_label("rev_roundtag", "Current round"),
        '</td><td>',
        Ht::select("rev_roundtag", $selector, $round_value, setting_js("rev_roundtag")),
        '</td></tr></table>',
        '<div class="hint">This round is used for new assignments.</div><div class="g"></div></div>';

    echo '<div id="roundtable">';
    $num_printed = 0;
    for ($i = 0; $i < count($rounds); ++$i)
        if ($i ? $rounds[$i] !== ";" : $print_round0) {
            echo_round($i, $i ? $rounds[$i] : "", @+$round_map[$i], count($selector) !== 1);
            ++$num_printed;
        }
    echo '</div><div id="newround" style="display:none">';
    echo_round('$', "", "", true);
    echo '</div><div class="g"></div>';
    echo Ht::js_button("Add round", "review_round_settings.add();hiliter(this)"),
        ' &nbsp; <span class="hint"><a href="', hoturl("help", "t=revround"), '">What is this?</a></span>',
        Ht::hidden("oldroundcount", count($Conf->round_list())),
        Ht::hidden("has_rev_roundtag", 1);
    for ($i = 1; $i < count($rounds); ++$i)
        if ($rounds[$i] === ";")
            echo Ht::hidden("roundname_$i", "", array("id" => "roundname_$i")),
                Ht::hidden("deleteround_$i", 1);
    Ht::stash_script("review_round_settings.init()");


    // External reviews
    echo "<h3 class=\"settings g\">External reviews</h3>\n";

    echo "<div class='g'></div>";
    doCheckbox("extrev_chairreq", "PC chair must approve proposed external reviewers");
    doCheckbox("pcrev_editdelegate", "PC members can edit external reviews they requested");

    echo "<div class='g'></div>\n";
    $t = expandMailTemplate("requestreview", false);
    echo "<table id='foldmailbody_requestreview' class='",
        ($t == expandMailTemplate("requestreview", true) ? "foldc" : "foldo"),
        "'><tr><td>", foldbutton("mailbody_requestreview"), "</td>",
        "<td><a href='#' onclick='return fold(\"mailbody_requestreview\")' class='q'><strong>Mail template for external review requests</strong></a>",
        " <span class='fx'>(<a href='", hoturl("mail"), "'>keywords</a> allowed; set to empty for default)<br /></span>
<textarea class='tt fx' name='mailbody_requestreview' cols='80' rows='20'>", htmlspecialchars($t["body"]), "</textarea>",
        "</td></tr></table>\n";


    // Review visibility
    echo "<h3 class=\"settings g\">Visibility</h3>\n";

    echo "Can PC members <strong>see all reviews</strong> except for conflicts?<br />\n";
    doRadio("pc_seeallrev", array(Conference::PCSEEREV_YES => "Yes",
                                  Conference::PCSEEREV_UNLESSINCOMPLETE => "Yes, unless they haven’t completed an assigned review for the same paper",
                                  Conference::PCSEEREV_UNLESSANYINCOMPLETE => "Yes, after completing all their assigned reviews",
                                  Conference::PCSEEREV_IFCOMPLETE => "Only after completing a review for the same paper"));

    echo "<div class='g'></div>\n";
    echo "Can PC members see <strong>reviewer names</strong> except for conflicts?<br />\n";
    doRadio("pc_seeblindrev", array(0 => "Yes",
                                    1 => "Only after completing a review for the same paper<br /><span class='hint'>This setting also hides reviewer-only comments from PC members who have not completed a review for the paper.</span>"));

    echo "<div class='g'></div>";
    echo "Can external reviewers see the other reviews for their assigned papers, once they’ve submitted their own?<br />\n";
    doRadio("extrev_view", array(2 => "Yes", 1 => "Yes, but they can’t see who wrote blind reviews", 0 => "No"));


    // Review ratings
    echo "<h3 class=\"settings g\">Review ratings</h3>\n";

    echo "Should HotCRP collect ratings of reviews? &nbsp; <a class='hint' href='", hoturl("help", "t=revrate"), "'>(Learn more)</a><br />\n";
    doRadio("rev_ratings", array(REV_RATINGS_PC => "Yes, PC members can rate reviews", REV_RATINGS_PC_EXTERNAL => "Yes, PC members and external reviewers can rate reviews", REV_RATINGS_NONE => "No"));
}

// Review form
function doRfoGroup() {
    require_once("src/reviewsetform.php");
    rf_show();
}

// Tags and tracks
function do_track_permission($type, $question, $tnum, $thistrack) {
    global $Conf, $Error;
    $tclass = $ttag = "";
    if (count($Error) > 0) {
        $tclass = defval($_POST, "${type}_track$tnum", "");
        $ttag = defval($_POST, "${type}tag_track$tnum", "");
    } else if ($thistrack && @$thistrack->$type) {
        if ($thistrack->$type == "+none")
            $tclass = "none";
        else {
            $tclass = substr($thistrack->$type, 0, 1);
            $ttag = substr($thistrack->$type, 1);
        }
    }

    echo "<tr hotcrp_fold=\"1\" class=\"fold", ($tclass == "" || $tclass == "none" ? "c" : "o"), "\">",
        "<td class=\"lxcaption\">",
        setting_label("${type}_track$tnum", $question, "${type}_track$tnum"),
        "</td>",
        "<td>",
        Ht::select("${type}_track$tnum", array("" => "Whole PC", "+" => "PC members with tag", "-" => "PC members without tag", "none" => "Administrators only"), $tclass,
                   array("onchange" => "void foldup(this,event,{f:this.selectedIndex==0||this.selectedIndex==3})")),
        " &nbsp;",
        Ht::entry("${type}tag_track$tnum", $ttag,
                  array("class" => "fx",
                        "id" => "${type}tag_track$tnum",
                        "hottemptext" => "(tag)")),
        "</td></tr>";
}

function do_track($trackname, $tnum) {
    global $Conf;
    echo "<div id=\"trackgroup$tnum\"",
        ($tnum ? "" : " style=\"display:none\""),
        "><div class=\"trackname\" style=\"margin-bottom:3px\">";
    if ($trackname === "_")
        echo "For papers not on other tracks:", Ht::hidden("name_track$tnum", "_");
    else
        echo "For papers with tag &nbsp;",
            Ht::entry("name_track$tnum", $trackname, array("id" => "name_track$tnum", "hottemptext" => "(tag)")), ":";
    echo "</div>\n";

    $t = $Conf->setting_json("tracks");
    $t = $t && $trackname !== "" ? @$t->$trackname : null;
    echo "<table style=\"margin-left:1.5em;margin-bottom:0.5em\">";
    do_track_permission("view", "Who can view these papers?", $tnum, $t);
    do_track_permission("viewpdf", "Who can view PDFs?<br><span class=\"hint\">Assigned reviewers can always view PDFs.</span>", $tnum, $t);
    do_track_permission("viewrev", "Who can view reviews?", $tnum, $t);
    do_track_permission("assrev", "Who can be assigned a review?", $tnum, $t);
    do_track_permission("unassrev", "Who can self-assign a review?", $tnum, $t);
    echo "</table></div>\n\n";
}

function doTagsGroup() {
    global $Conf, $Error, $Highlight, $DateExplanation, $TagStyles;

    // Tags
    $tagger = new Tagger;
    echo "<h3 class=\"settings\">Tags</h3>\n";

    echo "<table><tr><td class='lxcaption'>", setting_label("tag_chair", "Chair-only tags"), "</td>";
    if (count($Error) > 0)
        $v = defval($_POST, "tag_chair", "");
    else
        $v = join(" ", array_keys(TagInfo::chair_tags()));
    echo "<td>", Ht::hidden("has_tag_chair", 1);
    doEntry("tag_chair", $v, 40, "");
    echo "<br /><div class='hint'>Only PC chairs can change these tags.  (PC members can still <i>view</i> the tags.)</div></td></tr>";

    echo "<tr><td class='lxcaption'>", setting_label("tag_vote", "Voting tags"), "</td>";
    if (count($Error) > 0)
        $v = defval($_POST, "tag_vote", "");
    else {
        $x = "";
        foreach (TagInfo::vote_tags() as $n => $v)
            $x .= "$n#$v ";
        $v = trim($x);
    }
    echo "<td>", Ht::hidden("has_tag_vote", 1);
    doEntry("tag_vote", $v, 40);
    echo "<br /><div class='hint'>“vote#10” declares a voting tag named “vote” with an allotment of 10 votes per PC member. <span class='barsep'>·</span> <a href='", hoturl("help", "t=votetags"), "'>What is this?</a></div></td></tr>";

    echo "<tr><td class='lxcaption'>", setting_label("tag_rank", "Ranking tag"), "</td>";
    if (count($Error) > 0)
        $v = defval($_POST, "tag_rank", "");
    else
        $v = $Conf->setting_data("tag_rank", "");
    echo "<td>", Ht::hidden("has_tag_rank", 1);
    doEntry("tag_rank", $v, 40);
    echo "<br /><div class='hint'>The <a href='", hoturl("offline"), "'>offline reviewing page</a> will expose support for uploading rankings by this tag. <span class='barsep'>·</span> <a href='", hoturl("help", "t=ranking"), "'>What is this?</a></div></td></tr>";
    echo "</table>";

    echo "<div class='g'></div>\n";
    doCheckbox('tag_seeall', "PC can see tags for conflicted papers");

    preg_match_all('_(\S+)=(\S+)_', $Conf->setting_data("tag_color", ""), $m,
                   PREG_SET_ORDER);
    $tag_colors = array();
    foreach ($m as $x)
        $tag_colors[TagInfo::canonical_color($x[2])][] = $x[1];
    $tag_colors_open = 0;
    $tag_colors_rows = array();
    foreach (explode("|", $TagStyles) as $k) {
        if (count($Error) > 0)
            $v = defval($_POST, "tag_color_$k", "");
        else if (isset($tag_colors[$k]))
            $v = join(" ", $tag_colors[$k]);
        else
            $v = "";
        $tag_colors_open += ($v !== "");
        $tag_colors_rows[] = "<tr class='k0 ${k}tag'><td class='lxcaption'></td><td class='lxcaption'>$k</td><td class='lentry' style='font-size: 10.5pt'><input type='text' name='tag_color_$k' value=\"" . htmlspecialchars($v) . "\" size='40' /></td></tr>"; /* MAINSIZE */
    }
    echo "<div class='g'></div>\n";
    echo "<table id='foldtag_color' class='",
        ($tag_colors_open ? "foldo" : "foldc"), "'><tr>",
        "<td>", foldbutton("tag_color"), Ht::hidden("has_tag_color", 1), "</td>",
        "<td><a href='#' onclick='return fold(\"tag_color\")' name='tagcolor' class='q'><strong>Styles and colors</strong></a><br />\n",
        "<div class='hint fx'>Papers tagged with a style name, or with one of the associated tags (if any), will appear in that style in paper lists.</div>",
        "<div class='smg fx'></div>",
        "<table class='fx'><tr><th colspan='2'>Style name</th><th>Tags</th></tr>",
        join("", $tag_colors_rows), "</table></td></tr></table>\n";


    echo '<h3 class="settings g">Tracks</h3>', "\n";
    echo "<div class='hint'>Tracks control the PC members allowed to view or review different sets of papers. <span class='barsep'>·</span> <a href=\"" . hoturl("help", "t=tracks") . "\">What is this?</a></div>",
        Ht::hidden("has_tracks", 1),
        "<div class=\"smg\"></div>\n";
    do_track("", 0);
    $tracknum = 2;
    if (($trackj = $Conf->setting_json("tracks")))
        foreach ($trackj as $trackname => $x)
            if ($trackname !== "_") {
                do_track($trackname, $tracknum);
                ++$tracknum;
            }
    do_track("_", 1);
    echo Ht::button("Add track", array("onclick" => "settings_add_track()"));
}


// Responses and decisions
function doDecGroup() {
    global $Conf, $Highlight, $Error;

    echo "Can <b>authors see reviews and author-visible comments</b> for their papers?<br />";
    if ($Conf->setting("resp_active"))
        $no_text = "No, unless responses are open";
    else
        $no_text = "No";
    if (!$Conf->setting("au_seerev", 0)
        && $Conf->timeAuthorViewReviews())
        $no_text .= '<div class="hint">Authors are currently able to see reviews since responses are open.</div>';
    doRadio("au_seerev", array(AU_SEEREV_NO => $no_text, AU_SEEREV_ALWAYS => "Yes", AU_SEEREV_YES => "Yes, once they’ve completed any requested reviews"));

    // Authors' response
    echo '<div class="g"></div><table id="foldauresp" class="fold2o">';
    doCheckbox('resp_active', "<b>Collect authors’ responses to the reviews<span class='fx2'>:</span></b>", true, "void fold('auresp',!this.checked,2)");
    echo '<tr class="fx2"><td></td><td><div id="auresparea">',
        Ht::hidden("has_resp_rounds", 1);

    // Response rounds
    if (count($Error)) {
        $rrounds = array(1);
        for ($i = 1; isset($_POST["resp_roundname_$i"]); ++$i)
            $rrounds[$i] = $_POST["resp_roundname_$i"];
    } else
        $rrounds = $Conf->resp_round_list();
    $rrounds["n"] = "";
    foreach ($rrounds as $i => $rname) {
        $isuf = $i ? "_$i" : "";
        echo '<div id="response', $isuf;
        if ($i)
            echo '" style="padding-top:1em';
        if ($i === "n")
            echo ';display:none';
        echo '"><table>';
        if (!$i) {
            $rname = $rname == "1" ? "none" : $rname;
            doTextRow("resp_roundname$isuf", "Response name", $rname, 20, "lxcaption", "none");
        } else
            doTextRow("resp_roundname$isuf", "Response name", $rname, 20, "lxcaption");
        if (setting("resp_open$isuf") === 1 && ($x = setting("resp_done$isuf")))
            $Conf->settings["resp_open$isuf"] = $x - 7 * 86400;
        doDateRow("resp_open$isuf", "Start time", null, "lxcaption");
        doDateRow("resp_done$isuf", "Hard deadline", null, "lxcaption");
        doGraceRow("resp_grace$isuf", "Grace period", "lxcaption");
        doTextRow("resp_words$isuf", array("Word limit", $i ? null : "This is a soft limit: authors may submit longer responses. 0 means no limit."),
                  setting("resp_words$isuf", 500), 5, "lxcaption", "none");
        echo '</table><div style="padding-top:4px">';
        do_message(array("msg.resp_instrux$isuf", "msg.resp_instrux"), "Instructions", 1, 3);
        echo '</div></div>', "\n";
    }

    echo '</div><div style="padding-top:1em">',
        '<button type="button" onclick="settings_add_resp_round()">Add response round</button>',
        '</div></div></td></tr></table>';
    $Conf->footerScript("fold('auresp',!\$\$('cbresp_active').checked,2)");

    echo "<div class='g'></div>\n<hr class='hr' />\n",
        "Who can see paper <b>decisions</b> (accept/reject)?<br />\n";
    doRadio("seedec", array(Conference::SEEDEC_ADMIN => "Only administrators",
                            Conference::SEEDEC_NCREV => "Reviewers and non-conflicted PC members",
                            Conference::SEEDEC_REV => "Reviewers and <em>all</em> PC members",
                            Conference::SEEDEC_ALL => "<b>Authors</b>, reviewers, and all PC members (and reviewers can see accepted papers’ author lists)"));

    echo "<div class='g'></div>\n";
    echo "<table>\n";
    $decs = $Conf->decision_map();
    krsort($decs);

    // count papers per decision
    $decs_pcount = array();
    $result = $Conf->qe("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
    while (($row = edb_row($result)))
        $decs_pcount[$row[0]] = $row[1];

    // real decisions
    $n_real_decs = 0;
    foreach ($decs as $k => $v)
        $n_real_decs += ($k ? 1 : 0);
    $caption = "<td class='lcaption' rowspan='$n_real_decs'>Current decision types</td>";
    foreach ($decs as $k => $v)
        if ($k) {
            if (count($Error) > 0)
                $v = defval($_POST, "dec$k", $v);
            echo "<tr>", $caption, '<td class="lentry nw">',
                Ht::entry("dec$k", $v, array("size" => 35)),
                " &nbsp; ", ($k > 0 ? "Accept class" : "Reject class"), "</td>";
            if (isset($decs_pcount[$k]) && $decs_pcount[$k])
                echo '<td class="lentry nw">', plural($decs_pcount[$k], "paper"), "</td>";
            echo "</tr>\n";
            $caption = "";
        }

    // new decision
    $v = "";
    $vclass = 1;
    if (count($Error) > 0) {
        $v = defval($_POST, "decn", $v);
        $vclass = defval($_POST, "dtypn", $vclass);
    }
    echo '<tr><td class="lcaption">',
        setting_label("decn", "New decision type"),
        '<br /></td>',
        '<td class="lentry nw">',
        Ht::hidden("has_decisions", 1),
        Ht::entry("decn", $v, array("size" => 35)), ' &nbsp; ',
        Ht::select("dtypn", array(1 => "Accept class", -1 => "Reject class"), $vclass),
        "<br /><small>Examples: “Accepted as short paper”, “Early reject”</small>",
        "</td></tr>";
    if (defval($Highlight, "decn"))
        echo '<tr><td></td><td class="lentry nw">',
            Ht::checkbox("decn_confirm", 1, false),
            '&nbsp;<span class="error">', Ht::label("Confirm"), "</span></td></tr>";
    echo "</table>\n";

    // Final versions
    echo "<h3 class=\"settings g\">Final versions</h3>\n";
    echo '<table id="foldfinal" class="fold2o">';
    doCheckbox('final_open', '<b>Collect final versions of accepted papers<span class="fx">:</span></b>', true, "void fold('final',!this.checked,2)");
    echo "<tr class='fx2'><td></td><td><table>";
    doDateRow("final_soft", "Deadline", "final_done", "lxcaption");
    doDateRow("final_done", "Hard deadline", null, "lxcaption");
    doGraceRow("final_grace", "Grace period", "lxcaption");
    echo "</table><div class='g'></div>";
    do_message("msg.finalsubmit", "Instructions", 1);
    echo "<div class='g'></div>",
        "<small>To collect <em>multiple</em> final versions, such as one in 9pt and one in 11pt, add “Alternate final version” options via <a href='", hoturl("settings", "group=opt"), "'>Settings &gt; Submission options</a>.</small>",
        "</div></td></tr></table>\n\n";
    $Conf->footerScript("fold('final',!\$\$('cbfinal_open').checked)");
}



$Conf->header("Settings", "settings", actionBar());
$Conf->echoScript(""); // clear out other script references
echo $Conf->make_script_file("scripts/settings.js"), "\n";

echo Ht::form(hoturl_post("settings"), array("id" => "settingsform")), "<div>",
    Ht::hidden("group", $Group);

echo "<table class='settings'><tr><td class='caption initial final'>";
echo "<table class='lhsel'>";
foreach (array("info" => "Conference information",
               "users" => "Accounts",
               "msg" => "Messages",
               "sub" => "Submissions",
               "opt" => "Submission options",
               "reviews" => "Reviews",
               "reviewform" => "Review form",
               "tags" => "Tags &amp; tracks",
               "dec" => "Decisions") as $k => $v) {
    echo "<tr><td>";
    if ($Group == $k)
        echo "<div class='lhl1'><a class='q' href='", hoturl("settings", "group=$k"), "'>$v</a></div>";
    else
        echo "<div class='lhl0'><a href='", hoturl("settings", "group=$k"), "'>$v</a></div>";
    echo "</td></tr>";
}
echo "</table></td><td class='top'><div class='lht'>";

echo "<div class='aahc'>";
doActionArea(true);
echo "<div>";

if ($Group == "info")
    doInfoGroup();
else if ($Group == "users")
    doAccGroup();
else if ($Group == "msg")
    doMsgGroup();
else if ($Group == "sub")
    doSubGroup();
else if ($Group == "opt")
    doOptGroup();
else if ($Group == "reviews")
    doRevGroup();
else if ($Group == "reviewform")
    doRfoGroup();
else if ($Group == "tags")
    doTagsGroup();
else {
    if ($Group != "dec")
        error_log("bad settings group $Group");
    doDecGroup();
}

echo "</div>";
doActionArea(false);
echo "</div></div></td></tr>
</table></div></form>\n";

$Conf->footerScript("hiliter_children('#settingsform');jQuery('textarea').autogrow()");
$Conf->footer();
