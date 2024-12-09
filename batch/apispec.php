<?php
// apispec.php -- HotCRP script for generating OpenAPI specification
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    APISpec_Batch::run_args($argv);
}

class APISpec_Batch {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var XtParams */
    private $xtp;
    /** @var bool */
    private $base;
    /** @var array<string,list<object>> */
    public $api_map;
    /** @var object */
    private $j;
    /** @var ?JsonParser */
    private $jparser;
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
    /** @var object */
    private $setj_tags;
    /** @var array<string,list<object>> */
    public $description_map;
    /** @var string */
    private $output_file = "-";
    /** @var bool */
    private $batch = false;
    /** @var bool */
    private $override_ref;
    /** @var bool */
    private $override_param;
    /** @var bool */
    private $override_response;
    /** @var bool */
    private $override_tags;
    /** @var bool */
    private $override_schema;
    /** @var bool */
    private $override_description;
    /** @var bool */
    private $sort;
    /** @var array<string,int> */
    private $tag_order;

    /** @var string */
    private $cur_path;
    /** @var string */
    private $cur_lmethod;
    /** @var int */
    private $cur_ptype;
    /** @var string */
    private $cur_psubtype;
    /** @var array<string,int> */
    private $cur_fieldf;
    /** @var array<string,object> */
    private $cur_fields;
    /** @var array<string,string> */
    private $cur_fieldd;
    /** @var list<string> */
    private $cur_fieldsch;

    const PT_QUERY = 1;
    const PT_BODY = 2;
    const PT_RESPONSE = 3;

    static private $default_tag_order = [
        "Submissions", "Documents", "Submission administration",
        "Search", "Tags", "Review preferences", "Reviews", "Comments",
        "Meeting tracker", "Users", "Profile", "Notifications",
        "Site information", "Site administration", "Settings",
        "Session"
    ];

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $this->xtp = new XtParams($this->conf, null);
        $this->base = isset($arg["x"]);

        $this->api_map = $conf->expanded_api_map();
        $this->j = (object) [];
        $this->setj_schemas = (object) [];
        $this->setj_parameters = (object) [];
        $this->setj_tags = (object) [];
        $this->setj = (object) [
            "paths" => (object) [],
            "components" => (object) [
                "schemas" => $this->setj_schemas,
                "parameters" => $this->setj_parameters
            ],
            "tags" => $this->setj_tags
        ];

        $this->description_map = [];
        foreach ([["?devel/apidoc/*.md"], $conf->opt("apiDescriptions")] as $desc) {
            expand_json_includes_callback($desc, [$this, "_add_description_item"], "APISpec_Batch::parse_description_markdown");
        }

        if (isset($arg["i"])
            || ($this->base && !isset($arg["o"]))) {
            $filearg = $arg["i"] ?? "devel/apidoc/openapi-base.json";
            if ($filearg === "-") {
                $filename = "<stdin>";
                $s = stream_get_contents(STDIN);
            } else {
                $filename = safe_filename($filearg);
                $s = file_get_contents_throw($filename);
            }
            if ($s !== false) {
                $this->jparser = (new JsonParser($s))->set_filename($filename);
                $this->j = $this->jparser->decode();
            }
            if ($s === false || !is_object($this->j)) {
                $msg = $filearg . ": Invalid input";
                if ($this->j === null && $this->jparser->last_error()) {
                    $msg .= ": " . $this->jparser->last_error_msg();
                }
                throw new CommandLineException($msg);
            }
            $this->output_file = $arg["i"] ?? "devel/openapi.json";
            $this->batch = true;
        }
        if (isset($arg["o"])) {
            $this->output_file = $arg["o"];
        }

