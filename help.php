<?php
// help.php -- HotCRP help page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

$help_topics = new GroupedExtensions($Me, [
    '{"name":"topics","title":"Help topics","position":-1000000,"priority":1000000,"render_callback":"show_help_topics"}',
    "etc/helptopics.json"
], $Conf->opt("helpTopics"));

if (!$Qreq->t && preg_match('/\A\/\w+\/*\z/i', $Qreq->path())) {
    $Qreq->t = $Qreq->path_component(0);
}
$topic = $Qreq->t ? : "topics";
$want_topic = $help_topics->canonical_group($topic);
if (!$want_topic) {
    $want_topic = "topics";
}
if ($want_topic !== $topic) {
    $Conf->self_redirect($Qreq, ["t" => $want_topic]);
}
$topicj = $help_topics->get($topic);

$Conf->header("Help", "help", ["title_div" => '<hr class="c">', "body_class" => "leftmenu"]);

class HtHead extends Ht {
    public $conf;
    public $user;
    private $_tabletype;
    private $_rowidx;
    private $_help_topics;
    private $_renderers = [];
    private $_sv;
    private $_h3ids;
    function __construct($help_topics, Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->_help_topics = $help_topics;
        $this->_help_topics->set_context(["hclass" => "helppage", "args" => [$this]]);
    }
    function subhead($title, $id = null) {
        if (!$id && $title) {
            $id = preg_replace('/[^A-Za-z0-9]+|<.*?>/', "-", strtolower($title));
            if (str_ends_with($id, "-")) {
                $id = substr($id, 0, strlen($id) - 1);
            }
            if (preg_match('/\A(?:htctl.*|fold.*|body.*|tracker.*|msg.*|header.*|quicklink.*|tla.*|-|)\z/', $id)) {
                $id = ($id === "" || $id === "-" ? null : "h-$id");
            }
            if ($id) {
                $n = "";
                while (isset($this->_h3ids[$id . $n])) {
                    $n = (int) $n + 1;
                }
                $id .= $n;
            }
        }
        if ($id || $title) {
            if ($id) {
                $this->_h3ids[$id] = true;
            }
            return '<h3 class="helppage"' . ($id ? " id=\"{$id}\"" : "") . '>' . $title . "</h3>\n";
        } else {
            return "";
        }
    }
    function table($tabletype = false) {
        $this->_rowidx = 0;
        $this->_tabletype = $tabletype;
        return $this->_tabletype ? "" : '<table class="demargin"><tbody>';
    }
    function tgroup($title, $id = null) {
        $this->_rowidx = 0;
        if ($this->_tabletype) {
            return $this->subhead($title, $id);
        } else {
            return '<tr><td class="sentry nw remargin-left remargin-right" colspan="2"><h4 class="helppage"'
                . ($id ? " id=\"{$id}\"" : "") . '>'
                . $title . "</h4></td></tr>\n";
        }
    }
    function trow($caption, $entry = null) {
        if ($this->_tabletype) {
            $t = "<div class=\"helplist-item demargin k{$this->_rowidx}\">"
                . "<table><tbody><tr>"
                . "<td class=\"helplist-dt remargin-left\">"
                . $caption
                . "</td><td class=\"helplist-dd remargin-right\">"
                . $entry . "</td></tr></tbody></table></div>\n";
        } else {
            $t = '<tr class="k' . $this->_rowidx . '"><td class="sentry remargin-left';
            if ((string) $entry === "") {
                $t .= ' remargin-right" colspan="2">' . $caption;
            } else {
                $t .= '">' . $caption . '</td><td class="sentry remargin-right">' . $entry;
            }
            $t .= "</td></tr>\n";
        }
        $this->_rowidx = 1 - $this->_rowidx;
        return $t;
    }
    function end_table() {
        return $this->_tabletype ? "" : "</tbody></table>\n";
    }
    function hotlink($html, $page, $options = null, $js = []) {
        if (!isset($js["rel"])) {
            $js["rel"] = "nofollow";
        }
        return $this->conf->hotlink($html, $page, $options, $js);
    }
    function search_link($html, $q = null, $js = []) {
        if ($q === null) {
            $q = $html;
        }
        if (is_string($q)) {
            $q = ["q" => $q];
        }
        return $this->hotlink($html ? : htmlspecialchars($q["q"]), "search", $q, $js);
    }
    function help_link($html, $topic = null) {
        if ($topic === null) {
            $topic = $html;
            $html = "Learn more";
        }
        if (is_string($topic) && ($hash = strpos($topic, "#")) !== false) {
            $topic = ["t" => substr($topic, 0, $hash), "anchor" => substr($topic, $hash + 1)];
        } else if (is_string($topic)) {
            $topic = ["t" => $topic];
        }
        if (isset($topic["t"]) && ($group = $this->_help_topics->canonical_group($topic["t"]))) {
            $topic["t"] = $group;
        }
        return $this->hotlink($html, "help", $topic);
    }
    function setting_link($html, $siname = null) {
        if ($this->user->privChair || $siname !== null) {
            $pre = $post = "";
            if ($this->_sv === null) {
                $this->_sv = new SettingValues($this->user);
            }
            if ($siname === null) {
                $siname = $html;
                $html = "Change this setting";
                $pre = " (";
                $post = ")";
            }
            if (($si = Si::get($this->conf, $siname))) {
                $param = $si->hoturl_param($this->conf);
            } else if (($g = $this->_sv->canonical_group($siname))) {
                $param = ["group" => $g];
            } else {
                error_log("missing setting information for $siname");
                $param = [];
            }
            $t = $pre . '<a href="' . $this->conf->hoturl("settings", $param);
            if (!$this->user->privChair) {
                $t .= '" class="u need-tooltip" aria-label="This link to a settings page only works for administrators.';
            }
            return $t . '" rel="nofollow">' . $html . '</a>' . $post;
        } else {
            return '';
        }
    }
    function search_form($q, $size = 20) {
        if (is_string($q)) {
            $q = ["q" => $q];
        }
        $t = Ht::form($this->conf->hoturl("search"), ["method" => "get", "class" => "nw"])
            . Ht::entry("q", $q["q"], ["size" => $size])
            . " &nbsp;"
            . Ht::submit("go", "Search");
        foreach ($q as $k => $v) {
            if ($k !== "q")
                $t .= Ht::hidden($k, $v);
        }
        return $t . "</form>";
    }
    function search_trow($q, $entry) {
        return $this->trow($this->search_form($q, 36), $entry);
    }
    function example_tag($property) {
        $vt = [];
        if ($this->user->isPC) {
            $vt = $this->conf->tags()->filter($property);
        }
        return empty($vt) ? $property : current($vt)->tag;
    }
    function current_tag_list($property) {
        $vt = [];
        if ($this->user->isPC) {
            $vt = $this->conf->tags()->filter($property);
        }
        if (empty($vt)) {
            return "";
        } else {
            return " (currently " . join(", ", array_map(function ($t) {
                return $this->search_link($t->tag, "#{$t->tag}");
            }, $vt)) . ")";
        }
    }
    function render_group($topic, $top = false) {
        $this->_help_topics->render_group($topic, ["top" => $top]);
    }
    function groups() {
        return $this->_help_topics->groups();
    }
    function member($name) {
        return $this->_help_topics->get($name);
    }
}

