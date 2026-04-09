<?php
// render.php -- HotCRP page render script
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Render_Batch::make_args($argv)->run());
}

class Render_Batch {
    /** @var Conf */
    public $conf;
    /** @var string */
    public $urlbase;
    /** @var Contact */
    public $user;
    /** @var string */
    public $method;
    /** @var string */
    public $url;
    /** @var list<string> */
    public $req_headers;
    /** @var ?string */
    public $data;
    /** @var ?list<string|QrequestFile> */
    private $form_data;
    /** @var ?string */
    public $data_content_type;
    /** @var bool */
    public $include_headers;
    /** @var bool */
    public $head;
    /** @var bool */
    public $verbose;
    /** @var bool */
    public $follow_redirects;
    /** @var ?string */
    public $from;
    /** @var ?string */
    public $diff_file1;
    /** @var ?string */
    public $diff_file2;
    /** @var ?bool */
    public $color;
    /** @var int */
    private $from_status = 0;
    /** @var resource */
    private $_from_stream;

    function __construct(?Contact $user, $arg) {
        if (isset($arg["color"]) || isset($arg["no-color"])) {
            $this->color = isset($arg["color"]);
        } else {
            $this->color = stream_isatty(STDOUT);
        }

        if (isset($arg["diff"]) && !isset($arg["from"])) {
            $this->diff_file1 = $arg["_"][0];
            $this->diff_file2 = $arg["_"][1];
            return;
        }

        $this->conf = $user->conf;
        $this->urlbase = $this->conf->opt("paperSite");
        if ($this->urlbase === "") {
            $this->urlbase = "http://hotcrp.invalid";
        }
        $this->urlbase .= "/";
        $this->user = $user;
        $this->req_headers = $arg["H"] ?? [];
        $this->include_headers = isset($arg["i"]) || isset($arg["I"]);
        $this->head = isset($arg["I"]);
        $this->verbose = isset($arg["V"]);
        $this->follow_redirects = isset($arg["L"]);

        if (isset($arg["from"])) {
            $this->from = $arg["_"][0];
            if (isset($arg["diff"])) {
                $this->diff_file1 = $this->from;
            }
            return;
        }

        $basenav = NavigationState::make_base($this->urlbase);
        $this->url = $basenav->resolve(preg_replace('/\A\/++/', "", $arg["_"][0]));
        if (!str_starts_with($this->url, $this->urlbase)) {
            throw new CommandLineException("invalid URL `" . $arg["_"][0] . "`");
        }

        foreach ($arg["__"] as $nv) {
            list($n, $v) = $nv;
            if ($n === "data" || $n === "data-raw" || $n === "data-binary") {
                $this->add_data($n, $v);
            } else if ($n === "form" || $n === "form-string") {
                $this->add_form($n, $v);
            }
        }

        if ($this->data !== null && $this->head) {
            throw new CommandLineException("`-I` and `--data` options conflict");
        }
        if (isset($arg["X"])) {
            $this->method = strtoupper($arg["X"]);
        } else {
            $this->method = $this->data === null ? "GET" : "POST";
        }
    }

    /** @param string $f
     * @return string */
    static function read_file($f) {
        // no protocol wrappers
        if (!str_starts_with($f, "/") && strpos($f, "://") !== false) {
            $f = "./{$f}";
        }
        if (($v = @file_get_contents($f)) === false) {
            throw CommandLineException::make_file_error($f);
        }
        return $v;
    }

    /** @param string $n
     * @param string $v */
    private function add_data($n, $v) {
        if ($this->data === null) {
            $this->data_content_type = $this->data_content_type ?? "application/x-www-form-urlencoded";
        }
        if ($this->data_content_type === "multipart/form-data") {
            throw new CommandLineException("cannot use `--{$n}` with multipart/form-data");
        }
        if ($n !== "data-raw" && str_starts_with($v, "@")) {
            $v = self::read_file(substr($v, 1));
            if ($n === "data") {
                $v = preg_replace('/[\r\n]++/', "", $v);
            }
        }
        if ($n !== "data-binary") {
            $v = urlencode($v);
        }
        if ($this->data === null) {
            $this->data = $v;
        } else {
            $this->data .= "&" . $v;
        }
    }

