<?php
// apispec.php -- HotCRP script for generating OpenAPI specification
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

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
    /** @var object */
    private $j;
    /** @var object */
    private $paths;
    /** @var ?object */
    private $schemas;
    /** @var ?object */
    private $parameters;
    /** @var object */
    private $setj;
    /** @var object */
    private $setj_schemas;
    /** @var object */
    private $setj_parameters;
    /** @var string */
    private $output_file = "-";
    /** @var bool */
    private $batch = false;
    /** @var bool */
    private $override_ref;
    /** @var bool */
    private $override_tags;
    /** @var bool */
    private $override_schema;
    /** @var bool */
    private $sort;
    /** @var array<string,int> */
    private $tag_order;

    static private $default_tag_order = [
        "Submissions", "Documents", "Submission administration",
        "Search", "Tags", "Review preferences", "Reviews", "Comments",
        "Meeting tracker", "Users", "Profile", "Notifications",
        "Site information", "Site administration", "Settings",
        "Session"
    ];

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        if (isset($arg["x"])) {
            $conf->set_opt("apiFunctions", null);
        }
        $this->user = $conf->root_user();

        $this->api_map = $conf->expanded_api_map();
        $this->j = (object) [];
        $this->setj = (object) [
            "paths" => (object) [],
            "components" => (object) [
                "schemas" => (object) [],
                "parameters" => (object) []
            ]
        ];
        $this->setj_schemas = $this->setj->components->schemas;
        $this->setj_parameters = $this->setj->components->parameters;

        if (isset($arg["i"])) {
            if ($arg["i"] === "-") {
                $s = stream_get_contents(STDIN);
            } else {
                $s = file_get_contents_throw(safe_filename($arg["i"]));
            }
            if ($s === false || !is_object($this->j = json_decode($s))) {
                throw new CommandLineException($arg["i"] . ": Invalid input");
            }
            $this->output_file = $arg["i"];
            $this->batch = true;
        }
        if (isset($arg["o"])) {
            $this->output_file = $arg["o"];
        }

        $this->override_ref = isset($arg["override-ref"]);
        $this->override_tags = isset($arg["override-tags"]);
        $this->override_schema = isset($arg["override-schema"]);
        $this->sort = isset($arg["sort"]);
    }

    /** @return int */
    function run() {
        $mj = $this->j;
        $mj->openapi = "3.1.0";
        $info = $mj->info = $mj->info ?? (object) [];
        $info->title = $info->title ?? "HotCRP";
        $info->version = $info->version ?? "0.1";

        // initialize paths
        $this->paths = $mj->paths = $mj->paths ?? (object) [];
        foreach ($this->paths as $name => $pj) {
            $pj->__path = $name;
        }

        // expand paths
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

        // warn about unreferenced paths
        if ($this->batch) {
            foreach ($this->paths as $name => $pj) {
                if (!isset($this->setj->paths->$name)) {
                    fwrite(STDERR, "warning: input path {$name} unknown\n");
                } else {
                    foreach ($pj as $method => $x) {
                        if ($method !== "__path"
                            && !isset($this->setj->paths->$name->$method)) {
                            fwrite(STDERR, "warning: input operation {$method} {$name} unknown\n");
                        }
                    }
                }
            }
        }

        // maybe sort
        if ($this->sort || !$this->batch) {
            $this->sort();
        }

        // erase unwanted keys
        foreach ($this->paths as $pj) {
            unset($pj->__path);
        }

        // print
        if (($this->output_file ?? "-") === "-") {
            $out = STDOUT;
        } else {
            $out = @fopen(safe_filename($this->output_file), "wb");
            if (!$out) {
                throw error_get_last_as_exception("{$this->output_file}: ");
            }
        }
        fwrite($out, json_encode($this->j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        if ($out !== STDOUT) {
            fclose($out);
        }
        return 0;
    }

    static function path_first_tag($pj) {
        foreach ($pj as $name => $oj) {
            if (is_object($oj) && !empty($oj->tags)) {
                return $oj->tags[0];
            }
        }
        return null;
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
        if ($j->redirect ?? false) {
            $known["redirect"] = 0;
        }
        return $known;
    }

    /** @param string $fn */
    private function expand_paths($fn) {
        $getj = null;
        foreach (["GET", "POST"] as $method) {
            if (!($uf = $this->conf->api($fn, null, $method))) {
                continue;
            }
            if ($method === "POST" && !($uf->post ?? false) && ($uf->get ?? false)) {
                continue;
            }
            $known = self::parse_parameters($uf);
            $p = $known["p"] ?? 0;
            if (($p & self::F_REQUIRED) !== 0) {
                $known["p"] |= self::F_PATH;
                $this->expand_path_method("/{p}/{$fn}", $method, $known, $uf);
            } else {
                $this->expand_path_method("/{$fn}", $method, $known, $uf);
            }
        }
    }

    /** @param string $path
     * @param 'GET'|'POST' $method
     * @param array<string,int> $known
     * @param object $uf */
    private function expand_path_method($path, $method, $known, $uf) {
        $pathj = $this->paths->$path = $this->paths->$path ?? (object) [];
        $pathj->__path = $path;
        $this->setj->paths->$path = $this->setj->paths->$path ?? (object) [];
        $lmethod = strtolower($method);
        $xj = $pathj->$lmethod = $pathj->$lmethod ?? (object) [];
        $this->setj->paths->$path->$lmethod = true;
        $this->expand_metadata($xj, $uf, "{$path}.{$lmethod}");
        $this->expand_request($xj, $known, $uf, "{$path}.{$lmethod}");
        $this->expand_response($xj, $uf);
    }

    /** @param object $x
     * @param object $uf
     * @param string $path */
    private function expand_metadata($xj, $uf, $path) {
        if (isset($uf->tags) && (!isset($xj->tags) || $this->override_tags)) {
            $xj->tags = $uf->tags;
        } else if (isset($uf->tags) && $uf->tags !== $xj->tags) {
            fwrite(STDERR, "{$path}: tags differ, expected " . json_encode($xj->tags) . "\n");
        }
        foreach ($xj->tags ?? [] as $tag) {
            $tags = $this->j->tags = $this->j->tags ?? [];
            $i = 0;
            while ($i !== count($tags) && $tags[$i]->name !== $tag) {
                ++$i;
            }
            if ($i === count($tags)) {
                $this->j->tags[] = (object) [
                    "name" => $tag
                ];
            }
        }
    }

    /** @param string $name
     * @return object */
    private function resolve_common_schema($name) {
        if ($this->schemas === null) {
            $compj = $this->j->components = $this->j->components ?? (object) [];
            $this->schemas = $compj->schemas = $compj->schemas ?? (object) [];
        }
        if (!isset($this->schemas->$name)
            || ($this->override_schema && !isset($this->setj_schemas->$name))) {
            if ($name === "pid") {
                $this->schemas->$name = (object) [
                    "type" => "integer",
                    "description" => "Submission ID",
                    "minimum" => 1
                ];
                $this->setj_schemas->$name = true;
            } else if ($name === "ok") {
                return (object) ["type" => "boolean"];
            } else if ($name === "message_list") {
                $this->schemas->$name = (object) [
                    "type" => "list",
                    "items" => $this->resolve_common_schema("message")
                ];
                $this->setj_schemas->$name = true;
            } else if ($name === "message") {
                $this->schemas->$name = (object) [
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
                $this->setj_schemas->$name = true;
            } else if ($name === "minimal_response") {
                $this->schemas->$name = (object) [
                    "type" => "object",
                    "required" => ["ok"],
                    "properties" => (object) [
                        "ok" => (object) ["type" => "boolean"],
                        "message_list" => $this->resolve_common_schema("message_list")
                    ]
                ];
                $this->setj_schemas->$name = true;
            } else if ($name === "error_response") {
                $this->schemas->$name = (object) [
                    "type" => "object",
                    "required" => ["ok"],
                    "properties" => (object) [
                        "ok" => (object) ["type" => "boolean", "description" => "always false"],
                        "message_list" => $this->resolve_common_schema("message_list"),
                        "status_code" => (object) ["type" => "integer"]
                    ]
                ];
                $this->setj_schemas->$name = true;
            } else {
                assert(false);
            }
        }
        return (object) ["\$ref" => "#/components/schemas/{$name}"];
    }

    /** @param string $name
     * @return object */
    private function resolve_common_param($name) {
        if ($this->parameters === null) {
            $compj = $this->j->components = $this->j->components ?? (object) [];
            $this->parameters = $compj->parameters = $compj->parameters ?? (object) [];
        }
        if (!isset($this->parameters->$name)) {
            if ($name === "p.path") {
                $this->parameters->{"p.path"} = (object) [
                    "name" => "p",
                    "in" => "path",
                    "required" => true,
                    "schema" => $this->resolve_common_schema("pid")
                ];
                $this->setj_parameters->{"p.path"} = true;
            } else if ($name === "p") {
                $this->parameters->p = (object) [
                    "name" => "p",
                    "in" => "query",
                    "required" => false,
                    "schema" => $this->resolve_common_schema("pid")
                ];
                $this->setj_parameters->p = true;
            } else if ($name === "redirect") {
                $this->parameters->redirect = (object) [
                    "name" => "redirect",
                    "in" => "query",
                    "required" => false,
                    "schema" => (object) ["type" => "string"]
                ];
                $this->setj_parameters->redirect = true;
            } else {
                assert(false);
            }
        }
        return (object) ["\$ref" => "#/components/parameters/{$name}"];
    }

    /** @param object $x
     * @param array<string,int> $known
     * @param object $uf
     * @param string $path */
    private function expand_request($x, $known, $uf, $path) {
        $params = $bprop = $breq = [];
        $has_file = false;
        foreach ($known as $name => $f) {
            if ($name === "*") {
                // skip
            } else if ($name === "p" && $f === (self::F_REQUIRED | self::F_PATH)) {
                $params["p"] = $this->resolve_common_param("p.path");
            } else if ($name === "p") {
                $params["p"] = $this->resolve_common_param("p");
            } else if ($name === "redirect" && $f === 0) {
                $params["redirect"] = $this->resolve_common_param("redirect");
            } else if (($f & (self::F_BODY | self::F_FILE)) === 0) {
                $params[$name] = (object) [
                    "name" => $name,
                    "in" => "query",
                    "required" => ($f & self::F_REQUIRED) !== 0,
                    "schema" => (object) []
                ];
            } else {
                $bprop[$name] = (object) [
                    "schema" => (object) []
                ];
                if (($f & self::F_REQUIRED) !== 0) {
                    $breq[] = $name;
                }
                if (($f & self::F_FILE) !== 0) {
                    $has_file = true;
                }
            }
        }
        if (!empty($params)) {
            $x->parameters = $x->parameters ?? [];
            $xparams = [];
            foreach ($x->parameters as $i => $pj) {
                if (isset($pj->name) && is_string($pj->name)) {
                    $xparams[$pj->name] = $i;
                } else if (isset($pj->{"\$ref"}) && is_string($pj->{"\$ref"})
                           && preg_match('/\A\#\/components\/parameters\/([^+]*)/', $pj->{"\$ref"}, $m)) {
                    $xparams[$m[1]] = $i;
                }
            }
            foreach ($params as $n => $npj) {
                $i = $xparams[$n] ?? null;
                if ($i === null) {
                    $x->parameters[] = $npj;
                    continue;
                }
                $xpj = $x->parameters[$i];
                if ($this->override_ref && isset($npj->{"\$ref"})) {
                    $x->parameters[$i] = $npj;
                } else if (isset($xpj->{"\$ref"}) !== isset($npj->{"\$ref"})) {
                    fwrite(STDERR, "{$path}.param[{$n}]: \$ref status differs\n");
                } else if (isset($xpj->{"\$ref"})) {
                    if ($xpj->{"\$ref"} !== $npj->{"\$ref"}) {
                        fwrite(STDERR, "{$path}.param[{$n}]: \$ref destination differs\n");
                    }
                } else {
                    foreach ((array) $npj as $k => $v) {
                        if (!isset($xpj->$k)) {
                            $xpj->$k = $v;
                        } else if (is_scalar($v) && $xpj->$k !== $v) {
                            fwrite(STDERR, "{$path}.param[{$n}]: {$k} differs\n");
                        }
                    }
                }
            }
        }
        if (!empty($bprop)) {
            $schema = (object) [
                "type" => "object",
                "properties" => $bprop
            ];
            if (!empty($breq)) {
                $schema->required = $breq;
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
     * @param object $uf */
    private function expand_response($x, $uf) {
        $bprop = $breq = [];
        $response = $uf->response ?? [];
        if (is_string($response)) {
            $response = explode(" ", trim($response));
        }
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
                $bprop[$name] = (object) [];
                if ($required) {
                    $breq[] = $name;
                }
            }
        }

        $rschema = $this->resolve_common_schema("minimal_response");
        if (!empty($bprop)) {
            $restschema = ["type" => "object"];
            if (!empty($breq))  {
                $restschema["required"] = $breq;
            }
            $restschema["properties"] = $bprop;
            $rschema = (object) [
                "allOf" => [$rschema, (object) $restschema]
            ];
        }

        $x->responses = (object) [
            "200" => (object) [
                "description" => "",
                "content" => (object) [
                    "application/json" => (object) [
                        "schema" => $rschema
                    ]
                ]
            ],
            "default" => (object) [
                "description" => "",
                "content" => (object) [
                    "application/json" => (object) [
                        "schema" => $this->resolve_common_schema("error_response")
                    ]
                ]
            ]
        ];
    }

    private function sort() {
        $this->tag_order = [];
        foreach ($this->j->tags ?? [] as $i => $x) {
            if (isset($x->name) && is_string($x->name)) {
                $p = array_search(self::$default_tag_order, $x->name);
                if ($p === false) {
                    $p = count(self::$default_tag_order) + $i;
                }
                $this->tag_order[$x->name] = $p;
            }
        }
        if (isset($this->j->tags)) {
            usort($this->j->tags, function ($a, $b) {
                return $this->tag_order[$a->name] <=> $this->tag_order[$b->name];
            });
        }

        $paths = (array) $this->j->paths;
        uasort($paths, [$this, "compare_paths"]);
        $this->j->paths = (object) $paths;
    }

    function compare_paths($a, $b) {
        $atag = self::path_first_tag($a);
        $btag = self::path_first_tag($b);
        if ($atag !== $btag) {
            if ($atag === null || $btag === null) {
                return $atag === null ? 1 : -1;
            }
            $ato = $this->tag_order[$atag] ?? PHP_INT_MAX;
            $bto = $this->tag_order[$btag] ?? PHP_INT_MAX;
            return $ato <=> $bto ? : strcmp($atag, $btag);
        }
        $an = substr($a->__path, strrpos($a->__path, "/") + 1);
        $bn = substr($b->__path, strrpos($b->__path, "/") + 1);
        $auf = $this->conf->api($an, null, null);
        $buf = $this->conf->api($bn, null, null);
        if ($auf === null || $buf === null) {
            if ($auf !== null || $buf !== null) {
                return $auf === null ? 1 : -1;
            }
        } else {
            $ao = $auf->order ?? PHP_INT_MAX;
            $bo = $buf->order ?? PHP_INT_MAX;
            if ($ao !== $bo) {
                return $ao <=> $bo;
            }
        }
        return strcmp($an, $bn);
    }

    /** @return APISpec_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "x,no-extensions Ignore extensions",
            "i:,input: =FILE Modify existing specification in FILE",
            "override-ref Overwrite conflicting \$refs in input",
            "override-tags",
            "override-schema",
            "sort",
            "o:,output: =FILE Write specification to FILE"
        )->description("Generate an OpenAPI specification.
Usage: php batch/apispec.php")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new APISpec_Batch($conf, $arg);
    }
}
