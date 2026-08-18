<?php
// subprocess.php -- Helper class for subprocess running
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Subprocess {
    /** @var list<string> */
    public $command;
    /** @var string */
    public $cwd;
    /** @var ?int */
    public $status;
    /** @var string */
    public $stdin = "";
    /** @var ?array<string,string> */
    public $env;
    /** @var string */
    public $stdout = "";
    /** @var string */
    public $stderr = "";
    /** @var float */
    public $start_time;
    /** @var float */
    public $runtime = 0.0;
    /** @var int */
    private $_run_status = 0;
    /** @var bool */
    public $ok = false;
    /** @var ?list<callable> */
    private $_progress_functions;
    /** @var ?resource */
    private $_proc;
    /** @var array{?resource,resource,resource} */
    private $_pipes;
    /** @var int */
    private $_stdin_pos = 0;


    /** @param list<string> $command
     * @param string $cwd */
    function __construct($command, $cwd) {
        $this->command = $command;
        $this->cwd = $cwd;
    }

    /** @param string $stdin
     * @return $this */
    function set_stdin($stdin) {
        $this->stdin = $stdin;
        return $this;
    }

    /** @param ?array<string,string> $env
     * @return $this */
    function set_env($env) {
        $this->env = $env;
        return $this;
    }

    /** Register a function called while the subprocess runs. Progress functions
     * are invoked once per I/O loop iteration; this can be very frequently
     * (callers should self-throttle using `Subprocess::$runtime`), but will
     * be at least once every 5 seconds.
     * @param callable(Subprocess) $f
     * @return $this */
    function add_progress_function($f) {
        $this->_progress_functions[] = $f;
        return $this;
    }


    /** @param string $word
     * @return string */
    static function shell_quote_light($word) {
        if (preg_match('/\A[-_.,:+\/a-zA-Z0-9][-_.,:=+\/a-zA-Z0-9~]*\z/', $word)) {
            return $word;
        }
        return escapeshellarg($word);
    }

    /** @param list<string> $args
     * @return string */
    static function shell_quote_args($args) {
        $s = [];
        foreach ($args as $word) {
            $s[] = self::shell_quote_light($word);
        }
        return join(" ", $s);
    }

    /** @param list<string> $args
     * @return list<string>|string */
    static function args_to_command($args) {
        if (PHP_VERSION_ID < 70400) {
            return self::shell_quote_args($args);
        }
        return $args;
    }


    /** @return $this */
    function start() {
        if ($this->_run_status !== 0) {
            throw new Error("restarting subprocess");
        }

        $descriptors = [
            $this->_stdin_pos === strlen($this->stdin) ? ["file", "/dev/null", "r"] : ["pipe", "rb"],
            ["pipe", "wb"],
            ["pipe", "wb"]
        ];
        $this->start_time = microtime(true);
        $this->_proc = proc_open(self::args_to_command($this->command),
            $descriptors, $this->_pipes, $this->cwd, $this->env);
        if (!$this->_proc) {
            $this->status = -1;
            $this->_run_status = 4;
            return $this;
        }

        if ($this->_stdin_pos !== strlen($this->stdin)) {
            stream_set_blocking($this->_pipes[0], false);
        } else {
            $this->_pipes[0] = null;
        }
        stream_set_blocking($this->_pipes[1], false);
        stream_set_blocking($this->_pipes[2], false);
        $this->_run_status = 1;
        return $this;
    }

    private function _run_step() {
        if (feof($this->_pipes[1]) && feof($this->_pipes[2])) {
            $this->_run_status = 3;
            return;
        }

        if ($this->_run_status === 1) {
            $this->_run_status = 2;
        } else if (!empty($this->_progress_functions)) {
            $this->runtime = microtime(true) - $this->start_time;
            foreach ($this->_progress_functions as $f) {
                $f($this);
            }
        }

        if ($this->_pipes[0]) {
            $nw = fwrite($this->_pipes[0], substr($this->stdin, $this->_stdin_pos));
            if ($nw !== false) {
                $this->_stdin_pos += $nw;
            }
            if ($this->_stdin_pos === strlen($this->stdin)) {
                fclose($this->_pipes[0]);
                $this->_pipes[0] = null;
            }
        }
        $x = fread($this->_pipes[1], 32768);
        if ($x !== false) {
            $this->stdout .= $x;
        }
        $y = fread($this->_pipes[2], 32768);
        if ($y !== false) {
            $this->stderr .= $y;
        }
        if ($x === false || $y === false) {
            $this->_run_status = 3;
            return;
        }

        $r = [$this->_pipes[1], $this->_pipes[2]];
        $w = $this->_pipes[0] ? [$this->_pipes[0]] : [];
        $e = [];
        stream_select($r, $w, $e, 5);
    }

    /** @return $this */
    function step() {
        if ($this->_run_status === 0) {
            $this->start();
        }
        if ($this->_run_status === 1 || $this->_run_status === 2) {
            $this->_run_step();
        }
        if ($this->_run_status === 3) {
            if ($this->_pipes[0]) {
                fclose($this->_pipes[0]);
            }
            fclose($this->_pipes[1]);
            fclose($this->_pipes[2]);
            $st = proc_get_status($this->_proc);
            $close = proc_close($this->_proc);
            $this->status = $st["running"] ? $close : $st["exitcode"] /* = -1 on signal */;
            $this->ok = $this->status === 0;
            $this->runtime = microtime(true) - $this->start_time;
            $this->_proc = $this->_pipes = null;
            $this->_run_status = 4;
        }
        return $this;
    }

    /** @return $this */
    function run() {
        while ($this->_run_status !== 4) {
            $this->step();
        }
        return $this;
    }
}