    /** @param string $n
     * @param string $v */
    private function add_form($n, $v) {
        if ($this->form_data === null) {
            $this->data_content_type = $this->data_content_type ?? "multipart/form-data";
        }
        if ($this->data_content_type !== "multipart/form-data") {
            throw new CommandLineException("cannot use `--{$n}` with {$this->data_content_type}");
        }
        $eq = strpos($v, "=");
        if ($eq === false) {
            throw new CommandLineException("`--{$n}` option must match `name=value`");
        }
        if ($n === "form-string") {
            $qf = QrequestFile::make_string(substr($v, $eq + 1), "");
            $qf->input_name = substr($v, 0, $eq);
            $this->form_data[] = $qf;
            return;
        }
        if (!preg_match('/\G=([@<]?+)(\"(?:[^\\\\"]|\\\\[\\\\"])*+\"|(?=;|\z)|[^;,\s][^;,]*+)/s', $v, $m, 0, $eq)
            || ($m[2] !== "" && ctype_space(substr($m[2], -1)))) {
            throw new CommandLineException("`--{$n}` option has bad value");
        }
        $ftype = $m[1];
        $value = str_starts_with($m[2], "\"") ? stripcslashes(substr($m[2], 1, -1)) : $m[2];
        $rest = [];
        $pos = $eq + strlen($m[0]);
        while ($pos < strlen($v)) {
            if (!preg_match('/\G;(type|filename)=([^;]++|\"([^\\\\"]|\\\\[\\\\"])*+\")/', $v, $mm, 0, $pos)
                || isset($rest[$mm[1]])) {
                throw new CommandLineException("`--{$n}` option has bad parameters");
            }
            $rest[$mm[1]] = str_starts_with($mm[2], "\"") ? stripcslashes(substr($mm[2], 1, -1)) : $mm[2];
            $pos += strlen($mm[0]);
        }
        if ($ftype !== "") {
            $content = self::read_file($value);
        } else {
            $content = $value;
        }
        if ($ftype === "@" && !isset($rest["filename"])) {
            $rest["filename"] = $value;
        }
        $qf = QrequestFile::make_string($content, $rest["filename"] ?? "", $rest["type"] ?? null);
        $qf->input_name = substr($v, 0, $eq);
        $this->form_data[] = $qf;
    }

    /** @return Qrequest */
    private function make_qrequest($url) {
        $nav = NavigationState::make_base($this->urlbase,
                                          substr($url, strlen($this->urlbase)));
        Navigation::set($nav);

        // query and, optionally, post data
        $qstr = $nav->query === "" ? "" : substr($nav->query, 1);
        if ($this->data_content_type === "application/x-www-form-urlencoded") {
            $qstr .= ($qstr === "" ? "" : "&") . $this->data;
        }
        $qfiles = [];
        foreach ($this->form_data ?? [] as $qf) {
            if ($qf->name !== "") {
                $qfiles[] = $qf;
            } else {
                $qstr .= ($qstr === "" ? "" : "&")
                    . urlencode($qf->input_name)
                    . "=" . urlencode($qf->content);
            }
        }
        parse_str($qstr, $args);

        $qreq = (new Qrequest($this->method, $args))
            ->set_user($this->user)
            ->set_navigation($nav);
        foreach ($qfiles as $qf) {
            $qreq->set_file($qf->input_name, $qf);
        }

        if ($this->method === "POST" || $this->method === "DELETE") {
            $qreq->approve_token();
        }

        if ($this->data !== null
            && $this->data_content_type !== "application/x-www-form-urlencoded") {
            $qreq->set_body($this->data, $this->data_content_type);
        }

        foreach ($this->req_headers as $h) {
            $colon = strpos($h, ":");
            if ($colon !== false) {
                $qreq->set_header(trim(substr($h, 0, $colon)), trim(substr($h, $colon + 1)));
            }
        }

        return $qreq;
    }

    private function write_response_headers($rc) {
        fwrite(STDOUT, "HTTP/1.1 {$rc->status}\r\n");
        foreach ($rc->headers as $h) {
            fwrite(STDOUT, "{$h}\r\n");
        }
        fwrite(STDOUT, "\r\n");
    }

    /** @param string $urlarg
     * @return string */
    private function resolve_url($urlarg) {
        $basenav = NavigationState::make_base($this->urlbase);
        $url = $basenav->resolve(preg_replace('/\A\/++/', "", $urlarg));
        if (!str_starts_with($url, $this->urlbase)) {
            throw new CommandLineException("invalid URL `{$urlarg}`");
        }
        return $url;
    }

