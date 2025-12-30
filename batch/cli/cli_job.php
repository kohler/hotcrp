<?php
// cli_job.php -- HotCRP script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Job_CLIBatch {
    /** @var string
     * @readonly */
    public $job;
    /** @var bool
     * @readonly */
    public $delay_first = false;
    /** @var int
     * @readonly */
    public $delay = 500000;
    /** @var float
     * @readonly */
    public $backoff = 1.5;
    /** @var int
     * @readonly */
    public $max_delay = 5000000;

    /** @param string $job_id */
    function __construct($job_id) {
        $this->job = $job_id;
    }

    /** @param bool $delay_first
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_delay_first($delay_first) {
        $this->delay_first = $delay_first;
        return $this;
    }

    /** @param int $delay_ms
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_delay($delay_ms) {
        assert($delay_ms > 100000);
        $this->delay = max($delay_ms, 100000);
        return $this;
    }

    /** @param float $backoff
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_backoff($backoff) {
        assert($backoff >= 1.0);
        $this->backoff = max($backoff, 1.0);
        return $this;
    }

    /** @param int $max_delay_ms
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_max_delay($max_delay_ms) {
        assert($max_delay_ms > 100000);
        $this->max_delay = max($max_delay_ms, 100000);
        return $this;
    }

    /** @return bool */
    function run(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl("GET");
        $args = "job=" . urlencode($this->job) . "&output=string";
        $status = null;
        $first = true;
        while (true) {
            curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/job?{$args}");
            if ($first && $this->delay_first) {
                $clib->content_json = (object) ["status" => "wait"];
            } else if (!$clib->exec_api($curlh)) {
                return false;
            }
            $first = false;
            $status = $clib->content_json->status ?? "failed";
            if ($status !== "wait" && $status !== "run") {
                break;
            }
            if (is_string($clib->content_json->progress ?? null)) {
                $clib->set_progress_text($clib->content_json->progress);
            }
            $progress_value = $clib->content_json->progress_value ?? null;
            if (!is_int($progress_value) && !is_float($progress_value)) {
                $progress_value = null;
            }
            $progress_max = $clib->content_json->progress_max ?? null;
            if (!is_int($progress_max) && !is_float($progress_max)) {
                $progress_max = null;
            }
            $clib->progress_show($progress_value, $progress_max);
            time_nanosleep((int) ($this->delay / 1000000), ($this->delay % 1000000) * 1000);
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->delay = min($this->delay * $this->backoff, $this->max_delay);
        }
        return $status === "done";
    }
}
