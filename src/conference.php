<?php
// conference.php -- HotCRP central helper class (singleton)
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

interface XtContext {
    /** @param string $str
     * @param object $xt
     * @param ?Contact $user
     * @param Conf $conf
     * @return ?bool */
    function xt_check_element($str, $xt, $user, Conf $conf);
}

class Conf {
    /** @var ?mysqli
     * @readonly */
    public $dblink;
    /** @var string
     * @readonly */
    public $dbname;
    /** @var ?string
     * @readonly */
    public $session_key;

    /** @var array<string,int> */
    public $settings;
    /** @var array<string,?string> */
    private $settingTexts;
    /** @var int */
    public $sversion;
    /** @var int */
    private $_pc_see_cache;

    /** @var string */
    public $short_name;
    /** @var string */
    public $long_name;
    /** @var int */
    public $default_format;
    /** @var string */
    public $download_prefix;
    /** @var int */
    public $au_seerev;
    /** @var ?list<string> */
    public $tag_au_seerev;
    /** @var bool */
    public $tag_seeall;
    /** @var int */
    public $ext_subreviews;
    /** @var int */
    public $any_response_open;
    /** @var bool */
    public $sort_by_last;
    /** @var array<string,mixed> */
    public $opt;
    /** @var array<string,mixed> */
    public $opt_override;
    /** @var ?int */
    private $_opt_timestamp;
    /** @var PaperOptionList */
    private $_paper_opts;

    /** @var bool */
    public $_header_printed = false;
    public $_session_handler;
    /** @var ?list<array{string,int}> */
    private $_save_msgs;
    /** @var ?array<string,array<int,true>> */
    private $_save_logs;

    /** @var ?Collator */
    private $_collator;
    /** @var ?Collator */
    private $_pcollator;
    /** @var list<string> */
    private $rounds;
    /** @var ?array<int,string> */
    private $_defined_rounds;
    private $_round_settings;
    /** @var ?list<ResponseRound> */
    private $_resp_rounds;
    /** @var ?list<Track> */
    private $_tracks;
    /** @var ?list<string> */
    private $_track_tags;
    /** @var int */
    private $_track_sensitivity = 0;
    /** @var ?TagMap */
    private $_tag_map;
    /** @var bool */
    private $_maybe_automatic_tags;
    /** @var bool */
    private $_has_permtag = false;
    /** @var ?array<int,string> */
    private $_decisions;
    /** @var ?AbbreviationMatcher<int> */
    private $_decision_matcher;
    /** @var ?array<int,array{string,string}> */
    private $_decision_status_info;
    /** @var ?TopicSet */
    private $_topic_set;
    /** @var ?Conflict */
    private $_conflict_types;
    /** @var ?array<int,Contact> */
    private $_pc_user_cache;
    /** @var ?array<int,Contact> */
    private $_pc_members_cache;
    /** @var ?array<int,Contact> */
    private $_pc_chairs_cache;
    /** @var ?array<string,string> */
    private $_pc_tags_cache;
    /** @var bool */
    private $_pc_members_all_enabled = true;
    /** @var int */
    private $_slice = 3;
    /** @var ?array<int,?Contact> */
    private $_user_cache;
    /** @var ?list<int|string> */
    private $_user_cache_missing;
    /** @var ?array<string,Contact> */
    private $_user_email_cache;
    /** @var ?array<int|string,Contact> */
    private $_cdb_user_cache;
    /** @var ?list<int|string> */
    private $_cdb_user_cache_missing;
    /** @var ?Contact */
    private $_root_user;
    /** @var ?Contact */
    private $_site_contact;
    /** @var ?ReviewForm */
    private $_review_form;
    /** @var ?AbbreviationMatcher<PaperOption|ReviewField|Formula> */
    private $_abbrev_matcher;
    /** @var bool */
    private $_date_format_initialized = false;
    /** @var ?DateTimeZone */
    private $_dtz;
    /** @var array<int,FormatSpec> */
    private $_formatspec_cache = [];
    /** @var ?non-empty-string */
    private $_docstore;
    /** @var array<int,Formula> */
    private $_defined_formulas;
    private $_emoji_codes;
    /** @var S3Client|null|false */
    private $_s3_client = false;
    /** @var ?Fmt */
    private $_fmt;
    /** @var ?list<string> */
    private $_fmt_override_names;
    /** @var ?array<int,TextFormat> */
    private $_format_info;
    /** @var bool */
    private $_updating_automatic_tags = false;

    /** @var ?XtContext */
    public $xt_context;
    /** @var ?callable(object,?Contact):bool */
    public $_xt_allow_callback;
    /** @var ?object */
    private $_xt_last_match;

    /** @var ?array<string,list<object>> */
    private $_formula_functions;
    /** @var ?array<string,list<object>> */
    private $_search_keyword_base;
    /** @var ?list<object> */
    private $_search_keyword_factories;
    /** @var ?array<string,list<object>> */
    private $_assignment_parsers;
    /** @var ?array<string,list<object>> */
    private $_api_map;
    /** @var ?array<string,list<object>> */
    private $_paper_column_map;
    /** @var ?list<object> */
    private $_paper_column_factories;
    /** @var ?array<string,list<object>> */
    private $_option_type_map;
    /** @var ?list<object> */
    private $_token_factories;
    /** @var ?array<int,object> */
    private $_token_types;
    private $_hook_map;
    private $_hook_factories;
    /** @var ?array<string,FileFilter> */
    public $_file_filters; // maintained externally
    /** @var ?SettingInfoSet */
    public $_setting_info; // maintained externally
    /** @var ?array<string,list<object>> */
    private $_mail_keyword_map;
    /** @var ?list<object> */
    private $_mail_keyword_factories;
    /** @var ?array<string,list<object>> */
    private $_mail_template_map;
    /** @var DKIMSigner|null|false */
    private $_dkim_signer = false;
    /** @var ?ComponentSet */
    private $_page_components;

    /** @var ?PaperInfo */
    public $paper; // current paper row
    /** @var SessionList|null|false */
    private $_active_list = false;

    /** @var Conf */
    static public $main;
    /** @var int */
    static public $now;
    /** @var int|float */
    static public $unow;
    /** @var float */
    static public $blocked_time = 0.0;
    /** @var false|null|\mysqli */
    static private $_cdb = false;

    /** @var bool */
    static public $test_mode;
    /** @var bool */
    static public $no_invalidate_caches = false;
    /** @var int */
    static public $next_xt_source_order = 0;
    static private $xt_require_resolved = [];

    const BLIND_NEVER = 0;          // these values are used in `msgs.json`
    const BLIND_OPTIONAL = 1;
    const BLIND_ALWAYS = 2;
    const BLIND_UNTILREVIEW = 3;

    const SEEDEC_ADMIN = 0;
    const SEEDEC_REV = 1;
    const SEEDEC_ALL = 2;
    const SEEDEC_NCREV = 3;

    const AUSEEREV_NO = 0;          // these values matter
    const AUSEEREV_YES = 2;
    const AUSEEREV_TAGS = 3;

    const PCSEEREV_IFCOMPLETE = 0;
    const PCSEEREV_YES = 1;
    const PCSEEREV_UNLESSINCOMPLETE = 3;
    const PCSEEREV_UNLESSANYINCOMPLETE = 4;

    static public $review_deadlines = ["pcrev_soft", "pcrev_hard", "extrev_soft", "extrev_hard"];

    static public $hoturl_defaults = null;

    /** @param array<string,mixed> $options
     * @param bool $connect */
    function __construct($options, $connect) {
        // unpack dsn, connect to database, load current settings
        if (($cp = Dbl::parse_connection_params($options))) {
            $this->dblink = $connect ? $cp->connect() : null;
            $this->dbname = $cp->name;
            $this->session_key = "@{$this->dbname}";
        }
        $this->opt = $options;
        $this->opt["confid"] = $this->opt["confid"] ?? $this->dbname;
        $this->_paper_opts = new PaperOptionList($this);
        if ($this->dblink && !Dbl::$default_dblink) {
            Dbl::set_default_dblink($this->dblink);
            Dbl::set_error_handler([$this, "query_error_handler"]);
        }
        if ($this->dblink) {
            Dbl::$landmark_sanitizer = "/^(?:Dbl::|Conf::q|Conf::fetch|call_user_func)/";
            $this->load_settings();
        } else {
            $this->refresh_options();
        }
    }

    /** @param int|float $t */
    static function set_current_time($t) {
        global $Now;
        self::$unow = $t;
        $Now = Conf::$now = (int) $t;
        if (Conf::$main) {
            Conf::$main->refresh_time_settings();
        }
    }

    /** @param int $advance_past */
    static function advance_current_time($advance_past) {
        if ($advance_past + 1 > Conf::$now) {
            self::set_current_time($advance_past + 1);
        }
    }


    //
    // Initialization functions
    //

    function __load_settings() {
        // load settings from database
        $this->settings = [];
        $this->settingTexts = [];
        foreach ($this->opt_override ?? [] as $k => $v) {
            if ($v === null) {
                unset($this->opt[$k]);
            } else {
                $this->opt[$k] = $v;
            }
        }
        $this->opt_override = [];
        if ($this->_fmt) {
            $this->_fmt->remove_overrides();
        }
        $this->_fmt_override_names = null;

        $result = $this->q_raw("select name, value, data from Settings");
        while (($row = $result->fetch_row())) {
            $this->settings[$row[0]] = (int) $row[1];
            if ($row[2] !== null) {
                $this->settingTexts[$row[0]] = $row[2];
            }
            if (str_starts_with($row[0], "opt.")) {
                $okey = substr($row[0], 4);
                $this->opt_override[$okey] = $this->opt[$okey] ?? null;
                $this->opt[$okey] = ($row[2] === null ? (int) $row[1] : $row[2]);
            } else if (str_starts_with($row[0], "msg.")) {
                $this->_fmt_override_names = $this->_fmt_override_names ?? [];
                $this->_fmt_override_names[] = $row[0];
            }
        }
        Dbl::free($result);

        $this->sversion = $this->settings["allowPaperOption"];
    }

    function load_settings() {
        $this->__load_settings();
        if ($this->sversion < 269) {
            $old_nerrors = Dbl::$nerrors;
            (new UpdateSchema($this))->run();
            Dbl::$nerrors = $old_nerrors;
        }
        if ($this->sversion < 200) {
            $this->error_msg("<0>Warning: The database could not be upgraded to the current version; expect errors. A system administrator must solve this problem.");
        }

        // refresh after loading from backup
        if (isset($this->settings["frombackup"])) {
            // in current code, refresh_settings() suffices
            $this->qe_raw("delete from Settings where name='frombackup' and value=" . $this->settings["frombackup"]);
            unset($this->settings["frombackup"]);
        }

        // GC old capabilities
        if (($this->settings["__capability_gc"] ?? 0) < Conf::$now - 86400) {
            $this->clean_tokens();
        }

        $this->refresh_settings();
        $this->refresh_options();
    }

    function refresh_settings() {
        // enforce invariants
        $this->settings["pcrev_any"] = $this->settings["pcrev_any"] ?? 0;
        $this->settings["extrev_view"] = $this->settings["extrev_view"] ?? 0;
        $this->settings["sub_blind"] = $this->settings["sub_blind"] ?? self::BLIND_ALWAYS;
        $this->settings["rev_blind"] = $this->settings["rev_blind"] ?? self::BLIND_ALWAYS;
        if (!isset($this->settings["seedec"])) {
            if ($this->settings["au_seedec"] ?? null) {
                $this->settings["seedec"] = self::SEEDEC_ALL;
            } else if ($this->settings["rev_seedec"] ?? null) {
                $this->settings["seedec"] = self::SEEDEC_REV;
            }
        }
        if (($this->settings["pc_seeallrev"] ?? null) == 2) {
            $this->settings["pc_seeblindrev"] = 1;
            $this->settings["pc_seeallrev"] = self::PCSEEREV_YES;
        }
        if (($sub_update = $this->settings["sub_update"] ?? -1) > 0
            && ($sub_reg = $this->settings["sub_reg"] ?? -1) <= 0) {
            $this->settings["sub_reg"] = $sub_update;
            $this->settings["__sub_reg"] = $sub_reg;
        }

        // rounds
        $this->refresh_round_settings();

        // S3 settings
        foreach (["s3_bucket", "s3_key", "s3_secret"] as $k) {
            if (!($this->settingTexts[$k] ?? null)
                && ($x = $this->opt[$k] ?? null)) {
                $this->settingTexts[$k] = $x;
            }
        }
        if (!($this->settingTexts["s3_key"] ?? null)
            || !($this->settingTexts["s3_secret"] ?? null)
            || !($this->settingTexts["s3_bucket"] ?? null)) {
            unset($this->settingTexts["s3_key"], $this->settingTexts["s3_secret"],
                  $this->settingTexts["s3_bucket"]);
        }
        if (($this->opt["dbNoPapers"] ?? null)
            && !($this->opt["docstore"] ?? null)
            && !($this->opt["filestore"] ?? null)
            && !($this->settingTexts["s3_bucket"] ?? null)) {
            unset($this->opt["dbNoPapers"]);
        }
        if ($this->_s3_client
            && (!isset($this->settingTexts["s3_bucket"])
                || !$this->_s3_client->check_key_secret_bucket($this->settingTexts["s3_key"], $this->settingTexts["s3_secret"], $this->settingTexts["s3_bucket"]))) {
            $this->_s3_client = false;
        }

        // tracks settings
        $this->_tracks = $this->_track_tags = null;
        $this->_track_sensitivity = 0;
        if (($j = $this->settingTexts["tracks"] ?? null)) {
            $this->refresh_track_settings($j);
        }
        if (($this->settings["has_permtag"] ?? 0) > 0) {
            $this->_has_permtag = true;
        }

        // clear caches
        $this->_paper_opts->invalidate_options();
        $this->_decisions = null;
        $this->_decision_matcher = null;
        $this->_decision_status_info = null;
        $this->_review_form = null;
        $this->_defined_rounds = null;
        $this->_resp_rounds = null;
        $this->_formatspec_cache = [];
        $this->_abbrev_matcher = null;
        $this->_tag_map = null;
        $this->_formula_functions = null;
        $this->_assignment_parsers = null;
        $this->_topic_set = null;

        // digested settings
        $this->au_seerev = $this->settings["au_seerev"] ?? 0;
        $this->tag_au_seerev = null;
        if ($this->au_seerev == self::AUSEEREV_TAGS) {
            $this->tag_au_seerev = explode(" ", $this->settingTexts["tag_au_seerev"] ?? "");
        }
        $this->tag_seeall = ($this->settings["tag_seeall"] ?? 0) > 0;
        $this->ext_subreviews = $this->settings["pcrev_editdelegate"] ?? 0;
        $this->_maybe_automatic_tags = ($this->settings["tag_vote"] ?? 0) > 0
            || ($this->settings["tag_approval"] ?? 0) > 0
            || ($this->settings["tag_autosearch"] ?? 0) > 0
            || !!$this->opt("definedTags");
        $this->refresh_time_settings();
    }

    private function refresh_time_settings() {
        $tf = $this->time_between_settings("sub_open", "sub_sub", "sub_grace");
        $this->_pc_see_cache = (($this->settings["sub_freeze"] ?? 0) > 0 ? 1 : 0)
            | ($tf === 1 ? 2 : 0)
            | ($tf > 0 ? 4 : 0)
            | (($this->settings["pc_seeallpdf"] ?? 0) > 0 ? 16 : 0);
        if (($this->settings["pc_seeall"] ?? 0) > 0
            && ($this->_pc_see_cache & 4) !== 0) {
            $this->_pc_see_cache |= 8;
        }

        $this->any_response_open = 0;
        if (($this->settings["resp_active"] ?? 0) > 0) {
            foreach ($this->response_rounds() as $rrd) {
                if ($rrd->time_allowed(true)) {
                    if ($rrd->search) {
                        $this->any_response_open = 1;
                    } else {
                        $this->any_response_open = 2;
                        break;
                    }
                }
            }
        }
    }

    private function refresh_round_settings() {
        $this->rounds = [""];
        if (isset($this->settingTexts["tag_rounds"])) {
            foreach (explode(" ", $this->settingTexts["tag_rounds"]) as $r) {
                if ($r !== "")
                    $this->rounds[] = $r;
            }
        }
        $this->_round_settings = null;
        if (isset($this->settingTexts["round_settings"])) {
            $this->_round_settings = json_decode($this->settingTexts["round_settings"]);
            $max_rs = [];
            foreach ($this->_round_settings as $rs) {
                if ($rs
                    && isset($rs->pc_seeallrev)
                    && self::pcseerev_compare($rs->pc_seeallrev, $max_rs["pc_seeallrev"] ?? 0) > 0) {
                    $max_rs["pc_seeallrev"] = $rs->pc_seeallrev;
                }
                if ($rs && isset($rs->extrev_view)
                    && $rs->extrev_view > ($max_rs["extrev_view"] ?? 0)) {
                    $max_rs["extrev_view"] = $rs->extrev_view;
                }
            }
            $this->_round_settings["max"] = (object) $max_rs;
        }

        // review times
        foreach ($this->rounds as $i => $rname) {
            $suf = $i ? "_$i" : "";
            if (!isset($this->settings["extrev_soft$suf"])
                && isset($this->settings["pcrev_soft$suf"])) {
                $this->settings["extrev_soft$suf"] = $this->settings["pcrev_soft$suf"];
            }
            if (!isset($this->settings["extrev_hard$suf"])
                && isset($this->settings["pcrev_hard$suf"])) {
                $this->settings["extrev_hard$suf"] = $this->settings["pcrev_hard$suf"];
            }
        }
    }

    private function refresh_track_settings($j) {
        if (is_string($j) && !($j = json_decode($j))) {
            return;
        }
        $this->_tracks = [];
        $this->_track_tags = [];
        $trest = new Track("");
        foreach ((array) $j as $tag => $v) {
            if ($tag === "" || $tag === "_") {
                $tr = $trest;
            } else if (!($tr = $this->track($tag))) {
                $this->_tracks[] = $tr = new Track($tag);
                $this->_track_tags[] = $tag;
            }
            if (!isset($v->viewpdf) && isset($v->view)) {
                $v->viewpdf = $v->view;
            }
            foreach (Track::$perm_name_map as $tname => $idx) {
                if (isset($v->$tname)) {
                    $tr->perm[$idx] = $v->$tname;
                    $this->_track_sensitivity |= 1 << $idx;
                }
            }
        }
        $this->_tracks[] = $trest;
    }

    function refresh_options() {
        // set longName, downloadPrefix, etc.
        $confid = $this->opt["confid"];
        if (($this->opt["longName"] ?? "") === "") {
            if (($this->opt["shortName"] ?? "") === "") {
                $this->opt["shortNameDefaulted"] = true;
                $this->opt["shortName"] = $confid;
            }
            $this->opt["longName"] = $this->opt["shortName"];
        } else if (($this->opt["shortName"] ?? "") === "") {
            $this->opt["shortName"] = $this->opt["longName"];
        }
        if (($this->opt["downloadPrefix"] ?? "") === "") {
            $this->opt["downloadPrefix"] = $confid . "-";
        }
        $this->short_name = $this->opt["shortName"];
        $this->long_name = $this->opt["longName"];

        // expand ${confid}, ${confshortname}
        foreach (["sessionName", "downloadPrefix", "conferenceSite",
                  "paperSite", "defaultPaperSite", "contactName",
                  "contactEmail", "docstore"] as $k) {
            if (isset($this->opt[$k])
                && is_string($this->opt[$k])
                && strpos($this->opt[$k], "\$") !== false) {
                $this->opt[$k] = preg_replace('/\$\{confid\}|\$confid\b/', $confid, $this->opt[$k]);
                $this->opt[$k] = preg_replace('/\$\{confshortname\}|\$confshortname\b/', $this->short_name, $this->opt[$k]);
            }
        }
        $this->download_prefix = $this->opt["downloadPrefix"];

        foreach (["emailFrom", "emailSender", "emailCc", "emailReplyTo"] as $k) {
            if (isset($this->opt[$k])
                && is_string($this->opt[$k])
                && strpos($this->opt[$k], "\$") !== false) {
                $this->opt[$k] = preg_replace('/\$\{confid\}|\$confid\b/', $confid, $this->opt[$k]);
                if (strpos($this->opt[$k], "confshortname") !== false) {
                    $v = rfc2822_words_quote($this->short_name);
                    if ($v[0] === "\"" && strpos($this->opt[$k], "\"") !== false) {
                        $v = substr($v, 1, strlen($v) - 2);
                    }
                    $this->opt[$k] = preg_replace('/\$\{confshortname\}|\$confshortname\b/', $v, $this->opt[$k]);
                }
            }
        }

        // remove final slash from $Opt["paperSite"]
        if (!isset($this->opt["paperSite"]) || $this->opt["paperSite"] === "") {
            $this->opt["paperSite"] = Navigation::base_absolute();
        }
        if ($this->opt["paperSite"] == "" && isset($this->opt["defaultPaperSite"])) {
            $this->opt["paperSite"] = $this->opt["defaultPaperSite"];
        }
        while (str_ends_with($this->opt["paperSite"], "/")) {
            $this->opt["paperSite"] = substr($this->opt["paperSite"], 0, -1);
        }

        // assert URLs (general assets, scripts, jQuery)
        $this->opt["assetsUrl"] = $this->opt["assetsUrl"] ?? $this->opt["assetsURL"] ?? (string) Navigation::siteurl();
        if ($this->opt["assetsUrl"] !== "" && !str_ends_with($this->opt["assetsUrl"], "/")) {
            $this->opt["assetsUrl"] .= "/";
        }

        if (!isset($this->opt["scriptAssetsUrl"])
            && isset($_SERVER["HTTP_USER_AGENT"])
            && strpos($_SERVER["HTTP_USER_AGENT"], "MSIE") !== false) {
            $this->opt["scriptAssetsUrl"] = Navigation::siteurl();
        }
        if (!isset($this->opt["scriptAssetsUrl"])) {
            $this->opt["scriptAssetsUrl"] = $this->opt["assetsUrl"];
        }

        // set docstore
        $docstore = $this->opt["docstore"] ?? null;
        $dpath = "";
        $dpsubdir = $this->opt["docstoreSubdir"] ?? null;
        if (is_string($docstore)) {
            $dpath = $docstore;
        } else if ($docstore === true) {
            $dpath = "docs";
        } else if ($docstore === null && isset($this->opt["filestore"])) {
            if (is_string($this->opt["filestore"])) {
                $dpath = $this->opt["filestore"];
            } else if ($this->opt["filestore"] === true) {
                $dpath = "filestore";
            }
            $dpsubdir = $this->opt["filestoreSubdir"] ?? null;
        }
        if ($dpath !== "") {
            if ($dpath[0] !== "/") {
                $dpath = SiteLoader::$root . "/" . $dpath;
            }
            if (strpos($dpath, "%") === false) {
                $dpath .= ($dpath[strlen($dpath) - 1] === "/" ? "" : "/");
                if ($dpsubdir && ($dpsubdir === true || $dpsubdir > 0)) {
                    $dpath .= "%" . ($dpsubdir === true ? 2 : $dpsubdir) . "h/";
                }
                $dpath .= "%h%x";
            }
            $this->_docstore = $dpath;
        } else {
            $this->_docstore = null;
        }

        // set defaultFormat
        $this->default_format = (int) ($this->opt["defaultFormat"] ?? 0);
        $this->_format_info = null;

        // emails
        if (($eol = $this->opt["postfixMailer"] ?? $this->opt["postfixEOL"] ?? false)) {
            $this->opt["postfixEOL"] = is_string($eol) ? $eol : PHP_EOL;
        }
        $this->_dkim_signer = false;

        // other caches
        $sort_by_last = !!($this->opt["sortByLastName"] ?? false);
        if (!$this->sort_by_last != !$sort_by_last) {
            $this->invalidate_caches(["pc" => true]);
        }
        $this->sort_by_last = $sort_by_last;

        $this->_api_map = null;
        $this->_file_filters = null;
        $this->_site_contact = null;
        $this->_date_format_initialized = false;
        $this->_dtz = null;

        if ($this === Conf::$main) {
            $this->refresh_globals();
        }
    }

    function refresh_globals() {
        Ht::$img_base = $this->opt["assetsUrl"] . "images/";

        if (isset($this->opt["timezone"])) {
            if (!date_default_timezone_set($this->opt["timezone"])) {
                $this->error_msg("<0>Timezone option ‘" . $this->opt["timezone"] . "’ is invalid; falling back to ‘America/New_York’");
                date_default_timezone_set("America/New_York");
            }
        } else if (!ini_get("date.timezone") && !getenv("TZ")) {
            date_default_timezone_set("America/New_York");
        }
    }

