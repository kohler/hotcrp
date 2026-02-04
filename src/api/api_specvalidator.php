<?php
// api_specvalidator.php -- HotCRP API spec validator
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class SpecValidator_API {
    /** @var Qrequest */
    private $qreq;
    /** @var string */
    private $fn;
    /** @var object */
    private $uf;
    /** @var ?mixed */
    private $parameters;
    /** @var ?mixed */
    private $response;

    const F_REQUIRED = 0x01;     // '!'; '?' means not required
    const F_POST = 0x02;         // '+' (implies QUERY if given in request)
    const F_QUERY = 0x04;
    const F_BODY = 0x08;         // '='
    const F_FILE = 0x10;         // '@'
    const F_SUFFIX = 0x20;       // ':'
    const F_PRESENT = 0x40;
    const F_DEPRECATED = 0x80;   // '<'
    const FM_BODYQUERY = 0x0C;
    const FM_QUERYPOST = 0x06;
    const FM_LOCATION = 0x1C;

    /** @param string $fn
     * @param object $uf */
    function __construct($fn, $uf, Qrequest $qreq) {
        $this->qreq = $qreq;
        $this->fn = $fn;
        $this->uf = $uf;
        $this->parameters = $uf->parameters ?? null;
        $this->response = $uf->response ?? null;
        if (($this->parameters === null || $this->response === null)
            && ($ufx = $qreq->conf()->api_expansion($fn, $qreq->method()))) {
            $this->parameters = $this->parameters ?? $ufx->parameters ?? null;
            $this->response = $this->response ?? $ufx->response ?? null;
        }
    }

    function request() {
        $post = $this->qreq->is_post();
        if ($post && !($this->uf->post ?? false)) {
            $this->error("POST request handled by get handler");
        }
        if ($this->uf->deprecated ?? false) {
            $this->error("deprecated API");
        }

        if ($this->parameters === null) {
            return;
        }

        $parameters = $this->parameters ?? [];
        if (is_string($parameters)) {
            $parameters = explode(" ", trim($parameters));
        }
        $known = [];
        $has_suffix = false;
        foreach ($parameters as $p) {
            $f = self::F_REQUIRED;
            $plen = strlen($p);
            for ($i = 0; $i !== $plen; ++$i) {
                if ($p[$i] === "?") {
                    $f &= ~self::F_REQUIRED;
                } else if ($p[$i] === "!") {
                    $f |= self::F_REQUIRED;
                } else if ($p[$i] === "+") {
                    $f |= self::F_POST | self::F_QUERY;
                } else if ($p[$i] === "=") {
                    $f |= self::F_BODY;
                } else if ($p[$i] === "@") {
                    $f |= self::F_FILE;
                } else if ($p[$i] === ":") {
                    $f |= self::F_SUFFIX;
                    $has_suffix = true;
                } else if ($p[$i] === "*") {
                    $f &= ~self::F_REQUIRED;
                    if (($f & self::FM_LOCATION) === 0) {
                        $f |= self::FM_LOCATION;
                    }
                    if ($i === $plen - 1) {
                        break;
                    }
                } else if ($p[$i] === "<") {
                    $f |= self::F_DEPRECATED;
                } else {
                    break;
                }
            }
            if (($f & self::FM_LOCATION) === 0) {
                $f |= self::F_QUERY;
            }
            if (!$post && ($f & self::FM_QUERYPOST) !== self::F_QUERY) {
                continue;
            }
            $n = substr($p, $i);
            if ($n !== "") {
                $known[$n] = $f;
            }
        }

        $param = [];
        foreach (array_keys($_GET) as $n) {
            if (($t = self::lookup_type($n, $known, $has_suffix)) === null) {
                if (!in_array($n, ["post", "base", "fn", "forceShow", "cap", "actas", "smsg", "_", ":method:", "apiKey"], true)
                    && ($n !== "p" || !($this->uf->paper ?? false))) {
                    $this->error("query param `{$n}` unknown");
                }
            } else if (($t & self::F_QUERY) === 0) {
                $this->error("query param `{$n}` should be in body");
            }
        }
        foreach (array_keys($_POST) as $n) {
            if (($t = self::lookup_type($n, $known, $has_suffix)) === null) {
                $this->error("body param `{$n}` unknown");
            } else if (!isset($_GET[$n])
                       && ($t & self::F_BODY) === 0
                       && !$this->qreq->is_get() /* no `:method:` overriding */) {
                $this->error("body param `{$n}` should be in query");
            }
        }
        foreach (array_keys($_FILES) as $n) {
            if (($t = self::lookup_type($n, $known, $has_suffix)) === null
                || ($t & (self::F_FILE | self::F_BODY)) === 0) {
                $this->error("file param `{$n}` unknown");
            }
        }
        foreach ($known as $n => $t) {
            if (($t & (self::F_REQUIRED | self::F_PRESENT)) === self::F_REQUIRED) {
                $type = self::unparse_param_type($n, $t);
                $this->error("required {$type} `{$n}` missing");
            }
        }
    }

    function response($jr) {
        $post = $this->qreq->is_post();
        if ($this->response === null
            || !($jr instanceof JsonResult)
            || $jr->minimal) {
            return;
        }
        $response = $this->response;
        if (is_string($response)) {
            $response = explode(" ", trim($response));
        }
        if ($response === ["*"]) {
            return;
        }
        $nmandatory = count($response);
        $known = [];
        $has_suffix = false;
        foreach ($response as $ri => $p) {
            $f = self::F_REQUIRED;
            $plen = strlen($p);
            for ($i = 0; $i !== strlen($p); ++$i) {
                if ($p[$i] === "?") {
                    $f &= ~self::F_REQUIRED;
                } else if ($p[$i] === "!") {
                    $f |= self::F_REQUIRED;
                } else if ($p[$i] === "<") {
                    $f |= self::F_DEPRECATED;
                } else if ($p[$i] === ":") {
                    $f |= self::F_SUFFIX;
                    $has_suffix = true;
                } else if ($p[$i] === "*") {
                    $f &= ~self::F_REQUIRED;
                    if ($i === $plen - 1) {
                        break;
                    }
                } else if ($p[$i] === "+") {
                    $f |= self::F_POST;
                } else {
                    break;
                }
            }
            if (!$post && ($f & self::F_POST) !== 0) {
                continue;
            }
            $n = substr($p, $i);
            if ($n !== "") {
                $known[$n] = $f;
            }
        }
        foreach (array_keys($jr->content) as $n) {
            if (($t = self::lookup_type($n, $known, $has_suffix)) === null) {
                if (!in_array($n, ["ok", "message_list"], true)) {
                    $this->error("response component `{$n}` unknown");
                }
            }
        }
        if (!$jr->content["ok"]) {
            return;
        }
        $missing = [];
        foreach ($known as $n => $t) {
            if (($t & (self::F_REQUIRED | self::F_PRESENT)) === self::F_REQUIRED) {
                $missing[] = $n;
            } else if (($t & (self::F_DEPRECATED | self::F_PRESENT)) === (self::F_DEPRECATED | self::F_PRESENT)) {
                // if a deprecated response component is present,
                // ignore required response component errors
                return;
            }
        }
        foreach ($missing as $n) {
            $this->error("response component `{$n}` missing");
        }
    }

    static function lookup_type($n, &$known, $has_suffix) {
        if (isset($known[$n])) {
            $known[$n] |= self::F_PRESENT;
            return $known[$n];
        }
        if ($has_suffix) {
            $colon = strpos($n, ":");
            $slash = strpos($n, "/");
            if ($colon === false || ($slash !== false && $colon > $slash)) {
                $colon = $slash;
            }
            if ($colon !== false) {
                $pfx = substr($n, 0, $colon);
                $t = $known[$pfx] ?? 0;
                if (($t & self::F_SUFFIX) !== 0) {
                    $known[$pfx] |= self::F_PRESENT;
                    return $t;
                }
            }
        }
        return $known["*"] ?? null;
    }

    static function unparse_param_type($n, $t) {
        if (($t & self::F_FILE) !== 0) {
            return "file param";
        } else if (($t & self::FM_BODYQUERY) === self::FM_BODYQUERY) {
            return "param";
        } else if (($t & self::F_BODY) !== 0) {
            return "body param";
        }
        return "query param";
    }

    function error($error) {
        $nav = $this->qreq->navigation();
        $url = substr($nav->self(), 0, 100);
        $out = $this->qreq->conf()->opt("validateApiSpec");
        $method = $this->qreq->method();
        if (is_string($out)) {
            @file_put_contents($out, "{$method} {$url}: {$error}\n", FILE_APPEND);
        } else {
            error_log("{$method} {$url}: {$error}");
        }
    }
}
