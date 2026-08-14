<?php
// oauthclientdocument.php -- HotCRP OAuth client ID metadata documents
// Copyright (c) 2026 Eddie Kohler; see LICENSE.

namespace HotCRP;
use Conf, UnicodeHelper;
use Uri\Rfc3986\Uri;

/** Client ID metadata document, as defined by
 * draft-ietf-oauth-client-id-metadata-document-00 (and used by the Model
 * Context Protocol as of 2026-07-28).
 *
 * A client using this mechanism identifies itself with an HTTPS URL that
 * resolves to a JSON document containing its OAuth client metadata. No
 * registration step is required: the authorization server fetches the
 * document when it sees a URL-shaped `client_id`. Such clients are always
 * public clients—the document may not contain a `client_secret`—so PKCE is
 * required. */
class OAuthClientDocument {
    /** @var Conf */
    private $conf;
    /** @var string */
    public $client_id;
    /** @var \Uri\Rfc3986\Uri */
    private $_client_uri;
    /** @var ?object */
    public $document;
    /** @var ?string */
    public $error;
    /** @var string */
    private $content;

    /** Maximum size of a client ID metadata document. The specification
     * recommends a limit of 5KB; allow some slack for unknown metadata. */
    const MAXSIZE = 16384;
    /** Maximum time to spend fetching a client ID metadata document. */
    const TIMEOUT = 10;
    /** Maximum length of a client name as displayed to the user. */
    const MAXNAME = 120;

    /** Testing hook: if set, called instead of an actual HTTP request.
     * @var ?callable(string):(?array{int,?string,string}) */
    static public $fetch_function;

    /** Special-use IPv4 address ranges (RFC 6890 and successors).
     * @var list<string> */
    static private $special_ipv4 = [
        "0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8",
        "169.254.0.0/16", "172.16.0.0/12", "192.0.0.0/24", "192.0.2.0/24",
        "192.88.99.0/24", "192.168.0.0/16", "198.18.0.0/15",
        "198.51.100.0/24", "203.0.113.0/24", "224.0.0.0/4", "240.0.0.0/4"
    ];
    /** Special-use IPv6 address ranges.
     * @var list<string> */
    static private $special_ipv6 = [
        // `::/96` covers `::`, `::1`, and the deprecated IPv4-compatible
        // addresses, such as `::127.0.0.1`; IPv4-mapped addresses do not
        // match it and are unwrapped before comparison
        "::/96", "64:ff9b::/96", "64:ff9b:1::/48", "100::/64",
        "2001::/23", "2001:db8::/32", "2002::/16", "fc00::/7", "fe80::/10",
        "ff00::/8"
    ];


    /** Return true if this installation can check client identifier URLs.
     *
     * URL syntax is checked by parsing, using the PHP 8.5 URI extension.
     * `Uri\Rfc3986\Uri` accepts only what RFC 3986 allows, so parsing rejects
     * control characters, spaces, non-ASCII bytes, misplaced brackets, and
     * malformed percent-encodings; it also leaves the URL as given, so what
     * is checked here is what is later fetched. `parse_url` can do neither—it
     * rewrites control characters to `_` and accepts nearly anything.
     * @return bool */
    static function supported() {
        // do not autoload: `SiteLoader` would try to `require` a file
        return class_exists(Uri::class, false);
    }


    /** @param string $client_id
     * @param \Uri\Rfc3986\Uri $uri */
    private function __construct(Conf $conf, $client_id, $uri) {
        $this->conf = $conf;
        $this->client_id = $client_id;
        $this->_client_uri = $uri;
    }

