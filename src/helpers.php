<?php
// helpers.php -- HotCRP non-class helper functions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function defappend(&$var, $str) {
    if (!isset($var))
        $var = "";
    $var .= $str;
}

function arrayappend(&$var, $value) {
    if (isset($var))
        $var[] = $value;
    else
        $var = array($value);
}

function mkarray($value) {
    if (is_array($value))
        return $value;
    else
        return array($value);
}

function set_error_html($x, $error_html = null) {
    if (!$error_html) {
        $error_html = $x;
        $x = (object) array();
    }
    $x->error = true;
    $x->error_html = $error_html;
    return $x;
}


// string helpers

function cvtint($value, $default = -1) {
    $v = trim((string) $value);
    if (is_numeric($v)) {
        $ival = intval($v);
        if ($ival == floatval($v))
            return $ival;
    }
    return $default;
}

function cvtnum($value, $default = -1) {
    $v = trim((string) $value);
    if (is_numeric($v))
        return floatval($v);
    return $default;
}


// web helpers

global $_hoturl_defaults;
$_hoturl_defaults = null;

function hoturl_defaults($options = array()) {
    global $_hoturl_defaults;
    foreach ($options as $k => $v)
        if ($v !== null)
            $_hoturl_defaults[$k] = urlencode($v);
        else
            unset($_hoturl_defaults[$k]);
    $ret = array();
    if ($_hoturl_defaults)
        foreach ($_hoturl_defaults as $k => $v)
            $ret[$k] = urldecode($v);
    return $ret;
}

function hoturl_site_relative($page, $options = null) {
    global $Conf, $Me, $_hoturl_defaults;
    $t = $page . Navigation::php_suffix();
    // parse options, separate anchor; see also redirectSelf
    $anchor = "";
    if ($options && is_array($options)) {
        $x = "";
        foreach ($options as $k => $v)
            if ($v !== null && $k !== "anchor")
                $x .= ($x === "" ? "" : "&amp;") . $k . "=" . urlencode($v);
            else if ($v !== null)
                $anchor = "#" . urlencode($v);
        $options = $x;
    } else if (preg_match('/\A(.*?)(#.*)\z/', $options, $m))
        list($options, $anchor) = array($m[1], $m[2]);
    // append defaults
    $are = '/\A(|.*?(?:&|&amp;))';
    $zre = '(?:&(?:amp;)?|\z)(.*)\z/';
    if ($_hoturl_defaults)
        foreach ($_hoturl_defaults as $k => $v)
            if (!preg_match($are . preg_quote($k) . '=/', $options))
                $options .= "&amp;" . $k . "=" . $v;
    // append forceShow to links to same paper if appropriate
    $is_paper_page = preg_match('/\A(?:paper|review|comment|assign)\z/', $page);
    if ($is_paper_page && $Conf->paper
        && preg_match($are . 'p=' . $Conf->paper->paperId . $zre, $options)
        && $Me->can_administer($Conf->paper)
        && $Conf->paper->has_conflict($Me)
        && !preg_match($are . 'forceShow=/', $options))
        $options .= "&amp;forceShow=1";
    // create slash-based URLs if appropriate
    if ($options) {
        if ($page == "review"
            && preg_match($are . 'r=(\d+[A-Z]+)' . $zre, $options, $m)) {
            $t .= "/" . $m[2];
            $options = $m[1] . $m[3];
            if (preg_match($are . 'p=\d+' . $zre, $options, $m))
                $options = $m[1] . $m[2];
        } else if ($page == "paper"
                   && preg_match($are . 'p=(\d+|%\w+%|new)' . $zre, $options, $m)
                   && preg_match($are . 'm=(\w+)' . $zre, $m[1] . $m[3], $m2)) {
            $t .= "/" . $m[2] . "/" . $m2[2];
            $options = $m2[1] . $m2[3];
        } else if (($is_paper_page
                    && preg_match($are . 'p=(\d+|%\w+%|new)' . $zre, $options, $m))
                   || ($page == "profile"
                       && preg_match($are . 'u=([^&?]+)' . $zre, $options, $m))
                   || ($page == "help"
                       && preg_match($are . 't=(\w+)' . $zre, $options, $m))
                   || ($page == "settings"
                       && preg_match($are . 'group=(\w+)' . $zre, $options, $m))
                   || ($page == "graph"
                       && preg_match($are . 'g=([^&?]+)' . $zre, $options, $m))
                   || ($page == "doc"
                       && preg_match($are . 'file=([^&]+)' . $zre, $options, $m))
                   || preg_match($are . '__PATH__=([^&]+)' . $zre, $options, $m)) {
            $t .= "/" . str_replace("%2F", "/", $m[2]);
            $options = $m[1] . $m[3];
        }
        $options = preg_replace('/&(?:amp;)?\z/', "", $options);
    }
    if ($options && preg_match('/\A&(?:amp;)?(.*)\z/', $options, $m))
        $options = $m[1];
    if ($options)
        return $t . "?" . $options . $anchor;
    else
        return $t . $anchor;
}

function hoturl($page, $options = null) {
    $siteurl = Navigation::siteurl();
    $t = hoturl_site_relative($page, $options);
    if ($page !== "index")
        return $siteurl . $t;
    $trail = substr($t, 5 + strlen(Navigation::php_suffix()));
    if (@$trail[0] === "/")
        return $siteurl . $t;
    else if ($siteurl !== "")
        return $siteurl . $trail;
    else
        return Navigation::site_path() . $trail;
}

function hoturl_post($page, $options = null) {
    if (is_array($options))
        $options["post"] = post_value();
    else if ($options)
        $options .= "&amp;post=" . post_value();
    else
        $options = "post=" . post_value();
    return hoturl($page, $options);
}

function hoturl_absolute($page, $options = null) {
    return opt("paperSite") . "/" . hoturl_site_relative($page, $options);
}

function hoturl_absolute_nodefaults($page, $options = null) {
    global $_hoturl_defaults;
    $defaults = $_hoturl_defaults;
    $_hoturl_defaults = null;
    $url = hoturl_absolute($page, $options);
    $_hoturl_defaults = $defaults;
    return $url;
}

function hoturl_site_relative_raw($page, $options = null) {
    return htmlspecialchars_decode(hoturl_site_relative($page, $options));
}

function hoturl_raw($page, $options = null) {
    return htmlspecialchars_decode(hoturl($page, $options));
}

