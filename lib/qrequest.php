<?php
// qrequest.php -- HotCRP helper class for request objects (no warnings)
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Qrequest implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    // NB see also count()
    /** @var string */
    private $____method;
    private $____a = [];
    private $____files = [];
    private $____x = [];
    /** @var bool */
    private $____post_ok = false;
    /** @var bool */
    private $____post_empty = false;
    /** @var ?string */
    private $____page;
    /** @var ?string */
    private $____path;
    /** @var ?string */
    private $____referrer;
    /** @param string $method */
    function __construct($method, $data = null) {
        $this->____method = $method;
        if ($data) {
            foreach ((array) $data as $k => $v) {
                $this->$k = $v;
            }
        }
    }
    /** @param Qrequest $qreq
     * @return Qrequest */
    static function empty_clone($qreq) {
        $qreq2 = new Qrequest($qreq->____method);
        return $qreq2->set_page($qreq->____page, $qreq->____path);
    }
    /** @param string $page
     * @param ?string $path
     * @return $this */
    function set_page($page, $path = null) {
        $this->____page = $page;
        $this->____path = $path;
        return $this;
    }
    /** @param ?string $referrer
     * @return $this */
    function set_referrer($referrer) {
        $this->____referrer = $referrer;
        return $this;
    }
    /** @return string */
    function method() {
        return $this->____method;
    }
    /** @return bool */
    function is_get() {
        return $this->____method === "GET";
    }
    /** @return bool */
    function is_post() {
        return $this->____method === "POST";
    }
    /** @return bool */
    function is_head() {
        return $this->____method === "HEAD";
    }
    /** @return ?string */
    function page() {
        return $this->____page;
    }
    /** @return ?string */
    function path() {
        return $this->____path;
    }
    /** @param int $n
     * @return ?string */
    function path_component($n, $decoded = false) {
        if ((string) $this->____path !== "") {
            $p = explode("/", substr($this->____path, 1));
            if ($n + 1 < count($p)
                || ($n + 1 == count($p) && $p[$n] !== "")) {
                return $decoded ? urldecode($p[$n]) : $p[$n];
            }
        }
        return null;
    }
    /** @return ?string */
    function referrer() {
        return $this->____referrer;
    }

    function offsetExists($offset) {
        return property_exists($this, $offset);
    }
    function& offsetGet($offset) {
        $x = null;
        if (property_exists($this, $offset)) {
            $x =& $this->$offset;
        }
        return $x;
    }
    function offsetSet($offset, $value) {
        $this->$offset = $value;
        unset($this->____a[$offset]);
    }
    function offsetUnset($offset) {
        unset($this->$offset);
    }
    function getIterator() {
        return new ArrayIterator($this->as_array());
    }
    function __set($name, $value) {
        $this->$name = $value;
        unset($this->____a[$name]);
    }
    function& __get($name) {
        $x = null;
        if (property_exists($this, $name)) {
            $x =& $this->$name;
        }
        return $x;
    }
    function __isset($name) {
        return isset($this->$name);
    }
    function __unset($name) {
        unset($this->$name);
    }
    function get($name, $default = null) {
        if (property_exists($this, $name)) {
            $default = $this->$name;
        }
        return $default;
    }
    function get_a($name, $default = null) {
        if (property_exists($this, $name)) {
            $default = $this->$name;
            if ($default === "__array__" && isset($this->____a[$name])) {
                $default = $this->____a[$name];
            }
        }
        return $default;
    }
    function allow_a(/* ... */) {
        foreach (func_get_args() as $name) {
            if (property_exists($this, $name)
                && $this->$name === "__array__"
                && isset($this->____a[$name])) {
                $this->$name = $this->____a[$name];
                unset($this->____a[$name]);
            }
        }
    }
    /** @return $this */
    function set_req($name, $value) {
        if (is_array($value)) {
            $this->$name = "__array__";
            $this->____a[$name] = $value;
        } else {
            $this->$name = $value;
        }
        return $this;
    }
    /** @return int */
    function count() {
        return count(get_object_vars($this)) - 9;
    }
    function jsonSerialize() {
        return $this->as_array();
    }
    /** @return array<string,mixed> */
    function as_array() {
        $d = [];
        foreach (get_object_vars($this) as $k => $v) {
            if (substr($k, 0, 4) !== "____")
                $d[$k] = $v;
        }
        return $d;
    }
    /** @param list<string> $keys
     * @return array<string,mixed> */
    function subset_as_array($keys) {
        $d = [];
        foreach ($keys as $k) {
            if (substr($k, 0, 4) !== "____" && isset($this->$k))
                $d[$k] = $this->$k;
        }
        return $d;
    }
    /** @return object */
    function as_object() {
        return (object) $this->as_array();
    }
    /** @return list<string> */
    function keys() {
        $d = [];
        foreach (array_keys(get_object_vars($this)) as $k) {
            if (substr($k, 0, 4) !== "____")
                $d[] = $k;
        }
        return $d;
    }
    /** @param string $key
     * @return bool */
    function contains($key) {
        return property_exists($this, $key);
    }
    /** @param string $name
     * @return $this */
    function set_file($name, $finfo) {
        $this->____files[$name] = $finfo;
        return $this;
    }
    /** @param string $name
     * @param string $content
     * @param ?string $filename
     * @param ?string $mimetype
     * @return $this */
    function set_file_content($name, $content, $filename = null, $mimetype = null) {
        $this->____files[$name] = [
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
        return !empty($this->____files);
    }
    /** @param string $name
     * @return bool */
    function has_file($name) {
        return isset($this->____files[$name]);
    }
    /** @param string $name
     * @return ?array{name:string,type:string,size:int,tmp_name:string,error:int} */
    function file($name) {
        $f = null;
        if (array_key_exists($name, $this->____files)) {
            $f = $this->____files[$name];
        }
        return $f;
    }
    /** @param string $name
     * @return string|false */
    function file_filename($name) {
        $fn = false;
        if (array_key_exists($name, $this->____files)) {
            $fn = $this->____files[$name]["name"];
        }
        return $fn;
    }
    /** @param string $name
     * @return int|false */
    function file_size($name) {
        $sz = false;
        if (array_key_exists($name, $this->____files)) {
            $sz = $this->____files[$name]["size"];
        }
        return $sz;
    }
    /** @param string $name
     * @param int $offset
     * @param ?int $maxlen
     * @return string|false */
    function file_contents($name, $offset = 0, $maxlen = null) {
        $data = false;
        if (array_key_exists($name, $this->____files)) {
            $finfo = $this->____files[$name];
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
        return $this->____files;
    }
    /** @return bool */
    function has_annexes() {
        return !empty($this->____x);
    }
    /** @return array<string,mixed> */
    function annexes() {
        return $this->____x;
    }
    /** @param string $name
     * @return bool */
    function has_annex($name) {
        return isset($this->____x[$name]);
    }
    /** @param string $name */
    function annex($name) {
        $x = null;
        if (array_key_exists($name, $this->____x)) {
            $x = $this->____x[$name];
        }
        return $x;
    }
    /** @template T
     * @param string $name
     * @param class-string<T> $class
     * @return T */
    function checked_annex($name, $class) {
        $x = $this->____x[$name] ?? null;
        if (!$x || !($x instanceof $class)) {
            throw new Exception("Bad annex $name.");
        }
        return $x;
    }
    /** @param string $name */
    function set_annex($name, $x) {
        $this->____x[$name] = $x;
    }
    /** @return void */
    function approve_token() {
        $this->____post_ok = true;
    }
    /** @return bool */
    function valid_token() {
        return $this->____post_ok;
    }
    /** @return bool */
    function valid_post() {
        if ($this->____post_ok && $this->____method !== "POST") {
            error_log("Qrequest::valid_post() on {$this->____method}");
        }
        return $this->____post_ok && $this->____method === "POST";
    }
    /** @return void */
    function set_post_empty() {
        $this->____post_empty = true;
    }
    /** @return bool */
    function post_empty() {
        return $this->____post_empty;
    }

    function xt_allow($e) {
        if ($e === "post") {
            return $this->____method === "POST" && $this->____post_ok;
        } else if ($e === "anypost") {
            return $this->____method === "POST";
        } else if ($e === "getpost") {
            return in_array($this->____method, ["POST", "GET", "HEAD"]) && $this->____post_ok;
        } else if (str_starts_with($e, "req.")) {
            foreach (explode(" ", $e) as $w) {
                if (str_starts_with($w, "req.")
                    && property_exists($this, substr($w, 4))) {
                    return true;
                }
            }
            return false;
        } else {
            return null;
        }
    }

    static function make_global() : Qrequest {
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
                    $s = "";
                    if (isset($fi["name"])) {
                        $s .= '<span class="lineno">' . htmlspecialchars($fi["name"]) . ':</span> ';
                    }
                    if ($fi["error"] == UPLOAD_ERR_INI_SIZE
                        || $fi["error"] == UPLOAD_ERR_FORM_SIZE) {
                        $s .= "Uploaded file too big. The maximum upload size is " . ini_get("upload_max_filesize") . "B.";
                    } else if ($fi["error"] == UPLOAD_ERR_PARTIAL) {
                        $s .= "File upload interrupted.";
                    } else {
                        $s .= "Error uploading file.";
                    }
                    $errors[] = $s;
                }
            }
        }
        if (!empty($errors)) {
            $qreq->set_annex("upload_errors", $errors);
        }
        return $qreq;
    }
}
