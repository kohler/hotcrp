<?php
// qrequest.php -- HotCRP helper class for request objects (no warnings)
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Qrequest implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    /** @var ?Conf */
    private $_conf;
    /** @var ?Contact */
    private $_user;
    /** @var ?NavigationState */
    private $_navigation;
    /** @var ?string */
    private $_page;
    /** @var ?string */
    private $_path;
    /** @var int */
    private $_path_component_index = 0;
    /** @var ?int */
    private $_path_component_count;
    /** @var string */
    private $_method;
    /** @var ?array<string,string> */
    private $_headers;
    /** @var int */
    private $_body_type = 0;
    /** @var ?string */
    private $_body;
    /** @var ?string */
    private $_body_file;
    /** @var array<string,string> */
    private $_v;
    /** @var array<string,list> */
    private $_a = [];
    /** @var array<string,QrequestFile> */
    private $_files = [];
    private $_annexes = [];
    /** @var bool */
    private $_post_ok = false;
    /** @var bool */
    private $_post_empty = false;
    /** @var ?string */
    private $_referrer;
    /** @var null|false|SessionList */
    private $_active_list = false;
    /** @var Qsession */
    private $_qsession;
    /** @var ?PaperInfo */
    private $_requested_paper;

    /** @var Qrequest */
    static public $main_request;

    const ARRAY_MARKER = "__array__";
    const BODY_NONE = 0;
    const BODY_FILE = 1;
    const BODY_STRING = 2;

    /** @param string $method
     * @param array<string,string> $data */
    function __construct($method, $data = []) {
        $this->_method = $method;
        $this->_v = $data;
        $this->_qsession = new Qsession;
    }

    /** @param string $method
     * @param array<string,string> $data
     * @return Qrequest */
    static function make($method, $data = []) {
        return new Qrequest($method, $data);
    }

    /** @param NavigationState $nav
     * @return $this */
    function set_navigation($nav) {
        $this->_navigation = $nav;
        $this->_page = $nav->page;
        return $this->set_path($nav->path);
    }

    /** @param string $page
     * @return $this */
    function set_page($page) {
        $this->_page = $page;
        return $this;
    }

    /** @param string $path
     * @return $this */
    function set_path($path) {
        $this->_path = $path;
        $this->_path_component_index = 0;
        $this->_path_component_count = null;
        return $this;
    }

    /** @param int $n
     * @return $this */
    function set_path_component_index($n) {
        $this->_path_component_index = $n;
        return $this;
    }

    /** @param ?string $referrer
     * @return $this */
    function set_referrer($referrer) {
        $this->_referrer = $referrer;
        return $this;
    }

    /** @param Conf $conf
     * @return $this */
    function set_conf($conf) {
        assert(!$this->_conf || $this->_conf === $conf);
        $this->_conf = $conf;
        return $this;
    }

    /** @param ?Contact $user
     * @return $this */
    function set_user($user) {
        assert(!$user || !$this->_conf || $this->_conf === $user->conf);
        if ($user) {
            $this->_conf = $user->conf;
        }
        $this->_user = $user;
        return $this;
    }

    /** @return $this */
    function set_qsession(Qsession $qsession) {
        $this->_qsession = $qsession;
        return $this;
    }

    /** @return $this */
    function set_paper(?PaperInfo $prow) {
        $this->_requested_paper = $prow;
        return $this;
    }

    /** @return string */
    function method() {
        return $this->_method;
    }
    /** @return bool */
    function is_get() {
        return $this->_method === "GET";
    }
    /** @return bool */
    function is_post() {
        return $this->_method === "POST";
    }
    /** @return bool */
    function is_head() {
        return $this->_method === "HEAD";
    }

    /** @return Conf */
    function conf() {
        return $this->_conf;
    }
    /** @return ?Contact */
    function user() {
        return $this->_user;
    }
    /** @return NavigationState */
    function navigation() {
        return $this->_navigation;
    }
    /** @return Qsession */
    function qsession() {
        return $this->_qsession;
    }

    /** @return ?string */
    function page() {
        return $this->_page;
    }
    /** @return ?string */
    function path() {
        return $this->_path;
    }
    /** @param int $n
     * @return ?string */
    function path_component($n, $decoded = false) {
        if ($this->_path_component_count === null) {
            $p = $this->_path ?? "";
            $this->_path_component_count = substr_count($p, "/")
                + (str_ends_with($p, "/") ? 0 : 1);
        }
        $n += $this->_path_component_index + 1;
        if ($n <= 0 || $n >= $this->_path_component_count) {
            return null;
        }
        $pc = explode("/", $this->_path);
        return $decoded ? urldecode($pc[$n]) : $pc[$n];
    }
    /** @return ?PaperInfo */
    function paper() {
        return $this->_requested_paper;
    }

    /** @return ?string */
    function referrer() {
        return $this->_referrer;
    }
    /** @return ?string */
    function user_agent() {
        return $this->_headers["HTTP_USER_AGENT"] ?? null;
    }

    /** @param string $k
     * @return ?string */
    function raw_header($k) {
        return $this->_headers[$k] ?? null;
    }

    /** @param string $k
     * @return ?string */
    function header($k) {
        return $this->_headers["HTTP_" . strtoupper(str_replace("-", "_", $k))] ?? null;
    }

    /** @param string $k
     * @param ?string $v */
    function set_header($k, $v) {
        $this->_headers["HTTP_" . strtoupper(str_replace("-", "_", $k))] = $v;
    }

    /** @return ?string */
    function body() {
        if ($this->_body !== null || $this->_body_type === self::BODY_NONE) {
            return $this->_body;
        }
        $s = @file_get_contents($this->_body_file ?? "php://input");
        if ($s !== false) {
            $this->_body = $s;
        } else {
            $this->_body_type = self::BODY_NONE;
            $this->_body_file = null;
        }
        return $this->_body;
    }

    /** @param ?string $extension
     * @return ?string */
    function body_file($extension = null) {
        if ($this->_body_file !== null || $this->_body_type === self::BODY_NONE) {
            return $this->_body_file;
        }
        $extension = $extension ?? Mimetype::extension($this->header("Content-Type"));
        $finfo = Filer::create_tempfile(null, "reqbody-%s{$extension}", 5);
        if (!$finfo) {
            return null;
        }
        if ($this->_body === null) {
            $if = fopen("php://input", "rb");
            $nc = stream_copy_to_stream($if, $finfo[1]);
            fclose($if);
            $ok = $nc !== false;
        } else {
            $nc = fwrite($finfo[1], $this->_body);
            $ok = $nc === strlen($this->_body);
        }
        fclose($finfo[1]);
        if ($ok) {
            $this->_body_file = $finfo[0];
        } else {
            $this->_body_type = self::BODY_NONE;
        }
        return $this->_body_file;
    }

    /** @param ?string $extension
     * @return ?string
     * @deprecated */
    function body_filename($extension = null) {
        return $this->body_file($extension);
    }

    /** @return ?string */
    function body_content_type() {
        if (($ct = $this->header("Content-Type"))) {
            return Mimetype::base($ct);
        } else if ($this->_body_type === self::BODY_NONE) {
            return null;
        }
        $b = (string) $this->body();
        if (str_starts_with($b, "\x50\x4B\x03\x04")) {
            return "application/zip";
        } else if (preg_match('/\A\s*[\[\{]/s', $b)) {
            return "application/json";
        }
        return null;
    }

    /** @param string $body
     * @param ?string $content_type
     * @return $this */
    function set_body($body, $content_type = null) {
        $this->_body_type = self::BODY_STRING;
        $this->_body_file = null;
        $this->_body = $body;
        if ($content_type !== null) {
            $this->set_header("Content-Type", $content_type);
        }
        return $this;
    }

    #[\ReturnTypeWillChange]
    function offsetExists($offset) {
        return array_key_exists($offset, $this->_v);
    }
    #[\ReturnTypeWillChange]
    function offsetGet($offset) {
        return $this->_v[$offset] ?? null;
    }
    #[\ReturnTypeWillChange]
    function offsetSet($offset, $value) {
        if (is_array($value)) {
            error_log("array offsetSet at " . debug_string_backtrace());
        }
        $this->_v[$offset] = $value;
        unset($this->_a[$offset]);
    }
    #[\ReturnTypeWillChange]
    function offsetUnset($offset) {
        unset($this->_v[$offset]);
        unset($this->_a[$offset]);
    }
    #[\ReturnTypeWillChange]
    /** @return Iterator<string,mixed> */
    function getIterator() {
        return new ArrayIterator($this->as_array());
    }
    /** @param string $name
     * @param int|float|string $value
     * @return void */
    function __set($name, $value) {
        if (is_array($value)) {
            error_log("array __set at " . debug_string_backtrace());
        }
        $this->_v[$name] = $value;
        unset($this->_a[$name]);
    }
    /** @param string $name
     * @return ?string */
    function __get($name) {
        return $this->_v[$name] ?? null;
    }
    /** @param string $name
     * @return bool */
    function __isset($name) {
        return isset($this->_v[$name]);
    }
    /** @param string $name */
    function __unset($name) {
        unset($this->_v[$name]);
        unset($this->_a[$name]);
    }
    /** @param string $name
     * @return bool */
    function has($name) {
        return array_key_exists($name, $this->_v);
    }
    /** @param string $name
     * @return ?string */
    function get($name) {
        return $this->_v[$name] ?? null;
    }
    /** @param string $name
     * @param string $value
     * @return $this */
    function set($name, $value) {
        $this->_v[$name] = $value;
        unset($this->_a[$name]);
        return $this;
    }
    /** @param string $name
     * @return bool */
    function has_a($name) {
        return isset($this->_a[$name]);
    }
    /** @param string $name
     * @return ?list */
    function get_a($name) {
        return $this->_a[$name] ?? null;
    }
    /** @param string $name
     * @param list $value
     * @return $this */
    function set_a($name, $value) {
        $this->_v[$name] = self::ARRAY_MARKER;
        $this->_a[$name] = $value;
        return $this;
    }
    /** @return $this */
    function set_req($name, $value) {
        if (is_array($value)) {
            $this->_v[$name] = self::ARRAY_MARKER;
            $this->_a[$name] = $value;
        } else {
            $this->_v[$name] = $value;
            unset($this->_a[$name]);
        }
        return $this;
    }
    #[\ReturnTypeWillChange]
    /** @return int */
    function count() {
        return count($this->_v);
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->as_array();
    }
    /** @return array<string,mixed> */
    function as_array() {
        return $this->_v;
    }
    /** @param string ...$keys
     * @return array<string,mixed> */
    function subset_as_array(...$keys) {
        $d = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $this->_v))
                $d[$k] = $this->_v[$k];
        }
        return $d;
    }
    /** @return object */
    function as_object() {
        return (object) $this->as_array();
    }
    /** @return list<string> */
    function keys() {
        return array_keys($this->_v);
    }
    /** @param string $key
     * @return bool */
    function contains($key) {
        return array_key_exists($key, $this->_v);
    }
    /** @param string $name
     * @param array|QrequestFile $finfo
     * @return $this */
    function set_file($name, $finfo) {
        if (is_array($finfo)) {
            $this->_files[$name] = QrequestFile::make_finfo($finfo);
        } else {
            $this->_files[$name] = $finfo;
        }
        return $this;
    }
    /** @param string $name
     * @param string $content
     * @param ?string $filename
     * @param ?string $mimetype
     * @return $this */
    function set_file_content($name, $content, $filename = null, $mimetype = null) {
        $this->_files[$name] = QrequestFile::make_string($content, $filename, $mimetype);
        return $this;
    }
    /** @return bool */
    function has_files() {
        return !empty($this->_files);
    }
    /** @param string $name
     * @return bool */
    function has_file($name) {
        return isset($this->_files[$name]);
    }
    /** @param string $name
     * @return ?QrequestFile */
    function file($name) {
        return $this->_files[$name] ?? null;
    }
    /** @param string $name
     * @return ?string */
    function file_filename($name) {
        $f = $this->_files[$name] ?? null;
        return $f ? $f->name : null;
    }
    /** @param string $name
     * @return int|false */
    function file_size($name) {
        $f = $this->_files[$name] ?? null;
        return $f ? $f->size : false;
    }
    /** @param string $name
     * @param int $offset
     * @param ?int $maxlen
     * @return string|false */
    function file_content($name, $offset = 0, $maxlen = null) {
        $f = $this->_files[$name] ?? null;
        return $f ? $f->content($offset, $maxlen) : false;
    }
    /** @param string $name
     * @param int $offset
     * @param ?int $maxlen
     * @return string|false
     * @deprecated */
    function file_contents($name, $offset = 0, $maxlen = null) {
        return $this->file_content($name, $offset, $maxlen);
    }
    /** @return array<string,QrequestFile> */
    function files() {
        return $this->_files;
    }
    /** @return bool */
    function has_annexes() {
        return !empty($this->_annexes);
    }
    /** @return array<string,mixed> */
    function annexes() {
        return $this->_annexes;
    }
    /** @param string $name
     * @return bool */
    function has_annex($name) {
        return isset($this->_annexes[$name]);
    }
    /** @param string $name */
    function annex($name) {
        return $this->_annexes[$name] ?? null;
    }
    /** @template T
     * @param string $name
     * @param class-string<T> $class
     * @return T */
    function checked_annex($name, $class) {
        $x = $this->_annexes[$name] ?? null;
        if (!$x || !($x instanceof $class)) {
            throw new Exception("Bad annex $name");
        }
        return $x;
    }
    /** @param string $name */
    function set_annex($name, $x) {
        $this->_annexes[$name] = $x;
    }
    /** @return $this */
    function approve_token() {
        $this->_post_ok = true;
        return $this;
    }
    /** @return bool */
    function valid_token() {
        return $this->_post_ok;
    }
    /** @return bool */
    function valid_post() {
        return $this->_post_ok && $this->_method === "POST";
    }
    /** @return void */
    function set_post_empty() {
        $this->_post_empty = true;
    }
    /** @return bool */
    function post_empty() {
        return $this->_post_empty;
    }

    /** @param string $e
     * @return ?bool */
    function xt_allow($e) {
        if ($e === "post") {
            return $this->_post_ok
                && $this->_method === "POST";
        } else if ($e === "anypost") {
            return $this->_method === "POST";
        } else if ($e === "getpost") {
            return $this->_post_ok
                && in_array($this->_method, ["POST", "GET", "HEAD"], true);
        } else if ($e === "get") {
            return $this->_method === "GET";
        } else if ($e === "head") {
            return $this->_method === "HEAD";
        } else if (str_starts_with($e, "req.")) {
            return $this->has(substr($e, 4));
        }
        return null;
    }

    /** @param ?NavigationState $nav */
    static function make_minimal($nav = null) : Qrequest {
        $qreq = new Qrequest($_SERVER["REQUEST_METHOD"] ?? "NONE");
        $qreq->set_navigation($nav ?? Navigation::get());
        if (array_key_exists("post", $_GET)) {
            $qreq->set_req("post", $_GET["post"]);
        }
        return $qreq;
    }

    /** @param ?NavigationState $nav */
    static function make_global($nav = null) : Qrequest {
        $qreq = self::make_minimal($nav);
        foreach ($_GET as $k => $v) {
            $qreq->set_req($k, $v);
        }
        foreach ($_POST as $k => $v) {
            $qreq->set_req($k, $v);
        }
        if (empty($_POST)) {
            $qreq->set_post_empty();
            $qreq->_body_type = self::BODY_FILE;
        }
        $qreq->_headers = $_SERVER;
        if (isset($_SERVER["HTTP_REFERER"])) {
            $qreq->set_referrer($_SERVER["HTTP_REFERER"]);
        }

        // Work around GET URL length limitations with `:method:` parameter.
        // A POST request can set `:method:` to GET for GET semantics.
        if (($_GET[":method:"] ?? null) === "GET"
            && $qreq->method() === "POST") {
            $qreq->_method = "GET";
        }

        // $_FILES requires special processing since we want error messages.
        $errors = [];
        $too_big = false;
        foreach ($_FILES as $nx => $fix) {
            if (is_array($fix["error"])) {
                $fis = [];
                foreach (array_keys($fix["error"]) as $i) {
                    $fis[$i ? "$nx.$i" : $nx] = ["name" => $fix["name"][$i], "type" => $fix["type"][$i], "size" => $fix["size"][$i], "tmp_name" => $fix["tmp_name"][$i], "error" => $fix["error"][$i]];
                }
            } else {
                $fis = [$nx => $fix];
            }
            foreach ($fis as $n => $fi) {
                if ($fi["error"] == UPLOAD_ERR_OK) {
                    if (is_uploaded_file($fi["tmp_name"])) {
                        $qreq->set_file($n, $fi);
                    }
                } else if ($fi["error"] != UPLOAD_ERR_NO_FILE) {
                    if ($fi["error"] == UPLOAD_ERR_INI_SIZE
                        || $fi["error"] == UPLOAD_ERR_FORM_SIZE) {
                        $errors[] = $e = MessageItem::error("<0>Uploaded file too large");
                        if (!$too_big) {
                            $errors[] = MessageItem::inform("<0>The maximum upload size is " . ini_get("upload_max_filesie") . "B.");
                            $too_big = true;
                        }
                    } else if ($fi["error"] == UPLOAD_ERR_PARTIAL) {
                        $errors[] = $e = MessageItem::error("<0>File upload interrupted");
                    } else {
                        $errors[] = $e = MessageItem::error("<0>Error uploading file");
                    }
                    $e->landmark = $fi["name"] ?? null;
                }
            }
        }
        if (!empty($errors)) {
            $qreq->set_annex("upload_errors", $errors);
        }

        return $qreq;
    }

    /** @return Qrequest */
    static function set_main_request(Qrequest $qreq) {
        global $Qreq;
        Qrequest::$main_request = $Qreq = $qreq;
        return $qreq;
    }


    /** @param string $name
     * @param string $value
     * @param array $opt */
    function set_cookie_opt($name, $value, $opt) {
        $opt["path"] = $opt["path"] ?? $this->_navigation->base_path;
        $opt["domain"] = $opt["domain"] ?? $this->_conf->opt("sessionDomain") ?? "";
        $opt["secure"] = $opt["secure"] ?? $this->_conf->opt("sessionSecure") ?? false;
        if (!isset($opt["samesite"])) {
            $samesite = $this->_conf->opt("sessionSameSite") ?? "Lax";
            if ($samesite && ($opt["secure"] || $samesite !== "None")) {
                $opt["samesite"] = $samesite;
            }
        }
        if (!hotcrp_setcookie($name, $value, $opt)) {
            error_log(debug_string_backtrace());
        }
    }

    /** @param string $name
     * @param string $value
     * @param int $expires_at */
    function set_cookie($name, $value, $expires_at) {
        $this->set_cookie_opt($name, $value, ["expires" => $expires_at]);
    }

    /** @param string $name
     * @param string $value
     * @param int $expires_at */
    function set_httponly_cookie($name, $value, $expires_at) {
        $this->set_cookie_opt($name, $value, ["expires" => $expires_at, "httponly" => true]);
    }


    /** @param string|list<string> $title
     * @param string $id
     * @param array{paperId?:int|string,body_class?:string,action_bar?:string,title_div?:string,subtitle?:string,save_messages?:bool,hide_title?:bool,hide_header?:bool} $extra */
    function print_header($title, $id, $extra = []) {
        if (!$this->_conf->_header_printed) {
            $this->_conf->print_head_tag($this, $title, $extra);
            $this->_conf->print_body_entry($this, $title, $id, $extra);
        }
    }

    function print_footer() {
        echo '<hr class="c"></main>', // close #p-body
            '</div>',                // close #p-page
            '<footer id="p-footer" class="need-banner-offset banner-bottom">',
            $this->_conf->opt("extraFooter") ?? "",
            '<a class="noq" href="https://hotcrp.com/">HotCRP</a>';
        if (!$this->_conf->opt("noFooterVersion")) {
            if ($this->_user && $this->_user->privChair) {
                echo " v", HOTCRP_VERSION, " [";
                if (($git_data = Conf::git_status())
                    && $git_data[0] !== $git_data[1]) {
                    echo substr($git_data[0], 0, 7), "... ";
                }
                echo round(memory_get_peak_usage() / (1 << 20)), "M]";
            } else {
                echo "<!-- Version ", HOTCRP_VERSION, " -->";
            }
        }
        echo '</footer>', Ht::unstash(), "</body>\n</html>\n";
    }

    static function print_footer_hook(Contact $user, Qrequest $qreq) {
        $qreq->print_footer();
    }


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

    function set_active_list(?SessionList $list) {
        assert($this->_active_list === false);
        $this->_active_list = $list;
    }


    /** @return void */
    function open_session() {
        $this->_qsession->open();
    }

    /** @return ?string */
    function qsid() {
        return $this->_qsession->sid;
    }

    /** @param string $key
     * @return bool */
    function has_gsession($key) {
        return $this->_qsession->has($key);
    }

    function clear_gsession() {
        $this->_qsession->clear();
    }

    /** @param string $key
     * @return mixed */
    function gsession($key) {
        return $this->_qsession->get($key);
    }

    /** @param string $key
     * @param mixed $value */
    function set_gsession($key, $value) {
        $this->_qsession->set($key, $value);
    }

    /** @param string $key */
    function unset_gsession($key) {
        $this->_qsession->unset($key);
    }

    /** @param string $key
     * @return bool */
    function has_csession($key) {
        return $this->_conf
            && $this->_conf->session_key !== null
            && $this->_qsession->has2($this->_conf->session_key, $key);
    }

    /** @param string $key
     * @return mixed */
    function csession($key) {
        if ($this->_conf && $this->_conf->session_key !== null) {
            return $this->_qsession->get2($this->_conf->session_key, $key);
        }
        return null;
    }

    /** @param string $key
     * @param mixed $value */
    function set_csession($key, $value) {
        if ($this->_conf && $this->_conf->session_key !== null) {
            $this->_qsession->set2($this->_conf->session_key, $key, $value);
        }
    }

    /** @param string $key */
    function unset_csession($key) {
        if ($this->_conf && $this->_conf->session_key !== null) {
            $this->_qsession->unset2($this->_conf->session_key, $key);
        }
    }

    /** @return string */
    function post_value() {
        if ($this->_qsession->sid === null) {
            $this->_qsession->open();
        }
        return $this->maybe_post_value();
    }

    /** @return string */
    function maybe_post_value() {
        $sid = $this->_qsession->sid ?? "";
        if ($sid === "") {
            return ".empty";
        }
        return urlencode(substr($sid, strlen($sid) > 16 ? 8 : 0, 12));
    }


    /** @return array<string,string|list> */
    function debug_json() {
        $a = [];
        foreach ($this->_v as $k => $v) {
            if ($v === "__array__" && ($av = $this->_a[$k] ?? null) !== null) {
                if (count($av) > 20) {
                    $av = array_slice($av, 0, 20);
                    $av[] = "...";
                }
                $a[$k] = $av;
            } else if ($v !== null) {
                if (strlen($v) > 120) {
                    $v = substr($v, 0, 117) . "...";
                }
                $a[$k] = $v;
            }
        }
        foreach ($this->_files as $k => $v) {
            $fv = ["name" => $v->name, "type" => $v->type, "size" => $v->size];
            if ($v->error) {
                $fv["error"] = $v->error;
            }
            $a[$k] = $v;
        }
        return $a;
    }
}

