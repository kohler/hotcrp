<?php
// s3verifyall.php -- HotCRP maintenance script
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(S3VerifyAll_Batch::make_args($argv)->run());
}

class S3VerifyAll_Batch {
    /** @var Conf */
    public $conf;
    /** @var ?int */
    public $count;
    /** @var bool */
    public $verbose;
    /** @var string */
    public $match;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->count = $arg["count"] ?? null;
        $this->verbose = isset($arg["verbose"]);
        $this->match = $arg["match"] ?? "";
    }

    /** @return int */
    function run() {
        $match_algos = ["", "sha2-"];
        $match_re = $match_pfx = "";
        if ($this->match !== "") {
            $docmatch = new DocumentHashMatcher($this->match);
            if (preg_match('{\A(?:|sha\d-)\z}', $docmatch->algo_pfx_preg)) {
                $match_algos = [$docmatch->algo_pfx_preg];
            }
            if ($docmatch->fixed_hash) {
                $match_pfx = substr($docmatch->fixed_hash, 0, 2);
            }
            if ($docmatch->has_hash_preg) {
                $match_re = '{/' . $docmatch->algo_pfx_preg . $docmatch->hash_preg . '[^/]*\z}';
            }
        }
        $algo_key_re_map = [
            "" => '{/([0-9a-f]{40})(?:\.[^/]*|)\z}',
            "sha2-" => '{/(sha2-[0-9a-f]{64})(?:\.[^/]*|)\z}'
        ];

        $s3doc = $this->conf->s3_client();

        $algo_pos = -1;
        $algo_pfx = $last_key = $continuation_token = null;
        $doc = DocumentInfo::make($this->conf);
        $xml = null;
        $xmlpos = 0;
        $key_re = '/.*/';
        while (true) {
            if ($this->count === 0) {
                break;
            } else if ($this->count !== null) {
                --$this->count;
            }

            if ($xml === null || $xmlpos >= count($xml->Contents)) {
                // depends on all non-empty algo_pfx being >`f`
                $next_algo = false;
                if ($last_key === null
                    || ($match_pfx != "" && strcmp($last_key, $algo_pfx . $match_pfx) > 0)
                    || ($algo_pfx == "" && strcmp($last_key, "f") > 0)
                    || $continuation_token === false) {
                    $next_algo = true;
                }
                if ($next_algo) {
                    ++$algo_pos;
                    if ($algo_pos >= count($match_algos)) {
                        break;
                    }
                    $algo_pfx = $match_algos[$algo_pos];
                    $key_re = $algo_key_re_map[$algo_pfx];
                    $continuation_token = null;
                }

                if ($continuation_token !== null) {
                    $content = $s3doc->ls("doc/", ["max-keys" => 500, "continuation-token" => $continuation_token]);
                } else {
                    $content = $s3doc->ls("doc/" . $match_pfx, ["max-keys" => 500]);
                }

                $xml = new SimpleXMLElement($content);
                $xmlpos = 0;
                if (!isset($xml->Contents) || $xmlpos >= count($xml->Contents)) {
                    break;
                }
                $continuation_token = false;
                if (isset($xml->IsTruncated) && (string) $xml->IsTruncated === "true") {
                    $continuation_token = (string) $xml->NextContinuationToken;
                }
            }

            $node = $xml->Contents[$xmlpos];
            ++$xmlpos;

            $last_key = (string) $node->Key;
            if ((!$match_re || preg_match($match_re, $last_key))
                && preg_match($key_re, $last_key, $m)
                && ($khash = HashAnalysis::hash_as_binary($m[1]))) {
                if ($this->verbose) {
                    fwrite(STDOUT, "$last_key: ");
                }
                $content = $s3doc->get($last_key);
                $doc->set_content($content);
                $chash = $doc->content_binary_hash($khash);
                if ($chash !== $khash) {
                    if (!$this->verbose) {
                        fwrite(STDOUT, "$last_key: ");
                    }
                    fwrite(STDOUT, "bad checksum " . HashAnalysis::hash_as_text($chash) . " (" . HashAnalysis::hash_as_text($khash) . ")\n");
                } else if ($this->verbose) {
                    fwrite(STDOUT, "ok\n");
                }
            }
        }

        return 0;
    }

    /** @param list<string> $argv
     * @return S3VerifyAll_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "config: !",
            "name:,n: !",
            "help,h !",
            "count:,c: {n}",
            "match:,m:",
            "verbose,V"
        )->description("Verify checksums of documents stored on S3 for HotCRP.
Usage: php batch/s3verifyall.php [-n CONFID | --config CONFIG] [-c COUNT] [-m MATCH] [-V]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        if (!$conf->s3_client()) {
            throw new ErrorException("S3 is not configured for this conference");
        }
        return new S3VerifyAll_Batch($conf, $arg);
    }
}