function hoturl_post_raw($page, $options = null) {
    return htmlspecialchars_decode(hoturl_post($page, $options));
}

function hoturl_absolute_raw($page, $options = null) {
    return htmlspecialchars_decode(hoturl_absolute($page, $options));
}


function fileUploaded(&$var) {
    global $Conf;
    if (!isset($var) || ($var['error'] != UPLOAD_ERR_OK && !$Conf))
        return false;
    switch ($var['error']) {
    case UPLOAD_ERR_OK:
        return is_uploaded_file($var['tmp_name'])
            || (PHP_SAPI === "cli" && get($var, "tmp_name_safe"));
    case UPLOAD_ERR_NO_FILE:
        return false;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        Conf::msg_error("You tried to upload a file that’s too big for our system to accept.  The maximum size is " . ini_get("upload_max_filesize") . "B.");
        return false;
    case UPLOAD_ERR_PARTIAL:
        Conf::msg_error("You appear to have interrupted the upload process; I am not storing that file.");
        return false;
    default:
        Conf::msg_error("Internal upload error " . $var['error'] . "!");
        return false;
    }
}

function selfHref($extra = array(), $options = null) {
    // clean parameters from pathinfo URLs
    foreach (array("paperId" => "p", "pap" => "p", "reviewId" => "r", "commentId" => "c") as $k => $v)
        if (isset($_REQUEST[$k]) && !isset($_REQUEST[$v]))
            $_REQUEST[$v] = $_REQUEST[$k];

    $param = "";
    foreach (array("p", "r", "c", "m", "u", "g", "fx", "fy", "mode", "forceShow", "validator", "ls", "list", "t", "q", "qa", "qo", "qx", "qt", "tab", "atab", "group", "sort", "monreq", "noedit", "contact", "reviewer") as $what)
        if (isset($_REQUEST[$what]) && !array_key_exists($what, $extra)
            && !is_array($_REQUEST[$what]))
            $param .= "&$what=" . urlencode($_REQUEST[$what]);
    foreach ($extra as $key => $value)
        if ($key != "anchor" && $value !== null)
            $param .= "&$key=" . urlencode($value);
    if (!isset($_REQUEST["ls"]) && !array_key_exists("ls", $extra)
        && ($list = SessionList::active()))
        $param .= "&ls=" . $list->listno;

    $param = $param ? substr($param, 1) : "";
    if (!$options || !@$options["site_relative"])
        $uri = hoturl(Navigation::page(), $param);
    else
        $uri = hoturl_site_relative(Navigation::page(), $param);
    if (isset($extra["anchor"]))
        $uri .= "#" . $extra["anchor"];
    $uri = str_replace("&amp;", "&", $uri);
    if (!$options || @$options["raw"])
        return $uri;
    else
        return htmlspecialchars($uri);
}

function redirectSelf($extra = array()) {
    go(selfHref($extra, array("raw" => true)));
}

class JsonResultException extends Exception {
    public $result;
    static public $capturing = false;
    function __construct($j) {
        $this->result = $j;
    }
}

function json_exit($json, $div = false) {
    global $Conf;
    if (JsonResultException::$capturing)
        throw new JsonResultException($json);
    else
        $Conf->ajaxExit($json, $div);
}

function foldbutton($foldtype, $foldnum = 0, $content = "") {
    $foldnumid = ($foldnum ? ",$foldnum" : "");
    return '<a href="#" class="q" onclick="return fold(\''
        . $foldtype . '\',null' . $foldnumid . ')">'
        . expander(null, $foldnum) . $content . '</a>';
}

function expander($open, $foldnum = null) {
    $f = $foldnum !== null;
    $foldnum = ($foldnum !== 0 ? $foldnum : "");
    $t = '<span class="expander">';
    if ($open === null || !$open)
        $t .= '<span class="in0' . ($f ? " fx$foldnum" : "") . '">&#x25BC;</span>';
    if ($open === null || $open)
        $t .= '<span class="in1' . ($f ? " fn$foldnum" : "") . '">&#x25B6;</span>';
    return $t . '</span>';
}

function reviewType($paperId, $row, $long = 0) {
    if ($row->reviewType == REVIEW_PRIMARY)
        return "<span class='rtype rtype_pri'>Primary</span>";
    else if ($row->reviewType == REVIEW_SECONDARY)
        return "<span class='rtype rtype_sec'>Secondary</span>";
    else if ($row->reviewType == REVIEW_EXTERNAL)
        return "<span class='rtype rtype_req'>External</span>";
    else if ($row->conflictType >= CONFLICT_AUTHOR)
        return "<span class='author'>Author</span>";
    else if ($row->conflictType > 0)
        return "<span class='conflict'>Conflict</span>";
    else if (!($row->reviewId === null) || $long)
        return "<span class='rtype rtype_pc'>PC</span>";
    else
        return "";
}

function topicTable($prow, $active = 0) {
    global $Conf;
    $paperId = ($prow ? $prow->paperId : -1);

    // get current topics
    $paperTopic = array();
    $tmap = $Conf->topic_map();
    if ($paperId > 0) {
        $result = Dbl::q("select topicId from PaperTopic where paperId=$paperId");
        while ($row = edb_row($result))
            $paperTopic[$row[0]] = $tmap[$row[0]];
    }
    $allTopics = ($active < 0 ? $paperTopic : $tmap);
    if (count($allTopics) == 0)
        return "";

    $out = '<div class="ctable">';
    $i = 0;
    foreach ($tmap as $tid => $tname) {
        if (!isset($allTopics[$tid]))
            continue;
        $out .= '<div class="ctelt"><div class="ctelti">';
        $tname = '<span class="topic0">' . htmlspecialchars($tname) . '</span>';
        if ($paperId <= 0 || $active >= 0) {
            $out .= '<table><tr><td class="nw">'
                . Ht::checkbox_h("top$tid", 1, ($active > 0 ? isset($_REQUEST["top$tid"]) : isset($paperTopic[$tid])),
                                 array("disabled" => $active < 0))
                . "&nbsp;</td><td>" . Ht::label($tname) . "</td></tr></table>";
        } else
            $out .= $tname;
        $out .= "</div></div>\n";
        $i++;
    }
    return $out . "</div>";
}