class QrequestFile {
    /** @var string */
    public $name;
    /** @var string */
    public $type;
    /** @var ?int */
    public $size;
    /** @var ?string */
    public $tmp_name;
    /** @var ?string */
    public $content;
    /** @var int */
    public $error;
    /** @var ?resource */
    public $stream;
    /** @var string */
    public $docstore_tmp_name;

    /** @param array{name?:string,type?:string,size?:?int,tmp_name?:?string,content?:?string,error?:int} $finfo
     * @return QrequestFile */
    static function make_finfo($finfo) {
        $qf = new QrequestFile;
        $qf->name = $finfo["name"] ?? "";
        $qf->type = $finfo["type"] ?? "application/octet-stream";
        $qf->size = $finfo["size"] ?? null;
        $qf->tmp_name = $finfo["tmp_name"] ?? null;
        $qf->content = $finfo["content"] ?? null;
        $qf->error = $finfo["error"] ?? 0;
        return $qf;
    }

    /** @param string $content
     * @param ?string $filename
     * @param ?string $mimetype
     * @return QrequestFile */
    static function make_string($content, $filename = null, $mimetype = null) {
        $qf = new QrequestFile;
        $qf->name = $filename ?? "__content__";
        $qf->type = $mimetype ?? "application/octet-stream";
        $qf->size = strlen($content);
        $qf->content = $content;
        $qf->error = 0;
        return $qf;
    }

