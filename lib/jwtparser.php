<?php
// jwtparser.php -- HotCRP class for limited parsing of JSON Web Tokens
// Copyright (c) 2022 Eddie Kohler; see LICENSE.

class JWTParser extends MessageSet {
    /** @var ?object */
    public $jose;
    /** @var ?object */
    public $payload;
    /** @var ?string */
    public $verify_key;
    /** @var bool */
    static private $has_openssl;
    /** @var ?array<string,bool> */
    static private $openssl_known;

    static private $hash_alg_map = [
        "HS256" => "sha256",
        "HS384" => "sha384",
        "HS512" => "sha512"
    ];
    static private $openssl_alg_map = [
        "RS256" => "sha256WithRSAEncryption",
        "RS384" => "sha384WithRSAEncryption",
        "RS512" => "sha512WithRSAEncryption"
    ];

    function __construct() {
        parent::__construct();
        self::$has_openssl = self::$has_openssl ?? function_exists("openssl_verify");
    }

    /** @param int $t
     * @param string $v
     * @return string */
    static private function der_encode_tlv($t, $v) {
        $s = chr($t);
        $l = strlen($v);
        if ($l < 128) {
            $s .= chr($l);
        } else if ($l < 256) {
            $s .= "\x81" . chr($l);
        } else if ($l < 65536) {
            $s .= "\x82" . chr($l >> 8) . chr($l & 255);
        } else if ($l < 16777216) {
            $s .= "\x83" . chr($l >> 16) . chr(($l >> 8) & 255) . chr($l & 255);
        } else {
            throw new InvalidArgumentException("der_encode_tlv too long");
        }
        return $s . $v;
    }

    /** @param string $v
     * @param int $num_unused_bits
     * @return string */
    static function der_encode_bit_string($v, $num_unused_bits) {
        return self::der_encode_tlv(3, chr($num_unused_bits) . $v);
    }

    /** @param int $i
     * @return string */
    static function der_encode_int($i) {
        if ($i < 0) {
            $mask = 0xFF;
            $i = ~$i;
        } else {
            $mask = 0;
        }
        $s = "";
        while ($i > 127) {
            $s = chr(($i & 255) ^ $mask) . $s;
            $i >>= 8;
        }
        return self::der_encode_tlv(2, chr($i ^ $mask) . $s);
    }

    /** @param string $v
     * @return string */
    static private function der_encode_positive_int_string($v) {
        assert($v !== "");
        while (strlen($v) > 1 && $v[0] === "\x00" && ord($v[1]) < 128) {
            $v = substr($v, 1);
        }
        if (ord($v[0]) > 127) {
            $v = "\x00" . $v;
        }
        return self::der_encode_tlv(2, $v);
    }

    /** @param list<int> $oid
     * @return string */
    static function der_encode_oid($oid) {
        $s = "";
        for ($i = 0; $i !== count($oid); ++$i) {
            if ($i === 0) {
                $n = 40 * $oid[0] + $oid[1];
                ++$i;
            } else {
                $n = $oid[$i];
            }
            $x = chr($n & 127);
            $n >>= 7;
            while ($n !== 0) {
                $x = chr(128 | ($n & 127)) . $x;
                $n >>= 7;
            }
            $s .= $x;
        }
        return self::der_encode_tlv(6, $s);
    }

    /** @return ?string */
    function jwk_to_pem($jwk) {
        if (!is_object($jwk) || !isset($jwk->kty)) {
            $this->error_at(null, "<0>Input not in JWK format");
            return null;
        } else if ($jwk->kty !== "RSA") {
            $suffix = is_string($jwk->kty) ? " ‘{$jwk->kty}’" : "";
            $this->error_at(null, "<0>JWK type{$suffix} not understood");
            return null;
        } else if (!isset($jwk->e)
                   || !is_string($jwk->e)
                   || !is_base64url_string($jwk->e)
                   || !isset($jwk->n)
                   || !is_string($jwk->n)
                   || !is_base64url_string($jwk->n)) {
            $this->error_at(null, "<0>JWK key parameters incorrect");
            return null;
        } else {
            $algseq = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
            $nenc = self::der_encode_positive_int_string(base64url_decode($jwk->n));
            $eenc = self::der_encode_positive_int_string(base64url_decode($jwk->e));
            $param = self::der_encode_bit_string(self::der_encode_tlv(0x30, $nenc . $eenc), 0);
            $content = self::der_encode_tlv(0x30, $algseq . $param);
            $s = base64_encode($content);
            $t = ["-----BEGIN PUBLIC KEY-----\r\n"];
            for ($p = 0; $p < strlen($s); $p += 64) {
                $t[] = substr($s, $p, 64) . "\r\n";
            }
            $t[] = "-----END PUBLIC KEY-----\r\n";
            return join("", $t);
        }
    }