function actas_link($cid, $contact = null) {
    global $Conf;
    $contact = !$contact && is_object($cid) ? $cid : $contact;
    $cid = is_object($contact) ? $contact->email : $cid;
    return '<a href="' . selfHref(array("actas" => $cid))
        . '">' . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($contact))) . '</a>';
}

function decorateNumber($n) {
    if ($n < 0)
        return "&#8722;" . (-$n);
    else if ($n > 0)
        return $n;
    else
        return 0;
}


class SessionList {
    static private $active_listid = null;
    static private $active_list = null;
    static private $requested_list = false;
    static public function decode_ids($ids) {
        if (strpos($ids, "-") === false && ($a = json_decode($ids)) !== null)
            return $a;
        $a = [];
        preg_match_all('/[-\d]+/', $ids, $m);
        foreach ($m[0] as $p)
            if (($pos = strpos($p, "-"))) {
                $j = (int) substr($p, $pos + 1);
                for ($i = (int) substr($p, 0, $pos); $i <= $j; ++$i)
                    $a[] = $i;
            } else
                $a[] = (int) $p;
        return $a;
    }
    static public function encode_ids($ids) {
        if (count($ids) < 30)
            return json_encode($ids);
        $a = array();
        $p0 = $p1 = -100;
        foreach ($ids as $p) {
            if ($p1 + 1 != $p) {
                if ($p0 > 0)
                    $a[] = ($p0 == $p1 ? $p0 : "$p0-$p1");
                $p0 = $p;
            }
            $p1 = $p;
        }
        if ($p0 > 0)
            $a[] = ($p0 == $p1 ? $p0 : "$p0-$p1");
        return "[" . join(",", $a) . "]";
    }
    static function lookup($idx) {
        global $Conf, $Me;
        $lists = $Conf->session("l", array());
        $l = get($lists, $idx);
        if ($l && $l->cid == ($Me ? $Me->contactId : 0)) {
            $l = clone $l;
            if (is_string($l->ids))
                $l->ids = self::decode_ids($l->ids);
            $l->listno = (int) $idx;
            return $l;
        } else
            return null;
    }
    static function change($idx, $l) {
        global $Conf;
        $l = is_object($l) ? get_object_vars($l) : $l;
        if (isset($l["ids"]) && !is_string($l["ids"]))
            $l["ids"] = self::encode_ids($l["ids"]);
        $Conf->save_session_array("l", $idx, (object) $l);
        return true;
    }
    static function allocate($listid) {
        global $Conf, $Me;
        $lists = $Conf->session("l", array());
        $cid = $Me ? $Me->contactId : 0;
        $oldest = $empty = 0;
        for ($i = 1; $i <= 8; ++$i)
            if (($l = get($lists, $i))) {
                if (!isset($l->timestamp))
                    error_log("missing timestamp " . json_encode($l));
                if ($listid && $l->listid == $listid && $l->cid == $cid)
                    return $i;
                else if (!$oldest || $l->timestamp < $lists[$oldest]->timestamp)
                    $oldest = $i;
            } else if (!$empty)
                $empty = $i;
        return $empty ? : $oldest;
    }
    static function create($listid, $ids, $description, $url) {
        global $Me, $Now;
        return (object) array("listid" => $listid, "ids" => $ids,
                              "description" => $description,
                              "url" => $url, "timestamp" => $Now,
                              "cid" => $Me ? $Me->contactId : 0);
    }
    static private function try_list($opt, $listtype, $sort = null) {
        global $Conf, $Me;
        if ($listtype == "u" && $Me->privChair) {
            $searchtype = (defval($opt, "t") === "all" ? "all" : "pc");
            $q = "select contactId from ContactInfo";
            if ($searchtype == "pc")
                $q .= " where (roles&" . Contact::ROLE_PC . ")!=0";
            $result = Dbl::ql("$q order by lastName, firstName, email");
            $a = array();
            while (($row = edb_row($result)))
                $a[] = (int) $row[0];
            Dbl::free($result);
            return self::create("u/" . $searchtype, $a,
                                ($searchtype == "pc" ? "Program committee" : "Users"),
                                hoturl_site_relative_raw("users", "t=$searchtype"));
        } else {
            $search = new PaperSearch($Me, $opt);
            $x = $search->session_list_object($sort);
            if ($sort || $search->has_sort()) {
                $pl = new PaperList($search, array("sort" => $sort));
                $x->ids = $pl->id_array();
            }
            return $x;
        }
    }
    static public function set_requested($listno) {
        global $Now;
        if ($listno)
            setcookie("hotcrp_ls", $listno, $Now + 2, Navigation::site_path());
        else if (isset($_COOKIE["hotcrp_ls"]))
            setcookie("hotcrp_ls", "", $Now - 86400, Navigation::site_path());
    }
    static public function requested() {
        global $Me;
        if (self::$requested_list === false) {
            // look up list ID
            $listdesc = req("ls");
            if (isset($_COOKIE["hotcrp_ls"]))
                $listdesc = $listdesc ? : $_COOKIE["hotcrp_ls"];

            $list = null;
            if (($listno = cvtint($listdesc, null))
                && ($xlist = self::lookup($listno))
                && (!get($xlist, "cid") || $xlist->cid == ($Me ? $Me->contactId : 0)))
                $list = $xlist;

            // look up list description
            if (!$list && $listdesc) {
                $listtype = "p";
                if (Navigation::page() === "profile" || Navigation::page() === "users")
                    $listtype = "u";
                if (preg_match('_\Ap/([^/]*)/([^/]*)/?(.*)\z_', $listdesc, $m))
                    $list = self::try_list(["t" => $m[1], "q" => urldecode($m[2])],
                                           "p", $m[3]);
                if (!$list && preg_match('/\A(all|s):(.*)\z/s', $listdesc, $m))
                    $list = self::try_list(["t" => $m[1], "q" => $m[2]], "p");
                if (!$list && preg_match('/\A[a-z]+\z/', $listdesc))
                    $list = self::try_list(["t" => $listdesc], $listtype);
                if (!$list)
                    $list = self::try_list(["q" => $listdesc], $listtype);
            }

            self::$requested_list = $list;
        }
        return self::$requested_list;
    }
    static public function active($listtype = null, $id = null) {
        global $Conf, $Me, $Now;

        // check current-list cache
        if (!$listtype && self::$active_list)
            return self::$active_list;
        else if (!$listtype) {
            $listtype = "p";
            $id = $Conf->paper ? $Conf->paper->paperId : null;
        }
        if (!$id)
            return null;
        $listid = "$id/$listtype";
        if (self::$active_listid === $listid)
            return self::$active_list;

        // start with requested list
        $list = self::requested();
        if ($list && !str_starts_with(get_s($list, "listid"), $listtype))
            $list = null;

        // look up ID in list; try new lists if not found
        $k = false;
        if ($list)
            $k = array_search($id, $list->ids);
        if ($k === false) {
            $list = self::try_list([], $listtype);
            $k = array_search($id, $list->ids);
        }
        if ($k === false && $Me->privChair) {
            $list = self::try_list(["t" => "all"], $listtype);
            $k = array_search($id, $list->ids);
        }
        if ($k === false)
            $list = null;

        // save list changes
        if ($list && !get($list, "listno"))
            $list->listno = self::allocate($list->listid);
        if ($list) {
            $list->timestamp = $Now;
            self::change($list->listno, $list);
            $list->id_position = $k;
        }
        self::$active_listid = $listid;
        self::$active_list = $list;
        return $list;
    }
}

