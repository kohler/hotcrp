<?php
// api_specvalidator.php -- HotCRP API spec validator
// Copyright (c) 2008-2024 Eddie Kohler; see LICENSE.

class SpecValidator_API {
    const F_REQUIRED = 1;
    const F_BODY = 2;

    static function run($uf, Qrequest $qreq) {
        $known = [];
        foreach ($uf->parameters ?? [] as $p) {
            $flags = self::F_REQUIRED;
            for ($i = 0; $i !== strlen($p); ++$i) {
                if ($p[$i] === "?") {
                    $flags &= ~self::F_REQUIRED;
                } else if ($p[$i] === "=") {
                    $flags |= self::F_BODY;
                } else {
                    break;
                }
            }
            $n = substr($p, $i);
            $known[$n] = $flags;
            if (($flags & self::F_REQUIRED) !== 0
                && !$qreq->has($n)) {
                self::error($qreq, "required parameter `{$n}` missing");
            }
        }
        $param = [];
        foreach (array_keys($_GET) as $n) {
            if (!isset($known[$n])) {
                if (!in_array($n, ["post", "base", "fn", "forceShow", "cap", "actas", "_"])
                    && ($n !== "p" || !($uf->paper ?? false))
                    && !isset($known["*"])) {
                    self::error($qreq, "query param `{$n}` unknown");
                }
            } else if (($known[$n] & self::F_BODY) !== 0) {
                self::error($qreq, "query param `{$n}` should be in body");
            }
        }
        foreach (array_keys($_POST) as $n) {
            if (!isset($known[$n])) {
                if (!isset($known["*"])) {
                    self::error($qreq, "post param `{$n}` unknown");
                }
            } else if (!isset($_GET[$n])
                       && ($known[$n] & self::F_BODY) === 0) {
                self::error($qreq, "post param `{$n}` should be in query");
            }
        }
    }

    static function error(Qrequest $qreq, $error) {
        $nav = $qreq->navigation();
        $url = substr($nav->self(), 0, 100);
        $out = $qreq->conf()->opt("validateApiSpec");
        if (is_string($out)) {
            @file_put_contents($out, "{$url}: {$error}\n", FILE_APPEND);
        } else {
            error_log("{$url}: {$error}");
        }
    }
}
