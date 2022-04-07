<?php
// dkimsigner.php -- HotCRP DKIM signatures
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DKIMSigner {
    /** @var OpenSSLAsymmetricKey */
    private $pkey;
    /** @var string */
    private $domain;
    /** @var string */
    private $selector;
    /** @var ?string */
    private $identity;
    /** @var list<string> */
    private $h;

    /** @param OpenSSLAsymmetricKey $pkey
     * @param string $domain
     * @param string $selector */
    function __construct($pkey, $domain, $selector) {
        $this->pkey = $pkey;
        $this->domain = $domain;
        $this->selector = $selector;
        $this->h = ["mime-version", "from", "to", "subject", "cc", "reply-to"];
    }

    /** @param string $header
     * @return $this */
    function add_signed_header($header) {
        $header = strtolower($header);
        if (!in_array($header, $this->h)) {
            $this->h[] = $header;
        }
        return $this;
    }

    /** @param string $identity
     * @return $this */
    function set_identity($identity) {
        $this->identity = $identity;
        return $this;
    }

    /** @param string $s
     * @return string */
    private function _canonicalize_header_value_relaxed($s) {
        $s = preg_replace('/[ \r\n\t]+/', " ", $s);
        $b = 0;
        $e = strlen($s);
        if ($b !== $e && $s[$b] === " ") {
            ++$b;
        }
        while ($b !== $e && ($s[$e-1] === "\n" || $s[$e-1] === "\r" || $s[$e-1] === " ")) {
            --$e;
        }
        return substr($s, $b, $e - $b);
    }

    /** @param array<string,string> $headers
     * @return string */
    private function _canonicalize_headers_relaxed($headers) {
        $a = [];
        foreach ($this->h as $k) {
            if (array_key_exists($k, $headers)) {
                $v = $headers[$k];
                $t = $k . ":";
                assert(substr_compare($v, $t, 0, strlen($t), true) === 0);
                $a[] = $t . $this->_canonicalize_header_value_relaxed(substr($v, strlen($k) + 1)) . "\r\n";
            }
        }
        return join("", $a);
    }

    /** @param string $body
     * @return string */
    private function _canonicalize_body_relaxed($body) {
        $body = preg_replace('/[ \t][ \t]+/', " ", $body);
        $body = preg_replace('/ ?\r?\n/', "\r\n", $body);
        if ($body !== "" && !str_ends_with($body, "\r\n")) {
            $body .= "\r\n";
        } else {
            $l = strlen($body);
            while ($l !== 0
                   && ($l === 2 || $body[$l - 3] === "\n")
                   && $body[$l - 2] === "\r"
                   && $body[$l - 1] === "\n") {
                $l -= 2;
            }
            if ($l !== strlen($body)) {
                $body = substr($body, 0, $l);
            }
        }
        return $body;
    }

    /** @param array<string,string> $headers
     * @param string $body
     * @param ?string $eol
     * @return string|false */
    function signature($headers, $body, $eol = null) {
        $eol = $eol ?? "\r\n";
        $b = $this->_canonicalize_body_relaxed($body);
        $bhash = rtrim(chunk_split(base64_encode(hash("sha256", $b, true)), 64, "{$eol} "));
        $a = $this->_canonicalize_headers_relaxed($headers);
        $dkimh = "v=1;a=rsa-sha256;q=dns/txt;s={$this->selector};{$eol} "
            . "t=" . time() . ";c=relaxed/relaxed;d={$this->domain};";
        if ($this->identity !== null) {
            $dkimh .= "i={$this->identity};";
        }
        $dkimh .= "{$eol} h=" . join(":", $this->h) . ";{$eol} bh={$bhash};{$eol} b=";
        $a .= "dkim-signature:" . rtrim($this->_canonicalize_header_value_relaxed($dkimh));
        $signature = "";
        if (openssl_sign($a, $signature, $this->pkey, OPENSSL_ALGO_SHA256)) {
            $ahash = rtrim(chunk_split(base64_encode($signature), 64, "{$eol} "));
            return "DKIM-Signature: {$dkimh}{$ahash}";
        } else {
            return false;
        }
    }

    /** @return ?string */
    function txt_content() {
        $det = openssl_pkey_get_details($this->pkey);
        if ($det
            && ($det["key"] ?? false)
            && preg_match('/\A-+\s*BEGIN\s*PUBLIC\s*KEY\s*-+\r?\n([\sA-Za-z0-9\/+=]+)(?:|-+\s*END\s*PUBLIC\s*KEY\s*-+\s*)\z/s', $det["key"], $m)) {
            return "v=DKIM1;h=sha256;k=rsa;p=" . preg_replace('/\s/', "", $m[1]);
        } else {
            return null;
        }
    }

    /** @param string|array|object $param
     * @return ?DKIMSigner */
    static function make($param) {
        if (is_string($param)) {
            $config = json_decode($param);
        } else if (is_array($param)) {
            $config = (object) $param;
        } else {
            $config = is_object($param) ? $param : null;
        }
        if (!$config
            || !($key = $config->key ?? null)
            || !($domain = $config->domain ?? null)
            || !($selector = $config->selector ?? null)
            || !($pkey = openssl_pkey_get_private($key, $config->passphrase ?? null))) {
            return null;
        }
        $dkims = new DKIMSigner($pkey, $domain, $selector);
        if ($config->identity ?? null) {
            $dkims->set_identity($config->identity);
        }
        return $dkims;
    }
}