    private function from_flush($block, $pending_url) {
        if ($pending_url === null) {
            return;
        }
        $out = $this->_from_stream;
        $save_user = $this->user;
        if (preg_match('/\A-u[ \t]*+(\S++)\s++(.*+)\z/', $pending_url, $m)) {
            $user = $this->conf->user_by_email($m[1]) ?? $this->conf->cdb_user_by_email($m[1]);
            if (!$user) {
                throw new CommandLineException("User {$m[1]} not found");
            }
            $this->user = $user;
            $pending_url = $m[2];
        }
        $url = $this->resolve_url($pending_url);
        $this->method = "GET";
        $this->data = null;
        $this->form_data = null;
        $this->data_content_type = null;
        $rc = RenderCapture::make($this->make_qrequest($url));

        if (!str_ends_with($block, "\n\n")) {
            $block .= "\n";
        }
        fwrite($out, $block);
        fwrite($out, "    HTTP/1.1 {$rc->status}\n");
        // headers in sorted order
        if (!empty($rc->headers)) {
            $headers = $rc->headers;
            sort($headers, SORT_STRING | SORT_FLAG_CASE);
            fwrite($out, "    " . join("\n    ", $headers) . "\n");
        }
        // content (with terminating newline)
        if (!empty($rc->content)) {
            $content = $rc->content;
            if (str_ends_with($content, "\n")) {
                $content = substr($content, 0, -1);
            }
            fwrite($out, "    \n    " . str_replace("\n", "\n    ", $content) . "\n");
        }
        fwrite($out, "\n");
        if ($rc->status < 200 || $rc->status >= 400) {
            $this->from_status = 1;
        }
        $this->user = $save_user;
    }

    /** @param ?resource $stream
     * @return int */
    private function run_from($stream = null) {
        $this->_from_stream = $stream ?? STDOUT;
        $str = self::read_file($this->from);
        $this->from_status = 0;
        $pending_url = null;
        $lastpos = $pos = 0;
        $len = strlen($str);
        $block = "";
        while ($pos < $len) {
            $epos = strpos($str, "\n", $pos);
            $epos = $epos === false ? $len : $epos + 1;
            if (preg_match('/\G\#[ \t]++((?:-u[ \t]*+\S++[ \t]++)?+\S*+)/', $str, $m, 0, $pos)) {
                $block .= substr($str, $lastpos, $pos - $lastpos);
                $this->from_flush($block, $pending_url);
                $pending_url = $m[1];
                $block = "";
                $lastpos = $pos;
                $pos = $epos;
            } else if (substr_compare($str, "    HTTP/1.1 ", $pos, 13) === 0) {
                // skip existing HTTP response block
                $block .= substr($str, $lastpos, $pos - $lastpos);
                while (true) {
                    $pos = $epos;
                    if ($pos === $len
                        || substr_compare($str, "    ", $pos, 4) !== 0) {
                        break;
                    }
                    $epos = strpos($str, "\n", $pos);
                    $epos = $epos === false ? $len : $epos + 1;
                }
                while ($pos !== $len && $str[$pos] === "\n") {
                    ++$pos;
                }
                $lastpos = $pos;
            } else {
                $pos = $epos;
            }
        }
        $block .= substr($str, $lastpos, $pos - $lastpos);
        $this->from_flush($block, $pending_url);
        return $this->from_status;
    }

