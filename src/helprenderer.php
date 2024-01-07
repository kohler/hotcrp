<?php
// helprenderer.php -- HotCRP help renderer class
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class HelpRenderer extends Ht {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var bool */
    private $_tabletype;
    /** @var int */
    private $_rowidx;
    /** @var ComponentSet */
    private $_help_topics;
    /** @var ?SettingValues */
    private $_sv;
    /** @var array<string,true> */
    private $_h3ids = [];

    function __construct(ComponentSet $help_topics, Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->_help_topics = $help_topics;
        $this->_help_topics->set_title_class("helppage")->set_context_args($this);
    }

    /** @param string $title
     * @param ?string $id
     * @return string */
    function subhead($title, $id = null) {
        if (!$id && $title) {
            $id = preg_replace('/[^A-Za-z0-9]+|<.*?>/', "-", strtolower($title));
            if (str_ends_with($id, "-")) {
                $id = substr($id, 0, strlen($id) - 1);
            }
            if (preg_match('/\A(?:fold.*|-.*|[a-z]-.*|)\z/', $id)) {
                $id = ($id === "" || $id === "-" ? null : "heading-{$id}");
            }
            if ($id) {
                $n = "";
                while (isset($this->_h3ids[$id . $n])) {
                    $n = (int) $n + 1;
                }
                $id .= $n;
            }
        }
        if (!$id && !$title) {
            return "";
        } else if ($id) {
            $this->_h3ids[$id] = true;
            return "<h3 class=\"helppage\" id=\"{$id}\">{$title}</h3>\n";
        } else {
            return "<h3 class=\"helppage\">{$title}</h3>\n";
        }
    }

    /** @param bool $tabletype
     * @return string */
    function table($tabletype = false) {
        $this->_rowidx = 0;
        $this->_tabletype = $tabletype;
        return $this->_tabletype ? "" : '<table class="sentry-demargin"><tbody>';
    }

    /** @param string $title
     * @param ?string $id
     * @return string */
    function tgroup($title, $id = null) {
        $this->_rowidx = 0;
        if ($this->_tabletype) {
            return $this->subhead($title, $id);
        } else {
            return '<tr><td class="sentry nw" colspan="2"><h4 class="helppage"'
                . ($id ? " id=\"{$id}\"" : "") . '>'
                . $title . "</h4></td></tr>\n";
        }
    }

    /** @param string $caption
     * @param ?string $entry
     * @return string */
    function trow($caption, $entry = null) {
        if ($this->_tabletype) {
            $t = "<div class=\"helplist-item demargin k{$this->_rowidx}\">"
                . "<table><tbody><tr>"
                . "<td class=\"helplist-dt remargin-left\">"
                . $caption
                . "</td><td class=\"helplist-dd remargin-right\">"
                . $entry . "</td></tr></tbody></table></div>\n";
        } else {
            $t = '<tr class="k' . $this->_rowidx . '"><td class="sentry';
            if ((string) $entry === "") {
                $t .= '" colspan="2">' . $caption;
            } else {
                $t .= '">' . $caption . '</td><td class="sentry">' . $entry;
            }
            $t .= "</td></tr>\n";
        }
        $this->_rowidx = 1 - $this->_rowidx;
        return $t;
    }

    /** @return string */
    function end_table() {
        return $this->_tabletype ? "" : "</tbody></table>\n";
    }

    /** @param string $html
     * @param string $page
     * @param null|string|array $options
     * @param array $js
     * @return string */
    function hotlink($html, $page, $options = null, $js = []) {
        if (!isset($js["rel"])) {
            $js["rel"] = "nofollow";
        }
        return $this->conf->hotlink($html, $page, $options, $js);
    }

    /** @param string $html
     * @param string|array{q:string} $q
     * @param array $js
     * @return string */
    function search_link($html, $q = null, $js = []) {
        if ($q === null) {
            $q = $html;
        }
        if (is_string($q)) {
            $q = ["q" => $q];
        }
        return $this->hotlink($html ? : htmlspecialchars($q["q"]), "search", $q, $js);
    }

    /** @param string $html
     * @param ?string $topic
     * @return string */
    function help_link($html, $topic = null) {
        if ($topic === null) {
            $topic = $html;
            $html = "Learn more";
        }
        if (is_string($topic) && ($hash = strpos($topic, "#")) !== false) {
            $topic = ["t" => substr($topic, 0, $hash), "#" => substr($topic, $hash + 1)];
        } else if (is_string($topic)) {
            $topic = ["t" => $topic];
        }
        if (isset($topic["t"]) && ($group = $this->_help_topics->canonical_group($topic["t"]))) {
            $topic["t"] = $group;
        }
        return $this->hotlink($html, "help", $topic);
    }

    /** @param string $html
     * @param string $siname
     * @return string */
    function setting_link($html, $siname) {
        if ($this->_sv === null) {
            $this->_sv = new SettingValues($this->user);
        }
        if (($si = $this->conf->si($siname))) {
            $param = $si->hoturl_param();
        } else {
            error_log("missing setting information for $siname\n" . debug_string_backtrace());
            $param = [];
        }
        $url = $this->conf->hoturl("settings", $param);
        $rest = $this->user->privChair ? "" : ' class="noq need-tooltip" aria-label="This link to a settings page only works for administrators."';
        return "<a href=\"{$url}\"{$rest} rel=\"nofollow\">{$html}</a>";
    }

    /** @param string $siname
     * @return string */
    function change_setting_link($siname) {
        if ($this->user->privChair
            && ($t = $this->setting_link("Change this setting", $siname)) !== "") {
            return " ({$t})";
        } else {
            return "";
        }
    }

    /** @param string $html
     * @param string $sg
     * @return string */
    function setting_group_link($html, $sg) {
        if ($this->_sv === null) {
            $this->_sv = new SettingValues($this->user);
        }
        if (($g = $this->_sv->group_item($sg))) {
            $param = ["group" => $this->_sv->canonical_group($g->group), "#" => $g->hashid ?? null];
        } else {
            error_log("missing setting_group information for $sg\n" . debug_string_backtrace());
            $param = [];
        }
        $url = $this->conf->hoturl("settings", $param);
        $rest = $this->user->privChair ? "" : ' class="noq need-tooltip" aria-label="This link to a settings page only works for administrators."';
        return "<a href=\"{$url}\"{$rest} rel=\"nofollow\">{$html}</a>";
    }

    /** @param string|array{q:string} $q
     * @return string */
    function search_form($q, $size = 20) {
        if (is_string($q)) {
            $q = ["q" => $q];
        }
        $t = Ht::form($this->conf->hoturl("search"), ["method" => "get", "class" => "nw"])
            . Ht::entry("q", $q["q"], ["size" => $size])
            . " &nbsp;"
            . Ht::submit("Search");
        foreach ($q as $k => $v) {
            if ($k !== "q")
                $t .= Ht::hidden($k, $v);
        }
        return $t . "</form>";
    }

    /** @param string|array{q:string} $q
     * @param string $entry
     * @return string */
    function search_trow($q, $entry) {
        return $this->trow($this->search_form($q, 36), $entry);
    }

    /** @param int $flags
     * @return ?string */
    function example_tag($flags) {
        if ($this->user->isPC) {
            foreach ($this->conf->tags()->settings_having($flags) as $dt) {
                if ($this->user->can_view_some_tag($dt->tag))
                    return $dt->tag;
            }
        }
        return null;
    }

    /** @param int $flags
     * @return list<string> */
    function tag_settings_having($flags) {
        $ts = [];
        if ($this->user->isPC) {
            foreach ($this->conf->tags()->sorted_settings_having($flags) as $dt) {
                if ($this->user->can_view_some_tag($dt->tag))
                    $ts[] = $dt->tag;
            }
        }
        return $ts;
    }

    /** @param int $flags
     * @return string */
    function tag_settings_having_note($flags) {
        $ts = $this->tag_settings_having($flags);
        if (empty($ts)) {
            return "";
        } else {
            return " (currently " . join(", ", array_map(function ($t) {
                return $this->search_link($t, "#{$t}");
            }, $ts)) . ")";
        }
    }

    /** @param string $topic */
    function print_members($topic) {
        $this->_help_topics->print_members($topic);
    }

    /** @return list<object> */
    function groups() {
        return $this->_help_topics->groups();
    }

    /** @param string $name
     * @return ?object */
    function member($name) {
        return $this->_help_topics->get($name);
    }

    /** @param string $name
     * @return ?string */
    function hashid($name) {
        return $this->_help_topics->hashid($name);
    }

    /** @return ?string */
    function meaningful_pc_tag() {
        foreach ($this->conf->viewable_user_tags($this->user) as $tag) {
            if ($tag !== "pc")
                return $tag;
        }
        return null;
    }

    /** @return ?string */
    function meaningful_review_round_name() {
        if ($this->user->isPC) {
            $rounds = $this->conf->round_list();
            for ($i = 1; $i < count($rounds); ++$i) {
                if ($rounds[$i] !== ";")
                    return $rounds[$i];
            }
        }
        return null;
    }
}
