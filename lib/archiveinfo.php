<?php
// archiveinfo.php -- expand archive contents
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ArchiveInfo {
    /** @param int $max_length
     * @return false|list<string> */
    static function archive_listing(DocumentInfo $doc, $max_length = -1) {
        if (!($path = $doc->content_file())) {
            return false;
        }
        $type = null;
        if ($doc->filename === null || $doc->filename === "") {
            $contents = file_get_contents($path, false, null, 0, 1000);
            if (str_starts_with($contents, "\x1F\x9D")
                || str_starts_with($contents, "\x1F\xA0")
                || str_starts_with($contents, "BZh")
                || str_starts_with($contents, "\x1F\x8B")
                || str_starts_with($contents, "\xFD7zXZ\x00")) {
                $type = "tar";
            } else if (str_starts_with($contents, "ustar\x0000")
                       || str_starts_with($contents, "ustar  \x00")) {
                $type = "tar";
            } else if (str_starts_with($contents, "PK\x03\x04")
                       || str_starts_with($contents, "PK\x05\x06")
                       || str_starts_with($contents, "PK\x07\x08")) {
                $type = "zip";
            }
        } else if (preg_match('/\.zip\z/i', $doc->filename)) {
            $type = "zip";
        } else if (preg_match('/\.(?:tar|tgz|tar\.[gx]?z|tar\.bz2)\z/i', $doc->filename)) {
            $type = "tar";
        }
        if (!$type) {
            return false;
        } else if ($type === "zip") {
            $cmd = "zipinfo -1 ";
        } else {
            $cmd = "tar tf ";
        }
        $cmd .= escapeshellarg($path);
        $pipes = null;
        $proc = proc_open($cmd, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        // Some versions of PHP experience timeouts here; work around that.
        $out = $err = "";
        $now = microtime(true);
        $end_time = $now + 5;
        $done = false;
        while (!$done
               && $now < $end_time
               && ($max_length < 0 || $max_length > strlen($out))) {
            $r = [$pipes[1], $pipes[2]];
            $w = $e = [];
            $delta = $end_time - $now;
            $delta_sec = (int) $delta;
            stream_select($r, $w, $e, $delta_sec, (int) (($delta - $delta_sec) * 1000000));
            foreach ($r as $f) {
                if ($f === $pipes[1]) {
                    $t = fread($pipes[1], $max_length < 0 ? 65536 : min(65536, $max_length - strlen($out)));
                    if ($t === "") {
                        $done = true;
                    } else {
                        $out .= $t;
                    }
                } else if ($f === $pipes[2]) {
                    $err .= fread($pipes[2], 65536);
                }
            }
            $now = microtime(true);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        if ($err !== "") {
            $err = preg_replace('/^tar: Ignoring unknown[^\n]*\n*/m', '', $err);
        }
        if (($status !== 0 && $status !== 2) || $err !== "") {
            error_log("$cmd problem: status $status, stderr $err");
        }
        if (!$done && ($slash = strrpos($out, "\n")) > 0) {
            return explode("\n", substr($out, 0, $slash + 1) . "…");
        } else {
            return explode("\n", rtrim($out));
        }
    }

    /** @param list<string> $listing
     * @return list<string> */
    static function clean_archive_listing($listing) {
        $bad = preg_grep('/(?:\A|\/)(?:\A__MACOSX|\._.*|\.DS_Store|\.svn|\.git|.*~\z|.*\/\z|\A…\z)(?:\/|\z)/', $listing);
        if (!empty($bad)) {
            $listing = array_values(array_diff_key($listing, $bad));
            if (preg_match('/[^\/]\n/', join("\n", $bad) . "\n")) {
                $listing[] = "…";
            }
        }
        return $listing;
    }

    /** @param list<string> $listing
     * @return list<string> */
    static function consolidate_archive_listing($listing) {
        $new_listing = [];
        $nlisting = count($listing);
        $etcetera = $nlisting && $listing[$nlisting - 1] === "…";
        if ($etcetera) {
            --$nlisting;
        }
        for ($i = 0; $i < $nlisting; ) {
            if ($i + 1 < $nlisting && ($slash = strpos($listing[$i], "/")) !== false) {
                $prefix = substr($listing[$i], 0, $slash + 1);
                for ($j = $i + 1; $j < $nlisting && str_starts_with($listing[$j], $prefix); ++$j) {
                }
                if ($j > $i + 1) {
                    $xlisting = [];
                    for (; $i < $j; ++$i) {
                        $xlisting[] = substr($listing[$i], $slash + 1);
                    }
                    $xlisting = self::consolidate_archive_listing($xlisting);
                    if (count($xlisting) == 1) {
                        $new_listing[] = $prefix . $xlisting[0];
                    } else {
                        $new_listing[] = $prefix . "{" . join(", ", $xlisting) . "}";
                    }
                    continue;
                }
            }
            $new_listing[] = $listing[$i];
            ++$i;
        }
        if ($etcetera) {
            $new_listing[] = "…";
        }
        return $new_listing;
    }
}