    /** Parse a render .md file into an ordered map of heading => block content.
     * @param string $text
     * @return array<string,string> */
    static function parse_blocks($text) {
        $blocks = [];
        $heading = null;
        $content = "";
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/\A#\s+(\S.*)/', $line)) {
                if ($heading !== null) {
                    $blocks[$heading] = $content;
                }
                $heading = $line;
                $content = "";
            } else if ($heading !== null) {
                $content .= $line . "\n";
            }
        }
        if ($heading !== null) {
            $blocks[$heading] = $content;
        }
        return $blocks;
    }

    /** Normalize a block to remove semantically non-meaningful differences.
     * @param string $s
     * @return string */
    static function normalize($s) {
        $s = preg_replace('/"now":[0-9]+(?:\.[0-9]+)?/', '"now":0', $s);
        $s = preg_replace('/name="[A-Za-z0-9\/+=]{8,}="/', 'name="TOKEN"', $s);
        $s = preg_replace('/"bannertoken":"[\w.]+"/', '"bannertoken":null', $s);
        $s = preg_replace('/\[[0-9a-f]+\.\.\. [0-9]+M\]/', '[HASH... 0M]', $s);
        // Normalize colons in href query strings to %3A
        $s = preg_replace_callback('/(href="[^"?]*+\?)([^\#"]*+)/', function ($m) {
            $x = str_replace(["%7E", ":"], ["~", "%3A"], $m[2]);
            if (str_starts_with($x, "t=s&")) {
                $x = preg_replace('/\A(t=s)(&(?:amp;)?+)([^&]*+)/', '$3$2$1', $x);
            }
            return $m[1] . $x;
        }, $s);
        // Normalize location of href attribute
        $s = preg_replace_callback('/(<a(?![a-z])[^>]*)( href="[^"]*+")( [^>]*+)/', function ($m) {
            return $m[1] . $m[3] . $m[2];
        }, $s);
        // Normalize HTML entities
        $s = str_replace("&ndash;", "\xE2\x80\x93", $s);
        $s = str_replace("&#039;", "&apos;", $s);
        return $s;
    }

    /** @param string $n1
     * @param string $n2 */
    private function print_diff_plain($n1, $n2) {
        $f1 = tempnam(sys_get_temp_dir(), "rd1_");
        $f2 = tempnam(sys_get_temp_dir(), "rd2_");
        file_put_contents($f1, $n1);
        file_put_contents($f2, $n2);
        $label1 = basename($this->diff_file1);
        $label2 = basename($this->diff_file2);
        $cmd = "diff -u"
            . " --label " . escapeshellarg($label1)
            . " --label " . escapeshellarg($label2)
            . " " . escapeshellarg($f1)
            . " " . escapeshellarg($f2);
        passthru($cmd);
        unlink($f1);
        unlink($f2);
    }

    // ANSI escape sequences for terminal diff
    const ANSI_RESET = "\e[0m";
    const ANSI_BOLD = "\e[1m";
    const ANSI_DEL_BG = "\e[48;5;224m";        // light red background
    const ANSI_DEL_HIGHLIGHT = "\e[48;5;217m"; // medium red for changed part
    const ANSI_INS_BG = "\e[48;5;194m";        // light green background
    const ANSI_INS_HIGHLIGHT = "\e[48;5;76m";  // darker green for changed part
    const ANSI_LINENO = "\e[2m";               // dim for line numbers

    /** @param string $n1
     * @param string $n2 */
    private function print_diff_terminal($n1, $n2) {
        $dmp = new \dmp\diff_match_patch;
        $dmp->Line_Histogram = true;
        $diffs = $dmp->line_diff($n1, $n2);
        $ndiffs = count($diffs);
        if ($ndiffs === 0 || ($ndiffs === 1 && $diffs[0]->op === \dmp\DIFF_EQUAL)) {
            return;
        }

        // Collect hunks with context
        $lines1 = explode("\n", rtrim($n1, "\n"));
        $lines2 = explode("\n", rtrim($n2, "\n"));
        $context = 3;
        $l1 = 0; // line index in file1
        $l2 = 0; // line index in file2

        // Build display entries: each is [type, line1_num, line2_num, text, ?inline_diffs]
        $entries = [];
        for ($i = 0; $i < $ndiffs; ++$i) {
            $diff = $diffs[$i];
            $dlines = $dmp->split_lines($diff->text);
            if ($diff->op === \dmp\DIFF_EQUAL) {
                foreach ($dlines as $dl) {
                    $entries[] = ["=", $l1, $l2, $dl];
                    ++$l1;
                    ++$l2;
                }
            } else if ($diff->op === \dmp\DIFF_DELETE) {
                // Check if next diff is an insert (paired change)
                $ins_lines = null;
                if ($i + 1 < $ndiffs && $diffs[$i + 1]->op === \dmp\DIFF_INSERT) {
                    $ins_lines = $dmp->split_lines($diffs[$i + 1]->text);
                }
                foreach ($dlines as $j => $dl) {
                    $inline = null;
                    if ($ins_lines !== null && $j < count($ins_lines)) {
                        $inline = $dmp->diff($dl, $ins_lines[$j]);
                        $dmp->diff_cleanupSemantic($inline);
                    }
                    $entries[] = ["-", $l1, null, $dl, $inline];
                    ++$l1;
                }
                if ($ins_lines !== null) {
                    ++$i; // consume the insert
                    foreach ($ins_lines as $j => $il) {
                        $inline = null;
                        if ($j < count($dlines)) {
                            $inline = $dmp->diff($dlines[$j], $il);
                            $dmp->diff_cleanupSemantic($inline);
                        }
                        $entries[] = ["+", null, $l2, $il, $inline];
                        ++$l2;
                    }
                }
            } else { // DIFF_INSERT (unpaired)
                foreach ($dlines as $dl) {
                    $entries[] = ["+", null, $l2, $dl];
                    ++$l2;
                }
            }
        }

        // Now render with context collapsing
        $nentries = count($entries);
        // Find ranges of changed entries, expanded by context
        $visible = array_fill(0, $nentries, false);
        for ($i = 0; $i < $nentries; ++$i) {
            if ($entries[$i][0] !== "=") {
                for ($j = max(0, $i - $context); $j < min($nentries, $i + $context + 1); ++$j) {
                    $visible[$j] = true;
                }
            }
        }

        $was_visible = false;
        for ($i = 0; $i < $nentries; ++$i) {
            if (!$visible[$i]) {
                $was_visible = false;
                continue;
            }
            if (!$was_visible && $i > 0) {
                fwrite(STDOUT, self::ANSI_LINENO . "  ..." . self::ANSI_RESET . "\n");
            }
            $was_visible = true;

            $e = $entries[$i];
            $type = $e[0];
            $lnum = $type === "+" ? $e[2] : $e[1];
            $lpad = str_pad((string)($lnum + 1), 5, " ", STR_PAD_LEFT);

            if ($type === "=") {
                fwrite(STDOUT, self::ANSI_LINENO . $lpad . self::ANSI_RESET . "  " . $e[3] . "\n");
            } else if ($type === "-") {
                $line_text = $this->render_inline_diff($e, \dmp\DIFF_DELETE);
                fwrite(STDOUT, self::ANSI_DEL_BG . self::ANSI_LINENO . $lpad . self::ANSI_RESET
                    . self::ANSI_DEL_BG . " " . $line_text . self::ANSI_RESET . "\n");
            } else {
                $line_text = $this->render_inline_diff($e, \dmp\DIFF_INSERT);
                fwrite(STDOUT, self::ANSI_INS_BG . self::ANSI_LINENO . $lpad . self::ANSI_RESET
                    . self::ANSI_INS_BG . " " . $line_text . self::ANSI_RESET . "\n");
            }
        }
    }

    /** @param array $entry
     * @param -1|1 $side
     * @return string */
    private function render_inline_diff($entry, $side) {
        if (!isset($entry[4]) || $entry[4] === null) {
            return $entry[3];
        }
        $bg = $side === \dmp\DIFF_DELETE ? self::ANSI_DEL_BG : self::ANSI_INS_BG;
        $highlight = $side === \dmp\DIFF_DELETE ? self::ANSI_DEL_HIGHLIGHT : self::ANSI_INS_HIGHLIGHT;
        $out = "";
        foreach ($entry[4] as $d) {
            if ($d->op === \dmp\DIFF_EQUAL) {
                $out .= $d->text;
            } else if ($d->op === $side) {
                $out .= $highlight . $d->text . $bg;
            }
            // skip the other side
        }
        return $out;
    }

    /** @return int */
    private function run_diff() {
        $text1 = self::read_file($this->diff_file1);
        $text2 = self::read_file($this->diff_file2);
        $blocks1 = self::parse_blocks($text1);
        $blocks2 = self::parse_blocks($text2);

        $all_headings = array_keys($blocks1 + $blocks2);
        $has_diff = false;

        foreach ($all_headings as $heading) {
            $hdr = $this->color ? self::ANSI_BOLD . $heading . self::ANSI_RESET : $heading;
            if (!isset($blocks1[$heading])) {
                fwrite(STDOUT, "{$hdr}\n  Only in {$this->diff_file2}\n\n");
                $has_diff = true;
                continue;
            }
            if (!isset($blocks2[$heading])) {
                fwrite(STDOUT, "{$hdr}\n  Only in {$this->diff_file1}\n\n");
                $has_diff = true;
                continue;
            }
            $n1 = self::normalize($blocks1[$heading]);
            $n2 = self::normalize($blocks2[$heading]);
            if ($n1 !== $n2) {
                fwrite(STDOUT, "{$hdr}\n");
                if ($this->color) {
                    $this->print_diff_terminal($n1, $n2);
                } else {
                    $this->print_diff_plain($n1, $n2);
                }
                fwrite(STDOUT, "\n");
                $has_diff = true;
            }
        }

        if (!$has_diff) {
            fwrite(STDERR, "No semantic differences found.\n");
        }

        return $has_diff ? 1 : 0;
    }

    /** @return int */
    function run() {
        Navigation::$test_mode = 2;

        if ($this->diff_file1 !== null && $this->from !== null) {
            // --diff --from: render from file, then diff against it
            $f2 = tempnam(sys_get_temp_dir(), "rdf_");
            $stream = fopen($f2, "wb");
            $this->run_from($stream);
            fclose($stream);
            $this->diff_file2 = $f2;
            $result = $this->run_diff();
            unlink($f2);
            return $result;
        }
        if ($this->diff_file1 !== null) {
            return $this->run_diff();
        }
        if ($this->from !== null) {
            return $this->run_from();
        }

        $rc = RenderCapture::make($this->make_qrequest($this->url));

        $max_redirects = 10;
        while ($this->follow_redirects
               && $max_redirects > 0
               && ($location = $rc->header("Location")) !== null
               && str_starts_with($location, $this->urlbase)) {
            if ($this->verbose || $this->include_headers) {
                $this->write_response_headers($rc);
            }
            --$max_redirects;
            $this->method = "GET";
            $this->data = null;
            $rc = RenderCapture::make($this->make_qrequest($location));
        }

        if ($this->verbose || $this->include_headers) {
            $this->write_response_headers($rc);
        }

        if (!$this->head) {
            fwrite(STDOUT, $rc->content);
        }

        return $rc->status >= 200 && $rc->status < 400 ? 0 : 1;
    }

    /** @return Render_Batch */
    static function make_args($argv) {
        $getopt = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "user:,u: =EMAIL Act as user EMAIL",
            "X:,request: =METHOD HTTP method (GET, POST, etc.) [default GET]",
            "H[],header[] =HEADER Add request header",
            "data[],d[] =NAME=VALUE Send parameter in request body",
            "data-raw[] !",
            "form[],F[] =NAME=VALUE Send parameter or attach file",
            "form-string[] !",
            "from Read URLs from FILE and render each",
            "diff Compare two render output files",
            "color Print diffs in color",
            "no-color",
            "i,include Include response headers in output",
            "I,head Show response headers only",
            "V,verbose Show request/response headers",
            "L,location Follow redirects",
            "help,h !"
        )->description("Render a HotCRP page and print the output.
Usage: php batch/render.php [-n CONFID] [-u EMAIL] [OPTIONS] URL
       php batch/render.php [-n CONFID] [-u EMAIL] --from FILE
       php batch/render.php --diff FILE1 FILE2
       php batch/render.php [-n CONFID] [-u EMAIL] --diff --from FILE")
         ->helpopt("help")
         ->maxarg(2)
         ->interleave(true)
         ->order(true);
        $arg = $getopt->parse($argv);

        if (isset($arg["from"])) {
            // --diff --from FILE: re-render FILE and diff against it
            if (count($arg["_"]) !== 1) {
                throw new CommandLineException("Too many arguments for `--from`", $getopt);
            }
        } else if (isset($arg["diff"])) {
            if (count($arg["_"]) !== 2) {
                throw new CommandLineException("Exactly two arguments required for `--diff`", $getopt);
            }
            return new Render_Batch(null, $arg);
        } else if (count($arg["_"]) !== 1) {
            throw new CommandLineException("URL argument required", $getopt);
        }

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        $email = $arg["user"] ?? null;
        if ($email === null) {
            $user = $conf->root_user();
        } else if (!($user = $conf->user_by_email($email) ?? $conf->cdb_user_by_email($email))) {
            throw new CommandLineException("User {$email} not found");
        }
        return new Render_Batch($user, $arg);
    }
}