        $this->override_ref = isset($arg["override-ref"]);
        $this->override_param = isset($arg["override-param"]);
        $this->override_response = isset($arg["override-response"]);
        $this->override_tags = isset($arg["override-tags"]);
        $this->override_schema = isset($arg["override-schema"]);
        $this->override_description = !isset($arg["no-override-description"]);
        $this->sort = !isset($arg["no-sort"]);
    }


    /** @param mixed $x
     * @return bool */
    static function is_empty_object($x) {
        return is_object($x) && empty(get_object_vars($x));
    }


    // ERROR MESSAGES

    /** @return string */
    private function jpath_landmark($jpath) {
        $lm = $jpath ? $this->jparser->path_landmark($jpath, false) : null;
        return $lm ? "{$lm}: " : "";
    }

    /** @param null|int|string $paramid
     * @return string */
    private function cur_landmark($paramid = null) {
        $prefix = "{$this->cur_path}.{$this->cur_lmethod}: ";
        $jpath = "\$.paths[\"{$this->cur_path}\"].{$this->cur_lmethod}";
        if ($paramid === null) {
            // path for operation
        } else if ($this->cur_ptype === self::PT_QUERY && is_int($paramid)) {
            $jpath .= ".parameters[{$paramid}]";
        } else if ($this->cur_ptype === self::PT_BODY && is_string($paramid)) {
            $jpath .= ".requestBody.content[\"{$this->cur_psubtype}\"].schema";
            if ($paramid === "\$required") {
                $jpath .= ".required";
            } else {
                $jpath .= ".properties[\"{$paramid}\"]";
            }
        } else if ($this->cur_ptype === self::PT_RESPONSE && is_string($paramid)) {
            $jpath .= ".responses[200].content[\"application/json\"].schema.allOf[{$this->cur_psubtype}]";
            if ($paramid === "\$required") {
                $jpath .= ".required";
            } else {
                $jpath .= ".properties[\"{$paramid}\"]";
            }
        } else {
            return "";
        }
        return $this->jpath_landmark($jpath);
    }

    /** @param int|string $paramid
     * @return string */
    private function cur_prefix($paramid = null) {
        return $this->cur_landmark($paramid) . "{$this->cur_path}.{$this->cur_lmethod}: ";
    }

    /** @return string */
    private function cur_field_description() {
        if ($this->cur_ptype === self::PT_QUERY) {
            return "parameter";
        } else if ($this->cur_ptype === self::PT_BODY) {
            return "body parameter";
        } else {
            return "response field";
        }
    }


    function _add_description_item($xt) {
        if (isset($xt->name) && is_string($xt->name)) {
            $this->description_map[$xt->name][] = $xt;
            return true;
        }
        return false;
    }

    static function parse_description_markdown($s, $landmark) {
        if (!str_starts_with($s, "#")) {
            return null;
        }
        $s = cleannl($s);
        if ($s !== "" && !str_ends_with($s, "\n")) {
            $s .= "\n";
        }
        $m = preg_split('/^\#\s+([^\n]*?)\s*\n/m', cleannl($s), -1, PREG_SPLIT_DELIM_CAPTURE);
        $xs = [];
        $lineno = 1;
        for ($i = 1; $i < count($m); $i += 2) {
            $x = ["name" => simplify_whitespace($m[$i]), "landmark" => "{$landmark}:{$lineno}"];
            $d = cleannl(ltrim($m[$i + 1]));
            if (str_starts_with($d, "> ")) {
                preg_match('/\A(?:^> .*?\n)+/m', $d, $mx);
                $x["summary"] = simplify_whitespace(str_replace("\n> ", "", substr($mx[0], 2)));
                $d = ltrim(substr($d, strlen($mx[0])));
            }
            if (preg_match('/(?:\n|\A)(?=\*)(?:\* (?:param|parameter|response|response_schema) [^\n]*+\n|  [^\n]*+\n|[ \t]*+\n)+\z/s', $d, $mx)) {
                $d = cleannl(substr($d, 0, -strlen($mx[0])));
                $x["fields"] = ltrim($mx[0]);
            }
            if ($d !== "") {
                $x["description"] = $d;
            }
            $xs[] = (object) $x;
            $lineno += 1 + substr_count($m[$i + 1], "\n");
        }
        return $xs;
    }

    /** @param string $name
     * @return ?object */
    private function find_description($name) {
        if (!isset($this->description_map[$name])) {
            return null;
        }
        return $this->xtp->search_name($this->description_map, $name);
    }

    /** @param object $xj
     * @param object $dj */
    private function merge_description_from($xj, $dj) {
        if (isset($dj->summary)
            && ($this->override_description || ($xj->summary ?? "") === "")) {
            $xj->summary = $dj->summary;
        }
        if (isset($dj->description)
            && ($this->override_description || ($xj->description ?? "") === "")) {
            $xj->description = $dj->description;
        }
    }

    /** @param string $name
     * @param object $xj */
    private function merge_description($name, $xj) {
        if (($dj = $this->find_description($name))) {
            $this->merge_description_from($xj, $dj);
        }
    }


    const F_REQUIRED = 0x01;
    const F_POST = 0x02;
    const F_BODY = 0x04;
    const F_FILE = 0x08;
    const F_SUFFIX = 0x10;
    const F_PATH = 0x20;
    const F_DEFAULT = 0x40;
    const FM_NONGET = 0x0E;

    /** @param string $p
     * @param int $f
     * @return array{string,int} */
    static function parse_field_name($p, $f = self::F_DEFAULT | self::F_REQUIRED) {
        for ($i = 0; $i !== strlen($p); ++$i) {
            if ($p[$i] === "?") {
                $f &= ~(self::F_REQUIRED | self::F_DEFAULT);
            } else if ($p[$i] === "!") {
                $f = ($f & ~self::F_DEFAULT) | self::F_REQUIRED;
            } else if ($p[$i] === "+") {
                $f |= self::F_POST;
            } else if ($p[$i] === "=") {
                $f |= self::F_BODY;
            } else if ($p[$i] === "@") {
                $f |= self::F_FILE;
            } else if ($p[$i] === ":") {
                $f |= self::F_SUFFIX;
            } else {
                break;
            }
        }
        return [substr($p, $i), $f];
    }

    /** @param string $name
     * @param int $f */
    private function add_field($name, $f) {
        if (!isset($this->cur_fieldf[$name])) {
            $this->cur_fieldf[$name] = $f;
        } else if (($f & self::F_DEFAULT) !== 0) {
            $this->cur_fieldf[$name] |= $f & ~(self::F_DEFAULT | self::F_REQUIRED);
        } else {
            $this->cur_fieldf[$name] = ($this->cur_fieldf[$name] & ~(self::F_DEFAULT | self::F_REQUIRED)) | $f;
        }
    }

    /** @param object $j */
    private function parse_basic_parameters($j) {
        $this->cur_fieldf = [];
        $this->cur_fields = [];
        $this->cur_fieldd = [];

        if ($j->paper ?? false) {
            $this->cur_fieldf["p"] = self::F_REQUIRED;
        }
        $parameters = $j->parameters ?? [];
        if (is_string($parameters)) {
            $parameters = explode(" ", trim($parameters));
        }
        foreach ($parameters as $p) {
            list($name, $f) = self::parse_field_name($p);
            $this->add_field($name, $f);
        }
        if ($j->redirect ?? false) {
            $this->add_field("redirect", 0);
        }
    }

    /** @param string $fn */
    private function expand_paths($fn) {
        foreach (["get", "post"] as $lmethod) {
            if (!($uf = $this->conf->api($fn, null, strtoupper($lmethod)))) {
                continue;
            }
            if ($lmethod === "post" && !($uf->post ?? false) && ($uf->get ?? false)) {
                continue;
            }
            $this->parse_basic_parameters($uf);
            $p = $this->cur_fieldf["p"] ?? 0;
            if (($p & self::F_REQUIRED) !== 0) {
                $this->cur_fieldf["p"] |= self::F_PATH;
                $path = "/{p}/{$fn}";
            } else {
                $path = "/{$fn}";
            }
            $this->expand_path_method($path, $lmethod, $uf);
        }
    }

    /** @param string $path
     * @param 'get'|'post' $lmethod
     * @param object $uf */
    private function expand_path_method($path, $lmethod, $uf) {
        $this->cur_path = $path;
        $this->cur_lmethod = $lmethod;

        $pathj = $this->paths->$path = $this->paths->$path ?? (object) [];
        $pathj->__path = $path;
        if (!isset($this->setj->paths->$path)) {
            $this->merge_description($path, $pathj);
            $this->setj->paths->$path = (object) [];
        }
        $xj = $pathj->$lmethod = $pathj->$lmethod ?? (object) [];
        if (!isset($this->setj->paths->$path->$lmethod)) {
            if ($this->override_description || ($xj->summary ?? "") === "") {
                $xj->summary = $path;
            }
            $this->setj->paths->$path->$lmethod = true;
        }

        $dj = $this->find_description("{$this->cur_lmethod} {$this->cur_path}");
        $this->expand_metadata($xj, $uf, $dj);
        $this->expand_request($xj, $uf, $dj);
        $this->expand_response($xj, $uf, $dj);
    }

    /** @param object $xj
     * @param object $uf
     * @param ?object $dj */
    private function expand_metadata($xj, $uf, $dj) {
        if ($dj) {
            $this->merge_description_from($xj, $dj);
        }
        if (isset($uf->tags)
            && (!isset($xj->tags) || $this->override_tags)) {
            $xj->tags = $uf->tags;
        } else if (isset($uf->tags) && $uf->tags !== $xj->tags) {
            fwrite(STDERR, $this->cur_prefix() . "tags differ, expected " . json_encode($xj->tags) . "\n");
        }
        if (empty($xj->tags)) {
            fwrite(STDERR, $this->cur_prefix() . "tags missing\n");
            return;
        }
        foreach ($xj->tags as $tag) {
            if (isset($this->setj_tags->$tag)) {
                continue;
            }
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
            $this->merge_description($tag, $this->j->tags[$i]);
            $this->setj_tags->$tag = true;
        }
    }

    /** @param string $name
     * @return object */
    private function reference_common_schema($name) {
        if (in_array($name, ["string", "number", "integer", "boolean", "null"])) {
            return (object) ["type" => $name];
        } else if ($name === "nonnegative_integer") {
            return (object) ["type" => "integer", "minimum" => 0];
        }
        if ($this->schemas === null) {
            $compj = $this->j->components = $this->j->components ?? (object) [];
            $this->schemas = $compj->schemas = $compj->schemas ?? (object) [];
        }
        $nj = $this->schemas->$name ?? null;
        if (!$nj || ($this->override_schema && !isset($this->setj_schemas->$name))) {
            if ($name === "pid") {
                $nj = (object) [
                    "type" => "integer",
                    "description" => "Submission ID",
                    "minimum" => 1
                ];
            } else if ($name === "rid") {
                $nj = (object) [
                    "oneOf" => [
                        (object) ["type" => "integer", "minimum" => 1],
                        (object) ["type" => "string"]
                    ],
                    "description" => "Review ID"
                ];
            } else if ($name === "cid") {
                $nj = (object) [
                    "oneOf" => [
                        (object) ["type" => "integer", "minimum" => 1],
                        (object) ["type" => "string", "examples" => ["new", "response", "R2response"]]
                    ],
                    "description" => "Comment ID"
                ];
            } else if ($name === "ok") {
                $nj = (object) [
                    "type" => "boolean",
                    "description" => "Success marker"
                ];
            } else if ($name === "message_list") {
                $nj = (object) [
                    "type" => "list",
                    "description" => "Diagnostic list",
                    "items" => $this->reference_common_schema("message")
                ];
            } else if ($name === "message") {
                $nj = (object) [
                    "type" => "object",
                    "description" => "Diagnostic",
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
            } else if ($name === "minimal_response") {
                $nj = (object) [
                    "type" => "object",
                    "required" => ["ok"],
                    "properties" => (object) [
                        "ok" => (object) ["type" => "boolean"],
                        "message_list" => $this->reference_common_schema("message_list")
                    ]
                ];
            } else if ($name === "error_response") {
                $nj = (object) [
                    "type" => "object",
                    "required" => ["ok"],
                    "properties" => (object) [
                        "ok" => (object) ["type" => "boolean", "description" => "always false"],
                        "message_list" => $this->reference_common_schema("message_list"),
                        "status_code" => (object) ["type" => "integer"]
                    ]
                ];
            } else {
                throw new CommandLineException("Common schema `{$name}` unknown");
                assert(false);
            }
            $this->schemas->$name = $nj;
        }
        if (!isset($this->setj_schemas->$name)) {
            $this->merge_description("schema {$name}", $nj);
            $this->setj_schemas->$name = true;
        }
        return (object) ["\$ref" => "#/components/schemas/{$name}"];
    }

    static private $param_schemas = [
        "p" => "pid", "r" => "rid", "c" => "cid",
        "q" => "search_string", "t" => "search_collection", "qt" => "search_qt",
        "reviewer" => "search_reviewer", "sort" => "search_sort", "scoresort" => "search_scoresort",
        "redirect" => "string", "forceShow" => "boolean"
    ];
    static private $param_required = [
        "p" => true, "r" => true, "c" => true
    ];

    /** @param string $name
     * @return object */
    private function reference_common_param($name) {
        if ($this->parameters === null) {
            $compj = $this->j->components = $this->j->components ?? (object) [];
            $this->parameters = $compj->parameters = $compj->parameters ?? (object) [];
        }
        $nj = $this->parameters->$name ?? null;
        if ($nj === null || ($this->override_schema && !isset($this->setj_parameters->$name))) {
            if (str_ends_with($name, ".path")) {
                $xname = substr($name, 0, -5);
                $in = "path";
                $required = true;
            } else if (str_ends_with($name, ".opt")) {
                $xname = substr($name, 0, -4);
                $in = "query";
                $required = false;
            } else {
                $xname = $name;
                $in = "query";
                $required = self::$param_required[$xname] ?? false;
            }
            if (isset(self::$param_schemas[$xname])) {
                $nj = (object) [
                    "name" => $xname,
                    "in" => $in,
                    "required" => $required,
                    "schema" => $this->reference_common_schema(self::$param_schemas[$xname])
                ];
            } else {
                assert(false);
            }
            $this->parameters->$name = $nj;
        }
        if (!isset($this->setj_parameters->$name)) {
            $this->merge_description("parameter {$name}", $nj);
            $this->setj_parameters->$name = true;
        }
        return (object) ["\$ref" => "#/components/parameters/{$name}"];
    }

    /** @param object $x
     * @param ?string $component
     * @return ?object */
    private function resolve_reference($x, $component = null) {
        if (!is_object($x)
            || !isset($x->{"\$ref"})
            || !is_string($x->{"\$ref"})
            || !str_starts_with($x->{"\$ref"}, "#/")) {
            return null;
        }
        if ($component !== null
            && !str_starts_with($x->{"\$ref"}, "#/components/{$component}/")) {
            return null;
        }
        $j = $this->j;
        foreach (explode("/", substr($x->{"\$ref"}, 2)) as $pathpart) {
            if (!is_object($j)
                || !isset($j->{$pathpart})) {
                return null;
            }
            $j = $j->{$pathpart};
        }
        return $j;
    }

    private function resolve_info($info, $name) {
        if ($info === null) {
            return (object) [];
        } else if (is_object($info)) {
            return $info;
        } else if (!is_string($info) || $info === "") {
            fwrite(STDERR, $this->cur_prefix() . "bad info for " . $this->cur_field_description() . " `{$name}`\n");
            return (object) [];
        } else if (str_starts_with($info, "[") && str_ends_with($info, "]")) {
            return (object) ["type" => "array", "items" => $this->resolve_info(substr($info, 1, -1), $name)];
        } else if (($s = $this->reference_common_schema($info))) {
            return $s;
        } else {
            fwrite(STDERR, $this->cur_prefix() . "unknown type `{$info}` for " . $this->cur_field_description() . " `{$name}`\n");
            return (object) [];
        }
    }

    private function parse_description_fields($params, $response, $landmark) {
        $pos = 0;
        while (preg_match('/\G\* (param|parameter|response|response_schema)[ \t]++([?!+=@:]*+[^\s:]++)[ \t]*+(|[^\s:]++)[ \t]*+(:[^\n]*+(?:\n|\z)(?:  [^\n]++\n|[ \t]++\n)*+|\n)(?:[ \t]*+\n)*+/', $params, $m, 0, $pos)) {
            $pos += strlen($m[0]);
            if (str_starts_with($m[1], "response") !== $response) {
                continue;
            }
            if ($m[1] === "response_schema") {
                if ($this->reference_common_schema($m[2])) {
                    if (!in_array($m[2], $this->cur_fieldsch)) {
                        $this->cur_fieldsch[] = $m[2];
                    }
                } else {
                    fwrite(STDERR, "{$landmark}: {$this->cur_prefix()}: unknown response_schema `{$m[2]}`\n");
                }
                continue;
            }
            list($name, $f) = self::parse_field_name($m[2]);
            $this->add_field($name, $f);
            if ($m[3] !== "") {
                $info = self::resolve_info($m[3], $name);
                if (!self::is_empty_object($info)) {
                    $this->cur_fields[$name] = $info;
                }
            }
            if (str_starts_with($m[4], ":")
                && ($d = trim(substr($m[4], 1))) !== "") {
                $this->cur_fieldd[$name] = $d;
            }
        }
    }

    private function parse_field_info($pinfo) {
        foreach (get_object_vars($pinfo) as $p => $info) {
            list($name, $f) = self::parse_field_name($p, self::F_DEFAULT | self::F_REQUIRED);
            $info = self::resolve_info($info, $name);
            if (isset($info->required)) {
                $f = ($f & ~(self::F_DEFAULT | self::F_REQUIRED)) | ($info->required ? self::F_DEFAULT : 0);
                unset($info->required);
            }
            $this->add_field($name, $f);
            if (isset($info->description)) {
                $this->cur_fieldd[$name] = $info->description;
                unset($info->description);
            }
            if (!empty(get_object_vars($info))) {
                $this->cur_fields[$name] = $info;
            }
        }
    }

    /** @param string $name
     * @param int $f
     * @return ?string */
    private function common_param_name($name, $f, $query_plausible) {
        if (!isset(self::$param_schemas[$name])) {
            return null;
        }
        if ($name === "p") {
            if (($f & self::F_REQUIRED) === 0) {
                return "p.opt";
            } else if (($f & self::F_PATH) !== 0) {
                return "p.path";
            } else {
                return "p";
            }
        } else if ($name === "r") {
            return $f & self::F_REQUIRED ? "r" : "r.opt";
        } else if ($name === "c") {
            return $f & self::F_REQUIRED ? "c" : "c.opt";
        } else if ($name === "q") {
            return $f & self::F_REQUIRED ? "q" : "q.opt";
        } else if ((($name === "redirect" || $name === "forceShow")
                    && $f === 0)
                   || (in_array($name, ["t", "qt", "reviewer", "sort", "scoresort"])
                       && $query_plausible
                       && ($f & self::F_REQUIRED) === 0)) {
            return $name;
        } else {
            return null;
        }
    }

    /** @param object $x
     * @param object $uf
     * @param ?object $dj */
    private function expand_request($x, $uf, $dj) {
        if ($dj && isset($dj->fields)) {
            $this->parse_description_fields($dj->fields, false, $dj->landmark);
        }
        if (isset($uf->parameter_info)) {
            $this->parse_field_info($uf->parameter_info);
        }

        $params = $bprop = $breq = [];
        $query_plausible = isset($this->cur_fieldf["q"]);
        $has_file = false;
        foreach ($this->cur_fieldf as $name => $f) {
            if ($name === "*"
                || (($f & self::FM_NONGET) !== 0 && $this->cur_lmethod === "get")) {
                continue;
            }
            if (($f & (self::F_BODY | self::F_FILE)) !== 0) {
                $schema = $this->cur_fields[$name] ?? self::$param_schemas[$name] ?? null;
                $bprop[$name] = $this->resolve_info($schema, $name);
                if (isset($this->cur_fieldd[$name]) && !isset($bprop[$name]->{"\$ref"})) {
                    $bprop[$name]->description = $this->cur_fieldd[$name];
                }
                if (($f & self::F_REQUIRED) !== 0) {
                    $breq[] = $name;
                }
                if (($f & self::F_FILE) !== 0) {
                    $has_file = true;
                }
                continue;
            }
            if (($pn = $this->common_param_name($name, $f, $query_plausible))
                && !isset($this->cur_fieldd[$name])) {
                $params[$name] = $this->reference_common_param($pn);
            } else {
                $params[$name] = (object) [
                    "name" => $name,
                    "in" => "query",
                    "required" => ($f & self::F_REQUIRED) !== 0,
                    "schema" => $this->resolve_info($this->cur_fields[$name] ?? null, $name)
                ];
                if (isset($this->cur_fieldd[$name])) {
                    $params[$name]->description = $this->cur_fieldd[$name];
                }
            }
        }
        if (!empty($params) || isset($x->parameters)) {
            $this->apply_parameters($x, $params);
        }
        if (!empty($bprop) || isset($x->requestBody)) {
            $rbj = $x->requestBody = $x->requestBody ?? (object) ["description" => ""];
            $cj = $rbj->content = $rbj->content ?? (object) [];
            $bodyj = $cj->{"multipart/form-data"}
                ?? $cj->{"application/x-www-form-urlencoded"}
                ?? (object) [];
            unset($cj->{"multipart/form-data"}, $cj->{"application/x-www-form-urlencoded"}, $cj->schema);
            $formtype = $has_file ? "multipart/form-data" : "application/x-www-form-urlencoded";
            $cj->{$formtype} = $bodyj;
            $xbschema = $bodyj->schema = $bodyj->schema ?? (object) [];
            $xbschema->type = "object";
            $this->apply_body_parameters($xbschema, $bprop, $breq, $formtype);
        }
    }

    private function apply_parameters($x, $params) {
        $this->cur_ptype = self::PT_QUERY;

        $x->parameters = $x->parameters ?? [];
        $xparams = [];
        foreach ($x->parameters as $i => $pj) {
            $pj = $this->resolve_reference($pj, "parameters") ?? $pj;
            if (!is_string($pj->name ?? null)) {
                continue;
            }
            $xparams[$pj->name] = $i;
            if (!isset($params[$pj->name])) {
                fwrite(STDERR, $this->cur_prefix($i) . "unexpected parameter `{$pj->name}`\n");
            }
        }

        foreach ($params as $n => $npj) {
            $i = $xparams[$n] ?? null;
            if ($i === null) {
                $x->parameters[] = $npj;
                continue;
            }
            $xpj = $x->parameters[$i];
            if ($this->combine_fields($n, $npj, $xpj, $i)) {
                $x->parameters[$i] = $npj;
            }
        }
    }

    private function cur_override() {
        return $this->cur_ptype === self::PT_RESPONSE ? $this->override_response : $this->override_param;
    }

    private function apply_body_parameters($x, $bprop, $breq, $content_type) {
        $this->cur_ptype = self::PT_BODY;
        $this->cur_psubtype = $content_type;

        $this->apply_required($x, $bprop, $breq, []);
        $xprop = $x->properties = $x->properties ?? (object) [];
        foreach (get_object_vars($xprop) as $n => $v) {
            if (!isset($bprop[$n])) {
                fwrite(STDERR, $this->cur_prefix($n) . "unexpected body parameter `{$n}`\n");
            }
        }
        foreach ($bprop as $n => $npj) {
            $xpj = $xprop->{$n} ?? null;
            if ($xpj === null
                || $this->combine_fields($n, $npj, $xpj, $n)) {
                $xprop->{$n} = $npj;
            }
        }
    }

    private function apply_required($x, $bprop, $breq, $ignore) {
        if ($this->cur_override()) {
            $xreq = [];
        } else {
            $xreq = $x->required ?? [];
        }

        foreach ($xreq as $p) {
            if (isset($bprop[$p])
                && !in_array($p, $breq)
                && !in_array($p, $ignore)) {
                fwrite(STDERR, $this->cur_prefix("\$required") . $this->cur_field_description() . " `{$p}` expected optional\n");
            }
        }
        foreach ($breq as $p) {
            if (!in_array($p, $ignore)
                && !in_array($p, $xreq)) {
                $xreq[] = $p;
            }
        }

        if (empty($xreq)) {
            unset($x->required);
        } else {
            $x->required = $xreq;
        }
    }

    /** @param object $x
     * @param object $uf
     * @param ?object $dj */
    private function expand_response($x, $uf, $dj) {
        $this->cur_fieldf = [];
        $this->cur_fields = [];
        $this->cur_fieldd = [];
        $this->cur_fieldsch = [];

        $response = $uf->response ?? [];
        if (is_string($response)) {
            $response = explode(" ", trim($response));
        }
        foreach ($response as $p) {
            list($name, $f) = self::parse_field_name($p);
            $this->cur_fieldf[$name] = $f;
        }
        if ($dj && isset($dj->fields)) {
            $this->parse_description_fields($dj->fields, true, $dj->landmark);
        }
        if (isset($uf->response_info)) {
            $this->parse_field_info($uf->response_info);
        }

        $bprop = $breq = [];
        foreach ($this->cur_fieldf as $name => $f) {
            if ($name === "*"
                || (($f & self::FM_NONGET) !== 0 && $this->cur_lmethod === "get")) {
                continue;
            }
            $bprop[$name] = $this->resolve_info($this->cur_fields[$name] ?? null, $name);
            if (($f & self::F_REQUIRED) !== 0) {
                $breq[] = $name;
            }
        }

        $this->apply_response($x, $bprop, $breq);
    }

    private function apply_response($x, $bprop, $breq) {
        $x->responses = $x->responses ?? (object) [];
        $resp200 = $x->responses->{"200"} = $x->responses->{"200"} ?? (object) [];

        // default response
        if (!isset($x->responses->default)) {
            $x->responses->default = (object) [
                "description" => "",
                "content" => (object) [
                    "application/json" => (object) [
                        "schema" => $this->reference_common_schema("error_response")
                    ]
                ]
            ];
        }

        // 200 status response
        $resp200->description = $resp200->description ?? "";
        $respc = $resp200->content = $resp200->content ?? (object) [];
        $respj = $respc->{"application/json"} = $respc->{"application/json"} ?? (object) [];
        if ($this->override_response || !isset($respj->{"schema"})) {
            $resps = $respj->{"schema"} = $this->reference_common_schema("minimal_response");
        } else {
            $resps = $respj->{"schema"};
        }

        // obtain allOf list of schemas
        $this->cur_ptype = self::PT_RESPONSE;
        if (is_array($resps->allOf ?? null)) {
            $rsch = $resps->allOf;
            $this->cur_psubtype = count($rsch) - 1;
        } else {
            $rsch = [$resps];
            $this->cur_psubtype = null;
        }

        // add requested response_schemas
        foreach ($this->cur_fieldsch as $sch) {
            $ref = $this->reference_common_schema($sch);
            assert(isset($ref->{"\$ref"}));
            $i = 0;
            while (isset($rsch[$i])
                   && isset($rsch[$i]->{"\$ref"})
                   && $rsch[$i]->{"\$ref"} !== $ref->{"\$ref"}) {
                ++$i;
            }
            if (!isset($rsch[$i]) || !isset($rsch[$i]->{"\$ref"})) {
                array_splice($rsch, $i, 0, [$ref]);
            }
        }

        // enumerate known properties
        $knownparam = $knownreq = [];
        foreach ($rsch as $respx) {
            if (($j = $this->resolve_reference($respx, "schemas"))
                && is_object($j)
                && ($j->type ?? null) === "object") {
                foreach ($j->properties as $k => $v) {
                    $knownparam[$k] = true;
                }
                $knownreq = array_merge($knownreq, $j->required ?? []);
            }
        }

        // `$respb` is the last object schema
        if (($rsch[count($rsch) - 1]->type ?? null) === "object") {
            $respb = $rsch[count($rsch) - 1];
        } else {
            $rsch[] = $respb = (object) ["type" => "object"];
        }

        // required properties
        $this->apply_required($respb, $bprop, $breq, $knownreq);

        // property settings
        $respprop = $respb->properties ?? (object) [];
        foreach ((array) $respprop as $p => $v) {
            if (!isset($bprop[$p])
                && !isset($knownparam[$p])) {
                fwrite(STDERR, $this->cur_prefix($p) . "unexpected response field `{$p}`\n");
            }
        }
        foreach ($bprop as $k => $v) {
            if (isset($knownparam[$k])) {
                continue;
            }
            if (!isset($respprop->{$k})
                || $this->combine_fields($k, $v, $respprop->{$k}, $k)) {
                $respprop->$k = $v;
            }
            if (isset($this->cur_fieldd[$k])
                && ($this->override_description || ($respprop->$k->description ?? "") === "")) {
                $respprop->$k->description = $this->cur_fieldd[$k];
            }
        }

        // result
        if (self::is_empty_object($respprop)) {
            if (!empty($respb->required)) {
                error_log(json_encode($respb->required));
            }
            assert(empty($respb->required));
            array_pop($rsch);
        } else {
            $respb->properties = $respprop;
        }
        if (count($rsch) === 1) {
            $respj->{"schema"} = $rsch[0];
        } else {
            $respj->{"schema"} = (object) ["allOf" => $rsch];
        }
    }

    private function combine_fields($name, $npj, $xpj, $paramid) {
        if (empty(get_object_vars($xpj))) {
            return true;
        } else if (empty(get_object_vars($npj))) {
            return false;
        }
        $npjref = $npj->{"\$ref"} ?? null;
        $xpjref = $xpj->{"\$ref"} ?? null;
        if ($this->cur_override()
            || ($this->override_ref && $npjref !== null)) {
            if (isset($xpj->schema) && !$npjref && !isset($npj->schema)) {
                $npj->schema = $xpj->schema;
            }
            if (isset($xpj->description) && !$npjref && !isset($npj->description)) {
                $npj->description = $xpj->description;
            }
            return true;
        }
        $paramdesc = $this->cur_field_description();
        if (isset($xpjref) !== isset($npjref)) {
            fwrite(STDERR, $this->cur_prefix($paramid) . "{$paramdesc} `{$name}` \$ref status differs\n  input " . ($xpjref ?? "noref") . ", expected " . ($npjref ?? "noref") . "\n");
        } else if (isset($xpjref)) {
            if ($xpjref !== $npjref) {
                fwrite(STDERR, $this->cur_prefix($paramid) . "{$paramdesc} `{$name}` \$ref destination differs\n  input {$xpjref}, expected {$npjref}\n");
            }
        } else {
            foreach ((array) $npj as $k => $v) {
                if (!isset($xpj->$k)
                    || self::is_empty_object($xpj->$k)) {
                    $xpj->$k = $v;
                } else if (self::is_empty_object($v)) {
                    continue;
                } else if (is_scalar($v) ? $xpj->$k !== $v : json_encode($xpj->$k) !== json_encode($v)) {
                    fwrite(STDERR, $this->cur_prefix($paramid) . "{$paramdesc} `{$name}` {$k} differs\n  input " . json_encode($xpj->$k) . ", expected " . json_encode($v) . "\n");
                }
            }
        }
        return false;
    }

    static private function allOf_object_position($j) {
        $found = -1;
        foreach ($j as $i => $jx) {
            if (($jx->type ?? null) === "object") {
                if ($found >= 0) {
                    return -1;
                }
                $found = $i;
            }
        }
        return $found;
    }


    // SORTING

    private function sort() {
        $this->tag_order = [];
        foreach ($this->j->tags ?? [] as $i => $x) {
            if (isset($x->name) && is_string($x->name)) {
                $p = array_search($x->name, self::$default_tag_order);
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

    static function path_first_tag($pj) {
        foreach ($pj as $name => $oj) {
            if (is_object($oj) && !empty($oj->tags)) {
                return $oj->tags[0];
            }
        }
        return null;
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


    // RUNNING

    /** @return int */
    function run() {
        $mj = $this->j;
        $mj->openapi = "3.1.0";
        $info = $mj->info = $mj->info ?? (object) [];
        if ($this->base) {
            $info->title = "HotCRP REST API";
            $info->version = `git log --format="format:%cs:%h" -n1 devel/apidoc etc/apifunctions.json etc/apiexpansions.json batch/apispec.php`;
        } else {
            $info->title = $info->title ?? "HotCRP";
            $info->version = $info->version ?? "0.1";
        }
        $this->merge_description("info", $info);

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
                    fwrite(STDERR, $this->jpath_landmark("\$.paths[\"{$name}\"]") . "input path {$name} not specified\n");
                } else {
                    foreach ($pj as $lmethod => $x) {
                        if ($lmethod !== "__path"
                            && !isset($this->setj->paths->$name->$lmethod)) {
                            fwrite(STDERR, $this->jpath_landmark("\$.paths[\"{$name}\"].{$lmethod}") . "input operation {$lmethod} {$name} not specified\n");
                        }
                    }
                }
            }
        }
        foreach ($this->description_map as $name => $djs) {
            if (preg_match('/\A(get|post)\s+(\S+)\z/', $name, $m)
                && !isset($this->paths->{$m[2]}->{$m[1]})
                && ($dj = $this->find_description($name))) {
                fwrite(STDERR, "{$dj->landmark}: description path {$m[1]}.{$m[2]} not specified\n");
            }
        }

        // maybe sort
        if ($this->sort || !$this->batch) {
            $this->sort();
        }

        // erase unwanted keys
        foreach ($this->paths as $pj) {
            foreach ($pj as $xj) {
                if (!is_object($xj)) {
                    continue;
                }
                if (($xj->summary ?? "") === $pj->__path
                    && !isset($xj->description)
                    && !isset($xj->operationId)) {
                    unset($xj->summary);
                }
            }
            unset($pj->__path);
        }
        foreach ($this->j->tags as $tj) {
            unset($tj->summary);
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

    /** @return array{Conf,array<string,mixed>} */
    static function parse_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "x,base Produce the base API",
            "w,watch Watch for updates",
            "i:,input: =FILE Modify existing specification in FILE",
            "override-ref Overwrite conflicting \$refs in input",
            "override-param",
            "override-response",
            "override-tags",
            "override-schema",
            "no-override-description",
            "no-sort",
            "sort !",
            "o:,output: =FILE Write specification to FILE"
        )->description("Generate an OpenAPI specification.
Usage: php batch/apispec.php")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        if (isset($arg["x"])) {
            $conf->set_opt("apiFunctions", null);
            $conf->set_opt("apiDescriptions", null);
        }
        return [$conf, $arg];
    }

    static private function add_expanded_includes($opt, &$cmd) {
        if (!$opt) {
            return;
        }
        foreach (is_array($opt) ? $opt : [$opt] as $fn) {
            if (str_starts_with($fn, "?")) {
                $fn = substr($fn, 1);
            }
            if (preg_match('/\A(.*?)\/[^\/]*[?*{\[]/', $fn, $m)) {
                $cmd[] = $m[1];
            } else {
                $cmd[] = $fn;
            }
        }
    }

    /** @return never */
    static function run_args($argv) {
        list($conf, $arg) = self::parse_args($argv);

        // handle non-watch
        if (!isset($arg["w"])) {
            $apispec = new APISpec_Batch($conf, $arg);
            exit($apispec->run());
        }

        // watch requires `-i` or `-o` with a named file
        if (isset($arg["x"])
            && !isset($arg["i"])
            && !isset($arg["o"])) {
            $file = "devel/openapi.json";
        } else {
            $file = $arg["o"] ?? $arg["i"] ?? "-";
        }
        if ($file === "-") {
            throw new CommandLineException("`-w` requires known output file");
        }

        if (str_starts_with($file, "../") || str_contains($file, "/..")) {
            throw new CommandLineException("`-w` spec filename must not contain `..`");
        }
        if (str_starts_with($file, "/")) {
            $path = $file;
        } else if (str_starts_with($file, "./")) {
            $path = getcwd() . substr($file, 1);
        } else {
            $path = getcwd() . "/" . $file;
        }

        // enumerate files to watch
        $cmd = [
            "fswatch",
            "etc/apifunctions.json", "etc/apiexpansions.json", "devel/apidoc", "batch/apispec.php",
            $path
        ];
        self::add_expanded_includes($conf->opt("apiFunctions"), $cmd);
        self::add_expanded_includes($conf->opt("apiDescriptions"), $cmd);
        $proc = proc_open(Subprocess::args_to_command($cmd),
            [0 => ["file", "/dev/null", "r"], 1 => ["pipe", "w"]],
            $pipes);
        $fswatch = $pipes[1];

        // create command line for creating spec
        $clone = ["php", "batch/apispec.php"];
        foreach ($arg as $name => $value) {
            if ($name === "_" || $name === "w") {
                continue;
            }
            $start = strlen($name) === 1 ? "-{$name}" : "--{$name}";
            if ($value === false) {
                $clone[] = $start;
            } else if (strlen($name) > 1) {
                $clone[] = "{$start}={$value}";
            } else {
                $clone[] = $start . $value;
            }
        }
        if (!empty($arg["_"])) {
            array_push($clone, "--", ...$arg["_"]);
        }

        // build/wait loop
        while (true) {
            $xproc = proc_open(Subprocess::args_to_command($clone), [], $xpipes);
            $term = proc_close($xproc);
            $t = microtime(true);
            fwrite(STDERR, "Created {$file}" . ($term === 0 ? "" : " (termination status {$term})") . "\n\n");
            while (true) {
                $x = @fread($fswatch, 1024);
                if ($x === false) {
                    exit(1);
                }
                if ($x !== "{$path}\n" || microtime(true) - $t > 1) {
                    break;
                }
            }
        }
    }
}
