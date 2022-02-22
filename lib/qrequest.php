<?php
// qrequest.php -- HotCRP helper class for request objects (no warnings)
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Qrequest implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    // NB see also count()
    /** @var string */
    private $_method;
    /** @var array<string,string> */
    private $_v;
    /** @var array<string,list> */
    private $_a = [];
    private $_files = [];
    private $_annexes = [];
    /** @var bool */
    private $_post_ok = false;
    /** @var bool */
    private $_post_empty = false;
    /** @var ?string */
    private $_page;
    /** @var ?string */
    private $_path;
    /** @var ?string */
    private $_referrer;

    /** @var Qrequest */
    static public $main_request;

    const ARRAY_MARKER = "__array__";

    /** @param string $method
     * @param array<string,string> $data */
    function __construct($method, $data = []) {
        $this->_method = $method;
        $this->_v = $data;
    }
    /** @param Qrequest $qreq
     * @return Qrequest */
    static function empty_clone($qreq) {
        $qreq2 = new Qrequest($qreq->_method);
        return $qreq2->set_page($qreq->_page, $qreq->_path);
    }
    /** @param string $urlpart
     * @param ?string $method
     * @return Qrequest */
    static function make_url($urlpart, $method = "GET") {
        $qreq = new Qrequest($method);
        if (preg_match('/\A\/?([^\/?#]+)(\/.*?|)(?:\?|(?=#)|\z)([^#]*)(?:#.*|)\z/', $urlpart, $m)) {
            $qreq->set_page($m[1], $m[2]);
            if ($m[3] !== "") {
                preg_match_all('/([^&;=]*)=([^&;]*)/', $m[3], $n, PREG_SET_ORDER);
                foreach ($n as $x) {
                    $qreq->set_req(urldecode($x[1]), urldecode($x[2]));
                }
            }
        }
        if ($method === "POST") {
            $qreq->approve_token();
        }
        return $qreq;
    }
    /** @param string $page
     * @param ?string $path
     * @return $this */
    function set_page($page, $path = null) {
        $this->_page = $page;
        $this->_path = $path;
        return $this;
    }
    /** @param ?string $referrer
     * @return $this */
    function set_referrer($referrer) {
        $this->_referrer = $referrer;
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
        if ((string) $this->_path !== "") {
            $p = explode("/", substr($this->_path, 1));
            if ($n + 1 < count($p)
                || ($n + 1 == count($p) && $p[$n] !== "")) {
                return $decoded ? urldecode($p[$n]) : $p[$n];
            }
        }
        return null;
    }
    /** @return ?string */
    function referrer() {
        return $this->_referrer;
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
     * @param string $value */
    function set($name, $value) {
        $this->_v[$name] = $value;
        unset($this->_a[$name]);
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
     * @param list $value */
    function set_a($name, $value) {
        $this->_v[$name] = self::ARRAY_MARKER;
        $this->_a[$name] = $value;
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
     * @return $this */
    function set_file($name, $finfo) {
        $this->_files[$name] = $finfo;
        return $this;
    }
    /** @param string $name
     * @param string $content
     * @param ?string $filename
     * @param ?string $mimetype
     * @return $this */
    function set_file_content($name, $content, $filename = null, $mimetype = null) {
        $this->_files[$name] = [
            "name" => $filename ?? "__set_file_content.$name",
            "type" => $mimetype ?? "application/octet-stream",
            "size" => strlen($content),
            "content" => $content,
            "error" => 0
        ];
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
     * @return ?array{name:string,type:string,size:int,tmp_name:string,error:int} */
    function file($name) {
        $f = null;
        if (array_key_exists($name, $this->_files)) {
            $f = $this->_files[$name];
        }
        return $f;
    }
    /** @param string $name
     * @return string|false */
    function file_filename($name) {
        $fn = false;
        if (array_key_exists($name, $this->_files)) {
            $fn = $this->_files[$name]["name"];
        }
        return $fn;
    }
    /** @param string $name
     * @return int|false */
    function file_size($name) {
        $sz = false;
        if (array_key_exists($name, $this->_files)) {
            $sz = $this->_files[$name]["size"];
        }
        return $sz;
    }
    /** @param string $name
     * @param int $offset
     * @param ?int $maxlen
     * @return string|false */
    function file_contents($name, $offset = 0, $maxlen = null) {
        $data = false;
        if (array_key_exists($name, $this->_files)) {
            $finfo = $this->_files[$name];
            if (isset($finfo["content"])) {
                $data = substr($finfo["content"], $offset, $maxlen ?? PHP_INT_MAX);
            } else if ($maxlen === null) {
                $data = @file_get_contents($finfo["tmp_name"], false, null, $offset);
            } else {
                $data = @file_get_contents($finfo["tmp_name"], false, null, $offset, $maxlen);
            }
        }
        return $data;
    }
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
        $x = null;
        if (array_key_exists($name, $this->_annexes)) {
            $x = $this->_annexes[$name];
        }
        return $x;
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
    /** @return void */
    function approve_token() {
        $this->_post_ok = true;
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
            return $this->_method === "POST" && $this->_post_ok;
        } else if ($e === "anypost") {
            return $this->_method === "POST";
        } else if ($e === "getpost") {
            return in_array($this->_method, ["POST", "GET", "HEAD"]) && $this->_post_ok;
        } else if (str_starts_with($e, "req.")) {
            foreach (explode(" ", $e) as $w) {
                if (str_starts_with($w, "req.")
                    && $this->has(substr($w, 4))) {
                    return true;
                }
            }
            return false;
        } else {
            return null;
        }
    }

    static function make_global() : Qrequest {
        global $Qreq;
        $qreq = new Qrequest($_SERVER["REQUEST_METHOD"]);
        $qreq->set_page(Navigation::page(), Navigation::path());
        foreach ($_GET as $k => $v) {
            $qreq->set_req($k, $v);
        }
        foreach ($_POST as $k => $v) {
            $qreq->set_req($k, $v);
        }
        if (empty($_POST)) {
            $qreq->set_post_empty();
        }
        if (isset($_SERVER["HTTP_REFERER"])) {
            $qreq->set_referrer($_SERVER["HTTP_REFERER"]);
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
                        $errors[] = $e = MessageItem::error("Uploaded file too large");
                        if (!$too_big) {
                            $errors[] = MessageItem::inform("The maximum upload size is " . ini_get("upload_max_filesie") . "B.");
                            $too_big = true;
                        }
                    } else if ($fi["error"] == UPLOAD_ERR_PARTIAL) {
                        $errors[] = $e = MessageItem::error("File upload interrupted");
                    } else {
                        $errors[] = $e = MessageItem::error("Error uploading file");
                    }
                    $e->landmark = $fi["name"] ?? null;
                }
            }
        }
        if (!empty($errors)) {
            $qreq->set_annex("upload_errors", $errors);
        }
        Qrequest::$main_request = $Qreq = $qreq;
        return $qreq;
    }
}
