<?php
// api_error.php -- HotCRP error reporting API calls
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Error_API {
    static function jserror(Contact $user, Qrequest $qreq) {
        $errormsg = trim((string) $qreq->error);
        if ($errormsg === ""
            || preg_match('/(?:moz|safari|chrome)-extension/', $errormsg . ($qreq->stack ?? ""))) {
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
        error_log("CSP error: " . ($qreq->referrer() ?? "<unknown>"));
        if (($bct !== "application/reports+json" && $bct !== "application/json" && $bct !== "application/csp-report")
            || !($j = json_decode($qreq->body() ?? "null"))
            || !is_object($j)) {
            return new JsonResult(400, [
                "ok" => false,
                "message_list" => [MessageItem::error("<0>Unexpected request")],
                "body_content_type" => $qreq->body_content_type(),
                "body_type" => gettype($j)
            ]);
        }
        $f = $user->conf->opt("cspReportFile") ?? SiteLoader::find("var/cspreports.json-seq");
        if (!file_exists($f)
            || !is_file($f)
            || !is_writable($f)) {
            return JsonResult::make_error(503, "<0>Report cannot be saved");
        } else if (filesize($f) >= 3000000) {
            return JsonResult::make_error(503, "<0>Report quota reached");
        } else if (@file_put_contents($f,
                       "\x1E" /* RS */ . json_encode($j, JSON_PRETTY_PRINT) . "\n",
                       FILE_APPEND) === false) {
            return JsonResult::make_error(500, "<0>Report not saved");
        }
        return JsonResult::make_ok();
    }
}
