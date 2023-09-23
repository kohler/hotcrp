<?php
// navigation.php -- HotCRP navigation helper functions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class NavigationState {
    // Base URL:    PROTOCOL://HOST[:PORT]/BASEPATH/
    // Site URL:    PROTOCOL://HOST[:PORT]/BASEPATH/[u/NNN/]
    // Current URL: PROTOCOL://HOST[:PORT]/SITEPATH/PAGE/PATH?QUERY
    /** @var string */
    public $protocol;           // "PROTOCOL://"
    /** @var string */
    public $host;               // "HOST"
    /** @var string */
    public $server;             // "PROTOCOL://HOST[:PORT]"
    /** @var non-empty-string */
    public $base_path;          // "/BASEPATH/"; always ends in /
    /** @var string */
    public $base_path_relative; // "/BASEPATH/", "../"+, or ""
    /** @var string */
    public $site_path;          // "/SITEPATH/"; always ends in /; suffix of $base_path;
                                // may end in `/u/NNN/`
    /** @var string */
    public $site_path_relative; // "/SITEPATH/", "../"+, or ""
    /** @var string */
    public $page;               // "PAGE" or "index" (.php suffix stripped)
    /** @var string */
    public $raw_page;           // "PAGE" or "", with .php suffix if given
    /** @var string */
    public $path;               // "/PATH" or ""
    /** @var string */
    public $shifted_path = "";
    /** @var string */
    public $query;              // "?QUERY" or ""
    /** @var string */
    public $php_suffix = "";
    /** @var bool */
    public $unproxied = false;
    /** @var bool */
    public $above_base = false;
    /** @var string */
    public $request_uri;

    // server variables:
    //   required: SERVER_PORT, SCRIPT_FILENAME, SCRIPT_NAME, REQUEST_URI
    //   optional: HTTP_HOST, SERVER_NAME, HTTPS, REQUEST_SCHEME

    function __construct($server) {
        if (!$server) {
            return;
        }

        // host, protocol, server
        $this->host = $server["HTTP_HOST"] ?? $server["SERVER_NAME"] ?? null;
        if ((isset($server["HTTPS"])
             && $server["HTTPS"] !== ""
             && $server["HTTPS"] !== "off")
            || ($server["HTTP_X_FORWARDED_PROTO"] ?? null) === "https"
            || ($server["REQUEST_SCHEME"] ?? null) === "https") {
            $x = "https://";
            $xport = 443;
        } else {
            $x = "http://";
            $xport = 80;
        }
        $this->protocol = $x;
        $x .= $this->host ? : "localhost";
        if (($port = $server["SERVER_PORT"])
            && $port != $xport
            && strpos($x, ":", 6) === false) {
            $x .= ":" . $port;
        }
        $this->server = $x;
        $this->request_uri = $server["REQUEST_URI"];
        $pct = strpos($this->request_uri, "%") !== false;

        // $this->query: easy-urldecoded portion including and after [?#];
        // $uri: encoded portion preceding $query
        $qpos = strpos($this->request_uri, "?");
        if (($hpos = strpos($this->request_uri, "#")) !== false) {
            $qpos = $qpos === false ? $hpos : min($qpos, $hpos);
        }
        if ($qpos !== false) {
            $this->query = substr($this->request_uri, $qpos);
            if ($pct) {
                $this->query = self::easy_urldecode($this->query);
            }
            $uri = substr($this->request_uri, 0, $qpos);
        } else {
            $this->query = "";
            $uri = $this->request_uri;
        }

        // $this->base_path: encoded path to root of site; nonempty, ends in /
        $bp = $this->find_base($uri, $server);
        if ($bp === "/"
            || strlen($bp) > strlen($uri)
            || substr($uri, 0, strlen($bp)) === $bp) {
            $this->base_path = $bp;
        } else {
            $nsl = substr_count($bp, "/");
            $pos = -1;
            while ($nsl > 0 && $pos !== false) {
                $pos = strpos($uri, "/", $pos + 1);
                --$nsl;
            }
            if ($pos !== false) {
                $this->base_path = substr($uri, 0, $pos + 1);
            } else { // this should never happen
                $this->base_path = $uri;
                if ($uri === "" || $uri[strlen($uri) - 1] !== "/") {
                    $this->base_path .= "/";
                }
            }
        }
        $this->above_base = strlen($this->base_path) > strlen($uri);

        // $this->php_suffix: ".php" or ""
        if (isset($server["HOTCRP_PHP_SUFFIX"])) {
            $this->php_suffix = $server["HOTCRP_PHP_SUFFIX"];
        } else if ($this->unproxied && function_exists("apache_get_modules")) {
            $this->php_suffix = ".php";
        }

        // separate $this->page and $this->path
        $nbp = strlen($this->base_path);
        $uri_suffix = (string) substr($uri, min($nbp, strlen($uri)));
        if ($pct) {
            $uri_suffix = self::easy_urldecode($uri_suffix);
        }
        if (($n = strpos($uri_suffix, "/")) === false) {
            $n = strlen($uri_suffix);
        }
        if ($n === 0) {
            $this->raw_page = "";
            $this->page = "index";
            $this->path = "";
        } else {
            $this->raw_page = substr($uri_suffix, 0, $n);
            $this->page = $this->raw_page;
            $this->path = substr($uri_suffix, $n);
            $this->apply_php_suffix();
        }

        // compute $this->base_path_relative
        $path_slash = substr_count($this->path, "/");
        if ($path_slash > 0) {
            $this->base_path_relative = str_repeat("../", $path_slash);
        } else if ($this->raw_page === "") {
            $this->base_path_relative = $this->base_path;
        } else {
            $this->base_path_relative = "";
        }

        // $this->site_path: initially $this->base_path
        $this->site_path = $this->base_path;
        $this->site_path_relative = $this->base_path_relative;
    }

    /** @param string $uri
     * @param array $server
     * @return non-empty-string */
    private function find_base($uri, $server) {
        // $sn: URI-decoded path by which server found this script.
        // $this->base_path is a prefix of $sn (but $sn might be decoded)
        $sn = $server["SCRIPT_NAME"];
        if ($sn === "" || $sn === "/") {
            return "/";
        }

        // Detect direct mapping within Apache (i.e., no proxying;
        // unlikely/not recommended configuration)
        $sfn = $server["SCRIPT_FILENAME"];
        $origsn = $server["ORIG_SCRIPT_NAME"] ?? null;
        $origsfn = $server["ORIG_SCRIPT_FILENAME"] ?? null;
        if ($origsn === null && $origsfn === null) {
            $nsn = strlen($sn);
            $sfx = substr($sfn, strrpos($sfn, "/") + 1);
            $npfx = strlen($sn) - strlen($sfx);
            if ($npfx > 0
                && $sn[$npfx - 1] === "/"
                && substr($sn, $npfx) === $sfx) {
                $this->unproxied = true;
                return substr($sn, 0, $npfx);
            }
        }

        // $path_info is URI-decoded path following base; detect it
        // and remove it from $sn if appropriate
        if ($origsn === null) {
            $path_info = $server["PATH_INFO"] ?? null;
            if ($path_info === null && $origsfn !== null) {
                $n1 = strlen($sfn);
                $n2 = strlen($origsfn);
                if ($n1 < $n2 && substr($origsfn, 0, $n1) === $sfn) {
                    $path_info = substr($origsfn, $n1);
                }
            }
            if ($path_info !== null) {
                $np = strlen($sn) - strlen($path_info);
                if ($np >= 0 && substr($sn, $np) === $path_info) {
                    $sn = substr($sn, 0, $np);
                }
            }
        }

        // Ensure base_path ends with slash
        if ($sn === "" || $sn[strlen($sn) - 1] !== "/") {
            $sn .= "/";
        }
        return $sn;
    }

    private function apply_php_suffix() {
        if ($this->page === $this->raw_page) {
            $pagelen = strlen($this->page);
            if ($pagelen > 4
                && substr($this->page, $pagelen - 4) === ".php") {
                $this->page = substr($this->page, 0, $pagelen - 4);
            } else if ($this->php_suffix !== ""
                       && $this->php_suffix !== ".php"
                       && $pagelen > ($sfxlen = strlen($this->php_suffix))
                       && substr($this->page, $pagelen - $sfxlen) === $this->php_suffix) {
                $this->page = substr($this->page, 0, $pagelen - $sfxlen);
            }
        }
    }

    /** @param string $s
     * @return string */
    static function easy_urldecode($s) {
        return preg_replace_callback('/%(?:2[CDEcde]|3[0-9]|4[0-9A-Fa-f]|5[0-9AaFf]|6[1-9A-Fa-f]|7[0-9AEae])/', function ($m) {
            return urldecode($m[0]);
        }, $s);
    }

    /** @param string $suffix
     * @return $this */
    function set_php_suffix($suffix) {
        $this->php_suffix = $suffix;
        $this->apply_php_suffix();
        return $this;
    }

    /** @return string */
    function self() {
        return "{$this->server}{$this->site_path}{$this->raw_page}{$this->path}{$this->query}";
    }

    /** @param bool $downcase_host
     * @return string */
    function site_absolute($downcase_host = false) {
        $x = $downcase_host ? strtolower($this->server) : $this->server;
        return $x . $this->site_path;
    }

    /** @param bool $downcase_host
     * @return string */
    function base_absolute($downcase_host = false) {
        $x = $downcase_host ? strtolower($this->server) : $this->server;
        return $x . $this->base_path;
    }

    /** @param ?string $url
     * @return string */
    function siteurl($url = null) {
        $x = $this->site_path_relative;
        if (!$url) {
            return $x;
        } else if (substr($url, 0, 5) !== "index" || substr($url, 5, 1) === "/") {
            return $x . $url;
        } else {
            return ($x ? : $this->site_path) . substr($url, 5);
        }
    }

    /** @param ?string $url
     * @return string */
    function siteurl_path($url = null) {
        $x = $this->site_path;
        if (!$url) {
            return $x;
        } else if (substr($url, 0, 5) !== "index" || substr($url, 5, 1) === "/") {
            return $x . $url;
        } else {
            return $x . substr($url, 5);
        }
    }

    /** @param string $url
     * @return string */
    function set_siteurl($url) {
        if ($url !== "" && $url[strlen($url) - 1] !== "/") {
            $url .= "/";
        }
        return ($this->site_path_relative = $url);
    }

    /** @param string $page
     * @return string */
    function set_page($page) {
        $this->raw_page = $this->page = $page;
        $this->apply_php_suffix();
        return $this->page;
    }

    /** @param string $path
     * @return string */
    function set_path($path) {
        return ($this->path = $path);
    }

    /** @param int $n
     * @param bool $decoded
     * @return ?string */
    function path_component($n, $decoded = false) {
        if ($this->path !== "") {
            $p = explode("/", substr($this->path, 1));
            if ($n + 1 < count($p)
                || ($n + 1 == count($p) && $p[$n] !== "")) {
                return $decoded ? urldecode($p[$n]) : $p[$n];
            }
        }
        return null;
    }

    /** @param int $n
     * @return string */
    function path_suffix($n) {
        if ($this->path !== "") {
            $p = 0;
            while ($n > 0 && ($p = strpos($this->path, "/", $p + 1))) {
                --$n;
            }
            if ($p !== false) {
                return substr($this->path, $p);
            }
        }
        return "";
    }

    /** @param int $n
     * @return ?string */
    function shift_path_components($n) {
        $nx = $n;
        $pos = 0;
        $path = $this->raw_page . $this->path;
        while ($n > 0 && $pos < strlen($path)) {
            if (($pos = strpos($path, "/", $pos)) !== false) {
                ++$pos;
                --$n;
            } else {
                $pos = strlen($path);
            }
        }
        if ($n > 0) {
            return null;
        }
        $this->site_path .= substr($path, 0, $pos);
        if (substr($this->site_path_relative, 0, 3) === "../") {
            $this->site_path_relative = substr($this->site_path_relative, 3 * $nx);
        } else {
            $this->site_path_relative = $this->site_path;
        }
        $this->shifted_path .= substr($path, 0, $pos);
        $spos = $pos;
        if ($pos < strlen($path) && ($spos = strpos($path, "/", $pos)) === false) {
            $spos = strlen($path);
        }
        if ($pos !== $spos) {
            $this->raw_page = $this->page = substr($path, $pos, $spos - $pos);
        } else {
            $this->raw_page = "";
            $this->page = "index";
        }
        // NB: str_ends_with is not available in this file in older PHPs
        if (($pagelen = strlen($this->page)) > 4
            && substr($this->page, $pagelen - 4) === ".php") {
            $this->page = substr($this->page, 0, $pagelen - 4);
        }
        $this->path = (string) substr($path, $spos);
        return $this->page;
    }

    /** @param string $url
     * @param ?string $siteref
     * @return string */
    function make_absolute($url, $siteref = null) {
        preg_match('/\A((?:https?:\/\/[^\/]+)?)(\/*)((?:\.\.\/)*)(.*)\z/i', $url, $m);
        if ($m[1] !== "") {
            return $url;
        } else if (strlen($m[2]) > 1) {
            return $this->protocol . substr($url, 2);
        } else if ($m[2] === "/") {
            return $this->server . $url;
        } else {
            if ($siteref === null) {
                $siteref = preg_replace('/\/[^\/]+\z/', "/",
                    substr($this->request_uri, 0, strlen($this->request_uri) - strlen($this->query)));
            }
            while ($m[3]) {
                $siteref = preg_replace('/\/[^\/]+\/\z/', "/", $siteref);
                $m[3] = substr($m[3], 3);
            }
            return "{$this->server}{$siteref}{$m[3]}{$m[4]}";
        }
    }

    /** @param bool $allow_http_if_localhost
     * @return void */
    function redirect_http_to_https($allow_http_if_localhost = false) {
        if ($this->protocol === "http://"
            && (!$allow_http_if_localhost
                || ($_SERVER["REMOTE_ADDR"] !== "127.0.0.1"
                    && $_SERVER["REMOTE_ADDR"] !== "::1"))) {
            Navigation::redirect_absolute("https://" . ($this->host ? : "localhost")
                . $this->siteurl_path("{$this->page}{$this->php_suffix}{$this->path}{$this->query}"));
        }
    }
}

class Navigation {
    /** @var ?NavigationState */
    static private $s;

    /** @return NavigationState */
    static function get() {
        if (!self::$s) {
            self::$s = new NavigationState(PHP_SAPI !== "cli" ? $_SERVER : null);
        }
        return self::$s;
    }

    /** @return string */
    static function self() {
        return self::get()->self();
    }

    /** @param string $url
     * @return never */
    static function redirect_absolute($url) {
        assert(substr_compare($url, "https://", 0, 8) === 0
               || substr_compare($url, "http://", 0, 7) === 0);
        // Might have an HTML-encoded URL; decode at least &amp;.
        $url = str_replace("&amp;", "&", $url);
        header("Location: {$url}");
        echo "<!DOCTYPE html>
<html lang=\"en\"><head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />
<title>Redirection</title>
<script>location=", json_encode($url), ";</script></head>
<body><p>You should be redirected <a href=\"", htmlspecialchars($url), "\">to here</a>.</p></body></html>\n";
        exit;
    }
}
