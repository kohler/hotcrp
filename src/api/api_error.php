<?php
// api_error.php -- HotCRP error reporting API calls
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Error_API {
    static function jserror(Contact $user, Qrequest $qreq) {
        $errormsg = trim((string) $qreq->error);
        if ($errormsg === ""
            || str_contains($errormsg . ($qreq->stack ?? ""), "-extension")
            || (($ua = $qreq->user_agent()) && str_contains($ua, "Googlebot"))) {
            return new JsonResult(["ok" => true]);
        }
        $url = $qreq->url ?? "";
        if (preg_match(',[/=]((?:script|jquery)[^/&;]*[.]js),', $url, $m)) {
            $url = $m[1];
        }
        if (($n = $qreq->lineno)) {
            $url .= ":" . $n;
        }
        if (($n = $qreq->colno)) {
            $url .= ":" . $n;
        }
        if ($url !== "") {
            $url .= ": ";
        }
        $suffix = "";
        if ($user->email) {
            $suffix .= ", user " . $user->email;
        }
        if (isset($_SERVER["REMOTE_ADDR"])) {
            $suffix .= ", host " . $_SERVER["REMOTE_ADDR"];
        }
        error_log("JS error: {$url}{$errormsg}{$suffix}");
        if ($qreq->detail) {
            error_log("JS error: {$url}detail " . substr($qreq->detail, 0, 200));
        }
        if (($stacktext = $qreq->stack)) {
            $stack = [];
            foreach (explode("\n", $stacktext) as $line) {
                $line = trim($line);
                if ($line === "" || $line === $errormsg || "Uncaught {$line}" === $errormsg) {
                    continue;
                }
                if (preg_match('/\Aat (\S+) \((\S+)\)/', $line, $m)) {
                    $line = $m[1] . "@" . $m[2];
                } else if (substr($line, 0, 1) === "@") {
                    $line = substr($line, 1);
                } else if (substr($line, 0, 3) === "at ") {
                    $line = substr($line, 3);
                }
                $stack[] = $line;
            }
            error_log("JS error: {$url}via " . join(" ", $stack));
        }
        return new JsonResult(["ok" => true]);
    }

    static function cspreport(Contact $user, Qrequest $qreq) {
        $bct = $qreq->body_content_type();
        $j = null;
        if ($bct === "application/reports+json" || $bct === "application/json" || $bct === "application/csp-report") {
            $j = json_decode($qreq->body() ?? "null");
        }
        if (is_object($j)) {
            $j = [$j];
        }
        $t = [];
        if (($ok = is_list($j))) {
            foreach ($j as $jx) {
                if (!is_object($jx)) {
                    $ok = false;
                } else if (isset($jx->body)
                           && is_object($jx->body)
                           && isset($jx->body->sourceFile)
                           && str_contains($jx->body->sourceFile, "-extension")) {
                    /* skip */
                } else {
                    if ($user->has_email() && !isset($jx->user)) {
                        $jx->user = $user->email;
                    }
                    $t[] = "\x1E" /* RS */ . json_encode($jx, JSON_PRETTY_PRINT) . "\n";
                }
            }
        }
        if (!$ok) {
            if (($body = $qreq->body())
                && ($f = SiteLoader::find("var/cspreport-invalid.txt"))) {
                @file_put_contents($f, $bct . "\n" . $body);
            }
            return new JsonResult(400, [
                "ok" => false,
                "message_list" => [MessageItem::error("<0>Unexpected request")],
                "body_content_type" => $qreq->body_content_type(),
                "body_type" => gettype($j)
            ]);
        } else if (empty($t)) {
            return JsonResult::make_ok();
        }
        $f = $user->conf->opt("cspReportFile") ?? SiteLoader::find("var/cspreports.json-seq");
        if (!file_exists($f)
            || !is_file($f)
            || !is_writable($f)) {
            return JsonResult::make_error(503, "<0>Report cannot be saved");
        } else if (filesize($f) >= 3000000) {
            return JsonResult::make_error(503, "<0>Report quota reached");
        } else if (@file_put_contents($f, join("", $t), FILE_APPEND) === false) {
            return JsonResult::make_error(500, "<0>Report not saved");
        }
        return JsonResult::make_ok();
    }
}
