// settings.js -- HotCRP JavaScript library for settings
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

function next_lexicographic_permutation(i, size) {
    var y = (i & -i) || 1, c = i + y, highbit = 1 << size;
    i = (((i ^ c) >> 2) / y) | c;
    if (i >= highbit) {
        i = ((i & (highbit - 1)) << 2) | 3;
        if (i >= highbit)
            i = false;
    }
    return i;
}

handle_ui.on("js-settings-resp-active", function (event) {
    $(".if-response-active").toggleClass("hidden", !this.checked);
});

$(function () { $(".js-settings-resp-active").trigger("change"); });

handle_ui.on("js-settings-au-seerev-tag", function (event) {
    $("#au_seerev_3").click(); // AUSEEREV_TAGS
});

handle_ui.on("js-settings-sub-nopapers", function (event) {
    var v = $(this).val();
    hotcrp.fold("pdfupload", v == 1, 2);
    hotcrp.fold("pdfupload", v != 0, 3);
});

$(function () { $(".js-settings-sub-nopapers").trigger("change"); });

handle_ui.on("js-settings-sf-type", function (event) {
    var v = this.value;
    $(this).closest(".settings-sf").find(".has-optvt-condition").each(function () {
        toggleClass(this, "hidden", this.getAttribute("data-optvt-condition").split(" ").indexOf(v) < 0);
    });
});

handle_ui.on("js-settings-show-property", function () {
    var prop = this.getAttribute("data-property"),
        $j = $(this).closest(".settings-sf, .settings-rf").find(".is-property-" + prop);
    $j.removeClass("hidden");
    addClass(this, "btn-disabled");
    tooltip.erase.call(this);
    if (document.activeElement === this || document.activeElement === document.body) {
        var $jx = $j.find("input, select, textarea").not("[type=hidden], :disabled");
        $jx.length && setTimeout(function () { focus_at($jx[0]); }, 0);
    }
});

handle_ui.on("js-settings-sf-move", function (event) {
    var odiv = $(this).closest(".settings-sf")[0];
    if (hasClass(this, "moveup") && odiv.previousSibling)
        odiv.parentNode.insertBefore(odiv, odiv.previousSibling);
    else if (hasClass(this, "movedown") && odiv.nextSibling)
        odiv.parentNode.insertBefore(odiv, odiv.nextSibling.nextSibling);
    else if (hasClass(this, "delete")) {
        var $odiv = $(odiv), x;
        if ($odiv.find(".settings-sf-id").val() === "new")
            $odiv.remove();
        else {
            tooltip.erase.call(this);
            $odiv.find(".settings-sf-fp").val("deleted").change();
            $odiv.find(".f-i, .entryi").each(function () {
                if (!$(this).find(".settings-sf-fp").length)
                    $(this).remove();
            });
            $odiv.find("input[type=text]").prop("disabled", true).addClass("text-decoration-line-through");
            if ((x = this.getAttribute("data-option-exists")))
                $odiv.append('<div class="f-i"><em>This field will be deleted from the submission form and from ' + plural(x, 'submission') + '.</em></div>');
            else
                $odiv.append('<div class="f-i"><em>This field will be deleted from the submission form. It is not used on any submissions.</em></div>');
        }
    }
    settings_sf_positions();
});

handle_ui.on("js-settings-sf-new", function (event) {
    var h = $("#settings-sf-new").attr("data-template");
    var next = 1;
    while ($("#optn_" + next).length)
        ++next;
    h = h.replace(/_0/g, "_" + next);
    var odiv = $(h).appendTo("#settings-sform");
    odiv.find(".need-autogrow").autogrow();
    odiv.find(".need-tooltip").each(tooltip);
    odiv.find(".js-settings-sf-type").change();
    $("#optn_" + next)[0].focus();
    settings_sf_positions();
});