    /** @return bool */
    function has_alg($alg) {
        if (!is_string($alg)) {
            return false;
        } else if ($alg === "none" || isset(self::$hash_alg_map[$alg])) {
            return true;
        } else if (isset(self::$openssl_alg_map[$alg]) && self::$has_openssl) {
            if (self::$openssl_known === null) {
                self::$openssl_known = [];
                foreach (openssl_get_md_methods(true) as $x) {
                    self::$openssl_known[$x] = true;
                }
            }
            return self::$openssl_known[self::$openssl_alg_map[$alg]] ?? false;
        } else {
            return false;
        }
    }

    /** @param string $s
     * @param string $alg
     * @param string $signature
     * @return bool */
    function verify($s, $alg, $signature) {
        if ($alg === "none") {
            return $signature === "";
        } else if (isset(self::$hash_alg_map[$alg])) {
            $hash = hash_hmac(self::$hash_alg_map[$alg], $s, $this->verify_key);
            return hash_equals($hash, $signature);
        } else if (isset(self::$openssl_alg_map[$alg])) {
            // XXX openssl_error_string();
            return openssl_verify($s, $signature, $this->verify_key, self::$openssl_alg_map[$alg]) === 1;
        } else {
            return false;
        }
    }

    /** @param string $s
     * @return ?object */
    function validate($s) {
        $this->jose = $this->payload = null;
        if ($s === null || $s === "") {
            $this->error_at(null, "<0>Message required");
            return null;
        } else if (!preg_match('/\A([-_A-Za-z0-9]*)\.([-_A-Za-z0-9.]+)\z/', $s, $m)) {
            $this->error_at(null, "<0>Message format error");
            return null;
        }

        $jose = json_decode(base64url_decode($m[1]));
        if (!$jose || !is_object($jose)) {
            $this->error_at(null, "<0>Message header syntax error");
            return null;
        }
        $this->jose = $jose;
        if (!$this->has_alg($jose->alg ?? null)) {
            $this->error_at(null, "<0>Unknown algorithm");
            return null;
        } else if (($jose->typ ?? null) !== "JWT") {
            $suffix = isset($jose->typ) && is_string($jose->typ) ? " ‘{$jose->typ}’" : "";
            $this->error_at(null, "<0>Unexpected message type{$suffix}");
            return null;
        } else if (($jose->cty ?? null) === "JWT") {
            $this->error_at(null, "<0>Nested messages not supported");
            return null;
        } else if (isset($jose->crit)) {
            $this->error_at(null, "<0>Critical message extensions not supported");
            return null;
        }

        $dot = strpos($m[2], ".");
        if ($dot === false && $jose->alg !== "none") {
            $this->error_at(null, "<0>Message signature missing");
            return null;
        }
        $dot = $dot === false ? strlen($m[2]) : $dot;
        $payload = json_decode(base64url_decode(substr($m[2], 0, $dot)));
        if (!$payload || !is_object($payload)) {
            $this->error_at(null, "<0>Message payload syntax error");
            return null;
        }
        $this->payload = $payload;

        if ($dot !== strlen($m[2]) && strpos($m[2], ".", $dot + 1) !== false) {
            $this->error_at(null, "<0>Message may be encrypted");
            return null;
        }

        if ($this->verify_key
            && !$this->verify(substr($s, 0, strlen($m[1]) + 1 + $dot), $jose->alg,
                              base64url_decode(substr($m[2], $dot + 1)))) {
            $this->error_at(null, "<0>Message signature invalid");
            return null;
        }

        return $this->payload;
    }

    /** @param object $payload
     * @return string */
    static function make_plaintext($payload) {
        $jose = '{"alg":"none","typ":"JWT"}';
        return base64url_encode($jose) . "." . base64url_encode(json_encode_db($payload)) . ".";
    }
}
