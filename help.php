<?php
// help.php -- HotCRP help page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

$help_topics = new GroupedExtensions($Me, [
    '{"name":"topics","title":"Help topics","position":-1000000,"priority":1000000,"function":"show_help_topics"}',
    "etc/helptopics.json"
], $Conf->opt("helpTopics"));

$Qreq = make_qreq();
if (!$Qreq->t && preg_match(',\A/(\w+)\z,i', Navigation::path()))
    $Qreq->t = substr(Navigation::path(), 1);
$topic = $Qreq->t ? : "topics";
$want_topic = $help_topics->canonical_group($topic);
if (!$want_topic)
    $want_topic = "topics";
if ($want_topic !== $topic)
    redirectSelf(["t" => $want_topic]);
$topicj = $help_topics->get($topic);

$Conf->header_head($topic === "topics" ? "Help" : "Help - {$topicj->title}");
$Conf->header_body("Help", "help", actionBar());

class HtHead extends Ht {
    public $conf;
    public $user;
    private $_tabletype;
    private $_rowidx;
    private $_help_topics;
    private $_renderers = [];
    function __construct(Contact $user, $help_topics) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->_help_topics = $help_topics;
    }
    static function subhead($title, $id = null) {
        if ($id || $title)
            return '<h3 class="helppage"' . ($id ? " id=\"{$id}\"" : "") . '>' . $title . "</h3>\n";
        else
            return "";
    }
    function table($tabletype = false) {
        $this->_rowidx = 0;
        $this->_tabletype = $tabletype;
        return $this->_tabletype ? "" : '<table class="helppage"><tbody>';
    }
    function tgroup($title, $id = null) {
        $this->_rowidx = 0;
        if ($this->_tabletype)
            return $this->subhead($title, $id);
        else
            return '<tr><td class="sentry nw" colspan="2"><h4 class="helppage"'
                . ($id ? " id=\"{$id}\"" : "") . '>'
                . $title . "</h4></td></tr>\n";
    }
    function trow($caption, $entry = null) {
        if ($this->_tabletype) {
            $t = "<div class=\"helplist-item k{$this->_rowidx}\">"
                . "<table class=\"helppage\"><tbody><tr><td class=\"helplist-dt\">"
                . $caption
                . "</td><td class=\"helplist-dd\">"
                . $entry . "</td></tr></tbody></table></div>\n";
        } else {
            $t = "<tr class=\"k{$this->_rowidx}\"><td class=\"sentry\"";
            if ((string) $entry === "")
                $t .= ' colspan="2">' . $caption;
            else
                $t .= '>' . $caption . '</td><td class="sentry">' . $entry;
            $t .= "</td></tr>\n";
        }
        $this->_rowidx = 1 - $this->_rowidx;
        return $t;
    }
    function end_table() {
        return $this->_tabletype ? "" : "</tbody></table>\n";
    }
    function search_link($html, $q = null) {
        if ($q === null)
            $q = $html;
        if (is_string($q))
            $q = ["q" => $q];
        return '<a href="' . hoturl("search", $q) . '">'
            . ($html ? : htmlspecialchars($q["q"])) . '</a>';
    }
    function help_link($html, $topic = null) {
        if ($topic === null) {
            $topic = $html;
            $html = "Learn more";
        }
        if (is_string($topic) && ($hash = strpos($topic, "#")) !== false)
            $topic = ["t" => substr($topic, 0, $hash), "anchor" => substr($topic, $hash + 1)];
        else if (is_string($topic))
            $topic = ["t" => $topic];
        if (isset($topic["t"]) && ($group = $this->_help_topics->canonical_group($topic["t"])))
            $topic["t"] = $group;
        return '<a href="' . hoturl("help", $topic) . '">' . $html . '</a>';
    }
    function settings_link($html, $group = null) {
        if ($this->user->privChair) {
            if ($group === null) {
                $group = is_string($html) ? ["group" => $html] : $html;
                return ' (<a href="' . hoturl("settings", $group) . '">Change this setting</a>)';
            } else {
                $group = is_string($group) ? ["group" => $group] : $group;
                return '<a href="' . hoturl("settings", $group) . '">' . $html . '</a>';
            }
        } else {
            if ($group === null)
                return '';
            else
                return $html;
        }
    }
    function search_form($q, $size = 20) {
        if (is_string($q))
            $q = ["q" => $q];
        $t = Ht::form_div(hoturl("search"), ["method" => "get", "divclass" => "nw"])
            . Ht::entry("q", $q["q"], ["size" => $size])
            . " &nbsp;"
            . Ht::submit("go", "Search");
        foreach ($q as $k => $v) {
            if ($k !== "q")
                $t .= Ht::hidden($k, $v);
        }
        return $t . "</div></form>";
    }
    function search_trow($q, $entry) {
        return $this->trow($this->search_form($q, 36), $entry);
    }
    function example_tag($property) {
        $vt = [];
        if ($this->user->isPC)
            $vt = $this->conf->tags()->filter($property);
        return empty($vt) ? $property : current($vt)->tag;
    }
    function current_tag_list($property) {
        $vt = [];
        if ($this->user->isPC)
            $vt = $this->conf->tags()->filter($property);
        if (empty($vt))
            return "";
        else
            return " (currently " . join(", ", array_map(function ($t) {
                return $this->search_link($t->tag, "#{$t->tag}");
            }, $vt)) . ")";
    }
    function echo_topic($topic) {
        foreach ($this->_help_topics->members($topic) as $gj) {
            Conf::xt_resolve_require($gj);
            if (isset($gj->function))
                call_user_func($gj->function, $this, $gj);
            else if (isset($gj->renderer))
                call_user_func($gj->renderer, $this, $gj);
            else if (isset($gj->method)) {
                $method = $gj->method;
                if (($colons = strpos($method, "::"))) {
                    $klass = substr($method, 0, $colons);
                    $method = substr($method, $colons + 2);
                } else
                    $klass = $gj->factory_class;
                if (!isset($renderers[$klass]))
                    $renderers[$klass] = new $klass($this, $gj);
                call_user_func([$renderers[$klass], $method], $gj);
            }
        }
    }
    function groups() {
        return $this->_help_topics->groups();
    }
}

