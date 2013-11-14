<?php
// redirect.php -- HotCRP redirection helper functions
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function request_absolute_uri_base() {
    if (!@$_SERVER["HTTP_HOST"] || !@$_SERVER["SERVER_PORT"]
        || !@$_SERVER["REQUEST_URI"])
        return false;

    if (@$_SERVER["HTTPS"] && $_SERVER["HTTPS"] != "off") {
	$host = "https://" . $_SERVER["HTTP_HOST"];
	if (($port = defval($_SERVER, "SERVER_PORT", 443)) != 443)
	    $host .= ":" . $port;
    } else {
	$host = "http://" . $_SERVER["HTTP_HOST"];
	if (($port = defval($_SERVER, "SERVER_PORT", 80)) != 80)
	    $host .= ":" . $port;
    }

    return $host . preg_replace('_\A([^\?\#]*).*\z_', '\1',
                                $_SERVER["REQUEST_URI"]);
}

function request_trim_path_info($uri) {
    if (!isset($_SERVER["PATH_INFO"]) || $_SERVER["PATH_INFO"] == "")
        return $uri;
    $path = $_SERVER["PATH_INFO"];
    if (str_ends_with($uri, $path))
        return substr($uri, 0, strlen($uri) - strlen($path));
    $uritail = "";
    while (($pathpos = strrpos($path, "/")) !== false
           && ($uripos = strrpos($uri, "/")) !== false) {
        $uritail = substr($uri, $uripos) . $uritail;
        $path = substr($path, 0, $pathpos);
        $uri = substr($uri, 0, $uripos);
    }
    if ($path == "" && urldecode($uritail) == $_SERVER["PATH_INFO"])
        return $uri;
    else
        return $uri . $uritail;
}

function request_absolute_uri_dir() {
    $uri = request_trim_path_info(request_absolute_uri_base());
    return preg_replace('_\A(.*?)[^/]*\z_', '\1', $uri);
}

function request_script_base() {
    if (!@$_SERVER["REQUEST_URI"])
        return false;
    $uri = request_trim_path_info(preg_replace('_\A([^\?\#]*).*\z_', '\1',
                                               $_SERVER["REQUEST_URI"]));
    if (preg_match('_(?:\A|/)([^/]*?)(?:\.php|)\z_', $uri, $m))
        return $m[1] == "" ? "index" : $m[1];
    else
        return false;
}

function go($url = false) {
    global $ConfSiteBase, $ConfSiteSuffix;
    if ($url === false)
	$url = "${ConfSiteBase}index$ConfSiteSuffix";

    // The URL is often relative; make it absolute if possible.
    if (!preg_match('_\A[a-z]+://_', $url)
	&& ($absuri = request_absolute_uri_base())) {
	if (substr($url, 0, 1) == "/")
	    $url = preg_replace('|\A(.*?//[^/]+).*|', '\1', $absuri) . $url;
	else {
	    $pfx = preg_replace('|[^/]+\z|', "", $absuri);
	    while (strlen($url) >= 3 && substr($url, 0, 3) == "../") {
		$pfx = preg_replace('|\A(.*?)/+[^/]+/*\z|', '\1', $pfx) . "/";
		$url = substr($url, 3);
	    }
	    $url = $pfx . $url;
	}
    }

    // Might have an HTML-encoded URL; decode at least &amp;.
    $url = str_replace("&amp;", "&", $url);

    if (preg_match('|\A[a-z]+://|', $url))
	header("Location: $url");

    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Strict//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\">\n";
    echo "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"en\" lang=\"en\">
<head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Style-Type\" content=\"text/css\" />
<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />
<title>Redirection</title>
<script type='text/javascript'>
  location=\"$url\";
</script></head><body>
<p>You should be redirected <a href='", htmlspecialchars($url), "'>to here</a>.</p>
</body></html>\n";
    exit();
}

function error_go($url, $message) {
    global $Conf;
    $Conf->errorMsg($message);
    go($url);
}

function ensure_session() {
    global $Opt;
    if (!session_id()) {
        session_name($Opt["sessionName"]);
        session_cache_limiter("");
        session_start();
    }
}

function post_value() {
    ensure_session();
    if (($sid = session_id())) {
	if (strlen($sid) > 16)
	    $sid = substr($sid, 8);
	$sid = substr($sid, 0, 8);
    } else
	$sid = "1";
    return urlencode($sid);
}

function check_post() {
    return isset($_REQUEST["post"]) && $_REQUEST["post"] == post_value();
}