    static function set_main_instance(Conf $conf) {
        global $Conf;
        $Conf = Conf::$main = $conf;
        $conf->refresh_globals();
    }


    /** @return bool */
    function has_setting($name) {
        return isset($this->settings[$name]);
    }

    /** @param string $name
     * @return ?int */
    function setting($name) {
        return $this->settings[$name] ?? null;
    }

    /** @param string $name
     * @return ?string */
    function setting_data($name) {
        return $this->settingTexts[$name] ?? null;
    }

    /** @param string $name
     * @return mixed */
    function setting_json($name) {
        $x = $this->settingTexts[$name] ?? null;
        return $x !== null ? json_decode($x) : null;
    }

    /** @param string $name
     * @param ?int $value
     * @param null|string|array|object $data
     * @return bool */
    function change_setting($name, $value, $data = null) {
        if ($value === null && $data === null) {
            if (($change = isset($this->settings[$name]))) {
                unset($this->settings[$name], $this->settingTexts[$name]);
            }
        } else {
            $value = (int) $value;
            $dval = is_array($data) || is_object($data) ? json_encode_db($data) : $data;
            if (($change = ($this->settings[$name] ?? null) !== $value
                           || ($this->settingTexts[$name] ?? null) !== $dval)) {
                $this->settings[$name] = $value;
                $this->settingTexts[$name] = $dval;
            }
        }
        if ($change && str_starts_with($name, "opt.")) {
            $oname = substr($name, 4);
            if ($value === null && $data === null) {
                $this->opt[$oname] = $this->opt_override[$oname] ?? null;
            } else {
                $this->opt_override[$oname] = $this->opt[$oname] ?? null;
                $this->opt[$oname] = $data === null ? $value : $data;
            }
        }
        return $change;
    }

    /** @param string $name
     * @param ?int $value
     * @param null|string|array|object $data
     * @return bool */
    function save_setting($name, $value, $data = null) {
        if ($value === null && $data === null) {
            $result = $this->qe("delete from Settings where name=?", $name);
            $change = $result->affected_rows !== 0;
        } else {
            $value = (int) $value;
            $dval = is_array($data) || is_object($data) ? json_encode_db($data) : $data;
            $result = $this->qe("insert into Settings (name, value, data) values (?, ?, ?) ?U on duplicate key update value=?U(value), data=?U(data)", $name, $value, $dval);
            $change = $result->affected_rows !== 0;
        }
        // return if changed EITHER in database OR in this instance
        return $this->change_setting($name, $value, $data) || $change;
    }

    /** @param string $name
     * @param ?int $value
     * @return bool */
    function save_refresh_setting($name, $value, $data = null) {
        $change = $this->save_setting($name, $value, $data);
        if ($change) {
            $this->refresh_settings();
            if (str_starts_with($name, "opt.")) {
                $this->refresh_options();
            }
        }
        return $change;
    }


    /** @param string $name
     * @return mixed */
    function opt($name) {
        return $this->opt[$name] ?? null;
    }

    /** @param string $name
     * @param mixed $value */
    function set_opt($name, $value) {
        global $Opt;
        $Opt[$name] = $this->opt[$name] = $value;
    }

    /** @return int */
    function opt_timestamp() {
        if ($this->_opt_timestamp === null) {
            $this->_opt_timestamp = 1;
            foreach ($this->opt["loaded"] ?? [] as $fn) {
                $this->_opt_timestamp = max($this->_opt_timestamp, +@filemtime($fn));
            }
        }
        return $this->_opt_timestamp;
    }


    static function pcseerev_compare($sr1, $sr2) {
        if ($sr1 == $sr2) {
            return 0;
        } else if ($sr1 == self::PCSEEREV_YES || $sr2 == self::PCSEEREV_YES) {
            return $sr1 == self::PCSEEREV_YES ? 1 : -1;
        } else {
            return $sr1 > $sr2 ? 1 : -1;
        }
    }


    // database