function settings_sf_positions() {
    if ($(".settings-sf").length) {
        $(".settings-sf .moveup, .settings-sf .movedown").prop("disabled", false);
        $(".settings-sf:first-child .moveup").prop("disabled", true);
        $(".settings-sf:last-child .movedown").prop("disabled", true);
        var index = 0;
        $(".settings-sf-fp").each(function () {
            if (this.value !== "deleted" && this.name !== "optfp_0") {
                ++index;
                if (this.value != index)
                    $(this).val(index).change();
            }
        });
    }
}

$(settings_sf_positions);


handle_ui.on("js-settings-banal-pagelimit", function (evt) {
    var s = $.trim(this.value),
        empty = s === "" || s.toUpperCase() === "N/A",
        $ur = $(this).closest(".has-fold").find(".settings-banal-unlimitedref");
    $ur.find("label").toggleClass("dim", empty);
    $ur.find("input").prop("disabled", empty);
    if (evt && evt.type === "change" && empty)
        $ur.find("input").prop("checked", false);
});


handle_ui.on("js-settings-add-decision-type", function (event) {
    var $t = $("#settings-decision-types"), next = 1;
    while ($t.find("input[name=dec_name_n" + next + "]").length)
        ++next;
    $("#settings-decision-type-notes").removeClass("hidden");
    var h = $("#settings-new-decision-type").html().replace(/_\$/g, "_n" + next),
        $r = $(h).appendTo($t);
    $r.find("input[type=text]").autogrow();
    $r.find("input[name=dec_name_n" + next + "]")[0].focus();
});

handle_ui.on("js-settings-remove-decision-type", function (event) {
    var $r = $(this).closest(".is-decision-type"),
        ne = $r.find("input[name^=dec_name]")[0],
        sc = ne.getAttribute("data-submission-count")|0;
    if (ne.name.startsWith("dec_name_n")) {
        $r.remove();
        if (!$("#settings-decision-types .is-new-decision-type").length)
            $("#settings-decision-type-notes").addClass("hidden");
    } else {
        foldup.call(ne, {f: true});
        addClass(ne, "hidden");
        ne.value = "";
        $(ne).after('<span class="text-decoration-line-through">'.concat(escape_html(ne.defaultValue), '</span>'));
        var t = '<div class="f-i"><em>This decision will be removed';
        if (sc)
            t = t.concat(' and <a href="', hoturl_html("search", {q: "dec:\"" + ne.defaultValue + "\""}), '" target="_blank">', plural(sc, 'submission'), '</a> set to undecided');
        $r.after(t + '.</em></div>');
        form_highlight($r.closest("form"));
    }
});

handle_ui.on("js-settings-new-autosearch", function (event) {
    var odiv = $(this).closest(".settings_tag_autosearch")[0],
        h = $("#settings_newtag_autosearch").html(), next = 1;
    while ($("#tag_autosearch_t_" + next).length)
        ++next;
    h = h.replace(/_0/g, "_" + next);
    odiv = $(h).appendTo("#settings_tag_autosearch");
    odiv.find("input[type=text]").autogrow();
    $("#tag_autosearch_t_" + next)[0].focus();
});

handle_ui.on("js-settings-delete-autosearch", function (event) {
    var odiv = $(this).closest(".settings_tag_autosearch")[0];
    $(odiv).find("input[name^=tag_autosearch_q_]").val("");
    $(odiv).find("input[type=text]").prop("disabled", true).addClass("text-decoration-line-through");
});

handle_ui.on("js-settings-add-track", function () {
    for (var i = 1; jQuery("#trackgroup" + i).length; ++i)
        /* do nothing */;
    $("#trackgroup" + (i - 1)).after("<div id=\"trackgroup" + i + "\" class=\"mg has-fold fold3o\"></div>");
    var $j = jQuery("#trackgroup" + i);
    $j.html(jQuery("#trackgroup0").html().replace(/_track0/g, "_track" + i));
    $j.find(".need-suggest").each(suggest);
    $j.find("input[name^=name]").focus();
});

handle_ui.on("js-settings-copy-topics", function () {
    var topics = [];
    $(this).closest(".has-copy-topics").find("[name^=top]").each(function () {
        topics.push(escape_html(this.value));
    });
    var node = $("<textarea></textarea>").appendTo(document.body);
    node.val(topics.join("\n"));
    node[0].select();
    document.execCommand("copy");
    node.remove();
});


