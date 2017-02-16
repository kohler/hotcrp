<?php
// conference.php -- HotCRP central helper class (singleton)
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Track {
    private $a;
    const VIEW = 0;
    const VIEWPDF = 1;
    const VIEWREV = 2;
    const VIEWREVID = 3;
    const ASSREV = 4;
    const UNASSREV = 5;
    const VIEWTRACKER = 6;
    const ADMIN = 7;
    static public $map = [
        "view" => 0, "viewpdf" => 1, "viewrev" => 2, "viewrevid" => 3,
        "assrev" => 4, "unassrev" => 5, "viewtracker" => 6, "admin" => 7
    ];
    static public $zero = [null, null, null, null, null, null, null, null];
    static function match_perm(Contact $user, $perm) {
        if ($perm) {
            $has_tag = $user->has_tag(substr($perm, 1));
            return $perm[0] === "-" ? !$has_tag : $has_tag;
        } else
            return true;
    }
}

class Conf {
    public $dblink = null;

    var $settings;
    private $settingTexts;
    public $sversion;
    private $_pc_seeall_cache = null;
    private $_pc_see_pdf = false;

    public $dbname;
    public $dsn = null;

    public $short_name;
    public $long_name;
    public $default_format;
    public $download_prefix;
    public $au_seerev;
    public $tag_au_seerev;
    public $tag_seeall;
    public $sort_by_last;
    public $opt;
    public $opt_override = null;
    public $paper_opts;

    private $save_messages = true;
    var $headerPrinted = false;
    private $_save_logs = false;

    private $usertimeId = 1;

    private $rounds = null;
    private $_defined_rounds = null;
    private $tracks = null;
    private $_taginfo = null;
    private $_track_tags = null;
    private $_track_view_sensitivity = false;
    private $_track_review_sensitivity = false;
    private $_track_admin_sensitivity = false;
    private $_decisions = null;
    private $_topic_map = null;
    private $_topic_order_map = null;
    private $_topic_separator_cache = null;
    private $_pc_members_cache = null;
    private $_pc_tags_cache = null;
    private $_pc_members_and_admins_cache = null;
    private $_review_form_cache = null;
    private $_abbrev_matcher = null;
    private $_date_format_initialized = false;
    private $_docclass_cache = [];
    private $_docstore = false;
    private $_formula_functions = null;
    private $_search_keyword_base = null;
    private $_search_keyword_factories = null;
    private $_search_keywords = null;
    private $_defined_formulas = null;
    private $_emoji_codes = null;
    private $_s3_document = false;
    private $_ims = null;
    private $_api_map = null;
    private $_format_info = null;

    public $paper = null; // current paper row

    static public $g;
    static public $no_invalidate_caches = false;

    const BLIND_NEVER = 0;
    const BLIND_OPTIONAL = 1;
    const BLIND_ALWAYS = 2;
    const BLIND_UNTILREVIEW = 3;

    const SEEDEC_ADMIN = 0;
    const SEEDEC_REV = 1;
    const SEEDEC_ALL = 2;
    const SEEDEC_NCREV = 3;

    const AUSEEREV_NO = 0;
    const AUSEEREV_UNLESSINCOMPLETE = 1;
    const AUSEEREV_YES = 2;
    const AUSEEREV_TAGS = 3;

    const PCSEEREV_IFCOMPLETE = 0;
    const PCSEEREV_YES = 1;
    const PCSEEREV_UNLESSINCOMPLETE = 3;
    const PCSEEREV_UNLESSANYINCOMPLETE = 4;

    static public $review_deadlines = array("pcrev_soft", "pcrev_hard", "extrev_soft", "extrev_hard");

    function __construct($options, $make_dsn) {
        // unpack dsn, connect to database, load current settings
        if ($make_dsn && ($this->dsn = Dbl::make_dsn($options)))
            list($this->dblink, $options["dbName"]) = Dbl::connect_dsn($this->dsn);
        if (!isset($options["confid"]))
            $options["confid"] = get($options, "dbName");
        $this->opt = $options;
        $this->dbname = $options["dbName"];
        $this->paper_opts = new PaperOptionList($this);
        if ($this->dblink && !Dbl::$default_dblink) {
            Dbl::set_default_dblink($this->dblink);
            Dbl::set_error_handler(array($this, "query_error_handler"));
        }
        if ($this->dblink) {
            Dbl::$landmark_sanitizer = "/^(?:Dbl::|Conf::q|Conf::fetch|call_user_func)/";
            $this->load_settings();
        } else
            $this->crosscheck_options();
    }


    //
    // Initialization functions
    //

    function load_settings() {
        global $Now;

        // load settings from database
        $this->settings = array();
        $this->settingTexts = array();
        foreach ($this->opt_override ? : [] as $k => $v) {
            if ($v === null)
                unset($this->opt[$k]);
            else
                $this->opt[$k] = $v;
        }
        $this->opt_override = [];

        $result = $this->q_raw("select name, value, data from Settings");
        while ($result && ($row = $result->fetch_row())) {
            $this->settings[$row[0]] = (int) $row[1];
            if ($row[2] !== null)
                $this->settingTexts[$row[0]] = $row[2];
            if (substr($row[0], 0, 4) == "opt.") {
                $okey = substr($row[0], 4);
                $this->opt_override[$okey] = get($this->opt, $okey);
                $this->opt[$okey] = ($row[2] === null ? (int) $row[1] : $row[2]);
            }
        }
        Dbl::free($result);

        // update schema
        $this->sversion = $this->settings["allowPaperOption"];
        if ($this->sversion < 160) {
            require_once("updateschema.php");
            $old_nerrors = Dbl::$nerrors;
            updateSchema($this);
            Dbl::$nerrors = $old_nerrors;
        }
        if ($this->sversion < 73)
            self::msg_error("Warning: The database could not be upgraded to the current version; expect errors. A system administrator must solve this problem.");

        // invalidate all caches after loading from backup
        if (isset($this->settings["frombackup"])
            && $this->invalidate_caches()) {
            $this->qe_raw("delete from Settings where name='frombackup' and value=" . $this->settings["frombackup"]);
            unset($this->settings["frombackup"]);
        }

        // update options
        if (isset($this->opt["ldapLogin"]) && !$this->opt["ldapLogin"])
            unset($this->opt["ldapLogin"]);
        if (isset($this->opt["httpAuthLogin"]) && !$this->opt["httpAuthLogin"])
            unset($this->opt["httpAuthLogin"]);

        // set conferenceKey
        if (!isset($this->opt["conferenceKey"])) {
            if (!isset($this->settingTexts["conf_key"])
                && ($key = random_bytes(32)) !== false)
                $this->save_setting("conf_key", 1, $key);
            $this->opt["conferenceKey"] = get($this->settingTexts, "conf_key", "");
        }

        // set capability key
        if (!get($this->settings, "cap_key")
            && !get($this->opt, "disableCapabilities")
            && !(($key = random_bytes(16)) !== false
                 && ($key = base64_encode($key))
                 && $this->save_setting("cap_key", 1, $key)))
            $this->opt["disableCapabilities"] = true;

        // GC old capabilities
        if (defval($this->settings, "__capability_gc", 0) < $Now - 86400) {
            foreach (array($this->dblink, Contact::contactdb()) as $db)
                if ($db)
                    Dbl::ql($db, "delete from Capability where timeExpires>0 and timeExpires<$Now");
            $this->q_raw("insert into Settings (name, value) values ('__capability_gc', $Now) on duplicate key update value=values(value)");
            $this->settings["__capability_gc"] = $Now;
        }

        $this->crosscheck_settings();
        $this->crosscheck_options();
    }

    private function crosscheck_settings() {
        global $Now;

        // enforce invariants
        foreach (array("pcrev_any", "extrev_view") as $x)
            if (!isset($this->settings[$x]))
                $this->settings[$x] = 0;
        if (!isset($this->settings["sub_blind"]))
            $this->settings["sub_blind"] = self::BLIND_ALWAYS;
        if (!isset($this->settings["rev_blind"]))
            $this->settings["rev_blind"] = self::BLIND_ALWAYS;
        if (!isset($this->settings["seedec"])) {
            if (get($this->settings, "au_seedec"))
                $this->settings["seedec"] = self::SEEDEC_ALL;
            else if (get($this->settings, "rev_seedec"))
                $this->settings["seedec"] = self::SEEDEC_REV;
        }
        if (get($this->settings, "pc_seeallrev") == 2) {
            $this->settings["pc_seeblindrev"] = 1;
            $this->settings["pc_seeallrev"] = self::PCSEEREV_YES;
        }
        if (($sub_update = get($this->settings, "sub_update", -1)) > 0
            && ($sub_reg = get($this->settings, "sub_reg", -1)) <= 0) {
            $this->settings["sub_reg"] = $sub_update;
            $this->settings["__sub_reg"] = $sub_reg;
        }

        // rounds
        $this->rounds = array("");
        if (isset($this->settingTexts["tag_rounds"])) {
            foreach (explode(" ", $this->settingTexts["tag_rounds"]) as $r)
                if ($r != "")
                    $this->rounds[] = $r;
        }

        // review times
        foreach ($this->rounds as $i => $rname) {
            $suf = $i ? "_$i" : "";
            if (!isset($this->settings["extrev_soft$suf"]) && isset($this->settings["pcrev_soft$suf"]))
                $this->settings["extrev_soft$suf"] = $this->settings["pcrev_soft$suf"];
            if (!isset($this->settings["extrev_hard$suf"]) && isset($this->settings["pcrev_hard$suf"]))
                $this->settings["extrev_hard$suf"] = $this->settings["pcrev_hard$suf"];
        }

        // S3 settings
        foreach (array("s3_bucket", "s3_key", "s3_secret") as $k)
            if (!get($this->settingTexts, $k) && ($x = get($this->opt, $k)))
                $this->settingTexts[$k] = $x;
        if (!get($this->settingTexts, "s3_bucket")
            || !get($this->settingTexts, "s3_key")
            || !get($this->settingTexts, "s3_secret"))
            unset($this->settingTexts["s3_bucket"], $this->settingTexts["s3_key"],
                  $this->settingTexts["s3_secret"]);
        if (get($this->opt, "dbNoPapers") && !get($this->opt, "docstore")
            && !get($this->opt, "filestore") && !get($this->settingTexts, "s3_bucket"))
            unset($this->opt["dbNoPapers"]);
        if ($this->_s3_document
            && (!isset($this->settingTexts["s3_bucket"])
                || !$this->_s3_document->check_key_secret_bucket($this->settingTexts["s3_key"], $this->settingTexts["s3_secret"], $this->settingTexts["s3_bucket"])))
            $this->_s3_document = false;

        // tracks settings
        $this->tracks = $this->_track_tags = null;
        $this->_track_view_sensitivity = $this->_track_review_sensitivity =
            $this->_track_admin_sensitivity = false;
        if (($j = get($this->settingTexts, "tracks")))
            $this->crosscheck_track_settings($j);

        // clear caches
        $this->_decisions = null;
        $this->_pc_seeall_cache = null;
        $this->_defined_rounds = null;
        // digested settings
        $this->_pc_see_pdf = true;
        if (get($this->settings, "sub_freeze", 0) <= 0
            && ($so = get($this->settings, "sub_open", 0)) > 0
            && $so < $Now
            && ($ss = get($this->settings, "sub_sub", 0)) > 0
            && $ss > $Now
            && (get($this->settings, "pc_seeallpdf", 0) <= 0
                || !$this->can_pc_see_all_submissions()))
            $this->_pc_see_pdf = false;

        $this->au_seerev = get($this->settings, "au_seerev", 0);
        if (!$this->au_seerev
            && get($this->settings, "resp_active", 0) > 0
            && $this->time_author_respond_all_rounds())
            $this->au_seerev = self::AUSEEREV_YES;
        $this->tag_au_seerev = null;
        if ($this->au_seerev == self::AUSEEREV_TAGS)
            $this->tag_au_seerev = explode(" ", get_s($this->settingTexts, "tag_au_seerev"));
        $this->tag_seeall = get($this->settings, "tag_seeall", 0) > 0;
    }

    private function crosscheck_track_settings($j) {
        if (is_string($j) && !($j = json_decode($j)))
            return;
        $this->tracks = [];
        $default_track = Track::$zero;
        $this->_track_tags = [];
        foreach ((array) $j as $k => $v) {
            if ($k !== "_")
                $this->_track_tags[] = $k;
            if (!isset($v->viewpdf) && isset($v->view))
                $v->viewpdf = $v->view;
            $t = Track::$zero;
            foreach (Track::$map as $tname => $idx)
                if (isset($v->$tname))
                    $t[$idx] = $v->$tname;
            if ($k === "_")
                $default_track = $t;
            else
                $this->tracks[$k] = $t;
            if ($t[Track::VIEW])
                $this->_track_view_sensitivity = true;
            if ($t[Track::UNASSREV] || $t[Track::ASSREV])
                $this->_track_review_sensitivity = true;
            if ($t[Track::ADMIN])
                $this->_track_admin_sensitivity = true;
        }
        $this->tracks["_"] = $default_track;
    }

