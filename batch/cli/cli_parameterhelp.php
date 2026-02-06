<?php
// cli_assign.php -- Hotcrapi script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class ParameterHelp_CLIBatch {
    /** @var string */
    public $subcommand;
    /** @var string */
    public $api_endpoint;
    /** @var string */
    public $key;
    /** @var string */
    public $title;
    /** @var ?string */
    public $action;
    /** @var string */
    public $trailer = "";
    /** @var bool */
    public $help_prefix = false;
    /** @var ?bool */
    public $show_title;
    /** @var bool */
    public $json = false;
    /** @var ?callable(object):(list<object>) */
    public $expand_callback;

    /** @return int */
    function run_help(Hotcrapi_Batch $clib) {
        $curlh = $clib->make_curl();
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($curlh, CURLOPT_URL, "{$clib->site}/{$this->api_endpoint}");
        if (!$clib->exec_api($curlh, null)) {
            if (!$this->action) {
                $clib->set_output($clib->getopt->help($this->subcommand)
                    . "\n\n" . pluralize($this->title) . " cannot be loaded\n");
            }
            return 1;
        }
        if ($this->json) {
            $clib->set_output_json($clib->content_json->{$this->key} ?? []);
            return 0;
        }
        $x = [];
        if ($clib->getopt && $this->help_prefix) {
            $x[] = $clib->getopt->help($this->subcommand);
        }
        if ($this->show_title ?? !$this->action) {
            $x[] = pluralize($this->title) . ":\n";
            $fmt = "  %-23s %s\n";
            $indent = "  ";
        } else {
            $fmt = "%-25s %s\n";
            $indent = "";
        }
        $jsons = $clib->content_json->{$this->key} ?? [];
        if ($this->action) {
            $jsons = array_filter($jsons, function ($aj) {
                return $aj->name === $this->action;
            });
        }
        if ($this->expand_callback) {
            $jsons = $this->expand($jsons);
        }
        foreach ($jsons as $aj) {
            if (isset($aj->title)) {
                $t = $aj->title;
            } else if (isset($aj->description)) {
                $t = Ftext::as(0, $aj->description, 0);
                if (!$this->action && ($nl = strpos($t, "\n")) !== false) {
                    $t = substr($t, 0, $nl);
                }
            } else {
                $t = "";
            }
            if ($this->action) {
                $x[] = $t !== "" ? "{$this->title} {$aj->name}:\n  {$t}\n" : "{$this->title} {$aj->name}\n";
            } else if ($t !== "") {
                $x[] = sprintf($fmt, $aj->name, $t);
            } else {
                $x[] = "{$indent}{$aj->name}\n";
            }
            if ($this->action && !empty($aj->parameters)) {
                $x[] = "\nParameters:\n";
                foreach ($aj->parameters as $pj) {
                    $x[] = ViewOptionType::make($pj)->unparse_help_line();
                }
                $x[] = "\n";
            }
        }
        if ($clib->getopt && $this->action && empty($x)) {
            $clib->error_at(null, "<0>{$this->title} not found");
            return 1;
        }
        if ($this->trailer !== "") {
            $x[] = "\n{$this->trailer}\n";
        }
        $clib->set_output(join("", $x));
        return 0;
    }

    private function expand($json_list) {
        $js = [];
        foreach ($json_list as $aj) {
            array_push($js, ...call_user_func($this->expand_callback, $aj));
        }
        return $js;
    }
}
