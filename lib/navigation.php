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
    public $site_path;          // "/SITEPATH/"; always ends in /; has
                                // $base_path as prefix; may end in `/u/NNN/`
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

    /** @param associative-array $server
     * @return NavigationState */
    static function make_server($server) {
        $nav = new NavigationState;

        // host, protocol, server
        $nav->host = $server["HTTP_HOST"] ?? $server["SERVER_NAME"] ?? null;
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
        $nav->protocol = $x;
        $x .= $nav->host ? : "localhost";
        if (($port = $server["SERVER_PORT"])
            && $port != $xport
            && strpos($x, ":", 6) === false) {
            $x .= ":" . $port;
        }
        $nav->server = $x;
        $nav->request_uri = $server["REQUEST_URI"];
        $pct = strpos($nav->request_uri, "%") !== false;

        // $nav->query: easy-urldecoded portion including and after [?#];
        // $uri: encoded portion preceding $query
        $qpos = strpos($nav->request_uri, "?");
        if (($hpos = strpos($nav->request_uri, "#")) !== false) {
            $qpos = $qpos === false ? $hpos : min($qpos, $hpos);
        }
        if ($qpos !== false) {
            $nav->query = substr($nav->request_uri, $qpos);
            if ($pct) {
                $nav->query = self::easy_urldecode($nav->query);
            }
            $uri = substr($nav->request_uri, 0, $qpos);
        } else {
            $nav->query = "";
            $uri = $nav->request_uri;
        }

        // base_path: encoded path to root of site; nonempty, ends in /
        $bp = $nav->find_base($uri, $server);
        if ($bp === "/"
            || strlen($bp) > strlen($uri)
            || substr($uri, 0, strlen($bp)) === $bp) {
            $nav->base_path = $bp;
        } else {
            $nsl = substr_count($bp, "/");
            $pos = -1;
            while ($nsl > 0 && $pos !== false) {
                $pos = strpos($uri, "/", $pos + 1);
                --$nsl;
            }
            if ($pos !== false) {
                $nav->base_path = substr($uri, 0, $pos + 1);
            } else { // this should never happen
                $nav->base_path = $uri;
                if ($uri === "" || $uri[strlen($uri) - 1] !== "/") {
                    $nav->base_path .= "/";
                }
            }
        }
        $nav->above_base = strlen($nav->base_path) > strlen($uri);

        // php_suffix: ".php" or ""
        if (isset($server["HOTCRP_PHP_SUFFIX"])) {
            $nav->php_suffix = $server["HOTCRP_PHP_SUFFIX"];
        } else if ($nav->unproxied && function_exists("apache_get_modules")) {
            $nav->php_suffix = ".php";
        }

        // separate page and path
        $nbp = strlen($nav->base_path);
        $uri_suffix = (string) substr($uri, min($nbp, strlen($uri)));
        if ($pct) {
            $uri_suffix = self::easy_urldecode($uri_suffix);
        }
        if (($n = strpos($uri_suffix, "/")) === false) {
            $n = strlen($uri_suffix);
        }
        if ($n === 0) {
            $nav->raw_page = "";
            $nav->page = "index";
            $nav->path = "";
        } else {
            $nav->raw_page = substr($uri_suffix, 0, $n);
            $nav->page = $nav->raw_page;
            $nav->path = substr($uri_suffix, $n);
            $nav->apply_php_suffix();
        }

        // compute base_path_relative
        $path_slash = substr_count($nav->path, "/");
        if ($path_slash > 0) {
            $nav->base_path_relative = str_repeat("../", $path_slash);
        } else if ($nav->raw_page === "") {
            $nav->base_path_relative = $nav->base_path;
        } else {
            $nav->base_path_relative = "";
        }

        // site_path is initially base_path
        $nav->site_path = $nav->base_path;
        $nav->site_path_relative = $nav->base_path_relative;

        return $nav;
    }

    /** @param string $base_uri
     * @param string $path
     * @return NavigationState */
    static function make_base($base_uri, $path = "") {
        // no query or fragment allowed
        $is_https = substr_compare($base_uri, "https://", 0, 8, true) === 0;
        if (strpos($base_uri, "?") !== false
            || strpos($base_uri, "#") !== false
            || (!$is_https
                && substr_compare($base_uri, "http://", 0, 7, true) !== 0)) {
            throw new Exception("invalid \$base_uri");
        }
        if (!str_ends_with($base_uri, "/")) {
            $base_uri .= "/";
            if (str_starts_with($path, "/")) {
                $path = substr($path, 1);
            }
        }
        // protocol, host
        $nav = new NavigationState;
        $nav->protocol = $is_https ? "https://" : "http://";
        $plen = strlen($nav->protocol);
        $slash = strpos($base_uri, "/", $plen);
        if ($slash === false) {
            $slash = strlen($base_uri);
        }
        $nav->server = substr($base_uri, 0, $slash);
        if (($colon = strpos($nav->server, ":", $plen)) === false) {
            $nav->host = substr($nav->server, $plen);
        } else {
            $nav->host = substr($nav->server, $plen, $colon - $plen);
        }
        // base path
        if ($slash === strlen($base_uri)) {
            $nav->base_path = "/";
        } else {
            $nav->base_path = substr($base_uri, $slash);
        }
        $nav->base_path_relative = $nav->site_path = $nav->site_path_relative = $nav->base_path;
        // rest
        if (($n = strpos($path, "/")) === false) {
            $n = strlen($path);
        }
        if ($n === 0) {
            $nav->raw_page = "";
            $nav->page = "index";
            $nav->path = "";
        } else {
            $nav->raw_page = substr($path, 0, $n);
            $nav->page = $nav->raw_page;
            $nav->path = substr($path, $n);
        }
        $nav->query = "";
        $nav->request_uri = $nav->base_path . $nav->path;
        return $nav;
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
     * @param ?string $ref
     * @return string
     * @deprecated */
    function make_absolute($url, $ref = null) {
        return $this->resolve($url, $ref);
    }

    /** @param string $url
     * @param ?string $ref
     * @return string */
    function resolve($url, $ref = null) {
        $up = parse_url($url);
        if (isset($up["scheme"])) {
            return $url;
        } else if (isset($up["host"])) {
            return $this->protocol . substr($url, 2);
        } else if (str_starts_with($url, "/")) {
            return $this->server . $url;
        }
        // find reference
        if ($ref === null) {
            $ref = $this->request_uri;
            $slash = max(strlen($ref) - strlen($this->query) - 1, 0);
        } else {
            $slash = strlen($ref) - 1;
        }
        // merge: drop last component in $ref
        if ($url !== "" && $url[0] !== "?" && $url[0] !== "#"
            && $slash > 0 && $ref[$slash] !== "/") {
            $slash = strrpos($ref, "/", $slash - strlen($ref) - 1) ? : 0;
        }
        // partial remove_dot_segments at beginning of $url
        while ($url !== "" && $url[0] === ".") {
            if (str_starts_with($url, "../")) {
                if ($slash > 0) {
                    $slash = strrpos($ref, "/", $slash - strlen($ref) - 1) ? : 0;
                }
                $url = substr($url, 3);
            } else if (str_starts_with($url, "./")) {
                $url = substr($url, 2);
            } else {
                break;
            }
        }
        $prefix = $slash > 0 ? substr($ref, 0, $slash + 1) : "/";
        return "{$this->server}{$prefix}{$url}";
    }

    /** @param array $purl
     * @param string $server
     * @return string */
    static function resolve_relative_server($purl, $server) {
        if (!isset($purl["host"])) {
            return $server;
        }
        $host = $purl["host"];
        if (isset($purl["user"])) {
            $user = $purl["user"];
            if (isset($purl["pass"])) {
                $user .= ":" . $purl["pass"];
            }
            $host = "{$user}@{$host}";
        }
        if (isset($purl["scheme"])) {
            $scheme = strtolower($purl["scheme"]);
        } else {
            $scheme = substr($server, 0, strpos($server, ":"));
        }
        if (($port = $purl["port"] ?? null) !== null
            && ($scheme !== "http" || $port !== 80)
            && ($scheme !== "https" || $port !== 443)) {
            return "{$scheme}://{$host}:{$port}";
        } else {
            return "{$scheme}://{$host}";
        }
    }

    /** @param string $path
     * @param string $ref
     * @return string */
    static function resolve_relative_path($path, $ref) {
        if (!str_starts_with($path, "/")) {
            if (($slash = strrpos($ref, "/") ? : 0) > 0) {
                $path = substr($ref, 0, $slash + 1) . $path;
            } else {
                $path = "/{$path}";
            }
        }
        $pos = 0;
        while (($slashdot = strpos($path, "/.", $pos)) !== false) {
            $dot = $slashdot + 1;
            $sfxlen = strlen($path) - $dot;
            if ($sfxlen === 1) {
                $path = substr($path, 0, $dot);
                break;
            }
            $ch = $path[$dot + 1];
            if ($ch === "/") {
                $path = substr_replace($path, "", $dot, 2);
            } else if ($ch !== "." || ($sfxlen > 2 && $path[$dot + 2] !== "/")) {
                $pos = $dot + 1;
            } else if ($slashdot === 0) {
                $path = substr($path, $dot + 2);
                if ($path === "") {
                    $path = "/";
                }
            } else {
                $rpos = $sfxlen === 2 ? $dot + 2 : $dot + 3;
                $slash = strrpos($path, "/", $dot - 2 - strlen($path)) ? : 0;
                $path = substr_replace($path, "", $slash + 1, $rpos - $slash - 1);
                $pos = $slash;
            }
        }
        return $path;
    }

    /** @param ?string $url
     * @param string $ref
     * @return ?string
     * @deprecated */
    function make_absolute_under($url, $ref) {
        return $this->resolve_within($url, $ref);
    }

    /** @param ?string $url
     * @param string $ref
     * @return ?string */
    function resolve_within($url, $ref = "") {
        // Like `resolve`, but result is constrained to live under
        // `$ref` (returns null if that wouldn’t hold).
        // Relative paths in `$ref` are parsed relative to this server.
        // Relative paths in `$url` are parsed relative to `$ref`.
        // Note that `$ref` should generally end with a slash; if it does
        // not, its last component will be dropped while resolving `$url`
        // (which is how relative URIs are resolved), so the prefix won't
        // generally match.
        // If the return value is non-null, then it has the same server
        // as the resolved `$ref`, and the path component of `$ref` (with
        // slash termination) is its prefix. Query and fragment components
        // of `$ref` are ignored.

        if ($url === null) {
            return null;
        }

        $rp = parse_url($ref);
        assert($rp !== false);
        $rp_server = self::resolve_relative_server($rp, $this->server);
        if (isset($rp["host"])) {
            if (($rp_path = $rp["path"] ?? "") === "") {
                $rp_path = "/";
            }
        } else {
            $rp_path = $rp["path"] ?? "";
            if (!str_starts_with($rp_path, "/")) {
                $my_path = $this->request_uri;
                if ($this->query !== "") {
                    $my_path = substr($my_path, 0, -strlen($this->query));
                }
                $rp_path = self::resolve_relative_path($rp_path, $my_path);
            }
        }

        $up = parse_url($url);
        if ($up === false) {
            return null;
        }
        $up_server = self::resolve_relative_server($up, $rp_server);

        // compare server components
        if ($up_server !== $rp_server) {
            // XXX host, scheme comparisons should be case insensitive
            // but this is good enough
            return null;
        }

        // merge + remove_dot_segments, but resulting path is always nonempty
        $path = $up["path"] ?? "";
        if (strpos($path, "//") !== false) { // reject empty segments
            return null;
        }
        $path = self::resolve_relative_path($path, $rp_path);

        // reject if not under $rp_path
        $rp_pathlen = strlen($rp_path) - (str_ends_with($rp_path, "/") ? 1 : 0);
        if (strlen($path) === $rp_pathlen) {
            $path .= "/";
        }
        if (!str_starts_with($path, $rp_path) || $path[$rp_pathlen] !== "/") {
            return null;
        }

        $url = $rp_server . $path;
        if (isset($up["query"])) {
            $url .= "?" . $up["query"];
        }
        if (isset($up["fragment"])) {
            $url .= "#" . $up["fragment"];
        }
        return $url;
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
            if (PHP_SAPI !== "cli") {
                self::$s = NavigationState::make_server($_SERVER);
            } else {
                self::$s = new NavigationState;
            }
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