    private function crosscheck_options() {
        global $ConfSitePATH;

        // set longName, downloadPrefix, etc.
        $confid = $this->opt["confid"];
        if ((!isset($this->opt["longName"]) || $this->opt["longName"] == "")
            && (!isset($this->opt["shortName"]) || $this->opt["shortName"] == "")) {
            $this->opt["shortNameDefaulted"] = true;
            $this->opt["longName"] = $this->opt["shortName"] = $confid;
        } else if (!isset($this->opt["longName"]) || $this->opt["longName"] == "")
            $this->opt["longName"] = $this->opt["shortName"];
        else if (!isset($this->opt["shortName"]) || $this->opt["shortName"] == "")
            $this->opt["shortName"] = $this->opt["longName"];
        if (!isset($this->opt["downloadPrefix"]) || $this->opt["downloadPrefix"] == "")
            $this->opt["downloadPrefix"] = $confid . "-";
        $this->short_name = $this->opt["shortName"];
        $this->long_name = $this->opt["longName"];

        // expand ${confid}, ${confshortname}
        foreach (array("sessionName", "downloadPrefix", "conferenceSite",
                       "paperSite", "defaultPaperSite", "contactName",
                       "contactEmail", "docstore") as $k)
            if (isset($this->opt[$k]) && is_string($this->opt[$k])
                && strpos($this->opt[$k], "$") !== false) {
                $this->opt[$k] = preg_replace(',\$\{confid\}|\$confid\b,', $confid, $this->opt[$k]);
                $this->opt[$k] = preg_replace(',\$\{confshortname\}|\$confshortname\b,', $this->short_name, $this->opt[$k]);
            }
        $this->download_prefix = $this->opt["downloadPrefix"];

        foreach (array("emailFrom", "emailSender", "emailCc", "emailReplyTo") as $k)
            if (isset($this->opt[$k]) && is_string($this->opt[$k])
                && strpos($this->opt[$k], "$") !== false) {
                $this->opt[$k] = preg_replace(',\$\{confid\}|\$confid\b,', $confid, $this->opt[$k]);
                if (strpos($this->opt[$k], "confshortname") !== false) {
                    $v = rfc2822_words_quote($this->short_name);
                    if ($v[0] === "\"" && strpos($this->opt[$k], "\"") !== false)
                        $v = substr($v, 1, strlen($v) - 2);
                    $this->opt[$k] = preg_replace(',\$\{confshortname\}|\$confshortname\b,', $v, $this->opt[$k]);
                }
            }

        // remove final slash from $Opt["paperSite"]
        if (!isset($this->opt["paperSite"]) || $this->opt["paperSite"] == "")
            $this->opt["paperSite"] = Navigation::site_absolute();
        if ($this->opt["paperSite"] == "" && isset($this->opt["defaultPaperSite"]))
            $this->opt["paperSite"] = $this->opt["defaultPaperSite"];
        $this->opt["paperSite"] = preg_replace('|/+\z|', "", $this->opt["paperSite"]);

        // option name updates (backwards compatibility)
        foreach (array("assetsURL" => "assetsUrl",
                       "jqueryURL" => "jqueryUrl", "jqueryCDN" => "jqueryCdn",
                       "disableCSV" => "disableCsv") as $kold => $knew)
            if (isset($this->opt[$kold]) && !isset($this->opt[$knew]))
                $this->opt[$knew] = $this->opt[$kold];

        // set assetsUrl and scriptAssetsUrl
        if (!isset($this->opt["scriptAssetsUrl"]) && isset($_SERVER["HTTP_USER_AGENT"])
            && strpos($_SERVER["HTTP_USER_AGENT"], "MSIE") !== false)
            $this->opt["scriptAssetsUrl"] = Navigation::siteurl();
        if (!isset($this->opt["assetsUrl"]))
            $this->opt["assetsUrl"] = Navigation::siteurl();
        if ($this->opt["assetsUrl"] !== "" && !str_ends_with($this->opt["assetsUrl"], "/"))
            $this->opt["assetsUrl"] .= "/";
        if (!isset($this->opt["scriptAssetsUrl"]))
            $this->opt["scriptAssetsUrl"] = $this->opt["assetsUrl"];
        Ht::$img_base = $this->opt["assetsUrl"] . "images/";
        Ht::$default_button_class = "btn";

        // set docstore
        if (get($this->opt, "docstore") === true)
            $this->opt["docstore"] = "docs";
        else if (!get($this->opt, "docstore") && get($this->opt, "filestore")) { // backwards compat
            $this->opt["docstore"] = $this->opt["filestore"];
            if ($this->opt["docstore"] === true)
                $this->opt["docstore"] = "filestore";
            $this->opt["docstoreSubdir"] = get($this->opt, "filestoreSubdir");
        }
        if (get($this->opt, "docstore") && $this->opt["docstore"][0] !== "/")
            $this->opt["docstore"] = $ConfSitePATH . "/" . $this->opt["docstore"];
        $this->_docstore = false;
        if (($fdir = get($this->opt, "docstore"))) {
            $fpath = $fdir;
            $use_subdir = get($this->opt, "docstoreSubdir");
            if ($use_subdir && ($use_subdir === true || $use_subdir > 0))
                $fpath .= "/%" . ($use_subdir === true ? 2 : $use_subdir) . "h";
            $this->_docstore = [$fdir, $fpath . "/%h%x"];
        }

        // handle timezone
        if (function_exists("date_default_timezone_set")) {
            if (isset($this->opt["timezone"])) {
                if (!date_default_timezone_set($this->opt["timezone"])) {
                    self::msg_error("Timezone option “" . htmlspecialchars($this->opt["timezone"]) . "” is invalid; falling back to “America/New_York”.");
                    date_default_timezone_set("America/New_York");
                }
            } else if (!ini_get("date.timezone") && !getenv("TZ"))
                date_default_timezone_set("America/New_York");
        }
        $this->_date_format_initialized = false;

        // set safePasswords
        if (!get($this->opt, "safePasswords")
            || (is_int($this->opt["safePasswords"]) && $this->opt["safePasswords"] < 1))
            $this->opt["safePasswords"] = 0;
        else if ($this->opt["safePasswords"] === true)
            $this->opt["safePasswords"] = 1;
        if (!isset($this->opt["contactdb_safePasswords"]))
            $this->opt["contactdb_safePasswords"] = $this->opt["safePasswords"];

        // set defaultFormat
        $this->default_format = (int) get($this->opt, "defaultFormat");
        $this->_format_info = null;

        // other caches
        $sort_by_last = !!get($this->opt, "sortByLastName");
        if (!$this->sort_by_last != !$sort_by_last)
            $this->_pc_members_cache = null;
        $this->sort_by_last = $sort_by_last;
        $this->_api_map = null;
    }

    function has_setting($name) {
        return isset($this->settings[$name]);
    }

    function setting($name, $defval = null) {
        return get($this->settings, $name, $defval);
    }

    function setting_data($name, $defval = false) {
        return get($this->settingTexts, $name, $defval);
    }

    function setting_json($name, $defval = false) {
        $x = get($this->settingTexts, $name, $defval);
        return is_string($x) ? json_decode($x) : $x;
    }

    function opt($name, $defval = null) {
        return get($this->opt, $name, $defval);
    }

    function set_opt($name, $value) {
        global $Opt;
        $Opt[$name] = $this->opt[$name] = $value;
    }

    function unset_opt($name) {
        global $Opt;
        unset($Opt[$name], $this->opt[$name]);
    }


    // database