    /** @return Dbl_Result */
    function q(...$args /* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, $args, 0);
    }
    /** @return Dbl_Result */
    function q_raw(...$args /* $qstr */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_RAW);
    }
    /** @return Dbl_Result */
    function q_apply(...$args /* $qstr, $argv */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_APPLY);
    }

    /** @return Dbl_Result */
    function ql(...$args /* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_LOG);
    }
    /** @return Dbl_Result */
    function ql_raw(...$args /* $qstr */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_RAW | Dbl::F_LOG);
    }
    /** @return Dbl_Result */
    function ql_apply(...$args /* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_APPLY | Dbl::F_LOG);
    }
    /** @return ?Dbl_Result */
    function ql_ok(...$args /* $qstr, ... */) {
        $result = Dbl::do_query_on($this->dblink, $args, Dbl::F_LOG);
        return Dbl::is_error($result) ? null : $result;
    }

    /** @return Dbl_Result */
    function qe(...$args /* $qstr, ... */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR);
    }
    /** @return Dbl_Result */
    function qe_raw(...$args /* $qstr */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_RAW | Dbl::F_ERROR);
    }
    /** @return Dbl_Result */
    function qe_apply(...$args /* $qstr, $args */) {
        return Dbl::do_query_on($this->dblink, $args, Dbl::F_APPLY | Dbl::F_ERROR);
    }

    /** @return list<list<?string>> */
    function fetch_rows(...$args /* $qstr, ... */) {
        return Dbl::fetch_rows(Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR));
    }
    /** @return ?list<?string> */
    function fetch_first_row(...$args /* $qstr, ... */) {
        return Dbl::fetch_first_row(Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR));
    }
    /** @return ?object */
    function fetch_first_object(...$args /* $qstr, ... */) {
        return Dbl::fetch_first_object(Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR));
    }
    /** @return ?string */
    function fetch_value(...$args /* $qstr, ... */) {
        return Dbl::fetch_value(Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR));
    }
    /** @return ?int */
    function fetch_ivalue(...$args /* $qstr, ... */) {
        return Dbl::fetch_ivalue(Dbl::do_query_on($this->dblink, $args, Dbl::F_ERROR));
    }

    function db_error_html($getdb = true) {
        $text = "<p>Database error";
        if ($getdb) {
            $text .= ": " . htmlspecialchars($this->dblink->error);
        }
        return $text . "</p>";
    }

    function db_error_text($getdb = true) {
        $text = "Database error";
        if ($getdb) {
            $text .= ": " . $this->dblink->error;
        }
        return $text;
    }

    function query_error_handler($dblink, $query) {
        $landmark = caller_landmark(1, "/^(?:Dbl::|Conf::q|call_user_func)/");
        if (PHP_SAPI == "cli") {
            fwrite(STDERR, "$landmark: database error: $dblink->error in $query\n" . debug_string_backtrace());
        } else {
            error_log("$landmark: database error: $dblink->error in $query\n" . debug_string_backtrace());
            $this->error_msg("<5><p>" . htmlspecialchars($landmark) . ": database error: " . htmlspecialchars($this->dblink->error) . " in " . Ht::pre_text_wrap($query) . "</p>");
        }
    }


    /** @return Collator */
    function collator() {
        if (!$this->_collator) {
            $this->_collator = new Collator("en_US.utf8");
            $this->_collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        }
        return $this->_collator;
    }

    /** @return Collator */
    function punctuation_collator() {
        if (!$this->_pcollator) {
            $this->_pcollator = new Collator("en_US.utf8");
            $this->_pcollator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
            $this->_pcollator->setAttribute(Collator::ALTERNATE_HANDLING, Collator::SHIFTED);
            $this->_pcollator->setStrength(Collator::QUATERNARY);
        }
        return $this->_pcollator;
    }

    /** @return callable(Contact|Author,Contact|Author):int */
    function user_comparator() {
        $sortspec = $this->sort_by_last ? 0312 : 0321;
        $pcollator = $this->punctuation_collator();
        return function ($a, $b) use ($sortspec, $pcollator) {
            $as = Contact::get_sorter($a, $sortspec);
            $bs = Contact::get_sorter($b, $sortspec);
            return $pcollator->compare($as, $bs);
        };
    }


    // name

    /** @return string */
    function full_name() {
        if ($this->short_name && $this->short_name != $this->long_name) {
            return $this->long_name . " (" . $this->short_name . ")";
        } else {
            return $this->long_name;
        }
    }


    /** @return FormatSpec */
    function format_spec($dtype) {
        if (!isset($this->_formatspec_cache[$dtype])) {
            $o = $this->option_by_id($dtype);
            $spec = ($o ? $o->format_spec() : null) ?? new FormatSpec;
            if (!$spec->timestamp && $this->opt("banalAlways")) {
                $spec->timestamp = 1;
            }
            $this->_formatspec_cache[$dtype] = $spec;
        }
        return $this->_formatspec_cache[$dtype];
    }

    /** @return ?non-empty-string */
    function docstore() {
        return $this->_docstore;
    }

    /** @return ?S3Client */
    function s3_docstore() {
        if ($this->_s3_client === false) {
            if ($this->setting_data("s3_bucket")) {
                $opts = [
                    "key" => $this->setting_data("s3_key"),
                    "secret" => $this->setting_data("s3_secret"),
                    "bucket" => $this->setting_data("s3_bucket"),
                    "setting_cache" => $this,
                    "setting_cache_prefix" => "__s3"
                ];
                $this->_s3_client = S3Client::make($opts);
            } else {
                $this->_s3_client = null;
            }
        }
        return $this->_s3_client;
    }


    /** @param ?object $xt
     * @return int|float */
    static function xt_priority($xt) {
        return $xt ? $xt->priority ?? 0 : -INF;
    }
    /** @param ?object $xta
     * @param ?object $xtb
     * @return -1|0|1 */
    static function xt_priority_compare($xta, $xtb) {
        // Return -1 if $xta is higher priority, 1 if $xtb is.
        $ap = self::xt_priority($xta);
        $bp = self::xt_priority($xtb);
        if ($ap == $bp) {
            $ap = $xta ? $xta->__source_order ?? 0 : -INF;
            $bp = $xtb ? $xtb->__source_order ?? 0 : -INF;
        }
        return $bp <=> $ap;
    }
    /** @param object $xta
     * @param object $xtb
     * @return -1|0|1 */
    static function xt_order_compare($xta, $xtb) {
        $ap = $xta->order ?? 0;
        $ap = $ap !== false ? $ap : INF;
        $bp = $xtb->order ?? 0;
        $bp = $bp !== false ? $bp : INF;
        if ($ap == $bp) {
            if (isset($xta->name)
                && isset($xtb->name)
                && ($namecmp = strcmp($xta->name, $xtb->name)) !== 0) {
                return $namecmp;
            }
            $ap = $xta->__source_order ?? 0;
            $bp = $xtb->__source_order ?? 0;
        }
        return $ap <=> $bp;
    }
    /** @param object $xta
     * @param object $xtb
     * @return -1|0|1 */
    static function xt_pure_order_compare($xta, $xtb) {
        $ap = $xta->order ?? 0;
        $ap = $ap !== false ? $ap : INF;
        $bp = $xtb->order ?? 0;
        $bp = $bp !== false ? $bp : INF;
        if ($ap == $bp) {
            $ap = $xta->__source_order ?? 0;
            $bp = $xtb->__source_order ?? 0;
        }
        return $ap <=> $bp;
    }
    /** @param array<string|int,list<object>> &$a
     * @param object $xt
     * @return true */
    static function xt_add(&$a, $name, $xt) {
        $a[$name][] = $xt;
        return true;
    }
    /** @param object $xt1
     * @param object $xt2 */
    static private function xt_combine($xt1, $xt2) {
        foreach (get_object_vars($xt2) as $k => $v) {
            if (!property_exists($xt1, $k)
                && $k !== "match"
                && $k !== "expand_function")
                $xt1->$k = $v;
        }
    }
    /** @param ?object $xt
     * @return bool */
    static function xt_enabled($xt) {
        return $xt && (!isset($xt->disabled) || !$xt->disabled);
    }
    /** @param ?object $xt
     * @return ?object */
    static function xt_resolve_require($xt) {
        if ($xt
            && isset($xt->require)
            && !isset(self::$xt_require_resolved[$xt->require])) {
            foreach (SiteLoader::expand_includes($xt->require, ["autoload" => true]) as $f) {
                require_once($f);
            }
            self::$xt_require_resolved[$xt->require] = true;
        }
        return $xt && (!isset($xt->disabled) || !$xt->disabled) ? $xt : null;
    }
    /** @param XtContext $context
     * @return ?XtContext */
    function xt_swap_context($context) {
        $old = $this->xt_context;
        $this->xt_context = $context;
        return $old;
    }
    /** @param string $s
     * @param ?Contact $user
     * @return bool */
    function xt_check_string($s, $xt, $user) {
        if ($s === "chair" || $s === "admin") {
            return !$user || $user->privChair;
        } else if ($s === "manager") {
            return !$user || $user->is_manager();
        } else if ($s === "pc") {
            return !$user || $user->isPC;
        } else if ($s === "reviewer") {
            return !$user || $user->is_reviewer();
        } else if ($s === "view_review") {
            return !$user || $user->can_view_some_review();
        } else if ($s === "lead" || $s === "shepherd") {
            return $this->has_any_lead_or_shepherd();
        } else if ($s === "empty") {
            return $user && $user->is_empty();
        } else if ($s === "allow" || $s === "true") {
            return true;
        } else if ($s === "deny" || $s === "false") {
            return false;
        } else if (strcspn($s, " !&|()") !== strlen($s)) {
            $e = $this->xt_check_complex_string($s, $xt, $user);
            if ($e === null) {
                throw new UnexpectedValueException("xt_check syntax error in `$s`");
            }
            return $e;
        } else if (strpos($s, "::") !== false) {
            self::xt_resolve_require($xt);
            return call_user_func($s, $xt, $user, $this);
        } else if (str_starts_with($s, "opt.")) {
            return !!$this->opt(substr($s, 4));
        } else if (str_starts_with($s, "setting.")) {
            return !!$this->setting(substr($s, 8));
        } else if (str_starts_with($s, "conf.")) {
            $f = substr($s, 5);
            return !!$this->$f();
        } else if (str_starts_with($s, "user.")) {
            $f = substr($s, 5);
            return !$user || $user->$f();
        } else if ($this->xt_context
                   && ($x = $this->xt_context->xt_check_element($s, $xt, $user, $this)) !== null) {
            return $x;
        } else {
            error_log("unknown xt_check $s");
            return false;
        }
    }
    /** @param string $s
     * @param ?Contact $user
     * @return ?bool */
    function xt_check_complex_string($s, $xt, $user) {
        $stk = [];
        $p = 0;
        $l = strlen($s);
        $e = null;
        $eval = true;
        while ($p !== $l) {
            $ch = $s[$p];
            if ($ch === " ") {
                ++$p;
            } else if ($ch === "(" || $ch === "!") {
                if ($e !== null) {
                    return null;
                }
                $stk[] = [$ch === "(" ? 0 : 9, null, $eval];
                ++$p;
            } else if ($ch === "&" || $ch === "|") {
                if ($e === null || $p + 1 === $l || $s[$p + 1] !== $ch) {
                    return null;
                }
                $prec = $ch === "&" ? 2 : 1;
                $e = self::xt_check_complex_resolve_stack($stk, $e, $prec);
                $stk[] = [$prec, $e, $eval];
                $eval = self::xt_check_complex_want_eval($stk);
                $e = null;
                $p += 2;
            } else if ($ch === ")") {
                if ($e === null) {
                    return null;
                }
                $e = self::xt_check_complex_resolve_stack($stk, $e, 1);
                if (empty($stk)) {
                    return null;
                }
                array_pop($stk);
                $eval = self::xt_check_complex_want_eval($stk);
                ++$p;
            } else {
                if ($e !== null) {
                    return null;
                }
                $wl = strcspn($s, " !&|()", $p);
                $e = $eval && self::xt_check_string(substr($s, $p, $wl), $xt, $user);
                $p += $wl;
            }
        }
        if (!empty($stk) && $e !== null) {
            $e = self::xt_check_complex_resolve_stack($stk, $e, 1);
        }
        return empty($stk) ? $e : null;
    }
    /** @param list<array{int,?bool,bool}> &$stk
     * @param bool $e
     * @param int $prec
     * @return bool */
    static function xt_check_complex_resolve_stack(&$stk, $e, $prec) {
        $n = count($stk) - 1;
        while ($n >= 0 && $stk[$n][0] >= $prec) {
            $se = array_pop($stk);
            '@phan-var array{int,?bool,bool} $se';
            --$n;
            if ($se[0] === 9) {
                $e = !$e;
            } else if ($se[0] === 2) {
                $e = $se[1] && $e;
            } else {
                $e = $se[1] || $e;
            }
        }
        return $e;
    }
    /** @param list<array{int,?bool,bool}> $stk
     * @return bool */
    static function xt_check_complex_want_eval($stk) {
        $n = count($stk);
        $se = $n ? $stk[$n - 1] : null;
        return !$se || ($se[2] && ($se[0] !== 1 || !$se[1]) && ($se[0] !== 2 || $se[1]));
    }
    /** @param list<string>|string|bool $expr
     * @param ?Contact $user
     * @return bool */
    function xt_check($expr, $xt = null, $user = null) {
        if (is_bool($expr)) {
            return $expr;
        } else if (is_string($expr)) {
            return $this->xt_check_string($expr, $xt, $user);
        } else {
            foreach ($expr as $e) {
                if (!(is_bool($e) ? $e : $this->xt_check_string($e, $xt, $user)))
                    return false;
            }
            return true;
        }
    }
    /** @param object $xt
     * @return bool */
    function xt_allowed($xt, Contact $user = null) {
        return $xt && (!isset($xt->allow_if)
                       || $this->xt_check($xt->allow_if, $xt, $user));
    }
    /** @param object $xt
     * @return list<string> */
    static function xt_allow_list($xt) {
        if ($xt && isset($xt->allow_if)) {
            return is_array($xt->allow_if) ? $xt->allow_if : [$xt->allow_if];
        } else {
            return [];
        }
    }
    /** @param object $xt
     * @param ?Contact $user
     * @return bool */
    function xt_checkf($xt, $user) {
        if ($this->_xt_allow_callback !== null) {
            return call_user_func($this->_xt_allow_callback, $xt, $user);
        } else {
            return !isset($xt->allow_if)
                || $this->xt_check($xt->allow_if, $xt, $user);
        }
    }
    /** @param array<string,list<object>> $map
     * @param string $name
     * @param ?Contact $user
     * @param bool $noalias
     * @return ?object */
    function xt_search_name($map, $name, $user, $noalias = false) {
        $this->_xt_last_match = null;
        for ($aliases = 0;
             $aliases < 5 && $name !== null && isset($map[$name]);
             ++$aliases) {
            $xt = $this->xt_search_list($map[$name], $user);
            if ($xt && isset($xt->alias) && is_string($xt->alias) && !$noalias) {
                $name = $xt->alias;
            } else {
                return $xt;
            }
        }
        return null;
    }
    /** @param list<object> $list
     * @param ?Contact $user
     * @return ?object */
    function xt_search_list($list, $user) {
        $nlist = count($list);
        if ($nlist > 1) {
            usort($list, "Conf::xt_priority_compare");
        }
        for ($i = 0; $i < $nlist; ++$i) {
            $xt = $list[$i];
            while ($i + 1 < $nlist && ($xt->merge ?? false)) {
                ++$i;
                $overlay = $xt;
                $xt = clone $list[$i];
                foreach (get_object_vars($overlay) as $k => $v) {
                    if ($k === "merge" || $k === "__source_order") {
                        // skip
                    } else if ($v === null) {
                        unset($xt->{$k});
                    } else if (!property_exists($xt, $k)
                               || !is_object($v)
                               || !is_object($xt->{$k})) {
                        $xt->{$k} = $v;
                    } else {
                        object_replace_recursive($xt->{$k}, $v);
                    }
                }
            }
            if (isset($xt->deprecated) && $xt->deprecated) {
                $name = $xt->name ?? "<unknown>";
                error_log("{$this->dbname}: deprecated use of `{$name}`\n" . debug_string_backtrace());
            }
            $this->_xt_last_match = $xt;
            if ($this->xt_checkf($xt, $user)) {
                return $xt;
            }
        }
        return null;
    }
    /** @param list<object> $factories
     * @param string $name
     * @param ?Contact $user
     * @param ?object $found
     * @param string $reflags
     * @return non-empty-list<?object> */
    function xt_search_factories($factories, $name, $user, $found = null, $reflags = "") {
        $xts = [$found];
        foreach ($factories as $fxt) {
            if (self::xt_priority_compare($fxt, $found ?? $this->_xt_last_match) > 0) {
                break;
            }
            if (!isset($fxt->match)) {
                continue;
            } else if ($fxt->match === ".*") {
                $m = [$name];
            } else if (!preg_match("\1\\A(?:{$fxt->match})\\z\1{$reflags}", $name, $m)) {
                continue;
            }
            if (!$this->xt_checkf($fxt, $user)) {
                continue;
            }
            self::xt_resolve_require($fxt);
            if (!$user) {
                $user = $this->root_user();
            }
            if (isset($fxt->expand_function)) {
                $r = call_user_func($fxt->expand_function, $name, $user, $fxt, $m);
            } else {
                $r = (object) ["name" => $name, "match_data" => $m];
            }
            if (is_object($r)) {
                $r = [$r];
            }
            foreach ($r ? : [] as $xt) {
                self::xt_combine($xt, $fxt);
                $prio = self::xt_priority_compare($xt, $found);
                if ($prio <= 0 && $this->xt_checkf($xt, $user)) {
                    if ($prio < 0) {
                        $xts = [$xt];
                        $found = $xt;
                    } else {
                        $xts[] = $xt;
                    }
                }
            }
        }
        return $xts;
    }


    // emoji codes
    function _add_emoji_code($val, $key) {
        if (is_string($val) && str_starts_with($key, ":") && str_ends_with($key, ":")) {
            $this->_emoji_codes->emoji[$key] = $val;
            return true;
        } else {
            return false;
        }
    }
    /** @return array<string,string> */
    function emoji_code_map() {
        if ($this->_emoji_codes === null) {
            $this->_emoji_codes = json_decode(file_get_contents(SiteLoader::find("scripts/emojicodes.json")));
            $this->_emoji_codes->emoji = (array) $this->_emoji_codes->emoji;
            if (($olist = $this->opt("emojiCodes")))
                expand_json_includes_callback($olist, [$this, "_add_emoji_code"]);
        }
        return $this->_emoji_codes->emoji;
    }


    /** @return PaperOptionList */
    function options() {
        return $this->_paper_opts;
    }

    /** @param int $id
     * @return ?PaperOption */
    function option_by_id($id) {
        return $this->_paper_opts->option_by_id($id);
    }

    /** @param int $id
     * @return PaperOption */
    function checked_option_by_id($id) {
        return $this->_paper_opts->checked_option_by_id($id);
    }


    /** @return array<int,Formula> */
    function named_formulas() {
        if ($this->_defined_formulas === null) {
            $this->_defined_formulas = [];
            if ($this->setting("formulas")) {
                $result = $this->q("select * from Formula");
                while ($result && ($f = Formula::fetch($this, $result))) {
                    $this->_defined_formulas[$f->formulaId] = $f;
                }
                Dbl::free($result);
                uasort($this->_defined_formulas, function ($a, $b) {
                    return strnatcasecmp($a->name, $b->name);
                });
            }
        }
        return $this->_defined_formulas;
    }

    /** @param array<int,Formula> $formula_map */
    function replace_named_formulas($formula_map) {
        $this->_defined_formulas = $formula_map;
        $this->_abbrev_matcher = null;
    }

    /** @return ?Formula */
    function find_named_formula($text) {
        return $this->abbrev_matcher()->find1($text, self::MFLAG_FORMULA);
    }

    /** @return array<int,Formula> */
    function viewable_named_formulas(Contact $user) {
        return array_filter($this->named_formulas(), function ($f) use ($user) {
            return $user->can_view_formula($f);
        });
    }


    /** @return array<int,string> */
    function decision_map() {
        if ($this->_decisions === null) {
            if (($j = $this->settingTexts["outcome_map"] ?? null)
                && ($j = json_decode($j, true))
                && is_array($j)) {
                $dmap = $j;
            } else {
                // see also settinginfo.json default_value
                $dmap = [1 => "Accepted", -1 => "Rejected"];
            }
            $dmap[0] = "Unspecified";
            $collator = $this->collator();
            $this->_decisions = $dmap;
            uksort($this->_decisions, function ($ka, $kb) use ($dmap, $collator) {
                if ($ka === 0 || $kb === 0) {
                    return $ka === 0 ? -1 : 1;
                } else if (($ka > 0) !== ($kb > 0)) {
                    return $ka > 0 ? -1 : 1;
                } else {
                    return $collator->compare($dmap[$ka], $dmap[$kb]);
                }
            });
        }
        return $this->_decisions;
    }

    /** @param int $dnum
     * @return string|false */
    function decision_name($dnum) {
        return ($this->decision_map())[$dnum] ?? false;
    }

    /** @param string $dname
     * @return string|false */
    static function decision_name_error($dname) {
        $dname = simplify_whitespace($dname);
        if ((string) $dname === "") {
            return "Empty decision name";
        } else if (preg_match('/\A(?:yes|no|any|none|unknown|unspecified|undecided|\?)\z/i', $dname)) {
            return "Decision name “{$dname}” is reserved";
        } else {
            return false;
        }
    }

    /** @return AbbreviationMatcher<int> */
    function decision_matcher() {
        if ($this->_decision_matcher === null) {
            $this->_decision_matcher = new AbbreviationMatcher;
            foreach ($this->decision_map() as $d => $dname) {
                $this->_decision_matcher->add_phrase($dname, $d);
            }
            foreach (["none", "unknown", "undecided", "?"] as $dname) {
                $this->_decision_matcher->add_phrase($dname, 0);
            }
        }
        return $this->_decision_matcher;
    }

    /** @param string $dname
     * @return list<int> */
    function find_all_decisions($dname) {
        return $this->decision_matcher()->find_all($dname);
    }

    /** @param int $dnum
     * @return array{string,string} */
    function decision_status_info($dnum) {
        if ($this->_decision_status_info === null) {
            $this->_decision_status_info = [];
        }
        $s = $this->_decision_status_info[$dnum] ?? null;
        if (!$s) {
            $decclass = $dnum > 0 ? "pstat_decyes" : "pstat_decno";
            if (($decname = $this->decision_name($dnum))) {
                if (($trdecname = preg_replace('/[^-.\w]/', '', $decname)) !== "") {
                    $decclass .= " pstat_" . strtolower($trdecname);
                }
            } else {
                $decname = "Unknown decision #" . $dnum;
            }
            $s = $this->_decision_status_info[$dnum] = [$decclass, $decname];
        }
        return $s;
    }


    /** @return bool */
    function has_topics() {
        return ($this->settings["has_topics"] ?? 0) !== 0;
    }

    /** @return TopicSet */
    function topic_set() {
        if ($this->_topic_set === null) {
            $this->_topic_set = TopicSet::make_main($this);
        }
        return $this->_topic_set;
    }

    function invalidate_topics() {
        $this->_topic_set = null;
        $this->_paper_opts->invalidate_intrinsic_option(PaperOption::TOPICSID);
    }


    /** @return Conflict */
    function conflict_types() {
        if ($this->_conflict_types === null) {
            $this->_conflict_types = new Conflict($this);
        }
        return $this->_conflict_types;
    }


    const MFLAG_OPTION = 1;
    const MFLAG_REVIEW = 2;
    const MFLAG_FORMULA = 4;

    /** @return AbbreviationMatcher<PaperOption|ReviewField|Formula> */
    function abbrev_matcher() {
        if (!$this->_abbrev_matcher) {
            $this->_abbrev_matcher = new AbbreviationMatcher;
            $this->_abbrev_matcher->set_priority(self::MFLAG_FORMULA, -1);
            // XXX exposes invisible paper options, review fields
            $this->_paper_opts->populate_abbrev_matcher($this->_abbrev_matcher);
            $this->review_form()->populate_abbrev_matcher($this->_abbrev_matcher);
            foreach ($this->named_formulas() as $f) {
                if ($f->name) {
                    $this->_abbrev_matcher->add_phrase($f->name, $f, self::MFLAG_FORMULA);
                }
            }
            $this->_paper_opts->assign_search_keywords(false, $this->_abbrev_matcher);
            $this->review_form()->assign_search_keywords($this->_abbrev_matcher);
            foreach ($this->named_formulas() as $f) {
                if ($f->name) {
                    $f->assign_search_keyword($this->_abbrev_matcher);
                }
            }
        }
        return $this->_abbrev_matcher;
    }

    /** @return list<PaperOption|ReviewField|Formula> */
    function find_all_fields($text, $tflags = 0) {
        return $this->abbrev_matcher()->find_all($text, $tflags);
    }


    /** @return null|array|object */
    function review_form_json() {
        $x = $this->settingTexts["review_form"] ?? null;
        if (is_string($x)) {
            $x = json_decode($x);
        }
        return is_array($x) || is_object($x) ? $x : null;
    }

    /** @return ReviewForm */
    function review_form() {
        if ($this->_review_form === null) {
            $this->_review_form = new ReviewForm($this, $this->review_form_json());
        }
        return $this->_review_form;
    }

    /** @return array<string,ReviewField> */
    function all_review_fields() {
        return $this->review_form()->all_fields();
    }
    /** @param string $fid
     * @return ?ReviewField */
    function review_field($fid) {
        return $this->review_form()->field($fid);
    }
    /** @param string $text
     * @return ?ReviewField */
    function find_review_field($text) {
        if ($text !== "" && $text[strlen($text) - 1] === ")") {
            $text = ReviewField::clean_name($text);
        }
        return $this->abbrev_matcher()->find1($text, self::MFLAG_REVIEW);
    }
    /** @param string $fid
     * @return ReviewField */
    function checked_review_field($fid) {
        if (($f = $this->review_form()->field($fid))) {
            return $f;
        } else {
            throw new Exception("Unknown review field ‘{$fid}’");
        }
    }


    /** @return TagMap */
    function tags() {
        if (!$this->_tag_map) {
            $this->_tag_map = TagMap::make($this);
        }
        return $this->_tag_map;
    }


    /** @return bool */
    function has_tracks() {
        return $this->_tracks !== null;
    }

    /** @return list<string> */
    function track_tags() {
        return $this->_track_tags ?? [];
    }

    /** @param string $tag
     * @return ?Track */
    function track($tag) {
        foreach ($this->_tracks ?? [] as $tr) {
            if (strcasecmp($tr->tag, $tag) === 0)
                return $tr;
        }
        return null;
    }

    /** @param int $ttype
     * @return ?string */
    function permissive_track_tag_for(Contact $user, $ttype) {
        foreach ($this->_tracks ?? [] as $tr) {
            if ($user->has_permission($tr->perm[$ttype]) && !$tr->is_default) {
                return $tr->tag;
            }
        }
        return null;
    }

    /** @param int $ttype
     * @return bool */
    function check_tracks(PaperInfo $prow, Contact $user, $ttype) {
        $unmatched = true;
        if ($this->_tracks !== null) {
            foreach ($this->_tracks as $tr) {
                if ($tr->is_default ? $unmatched : $prow->has_tag($tr->ltag)) {
                    $unmatched = false;
                    if ($user->has_permission($tr->perm[$ttype])) {
                        return true;
                    }
                }
            }
        }
        return $unmatched;
    }

    /** @param int $ttype
     * @return bool */
    function check_required_tracks(PaperInfo $prow, Contact $user, $ttype) {
        if ($this->_track_sensitivity & (1 << $ttype)) {
            $unmatched = true;
            foreach ($this->_tracks as $tr) {
                if ($tr->is_default ? $unmatched : $prow->has_tag($tr->ltag)) {
                    $unmatched = false;
                    if ($tr->perm[$ttype] && $user->has_permission($tr->perm[$ttype])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** @return bool */
    function check_admin_tracks(PaperInfo $prow, Contact $user) {
        return $this->check_required_tracks($prow, $user, Track::ADMIN);
    }

    /** @param int $ttype
     * @return bool */
    function check_default_track(Contact $user, $ttype) {
        return !$this->_tracks
            || $user->has_permission($this->_tracks[count($this->_tracks) - 1]->perm[$ttype]);
    }

    /** @param int $ttype
     * @return bool */
    function check_any_tracks(Contact $user, $ttype) {
        if ($this->_tracks) {
            foreach ($this->_tracks as $tr) {
                if (($ttype === Track::VIEW
                     || $user->has_permission($tr->perm[Track::VIEW]))
                    && $user->has_permission($tr->perm[$ttype])) {
                    return true;
                }
            }
        }
        return !$this->_tracks;
    }

    /** @return bool */
    function check_any_admin_tracks(Contact $user) {
        if ($this->_track_sensitivity & Track::BITS_ADMIN) {
            foreach ($this->_tracks as $tr) {
                if ($tr->perm[Track::ADMIN]
                    && $user->has_permission($tr->perm[Track::ADMIN])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param int $ttype
     * @return bool */
    function check_all_tracks(Contact $user, $ttype) {
        if ($this->_tracks) {
            foreach ($this->_tracks as $tr) {
                if (!(($ttype === Track::VIEW
                       || $user->has_permission($tr->perm[Track::VIEW]))
                      && $user->has_permission($tr->perm[$ttype]))) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param int $ttype
     * @return bool */
    function check_track_sensitivity($ttype) {
        return ($this->_track_sensitivity & (1 << $ttype)) !== 0;
    }
    /** @return bool */
    function check_track_view_sensitivity() {
        return ($this->_track_sensitivity & Track::BITS_VIEW) !== 0;
    }
    /** @return bool */
    function check_track_review_sensitivity() {
        return ($this->_track_sensitivity & Track::BITS_REVIEW) !== 0;
    }
    /** @return bool */
    function check_track_admin_sensitivity() {
        return ($this->_track_sensitivity & Track::BITS_ADMIN) !== 0;
    }

    /** @return bool */
    function check_paper_track_sensitivity(PaperInfo $prow, $ttype) {
        if ($this->_track_sensitivity & (1 << $ttype)) {
            $unmatched = true;
            foreach ($this->_tracks as $tr) {
                if ($tr->is_default ? $unmatched : $prow->has_tag($tr->ltag)) {
                    $unmatched = false;
                    if ($tr->perm[$ttype]) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** @param string $tag
     * @param int $ttype
     * @return ?string */
    function track_permission($tag, $ttype) {
        if ($this->_tracks) {
            foreach ($this->_tracks as $tr) {
                if (strcasecmp($tr->tag, $tag) === 0) {
                    return $tr->perm[$ttype];
                }
            }
        }
        return null;
    }

    /** @return int */
    function dangerous_track_mask(Contact $user) {
        $m = 0;
        if ($this->_tracks && $user->contactTags) {
            foreach ($this->_tracks as $tr) {
                foreach ($tr->perm as $i => $perm) {
                    if ($perm
                        && $perm[0] === "-"
                        && !$user->has_permission($perm)) {
                        $m |= 1 << $i;
                    }
                }
            }
        }
        return $m;
    }


    /** @return bool */
    function rights_need_tags() {
        return $this->_track_tags !== null || $this->_has_permtag;
    }

    /** @return bool */
    function has_perm_tags() {
        return $this->_has_permtag;
    }

    /** @param string $tag
     * @return bool */
    function is_known_perm_tag($tag) {
        return preg_match('/\A(?:perm:)?(?:author-read-review|author-write)\z/i', $tag);
    }


    /** @return bool */
    function has_rounds() {
        return count($this->rounds) > 1;
    }

    /** @return list<string> */
    function round_list() {
        return $this->rounds;
    }

    /** @return array<int,string> */
    function defined_rounds() {
        if ($this->_defined_rounds === null) {
            $dl = $r = [];
            foreach ($this->rounds as $i => $rname) {
                if ($i === 0 || $rname !== ";") {
                    if ($i === 0) {
                        $sfx = "";
                    } else {
                        $sfx = "_{$i}";
                        $dl[$i] = 0;
                    }
                    foreach (self::$review_deadlines as $rd) {
                        if (($t = $this->settings["{$rd}{$sfx}"] ?? null) !== null) {
                            $dl[$i] = max($dl[$i] ?? 0, $t);
                        }
                    }
                    if (isset($dl[$i])) {
                        $r[$i] = $i === 0 ? "unnamed" : $rname;
                    }
                }
            }
            uksort($r, function ($a, $b) use ($r, $dl) {
                $adl = $dl[$a] ?? null;
                $bdl = $dl[$b] ?? null;
                if ($adl && $bdl && $adl != $bdl) {
                    return $adl < $bdl ? -1 : 1;
                } else if (!$adl != !$bdl) {
                    return $adl ? -1 : 1;
                } else {
                    return strcasecmp($a ? $r[$a] : "~", $b ? $r[$b] : "~");
                }
            });
            $this->_defined_rounds = $r;
        }
        return $this->_defined_rounds;
    }

    /** @return array<int,string>
     * @deprecated */
    function defined_round_list() {
        return $this->defined_rounds();
    }

    /** @param int $roundno
     * @return string */
    function round_name($roundno) {
        if ($roundno > 0) {
            $rname = $this->rounds[$roundno] ?? null;
            if ($rname === null) {
                error_log($this->dbname . ": round #$roundno undefined");
                while (count($this->rounds) <= $roundno) {
                    $this->rounds[] = null;
                }
                $this->rounds[$roundno] = ";";
            } else if ($rname !== ";") {
                return $rname;
            }
        }
        return "";
    }

    /** @param int $roundno
     * @return string */
    function round_suffix($roundno) {
        if ($roundno > 0
            && ($rname = $this->rounds[$roundno] ?? null)
            && $rname !== ";") {
            return "_$rname";
        }
        return "";
    }

    /** @param string $rname
     * @return string|false */
    static function round_name_error($rname) {
        // Must return HTML-safe plaintext
        // Also see `settings.js`
        if ((string) $rname === "") {
            return "Round name required";
        } else if (!preg_match('/\A[a-zA-Z][-_a-zA-Z0-9]*\z/', $rname)) {
            return "Round names must start with a letter and contain only letters, numbers, and dashes";
        } else if (str_ends_with($rname, "_") || str_ends_with($rname, "-")) {
            return "Round names must not end in a dash";
        } else if (preg_match('/\A(?:none|any|all|default|unnamed|.*(?:draft|response|review)|(?:draft|response).*|pri(?:mary)|sec(?:ondary)|opt(?:ional)|pc|ext(?:ernal)|meta)\z/i', $rname)) {
            return "Round name ‘{$rname}’ is reserved";
        } else {
            return false;
        }
    }

    /** @param ?string $rname
     * @return string|false */
    function sanitize_round_name($rname) {
        if ($rname === null) {
            return (string) ($this->settingTexts["rev_roundtag"] ?? null);
        } else if ($rname === ""
                   || strcasecmp($rname, "(none)") === 0
                   || strcasecmp($rname, "none") === 0
                   || strcasecmp($rname, "unnamed") === 0) {
            return "";
        } else if (self::round_name_error($rname)) {
            return false;
        } else {
            return $rname;
        }
    }

    /** @param bool $external
     * @return string */
    function assignment_round_option($external) {
        $x = $this->settingTexts["rev_roundtag"] ?? "";
        if ($external) {
            $x = $this->settingTexts["extrev_roundtag"] ?? $x;
        }
        return $x === "" ? "unnamed" : $x;
    }

    /** @param bool $external
     * @return int */
    function assignment_round($external) {
        return $this->round_number($this->assignment_round_option($external), false);
    }

    /** @param string $rname
     * @param bool $add
     * @return ?int */
    function round_number($rname, $add) {
        if (!$rname
            || strcasecmp($rname, "none") === 0
            || strcasecmp($rname, "unnamed") === 0) {
            return 0;
        }
        for ($i = 1; $i != count($this->rounds); ++$i) {
            if (!strcasecmp($this->rounds[$i], $rname)) {
                return $i;
            }
        }
        if ($add && !self::round_name_error($rname)) {
            $rtext = $this->setting_data("tag_rounds") ?? "";
            $rtext = $rtext === "" ? $rname : "$rtext $rname";
            $this->save_setting("tag_rounds", 1, $rtext);
            $this->refresh_round_settings();
            return $this->round_number($rname, false);
        } else {
            return null;
        }
    }

    /** @return array<string,string> */
    function round_selector_options($isexternal) {
        $opt = [];
        foreach ($this->defined_rounds() as $rname) {
            $opt[$rname] = $rname;
        }
        if (($isexternal === null || $isexternal === true)
            && ($r = $this->settingTexts["rev_roundtag"] ?? null) !== null
            && !isset($opt[$r ? : "unnamed"])) {
            $opt[$r ? : "unnamed"] = $r ? : "unnamed";
        }
        if (($isexternal === null || $isexternal === false)
            && ($r = $this->settingTexts["extrev_roundtag"] ?? null) !== null
            && !isset($opt[$r ? : "unnamed"])) {
            $opt[$r ? : "unnamed"] = $r ? : "unnamed";
        }
        return $opt;
    }

    /** @param string $name
     * @param ?int $round */
    function round_setting($name, $round) {
        if ($this->_round_settings !== null
            && $round !== null
            && isset($this->_round_settings[$round])
            && isset($this->_round_settings[$round]->$name)) {
            return $this->_round_settings[$round]->$name;
        } else {
            return $this->settings[$name] ?? null;
        }
    }



    /** @return list<ResponseRound> */
    function response_rounds() {
        if ($this->_resp_rounds === null) {
            if ($this->sversion >= 257) {
                $this->_resp_rounds = $this->_new_response_rounds();
            } else {
                $this->_resp_rounds = $this->_old_response_rounds();
            }
        }
        return $this->_resp_rounds;
    }

    /** @return list<ResponseRound> */
    private function _new_response_rounds() {
        $rrds = [];
        $active = ($this->settings["resp_active"] ?? 0) > 0;
        $jresp = json_decode($this->settingTexts["responses"] ?? "[{}]");
        foreach ($jresp ?? [(object) []] as $i => $rrj) {
            $r = new ResponseRound;
            $r->id = $i + 1;
            $r->unnamed = $i === 0 && !isset($rrj->name);
            $r->name = $rrj->name ?? "1";
            $r->active = $active;
            $r->done = $rrj->done ?? 0;
            $r->grace = $rrj->grace ?? 0;
            $r->open = $rrj->open
                ?? ($r->done && $r->done + $r->grace >= self::$now ? 1 : 0);
            $r->words = $rrj->words ?? 500;
            if (isset($rrj->condition)) {
                $r->search = new PaperSearch($this->root_user(), $rrj->condition);
            }
            $r->instructions = $rrj->instructions ?? null;
            $rrds[] = $r;
        }
        return $rrds;
    }

    /** @return list<ResponseRound> */
    private function _old_response_rounds() {
        $rrds = [];
        $x = $this->settingTexts["resp_rounds"] ?? "1";
        $active = ($this->settings["resp_active"] ?? 0) > 0;
        foreach (explode(" ", $x) as $i => $rname) {
            $r = new ResponseRound;
            $r->id = $i + 1;
            $r->unnamed = $rname === "1";
            $r->name = $rname;
            $isuf = $i ? "_{$i}" : "";
            $r->active = $active;
            $r->done = $this->settings["resp_done{$isuf}"] ?? 0;
            $r->grace = $this->settings["resp_grace{$isuf}"] ?? 0;
            $r->open = $this->settings["resp_open{$isuf}"]
                ?? ($r->done && $r->done + $r->grace >= self::$now ? 1 : 0);
            $r->words = $this->settings["resp_words{$isuf}"] ?? 500;
            if (($s = $this->settingTexts["resp_search{$isuf}"] ?? null)) {
                $r->search = new PaperSearch($this->root_user(), $s);
            }
            $r->instructions = $this->settingTexts["msg.resp_instrux_{$i}"] ?? null;
            $rrds[] = $r;
        }
        return $rrds;
    }

    /** @param string $rname
     * @return string|false */
    static function response_round_name_error($rname) {
        return self::round_name_error($rname);
    }

    /** @param string $rname
     * @return ?ResponseRound */
    function response_round($rname) {
        $rrds = $this->response_rounds();
        foreach ($rrds as $rrd) {
            if (strcasecmp($rname, $rrd->name) === 0)
                return $rrd;
        }
        if ($rrds[0]->unnamed
            && ($rname === ""
                || strcasecmp($rname, "unnamed") === 0
                || strcasecmp($rname, "none") === 0)) {
            return $rrds[0];
        }
        return null;
    }

    /** @param int $round
     * @return ?ResponseRound */
    function response_round_by_id($round) {
        $rrds = $this->response_rounds();
        return $rrds[$round - 1] ?? null;
    }


    /** @param ?int $format
     * @return ?TextFormat */
    function format_info($format) {
        if ($this->_format_info === null) {
            $this->_format_info = [];
            if (!isset($this->opt["formatInfo"])) {
                // ok
            } else if (is_array($this->opt["formatInfo"])) {
                $this->_format_info = $this->opt["formatInfo"];
            } else if (is_string($this->opt["formatInfo"])) {
                $this->_format_info = json_decode($this->opt["formatInfo"], true);
            }
            foreach ($this->_format_info as $format => &$fi) {
                $fi = new TextFormat($format, $fi);
            }
        }
        return $this->_format_info[$format ?? $this->default_format] ?? null;
    }

    /** @param ?int $format
     * @param ?string $text
     * @return int */
    function check_format($format, $text = null) {
        $format = $format ?? $this->default_format;
        if ($format
            && $text !== null
            && ($f = $this->format_info($format))
            && $f->simple_regex
            && preg_match($f->simple_regex, $text)) {
            $format = 0;
        }
        return $format;
    }


    /** @return array<string,object> */
    function named_searches() {
        $ss = [];
        foreach ($this->settingTexts as $k => $v) {
            if (substr($k, 0, 3) === "ss:" && ($v = json_decode($v))) {
                $ss[substr($k, 3)] = $v;
            }
        }
        return $ss;
    }

    function replace_named_searches() {
        foreach (array_keys($this->named_searches()) as $k) {
            unset($this->settings[$k], $this->settingTexts[$k]);
        }
        $result = $this->qe("select name, value, data from Settings where name LIKE 'ss:%'");
        while (($row = $result->fetch_row())) {
            $this->settings[$row[0]] = (int) $row[1];
            $this->settingTexts[$row[0]] = $row[2];
        }
        Dbl::free($result);
    }


    /** @return bool */
    function external_login() {
        return ($this->opt["ldapLogin"] ?? false) || ($this->opt["httpAuthLogin"] ?? false);
    }

    /** @return bool */
    function allow_user_self_register() {
        return !$this->external_login()
            && !$this->opt("disableNewUsers")
            && !$this->opt("disableNonPC");
    }


    // root user, site contact

    /** @return Contact */
    function root_user() {
        if (!$this->_root_user) {
            $this->_root_user = Contact::make_site_contact($this, ["email" => "rootuser"]);
            $this->_root_user->set_overrides(Contact::OVERRIDE_CONFLICT);
        }
        return $this->_root_user;
    }

    /** @return Author */
    function default_site_contact() {
        $result = $this->ql("select firstName, lastName, affiliation, email from ContactInfo where roles!=0 and (roles&" . (Contact::ROLE_CHAIR | Contact::ROLE_ADMIN) . ")!=0 order by (roles&" . Contact::ROLE_CHAIR . ") desc, contactId asc limit 1");
        $chair = $result->fetch_object("Author");
        Dbl::free($result);
        return $chair;
    }

    /** @return Contact */
    function site_contact() {
        if (!$this->_site_contact) {
            $args = ["email" => $this->opt("contactEmail") ?? ""];
            if (($args["email"] === "" || $args["email"] === "you@example.com")
                && ($row = $this->default_site_contact())) {
                $args["email"] = $row->email;
                $args["firstName"] = $row->firstName;
                $args["lastName"] = $row->lastName;
            } else if (($name = $this->opt("contactName"))) {
                list($args["firstName"], $args["lastName"], $unused) = Text::split_name($name);
            }
            $this->_site_contact = Contact::make_site_contact($this, $args);
        }
        return $this->_site_contact;
    }


    // database users

    /** @param int $id
     * @return ?Contact */
    function user_by_id($id) {
        $result = $this->qe("select * from ContactInfo where contactId=?", $id);
        $acct = Contact::fetch($result, $this);
        Dbl::free($result);
        return $acct;
    }

    /** @param string $email
     * @return ?Contact */
    function user_by_email($email) {
        $acct = null;
        if (($email = trim((string) $email)) !== "") {
            $result = $this->qe("select * from ContactInfo where email=?", $email);
            $acct = Contact::fetch($result, $this);
            Dbl::free($result);
        }
        return $acct;
    }

    /** @param string $email
     * @return Contact */
    function checked_user_by_email($email) {
        $acct = $this->user_by_email($email);
        if (!$acct) {
            throw new Exception("Contact::checked_user_by_email($email) failed");
        }
        return $acct;
    }


    // user cache

    private function _ensure_user_email_cache() {
        if ($this->_user_email_cache === null) {
            $this->_user_cache = $this->_user_cache ?? $this->_pc_user_cache ?? [];
            $this->_user_email_cache = [];
            foreach ($this->_user_cache as $u) {
                $this->_user_email_cache[strtolower($u->email)] = $u;
            }
        }
    }

    /** @param int $id */
    function prefetch_user_by_id($id) {
        $this->_user_cache_missing[] = $id;
    }

    /** @param iterable<int> $ids */
    function prefetch_users_by_id($ids) {
        foreach ($ids as $id) {
            $this->_user_cache_missing[] = $id;
        }
    }

    /** @param string $email */
    function prefetch_user_by_email($email) {
        $this->_user_cache_missing[] = strtolower($email);
    }

    private function _refresh_user_cache() {
        $this->_user_cache = $this->_user_cache ?? $this->_pc_user_cache ?? [];
        $reqids = $reqemails = [];
        foreach ($this->_user_cache_missing as $reqid) {
            if (is_int($reqid)) {
                if ($reqid > 0
                    && !array_key_exists($reqid, $this->_user_cache)) {
                    $this->_user_cache[$reqid] = null;
                    $reqids[] = $reqid;
                }
            } else if (is_string($reqid)) {
                $this->_ensure_user_email_cache();
                if ($reqid !== ""
                    && !array_key_exists($reqid, $this->_user_email_cache)) {
                    $this->_user_email_cache[$reqid] = null;
                    $reqemails[] = $reqid;
                }
            }
        }
        $this->_user_cache_missing = null;
        if (!empty($reqids) || !empty($reqemails)) {
            $q = "select " . $this->cached_user_query() . " from ContactInfo where ";
            if (empty($reqemails)) {
                $q .= "contactId?a";
                $qv = [$reqids];
            } else if (empty($reqids)) {
                $q .= "email?a";
                $qv = [$reqemails];
            } else {
                $q .= "contactId?a or email?a";
                $qv = [$reqids, $reqemails];
            }
            $result = $this->qe_apply($q, $qv);
            while (($u = Contact::fetch($result, $this))) {
                $this->_user_cache[$u->contactId] = $u;
                if ($this->_user_email_cache !== null) {
                    $this->_user_email_cache[strtolower($u->email)] = $u;
                }
            }
            Dbl::free($result);
        }
    }

    /** @param int $id
     * @return ?Contact */
    function cached_user_by_id($id) {
        $id = (int) $id;
        if ($id === 0) {
            return null;
        } else if (Contact::$main_user !== null
                   && Contact::$main_user->conf === $this
                   && Contact::$main_user->contactId === $id) {
            return Contact::$main_user;
        }
        if (!array_key_exists($id, $this->_user_cache ?? [])) {
            $this->_user_cache_missing[] = $id;
            $this->_refresh_user_cache();
        }
        return $this->_user_cache[$id] ?? null;
    }

    /** @param string $email
     * @return ?Contact */
    function cached_user_by_email($email) {
        if ($email
            && Contact::$main_user !== null
            && Contact::$main_user->conf === $this
            && strcasecmp(Contact::$main_user->email, $email) === 0) {
            return Contact::$main_user;
        }
        $this->_ensure_user_email_cache();
        $lemail = strtolower($email);
        if (!array_key_exists($lemail, $this->_user_email_cache)) {
            $this->_user_cache_missing[] = $lemail;
            $this->_refresh_user_cache();
        }
        return $this->_user_email_cache[$lemail] ?? null;
    }

    /** @return string */
    private function cached_user_query() {
        if ($this->_slice === 3) {
            // see also MailRecipients
            return "contactId, firstName, lastName, affiliation, email, roles, contactTags, disabled, primaryContactId, 3 _slice";
        } else if ($this->_slice === 2) {
            return "contactId, firstName, lastName, affiliation, email, roles, contactTags, disabled, primaryContactId, collaborators, 2 _slice";
        } else {
            return "*";
        }
    }

    function ensure_cached_user_collaborators() {
        if ($this->_slice & 1) {
            $this->_slice &= ~1;
            $this->_user_cache = $this->_user_cache ?? $this->_pc_user_cache;
            if (!empty($this->_user_cache)) {
                $result = $this->qe("select contactId, collaborators from ContactInfo where contactId?a", array_keys($this->_user_cache));
                while (($row = $result->fetch_row())) {
                    $this->_user_cache[intval($row[0])]->set_collaborators($row[1]);
                }
                Dbl::free($result);
            }
        }
    }

    /** @param Contact $u */
    function unslice_user($u) {
        if ($this->_user_cache !== null
            && $u === $this->_user_cache[$u->contactId]
            && $this->_slice) {
            // assume we'll need to unslice all cached users (likely the PC)
            $result = $this->qe("select * from ContactInfo where contactId?a", array_keys($this->_user_cache));
            while (($m = $result->fetch_object())) {
                $this->_user_cache[intval($m->contactId)]->unslice_using($m);
            }
            Dbl::free($result);
            $this->_slice = 0;
        } else if (($m = $this->fetch_first_object("select * from ContactInfo where contactId=?", $u->contactId))) {
            $u->unslice_using($m);
        } else {
            // will rarely happen -- unslicing a user who has been deleted
            $u->_slice = 0;
        }
    }


    // primary emails

    /** @param list<string> $emails
     * @return list<string> */
    function resolve_primary_emails($emails) {
        $cdb = $this->contactdb();

        // load local sliced users
        foreach ($emails as $email) {
            $this->prefetch_user_by_email($email);
        }
        $missing = $redirect = $oemails = [];
        foreach ($emails as $i => $email) {
            $u = $this->cached_user_by_email($email);
            if (!$u && $cdb) {
                $missing[] = $i;
                $this->prefetch_cdb_user_by_email($email);
            } else if ($u && $u->primaryContactId > 0) {
                $redirect[$i] = $u;
            }
            $oemails[] = $u ? $u->email : $email;
        }

        // load cdb users
        foreach ($missing as $i) {
            if (($u = $this->cdb_user_by_email($emails[$i]))) {
                $oemails[$i] = $u->email;
                if ($u->primaryContactId > 0) {
                    $redirect[$i] = $u;
                }
            }
        }

        // resolve indirected users
        for ($round = 0; !empty($redirect) && $round !== 3; ++$round) {
            // redirection chains stop at explicitly disabled users
            $this_redirect = [];
            foreach ($redirect as $i => $u) {
                if (!$u->is_explicitly_disabled()) {
                    if ($u->cdb_confid !== 0) {
                        $this->prefetch_cdb_user_by_id($u->primaryContactId);
                    } else {
                        $this->prefetch_user_by_id($u->primaryContactId);
                    }
                    $this_redirect[$i] = $u;
                }
            }

            $redirect = [];
            foreach ($this_redirect as $i => $u) {
                if ($u->cdb_confid !== 0) {
                    $u2 = $this->cdb_user_by_id($u->primaryContactId);
                } else {
                    $u2 = $this->cached_user_by_id($u->primaryContactId);
                }
                if ($u2) {
                    $oemails[$i] = $u2->email;
                    if ($u2->primaryContactId > 0) {
                        $redirect[$i] = $u2;
                    }
                }
            }
        }

        return $oemails;
    }


    // program committee

    /** @return array<int,Contact> */
    function pc_members() {
        if ($this->_pc_members_cache === null) {
            $result = $this->qe("select " . $this->cached_user_query() . " from ContactInfo where roles!=0 and (roles&" . Contact::ROLE_PCLIKE . ")!=0");
            $this->_pc_user_cache = $by_name_text = [];
            $this->_pc_members_all_enabled = true;
            $expected_by_name_count = 0;
            while (($u = Contact::fetch($result, $this))) {
                $this->_pc_user_cache[$u->contactId] = $u;
                if (($name = $u->name()) !== "") {
                    $by_name_text[$name][] = $u;
                    $expected_by_name_count += 1;
                }
                if ($u->is_disabled()) {
                    $this->_pc_members_all_enabled = false;
                }
            }
            Dbl::free($result);

            if ($expected_by_name_count > count($by_name_text)) {
                foreach ($by_name_text as $us) {
                    if (count($us) > 1) {
                        $npcus = 0;
                        foreach ($us as $u) {
                            $npcus += ($u->roles & Contact::ROLE_PC ? 1 : 0);
                        }
                        foreach ($us as $u) {
                            if ($npcus > 1 || ($u->roles & Contact::ROLE_PC) == 0) {
                                $u->nameAmbiguous = true;
                            }
                        }
                    }
                }
            }

            uasort($this->_pc_user_cache, $this->user_comparator());

            $this->_pc_members_cache = $this->_pc_chairs_cache = [];
            $next_pc_index = 0;
            foreach ($this->_pc_user_cache as $u) {
                if ($u->roles & Contact::ROLE_PC) {
                    $u->pc_index = $next_pc_index;
                    ++$next_pc_index;
                    $this->_pc_members_cache[$u->contactId] = $u;
                }
                if ($u->roles & Contact::ROLE_CHAIR) {
                    $this->_pc_chairs_cache[$u->contactId] = $u;
                }
                if ($this->_user_cache !== null) {
                    $this->_user_cache[$u->contactId] = $u;
                }
                if ($this->_user_email_cache !== null) {
                    $this->_user_email_cache[strtolower($u->email)] = $u;
                }
            }
        }
        return $this->_pc_members_cache;
    }

    /** @return array<int,Contact> */
    function pc_chairs() {
        if ($this->_pc_chairs_cache === null) {
            $this->pc_members();
        }
        return $this->_pc_chairs_cache;
    }

    /** @return bool */
    function has_disabled_pc_members() {
        if ($this->_pc_members_cache === null) {
            $this->pc_members();
        }
        return !$this->_pc_members_all_enabled;
    }

    /** @return array<int,Contact> */
    function enabled_pc_members() {
        if ($this->_pc_members_cache === null) {
            $this->pc_members();
        }
        if ($this->_pc_members_all_enabled) {
            return $this->_pc_members_cache;
        } else {
            $pcm = [];
            foreach ($this->_pc_members_cache as $cid => $u) {
                if (!$u->is_disabled())
                    $pcm[$cid] = $u;
            }
            return $pcm;
        }
    }

    /** @return array<int,Contact>
     * @deprecated */
    function full_pc_members() {
        if ($this->_user_cache && $this->_slice) {
            $u = (array_values($this->_user_cache))[0];
            $this->unslice_user($u);
        }
        $this->_slice = 0;
        return $this->pc_members();
    }

    /** @param int $cid
     * @return ?Contact */
    function pc_member_by_id($cid) {
        return ($this->pc_members())[$cid] ?? null;
    }

    /** @param string $email
     * @return ?Contact */
    function pc_member_by_email($email) {
        if ($this->_pc_members_cache === null) {
            $this->pc_members();
        }
        if ($this->_user_email_cache === null) {
            $this->_ensure_user_email_cache();
        }
        /** @phan-suppress-next-line PhanTypeArraySuspiciousNullable */
        if (($u = $this->_user_email_cache[strtolower($email)] ?? null)
            && ($u->roles & Contact::ROLE_PC) !== 0) {
            return $u;
        } else {
            return null;
        }
    }

    /** @return array<int,Contact> */
    function pc_users() {
        if ($this->_pc_user_cache === null) {
            $this->pc_members();
        }
        return $this->_pc_user_cache;
    }

    /** @param int $cid
     * @return ?Contact */
    function pc_user_by_id($cid) {
        return ($this->pc_users())[$cid] ?? null;
    }

    /** @return array<string,string> */
    private function pc_tagmap() {
        if ($this->_pc_tags_cache === null) {
            $this->_pc_tags_cache = ["pc" => "pc"];
            foreach ($this->pc_users() as $u) {
                if ($u->contactTags !== null) {
                    foreach (explode(" ", $u->contactTags) as $tv) {
                        list($tag, $unused) = Tagger::unpack($tv);
                        if ($tag) {
                            $this->_pc_tags_cache[strtolower($tag)] = $tag;
                        }
                    }
                }
            }
            $this->collator()->asort($this->_pc_tags_cache);
        }
        return $this->_pc_tags_cache;
    }

    /** @return list<string> */
    function pc_tags() {
        return array_values($this->pc_tagmap());
    }

    /** @param string $tag
     * @return bool */
    function pc_tag_exists($tag) {
        return isset(($this->pc_tagmap())[strtolower($tag)]);
    }

    /** @return list<string> */
    function viewable_user_tags(Contact $viewer) {
        if ($viewer->privChair) {
            return $this->pc_tags();
        } else if ($viewer->can_view_user_tags()) {
            $t = " " . join("#0 ", $this->pc_tags()) . "#0";
            $t = $this->tags()->censor(TagMap::CENSOR_VIEW, $t, $viewer, null);
            return explode("#0 ", substr($t, 1, -2));
        } else {
            return [];
        }
    }


    // contactdb

    /** @return ?\mysqli */
    static function main_contactdb() {
        global $Opt;
        if (self::$_cdb === false) {
            self::$_cdb = null;
            $opt = Conf::$main ? Conf::$main->opt : $Opt;
            if (($dsn = $opt["contactdbDsn"] ?? null)
                && ($cp = Dbl::parse_connection_params([
                        "dsn" => $dsn,
                        "dbSocket" => $opt["contactdbSocket"] ?? $opt["dbSocket"] ?? null
                    ]))) {
                self::$_cdb = $cp->connect();
            }
        }
        return self::$_cdb;
    }

    /** @return ?\mysqli */
    function contactdb() {
        return self::$_cdb === false ? self::main_contactdb() : self::$_cdb;
    }

    /** @return int */
    function cdb_confid() {
        $confid = $this->opt["contactdbConfid"] ?? null;
        if ($confid === null && ($cdb = $this->contactdb())) {
            $confid = $this->opt["contactdbConfid"] = Dbl::fetch_ivalue($cdb, "select confid from Conferences where `dbname`=?", $this->dbname) ?? -1;
        }
        return $confid ?? -1;
    }

    private function _refresh_cdb_user_cache() {
        $cdb = $this->contactdb();
        $reqids = $reqemails = [];
        if ($cdb) {
            $this->_cdb_user_cache = $this->_cdb_user_cache ?? [];
            foreach ($this->_cdb_user_cache_missing as $reqid) {
                if (is_int($reqid) && !array_key_exists($reqid, $this->_cdb_user_cache)) {
                    $this->_cdb_user_cache[$reqid] = null;
                    $reqids[] = $reqid;
                } else if (is_string($reqid) && !array_key_exists($reqid, $this->_cdb_user_cache)) {
                    $this->_cdb_user_cache[$reqid] = null;
                    $reqemails[] = $reqid;
                }
            }
        }
        $this->_cdb_user_cache_missing = null;
        if (!empty($reqids) || !empty($reqemails)) {
            $q = "select ContactInfo.*, roles, activity_at";
            if (($confid = $this->opt("contactdbConfid") ?? 0) > 0) {
                $q .= ", ? cdb_confid from ContactInfo left join Roles on (Roles.contactDbId=ContactInfo.contactDbId and Roles.confid=?)";
                $qv = [$confid, $confid];
            } else {
                $q .= ", coalesce(Conferences.confid,-1) cdb_confid from ContactInfo left join Conferences on (Conferences.`dbname`=?) left join Roles on (Roles.contactDbId=ContactInfo.contactDbId and Roles.confid=Conferences.confid)";
                $qv = [$this->dbname];
            }
            if (empty($reqemails)) {
                $q .= " where ContactInfo.contactDbId?a";
                $qv[] = $reqids;
            } else if (empty($reqids)) {
                $q .= " where email?a";
                $qv[] = $reqemails;
            } else {
                $q .= " where ContactInfo.contactDbId?a or email?a";
                $qv[] = $reqids;
                $qv[] = $reqemails;
            }
            $result = Dbl::qe_apply($cdb, $q, $qv);
            while (($u = Contact::fetch($result, $this))) {
                if ($confid <= 0 && $u->cdb_confid > 0) {
                    $confid = $this->opt["contactdbConfid"] = $u->cdb_confid;
                }
                $this->_cdb_user_cache[$u->contactDbId] = $u;
                $this->_cdb_user_cache[strtolower($u->email)] = $u;
            }
            Dbl::free($result);
        }
    }

    /** @param int $id */
    function prefetch_cdb_user_by_id($id) {
        if ($id > 0) {
            $this->_cdb_user_cache_missing[] = $id;
        }
    }

    /** @param string $email */
    function prefetch_cdb_user_by_email($email) {
        if ($email !== "") {
            $this->_cdb_user_cache_missing[] = strtolower($email);
        }
    }

    /** @param iterable<string> $emails */
    function prefetch_cdb_users_by_email($emails) {
        foreach ($emails as $email) {
            if ($email !== "")
                $this->_cdb_user_cache_missing[] = strtolower($email);
        }
    }

    /** @param int $id
     * @return ?Contact */
    function cdb_user_by_id($id) {
        if ($id > 0) {
            if (!array_key_exists($id, $this->_cdb_user_cache ?? [])) {
                $this->_cdb_user_cache_missing[] = $id;
                $this->_refresh_cdb_user_cache();
            }
            return $this->_cdb_user_cache[$id] ?? null;
        } else {
            return null;
        }
    }

    /** @param string $email
     * @return ?Contact */
    function cdb_user_by_email($email) {
        if ($email !== "" && !Contact::is_anonymous_email($email)) {
            $lemail = strtolower($email);
            if (!array_key_exists($lemail, $this->_cdb_user_cache ?? [])) {
                $this->_cdb_user_cache_missing[] = $lemail;
                $this->_refresh_cdb_user_cache();
            }
            return $this->_cdb_user_cache[$lemail] ?? null;
        } else {
            return null;
        }
    }

    /** @param string $email */
    function invalidate_cdb_user_by_email($email) {
        if ($this->_cdb_user_cache !== null) {
            unset($this->_cdb_user_cache[strtolower($email)]);
        }
    }

    /** @param string $email
     * @return ?Contact */
    function fresh_cdb_user_by_email($email) {
        $this->invalidate_cdb_user_by_email($email);
        return $this->cdb_user_by_email($email);
    }

    /** @param string $email
     * @return Contact */
    function checked_cdb_user_by_email($email) {
        $acct = $this->cdb_user_by_email($email);
        if (!$acct) {
            throw new Exception("Contact::checked_cdb_user_by_email($email) failed");
        }
        return $acct;
    }


    // session data

    /** @suppress PhanAccessReadOnlyProperty */
    function disable_session() {
        $this->session_key = null;
    }

    /** @param string $name */
    function session($name) {
        if ($this->session_key !== null) {
            return $_SESSION[$this->session_key][$name] ?? null;
        } else {
            return null;
        }
    }

    /** @param string $name */
    function save_session($name, $value) {
        if ($this->session_key !== null) {
            if ($value !== null) {
                if (empty($_SESSION)) {
                    ensure_session();
                }
                $_SESSION[$this->session_key][$name] = $value;
            } else if (isset($_SESSION[$this->session_key])) {
                unset($_SESSION[$this->session_key][$name]);
                if (empty($_SESSION[$this->session_key])) {
                    unset($_SESSION[$this->session_key]);
                }
            }
        }
    }


    /** @param string $name
     * @param string $existsq
     * @param int $adding */
    private function update_setting_exists($name, $existsq, $adding) {
        if ($adding >= 0) {
            $this->qe_raw("insert into Settings (name, value) select '$name', 1 from dual where $existsq on duplicate key update value=1");
        }
        if ($adding <= 0) {
            $this->qe_raw("delete from Settings where name='$name' and not ($existsq)");
        }
        $this->settings[$name] = (int) $this->fetch_ivalue("select value from Settings where name='$name'");
    }

    // update the 'papersub' setting: are there any submitted papers?
    /** @param int $adding */
    function update_papersub_setting($adding) {
        if (($this->setting("no_papersub") ?? 0) <= 0 ? $adding <= 0 : $adding >= 0) {
            $this->update_setting_exists("no_papersub", "not exists (select * from Paper where timeSubmitted>0)", -$adding);
        }
    }

    /** @param int $adding */
    function update_paperacc_setting($adding) {
        if (($this->setting("paperacc") ?? 0) <= 0 ? $adding >= 0 : $adding <= 0) {
            $this->update_setting_exists("paperacc", "exists (select * from Paper where outcome>0 and timeSubmitted>0)", $adding);
        }
    }

    /** @param int $adding */
    function update_rev_tokens_setting($adding) {
        if (($this->setting("rev_tokens") ?? 0) === -1) {
            $adding = 0;
        }
        if (($this->setting("rev_tokens") ?? 0) <= 0 ? $adding >= 0 : $adding <= 0) {
            $this->update_setting_exists("rev_tokens", "exists (select * from PaperReview where reviewToken!=0)", $adding);
        }
    }

    /** @param int $adding */
    function update_paperlead_setting($adding) {
        if (($this->setting("paperlead") ?? 0) <= 0 ? $adding >= 0 : $adding <= 0) {
            $this->update_setting_exists("paperlead", "exists (select * from Paper where leadContactId>0 or shepherdContactId>0)", $adding);
        }
    }

    /** @param int $adding */
    function update_papermanager_setting($adding) {
        if (($this->setting("papermanager") ?? 0) <= 0 ? $adding >= 0 : $adding <= 0) {
            $this->update_setting_exists("papermanager", "exists (select * from Paper where managerContactId>0)", $adding);
        }
    }

    /** @param int $adding */
    function update_metareviews_setting($adding) {
        if (($this->setting("metareviews") ?? 0) <= 0 ? $adding >= 0 : $adding <= 0) {
            $this->update_setting_exists("metareviews", "exists (select * from PaperReview where reviewType=" . REVIEW_META . ")", $adding);
        }
    }

    /** @param null|int|list<int>|PaperInfo $paper
     * @param null|string|list<string> $types */
    function update_automatic_tags($paper = null, $types = null) {
        if (!$this->_maybe_automatic_tags || $this->_updating_automatic_tags) {
            return;
        }
        $csv = ["paper,tag,tag value"];
        if ($paper === null) {
            foreach ($this->tags()->filter("automatic") as $dt) {
                $csv[] = CsvGenerator::quote("#{$dt->tag}") . "," . CsvGenerator::quote($dt->tag) . ",clear";
                $csv[] = CsvGenerator::quote($dt->automatic_search()) . "," . CsvGenerator::quote($dt->tag) . "," . CsvGenerator::quote($dt->automatic_formula_expression());
            }
        } else if (!empty($paper)) {
            if (is_int($paper)) {
                $pids = [$paper];
            } else if (is_object($paper)) {
                $pids = [$paper->paperId];
            } else {
                $pids = $paper;
            }
            $rowset = $this->paper_set(["paperId" => $pids]);
            foreach ($this->tags()->filter("automatic") as $dt) {
                $search = new PaperSearch($this->root_user(), ["q" => $dt->automatic_search(), "t" => "all"]);
                $fexpr = $dt->automatic_formula_expression();
                foreach ($rowset as $prow) {
                    $test = $search->test($prow);
                    $value = $prow->tag_value($dt->tag);
                    if ($test
                        ? $fexpr !== "0" || $value !== 0.0
                        : $value !== null) {
                        $csv[] = "{$prow->paperId}," . CsvGenerator::quote($dt->tag) . "," . ($test ? CsvGenerator::quote($fexpr) : "clear");
                    }
                }
            }
        }
        $this->_update_automatic_tags_csv($csv);
    }

    function _update_automatic_tags_csv($csv) {
        if (count($csv) > 1) {
            $this->_updating_automatic_tags = true;
            $aset = new AssignmentSet($this->root_user(), true);
            $aset->set_search_type("all");
            $aset->parse($csv);
            $aset->execute();
            $this->_updating_automatic_tags = false;
        }
    }

    /** @return bool */
    function is_updating_automatic_tags() {
        return $this->_updating_automatic_tags;
    }


    /** @param int $n
     * @return bool */
    function update_schema_version($n) {
        if (!$n) {
            $n = $this->fetch_ivalue("select value from Settings where name='allowPaperOption'");
        }
        if ($n && $this->ql_ok("update Settings set value=? where name='allowPaperOption'", $n)) {
            $this->sversion = $this->settings["allowPaperOption"] = $n;
            return true;
        } else {
            return false;
        }
    }

    /** @param array<string,true> $caches */
    function invalidate_caches($caches) {
        if (!self::$no_invalidate_caches) {
            if (is_string($caches)) {
                $caches = [$caches => true];
            }
            if (!$caches || isset($caches["pc"]) || isset($caches["users"])) {
                $this->_pc_members_cache = $this->_pc_tags_cache = $this->_pc_user_cache = $this->_pc_chairs_cache = null;
                $this->_user_cache = $this->_user_email_cache = null;
            }
            if (!$caches || isset($caches["users"]) || isset($caches["cdb"])) {
                $this->_cdb_user_cache = null;
            }
            if (isset($caches["cdb"])) {
                unset($this->opt["contactdbConfid"]);
            }
            // NB All setting-related caches cleared here should also be cleared
            // in refresh_settings().
            if (!$caches || isset($caches["options"])) {
                $this->_paper_opts->invalidate_options();
                $this->_formatspec_cache = [];
                $this->_abbrev_matcher = null;
            }
            if (!$caches || isset($caches["rf"])) {
                $this->_review_form = null;
                $this->_defined_rounds = null;
                $this->_abbrev_matcher = null;
            }
            if (!$caches || isset($caches["tags"]) || isset($caches["tracks"])) {
                $this->_tag_map = null;
            }
            if (!$caches || isset($caches["formulas"])) {
                $this->_formula_functions = null;
            }
            if (!$caches || isset($caches["assigners"])) {
                $this->_assignment_parsers = null;
            }
            if (!$caches || isset($caches["topics"])) {
                $this->invalidate_topics();
            }
            if (!$caches || isset($caches["tracks"])) {
                Contact::update_rights();
            }
            if (isset($caches["autosearch"])) {
                $this->update_automatic_tags();
            }
        }
    }


    // times

    /** @return DateTimeZone */
    function timezone() {
        if ($this->_dtz === null) {
            $this->_dtz = timezone_open($this->opt["timezone"] ?? date_default_timezone_get());
        }
        return $this->_dtz;
    }
    /** @param string $format
     * @param int|float $t
     * @return string */
    private function _date_format($format, $t) {
        if ($this !== self::$main && !$this->_dtz && isset($this->opt["timezone"])) {
            $this->timezone();
        }
        if ($this->_dtz) {
            $dt = new DateTime("@" . (int) $t);
            $dt->setTimeZone($this->_dtz);
            return $dt->format($format);
        } else {
            return date($format, $t);
        }
    }
    /** @param string $type
     * @param int|float $t
     * @return string */
    private function _date_unparse($type, $t) {
        if (!$this->_date_format_initialized) {
            if (!isset($this->opt["time24hour"]) && isset($this->opt["time24Hour"])) {
                $this->opt["time24hour"] = $this->opt["time24Hour"];
            }
            if (!isset($this->opt["dateFormatLong"]) && isset($this->opt["dateFormat"])) {
                $this->opt["dateFormatLong"] = $this->opt["dateFormat"];
            }
            if (!isset($this->opt["dateFormat"])) {
                $this->opt["dateFormat"] = ($this->opt["time24hour"] ?? false) ? "j M Y H:i:s" : "j M Y g:i:sa";
            }
            if (!isset($this->opt["dateFormatLong"])) {
                $this->opt["dateFormatLong"] = "l " . $this->opt["dateFormat"];
            }
            if (!isset($this->opt["dateFormatObscure"])) {
                $this->opt["dateFormatObscure"] = "j M Y";
            }
            if (!isset($this->opt["timestampFormat"])) {
                $this->opt["timestampFormat"] = $this->opt["dateFormat"];
            }
            if (!isset($this->opt["dateFormatSimplifier"])) {
                $this->opt["dateFormatSimplifier"] = ($this->opt["time24hour"] ?? false) ? "/:00(?!:)/" : "/:00(?::00|)(?= ?[ap]m)/";
            }
            $this->_date_format_initialized = true;
        }
        if ($type === "timestamp") {
            $f = $this->opt["timestampFormat"];
        } else if ($type === "obscure") {
            $f = $this->opt["dateFormatObscure"];
        } else if ($type === "long") {
            $f = $this->opt["dateFormatLong"];
        } else if ($type === "zone") {
            $f = "T";
        } else {
            $f = $this->opt["dateFormat"];
        }
        return $this->_date_format($f, $t);
    }
    /** @param int|float $value
     * @return string */
    private function _unparse_timezone($value) {
        $z = $this->opt["dateFormatTimezone"] ?? null;
        if ($z === null) {
            $z = $this->_date_unparse("zone", $value);
            if ($z === "-12") {
                $z = "AoE";
            } else if ($z && ($z[0] === "+" || $z[0] === "-")) {
                $z = "UTC" . $z;
            }
        }
        return $z;
    }

    /** @param int $value
     * @param bool $include_zone
     * @return string */
    function parseableTime($value, $include_zone) {
        $d = $this->_date_unparse("short", $value);
        if ($this->opt["dateFormatSimplifier"]) {
            $d = preg_replace($this->opt["dateFormatSimplifier"], "", $d);
        }
        if ($include_zone && ($z = $this->_unparse_timezone($value))) {
            $d .= " $z";
        }
        return $d;
    }
    /** @param string $d
     * @param ?int $reference
     * @return int|float|false */
    function parse_time($d, $reference = null) {
        $reference = $reference ?? Conf::$now;
        if (!isset($this->opt["dateFormatTimezoneRemover"])) {
            $x = [];
            if (function_exists("timezone_abbreviations_list")) {
                $mytz = date_default_timezone_get();
                foreach (timezone_abbreviations_list() as $tzname => $tzinfo) {
                    foreach ($tzinfo as $tz) {
                        if ($tz["timezone_id"] == $mytz) {
                            $x[] = preg_quote($tzname);
                        }
                    }
                }
            }
            if (empty($x)) {
                $x[] = preg_quote($this->_unparse_timezone($reference));
            }
            $this->opt["dateFormatTimezoneRemover"] =
                "/(?:\\s|\\A)(?:" . join("|", $x) . ")(?:\\s|\\z)/i";
        }
        if ($this->opt["dateFormatTimezoneRemover"]) {
            $d = preg_replace($this->opt["dateFormatTimezoneRemover"], " ", $d);
        }
        if (preg_match('/\A(.*)\b(utc(?=[-+])|aoe(?=\s|\z))(.*)\z/i', $d, $m)) {
            if (strcasecmp($m[2], "aoe") === 0) {
                $d = strtotime($m[1] . "GMT-1200" . $m[3], $reference);
                if ($d !== false
                    && $d % 86400 == 43200
                    && ($dx = strtotime($m[1] . " T23:59:59 GMT-1200" . $m[3], $reference)) === $d + 86399) {
                    return $dx;
                } else {
                    return $d;
                }
            } else {
                return strtotime($m[1] . "GMT" . $m[3], $reference);
            }
        } else {
            return strtotime($d, $reference);
        }
    }

    // NB must return HTML-safe plaintext
    /** @param int $timestamp
     * @return string */
    private function _unparse_time($timestamp, $type) {
        if ($timestamp <= 0) {
            return "N/A";
        }
        $t = $this->_date_unparse($type, $timestamp);
        if ($this->opt["dateFormatSimplifier"]) {
            $t = preg_replace($this->opt["dateFormatSimplifier"], "", $t);
        }
        if ($type !== "obscure" && ($z = $this->_unparse_timezone($timestamp))) {
            $t .= " $z";
        }
        return $t;
    }
    /** @param int|float|null $timestamp
     * @return ?int */
    function obscure_time($timestamp) {
        if ($timestamp !== null) {
            $timestamp = (int) ($timestamp + 0.5);
        }
        if ($timestamp > 0) {
            $offset = 0;
            if (($zone = $this->timezone())) {
                $offset = $zone->getOffset(new DateTime("@$timestamp"));
            }
            $timestamp += 43200 - ($timestamp + $offset) % 86400;
        }
        return $timestamp;
    }
    /** @param int $timestamp
     * @return string */
    function unparse_time_long($timestamp) {
        return $this->_unparse_time($timestamp, "long");
    }
    /** @param int $timestamp
     * @return string */
    function unparse_time($timestamp) {
        return $this->_unparse_time($timestamp, "timestamp");
    }
    /** @param int $timestamp
     * @return string */
    function unparse_time_obscure($timestamp) {
        return $this->_unparse_time($timestamp, "obscure");
    }
    /** @param int $timestamp
     * @return string */
    function unparse_time_point($timestamp) {
        return $this->_date_format("j M Y", $timestamp);
    }
    /** @param int $timestamp
     * @return string */
    function unparse_time_log($timestamp) {
        return $this->_date_format("Y-m-d H:i:s O", $timestamp);
    }
    /** @param int $timestamp
     * @return string */
    function unparse_time_iso($timestamp) {
        return $this->_date_format("Ymd\\THis", $timestamp);
    }
    /** @param int $timestamp
     * @param int $now
     * @return string */
    function unparse_time_relative($timestamp, $now = 0, $format = 0) {
        $d = abs($timestamp - ($now ? : Conf::$now));
        if ($d >= 5227200) {
            if (!($format & 1)) {
                return ($format & 8 ? "on " : "") . $this->_date_unparse("obscure", $timestamp);
            }
            $unit = 5;
        } else if ($d >= 259200) {
            $unit = 4;
        } else if ($d >= 28800) {
            $unit = 3;
        } else if ($d >= 3630) {
            $unit = 2;
        } else if ($d >= 180.5) {
            $unit = 1;
        } else if ($d >= 1) {
            $unit = 0;
        } else {
            return "now";
        }
        $units = [1, 60, 1800, 3600, 86400, 604800];
        $x = $units[$unit];
        $d = ceil(($d - $x / 2) / $x);
        if ($unit === 2) {
            $d /= 2;
        }
        if ($format & 4) {
            $d .= substr("smhhdw", $unit, 1);
        } else {
            $unit_names = ["second", "minute", "hour", "hour", "day", "week"];
            $d .= " " . $unit_names[$unit] . ($d == 1 ? "" : "s");
        }
        if ($format & 2) {
            return $d;
        } else {
            return $timestamp < ($now ? : Conf::$now) ? $d . " ago" : "in " . $d;
        }
    }
    /** @param int $timestamp
     * @return string */
    function unparse_usertime_span($timestamp) {
        return '<span class="usertime hidden need-usertime" data-time="' . $timestamp . '"></span>';
    }

    /** @param string $name
     * @return string */
    function unparse_setting_time($name) {
        $t = $this->settings[$name] ?? 0;
        return $this->unparse_time_long($t);
    }
    /** @param string $name
     * @param string $suffix
     * @return string */
    function unparse_setting_time_span($name, $suffix = "") {
        $t = $this->settings[$name] ?? 0;
        if ($t > 0) {
            return $this->unparse_time_long($t) . $suffix . $this->unparse_usertime_span($t);
        } else {
            return "N/A";
        }
    }
    /** @param string $name
     * @return string */
    function unparse_setting_deadline_span($name) {
        $t = $this->settings[$name] ?? 0;
        if ($t > 0) {
            return "Deadline: " . $this->unparse_time_long($t) . $this->unparse_usertime_span($t);
        } else {
            return "No deadline";
        }
    }

    /** @param string $lo
     * @param ?int $time
     * @return bool */
    function time_after_setting($lo, $time = null) {
        $time = $time ?? Conf::$now;
        $t0 = $this->settings[$lo] ?? null;
        return $t0 !== null && $t0 > 0 && $time >= $t0;
    }

    /** @param string $lo
     * @param string $hi
     * @param ?string $grace
     * @param ?int $time
     * @return 0|1|2 */
    function time_between_settings($lo, $hi, $grace = null, $time = null) {
        // see also ResponseRound::time_allowed
        $time = $time ?? Conf::$now;
        $t0 = $this->settings[$lo] ?? null;
        if (($t0 === null || $t0 <= 0 || $time < $t0) && $lo !== "") {
            return 0;
        }
        $t1 = $this->settings[$hi] ?? null;
        if ($t1 === null || $t1 <= 0 || $time <= $t1) {
            return 1;
        } else if ($grace && $time <= $t1 + ($this->settings[$grace] ?? 0)) {
            return 2;
        } else {
            return 0;
        }
    }

    /** @return bool */
    function time_start_paper() {
        return $this->time_between_settings("sub_open", "sub_reg", "sub_grace") > 0;
    }
    /** @param ?PaperInfo $prow
     * @return bool */
    function time_edit_paper($prow = null) {
        return $this->time_between_settings("sub_open", "sub_update", "sub_grace") > 0
            && (!$prow || $prow->timeSubmitted <= 0 || ($this->_pc_see_cache & 1) === 0);
    }
    /** @param ?PaperInfo $prow
     * @return bool */
    function time_finalize_paper($prow = null) {
        return ($this->_pc_see_cache & 4) !== 0
            && (!$prow || $prow->timeSubmitted <= 0 || ($this->_pc_see_cache & 1) === 0);
    }
    /** @return bool */
    function allow_final_versions() {
        return $this->setting("final_open") > 0;
    }
    /** @return bool */
    function time_edit_final_paper() {
        return $this->time_between_settings("final_open", "final_done", "final_grace") > 0;
    }
    /** @return bool */
    function time_some_author_view_review() {
        return $this->any_response_open || $this->au_seerev > 0;
    }
    /** @return bool */
    function time_all_author_view_decision() {
        return $this->setting("seedec") == self::SEEDEC_ALL;
    }
    /** @return bool */
    function time_some_author_view_decision() {
        return $this->setting("seedec") == self::SEEDEC_ALL;
    }
    /** @return bool */
    function time_review_open() {
        $rev_open = $this->settings["rev_open"] ?? 0;
        return 0 < $rev_open && $rev_open <= Conf::$now;
    }
    /** @param ?int $round
     * @param bool|int $reviewType
     * @param bool $hard
     * @return string */
    function review_deadline_name($round, $reviewType, $hard) {
        $isPC = is_bool($reviewType) ? $reviewType : $reviewType >= REVIEW_PC;
        if ($round === null) {
            $round = $this->assignment_round(!$isPC);
        } else if (is_object($round)) { /* XXX backward compat */
            $round = $round->reviewRound ? : 0;
        }
        return ($isPC ? "pcrev_" : "extrev_") . ($hard ? "hard" : "soft")
            . ($round ? "_$round" : "");
    }
    /** @param ?int $round
     * @param bool|int $reviewType
     * @param bool $hard
     * @return string|false */
    function missed_review_deadline($round, $reviewType, $hard) {
        $rev_open = $this->settings["rev_open"] ?? 0;
        if (!(0 < $rev_open && $rev_open <= Conf::$now)) {
            return "rev_open";
        }
        $dn = $this->review_deadline_name($round, $reviewType, $hard);
        $dv = $this->settings[$dn] ?? 0;
        if ($dv > 0 && $dv < Conf::$now) {
            return $dn;
        }
        return false;
    }
    /** @param ?int $round
     * @param bool|int $reviewType
     * @param bool $hard
     * @return bool */
    function time_review($round, $reviewType, $hard) {
        return !$this->missed_review_deadline($round, $reviewType, $hard);
    }
    /** @return bool */
    function timePCReviewPreferences() {
        return $this->time_pc_view_active_submissions() || $this->has_any_submitted();
    }
    /** @param bool $pdf
     * @return bool */
    function time_pc_view(PaperInfo $prow, $pdf) {
        if ($prow->timeSubmitted > 0) {
            return !$pdf
                || ($this->_pc_see_cache & 18) !== 2
                   // 16 = all submitted PDFs viewable, 2 = some submissions open
                || $prow->timeSubmitted < ($this->settings["sub_open"] ?? 0);
        } else if ($prow->timeWithdrawn <= 0) {
            return !$pdf
                && ($this->_pc_see_cache & 8) !== 0
                && $this->time_finalize_paper($prow);
        } else {
            return false;
        }
    }
    /** @return bool */
    function time_pc_view_decision($conflicted) {
        $s = $this->setting("seedec");
        if ($conflicted) {
            return $s == self::SEEDEC_ALL || $s == self::SEEDEC_REV;
        } else {
            return $s >= self::SEEDEC_REV;
        }
    }
    /** @return bool */
    function time_reviewer_view_decision() {
        return $this->setting("seedec") >= self::SEEDEC_REV;
    }
    /** @return bool */
    function time_reviewer_view_accepted_authors() {
        return $this->setting("seedec") == self::SEEDEC_ALL
            && !$this->setting("seedec_hideau");
    }

    /** @return int */
    function submission_blindness() {
        return $this->settings["sub_blind"];
    }
    /** @return bool */
    function subBlindAlways() {
        return $this->settings["sub_blind"] === self::BLIND_ALWAYS;
    }

    /** @param ?bool $rrow_blind
     * @return bool */
    function is_review_blind($rrow_blind) {
        $rb = $this->settings["rev_blind"];
        if ($rb === self::BLIND_NEVER) {
            return false;
        } else if ($rb !== self::BLIND_OPTIONAL) {
            return true;
        } else {
            return $rrow_blind !== false;
        }
    }
    /** @return int */
    function review_blindness() {
        return $this->settings["rev_blind"];
    }
    /** @return bool */
    function time_some_external_reviewer_view_comment() {
        return $this->settings["extrev_view"] === 2;
    }

    /** @return bool */
    function has_any_submitted() {
        return !($this->settings["no_papersub"] ?? false);
    }
    /** @return bool */
    function has_any_accepted() {
        return !!($this->settings["paperacc"] ?? false);
    }

    /** @return array{int,int} */
    function count_submitted_accepted() {
        $dlt = max($this->setting("sub_sub"), $this->setting("sub_close"));
        $result = $this->qe("select outcome, count(paperId) from Paper where timeSubmitted>0 " . ($dlt ? "or (timeSubmitted=-100 and timeWithdrawn>=$dlt) " : "") . "group by outcome");
        $n = $nyes = 0;
        while (($row = $result->fetch_row())) {
            $n += $row[1];
            if ($row[0] > 0) {
                $nyes += $row[1];
            }
        }
        Dbl::free($result);
        return [$n, $nyes];
    }

    /** @return bool */
    function has_any_lead_or_shepherd() {
        return !!($this->settings["paperlead"] ?? false);
    }

    /** @return bool */
    function has_any_manager() {
        return ($this->_track_sensitivity & Track::BITS_ADMIN)
            || !!($this->settings["papermanager"] ?? false);
    }

    /** @return bool */
    function has_any_explicit_manager() {
        return !!($this->settings["papermanager"] ?? false);
    }

    /** @return bool */
    function has_any_metareviews() {
        return !!($this->settings["metareviews"] ?? false);
    }

    /** @return bool */
    function time_pc_view_active_submissions() {
        return ($this->_pc_see_cache & 8) !== 0;
    }


    function set_siteurl($base) {
        $old_siteurl = Navigation::siteurl();
        $base = Navigation::set_siteurl($base);
        if ($this->opt["assetsUrl"] === $old_siteurl) {
            $this->opt["assetsUrl"] = $base;
            Ht::$img_base = $this->opt["assetsUrl"] . "images/";
        }
        if ($this->opt["scriptAssetsUrl"] === $old_siteurl) {
            $this->opt["scriptAssetsUrl"] = $base;
        }
    }

    const HOTURL_RAW = 1;
    const HOTURL_POST = 2;
    const HOTURL_ABSOLUTE = 4;
    const HOTURL_SITEREL = 8;
    const HOTURL_SITE_RELATIVE = 8;
    const HOTURL_SERVERREL = 16;
    const HOTURL_NO_DEFAULTS = 32;

    /** @param string $page
     * @param null|string|array $params
     * @param int $flags
     * @return string */
    function hoturl($page, $params = null, $flags = 0) {
        $nav = Navigation::get();
        $amp = ($flags & self::HOTURL_RAW ? "&" : "&amp;");
        if (str_starts_with($page, "=")) {
            $page = substr($page, 1);
            $flags |= self::HOTURL_POST;
        }
        $t = $page;
        $are = '/\A(|.*?(?:&|&amp;))';
        $zre = '(?:&(?:amp;)?|\z)(.*)\z/';
        // parse options, separate anchor
        $anchor = "";
        if (is_array($params)) {
            $x = "";
            foreach ($params as $k => $v) {
                if ($v === null || $v === false) {
                    // skip
                } else if ($k === "anchor" /* XXX deprecated */ || $k === "#") {
                    $anchor = "#" . urlencode($v);
                } else {
                    $x .= ($x === "" ? "" : $amp) . $k . "=" . urlencode($v);
                }
            }
            if (Conf::$hoturl_defaults && !($flags & self::HOTURL_NO_DEFAULTS)) {
                foreach (Conf::$hoturl_defaults as $k => $v) {
                    if (!array_key_exists($k, $params)) {
                        $x .= ($x === "" ? "" : $amp) . $k . "=" . $v;
                    }
                }
            }
            $param = $x;
        } else {
            $param = (string) $params;
            if (($pos = strpos($param, "#"))) {
                $anchor = substr($param, $pos);
                $param = substr($param, 0, $pos);
            }
            if (Conf::$hoturl_defaults && !($flags & self::HOTURL_NO_DEFAULTS)) {
                foreach (Conf::$hoturl_defaults as $k => $v) {
                    if (!preg_match($are . preg_quote($k) . '=/', $param)) {
                        $param .= ($param === "" ? "" : $amp) . $k . "=" . $v;
                    }
                }
            }
        }
        if ($flags & self::HOTURL_POST) {
            $param .= ($param === "" ? "" : $amp) . "post=" . post_value();
        }
        // append forceShow to links to same paper if appropriate
        $is_paper_page = preg_match('/\A(?:paper|review|comment|assign)\z/', $page);
        if ($is_paper_page
            && $this->paper
            && Contact::$main_user
            && Contact::$main_user->conf === $this
            && Contact::$main_user->can_administer($this->paper)
            && $this->paper->has_conflict(Contact::$main_user)
            && preg_match("{$are}p={$this->paper->paperId}{$zre}", $param)
            && (is_array($params) ? !array_key_exists("forceShow", $params) : !preg_match($are . 'forceShow=/', $param))) {
            $param .= $amp . "forceShow=1";
        }
        // create slash-based URLs if appropriate
        if ($param) {
            if ($page === "review"
                && preg_match($are . 'p=(\d+)' . $zre, $param, $m)) {
                $tp = "/" . $m[2];
                $param = $m[1] . $m[3];
                if (preg_match($are . 'r=(\d+)([A-Z]+|r\d+|rnew)' . $zre, $param, $mm)
                    && $mm[2] === $m[2]) {
                    $tp .= $mm[3];
                    $param = $mm[1] . $mm[4];
                }
            } else if (($is_paper_page
                        && preg_match($are . 'p=(\d+|%\w+%|new)' . $zre, $param, $m))
                       || ($page === "help"
                           && preg_match($are . 't=(\w+)' . $zre, $param, $m))
                       || (($page === "settings" || $page === "graph")
                           && preg_match($are . 'group=(\w+)' . $zre, $param, $m))) {
                $tp = "/" . $m[2];
                $param = $m[1] . $m[3];
                if ($param !== ""
                    && $page === "paper"
                    && preg_match($are . 'm=(\w+)' . $zre, $param, $m)) {
                    $tp .= "/" . $m[2];
                    $param = $m[1] . $m[3];
                }
            } else if ($page === "doc"
                       && preg_match($are . 'file=([^&]+)' . $zre, $param, $m)) {
                $tp = "/" . str_replace("%2F", "/", $m[2]);
                $param = $m[1] . $m[3];
            } else if ($page === "profile"
                       && preg_match($are . 'u=([^&?]+)' . $zre, $param, $m)) {
                $tp = "/" . str_replace("%2F", "/", $m[2]);
                $param = $m[1] . $m[3];
                if ($param !== ""
                    && preg_match($are . 't=(\w+)' . $zre, $param, $m)) {
                    $tp .= "/" . $m[2];
                    $param = $m[1] . $m[3];
                }
            } else if ($page === "profile"
                       && preg_match($are . 't=(\w+)' . $zre, $param, $m)) {
                $tp = "/" . $m[2];
                $param = $m[1] . $m[3];
            } else if ($page === "api"
                       && preg_match($are . 'fn=(\w+)' . $zre, $param, $m)) {
                $tp = "/" . $m[2];
                $param = $m[1] . $m[3];
                if (preg_match($are . 'p=(\d+)' . $zre, $param, $m)) {
                    $tp = "/" . $m[2] . $tp;
                    $param = $m[1] . $m[3];
                }
            } else if ($page === "users"
                       && preg_match($are . 't=(\w+)' . $zre, $param, $m)) {
                $tp = "/" . $m[2];
                $param = $m[1] . $m[3];
            } else if (preg_match($are . '__PATH__=([^&]+)' . $zre, $param, $m)) {
                $tp = "/" . urldecode($m[2]);
                $param = $m[1] . $m[3];
            } else {
                $tp = "";
            }
            if ($tp !== "") {
                $t .= $tp;
                if (preg_match($are . '__PATH__=([^&]+)' . $zre, $param, $m)
                    && $tp === "/" . urldecode($m[2])) {
                    $param = $m[1] . $m[3];
                }
            }
            $param = preg_replace('/&(?:amp;)?\z/', "", $param);
        }
        if ($nav->php_suffix !== "") {
            if (($slash = strpos($t, "/")) !== false) {
                $a = substr($t, 0, $slash);
                $b = substr($t, $slash);
            } else {
                $a = $t;
                $b = "";
            }
            if (!str_ends_with($a, $nav->php_suffix)) {
                $a .= $nav->php_suffix;
            }
            $t = $a . $b;
        }
        if ($param !== "" && preg_match('/\A&(?:amp;)?(.*)\z/', $param, $m)) {
            $param = $m[1];
        }
        if ($param !== "") {
            $t .= "?" . $param;
        }
        if ($anchor !== "") {
            $t .= $anchor;
        }
        if ($flags & self::HOTURL_SITEREL) {
            return $t;
        } else if ($flags & self::HOTURL_SERVERREL) {
            return $nav->site_path . $t;
        }
        $need_site_path = false;
        if ($page === "index") {
            $expect = "index" . $nav->php_suffix;
            $lexpect = strlen($expect);
            if (substr($t, 0, $lexpect) === $expect
                && ($t === $expect || $t[$lexpect] === "?" || $t[$lexpect] === "#")) {
                $need_site_path = true;
                $t = substr($t, $lexpect);
            }
        }
        if (($flags & self::HOTURL_ABSOLUTE) || $this !== Conf::$main) {
            return $this->opt("paperSite") . "/" . $t;
        } else {
            $siteurl = $nav->site_path_relative;
            if ($need_site_path && $siteurl === "") {
                $siteurl = $nav->site_path;
            }
            return $siteurl . $t;
        }
    }

    /** @param string $page
     * @param null|string|array $param
     * @param int $flags
     * @return string */
    function hoturl_raw($page, $param = null, $flags = 0) {
        return $this->hoturl($page, $param, self::HOTURL_RAW | $flags);
    }

    /** @param string $page
     * @param null|string|array $param
     * @return string */
    function hoturl_post($page, $param = null) {
        return $this->hoturl($page, $param, self::HOTURL_POST);
    }

    /** @param string $html
     * @param string $page
     * @param null|string|array $param
     * @param ?array $js
     * @return string */
    function hotlink($html, $page, $param = null, $js = null) {
        return Ht::link($html, $this->hoturl($page, $param), $js);
    }


    static $selfurl_safe = [
        "p" => true, "paperId" => "p", "pap" => "p",
        "r" => true, "reviewId" => "r",
        "c" => true, "commentId" => "c",
        "m" => true, "mode" => true, "u" => true, "g" => true,
        "q" => true, "t" => true, "qa" => true, "qo" => true, "qx" => true, "qt" => true,
        "fx" => true, "fy" => true,
        "forceShow" => true, "tab" => true, "atab" => true, "sort" => true,
        "group" => true, "monreq" => true, "noedit" => true,
        "contact" => true, "reviewer" => true,
        "editcomment" => true
    ];

    /** @param Qrequest $qreq
     * @param array $param
     * @param int $flags
     * @return string */
    private function qrequrl($qreq, $param, $flags) {
        $x = [];
        foreach ($qreq as $k => $v) {
            $ak = self::$selfurl_safe[$k] ?? false;
            if ($ak === true) {
                $ak = $k;
            }
            if ($ak
                && ($ak === $k || !isset($qreq[$ak]))
                && !array_key_exists($ak, $param)) {
                $x[$ak] = $v;
            }
        }
        foreach ($param as $k => $v) {
            $x[$k] = $v;
        }
        return $this->hoturl($qreq->page(), $x, $flags);
    }

    /** @param Qrequest $qreq
     * @param ?array $param
     * @param int $flags
     * @return string */
    function selfurl(Qrequest $qreq, $param = null, $flags = 0) {
        if (!$qreq->page() || $qreq->page() === "api") {
            error_log("selfurl for bad page: " . debug_string_backtrace());
        }
        if (($p = Navigation::page()) !== null && $p !== $qreq->page()) {
            error_log("selfurl on different page: " . debug_string_backtrace());
        }
        return $this->qrequrl($qreq, $param ?? [], $flags);
    }

    /** @param Qrequest $qreq
     * @param ?array $param
     * @param int $flags
     * @return string */
    function site_referrer_url(Qrequest $qreq, $param = null, $flags = 0) {
        if (($r = $qreq->referrer()) && ($rf = parse_url($r))) {
            $sup = Navigation::siteurl_path();
            $path = $rf["path"] ?? "";
            if ($path !== "" && str_starts_with($path, $sup)) {
                $xqreq = new Qrequest("GET");
                $p = substr($path, strlen($sup));
                if (($slash = strpos($p, "/"))) {
                    $xqreq->set_page(substr($p, $slash), substr($p, $slash + 1));
                } else {
                    $xqreq->set_page($p);
                }
                preg_match_all('/([^=;&]+)=([^;&]+)/', $rf["query"] ?? "", $m, PREG_SET_ORDER);
                foreach ($m as $mx) {
                    $xqreq[urldecode($mx[1])] = urldecode($mx[2]);
                }
                return $this->qrequrl($xqreq, $param ?? [], $flags);
            }
        }
        return $this->selfurl($qreq, $param, $flags);
    }


    /** @return int */
    function report_saved_messages() {
        $max_status = 0;
        foreach ($this->_save_msgs ?? [] as $m) {
            self::msg_on($this, $m[0], $m[1]);
            if (is_int($m[1])) {
                $max_status = max($max_status, $m[1]);
            }
        }
        $this->_save_msgs = null;
        return $max_status;
    }

    function transfer_messages_to_session() {
        if ($this->_save_msgs && $this->session_key !== null) {
            ensure_session();
            foreach ($this->_save_msgs as $m) {
                $_SESSION[$this->session_key]["msgs"][] = $m;
            }
            $this->_save_msgs = null;
        }
    }

    /** @param ?string $url
     * @return never
     * @throws Redirection */
    function redirect($url = null) {
        $nav = Navigation::get();
        if (self::$test_mode) {
            throw new Redirection($nav->make_absolute($url ?? $this->hoturl("index")));
        } else {
            $this->transfer_messages_to_session();
            session_write_close();
            Navigation::redirect_absolute($nav->make_absolute($url ?? $this->hoturl("index")));
        }
    }

    /** @param string $page
     * @param null|string|array $param
     * @return never
     * @throws Redirection */
    function redirect_hoturl($page, $param = null) {
        $this->redirect($this->hoturl($page, $param, self::HOTURL_RAW));
    }

    /** @param Qrequest $qreq
     * @param ?array $param
     * @return never
     * @throws Redirection */
    function redirect_self(Qrequest $qreq, $param = null) {
        $this->redirect($this->selfurl($qreq, $param, self::HOTURL_RAW));
    }

    /** @param string $siteurl
     * @return string */
    function make_absolute_site($siteurl) {
        $nav = Navigation::get();
        if (str_starts_with($siteurl, "u/")) {
            return $nav->make_absolute($siteurl, $nav->base_path);
        } else {
            return $nav->make_absolute($siteurl, $nav->site_path);
        }
    }


    /** @param string $basename
     * @param int $flags
     * @return CsvGenerator */
    function make_csvg($basename, $flags = 0) {
        $csv = new CsvGenerator($flags);
        $csv->set_filename($this->download_prefix . $basename . $csv->extension());
        return $csv;
    }


    //
    // Paper search
    //

    function query_ratings() {
        if ($this->setting("rev_ratings") !== REV_RATINGS_NONE) {
            return "coalesce((select group_concat(contactId, ' ', rating) from ReviewRating where paperId=PaperReview.paperId and reviewId=PaperReview.reviewId),'')";
        } else {
            return "''";
        }
    }

    function query_all_reviewer_preference() {
        return "group_concat(contactId,' ',preference,' ',coalesce(expertise,'.'))";
    }

    /** @param array{paperId?:list<int>|PaperID_SearchTerm,where?:string} $options
     * @return Dbl_Result */
    function paper_result($options, Contact $user = null) {
        // Options:
        //   "paperId" => $pids Only papers in list<int> $pids
        //   "finalized"        Only submitted papers
        //   "unsub"            Only unsubmitted papers
        //   "accepted"         Only accepted papers
        //   "rejected"         Only rejected papers
        //   "active"           Only nonwithdrawn papers
        //   "author"           Only papers authored by $user
        //   "myReviewRequests" Only reviews requested by $user
        //   "myReviews"        All reviews authored by $user
        //   "myOutstandingReviews" All unsubmitted reviews auth by $user
        //   "myConflicts"      Only conflicted papers
        //   "commenterName"    Include commenter names
        //   "tags"             Include paperTags
        //   "minimal"          Only include minimal paper fields
        //   "topics"
        //   "options"
        //   "scores" => list<ReviewField>
        //   "assignments"
        //   "where" => $sql    SQL 'where' clause
        //   "order" => $sql    $sql is SQL 'order by' clause (or empty)
        //   "limit" => $sql    SQL 'limit' clause

        $cxid = $user ? $user->contactXid : -2;
        assert($cxid > 0 || $cxid < -1);
        if (is_int($options)
            || (is_array($options) && !empty($options) && !is_associative_array($options))) {
            error_log("bad \$options to Conf::paper_result"); // XXX
            $options = ["paperId" => $options];
        }

        // paper selection
        $paperset = null;
        '@phan-var null|list<int>|PaperID_SearchTerm $paperset';
        if (isset($options["paperId"])) {
            $paperset = $options["paperId"];
        }
        if (isset($options["reviewId"]) || isset($options["commentId"])) {
            throw new Exception("unexpected reviewId/commentId argument to Conf::paper_result");
        }

        // prepare query: basic tables
        // * Every table in `$joins` can have at most one row per paperId,
        //   except for `PaperReview`.
        $where = [];

        $joins = ["Paper"];

        if ($options["minimal"] ?? false) {
            $cols = ["Paper.paperId, Paper.timeSubmitted, Paper.timeWithdrawn, Paper.outcome, Paper.leadContactId, Paper.managerContactId"];
            if ($this->submission_blindness() === self::BLIND_OPTIONAL) {
                $cols[] = "Paper.blind";
            }
            foreach (["title", "authorInformation", "shepherdContactId"] as $k) {
                if ($options[$k] ?? false)
                    $cols[] = "Paper.{$k}";
            }
        } else {
            $cols = ["Paper.*"];
        }

        if ($user) {
            $aujoinwhere = null;
            if (($options["author"] ?? false)
                && ($aujoinwhere = $user->act_author_view_sql("PaperConflict", true))) {
                $where[] = $aujoinwhere;
            }
            if (($options["author"] ?? false) && !$aujoinwhere) {
                $joins[] = "join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$cxid and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")";
            } else {
                $joins[] = "left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$cxid)";
            }
            $cols[] = "PaperConflict.conflictType";
        } else if ($options["author"] ?? false) {
            $where[] = "false";
        }

        // my review
        $no_paperreview = $paperreview_is_my_reviews = false;
        $reviewjoin = "PaperReview.paperId=Paper.paperId and " . ($user ? $user->act_reviewer_sql("PaperReview") : "false");
        if ($options["myReviews"] ?? false) {
            $joins[] = "join PaperReview on ($reviewjoin)";
            $paperreview_is_my_reviews = true;
        } else if ($options["myOutstandingReviews"] ?? false) {
            $joins[] = "join PaperReview on ($reviewjoin and reviewNeedsSubmit!=0)";
        } else if ($options["myReviewRequests"] ?? false) {
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and requestedBy=$cxid and reviewType=" . REVIEW_EXTERNAL . ")";
        } else {
            $no_paperreview = true;
        }

        // review signatures
        if (($options["reviewSignatures"] ?? false)
            || ($options["scores"] ?? null)
            || ($options["reviewWordCounts"] ?? false)) {
            $cols[] = "coalesce((select " . ReviewInfo::review_signature_sql($this, $options["scores"] ?? null) . " from PaperReview r force index (primary) where r.paperId=Paper.paperId), '') reviewSignatures";
            if ($options["reviewWordCounts"] ?? false) {
                $cols[] = "coalesce((select group_concat(coalesce(reviewWordCount,'.') order by reviewId) from PaperReview force index (primary) where PaperReview.paperId=Paper.paperId), '') reviewWordCountSignature";
            }
        } else if ($user) {
            // need myReviewPermissions
            if ($no_paperreview) {
                $joins[] = "left join PaperReview on ($reviewjoin)";
            }
            if ($no_paperreview || $paperreview_is_my_reviews) {
                $cols[] = "coalesce(" . PaperInfo::my_review_permissions_sql("PaperReview.") . ", '') myReviewPermissions";
            } else {
                $cols[] = "coalesce((select " . PaperInfo::my_review_permissions_sql() . " from PaperReview force index (primary) where $reviewjoin group by paperId), '') myReviewPermissions";
            }
        }

        // fields
        if ($options["topics"] ?? false) {
            $cols[] = "coalesce((select group_concat(topicId) from PaperTopic force index (primary) where PaperTopic.paperId=Paper.paperId), '') topicIds";
        }

        if ($options["options"] ?? false) {
            if ((isset($this->settingTexts["options"]) || isset($this->opt["fixedOptions"]))
                && $this->_paper_opts->has_universal()) {
                $cols[] = "coalesce((select group_concat(PaperOption.optionId, '#', value) from PaperOption force index (primary) where paperId=Paper.paperId), '') optionIds";
            } else {
                $cols[] = "'' as optionIds";
            }
        }

        if (($options["tags"] ?? false)
            || ($user && $user->isPC)
            || $this->rights_need_tags()) {
            $cols[] = "coalesce((select group_concat(' ', tag, '#', tagIndex separator '') from PaperTag force index (primary) where PaperTag.paperId=Paper.paperId), '') paperTags";
        }

        if ($options["reviewerPreference"] ?? false) {
            $joins[] = "left join PaperReviewPreference force index (primary) on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=$cxid)";
            $cols[] = "coalesce(PaperReviewPreference.preference, 0) as myReviewerPreference";
            $cols[] = "PaperReviewPreference.expertise as myReviewerExpertise";
        }

        if ($options["allReviewerPreference"] ?? false) {
            $cols[] = "coalesce((select " . $this->query_all_reviewer_preference() . " from PaperReviewPreference force index (primary) where PaperReviewPreference.paperId=Paper.paperId), '') allReviewerPreference";
        }

        if ($options["allConflictType"] ?? false) {
            // See also SearchQueryInfo::add_allConflictType_column
            $cols[] = "coalesce((select group_concat(contactId, ' ', conflictType) from PaperConflict force index (paperId) where PaperConflict.paperId=Paper.paperId), '') allConflictType";
        }

        if (($options["watch"] ?? false) && $cxid > 0) {
            $joins[] = "left join PaperWatch on (PaperWatch.paperId=Paper.paperId and PaperWatch.contactId=$cxid)";
            $cols[] = "PaperWatch.watch";
        }

        // conditions
        if ($paperset !== null) {
            if (is_array($paperset)) {
                $where[] = "Paper.paperId" . sql_in_int_list($paperset);
            } else{
                $where[] = $paperset->sql_predicate("Paper.paperId");
            }
        }
        if ($options["finalized"] ?? false) {
            $where[] = "timeSubmitted>0";
        }
        if ($options["unsub"] ?? false) {
            $where[] = "timeSubmitted<=0";
        }
        if ($options["active"] ?? false) {
            $where[] = "timeWithdrawn<=0";
        }
        if ($options["accepted"] ?? false) {
            $where[] = "outcome>0";
        }
        if ($options["rejected"] ?? false) {
            $where[] = "outcome<0";
        }
        if ($options["undecided"] ?? false) {
            $where[] = "outcome=0";
        }
        if ($options["decided"] ?? false) {
            $where[] = "outcome!=0";
        }
        if ($options["myLead"] ?? false) {
            $where[] = "leadContactId=$cxid";
        } else if ($options["anyLead"] ?? false) {
            $where[] = "leadContactId!=0";
        }
        if ($options["myShepherd"] ?? false) {
            $where[] = "shepherdContactId=$cxid";
        } else if ($options["anyShepherd"] ?? false) {
            $where[] = "shepherdContactId!=0";
        }
        if ($options["myManaged"] ?? false) {
            $where[] = "managerContactId=$cxid";
        }
        if (($options["myWatching"] ?? false) && $cxid > 0) {
            // return the papers with explicit or implicit WATCH_REVIEW
            // (i.e., author/reviewer/commenter); or explicitly managed
            // papers
            $owhere = [
                "PaperConflict.conflictType>=" . CONFLICT_AUTHOR,
                "PaperReview.reviewType>0",
                "exists (select * from PaperComment where paperId=Paper.paperId and contactId=$cxid)",
                "(PaperWatch.watch&" . Contact::WATCH_REVIEW . ")!=0"
            ];
            if ($this->has_any_lead_or_shepherd()) {
                $owhere[] = "leadContactId=$cxid";
            }
            if ($this->has_any_manager() && $user->is_explicit_manager()) {
                $owhere[] = "managerContactId=$cxid";
            }
            $where[] = "(" . join(" or ", $owhere) . ")";
        }
        if ($options["myConflicts"] ?? false) {
            $where[] = $cxid > 0 ? "PaperConflict.conflictType>" . CONFLICT_MAXUNCONFLICTED : "false";
        }
        if (isset($options["where"]) && $options["where"]) {
            $where[] = $options["where"];
        }

        $pq = "select " . join(",\n    ", $cols)
            . "\nfrom " . join("\n    ", $joins);
        if (!empty($where)) {
            $pq .= "\nwhere " . join("\n    and ", $where);
        }

        $pq .= "\ngroup by Paper.paperId\n";
        // This `having` is probably faster than a `where exists` if most papers
        // have at least one tag.
        if (($options["tags"] ?? false) === "require") {
            $pq .= "having paperTags!=''\n";
        }
        $pq .= ($options["order"] ?? "order by Paper.paperId") . "\n"
            . ($options["limit"] ?? "");

        //Conf::msg_debugt($pq);
        return $this->qe_raw($pq);
    }

    /** @param array{paperId?:list<int>|PaperID_SearchTerm} $options
     * @return PaperInfoSet|Iterable<PaperInfo> */
    function paper_set($options, Contact $user = null) {
        $result = $this->paper_result($options, $user);
        return PaperInfoSet::make_result($result, $user, $this);
    }

    /** @param int $pid
     * @return ?PaperInfo */
    function paper_by_id($pid, Contact $user = null, $options = []) {
        $options["paperId"] = [$pid];
        $result = $this->paper_result($options, $user);
        $prow = PaperInfo::fetch($result, $user, $this);
        Dbl::free($result);
        return $prow;
    }

    /** @param int $pid
     * @return PaperInfo */
    function checked_paper_by_id($pid, Contact $user = null, $options = []) {
        $prow = $this->paper_by_id($pid, $user, $options);
        if (!$prow) {
            throw new Exception("Conf::checked_paper_by_id($pid) failed");
        }
        return $prow;
    }

    /** @return ?PaperInfo */
    function set_paper_request(Qrequest $qreq, Contact $user) {
        $this->paper = $prow = null;
        if ($qreq->p) {
            if (ctype_digit($qreq->p)) {
                $prow = $this->paper_by_id(intval($qreq->p), $user);
            }
            if (($whynot = $user->perm_view_paper($prow, false, $qreq->p))) {
                $qreq->set_annex("paper_whynot", $whynot);
                $prow = null;
            }
        }
        return ($this->paper = $prow);
    }


    function preference_conflict_result($type, $extra) {
        $q = "select PRP.paperId, PRP.contactId, PRP.preference
                from PaperReviewPreference PRP
                join ContactInfo c on (c.contactId=PRP.contactId and c.roles!=0 and (c.roles&" . Contact::ROLE_PC . ")!=0)
                join Paper P on (P.paperId=PRP.paperId)
                left join PaperConflict PC on (PC.paperId=PRP.paperId and PC.contactId=PRP.contactId)
                where PRP.preference<=-100 and coalesce(PC.conflictType,0)<=" . CONFLICT_MAXUNCONFLICTED . "
                  and P.timeWithdrawn<=0";
        if ($type !== "all" && $type !== "act") {
            $q .= " and P.timeSubmitted>0";
        }
        if ($extra) {
            $q .= " " . $extra;
        }
        return $this->ql_raw($q);
    }


    //
    // Message routines
    //

    /** @param string $text
     * @param int $type */
    static function msg_on(Conf $conf = null, $text, $type) {
        if (PHP_SAPI === "cli") {
            if (is_array($text)) {
                $text = join("\n", $text);
            }
            if ($type === "xmerror" || $type === "merror" || $type === 2) {
                fwrite(STDERR, "$text\n");
            } else if ($type === "xwarning" || $type === "warning" || $type === 1
                       || !defined("HOTCRP_TESTHARNESS")) {
                fwrite(STDOUT, "$text\n");
            }
        } else if ($conf && !$conf->_header_printed) {
            $conf->_save_msgs[] = [$text, $type];
        } else if (is_int($type) || $type[0] === "x") {
            echo Ht::msg($text, $type);
        } else {
            if (is_array($text)) {
                $text = '<div class="multimessage">' . join("", array_map(function ($x) { return '<div class="mmm">' . $x . '</div>'; }, $text)) . '</div>';
            }
            echo "<div class=\"$type\">$text</div>";
        }
    }

    /** @param string $text
     * @param int $type */
    function msg($text, $type) {
        self::msg_on($this, $text, $type);
    }

    /** @param MessageItem|iterable<MessageItem>|MessageSet ...$mls */
    function feedback_msg(...$mls) {
        $ms = Ht::feedback_msg_content(...$mls);
        $ms[0] === "" || self::msg_on($this, $ms[0], $ms[1]);
    }

    /** @param string $msg */
    function error_msg($msg) {
        $this->feedback_msg(MessageItem::error($msg));
    }

    /** @param string $msg */
    function warning_msg($msg) {
        $this->feedback_msg(MessageItem::warning($msg));
    }

    /** @param string $msg */
    function success_msg($msg) {
        $this->feedback_msg(MessageItem::success($msg));
    }

    /** @param mixed $text */
    static function msg_debugt($text) {
        if (is_object($text) || is_array($text) || $text === null || $text === false || $text === true) {
            $text = json_encode_browser($text);
        }
        self::msg_on(self::$main, Ht::pre_text_wrap($text), 2);
        return false;
    }

    function post_missing_msg() {
        $this->feedback_msg(
            MessageItem::error("<0>Uploaded data was not received"),
            MessageItem::inform("<0>This can happen on unusually slow connections, or if you tried to upload a file larger than I can accept.")
        );
    }


    //
    // Conference header, footer
    //

    /** @return bool */
    function has_active_list() {
        return !!$this->_active_list;
    }

    /** @return ?SessionList */
    function active_list() {
        if ($this->_active_list === false) {
            $this->_active_list = null;
        }
        return $this->_active_list;
    }

    function set_active_list(SessionList $list = null) {
        assert($this->_active_list === false);
        $this->_active_list = $list;
    }

    /** @param array $x
     * @return string */
    function make_css_link($x) {
        $x["rel"] = $x["rel"] ?? "stylesheet";
        $url = $x["href"];
        if ($url[0] !== "/"
            && (($url[0] !== "h" && $url[0] !== "H")
                || (strtolower(substr($url, 0, 5)) !== "http:"
                    && strtolower(substr($url, 0, 6)) !== "https:"))) {
            if (($mtime = @filemtime(SiteLoader::find($url))) !== false) {
                $url .= "?mtime=$mtime";
            }
            $x["href"] = $this->opt["assetsUrl"] . $url;
        }
        return "<link" . Ht::extra($x) . ">";
    }

    /** @param non-empty-string $url
     * @return string */
    function make_script_file($url, $no_strict = false, $integrity = null) {
        if (str_starts_with($url, "scripts/")) {
            $post = "";
            if (($mtime = @filemtime(SiteLoader::find($url))) !== false) {
                $post = "mtime=$mtime";
            }
            if (($this->opt["strictJavascript"] ?? false) && !$no_strict) {
                $url = $this->opt["scriptAssetsUrl"] . "cacheable.php/"
                    . str_replace("%2F", "/", urlencode($url))
                    . "?strictjs=1" . ($post ? "&$post" : "");
            } else {
                $url = $this->opt["scriptAssetsUrl"] . $url . ($post ? "?$post" : "");
            }
            if ($this->opt["scriptAssetsUrl"] === Navigation::siteurl()) {
                return Ht::script_file($url);
            }
        }
        return Ht::script_file($url, ["crossorigin" => "anonymous", "integrity" => $integrity]);
    }

    private function make_jquery_script_file($jqueryVersion) {
        $integrity = null;
        if ($this->opt["jqueryCdn"] ?? $this->opt["jqueryCDN"] ?? false) {
            if ($jqueryVersion === "3.6.0") {
                $integrity = "sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=";
            } else if ($jqueryVersion === "3.5.1") {
                $integrity = "sha384-ZvpUoO/+PpLXR1lu4jmpXWu80pZlYUAfxl5NsBMWOEPSjUn/6Z/hRTt8+pR6L4N2";
            } else if ($jqueryVersion === "3.4.1") {
                $integrity = "sha384-vk5WoKIaW/vJyUAd9n/wmopsmNhiy+L2Z+SBxGYnUkunIxVxAv/UtMOhba/xskxh";
            } else if ($jqueryVersion === "3.3.1") {
                $integrity = "sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=";
            } else if ($jqueryVersion === "1.12.4") {
                $integrity = "sha256-ZosEbRLbNQzLpnKIkEdrPv7lOy9C27hHQ+Xp8a4MxAQ=";
            }
            $jquery = "//code.jquery.com/jquery-{$jqueryVersion}.min.js";
        } else {
            $jquery = "scripts/jquery-{$jqueryVersion}.min.js";
        }
        '@phan-var non-empty-string $jquery';
        return $this->make_script_file($jquery, true, $integrity);
    }

    function prepare_security_headers() {
        if (($csp = $this->opt("contentSecurityPolicy"))) {
            if (is_string($csp)) {
                $csp = [$csp];
            } else if ($csp === true) {
                $csp = [];
            }
            $report_only = false;
            if (($pos = array_search("'report-only'", $csp)) !== false) {
                $report_only = true;
                array_splice($csp, $pos, 1);
            }
            if (empty($csp)) {
                array_push($csp, "script-src", "'nonce'");
            }
            if (($pos = array_search("'nonce'", $csp)) !== false) {
                $nonceval = base64_encode(random_bytes(16));
                $csp[$pos] = "'nonce-$nonceval'";
                Ht::set_script_nonce($nonceval);
            }
            header("Content-Security-Policy"
                   . ($report_only ? "-Report-Only: " : ": ")
                   . join(" ", $csp));
        }
        if ($this->opt("crossOriginIsolation") !== false) {
            header("Cross-Origin-Opener-Policy: same-origin");
        }
        if (($sts = $this->opt("strictTransportSecurity"))) {
            header("Strict-Transport-Security: $sts");
        }
    }

    /** @param string $name
     * @param string $value
     * @param int $expires_at */
    function set_cookie($name, $value, $expires_at) {
        $secure = $this->opt("sessionSecure") ?? false;
        $samesite = $this->opt("sessionSameSite") ?? "Lax";
        $opt = [
            "expires" => $expires_at,
            "path" => Navigation::base_path(),
            "domain" => $this->opt("sessionDomain") ?? "",
            "secure" => $secure
        ];
        if ($samesite && ($secure || $samesite !== "None")) {
            $opt["samesite"] = $samesite;
        }
        if (!hotcrp_setcookie($name, $value, $opt)) {
            error_log(debug_string_backtrace());
        }
    }

    function header_head($title, $extra = []) {
        // clear session list cookies
        foreach ($_COOKIE as $k => $v) {
            if (str_starts_with($k, "hotlist-info")
                || str_starts_with($k, "hc-uredirect-"))
                $this->set_cookie($k, "", Conf::$now - 86400);
        }

        echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n",
            "<meta http-equiv=\"content-type\" content=\"text/html; charset=utf-8\">\n";

        // gather stylesheets
        $cssx = [];
        $has_default_css = $has_media = false;
        foreach ($this->opt("stylesheets") ?? [] as $css) {
            if (is_string($css)) {
                $css = ["href" => $css];
            }
            $cssx[] = $this->make_css_link($css);
            $has_default_css = $has_default_css || $css["href"] === "stylesheets/style.css";
            $has_media = $has_media || ($css["media"] ?? null) !== null;
        }

        // meta elements
        $meta = $this->opt("metaTags") ?? [];
        if ($has_media) {
            $meta["viewport"] = $meta["viewport"] ?? "width=device-width, initial-scale=1";
        }
        foreach ($meta as $key => $value) {
            if ($value === false) {
                // nothing
            } else if (is_int($key)) {
                assert(str_starts_with($value, "<meta"));
                echo $value, "\n";
            } else if ($key === "default-style" || $key === "content-security-policy") {
                echo "<meta http-equiv=\"", $key, "\" content=\"", htmlspecialchars($value), "\">\n";
            } else {
                echo "<meta name=\"", htmlspecialchars($key), "\" content=\"", htmlspecialchars($value), "\">\n";
            }
        }

        // css references
        if (!$has_default_css) {
            echo $this->make_css_link(["href" => "stylesheets/style.css"]), "\n";
        }
        foreach ($cssx as $css) {
            echo $css, "\n";
        }

        // favicon
        $favicon = $this->opt("favicon") ?? "images/review48.png";
        if ($favicon) {
            if (strpos($favicon, "://") === false && $favicon[0] != "/") {
                if ($this->opt["assetsUrl"] && substr($favicon, 0, 7) === "images/") {
                    $favicon = $this->opt["assetsUrl"] . $favicon;
                } else {
                    $favicon = Navigation::siteurl() . $favicon;
                }
            }
            if (substr($favicon, -4) == ".png") {
                echo "<link rel=\"icon\" type=\"image/png\" href=\"$favicon\">\n";
            } else if (substr($favicon, -4) == ".ico") {
                echo "<link rel=\"shortcut icon\" href=\"$favicon\">\n";
            } else if (substr($favicon, -4) == ".gif") {
                echo "<link rel=\"icon\" type=\"image/gif\" href=\"$favicon\">\n";
            } else {
                echo "<link rel=\"icon\" href=\"$favicon\">\n";
            }
        }

        // title
        echo "<title>";
        if ($title) {
            if (is_array($title)) {
                if (count($title) === 3 && $title[2]) {
                    $title = $title[1] . " - " . $title[0];
                } else {
                    $title = $title[0];
                }
            }
            $title = preg_replace("/<([^>\"']|'[^']*'|\"[^\"]*\")*>/", "", $title);
        }
        if ($title && $title !== "Home" && $title !== "Sign in") {
            echo $title, " - ";
        }
        echo htmlspecialchars($this->short_name), "</title>\n</head>\n";

        // jQuery
        $stash = Ht::unstash();
        if (($jqurl = $this->opt["jqueryUrl"] ?? $this->opt["jqueryURL"] ?? null)) {
            Ht::stash_html($this->make_script_file($jqurl, true) . "\n");
        } else {
            $jqueryVersion = $this->opt["jqueryVersion"] ?? "3.6.0";
            if ($jqueryVersion[0] === "3") {
                Ht::stash_html("<!--[if lt IE 9]>" . $this->make_jquery_script_file("1.12.4") . "<![endif]-->\n");
                Ht::stash_html("<![if !IE|gte IE 9]>" . $this->make_jquery_script_file($jqueryVersion) . "<![endif]>\n");
            } else {
                Ht::stash_html($this->make_jquery_script_file($jqueryVersion) . "\n");
            }
        }
        if ($this->opt("jqueryMigrate")) {
            Ht::stash_html($this->make_script_file("//code.jquery.com/jquery-migrate-3.0.0.js", true));
        }

        // Javascript settings to set before script.js
        $nav = Navigation::get();
        $siteinfo = [
            "site_relative" => $nav->site_path_relative,
            "base" => $nav->base_path,
            "suffix" => $nav->php_suffix,
            "assets" => $this->opt["assetsUrl"],
            "cookie_params" => "",
            "postvalue" => post_value(true),
            "user" => []
        ];
        if (($x = $this->opt("sessionDomain"))) {
            $siteinfo["cookie_params"] .= "; Domain=$x";
        }
        if ($this->opt("sessionSecure")) {
            $siteinfo["cookie_params"] .= "; Secure";
        }
        if (($samesite = $this->opt("sessionSameSite") ?? "Lax")) {
            $siteinfo["cookie_params"] .= "; SameSite={$samesite}";
        }
        if (self::$hoturl_defaults) {
            $siteinfo["defaults"] = [];
            foreach (self::$hoturl_defaults as $k => $v) {
                $siteinfo["defaults"][$k] = urldecode($v);
            }
        }
        if (($user = Contact::$main_user)) {
            if ($user->email) {
                $siteinfo["user"]["email"] = $user->email;
            }
            if ($user->is_pclike()) {
                $siteinfo["user"]["is_pclike"] = true;
            }
            if ($user->has_account_here()) {
                $siteinfo["user"]["cid"] = $user->contactId;
            }
            if ($user->is_actas_user()) {
                $siteinfo["user"]["is_actas"] = true;
            } else if (count(Contact::session_users()) > 1) {
                $siteinfo["user"]["session_users"] = Contact::session_users();
            }
        }

        $pid = $extra["paperId"] ?? 0;
        if (!is_int($pid)) {
            $pid = $pid && ctype_digit($pid) ? intval($pid) : 0;
        }
        if ($pid > 0 && $this->paper) {
            $pid = $this->paper->paperId;
        }
        if ($pid > 0) {
            $siteinfo["paperid"] = $pid;
        }
        if ($pid > 0 && $user && $user->is_admin_force()) {
            $siteinfo["want_override_conflict"] = true;
        }

        Ht::stash_script("window.siteinfo=" . json_encode_browser($siteinfo));

        // script.js
        if (!$this->opt("noDefaultScript")) {
            Ht::stash_html($this->make_script_file("scripts/script.js") . "\n");
        }

        // other scripts
        foreach ($this->opt("scripts") ?? [] as $file) {
            Ht::stash_html($this->make_script_file($file) . "\n");
        }

        if ($stash) {
            Ht::stash_html($stash);
        }
    }

    /** @return bool */
    function has_interesting_deadline($my_deadlines) {
        if ($my_deadlines->sub->open ?? false) {
            if (Conf::$now <= ($my_deadlines->sub->reg ?? 0)
                || Conf::$now <= ($my_deadlines->sub->update ?? 0)
                || Conf::$now <= ($my_deadlines->sub->sub ?? 0)
                || ($my_deadlines->sub->reg_ingrace ?? false)
                || ($my_deadlines->sub->update_ingrace ?? false)
                || ($my_deadlines->sub->sub_ingrace ?? false)) {
                return true;
            }
        }
        if (($my_deadlines->is_author ?? false)
            && ($my_deadlines->resps ?? false)) {
            foreach ($my_deadlines->resps as $r) {
                if ($r->open && (Conf::$now <= $r->done || ($r->ingrace ?? false)))
                    return true;
            }
        }
        return false;
    }

    function header_body($title, $id, $extra = []) {
        $user = Contact::$main_user;
        $qreq = Qrequest::$main_request;
        echo "<body";
        if ($id) {
            echo ' id="body-', $id, '"';
        }
        $class = $extra["body_class"] ?? null;
        if (($list = $this->active_list())) {
            $class = ($class ? $class . " " : "") . "has-hotlist";
        }
        if ($class) {
            echo ' class="', $class, '"';
        }
        if ($list) {
            echo ' data-hotlist="', htmlspecialchars($list->info_string()), '"';
        }
        echo ' data-upload-limit="', ini_get_bytes("upload_max_filesize");
        if (($s = $this->opt("uploadMaxFilesize"))) {
            echo '" data-document-max-size="', (int) $s;
        }
        echo '"><div id="top">';

        // site header
        if ($id === "home") {
            $site_div = '<div id="header-site" class="header-site-home">'
                . '<h1><a class="q" href="' . $this->hoturl("index", ["cap" => null])
                . '">' . htmlspecialchars($this->short_name) . '</a></h1></div>';
        } else {
            $site_div = '<div id="header-site" class="header-site-page">'
                . '<a class="q" href="' . $this->hoturl("index", ["cap" => null])
                . '"><span class="header-site-name">' . htmlspecialchars($this->short_name)
                . '</span> Home</a></div>';
        }

        // initial load (JS's timezone offsets are negative of PHP's)
        Ht::stash_script("hotcrp.onload.time(" . (-(int) date("Z", Conf::$now) / 60) . "," . ($this->opt("time24hour") ? 1 : 0) . ")");

        // deadlines settings
        $my_deadlines = null;
        if ($user) {
            $my_deadlines = $user->my_deadlines($this->paper ? [$this->paper] : []);
            Ht::stash_script("hotcrp.init_deadlines(" . json_encode_browser($my_deadlines) . ")");
        }

        // $header_profile
        $profile_html = "";
        if ($user && !$user->is_empty()) {
            // profile link
            $profile_parts = [];
            if ($user->has_email() && !$user->is_disabled()) {
                if (!$user->is_anonymous_user()) {
                    $purl = $this->hoturl("profile");
                    $link = "<a class=\"q\" href=\"{$purl}\"><strong>" . htmlspecialchars($user->email) . "</strong></a>";
                    if ($user->is_actas_user()) {
                        $link = "<span class=\"header-actas\"><span class=\"warning-mark\"></span> Acting as {$link}</span>";
                    }
                    $profile_parts[] = "{$link} &nbsp; <a href=\"{$purl}\">Profile</a>";
                } else {
                    $profile_parts[] = "<strong>" . htmlspecialchars($user->email) . "</strong>";
                }
            }

            // "act as" link
            if (Contact::$base_auth_user) {
                $link = $this->selfurl($qreq, ["actas" => null]);
                $profile_parts[] = "<a href=\"{$link}\">Admin&nbsp;"
                    . Ht::img('viewas.png', 'Act as ' . htmlspecialchars(Contact::$base_auth_user->email)) . '</a>';
            } else if ($this->session_key !== null
                       && ($actas = $_SESSION["last_actas"] ?? null)
                       && $user->privChair
                       && strcasecmp($user->email, $actas) !== 0) {
                $actas_html = htmlspecialchars($actas);
                $link = $this->selfurl($qreq, ["actas" => $actas]);
                $profile_parts[] = "<a href=\"{$link}\">{$actas_html}&nbsp;"
                    . Ht::img('viewas.png', "Act as {$actas_html}") . '</a>';
            }

            // help
            if (!$user->is_disabled()) {
                $helpargs = ($id == "search" ? "t=$id" : ($id == "settings" ? "t=chair" : ""));
                $profile_parts[] = '<a href="' . $this->hoturl("help", $helpargs) . '">Help</a>';
            }

            // sign in and out
            if (!$user->is_signed_in()
                && !($this->opt["httpAuthLogin"] ?? false)
                && $id !== "signin") {
                $profile_parts[] = '<a href="' . $this->hoturl("signin", ["cap" => null]) . '" class="nw">Sign in</a>';
            }
            if ((!$user->is_empty() || ($this->opt["httpAuthLogin"] ?? false))
                && $id !== "signout") {
                $profile_parts[] = Ht::form($this->hoturl("=signout", ["cap" => null]), ["class" => "d-inline"])
                    . Ht::button("Sign out", ["type" => "submit", "class" => "btn btn-link"])
                    . "</form>";
            }

            if (!empty($profile_parts))
                $profile_html .= join(' <span class="barsep">·</span> ', $profile_parts);
        }

        $action_bar = $extra["action_bar"] ?? null;
        if ($action_bar === null) {
            $action_bar = actionBar();
        }

        $title_div = $extra["title_div"] ?? null;
        if ($title_div === null) {
            if (($subtitle = $extra["subtitle"] ?? null)) {
                $title .= " &nbsp;&#x2215;&nbsp; <strong>" . $subtitle . "</strong>";
            }
            if ($title && $title !== "Home") {
                $title_div = '<div id="header-page"><h1>' . $title . '</h1></div>';
            } else if ($action_bar) {
                $title_div = '<hr class="c">';
            }
        }

        echo $site_div, '<div id="header-right">', $profile_html;
        if ($my_deadlines && $this->has_interesting_deadline($my_deadlines)) {
            echo '<div id="header-deadline">&nbsp;</div>';
        } else {
            echo '<div id="header-deadline" class="hidden"></div>';
        }
        echo '</div>', ($title_div ? : ""), ($action_bar ? : "");

        echo "  <hr class=\"c\">\n";

        $this->_header_printed = true;
        echo "<div id=\"msgs-initial\">\n";
        if (($x = $this->opt("maintenance"))) {
            echo Ht::msg(is_string($x) ? $x : "<strong>The site is down for maintenance.</strong> Please check back later.", 2);
        }
        if ($user && ($msgs = $user->session("msgs"))) {
            $user->save_session("msgs", null);
            $this->_save_msgs = array_merge($msgs, $this->_save_msgs ?? []);
        }
        if ($this->_save_msgs && !($extra["save_messages"] ?? false)) {
            $this->report_saved_messages();
        }
        if ($qreq && $qreq->has_annex("upload_errors")) {
            $this->feedback_msg($qreq->annex("upload_errors"));
        }
        echo "</div></div>\n";

        echo "<div id=\"body\" class=\"body\">\n";

        // If browser owns tracker, send it the script immediately
        if ($this->setting("tracker")
            && MeetingTracker::session_owns_tracker($this)) {
            echo Ht::unstash();
        }

        // Callback for version warnings
        if ($user
            && $user->privChair
            && $this->session_key !== null
            && (!isset($_SESSION["updatecheck"])
                || $_SESSION["updatecheck"] + 3600 <= Conf::$now)
            && (!isset($this->opt["updatesSite"]) || $this->opt["updatesSite"])) {
            $m = isset($this->opt["updatesSite"]) ? $this->opt["updatesSite"] : "//hotcrp.lcdf.org/updates";
            $m .= (strpos($m, "?") === false ? "?" : "&")
                . "addr=" . urlencode($_SERVER["SERVER_ADDR"])
                . "&base=" . urlencode(Navigation::siteurl())
                . "&version=" . HOTCRP_VERSION;
            $v = HOTCRP_VERSION;
            if (is_dir(SiteLoader::find(".git"))) {
                $args = [];
                exec("export GIT_DIR=" . escapeshellarg(SiteLoader::$root) . "/.git; git rev-parse HEAD 2>/dev/null; git merge-base origin/master HEAD 2>/dev/null", $args);
                if (count($args) >= 1) {
                    $m .= "&git-head=" . urlencode($args[0]);
                    $v .= " " . $args[0];
                }
                if (count($args) >= 2) {
                    $m .= "&git-upstream=" . urlencode($args[1]);
                    $v .= " " . $args[1];
                }
            }
            Ht::stash_script("hotcrp.check_version(\"$m\",\"$v\")");
            $_SESSION["updatecheck"] = Conf::$now;
        }
    }

    function header($title, $id, $extra = []) {
        if (!$this->_header_printed) {
            $this->header_head($title, $extra);
            $this->header_body($title, $id, $extra);
        }
    }

    static function git_status() {
        $args = [];
        if (is_dir(SiteLoader::find(".git"))) {
            exec("export GIT_DIR=" . escapeshellarg(SiteLoader::$root) . "/.git; git rev-parse HEAD 2>/dev/null; git rev-parse v" . HOTCRP_VERSION . " 2>/dev/null", $args);
        }
        return count($args) == 2 ? $args : null;
    }

    function footer() {
        echo "<hr class=\"c\"></div>", // class='body'
            '<div id="footer">',
            $this->opt("extraFooter") ?? "",
            '<a class="noq" href="https://hotcrp.com/">HotCRP</a>';
        if (!$this->opt("noFooterVersion")) {
            if (Contact::$main_user && Contact::$main_user->privChair) {
                echo " v", HOTCRP_VERSION, " [";
                if (($git_data = self::git_status())
                    && $git_data[0] !== $git_data[1]) {
                    echo substr($git_data[0], 0, 7), "... ";
                }
                echo round(memory_get_peak_usage() / (1 << 20)), "M]";
            } else {
                echo "<!-- Version ", HOTCRP_VERSION, " -->";
            }
        }
        echo '</div>', Ht::unstash(), "</body>\n</html>\n";
    }

    /** @param Contact $viewer
     * @param Contact $user */
    private function pc_json_item($viewer, $user) {
        $name = $viewer->name_text_for($user);
        $j = (object) [
            "name" => $name !== "" ? $name : $user->email,
            "email" => $user->email
        ];
        if (($color_classes = $user->viewable_color_classes($viewer))) {
            $j->color_classes = $color_classes;
        }
        if ($this->sort_by_last && $user->lastName !== "") {
            self::pc_json_sort_by_last($j, $user);
        }
        return $j;
    }

    /** @param Contact $viewer
     * @param ReviewInfo $user
     * @return stdClass */
    private function pc_json_reviewer_item($viewer, $user) {
        $j = (object) [
            "name" => Text::nameo($user, NAME_P),
            "email" => $user->email
        ];
        if ($this->sort_by_last && $user->lastName !== "") {
            self::pc_json_sort_by_last($j, $user);
        }
        return $j;
    }

    /** @param Contact|ReviewInfo $r */
    static private function pc_json_sort_by_last($j, $r) {
        if (strlen($r->lastName) !== strlen($j->name)) {
            $j->lastpos = UnicodeHelper::utf16_strlen($r->firstName) + 1;
        }
        if (($r->nameAmbiguous ?? false) && $j->name !== "" && $r->email !== "") {
            $j->emailpos = UnicodeHelper::utf16_strlen($j->name) + 1;
        }
    }

    /** @return array<string,mixed> */
    function hotcrp_pc_json(Contact $viewer) {
        $hpcj = $list = $otherj = [];
        foreach ($this->pc_members() as $pcm) {
            $hpcj[$pcm->contactId] = $this->pc_json_item($viewer, $pcm);
            $list[] = $pcm->contactId;
        }
        $hpcj["__order__"] = $list;
        if ($this->sort_by_last) {
            $hpcj["__sort__"] = "last";
        }
        if ($viewer->can_view_user_tags()) {
            $hpcj["__tags__"] = $this->viewable_user_tags($viewer);
        }
        if ($this->paper
            && ($viewer->privChair || $viewer->allow_administer($this->paper))) {
            $list = [];
            foreach ($this->pc_members() as $pcm) {
                if ($pcm->can_accept_review_assignment($this->paper)) {
                    $list[] = $pcm->contactId;
                }
            }
            $hpcj["__assignable__"] = [$this->paper->paperId => $list];
            if ($this->setting("extrev_shepherd")) {
                $this->paper->ensure_reviewer_names();
                $erlist = [];
                foreach ($this->paper->reviews_as_display() as $rrow) {
                    if ($rrow->reviewType == REVIEW_EXTERNAL
                        && !$rrow->reviewToken
                        && !in_array($rrow->contactId, $erlist)) {
                        $otherj[$rrow->contactId] = $this->pc_json_reviewer_item($viewer, $rrow);
                        $erlist[] = $rrow->contactId;
                    }
                }
                if (!empty($erlist)) {
                    $hpcj["__extrev__"] = [$this->paper->paperId => $erlist];
                }
            }
        }
        if (!empty($otherj)) {
            $hpcj["__other__"] = $otherj;
        }
        return $hpcj;
    }

    function stash_hotcrp_pc(Contact $viewer, $always = false) {
        if (($always || !$this->opt("largePC"))
            && Ht::mark_stash("hotcrp_pc")) {
            Ht::stash_script("hotcrp.demand_load.pc(" . json_encode_browser($this->hotcrp_pc_json($viewer)) . ");");
        }
    }


    //
    // Action recording
    //

    const action_log_query = "insert into ActionLog (ipaddr, contactId, destContactId, trueContactId, paperId, timestamp, action)";
    const action_log_query_action_index = 6;

    function save_logs($on) {
        if ($on && $this->_save_logs === null) {
            $this->_save_logs = [];
        } else if (!$on && $this->_save_logs !== null) {
            $qv = [];
            '@phan-var-force list<list<string>> $qv';
            $last_pids = null;
            foreach ($this->_save_logs as $cid_text => $pids) {
                $pos = strpos($cid_text, "|");
                list($user, $dest_user, $true_user) = explode(",", substr($cid_text, 0, $pos));
                $what = substr($cid_text, $pos + 1);
                $pids = array_keys($pids);

                // Combine `Tag` messages
                if (substr($what, 0, 4) === "Tag "
                    && ($n = count($qv)) > 0
                    && substr($qv[$n-1][self::action_log_query_action_index], 0, 4) === "Tag "
                    && $last_pids === $pids) {
                    $qv[$n-1][self::action_log_query_action_index] .= substr($what, 3);
                } else {
                    foreach (self::format_log_values($what, $user, $dest_user, $true_user, $pids) as $x) {
                        $qv[] = $x;
                    }
                    $last_pids = $pids;
                }
            }
            if (!empty($qv)) {
                $this->qe(self::action_log_query . " values ?v", $qv);
            }
            $this->_save_logs = null;
        }
    }

    private static function log_clean_user($user, &$text) {
        if (!$user) {
            return 0;
        } else if (!is_numeric($user)) {
            if ($user->email
                && !$user->contactId
                && !$user->is_site_contact) {
                $suffix = " <{$user->email}>";
                if (!str_ends_with($text, $suffix)) {
                    $text .= $suffix;
                }
            }
            return $user->contactId;
        } else {
            return $user;
        }
    }

    /** @param null|int|Contact $user
     * @param null|int|Contact $dest_user
     * @param string $text
     * @param null|int|PaperInfo|list<int|PaperInfo> $pids
     * @param bool $dedup */
    function log_for($user, $dest_user, $text, $pids = null, $dedup = false) {
        if (is_object($pids)) {
            $pids = [$pids->paperId];
        } else if (is_array($pids)) {
            foreach ($pids as &$p) {
                $p = is_object($p) ? $p->paperId : $p;
            }
            unset($p);
        } else if ($pids === null || $pids <= 0) {
            $pids = [];
        } else {
            $pids = [$pids];
        }
        '@phan-var-force list<int> $pids';

        $true_user = 0;
        if ($user && is_object($user)) {
            if ($user->is_actas_user()) {
                $true_user = Contact::$base_auth_user->contactId;
            } else if (!$user->contactId
                       && !empty($pids)
                       && $user->has_capability_for($pids[0])) {
                $true_user = -1; // indicate download via link
            } else if ($user->is_bearer_authorized()) {
                $true_user = -2; // indicate bearer token
            }
        }
        $user = self::log_clean_user($user, $text);
        $dest_user = self::log_clean_user($dest_user, $text);

        if ($this->_save_logs === null) {
            $values = self::format_log_values($text, $user, $dest_user, $true_user, $pids);
            if ($dedup && count($values) === 1) {
                $this->qe_apply(self::action_log_query . " select ?, ?, ?, ?, ?, ?, ? from dual"
                    . " where (select max(logId) from (select * from ActionLog order by logId desc limit 100) t1 where ipaddr<=>? and contactId<=>? and destContactId<=>? and trueContactId<=>? and paperId<=>? and timestamp>=?-3600 and action<=>?) is null",
                    array_merge($values[0], $values[0]));
            } else {
                $this->qe(self::action_log_query . " values ?v", $values);
            }
        } else {
            $key = "$user,$dest_user,$true_user|$text";
            if (!isset($this->_save_logs[$key])) {
                $this->_save_logs[$key] = [];
            }
            foreach ($pids as $p) {
                $this->_save_logs[$key][$p] = true;
            }
        }
    }

    /** @return list<list<string>> */
    private static function format_log_values($text, $user, $dest_user, $true_user, $pids) {
        if (empty($pids)) {
            $pids = [null];
        }
        $addr = $_SERVER["REMOTE_ADDR"] ?? null;
        $user = (int) $user;
        $dest_user = (int) $dest_user;
        if ($dest_user === 0 || $dest_user === $user) {
            $dest_user = null;
        }
        $true_user = (int) $true_user;
        if ($true_user === 0) {
            $true_user = null;
        }
        $l = 0;
        $n = count($pids);
        $result = [];
        while ($l < $n) {
            $t = $text;
            $r = $n;
            while ($l + 1 !== $r) {
                $t = $text . " (papers ";
                if ($l === 0 && $r === $n) {
                    $t .= join(", ", $pids);
                } else {
                    $t .= join(", ", array_slice($pids, $l, $r - $l, true));
                }
                $t .= ")";
                if (strlen($t) <= 4096) {
                    break;
                }
                $r = $l + max(1, ($r - $l) >> 1);
            }
            if ($l + 1 === $r) {
                $pid = $pids[$l];
                $t = substr($text, 0, 4096);
            } else {
                $pid = null;
                $t = substr($t, 0, 4096);
            }
            $result[] = [$addr, $user, $dest_user, $true_user, $pid, Conf::$now, $t];
            $l = $r;
        }
        return $result;
    }


    // messages

    /** @return Fmt
     * @deprecated */
    function ims() {
        return $this->fmt();
    }

    /** @return Fmt */
    function fmt() {
        if (!$this->_fmt) {
            $this->_fmt = new Fmt;
            $this->_fmt->add_requirement_resolver([$this, "resolve_fmt_requirement"]);
            $m = ["?etc/msgs.json"];
            if (($lang = $this->opt("lang"))) {
                $m[] = "?etc/msgs.$lang.json";
            }
            $this->_fmt->set_default_priority(-1.0);
            expand_json_includes_callback($m, [$this->_fmt, "addj"]);
            $this->_fmt->clear_default_priority();
            if (($mlist = $this->opt("messageOverrides"))) {
                expand_json_includes_callback($mlist, [$this->_fmt, "addj"]);
            }
        }
        if ($this->_fmt_override_names !== null) {
            foreach ($this->_fmt_override_names as $id) {
                $this->_fmt->add_override(substr($id, 4), $this->settingTexts[$id]);
            }
            $this->_fmt_override_names = null;
        }
        return $this->_fmt;
    }

    /** @param string $itext
     * @return string */
    function _($itext, ...$args) {
        return $this->fmt()->_($itext, ...$args);
    }

    /** @param string $context
     * @param string $itext
     * @return string */
    function _c($context, $itext, ...$args) {
        return $this->fmt()->_c($context, $itext, ...$args);
    }

    /** @param string $id
     * @return string */
    function _i($id, ...$args) {
        return $this->fmt()->_i($id, ...$args);
    }

    /** @param string $context
     * @param string $id
     * @return string */
    function _ci($context, $id, ...$args) {
        return $this->fmt()->_ci($context, $id, ...$args);
    }

    /** @param string $s
     * @return false|array{true,mixed} */
    function resolve_fmt_requirement($s) {
        if (str_starts_with($s, "setting.")) {
            return [true, $this->setting(substr($s, 8))];
        } else if (str_starts_with($s, "opt.")) {
            return [true, $this->opt(substr($s, 4))];
        } else {
            return false;
        }
    }


    // search keywords

    function _add_search_keyword_json($kwj) {
        if (isset($kwj->name) && is_string($kwj->name)) {
            return self::xt_add($this->_search_keyword_base, $kwj->name, $kwj);
        } else if (is_string($kwj->match) && is_string($kwj->expand_function)) {
            $this->_search_keyword_factories[] = $kwj;
            return true;
        } else {
            return false;
        }
    }
    private function make_search_keyword_map() {
        $this->_search_keyword_base = $this->_search_keyword_factories = [];
        expand_json_includes_callback(["etc/searchkeywords.json"], [$this, "_add_search_keyword_json"]);
        if (($olist = $this->opt("searchKeywords"))) {
            expand_json_includes_callback($olist, [$this, "_add_search_keyword_json"]);
        }
        usort($this->_search_keyword_factories, "Conf::xt_priority_compare");
    }
    /** @return ?object */
    function search_keyword($keyword, Contact $user = null) {
        if ($this->_search_keyword_base === null) {
            $this->make_search_keyword_map();
        }
        $uf = $this->xt_search_name($this->_search_keyword_base, $keyword, $user);
        $ufs = $this->xt_search_factories($this->_search_keyword_factories, $keyword, $user, $uf);
        return self::xt_resolve_require($ufs[0]);
    }


    // assignment parsers

    function _add_assignment_parser_json($uf) {
        if (isset($uf->name) && is_string($uf->name)) {
            return self::xt_add($this->_assignment_parsers, $uf->name, $uf);
        } else {
            return false;
        }
    }
    /** @return ?AssignmentParser */
    function assignment_parser($keyword, Contact $user = null) {
        require_once("assignmentset.php");
        if ($this->_assignment_parsers === null) {
            $this->_assignment_parsers = [];
            expand_json_includes_callback(["etc/assignmentparsers.json"], [$this, "_add_assignment_parser_json"]);
            if (($olist = $this->opt("assignmentParsers"))) {
                expand_json_includes_callback($olist, [$this, "_add_assignment_parser_json"]);
            }
        }
        $uf = $this->xt_search_name($this->_assignment_parsers, $keyword, $user);
        $uf = self::xt_resolve_require($uf);
        if ($uf && !isset($uf->__parser)) {
            $p = $uf->parser_class;
            $uf->__parser = new $p($this, $uf);
        }
        return $uf ? $uf->__parser : null;
    }


    // formula functions

    function _add_formula_function_json($fj) {
        if (isset($fj->name) && is_string($fj->name)) {
            return self::xt_add($this->_formula_functions, $fj->name, $fj);
        } else {
            return false;
        }
    }
    /** @return ?object */
    function formula_function($fname, Contact $user) {
        if ($this->_formula_functions === null) {
            $this->_formula_functions = [];
            expand_json_includes_callback(["etc/formulafunctions.json"], [$this, "_add_formula_function_json"]);
            if (($olist = $this->opt("formulaFunctions"))) {
                expand_json_includes_callback($olist, [$this, "_add_formula_function_json"]);
            }
        }
        $uf = $this->xt_search_name($this->_formula_functions, $fname, $user);
        return self::xt_resolve_require($uf);
    }


    // API

    function _add_api_json($fj) {
        if (isset($fj->name) && is_string($fj->name)) {
            return self::xt_add($this->_api_map, $fj->name, $fj);
        } else {
            return false;
        }
    }
    private function api_map() {
        if ($this->_api_map === null) {
            $this->_api_map = [];
            expand_json_includes_callback(["etc/apifunctions.json"], [$this, "_add_api_json"]);
            if (($olist = $this->opt("apiFunctions"))) {
                expand_json_includes_callback($olist, [$this, "_add_api_json"]);
            }
        }
        return $this->_api_map;
    }
    private function check_api_json($fj, $user, $method) {
        if (isset($fj->allow_if) && !$this->xt_allowed($fj, $user)) {
            return false;
        } else if (!$method || isset($fj->alias)) {
            return true;
        } else {
            $k = strtolower($method);
            $methodx = $fj->$k ?? null;
            return $methodx
                || (($method === "POST" || $method === "HEAD")
                    && $methodx === null
                    && ($fj->get ?? false));
        }
    }
    function make_check_api_json($method) {
        return function ($xt, $user) use ($method) {
            return $this->check_api_json($xt, $user, $method);
        };
    }
    function has_api($fn, Contact $user = null, $method = null) {
        return !!$this->api($fn, $user, $method);
    }
    function api($fn, Contact $user = null, $method = null) {
        $this->_xt_allow_callback = $this->make_check_api_json($method);
        $uf = $this->xt_search_name($this->api_map(), $fn, $user);
        $this->_xt_allow_callback = null;
        return self::xt_enabled($uf) ? $uf : null;
    }
    /** @return JsonResult */
    function call_api_on($uf, $fn, Contact $user, Qrequest $qreq, $prow) {
        // NOTE: Does not check $user->can_view_paper($prow)
        $method = $qreq->method();
        if ($method !== "GET"
            && $method !== "HEAD"
            && $method !== "OPTIONS"
            && !$qreq->valid_token()
            && (!$uf || ($uf->post ?? false))
            && (!$uf || !($uf->allow_xss ?? false))) {
            return JsonResult::make_error(403, "<0>Missing credentials");
        } else if ($user->is_disabled()
                   && (!$uf || !($uf->allow_disabled ?? false))) {
            return JsonResult::make_error(403, "<0>Your account is disabled");
        } else if (!$uf) {
            if ($this->has_api($fn, $user, null)) {
                return JsonResult::make_error(405, "<0>Method not supported");
            } else if ($this->has_api($fn, null, $qreq->method())) {
                return JsonResult::make_error(403, "<0>Permission error");
            } else {
                return JsonResult::make_error(404, "<0>Function not found");
            }
        } else if (!$prow && ($uf->paper ?? false)) {
            return self::paper_error_json_result($qreq->annex("paper_whynot"));
        } else if (!is_string($uf->function)) {
            return JsonResult::make_error(404, "<0>Function not found");
        } else {
            try {
                self::xt_resolve_require($uf);
                $j = call_user_func($uf->function, $user, $qreq, $prow, $uf);
                return new JsonResult($j);
            } catch (JsonCompletion $ex) {
                return $ex->result;
            }
        }
    }
    /** @param PermissionProblem $whynot
     * @return JsonResult */
    static function paper_error_json_result($whynot) {
        $result = ["ok" => false, "message_list" => []];
        if ($whynot) {
            $status = isset($whynot["noPaper"]) ? 404 : 403;
            $m = "<5>" . $whynot->unparse_html();
            if (isset($whynot["signin"])) {
                $result["loggedout"] = true;
            }
        } else {
            $status = 400;
            $m = "<0>Bad request, missing submission";
        }
        $result["message_list"][] = new MessageItem(null, $m, 2);
        return new JsonResult($status, $result);
    }


    // paper columns

    function _add_paper_column_json($fj) {
        $ok = false;
        if (isset($fj->name) && is_string($fj->name)) {
            $ok = self::xt_add($this->_paper_column_map, $fj->name, $fj);
        }
        if (isset($fj->match)
            && is_string($fj->match)
            && isset($fj->expand_function)
            && is_string($fj->expand_function)) {
            $this->_paper_column_factories[] = $fj;
            $ok = true;
        }
        return $ok;
    }
    /** @return array<string,list<object>> */
    function paper_column_map() {
        if ($this->_paper_column_map === null) {
            require_once("papercolumn.php");
            $this->_paper_column_map = $this->_paper_column_factories = [];
            expand_json_includes_callback(["etc/papercolumns.json"], [$this, "_add_paper_column_json"]);
            if (($olist = $this->opt("paperColumns"))) {
                expand_json_includes_callback($olist, [$this, "_add_paper_column_json"]);
            }
            usort($this->_paper_column_factories, "Conf::xt_priority_compare");
        }
        return $this->_paper_column_map;
    }
    /** @return list<object> */
    function paper_column_factories() {
        $this->paper_column_map();
        return $this->_paper_column_factories;
    }
    /** @return ?object */
    function basic_paper_column($name, Contact $user = null) {
        $uf = $this->xt_search_name($this->paper_column_map(), $name, $user);
        return self::xt_enabled($uf) ? $uf : null;
    }
    /** @param string $name
     * @return list<object> */
    function paper_columns($name, Contact $user) {
        if ($name === "" || $name[0] === "?") {
            return [];
        }
        $uf = $this->xt_search_name($this->paper_column_map(), $name, $user);
        $ufs = $this->xt_search_factories($this->_paper_column_factories, $name, $user, $uf, "i");
        return array_values(array_filter($ufs, "Conf::xt_resolve_require"));
    }


    // option types

    function _add_option_type_json($fj) {
        if (isset($fj->name) && is_string($fj->name)
            && isset($fj->function) && is_string($fj->function)) {
            return self::xt_add($this->_option_type_map, $fj->name, $fj);
        } else {
            return false;
        }
    }

    /** @return array<string,object> */
    function option_type_map() {
        if ($this->_option_type_map === null) {
            require_once("paperoption.php");
            $this->_option_type_map = [];
            expand_json_includes_callback(["etc/optiontypes.json"], [$this, "_add_option_type_json"]);
            if (($olist = $this->opt("optionTypes"))) {
                expand_json_includes_callback($olist, [$this, "_add_option_type_json"]);
            }
            $m = [];
            foreach (array_keys($this->_option_type_map) as $name) {
                if (($uf = $this->xt_search_name($this->_option_type_map, $name, null)))
                    $m[$name] = $uf;
            }
            $this->_option_type_map = $m;
            uasort($this->_option_type_map, "Conf::xt_order_compare");
        }
        return $this->_option_type_map;
    }

    /** @param string $name
     * @return ?object */
    function option_type($name) {
        return ($this->option_type_map())[$name] ?? null;
    }


    // tokens

    function _add_token_json($fj) {
        if ((isset($fj->match) && is_string($fj->match))
            || (isset($fj->type) && is_int($fj->type))) {
            $this->_token_factories[] = $fj;
            return true;
        } else {
            return false;
        }
    }

    private function load_token_types() {
        if ($this->_token_factories === null) {
            $this->_token_factories = $this->_token_types = [];
            expand_json_includes_callback(["etc/capabilityhandlers.json"], [$this, "_add_token_json"]);
            if (($olist = $this->opt("capabilityHandlers"))) {
                expand_json_includes_callback($olist, [$this, "_add_token_json"]);
            }
            usort($this->_token_factories, "Conf::xt_priority_compare");
        }
    }

    /** @param string $token
     * @return ?object */
    function token_handler($token) {
        if ($this->_token_factories === null) {
            $this->load_token_types();
        }
        $this->_xt_last_match = null;
        $ufs = $this->xt_search_factories($this->_token_factories, $token, null);
        return $ufs[0];
    }

    /** @param int $type
     * @return ?object */
    function token_type($type) {
        if ($this->_token_factories === null) {
            $this->load_token_types();
        }
        if (!array_key_exists($type, $this->_token_types)) {
            $m = [];
            foreach ($this->_token_factories as $tf) {
                if (($tf->type ?? null) === $type)
                    $m[$type][] = $tf;
            }
            /** @phan-suppress-next-line PhanTypeMismatchArgument */
            $this->_token_types[$type] = $this->xt_search_name($m, $type, null);
        }
        return $this->_token_types[$type];
    }

    private function clean_tokens() {
        if ($this->_token_factories === null) {
            $this->load_token_types();
        }
        $ct_cleanups = [];
        foreach ($this->_token_factories as $tf) {
            if (isset($tf->type) && is_int($tf->type) && isset($tf->cleanup_function))
                $ct_cleanups[$tf->type] = true;
        }
        if (!empty($ct_cleanups)) {
            $result = $this->ql("select * from Capability where timeExpires>0 and timeExpires<? and capabilityType?a", Conf::$now, array_keys($ct_cleanups));
            while (($tok = TokenInfo::fetch($result, $this, false))) {
                if (($tf = $this->token_type($tok->capabilityType))
                    && isset($tf->cleanup_function))
                    call_user_func($tf->cleanup_function, $tok, $this);
            }
            Dbl::free($result);
        }
        $this->ql("delete from Capability where timeExpires>0 and timeExpires<".Conf::$now);
        $this->ql("insert into Settings set name='__capability_gc', value=? on duplicate key update value=?", Conf::$now, Conf::$now);
        $this->settings["__capability_gc"] = Conf::$now;
    }


    // mail: keywords, templates, DKIM

    function _add_mail_keyword_json($fj) {
        if (isset($fj->name) && is_string($fj->name)) {
            return self::xt_add($this->_mail_keyword_map, $fj->name, $fj);
        } else if (is_string($fj->match)) {
            $this->_mail_keyword_factories[] = $fj;
            return true;
        } else {
            return false;
        }
    }

    /** @return array<string,list<object>> */
    private function mail_keyword_map() {
        if ($this->_mail_keyword_map === null) {
            $this->_mail_keyword_map = $this->_mail_keyword_factories = [];
            expand_json_includes_callback(["etc/mailkeywords.json"], [$this, "_add_mail_keyword_json"]);
            if (($mks = $this->opt("mailKeywords"))) {
                expand_json_includes_callback($mks, [$this, "_add_mail_keyword_json"]);
            }
            usort($this->_mail_keyword_factories, "Conf::xt_priority_compare");
        }
        return $this->_mail_keyword_map;
    }

    /** @return list<object> */
    function mail_keywords($name) {
        $uf = $this->xt_search_name($this->mail_keyword_map(), $name, null);
        $ufs = $this->xt_search_factories($this->_mail_keyword_factories, $name, null, $uf);
        return array_values(array_filter($ufs, "Conf::xt_resolve_require"));
    }


    function _add_mail_template_json($fj) {
        if (isset($fj->name) && is_string($fj->name)) {
            if (isset($fj->body) && is_array($fj->body)) {
                $fj->body = join("", $fj->body);
            }
            return self::xt_add($this->_mail_template_map, $fj->name, $fj);
        } else {
            return false;
        }
    }

    /** @return array<string,list<object>> */
    function mail_template_map() {
        if ($this->_mail_template_map === null) {
            $this->_mail_template_map = [];
            expand_json_includes_callback(["etc/mailtemplates.json"], [$this, "_add_mail_template_json"]);
            if (($mts = $this->opt("mailTemplates"))) {
                expand_json_includes_callback($mts, [$this, "_add_mail_template_json"]);
            }
        }
        return $this->_mail_template_map;
    }

    /** @param string $name
     * @param bool $default_only
     * @param ?Contact $user
     * @return ?object */
    function mail_template($name, $default_only = false, $user = null) {
        $uf = $this->xt_search_name($this->mail_template_map(), $name, $user);
        if (!$uf || !Conf::xt_resolve_require($uf)) {
            return null;
        }
        if (!$default_only) {
            $se = $this->has_setting("mailsubj_$name");
            $s = $se ? $this->setting_data("mailsubj_$name") : null;
            $be = $this->has_setting("mailbody_$name");
            $b = $be ? $this->setting_data("mailbody_$name") : null;
            if (($se && $s !== $uf->subject)
                || ($be && $b !== $uf->body)) {
                $uf = clone $uf;
                if ($se) {
                    $uf->subject = $s;
                }
                if ($be) {
                    $uf->body = $b;
                }
            }
        }
        return $uf;
    }

    /** @return ?DKIMSigner */
    function dkim_signer() {
        if ($this->_dkim_signer === false) {
            if (($dkim = $this->opt("dkimConfig"))) {
                $this->_dkim_signer = DKIMSigner::make($dkim);
            } else {
                $this->_dkim_signer = null;
            }
        }
        return $this->_dkim_signer;
    }


    // hooks

    function _add_hook_json($fj) {
        if (isset($fj->function) && is_string($fj->function)) {
            if (isset($fj->event) && is_string($fj->event)) {
                return self::xt_add($this->_hook_map, $fj->event, $fj);
            } else if (isset($fj->match) && is_string($fj->match)) {
                $this->_hook_factories[] = $fj;
                return true;
            }
        }
        return false;
    }
    function add_hook($name, $function = null, $priority = null) {
        if ($this->_hook_map === null) {
            $this->hook_map();
        }
        $fj = is_object($name) ? $name : $function;
        if (is_string($fj)) {
            $fj = (object) ["function" => $fj];
        }
        if (is_string($name)) {
            $fj->event = $name;
        }
        if ($priority !== null) {
            $fj->priority = $priority;
        }
        return $this->_add_hook_json($fj) ? $fj : false;
    }
    function remove_hook($fj) {
        if (isset($fj->event) && is_string($fj->event)
            && isset($this->_hook_map[$fj->event])
            && ($i = array_search($fj, $this->_hook_map[$fj->event], true)) !== false) {
            array_splice($this->_hook_map[$fj->event], $i, 1);
            return true;
        } else if (isset($fj->match) && is_string($fj->match)
                   && ($i = array_search($fj, $this->_hook_factories, true)) !== false) {
            array_splice($this->_hook_factories, $i, 1);
            return true;
        }
        return false;
    }
    private function hook_map() {
        if ($this->_hook_map === null) {
            $this->_hook_map = $this->_hook_factories = [];
            if (($hlist = $this->opt("hooks"))) {
                expand_json_includes_callback($hlist, [$this, "_add_hook_json"]);
            }
        }
        return $this->_hook_map;
    }
    function call_hooks($name, Contact $user = null, ...$args) {
        $hs = ($this->hook_map())[$name] ?? null;
        foreach ($this->_hook_factories as $fj) {
            if ($fj->match === ".*"
                || preg_match("\1\\A(?:{$fj->match})\\z\1", $name, $m)) {
                $xfj = clone $fj;
                $xfj->event = $name;
                $xfj->match_data = $m;
                $hs = $hs ?? [];
                $hs[] = $xfj;
            }
        }
        if ($hs !== null) {
            usort($hs, "Conf::xt_priority_compare");
            $ids = [];
            foreach ($hs as $fj) {
                if ((!isset($fj->id) || !isset($ids[$fj->id]))
                    && $this->xt_allowed($fj, $user)) {
                    if (isset($fj->id)) {
                        $ids[$fj->id] = true;
                    }
                    if (self::xt_enabled($fj)) {
                        $fj->conf = $this;
                        $fj->user = $user;
                        $x = call_user_func($fj->function, $fj, ...$args);
                        unset($fj->conf, $fj->user);
                        if ($x === false) {
                            return false;
                        }
                    }
                }
            }
        }
    }


    // pages

    /** @return ComponentSet */
    function page_components(Contact $viewer) {
        if (!$this->_page_components || $this->_page_components->viewer() !== $viewer) {
            $this->_page_components = new ComponentSet($viewer, ["etc/pages.json"], $this->opt("pages"));
        }
        return $this->_page_components;
    }


    // setting info

    /** @return SettingInfoSet */
    function si_set() {
        $this->_setting_info = $this->_setting_info ?? SettingInfoSet::make_conf($this);
        return $this->_setting_info;
    }

    /** @param string $name
     * @return ?Si */
    function si($name) {
        return $this->si_set()->get($name);
    }
}
