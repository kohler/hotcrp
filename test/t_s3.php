<?php
// t_s3.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class S3_Tester {
    /** @param non-empty-string $tester
     * @return ?S3Client */
    static function make_s3_client(Conf $conf, $tester)  {
        if (($s3k = $conf->opt("testS3Key"))
            && ($s3s = $conf->opt("testS3Secret"))
            && in_array($tester, $conf->opt("testS3Testers") ?? [])) {
            $s3r = $conf->opt("testS3Region");
            $s3b = $conf->opt("testS3Bucket") ?? ("hotcrptest-" . strtolower(encode_token(random_bytes(8))));
            return S3Client::make([
                "key" => $s3k, "secret" => $s3s, "region" => $s3r,
                "bucket" => $s3b
            ]);
        } else {
            return null;
        }
    }

    /** @param S3Client|array{?string,?string,?string,?string} $s3i
     * @return array{?string,?string,?string,?string} */
    static function install_s3_options(Conf $conf, $s3i) {
        $r = [];
        foreach (["s3_bucket", "s3_key", "s3_secret", "s3_region"] as $i => $k) {
            $v = $s3i instanceof S3Client ? $s3i->$k : $s3i[$i];
            $r[] = $conf->opt($k);
            $conf->set_opt($k, $v);
        }
        return $r;
    }
}
