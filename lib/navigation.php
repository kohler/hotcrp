<?php
// navigation.php -- HotCRP navigation helper functions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class NavigationState {
    public $protocol;           // "http://" or "https://"
    public $host;
    public $server;             // "PROTOCOL://HOST[:PORT]"
    public $site_path;          // "/PATH", does not include $page, ends in /
    public $page;               // Name of page
    public $path;
    public $query;
    public $site_path_relative;
    public $php_suffix;
    public $request_uri;

    // server variables:
    //   required: SERVER_PORT, SCRIPT_FILENAME, SCRIPT_NAME, REQUEST_URI
    //   optional: HTTP_HOST, SERVER_NAME, HTTPS, SERVER_SOFTWARE

    function __construct($server, $index_name = "index") {
        if (!$server)
            return;

        $this->host = null;
        if (isset($server["HTTP_HOST"]))
            $this->host = $server["HTTP_HOST"];
        if (!$this->host && isset($server["SERVER_NAME"]))
            $this->host = $server["SERVER_NAME"];

        if (isset($server["HTTPS"]) && $server["HTTPS"] != "off")
            list($x, $xport) = array("https://", 443);
        else
            list($x, $xport) = array("http://", 80);
        $this->protocol = $x;
        $x .= $this->host ? : "localhost";
        if (($port = $server["SERVER_PORT"])
            && $port != $xport
            && strpos($x, ":", 6) === false)
            $x .= ":" . $port;
        $this->server = $x;

        // detect $site_path
        $sfilename = $server["SCRIPT_FILENAME"]; // pathname
        $sfile = substr($sfilename, strrpos($sfilename, "/") + 1);

        $sname = $server["SCRIPT_NAME"]; // URL-decoded
        $sname_slash = strrpos($sname, "/");
        if (substr($sname, $sname_slash + 1) !== $sfile) {
            if ($sname === "" || $sname[strlen($sname) - 1] !== "/")
                $sname .= "/";
            $sname_slash = strlen($sname) - 1;
        }

        $this->request_uri = $uri = $server["REQUEST_URI"]; // URL-encoded
        if (substr($uri, 0, $sname_slash) === substr($sname, 0, $sname_slash))
            $uri_slash = $sname_slash;
        else {
            // URL-encoded prefix != URL-decoded prefix
            for ($nslash = substr_count(substr($sname, 0, $sname_slash), "/"),
                 $uri_slash = 0;
                 $nslash > 0; --$nslash)
                $uri_slash = strpos($uri, "/", $uri_slash + 1);
        }
        if ($uri_slash === false || $uri_slash > strlen($uri))
            $uri_slash = strlen($uri);

        $this->site_path = substr($uri, 0, $uri_slash) . "/";

        // separate $page, $path, $query
        $uri_suffix = substr($uri, $uri_slash);
        // Semi-URL-decode $uri_suffix, only decoding safe characters.
        // (This is generally already done for us but just to be safe.)
        $uri_suffix = preg_replace_callback('/%[2-7][0-9a-f]/i', function ($m) {
            $x = urldecode($m[0]);
            if (ctype_alnum($x) || strpos("._,-=@~", $x) !== false)
                return $x;
            else
                return $m[0];
        }, $uri_suffix);
        preg_match(',\A(/[^/\?\#]*|)([^\?\#]*)(.*)\z,', $uri_suffix, $m);
        if ($m[1] !== "" && $m[1] !== "/")
            $this->page = substr($m[1], 1);
        else
            $this->page = $index_name;
        if (($pagelen = strlen($this->page)) > 4
            && substr($this->page, $pagelen - 4) === ".php")
            $this->page = substr($this->page, 0, $pagelen - 4);
        $this->path = $m[2];
        $this->query = $m[3];

        // detect $site_path_relative
        $path_slash = substr_count($this->path, "/");
        if ($path_slash)
            $this->site_path_relative = str_repeat("../", $path_slash);
        else if ($uri_slash >= strlen($uri))
            $this->site_path_relative = $this->site_path;
        else
            $this->site_path_relative = "";

        $this->php_suffix = ".php";
        if ((isset($server["SERVER_SOFTWARE"])
             && substr($server["SERVER_SOFTWARE"], 0, 5) === "nginx")
            || (function_exists("apache_get_modules")
                && array_search("mod_rewrite", apache_get_modules()) !== false))
            $this->php_suffix = "";
    }

    function self() {
        return $this->server . $this->site_path . $this->page . $this->path . $this->query;
    }

    function site_absolute($downcase_host = false) {
        $x = $downcase_host ? strtolower($this->server) : $this->server;
        return $x . $this->site_path;
    }

    function siteurl($url = null) {
        $x = $this->site_path_relative;
        if (!$url)
            return $x;
        else if (substr($url, 0, 5) !== "index" || substr($url, 5, 1) === "/")
            return $x . $url;
        else
            return ($x ? : $this->site_path) . substr($url, 5);
    }

    function siteurl_path($url = null) {
        $x = $this->site_path;
        if (!$url)
            return $x;
        else if (substr($url, 0, 5) !== "index" || substr($url, 5, 1) === "/")
            return $x . $url;
        else
            return $x . substr($url, 5);
    }

    function set_siteurl($url) {
        if ($url !== "" && $url[strlen($url) - 1] !== "/")
            $url .= "/";
        return ($this->site_path_relative = $url);
    }

    function path_component($n, $decoded = false) {
        if ($this->path !== "") {
            $p = explode("/", substr($this->path, 1));
            if ($n + 1 < count($p) || ($n + 1 == count($p) && $p[$n] !== ""))
                return $decoded ? urldecode($p[$n]) : $p[$n];
        }
        return null;
    }

    function path_suffix($n) {
        if ($this->path !== "") {
            $p = 0;
            while ($n > 0 && ($p = strpos($this->path, "/", $p + 1)))
                --$n;
            if ($p !== false)
                return substr($this->path, $p);
        }
        return "";
    }

    function make_absolute($url) {
        if ($url === false)
            return $this->server . $this->site_path;
        preg_match(',\A((?:https?://[^/]+)?)(/*)((?:[.][.]/)*)(.*)\z,i', $url, $m);
        if ($m[1] !== "")
            return $url;
        else if (strlen($m[2]) > 1)
            return $this->protocol . substr($url, 2);
        else if ($m[2] === "/")
            return $this->server . $url;
        else {
            $site = substr($this->request_uri, 0, strlen($this->request_uri) - strlen($this->query));
            $site = preg_replace(',/[^/]+\z,', "/", $site);
            for (; $m[3]; $m[3] = substr($m[3], 3))
                $site = preg_replace(',/[^/]+/\z,', "/", $site);
            return $this->server . $site . $m[3] . $m[4];
        }
    }
}

