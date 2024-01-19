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
    /** @var int */
    public $errcode = 0;
    /** @var ?int */
    private $fixed_time;

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

    /** @param ?int $t
     * @return $this */
    function set_fixed_time($t) {
        $this->fixed_time = $t;
        return $this;
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

    /** @param int $errcode
     * @param string $msg
     * @return null */
    private function null_error($errcode, $msg) {
        $this->errcode = $errcode;
        $this->error_at(null, $msg);
        return null;
    }

    /** @param int $errcode
     * @param string $msg
     * @return false */
    private function false_error($errcode, $msg) {
        $this->errcode = $errcode;
        $this->error_at(null, $msg);
        return false;
    }

    /** @return ?string */
    function jwk_to_pem($jwk) {
        $this->errcode = 0;
        if (!is_object($jwk) || !isset($jwk->kty)) {
            return $this->null_error(1001, "<0>Input not in JWK format");
        } else if ($jwk->kty !== "RSA") {
            $suffix = is_string($jwk->kty) ? " ‘{$jwk->kty}’" : "";
            return $this->null_error(1002, "<0>JWK type{$suffix} not understood");
        } else if (!isset($jwk->e)
                   || !is_string($jwk->e)
                   || !is_base64url_string($jwk->e)
                   || !isset($jwk->n)
                   || !is_string($jwk->n)
                   || !is_base64url_string($jwk->n)) {
            return $this->null_error(1003, "<0>JWK key parameters incorrect");
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
        $this->errcode = 0;
        $this->jose = $this->payload = null;
        if ($s === null || $s === "") {
            return $this->null_error(1101, "<0>Message required");
        } else if (!preg_match('/\A([-_A-Za-z0-9]*)\.([-_A-Za-z0-9.]+)\z/', $s, $m)) {
            return $this->null_error(1102, "<0>Message format error");
        }

        $jose = json_decode(base64url_decode($m[1]));
        if (!$jose || !is_object($jose)) {
            return $this->null_error(1103, "<0>Message header syntax error");
        }
        $this->jose = $jose;
        if (!$this->has_alg($jose->alg ?? null)) {
            return $this->null_error(1104, "<0>Unknown algorithm");
        } else if (isset($jose->typ) && ($jose->typ ?? null) !== "JWT") {
            $suffix = isset($jose->typ) && is_string($jose->typ) ? " ‘{$jose->typ}’" : "";
            return $this->null_error(1105, "<0>Unexpected message type{$suffix}");
        } else if (($jose->cty ?? null) === "JWT") {
            return $this->null_error(1106, "<0>Nested messages not supported");
        } else if (isset($jose->crit)) {
            return $this->null_error(1107, "<0>Critical message extensions not supported");
        }

        $dot = strpos($m[2], ".");
        if ($dot === false && $jose->alg !== "none") {
            return $this->null_error(1108, "<0>Message signature missing");
        }
        $dot = $dot === false ? strlen($m[2]) : $dot;
        $payload = json_decode(base64url_decode(substr($m[2], 0, $dot)));
        if (!$payload || !is_object($payload)) {
            return $this->null_error(1109, "<0>Message payload syntax error");
        }
        $this->payload = $payload;

        if ($dot !== strlen($m[2]) && strpos($m[2], ".", $dot + 1) !== false) {
            return $this->null_error(1110, "<0>Message may be encrypted");
        }

        if ($this->verify_key
            && !$this->verify(substr($s, 0, strlen($m[1]) + 1 + $dot), $jose->alg,
                              base64url_decode(substr($m[2], $dot + 1)))) {
            return $this->null_error(1111, "<0>Message signature invalid");
        }

        return $this->payload;
    }

    /** @param object $payload
     * @param OAuthProvider $authi
     * @param 0|1|2 $level
     * @return bool */
    function validate_id_token($payload, $authi, $level = 1) {
        // check issuer claim
        if (!isset($payload->iss) || !is_string($payload->iss)) {
            return $this->false_error(1201, "<0>`iss` claim missing or invalid");
        } else if ($authi->issuer !== null && $authi->issuer !== $payload->iss) {
            return $this->false_error(1202, "<0>`iss` claim does not match expected issuer");
        }

        // check audience claim
        if (!isset($payload->aud) || (!is_string($payload->aud) && !is_array($payload->aud))) {
            return $this->false_error(1203, "<0>`aud` claim missing or invalid");
        } else if (is_array($payload->aud)
                   ? !in_array($authi->client_id, $payload->aud)
                   : $authi->client_id !== $payload->aud) {
            return $this->false_error(1204, "<0>`aud` claim does not match client ID");
        }

        // check authorized-party claim
        if ($level >= 1) {
            if (isset($payload->azt)) {
                if (!is_string($payload->azt)) {
                    return $this->false_error(1206, "<0>`azt` claim invalid");
                } else if ($authi->client_id !== $payload->azt) {
                    return $this->false_error(1207, "<0>`azt` claim does not match client ID");
                }
            } else if (is_array($payload->aud) && !isset($payload->azt)) {
                return $this->false_error(1205, "<0>`azt` claim missing");
            }
        }

        // XXX check algorithm claim

        // check expiration time
        $now = $this->fixed_time ?? Conf::$now;
        if (!isset($payload->exp) || !is_int($payload->exp)) {
            return $this->false_error(1208, "<0>`exp` claim missing or invalid");
        } else if ($now >= $payload->exp) {
            return $this->false_error(1209, "<0>ID token expired");
        }

        // check `iat` claims
        if ($level >= 2) {
            if (!isset($payload->iat) || !is_int($payload->iat)) {
                return $this->false_error(1210, "<0>`iat` claim missing or invalid");
            } else if ($payload->iat > $now + 60) {
                return $this->false_error(1211, "<0>ID token claims to have been issued in the future");
            } else if ($payload->iat < $now - 3600) {
                return $this->false_error(1212, "<0>ID token issued too long ago");
            }
        }

        // check nonce
        if (isset($authi->nonce)
            && (!isset($payload->nonce) || $payload->nonce !== $authi->nonce)) {
            return $this->false_error(1213, "<0>ID token nonce mismatch");
        }

        return true;
    }

    /** @param object $payload
     * @return string */
    static function make_plaintext($payload) {
        $jose = '{"alg":"none","typ":"JWT"}';
        $payload = json_encode_db($payload);
        return base64url_encode($jose) . "." . base64url_encode($payload) . ".";
    }

    /** @param object $payload
     * @param string $key
     * @param 'HS256'|'HS384'|'HS512' $alg
     * @return string */
    static function make_mac($payload, $key, $alg = "HS256") {
        assert(isset(self::$hash_alg_map[$alg]));
        $jose = '{"alg":"' . $alg . '","typ":"JWT"}';
        $payload = json_encode_db($payload);
        $s = base64url_encode($jose) . "." . base64url_encode($payload);
        $signature = hash_hmac(self::$hash_alg_map[$alg], $s, $key);
        return $s . "." . base64url_encode($signature);
    }
}