function _one_quicklink($id, $baseUrl, $urlrest, $listtype, $isprev) {
    global $Conf;
    if ($listtype == "u") {
        $result = Dbl::ql("select email from ContactInfo where contactId=?", $id);
        $row = edb_row($result);
        Dbl::free($result);
        $paperText = htmlspecialchars($row ? $row[0] : $id);
        $urlrest["u"] = urlencode($id);
    } else {
        $paperText = "#$id";
        $urlrest["p"] = $id;
    }
    return "<a id=\"quicklink_" . ($isprev ? "prev" : "next")
        . "\" class=\"x\" href=\"" . hoturl($baseUrl, $urlrest)
        . "\" onclick=\"return !Miniajax.isoutstanding('revprevform', make_link_callback(this))\">"
        . ($isprev ? Ht::img("_.gif", "<-", "prev") : "")
        . $paperText
        . ($isprev ? "" : Ht::img("_.gif", "->", "next"))
        . "</a>";
}

function quicklinks($id, $baseUrl, $args, $listtype) {
    global $Me, $Conf;

    $list = SessionList::active($listtype, $id);
    if (!$list)
        return "";

    $args["ls"] = null;
    $x = '<td class="quicklinks nw has_hotcrp_list" data-hotcrp-list="' . $list->listno . '">';
    if ($list->id_position > 0)
        $x .= _one_quicklink($list->ids[$list->id_position - 1], $baseUrl, $args, $listtype, true);
    if (@$list->description) {
        $x .= ($list->id_position > 0 ? "&nbsp;&nbsp;" : "");
        if (@$list->url)
            $x .= '<a id="quicklink_list" class="x" href="' . htmlspecialchars(Navigation::siteurl() . $list->url) . "\">" . $list->description . "</a>";
        else
            $x .= '<span id="quicklink_list">' . $list->description . '</span>';
    }
    if (isset($list->ids[$list->id_position + 1])) {
        $x .= ($list->id_position > 0 || @$list->description ? "&nbsp;&nbsp;" : "");
        $x .= _one_quicklink($list->ids[$list->id_position + 1], $baseUrl, $args, $listtype, false);
    }
    return $x . '</td>';
}

function goPaperForm($baseUrl = null, $args = array()) {
    global $Conf, $Me;
    if ($Me->is_empty())
        return "";
    $list = SessionList::active();
    $x = Ht::form_div(hoturl($baseUrl ? : "paper", array("ls" => null)),
                      array("method" => "get", "class" => "gopaper" . ($list ? " has_hotcrp_list" : ""), "data-hotcrp-list" => $list ? $list->listno : null));
    if ($baseUrl == "profile")
        $x .= Ht::entry("u", "", array("id" => "quicksearchq", "size" => 10, "placeholder" => "(User)", "class" => "need-autogrow"));
    else
        $x .= Ht::entry("p", "", array("id" => "quicksearchq", "size" => 10, "placeholder" => "(All)", "class" => "hotcrp_searchbox need-autogrow"));
    foreach ($args as $what => $val)
        $x .= Ht::hidden($what, $val);
    $x .= "&nbsp; " . Ht::submit("Search") . "</div></form>";
    return $x;
}

function rm_rf_tempdir($tempdir) {
    assert(substr($tempdir, 0, 1) === "/");
    exec("/bin/rm -rf " . escapeshellarg($tempdir));
}

function clean_tempdirs() {
    $dir = null;
    if (function_exists("sys_get_temp_dir"))
        $dir = sys_get_temp_dir();
    if (!$dir)
        $dir = "/tmp";
    while (substr($dir, -1) == "/")
        $dir = substr($dir, 0, -1);
    $dirh = opendir($dir);
    $now = time();
    while (($fname = readdir($dirh)) !== false)
        if (preg_match('/\Ahotcrptmp\d+\z/', $fname)
            && is_dir("$dir/$fname")
            && ($mtime = @filemtime("$dir/$fname")) !== false
            && $mtime < $now - 1800)
            rm_rf_tempdir("$dir/$fname");
    closedir($dirh);
}

function tempdir($mode = 0700) {
    $dir = null;
    if (function_exists("sys_get_temp_dir"))
        $dir = sys_get_temp_dir();
    if (!$dir)
        $dir = "/tmp";
    while (substr($dir, -1) == "/")
        $dir = substr($dir, 0, -1);
    for ($i = 0; $i < 100; $i++) {
        $path = $dir . "/hotcrptmp" . mt_rand(0, 9999999);
        if (mkdir($path, $mode)) {
            register_shutdown_function("rm_rf_tempdir", $path);
            return $path;
        }
    }
    return false;
}