    /** @return ?OAuthClientDocument */
    static function try_make(Conf $conf, $client_id) {
        if (!str_starts_with($client_id, "https://")
            && (!str_starts_with($client_id, "http://")
                || $conf->opt("oAuthMetadataDocumentClients") !== "insecure")) {
            return null;
        }
        // check URL parsing availability
        if (!self::supported()) {
            return null;
        }
        // validate client_id
        if (strlen($client_id) > 1024
            || !($uri = Uri::parse($client_id))
            || ($uri->getRawPath() ?? "") === ""
            || strlen($uri->getRawPath()) > strlen($uri->getPath())  // catches `.` or `..` segments
            || $uri->getRawUserInfo() !== null
            || $uri->getRawFragment() !== null
            || ($host = $uri->getHost() ?? "") === ""
            || ($client_id[4] === "s"
                ? self::invalid_https_host($host)
                : $host !== "localhost" && $host !== "127.0.0.1" && $host !== "[::1]")) {
            return null;
        }
        return new OAuthClientDocument($conf, $client_id, $uri);
    }

    /** @param string $host
     * @return bool */
    /** @param string $host
     * @return bool */
    static private function invalid_https_host($host) {
        return !!preg_match('/\A(?:|.*\.)(?:(?:0x[0-9a-f]+|\d+)|local|localhost|internal|home\.arpa|\[.*?\])\.?\z/i', $host);
    }


    /** @param object|string|list<string> $cx
     * @return bool */
    /** Return true if this document's client identifier is one that component
     * `$cx` accepts. A `client_id_match` setting, if present, restricts those
     * identifiers; it is a glob pattern or a list of glob patterns.
     * @param object $cx
     * @return bool */
    function matches($cx) {
        $match = $cx->client_id_match ?? null;
        if ($match === null) {
            return true;
        }
        foreach (is_string($match) ? [$match] : $match as $pat) {
            if ($this->_matches_one($pat))
                return true;
        }
        return false;
    }

    /** @param string $pat
     * @return bool */
    private function _matches_one($pat) {
        if ($pat === $this->client_id) {
            return true;
        } else if (strpos($pat, "*") === false) {
            return false;
        } else if ($pat === "*") {
            return true;
        }
        $s = $this->_client_uri->getScheme();
        $slen = strlen($s);
        if (substr_compare($pat, $s, 0, $slen, true) !== 0
            || substr_compare($pat, "://", $slen, 3) !== 0) {
            return false;
        }
        $pathpos = $slen + 3 + strcspn($pat, "/?", $slen + 3);
        $querypos = $pathpos + strcspn($pat, "?", $pathpos);
        $pathp = substr($pat, $slen + 3, $pathpos - $slen - 3);
        $patpath = substr($pat, $pathpos, $querypos - $pathpos);
        $patquery = substr($pat, $querypos);
        $pattern = '[a-z]*+://' . str_replace('\*', '[^\/?]*', preg_quote($pathp));
        if ($patquery !== "") {
            $pattern .= str_replace('\*', '[^?]*', preg_quote($patpath))
                // `?*` means “query optional”, so the `?` itself is optional;
                // a pattern that names a parameter, such as `?v=*`, still
                // requires a query
                . ($patquery === "?*" ? '(?:\?.*)?' : str_replace('\*', '.*', preg_quote($patquery)));
        } else {
            $pattern .= str_replace('\*', str_ends_with($patpath, "*") ? '.*' : '[^?]*', preg_quote($patpath));
        }
        return !!preg_match("\x01\\A{$pattern}\\z\x01", $this->client_id);
    }


    /** @param string $message
     * @return false */
    function set_error($message) {
        $this->error = $this->error ?? $message;
        return false;
    }

    /** @return ?string */
    function error_message() {
        return $this->error;
    }

    /** @return non-empty-string */
    function host() {
        return $this->_client_uri->getRawHost();
    }

