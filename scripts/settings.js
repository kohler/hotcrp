// settings.js -- HotCRP JavaScript library for settings
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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


(function () {

handle_ui.on("js-settings-sf-type", function (event) {
    var v = this.value;
    $(this).closest(".settings-sf").find(".has-type-condition").each(function () {
        toggleClass(this, "hidden", this.getAttribute("data-type-condition").split(" ").indexOf(v) < 0);
    });
});

handle_ui.on("js-settings-sf-move", function (event) {
    var odiv = $(this).closest(".settings-sf")[0];
    if (hasClass(this, "moveup") && odiv.previousSibling)
        odiv.parentNode.insertBefore(odiv, odiv.previousSibling);
    else if (hasClass(this, "movedown") && odiv.nextSibling)
        odiv.parentNode.insertBefore(odiv, odiv.nextSibling.nextSibling);
    else if (hasClass(this, "delete")) {
        var $odiv = $(odiv), x;
        if ($odiv.find(".settings-sf-id").val() === "new") {
            $odiv.remove();
            form_highlight("#settingsform");
        } else {
            tooltip.erase.call(this);
            $odiv.find(".settings-sf-order").val("deleted").change();
            $odiv.find(".f-i, .entryi").each(function () {
                if (!$(this).find(".settings-sf-order").length)
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


function add_dialog() {
    var $d, sel;
    function cur_option() {
        return sel.options[sel.selectedIndex] || sel.options[0];
    }
    function render_template() {
        var opt = cur_option(),
            samp = $$("settings-sform-samples").childNodes[opt.value | 0];
        $d.find(".settings-sf-template-view").html($(samp).html());
    }
    function submit(event) {
        var opt = cur_option(),
            samp = $$("settings-sform-samples").childNodes[opt.value | 0],
            h = $$("settings-sf-new").getAttribute("data-template"),
            next = 1, odiv;
        while ($$("sf__" + next + "__name"))
            ++next;
        h = h.replace(/__0/g, "__" + next);
        odiv = $(h).appendTo("#settings-sform");
        odiv.find(".need-autogrow").autogrow();
        odiv.find(".need-tooltip").each(tooltip);
        odiv.find(".js-settings-sf-type").val(samp.getAttribute("data-name")).change();
        $$("sf__" + next + "__name").focus();
        settings_sf_positions();
        $d.close();
        event.preventDefault();
    }
    function create() {
        var hc = popup_skeleton(), i;
        hc.push('<h2>Add field</h2>');
        hc.push('<p>Choose a template for the new field.</p>');
        hc.push('<select name="sf_template" class="w-99 want-focus" size="5">', '</select>');
        $("#settings-sform-samples").children().each(function (i) {
            hc.push('<option value="'.concat(i, i ? '">' : '" selected>', escape_html(this.getAttribute("data-title")), '</option>'));
        });
        hc.pop();
        hc.push('<div class="settings-sf-template-view mt-4" style="width:500px;max-width:90%;min-height:10em"></div>');
        hc.push_actions(['<button type="submit" name="add" class="btn-primary">Add field</button>',
            '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        sel = $d.find("select")[0];
        render_template();
        $(sel).on("input", render_template);
        $d.find("form").on("submit", submit);
    }
    create();
}

handle_ui.on("js-settings-sf-add", add_dialog);

function settings_sf_positions() {
    $(".settings-sf .moveup, .settings-sf .movedown").prop("disabled", false);
    $(".settings-sf:first-child .moveup").prop("disabled", true);
    $(".settings-sf:last-child .movedown").prop("disabled", true);
    var index = 0;
    $(".settings-sf-order").each(function () {
        if (this.value !== "deleted" && this.name !== "sf__0__order") {
            ++index;
            if (this.value != index)
                $(this).val(index).change();
        }
    });
}

$(function () {
    if ($(".settings-sf").length) {
        $(".settings-sf-view").find("input, select, textarea, button").each(function () {
            this.removeAttribute("name"); // do not submit with form
            if (this.type === "checkbox" || this.type === "radio" || this.type === "button")
                this.disabled = true;
            else if (this.type !== "select")
                this.readonly = true;
            removeClass(this, "ui");
        });
        $("#settings-sform").on("unfold", ".settings-sf", function (evt, opts) {
            $(this).find("textarea").css("height", "auto").autogrow();
            $(this).find("input[type=text]").autogrow();
        });
        settings_sf_positions();
    }
});

})();


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
    var form = this.form, ctr = 1;
    while (form.elements["decision__" + ctr + "__id"])
        ++ctr;
    $("#settings-decision-type-notes").removeClass("hidden");
    var h = $("#settings-new-decision-type").html().replace(/__\$/g, "__" + ctr),
        $r = $(h).appendTo("#settings-decision-types");
    $r.find("input[type=text]").autogrow();
    form.elements["decision__" + ctr + "__delete"].value = "";
    form.elements["decision__" + ctr + "__name"].focus();
    form_highlight(form);
});

handle_ui.on("js-settings-remove-decision-type", function (event) {
    var row = this.closest(".settings-decision"),
        ne = this.form.elements[row.id + "__name"],
        sc = ne.getAttribute("data-submission-count")|0;
    this.form.elements[row.id + "__delete"].value = "1";
    if (hasClass(row, "settings-decision-new")) {
        addClass(row, "hidden");
        $(row).find("input").addClass("ignore-diff");
        if (!$("#settings-decision-types .settings-decision-new:not(.hidden)").length)
            $("#settings-decision-type-notes").addClass("hidden");
    } else {
        $(ne).prop("disabled", true).addClass("text-decoration-line-through");
        this.disabled = true;
        var t = '<div class="f-i"><em>This decision will be removed';
        if (sc)
            t = t.concat(' and <a href="', hoturl_html("search", {q: "dec:\"" + ne.defaultValue + "\""}), '" target="_blank">', plural(sc, 'submission'), '</a> set to undecided');
        $(row).after(t + '.</em></div>');
    }
    form_highlight(this.form);
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
        .text(name === "" ? "unnamed" : name).val(name);
}

function add() {
    var i, h, j;
    for (i = 1; $("#roundname_" + i).length; ++i)
        /* do nothing */;
    $("#round_container").show();
    $("#roundtable").append($("#newround").html().replace(/\$/g, i));
    var $mydiv = $("#roundname_" + i).closest(".js-settings-review-round");
    $("#rev_roundtag").append('<option value="" id="rev_roundtag_' + i + '">(new round)</option>');
    $("#extrev_roundtag").append('<option value="" id="extrev_roundtag_' + i + '">(new round)</option>');
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
var fieldorder = [], original, samples, stemplate, ttemplate,
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
    var i = 0, n, pos,
        form = document.getElementById("settingsform"),
        c = document.getElementById("settings-rform");
    for (n = c.firstChild; n; n = n.nextSibling) {
        pos = hasClass(n, "deleted") ? 0 : ++i;
        form.elements[n.id + "__order"].value = pos;
    }
    form_highlight("#settingsform");
}

function rf_fill_control(form, name, value, setdefault) {
    var elt = form.elements[name];
    elt && $(elt).val(value);
    elt && setdefault && elt.setAttribute("data-default-value", value);
}

function rf_fill(pos, fieldj, setdefault) {
    var form = document.getElementById("settingsform"),
        rfid = "rf__" + pos + "__",
        fid = form.elements[rfid + "id"].value;
    fieldj = fieldj || original[fid] || {};
    rf_fill_control(form, rfid + "name", fieldj.name || "", setdefault);
    rf_fill_control(form, rfid + "description", fieldj.description || "", setdefault);
    rf_fill_control(form, rfid + "visibility", fieldj.visibility || "pc", setdefault);
    rf_fill_control(form, rfid + "choices", options_to_text(fieldj), setdefault);
    rf_fill_control(form, rfid + "required", fieldj.required ? "1" : "0", setdefault);
    rf_fill_control(form, rfid + "colorsflipped", fieldj.option_letter ? "1" : "", setdefault);
    rf_fill_control(form, rfid + "colors", option_class_prefix(fieldj), setdefault);
    var ec, ecs = fieldj.exists_if != null ? fieldj.exists_if : "";
    if (ecs === "" || ecs.toLowerCase() === "all") {
        ec = "all";
    } else {
        ec = "custom";
        if (/^round:[a-zA-Z][-_a-zA-Z0-9]*$/.test(ecs)) {
            var ecelt = form.elements[rfid + "presence"];
            if (ecelt.querySelector("option[value=\"" + ecs + "\"]"))
                ec = ecs;
        }
    }
    rf_fill_control(form, rfid + "presence", ec, setdefault);
    rf_fill_control(form, rfid + "condition", ecs, setdefault);
    rf_fill_control(form, rfid + "id", fid, true);
    $("#" + rfid + " textarea").trigger("change");
    $("#" + rfid + "view").html("").append(rf_render_view(fieldj));
    $("#" + rfid + "delete").attr("aria-label", "Delete from form");
    if (setdefault) {
        rf_fill_control(form, rfid + "order", fieldj.order || 0, setdefault);
    }
    if (fieldj.search_keyword) {
        $("#rf__" + pos).attr("data-rf", fieldj.search_keyword);
    }
    return false;
}

function rf_delete() {
    var rf = this.closest(".settings-rf"), form = this.form;
    addClass(rf, "deleted");
    if (hasClass(rf, "settings-rf-new")) {
        addClass(rf, "hidden");
        $(rf).find("input, select, textarea").addClass("ignore-diff");
    } else {
        var $rfedit = $("#" + rf.id + "__edit");
        $rfedit.children().addClass("hidden");
        var name = form.elements[rf.id + "__name"];
        $(name).prop("disabled", true).addClass("text-decoration-line-through");
        removeClass(name.closest(".entryi"), "hidden");
        $rfedit.append('<div class="f-i"><em id="'.concat(rf.id, '__removemsg">This field will be deleted from the review form.</em></div>'));
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
                $("#" + rf.id + "__removemsg").html(t);
            });
        }
        foldup.call(rf, event, {n: 2, f: false});
    }
    fill_order();
}

tooltip.add_builder("settings-rf", function (info) {
    var m = this.name.match(/^rf__\d+__(.*)$/);
    return $.extend({
        anchor: "w", content: $("#settings-rf-caption-" + m[1]).html(), className: "gray"
    }, info);
});

tooltip.add_builder("settings-sf", function (info) {
    var x = "#settings-sf-caption-choices";
    if (/__name$/.test(this.name))
        x = "#settings-sf-caption-name";
    else if (/__condition$/.test(this.name))
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

function rf_move(event) {
    var isup = $(this).hasClass("moveup"),
        $f = $(this).closest(".settings-rf").detach(),
        pos = $f.find(".rf-order").val() | 0,
        $c = $("#settings-rform")[0], $n, i;
    for (i = 1, $n = $c.firstChild;
         $n && i < (isup ? pos - 1 : pos + 1);
         ++i, $n = $n.nextSibling) {
    }
    $c.insertBefore($f[0], $n);
    fill_order();
}

function rf_append(fid) {
    var pos = fieldorder.length + 1, $f, i, $j, $tmpl = $("#rf__template");
    if (document.getElementById("rf__" + pos + "__id")
        || !/^[st]\d\d$/.test(fid)
        || fieldorder.indexOf(fid) >= 0) {
        throw new Error("rf_append error on " + fid + " " + (document.getElementById("rf__" + pos + "__id") ? "1 " : "0 ") + fieldorder.join(","));
    }
    fieldorder.push(fid);
    var has_options = fid.charAt(0) === "s";
    original[fid] = original[fid] || Object.assign({}, has_options ? stemplate : ttemplate, {id: fid});
    $f = $($tmpl.html().replace(/\$/g, pos));
    if (has_options) {
        $j = $f.find("select.rf-colors");
        for (i = 0; i < colors.length; i += 2)
            $j.append("<option value=\"" + colors[i] + "\">" + colors[i+1] + "</option>");
    } else
        $f.find(".is-property-options").remove();
    $f.find(".js-settings-rf-delete").on("click", rf_delete);
    $f.find(".js-settings-rf-move").on("click", rf_move);
    $f.find(".rf-id").val(fid);
    $f.appendTo("#settings-rform");
    rf_fill(pos, original[fid], true);
    $f.find(".need-tooltip").each(tooltip);
}

function rf_add(fid) {
    var pos = fieldorder.length + 1;
    rf_append(fid);
    var rf = document.getElementById("rf__" + pos);
    addClass(rf, "settings-rf-new");
    foldup.call(rf, null, {n: 2, f: false});
    var ordere = document.getElementById("rf__" + pos + "__order");
    ordere.setAttribute("data-default-value", "0");
    ordere.value = pos;
    form_highlight("#settingsform");
    return true;
}

function rfs(data) {
    var i, fid, forder, mi, e, entryi;
    original = {};
    samples = data.samples;
    stemplate = data.stemplate;
    ttemplate = data.ttemplate;

    // construct form for original fields
    forder = [];
    for (i in data.fields) {
        fid = data.fields[i].id || i;
        original[fid] = data.fields[i];
        if (original[fid].order)
            forder.push(fid);
    }
    forder.sort(function (a, b) {
        return original[a].order - original[b].order;
    });
    while (fieldorder.length < forder.length) {
        rf_append(forder[fieldorder.length]);
    }

    // amend form for new fields
    while ((data.req || {})["rf__" + (fieldorder.length + 1) + "__id"]) {
        rf_add(data.req["rf__" + (fieldorder.length + 1) + "__id"]);
    }

    // highlight errors, apply request
    for (i in data.req || {}) {
        if (/^rf__\d+__/.test(i)
            && (e = document.getElementById(i))
            && !text_eq($(e).val(), data.req[i])) {
            $(e).val(data.req[i]);
            foldup.call(e, null, {n: 2, f: false});
        }
    }
    for (i in data.message_list || []) {
        mi = data.message_list[i];
        if (mi.field
            && (e = document.getElementById(mi.field))
            && (entryi = e.closest(".entryi"))) {
            append_feedback_near(entryi, mi);
            foldup.call(entryi, null, {n: 2, f: false});
        }
    }

    $("#settings-rform").on("unfold", ".settings-rf", function (evt, opts) {
        $(this).find("textarea").css("height", "auto").autogrow();
        $(this).find("input[type=text]").autogrow();
    });
    form_highlight("#settingsform");
};

function add_dialog() {
    var $d, sel;
    function cur_sample() {
        return samples[sel.options[sel.selectedIndex].value | 0] || samples[0];
    }
    function render_template() {
        $d.find(".settings-rf-template-view").html(rf_render_view(cur_sample()));
    }
    function submit(event) {
        var sample = cur_sample(),
            has_options = !!sample.options,
            ffmt = has_options ? "s%02d" : "t%02d",
            i, fid;
        for (i = 1; ; ++i) {
            fid = sprintf(ffmt, i);
            if ($.inArray(fid, fieldorder) < 0)
                break;
        }
        original[fid] = Object.assign({}, has_options ? stemplate : ttemplate, {id: fid});
        rf_add(fid);
        if (sample.is_example)
            sample = Object.assign({}, sample, {name: ""});
        rf_fill(fieldorder.length, sample, false);
        document.getElementById("rf__" + fieldorder.length + "__name").focus();
        $d.close();
        event.preventDefault();
    }
    function create() {
        var hc = popup_skeleton(), i;
        hc.push('<h2>Add field</h2>');
        hc.push('<p>Choose a template for the new field.</p>');
        hc.push('<select name="rf_template" class="w-99 want-focus" size="5">', '</select>');
        for (i = 0; i !== samples.length; ++i)
            hc.push('<option value="'.concat(i, i ? '">' : '" selected>', escape_html(samples[i].selector), '</option>'));
        hc.pop();
        hc.push('<div class="settings-rf-template-view" style="width:500px;max-width:90%;min-height:10em"></div>');
        hc.push_actions(['<button type="submit" name="add" class="btn-primary">Add field</button>',
            '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        sel = $d.find("select")[0];
        render_template();
        $(sel).on("input", render_template);
        $d.find("form").on("submit", submit);
    }
    create();
}

handle_ui.on("js-settings-rf-add", add_dialog);

return rfs;
})();


handle_ui.on("js-settings-resp-round-new", function () {
    var i, $rx, $rt = $("#response_new"), t;
    for (i = 1; jQuery("#response_" + i).length; ++i) {
    }
    $rt.before($rt.html().replace(/\$/g, i));
    $rx = $("#response_" + i);
    $rx.find("textarea").css({height: "auto"}).autogrow();
    $rx.find(".need-suggest").each(suggest);
    $rx.find(".need-tooltip").each(tooltip);
    return false;
});

handle_ui.on("js-settings-resp-round-delete", function () {
    var rr = this.closest(".settings-response");
    if (hasClass(rr, "settings-rf-new")) {
        rr.parentElement.removeChild(rf);
    } else {
        var fid = rr.getAttribute("data-resp-round");
        addClass(rr, "deleted");
        this.form.elements["response/" + fid + "/delete"].click();
        $(rr).children().addClass("hidden");
        var name = this.form.elements["response/" + fid + "/name"];
        $(name).prop("disabled", true).addClass("text-decoration-line-through");
        removeClass(name.closest(".entryi"), "hidden");
        $(name).closest(".entry").append('<div class="mt-2"><em>This response round will be deleted.</em></div>');
    }
    return false;
});


hotcrp.settings = {
    review_form: review_form_settings,
    review_round: review_round_settings
};
