<?php
// api_specvalidator.php -- HotCRP API spec validator
// Copyright (c) 2008-2024 Eddie Kohler; see LICENSE.

class SpecValidator_API {
    const F_REQUIRED = 1;
    const F_BODY = 2;
    const F_FILE = 4;
    const F_SUFFIX = 8;
    const F_PRESENT = 16;
    const F_DEPRECATED = 32;

    static function request($uf, Qrequest $qreq) {
        if ($qreq->is_post() && !($uf->post ?? false)) {
            self::error($qreq, "POST request handled by get handler");
        }

        $parameters = $uf->parameters ?? [];
        if (is_string($parameters)) {
            $parameters = explode(" ", trim($parameters));
        }
        $known = [];
        $has_suffix = false;
        foreach ($parameters as $p) {
            $t = self::F_REQUIRED;
            for ($i = 0; $i !== strlen($p); ++$i) {
                if ($p[$i] === "?") {
                    $t &= ~self::F_REQUIRED;
                } else if ($p[$i] === "=") {
                    $t |= self::F_BODY;
                } else if ($p[$i] === "@") {
                    $t |= self::F_FILE;
                } else if ($p[$i] === ":") {
                    $t |= self::F_SUFFIX;
                    $has_suffix = true;
                } else if ($p[$i] === "*") {
                    $t &= ~self::F_REQUIRED;
                    break;
                } else {
                    break;
                }
            }
            $n = substr($p, $i);
            $known[$n] = $t;
        }

        $param = [];
        foreach (array_keys($_GET) as $n) {
            if (($t = self::lookup_type($n, $known, $has_suffix)) === null) {
                if (!in_array($n, ["post", "base", "fn", "forceShow", "cap", "actas", "smsg", "_"])
                    && ($n !== "p" || !($uf->paper ?? false))
                    && ($n !== "redirect" || !($uf->redirect ?? false))) {
                    self::error($qreq, "query param `{$n}` unknown");
                }
            } else if (($t & self::F_BODY) !== 0) {
                self::error($qreq, "query param `{$n}` should be in body");
            }
        }
        foreach (array_keys($_POST) as $n) {
            if (($t = self::lookup_type($n, $known, $has_suffix)) === null) {
                self::error($qreq, "post param `{$n}` unknown");
            } else if (!isset($_GET[$n])
                       && ($t & self::F_BODY) === 0) {
                self::error($qreq, "post param `{$n}` should be in query");
            }
        }
        foreach (array_keys($_FILES) as $n) {
            if (($t = self::lookup_type($n, $known, $has_suffix)) === null
                || ($t & (self::F_FILE | self::F_BODY)) === 0) {
                self::error($qreq, "file param `{$n}` unknown");
            }
        }
        foreach ($known as $n => $t) {
            if (($t & (self::F_REQUIRED | self::F_PRESENT)) === self::F_REQUIRED) {
                $type = self::unparse_param_type($n, $t);
                self::error($qreq, "required {$type} `{$n}` missing");
            }
        }
    }

    static function response($uf, Qrequest $qreq, $jr) {
        $response = $uf->response ?? [];
        if (is_string($response)) {
            $response = explode(" ", trim($response));
        }
        if ($response === ["*"] || !($jr instanceof JsonResult)) {
            return;
        }
        $response_deprecated = $uf->response_deprecated ?? [];
        if (is_string($response_deprecated)) {
            $response_deprecated = explode(" ", trim($response_deprecated));
        }
        $nmandatory = count($response);
        if (!empty($response_deprecated)) {
            array_push($response, ...$response_deprecated);
        }
        $known = [];
        $has_suffix = false;
        foreach ($response as $ri => $p) {
            $t = $ri < $nmandatory ? self::F_REQUIRED : self::F_DEPRECATED;
            for ($i = 0; $i !== strlen($p); ++$i) {
                if ($p[$i] === "?") {
                    $t &= ~self::F_REQUIRED;
                } else if ($p[$i] === ":") {
                    $t |= self::F_SUFFIX;
                    $has_suffix = true;
                } else if ($p[$i] === "*") {
                    $t &= ~self::F_REQUIRED;
                    break;
                } else {
                    break;
                }
            }
            $n = substr($p, $i);
            $known[$n] = $t;
        }
        foreach (array_keys($jr->content) as $n) {
            if (($t = self::lookup_type($n, $known, $has_suffix)) === null) {
                if (!in_array($n, ["ok", "message_list"])) {
                    self::error($qreq, "response component `{$n}` unknown");
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
            self::error($qreq, "response component `{$n}` missing");
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
        } else if (($t & self::F_BODY) !== 0) {
            return "post param";
        } else {
            return "query param";
        }
    }

    static function error(Qrequest $qreq, $error) {
        $nav = $qreq->navigation();
        $url = substr($nav->self(), 0, 100);
        $out = $qreq->conf()->opt("validateApiSpec");
        if (is_string($out)) {
            @file_put_contents($out, "{$qreq->method()} {$url}: {$error}\n", FILE_APPEND);
        } else {
            error_log("{$qreq->method()} {$url}: {$error}");
        }
    }
}
