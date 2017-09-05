<?php
// helpers.php -- HotCRP non-class helper functions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
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

function hoturl_defaults($options = array()) {
    foreach ($options as $k => $v)
        if ($v !== null)
            Conf::$hoturl_defaults[$k] = urlencode($v);
        else
            unset(Conf::$hoturl_defaults[$k]);
    $ret = array();
    if (Conf::$hoturl_defaults)
        foreach (Conf::$hoturl_defaults as $k => $v)
            $ret[$k] = urldecode($v);
    return $ret;
}

function hoturl_site_relative($page, $options = null) {
    global $Conf;
    return $Conf->hoturl($page, $options, Conf::HOTURL_SITE_RELATIVE);
}

function hoturl($page, $options = null) {
    global $Conf;
    return $Conf->hoturl($page, $options);
}

function hoturl_post($page, $options = null) {
    global $Conf;
    return $Conf->hoturl($page, $options, Conf::HOTURL_POST);
}

function hoturl_absolute($page, $options = null) {
    global $Conf;
    return $Conf->hoturl($page, $options, Conf::HOTURL_ABSOLUTE);
}

function hoturl_absolute_nodefaults($page, $options = null) {
    global $Conf;
    return $Conf->hoturl($page, $options, Conf::HOTURL_ABSOLUTE | Conf::HOTURL_NO_DEFAULTS);
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


function file_uploaded(&$var) {
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

class SelfHref {
    static private $argmap = null;
    static private function set_argmap() {
        self::$argmap = [
            "p" => true, "paperId" => "p", "pap" => "p",
            "r" => true, "reviewId" => "r",
            "c" => true, "commentId" => "c",
            "m" => true, "mode" => true,
            "u" => true,
            "g" => true,
            "q" => true, "t" => true, "qa" => true, "qo" => true, "qx" => true, "qt" => true,
            "fx" => true, "fy" => true,
            "forceShow" => true, "validator" => true, "ls" => true,
            "tab" => true, "atab" => true, "sort" => true,
            "group" => true, "monreq" => true, "noedit" => true,
            "contact" => true, "reviewer" => true,
            "editcomment" => true
        ];
    }
    static function make(Qrequest $qreq = null, $params = [], $options = null) {
        if (self::$argmap === null)
            self::set_argmap();

        $x = [];
        $args = $qreq ? $qreq->make_array() : $_REQUEST;
        foreach ($args as $k => $v) {
            $ak = get(self::$argmap, $k);
            if ($ak === true)
                $ak = $k;
            if ($ak
                && ($ak === $k || !isset($args[$ak]))
                && !array_key_exists($ak, $params)
                && ($qreq || isset($_GET[$k]) || isset($_POST[$k]))
                && !is_array($v))
                $x[$ak] = $v;
        }
        foreach ($params as $k => $v)
            if ($v !== null)
                $x[$k] = $v;

        $page = Navigation::page();
        if ($options && get($options, "site_relative")) {
            if (get($options, "raw"))
                return hoturl_site_relative_raw($page, $x);
            else
                return hoturl_site_relative($page, $x);
        } else if ($options && get($options, "raw"))
            return hoturl_raw($page, $x);
        else
            return hoturl($page, $x);
    }
    static function redirect(Qrequest $qreq = null, $params = []) {
        Navigation::redirect(self::make($qreq, $params, ["raw" => true]));
    }
}

function selfHref($params = [], $options = null) {
    return SelfHref::make(null, $params, $options);
}

function redirectSelf($params = []) {
    SelfHref::redirect(null, $params);
}

class JsonResult {
    public $status;
    public $content;
    public $has_messages = false;

    function __construct($values = null) {
        if (is_int($values)) {
            $this->status = $values;
            if (func_num_args() === 2)
                $values = func_get_arg(1);
            else
                $values = null;
        }
        if ($values === true || $values === false)
            $this->content = ["ok" => $values];
        else if ($values === null)
            $this->content = [];
        else if (is_object($values)) {
            assert(!($values instanceof JsonResult));
            $this->content = get_object_vars($values);
        } else if (is_string($values)) {
            if ($this->status && $this->status > 299)
                $this->content = ["ok" => false, "error" => $values];
            else
                $this->content = ["ok" => true, "response" => $values];
        } else
            $this->content = $values;
    }
    function transfer_messages(Conf $conf, $div = false) {
        if (session_id() !== ""
            && ($msgs = $conf->session("msgs", []))) {
            $conf->save_session("msgs", null);
            $t = "";
            foreach ($msgs as $msg) {
                if (($msg[0] === "merror" || $msg[0] === "xmerror")
                    && !isset($this->status["error"]))
                    $this->status["error"] = $msg[1];
                if ($div)
                    $t .= Ht::xmsg($msg[0], $msg[1]);
                else
                    $t .= "<span class=\"$msg[0]\">$msg[1]</span>";
            }
            if ($t !== "")
                $this->content["response"] = $t . get_s($this->content, "response");
            $this->has_messages = true;
        }
    }
}

class JsonResultException extends Exception {
    public $result;
    static public $capturing = false;
    function __construct($j) {
        $this->result = $j;
    }
}

function json_exit($json, $arg2 = null) {
    global $Conf;
    if (is_int($json))
        $json = new JsonResult($json, $arg2);
    else if (!is_object($json) || !($json instanceof JsonResult))
        $json = new JsonResult($json);
    if (!$json->has_messages && $Conf)
        $json->transfer_messages($Conf);
    if (JsonResultException::$capturing)
        throw new JsonResultException($json);
    else {
        if ($json->status)
            http_response_code($json->status);
        if (isset($_GET["jsontext"]) && $_GET["jsontext"])
            header("Content-Type: text/plain");
        else
            header("Content-Type: application/json");
        if (check_post())
            header("Access-Control-Allow-Origin: *");
        echo json_encode_browser($json->content);
        exit;
    }
}

function foldbutton($foldtype, $foldnum = 0, $content = "") {
    $foldnumid = ($foldnum ? ",$foldnum" : "");
    return '<a href="#" class="q" onclick="return fold(\''
        . $foldtype . '\',null' . $foldnumid . ')">'
        . expander(null, $foldnum) . $content . '</a>';
}

function foldupbutton($foldnum = 0, $content = "", $j = null) {
    return '<a href="#" class="q" onclick="return foldup(this,event,'
        . ($j ? htmlspecialchars(json_encode_browser($j)) : $foldnum) . ')">'
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
    public $listid;
    public $ids;
    public $cid;
    public $description;
    public $url;
    public $urlbase;
    public $timestamp;
    public $highlight;
    static private $active_listid = null;
    static private $active_list = null;
    static private $requested_list = false;
    static function decode_ids($ids) {
        if (strpos($ids, "-") === false && ($a = json_decode($ids)) !== null)
            return is_array($a) ? $a : [$a];
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
    static function encode_ids($ids) {
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
        return join("'", $a);
    }
    static function decode_info_string($info) {
        if (($j = json_decode($info)) && isset($j->ids)) {
            $list = new SessionList;
            foreach ($j as $key => $value)
                $list->$key = $value;
            if (is_string($list->ids))
                $list->ids = self::decode_ids($list->ids);
            return $list;
        } else
            return null;
    }
    function full_site_relative_url() {
        if ($this->url)
            return $this->url;
        else if ($this->urlbase) {
            $url = $this->urlbase;
            if (preg_match(',\Ap/[^/]*/([^/]*)(?:|/([^/]*))\z,', $this->listid, $m)) {
                if ($m[1] !== "" || str_starts_with($url, "search"))
                    $url .= (strpos($url, "?") ? "&" : "?") . "q=" . $m[1];
                if (isset($m[2]) && $m[2] !== "") {
                    foreach (explode("&", $m[2]) as $kv) {
                        $eq = strpos($kv, "=");
                        if (!preg_match('/[?&]' . preg_quote(substr($kv, 0, $eq)) . '=/', $url))
                            $url .= (strpos($url, "?") ? "&" : "?") . $kv;
                    }
                }
            }
            return $url;
        } else
            return null;
    }
    function info_string($minimal = false) {
        $j = ["ids" => self::encode_ids($this->ids)];
        $urlkey = $this->urlbase ? "urlbase" : "url";
        foreach (get_object_vars($this) as $k => $v)
            if ($v != null
                && $k !== "ids" && $k !== "cid" && $k !== "timestamp" && $k !== "id_position"
                && (!$minimal || $k === "listid" || $k === "description" || $k === $urlkey))
                $j[$k] = $v;
        return json_encode_browser($j);
    }
    static function create($listid, $ids, $description, $urlbase) {
        global $Me, $Now;
        $lx = new SessionList;
        $lx->listid = $listid;
        $lx->ids = $ids;
        $lx->cid = $Me ? $Me->contactId : 0;
        $lx->description = $description;
        $lx->urlbase = $urlbase;
        $lx->timestamp = $Now;
        return $lx;
    }
    static private function try_list($opt, $listtype, $sort = null) {
        global $Conf, $Me;
        if ($listtype == "u" && $Me->privChair) {
            $searchtype = (defval($opt, "t") === "all" ? "all" : "pc");
            $q = "select contactId from ContactInfo";
            if ($searchtype == "pc")
                $q .= " where roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0";
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
    function set_cookie() {
        global $Now;
        $s = $this->info_string();
        if (strlen($s) > 1500)
            $s = $this->info_string(true);
        setcookie("hotlist-info", $s, $Now + 20, Navigation::site_path());
    }
    static function clear_cookie() {
        global $Now;
        if (isset($_COOKIE["hotlist-info"]))
            setcookie("hotlist-info", "", $Now - 86400, Navigation::site_path());
    }
    static function requested() {
        global $Me;
        if (self::$requested_list !== false)
            return self::$requested_list;

        if (isset($_COOKIE["hotlist-info"])
            && ($list = self::decode_info_string($_COOKIE["hotlist-info"])))
            return (self::$requested_list = $list);

        // look up list description
        $list = null;
        $listdesc = req("ls");
        if (!$listdesc && isset($_COOKIE["hotlist-info"]))
            $listdesc = $_COOKIE["hotlist-info"];
        else if (!$listdesc && isset($_COOKIE["hotcrp_ls"]))
            $listdesc = $_COOKIE["hotcrp_ls"];
        if ($listdesc) {
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

        return (self::$requested_list = $list);
    }
    static function active($listtype = null, $id = null) {
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

        // completion
        if ($list) {
            $list->timestamp = $Now;
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
        . "\" class=\"x\" href=\"" . hoturl($baseUrl, $urlrest) . "\">"
        . ($isprev ? Ht::img("_.gif", "<-", "prev") : "")
        . $paperText
        . ($isprev ? "" : Ht::img("_.gif", "->", "next"))
        . "</a>";
}

function goPaperForm($baseUrl = null, $args = array()) {
    global $Conf, $Me;
    if ($Me->is_empty())
        return "";
    $list = SessionList::active();
    $x = Ht::form_div(hoturl($baseUrl ? : "paper", ["ls" => null]), ["method" => "get", "class" => "gopaper"]);
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
function saveWatchPreference($paperId, $contactId, $watchtype, $on, $explicit) {
    $isset_val = ($watchtype << WATCHSHIFT_ISSET);
    $on_val = ($watchtype << WATCHSHIFT_ON);
    $want_val = ($on ? $on_val : 0) | ($explicit ? $isset_val : 0);
    $mod = "(watch&~$on_val)|$want_val";
    if (!$explicit)
        $mod = "if(watch&$isset_val,watch,$mod)";
    Dbl::qe("insert into PaperWatch set paperId=$paperId, contactId=$contactId, watch=$want_val on duplicate key update watch=$mod");
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
    $a = [];
    $format = null;
    foreach ($range as $current) {
        if ($format !== null
            && sprintf($format, $intval + 1) === (string) $current) {
            ++$intval;
            $last = $current;
            continue;
        } else {
            if ($format !== null && $first === $last)
                $a[] = $first;
            else if ($format !== null)
                $a[] = $first . "–" . substr($last, $plen);
            if ($current !== "" && ctype_digit($current)) {
                $format = "%0" . strlen($current) . "d";
                $plen = 0;
                $first = $last = $current;
                $intval = intval($current);
            } else if (preg_match('/\A(\D*)(\d+)\z/', $current, $m)) {
                $format = str_replace("%", "%%", $m[1]) . "%0" . strlen($m[2]) . "d";
                $plen = strlen($m[1]);
                $first = $last = $current;
                $intval = intval($m[2]);
            } else {
                $format = null;
                $a[] = $current;
            }
        }
    }
    if ($format !== null && $first === $last)
        $a[] = $first;
    else if ($format !== null)
        $a[] = $first . "–" . substr($last, $plen);
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
    else if (preg_match('/\A.*?(?:s|sh|ch|[bcdfgjklmnpqrstvxz]y)\z/', $what)) {
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
    $x = $n;
    if ($x > 100)
        $x = $x % 100;
    if ($x > 20)
        $x = $x % 10;
    return $n . ($x < 1 || $x > 3 ? "th" : ($x == 1 ? "st" : ($x == 2 ? "nd" : "rd")));
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

function ini_get_bytes($varname, $value = null) {
    $val = trim($value !== null ? $value : ini_get($varname));
    $last = strlen($val) ? strtolower($val[strlen($val) - 1]) : ".";
    return (int) ceil(floatval($val) * (1 << (+strpos(".kmg", $last) * 10)));
}

function whyNotText($whyNot, $action, $suggest_redirection = false) {
    global $Conf, $Now;
    $conf = get($whyNot, "conf") ? : $Conf;
    if (!is_array($whyNot))
        $whyNot = array($whyNot => 1);
    $paperId = (isset($whyNot["paperId"]) ? $whyNot["paperId"] : -1);
    $reviewId = (isset($whyNot["reviewId"]) ? $whyNot["reviewId"] : -1);
    $ms = [];
    if (isset($whyNot["invalidId"])) {
        $x = $whyNot["invalidId"] . "Id";
        if (isset($whyNot[$x]))
            $ms[] = $conf->_("Invalid " . $whyNot["invalidId"] . " number “%s”.", htmlspecialchars($whyNot[$x]));
        else
            $ms[] = $conf->_("Invalid " . $whyNot["invalidId"] . " number.");
    }
    if (isset($whyNot["noPaper"]))
        $ms[] = $conf->_("No such submission #%d.", $paperId);
    if (isset($whyNot["noReview"]))
        $ms[] = $conf->_("No such review #%s.", $reviewId);
    if (isset($whyNot["dbError"]))
        $ms[] = $whyNot["dbError"];
    if (isset($whyNot["administer"]))
        $ms[] = $conf->_("You can’t administer submission #%d.", $paperId);
    if (isset($whyNot["permission"]))
        $ms[] = $conf->_("You don’t have permission to $action submission #%d.", $paperId);
    if (isset($whyNot["pdfPermission"]))
        $ms[] = $conf->_("You don’t have permission to view uploaded documents for submission #%d.", $paperId);
    if (isset($whyNot["optionPermission"]))
        $ms[] = $conf->_("You don’t have permission to view the %2\$s for submission #%1\$d.", $paperId, $whyNot["optionPermission"]->message_name);
    if (isset($whyNot["optionNotAccepted"]))
        $ms[] = $conf->_("Non-accepted submission #%d can have no %s.", $paperId, $whyNot["optionNotAccepted"]->message_name);
    if (isset($whyNot["signin"]))
        $ms[] = $conf->_("You must sign in to $action submission #%d.", $paperId);
    if (isset($whyNot["withdrawn"]))
        $ms[] = $conf->_("Submission #%d has been withdrawn.", $paperId);
    if (isset($whyNot["notWithdrawn"]))
        $ms[] = $conf->_("Submission #%d is not withdrawn.", $paperId);
    if (isset($whyNot["notSubmitted"]))
        $ms[] = $conf->_("Submission #%d is only a draft.", $paperId);
    if (isset($whyNot["rejected"]))
        $ms[] = $conf->_("Submission #%d was not accepted for publication.", $paperId);
    if (isset($whyNot["decided"]))
        $ms[] = $conf->_("The review process for submission #%d has completed.", $paperId);
    if (isset($whyNot["updateSubmitted"]))
        $ms[] = $conf->_("Submission #%d can no longer be updated.", $paperId);
    if (isset($whyNot["notUploaded"]))
        $ms[] = $conf->_("A PDF upload is required to submit.");
    if (isset($whyNot["reviewNotSubmitted"]))
        $ms[] = $conf->_("This review is not yet ready for others to see.");
    if (isset($whyNot["reviewNotComplete"]))
        $ms[] = $conf->_("Your own review for #%d is not complete, so you can’t view other people’s reviews.", $paperId);
    if (isset($whyNot["responseNotReady"]))
        $ms[] = $conf->_("The authors’ response is not yet ready for reviewers to view.");
    if (isset($whyNot["reviewsOutstanding"]))
        $ms[] = $conf->_("You will get access to the reviews once you complete <a href=\"%s\">your assigned reviews</a>. If you can’t complete your reviews, please let the organizers know via the “Refuse review” links.", hoturl("search", "q=&amp;t=r"));
    if (isset($whyNot["reviewNotAssigned"]))
        $ms[] = $conf->_("You are not assigned to review submission #%d.", $paperId);
    if (isset($whyNot["deadline"])) {
        $dname = $whyNot["deadline"];
        if ($dname[0] == "s")
            $start = $conf->setting("sub_open", -1);
        else if ($dname[0] == "p" || $dname[0] == "e")
            $start = $conf->setting("rev_open", -1);
        else
            $start = 1;
        $end = $conf->setting($dname, -1);
        if ($dname == "au_seerev") {
            if ($conf->au_seerev == Conf::AUSEEREV_UNLESSINCOMPLETE)
                $ms[] = $conf->_("Authors who are also reviewers can’t see reviews for their papers while they still have <a href=\"%s\">incomplete reviews</a> of their own.", hoturl("search", "t=rout&amp;q="));
            else
                $ms[] = $conf->_("Authors can’t view reviews at the moment.");
        } else if ($start <= 0 || $start == $end) {
            if ($dname[0] == "p" || $dname[0] == "e")
                $ms[] = $conf->_("You can’t $action submission #%d because the site is not open for reviewing.", $paperId);
            else
                $ms[] = $conf->_("You can’t $action submission #%d yet.", $paperId);
        } else if ($start > 0 && $Now < $start)
            $ms[] = $conf->_("You can’t $action submission #%d until %s.", $paperId, $conf->printableTime($start, "span"));
        else if ($end > 0 && $Now > $end) {
            if ($dname == "sub_reg")
                $ms[] = $conf->_("The registration deadline has passed.");
            else if ($dname == "sub_update")
                $ms[] = $conf->_("The update deadline has passed.");
            else if ($dname == "sub_sub")
                $ms[] = $conf->_("The submission deadline has passed.");
            else if ($dname == "extrev_hard")
                $ms[] = $conf->_("The external review deadline has passed.");
            else if ($dname == "pcrev_hard")
                $ms[] = $conf->_("The PC review deadline has passed.");
            else if ($dname == "final_done")
                $ms[] = $conf->_("The deadline to update final versions has passed.");
            else
                $ms[] = $conf->_("The deadline to $action submission #%d has passed.", $paperId);
            $ms[] = $conf->_("It was %s.", $conf->printableTime($end, "span"));
        } else
            $ms[] = $conf->_("You can’t $action submission #%d at the moment.", $paperId);
    }
    if (isset($whyNot["override"]))
        $ms[] = $conf->_("“Override deadlines” can override this restriction.");
    if (isset($whyNot["blindSubmission"]))
        $ms[] = $conf->_("Submission to this conference is blind.");
    if (isset($whyNot["author"]))
        $ms[] = $conf->_("You aren’t a contact for #%d.", $paperId);
    if (isset($whyNot["conflict"]))
        $ms[] = $conf->_("You have a conflict with #%d.", $paperId);
    if (isset($whyNot["externalReviewer"]))
        $ms[] = $conf->_("External reviewers cannot view other reviews.");
    if (isset($whyNot["differentReviewer"]))
        $ms[] = $conf->_("You didn’t write this review, so you can’t change it.");
    if (isset($whyNot["unacceptableReviewer"]))
        $ms[] = $conf->_("That user can’t be assigned to review #%d.", $paperId);
    if (isset($whyNot["reviewToken"]))
        $ms[] = $conf->_("If you know a valid review token, enter it above to edit that review.");
    if (isset($whyNot["clickthrough"]))
        $ms[] = $conf->_("You can’t do that until you agree to the terms.");
    if (isset($whyNot["otherTwiddleTag"]))
        $ms[] = $conf->_("Tag “#%s” doesn’t belong to you.", htmlspecialchars($whyNot["tag"]));
    if (isset($whyNot["chairTag"]))
        $ms[] = $conf->_("Tag “#%s” can only be changed by administrators.", htmlspecialchars($whyNot["tag"]));
    if (isset($whyNot["voteTag"]))
        $ms[] = $conf->_("The voting tag “#%s” shouldn’t be changed directly. To vote for this paper, change the “#~%1\$s” tag.", htmlspecialchars($whyNot["tag"]));
    if (isset($whyNot["voteTagNegative"]))
        $ms[] = $conf->_("Negative votes aren’t allowed.");
    // finish it off
    if (isset($whyNot["chairMode"]))
        $ms[] = $conf->_("(<a class=\"nw\" href=\"%s\">" . ucfirst($action) . " anyway</a>)", selfHref(["forceShow" => 1]));
    if (isset($whyNot["forceShow"]) && $whyNot["forceShow"] === true)
        $ms[] = $conf->_("(As an administrator, you can override your conflict.)");
    else if (isset($whyNot["forceShow"]))
        $ms[] = $conf->_("(<a class=\"nw\" href=\"%s\">Override conflict</a>)", selfHref(array("forceShow" => 1)));
    if (!empty($ms) && $suggest_redirection)
        $ms[] = $conf->_("Enter a submission number above, or <a href=\"%s\">list the submissions you can view</a>.", hoturl("search", "q="));
    return join(" ", $ms);
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

    $x = '<table class="vbar"><tr>';

    // quicklinks
    if ($prow
        && ($list = SessionList::active($listtype, $listtype === "u" ? $prow->contactId : $prow->paperId))) {
        $x .= '<td class="quicklinks nw">';
        if ($list->id_position > 0)
            $x .= _one_quicklink($list->ids[$list->id_position - 1], $goBase, $xmode, $listtype, true);
        if ($list->description) {
            $x .= ($list->id_position > 0 ? "&nbsp;&nbsp;" : "");
            $url = $list->full_site_relative_url();
            if ($url)
                $x .= '<a id="quicklink_list" class="x" href="' . htmlspecialchars(Navigation::siteurl() . $url) . "\">" . $list->description . "</a>";
            else
                $x .= '<span id="quicklink_list">' . $list->description . '</span>';
        }
        if (isset($list->ids[$list->id_position + 1])) {
            $x .= ($list->id_position > 0 || $list->description ? "&nbsp;&nbsp;" : "");
            $x .= _one_quicklink($list->ids[$list->id_position + 1], $goBase, $xmode, $listtype, false);
        }
        $x .= '</td>';

        if ($Me->privChair && $listtype == "p")
            $x .= "  <td id=\"trackerconnect\" class=\"nb\"><a id=\"trackerconnectbtn\" href=\"#\" onclick=\"return hotcrp_deadlines.tracker(1)\" class=\"tbtn need-tooltip\" data-tooltip=\"Start meeting tracker\">&#9759;</a><td>\n";
    }

    return $x . '<td class="gopaper nb">' . goPaperForm($goBase, $xmode) . "</td></tr></table>";
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
    if (!$ord)
        return ".";
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
        $csvg->add_string($text);
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
                $p = (int) round($m[1]);
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
    } else if (strcasecmp($n, "none") == 0 || strcasecmp($n, "n/a") == 0)
        return array(0, null);
    else if (strcasecmp($n, "conflict") == 0)
        return array(-100, null);
    else if (($o = str_replace("\xE2\x88\x92", "-", $n)) !== $n)
        // Translate UTF-8 for minus sign into a real minus sign ;)
        return parse_preference($o);
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

function pc_members_selector_options($include_none) {
    global $Conf;
    $sel = array();
    if ($include_none)
        $sel["0"] = is_string($include_none) ? $include_none : "None";
    $textarg = array("lastFirst" => $Conf->opt("sortByLastName"));
    foreach ($Conf->pc_members() as $p)
        $sel[$p->email] = Text::name_html($p, $textarg);
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
                               4 => array("1", "Primary review"),
                               5 => array("M", "Metareview"));
    if (!$revtype)
        return '<span class="rt0"></span>';
    $x = $revtypemap[$revtype];
    return '<span class="rto rt' . $revtype
        . ($revtype > 0 && $unfinished ? "n" : "")
        . '" title="' . ($title ? $title : $revtypemap[$revtype][1])
        . '"><span class="rti">' . $revtypemap[$revtype][0] . '</span></span>';
}

function review_lead_icon() {
    return '<span class="rto rtlead" title="Lead"><span class="rti">L</span></span>';
}

function review_shepherd_icon() {
    return '<span class="rto rtshep" title="Shepherd"><span class="rti">S</span></span>';
}

function displayOptionsSet($sessionvar, $var = null, $val = null) {
    global $Conf;
    if (($x = $Conf->session($sessionvar)) !== null)
        /* use session value */;
    else if ($sessionvar === "pldisplay")
        $x = $Conf->setting_data("pldisplay_default", "");
    else
        $x = "";
    if ($x == null || strpos($x, " ") === false) {
        if ($sessionvar == "pldisplay")
            $x = $Conf->review_form()->default_display();
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
        if (($sessionvar === "pldisplay" || $sessionvar === "pfdisplay")
            && ($f = $Conf->find_review_field($var))
            && $var !== $f->id)
            $x = str_replace(" {$f->id} ", " ", $x);
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
