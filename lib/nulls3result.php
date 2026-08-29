<?php
// nulls3result.php -- document helper class for S3 access
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

/** @template T
 * @inherits S3Result<T> */
class NullS3Result extends S3Result {
    /** @param string $skey
     * @param 'GET'|'POST'|'HEAD'|'PUT'|'DELETE' $method
     * @param array<string,string> $args
     * @param callable(S3Result):T $finisher */
    function __construct(S3Client $s3, $skey, $method, $args, $finisher) {
        parent::__construct($s3, $skey, $method, $args, $finisher);
    }

    /** @return $this */
    function run() {
        if ($this->method() === "GET" || $this->method() === "HEAD") {
            $this->status = 404;
        } else {
            $this->status = 500;
        }
        $this->s3->account($this->status);
        return $this;
    }

    /** @return string */
    function response_body() {
        $this->run();
        return "";
    }
}