$hth = new HtHead($help_topics, $Me);


function show_help_topics($hth) {
    echo "<dl>\n";
    foreach ($hth->groups() as $ht) {
        if ($ht->name !== "topics" && isset($ht->title)) {
            echo '<dt><strong><a href="', $hth->conf->hoturl("help", "t=$ht->name"), '">', $ht->title, '</a></strong></dt>';
            if (isset($ht->description))
                echo '<dd>', get($ht, "description", ""), '</dd>';
            echo "\n";
        }
    }
    echo "</dl>\n";
}


function meaningful_pc_tag(Contact $user) {
    foreach ($user->conf->viewable_user_tags($user) as $tag) {
        if ($tag !== "pc")
            return $tag;
    }
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


echo '<div class="leftmenu-left"><nav class="leftmenu-menu"><h1 class="leftmenu">Help</h1><div class="leftmenu-list">';
foreach ($help_topics->groups() as $gj) {
    if (isset($gj->title)) {
        echo '<div class="leftmenu-item',
            ($gj->name === "topics" ? " mb-3" : ""),
            ($gj->name === $topic ? ' active">' : ' ui js-click-child">');
        if ($gj->name === $topic) {
            echo $gj->title;
        } else {
            echo Ht::link($gj->title, $Conf->hoturl("help", "t=$gj->name"));
        }
        echo '</div>';
    }
}
echo "</div></nav></div>\n",
    '<main id="helpcontent" class="leftmenu-content main-column">',
    '<h2 class="leftmenu">', $topicj->title, '</h2>';
$hth->render_group($topic, true);
echo "</main>\n";


$Conf->footer();