    /** Return true if `$ip` belongs to a special-use address range.
     * @param string $ip
     * @return bool */
    static function special_use_address($ip) {
        $a = @inet_pton($ip);
        if ($a === false) {
            return true;
        }
        if (strlen($a) === 16
            && str_starts_with($a, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF")) {
            $a = substr($a, 12); // IPv4-mapped IPv6 address
        }
        $ranges = strlen($a) === 4 ? self::$special_ipv4 : self::$special_ipv6;
        foreach ($ranges as $range) {
            if (self::address_in_range($a, $range))
                return true;
        }
        return false;
    }

    /** @param string $a binary address
     * @param string $range address range in CIDR notation
     * @return bool */
    static private function address_in_range($a, $range) {
        $slash = strpos($range, "/");
        $net = @inet_pton(substr($range, 0, $slash));
        if ($net === false || strlen($net) !== strlen($a)) {
            return false;
        }
        $bits = (int) substr($range, $slash + 1);
        $nbytes = $bits >> 3;
        if ($nbytes > 0 && substr_compare($a, $net, 0, $nbytes) !== 0) {
            return false;
        }
        if (($bits & 7) !== 0) {
            $mask = 0xFF << (8 - ($bits & 7)) & 0xFF;
            if ((ord($a[$nbytes]) & $mask) !== (ord($net[$nbytes]) & $mask))
                return false;
        }
        return true;
    }

    /** Fetch and validate the client ID metadata document.
     * On success, `$this->document` contains the client’s metadata.
     * @return bool */
    function load() {
        if ($this->document) {
            return true;
        }
        $r = self::$fetch_function ? (self::$fetch_function)($this->client_id) : $this->fetch();
        if ($r === null) {
            return $this->set_error("Cannot retrieve authorization client information");
        }
        return $this->check_document($r[0], $r[1], $r[2]);
    }

    /** @return ?array{int,?string,string} */
    private function fetch() {
        $this->content = "";
        $curlh = curl_init();
        curl_setopt($curlh, CURLOPT_URL, $this->client_id);
        curl_setopt($curlh, CURLOPT_HTTPGET, true);
        curl_setopt($curlh, CURLOPT_HTTPHEADER, ["Accept: */*"]);
        // never follow redirects: the document must be served by the client ID URL
        curl_setopt($curlh, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curlh, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curlh, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($curlh, CURLOPT_WRITEFUNCTION, function ($ch, $s) {
            $this->content .= $s;
            return strlen($this->content) > self::MAXSIZE ? 0 : strlen($s);
        });
        if ($this->conf->opt("oAuthMetadataDocumentClients") !== "insecure"
            && !self::setopt_address_check($curlh)) {
            $this->set_error("Authorization client documents require libcurl 7.80 or later");
            return null;
        }
        curl_exec($curlh);
        $errno = curl_errno($curlh);
        $status = curl_getinfo($curlh, CURLINFO_RESPONSE_CODE);
        $ctype = curl_getinfo($curlh, CURLINFO_CONTENT_TYPE);
        $content = $this->content;
        $curlh = null;

        if ($errno === CURLE_ABORTED_BY_CALLBACK) {
            $this->set_error("Authorization client identifier has an unacceptable host");
            return null;
        } else if ($errno === CURLE_WRITE_ERROR || strlen($content) > self::MAXSIZE) {
            $this->set_error("Authorization client information too large");
            return null;
        } else if ($errno !== 0 || $status === 0) {
            $this->set_error("Cannot retrieve authorization client information");
            return null;
        }
        return [$status, is_string($ctype) ? $ctype : null, $content];
    }

    /** Refuse to talk to special-use addresses.
     *
     * The check must apply to the address cURL actually connected to, not to
     * one we resolved ourselves: resolving separately would leave a window in
     * which a name that checked out could resolve to something else for the
     * real connection (DNS rebinding). `CURLOPT_PREREQFUNCTION` runs after the
     * connection is established and before the request is sent, so aborting
     * there sends nothing to the peer.
     * @param \CurlHandle $curlh
     * @return bool */
    static private function setopt_address_check($curlh) {
        if (!defined("CURLOPT_PREREQFUNCTION")) {
            return false; // libcurl < 7.80
        }
        return curl_setopt($curlh, CURLOPT_PREREQFUNCTION,
            function ($ch, $primary_ip, $local_ip, $primary_port, $local_port) {
                return self::special_use_address($primary_ip)
                    ? CURL_PREREQFUNC_ABORT : CURL_PREREQFUNC_OK;
            });
    }

    /** @param int $status
     * @param ?string $content_type
     * @param string $content
     * @return bool */
    function check_document($status, $content_type, $content) {
        if ($status !== 200) {
            return $this->set_error("Authorization client information returned HTTP status {$status}");
        }
        if ($content_type !== null
            && $content_type !== "application/json"
            && !preg_match('/\Aapplication\/(?:[\w.+-]*\+)?json\s*+(?:;|\z)/i', $content_type)) {
            return $this->set_error("Authorization client information is not JSON");
        }
        $docj = json_decode($content);
        if (!is_object($docj)) {
            return $this->set_error("Authorization client information is not a JSON object");
        }

        // `client_id` must match the document URL exactly
        if (($docj->client_id ?? null) !== $this->client_id) {
            return $this->set_error("Authorization client information does not match its client identifier");
        }
        // clients using this mechanism are public clients
        if (isset($docj->client_secret)
            || isset($docj->client_secret_expires_at)) {
            return $this->set_error("Authorization client information must not contain a client secret");
        }
        // (`$team` is remote input of unknown type; do not interpolate it raw)
        $team = $docj->token_endpoint_auth_method ?? "none";
        if ($team !== "none") {
            $teamt = is_string($team) ? "`" . UnicodeHelper::utf8_truncate($team, 40) . "`" : "it requests";
            return $this->set_error("Authorization client requires unsupported authentication method {$teamt}");
        }
        // required grant and response types
        if (isset($docj->grant_types)
            && (!is_array($docj->grant_types)
                || !in_array("authorization_code", $docj->grant_types, true))) {
            return $this->set_error("Authorization client does not support the `authorization_code` grant");
        }
        if (isset($docj->response_types)
            && (!is_array($docj->response_types)
                || !in_array("code", $docj->response_types, true))) {
            return $this->set_error("Authorization client does not support the `code` response type");
        }

        // redirect URIs
        if (!is_array($docj->redirect_uris ?? null)
            || empty($docj->redirect_uris)
            || !is_list($docj->redirect_uris)) {
            return $this->set_error("Authorization client information does not list redirect URIs");
        }
        // Keep up to 32 URIs this site can accept and drop the rest, but only
        // check the first 100.
        $redirect_uris = [];
        foreach ($docj->redirect_uris as $i => $uri) {
            // clients using this mechanism are public clients
            if (is_string($uri)
                && OAuthClient::check_redirect_uri($uri, OAuthClient::VALIDATION_DYNAMIC)) {
                $redirect_uris[] = $uri;
            }
            if (count($redirect_uris) >= 32 || $i >= 100) {
                break;
            }
        }
        if (empty($redirect_uris)) {
            return $this->set_error("Authorization client information lists no usable redirect URI");
        }

        $d = (object) [
            "client_id" => $this->client_id,
            "redirect_uris" => $redirect_uris
        ];
        if (is_string($docj->client_name ?? null)) {
            $d->client_name = UnicodeHelper::utf8_truncate(simplify_whitespace($docj->client_name), self::MAXNAME);
        }
        // `client_uri` is shown to the user as a link
        if (is_string($docj->client_uri ?? null)
            && strlen($docj->client_uri) <= 1024
            && ($curi = Uri::parse($docj->client_uri)) !== null
            && ($curi->getScheme() === "https" || $curi->getScheme() === "http")
            && ($curi->getRawHost() ?? "") !== "") {
            $d->client_uri = $docj->client_uri;
        }
        if (is_string($docj->scope ?? null)
            && strlen($docj->scope) <= 1024) {
            $d->scope = trim($docj->scope);
        }
        $this->document = $d;
        return true;
    }
}
