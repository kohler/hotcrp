<?php
// stableidpermutation.php -- HotCRP class to translate IDs into unique strings
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class StableIDPermutation {
    /** @var string */
    private $key;
    /** @var string */
    private $cipher;
    /** @var int */
    private $block_size;
    /** @var array<int,string> */
    private $m = [];
    /** @var array<int,int> */
    private $order;

    /** @param int|string $key */
    function __construct($key) {
        if (is_int($key)) {
            $this->key = pack("P", $key);
        } else {
            $this->key = $key ?? "";
        }
        if (strlen($this->key) > 0
            && strlen($this->key) <= 16
            && @openssl_cipher_key_length("aes-128-ecb") === 16) {
            $this->cipher = "aes-128-ecb";
            $this->block_size = 16;
        } else if (strlen($this->key) > 0
                   && @openssl_cipher_key_length("aes-256-ecb") === 32) {
            $this->cipher = "aes-256-ecb";
            $this->block_size = 16;
        } else {
            $this->cipher = "null";
            $this->block_size = 8;
        }
    }

    /** @param Conf $conf
     * @param string $keyname
     * @return string */
    static function conf_key($conf, $keyname) {
        $x = $conf->setting_data($keyname);
        if ($x === null) {
            $conf->qe("insert into Settings set name=?, value=1, data=? on duplicate key update name=name",
                $keyname, random_bytes(7));
            $x = $conf->fetch_value("select data from Settings where name=?", $keyname);
            assert($x !== null);
            $conf->change_setting($keyname, 1, $x);
        }
        return substr($x, 0, 7);
    }

    /** @param Contact $u
     * @return StableIDPermutation */
    static function make_user($u) {
        $u->ensure_account_here();
        $pk = self::conf_key($u->conf, "__id_permuter_key");
        return new StableIDPermutation("U" . $pk . pack("P", $u->contactId));
    }

    /** @param Contact $u
     * @return StableIDPermutation */
    static function make_assignment($u) {
        $u->ensure_account_here();
        $pk = $u->data("assignment_key") ?? self::conf_key($u->conf, "__assignment_key");
        return new StableIDPermutation("U" . $pk . pack("P", $u->contactId));
    }

    /** @param int|list<int> ...$ids */
    function prefetch(...$ids) {
        $a = $x = [];
        foreach ($ids as $idelt) {
            foreach (is_array($idelt) ? $idelt : [$idelt] as $id) {
                if ($id === null || isset($this->m[$id])) {
                    continue;
                }
                $a[] = $id;
                $x[] = $id;
                if ($this->block_size === 16) {
                    $x[] = 0;
                }
            }
        }
        if (empty($x)) {
            return;
        }
        $s = pack("P*", ...$x);
        $e = openssl_encrypt($s, $this->cipher, $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        if (!is_string($e) || strlen($e) !== strlen($s)) {
            throw new ErrorException("unexpected openssl_encrypt result");
        }
        foreach ($a as $i => $id) {
            $this->m[$id] = substr($e, $this->block_size * $i, $this->block_size);
        }
        $this->order = null;
    }

    /** @param int $id
     * @return string */
    function get($id) {
        if (!isset($this->m[$id])) {
            $this->prefetch($id);
        }
        return $this->m[$id];
    }

    /** @param int $id
     * @return int */
    function order($id) {
        if (!isset($this->m[$id])) {
            $this->prefetch($id);
        }
        if ($this->order === null) {
            $this->order = [];
            asort($this->m);
            $i = 0;
            foreach ($this->m as $id => $t) {
                ++$i;
                $this->order[$id] = $i;
            }
        }
        return $this->order[$id];
    }
}