window.review_round_settings = (function ($) {
var added = 0;

function namechange() {
    var roundnum = this.id.substr(10), name = $.trim($(this).val());
    $("#rev_roundtag_" + roundnum + ", #extrev_roundtag_" + roundnum)
        .text(name === "" ? "(no name)" : name);
}

function add() {
    var i, h, j;
    for (i = 1; $("#roundname_" + i).length; ++i)
        /* do nothing */;
    $("#round_container").show();
    $("#roundtable").append($("#newround").html().replace(/\$/g, i));
    var $mydiv = $("#roundname_" + i).closest(".js-settings-review-round");
    $("#rev_roundtag").append('<option value="#' + i + '" id="rev_roundtag_' + i + '">(new round)</option>');
    $("#extrev_roundtag").append('<option value="#' + i + '" id="extrev_roundtag_' + i + '">(new round)</option>');
    $("#roundname_" + i).focus().on("input change", namechange);
}

function kill() {
    var divj = $(this).closest(".js-settings-review-round"),
        roundnum = divj.data("reviewRoundNumber"),
        vj = divj.find("input[name=deleteround_" + roundnum + "]"),
        ej = divj.find("input[name=roundname_" + roundnum + "]");
    if (vj.val()) {
        vj.val("");
        divj.find(".js-settings-review-round-deleted").remove();
        ej.prop("disabled", false);
        $(this).html("Delete round");
    } else {
        vj.val(1);
        ej.prop("disabled", true);
        $(this).html("Restore round").after('<strong class="js-settings-review-round-deleted" style="padding-left:1.5em;font-style:italic;color:red">&nbsp; Review round deleted</strong>');
    }
    divj.find("table").toggle(!vj.val());
    form_highlight("#settingsform");
}

return function () {
    $("#roundtable input[type=text]").on("input change", namechange);
    $("#settings_review_round_add").on("click", add);
    $("#roundtable").on("click", ".js-settings-review-round-delete", kill);
};
})($);