    function q(/* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), 0);
    }
    function q_raw(/* $qstr */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_RAW);
    }
    function q_apply(/* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_APPLY);
    }

    function ql(/* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_LOG);
    }
    function ql_raw(/* $qstr */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_RAW | Dbl::F_LOG);
    }
    function ql_apply(/* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_APPLY | Dbl::F_LOG);
    }

    function qe(/* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR);
    }
    function qe_raw(/* $qstr */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_RAW | Dbl::F_ERROR);
    }
    function qe_apply(/* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_APPLY | Dbl::F_ERROR);
    }

    function fetch_rows(/* $qstr, ... */) {
        return Dbl::fetch_rows(Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR));
    }
    function fetch_value(/* $qstr, ... */) {
        return Dbl::fetch_value(Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR));
    }
    function fetch_ivalue(/* $qstr, ... */) {
        return Dbl::fetch_ivalue(Dbl::do_query_on($this->dblink, func_get_args(), Dbl::F_ERROR));
    }

    function db_error_html($getdb = true, $while = "") {
        $text = "<p>Database error";
        if ($while)
            $text .= " $while";
        if ($getdb)
            $text .= ": " . htmlspecialchars($this->dblink->error);
        return $text . "</p>";
    }

    function db_error_text($getdb = true, $while = "") {
        $text = "Database error";
        if ($while)
            $text .= " $while";
        if ($getdb)
            $text .= ": " . $this->dblink->error;
        return $text;
    }

    function query_error_handler($dblink, $query) {
        $landmark = caller_landmark(1, "/^(?:Dbl::|Conf::q|call_user_func)/");
        if (PHP_SAPI == "cli")
            fwrite(STDERR, "$landmark: database error: $dblink->error in $query\n");
        else {
            error_log("$landmark: database error: $dblink->error in $query");
            self::msg_error("<p>" . htmlspecialchars($landmark) . ": database error: " . htmlspecialchars($this->dblink->error) . " in " . Ht::pre_text_wrap($query) . "</p>");
        }
    }


    // name

    function full_name() {
        if ($this->short_name && $this->short_name != $this->long_name)
            return $this->long_name . " (" . $this->short_name . ")";
        else
            return $this->long_name;
    }


    function docclass($dtype) {
        if (!isset($this->_docclass_cache[$dtype]))
            $this->_docclass_cache[$dtype] = new HotCRPDocument($this, $dtype);
        return $this->_docclass_cache[$dtype];
    }

    function docstore() {
        return $this->_docstore;
    }

    function s3_docstore() {
        global $Now;
        if ($this->_s3_document === false) {
            if ($this->setting_data("s3_bucket")) {
                $opts = ["bucket" => $this->setting_data("s3_bucket"),
                         "key" => $this->setting_data("s3_key"),
                         "secret" => $this->setting_data("s3_secret"),
                         "scope" => $this->setting_data("__s3_scope"),
                         "signing_key" => $this->setting_data("__s3_signing_key")];
                $this->_s3_document = new S3Document($opts);
                list($scope, $signing_key) = $this->_s3_document->scope_and_signing_key($Now);
                if ($opts["scope"] !== $scope || $opts["signing_key"] !== $signing_key) {
                    $this->save_setting("__s3_scope", 1, $scope);
                    $this->save_setting("__s3_signing_key", 1, $signing_key);
                }
            } else
                $this->_s3_document = null;
        }
        return $this->_s3_document;
    }


    function _add_emoji_code($val, $key) {
        if (is_string($val) && str_starts_with($key, ":") && str_ends_with($key, ":")) {
            $this->_emoji_codes[$key] = $val;
            return true;
        } else
            return false;
    }
    function emoji_code_map() {
        global $ConfSitePATH;
        if ($this->_emoji_codes === null) {
            $this->_emoji_codes = json_decode(file_get_contents("$ConfSitePATH/etc/emojicodes.json"), true);
            if (($olist = $this->opt("emojiCodes")))
                expand_json_includes_callback($olist, [$this, "_add_emoji_code", null, true]);
        }
        return $this->_emoji_codes;
    }


    private function _add_search_keyword_json_base($name, $kwj) {
        $b = get($this->_search_keyword_base, $name);
        if (!$b || get($b, "priority", 0) <= get($kwj, "priority", 0))
            $this->_search_keyword_base[$name] = $kwj;
    }
    function _add_search_keyword_json($kwj) {
        if (isset($kwj->name) && is_string($kwj->name)) {
            $this->_add_search_keyword_json_base($kwj->name, $kwj);
            if (($syn = get($kwj, "synonym")))
                foreach (is_string($syn) ? [$syn] : $syn as $x)
                    $this->_add_search_keyword_json_base($x, $kwj);
            return true;
        } else if (is_string($kwj->match) && is_string($kwj->factory)) {
            $this->_search_keyword_factories[] = $kwj;
            return true;
        } else
            return false;
    }
    private function make_search_keyword_map() {
        $this->_search_keyword_base = $this->_search_keyword_factories =
            $this->_search_keywords = [];
        expand_json_includes_callback(["etc/searchkeywords.json"], [$this, "_add_search_keyword_json"]);
        if (($olist = $this->opt("searchKeywords")))
            expand_json_includes_callback($olist, [$this, "_add_search_keyword_json"]);
    }
    function add_search_keyword($kwj) {
        assert(is_object($kwj) && is_string($kwj->name));
        assert(array_key_exists($kwj->name, $this->_search_keywords));
        $b = $this->_search_keywords[$kwj->name];
        if (!$b || get($b, "priority", 0) <= get($kwj, "priority", 0))
            $this->_search_keywords[$kwj->name] = $kwj;
    }
    function search_keyword($keyword) {
        if ($this->_search_keywords === null)
            $this->make_search_keyword_map();
        if (array_key_exists($keyword, $this->_search_keywords))
            return $this->_search_keywords[$keyword];
        $kwj = get($this->_search_keyword_base, $keyword);
        $this->_search_keywords[$keyword] = $kwj;
        foreach ($this->_search_keyword_factories as $kwfj) {
            if ((!$kwj || get($kwj, "priority", 0) <= get($kwfj, "priority", 0))
                && preg_match("\1\\A(?:" . $kwfj->match . ")\\z\1", $keyword, $m)) {
                $x = call_user_func($kwfj->factory, $keyword, $this, $kwfj, $m);
                if ($x && (is_object($x) || is_array($x)))
                    $this->add_search_keyword((object) $x);
                $kwj = $this->_search_keywords[$keyword];
            }
        }
        return $kwj;
    }


    function _add_formula_json($fj) {
        if (is_string($fj->name)) {
            if (!isset($this->_formula_functions[$fj->name])
                || get($this->_formula_functions[$fj->name], "priority", 0) <= get($fj, "priority", 0))
                $this->_formula_functions[$fj->name] = $fj;
            return true;
        } else
            return false;
    }
    function formula_functions() {
        if ($this->_formula_functions === null) {
            $this->_formula_functions = [];
            expand_json_includes_callback(["etc/formulafunctions.json"], [$this, "_add_formula_json"]);
            if (($olist = $this->opt("formulaFunctions")))
                expand_json_includes_callback($olist, [$this, "_add_formula_json"]);
        }
        return $this->_formula_functions;
    }

    function defined_formula_map(Contact $user) {
        if ($this->_defined_formulas !== null && !empty($this->_defined_formulas)) {
            reset($this->_defined_formulas);
            if (current($this->_defined_formulas)->user !== $user)
                $this->_defined_formulas = null;
        }
        if ($this->_defined_formulas === null) {
            $this->_defined_formulas = [];
            if ($this->setting("formulas")) {
                $result = $this->q("select * from Formula order by lower(name)");
                while ($result && ($f = Formula::fetch($user, $result)))
                    $this->_defined_formulas[$f->formulaId] = $f;
            }
        }
        return $this->_defined_formulas;
    }


    function decision_map() {
        if ($this->_decisions === null) {
            $this->_decisions = array();
            if (($j = get($this->settingTexts, "outcome_map"))
                && ($j = json_decode($j, true))
                && is_array($j))
                $this->_decisions = $j;
            $this->_decisions[0] = "Unspecified";
        }
        return $this->_decisions;
    }

    function decision_name($dnum) {
        if ($this->_decisions === null)
            $this->decision_map();
        if (($dname = get($this->_decisions, $dnum)))
            return $dname;
        else
            return false;
    }

    static function decision_name_error($dname) {
        $dname = simplify_whitespace($dname);
        if ((string) $dname === "")
            return "Empty decision name.";
        else if (preg_match(',\A(?:yes|no|any|none|unknown|unspecified)\z,i', $dname))
            return "Decision name “{$dname}” is reserved.";
        else
            return false;
    }



    function topic_map() {
        if ($this->_topic_map === null) {
            $this->_topic_map = $tx = [];
            $result = $this->qe_raw("select topicId, topicName from TopicArea order by topicName");
            while (($row = edb_row($result))) {
                if (strcasecmp(substr($row[1], 0, 7), "none of") == 0)
                    $tx[(int) $row[0]] = $row[1];
                else
                    $this->_topic_map[(int) $row[0]] = $row[1];
            }
            Dbl::free($result);
            foreach ($tx as $tid => $tname)
                $this->_topic_map[$tid] = $tname;
        }
        return $this->_topic_map;
    }

    function topic_order_map() {
        if ($this->_topic_order_map === null) {
            $this->_topic_order_map = [];
            foreach ($this->topic_map() as $tid => $tname)
                $this->_topic_order_map[$tid] = count($this->_topic_order_map);
        }
        return $this->_topic_order_map;
    }

    function has_topics() {
        $this->topic_map();
        return !empty($this->_topic_map);
    }

    function topic_count() {
        return count($this->topic_map());
    }

    function topic_separator() {
        if ($this->_topic_separator_cache === null) {
            $this->_topic_separator_cache = ", ";
            foreach ($this->topic_map() as $tname)
                if (strpos($tname, ",") !== false) {
                    $this->_topic_separator_cache = "; ";
                    break;
                }
        }
        return $this->_topic_separator_cache;
    }

    function invalidate_topics() {
        $this->_topic_map = $this->_topic_order_map = null;
        $this->_topic_separator_cache = null;
    }


    private function _abbrev_matcher() {
        if (!$this->_abbrev_matcher) {
            $this->_abbrev_matcher = new AbbreviationMatcher;
            $this->_abbrev_matcher->add("paper", $this->paper_opts->find_document(DTYPE_SUBMISSION), 1);
            $this->_abbrev_matcher->add("submission", $this->paper_opts->find_document(DTYPE_SUBMISSION), 1);
            if ($this->has_any_accepts()) {
                $ol = $this->paper_opts->option_list();
                $this->_abbrev_matcher->add("final", $this->paper_opts->find_document(DTYPE_FINAL), 1);
            } else
                $ol = $this->paper_opts->nonfinal_option_list();
            // XXX exposes invisible paper options, review fields
            foreach ($ol as $o)
                $this->_abbrev_matcher->add($o->name, $o, 1);
            foreach ($this->all_review_fields() as $f)
                if ($f->displayed)
                    $this->_abbrev_matcher->add($f->name, $f, 2);
        }
        return $this->_abbrev_matcher;
    }

    function field_search($text, $tflags = 0) {
        return $this->_abbrev_matcher()->find($text, $tflags);
    }


    function review_form_json() {
        $x = get($this->settingTexts, "review_form");
        if (is_string($x))
            $x = $this->settingTexts["review_form"] = json_decode($x);
        return is_object($x) ? $x : null;
    }

    function review_form() {
        if (!$this->_review_form_cache)
            $this->_review_form_cache = new ReviewForm($this->review_form_json(), $this);
        return $this->_review_form_cache;
    }

    function all_review_fields() {
        return $this->review_form()->all_fields();
    }

    function review_field($fid) {
        return $this->review_form()->field($fid);
    }

    function review_field_search($text) {
        return $this->_abbrev_matcher()->find1($text, 2);
    }



    function tags() {
        if (!$this->_taginfo)
            $this->_taginfo = TagMap::make($this);
        return $this->_taginfo;
    }



    function has_tracks() {
        return $this->tracks !== null;
    }

    function has_track_tags() {
        return $this->_track_tags !== null;
    }

    function track_tags() {
        return $this->_track_tags ? $this->_track_tags : array();
    }

    function check_tracks(PaperInfo $prow, Contact $contact, $type) {
        if ($this->tracks) {
            $matched = false;
            foreach ($this->tracks as $t => $tr)
                if ($t === "_" ? !$matched : $prow->has_tag($t)) {
                    $matched = true;
                    if (!Track::match_perm($contact, $tr[$type]))
                        return false;
                }
        }
        return true;
    }

    function check_admin_tracks(PaperInfo $prow, Contact $contact) {
        if ($this->_track_admin_sensitivity) {
            $matched = false;
            foreach ($this->tracks as $t => $tr)
                if ($t === "_" ? !$matched : $prow->has_tag($t)) {
                    $matched = true;
                    if ($tr[Track::ADMIN] && Track::match_perm($contact, $tr[Track::ADMIN]))
                        return true;
                }
        }
        return false;
    }

    function check_default_track(Contact $contact, $type) {
        return !$this->tracks || Track::match_perm($contact, $this->tracks["_"][$type]);
    }

    function check_any_tracks(Contact $contact, $type) {
        if ($this->tracks)
            foreach ($this->tracks as $t => $tr)
                if (($type === Track::VIEW
                     || Track::match_perm($contact, $tr[Track::VIEW]))
                    && Track::match_perm($contact, $tr[$type]))
                    return true;
        return !$this->tracks;
    }

    function check_any_admin_tracks(Contact $contact) {
        if ($this->_track_admin_sensitivity)
            foreach ($this->tracks as $t => $tr)
                if ($tr[Track::ADMIN] && Track::match_perm($contact, $tr[Track::ADMIN]))
                    return true;
        return false;
    }

    function check_all_tracks(Contact $contact, $type) {
        if ($this->tracks)
            foreach ($this->tracks as $t => $tr)
                if (!(($type === Track::VIEW
                       || Track::match_perm($contact, $tr[Track::VIEW]))
                      && Track::match_perm($contact, $tr[$type])))
                    return false;
        return true;
    }

    function check_track_sensitivity($type) {
        if ($this->tracks)
            foreach ($this->tracks as $t => $tr)
                if ($tr[$type] !== null)
                    return true;
        return false;
    }

    function check_track_view_sensitivity() {
        return $this->_track_view_sensitivity;
    }

    function check_track_review_sensitivity() {
        return $this->_track_review_sensitivity;
    }


    function has_rounds() {
        return count($this->rounds) > 1;
    }

    function round_list() {
        return $this->rounds;
    }

    function round0_defined() {
        return isset($this->defined_round_list()[0]);
    }

    function defined_round_list() {
        if ($this->_defined_rounds === null) {
            $r = $dl = [];
            foreach ($this->rounds as $i => $rname)
                if (!$i || $rname !== ";") {
                    foreach (self::$review_deadlines as $rd)
                        if (($dl[$i] = get($this->settings, $rd . ($i ? "_$i" : ""))))
                            break;
                    $i && ($r[$i] = $rname);
                }
            if (!$dl[0]) {
                $result = $this->qe("select reviewId from PaperReview where reviewRound=0 limit 1");
                if (!$result || !$result->num_rows)
                    unset($dl[0]);
                Dbl::free($result);
            }
            array_key_exists(0, $dl) && ($r[0] = "unnamed");
            uasort($r, function ($a, $b) use ($dl) {
                $adl = get($dl, $a);
                $bdl = get($dl, $b);
                if ($adl && $bdl && $adl != $bdl)
                    return $adl < $bdl ? -1 : 1;
                else if (!$adl != !$bdl)
                    return $adl ? -1 : 1;
                else
                    return strcmp($a !== "unnamed" ? $a : "",
                                  $b !== "unnamed" ? $b : "");
            });
            $this->_defined_rounds = $r;
        }
        return $this->_defined_rounds;
    }

    function round_name($roundno) {
        if ($roundno > 0) {
            if (($rname = get($this->rounds, $roundno)) && $rname !== ";")
                return $rname;
            error_log($this->dbname . ": round #$roundno undefined");
        }
        return "";
    }

    function round_suffix($roundno) {
        if ($roundno > 0) {
            if (($rname = get($this->rounds, $roundno)) && $rname !== ";")
                return "_$rname";
        }
        return "";
    }

    static function round_name_error($rname) {
        if ((string) $rname === "")
            return "Empty round name.";
        else if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9]*\z/', $rname))
            return "Round names must start with a letter and contain only letters and numbers.";
        else if (preg_match('/\A(?:none|any|all|default|unnamed|.*response)\z/i', $rname))
            return "Round name $rname is reserved.";
        else
            return false;
    }

    function sanitize_round_name($rname) {
        if ($rname === null)
            return $this->assignment_round_name(false);
        else if ($rname === "" || preg_match('/\A(?:\(none\)|none|default|unnamed)\z/i', $rname))
            return "";
        else if (self::round_name_error($rname))
            return false;
        else
            return $rname;
    }

    function assignment_round_name($external) {
        if ($external && ($x = get($this->settingTexts, "extrev_roundtag")) !== null)
            return $x;
        else
            return (string) get($this->settingTexts, "rev_roundtag");
    }

    function assignment_round($external) {
        return $this->round_number($this->assignment_round_name($external), false);
    }

    function round_number($name, $add) {
        if (!$name || !strcasecmp($name, "default") || !strcasecmp($name, "unnamed"))
            return 0;
        for ($i = 1; $i != count($this->rounds); ++$i)
            if (!strcasecmp($this->rounds[$i], $name))
                return $i;
        if ($add) {
            $rtext = $this->setting_data("tag_rounds", "");
            $rtext = ($rtext ? "$rtext$name " : " $name ");
            $this->save_setting("tag_rounds", 1, $rtext);
            return $this->round_number($name, false);
        } else
            return 0;
    }

    function round_selector_options() {
        $opt = array();
        foreach ($this->defined_round_list() as $rname)
            $opt[$rname] = $rname;
        $crname = $this->assignment_round_name(false) ? : "unnamed";
        if ($crname && !get($opt, $crname))
            $opt[$crname] = $crname;
        return $opt;
    }



    function resp_round_list() {
        if (($x = get($this->settingTexts, "resp_rounds")))
            return explode(" ", $x);
        else
            return array(1);
    }

    function resp_round_name($rnum) {
        if (($x = get($this->settingTexts, "resp_rounds"))) {
            $x = explode(" ", $x);
            if (($n = get($x, $rnum)))
                return $n;
        }
        return "1";
    }

    function resp_round_text($rnum) {
        $rname = $this->resp_round_name($rnum);
        return $rname == "1" ? "" : $rname;
    }

    static function resp_round_name_error($rname) {
        if ((string) $rname === "")
            return "Empty round name.";
        else if (!strcasecmp($rname, "none") || !strcasecmp($rname, "any")
                 || stri_ends_with($rname, "response"))
            return "Round name “{$rname}” is reserved.";
        else if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $rname))
            return "Round names must start with a letter and contain letters and numbers.";
        else
            return false;
    }

    function resp_round_number($rname) {
        if (!$rname || $rname === 1 || $rname === "1" || $rname === true
            || !strcasecmp($rname, "none"))
            return 0;
        $rtext = (string) get($this->settingTexts, "resp_rounds");
        foreach (explode(" ", $rtext) as $i => $x)
            if (!strcasecmp($x, $rname))
                return $i;
        return false;
    }


    function format_info($format) {
        if ($this->_format_info === null) {
            $this->_format_info = [];
            if (!isset($this->opt["formatInfo"]))
                /* OK */;
            else if (is_array($this->opt["formatInfo"]))
                $this->_format_info = $this->opt["formatInfo"];
            else if (is_string($this->opt["formatInfo"]))
                $this->_format_info = json_decode($this->opt["formatInfo"], true);
        }
        if ($format === null)
            $format = $this->default_format;
        return get($this->_format_info, $format);
    }

    public function check_format($format, $text = null) {
        if ($format === null)
            $format = $this->default_format;
        if ($format && $text !== null && ($f = $this->format_info($format))
            && ($re = get($f, "simple_regex")) && preg_match($re, $text))
            $format = 0;
        return $format;
    }


    function saved_searches() {
        $ss = [];
        foreach ($this->settingTexts as $k => $v)
            if (substr($k, 0, 3) === "ss:" && ($v = json_decode($v)))
                $ss[substr($k, 3)] = $v;
        return $ss;
    }


    // users

    function external_login() {
        return isset($this->opt["ldapLogin"]) || isset($this->opt["httpAuthLogin"]);
    }

    function site_contact() {
        $contactEmail = $this->opt("contactEmail");
        if (!$contactEmail || $contactEmail == "you@example.com") {
            $result = $this->ql("select firstName, lastName, email from ContactInfo where roles!=0 and (roles&" . (Contact::ROLE_CHAIR | Contact::ROLE_ADMIN) . ")!=0 order by (roles&" . Contact::ROLE_CHAIR . ") desc limit 1");
            if ($result && ($row = $result->fetch_object())) {
                $this->set_opt("defaultSiteContact", true);
                $this->set_opt("contactName", Text::name_text($row));
                $this->set_opt("contactEmail", $row->email);
            }
            Dbl::free($result);
        }
        return new Contact((object) array("fullName" => $this->opt["contactName"],
                                          "email" => $this->opt["contactEmail"],
                                          "isChair" => true,
                                          "isPC" => true,
                                          "is_site_contact" => true,
                                          "contactTags" => null), $this);
    }

    function user_by_id($id) {
        $result = $this->qe("select ContactInfo.* from ContactInfo where contactId=?", $id);
        $acct = Contact::fetch($result, $this);
        Dbl::free($result);
        return $acct;
    }

    function user_by_email($email) {
        $acct = null;
        if (($email = trim((string) $email)) !== "") {
            $result = $this->qe("select * from ContactInfo where email=?", $email);
            $acct = Contact::fetch($result, $this);
            Dbl::free($result);
        }
        return $acct;
    }

    function user_id_by_email($email) {
        $result = $this->qe("select contactId from ContactInfo where email=?", trim($email));
        $row = edb_row($result);
        Dbl::free($result);
        return $row ? (int) $row[0] : false;
    }

    function pc_members() {
        if ($this->_pc_members_cache === null) {
            $pc = array();
            $result = $this->q("select firstName, lastName, affiliation, email, contactId, roles, contactTags, disabled from ContactInfo where roles!=0 and (roles&" . Contact::ROLE_PCLIKE . ")!=0");
            $by_name_text = array();
            $this->_pc_tags_cache = ["pc" => "pc"];
            while ($result && ($row = Contact::fetch($result, $this))) {
                $pc[$row->contactId] = $row;
                if ($row->firstName || $row->lastName) {
                    $name_text = Text::name_text($row);
                    if (isset($by_name_text[$name_text]))
                        $row->nameAmbiguous = $by_name_text[$name_text]->nameAmbiguous = true;
                    $by_name_text[$name_text] = $row;
                }
                if ($row->contactTags)
                    foreach (explode(" ", $row->contactTags) as $t) {
                        list($tag, $value) = TagInfo::unpack($t);
                        if ($tag)
                            $this->_pc_tags_cache[strtolower($tag)] = $tag;
                    }
            }
            Dbl::free($result);
            uasort($pc, "Contact::compare");
            $this->_pc_members_and_admins_cache = $pc;
            $pc = array_filter($pc, function ($p) { return ($p->roles & Contact::ROLE_PC) != 0; });
            $order = 0;
            foreach ($pc as $row) {
                $row->sort_position = $order;
                ++$order;
            }
            $this->_pc_members_cache = $pc;
            ksort($this->_pc_tags_cache);
        }
        return $this->_pc_members_cache;
    }

    function pc_members_and_admins() {
        if ($this->_pc_members_and_admins_cache === null)
            $this->pc_members();
        return $this->_pc_members_and_admins_cache;
    }

    function pc_member_by_email($email) {
        foreach ($this->pc_members() as $p)
            if (strcasecmp($p->email, $email) == 0)
                return $p;
        return null;
    }

    function pc_tags() {
        if ($this->_pc_tags_cache === null)
            $this->pc_members();
        return $this->_pc_tags_cache;
    }

    function pc_tag_exists($tag) {
        if ($this->_pc_tags_cache === null)
            $this->pc_members();
        return isset($this->_pc_tags_cache[strtolower($tag)]);
    }

    function pc_completion_map() {
        $map = $bylevel = [];
        foreach ($this->pc_members_and_admins() as $pc)
            if (!$pc->disabled) {
                foreach ($pc->completion_items() as $k => $level) {
                    if (!isset($bylevel[$k])
                        || $bylevel[$k] < $level
                        || get($map, $k) === $pc) {
                        $map[$k] = $pc;
                        $bylevel[$k] = $level;
                    } else
                        unset($map[$k]);
                }
            }
        return $map;
    }


    // session data

    function session($name, $defval = null) {
        if (isset($_SESSION[$this->dsn][$name]))
            return $_SESSION[$this->dsn][$name];
        else
            return $defval;
    }

    function save_session($name, $value) {
        if ($value !== null)
            $_SESSION[$this->dsn][$name] = $value;
        else
            unset($_SESSION[$this->dsn][$name]);
    }

    function capability_text($prow, $capType) {
        // A capability has the following representation (. is concatenation):
        //    capFormat . paperId . capType . hashPrefix
        // capFormat -- Character denoting format (currently 0).
        // paperId -- Decimal representation of paper number.
        // capType -- Capability type (e.g. "a" for author view).
        // To create hashPrefix, calculate a SHA-1 hash of:
        //    capFormat . paperId . capType . paperCapVersion . capKey
        // where paperCapVersion is a decimal representation of the paper's
        // capability version (usually 0, but could allow conference admins
        // to disable old capabilities paper-by-paper), and capKey
        // is a random string specific to the conference, stored in Settings
        // under cap_key (created in load_settings).  Then hashPrefix
        // is the base-64 encoding of the first 8 bytes of this hash, except
        // that "+" is re-encoded as "-", "/" is re-encoded as "_", and
        // trailing "="s are removed.
        //
        // Any user who knows the conference's cap_key can construct any
        // capability for any paper.  Longer term, one might set each paper's
        // capVersion to a random value; but the only way to get cap_key is
        // database access, which would give you all the capVersions anyway.

        if (!isset($this->settingTexts["cap_key"]))
            return false;
        $start = "0" . $prow->paperId . $capType;
        $hash = sha1($start . $prow->capVersion . $this->settingTexts["cap_key"], true);
        $suffix = str_replace(array("+", "/", "="), array("-", "_", ""),
                              base64_encode(substr($hash, 0, 8)));
        return $start . $suffix;
    }


    // update the 'papersub' setting: are there any submitted papers?
    function update_papersub_setting($forsubmit) {
        $papersub = defval($this->settings, "papersub");
        if ($papersub === null && $forsubmit)
            $this->q_raw("insert into Settings (name, value) values ('papersub',1) on duplicate key update value=value");
        else if ($papersub <= 0 || !$forsubmit)
            // see also settings.php
            $this->q_raw("update Settings set value=(select ifnull(min(paperId),0) from Paper where " . ($this->can_pc_see_all_submissions() ? "timeWithdrawn<=0" : "timeSubmitted>0") . ") where name='papersub'");
        $this->settings["papersub"] = $this->fetch_ivalue("select value from Settings where name='papersub'");
    }

    function update_paperacc_setting($foraccept) {
        global $Now;
        if (!isset($this->settings["paperacc"]) && $foraccept)
            $this->q_raw("insert into Settings (name, value) values ('paperacc', $Now) on duplicate key update value=value");
        else if (get($this->settings, "paperacc", 0) <= 0 || !$foraccept)
            $this->q_raw("update Settings set value=coalesce((select timeSubmitted from Paper where timeSubmitted>0 and outcome>0 limit 1),0) where name='paperacc'");
        $this->settings["paperacc"] = $this->fetch_ivalue("select value from Settings where name='paperacc'");
    }

    function update_rev_tokens_setting($always) {
        if ($always || get($this->settings, "rev_tokens", 0) < 0) {
            $this->qe_raw("insert into Settings (name, value) select 'rev_tokens', count(reviewId) from PaperReview where reviewToken!=0 on duplicate key update value=values(value)");
            $this->settings["rev_tokens"] = $this->fetch_ivalue("select value from Settings where name='rev_tokens'");
        }
    }

    function update_paperlead_setting() {
        $this->qe_raw("insert into Settings (name, value) select 'paperlead', count(paperId) from Paper where leadContactId>0 or shepherdContactId>0 limit 1 on duplicate key update value=values(value)");
        $this->settings["paperlead"] = $this->fetch_ivalue("select value from Settings where name='paperlead'");
    }

    function update_papermanager_setting() {
        $this->qe_raw("insert into Settings (name, value) select 'papermanager', count(paperId) from Paper where managerContactId>0 limit 1 on duplicate key update value=values(value)");
        $this->settings["papermanager"] = $this->fetch_ivalue("select value from Settings where name='papermanager'");
    }


    static private $invariant_row = null;

    private function invariantq($q, $args = []) {
        $result = $this->ql_apply($q, $args);
        if ($result) {
            self::$invariant_row = $result->fetch_row();
            $result->close();
            return !!self::$invariant_row;
        } else
            return null;
    }

    function check_invariants() {
        $any = $this->invariantq("select paperId from Paper where " . ($this->can_pc_see_all_submissions() ? "timeWithdrawn<=0" : "timeSubmitted>0") . " limit 1");
        if ($any !== !!get($this->settings, "papersub"))
            trigger_error("$this->dbname invariant error: papersub");

        $any = $this->invariantq("select paperId from Paper where outcome>0 and timeSubmitted>0 limit 1");
        if ($any !== !!get($this->settings, "paperacc"))
            trigger_error("$this->dbname invariant error: paperacc");

        $any = $this->invariantq("select reviewId from PaperReview where reviewToken!=0 limit 1");
        if ($any !== !!get($this->settings, "rev_tokens"))
            trigger_error("$this->dbname invariant error: rev_tokens");

        $any = $this->invariantq("select paperId from Paper where leadContactId>0 or shepherdContactId>0 limit 1");
        if ($any !== !!get($this->settings, "paperlead"))
            trigger_error("$this->dbname invariant error: paperlead");

        $any = $this->invariantq("select paperId from Paper where managerContactId>0 limit 1");
        if ($any !== !!get($this->settings, "papermanager"))
            trigger_error("$this->dbname invariant error: papermanager");

        // no empty text options
        $text_options = array();
        foreach ($this->paper_opts->option_list() as $ox)
            if ($ox->type === "text")
                $text_options[] = $ox->id;
        if (count($text_options)) {
            $q = Dbl::format_query($this->dblink, "select paperId from PaperOption where optionId ?a and data='' limit 1", $text_options);
            $any = $this->invariantq($q);
            if ($any)
                trigger_error("$this->dbname invariant error: text option with empty text");
        }

        // no funky PaperConflict entries
        $any = $this->invariantq("select paperId from PaperConflict where conflictType<=0 limit 1");
        if ($any)
            trigger_error("$this->dbname invariant error: PaperConflict with zero conflictType");

        // reviewNeedsSubmit is defined correctly
        $any = $this->invariantq("select r.paperId, r.reviewId from PaperReview r
            left join (select paperId, requestedBy, count(reviewId) ct, count(reviewSubmitted) cs
                       from PaperReview where reviewType<" . REVIEW_SECONDARY . "
                       group by paperId, requestedBy) q
                on (q.paperId=r.paperId and q.requestedBy=r.contactId)
            where r.reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null
            and if(coalesce(q.ct,0)=0,1,if(q.cs=0,-1,0))!=r.reviewNeedsSubmit
            limit 1");
        if ($any)
            trigger_error("$this->dbname invariant error: bad reviewNeedsSubmit for review #" . self::$invariant_row[0] . "/" . self::$invariant_row[1]);

        // anonymous users are disabled
        $any = $this->invariantq("select email from ContactInfo where email regexp '^anonymous[0-9]*\$' and not disabled limit 1");
        if ($any)
            trigger_error("$this->dbname invariant error: anonymous user is not disabled");

        // no one has password '*'
        $any = $this->invariantq("select email from ContactInfo where password='*' limit 1");
        if ($any)
            trigger_error("$this->dbname invariant error: password '*'");

        // paper denormalizations match
        $any = $this->invariantq("select p.paperId from Paper p join PaperStorage ps on (ps.paperStorageId=p.paperStorageId) where p.finalPaperStorageId<=0 and p.paperStorageId>1 and (p.sha1!=ps.sha1 or p.size!=ps.size or p.mimetype!=ps.mimetype or p.timestamp!=ps.timestamp) limit 1");
        if ($any)
            trigger_error("$this->dbname invariant error: bad Paper denormalization, paper #" . self::$invariant_row[0]);
        $any = $this->invariantq("select p.paperId from Paper p join PaperStorage ps on (ps.paperStorageId=p.finalPaperStorageId) where p.finalPaperStorageId>1 and (p.sha1 != ps.sha1 or p.size!=ps.size or p.mimetype!=ps.mimetype or p.timestamp!=ps.timestamp) limit 1");
        if ($any)
            trigger_error("$this->dbname invariant error: bad Paper final denormalization, paper #" . self::$invariant_row[0]);

        // filterType is never zero
        $any = $this->invariantq("select paperStorageId from PaperStorage where filterType=0 limit 1");
        if ($any)
            trigger_error("$this->dbname invariant error: bad PaperStorage filterType, id #" . self::$invariant_row[0]);

        // has_colontag is defined
        $any = $this->invariantq("select tag from PaperTag where tag like '%:' limit 1");
        if ($any && !$this->setting("has_colontag"))
            trigger_error("$this->dbname invariant error: has tag " . self::$invariant_row[0] . " but no has_colontag");
    }


    function save_setting($name, $value, $data = null) {
        $change = false;
        if ($value === null && $data === null) {
            if ($this->qe("delete from Settings where name=?", $name)) {
                unset($this->settings[$name]);
                unset($this->settingTexts[$name]);
                $change = true;
            }
        } else {
            $dval = $data;
            if (is_array($dval) || is_object($dval))
                $dval = json_encode($dval);
            if ($this->qe("insert into Settings (name, value, data) values (?, ?, ?) on duplicate key update value=values(value), data=values(data)", $name, $value, $dval)) {
                $this->settings[$name] = $value;
                $this->settingTexts[$name] = $data;
                $change = true;
            }
        }
        if ($change) {
            $this->crosscheck_settings();
            if (str_starts_with($name, "opt."))
                $this->crosscheck_options();
        }
        return $change;
    }

    function update_schema_version($n) {
        if (!$n)
            $n = $this->fetch_ivalue("select value from Settings where name='allowPaperOption'");
        if ($n && $this->ql("update Settings set value=? where name='allowPaperOption'", $n)) {
            $this->sversion = $this->settings["allowPaperOption"] = $n;
            return true;
        } else
            return false;
    }

    function invalidate_caches($caches = null) {
        if (!self::$no_invalidate_caches) {
            if (!$caches || isset($caches["pc"]))
                $this->_pc_members_cache = $this->_pc_tags_cache = $this->_pc_members_and_admins_cache = null;
            if (!$caches || isset($caches["paperOption"])) {
                $this->paper_opts->invalidate_option_list();
                $this->_docclass_cache = [];
                $this->_abbrev_matcher = null;
            }
            if (!$caches || isset($caches["rf"])) {
                $this->_review_form_cache = $this->_defined_rounds = null;
                $this->_abbrev_matcher = null;
            }
            if (!$caches || isset($caches["taginfo"]) || isset($caches["tracks"]))
                $this->_taginfo = null;
            if (!$caches || isset($caches["formulas"]))
                $this->_formula_functions = null;
            if (!$caches || isset($caches["tracks"]))
                Contact::update_rights();
        }
    }


    // times

    private function _dateFormat($type) {
        if (!$this->_date_format_initialized) {
            if (!isset($this->opt["time24hour"]) && isset($this->opt["time24Hour"]))
                $this->opt["time24hour"] = $this->opt["time24Hour"];
            if (!isset($this->opt["dateFormatLong"]) && isset($this->opt["dateFormat"]))
                $this->opt["dateFormatLong"] = $this->opt["dateFormat"];
            if (!isset($this->opt["dateFormat"]))
                $this->opt["dateFormat"] = get($this->opt, "time24hour") ? "j M Y H:i:s" : "j M Y g:i:sa";
            if (!isset($this->opt["dateFormatLong"]))
                $this->opt["dateFormatLong"] = "l " . $this->opt["dateFormat"];
            if (!isset($this->opt["dateFormatObscure"]))
                $this->opt["dateFormatObscure"] = "j M Y";
            if (!isset($this->opt["timestampFormat"]))
                $this->opt["timestampFormat"] = $this->opt["dateFormat"];
            if (!isset($this->opt["dateFormatSimplifier"]))
                $this->opt["dateFormatSimplifier"] = get($this->opt, "time24hour") ? "/:00(?!:)/" : "/:00(?::00|)(?= ?[ap]m)/";
            if (!isset($this->opt["dateFormatTimezone"]))
                $this->opt["dateFormatTimezone"] = null;
            $this->_date_format_initialized = true;
        }
        if ($type == "timestamp")
            return $this->opt["timestampFormat"];
        else if ($type == "obscure")
            return $this->opt["dateFormatObscure"];
        else if ($type)
            return $this->opt["dateFormatLong"];
        else
            return $this->opt["dateFormat"];
    }

    function parseableTime($value, $include_zone) {
        $f = $this->_dateFormat(false);
        $d = date($f, $value);
        if ($this->opt["dateFormatSimplifier"])
            $d = preg_replace($this->opt["dateFormatSimplifier"], "", $d);
        if ($include_zone) {
            if ($this->opt["dateFormatTimezone"] === null)
                $d .= " " . date("T", $value);
            else if ($this->opt["dateFormatTimezone"])
                $d .= " " . $this->opt["dateFormatTimezone"];
        }
        return $d;
    }
    function parse_time($d, $reference = null) {
        global $Now;
        if ($reference === null)
            $reference = $Now;
        if (!isset($this->opt["dateFormatTimezoneRemover"])
            && function_exists("timezone_abbreviations_list")) {
            $mytz = date_default_timezone_get();
            $x = array();
            foreach (timezone_abbreviations_list() as $tzname => $tzinfo) {
                foreach ($tzinfo as $tz)
                    if ($tz["timezone_id"] == $mytz)
                        $x[] = preg_quote($tzname);
            }
            if (count($x) == 0)
                $x[] = preg_quote(date("T", $reference));
            $this->opt["dateFormatTimezoneRemover"] =
                "/(?:\\s|\\A)(?:" . join("|", $x) . ")(?:\\s|\\z)/i";
        }
        if ($this->opt["dateFormatTimezoneRemover"])
            $d = preg_replace($this->opt["dateFormatTimezoneRemover"], " ", $d);
        $d = preg_replace('/\butc([-+])/i', 'GMT$1', $d);
        return strtotime($d, $reference);
    }

    function _printableTime($value, $type, $useradjust, $preadjust = null) {
        if ($value <= 0)
            return "N/A";
        $t = date($this->_dateFormat($type), $value);
        if ($this->opt["dateFormatSimplifier"])
            $t = preg_replace($this->opt["dateFormatSimplifier"], "", $t);
        if ($type !== "obscure") {
            if ($this->opt["dateFormatTimezone"] === null)
                $t .= " " . date("T", $value);
            else if ($this->opt["dateFormatTimezone"])
                $t .= " " . $this->opt["dateFormatTimezone"];
        }
        if ($preadjust)
            $t .= $preadjust;
        if ($useradjust) {
            $sp = strpos($useradjust, " ");
            $t .= "<$useradjust class=\"usertime\" id=\"usertime$this->usertimeId\" style=\"display:none\"></" . ($sp ? substr($useradjust, 0, $sp) : $useradjust) . ">";
            Ht::stash_script("setLocalTime('usertime$this->usertimeId',$value)");
            ++$this->usertimeId;
        }
        return $t;
    }
    function printableTime($value, $useradjust = false, $preadjust = null) {
        return $this->_printableTime($value, true, $useradjust, $preadjust);
    }
    function obscure_time($timestamp) {
        if ($timestamp !== null)
            $timestamp = (int) ($timestamp + 0.5);
        if ($timestamp > 0) {
            $offset = 0;
            if (($zone = timezone_open(date_default_timezone_get())))
                $offset = $zone->getOffset(new DateTime("@$timestamp"));
            $timestamp += 43200 - ($timestamp + $offset) % 86400;
        }
        return $timestamp;
    }
    function unparse_time_short($value) {
        return $this->_printableTime($value, false, false, null);
    }
    function unparse_time_full($value) {
        return $this->_printableTime($value, "timestamp", false, null);
    }
    function unparse_time_obscure($value) {
        return $this->_printableTime($value, "obscure", false, null);
    }
    function unparse_time_log($value) {
        return date("d/M/Y:H:i:s O", $value);
    }

    function printableTimeSetting($what, $useradjust = false, $preadjust = null) {
        return $this->printableTime(defval($this->settings, $what, 0), $useradjust, $preadjust);
    }
    function printableDeadlineSetting($what, $useradjust = false, $preadjust = null) {
        if (!isset($this->settings[$what]) || $this->settings[$what] <= 0)
            return "No deadline";
        else
            return "Deadline: " . $this->printableTime($this->settings[$what], $useradjust, $preadjust);
    }

    function settingsAfter($name) {
        global $Now;
        $t = get($this->settings, $name);
        return $t !== null && $t > 0 && $t <= $Now;
    }
    function deadlinesAfter($name, $grace = null) {
        global $Now;
        $t = get($this->settings, $name);
        if ($t !== null && $t > 0 && $grace && ($g = get($this->settings, $grace)))
            $t += $g;
        return $t !== null && $t > 0 && $t <= $Now;
    }
    function deadlinesBetween($name1, $name2, $grace = null) {
        global $Now;
        $t = get($this->settings, $name1);
        if (($t === null || $t <= 0 || $t > $Now) && $name1)
            return false;
        $t = get($this->settings, $name2);
        if ($t !== null && $t > 0 && $grace && ($g = get($this->settings, $grace)))
            $t += $g;
        return $t === null || $t <= 0 || $t >= $Now;
    }

    function timeStartPaper() {
        return $this->deadlinesBetween("sub_open", "sub_reg", "sub_grace");
    }
    function timeUpdatePaper($prow = null) {
        return $this->deadlinesBetween("sub_open", "sub_update", "sub_grace")
            && (!$prow || $prow->timeSubmitted <= 0 || $this->setting('sub_freeze') <= 0);
    }
    function timeFinalizePaper($prow = null) {
        return $this->deadlinesBetween("sub_open", "sub_sub", "sub_grace")
            && (!$prow || $prow->timeSubmitted <= 0 || $this->setting('sub_freeze') <= 0);
    }
    function collectFinalPapers() {
        return $this->setting("final_open") > 0;
    }
    function time_submit_final_version() {
        return $this->deadlinesBetween("final_open", "final_done", "final_grace");
    }
    function timeAuthorViewReviews($reviewsOutstanding = false) {
        // also used to determine when authors can see review counts
        // and comments.  see also mailtemplate.php and PaperInfo::notify
        return $this->au_seerev > 0
            && ($this->au_seerev != self::AUSEEREV_UNLESSINCOMPLETE || !$reviewsOutstanding);
    }
    private function time_author_respond_all_rounds() {
        $allowed = array();
        foreach ($this->resp_round_list() as $i => $rname) {
            $isuf = $i ? "_$i" : "";
            if ($this->deadlinesBetween("resp_open$isuf", "resp_done$isuf", "resp_grace$isuf"))
                $allowed[$i] = $rname;
        }
        return $allowed;
    }
    function time_author_respond($round = null) {
        if (!$this->au_seerev || !$this->setting("resp_active"))
            return $round === null ? array() : false;
        if ($round === null)
            return $this->time_author_respond_all_rounds();
        $isuf = $round ? "_$round" : "";
        return $this->deadlinesBetween("resp_open$isuf", "resp_done$isuf", "resp_grace$isuf");
    }
    function can_all_author_view_decision() {
        return $this->setting("seedec") == self::SEEDEC_ALL;
    }
    function can_some_author_view_decision() {
        return $this->setting("seedec") == self::SEEDEC_ALL;
    }
    function time_review_open() {
        global $Now;
        $rev_open = +get($this->settings, "rev_open");
        return 0 < $rev_open && $rev_open <= $Now;
    }
    function review_deadline($round, $isPC, $hard) {
        $dn = ($isPC ? "pcrev_" : "extrev_") . ($hard ? "hard" : "soft");
        if ($round === null)
            $round = $this->assignment_round(!$isPC);
        else if (is_object($round))
            $round = $round->reviewRound ? : 0;
        if ($round && isset($this->settings["{$dn}_$round"]))
            $dn .= "_$round";
        return $dn;
    }
    function missed_review_deadline($round, $isPC, $hard) {
        global $Now;
        $rev_open = +get($this->settings, "rev_open");
        if (!(0 < $rev_open && $rev_open <= $Now))
            return "rev_open";
        $dn = $this->review_deadline($round, $isPC, $hard);
        $dv = +get($this->settings, $dn);
        if ($dv > 0 && $dv < $Now)
            return $dn;
        return false;
    }
    function time_review($round, $isPC, $hard) {
        return !$this->missed_review_deadline($round, $isPC, $hard);
    }
    function timePCReviewPreferences() {
        return defval($this->settings, "papersub") > 0;
    }
    function timePCViewAllReviews($myReviewNeedsSubmit = false, $reviewsOutstanding = false) {
        return ($this->settingsAfter("pc_seeallrev")
                && (!$myReviewNeedsSubmit
                    || $this->settings["pc_seeallrev"] != self::PCSEEREV_UNLESSINCOMPLETE)
                && (!$reviewsOutstanding
                    || $this->settings["pc_seeallrev"] != self::PCSEEREV_UNLESSANYINCOMPLETE));
    }
    function timePCViewDecision($conflicted) {
        $s = $this->setting("seedec");
        if ($conflicted)
            return $s == self::SEEDEC_ALL || $s == self::SEEDEC_REV;
        else
            return $s >= self::SEEDEC_REV;
    }
    function timeReviewerViewDecision() {
        return $this->setting("seedec") >= self::SEEDEC_REV;
    }
    function timeReviewerViewAcceptedAuthors() {
        return $this->setting("seedec") == self::SEEDEC_ALL;
    }
    function timePCViewPaper($prow, $pdf) {
        if ($prow->timeWithdrawn > 0)
            return false;
        else if ($prow->timeSubmitted > 0)
            return !$pdf || $this->_pc_see_pdf;
        else
            return !$pdf && $this->can_pc_see_all_submissions();
    }
    function timePCViewSomePaper($pdf) {
        return $this->setting("papersub")
            ? !$pdf || $this->_pc_see_pdf
            : !$pdf || $this->can_pc_see_all_submissions();
    }
    function timeEmailChairAboutReview() {
        return get($this->settings, "rev_notifychair") > 0;
    }

    function submission_blindness() {
        return $this->settings["sub_blind"];
    }
    function subBlindAlways() {
        return $this->settings["sub_blind"] == self::BLIND_ALWAYS;
    }
    function subBlindNever() {
        return $this->settings["sub_blind"] == self::BLIND_NEVER;
    }
    function subBlindOptional() {
        return $this->settings["sub_blind"] == self::BLIND_OPTIONAL;
    }
    function subBlindUntilReview() {
        return $this->settings["sub_blind"] == self::BLIND_UNTILREVIEW;
    }

    function is_review_blind($rrow) {
        $rb = $this->settings["rev_blind"];
        if ($rb == self::BLIND_ALWAYS)
            return true;
        else if ($rb != self::BLIND_OPTIONAL)
            return false;
        if (is_object($rrow))
            $rrow = (bool) $rrow->reviewBlind;
        return $rrow === null || $rrow;
    }
    function review_blindness() {
        return $this->settings["rev_blind"];
    }

    function has_any_accepts() {
        return !!get($this->settings, "paperacc");
    }

    function count_submitted_accepted() {
        $dlt = max($this->setting("sub_sub"), $this->setting("sub_close"));
        $result = $this->qe("select outcome, count(paperId) from Paper where timeSubmitted>0 " . ($dlt ? "or (timeSubmitted=-100 and timeWithdrawn>=$dlt) " : "") . "group by outcome");
        $n = $nyes = 0;
        while (($row = edb_row($result))) {
            $n += $row[1];
            if ($row[0] > 0)
                $nyes += $row[1];
        }
        Dbl::free($result);
        return [$n, $nyes];
    }

    function has_any_lead_or_shepherd() {
        return !!get($this->settings, "paperlead");
    }

    function has_any_manager() {
        return $this->_track_admin_sensitivity || !!get($this->settings, "papermanager");
    }

    function can_pc_see_all_submissions() {
        if ($this->_pc_seeall_cache === null) {
            $this->_pc_seeall_cache = get($this->settings, "pc_seeall") ? : 0;
            if ($this->_pc_seeall_cache > 0 && !$this->timeFinalizePaper())
                $this->_pc_seeall_cache = 0;
        }
        return $this->_pc_seeall_cache > 0;
    }


    function set_siteurl($base) {
        $old_siteurl = Navigation::siteurl();
        $base = Navigation::set_siteurl($base);
        if ($this->opt["assetsUrl"] === $old_siteurl) {
            $this->opt["assetsUrl"] = $base;
            Ht::$img_base = $this->opt["assetsUrl"] . "images/";
        }
        if ($this->opt["scriptAssetsUrl"] === $old_siteurl)
            $this->opt["scriptAssetsUrl"] = $base;
    }


    //
    // Paper storage
    //

    function active_document_ids() {
        $q = array("select paperStorageId from Paper where paperStorageId>1",
            "select finalPaperStorageId from Paper where finalPaperStorageId>1",
            "select paperStorageId from PaperComment where paperStorageId>1");
        $document_option_ids = array();
        foreach ($this->paper_opts->option_list() as $id => $o)
            if ($o->has_document())
                $document_option_ids[] = $id;
        if (!empty($document_option_ids))
            $q[] = "select value from PaperOption where optionId in ("
                . join(",", $document_option_ids) . ") and value>1";

        $result = $this->qe_raw(join(" UNION ", $q));
        $ids = array();
        while (($row = edb_row($result)))
            $ids[(int) $row[0]] = true;
        Dbl::free($result);
        ksort($ids);
        return array_keys($ids);
    }

    function document_by_id($did, PaperInfo $prow = null) {
        $result = $this->qe("select * from PaperStorage where paperStorageId=?"
            . ($prow ? " and paperId={$prow->paperId}" : ""), $did);
        $doc = DocumentInfo::fetch($result, $this, $prow);
        Dbl::free($result);
        return $doc;
    }

    function download_documents($docs, $attachment) {
        if (count($docs) == 1
            && $docs[0]->paperStorageId <= 1
            && (!isset($docs[0]->content) || $docs[0]->content === "")) {
            self::msg_error("Paper #" . $docs[0]->paperId . " hasn’t been uploaded yet.");
            return false;
        }

        foreach ($docs as $doc)
            $doc->filename = HotCRPDocument::filename($doc);
        $downloadname = false;
        if (count($docs) > 1) {
            $name = HotCRPDocument::unparse_dtype($docs[0]->documentType);
            if ($docs[0]->documentType <= 0)
                $name = pluralize($name);
            $downloadname = $this->download_prefix . "$name.zip";
        }
        $result = Filer::multidownload($docs, $downloadname, $attachment);
        if ($result->error) {
            self::msg_error($result->error_html);
            return false;
        } else
            return true;
    }


    //
    // Paper search
    //

    static private function _cvt_numeric_set($optarr) {
        $ids = array();
        if (is_object($optarr))
            $optarr = $optarr->selection();
        foreach (mkarray($optarr) as $x)
            if (($x = cvtint($x)) > 0)
                $ids[] = $x;
        return $ids;
    }

    function query_all_reviewer_preference() {
        return "group_concat(concat(contactId,' ',preference,' ',coalesce(expertise,'.')) separator ',')";
    }

    private function paperQuery(Contact $contact = null, $options = array()) {
        // Options:
        //   "paperId" => $pid  Only paperId $pid (if array, any of those)
        //   "reviewId" => $rid Only paper reviewed by $rid
        //   "commentId" => $c  Only paper where comment is $c
        //   "finalized"        Only submitted papers
        //   "unsub"            Only unsubmitted papers
        //   "accepted"         Only accepted papers
        //   "active"           Only nonwithdrawn papers
        //   "author"           Only papers authored by $contactId
        //   "myReviewRequests" Only reviews requested by $contactId
        //   "myReviews"        All reviews authored by $contactId
        //   "myOutstandingReviews" All unsubmitted reviews auth by $contactId
        //   "myReviewsOpt"     myReviews, + include papers not yet reviewed
        //   "allReviews"       All reviews (multiple rows per paper)
        //   "allReviewScores"  All review scores (multiple rows per paper)
        //   "reviewerName"     Include reviewer names
        //   "commenterName"    Include commenter names
        //   "reviewer" => $cid Include reviewerConflictType/reviewerReviewType
        //   "tags"             Include paperTags
        //   "tagIndex" => $tag Include tagIndex of named tag
        //   "tagIndex" => tag array -- include tagIndex, tagIndex1, ...
        //   "topics"
        //   "options"
        //   "scores" => array(fields to score)
        //   "assignments"
        //   "order" => $sql    $sql is SQL 'order by' clause (or empty)

        $reviewerQuery = isset($options["myReviews"]) || isset($options["allReviews"]) || isset($options["myReviewRequests"]) || isset($options["myReviewsOpt"]) || isset($options["myOutstandingReviews"]);
        $allReviewerQuery = isset($options["allReviews"]) || isset($options["allReviewScores"]);
        $scoresQuery = !$reviewerQuery && isset($options["allReviewScores"]);
        $contactId = $contact ? $contact->contactId : 0;
        if (isset($options["reviewer"]) && is_object($options["reviewer"]))
            $reviewerContactId = $options["reviewer"]->contactId;
        else if (isset($options["reviewer"]))
            $reviewerContactId = $options["reviewer"];
        else
            $reviewerContactId = $contactId;
        if (get($options, "author") || !$contactId)
            $myPaperReview = null;
        else if ($allReviewerQuery)
            $myPaperReview = "MyPaperReview";
        else
            $myPaperReview = "PaperReview";

        // paper selection
        $paperset = array();
        if (isset($options["paperId"]))
            $paperset[] = self::_cvt_numeric_set($options["paperId"]);
        if (isset($options["reviewId"])) {
            if (is_numeric($options["reviewId"])) {
                $result = $this->qe("select paperId from PaperReview where reviewId=?", $options["reviewId"]);
                $paperset[] = self::_cvt_numeric_set(edb_first_columns($result));
            } else if (preg_match('/^(\d+)([A-Z][A-Z]?)$/i', $options["reviewId"], $m)) {
                $result = $this->qe("select paperId from PaperReview where paperId=? and reviewOrdinal=?", $m[1], parseReviewOrdinal($m[2]));
                $paperset[] = self::_cvt_numeric_set(edb_first_columns($result));
            } else
                $paperset[] = array();
        }
        if (isset($options["commentId"])) {
            $result = $this->qe("select paperId from PaperComment where commentId?a", self::_cvt_numeric_set($options["commentId"]));
            $paperset[] = self::_cvt_numeric_set(edb_first_columns($result));
        }
        if (count($paperset) > 1)
            $paperset = array(call_user_func_array("array_intersect", $paperset));
        $papersel = "";
        if (count($paperset))
            $papersel = "paperId" . sql_in_numeric_set($paperset[0]) . " and ";

        // prepare query: basic tables
        $where = array();

        $joins = array("Paper");

        $cols = array("Paper.*, PaperConflict.conflictType");

        $aujoinwhere = null;
        if (get($options, "author") && $contact
            && ($aujoinwhere = $contact->actAuthorSql("PaperConflict", true)))
            $where[] = $aujoinwhere;
        if (get($options, "author") && !$aujoinwhere)
            $joins[] = "join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")";
        else
            $joins[] = "left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$contactId)";

        // my review
        $reviewjoin = "PaperReview.contactId=$contactId";
        $tokens = false;
        if ($contact && ($reviewerQuery || $myPaperReview)
            && ($tokens = $contact->review_tokens()))
            $reviewjoin = "($reviewjoin or reviewToken in (" . join(",", $tokens) . "))";
        if (get($options, "myReviewRequests"))
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.requestedBy=$contactId and PaperReview.reviewType=" . REVIEW_EXTERNAL . ")";
        else if (get($options, "myReviews"))
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and $reviewjoin)";
        else if (get($options, "myOutstandingReviews"))
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and $reviewjoin and PaperReview.reviewNeedsSubmit!=0)";
        else if (get($options, "myReviewsOpt"))
            $joins[] = "left join PaperReview on (PaperReview.paperId=Paper.paperId and $reviewjoin)";
        else if (get($options, "allReviews") || get($options, "allReviewScores")) {
            $x = (get($options, "reviewLimitSql") ? " and (" . $options["reviewLimitSql"] . ")" : "");
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId$x)";
        } else if ($myPaperReview)
            $joins[] = "left join PaperReview on (PaperReview.paperId=Paper.paperId and $reviewjoin)";

        // started reviews
        if (get($options, "startedReviewCount"))
            $cols[] = "(select count(*) from PaperReview where paperId=Paper.paperId and (reviewSubmitted>0 or reviewNeedsSubmit>0)) startedReviewCount";
        if (get($options, "inProgressReviewCount"))
            $cols[] = "(select count(*) from PaperReview where paperId=Paper.paperId and (reviewSubmitted>0 or reviewModified>0)) inProgressReviewCount";

        // submitted reviews
        $j = "select paperId, count(*) count";
        $before_ncols = count($cols);
        if (get($options, "startedReviewCount") || get($options, "inProgressReviewCount"))
            $cols[] = "coalesce(R_submitted.count,0) reviewCount";
        if (get($options, "scores"))
            foreach ($options["scores"] as $fid) {
                $cols[] = "R_submitted.{$fid}Scores";
                if ($myPaperReview)
                    $cols[] = "$myPaperReview.$fid";
                $j .= ", group_concat($fid order by reviewId) {$fid}Scores";
            }
        if (get($options, "reviewTypes") || get($options, "reviewIdentities")) {
            $cols[] = "R_submitted.reviewTypes";
            $j .= ", group_concat(reviewType order by reviewId) reviewTypes";
        }
        if (get($options, "reviewIdentities")) {
            $cols[] = "R_submitted.reviewRequestedBys";
            $j .= ", group_concat(requestedBy order by reviewId) reviewRequestedBys";
            if ($this->review_blindness() == self::BLIND_OPTIONAL) {
                $cols[] = "R_submitted.reviewBlinds";
                $j .= ", group_concat(reviewBlind order by reviewId) reviewBlinds";
            }
            if ($contact && $contact->review_tokens()) {
                $cols[] = "R_submitted.reviewTokens";
                $j .= ", group_concat(reviewToken order by reviewId) reviewTokens";
            }
        }
        if (get($options, "reviewRounds")) {
            $cols[] = "R_submitted.reviewRounds";
            $j .= ", group_concat(reviewRound order by reviewId) reviewRounds";
        }
        if (get($options, "reviewWordCounts") && $this->sversion >= 99) {
            $cols[] = "R_submitted.reviewWordCounts";
            $j .= ", group_concat(reviewWordCount order by reviewId) reviewWordCounts";
        }
        if (get($options, "reviewOrdinals")) {
            $cols[] = "R_submitted.reviewOrdinals";
            $j .= ", group_concat(reviewOrdinal order by reviewId) reviewOrdinals";
        }
        if (get($options, "reviewTypes") || get($options, "scores") || get($options, "reviewContactIds") || get($options, "reviewOrdinals") || get($options, "reviewIdentities")) {
            $cols[] = "R_submitted.reviewContactIds";
            $j .= ", group_concat(contactId order by reviewId) reviewContactIds";
        }
        if (count($cols) != $before_ncols)
            $joins[] = "left join ($j from PaperReview where {$papersel}reviewSubmitted>0 group by paperId) R_submitted on (R_submitted.paperId=Paper.paperId)";

        // assignments
        if (get($options, "assignments")) {
            $j = "select paperId, group_concat(contactId order by reviewId) allReviewContactIds, group_concat(reviewType order by reviewId) allReviewTypes, group_concat(reviewRound order by reviewId) allReviewRounds, group_concat(coalesce(reviewSubmitted,0) order by reviewId) allReviewSubmitted, group_concat(reviewNeedsSubmit order by reviewId) allReviewNeedsSubmit";
            $cols[] = "R_all.allReviewContactIds, R_all.allReviewTypes, R_all.allReviewRounds, R_all.allReviewSubmitted, R_all.allReviewNeedsSubmit";
            $joins[] = "left join ($j from PaperReview where {$papersel}true group by paperId) R_all on (R_all.paperId=Paper.paperId)";
        }

        // fields
        if ($myPaperReview) {
            // see also papercolumn.php
            array_push($cols, "PaperReview.reviewType, PaperReview.reviewId",
                       "PaperReview.reviewModified, PaperReview.reviewSubmitted, PaperReview.timeApprovalRequested",
                       "PaperReview.reviewNeedsSubmit, PaperReview.reviewOrdinal",
                       "PaperReview.reviewBlind, PaperReview.reviewToken, PaperReview.timeRequested",
                       "PaperReview.contactId as reviewContactId, PaperReview.requestedBy",
                       "max($myPaperReview.reviewType) as myReviewType",
                       "max($myPaperReview.reviewSubmitted) as myReviewSubmitted",
                       "min($myPaperReview.reviewNeedsSubmit) as myReviewNeedsSubmit",
                       "$myPaperReview.contactId as myReviewContactId",
                       "PaperReview.reviewRound");
        } else
            $cols[] = "null reviewType, null reviewId, null myReviewType";

        if ($reviewerQuery || $scoresQuery) {
            $cols[] = "PaperReview.reviewEditVersion as reviewEditVersion";
            $cols[] = ($this->sversion >= 105 ? "PaperReview.reviewFormat" : "null") . " as reviewFormat";
            foreach ($this->all_review_fields() as $f)
                if ($reviewerQuery || $f->has_options)
                    $cols[] = "PaperReview.$f->id as $f->id";
        }

        if ($myPaperReview == "MyPaperReview")
            $joins[] = "left join PaperReview as MyPaperReview on (MyPaperReview.paperId=Paper.paperId and MyPaperReview.contactId=$contactId)";

        if (get($options, "topics"))
            $cols[] = "(select group_concat(topicId) from PaperTopic where PaperTopic.paperId=Paper.paperId) topicIds";

        if (get($options, "options")
            && (isset($this->settingTexts["options"]) || isset($this->opt["fixedOptions"]))
            && $this->paper_opts->count_option_list())
            $cols[] = "(select group_concat(PaperOption.optionId, '#', value) from PaperOption where paperId=Paper.paperId) optionIds";
        else if (get($options, "options"))
            $cols[] = "'' as optionIds";

        if (get($options, "tags"))
            $cols[] = "(select group_concat(' ', tag, '#', tagIndex order by tag separator '') from PaperTag where PaperTag.paperId=Paper.paperId) paperTags";
        if (get($options, "tagIndex") && !is_array($options["tagIndex"]))
            $options["tagIndex"] = array($options["tagIndex"]);
        if (get($options, "tagIndex"))
            foreach ($options["tagIndex"] as $i => $tag)
                $cols[] = "(select tagIndex from PaperTag where PaperTag.paperId=Paper.paperId and PaperTag.tag='" . sqlq($tag) . "') tagIndex" . ($i ? : "");

        if (get($options, "reviewerPreference")) {
            $joins[] = "left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=$reviewerContactId)";
            $cols[] = "coalesce(PaperReviewPreference.preference, 0) as reviewerPreference";
            $cols[] = "PaperReviewPreference.expertise as reviewerExpertise";
        }

        if (get($options, "allReviewerPreference") || get($options, "desirability")) {
            $subq = "select paperId";
            if (get($options, "allReviewerPreference")) {
                $subq .= ", " . $this->query_all_reviewer_preference() . " as allReviewerPreference";
                $cols[] = "APRP.allReviewerPreference";
            }
            if (get($options, "desirability")) {
                $subq .= ", sum(if(preference<=-100,0,greatest(least(preference,1),-1))) as desirability";
                $cols[] = "coalesce(APRP.desirability,0) as desirability";
            }
            $subq .= " from PaperReviewPreference where {$papersel}true group by paperId";
            $joins[] = "left join ($subq) as APRP on (APRP.paperId=Paper.paperId)";
        }

        if (get($options, "allConflictType")) {
            $joins[] = "left join (select paperId, group_concat(concat(contactId,' ',conflictType) separator ',') as allConflictType from PaperConflict where {$papersel}conflictType>0 group by paperId) as AllConflict on (AllConflict.paperId=Paper.paperId)";
            $cols[] = "AllConflict.allConflictType";
        }

        if (get($options, "reviewer")) {
            $joins[] = "left join PaperConflict RPC on (RPC.paperId=Paper.paperId and RPC.contactId=$reviewerContactId)";
            $joins[] = "left join PaperReview RPR on (RPR.paperId=Paper.paperId and RPR.contactId=$reviewerContactId)";
            $cols[] = "RPC.conflictType reviewerConflictType, RPR.reviewType reviewerReviewType";
        }

        if (get($options, "reviewerName")) {
            if ($options["reviewerName"] === "lead" || $options["reviewerName"] === "shepherd")
                $joins[] = "left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=Paper." . $options['reviewerName'] . "ContactId)";
            else if (get($options, "reviewerName"))
                $joins[] = "left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=PaperReview.contactId)";
            array_push($cols, "ReviewerContactInfo.firstName as reviewFirstName",
                       "ReviewerContactInfo.lastName as reviewLastName",
                       "ReviewerContactInfo.email as reviewEmail",
                       "ReviewerContactInfo.lastLogin as reviewLastLogin");
        }

        if (get($options, "foldall"))
            $cols[] = "1 as folded";

        // conditions
        if (count($paperset))
            $where[] = "Paper.paperId" . sql_in_numeric_set($paperset[0]);
        if (get($options, "finalized"))
            $where[] = "timeSubmitted>0";
        else if (get($options, "unsub"))
            $where[] = "timeSubmitted<=0";
        if (get($options, "accepted"))
            $where[] = "outcome>0";
        if (get($options, "undecided"))
            $where[] = "outcome=0";
        if (get($options, "active") || get($options, "myReviews")
            || get($options, "myReviewRequests"))
            $where[] = "timeWithdrawn<=0";
        if (get($options, "myLead"))
            $where[] = "leadContactId=$contactId";
        if (get($options, "unmanaged"))
            $where[] = "managerContactId=0";

        $pq = "select " . join(",\n    ", $cols)
            . "\nfrom " . join("\n    ", $joins);
        if (count($where))
            $pq .= "\nwhere " . join("\n    and ", $where);

        // grouping and ordering
        if ($reviewerQuery || $scoresQuery || $tokens)
            $pq .= "\ngroup by Paper.paperId, PaperReview.reviewId";
        else
            $pq .= "\ngroup by Paper.paperId";
        if (get($options, "order") && $options["order"] != "order by Paper.paperId")
            $pq .= "\n" . $options["order"];
        else {
            $pq .= "\norder by Paper.paperId";
            if ($reviewerQuery || $scoresQuery || $tokens)
                $pq .= ", PaperReview.reviewOrdinal";
        }

        //Conf::msg_debugt($pq);
        return $pq . "\n";
    }

    function paperRow($sel, Contact $contact = null, &$whyNot = null) {
        $ret = null;
        $whyNot = array();

        if (!is_array($sel))
            $sel = array("paperId" => $sel);
        if (isset($sel["paperId"]))
            $whyNot["paperId"] = $sel["paperId"];
        if (isset($sel["reviewId"]))
            $whyNot["reviewId"] = $sel["reviewId"];

        if (isset($sel['paperId']) && cvtint($sel['paperId']) < 0)
            $whyNot['invalidId'] = 'paper';
        else if (isset($sel['reviewId']) && cvtint($sel['reviewId']) < 0
                 && !preg_match('/^\d+[A-Z][A-Z]?$/i', $sel['reviewId']))
            $whyNot['invalidId'] = 'review';
        else {
            $q = $this->paperQuery($contact, $sel);
            $result = $this->qe_raw($q);

            if (!$result)
                $whyNot["dbError"] = "Database error while fetching paper (" . htmlspecialchars($q) . "): " . htmlspecialchars($this->dblink->error);
            else if ($result->num_rows == 0)
                $whyNot["noPaper"] = 1;
            else
                $ret = PaperInfo::fetch($result, $contact, $this);

            Dbl::free($result);
        }

        return $ret;
    }

    function paper_result(Contact $user = null, $options = []) {
        return $this->qe_raw($this->paperQuery($user, $options));
    }

    function review_rows($q, $contact) {
        $result = $this->qe_raw($q);
        $rrows = array();
        while (($row = PaperInfo::fetch($result, $contact, $this)))
            $rrows[$row->reviewId] = $row;
        Dbl::free($result);
        return $rrows;
    }

    function comment_query($where) {
        return "select PaperComment.*, firstName reviewFirstName, lastName reviewLastName, email reviewEmail
                from PaperComment join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)
                where $where order by commentId";
    }

    function comment_rows($q, $contact) {
        $result = $this->qe_raw($q);
        $crows = array();
        while (($row = PaperInfo::fetch($result, $contact))) {
            $crows[$row->commentId] = $row;
            $row->threadContacts = array($row->contactId => 1);
            for ($r = $row; defval($r, "replyTo", 0) && isset($crows[$r->replyTo]); $r = $crows[$r->replyTo])
                /* do nothing */;
            $row->threadHead = $r->commentId;
            $r->threadContacts[$row->contactId] = 1;
        }
        Dbl::free($result);
        foreach ($crows as $row)
            if ($row->threadHead != $row->commentId)
                $row->threadContacts = $crows[$row->threadHead]->threadContacts;
        return $crows;
    }


    function reviewRow($selector, &$whyNot = null) {
        $whyNot = array();

        if (!is_array($selector))
            $selector = array("reviewId" => $selector);
        if (isset($selector["reviewId"])) {
            $whyNot["reviewId"] = $selector["reviewId"];
            if (($reviewId = cvtint($selector["reviewId"])) <= 0) {
                $whyNot["invalidId"] = "review";
                return null;
            }
        }
        if (isset($selector["paperId"])) {
            $whyNot["paperId"] = $selector["paperId"];
            if (($paperId = cvtint($selector["paperId"])) <= 0) {
                $whyNot["invalidId"] = "paper";
                return null;
            }
        }

        $q = "select PaperReview.*,
                ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.roles as contactRoles,
                ContactInfo.contactTags,
                ReqCI.firstName as reqFirstName, ReqCI.lastName as reqLastName, ReqCI.email as reqEmail";
        $qv = [];
        if (isset($selector["ratings"]))
            $q .= ",
                (select group_concat(rating) from ReviewRating where paperId=PaperReview.paperId and reviewId=PaperReview.reviewId) allRatings";
        if (isset($selector["myRating"])) {
            $q .= ",
                (select rating from ReviewRating where paperId=PaperReview.paperId and reviewId=PaperReview.reviewId and contactId=?) myRating";
            $qv[] = $selector["myRating"];
        }
        $q .= "\n               from PaperReview
                join ContactInfo using (contactId)
                left join ContactInfo as ReqCI on (ReqCI.contactId=PaperReview.requestedBy)\n";

        $where = array();
        if (isset($paperId)) {
            $where[] = "PaperReview.paperId=?";
            $qv[] = $paperId;
        }
        if (isset($reviewId)) {
            $where[] = "PaperReview.reviewId=?";
            $qv[] = $reviewId;
        }
        $cwhere = array();
        if (isset($selector["contactId"])) {
            $cwhere[] = "PaperReview.contactId=?";
            $qv[] = $selector["contactId"];
        }
        if (get($selector, "rev_tokens")) {
            $cwhere[] = "PaperReview.reviewToken?a";
            $qv[] = $selector["rev_tokens"];
        }
        if (count($cwhere))
            $where[] = "(" . join(" or ", $cwhere) . ")";
        if (isset($selector["reviewOrdinal"])) {
            $where[] = "PaperReview.reviewSubmitted>0 and reviewOrdinal=?";
            $qv[] = $selector["reviewOrdinal"];
        } else if (isset($selector["submitted"]))
            $where[] = "PaperReview.reviewSubmitted>0";
        if (empty($where)) {
            $whyNot["internal"] = 1;
            return null;
        }

        $order = array("PaperReview.paperId");
        if (count($cwhere) > 1) {
            $order[] = "(PaperReview.contactId=?) desc";
            $qv[] = $selector["contactId"];
        }

        // this review order is also implemented by PaperList::review_row_compare
        $q = $q . " where " . join(" and ", $where) . " group by PaperReview.reviewId
                order by " . join(", ", $order) . ", reviewOrdinal, timeRequested, reviewType desc, reviewId";

        $result = $this->qe_apply($q, $qv);
        if (!$result) {
            $whyNot["dbError"] = "Database error while fetching review (" . htmlspecialchars($q) . "): " . htmlspecialchars($this->dblink->error);
            return null;
        }

        $x = array();
        while (($row = edb_orow($result)))
            $x[] = $row;
        Dbl::free($result);

        if (isset($selector["array"]))
            return $x;
        else if (count($x) == 1 || (count($x) > 1 && get($selector, "first")))
            return $x[0];
        if (count($x) == 0)
            $whyNot['noReview'] = 1;
        else
            $whyNot['multipleReviews'] = 1;
        return null;
    }


    function preferenceConflictQuery($type, $extra) {
        $q = "select PRP.paperId, PRP.contactId, PRP.preference
                from PaperReviewPreference PRP
                join ContactInfo c on (c.contactId=PRP.contactId and c.roles!=0 and (c.roles&" . Contact::ROLE_PC . ")!=0)
                join Paper P on (P.paperId=PRP.paperId)
                left join PaperConflict PC on (PC.paperId=PRP.paperId and PC.contactId=PRP.contactId)
                where PRP.preference<=-100 and coalesce(PC.conflictType,0)<=0
                  and P.timeWithdrawn<=0";
        if ($type != "all" && ($type || !$this->can_pc_see_all_submissions()))
            $q .= " and P.timeSubmitted>0";
        if ($extra)
            $q .= " " . $extra;
        return $q;
    }


    // Activity

    private static function _flowQueryWheres(&$where, $table, $t0) {
        $time = $table . ($table == "PaperReview" ? ".reviewSubmitted" : ".timeModified");
        if (is_array($t0))
            $where[] = "($time<$t0[0] or ($time=$t0[0] and $table.contactId>$t0[1]) or ($time=$t0[0] and $table.contactId=$t0[1] and $table.paperId>$t0[2]))";
        else if ($t0)
            $where[] = "$time<$t0";
    }

    private function _flowQueryRest() {
        return "title, timeSubmitted, timeWithdrawn, Paper.blind paperBlind,
                outcome, managerContactId, leadContactId,
                ContactInfo.firstName as reviewFirstName,
                ContactInfo.lastName as reviewLastName,
                ContactInfo.email as reviewEmail,
                PaperConflict.conflictType,
                MyPaperReview.reviewType as myReviewType,
                MyPaperReview.reviewSubmitted as myReviewSubmitted,
                MyPaperReview.reviewNeedsSubmit as myReviewNeedsSubmit,
                MyPaperReview.contactId as myReviewContactId\n";
    }

    private function _commentFlowQuery($contact, $t0, $limit) {
        // XXX review tokens
        $q = "select straight_join PaperComment.*,\n"
            . $this->_flowQueryRest()
            . "\t\tfrom PaperComment
                join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)
                left join PaperConflict on (PaperConflict.paperId=PaperComment.paperId and PaperConflict.contactId=$contact->contactId)
                left join PaperReview as MyPaperReview on (MyPaperReview.paperId=PaperComment.paperId and MyPaperReview.contactId=$contact->contactId)
                join Paper on (Paper.paperId=PaperComment.paperId)\n";
        $where = $contact->canViewCommentReviewWheres();
        self::_flowQueryWheres($where, "PaperComment", $t0);
        if (count($where))
            $q .= " where " . join(" and ", $where);
        $q .= " order by PaperComment.timeModified desc, PaperComment.contactId asc, PaperComment.paperId asc";
        if ($limit)
            $q .= " limit $limit";
        return $q;
    }

    private function _reviewFlowQuery($contact, $t0, $limit) {
        // XXX review tokens
        $q = "select straight_join PaperReview.*,\n"
            . $this->_flowQueryRest()
            . "\t\tfrom PaperReview
                join ContactInfo on (ContactInfo.contactId=PaperReview.contactId)
                left join PaperConflict on (PaperConflict.paperId=PaperReview.paperId and PaperConflict.contactId=$contact->contactId)
                left join PaperReview as MyPaperReview on (MyPaperReview.paperId=PaperReview.paperId and MyPaperReview.contactId=$contact->contactId)
                join Paper on (Paper.paperId=PaperReview.paperId)\n";
        $where = $contact->canViewCommentReviewWheres();
        self::_flowQueryWheres($where, "PaperReview", $t0);
        $where[] = "PaperReview.reviewSubmitted>0";
        $q .= " where " . join(" and ", $where);
        $q .= " order by PaperReview.reviewSubmitted desc, PaperReview.contactId asc, PaperReview.paperId asc";
        if ($limit)
            $q .= " limit $limit";
        return $q;
    }

    static function _activity_compar($a, $b) {
        if (!$a || !$b)
            return !$a && !$b ? 0 : ($a ? -1 : 1);
        $at = isset($a->timeModified) ? $a->timeModified : $a->reviewSubmitted;
        $bt = isset($b->timeModified) ? $b->timeModified : $b->reviewSubmitted;
        if ($at != $bt)
            return $at > $bt ? -1 : 1;
        else if ($a->contactId != $b->contactId)
            return $a->contactId < $b->contactId ? -1 : 1;
        else if ($a->paperId != $b->paperId)
            return $a->paperId < $b->paperId ? -1 : 1;
        else
            return 0;
    }

    function reviewerActivity($contact, $t0, $limit) {
        // Return the $limit most recent pieces of activity on or before $t0.
        // Requires some care, since comments and reviews are loaded from
        // different queries, and we want to return the results sorted.  So we
        // load $limit comments and $limit reviews -- but if the comments run
        // out before the $limit is reached (because some comments cannot be
        // seen by the current user), we load additional comments & try again,
        // and the same for reviews.

        if ($t0 && preg_match('/\A(\d+)\.(\d+)\.(\d+)\z/', $t0, $m))
            $ct0 = $rt0 = array($m[1], $m[2], $m[3]);
        else
            $ct0 = $rt0 = $t0;
        $activity = array();

        $crows = $rrows = array(); // comment/review rows being worked through
        $curcr = $currr = null;    // current comment/review row
        $last_time = INF;
        // We read new comment/review rows when the current set is empty.

        while (1) {
            // load $curcr with most recent viewable comment
            if ($curcr)
                /* do nothing */;
            else if (($curcr = array_pop($crows))) {
                if (!$contact->can_view_comment($curcr, $curcr, false)) {
                    $curcr = null;
                    continue;
                }
            } else if ($ct0) {
                $crows = array_reverse($this->comment_rows(self::_commentFlowQuery($contact, $ct0, $limit), $contact));
                if (count($crows) == $limit)
                    $ct0 = array($crows[0]->timeModified, $crows[0]->contactId, $crows[0]->paperId);
                else
                    $ct0 = null;
                continue;
            }

            // load $currr with most recent viewable review
            if ($currr)
                /* do nothing */;
            else if (($currr = array_pop($rrows))) {
                if (!$contact->can_view_review($currr, $currr, false)) {
                    $currr = null;
                    continue;
                }
            } else if ($rt0) {
                $rrows = array_reverse($this->review_rows(self::_reviewFlowQuery($contact, $rt0, $limit), $contact));
                if (count($rrows) == $limit)
                    $rt0 = array($rrows[0]->reviewSubmitted, $rrows[0]->contactId, $rrows[0]->paperId);
                else
                    $rt0 = null;
                continue;
            }

            // if neither, ran out of activity
            if (!$curcr && !$currr)
                break;
            // if above limit, ran out of activity
            if (count($activity) >= $limit
                && (!$curcr || $curcr->timeModified < $last_time)
                && (!$currr || $currr->reviewSubmitted < $last_time))
                break;

            // otherwise, choose the later one first
            if (self::_activity_compar($curcr, $currr) < 0) {
                $curcr->isComment = true;
                $activity[] = $curcr;
                $last_time = $curcr->timeModified;
                $curcr = null;
            } else {
                $currr->isComment = false;
                $activity[] = $currr;
                $last_time = $currr->reviewSubmitted;
                $currr = null;
            }
        }

        return $activity;
    }


    //
    // Message routines
    //

    function msg($type, $text) {
        if (PHP_SAPI == "cli") {
            if ($type === "xmerror" || $type === "merror")
                fwrite(STDERR, "$text\n");
            else if ($type === "xwarning" || $type === "warning"
                     || !defined("HOTCRP_TESTHARNESS"))
                fwrite(STDOUT, "$text\n");
        } else if ($this->save_messages) {
            ensure_session();
            $_SESSION[$this->dsn]["msgs"][] = [$type, $text];
        } else if ($type[0] == "x")
            echo Ht::xmsg($type, $text);
        else
            echo "<div class=\"$type\">$text</div>";
    }

    function infoMsg($text, $minimal = false) {
        $this->msg($minimal ? "xinfo" : "info", $text);
    }

    static function msg_info($text, $minimal = false) {
        self::$g->msg($minimal ? "xinfo" : "info", $text);
    }

    function warnMsg($text, $minimal = false) {
        $this->msg($minimal ? "xwarning" : "warning", $text);
    }

    static function msg_warning($text, $minimal = false) {
        self::$g->msg($minimal ? "xwarning" : "warning", $text);
    }

    function confirmMsg($text, $minimal = false) {
        $this->msg($minimal ? "xconfirm" : "confirm", $text);
    }

    static function msg_confirm($text, $minimal = false) {
        self::$g->msg($minimal ? "xconfirm" : "confirm", $text);
    }

    function errorMsg($text, $minimal = false) {
        $this->msg($minimal ? "xmerror" : "merror", $text);
        return false;
    }

    static function msg_error($text, $minimal = false) {
        self::$g->msg($minimal ? "xmerror" : "merror", $text);
        return false;
    }

    static function msg_debugt($text) {
        if (is_object($text) || is_array($text) || $text === null || $text === false || $text === true)
            $text = json_encode($text);
        self::$g->msg("merror", Ht::pre_text_wrap($text));
        return false;
    }

    function post_missing_msg() {
        $this->msg("merror", "Your uploaded data wasn’t received. This can happen on unusually slow connections, or if you tried to upload a file larger than I can accept.");
    }


    //
    // Conference header, footer
    //

    function make_css_link($url, $media = null) {
        global $ConfSitePATH;
        $t = '<link rel="stylesheet" type="text/css" href="';
        if (str_starts_with($url, "stylesheets/")
            || !preg_match(',\A(?:https?:|/),i', $url))
            $t .= $this->opt["assetsUrl"];
        $t .= $url;
        if (($mtime = @filemtime("$ConfSitePATH/$url")) !== false)
            $t .= "?mtime=$mtime";
        if ($media)
            $t .= '" media="' . $media;
        return $t . '" />';
    }

    function make_script_file($url, $no_strict = false, $integrity = null) {
        global $ConfSitePATH;
        if (str_starts_with($url, "scripts/")) {
            $post = "";
            if (($mtime = @filemtime("$ConfSitePATH/$url")) !== false)
                $post = "mtime=$mtime";
            if (get($this->opt, "strictJavascript") && !$no_strict)
                $url = $this->opt["scriptAssetsUrl"] . "cacheable.php?file=" . urlencode($url)
                    . "&strictjs=1" . ($post ? "&$post" : "");
            else
                $url = $this->opt["scriptAssetsUrl"] . $url . ($post ? "?$post" : "");
            if ($this->opt["scriptAssetsUrl"] === Navigation::siteurl())
                return Ht::script_file($url);
        }
        return Ht::script_file($url, ["crossorigin" => "anonymous", "integrity" => $integrity]);
    }

    private function header_head($title) {
        global $Me, $ConfSitePATH;
        // load session list and clear its cookie
        $list = SessionList::active();
        SessionList::clear_cookie();

        echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta name=\"google\" content=\"notranslate\" />\n";
        if (!$this->opt("allowIndexPapers") && $this->paper)
            echo "<meta name=\"robots\" content=\"noindex,noarchive\" />\n";

        echo $this->opt("fontScript", "");

        echo $this->make_css_link("stylesheets/style.css"), "\n";
        if ($this->opt("mobileStylesheet")) {
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">', "\n";
            echo $this->make_css_link("stylesheets/mobile.css", "screen and (max-width: 768px)"), "\n";
        }
        foreach (mkarray($this->opt("stylesheets", [])) as $css)
            echo $this->make_css_link($css), "\n";

        // favicon
        $favicon = $this->opt("favicon", "images/review24.png");
        if ($favicon) {
            if (strpos($favicon, "://") === false && $favicon[0] != "/") {
                if ($this->opt["assetsUrl"] && substr($favicon, 0, 7) === "images/")
                    $favicon = $this->opt["assetsUrl"] . $favicon;
                else
                    $favicon = Navigation::siteurl() . $favicon;
            }
            if (substr($favicon, -4) == ".png")
                echo "<link rel=\"icon\" type=\"image/png\" href=\"$favicon\" />\n";
            else if (substr($favicon, -4) == ".ico")
                echo "<link rel=\"shortcut icon\" href=\"$favicon\" />\n";
            else if (substr($favicon, -4) == ".gif")
                echo "<link rel=\"icon\" type=\"image/gif\" href=\"$favicon\" />\n";
            else
                echo "<link rel=\"icon\" href=\"$favicon\" />\n";
        }

        // title
        echo "<title>";
        if ($title) {
            $title = preg_replace("/<([^>\"']|'[^']*'|\"[^\"]*\")*>/", "", $title);
            $title = preg_replace(",(?: |&nbsp;|\302\240)+,", " ", $title);
            $title = str_replace("&#x2215;", "-", $title);
        }
        if ($title)
            echo $title, " - ";
        echo htmlspecialchars($this->short_name), "</title>\n</head>\n";

        // jQuery
        $stash = Ht::unstash();
        $jqueryVersion = get($this->opt, "jqueryVersion", "1.12.4");
        $integrity = null;
        if (isset($this->opt["jqueryUrl"]))
            $jquery = $this->opt["jqueryUrl"];
        else if ($this->opt("jqueryCdn")) {
            $jquery = "//code.jquery.com/jquery-{$jqueryVersion}.min.js";
            if ($jqueryVersion === "1.12.4")
                $integrity = "sha256-ZosEbRLbNQzLpnKIkEdrPv7lOy9C27hHQ+Xp8a4MxAQ=";
            else if ($jqueryVersion === "3.1.1")
                $integrity = "sha256-hVVnYaiADRTO2PzUGmuLJr8BLUSjGIZsDYGmIJLv2b8=";
        } else
            $jquery = "scripts/jquery-{$jqueryVersion}.min.js";
        Ht::stash_html($this->make_script_file($jquery, true, $integrity) . "\n");
        if ($this->opt("jqueryMigrate"))
            Ht::stash_html($this->make_script_file("//code.jquery.com/jquery-migrate-3.0.0.min.js", true));

        // Javascript settings to set before script.js
        Ht::stash_script("siteurl=" . json_encode(Navigation::siteurl()) . ";siteurl_suffix=\"" . Navigation::php_suffix() . "\"");
        if (session_id() !== "")
            Ht::stash_script("siteurl_postvalue=\"" . post_value() . "\"");
        if ($list)
            Ht::stash_script("hotcrp_list=" . json_encode(["id" => $list->listid, "info" => $list->info_string()]) . ";");
        if (($urldefaults = hoturl_defaults()))
            Ht::stash_script("siteurl_defaults=" . json_encode($urldefaults) . ";");
        Ht::stash_script("assetsurl=" . json_encode($this->opt["assetsUrl"]) . ";");
        $huser = (object) array();
        if ($Me && $Me->email)
            $huser->email = $Me->email;
        if ($Me && $Me->is_pclike())
            $huser->is_pclike = true;
        if ($Me && $Me->has_database_account())
            $huser->cid = $Me->contactId;
        Ht::stash_script("hotcrp_user=" . json_encode($huser) . ";");

        $pid = get($_REQUEST, "paperId");
        $pid = $pid && ctype_digit($pid) ? (int) $pid : 0;
        if (!$pid && $this->paper)
            $pid = $this->paper->paperId;
        if ($pid)
            Ht::stash_script("hotcrp_paperid=$pid");
        if ($pid && $Me && $Me->is_admin_force())
            Ht::stash_script("hotcrp_want_override_conflict=true");

        // script.js
        if (!$this->opt("noDefaultScript"))
            Ht::stash_html($this->make_script_file("scripts/script.js") . "\n");

        // other scripts
        foreach ($this->opt("scripts", []) as $file)
            Ht::stash_html($this->make_script_file($file) . "\n");

        if ($stash)
            Ht::stash_html($stash);
    }

    function has_interesting_deadline($my_deadlines) {
        global $Now;
        if (get($my_deadlines->sub, "open"))
            foreach (["reg", "update", "sub"] as $k)
                if ($Now <= get($my_deadlines->sub, $k, 0) || get($my_deadlines->sub, "{$k}_ingrace"))
                    return true;
        if (get($my_deadlines, "is_author") && get($my_deadlines, "resps"))
            foreach (get($my_deadlines, "resps") as $r)
                if ($r->open && ($Now <= $r->done || get($r, "ingrace")))
                    return true;
        return false;
    }

    static function echo_header(Conf $conf, $is_home, $site_div, $title_div,
                                $profile_html, $actions_html, $my_deadlines) {
        echo $site_div, '<div id="header_right">', $profile_html;
        if ($my_deadlines && $conf->has_interesting_deadline($my_deadlines))
            echo '<div id="maindeadline">&nbsp;</div>';
        else
            echo '<div id="maindeadline" style="display:none"></div>';
        echo '</div>', $title_div, $actions_html;
    }

    function header($title, $id, $actionBar, $title_div = null) {
        global $ConfSitePATH, $Me, $Now;
        if ($this->headerPrinted)
            return;

        // <head>
        if ($title === "Home")
            $title = "";
        $this->header_head($title);

        // <body>
        echo "<body";
        if ($id)
            echo ' id="', $id, '"';
        $list = null;
        $ispaper = ($id === "paper_view" || $id === "paper_edit" || $id === "review" || $id === "assign");
        if ($ispaper || $id === "account")
            $list = SessionList::active();
        if ($list)
            echo ' class="', ($ispaper ? "paper " : ""), 'has-hotlist" data-hotlist="',
                htmlspecialchars($list->info_string()), '"';
        else if ($ispaper)
            echo ' class="paper"';
        echo ">\n";

        // initial load (JS's timezone offsets are negative of PHP's)
        Ht::stash_script("hotcrp_load.time(" . (-date("Z", $Now) / 60) . "," . ($this->opt("time24hour") ? 1 : 0) . ")");

        // deadlines settings
        $my_deadlines = null;
        if ($Me) {
            $my_deadlines = $Me->my_deadlines($this->paper);
            Ht::stash_script("hotcrp_deadlines.init(" . json_encode($my_deadlines) . ")");
        }
        if ($this->default_format)
            Ht::stash_script("render_text.set_default_format(" . $this->default_format . ")");

        // meeting tracker
        $trackerowner = ($trackerstate = $this->setting_json("tracker"))
            && $trackerstate->trackerid
            && $trackerstate->sessionid == session_id();
        if ($trackerowner)
            Ht::stash_script("hotcrp_deadlines.tracker(0)");

        echo '<div id="prebody"><div id="header">';

        // $header_site
        $is_home = $id === "home";
        $site_div = '<div id="header_site" class="'
            . ($is_home ? "header_site_home" : "header_site_page")
            . '"><h1><a class="qq" href="' . hoturl("index") . '">'
            . htmlspecialchars($this->short_name);
        if (!$is_home)
            $site_div .= ' <span style="font-weight:normal">Home</span>';
        $site_div .= '</a></h1></div>';

        // $header_profile
        $profile_html = "";
        if ($Me && !$Me->is_empty()) {
            // profile link
            $profile_parts = [];
            if ($Me->has_email() && !$Me->disabled) {
                $profile_parts[] = '<a class="q" href="' . hoturl("profile") . '"><strong>'
                    . htmlspecialchars($Me->email)
                    . '</strong></a> &nbsp; <a href="' . hoturl("profile") . '">Profile</a>';
            }

            // "act as" link
            if (($actas = get($_SESSION, "last_actas"))
                && get($_SESSION, "trueuser")
                && ($Me->privChair || Contact::$trueuser_privChair === $Me)) {
                // Link becomes true user if not currently chair.
                if (!$Me->privChair || strcasecmp($Me->email, $actas) == 0)
                    $actas = $_SESSION["trueuser"]->email;
                if (strcasecmp($Me->email, $actas) != 0)
                    $profile_parts[] = "<a href=\"" . selfHref(array("actas" => $actas)) . "\">"
                        . ($Me->privChair ? htmlspecialchars($actas) : "Admin")
                        . "&nbsp;" . Ht::img("viewas.png", "Act as " . htmlspecialchars($actas))
                        . "</a>";
            }

            // help, sign out
            $x = ($id == "search" ? "t=$id" : ($id == "settings" ? "t=chair" : ""));
            if (!$Me->disabled)
                $profile_parts[] = '<a href="' . hoturl("help", $x) . '">Help</a>';
            if (!$Me->has_email() && !isset($this->opt["httpAuthLogin"]))
                $profile_parts[] = '<a href="' . hoturl("index", "signin=1") . '" class="nw">Sign in</a>';
            if (!$Me->is_empty() || isset($this->opt["httpAuthLogin"]))
                $profile_parts[] = '<a href="' . hoturl_post("index", "signout=1") . '" class="nw">Sign out</a>';

            if (!empty($profile_parts))
                $profile_html .= join(' <span class="barsep">·</span> ', $profile_parts);
        }

        if (!$title_div && $title)
            $title_div = '<div id="header_page"><h1>' . $title . '</h1></div>';
        if (!$title_div && $actionBar)
            $title_div = '<hr class="c" />';

        $renderf = $this->opt("headerRenderer");
        if (!$renderf)
            $renderf = "Conf::echo_header";
        if (is_array($renderf)) {
            require_once($renderf[0]);
            $renderf = $renderf[1];
        }
        call_user_func($renderf, $this, $is_home, $site_div, $title_div, $profile_html, $actionBar, $my_deadlines);

        echo "  <hr class=\"c\" /></div>\n";

        echo "<div id=\"initialmsgs\">\n";
        if (($x = $this->opt("maintenance")))
            echo "<div class=\"merror\"><strong>The site is down for maintenance.</strong> ", (is_string($x) ? $x : "Please check back later."), "</div>";
        $this->save_messages = false;
        if (($msgs = $this->session("msgs")) && count($msgs)) {
            $this->save_session("msgs", null);
            foreach ($msgs as $m)
                $this->msg($m[0], $m[1]);
        }
        echo "</div>\n";

        $this->headerPrinted = true;
        echo "</div>\n<div id=\"body\" class=\"body\">\n";

        // If browser owns tracker, send it the script immediately
        if ($trackerowner)
            echo Ht::unstash();

        // Callback for version warnings
        if ($Me && $Me->privChair
            && (!isset($_SESSION["updatecheck"])
                || $_SESSION["updatecheck"] + 20 <= $Now)
            && (!isset($this->opt["updatesSite"]) || $this->opt["updatesSite"])) {
            $m = isset($this->opt["updatesSite"]) ? $this->opt["updatesSite"] : "//hotcrp.lcdf.org/updates";
            $m .= (strpos($m, "?") === false ? "?" : "&")
                . "addr=" . urlencode($_SERVER["SERVER_ADDR"])
                . "&base=" . urlencode(Navigation::siteurl())
                . "&version=" . HOTCRP_VERSION;
            $v = HOTCRP_VERSION;
            if (is_dir("$ConfSitePATH/.git")) {
                $args = array();
                exec("export GIT_DIR=" . escapeshellarg($ConfSitePATH) . "/.git; git rev-parse HEAD 2>/dev/null; git merge-base origin/master HEAD 2>/dev/null", $args);
                if (count($args) >= 1) {
                    $m .= "&git-head=" . urlencode($args[0]);
                    $v .= " " . $args[0];
                }
                if (count($args) >= 2) {
                    $m .= "&git-upstream=" . urlencode($args[1]);
                    $v .= " " . $args[1];
                }
            }
            Ht::stash_script("check_version(\"$m\",\"$v\")");
            $_SESSION["updatecheck"] = $Now;
        }
    }

    function footer() {
        global $Me, $ConfSitePATH;
        echo "</div>\n", // class='body'
            "<div id='footer'>\n  <div id='footer_crp'>",
            $this->opt("extraFooter", ""),
            "<a href='http://read.seas.harvard.edu/~kohler/hotcrp/'>HotCRP</a>";
        if (!$this->opt("noFooterVersion")) {
            if ($Me && $Me->privChair) {
                echo " v", HOTCRP_VERSION;
                if (is_dir("$ConfSitePATH/.git")) {
                    $args = array();
                    exec("export GIT_DIR=" . escapeshellarg($ConfSitePATH) . "/.git; git rev-parse HEAD 2>/dev/null; git rev-parse v" . HOTCRP_VERSION . " 2>/dev/null", $args);
                    if (count($args) == 2 && $args[0] != $args[1])
                        echo " [", substr($args[0], 0, 7), "...]";
                }
            } else
                echo "<!-- Version ", HOTCRP_VERSION, " -->";
        }
        echo "</div>\n  <hr class=\"c\" /></div>\n";
        echo Ht::unstash(), "</body>\n</html>\n";
    }

    public function stash_hotcrp_pc(Contact $user) {
        if (!Ht::mark_stash("hotcrp_pc"))
            return;
        $hpcj = $list = [];
        foreach ($this->pc_members() as $pcm) {
            $hpcj[$pcm->contactId] = $j = (object) ["name" => $user->name_text_for($pcm), "email" => $pcm->email];
            if (($color_classes = $user->reviewer_color_classes_for($pcm)))
                $j->color_classes = $color_classes;
            if ($this->sort_by_last && $pcm->lastName) {
                $r = Text::analyze_name($pcm);
                if (strlen($r->lastName) !== strlen($r->name))
                    $j->lastpos = strlen($r->firstName) + 1;
                if ($r->nameAmbiguous && $r->name && $r->email)
                    $j->emailpos = strlen($r->name) + 1;
            }
            $list[] = $pcm->contactId;
        }
        $hpcj["__order__"] = $list;
        if ($this->sort_by_last)
            $hpcj["__sort__"] = "last";
        Ht::stash_script("hotcrp_pc=" . json_encode($hpcj) . ";");
    }


    function output_ajax($values = null, $div = false) {
        if ($values === false || $values === true)
            $values = array("ok" => $values);
        else if ($values === null)
            $values = array();
        else if (is_object($values))
            $values = get_object_vars($values);
        $t = "";
        if (session_id() !== ""
            && ($msgs = $this->session("msgs", array()))) {
            $this->save_session("msgs", null);
            foreach ($msgs as $msg) {
                if (($msg[0] === "merror" || $msg[0] === "xmerror")
                    && !isset($values["error"]))
                    $values["error"] = $msg[1];
                if ($div)
                    $t .= Ht::xmsg($msg[0], $msg[1]);
                else
                    $t .= "<span class=\"$msg[0]\">$msg[1]</span>";
            }
        }
        if ($t !== "")
            $values["response"] = $t . get_s($values, "response");
        if (isset($_REQUEST["jsontext"]) && $_REQUEST["jsontext"])
            header("Content-Type: text/plain");
        else
            header("Content-Type: application/json");
        if (check_post())
            header("Access-Control-Allow-Origin: *");
        echo json_encode($values);
    }

    function ajaxExit($values = null, $div = false) {
        $this->output_ajax($values, $div);
        exit;
    }


    //
    // Action recording
    //

    const action_log_query = "insert into ActionLog (ipaddr, contactId, paperId, action) values ?v";

    function save_logs($on) {
        if ($on && $this->_save_logs === false)
            $this->_save_logs = array();
        else if (!$on && $this->_save_logs !== false) {
            $qv = [];
            $last_pids = null;
            foreach ($this->_save_logs as $cid_text => $pids) {
                $pos = strpos($cid_text, "|");
                $who = substr($cid_text, 0, $pos);
                $what = substr($cid_text, $pos + 1);
                $pids = array_keys($pids);

                // Combine `Tag:` messages
                if (substr($what, 0, 5) === "Tag: "
                    && ($n = count($qv))
                    && substr($qv[$n-1][3], 0, 5) === "Tag: "
                    && $last_pids === $pids) {
                    $qv[$n-1][3] = $what . substr($qv[$n-1][3], 4);
                    continue;
                }

                $qv[] = self::format_log_values($what, $who, $pids);
                $last_pids = $pids;
            }
            if (!empty($qv))
                $this->qe(self::action_log_query, $qv);
            $this->_save_logs = false;
        }
    }

    function log($text, $who, $pids = null) {
        if (!$who)
            $who = 0;
        else if (!is_numeric($who)) {
            if ($who->email && !$who->contactId)
                $text .= " <{$who->email}>";
            $who = $who->contactId;
        }

        if (is_object($pids))
            $pids = array($pids->paperId);
        else if (!is_array($pids))
            $pids = $pids > 0 ? array($pids) : array();
        $ps = array();
        foreach ($pids as $p)
            $ps[] = is_object($p) ? $p->paperId : $p;

        if ($this->_save_logs === false)
            $this->qe(self::action_log_query, [self::format_log_values($text, $who, $ps)]);
        else {
            $key = "$who|$text";
            if (!isset($this->_save_logs[$key]))
                $this->_save_logs[$key] = [];
            foreach ($ps as $p)
                $this->_save_logs[$key][$p] = true;
        }
    }

    private static function format_log_values($text, $who, $pids) {
        $pid = null;
        if (count($pids) == 1)
            $pid = $pids[0];
        else if (count($pids) > 1)
            $text .= " (papers " . join(", ", $pids) . ")";
        return [get($_SERVER, "REMOTE_ADDR"), (int) $who, $pid, substr($text, 0, 4096)];
    }


    // capabilities

    public function capability_manager($for = null) {
        if ($for && substr($for, 0, 1) === "U") {
            if (($cdb = Contact::contactdb()))
                return new CapabilityManager($cdb, "U");
            else
                return null;
        } else
            return new CapabilityManager($this->dblink, "");
    }


    // messages

    public function message_name($name) {
        if (str_starts_with($name, "msg."))
            $name = substr($name, 4);
        if ($name === "revprefdescription" && $this->has_topics())
            $name .= ".withtopics";
        else if (str_starts_with($name, "resp_instrux") && $this->setting("resp_words", 500) > 0)
            $name .= ".wordlimit";
        return $name;
    }

    public function message_html($name, $expansions = null) {
        $name = $this->message_name($name);
        $html = get($this->settingTexts, "msg.$name");
        if ($html === null && ($p = strrpos($name, ".")) !== false)
            $html = get($this->settingTexts, "msg." . substr($name, 0, $p));
        if ($html === null)
            $html = Message::default_html($name);
        if ($html && $expansions)
            foreach ($expansions as $k => $v)
                $html = str_ireplace("%$k%", $v, $html);
        return $html;
    }

    public function message_default_html($name) {
        return Message::default_html($this->message_name($name));
    }


    function ims() {
        if (!$this->_ims) {
            $this->_ims = new IntlMsgSet;
            $m = ["?etc/msgs.json"];
            if (($lang = $this->opt("lang")))
                $m[] = "?etc/msgs.$lang.json";
            expand_json_includes_callback($m, [$this->_ims, "addj"],
                                          ["priority" => -1.0], true);
            if (($mlist = $this->opt("msgs_include")))
                expand_json_includes_callback($mlist, [$this->_ims, "addj"], null, true);
        }
        return $this->_ims;
    }

    function _($itext) {
        return call_user_func_array([$this->ims(), "x"], func_get_args());
    }

    function _c($context, $itext) {
        return call_user_func_array([$this->ims(), "xc"], func_get_args());
    }

    function _i($id, $itext) {
        return call_user_func_array([$this->ims(), "xi"], func_get_args());
    }

    function _ci($context, $id, $itext) {
        return call_user_func_array([$this->ims(), "xci"], func_get_args());
    }


    // API

    private function call_api($uf, Contact $user, Qrequest $qreq, $prow) {
        if (!$uf)
            return ["ok" => false, "error" => "API function not found."];
        if (!get($uf, "get") && !check_post($qreq))
            return ["ok" => false, "error" => "Missing credentials."];
        if (!$prow && get($uf, "paper"))
            return ["ok" => false, "error" => "Missing paper."];
        return call_user_func($uf->callback, $user, $qreq, $prow, $uf);
    }
    function _add_api_json($fj) {
        if (is_string($fj->fn) && !isset($this->_api_map[$fj->fn])
            && isset($fj->callback)) {
            $this->_api_map[$fj->fn] = $fj;
            return true;
        } else
            return false;
    }
    private function fill_api_map() {
        $this->_api_map = [
            "alltags" => "1 PaperApi::alltags_api",
            "checkformat" => "3 PaperApi::checkformat_api",
            "fieldhtml" => "1 PaperApi::fieldhtml_api",
            "follow" => "2 PaperApi::follow_api",
            "mentioncompletion" => "1 PaperApi::mentioncompletion_api",
            "reviewround" => "2 PaperApi::reviewround_api",
            "setdecision" => "2 PaperApi::setdecision_api",
            "setlead" => "2 PaperApi::setlead_api",
            "setmanager" => "2 PaperApi::setmanager_api",
            "setpref" => "2 PaperApi::setpref_api",
            "setshepherd" => "2 PaperApi::setshepherd_api",
            "settaganno" => "0 PaperApi::settaganno_api",
            "settags" => "0 PaperApi::settags_api",
            "taganno" => "1 PaperApi::taganno_api",
            "trackerstatus" => "1 MeetingTracker::trackerstatus_api", // hotcrp-comet entrypoint
            "votereport" => "3 PaperApi::votereport_api",
            "whoami" => "1 PaperApi::whoami_api"
        ];
        if (($olist = $this->opt("apiFunctions")))
            expand_json_includes_callback($olist, [$this, "_add_api_json"]);
    }
    function has_api($fn) {
        if ($this->_api_map === null)
            $this->fill_api_map();
        return isset($this->_api_map[$fn]);
    }
    function api($fn) {
        if ($this->_api_map === null)
            $this->fill_api_map();
        $uf = get($this->_api_map, $fn);
        if ($uf && is_string($uf)) {
            $space = strpos($uf, " ");
            $flags = (int) substr($uf, 0, $space);
            $uf = $this->_api_map[$fn] = (object) ["callback" => substr($uf, $space + 1)];
            if ($flags & 1)
                $uf->get = true;
            if ($flags & 2)
                $uf->paper = true;
        }
        return $uf;
    }
    function call_api_exit($fn, Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        // XXX precondition: $user->can_view_paper($prow) || !$prow
        $uf = $this->api($fn);
        if ($uf && get($uf, "redirect") && $qreq->redirect
            && preg_match('@\A(?![a-z]+:|/).+@', $qreq->redirect)) {
            try {
                JsonResultException::$capturing = true;
                $j = $this->call_api($uf, $user, $qreq, $prow);
            } catch (JsonResultException $ex) {
                $j = $ex->result;
            }
            if (!get($j, "ok") && !get($j, "error"))
                Conf::msg_error("Internal error.");
            else if (($x = get($j, "error")))
                Conf::msg_error(htmlspecialchars($x));
            else if (($x = get($j, "error_html")))
                Conf::msg_error($x);
            Navigation::redirect_site($qreq->redirect);
        } else {
            $j = $this->call_api($uf, $user, $qreq, $prow);
            json_exit($j);
        }
    }
}