// watch functions
function saveWatchPreference($paperId, $contactId, $watchtype, $on) {
    global $Conf;
    $explicit = ($watchtype << WATCHSHIFT_EXPLICIT);
    $selected = ($watchtype << WATCHSHIFT_NORMAL);
    $onvalue = $explicit | ($on ? $selected : 0);
    Dbl::qe("insert into PaperWatch (paperId, contactId, watch)
                values ($paperId, $contactId, $onvalue)
                on duplicate key update watch = (watch & ~" . ($explicit | $selected) . ") | $onvalue");
    return !Dbl::has_error();
}


// text helpers
function commajoin($what, $joinword = "and") {
    $what = array_values($what);
    $c = count($what);
    if ($c == 0)
        return "";
    else if ($c == 1)
        return $what[0];
    else if ($c == 2)
        return $what[0] . " " . $joinword . " " . $what[1];
    else
        return join(", ", array_slice($what, 0, -1)) . ", " . $joinword . " " . $what[count($what) - 1];
}

function prefix_commajoin($what, $prefix, $joinword = "and") {
    return commajoin(array_map(function ($x) use ($prefix) {
        return $prefix . $x;
    }, $what), $joinword);
}

function numrangejoin($range) {
    $i = 0;
    $a = array();
    while ($i < count($range)) {
        for ($j = $i + 1;
             $j < count($range) && $range[$j-1] == $range[$j] - 1;
             $j++)
            /* nada */;
        if ($j == $i + 1)
            $a[] = $range[$i];
        else
            $a[] = $range[$i] . "&ndash;" . $range[$j - 1];
        $i = $j;
    }
    return commajoin($a);
}

function pluralx($n, $what) {
    if (is_array($n))
        $n = count($n);
    return $n == 1 ? $what : pluralize($what);
}

function pluralize($what) {
    if ($what == "this")
        return "these";
    else if ($what == "has")
        return "have";
    else if ($what == "is")
        return "are";
    else if (str_ends_with($what, ")") && preg_match('/\A(.*?)(\s*\([^)]*\))\z/', $what, $m))
        return pluralize($m[1]) . $m[2];
    else if (preg_match('/\A.*?(?:s|sh|ch|[bcdfgjklmnpqrstvxz][oy])\z/', $what)) {
        if (substr($what, -1) == "y")
            return substr($what, 0, -1) . "ies";
        else
            return $what . "es";
    } else
        return $what . "s";
}

function plural($n, $what) {
    return (is_array($n) ? count($n) : $n) . ' ' . pluralx($n, $what);
}

function ordinal($n) {
    if ($n >= 1 && $n <= 3)
        return $n . ($n == 1 ? "st" : ($n == 2 ? "nd" : "rd"));
    else
        return $n . "th";
}

function tabLength($text, $all) {
    $len = 0;
    for ($i = 0; $i < strlen($text); $i++)
        if ($text[$i] == ' ')
            $len++;
        else if ($text[$i] == '\t')
            $len += 8 - ($len % 8);
        else if (!$all)
            break;
        else
            $len++;
    return $len;
}

function ini_get_bytes($varname) {
    // from PHP manual
    $val = trim(ini_get($varname));
    $last = strtolower($val[strlen($val)-1]);
    switch ($last) {
    case 'g':
        $val *= 1024; // fallthru
    case 'm':
        $val *= 1024; // fallthru
    case 'k':
        $val *= 1024;
    }
    return $val;
}

function whyNotText($whyNot, $action) {
    global $Conf, $Now;
    if (!is_array($whyNot))
        $whyNot = array($whyNot => 1);
    $paperId = (isset($whyNot['paperId']) ? $whyNot['paperId'] : -1);
    $reviewId = (isset($whyNot['reviewId']) ? $whyNot['reviewId'] : -1);
    $thisPaper = ($paperId < 0 ? "this paper" : "paper #$paperId");
    $text = '';
    if (isset($whyNot['invalidId'])) {
        $x = $whyNot['invalidId'] . "Id";
        $xid = (isset($whyNot[$x]) ? " \"" . $whyNot[$x] . "\"" : "");
        $text .= "Invalid " . $whyNot['invalidId'] . " number" . htmlspecialchars($xid) . ". ";
    }
    if (isset($whyNot['noPaper']))
        $text .= "No such paper" . ($paperId < 0 ? "" : " #$paperId") . ". ";
    if (isset($whyNot['noReview']))
        $text .= "No such review" . ($reviewId < 0 ? "" : " #$reviewId") . ". ";
    if (isset($whyNot["dbError"]))
        $text .= $whyNot["dbError"] . " ";
    if (isset($whyNot["permission"]))
        $text .= "You don’t have permission to $action $thisPaper. ";
    if (isset($whyNot["signin"]))
        $text .= "You must sign in to $action $thisPaper. ";
    if (isset($whyNot["withdrawn"]))
        $text .= ucfirst($thisPaper) . " has been withdrawn. ";
    if (isset($whyNot['notWithdrawn']))
        $text .= ucfirst($thisPaper) . " has not been withdrawn. ";
    if (isset($whyNot['notSubmitted']))
        $text .= ucfirst($thisPaper) . " is not submitted. ";
    if (isset($whyNot["rejected"]))
        $text .= ucfirst($thisPaper) . " was not accepted for publication. ";
    if (isset($whyNot["decided"]))
        $text .= "The review process for $thisPaper has completed. ";
    if (isset($whyNot['updateSubmitted']))
        $text .= ucfirst($thisPaper) . " has already been submitted and can no longer be updated. ";
    if (isset($whyNot['notUploaded']))
        $text .= ucfirst($thisPaper) . " can’t be submitted because you haven’t yet uploaded the paper itself. Upload the paper and try again. ";
    if (isset($whyNot['reviewNotSubmitted']))
        $text .= "This review is not yet ready for others to see. ";
    if (isset($whyNot['reviewNotComplete']))
        $text .= "Your own review for $thisPaper is not complete, so you can’t view other people’s reviews. ";
    if (isset($whyNot['responseNotReady']))
        $text .= "The authors’ response for $thisPaper is not yet ready for reviewers to view. ";
    if (isset($whyNot['reviewsOutstanding']))
        $text .= "You will get access to the reviews once you complete <a href=\"" . hoturl("search", "q=&amp;t=r") . "\">your assigned reviews for other papers</a>.  If you can’t complete your reviews, please let the conference organizers know via the “Refuse review” links. ";
    if (isset($whyNot['reviewNotAssigned']))
        $text .= "You are not assigned to review $thisPaper. ";
    if (isset($whyNot['deadline'])) {
        $dname = $whyNot['deadline'];
        if ($dname[0] == "s")
            $start = $Conf->setting("sub_open", -1);
        else if ($dname[0] == "p" || $dname[0] == "e")
            $start = $Conf->setting("rev_open", -1);
        else
            $start = 1;
        $end = $Conf->setting($dname, -1);
        if ($start <= 0 || $start == $end)
            $text .= "You can’t $action $thisPaper yet. ";
        else if ($start > 0 && $Now < $start)
            $text .= "You can’t $action $thisPaper until " . $Conf->printableTime($start, "span") . ". ";
        else if ($end > 0 && $Now > $end) {
            if ($dname == "sub_reg")
                $text .= "The paper registration deadline has passed. ";
            else if ($dname == "sub_update")
                $text .= "The deadline to update papers has passed. ";
            else if ($dname == "sub_sub")
                $text .= "The paper submission deadline has passed. ";
            else if ($dname == "extrev_hard")
                $text .= "The external review deadline has passed. ";
            else if ($dname == "pcrev_hard")
                $text .= "The PC review deadline has passed. ";
            else if ($dname == "final_done")
                $text .= "The deadline to update final versions has passed. ";
            else
                $text .= "The deadline to $action $thisPaper has passed. ";
            $text .= "It was " . $Conf->printableTime($end, "span") . ". ";
        } else if ($dname == "au_seerev") {
            if ($Conf->au_seerev == Conf::AUSEEREV_UNLESSINCOMPLETE)
                $text .= "Authors who are also reviewers can’t see reviews for their papers while they still have <a href='" . hoturl("search", "t=rout&amp;q=") . "'>incomplete reviews</a> of their own. ";
            else
                $text .= "Authors can’t view paper reviews at the moment. ";
        } else
            $text .= "You can’t $action $thisPaper at the moment. ";
        $text .= "(<a class='nw' href='" . hoturl("deadlines") . "'>View deadlines</a>) ";
    }
    if (isset($whyNot["override"]))
        $text .= "“Override deadlines” can override this restriction. ";
    if (isset($whyNot['blindSubmission']))
        $text .= "Submission to this conference is blind. ";
    if (isset($whyNot['author']))
        $text .= "You aren’t a contact for $thisPaper. ";
    if (isset($whyNot['conflict']))
        $text .= "You have a conflict with $thisPaper. ";
    if (isset($whyNot['externalReviewer']))
        $text .= "External reviewers may not view other reviews for the papers they review. ";
    if (isset($whyNot['differentReviewer']))
        $text .= "You didn’t write this review, so you can’t change it. ";
    if (isset($whyNot['reviewToken']))
        $text .= "If you know a valid review token, enter it above to edit that review. ";
    if (isset($whyNot["clickthrough"]))
        $text .= "You can’t do that until you agree to the current terms. ";
    if (isset($whyNot["otherTwiddleTag"]))
        $text .= "Tag “#" . htmlspecialchars($whyNot["tag"]) . "” doesn’t belong to you. ";
    if (isset($whyNot["chairTag"]))
        $text .= "Tag “#" . htmlspecialchars($whyNot["tag"]) . "” can only be set by administrators. ";
    if (isset($whyNot["voteTag"]))
        $text .= "The voting tag “#" . htmlspecialchars($whyNot["tag"]) . "” shouldn’t be changed directly. To vote for this paper, change the “#~" . htmlspecialchars($whyNot["tag"]) . "” tag. ";
    if (isset($whyNot["voteTagNegative"]))
        $text .= "Negative votes aren’t allowed. ";
    // finish it off
    if (isset($whyNot["chairMode"]))
        $text .= "(<a class='nw' href=\"" . selfHref(array("forceShow" => 1)) . "\">" . ucfirst($action) . " the paper anyway</a>) ";
    if (isset($whyNot["forceShow"]) && $whyNot["forceShow"] === true)
        $text .= "(As an administrator, you can override your conflict.) ";
    else if (isset($whyNot["forceShow"]))
        $text .= "(<a class='nw' href=\"". selfHref(array("forceShow" => 1)) . "\">Override conflict</a>) ";
    if ($text && $action == "view")
        $text .= "Enter a paper number above, or <a href='" . hoturl("search", "q=") . "'>list the papers you can view</a>. ";
    return rtrim($text);
}

function whyNotHtmlToText($e) {
    $e = preg_replace('|\(?<a.*?</a>\)?\s*\z|i', "", $e);
    return preg_replace('|<.*?>|', "", $e);
}

function actionBar($mode = null, $prow = null) {
    global $Me, $Conf;
    $forceShow = ($Me->is_admin_force() ? "&amp;forceShow=1" : "");

    $paperArg = "p=*";
    $xmode = array();
    $listtype = "p";

    $goBase = "paper";
    if ($mode == "assign")
        $goBase = "assign";
    else if ($mode == "re")
        $goBase = "review";
    else if ($mode == "account") {
        $listtype = "u";
        if ($Me->privChair)
            $goBase = "profile";
        else
            $prow = null;
    } else if (($wantmode = defval($_REQUEST, "m", defval($_REQUEST, "mode"))))
        $xmode["m"] = $wantmode;

    $quicklinks_txt = "";
    if ($prow) {
        $id = ($listtype === "u" ? $prow->contactId : $prow->paperId);
        $quicklinks_txt = quicklinks($id, $goBase, $xmode, $listtype);
    }

    // collect actions
    $x = '<table class="vbar"><tr>';

    if ($quicklinks_txt)
        $x .= $quicklinks_txt;
    if ($quicklinks_txt && $Me->privChair && $listtype == "p")
        $x .= "  <td id=\"trackerconnect\" class=\"nw\"><a id=\"trackerconnectbtn\" href=\"#\" onclick=\"return hotcrp_deadlines.tracker(1)\" class=\"tbtn hottooltip\" data-hottooltip=\"Start meeting tracker\">&#9759;</a><td>\n";

    $x .= "  <td class='gopaper nw'>" . goPaperForm($goBase, $xmode) . "</td>\n";

    return $x . "</tr></table>";
}

function parseReviewOrdinal($text) {
    $text = strtoupper($text);
    if (ctype_alpha($text)) {
        if (strlen($text) == 1)
            return ord($text) - 64;
        else if (strlen($text) == 2)
            return (ord($text[0]) - 64) * 26 + ord($text[1]) - 64;
    }
    return -1;
}

function unparseReviewOrdinal($ord) {
    if ($ord === null)
        return "x";
    else if (is_object($ord)) {
        if ($ord->reviewOrdinal)
            return $ord->paperId . unparseReviewOrdinal($ord->reviewOrdinal);
        else
            return $ord->reviewId;
    } else if ($ord <= 26)
        return chr($ord + 64);
    else
        return chr(intval(($ord - 1) / 26) + 64) . chr((($ord - 1) % 26) + 65);
}

function downloadCSV($info, $header, $filename, $options = array()) {
    global $Conf;
    if (defval($options, "type", "csv") == "csv" && !opt("disableCsv"))
        $csvt = CsvGenerator::TYPE_COMMA;
    else
        $csvt = CsvGenerator::TYPE_TAB;
    if (get($options, "always_quote"))
        $csvt |= CsvGenerator::FLAG_ALWAYS_QUOTE;
    if (get($options, "crlf"))
        $csvt |= CsvGenerator::FLAG_CRLF;
    $csvg = new CsvGenerator($csvt, "# ");
    if ($header)
        $csvg->set_header($header, true);
    if (get($options, "selection"))
        $csvg->set_selection($options["selection"] === true ? $header : $options["selection"]);
    $csvg->download_headers($Conf->download_prefix . $filename . $csvg->extension(), !get($options, "inline"));
    if ($info === false)
        return $csvg;
    else {
        $csvg->add($info);
        if (get($options, "sort"))
            $csvg->sort($options["sort"]);
        $csvg->download();
        exit;
    }
}

function downloadText($text, $filename, $inline = false) {
    global $Conf;
    $csvg = new CsvGenerator(CsvGenerator::TYPE_TAB);
    $csvg->download_headers($Conf->download_prefix . $filename . $csvg->extension(), !$inline);
    if ($text !== false) {
        $csvg->add($text);
        $csvg->download();
        exit;
    }
}

function parse_preference($n) {
    $n = trim($n);
    if (preg_match(',\A(-+|\++|[-+]?\d+(?:\.\d*)?|)\s*([xyz]|)\z,i', $n, $m)) {
        if ($m[1] === "")
            $p = 0;
        else if (is_numeric($m[1])) {
            if ($m[1] <= 1000000)
                $p = round($m[1]);
            else
                return null;
        } else if ($m[1][0] === "-")
            $p = -strlen($m[1]);
        else
            $p = strlen($m[1]);
        if ($m[2] === "")
            $e = null;
        else
            $e = 9 - (ord($m[2]) & 15);
        return array($p, $e);
    } else if (strpos($n, "\xE2") !== false)
        // Translate UTF-8 for minus sign into a real minus sign ;)
        return parse_preference(str_replace("\xE2\x88\x92", '-', $n));
    else if (strcasecmp($n, "none") == 0 || strcasecmp($n, "n/a") == 0)
        return array(0, null);
    else if (strcasecmp($n, "conflict") == 0)
        return array(-100, null);
    else
        return null;
}

function unparse_expertise($expertise) {
    if ($expertise === null)
        return "";
    else
        return $expertise > 0 ? "X" : ($expertise == 0 ? "Y" : "Z");
}

function unparse_preference($preference, $expertise = null) {
    if (is_object($preference))
        list($preference, $expertise) = array(get($preference, "reviewerPreference"),
                                              get($preference, "reviewerExpertise"));
    else if (is_array($preference))
        list($preference, $expertise) = $preference;
    if ($preference === null || $preference === false)
        $preference = "0";
    return $preference . unparse_expertise($expertise);
}

function unparse_preference_span($preference, $always = false) {
    if (is_object($preference))
        $preference = array(get($preference, "reviewerPreference"),
                            get($preference, "reviewerExpertise"),
                            get($preference, "topicInterestScore"));
    else if (!is_array($preference))
        $preference = array($preference, null, null);
    $pv = (int) get($preference, 0);
    $ev = get($preference, 1);
    $tv = (int) get($preference, 2);
    $type = 1;
    if ($pv < 0 || (!$pv && $tv < 0))
        $type = -1;
    $t = "";
    if ($pv || $ev !== null || $always)
        $t .= "P" . decorateNumber($pv) . unparse_expertise($ev);
    if ($tv && !$pv)
        $t .= ($t ? " " : "") . "T" . decorateNumber($tv);
    if ($t !== "")
        $t = " <span class=\"asspref$type\">$t</span>";
    return $t;
}

function decisionSelector($curOutcome = 0, $id = null, $extra = "") {
    global $Conf;
    $text = "<select" . ($id === null ? "" : " id='$id'") . " name='decision'$extra>\n";
    $decs = $Conf->decision_map();
    if (!isset($decs[$curOutcome]))
        $curOutcome = null;
    $outcomes = array_keys($decs);
    if ($curOutcome === null)
        $text .= "    <option value='' selected='selected'>Set decision...</option>\n";
    foreach ($decs as $dnum => $dname)
        $text .= "    <option value='$dnum'" . ($curOutcome == $dnum && $curOutcome !== null ? " selected='selected'" : "") . ">" . htmlspecialchars($dname) . "</option>\n";
    return $text . "  </select>";
}

function pcMembers() {
    global $Conf, $PcMembersCache;
    if (!@$PcMembersCache
        || $Conf->setting("pc") <= 0
        || $PcMembersCache[0] < $Conf->setting("pc")
        || $PcMembersCache[1] != opt("sortByLastName")) {
        $pc = array();
        $result = Dbl::q("select firstName, lastName, affiliation, email, contactId, roles, contactTags, disabled from ContactInfo where (roles&" . Contact::ROLE_PC . ")!=0");
        $by_name_text = array();
        $pctags = array("pc" => "pc");
        while ($result && ($row = Contact::fetch($result))) {
            $pc[$row->contactId] = $row;
            if ($row->firstName || $row->lastName) {
                $name_text = Text::name_text($row);
                if (isset($by_name_text[$name_text]))
                    $row->nameAmbiguous = $by_name_text[$name_text]->nameAmbiguous = true;
                $by_name_text[$name_text] = $row;
            }
            if ($row->contactTags)
                foreach (explode(" ", $row->contactTags) as $t) {
                    list($tag, $value) = TagInfo::split_index($t);
                    if ($tag)
                        $pctags[strtolower($tag)] = $tag;
                }
        }
        uasort($pc, "Contact::compare");
        ksort($pctags);
        $order = 0;
        foreach ($pc as $row) {
            $row->sort_position = $order;
            ++$order;
        }
        $PcMembersCache = array($Conf->setting("pc"), opt("sortByLastName"), $pc, $pctags);
    }
    return $PcMembersCache[2];
}

function pcTags($tag = null) {
    global $PcMembersCache;
    pcMembers();
    if ($tag === null)
        return $PcMembersCache[3];
    else
        return isset($PcMembersCache[3][strtolower($tag)]);
}

function pcByEmail($email) {
    foreach (pcMembers() as $id => $row)
        if (strcasecmp($row->email, $email) == 0)
            return $row;
    return null;
}

function pc_members_selector_options($include_none) {
    $sel = array();
    if ($include_none)
        $sel["0"] = is_string($include_none) ? $include_none : "None";
    $textarg = array("lastFirst" => opt("sortByLastName"));
    foreach (pcMembers() as $p)
        $sel[htmlspecialchars($p->email)] = Text::name_html($p, $textarg);
    return $sel;
}

function review_type_icon($revtype, $unfinished = null, $title = null) {
    // see also script.js:review_form
    static $revtypemap = array(-3 => array("&minus;", "Refused"),
                               -2 => array("A", "Author"),
                               -1 => array("C", "Conflict"),
                               1 => array("E", "External review"),
                               2 => array("P", "PC review"),
                               3 => array("2", "Secondary review"),
                               4 => array("1", "Primary review"));
    if (!$revtype)
        return '<span class="rt0"></span>';
    $x = $revtypemap[$revtype];
    return '<span class="rt' . $revtype
        . ($revtype > 0 && $unfinished ? "n" : "")
        . '" title="' . ($title ? $title : $revtypemap[$revtype][1])
        . '"><span class="rti">' . $revtypemap[$revtype][0] . '</span></span>';
}

function review_lead_icon() {
    return '<span class="rtlead" title="Lead"><span class="rti">L</span></span>';
}

function review_shepherd_icon() {
    return '<span class="rtshep" title="Shepherd"><span class="rti">S</span></span>';
}

function scoreCounts($values, $max = null) {
    $merit = ($max ? array_fill(1, $max, 0) : array());
    $n = $sum = $sumsq = 0;
    if (is_string($values))
        $values = preg_split('/[\s,]+/', $values);
    foreach ($values ? : array() as $i)
        if (($i = (int) $i) > 0) {
            while ($i > count($merit))
                $merit[count($merit) + 1] = 0;
            $merit[$i]++;
            $sum += $i;
            $sumsq += $i * $i;
            $n++;
        }
    $avg = ($n > 0 ? $sum / $n : 0);
    $dev = ($n > 1 ? sqrt(($sumsq - $sum*$sum/$n) / ($n - 1)) : 0);
    return (object) array("v" => $merit, "max" => count($merit),
                          "n" => $n, "avg" => $avg, "stddev" => $dev);
}

function displayOptionsSet($sessionvar, $var = null, $val = null) {
    global $Conf;
    if (($x = $Conf->session($sessionvar)) !== null)
        /* use session value */;
    else if ($sessionvar == "pldisplay")
        $x = $Conf->setting_data("pldisplay_default", "");
    else
        $x = "";
    if ($x == null || strpos($x, " ") === false) {
        if ($sessionvar == "pldisplay")
            $x = " overAllMerit ";
        else if ($sessionvar == "uldisplay")
            $x = " tags overAllMerit ";
        else
            $x = " ";
    }

    // set $var to $val in list
    if ($var) {
        $x = str_replace(" $var ", " ", $x);
        if ($val)
            $x .= "$var ";
    }

    // store list in $_SESSION
    $Conf->save_session($sessionvar, $x);
    return $x;
}


if (!function_exists("random_bytes")) {
    function random_bytes($length) {
        $x = @file_get_contents("/dev/urandom", false, null, 0, $length);
        if (($x === false || $x === "")
            && function_exists("openssl_random_pseudo_bytes")) {
            $x = openssl_random_pseudo_bytes($length, $strong);
            $x = $strong ? $x : false;
        }
        return $x === "" ? false : $x;
    }
}

function hotcrp_random_password($length = 14) {
    $bytes = random_bytes($length + 10);
    if ($bytes === false) {
        $bytes = "";
        while (strlen($bytes) < $length)
            $bytes .= sha1(opt("conferenceKey") . pack("V", mt_rand()));
    }

    $l = "a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w trcrbrfrthdrchphwrstspswprslcl2 3 4 5 6 7 8 9 - @ _ + = ";
    $pw = "";
    $nvow = 0;
    for ($i = 0;
         $i < strlen($bytes) &&
             strlen($pw) < $length + max(0, ($nvow - 3) / 3);
         ++$i) {
        $x = ord($bytes[$i]) % (strlen($l) / 2);
        if ($x < 30)
            ++$nvow;
        $pw .= rtrim(substr($l, 2 * $x, 2));
    }
    return $pw;
}


function encode_token($x, $format = "") {
    $s = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $t = "";
    if (is_int($x))
        $format = "V";
    if ($format)
        $x = pack($format, $x);
    $i = 0;
    $have = 0;
    $n = 0;
    while ($have > 0 || $i < strlen($x)) {
        if ($have < 5 && $i < strlen($x)) {
            $n += ord($x[$i]) << $have;
            $have += 8;
            ++$i;
        }
        $t .= $s[$n & 31];
        $n >>= 5;
        $have -= 5;
    }
    if ($format == "V")
        return preg_replace('/(\AA|[^A])A*\z/', '$1', $t);
    else
        return $t;
}

function decode_token($x, $format = "") {
    $map = "//HIJKLMNO///////01234567/89:;</=>?@ABCDEFG";
    $t = "";
    $n = $have = 0;
    $x = trim(strtoupper($x));
    for ($i = 0; $i < strlen($x); ++$i) {
        $o = ord($x[$i]);
        if ($o >= 48 && $o <= 90 && ($out = ord($map[$o - 48])) >= 48)
            $o = $out - 48;
        else if ($o == 46 /*.*/ || $o == 34 /*"*/)
            continue;
        else
            return false;
        $n += $o << $have;
        $have += 5;
        while ($have >= 8 || ($n && $i == strlen($x) - 1)) {
            $t .= chr($n & 255);
            $n >>= 8;
            $have -= 8;
        }
    }
    if ($format == "V") {
        $x = unpack("Vx", $t . "\x00\x00\x00\x00\x00\x00\x00");
        return $x["x"];
    } else if ($format)
        return unpack($format, $t);
    else
        return $t;
}