    /** @param resource $stream
     * @param ?string $filename
     * @param ?string $mimetype
     * @return QrequestFile */
    static function make_stream($stream, $filename = null, $mimetype = null) {
        $qf = new QrequestFile;
        $qf->name = $filename ?? "__content__";
        $qf->type = $mimetype ?? "application/octet-stream";
        if (($stat = @fstat($stream)) && isset($stat["size"])) {
            $qf->size = $stat["size"];
        }
        $qf->stream = $stream;
        $qf->error = 0;
        return $qf;
    }

    /** @param DocumentInfo $doc
     * @return ?QrequestFile */
    static function make_document($doc) {
        $qf = new QrequestFile;
        $qf->name = $doc->filename;
        $qf->type = $doc->mimetype;
        if (($size = $doc->size()) >= 0) {
            $qf->size = $size;
        }
        if (($qf->tmp_name = $doc->content_file()) === null) {
            return null;
        }
        return $qf;
    }

    /** @param int $offset
     * @param ?int $maxlen
     * @return string|false */
    function content($offset = 0, $maxlen = null) {
        if ($this->content !== null) {
            $data = substr($this->content, $offset, $maxlen ?? PHP_INT_MAX);
        } else if ($maxlen === null) {
            $data = @file_get_contents($this->tmp_name, false, null, $offset);
        } else {
            $data = @file_get_contents($this->tmp_name, false, null, $offset, $maxlen);
        }
        return $data;
    }