$hth = new HtHead($Me, $help_topics);


function show_help_topics($hth) {
    echo "<dl>\n";
    foreach ($hth->groups() as $ht) {
        if ($ht->name !== "topics" && isset($ht->title)) {
            echo '<dt><strong><a href="', hoturl("help", "t=$ht->name"), '">', $ht->title, '</a></strong></dt>';
            if (isset($ht->description))
                echo '<dd>', get($ht, "description", ""), '</dd>';
            echo "\n";
        }
    }
    echo "</dl>\n";
}


function meaningful_pc_tag(Contact $user) {
    if ($user->isPC)
        foreach ($user->conf->pc_tags() as $tag)
            if ($tag !== "pc")
                return $tag;
    return false;
}

function meaningful_round_name(Contact $user) {
    if ($user->isPC) {
        $rounds = $user->conf->round_list();
        for ($i = 1; $i < count($rounds); ++$i)
            if ($rounds[$i] !== ";")
                return $rounds[$i];
    }
    return false;
}


echo '<div class="leftmenu_menucontainer"><div class="leftmenu_list">';
foreach ($help_topics->groups() as $gj) {
    if ($gj->name === $topic)
        echo '<div class="leftmenu_item_on">', $gj->title, '</div>';
    else if (isset($gj->title))
        echo '<div class="leftmenu_item">',
            '<a href="', hoturl("help", "t=$gj->name"), '">', $gj->title, '</a></div>';
    if ($gj->name === "topics")
        echo '<div class="c g"></div>';
}
echo "</div></div>\n",
    '<div class="leftmenu_content_container"><div class="leftmenu_content">',
    '<div id="helpcontent" class="leftmenu_body">';
Ht::stash_script("jQuery(\".leftmenu_item\").click(divclick)");

echo '<h2 class="helppage">', $topicj->title, '</h2>';
$hth->echo_topic($topic);
echo "</div></div></div>\n";


$Conf->footer();