window.review_form_settings = (function () {
var fieldorder, original, samples, stemplate, ttemplate,
    colors = ["sv", "Red to green", "svr", "Green to red",
              "sv-blpu", "Blue to purple", "sv-publ", "Purple to blue",
              "sv-viridis", "Purple to yellow", "sv-viridisr", "Yellow to purple"];

function get_fid(elt) {
    return elt.id.replace(/^.*_/, "");
}

function unparse_option(fieldj, idx) {
    if (fieldj.option_letter) {
        var cc = fieldj.option_letter.charCodeAt(0);
        return String.fromCharCode(cc + fieldj.options.length - idx);
    } else
        return idx.toString();
}

function options_to_text(fieldj) {
    var i, t = [];
    if (!fieldj.options)
        return "";
    for (i = 0; i !== fieldj.options.length; ++i)
        t.push(unparse_option(fieldj, i + 1) + ". " + fieldj.options[i]);
    if (fieldj.option_letter)
        t.reverse();
    if (t.length)
        t.push(""); // get a trailing newline
    return t.join("\n");
}

function option_class_prefix(fieldj) {
    var sv = fieldj.option_class_prefix || "sv";
    if (fieldj.option_letter)
        sv = colors[(colors.indexOf(sv) || 0) ^ 2];
    return sv;
}

function fill_order() {
    var i, c = $("#settings-rform")[0], n, pos;
    for (i = 1, n = c.firstChild; n; n = n.nextSibling) {
        if (hasClass(n, "deleted")) {
            pos = 0;
        } else {
            pos = i++;
        }
        $(n).find(".rf-position").val(pos);
    }
    form_highlight("#settingsform");
}

function fold_property(fid, property, $j, hideval) {
    var $f = $("#rf_" + fid), hidden = true;
    for (var i = 0; i !== $j.length; ++i) {
        hidden = hidden && !input_differs($j[i]) && $($j[i]).val() == hideval[i];
    }
    $f.find(".is-property-" + property).toggleClass("hidden", hidden);
    $f.find(".js-settings-show-property[data-property=\"".concat(property, "\"]")).toggleClass("btn-disabled", !hidden);
}

function fold_properties(fid) {
    fold_property(fid, "description", $("#rf_description_" + fid), [""]);
    fold_property(fid, "editing", $("#rf_ec_" + fid), ["all"]);
}

function rf_fill_control(sel, value, order) {
    var $j = $(sel).val(value);
    order && $j.attr("data-default-value", value);
}

function rf_fill($f, fid, fieldj, order) {
    fieldj = fieldj || original[fid] || {};
    rf_fill_control("#rf_name_" + fid, fieldj.name || "", order);
    rf_fill_control("#rf_description_" + fid, fieldj.description || "", order);
    rf_fill_control("#rf_visibility_" + fid, fieldj.visibility || "pc", order);
    rf_fill_control("#rf_options_" + fid, options_to_text(fieldj), order);
    rf_fill_control("#rf_required_" + fid, fieldj.required ? "1" : "0", order);
    rf_fill_control("#rf_colorsflipped_" + fid, fieldj.option_letter ? "1" : "", order);
    rf_fill_control("#rf_colors_" + fid, option_class_prefix(fieldj), order);
    var ec, ecs = fieldj.exists_if != null ? fieldj.exists_if : "";
    if (ecs === "" || ecs.toLowerCase() === "all") {
        ec = "all";
    } else if (/^round:[a-zA-Z][-_a-zA-Z0-9]*$/.test(ecs)
               && $("#rf_ec_" + fid + " > option[value=\"" + ecs + "\"]").length) {
        ec = ecs;
    } else {
        ec = "custom";
    }
    rf_fill_control("#rf_ec_" + fid, ec, order);
    rf_fill_control("#rf_ecs_" + fid, ecs, order);
    $("#rf_" + fid + " textarea").trigger("change");
    $("#rf_" + fid + "_view").html("").append(rf_render_view(fieldj));
    $("#rf_" + fid + "_delete").attr("aria-label", "Delete from form");
    if (order) {
        rf_fill_control("#rf_position_" + fid, fieldj.position || 0, order);
    }
    if (fieldj.search_keyword) {
        $("#rf_" + fid).attr("data-rf", fieldj.search_keyword);
    }
    fold_properties(fid);
    return false;
}

function remove() {
    var rf = this.closest(".settings-rf"),
        fid = rf.getAttribute("data-rfid"),
        form = rf.closest("form");
    if (hasClass(rf, "settings-rf-new")) {
        rf.parentElement.removeChild(rf);
    } else {
        addClass(rf, "deleted");
        var $rfedit = $("#rf_" + fid + "_edit");
        $rfedit.children().addClass("hidden", true);
        var name = form.elements["rf_name_" + fid];
        $(name).prop("disabled", true).addClass("text-decoration-line-through");
        removeClass(name.closest(".entryi"), "hidden");
        $rfedit.append('<div class="f-i"><em id="rf_'.concat(fid, '_removemsg">This field will be deleted from the review form.</em></div>'));
        if (rf.hasAttribute("data-rf")) {
            var search = {q: "has:" + rf.getAttribute("data-rf"), t: "all", forceShow: 1};
            $.get(hoturl("api/search", search), null, function (v) {
                var t;
                if (v && v.ok && v.ids && v.ids.length)
                    t = 'This field will be deleted from the review form and from reviews on <a href="'.concat(hoturl_html("search", search), '" target="_blank">', plural(v.ids.length, "submission"), "</a>.");
                else if (v && v.ok)
                    t = "This field will be deleted from the review form. No reviews have used the field.";
                else
                    t = "This field will be deleted from the review form and possibly from some reviews.";
                $("#rf_" + fid + "_removemsg").html(t);
            });
        }
        foldup.call(rf, event, {n: 2, f: false});
    }
    fill_order();
}

tooltip.add_builder("settings-rf", function (info) {
    var m = this.name.match(/^rf_(.*)_[a-z]\d+$/);
    return $.extend({
        anchor: "w", content: $("#settings-rf-caption-" + m[1]).html(), className: "gray"
    }, info);
});

tooltip.add_builder("settings-sf", function (info) {
    var x = "#settings-sf-caption-choices";
    if (/^optn/.test(this.name))
        x = "#settings-sf-caption-name";
    else if (/^optecs/.test(this.name))
        x = "#settings-sf-caption-condition";
    return $.extend({anchor: "h", content: $(x).html(), className: "gray"}, info);
});

function option_value_html(fieldj, value) {
    var t, n;
    if (!value || value < 0)
        return ["", "No entry"];
    t = '<strong class="rev_num sv';
    if (value <= fieldj.options.length) {
        if (fieldj.options.length > 1)
            n = Math.floor((value - 1) * 8 / (fieldj.options.length - 1) + 1.5);
        else
            n = 1;
        t += " " + (fieldj.option_class_prefix || "sv") + n;
    }
    return [t + '">' + unparse_option(fieldj, value) + '.</strong>',
            escape_html(fieldj.options[value - 1] || "Unknown")];
}

handle_ui.on("js-settings-field-unfold", function (event) {
    var f = event.target.closest(".has-fold");
    if ((hasClass(f, "fold2c") || !form_differs(f))
        && !hasClass(f, "deleted"))
        foldup.call(event.target, event, {n: 2});
});

function rf_visibility_text(visibility) {
    if ((visibility || "pc") === "pc")
        return "(hidden from authors)";
    else if (visibility === "admin")
        return "(administrators only)";
    else if (visibility === "secret")
        return "(secret)";
    else if (visibility === "audec")
        return "(hidden from authors until decision)";
    else
        return "";
}

function rf_render_view(fieldj) {
    var hc = new HtmlCollector;
    hc.push('<div>', '</div>');

    hc.push('<h3 class="rfehead">', '</h3>');
    hc.push('<label class="revfn'.concat(fieldj.required ? " field-required" : "", '">', escape_html(fieldj.name || "<unnamed>"), '</label>'));
    var t = rf_visibility_text(fieldj.visibility), i;
    if (t)
        hc.push('<div class="field-visibility">'.concat(t, '</div>'));
    hc.pop();

    if (fieldj.exists_if && /^round:[a-zA-Z][-_a-zA-Z0-9]*$/.test(fieldj.exists_if)) {
        hc.push('<p class="feedback is-warning-note">Present on ' + fieldj.exists_if.substring(6) + ' reviews</p>');
    } else if (fieldj.exists_if) {
        hc.push('<p class="feedback is-warning-note">Present on reviews matching “' + escape_html(fieldj.exists_if) + '”</p>');
    }

    if (fieldj.description)
        hc.push('<div class="field-d">'.concat(fieldj.description, '</div>'));

    hc.push('<div class="revev">', '</div>');
    if (fieldj.options) {
        for (i = 0; i !== fieldj.options.length; ++i) {
            var n = fieldj.option_letter ? fieldj.options.length - i : i + 1;
            hc.push('<label class="checki"><span class="checkc"><input type="radio" disabled></span>'.concat(option_value_html(fieldj, n).join(" "), '</label>'));
        }
        if (!fieldj.required) {
            hc.push('<label class="checki g"><span class="checkc"><input type="radio" disabled></span>No entry</label>');
        }
    } else
        hc.push('<textarea class="w-text" rows="' + Math.max(fieldj.display_space || 0, 3) + '" disabled>(Text field)</textarea>');

    return $(hc.render());
}

function move_field(event) {
    var isup = $(this).hasClass("moveup"),
        $f = $(this).closest(".settings-rf").detach(),
        fid = $f.attr("data-rfid"),
        pos = $f.find(".rf-position").val() | 0,
        $c = $("#settings-rform")[0], $n, i;
    for (i = 1, $n = $c.firstChild;
         $n && i < (isup ? pos - 1 : pos + 1);
         ++i, $n = $n.nextSibling)
        /* nada */;
    $c.insertBefore($f[0], $n);
    fill_order();
}

function rf_append(fid, pos) {
    var $f = $("#rf_" + fid), i, $j, $tmpl = $("#rf_template");

    if ($f.length) {
        $f.detach().show().appendTo("#settings-rform");
        fill_order();
        return;
    }

    $f = $($tmpl.html().replace(/\$/g, fid));

    if (fid.charAt(0) === "s") {
        $j = $f.find("select[name^=\"rf_colors_\"]");
        for (i = 0; i < colors.length; i += 2)
            $j.append("<option value=\"" + colors[i] + "\">" + colors[i+1] + "</option>");
    } else
        $f.find(".is-property-options").remove();

    var rnames = [];
    for (i in hotcrp_status.revs || {})
        rnames.push(i);
    if (rnames.length > 1) {
        var v, j, text;
        $j = $f.find("select[name$=\"rounds\"]");
        for (i = 0; i < (1 << rnames.length) - 1;
             i = next_lexicographic_permutation(i, rnames.length)) {
            text = [];
            for (j = 0; j < rnames.length; ++j)
                if (i & (1 << j))
                    text.push(rnames[j]);
            if (!text.length)
                $j.append("<option value=\"all\">All rounds</option>");
            else if (text.length == 1)
                $j.append("<option value=\"" + text[0] + "\">" + text[0] + " only</option>");
            else
                $j.append("<option value=\"" + text.join(" ") + "\">" + commajoin(text) + "</option>");
        }
    } else {
        $f.find(".reviewrow_rounds").remove();
    }

    $f.find(".js-settings-rf-delete").on("click", remove);
    $f.find(".js-settings-rf-move").on("click", move_field);
    $f.appendTo("#settings-rform");

    rf_fill($f, fid, original[fid], true);
    $f.find(".need-tooltip").each(tooltip);
}

function rf_add(fid) {
    fieldorder.push(fid);
    original[fid] = original[fid] || {};
    original[fid].position = fieldorder.length;
    rf_append(fid, fieldorder.length);
    var rf = document.getElementById("rf_" + fid);
    addClass(rf, "settings-rf-new");
    foldup.call(rf, null, {n: 2, f: false});
    document.getElementById("rf_position_" + fid).setAttribute("data-default-value", "0");
    form_highlight("#settingsform");
    return true;
}

function rfs(data) {
    var i, fid, $j, m, elt, entryi;
    original = {};
    samples = data.samples;
    stemplate = data.stemplate;
    ttemplate = data.ttemplate;

    fieldorder = [];
    for (i in data.fields) {
        fid = data.fields[i].id || i;
        original[fid] = data.fields[i];
        if (original[fid].position)
            fieldorder.push(fid);
    }
    fieldorder.sort(function (a, b) {
        return original[a].position - original[b].position;
    });

    // construct form
    for (i = 0; i !== fieldorder.length; ++i) {
        rf_append(fieldorder[i], i + 1);
    }
    $("#settings-rform").on("unfold", ".settings-rf", function (evt, opts) {
        $(this).find("textarea").css("height", "auto").autogrow();
        $(this).find("input[type=text]").autogrow();
    });

    // highlight errors, apply request
    for (i in data.req || {}) {
        m = i.match(/^rf_(?:[a-z]*_|)([st]\d+)$/);
        if (m) {
            $j = $("#" + i);
            if (!$j[0]) {
                rf_add(m[1]);
                $j = $("#" + i);
            }
            if ($j[0] && !text_eq($j.val(), data.req[i])) {
                $j.val(data.req[i]);
                foldup.call($j[0], null, {n: 2, f: false});
                fold_properties(m[1]);
            }
        }
    }
    for (i in data.errf || {}) {
        elt = document.getElementById(i);
        entryi = elt.closest(".entryi") || elt;
        removeClass(entryi, "hidden");
        addClass(entryi, "has-error");
        foldup.call(entryi, null, {n: 2, f: false});
    }
    for (i in data.message_list || []) {
        m = data.message_list[i];
        if (m.field
            && m.message
            && (elt = document.getElementById(m.field))
            && (entryi = elt.closest(".entry"))) {
            $(render_feedback(m.message, m.status)).prependTo(entryi);
        }
    }
    form_highlight("#settingsform");
};

function add_dialog(fid, focus) {
    var $d, template = 0, has_options = fid.charAt(0) === "s";
    function render_template() {
        var $dtn = $d.find(".newreviewfield-template-name"),
            $dt = $d.find(".newreviewfield-template");
        if (!template || !samples[template - 1] || !samples[template - 1].options != !has_options) {
            template = 0;
            $dtn.text("(Blank)");
            $dt.html("");
        } else {
            var s = samples[template - 1];
            $dtn.text(s.selector);
            $dt.html(rf_render_view(s));
        }
    }
    function submit(event) {
        rf_add(fid);
        var tmpl = template ? samples[template - 1] : {};
        if (!template && has_options)
            tmpl.required = true;
        rf_fill($("#rf_" + fid), fid, tmpl, false);
        $("#rf_name_" + fid)[0].focus();
        $d.close();
        event.preventDefault();
    }
    function click() {
        if (this.name == "next" || this.name == "prev") {
            var dir = this.name == "next" ? 1 : -1;
            template += dir;
            if (template < 0)
                template = samples.length;
            while (template
                   && samples[template - 1]
                   && !samples[template - 1].options !== !has_options)
                template += dir;
            render_template();
        }
    }
    function change_template() {
        ++template;
        while (samples[template - 1] && !samples[template - 1].options != !has_options)
            ++template;
        render_template();
    }
    function create() {
        var hc = popup_skeleton();
        hc.push('<h2>' + (has_options ? "Add score field" : "Add text field") + '</h2>');
        hc.push('<p>Choose a template for the new field.</p>');
        hc.push('<table style="width:500px;max-width:90%;margin-bottom:2em"><tbody><tr>', '</tr></tbody></table>');
        hc.push('<td style="text-align:left"><button name="prev" type="button" class="need-tooltip" data-tooltip="Previous template">&lt;</button></td>');
        hc.push('<td class="newreviewfield-template-name" style="text-align:center"></td>');
        hc.push('<td style="text-align:right"><button name="next" type="button" class="need-tooltip" data-tooltip="Next template">&gt;</button></td>');
        hc.pop();
        hc.push('<div class="newreviewfield-template" style="width:500px;max-width:90%;min-height:6em"></div>');
        hc.push_actions(['<button type="submit" name="add" class="btn-primary want-focus">Create field</button>',
            '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        render_template();
        $d.find(".newreviewfield-template-name").on("click", change_template);
        $d.on("click", "button", click);
        $d.find("form").on("submit", submit);
    }
    create();
}

handle_ui.on("js-settings-add-review-field", function () {
    var has_options = hasClass(this, "score"),
        tmpl = has_options ? stemplate : ttemplate;
    if (!tmpl || !/^[a-z]+00$/.test(tmpl.id))
        throw new Error("bad template");
    // no field found, so add one
    var ffmt = tmpl.id.substring(0, tmpl.id.length - 2) + "%02d", i, fid;
    for (i = 1; ; ++i) {
        fid = sprintf(ffmt, i);
        if ($.inArray(fid, fieldorder) < 0)
            break;
    }
    original[fid] = Object.assign({}, tmpl, {id: fid});
    return add_dialog(fid);
});

return rfs;
})();


handle_ui.on("js-settings-resp-round-new", function () {
    var i, j;
    for (i = 1; jQuery("#response_" + i).length; ++i)
        /* do nothing */;
    jQuery("#response_n").before("<div id=\"response_" + i + "\" class=\"form-g\"></div>");
    j = jQuery("#response_" + i);
    j.html(jQuery("#response_n").html().replace(/_n\"/g, "_" + i + "\""));
    j.find("textarea").css({height: "auto"}).autogrow().val(jQuery("#response_n textarea").val());
    j.find(".need-suggest").each(suggest);
    return false;
});


hotcrp.settings = {
    review_form: review_form_settings,
    review_round: review_round_settings
};