class Navigation {
    private static $s;

    static function analyze($index_name = "index") {
        if (PHP_SAPI != "cli")
            self::$s = new NavigationState($_SERVER, $index_name);
        else
            self::$s = new NavigationState(null);
    }

    static function get() {
        return self::$s;
    }

    static function self() {
        return self::$s->self();
    }

    static function host() {
        return self::$s->host;
    }

    static function site_absolute($downcase_host = false) {
        return self::$s->site_absolute($downcase_host);
    }

    static function site_path() {
        return self::$s->site_path;
    }

    static function siteurl($url = null) {
        return self::$s->siteurl($url);
    }

    static function siteurl_path($url = null) {
        return self::$s->siteurl_path($url);
    }

    static function set_siteurl($url) {
        return self::$s->set_siteurl($url);
    }

    static function page() {
        return self::$s->page;
    }

    static function path() {
        return self::$s->path;
    }

    static function path_component($n, $decoded = false) {
        return self::$s->path_component($n, $decoded);
    }

    static function path_suffix($n) {
        return self::$s->path_suffix($n);
    }

    static function set_page($page) {
        return (self::$s->page = $page);
    }

    static function set_path($path) {
        return (self::$s->path = $path);
    }

    static function php_suffix() {
        return self::$s->php_suffix;
    }

    static function make_absolute($url) {
        return self::$s->make_absolute($url);
    }

    static function redirect($url) {
        $url = self::make_absolute($url);
        // Might have an HTML-encoded URL; decode at least &amp;.
        $url = str_replace("&amp;", "&", $url);

        if (preg_match('|\A[a-z]+://|', $url))
            header("Location: $url");

        echo "<!DOCTYPE html><html lang=\"en\"><head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />
<title>Redirection</title>
<script>location=", json_encode($url), ";</script></head>
<body>
<p>You should be redirected <a href=\"", htmlspecialchars($url), "\">to here</a>.</p>
</body></html>\n";
        exit();
    }

    static function redirect_site($site_url) {
        self::redirect(self::site_absolute() . $site_url);
    }

    static function redirect_http_to_https($allow_http_if_localhost = false) {
        if (self::$s->protocol == "http://"
            && (!$allow_http_if_localhost
                || ($_SERVER["REMOTE_ADDR"] !== "127.0.0.1"
                    && $_SERVER["REMOTE_ADDR"] !== "::1")))
            self::redirect("https://" . (self::$s->host ? : "localhost")
                           . self::siteurl_path(self::$s->page . self::$s->php_suffix . self::$s->path . self::$s->query));
    }
}

Navigation::analyze();