    /** @return bool */
    function prepare_content() {
        if ($this->content !== null || $this->stream !== null) {
            return true;
        }
        if (($stream = @fopen($this->tmp_name, "rb"))) {
            $this->stream = $stream;
            return true;
        }
        return false;
    }

    /** @param string $template
     * @return ?QrequestFile */
    function content_or_docstore($template, ?Conf $conf = null) {
        if ($this->content !== null || $this->stream !== null) {
            return $this;
        }
        $size = $this->size ?? 0;
        if ($size <= (4 << 20)
            || ((!$conf || !$conf->docstore_tempdir())
                && $size <= (80 << 20))) {
            $t = @file_get_contents($this->tmp_name);
            if ($t === false) {
                return null;
            }
            $qf = clone $this;
            $qf->content = $t;
            $qf->size = strlen($t);
            return $qf;
        }
        $dstempdir = $conf ? $conf->docstore_tempdir() : null;
        if (!$dstempdir) {
            return null;
        }
        $tfinfo = Filer::create_tempfile($dstempdir, $template);
        if ($tfinfo === null) {
            return null;
        }
        if (@move_uploaded_file($this->tmp_name, $tfinfo[0])
            && ($stream = @fopen($tfinfo[0], "rb"))) {
            $this->tmp_name = $tfinfo[0];
            $this->stream = $stream;
            $this->docstore_tmp_name = substr($tfinfo[0], strrpos($tfinfo[0], "/") + 1);
        }
        fclose($tfinfo[1]);
        return $this->stream ? $this : null;
    }

    function convert_to_utf8() {
        assert($this->content !== null || $this->stream !== null);
        if ($this->content !== null) {
            $this->content = convert_to_utf8($this->content);
            $this->size = strlen($this->content);
        } else {
            UTF8ConversionFilter::append($this->stream);
        }
    }
}
