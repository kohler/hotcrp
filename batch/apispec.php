<?php
// apispec.php -- HotCRP script for generating OpenAPI specification
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

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
    private $basej;
    /** @var object */
    private $setj_paths;
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
    private $override_description;
    /** @var bool */
    private $merge_allOf;
    /** @var bool */
    private $sort;
    /** @var array<string,int> */
    private $tag_order;
    /** @var array<string,int> description item name => position in documentation */
    private $doc_order = [];

    /** @var string */
    private $cur_path;
    /** @var string */
    private $cur_lmethod;
    /** @var int */
    private $cur_ptype;
    /** @var null|int|string */
    private $cur_psubtype;
    /** @var array<string,int> */
    private $cur_fieldf;
    /** @var array<string,object> */
    private $cur_fields;
    /** @var array<string,string> */
    private $cur_fieldd;
    /** @var array<string,list<object>> */
    private $cur_fieldbadges;
    /** @var array<string,list<string>> */
    private $cur_fieldconditions;
    /** @var array<string,array{group:string,element:string}> field name =>
     * mutually-exclusive group: `group` names the whole "one of" set, `element`
     * the alternative within it that this field belongs to */
    private $cur_fieldoneof;
    /** @var array<string,string> field name => ad-hoc group label; consecutive
     * fields sharing a label render together inside a box titled with it */
    private $cur_fieldgroup;
    /** @var array<string,mixed> field name => OpenAPI `default` value declared
     * via `* default VALUE`. Keyed presence (not value truthiness) marks a
     * default, so a literal `null` default is representable. */
    private $cur_fielddefault;
    /** @var list<object> */
    private $cur_badge;
    /** @var list<string> */
    private $cur_fieldsch;
    /** @var array<string,string> response_schema name => field it follows (its
     * position among the response fields, for inlining in document order) */
    private $cur_fieldsch_after;
    /** @var list<string> field names in Markdown document order (authoritative
     * for output ordering) */
    private $cur_md_order;
    /** @var list<string> request-body media types (`* body`) in document order */
    private $cur_body_order;
    /** @var array<string,string> request-body media type => raw type string */
    private $cur_bodyschema;
    /** @var array<string,string> request-body media type => description */
    private $cur_bodyd;

    const PT_QUERY = 1;
    const PT_BODY = 2;
    const PT_RESPONSE = 3;

    static private $default_tag_order = [
        "Submissions", "Documents", "Search", "Tags", "Review preferences",
        "Assignments", "Submission administration",
        "Reviews", "Comments",
        "Meeting tracker", "Users", "Account",
        "Notifications", "Task management",
        "Site information", "Site administration", "Settings",
        "Session"
    ];

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $this->xtp = new XtParams($this->conf, null);
        $this->base = isset($arg["x"]);

        $this->api_map = $conf->api_map();
        $this->j = (object) [];
        $this->setj_paths = (object) [];
        $this->setj_tags = (object) [];

        $this->description_map = [];
        foreach ([["?devel/apidoc/*.md"], $conf->opt("apiDescriptions")] as $desc) {
            expand_json_includes_callback($desc, [$this, "_add_description_item"], "APISpec_Batch::parse_description_markdown");
        }

        $base_str = file_get_contents_throw("devel/apidoc/openapi-base.json");
        $jparser = (new JsonParser($base_str))
            ->set_filename("devel/apidoc/openapi-base.json");
        $this->basej = $jparser->decode();

        // read input specification (default `openapi-base.json`, or `-i FILE`)
        if (!isset($arg["i"])) {
            $filename = "devel/apidoc/openapi-base.json";
            $s = $base_str;
        } else if ($arg["i"] === "-") {
            $filename = "<stdin>";
            $s = stream_get_contents(STDIN);
        } else {
            $filename = safe_filename($arg["i"]);
            $s = file_get_contents_throw($filename);
        }
        if ($s !== false) {
            $this->jparser = (new JsonParser($s))->set_filename($filename);
            $this->j = $this->jparser->decode();
        }
        if ($s === false || !is_object($this->j)) {
            $msg = $filename . ": Invalid input";
            if ($this->j === null && $this->jparser->last_error()) {
                $msg .= ": " . $this->jparser->last_error_msg();
            }
            throw new CommandLineException($msg);
        }
        $this->batch = true;

        // determine output (default stdout; `-x` writes `devel/openapi.json`)
        if (isset($arg["o"])) {
            $this->output_file = $arg["o"];
        } else if ($this->base) {
            $this->output_file = "devel/openapi.json";
        }

        $this->override_ref = isset($arg["override-ref"]);
        $this->override_param = isset($arg["override-param"]);
        $this->override_response = isset($arg["override-response"]);
        $this->override_tags = isset($arg["override-tags"]);
        $this->override_description = !isset($arg["no-override-description"]);
        $this->merge_allOf = !isset($arg["no-merge"]);
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
            $jpath .= ".responses[200].content[\"application/json\"].schema";
            if (!$this->merge_allOf) {
                $jpath .= ".allOf[{$this->cur_psubtype}]";
            }
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
        }
        return "response field";
    }


    function _add_description_item($xt) {
        if (isset($xt->name) && is_string($xt->name)) {
            $this->description_map[$xt->name][] = $xt;
            // Record the order in which named items first appear in the
            // documentation; used to order operations to match the docs.
            if (!array_key_exists($xt->name, $this->doc_order)) {
                $this->doc_order[$xt->name] = count($this->doc_order);
            }
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
        $m = preg_split('/^\#\s+([^\n]*?)\s*\n/m', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
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
            if (preg_match('/(?:\n|\A)(?=\*)(?:\* (?:param(?:eter)?(?:_schema)?|response(?:_schema)?|badge|body) [^\n]*+\n|  [^\n]*+\n|[ \t]*+\n)+\z/s', $d, $mx)) {
                $d = cleannl(substr($d, 0, -strlen($mx[0])));
                $x["fields"] = ltrim($mx[0]);
            }
            if ($d !== "") {
                $x["description"] = $d;
                self::check_heading_nesting($d, $x["name"], $x["landmark"]);
            }
            $xs[] = (object) $x;
            $lineno += 1 + substr_count($m[$i + 1], "\n");
        }
        // Fold documentation sections into the leading (tag) heading. A
        // documentation section is a non-operation `#` heading that follows the
        // file's first heading (the tag) but precedes any operation. It is
        // stored on the tag item as `sections` and rendered by the doc
        // generator as a standalone subsection of the tag, separately listed in
        // the menu and separately addressable — not as an operation of its own.
        if (count($xs) > 1 && !self::is_operation_name($xs[0]->name)) {
            $nsec = 0;
            while ($nsec + 1 < count($xs)
                   && !self::is_operation_name($xs[$nsec + 1]->name)) {
                ++$nsec;
            }
            if ($nsec > 0) {
                $xs[0]->sections = array_slice($xs, 1, $nsec);
                array_splice($xs, 1, $nsec);
            }
        }
        return $xs;
    }

    /** True if a heading name denotes an API operation (e.g. `get /paper`)
     * rather than a tag or a documentation section.
     * @param string $name
     * @return bool */
    static private function is_operation_name($name) {
        return (bool) preg_match('/\A(?:get|post|put|delete|patch|head|options)\s+\//i', $name);
    }

    /** Build the public `x-sections` list (name, summary, description) for a tag
     * from its parsed documentation-section items.
     * @param list<object> $sections
     * @return list<object> */
    static private function clean_sections($sections) {
        $out = [];
        foreach ($sections as $s) {
            // A trailing `{#id}` annotation sets an explicit anchor id for the
            // section (e.g. `# The submission object {#object-submission}`).
            $name = $s->name;
            $id = null;
            if (preg_match('/\A(.*?)\s*\{#([^}\s]+)\}\z/', $name, $mm)) {
                $name = rtrim($mm[1]);
                $id = $mm[2];
            }
            $o = ["name" => $name];
            if ($id !== null) {
                $o["id"] = $id;
            }
            if (($s->summary ?? "") !== "") {
                $o["summary"] = $s->summary;
            }
            if (($s->description ?? "") !== "") {
                $o["description"] = $s->description;
            }
            $out[] = (object) $o;
        }
        return $out;
    }

    /** Warn about malformed heading nesting in a description: a first heading
     * deeper than `##`, or a level skip (e.g. an `##` followed directly by
     * `####`). Subsection headings should begin at `##` and step down one level
     * at a time. The documentation renderer re-levels each description so its
     * shallowest heading sits just beneath the tag/operation heading, preserving
     * relative depth — so neither mistake breaks the output, but both make the
     * source inconsistent (and a skip also yields a malformed outline).
     * @param string $d
     * @param string $name
     * @param string $landmark */
    static private function check_heading_nesting($d, $name, $landmark) {
        // ignore `#` lines inside fenced code blocks
        $d = preg_replace('/^```.*?^```/ms', "", $d);
        if (!preg_match_all('/^(\#{2,6})[ \t]+(\S[^\n]*)/m', $d, $ms, PREG_SET_ORDER)) {
            return;
        }
        $prev = 0;
        foreach ($ms as $i => $mm) {
            $lvl = strlen($mm[1]);
            if ($i === 0 && $lvl > 2) {
                fwrite(STDERR, "{$landmark}: first heading “" . simplify_whitespace($mm[2])
                    . "” under “{$name}” is h{$lvl}; descriptions should start at h2 (##)\n");
            }
            if ($prev !== 0 && $lvl > $prev + 1) {
                fwrite(STDERR, "{$landmark}: heading “" . simplify_whitespace($mm[2])
                    . "” under “{$name}” skips from h{$prev} to h{$lvl}\n");
            }
            $prev = $lvl;
        }
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
        if (isset($dj->sections)
            && ($this->override_description || !isset($xj->{"x-sections"}))) {
            $xj->{"x-sections"} = self::clean_sections($dj->sections);
        }
    }

    /** @param string $name
     * @param object $xj */
    private function merge_description($name, $xj) {
        if (($dj = $this->find_description($name))) {
            $this->merge_description_from($xj, $dj);
        }
    }


    const F_REQUIRED = 0x01;    // !
    const F_POST = 0x02;        // +
    const F_BODY = 0x04;        // =
    const F_FILE = 0x08;        // @
    const F_SUFFIX = 0x10;      // :
    const F_PATH = 0x20;
    const F_DEFAULT = 0x40;
    const F_DEPRECATED = 0x80;  // <
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
            } else if ($p[$i] === "<") {
                $f |= self::F_DEPRECATED;
            } else if ($p[$i] === "*" && $i !== strlen($p) - 1) {
                // location-flexible marker (as in the spec validator); strip it
                // unless it is the standalone `*` catch-all field name
                $f &= ~(self::F_REQUIRED | self::F_DEFAULT);
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

    /** Reorder `cur_fieldf` to follow the Markdown document order (`* param` /
     * `* response` line order). Fields documented in the Markdown come first, in
     * that order; any field known only from apifunctions/apiexpansions keeps its
     * relative order afterward. */
    private function apply_md_field_order() {
        if (empty($this->cur_md_order)) {
            return;
        }
        $ordered = [];
        foreach ($this->cur_md_order as $name) {
            if (isset($this->cur_fieldf[$name])) {
                $ordered[$name] = $this->cur_fieldf[$name];
            }
        }
        foreach ($this->cur_fieldf as $name => $f) {
            $ordered[$name] = $f;
        }
        $this->cur_fieldf = $ordered;
    }

    /** @param string $fn */
    private function expand_paths($fn) {
        $methods = [];
        foreach (["get", "post", "delete"] as $lmethod) {
            $umethod = strtoupper($lmethod);
            if (!($uf = $this->conf->api($fn, null, $umethod))) {
                continue;
            }
            if (($ufx = $this->conf->api_expansion($fn, $umethod))) {
                $uf = clone $uf;
                foreach (get_object_vars($ufx) as $k => $v) {
                    if (!isset($uf->$k) || $k === "__source_order")
                        $uf->$k = $v;
                }
            }
            $order = $uf->order ?? ($uf->deprecated ?? false ? false : null);
            if ($order !== false) {
                $methods[$lmethod] = $uf;
            }
        }

        foreach ($methods as $lmethod => $uf) {
            // parse subset of parameters
            $this->cur_fieldf = [];
            $this->cur_fields = [];
            $this->cur_fieldd = [];
            $this->cur_fieldbadges = [];
            $this->cur_fieldconditions = [];
            $this->cur_fieldoneof = [];
            $this->cur_fieldgroup = [];
            $this->cur_fielddefault = [];
            $this->cur_fieldsch = [];
            $this->cur_fieldsch_after = [];
            $this->cur_md_order = [];
            $this->cur_body_order = [];
            $this->cur_bodyschema = [];
            $this->cur_bodyd = [];
            $this->cur_badge = [];
            if ($uf->paper ?? false) {
                $this->add_field("p", self::F_REQUIRED);
            }
            if (isset($uf->parameters)) {
                $this->parse_json_parameters($uf->parameters, ["p"]);
            }

            // choose path
            $p = $this->cur_fieldf["p"] ?? 0;
            if (($p & self::F_REQUIRED) !== 0) {
                $this->cur_fieldf["p"] |= self::F_PATH;
                $path = "/{p}/{$fn}";
            } else {
                $path = "/{$fn}";
            }
            $this->cur_path = $path;
            $this->cur_lmethod = $lmethod;

            // create `paths` object
            $pathj = $this->paths->$path = $this->paths->$path ?? (object) [];
            $pathj->__path = $path;
            if (!isset($this->setj_paths->$path)) {
                $this->merge_description($path, $pathj);
                $this->setj_paths->$path = (object) [];
            }

            // create operation object
            $opj = $pathj->$lmethod = $pathj->$lmethod ?? (object) [];
            if (!isset($this->setj_paths->$path->$lmethod)) {
                if ($this->override_description
                    || ($opj->summary ?? "") === "") {
                    $opj->summary = $path;
                }
                $this->setj_paths->$path->$lmethod = true;
            }

            // apply description
            $dj = $this->find_description("{$this->cur_lmethod} {$this->cur_path}");
            if ($dj) {
                $this->merge_description_from($opj, $dj);
            }

            // set operationId
            if (!isset($opj->operationId)) {
                if ($lmethod === "get" || count($methods) === 1) {
                    $opj->operationId = $fn;
                } else {
                    $opj->operationId = "{$fn}-{$lmethod}";
                }
            }

            // record this operation's position in the Markdown documentation, so
            // a renderer can order operations by document order even when they
            // span distinct paths (e.g. `post /paper` between `get`/`delete
            // /{p}/paper`, which is the path `/{p}/paper`).
            if (isset($this->doc_order["{$lmethod} {$path}"])) {
                $opj->{"x-order"} = $this->doc_order["{$lmethod} {$path}"];
            }

            // apply tags, request, response
            $this->expand_tags($opj, $uf, $dj);
            $this->expand_request($opj, $uf, $dj);
            $this->expand_response($opj, $uf, $dj);
        }
    }

    /** @param string|list<string> $parameters
     * @param list<string> $only */
    private function parse_json_parameters($parameters, $only = []) {
        if (is_string($parameters)) {
            $parameters = explode(" ", trim($parameters));
        }
        foreach ($parameters as $p) {
            list($name, $f) = self::parse_field_name($p);
            if ($name !== ""
                && ((empty($only) && ($f & self::F_DEPRECATED) === 0)
                    || in_array($name, $only, true))) {
                $this->add_field($name, $f);
            }
        }
    }

    /** @param object $xj
     * @param object $uf
     * @param ?object $dj */
    private function expand_tags($xj, $uf, $dj) {
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
            '@phan-var list<object> $tags';
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
     * @param ?string $fieldname
     * @return object */
    private function reference_common_schema($name, $fieldname = null) {
        if (in_array($name, ["string", "number", "integer", "boolean", "null", "object"], true)) {
            return (object) ["type" => $name];
        } else if ($name === "nonnegative_integer") {
            return (object) ["type" => "integer", "minimum" => 0];
        }
        if ($this->schemas === null) {
            $compj = $this->j->components = $this->j->components ?? (object) [];
            $this->schemas = $compj->schemas = $compj->schemas ?? (object) [];
        }
        $nj = $this->schemas->$name ?? null;
        if (!$nj) {
            $nj = $this->basej->components->schemas->$name ?? null;
            if (!$nj) {
                throw new CommandLineException($this->cur_prefix() . "Common schema `{$name}` unknown");
            }
            $this->schemas->$name = $nj;
            $this->merge_description("schema {$name}", $nj);
        }
        return (object) ["\$ref" => "#/components/schemas/{$name}"];
    }

    /** @param object $parameters
     * @param string $name
     * @param int $f
     * @return ?string */
    static private function find_parameter($parameters, $name, $f) {
        $path = ($f & self::F_PATH) !== 0;
        $required = ($f & self::F_REQUIRED) !== 0;
        foreach ((array) $parameters as $pname => $pobj) {
            if ($pobj->name === $name
                && (($pobj->in === "path") === $path)
                && (($pobj->required ?? false) === $required)) {
                return $pname;
            }
        }
        return null;
    }

    /** @param string $name
     * @param int $f
     * @return ?object */
    private function reference_common_param($name, $f) {
        if ($this->parameters === null) {
            $compj = $this->j->components = $this->j->components ?? (object) [];
            $this->parameters = $compj->parameters = $compj->parameters ?? (object) [];
        }
        $pname = self::find_parameter($this->parameters, $name, $f);
        if (!$pname) {
            $base_param = $this->basej->components->parameters;
            $pname = self::find_parameter($base_param, $name, $f);
            if (!$pname) {
                return null;
            }
            $nj = json_decode(json_encode($base_param->$pname));
            $this->parameters->$pname = $nj;
            $this->merge_description("parameter {$pname}", $nj);
        }
        return (object) ["\$ref" => "#/components/parameters/{$pname}"];
    }

    /** Return an inline (non-`$ref`) copy of common parameter `$name`, or null
     * if there is no matching common parameter. Used when an endpoint must
     * attach instance-specific data — such as a `* default` — to a parameter
     * that would otherwise be referenced; the copy keeps the shared description
     * and schema, including any merged documentation.
     * @param string $name
     * @param int $f
     * @return ?object */
    private function inline_common_param($name, $f) {
        if ($this->reference_common_param($name, $f) === null) {
            return null;
        }
        $pname = self::find_parameter($this->parameters, $name, $f);
        return $pname ? json_decode(json_encode($this->parameters->$pname)) : null;
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
        } else if (str_starts_with($info, "[")
                   && strpos($info, "]") === strlen($info) - 1) {
            return (object) [
                "type" => "array",
                "items" => $this->resolve_info(substr($info, 1, -1), $name)
            ];
        } else if (str_starts_with($info, "?")) {
            return $this->resolve_info(substr($info, 1) . "|null", $name);
        } else if (str_starts_with($info, "=")) {
            // Inline string enum: `=v1|v2|...` becomes {"type":"string","enum":[...]}.
            // A `null` member (e.g. produced by a nullable `?=v1|v2`) is split off
            // into a oneOf with the null type, matching other nullable types.
            $enum = [];
            $nullable = false;
            foreach (explode("|", substr($info, 1)) as $v) {
                if ($v === "null") {
                    $nullable = true;
                } else {
                    $enum[] = $v;
                }
            }
            $ej = (object) ["type" => "string", "enum" => $enum];
            return $nullable
                ? (object) ["oneOf" => [$ej, (object) ["type" => "null"]]]
                : $ej;
        } else if (strpos($info, "|") !== false) {
            $res = (object) ["oneOf" => []];
            foreach (explode("|", $info) as $s) {
                $j = $this->resolve_info($s, $name);
                if (isset($j->oneOf) && count((array) $j) === 1) {
                    array_push($res->oneOf, ...$j->oneOf);
                } else {
                    $res->oneOf[] = $j;
                }
            }
            return $res;
        } else if (($s = $this->reference_common_schema($info, $name))) {
            return $s;
        } else {
            fwrite(STDERR, $this->cur_prefix() . "unknown type `{$info}` for " . $this->cur_field_description() . " `{$name}`\n");
            return (object) [];
        }
    }

    /** Badge name produced by `* badge featured`. Marks the API calls most worth
     * using by external integrators; the apidoc renderer displays it as a yellow
     * star. `apply_badges()` sorts it before other badges. */
    const FEATURED_BADGE = "Featured";

    private function resolve_badge($name) {
        if ($name === "siteadmin") {
            return (object) ["name" => "Site admin only"];
        } else if ($name === "admin") {
            return (object) ["name" => "Admin only"];
        } else if ($name === "trackmanager") {
            return (object) ["name" => "Track manager only"];
        } else if ($name === "featured") {
            return (object) ["name" => "Featured"];
        }
        return null;
    }

    /** @param string $params
     * @param bool $response
     * @param string $landmark */
    private function parse_description_fields($params, $response, $landmark) {
        $pos = 0;
        $last_field = "";
        while (preg_match('/\G\* (param(?:eter)?(?:_schema)?|response(?:_schema)?|badge|body)[ \t]++([?!+=@:]*+[^\s:]++)[ \t]*+(|[^\s:]++)[ \t]*+(:[^\n]*+(?:\n|\z)|\n)((?:(?:[ \t]*+\n)*+  [^\n]*+\n)*+)(?:[ \t]*+\n)*+/', $params, $m, 0, $pos)) {
            $pos += strlen($m[0]);
            if ($m[1] === "badge") {
                if (!$response) {
                    if (($b = $this->resolve_badge($m[2]))) {
                        $this->cur_badge[] = $b;
                    } else {
                        fwrite(STDERR, $this->cur_prefix() . "unknown badge `{$m[2]}`\n");
                    }
                }
                continue;
            }
            if ($m[1] === "body") {
                // `* body MEDIATYPE [TYPE]: description` declares a raw request
                // body (e.g. `application/json` or `application/zip`). The media
                // type keys its metadata (badges/conditions/oneof) just as a
                // field name does, so it can join a `* oneof` set as a peer of
                // form parameters.
                if (!$response) {
                    $mt = $m[2];
                    if ($m[3] !== "") {
                        $this->cur_bodyschema[$mt] = $m[3];
                    }
                    $this->cur_body_order[] = $mt;
                    $this->cur_md_order[] = $mt;
                    $bd = (str_starts_with($m[4], ":") ? substr($m[4], 1) : "") . $m[5];
                    if (($d = $this->parse_field_body($mt, $bd)) !== "") {
                        $this->cur_bodyd[$mt] = $d;
                    }
                }
                continue;
            }
            if (str_starts_with($m[1], "response") !== $response) {
                continue;
            }
            if (str_ends_with($m[1], "_schema")) {
                if ($this->reference_common_schema($m[2], $landmark)) {
                    if (!in_array($m[2], $this->cur_fieldsch, true)) {
                        if (empty($this->cur_fieldsch) && $response) {
                            $this->cur_fieldsch[] = "minimal_response";
                        }
                        $this->cur_fieldsch[] = $m[2];
                        // remember which response field (in document order) this
                        // schema follows, so its properties inline at that spot.
                        $this->cur_fieldsch_after[$m[2]] = $last_field;
                    }
                } else {
                    fwrite(STDERR, "{$landmark}: {$this->cur_prefix()}: unknown {$m[1]} `{$m[2]}`\n");
                }
                continue;
            }
            list($name, $f) = self::parse_field_name($m[2]);
            $this->add_field($name, $f);
            $last_field = $name;
            $this->cur_md_order[] = $name;
            if ($m[3] !== "") {
                $info = self::resolve_info($m[3], $name);
                if (!self::is_empty_object($info)) {
                    $this->cur_fields[$name] = $info;
                }
            }
            $body = str_starts_with($m[4], ":") ? substr($m[4], 1) : "";
            $body .= $m[5];
            if (($d = $this->parse_field_body($name, $body)) !== "") {
                $this->cur_fieldd[$name] = $d;
            }
        }
    }

    /** Split a field body into a block-Markdown description and trailing badge
     * metadata. The first line is the inline summary written after the `:`;
     * continuation lines are de-indented a block at a time (blocks are
     * separated by blank lines): each block is stripped of its own common
     * leading indentation, capped at four spaces, so 2-, 3-, and 4-space
     * list continuation all normalize to the left margin while indentation
     * beyond four spaces survives as content (e.g. indented code). Relative
     * indentation within a block (nested lists, multi-line code) is preserved.
     *
     * A trailing list of `* badge NAME` / `* condition TEXT` / `* oneof GROUP
     * [ELEMENT]` / `* group LABEL` items standing in its own paragraph is then
     * consumed as field-level metadata — `* badge` uses the same syntax as
     * operation badges, `* condition TEXT` records a presence condition (e.g.
     * `format=csv`) shown as a pill, `* oneof GROUP [ELEMENT]` marks the field
     * as one alternative of a mutually-exclusive set (GROUP names the whole set
     * and ELEMENT the alternative within it; fields sharing GROUP and ELEMENT
     * belong to the same alternative; ELEMENT defaults to the field name), and
     * `* group LABEL` puts the field in an ad-hoc group: consecutive fields
     * sharing LABEL render together inside a box titled with it, and `* default
     * VALUE` records the field's OpenAPI `default` (VALUE is parsed as JSON, so
     * `* default true`, `* default 42`, `* default "rev"`, and `* default null`
     * all work; a bare word that is not valid JSON is taken as a string). Items
     * are recognized by position (a trailing list) and grammar; any other
     * `*`-list is left untouched as Markdown. Returns the trimmed description.
     * @param string $name
     * @param string $body
     * @return string */
    private function parse_field_body($name, $body) {
        $lines = explode("\n", $body);

        // The summary sits right after the `:` and carries no block indentation.
        $summary = ltrim((string) array_shift($lines), " ");

        // De-indent the continuation one block at a time: each blank-separated
        // block is stripped of its own common leading indentation, up to four
        // spaces. This normalizes 2-/3-/4-space continuation independently per
        // block while preserving relative indentation inside a block.
        $out = $summary === "" ? [] : [$summary];
        $n = count($lines);
        for ($i = 0; $i !== $n; ) {
            if (trim($lines[$i]) === "") {
                $out[] = "";
                ++$i;
                continue;
            }
            $min = PHP_INT_MAX;
            $j = $i;
            while ($j !== $n && trim($lines[$j]) !== "") {
                $w = strlen($lines[$j]) - strlen(ltrim($lines[$j], " "));
                $min = $w < $min ? $w : $min;
                ++$j;
            }
            $strip = $min < 4 ? $min : 4;
            for (; $i !== $j; ++$i) {
                $out[] = (string) substr($lines[$i], $strip);
            }
        }
        $text = trim(implode("\n", $out));

        // Consume a trailing list of `* badge`/`* condition` items standing in
        // its own paragraph (preceded by a blank line or the start of the body).
        if (preg_match('/(?:\A|\n\n)((?:\* (?:badge|condition|oneof|group|default)[ \t]++[^\n]++(?:\n|\z))++)\z/', $text, $mm)) {
            foreach (explode("\n", trim($mm[1])) as $line) {
                if (preg_match('/\A\* badge[ \t]++(\S++)/', $line, $bm)) {
                    if (($b = $this->resolve_badge($bm[1]))) {
                        $this->cur_fieldbadges[$name][] = $b;
                    } else {
                        fwrite(STDERR, $this->cur_prefix() . "unknown badge `{$bm[1]}` for field `{$name}`\n");
                    }
                } else if (preg_match('/\A\* condition[ \t]++(\S+?)[ \t]*+\z/', $line, $cm)) {
                    // A condition tests an input parameter, either by value
                    // (`format=csv`) or by presence (`search`).
                    $this->cur_fieldconditions[$name][] = $cm[1];
                } else if (preg_match('/\A\* oneof[ \t]++(\S++)(?:[ \t]++(\S++))?[ \t]*+\z/', $line, $om)) {
                    // One alternative of a mutually-exclusive set; the element
                    // (the alternative within the set) defaults to the field name.
                    $this->cur_fieldoneof[$name] = [
                        "group" => $om[1],
                        "element" => $om[2] ?? $name
                    ];
                } else if (preg_match('/\A\* group[ \t]++(\S.*?)[ \t]*+\z/', $line, $gm)) {
                    // Ad-hoc group: the (possibly multi-word) label both names
                    // the box and keys it — consecutive fields with the same
                    // label group together.
                    $this->cur_fieldgroup[$name] = $gm[1];
                } else if (preg_match('/\A\* default[ \t]++(\S.*?)[ \t]*+\z/', $line, $dm)) {
                    // OpenAPI `default` for the field. The value is parsed as
                    // JSON (`true`, `42`, `"rev"`, `null`, …); a bare word that
                    // is not valid JSON is taken literally as a string.
                    $this->cur_fielddefault[$name] = self::parse_default_value($dm[1]);
                }
            }
            $text = rtrim(substr($text, 0, strlen($text) - strlen($mm[1])));
        }
        return $text;
    }

    /** Attach any `* badge`/`* condition`/`* oneof`/`* group` metadata collected
     * for field `$name` to `$target` as `x-badges`/`x-conditions`/`x-oneof`/
     * `x-group` extensions. OpenAPI 3.1 permits these alongside a `$ref` (as the
     * description code already relies on).
     * @param string $name
     * @param object $target */
    private function apply_field_metadata($name, $target) {
        if (!empty($this->cur_fieldbadges[$name])) {
            $target->{"x-badges"} = $this->cur_fieldbadges[$name];
        }
        if (!empty($this->cur_fieldconditions[$name])) {
            $target->{"x-conditions"} = $this->cur_fieldconditions[$name];
        }
        if (!empty($this->cur_fieldoneof[$name])) {
            $target->{"x-oneof"} = (object) $this->cur_fieldoneof[$name];
        }
        if (isset($this->cur_fieldgroup[$name])) {
            $target->{"x-group"} = $this->cur_fieldgroup[$name];
        }
    }

    /** Parse the VALUE of a `* default VALUE` line. The value is decoded as
     * JSON, so booleans, numbers, strings, `null`, and JSON arrays/objects are
     * all representable; a bare token that is not valid JSON (e.g. an unquoted
     * `rev`) is returned verbatim as a string.
     * @param string $s
     * @return mixed */
    static private function parse_default_value($s) {
        $s = trim($s);
        $v = json_decode($s);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $v;
        }
        return $s;
    }

    /** Set the OpenAPI `default` on schema object `$schema` if field `$name`
     * declared one via `* default VALUE`. `default` is a JSON Schema keyword, so
     * for query parameters it belongs on the parameter's `schema` and for
     * body/response properties on the property schema itself.
     * @param string $name
     * @param object $schema */
    private function apply_field_default($name, $schema) {
        if (array_key_exists($name, $this->cur_fielddefault)) {
            $schema->default = $this->cur_fielddefault[$name];
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

    /** @param object $x
     * @param object $uf
     * @param ?object $dj */
    private function expand_request($x, $uf, $dj) {
        if ($dj && isset($dj->fields)) {
            $this->parse_description_fields($dj->fields, false, $dj->landmark);
        }
        if (isset($uf->parameters)) {
            $this->parse_json_parameters($uf->parameters);
        }
        if (isset($uf->parameter_info)) {
            $this->parse_field_info($uf->parameter_info);
        }
        $this->apply_badges($x);
        $this->apply_md_field_order();

        // Unified document order across query params, form body fields, and
        // `* body` request bodies, so a renderer can interleave them (e.g. in a
        // `* oneof` set). Markdown order comes first, then any field known only
        // from apifunctions/apiexpansions.
        $orderpos = [];
        $o = 0;
        foreach ($this->cur_md_order as $nm) {
            if (!isset($orderpos[$nm])) {
                $orderpos[$nm] = $o++;
            }
        }
        foreach ($this->cur_fieldf as $nm => $f) {
            if (!isset($orderpos[$nm])) {
                $orderpos[$nm] = $o++;
            }
        }

        $params = $bprop = $breq = [];
        $query_plausible = isset($this->cur_fieldf["q"]);
        $has_file = false;
        foreach ($this->cur_fieldf as $name => $f) {
            if ($name === "*"
                || (($f & self::FM_NONGET) !== 0 && $this->cur_lmethod === "get")
                || ($f & self::F_DEPRECATED) !== 0) {
                continue;
            }
            $idx = $orderpos[$name];
            if (($f & (self::F_BODY | self::F_FILE)) !== 0) {
                $schema = $this->cur_fields[$name] ?? null;
                $bprop[$name] = $this->resolve_info($schema, $name);
                if (isset($this->cur_fieldd[$name]) && !isset($bprop[$name]->{"\$ref"})) {
                    $bprop[$name]->description = $this->cur_fieldd[$name];
                }
                $this->apply_field_metadata($name, $bprop[$name]);
                $this->apply_field_default($name, $bprop[$name]);
                $bprop[$name]->{"x-order"} = $idx;
                if (($f & self::F_REQUIRED) !== 0) {
                    $breq[] = $name;
                }
                if (($f & self::F_FILE) !== 0) {
                    $has_file = true;
                }
                continue;
            }
            $pobj = null;
            $has_default = array_key_exists($name, $this->cur_fielddefault);
            if (!isset($this->cur_fieldd[$name])) {
                // A `* default` is parameter-specific, so it cannot ride the
                // shared `$ref` to a common parameter (different endpoints give
                // the same parameter different defaults). Materialize an inline
                // copy of the common parameter instead, keeping its description
                // and schema; otherwise reference the shared component.
                $pobj = $has_default
                    ? $this->inline_common_param($name, $f)
                    : $this->reference_common_param($name, $f);
            }
            if (!$pobj) {
                $pobj = (object) [
                    "name" => $name,
                    "in" => "query",
                    "required" => ($f & self::F_REQUIRED) !== 0,
                    "schema" => $this->resolve_info($this->cur_fields[$name] ?? null, $name)
                ];
                if (isset($this->cur_fieldd[$name])) {
                    $pobj->description = $this->cur_fieldd[$name];
                }
            }
            $this->apply_field_metadata($name, $pobj);
            // `default` is a schema keyword; place it on the parameter's schema.
            // OpenAPI 3.1 permits it as a sibling of the schema's `$ref`.
            if ($has_default) {
                $pobj->schema = $pobj->schema ?? (object) [];
                $this->apply_field_default($name, $pobj->schema);
            }
            $pobj->{"x-order"} = $idx;
            $params[$name] = $pobj;
        }
        if (!empty($params) || isset($x->parameters)) {
            $this->apply_parameters($x, $params);
        }
        if (!empty($bprop) || !empty($this->cur_body_order) || isset($x->requestBody)) {
            $rbj = $x->requestBody = $x->requestBody ?? (object) ["description" => ""];
            $cj = $rbj->content = $rbj->content ?? (object) [];
            if (!empty($bprop)) {
                $bodyj = $cj->{"multipart/form-data"}
                    ?? $cj->{"application/x-www-form-urlencoded"}
                    ?? (object) [];
                unset($cj->{"multipart/form-data"}, $cj->{"application/x-www-form-urlencoded"}, $cj->schema);
                $formtype = $has_file ? "multipart/form-data" : "application/x-www-form-urlencoded";
                $cj->{$formtype} = $bodyj;
                $xbschema = $bodyj->schema = $bodyj->schema ?? (object) [];
                $xbschema->type = "object";
                $this->apply_body_parameters($xbschema, $bprop, $breq, $formtype);
                // a `*` field means additional (unlisted) parameters are accepted
                if (isset($this->cur_fieldf["*"])) {
                    $xbschema->additionalProperties = true;
                }
            }
            // raw request bodies declared with `* body` (e.g. application/json,
            // application/zip). A media type with no explicit type defaults to a
            // binary body. Its metadata (x-oneof/x-badges/x-conditions) lets it
            // join a `* oneof` set alongside form/query parameters.
            foreach ($this->cur_body_order as $mt) {
                $bschema = isset($this->cur_bodyschema[$mt])
                    ? $this->resolve_info($this->cur_bodyschema[$mt], $mt)
                    : (object) ["type" => "string", "format" => "binary"];
                $mobj = $cj->{$mt} = (object) ["schema" => $bschema];
                if (isset($this->cur_bodyd[$mt])) {
                    $mobj->{"x-description"} = $this->cur_bodyd[$mt];
                }
                $this->apply_field_metadata($mt, $mobj);
                $mobj->{"x-order"} = $orderpos[$mt] ?? PHP_INT_MAX;
            }
        }
    }

    private function apply_badges($x) {
        // The featured star always sorts before other badges (stable partition).
        $featured = $rest = [];
        foreach ($this->cur_badge as $bj) {
            if (($bj->name ?? null) === self::FEATURED_BADGE) {
                $featured[] = $bj;
            } else {
                $rest[] = $bj;
            }
        }
        $this->cur_badge = array_merge($featured, $rest);

        if ($this->override_tags) {
            if (empty($this->cur_badge)) {
                unset($x->{"x-badges"});
            } else {
                $x->{"x-badges"} = $this->cur_badge;
            }
            return;
        }
        foreach ($this->cur_badge as $bj) {
            if (!isset($x->{"x-badges"})) {
                $x->{"x-badges"} = [];
            }
            $found = false;
            foreach ($x->{"x-badges"} as $xbj) {
                if ($xbj->name === $bj->name) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $x->{"x-badges"}[] = $bj;
            }
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
            } else {
                // A kept `$ref` parameter has its identity from the component,
                // but its generated `x-*` siblings (x-order, x-group,
                // x-conditions, …) must still track the current documentation.
                $this->sync_x_extensions($npj, $x->parameters[$i]);
            }
        }
    }

    /** Refresh the generated `x-*` extension siblings on `$xpj` to match `$npj`,
     * adding new ones and dropping any that are no longer generated. Used when an
     * existing parameter object is kept rather than replaced.
     * @param object $npj
     * @param object $xpj */
    private function sync_x_extensions($npj, $xpj) {
        foreach (array_keys(get_object_vars($xpj)) as $k) {
            if (str_starts_with($k, "x-") && !isset($npj->$k)) {
                unset($xpj->$k);
            }
        }
        foreach (get_object_vars($npj) as $k => $v) {
            if (str_starts_with($k, "x-")) {
                $xpj->$k = $v;
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
                && !in_array($p, $breq, true)
                && !in_array($p, $ignore, true)) {
                fwrite(STDERR, $this->cur_prefix("\$required") . $this->cur_field_description() . " `{$p}` expected optional\n");
            }
        }
        foreach ($breq as $p) {
            if (!in_array($p, $ignore, true)
                && !in_array($p, $xreq, true)) {
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
        // Endpoints that do not use HotCRP's JSON envelope declare a literal
        // `response_content` map of responses instead of a list of JSON response
        // fields. This covers raw bodies (such as `/document`) and special
        // endpoints (such as the OAuth endpoints).
        if (isset($uf->response_content)) {
            $this->apply_raw_response($x, $uf);
            return;
        }

        $this->cur_fieldf = [];
        $this->cur_fields = [];
        $this->cur_fieldd = [];
        $this->cur_fieldbadges = [];
        $this->cur_fieldconditions = [];
        $this->cur_fieldoneof = [];
        $this->cur_fieldgroup = [];
        $this->cur_fielddefault = [];
        $this->cur_fieldsch = [];
        $this->cur_fieldsch_after = [];
        $this->cur_md_order = [];

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
        $this->apply_md_field_order();

        $bprop = $breq = [];
        foreach ($this->cur_fieldf as $name => $f) {
            if ($name === "*"
                || (($f & self::FM_NONGET) !== 0 && $this->cur_lmethod === "get")
                || ($f & self::F_DEPRECATED) !== 0) {
                continue;
            }
            $bprop[$name] = $this->resolve_info($this->cur_fields[$name] ?? null, $name);
            if (($f & self::F_REQUIRED) !== 0) {
                $breq[] = $name;
            }
        }

        $this->apply_response($x, $bprop, $breq);
    }

    /** Apply `$uf->response_content`, a literal map from HTTP status code (or
     * `default`) to an OpenAPI Response Object, e.g.
     * `{"200": {"description": ..., "content": {"*\/*": {"schema": ...}}}}`.
     * The standard JSON error envelope is added as `default` unless the map
     * supplies its own `default` (so OAuth-style endpoints can opt out with
     * `{"default": {}}`).
     * @param object $x
     * @param object $uf */
    private function apply_raw_response($x, $uf) {
        $x->responses = $x->responses ?? (object) [];

        foreach (get_object_vars($uf->response_content) as $code => $robj) {
            $r = $x->responses->{$code} = $x->responses->{$code} ?? (object) [];
            foreach (get_object_vars(self::deep_clone($robj)) as $k => $v) {
                // do not clobber an existing description unless overriding
                if ($k === "description"
                    && !$this->override_response
                    && ($r->description ?? "") !== "") {
                    continue;
                }
                $r->{$k} = $v;
            }
            // OpenAPI requires every response to have a description
            $r->description = $r->description ?? "";
        }

        // add the JSON error envelope as `default` unless the endpoint defined
        // its own `default` response
        if (!isset($uf->response_content->default)
            && !isset($x->responses->default)) {
            $x->responses->default = (object) [
                "description" => "",
                "content" => (object) [
                    "application/json" => (object) [
                        "schema" => $this->reference_common_schema("error_response")
                    ]
                ]
            ];
        }
    }

    private static function deep_clone($x) {
        $x = (array) $x;
        foreach ($x as $k => &$v) {
            if (is_object($v)) {
                $v = self::deep_clone($v);
            }
        }
        return (object) $x;
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
            if (!$this->merge_allOf) {
                $resps = $this->reference_common_schema("minimal_response");
            } else if (!in_array("minimal_response", $this->cur_fieldsch, true)) {
                $resps = self::deep_clone($this->schemas->minimal_response);
            } else {
                $resps = (object) ["type" => "object", "properties" => (object) []];
            }
            $respj->{"schema"} = $resps;
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
            if (isset($this->cur_fieldsch_after[$sch])) {
                // transient hint consumed by inline_response_schemas()
                $ref->{"x-after"} = $this->cur_fieldsch_after[$sch];
            }
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
        $knownparam = ["ok" => true, "message_list" => true];
        $knownreq = ["ok" => true];
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

        // a `*` field means the response may include unlisted values
        if (isset($this->cur_fieldf["*"])) {
            $respb->additionalProperties = true;
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
            $this->apply_field_metadata($k, $respprop->$k);
            $this->apply_field_default($k, $respprop->$k);
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

    /** Position of a path in the documentation, or PHP_INT_MAX if undocumented.
     * Uses the earliest documentation appearance among the path's operations
     * (e.g. `# get /session`) or the path itself (e.g. `# /session`). */
    private function path_doc_order($pj) {
        $best = PHP_INT_MAX;
        $path = $pj->__path ?? null;
        if ($path === null) {
            return $best;
        }
        if (isset($this->doc_order[$path])) {
            $best = $this->doc_order[$path];
        }
        foreach ($pj as $method => $oj) {
            if (is_object($oj)
                && isset($this->doc_order["{$method} {$path}"])) {
                $best = min($best, $this->doc_order["{$method} {$path}"]);
            }
        }
        return $best;
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
        // Within a tag, follow documentation order when available, then fall
        // back to the apifunctions.json `order`, then the path name.
        $ado = $this->path_doc_order($a);
        $bdo = $this->path_doc_order($b);
        if ($ado !== $bdo) {
            return $ado <=> $bdo;
        }
        $an = substr($a->__path, strrpos($a->__path, "/") + 1);
        $bn = substr($b->__path, strrpos($b->__path, "/") + 1);
        $auf = $this->conf->api($an, null, null);
        $aufx = $this->conf->api_expansion($an, null);
        $buf = $this->conf->api($bn, null, null);
        $bufx = $this->conf->api_expansion($bn, null);
        if ($auf === null || $buf === null) {
            if ($auf !== null || $buf !== null) {
                return $auf === null ? 1 : -1;
            }
        } else {
            $ao = $auf->order ?? $aufx->order ?? PHP_INT_MAX;
            $bo = $buf->order ?? $bufx->order ?? PHP_INT_MAX;
            if ($ao !== $bo) {
                return $ao <=> $bo;
            }
        }
        return strcmp($an, $bn);
    }


    // INLINE response_schema

    /** Inline `* response_schema` references in every 200 response: replace each
     * `allOf` member that `$ref`s a common schema (other than `minimal_response`)
     * with that schema's properties, appended to the response object. Generation
     * already registered every schema, so no `$ref` can dangle; the now-unused
     * common schema simply remains defined in `components`. */
    private function inline_response_schemas() {
        foreach ($this->paths as $pj) {
            if (!is_object($pj)) {
                continue;
            }
            foreach ($pj as $method => $opj) {
                if ($method === "__path" || !is_object($opj)) {
                    continue;
                }
                $schema = $opj->responses->{"200"}->content->{"application/json"}->schema ?? null;
                if (is_object($schema) && is_array($schema->allOf ?? null)) {
                    $this->inline_response_allof($schema);
                }
            }
        }
    }

    /** @param object $schema */
    private function inline_response_allof($schema) {
        $kept = $inlines = [];
        $target = null;
        foreach ($schema->allOf as $member) {
            $ref = is_object($member) ? ($member->{"\$ref"} ?? null) : null;
            if (is_string($ref)
                && preg_match('{\A#/components/schemas/(.+)\z}', $ref, $m)
                && $m[1] !== "minimal_response"
                && is_object($sch = $this->schemas->{$m[1]} ?? $this->basej->components->schemas->{$m[1]} ?? null)) {
                $conds = $sch->{"x-conditions"} ?? null;
                $props = [];
                foreach (get_object_vars($sch->properties ?? (object) []) as $pn => $pdef) {
                    $pd = is_object($pdef) ? self::deep_clone($pdef) : $pdef;
                    if ($conds !== null && is_object($pd) && !isset($pd->{"x-conditions"})) {
                        $pd->{"x-conditions"} = $conds;
                    }
                    $props[$pn] = $pd;
                }
                $inlines[] = [$props, $member->{"x-after"} ?? null, $sch->required ?? []];
                continue; // drop this member; its properties are inlined below
            }
            if (is_object($member)) {
                unset($member->{"x-after"});
            }
            $kept[] = $member;
            if (is_object($member) && ($member->type ?? null) === "object") {
                $target = $member;
            }
        }
        if (empty($inlines)) {
            return;
        }
        if ($target === null) {
            $kept[] = $target = (object) ["type" => "object", "properties" => (object) []];
        }
        $props = (array) ($target->properties ?? (object) []);
        $req = $target->required ?? [];
        foreach ($inlines as [$sprops, $after, $sreq]) {
            $props = self::insert_after($props, $sprops, $after);
            foreach ($sreq as $rq) {
                if (!in_array($rq, $req, true)) {
                    $req[] = $rq;
                }
            }
        }
        $target->properties = (object) $props;
        if (!empty($req)) {
            $target->required = $req;
        }
        $schema->allOf = array_values($kept);
    }

    /** Insert ordered `$new` properties into ordered `$existing` immediately
     * after key `$after` ("" means at the start; null or an unknown key means at
     * the end). Keys already present in `$existing` are skipped.
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $new
     * @param ?string $after
     * @return array<string,mixed> */
    private static function insert_after($existing, $new, $after) {
        $new = array_diff_key($new, $existing);
        if (empty($new)) {
            return $existing;
        } else if ($after === "") {
            return $new + $existing;
        } else if ($after === null || !array_key_exists($after, $existing)) {
            return $existing + $new;
        }
        $out = [];
        foreach ($existing as $k => $v) {
            $out[$k] = $v;
            if ($k === $after) {
                foreach ($new as $nk => $nv) {
                    $out[$nk] = $nv;
                }
            }
        }
        return $out;
    }


    // RUNNING

    /** @return int */
    function run() {
        $mj = $this->j;
        $mj->openapi = "3.1.0";
        $info = $mj->info = $mj->info ?? (object) [];
        if ($this->base) {
            $info->title = "HotCRP REST API";
            $info->version = shell_exec("git log --format=\"format:%cs\" -n1 devel/apidoc etc/apifunctions.json etc/apiexpansions.json batch/apispec.php");
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
                if (!isset($this->setj_paths->$name)) {
                    fwrite(STDERR, $this->jpath_landmark("\$.paths[\"{$name}\"]") . "input path {$name} not specified\n");
                } else {
                    foreach ($pj as $lmethod => $x) {
                        if ($lmethod !== "__path"
                            && !isset($this->setj_paths->$name->$lmethod)) {
                            fwrite(STDERR, $this->jpath_landmark("\$.paths[\"{$name}\"].{$lmethod}") . "input operation {$lmethod} {$name} not specified\n");
                        }
                    }
                }
            }
        }
        foreach ($this->description_map as $name => $djs) {
            if (preg_match('/\A(get|post|delete)\s+(\S+)\z/', $name, $m)
                && !isset($this->paths->{$m[2]}->{$m[1]})
                && ($dj = $this->find_description($name))) {
                fwrite(STDERR, "{$dj->landmark}: description path {$m[2]}.{$m[1]} not specified\n");
            }
        }

        // resolve `* response_schema` references inline
        $this->inline_response_schemas();

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
        $ofile = $this->output_file ?? "-";
        if ($ofile === "-") {
            $out = STDOUT;
            $tmpfile = null;
        } else if (file_exists($ofile) && !is_file($ofile)) {
            // non-regular target (/dev/null, FIFO, /dev/fd/N): write directly
            $tmpfile = null;
            $out = @fopen(safe_filename($ofile), "wb");
            if (!$out) {
                throw error_get_last_as_exception("{$ofile}: ");
            }
        } else {
            $tmpfile = "{$ofile}~";
            $out = @fopen(safe_filename($tmpfile), "wb");
            if (!$out) {
                throw error_get_last_as_exception("{$tmpfile}: ");
            }
        }
        fwrite($out, json_encode($this->j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        if ($out !== STDOUT) {
            fclose($out);
            if ($tmpfile !== null && !rename($tmpfile, $ofile)) {
                throw error_get_last_as_exception("{$ofile}: ");
            }
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
            "i:,input: =FILE Read specification from FILE instead of openapi-base.json",
            "no-merge Do not merge response schemas",
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

        // watch requires a named output file (mirror output resolution above)
        if (isset($arg["o"])) {
            $file = $arg["o"];
        } else if (isset($arg["x"])) {
            $file = "devel/openapi.json";
        } else {
            $file = "-";
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
                $x = @fgets($fswatch, 1024);
                if ($x === false) {
                    exit(1);
                }
                if (($x !== "{$path}\n" && $x !== "{$path}~\n")
                    || microtime(true) - $t > 1) {
                    break;
                }
            }
        }
    }
}
