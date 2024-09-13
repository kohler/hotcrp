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
    /** @var array<string,array> */
    public $paths = [];
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
        foreach ($fns as $fn) {
            $aj = [];
            foreach ($this->api_map[$fn] as $j) {
                if (!isset($j->alias))
                    $aj[] = $j;
            }
            if (!empty($aj)) {
                $this->expand_paths($fn);
            }
        }
        $j = [
            "openapi" => "3.1.0",
            "info" => [
                "title" => "HotCRP",
                "version" => "0.1"
            ],
            "paths" => $this->paths,
            "components" => []
        ];
        if (!empty($this->schemas)) {
            $j["components"]["schemas"] = $this->schemas;
        }
        if (!empty($this->parameters)) {
            $j["components"]["parameters"] = $this->parameters;
        }
        fwrite(STDOUT, json_encode($j, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        return 0;
    }

    const F_REQUIRED = 1;
    const F_BODY = 2;
    const F_FILE = 4;
    const F_SUFFIX = 8;
    const F_PATH = 16;

    /** @param object $j
     * @return array<string,int> */
    static private function parse_parameters($j) {
        $known = [];
        if ($j->paper ?? false) {
            $known["p"] = self::F_REQUIRED;
        }
        if ($j->redirect ?? false) {
            $known["redirect"] = 0;
        }
        $parameters = $j->parameters ?? [];
        if (is_string($parameters)) {
            $parameters = explode(" ", trim($parameters));
        }
        foreach ($parameters as $p) {
            $flags = self::F_REQUIRED;
            for ($i = 0; $i !== strlen($p); ++$i) {
                if ($p[$i] === "?") {
                    $flags &= ~self::F_REQUIRED;
                } else if ($p[$i] === "=") {
                    $flags |= self::F_BODY;
                } else if ($p[$i] === "@") {
                    $flags |= self::F_FILE;
                } else if ($p[$i] === ":") {
                    $flags |= self::F_SUFFIX;
                } else {
                    break;
                }
            }
            $name = substr($p, $i);
            $known[$name] = $flags;
        }
        return $known;
    }

    /** @param string $fn
     * @return mixed */
    private function expand_paths($fn) {
        foreach (["GET", "POST"] as $method) {
            if (!($j = $this->conf->api($fn, null, $method))) {
                continue;
            }
            $known = self::parse_parameters($j);
            $p = $known["p"] ?? null;
            if ($p !== null) {
                $known["p"] = self::F_REQUIRED | self::F_PATH;
                $this->expand_path_method("/{p}/{$fn}", $method, $known, $j);
            }
            if ($p !== self::F_REQUIRED) {
                unset($known["p"]);
                $this->expand_path_method("/{$fn}", $method, $known, $j);
            }
        }
    }

    /** @param string $path
     * @param 'GET'|'POST' $method
     * @param array<string,int> $known
     * @param object $j
     * @param array &$x
     * @return object */
    private function expand_path_method($path, $method, $known, $j) {
        $this->paths[$path] = $this->paths[$path] ?? [];
        $this->paths[$path][strtolower($method)] = $x = (object) [];
        $this->expand_request($x, $known, $j);
        $this->expand_response($x, $j);
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
            } else if ($name === "ok") {
                return (object) ["type" => "boolean"];
            } else if ($name === "message_list") {
                $this->schemas[$name] = (object) [
                    "type" => "list",
                    "items" => $this->resolve_common_schema("message")
                ];
            } else if ($name === "message") {
                $this->schemas[$name] = (object) [
                    "type" => "object",
                    "required" => ["status"],
                    "properties" => (object) [
                        "field" => (object) ["type" => "string"],
                        "message" => (object) ["type" => "string"],
                        "status" => (object) ["type" => "integer", "minimum" => -5, "maximum" => 3],
                        "context" => (object) ["type" => "string"],
                        "pos1" => (object) ["type" => "integer"],
                        "pos2" => (object) ["type" => "integer"]
                    ]
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
                $this->parameters["p"] = (object) [
                    "name" => "p",
                    "in" => "path",
                    "required" => true,
                    "schema" => $this->resolve_common_schema("pid")
                ];
            } else if ($name === "redirect") {
                $this->parameters["redirect"] = (object) [
                    "name" => "redirect",
                    "in" => "query",
                    "required" => false,
                    "schema" => (object) ["type" => "string"]
                ];
            } else {
                assert(false);
            }
        }
        return ["\$ref" => "#/components/parameters/{$name}"];
    }

    /** @param object $x
     * @param array<string,int> $known
     * @param object $j
     * @return object */
    private function expand_request($x, $known, $j) {
        $params = $body_properties = $body_required = [];
        $has_file = false;
        foreach ($known as $name => $f) {
            if ($name === "*") {
                // skip
            } else if ($name === "p" && $f === (self::F_REQUIRED | self::F_PATH)) {
                $params[] = $this->resolve_common_param("p");
            } else if ($name === "redirect" && $f === 0) {
                $params[] = $this->resolve_common_param("redirect");
            } else if (($f & (self::F_BODY | self::F_FILE)) === 0) {
                $params[] = [
                    "name" => $name,
                    "in" => "query",
                    "required" => ($f & self::F_REQUIRED) !== 0,
                    "schema" => (object) []
                ];
            } else {
                $body_properties[$name] = [
                    "schema" => (object) []
                ];
                if (($f & self::F_REQUIRED) !== 0) {
                    $body_required[] = $name;
                }
                if (($f & self::F_FILE) !== 0) {
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
                "description" => "",
                "content" => (object) [
                    $formtype => (object) [
                        "schema" => (object) $schema
                    ]
                ]
            ];
        }
    }

    /** @param object $x
     * @param object $j
     * @return object */
    private function expand_response($x, $j) {
        $body_properties = $body_required = [];
        $response = $j->response ?? [];
        if (is_string($response)) {
            $response = explode(" ", trim($response));
        }
        $body_properties["ok"] = $this->resolve_common_schema("ok");
        $body_required[] = "ok";
        $body_properties["message_list"] = $this->resolve_common_schema("message_list");
        foreach ($response as $p) {
            $required = true;
            for ($i = 0; $i !== strlen($p); ++$i) {
                if ($p[$i] === "?") {
                    $required = false;
                } else {
                    break;
                }
            }
            $name = substr($p, $i);
            if ($name === "*") {
                // skip
            } else {
                $body_properties[$name] = (object) [];
                if ($required) {
                    $body_required[] = $name;
                }
            }
        }
        $x->responses = (object) [
            "default" => (object) [
                "description" => "",
                "content" => (object) [
                    "application/json" => (object) [
                        "schema" => (object) [
                            "type" => "object",
                            "required" => $body_required,
                            "properties" => $body_properties
                        ]
                    ]
                ]
            ]
        ];
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
