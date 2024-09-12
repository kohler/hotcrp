<?php
// apispec.php -- HotCRP script for generating OpenAPI specification
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(APISpec_Batch::make_args($argv)->run());
}

class APISpec_Batch {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var array<string,list<object>> */
    public $api_map;
    /** @var array<string,object> */
    public $schemas = [];
    /** @var array<string,object> */
    public $parameters = [];

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $this->api_map = $conf->api_map();
    }

    /** @return int */
    function run() {
        $fns = array_keys($this->api_map);
        sort($fns);
        $paths = [];
        foreach ($fns as $fn) {
            $aj = [];
            foreach ($this->api_map[$fn] as $j) {
                if (!isset($j->alias))
                    $aj[] = $j;
            }
            if (!empty($aj)) {
                $path = ($aj[0]->paper ?? false) ? "/{p}/{$fn}" : "/{$fn}";
                $paths[$path] = $this->expand($fn);
            }
        }
        $components = [];
        if (!empty($this->schemas)) {
            $components["schemas"] = $this->schemas;
        }
        if (!empty($this->parameters)) {
            $components["parameters"] = $this->parameters;
        }
        $j = [
            "openapi" => "3.0.0",
            "info" => [
                "title" => "HotCRP"
            ],
            "paths" => $paths
        ];
        if (!empty($components)) {
            $j["components"] = $components;
        }
        fwrite(STDOUT, json_encode($j, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        return 0;
    }

    /** @return mixed */
    private function expand($fn) {
        $x = [];
        foreach (["GET", "POST"] as $method) {
            if (($j = $this->conf->api($fn, null, $method))) {
                $x[strtolower($method)] = $this->expand1($fn, $method, $j);
            }
        }
        return $x;
    }

    /** @param string $name
     * @return array */
    private function resolve_common_schema($name) {
        if (!isset($this->schemas[$name])) {
            if ($name === "pid") {
                $this->schemas[$name] = (object) [
                    "type" => "integer",
                    "minimum" => 1
                ];
            } else {
                assert(false);
            }
        }
        return ["\$ref" => "#/components/schemas/{$name}"];
    }

    /** @param string $name
     * @return array */
    private function resolve_common_param($name) {
        if (!isset($this->parameters[$name])) {
            if ($name === "p") {
                $this->parameters[$name] = (object) [
                    "name" => "p",
                    "in" => "path",
                    "required" => true,
                    "schema" => $this->resolve_common_schema("pid")
                ];
            } else {
                assert(false);
            }
        }
        return ["\$ref" => "#/components/parameters/{$name}"];
    }

    /** @return object */
    private function expand1($fn, $method, $j) {
        $x = (object) [];
        $params = $body_properties = $body_required = [];
        $has_file = false;
        if ($j->paper ?? false) {
            $params[] = $this->resolve_common_param("p");
        }
        foreach ($j->parameters ?? [] as $p) {
            $required = true;
            $in = "query";
            for ($i = 0; $i !== strlen($p); ++$i) {
                if ($p[$i] === "?") {
                    $required = false;
                } else if ($p[$i] === "=") {
                    $in = "body";
                } else if ($p[$i] === "@") {
                    $in = "file";
                } else if ($p[$i] === ":") {
                    // suffixed parameter
                } else {
                    break;
                }
            }
            $name = substr($p, $i);
            if ($in === "query") {
                $params[] = ["name" => $name, "in" => $in, "required" => $required];
            } else {
                $body_properties[] = ["name" => $name];
                if ($required) {
                    $body_required[] = $name;
                }
                if ($in === "file") {
                    $has_file = true;
                }
            }
        }
        if (!empty($params)) {
            $x->parameters = $params;
        }
        if (!empty($body_properties)) {
            $schema = (object) [
                "type" => "object",
                "properties" => $body_properties
            ];
            if (!empty($body_required)) {
                $schema->required = $body_required;
            }
            $formtype = $has_file ? "multipart/form-data" : "application/x-www-form-urlencoded";
            $x->requestBody = (object) [
                "content" => (object) [
                    $formtype => (object) [
                        "schema" => $schema
                    ]
                ]
            ];
        }
        return $x;
    }

    /** @return APISpec_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !"
        )->description("Generate an OpenAPI specification.
Usage: php batch/apispec.php")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new APISpec_Batch($conf, $arg);
    }
}
