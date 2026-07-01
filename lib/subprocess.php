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
    public $runtime = 0.0;
    /** @var bool */
    public $ok = false;


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
    function run() {
        if ($this->status !== null) {
            throw new Error("restarting subprocess");
        }

        $stdinpos = 0;
        $stdinlen = strlen($this->stdin);
        $descriptors = [
            $stdinpos === $stdinlen ? ["file", "/dev/null", "r"] : ["pipe", "rb"],
            ["pipe", "wb"],
            ["pipe", "wb"]
        ];
        $start_time = microtime(true);
        $proc = proc_open(self::args_to_command($this->command), $descriptors, $pipes, $this->cwd, $this->env);
        if (!$proc) {
            $this->status = -1;
            return $this;
        }

        if ($stdinpos !== $stdinlen) {
            stream_set_blocking($pipes[0], false);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            if ($stdinpos !== $stdinlen) {
                $nw = fwrite($pipes[0], substr($this->stdin, $stdinpos));
                if ($nw !== false) {
                    $stdinpos += $nw;
                }
                if ($stdinpos === $stdinlen) {
                    fclose($pipes[0]);
                }
            }
            $x = fread($pipes[1], 32768);
            if ($x !== false) {
                $this->stdout .= $x;
            }
            $y = fread($pipes[2], 32768);
            if ($y !== false) {
                $this->stderr .= $y;
            }
            if ($x === false
                || $y === false) {
                break;
            }
            $r = [$pipes[1], $pipes[2]];
            $w = $stdinpos === $stdinlen ? [] : [$pipes[0]];
            $e = [];
            stream_select($r, $w, $e, 5);
        }

        if ($stdinpos !== $stdinlen) {
            fclose($pipes[0]);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $st = proc_get_status($proc);
        $close = proc_close($proc);
        $this->status = $st["running"] ? $close : $st["exitcode"] /* = -1 on signal */;
        $this->ok = $this->status === 0;
        $this->runtime = microtime(true) - $start_time;
        return $this;
    }
}
