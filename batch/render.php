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

    function __construct(Contact $user, $arg) {
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

        $basenav = NavigationState::make_base($this->urlbase);
        $this->url = $basenav->resolve($arg["_"][0]);
        if (!str_starts_with($this->url, $this->urlbase)) {
            throw new CommandLineException("invalid URL `" . $arg["_"][0] . "`");
        }

        foreach ($arg["__"] as $nv) {
            list($n, $v) = $nv;
            if ($n !== "data" && $n !== "data-raw" && $n !== "data-binary") {
                continue;
            }
            if ($n !== "data-raw" && str_starts_with($v, "@")) {
                $f = substr($v, 1);
                // no protocol wrappers
                if (!str_starts_with($f, "/") && strpos($f, "://") !== false) {
                    $f = "./{$f}";
                }
                if (($v = @file_get_contents($f)) === false) {
                    throw CommandLineException::make_file_error($f);
                }
                if ($n === "data") {
                    $v = preg_replace('/[\r\n]++/', "", $v);
                }
            }
            if ($n !== "data-binary") {
                $v = urlencode($v);
            }
            if ($this->data === null) {
                $this->data = $v;
                $this->data_content_type = $this->data_content_type ?? "application/x-www-form-urlencoded";
            } else {
                $this->data .= "&" . $v;
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
        parse_str($qstr, $args);

        $qreq = (new Qrequest($this->method, $args))
            ->set_user($this->user)
            ->set_navigation($nav);

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

    /** @return int */
    function run() {
        Navigation::$test_mode = 2;

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
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "user:,u: =EMAIL Act as user EMAIL",
            "X:,request: =METHOD HTTP method (GET, POST, etc.) [default GET]",
            "H[],header[] =HEADER Add request header",
            "data[],d[] =DATA Send DATA as request body",
            "data-raw[] !",
            "i,include Include response headers in output",
            "I,head Show response headers only",
            "V,verbose Show request/response headers",
            "L,location Follow redirects",
            "help,h !"
        )->description("Render a HotCRP page and print the output.
Usage: php batch/render.php [-n CONFID] [-u EMAIL] [OPTIONS] URL")
         ->helpopt("help")
         ->minarg(1)
         ->maxarg(1)
         ->order(true)
         ->parse($argv);

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
