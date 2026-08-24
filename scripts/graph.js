// graph.js -- HotCRP JavaScript library for graph drawing
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

/* global hotcrp, siteinfo */
hotcrp.graph = (function (d3) {
const $$ = hotcrp.$$,
    $e = hotcrp.$e,
    $frag = hotcrp.$frag,
    $svg = hotcrp.$svg,
    addClass = hotcrp.classes.add,
    ensure_pattern = hotcrp.ensure_pattern,
    feedback = hotcrp.feedback,
    handle_ui = hotcrp.handle_ui,
    hasClass = hotcrp.classes.has,
    hoturl = hotcrp.hoturl,
    log_jserror = hotcrp.log_jserror,
    make_bubble = hotcrp.make_bubble,
    removeClass = hotcrp.classes.remove,
    strftime = hotcrp.text.strftime,
    tooltip = hotcrp.tooltip;

// Layout constants

// Shape of the graph box when neither the container's CSS nor `args` names
// one. 16:9 is what the flat 540px default amounted to at the graph page's
// 960px width, so this keeps that box and starts scaling narrower ones.
const DEFAULT_ASPECT_RATIO = 16 / 9;
// Floor below which the derived plot rectangle stops meaning anything
const MIN_BOX_WIDTH = 120, MIN_BOX_HEIGHT = 80;

// Insets are distances from graph box edges to the marks that show there:
// top of Y-axis label, right end of X-axis line or of a tick label
// overhanging it, bottom of deepest X-tick label or X-axis label, left edge
// of Y-tick labels. Unless overridden by plotLeft/Right/Top/Bottom/Width/
// Height, the plot rectangle is derived from insets. These are the defaults.
const INSET_TOP = 6, INSET_RIGHT = 8, INSET_BOTTOM = 6, INSET_LEFT = 8;
// Gap between an axis label and the tick labels it sits next to
const LABEL_GAP = 4;
// How far outside the plot rectangle marks may still draw; see `make_axis_pair`
const PLOT_CLIP_MARGIN = 6;
// One press of a zoom button changes the window by this factor
const ZOOM_STEP = 1.5;
// A graph zooms in until it shows this fraction of its full extent
const ZOOM_MIN_FRACTION = 0.001;
// Pointer travel, in pixels, that turns a click into a pan
const PAN_THRESHOLD = 3;
// How the graph follows its container. A render at or under the frame budget
// is cheap enough to repeat every frame while the container is being dragged;
// a slower one waits for the box to settle instead. Box changes under the
// threshold are not worth a redraw.
const RESIZE_FRAME_BUDGET = 12, RESIZE_DEBOUNCE = 150, RESIZE_THRESHOLD = 2;
// Hard ceiling on tick label width, in pixels, applied whether or not the
// dimension it extends into can grow — a growable box otherwise lets one
// pathological label set the size for everything.
const MAX_LABEL_WIDTH = 180;
// Fraction of the graph box that an axis's tick labels may occupy
const MAX_X_LABEL_HEIGHT_FRACTION = 0.28;
const MAX_Y_LABEL_WIDTH_FRACTION = 0.25;
// Where tick text sits relative to the axis line. d3's axisLeft puts Y tick
// text at -(tickSize + tickPadding) = -9; the X axis matches. A tilted X tick
// label hangs from (-X_TICK_TILT_DX, X_TICK_TILT_DY) instead.
const Y_TICK_TEXT_OFFSET = 9, X_TICK_TEXT_OFFSET = 9;
const X_TICK_TILT_DX = 9, X_TICK_TILT_DY = 2;
// Length of a tick mark, on either axis
const TICK_SIZE = 6;
// X tick labels are tilted by this much when they don't fit horizontally.
const TILT_DEGREES = 65;
const TILT_RADIANS = TILT_DEGREES * Math.PI / 180;
const SINE_TILT_RADIANS = Math.sin(TILT_RADIANS);
const SINE_TILT_RADIANS_INVERSE = 1 / SINE_TILT_RADIANS;
const COSINE_TILT_RADIANS = Math.cos(TILT_RADIANS);
// A box needs at least this many points to draw interpolated quartiles and
// 1.5*IQR whiskers; formerly 4
const BOXPLOT_IQR_MIN_N = 1;

/** Depth below the axis line of the lowest ink in a tilted tick label `width`
 * px wide whose font drops `descent` px below the baseline. The label hangs
 * from (-X_TICK_TILT_DX, X_TICK_TILT_DY) and runs up and to the left, so its
 * far end is its lowest point.
 * @param {number} width
 * @param {number} descent
 * @return {number} */
function tilted_label_depth(width, descent) {
    return (X_TICK_TILT_DX + width) * SINE_TILT_RADIANS
        + (X_TICK_TILT_DY + descent) * COSINE_TILT_RADIANS;
}

/** Inverse of `tilted_label_depth`: the text width that fits in `depth`.
 * @param {number} depth
 * @param {number} descent
 * @return {number} */
function tilted_label_width(depth, descent) {
    return (depth - (X_TICK_TILT_DY + descent) * COSINE_TILT_RADIANS)
        * SINE_TILT_RADIANS_INVERSE - X_TICK_TILT_DX;
}


/** GET `url` and hand the parsed JSON to `callback`. A failure is dropped, as
 * it was when this went through jQuery: every caller decorates a graph that
 * is already drawn, so there is nothing to fall back to and nothing to undo.
 * @param {string} url
 * @param {function(Object)} callback */
function api_get(url, callback) {
    fetch(url, {credentials: "same-origin"})
        .then(res => res.json())
        .then(callback)
        .catch(() => undefined);
}


// Path normalization (for pathNodeMayBeNearer/closestPoint)

const PATHSEG_ARGMAP = {
    m: 2, M: 2, z: 0, Z: 0, l: 2, L: 2, h: 1, H: 1, v: 1, V: 1, c: 6, C: 6,
    s: 4, S: 4, q: 4, Q: 4, t: 2, T: 2, a: 7, A: 7, b: 1, B: 1
};
const normalized_path_cache = new Map();

function svg_path_number_of_items(s) {
    if (s instanceof SVGPathElement) {
        s = s.getAttribute("d");
    }
    if (normalized_path_cache.has(s)) {
        return normalized_path_cache.get(s).length;
    } else {
        return s.replace(/[^A-DF-Za-df-z]+/g, "").length;
    }
}

function make_svg_path_parser(s) {
    if (s instanceof SVGPathElement) {
        s = s.getAttribute("d");
    }
    s = s.split(/([a-zA-Z]|[-+]?(?:\d+\.?\d*|\.\d+)(?:[Ee][-+]?\d+)?)/);
    let i = 1, e = s.length, next_cmd;
    return function () {
        let a = null;
        while (i < e) {
            const ch = s[i];
            if (ch >= "A") {
                if (a)
                    break;
                a = [ch];
                next_cmd = ch;
                if (ch === "m" || ch === "M" || ch === "z" || ch === "Z")
                    next_cmd = (ch === "m" || ch === "z" ? "l" : "L");
            } else {
                if (!a && next_cmd)
                    a = [next_cmd];
                else if (!a || a.length === PATHSEG_ARGMAP[a[0]] + 1)
                    break;
                a.push(+ch);
            }
            i += 2;
        }
        return a;
    };
}

let normalize_path_complaint = false;
function normalize_svg_path(s) {
    if (s instanceof SVGPathElement) {
        s = s.getAttribute("d");
    }
    if (normalized_path_cache.has(s)) {
        return normalized_path_cache.get(s);
    }

    let res = [],
        cx = 0, cy = 0, cx0 = 0, cy0 = 0, copen = false,
        cb = 0, sincb = 0, coscb = 1,
        i, dx, dy,
        parser = make_svg_path_parser(s), a, ch, preva;
    while ((a = parser())) {
        ch = a[0];
        // special commands: bearing, closepath
        if (ch === "b" || ch === "B") {
            cb = ch === "b" ? cb + a[1] : a[1];
            coscb = Math.cos(cb);
            sincb = Math.sin(cb);
            continue;
        } else if (ch === "z" || ch === "Z") {
            preva = res.length ? res[res.length - 1] : null;
            if (copen) {
                if (cx != cx0 || cy != cy0)
                    res.push(["L", cx, cy, cx0, cy0]);
                res.push(["Z"]);
                copen = false;
            }
            cx = cx0, cy = cy0;
            continue;
        }

        // normalize command 1: remove horiz/vert
        if (PATHSEG_ARGMAP[ch] == 1) {
            if (a.length == 1)
                a = ["L"]; // all data is missing
            else if (ch === "h")
                a = ["l", a[1], 0];
            else if (ch === "H")
                a = ["L", a[1], cy];
            else if (ch === "v")
                a = ["l", 0, a[1]];
            else if (ch === "V")
                a = ["L", cx, a[1]];
        }

        // normalize command 2: relative -> absolute
        ch = a[0];
        if (ch >= "a" && !cb) {
            for (i = ch !== "a" ? 1 : 6; i < a.length; i += 2) {
                a[i] += cx;
                a[i+1] += cy;
            }
        } else if (ch >= "a") {
            if (ch === "a")
                a[3] += cb;
            for (i = ch !== "a" ? 1 : 6; i < a.length; i += 2) {
                dx = a[i], dy = a[i + 1];
                a[i] = cx + dx * coscb + dy * sincb;
                a[i+1] = cy + dx * sincb + dy * coscb;
            }
        }
        ch = a[0] = ch.toUpperCase();

        // normalize command 3: use cx0,cy0 for missing data
        while (a.length < PATHSEG_ARGMAP[ch] + 1)
            a.push(cx0, cy0);

        // normalize command 4: shortcut -> full
        if (ch === "S") {
            dx = dy = 0;
            if (preva && preva[0] === "C")
                dx = cx - preva[3], dy = cy - preva[4];
            a = ["C", cx + dx, cy + dy, a[1], a[2], a[3], a[4]];
            ch = "C";
        } else if (ch === "T") {
            dx = dy = 0;
            if (preva && preva[0] === "Q")
                dx = cx - preva[1], dy = cy - preva[2];
            a = ["Q", cx + dx, cy + dy, a[1], a[2]];
            ch = "Q";
        }

        // process command
        if (!copen && ch !== "M") {
            res.push(["M", cx, cy]);
            copen = true;
        }
        if (ch === "M") {
            cx0 = a[1];
            cy0 = a[2];
            copen = false;
        } else if (ch === "L") {
            res.push(["L", cx, cy, a[1], a[2]]);
        } else if (ch === "C") {
            res.push(["C", cx, cy, a[1], a[2], a[3], a[4], a[5], a[6]]);
        } else if (ch === "Q") {
            res.push(["C", cx, cy,
                      cx + 2 * (a[1] - cx) / 3, cy + 2 * (a[2] - cy) / 3,
                      a[3] + 2 * (a[1] - a[3]) / 3, a[4] + 2 * (a[2] - a[4]) / 3,
                      a[3], a[4]]);
        } else {
            // XXX should render "A" as a bezier
            if (++normalize_path_complaint == 1)
                log_jserror("bad normalize_svg_path " + ch);
            res.push(a);
        }

        preva = a;
        cx = a[a.length - 2];
        cy = a[a.length - 1];
    }

    if (normalized_path_cache.size >= 1000) {
        normalized_path_cache.clear();
    }
    normalized_path_cache.set(s, res);
    return res;
}

function pathNodeMayBeNearer(pathNode, point, dist) {
    function oob(l, t, r, b) {
        return l - point[0] >= dist || point[0] - r >= dist
            || t - point[1] >= dist || point[1] - b >= dist;
    }
    // check bounding rectangle of path
    if ("clientX" in point) {
        const bounds = pathNode.getBoundingClientRect(),
            dx = point[0] - point.clientX, dy = point[1] - point.clientY;
        if (bounds && oob(bounds.left + dx, bounds.top + dy,
                          bounds.right + dx, bounds.bottom + dy))
            return false;
    }
    // check path
    const npsl = normalize_svg_path(pathNode);
    let l, t, r, b;
    for (const item of npsl) {
        if (item[0] === "L") {
            l = Math.min(item[1], item[3]);
            t = Math.min(item[2], item[4]);
            r = Math.max(item[1], item[3]);
            b = Math.max(item[2], item[4]);
        } else if (item[0] === "C") {
            l = Math.min(item[1], item[3], item[5], item[7]);
            t = Math.min(item[2], item[4], item[6], item[8]);
            r = Math.max(item[1], item[3], item[5], item[7]);
            b = Math.max(item[2], item[4], item[6], item[8]);
        } else if (item[0] === "Z" || item[0] === "M") {
            continue;
        } else {
            return true;
        }
        if (!oob(l, t, r, b)) {
            return true;
        }
    }
    return false;
}

function closestPoint(pathNode, point, inbest) {
    // originally from Mike Bostock http://bl.ocks.org/mbostock/8027637
    if (inbest && !pathNodeMayBeNearer(pathNode, point, inbest.distance))
        return inbest;

    let pathLength = pathNode.getTotalLength(),
        precision = Math.max(pathLength / svg_path_number_of_items(pathNode) * .125, 3),
        best, bestLength, bestDistance2 = Infinity;

    function check(pLength) {
        const p = pathNode.getPointAtLength(pLength),
            dx = point[0] - p.x, dy = point[1] - p.y,
            d2 = dx * dx + dy * dy;
        if (d2 >= bestDistance2) {
            return false;
        }
        best = [p.x, p.y];
        best.pathNode = pathNode;
        bestLength = pLength;
        bestDistance2 = d2;
        return true;
    }

    // linear scan for coarse approximation
    for (let sl = 0; sl <= pathLength; sl += precision) {
        check(sl);
    }

    // binary search for precise estimate
    precision *= .5;
    while (precision > .5) {
        const bl0 = bestLength - precision;
        if (bl0 < 0 || !check(bl0)) {
            const bl1 = bestLength + precision;
            if (bl1 > pathLength || !check(bl1)) {
                precision *= 0.5;
            }
        }
    }

    best.distance = Math.sqrt(bestDistance2);
    best.pathLength = bestLength;
    return inbest && inbest.distance < best.distance + 0.01 ? inbest : best;
}

function tangentAngle(pathNode, length) {
    const length0 = Math.max(0, length - 0.25);
    if (length0 == length) {
        length += 0.25;
    }
    const p0 = pathNode.getPointAtLength(length0),
        p1 = pathNode.getPointAtLength(length);
    return Math.atan2(p1.y - p0.y, p1.x - p0.x);
}


/* CDF functions */
function seq_to_cdf(seq, flip, raw) {
    const cdf = [], n = seq.ntotal || seq.length;
    seq.sort(flip ? d3.descending : d3.ascending);
    for (let i = 0; i <= seq.length; ++i) {
        const y = raw ? i : i/n;
        if (i != 0 && (i == seq.length || seq[i-1] != seq[i]))
            cdf.push([seq[i-1], y]);
        if (i != seq.length && (i == 0 || seq[i-1] != seq[i]))
            cdf.push([seq[i], y]);
    }
    cdf.cdf = true;
    return cdf;
}


function expand_extent(e, args) {
    let l = e[0], h = e[1];
    if (l > 0 && l < h / 11) {
        l = 0;
    } else if (l > 0 && args.discrete) {
        l -= 0.5;
    }
    if (h - l < 10) {
        const delta = Math.min(1, h - l) * 0.2;
        if (args.orientation !== "y" || l > 0) {
            l -= delta;
        }
        h += delta;
    }
    if (args.discrete) {
        h += 0.5;
    }
    return [l, h];
}


/** Emit one laid-out axis into `g`. `layout_axis` has already chosen the
 * ticks, their positions, their possibly-shortened text, and whether they
 * tilt; this only draws them.
 * @param {object} view
 * @param {*} g
 * @param {string} side */
function draw_axis(view, g, side) {
    const axis = view[side], lay = axis.layout, xside = side === "x";
    if (lay.widelabel) {
        g.attr("class", g.attr("class") + " widelabel");
    }
    // the X axis draws a baseline; the Y axis never has
    if (xside) {
        g.append("path").attr("d", "M0,0H" + view.plotWidth);
    }
    for (const t of lay.ticks) {
        const tg = g.append("g").attr("class", "tick")
            .attr("transform", xside ? "translate(" + t.pos + ",0)"
                : "translate(0," + t.pos + ")");
        tg.append("line").attr(xside ? "y2" : "x2", xside ? TICK_SIZE : -TICK_SIZE);
        const txt = tg.append("text").text(t.text);
        if (!xside) {
            txt.attr("text-anchor", "end")
                .attr("x", -Y_TICK_TEXT_OFFSET).attr("dy", "0.32em");
        } else if (lay.tilt) {
            txt.attr("text-anchor", "end")
                .attr("dx", -X_TICK_TILT_DX).attr("dy", X_TICK_TILT_DY);
        } else {
            // baseline one ascent down, so the ink starts exactly
            // `X_TICK_TEXT_OFFSET` below the axis line
            txt.attr("text-anchor", "middle")
                .attr("y", X_TICK_TEXT_OFFSET + lay.ascent);
        }
        if (t.classes) {
            txt.attr("class", t.classes);
        }
        if (t.fill) {
            txt.style("fill", t.fill);
        }
        if (t.bg) {
            const b = txt.node().getBBox();
            tg.insert("rect", "text")
                .attr("x", b.x - 3).attr("y", b.y)
                .attr("width", b.width + 6).attr("height", b.height + 1)
                .attr("class", "glab " + t.bg)
                .style("fill", ensure_pattern(t.bg, "glab"));
        }
        if (t.text !== t.full) {
            txt.append("title").text(t.full);
        }
        if (lay.tilt) {
            tg.selectAll("text, rect").attr("transform", `rotate(${-TILT_DEGREES})`);
        }
        if (t.opacity != null) {
            tg.selectAll("text, rect").attr("opacity", t.opacity);
        }
    }
}

/** Space claimed above the plot's top edge: the top half of the topmost Y
 * tick label, which is centered on that edge, plus the Y axis label and its
 * gap where there is one. `make_axis_pair` sets a derived top edge one inset
 * below the box top plus this much; `draw_axes` hangs the label from the edge
 * by the same amount, so the two agree however the edge was arrived at.
 * @param {object} args
 * @return {number} */
function y_head_room(view) {
    const h = view.labelMetrics.height;
    return Math.ceil(h / 2) + (view.args.y.label ? Math.ceil(h) + LABEL_GAP : 0);
}

/** @param {*} svg
 * @param {object} view */
function draw_axes(svg, view) {
    const parent = d3.select(svg.node().ownerSVGElement);

    const xaxe = parent.append("g")
        .attr("class", "hg-axis hg-axis-x")
        .attr("transform", `translate(${view.left},${view.bottom})`);
    draw_axis(view, xaxe, "x");
    if (view.args.x.label) {
        xaxe.append("text")
            .attr("class", "label")
            .attr("x", view.plotWidth)
            .attr("y", view.fullHeight - view.bottom - view.args.insetBottom
                - view.labelMetrics.descent)
            .attr("text-anchor", "end")
            .attr("pointer-events", "none")
            .text(view.args.x.label)
            .append("tspan")
            .attr("class", "arrow")
            .text(" \u2192");
    }

    const yaxe = parent.append("g")
        .attr("class", "hg-axis hg-axis-y")
        .attr("transform", `translate(${view.left},${view.top})`);
    draw_axis(view, yaxe, "y");
    if (view.args.y.label) {
        const uparrow = d3.create("svg:tspan")
            .attr("class", "arrow")
            .text("\u2191 ");
        // Placed against the plot, not the box: left-aligned with the leftmost
        // tick ink but never past the box's left inset, and hung from the
        // plot's top edge by `y_head_room`. Where those edges are derived the
        // label lands exactly on the top and left inset lines; where they are
        // pinned it follows the plot.
        yaxe.append("text")
            .attr("class", "label")
            .attr("x", Math.max(-view.y.layout.ink, view.args.insetLeft - view.left))
            .attr("y", view.labelMetrics.ascent - y_head_room(view))
            .attr("text-anchor", "start")
            .attr("pointer-events", "none")
            .text(view.args.y.label)
            .each(function () {
                this.insertBefore(uparrow.node(), this.firstChild);
            });
    }

    place_x_axis_label(xaxe, view);
}

function draw_annotations(svg, view) {
    for (const anno of view.args.annotations || []) {
        if (anno.type === "xline") {
            const x = view.x.scale(anno.x);
            svg.append("line")
                .attr("class", "gxline")
                .attr("x1", x)
                .attr("y1", view.y.scale(view.y.scale.domain()[0]))
                .attr("x2", x)
                .attr("y2", view.y.scale(view.y.scale.domain()[1]));
        }
    }
}

function proj0(d) {
    return d[0];
}

function proj1(d) {
    return d[1];
}

function projx(d) {
    return d.x;
}

function projy(d) {
    return d.y;
}

function id2pid(id) {
    if (typeof id === "string") {
        return parseInt(id, 10);
    }
    return id;
}

function pid_sorter(a, b) {
    if (typeof a === "object") {
        a = a.id || a[2];
    }
    if (typeof b === "object") {
        b = b.id || b[2];
    }
    const d = id2pid(a) - id2pid(b);
    return d ? d : (a < b ? -1 : (a == b ? 0 : 1));
}

function render_pid_p(ps, cc) {
    ps.sort(pid_sorter);
    const e = $e("p");
    for (let i = 0; i !== ps.length; ++i) {
        let p = ps[i], cx = cc, rest = "";
        if (typeof p === "object") {
            if (p.id) {
                rest = p.rest;
                cx = p.cc;
                p = p.id;
            } else {
                cx = p[3];
                p = p[2];
            }
        }
        const comma = i === ps.length - 1 ? "" : ",";
        let pe = "#" + p;
        if (cx) {
            ensure_pattern(cx);
            pe = $e("span", cx, pe);
        }
        i > 0 && e.append(" ");
        if (rest || cx) {
            e.append($e("span", "nw", pe, rest, comma));
        } else {
            e.append(pe + comma);
        }
    }
    e.normalize();
    return e;
}

function clicker(pids, event) {
    if (!pids) {
        return;
    }
    if (typeof pids !== "object") {
        pids = [pids];
    }
    let x = [], last_review = null;
    for (let p of pids) {
        if (typeof p === "object") {
            p = p.id;
        }
        if (typeof p === "string") {
            last_review = p;
            p = parseInt(p, 10);
        }
        x.push(p);
    }
    if (x.length === 1 && pids.length === 1 && last_review !== null) {
        clicker_go(hoturl("paper", {p: x[0], anchor: "r" + last_review}), event);
    } else if (x.length === 1) {
        clicker_go(hoturl("paper", {p: x[0]}), event);
    } else {
        x = Array.from(new Set(x).values());
        x.sort(pid_sorter);
        clicker_go(hoturl("search", {q: x.join(" ")}), event);
    }
}

function make_reviewer_clicker(email) {
    return function (event) {
        clicker_go(hoturl("search", {q: "re:" + email}), event);
    };
}

function clicker_go(url, event) {
    if (event && event.metaKey) {
        window.open(url, "_blank", "noopener");
    } else {
        window.location = url;
    }
}

const default_value_format = d3.format(",.4~f");

// An axis class supplies `tick_values(scale)` (which values get ticks, at the
// range the scale has just been given), `value_format(v)` (their text), and
// optionally `tick_decoration(v)` (classes, fill, background). Everything
// else — measuring, thinning, shortening, positioning, drawing — is generic
// and lives in `layout_axis` / `draw_axis`.
const default_axis_class = {
    scale_class: null,
    tick_values: function (scale) {
        const domain = scale.domain();
        if (Math.abs(domain[1] - domain[0]) < 0.01
            && this.value_format === default_value_format) {
            this.value_format = d3.format(",~f");
        }
        return scale.ticks();
    },
    value_format: default_value_format,
    color_classes: () => null,
    value_search: () => null,
    value_render: function (e, v) {
        e.append(this.value_format(v));
    },
    discrete: false,
    left_justify: false,
    projection: null
};

function ordinal_axis_class(ax) {
    // `ticks` is a list of {value, text, color_classes?, search?} in display
    // order; data values are mapped to display positions by `projection`
    // and `project_data` before rendering
    const projection = new Map(), ticks = ax.ticks;
    ticks.forEach(function (t, i) {
        projection.set(t.value, i + 1);
    });
    const want_tilt = ax.orientation === "x"
        && (ticks.length > 30
            || d3.max(ticks.map(function (t) { return (t.text || "").length; })) > 4),
        want_mclasses = ticks.some(function (t) { return t.color_classes; });

    function mtext(pos) {
        const t = ticks[pos - 1];
        return t ? t.text : "";
    }
    function mclasses(pos) {
        const t = ticks[pos - 1];
        return (t && t.color_classes) || "";
    }

    return {
        scale_class: "ordinal",
        // One tick per ordinal entry. `scale.ticks(n)` used to be asked for
        // these, but it returns "nice" values, so positions between entries
        // could come back and render as blank ticks.
        tick_values: function () {
            return ticks.map((t, i) => i + 1);
        },
        want_tilt: want_tilt,
        tick_decoration: want_mclasses ? function (pos) {
            const c = mclasses(pos);
            return c ? {classes: c + " taghh",
                        bg: /\btagbg\b/.test(c) ? c : null} : null;
        } : null,
        value_format: mtext,
        color_classes: mclasses,
        value_search: function (pos) {
            const t = ticks[pos - 1];
            return (t && t.search) || null;
        },
        value_render: function (e, value, include_numeric) {
            const fvalue = Math.round(value), t = ticks[fvalue - 1];
            if (Math.abs(value - fvalue) <= 0.05 && t) {
                e.append(t.color_classes ? $e("span", t.color_classes + " taghh", t.text) : t.text);
                if (include_numeric
                    && value !== fvalue
                    && typeof value === "number") {
                    e.append(" (" + value.toFixed(2) + ")");
                }
            }
        },
        discrete: true,
        projection: projection,
        left_justify: ax.left_justify ?? true
    };
}

const score_format = d3.format(",.2~f");

function score_axis_class(rf) {
    let numeric_format = score_format;
    function value_format(v) {
        let vt = rf.unparse_symbol(v);
        return typeof vt === "number" ? numeric_format(v) : v;
    }
    return {
        scale_class: "review_field",
        tick_values: function (scale) {
            const domain = scale.domain();
            let count = Math.floor(domain[1] * 2) - Math.ceil(domain[0] * 2) + 1;
            if (count > 11) {
                count = Math.floor(domain[1]) - Math.ceil(domain[0]) + 1;
            }
            if (Math.abs(domain[1] - domain[0]) < 0.1) {
                numeric_format = d3.format(",~f");
            }
            return rf.default_numeric ? scale.ticks() : scale.ticks(count);
        },
        tick_decoration: function (v) {
            return {classes: "sv " + rf.className(v), fill: rf.color(v)};
        },
        value_format: value_format,
        color_classes: function (v) {
            const k = rf.className(v);
            return k ? "sv " + k : null;
        },
        value_render: function (e, v, include_numeric) {
            let vt = value_format(v);
            const k = rf.className(v);
            e.append(k ? $e("span", "sv " + k, vt) : vt);
            if (include_numeric
                && !rf.default_numeric
                && v !== Math.round(v * 2) / 2) {
                e.append(" (" + numeric_format(v) + ")");
            }
        },
    };
}

function time_axis_class() {
    function format(value) {
        if (value < 1000000000) {
            value = Math.round(value / 8640) / 10;
            return value + "d";
        }
        const d = new Date(value * 1000);
        if (d.getHours() || d.getMinutes()) {
            return strftime("%Y-%m-%dT%R", d);
        }
        return strftime("%Y-%m-%d", d);
    }
    function fit_ticks(nscale, is_duration, range) {
        const maxticks = Math.max(2, Math.floor(Math.abs(range[1] - range[0]) / (is_duration ? 24 : 72)));
        let count = undefined;
        while (true) {
            const ticks = nscale.ticks(count);
            if (ticks.length <= maxticks || count === 1) {
                return ticks;
            }
            count = (count || maxticks) - 1;
        }
    }
    return {
        scale_class: "time",
        tick_values: function (scale) {
            const domain = scale.domain(),
                is_duration = Math.min(domain[0], domain[1]) < 1000000000,
                range = scale.range();
            if (is_duration) {
                const ddomain = [domain[0] / 86400, domain[1] / 86400],
                    nscale = d3.scaleLinear().domain(ddomain).range(range);
                return fit_ticks(nscale, is_duration, range).map(function (value) {
                    return value * 86400;
                });
            }
            const ddomain = [new Date(domain[0] * 1000), new Date(domain[1] * 1000)],
                nscale = d3.scaleTime().domain(ddomain).range(range);
            return fit_ticks(nscale, is_duration, range).map(function (value) {
                return value.getTime() / 1000;
            });
        },
        value_format: format,
        left_justify: true
    };
}

// Paper IDs: rendered as "#NNN" with no thousands separator.
function pid_axis_class() {
    function format(value) {
        return "#" + Math.round(value);
    }
    return {
        value_format: format,
        value_render: function (e, value) {
            e.append(format(value));
        }
    };
}

function instantiate_axis(ax) {
    // `ax` is a server FormulaGraphAxis JSON ({scale_class, ticks?, order?,
    // review_field?, ...}).
    const sc = ax && ax.scale_class;
    let info;
    if (ax && ax.ticks) {
        info = ordinal_axis_class(ax);
    } else if (sc === "review_field") {
        info = score_axis_class(hotcrp.make_review_field(ax.review_field));
    } else if (sc === "time") {
        info = time_axis_class();
    } else if (sc === "pid") {
        info = pid_axis_class();
    } else {
        info = {scale_class: sc || null};
    }
    const ax1 = Object.assign({}, ax, default_axis_class, info);
    if (!ax || !ax.order) {
        return ax1;
    }
    info = ordinal_axis_class({
        orientation: ax1.orientation,
        ticks: ax1.order.map(v => ({
            value: v, text: ax1.value_format(v),
            color_classes: ax1.color_classes(v)
        })),
        left_justify: ax1.left_justify ?? false
    });
    return Object.assign({}, ax, default_axis_class, info);
}

/** Choose and place the ticks for one axis at `length` px along it, shorten
 * any whose ink would reach more than `cap` px across it, and record the
 * result on the axis. Returns how far the tick ink actually reaches across
 * from the axis line — left of a Y axis, below an X axis. The caller adds the
 * inset that keeps that ink off the edge of the box.
 *
 * An upright X tick label is centered on its tick, so one near the end of the
 * axis reaches past it; `overhang_cap` is how far past a label may reach.
 * Ticks that would reach further are dropped, down to the last one, which an
 * axis keeps regardless. `axis.layout.overhang` reports how far the survivors
 * actually reach.
 * @param {object} axis
 * @param {string} side
 * @param {object} args
 * @param {*} scale
 * @param {number} length
 * @param {number} cap
 * @param {number} [overhang_cap]
 * @return {number} */
function layout_axis(view, side, scale, length, cap, overhang_cap) {
    const xside = side === "x", axis = view.args[side], vaxis = view[side];
    scale.range(!axis.flip === !xside ? [length, 0] : [0, length]);
    vaxis.scale = scale;

    const values = axis.tick_values(scale),
        texts = values.map(v => axis.value_format(v));
    let widelabel = false,
        m = measure_texts(view.svg, texts, false);
    if (d3.max(m.widths) > 100) { // shrink the font before anything else
        widelabel = true;
        m = measure_texts(view.svg, texts, true);
    }
    const tilt = xside && !!axis.want_tilt;

    // Thin ticks that would collide along the axis. This has to happen before
    // the breadth is taken, or the margin reserves room for labels that are
    // then hidden.
    // `slack` is how much of a tick's room may be given up before thinning;
    // crowding a line of text vertically still reads, two labels running into
    // each other does not, so upright labels get none
    let per_tick, slack = 0.1;
    if (tilt) {
        per_tick = m.height * COSINE_TILT_RADIANS + 8;
    } else if (xside) {
        // upright labels run along the axis, so the widest one sets the room
        // every tick needs; each is centered on its tick, so neighbors stay
        // 8px apart even where two of that width meet
        per_tick = (d3.max(m.widths) || 0) + 8;
        slack = 0;
    } else {
        per_tick = m.height + 4;
    }
    const alternation =
        Math.max(1, Math.ceil(values.length * per_tick / length - slack));

    const out = [];
    for (let i = 0; i !== values.length; ++i) {
        if (alternation > 1 && i % alternation !== 1) {
            continue;
        }
        const t = {value: values[i], pos: scale(values[i]), full: texts[i],
                text: texts[i], width: m.widths[i]},
            dec = axis.tick_decoration && axis.tick_decoration(values[i]);
        if (dec) {
            t.classes = dec.classes;
            t.fill = dec.fill;
            t.bg = dec.bg;
        }
        out.push(t);
    }

    // only the survivors have to fit. A tilted label's breadth is its text
    // width projected onto the cross axis, so the cap inverts through the tilt
    let textcap;
    if (!xside) {
        textcap = cap - Y_TICK_TEXT_OFFSET;
    } else if (tilt) {
        textcap = tilted_label_width(cap, m.descent);
    } else {
        textcap = Infinity; // upright X labels collide sideways, and thinning
                            // has already spaced them out
    }
    truncate_ticks(view.svg, out, Math.min(textcap, MAX_LABEL_WIDTH), widelabel);

    // How far the labels reach past the end of the axis, once any that
    // exceed the allowance are gone. Only upright X labels do: a tilted one
    // runs up and to the left, and a Y label's width is its axis's business.
    let overhang = 0;
    if (xside && !tilt) {
        const room = overhang_cap ?? Infinity;
        for (let i = out.length - 1; i >= 0; --i) {
            const over = out[i].pos + out[i].width / 2 - length;
            // an axis keeps its last label even where there is no room for
            // it; with one tick left there is nothing for thinning to change,
            // so granting the room it asks for cannot start a cycle
            if (over > room && out.length > 1) {
                out[i].opacity = Math.max(1 - 2 * (over - room) / out[i].width, 0);
            } else if (over > overhang) {
                overhang = over;
            }
        }
    }

    const maxw = out.length === 0 ? 0 : Math.max(...out.map(t => t.width)),
        ink = !xside ? Math.ceil(maxw) + Y_TICK_TEXT_OFFSET
            : (tilt ? tilted_label_depth(maxw, m.descent)
               : X_TICK_TEXT_OFFSET + m.height);
    vaxis.layout = {ticks: out, tilt: tilt, widelabel: widelabel,
        ascent: m.ascent, ink: ink, overhang: Math.ceil(overhang)};
    return ink;
}

/** Lay out both axes and settle the plot rectangle.
 *
 * In unexpandable plots each edge depends on the others through the ticks
 * they leave room for, so the layout iterates until it settles.
 * @param {object} view
 * @param {*} x
 * @param {*} y */
function make_axis_pair(view, x, y) {
    const args = view.args;
    // both axis labels render in the axis font, so any string measures it
    view.labelMetrics = measure_texts(view.svg, [args.x.label || args.y.label || "0"]);
    const lm = view.labelMetrics,
        grow = view.expandable === "height",
        base_height = view.box[1],
        lheight = Math.ceil(lm.height),
        // the X axis label claims a band of its own below the tick labels
        // unless they tilt, in which case it tucks in beside the shorter ones
        label_room = args.x.label && !args.x.want_tilt ? lheight + LABEL_GAP : 0,
        // a growable box keeps the plot height it would have with upright tick
        // labels; anything deeper makes the box taller instead of the plot
        // shorter
        nominal_bottom = args.insetBottom + X_TICK_TEXT_OFFSET + lheight + label_room,
        pin_top = args.plotTop, pin_right = args.plotRight,
        pin_bottom = args.plotBottom, pin_left = args.plotLeft,
        // how far tick ink may reach from each axis line. A pin is a hard
        // limit on its side; an unpinned side gets the policy fraction.
        ycap = (pin_left ?? Math.floor(view.box[1] * MAX_Y_LABEL_WIDTH_FRACTION))
            - args.insetLeft,
        xroom = grow ? Infinity
            : (pin_bottom != null ? base_height - pin_bottom
               : Math.floor(base_height * MAX_X_LABEL_HEIGHT_FRACTION)),
        xcap = xroom - args.insetBottom - label_room,
        top = pin_top ?? args.insetTop + y_head_room(view);
    let left = pin_left ?? 0,
        right = pin_right ?? view.box[0] - args.insetRight,
        bmargin = nominal_bottom, settled = false,
        // Room granted past the end of the X axis for a label that overhangs
        // it, and the allowance `layout_axis` enforces. The room only grows
        // while the allowance is open: the first round that asks for less
        // closes it at that figure, so giving room back cannot widen the plot
        // and bring the wide label round again.
        //
        // A zoomed view does not negotiate. Its window is fixed, so the plot
        // rect showing it stays put, and a label that overhangs is dropped.
        overhang = 0, overhang_cap = view.window ? 0 : Infinity;

    /** @param {number} b - bottom margin
     * @return {number} Y of the X axis line */
    function plot_bottom(b) {
        return pin_bottom ?? (grow ? base_height - nominal_bottom : base_height - b);
    }
    function lay_out() {
        const yink = layout_axis(view, "y", y, plot_bottom(bmargin) - top, ycap),
            nleft = pin_left ?? args.insetLeft + Math.min(yink, ycap),
            nbmargin = Math.ceil(Math.min(xroom,
                layout_axis(view, "x", x, right - nleft, xcap, overhang_cap)
                    + args.insetBottom + label_room)),
            need = view.x.layout.overhang;
        if (need > overhang) {
            overhang = need;
        } else if (need < overhang) {
            overhang = overhang_cap = need;
        }
        // the right inset measures to the rightmost ink, which is the end of
        // the axis line unless a tick label overhangs it
        const nright = pin_right ?? view.box[0] - args.insetRight - overhang;
        settled = nleft === left && nbmargin === bmargin && nright === right;
        left = nleft;
        bmargin = nbmargin;
        right = nright;
    }

    for (let round = 0; round !== 3 && !settled; ++round) {
        lay_out();
    }
    if (!settled) {
        // no more room is on offer, so this pass drops whatever does not fit
        // and cannot ask for more
        overhang_cap = overhang;
        lay_out(); // one consistent pass at whatever it converged on
    }

    const bottom = plot_bottom(bmargin);
    view.left = left;
    view.top = top;
    view.right = right;
    view.bottom = bottom;
    view.plotWidth = right - left;
    view.plotHeight = bottom - top;
    view.fullHeight = grow ? bottom + bmargin : base_height;
    d3.select(view.svg.node().ownerSVGElement).attr("height", view.fullHeight);
    const tr = `translate(${left},${top})`;
    view.svg.attr("transform", tr);
    view.overlay.attr("transform", tr);
    // Marks at the edge of the domain hang over it by their own radius, so
    // the clip keeps that much of the surround: a zoomed edge then looks like
    // an unzoomed one, while marks well outside the window stay hidden.
    view.clip.attr("x", left - PLOT_CLIP_MARGIN).attr("y", top - PLOT_CLIP_MARGIN)
        .attr("width", view.plotWidth + 2 * PLOT_CLIP_MARGIN)
        .attr("height", view.plotHeight + 2 * PLOT_CLIP_MARGIN);
}

function make_linear_scale(argextent, e) {
    if (argextent && argextent[0] != null) {
        e = [argextent[0], e[1]];
    }
    if (argextent && argextent[1] != null) {
        e = [e[0], argextent[1]];
    }
    return d3.scaleLinear().domain(e);
}

function render_position(aa, p, prefix) {
    const e = $e("span", "nw");
    if (prefix || aa.label) {
        e.append((prefix || "") + (aa.label ? aa.label + " " : ""));
    }
    aa.value_render(e, p, true);
    return e;
}


// args: {data: [{d: [ARRAY], label: STRING, className: STRING}],
//        x/y: {label: STRING, tickFormat: STRING}}
function graph_cdf(element, view) {
    const svg = this, args = view.args;

    // massage data
    let series = args.data;
    series = series.filter(function (d) {
        return (d.d ? d.d : d).length > 0;
    });
    const data = series.map(function (d) {
        d = d.d ? d.d : d;
        return d.cdf ? d : seq_to_cdf(d, args.x.flip, !args.y.fraction);
    });

    // axis domains
    let xdomain = data.reduce(function (e, d) {
        e[0] = Math.min(e[0], d[0][0], d[d.length - 1][0]);
        e[1] = Math.max(e[1], d[0][0], d[d.length - 1][0]);
        return e;
    }, [Infinity, -Infinity]);
    xdomain = [xdomain[0] - (xdomain[1] - xdomain[0]) / 32,
               xdomain[1] + (xdomain[1] - xdomain[0]) / 32];
    const x = make_linear_scale(view.x.extent, xdomain),
        y = make_linear_scale(view.y.extent, [0, Math.ceil(d3.max(data, function (d) {
                return d[d.length - 1][1];
            }) * 10) / 10]);
    make_axis_pair(view, x, y);

    // lines
    const line = d3.line().x(function (d) {return x(d[0]);})
        .y(function (d) {return y(d[1]);});

    // CDF lines
    data.forEach(function (d, i) {
        var cl = series[i].className;
        if (d[d.length - 1][0] != xdomain[args.x.flip ? 0 : 1])
            d.push([xdomain[args.x.flip ? 0 : 1], d[d.length - 1][1]]);
        var p = svg.append("path").attr("data-index", i)
            .datum(d)
            .attr("class", "gcdf" + (cl ? " " + cl : ""))
            .style("stroke", ensure_pattern(series[i].className, "gcdf"))
            .attr("d", line);
        if (series[i].dashpattern)
            p.attr("stroke-dasharray", series[i].dashpattern.join(","));
    });

    svg.append("path").attr("class", "gcdf gcdf-hover0");
    svg.append("path").attr("class", "gcdf gcdf-hover1");
    const hovers = svg.selectAll(".gcdf-hover0, .gcdf-hover1");
    hovers.style("display", "none");

    draw_axes(svg, view);
    draw_annotations(svg, view);

    if (args.interactive !== false) {
        view.overlay.append("rect")
            .attr("x", -view.left)
            .attr("width", view.right)
            .attr("height", view.fullHeight - view.top)
            .attr("fill", "none")
            .attr("pointer-events", "all")
            .on("mouseover", mousemoved)
            .on("mousemove", mousemoved)
            .on("mouseout", mouseout)
            .on("click", mouseclick);
    }

    var hovered_path, hovered_series, hubble;
    function mousemoved(event) {
        var m = d3.pointer(event), p = {distance: 16};
        m.clientX = event.clientX;
        m.clientY = event.clientY;
        for (var i in data) {
            if (series[i].label || args.cdf_tooltip_position)
                p = closestPoint(svg.select("[data-index='" + i + "']").node(), m, p);
        }
        if (p.pathNode !== hovered_path) {
            if (p.pathNode) {
                i = p.pathNode.getAttribute("data-index");
                hovered_series = series[i];
                hovers.datum(data[i]).attr("d", line).style("display", null);
            } else {
                hovered_series = null;
                hovers.style("display", "none");
            }
            hovered_path = p.pathNode;
        }
        if (hovered_series && (hovered_series.label || args.cdf_tooltip_position)) {
            hubble = hubble || make_bubble({class: args.tooltip_class || "graphtip", "pointer-events": "none"});
            var dir = Math.abs(tangentAngle(p.pathNode, p.pathLength));
            if (args.cdf_tooltip_position) {
                const f = $frag();
                hovered_series.label && f.append(hovered_series.label + ": ");
                args.x.value_render(f, x.invert(p[0]), true);
                f.append(", ");
                args.y.value_render(f, y.invert(p[1]), true);
                hubble.replace_content(f);
            } else {
                hubble.text(hovered_series.label);
            }
            hubble.anchor(dir >= 0.25*Math.PI && dir <= 0.75*Math.PI ? "e" : "s")
                .at(p[0] + view.left, p[1], this);
        } else if (hubble) {
            hubble = hubble.remove() && null;
        }
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_path = hovered_series = hubble = null;
    }

    function mouseclick(evt) {
        if (hovered_series && hovered_series.click)
            hovered_series.click.call(this, evt);
    }
}

function procrastination_seq(ri) {
    const seq = [];
    for (const r of ri) {
        if (r[0] > 0)
            seq.push(r[0]);
    }
    seq.ntotal = ri.length;
    return seq;
}

function procrastination_filter(revdata) {
    const args = Object.assign({}, revdata,
        {gtype: "cdf", data: [], data_format: "cdf",
         tooltip_class: "graphtip-s"});

    // collect data
    const alldata = [];
    for (const cid in revdata.reviews) {
        const d = {d: revdata.reviews[cid], className: "gcdf-many"},
            u = revdata.users[cid];
        u && u.name && (d.label = u.name);
        u && u.email && (d.click = make_reviewer_clicker(u.email));
        if (cid && cid == siteinfo.user.uid) {
            d.className = "gcdf-highlight";
            d.priority = 1;
        } else if (u && u.light) {
            d.className += " gcdf-thin";
        }
        u && u.color_classes && (d.className += " " + u.color_classes);
        Array.prototype.push.apply(alldata, d.d);
        if (cid !== "conflicts") {
            args.data.push(d);
        }
    }
    args.data.push({d: alldata, className: "gcdf-cumulative", priority: 2});
    args.data.sort(function (a, b) {
        return d3.ascending(a.priority || 0, b.priority || 0);
    });

    // make cdfs
    for (const ds of args.data) {
        ds.d = seq_to_cdf(procrastination_seq(ds.d));
    }

    args.x = {label: "Date", scale_class: "time"};
    args.y = {label: "Fraction of assignments completed"};
    args.annotations = [];
    revdata.deadlines.sort();
    let last_deadline = 0;
    for (const dl of revdata.deadlines) {
        if (dl > last_deadline) {
            last_deadline = dl;
            args.annotations.push({type: "xline", x: dl});
        }
    }
    return args;
}


/* grouped quadtree */
// mark bounds of each node
function grouped_quadtree_mark_bounds(q, rf, ordinalf) {
    //ordinalf = ordinalf || (function () { var m = 0; return function () { return ++m; }; })();
    //q.ordinal = ordinalf();

    let b, p, i, n, ps;
    if (!q.length) {
        for (p = q.data, ps = []; p; p = p.next) {
            ps.push(p);
        }
        ps.sort(function (a, b) { return d3.ascending(a.n, b.n); });
        for (i = n = 0; i < ps.length; ++i) {
            ps[i].r0 = i ? ps[i-1].r : 0;
            n += ps[i].n;
            ps[i].r = rf(n);
        }
        q.maxr = ps[ps.length - 1].r;
        p = q.data;
        b = [p[1] - q.maxr, p[0] + q.maxr, p[1] + q.maxr, p[0] - q.maxr];
    } else {
        b = [Infinity, -Infinity, -Infinity, Infinity];
        for (i = 0; i < 4; ++i)
            if ((p = q[i])) {
                grouped_quadtree_mark_bounds(p, rf, ordinalf);
                b[0] = Math.min(b[0], p.bounds[0]);
                b[1] = Math.max(b[1], p.bounds[1]);
                b[2] = Math.max(b[2], p.bounds[2]);
                b[3] = Math.min(b[3], p.bounds[3]);
            }
    }
    q.bounds = b;
}

function grouped_quadtree_gfind(point, min_distance) {
    var closest = null;
    if (min_distance == null)
        min_distance = Infinity;
    function visitor(node) {
        if (node.bounds[0] > point[1] + min_distance
            || node.bounds[1] < point[0] - min_distance
            || node.bounds[2] < point[1] - min_distance
            || node.bounds[3] > point[0] + min_distance)
            return true;
        if (node.length)
            return;
        let p = node.data;
        const dx = p[0] - point[0], dy = p[1] - point[1];
        if (Math.abs(dx) - node.maxr < min_distance
            || Math.abs(dy) - node.maxr < min_distance) {
            const dd = Math.sqrt(dx * dx + dy * dy);
            for (; p; p = p.next) {
                const d = Math.max(dd - p.r, 0);
                if (d < min_distance || (d == 0 && p.r < closest.r))
                    closest = p, min_distance = d;
            }
        }
    }
    this.visit(visitor);
    return closest;
}

function grouped_quadtree(data, xs, ys, rf, expand) {
    function make_extent() {
        const xe = xs.range(), ye = ys.range();
        return [[Math.min(xe[0], xe[1]), Math.min(ye[0], ye[1])],
                [Math.max(xe[0], xe[1]), Math.max(ye[0], ye[1])]];
    }
    const q = d3.quadtree().extent(make_extent()), nd = [];

    for (const d of data) {
        if (d[0] == null || d[1] == null) {
            continue;
        }
        const vd = {
            "0": xs(d[0]),
            "1": ys(d[1]),
            d0: d[0],
            d1: d[1],
            data: [d],
            cc: d[3],
            next: null,
            head: null,
            n: expand ? d[2].length : 1,
            i: nd.length,
            r0: null,
            r: null,
            ur: null
        };
        let vp = q.find(vd[0], vd[1]);
        if (vp) {
            const dx = Math.abs(vp[0] - vd[0]),
                dy = Math.abs(vp[1] - vd[1]);
            // group points within 2px of display space,
            // and within 1 of each coordinate
            if (dx > 2
                || dy > 2
                || Math.abs(d[0] - vp.d0) >= 1
                || Math.abs(d[1] - vp.d1) >= 1
                || dx * dx + dy * dy > 4) {
                vp = null;
            }
        }
        while (vp && vp.cc != vd.cc && vp.next) {
            vp = vp.next;
        }
        if (vp && vp.cc == vd.cc) {
            vp.data.push(d);
            vp.n += vd.n;
        } else {
            if (vp) {
                vp.next = vd;
                vd.head = vp.head || vp;
            } else {
                q.add(vd);
            }
            nd.push(vd);
        }
    }

    if (rf == null) {
        rf = Math.sqrt;
    } else if (typeof rf === "number") {
        rf = (function (f) {
            return function (n) { return Math.sqrt(n) * f; };
        })(rf);
    }
    if (q.root()) {
        grouped_quadtree_mark_bounds(q.root(), rf);
    }

    delete q.add;
    q.gfind = grouped_quadtree_gfind;
    return {data: nd, quadtree: q};
}

function gqdata_ids(gqp, want_cc) {
    const a = [], cch = gqp.cc;
    for (; gqp; gqp = gqp.next) {
        for (const d of gqp.data) {
            const ids = typeof d[2] === "object" ? d[2] : [d[2]];
            if (want_cc && cch !== d[3]) {
                for (const id of ids) {
                    a.push({id: id, cc: d[3]});
                }
            } else {
                a.push(...ids);
            }
        }
    }
    return a;
}

function ungroup_data(data) {
    if (Array.isArray(data)) {
        return data;
    }
    for (const style in data) {
        if (style && style !== "none") {
            data[style].forEach(d => d.push(style));
        }
    }
    return d3.merge(Object.values(data));
}

function project_axis(data, type, col, projection) {
    if (type === "cdf") {
        for (const s of data) {
            const dv = s.d;
            let n = dv.length;
            for (let i = 0; i !== n; ) {
                const nv = projection.get(dv[i]);
                if (nv == null) {
                    dv[i] = dv[n - 1];
                    dv.pop();
                    --n;
                } else {
                    dv[i] = nv;
                    ++i;
                }
            }
        }
    } else if (type === "style_xyi") {
        for (const g of Object.values(data)) {
            project_axis(g, "xyi", col, projection);
        }
    } else {
        let n = data.length;
        for (let i = 0; i !== n; ) {
            const nv = projection.get(data[i][col]);
            if (nv == null) {
                data[i] = data[n - 1];
                data.pop();
                --n;
            } else {
                data[i][col] = nv;
                ++i;
            }
        }
    }
}

// Rewrite `args.data` in place, mapping each ordinal axis's identities to
// positions. Runs once per graph before rendering; highlight refreshes call
// project_axis() directly against the original axisinfo.
function project_data(args, axes) {
    if (axes.x.projection) {
        project_axis(args.data, args.data_format, 0, axes.x.projection);
    }
    if (axes.y.projection) {
        project_axis(args.data, args.data_format, 1, axes.y.projection);
    }
}

const scatter_annulus = d3 ? d3.arc()
    .innerRadius(function (d) { return d.r0 ? d.r0 - 0.5 : 0; })
    .outerRadius(function (d) { return d.r - 0.5; })
    .startAngle(0)
    .endAngle(Math.PI * 2) : null;

const scatter_union_annulus = d3 ? d3.arc()
    .outerRadius(function (d) { return (d.ur || d.r) - 0.5; })
    .startAngle(0)
    .endAngle(Math.PI * 2) : null;

function scatter_transform(d) {
    return "translate(" + d[0] + "," + d[1] + ")";
}

function scatter_key(d) {
    return d[0] + "," + d[1] + "," + d.r;
}

function scatter_create(svg, gqdata, klass) {
    let sel = svg.selectAll(".gdot");
    if (klass)
        sel = sel.filter("." + klass);
    sel = sel.data(gqdata, scatter_key);
    sel.exit().remove();
    const pathklass = "gdot" + (klass ? " " + klass : "");
    sel.enter()
        .append("path")
        .attr("class", function (d) { return pathklass + (d.cc ? " " + d.cc : "") })
        .style("fill", function (d) { return ensure_pattern(d.cc, "gdot"); })
      .merge(sel)
        .attr("d", scatter_annulus)
        .attr("transform", scatter_transform);
    return sel;
}

function highlight_pattern() {
    if ($$("svggpat_dot_highlight")) {
        return;
    }
    $$("p-body").prepend($svg("svg", {width: 0, height: 0, "class": "position-absolute"},
        $svg("defs", null,
            $svg("radialGradient", {id: "svggpat_dot_highlight"},
                $svg("stop", {offset: "50%", "stop-opacity": 0}),
                $svg("stop", {offset: "50%", "stop-color": "#ffff00", "stop-opacity": 0.5}),
                $svg("stop", {offset: "100%", "stop-color": "#ffff00", "stop-opacity": 0})))));
}

function highlight_update(svg, data, keyfunc, klass) {
    highlight_pattern();
    let sel = svg.selectAll(".ghighlight");
    if (klass) {
        sel = sel.filter("." + klass);
    }
    sel = sel.data(data, keyfunc);
    sel.exit().remove();
    let g = sel.enter()
      .append("g")
        .attr("class", "ghighlight" + (klass ? " " + klass : ""));
    g.append("circle")
        .attr("class", "gdot-hover");
    g.append("circle")
        .style("fill", "url(#svggpat_dot_highlight)");
    return g.merge(sel).selectAll("circle");
}

function scatter_highlight(svg, data, klass) {
    highlight_update(svg, data, scatter_key, klass)
        .attr("cx", proj0)
        .attr("cy", proj1)
        .attr("r", function (d, i) {
            return i ? (d.r + 0.5) * 2 : d.r - 0.5;
        });
}

function scatter_union(p) {
    if (!p) {
        return null;
    }
    if (p.head) {
        p = p.head;
    }
    if (!p.next || p.ur != null) {
        return p;
    }
    p.ur = p.r;
    for (let pp = p.next; pp; pp = pp.next) {
        p.ur = Math.max(p.ur, pp.r);
    }
    return p;
}

function make_hover_interactor(svg, hovers, identity) {
    let data = null, over = null, owner = svg.node().closest("svg");
    function mouseout() {
        hovers.style("display", "none");
        if (self.bubble) {
            self.bubble.remove();
        }
        self.data = self.bubble = data = over = null;
        owner.style.cursor = "";
    }
    const self = {
        data: null,
        bubble: null,
        move: function (d) {
            if (!d && data) {
                mouseout();
            }
            if (!d || (data && (identity ? identity(d, data) : d === data))) {
                over = d;
                return false;
            }
            self.data = data = d;
            self.bubble = self.bubble || make_bubble({class: "graphtip", "pointer-events": "none"});
            owner.style.cursor = "pointer";
            return true;
        },
        mouseout: mouseout,
        mouseout_soon: function () {
            if (!data) {
                return;
            }
            const kill = data;
            over = null;
            setTimeout(function () {
                if (data === kill && !over)
                    mouseout();
            }, 10);
        }
    };
    return self;
}

function graph_scatter(element, view) {
    const svg = this, args = view.args;
    let data = ungroup_data(args.data);
    const x = make_linear_scale(view.x.extent, expand_extent(d3.extent(data, proj0), args.x)),
        y = make_linear_scale(view.y.extent, expand_extent(d3.extent(data, proj1), args.y));
    make_axis_pair(view, x, y);

    element.addEventListener("hotgraphhighlight", ev => highlight(ev.detail),
        {signal: view.signal});

    const gq = grouped_quadtree(data, x, y, 4, args.data_format === "xyis");
    data = null;
    scatter_create(svg, gq.data);

    svg.append("path").attr("class", "gdot gdot-hover");
    const hovers = svg.selectAll(".gdot-hover").style("display", "none"),
        hoverer = make_hover_interactor(svg, hovers);

    draw_axes(svg, view);
    draw_annotations(svg, view);

    if (args.interactive !== false) {
        view.overlay.append("rect")
            .attr("x", -view.left)
            .attr("width", view.right)
            .attr("height", view.fullHeight - view.top)
            .attr("fill", "none")
            .attr("pointer-events", "all")
            .on("mouseover", mousemoved)
            .on("mousemove", mousemoved)
            .on("mouseout", hoverer.mouseout_soon)
            .on("click", mouseclick);
    }

    function make_tooltip(p) {
        const pinstance = p.data[0];
        return [
            $e("p", null, render_position(args.x, pinstance[0]), ", ", render_position(args.y, pinstance[1])),
            render_pid_p(gqdata_ids(p, true), p.cc)
        ];
    }

    function mousemoved(event) {
        let m = d3.pointer(event), p = scatter_union(gq.quadtree.gfind(m, 4));
        if (!hoverer.move(p)) {
            return;
        }
        hovers.datum(p)
            .attr("d", scatter_union_annulus)
            .attr("transform", scatter_transform)
            .style("display", null);
        hoverer.bubble.replace_content(...make_tooltip(p))
            .anchor("s")
            .near(hovers.node());
    }

    function mouseclick(event) {
        clicker(hoverer.data ? gqdata_ids(hoverer.data) : null, event);
    }

    // `hl` is {ok, q, ids}: either the detail of a highlight event or the
    // search response that resolves one, which have the same shape
    function highlight(hl) {
        if (!hl.ids) {
            if (hl.q && hl.ok) {
                api_get(hoturl("api/search", {q: hl.q, forceShow: 1}), highlight);
            }
            return;
        }
        hoverer.mouseout();
        let myd = [];
        if (hl.ids.length) {
            myd = gq.data.filter(function (pd) {
                for (const d of pd.data) {
                    if (hl.ids.indexOf(id2pid(d[2])) >= 0) {
                        return true;
                    }
                }
                return false;
            });
        }
        scatter_highlight(svg, myd);
    }
}

const DOT_RADIUS = 5;

function dot_highlight(svg, data, klass) {
    highlight_update(svg, data, d => d.id, klass)
        .attr("cx", projx)
        .attr("cy", projy)
        .attr("r", d => d.r - 0.5);
}

const DOT_LABEL_MAX = 6;

function dot_label_class(d) {
    // longer labels get their own class, so CSS can condense them further;
    // `gdot-label-DOT_LABEL_MAX` means "that many characters or more"
    const n = Math.min(d.label.length, DOT_LABEL_MAX);
    return n > 2 ? "gdot-label gdot-label-" + n : "gdot-label";
}

// Label each datum with its pid, then give every dot one radius: the pid is
// not data, so it must not vary a dot's visual weight. Labels of the same
// length render at the same width, so one measurement per length suffices.
function assign_dot_labels(svg, data) {
    const text = svg.append("text"),
        widths = [];
    let width = 0;
    for (const d of data) {
        d.label = "" + d.id;
        const n = d.label.length;
        if (widths[n] === undefined) {
            text.attr("class", dot_label_class(d)).text(d.label);
            widths[n] = text.node().getComputedTextLength();
        }
        width = Math.max(width, widths[n]);
    }
    text.remove();
    const r = Math.max(DOT_RADIUS, width / 2 + 3);
    for (const d of data) {
        d.r = r;
    }
}

function data_pidcode(data) {
    const p = [];
    for (const d of data) {
        if (typeof d[2] === "object") {
            p.push(...d[2]);
        } else {
            p.push(d[2]);
        }
    }
    return hotcrp.pidcode(p);
}

function load_titles(titles, pidcode, success) {
    api_get(hoturl("api/search", {q: "pidcode:" + pidcode, f: "title", format: "json", forceShow: 1}),
        function (d) {
            for (const pd of d.papers || []) {
                titles[pd.pid] = pd.title;
            }
            success();
        });
}

function graph_dot(element, view) {
    const svg = this, args = view.args;
    let data = ungroup_data(args.data);
    const pidcode = data_pidcode(data),
        x = make_linear_scale(view.x.extent, expand_extent(d3.extent(data, proj0), args.x)),
        y = make_linear_scale(view.y.extent, expand_extent(d3.extent(data, proj1), args.y));
    make_axis_pair(view, x, y);
    data = data.map(d => {
        const xv = x(d[0]), yv = y(d[1]);
        return {"0": d[0], "1": d[1], x: xv, x0: xv, y: yv, y0: yv, id: d[2], cc: d[3], r: DOT_RADIUS};
    });
    const labeled = args.gtype === "ldot";
    if (labeled) {
        assign_dot_labels(svg, data);
    }

    const sim = d3.forceSimulation(data)
        .force("collide", d3.forceCollide(d => d.r + 1))
        .force("x", d3.forceX(d => d.x0).strength(0.05))
        .force("y", d3.forceY(d => d.y0).strength(0.05))
        .stop();
    sim.tick(Math.ceil(Math.log(sim.alphaMin()) / Math.log(1 - sim.alphaDecay())));

    element.addEventListener("hotgraphhighlight", ev => highlight(ev.detail),
        {signal: view.signal});

    svg.append("circle").attr("class", "gdot gdot-hover");
    const hovers = svg.selectAll(".gdot-hover")
            .attr("r", DOT_RADIUS)
            .style("display", "none"),
        hoverer = make_hover_interactor(svg, hovers);

    draw_axes(svg, view);
    draw_annotations(svg, view);

    // A labeled dot is two elements sharing one position, so group them and
    // place the group; a plain dot is just the circle and needs no wrapper.
    const enter = svg.selectAll(labeled ? ".gdot-g" : ".gdot:not(.gdot-hover)")
        .data(data)
        .enter();
    let target;
    if (labeled) {
        target = enter.append("g")
            .attr("class", d => "gdot-g" + (d.cc ? " " + d.cc : ""))
            .attr("transform", d => "translate(" + projx(d) + "," + projy(d) + ")");
        target.append("circle")
            .attr("r", d => d.r)
            .attr("class", d => "gdot" + (d.cc ? " " + d.cc : ""))
            .style("fill", d => ensure_pattern(d.cc, "gdot"));
        target.append("text")
            .attr("class", dot_label_class)
            .attr("dy", "0.35em")
            .text(d => d.label);
    } else {
        target = enter.append("circle")
            .attr("cx", projx)
            .attr("cy", projy)
            .attr("r", d => d.r)
            .attr("class", d => "gdot" + (d.cc ? " " + d.cc : ""))
            .style("fill", d => ensure_pattern(d.cc, "gdot"));
    }
    if (args.interactive !== false) {
        target.on("mouseover", mouseover)
            .on("mouseout", hoverer.mouseout_soon)
            .on("click", mouseclick);
    }

    let titles = null;

    function make_tooltip(p) {
        const a = [
            $e("p", null, render_position(args.x, p[0]), ", ", render_position(args.y, p[1])),
            render_pid_p([p], p.cc)
        ];
        // `titles` is keyed by paper, `p.id` may name a review on one
        const title = titles && titles[id2pid(p.id)];
        if (title) {
            a[1].append(" " + title);
        }
        return a;
    }

    function show_bubble() {
        if (hoverer.data) {
            hoverer.bubble.replace_content(...make_tooltip(hoverer.data))
                .anchor("s")
                .near(hovers.node());
        }
    }

    function mouseover(event, p) {
        if (!hoverer.move(p)) {
            return;
        }
        hovers.datum(p)
            .attr("cx", projx)
            .attr("cy", projy)
            .attr("r", d => d.r)
            .style("display", null);
        show_bubble();
        if (titles === null) {
            titles = [];
            load_titles(titles, pidcode, show_bubble);
        }
    }

    function mouseclick(event) {
        const p = d3.select(this).datum();
        clicker(p ? p.id : null, event);
    }

    // see `graph_scatter`
    function highlight(hl) {
        if (!hl.ids) {
            if (hl.q && hl.ok) {
                api_get(hoturl("api/search", {q: hl.q, forceShow: 1}), highlight);
            }
            return;
        }
        hoverer.mouseout();
        let myd = [];
        if (hl.ids.length) {
            myd = data.filter(function (d) {
                return hl.ids.indexOf(id2pid(d.id)) >= 0;
            });
        }
        dot_highlight(svg, myd);
    }
}

function data_quantize_x(data) {
    data = ungroup_data(data);
    if (!data.length) {
        return data;
    }
    data.sort(function (a, b) { return d3.ascending(a[0], b[0]); });
    const epsilon = (data[data.length - 1][0] - data[0][0]) / 5000;
    let active = null;
    for (const d of data) {
        if (active !== null && Math.abs(active - d[0]) <= epsilon) {
            d[0] = active;
        } else {
            active = d[0];
        }
    }
    return data;
}

function data_to_barchart(data, yaxis) {
    data = data_quantize_x(data);
    if (!data.length) {
        return data;
    }

    data.sort(function (a, b) {
        return d3.ascending(a[0], b[0])
            || d3.ascending(a[4] || 0, b[4] || 0)
            || (a[3] || "").localeCompare(b[3] || "");
    });

    let last = null;
    const ndata = [];
    for (const d of data) {
        if (d[1] == null) {
            continue;
        }
        const cur = {
            "0": d[0],
            "1": d[1],
            ids: d[2],
            yoff: 0,
            i0: ndata.length,
            cc: d[3],
            sx: d[4]
        };
        ndata.push(cur);
        if (last && cur[0] == last[0] && (cur.sx == last.sx || yaxis.fraction)) {
            cur.yoff = last.yoff + last[1];
            cur.i0 = last.i0;
        }
        last = cur;
    }

    if (!yaxis.fraction) {
        return ndata;
    }

    if (ndata.some(function (d) { return d.sx != data[0].sx; })) {
        let maxy = {};
        ndata.forEach(function (d) { maxy[d[0]] = d[1] + d.yoff; });
        ndata.forEach(function (d) { d.yoff /= maxy[d[0]]; d[1] /= maxy[d[0]]; });
    } else {
        let maxy = 0;
        ndata.forEach(function (d) { maxy += d[1]; });
        ndata.forEach(function (d) { d.yoff /= maxy; d[1] /= maxy; });
    }
    return ndata;
}

function graph_bars(element, view) {
    const svg = this, args = view.args,
        bdata = data_to_barchart(args.data, args.y);

    const ystart = args.y.scale_class === "review_field" ? 0.75 : 0,
        xe = d3.extent(bdata, proj0),
        ge = d3.extent(bdata, function (d) { return d.sx || 0; }),
        ye = [d3.min(bdata, function (d) { return Math.max(d.yoff, ystart); }),
              d3.max(bdata, function (d) { return d.yoff + d[1]; })],
        deltae = d3.extent(bdata, function (d, i) {
            const delta = i ? d[0] - bdata[i-1][0] : 0;
            return delta || Infinity;
        }),
        x = make_linear_scale(view.x.extent, expand_extent(xe, args.x)),
        y = make_linear_scale(view.y.extent, ye);
    make_axis_pair(view, x, y);

    const dpr = window.devicePixelRatio || 1;
    let barwidth = view.plotWidth / 20;
    if (deltae[0] != Infinity) {
        barwidth = Math.min(barwidth, Math.abs(x(xe[0] + deltae[0]) - x(xe[0])));
    }
    barwidth = Math.max(5, barwidth);
    if (ge[1]) {
        barwidth = Math.floor((barwidth - 3) * dpr) / (dpr * (ge[1] + 1));
    }
    const gdelta = -(ge[1] + 1) * barwidth / 2;

    function place(sel, close) {
        close = close || "";
        return sel.attr("d", function (d) {
            const yoff = Math.max(d.yoff, ystart),
                x0 = x(d[0]) + gdelta + (d.sx ? barwidth * d.sx : 0),
                y0 = y(yoff),
                y1 = y(d.yoff + d[1]);
            return `M${x0},${y0}V${y1}h${barwidth}V${y0}${close}`;
        });
    }

    place(svg.selectAll(".gbar").data(bdata)
          .enter().append("path")
            .attr("class", function (d) {
                return d.cc ? "gbar " + d.cc : "gbar";
            })
            .style("fill", function (d) { return ensure_pattern(d.cc, "gdot"); }));

    draw_axes(svg, view);

    svg.append("path").attr("class", "gbar gbar-hover0");
    svg.append("path").attr("class", "gbar gbar-hover1");
    const hovers = svg.selectAll(".gbar-hover0, .gbar-hover1")
            .style("display", "none")
            .attr("pointer-events", "none"),
        hoverer = make_hover_interactor(svg, hovers, function (d1, d2) {
            return d1.i0 === d2.i0;
        });

    if (args.interactive !== false) {
        svg.selectAll(".gbar")
            .on("mouseover", mouseover)
            .on("mouseout", hoverer.mouseout_soon)
            .on("click", mouseclick);
    }

    function make_tooltip(p) {
        return [
            $e("p", null, render_position(args.x, p[0]), ", ", render_position(args.y, p[1])),
            render_pid_p(p.ids, p.cc)
        ];
    }

    function make_hovered_data(p) {
        const hd = {
            "0": p[0],
            "1": 0,
            ids: [],
            yoff: 0,
            cc: "",
            sx: p.sx,
            i0: p.i0
        };
        for (let i = p.i0, any = false, q;
             i !== bdata.length && (q = bdata[i]).i0 === p.i0;
             ++i) {
            if (q.sx !== p.sx) {
                continue;
            }
            if (!any) {
                hd.yoff = q.yoff;
                any = true;
            }
            hd[1] = q[1] + q.yoff - hd.yoff;
            for (let id of q.ids) {
                hd.ids.push(q.cc ? {id: id, cc: q.cc} : id);
            }
        }
        return hd;
    }

    function mouseover() {
        const p = d3.select(this).data()[0];
        if (!hoverer.move(p)) {
            return;
        }
        hoverer.data = make_hovered_data(p);
        place(hovers.datum(hoverer.data), "Z").style("display", null);
        hoverer.bubble.replace_content(...make_tooltip(hoverer.data))
            .anchor("h")
            .near(hovers.node());
    }

    function mouseclick(event) {
        clicker(hoverer.data ? hoverer.data.ids : null, event);
    }
}

function boxplot_sort(data) {
    data.sort(function (a, b) {
        return d3.ascending(a[0], b[0])
            || d3.ascending(a[1], b[1])
            || (a[3] || "").localeCompare(b[3] || "")
            || pid_sorter(a[2], b[2]);
    });
    return data;
}

function data_to_boxplot(data, septags) {
    data = boxplot_sort(data_quantize_x(data));

    let active = null;
    data = data.reduce(function (newdata, d) {
        if (!active || active[0] != d[0] || (septags && active.cc != d[3])) {
            active = {
                "0": d[0],
                ymin: d[1],
                ymax: 0,
                cc: d[3] || "",
                ys: [],
                ids: [],
                qnt: null,
                mean: null
            };
            newdata.push(active);
        } else if (active.cc != d[3]) {
            active.cc = "";
        }
        active.ymax = d[1];
        active.ys.push(d[1]);
        active.ids.push(d[2]);
        return newdata;
    }, []);

    data.map(function (d) {
        const l = d.ys.length, med = d3.quantile(d.ys, 0.5);
        if (l < BOXPLOT_IQR_MIN_N) {
            d.qnt = [d.ys[0], d.ys[0], med, d.ys[l-1], d.ys[l-1]];
        } else {
            const q1 = d3.quantile(d.ys, 0.25),
                q3 = d3.quantile(d.ys, 0.75),
                iqr = q3 - q1;
            d.qnt = [
                Math.max(d.ys[0], q1 - 1.5 * iqr),
                q1,
                med,
                q3,
                Math.min(d.ys[l-1], q3 + 1.5 * iqr)
            ];
        }
        d.mean = d3.sum(d.ys) / d.ys.length;
    });

    return data;
}

function graph_boxplot(element, view) {
    const args = view.args,
        data = data_to_boxplot(args.data, !!args.y.fraction, true),
        svg = this;

    const xe = d3.extent(data, proj0),
        ye = [d3.min(data, function (d) { return d.ymin; }),
              d3.max(data, function (d) { return d.ymax; })],
        deltae = d3.extent(data, function (d, i) {
            var delta = i ? d[0] - data[i-1][0] : 0;
            return delta || Infinity;
        }),
        x = make_linear_scale(view.x.extent, expand_extent(xe, args.x)),
        y = make_linear_scale(view.y.extent, expand_extent(ye, args.y));
    make_axis_pair(view, x, y);

    let barwidth = view.plotWidth / 80;
    if (deltae[0] != Infinity) {
        barwidth = Math.max(Math.min(barwidth, Math.abs(x(xe[0] + deltae[0]) - x(xe[0])) * 0.5), 6);
    }

    function place_whisker(l, sel) {
        sel.attr("x1", function (d) { return x(d[0]); })
            .attr("x2", function (d) { return x(d[0]); })
            .attr("y1", function (d) { return y(d.qnt[l]); })
            .attr("y2", function (d) { return y(d.qnt[l + 1]); });
    }

    function place_box(sel) {
        sel.attr("d", function (d) {
            const x0 = x(d[0]),
                yq2 = y(d.qnt[2]);
            let yq1 = y(d.qnt[1]), yq3 = y(d.qnt[3]);
            if (yq1 < yq3) {
                const tmp = yq3;
                yq3 = yq1;
                yq1 = tmp;
            }
            if (yq1 - yq3 < 4) {
                yq3 = yq2 - 2;
                yq1 = yq3 + 4;
            }
            yq3 = Math.min(yq3, yq2 - 1);
            yq1 = Math.max(yq1, yq2 + 1);
            return `M${x0 - barwidth / 2},${yq3}h${barwidth}v${yq1 - yq3}h${-barwidth}Z`;
        });
    }

    function place_median(sel) {
        sel.attr("x1", function (d) { return x(d[0]) - barwidth / 2; })
            .attr("x2", function (d) { return x(d[0]) + barwidth / 2; })
            .attr("y1", function (d) { return y(d.qnt[2]); })
            .attr("y2", function (d) { return y(d.qnt[2]); });
    }

    function place_outlier(sel) {
        sel.attr("cx", proj0).attr("cy", proj1)
            .attr("r", function (d) { return d.r; });
    }

    function place_mean(sel) {
        sel.attr("transform", function (d) { return "translate(" + x(d[0]) + "," + y(d.mean) + ")"; })
            .attr("d", "M2.2,0L0,2.2L-2.2,0L0,-2.2Z");
    }

    const nonoutliers = data.filter(function (d) { return d.ys.length > 1; });

    place_whisker(0, svg.selectAll(".gbox.whiskerl").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox whiskerl " + d.cc; }));

    place_whisker(3, svg.selectAll(".gbox.whiskerh").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox whiskerh " + d.cc; }));

    place_box(svg.selectAll(".gbox.box").data(nonoutliers)
            .enter().append("path")
            .attr("class", function (d) { return "gbox box " + d.cc; })
            .style("fill", function (d) { return ensure_pattern(d.cc, "gdot"); }));

    place_median(svg.selectAll(".gbox.median").data(nonoutliers)
            .enter().append("line")
            .attr("class", function (d) { return "gbox median " + d.cc; }));

    place_mean(svg.selectAll(".gbox.mean").data(nonoutliers)
            .enter().append("path")
            .attr("class", function (d) { return "gbox mean " + d.cc; }));

    let outliers = d3.merge(data.map(function (d) {
        const nd = [], len = d.ys.length;
        for (let i = 0; i < len; ++i) {
            if (d.ys[i] < d.qnt[0] || d.ys[i] > d.qnt[4] || len <= 1)
                nd.push([d[0], d.ys[i], d.ids[i], d.cc]);
        }
        return nd;
    }));
    outliers = grouped_quadtree(outliers, x, y, 2);
    place_outlier(svg.selectAll(".gbox.outlier")
            .data(outliers.data)
            .enter()
              .append("circle")
              .attr("class", function (d) { return "gbox outlier " + d.cc; })
              .style("fill", function (d) { return ensure_pattern(d.cc, "gdot"); }));

    draw_axes(svg, view);

    svg.append("line").attr("class", "gbox whiskerl gbox-hover");
    svg.append("line").attr("class", "gbox whiskerh gbox-hover");
    svg.append("path").attr("class", "gbox box gbox-hover");
    svg.append("line").attr("class", "gbox median gbox-hover");
    svg.append("circle").attr("class", "gbox outlier gbox-hover");
    svg.append("path").attr("class", "gbox mean gbox-hover");
    const hovers = svg.selectAll(".gbox-hover")
            .style("display", "none")
            .style("ponter-events", "none"),
        hoverer = make_hover_interactor(svg, hovers);

    element.addEventListener("hotgraphhighlight", ev => highlight(ev.detail),
        {signal: view.signal});

    if (args.interactive !== false) {
        element.addEventListener("mouseout", hoverer.mouseout_soon,
            {signal: view.signal});

        element.addEventListener("mouseover", function (event) {
            if (hasClass(event.target, "outlier")
                || hasClass(event.target, "gscatter"))
                mouseover_outlier.call(event.target);
            else if (hasClass(event.target, "gbox"))
                mouseover.call(event.target);
        }, {signal: view.signal});

        element.addEventListener("click", function (event) {
            if (hasClass(event.target, "gbox")
                || hasClass(event.target, "gscatter"))
                mouseclick.call(event.target, event);
        }, {signal: view.signal});
    }

    function make_tooltip(p) {
        const posd = p.qnt ? p : p.data[0],
            pe = $e("p", null, render_position(args.x, posd[0]), ", ");
        let ids;
        if (p.qnt) {
            pe.append(render_position(args.y, p.qnt[2], "median "));
            ids = [];
            for (let i = 0; i !== p.ys.length; ++i) {
                const rest = $frag(" (");
                args.y.value_render(rest, p.ys[i]);
                rest.append(")");
                ids.push({id: p.ids[i], rest: rest});
            }
        } else {
            pe.append(render_position(args.y, posd[1]));
            ids = gqdata_ids(p, true);
        }
        return [pe, render_pid_p(ids, p.cc)];
    }

    function mouseover() {
        const p = d3.select(this).data()[0];
        if (!hoverer.move(p)) {
            return;
        }
        hovers.style("display", "none");
        hovers.filter(":not(.outlier)").style("display", null).datum(p);
        place_whisker(0, hovers.filter(".whiskerl"));
        place_whisker(3, hovers.filter(".whiskerh"));
        place_box(hovers.filter(".box"));
        place_median(hovers.filter(".median"));
        place_mean(hovers.filter(".mean"));
        hoverer.bubble.replace_content(...make_tooltip(p))
            .anchor("h")
            .near(hovers.filter(".box").node());
    }

    function mouseover_outlier() {
        const po = d3.select(this).data()[0];
        if (!hoverer.move(po)) {
            return;
        }
        hovers.style("display", "none");
        place_outlier(hovers.filter(".outlier").style("display", null).datum(po));
        hoverer.bubble.replace_content(...make_tooltip(po))
            .anchor("h")
            .near(hovers.filter(".outlier").node());
    }

    function mouseclick(event) {
        let s;
        if (!hoverer.data) {
            clicker(null, event);
        } else if (!hoverer.data.qnt) {
            clicker(gqdata_ids(hoverer.data), event);
        } else if ((s = args.x.value_search(hoverer.data[0]))) {
            clicker_go(hoturl("search", {q: s}), event);
        } else {
            clicker(hoverer.data.ids, event);
        }
    }

    // see `graph_scatter`
    function highlight(hl) {
        if (!hl.ids) {
            if (hl.q && hl.ok) {
                api_get(hoturl("api/search", {q: hl.q, forceShow: 1}), highlight);
            }
            return;
        }
        hoverer.mouseout();
        // A box keeps the points it summarizes, so what to highlight is here
        // already. Marks group at the outliers' radius, since they are drawn
        // in the same language.
        const pts = [];
        for (const d of data) {
            for (let i = 0; i !== d.ys.length; ++i) {
                if (hl.ids.indexOf(id2pid(d.ids[i])) >= 0) {
                    pts.push([d[0], d.ys[i], d.ids[i], d.cc]);
                }
            }
        }
        if (!pts.length) {
            svg.selectAll(".gscatter").remove();
            return;
        }
        const gq = grouped_quadtree(pts, x, y, 2);
        scatter_create(svg, gq.data, "gscatter");
        scatter_highlight(svg, gq.data, "gscatter");
    }
}

/** Tell a graph which of its marks to highlight.
 * @param {*} target - the graph, or anything inside it
 * @param {string} q
 * @param {list<number>} ids */
function fire_highlight(target, q, ids) {
    target && target.dispatchEvent(new CustomEvent("hotgraphhighlight",
        {bubbles: true, detail: {ok: true, q: q, ids: ids}}));
}

handle_ui.on("js-hotgraph-highlight", function () {
    const s = this.value.trim(),
        box = this.closest(".has-hotgraph"),
        g = box && box.querySelector(".hotgraph"),
        view = graph_view_near(this);
    function fire(ids) {
        if (view) {
            // remembered, so a graph drawn again comes back highlighted
            view.highlight = s === "" ? null : {q: s, ids: ids};
        }
        fire_highlight(g, s, ids);
    }
    // Resolve the query to a pid list once, so every listener (graph marks and
    // data table) consumes the same resolved ids rather than each searching.
    if (s === "") {
        fire([]);
    } else if (/^[1-9][0-9]*$/.test(s)) {
        fire([+s]);
    } else {
        api_get(hoturl("api/search", {q: s, forceShow: 1}), function (data) {
            fire(data && data.ids ? data.ids : []);
        });
    }
});

/** A graph that could not be computed
 * @param {Element} element
 * @param {object} args */
function graph_blank(element, view) {
    const svg = this;
    make_axis_pair(view, d3.scaleLinear().domain([0, 1]),
        d3.scaleLinear().domain([0, 1]));
    draw_axes(svg, view);
}

function graph_blank_prepare(args) {
    args.x.tick_values = args.y.tick_values = () => [];
}

// `zoom` names the axes a graph type zooms and pans; both unless it says
// otherwise. A bar chart or a CDF measures its Y axis off its X axis -- counts
// and fractions of what the X axis shows -- so only X is the reader's to move.
const graphers = {
    procrastination: {prepare_function: procrastination_filter},
    scatter: {function: graph_scatter},
    dot: {function: graph_dot},
    ldot: {function: graph_dot},
    cdf: {function: graph_cdf, zoom: "x"},
    cumfreq: {function: graph_cdf, zoom: "x"},
    bar: {function: graph_bars, zoom: "x"},
    fraction: {function: graph_bars, zoom: "x"},
    box: {function: graph_boxplot},
    blank: {prepare_function: graph_blank_prepare, function: graph_blank, blank: true}
};

/** `expandable` names the dimensions of the graph box that may grow to fit
 * tick labels. Only height growth is implemented, so "width" degrades to
 * false and true to "height".
 * @param {null|boolean|string} e
 * @return {false|"height"} */
function normalize_expandable(e) {
    return e == null || e === true || e === "height" ? "height" : false;
}

/** Move the X axis label up to sit just under the tick labels it is beside.
 * `draw_axes` places it against the bottom of the box, which the deepest
 * label anywhere on the axis sets; tilted labels near the right-anchored
 * label are usually shorter, leaving a gap. With nothing beside it the label
 * follows the deepest label on the axis. Only ever moves up, so it cannot
 * leave the box.
 * @param {*} xaxe
 * @param {object} view */
function place_x_axis_label(xaxe, view) {
    const lab = xaxe.select("text.label");
    if (!view.args.x.label || lab.empty()) {
        return;
    }
    const lr = lab.node().getBoundingClientRect(),
        axr = xaxe.select("path").node().getBoundingClientRect();
    let beside = null, deepest = axr.bottom + TICK_SIZE;
    xaxe.selectAll("g.tick").each(function () {
        const t = this.style.display === "none" ? null : this.querySelector("text");
        if (!t) {
            return;
        }
        const r = t.getBoundingClientRect();
        if (r.height > 0) {
            deepest = Math.max(deepest, r.bottom);
            if (r.right > lr.left && r.left < lr.right) {
                beside = beside === null ? r.bottom : Math.max(beside, r.bottom);
            }
        }
    });
    const shift = Math.min(0, (beside ?? deepest) + LABEL_GAP - lr.top);
    if (shift < 0) {
        lab.attr("y", +lab.attr("y") + shift);
    }
}

/** Measure `texts` as the axis will render them, returning each width and the
 * common line height. The scratch nodes mirror the real structure
 * (`g.hg-axis[.widelabel] > g.tick > text`) so that `hg-axis`'s relative
 * `font-size: smaller` and the `.widelabel > .tick` shrink both resolve the
 * same way, and they live inside the graph's own SVG so `smaller` resolves
 * against the same parent.
 *
 * Every node is created before any is measured, so the measurements cost one
 * layout flush rather than one apiece; `visibility: hidden` keeps layout while
 * skipping paint.
 * @param {*} svg
 * @param {list<string>} texts
 * @param {boolean} [widelabel]
 * @return {{widths: list<number>, height: number, ascent: number, descent: number}} */
function measure_texts(svg, texts, widelabel) {
    if (texts.length === 0) {
        return {widths: [], height: 0, ascent: 0, descent: 0};
    }
    const g = $svg("g", {class: "hg-axis" + (widelabel ? " widelabel" : ""),
            transform: "translate(-10000,-10000)"}),
        nodes = texts.map(function (t) {
            const e = $svg("text", null, t);
            g.append($svg("g", "tick", e));
            return e;
        });
    g.style.visibility = "hidden";
    svg.node().appendChild(g);
    const widths = nodes.map(e => e.getComputedTextLength()),
        box = nodes[0].getBBox();
    g.remove();
    return {widths: widths, height: box.height,
        ascent: -box.y, descent: box.y + box.height};
}

/** Shorten every tick in `ticks` wider than `maxwidth`, appending an ellipsis
 * and keeping the full string for a `<title>`.
 *
 * Character count gives a good first guess at how much fits, so start there
 * and shrink until it does, measuring each round's outstanding candidates in
 * one batch.
 * @param {*} svg
 * @param {list<object>} ticks
 * @param {number} maxwidth
 * @param {boolean} widelabel */
function truncate_ticks(svg, ticks, maxwidth, widelabel) {
    if (!(maxwidth > 0)) {
        return;
    }
    let pending = ticks.filter(t => t.width > maxwidth);
    for (const t of pending) {
        t.n = Math.max(0, Math.floor(t.full.length * maxwidth / t.width) - 1);
    }
    for (let round = 0; round < 8 && pending.length !== 0; ++round) {
        const cand = pending.map(t => t.full.substring(0, t.n) + "\u2026"),
            m = measure_texts(svg, cand, widelabel),
            next = [];
        for (let i = 0; i !== pending.length; ++i) {
            const t = pending[i];
            t.text = cand[i];
            t.width = m.widths[i];
            if (t.width > maxwidth && t.n > 0) {
                t.n -= 1;
                next.push(t);
            }
        }
        pending = next;
    }
}

/** Width-to-height ratio named by `v`, or null if it names none. Accepts a
 * number or the CSS `aspect-ratio` spellings -- `auto`, `<w>`, `<w> / <h>`,
 * `auto <w> / <h>`. A non-replaced element takes the ratio wherever one
 * appears, so `auto` matters only in that it carries none.
 * @param {null|number|string} v
 * @return {?number} */
function parse_aspect_ratio(v) {
    if (typeof v === "number") {
        return v > 0 ? v : null;
    } else if (typeof v !== "string") {
        return null;
    }
    const m = v.match(/([\d.]+)(?:\s*\/\s*([\d.]+))?\s*$/);
    if (!m) {
        return null;
    }
    const w = parseFloat(m[1]), h = m[2] === undefined ? 1 : parseFloat(m[2]);
    return w > 0 && h > 0 ? w / h : null;
}

/** Pixels named by the CSS length `v`, or null if it names none: `auto`,
 * `none`, any keyword, or a percentage. Computed values reach here already in
 * px, percentages excepted; a percentage height resolves against a containing
 * block the graph generally sizes itself, so honoring one would feed this
 * graph's output back into its input. Use `vh` or `maxHeight: "viewport"`.
 * @param {null|number|string} v
 * @return {?number} */
function css_length(v) {
    if (typeof v === "number") {
        return isFinite(v) ? v : null;
    } else if (typeof v !== "string" || v.endsWith("%")) {
        return null;
    }
    const n = parseFloat(v);
    return isFinite(n) ? n : null;
}

/** Whether `element` has a box to draw in at all. False when it is
 * `display: none`, detached, or collapsed to nothing.
 * @param {Element} element
 * @return {boolean} */
function has_box(element) {
    return element.getBoundingClientRect().width > 0;
}

/** Derive the graph box from its container, returning view.box = [width, height].
 *
 * Width is read from the container. Height cannot be: the `<svg>` is the
 * container's only block child, so its computed height is whatever we drew
 * last time. The CSS sizing algorithm is re-run here instead, over a ratio and
 * limits taken from the container's own CSS and overridable through `args`.
 * `min-height` wins over `max-height`, as in CSS.
 *
 * The limits size the box; they do not cap `expandable`, which grows past them
 * so deep tick labels hang below the box rather than squashing the plot.
 * @param {Element} element
 * @param {object} args */
function compute_box(element, args) {
    const cs = window.getComputedStyle(element),
        // CSS lengths measure the box `box-sizing` names; ours is the content
        // box, which is what the `<svg>` fills
        fy = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom)
            + parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth),
        fx = parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight)
            + parseFloat(cs.borderLeftWidth) + parseFloat(cs.borderRightWidth),
        border_box = cs.boxSizing === "border-box",
        d = border_box ? fy : 0,
        // the border box less its frame
        width = Math.max(element.getBoundingClientRect().width - fx,
            MIN_BOX_WIDTH);

    /** @param {*} av - the `args` override
     * @param {string} cv - the container's computed value
     * @return {?number} */
    function limit(av, cv) {
        if (av == null) {
            const n = css_length(cv);
            return n === null ? null : n - d;
        } else if (av === "viewport") {
            // put the box bottom on the fold: the element's top in *document*
            // coordinates, so scrolling does not resize the graph, against the
            // layout viewport, which a mobile URL bar does not shift
            return document.documentElement.clientHeight
                - (element.getBoundingClientRect().top + window.scrollY) - fy;
        }
        return css_length(av);
    }

    let height = args.height;
    if (height == null) {
        const ratio = parse_aspect_ratio(args.aspectRatio ?? cs.aspectRatio)
            ?? DEFAULT_ASPECT_RATIO;
        height = (border_box ? width + fx : width) / ratio - d;
    }
    height = Math.round(Math.max(
        limit(args.minHeight, cs.minHeight) ?? 0, MIN_BOX_HEIGHT,
        Math.min(height, limit(args.maxHeight, cs.maxHeight) ?? Infinity)));
    return [width, height];
}

/* zoom and pan */

// One per graph, keyed by the element the graph was drawn into. A view holds
// the arguments as the caller gave them, since every stage of drawing edits
// what it is handed -- `project_data` rewrites `data` in place, the graphers
// sort and annotate it -- so a redraw has to start from a copy.
const graph_views = new WeakMap();
let clip_counter = 0;

/** @param {Element} e - an element beside or inside a graph
 * @return {?object} */
function graph_view_near(e) {
    const box = e.closest(".has-hotgraph"),
        g = box && box.querySelector(".hotgraph");
    return g ? graph_views.get(g.parentElement) : null;
}

/** Hold `w` inside `home`, no wider than `home` and no narrower than a
 * thousandth of it, keeping its center where it can.
 * @param {[number,number]} w
 * @param {[number,number]} home
 * @return {[number,number]} */
function clamp_axis_window(w, home) {
    const hspan = home[1] - home[0];
    if (hspan <= 0) {
        return home.slice();
    }
    const mid = (w[0] + w[1]) / 2,
        span = Math.min(Math.max(w[1] - w[0], hspan * ZOOM_MIN_FRACTION), hspan);
    return slide_axis_window([mid - span / 2, mid + span / 2], home);
}

/** Zoom one axis's window by `factor` about its center.
 * @param {[number,number]} w
 * @param {[number,number]} home
 * @param {number} factor
 * @return {[number,number]} */
function zoom_axis_window(w, home, factor) {
    const mid = (w[0] + w[1]) / 2, half = (w[1] - w[0]) * factor / 2;
    return clamp_axis_window([mid - half, mid + half], home);
}

/** Move `w` inside `home` without changing its span.
 * @param {[number,number]} w
 * @param {[number,number]} home
 * @return {[number,number]} */
function slide_axis_window(w, home) {
    const span = Math.min(w[1] - w[0], home[1] - home[0]);
    let lo = Math.max(w[0], home[0]);
    if (lo + span > home[1]) {
        lo = home[1] - span;
    }
    return [lo, lo + span];
}

/** The window an axis shows once its plot has been scaled by `k` about the
 * pixel `a` and that pixel has moved to `b` -- a drag where `k` is 1, a pinch
 * otherwise. `p0` and `p1` are the pixels at the ends of the axis.
 * @param {*} scale
 * @param {number} p0
 * @param {number} p1
 * @param {{k: [number,number], a: [number,number], b: [number,number]}} g
 * @param {number} i - 0 for the X axis, 1 for the Y
 * @param {[number,number]} home
 * @return {[number,number]} */
function gesture_axis_window(scale, p0, p1, g, i, home) {
    const v0 = scale.invert((p0 - g.b[i]) / g.k[i] + g.a[i]),
        v1 = scale.invert((p1 - g.b[i]) / g.k[i] + g.a[i]);
    return clamp_axis_window(v0 < v1 ? [v0, v1] : [v1, v0], home);
}

/** @param {object} view
 * @param {string} axis
 * @return {boolean} */
function zooms_axis(view, axis) {
    return view.zoom_axes.indexOf(axis) >= 0;
}

/** @param {object} view
 * @param {number} factor
 * @return {{x: [number,number], y: [number,number]}} */
function zoomed_window(view, factor) {
    return {
        x: zooms_axis(view, "x")
            ? zoom_axis_window(view.window.x, view.home.x, factor)
            : view.window.x.slice(),
        y: zooms_axis(view, "y")
            ? zoom_axis_window(view.window.y, view.home.y, factor)
            : view.window.y.slice()
    };
}

/** Compare to within rounding: a window that has been through
 * `zoom_axis_window` and back does not land on exactly the same endpoints.
 * @param {list<number>} a
 * @param {list<number>} b
 * @return {boolean} */
function same_axis_window(a, b) {
    const eps = Math.abs(b[1] - b[0]) * 1e-9;
    return Math.abs(a[0] - b[0]) <= eps && Math.abs(a[1] - b[1]) <= eps;
}

/** @param {{x: list<number>, y: list<number>}} w1
 * @param {{x: list<number>, y: list<number>}} w2
 * @return {boolean} */
function same_window(w1, w2) {
    return same_axis_window(w1.x, w2.x) && same_axis_window(w1.y, w2.y);
}

/** @param {object} view */
function update_zoom_buttons(view) {
    const box = view.element.closest(".has-hotgraph");
    for (const b of box ? box.querySelectorAll(".js-hotgraph-zoom") : []) {
        const factor = hasClass(b, "zoom-out") ? ZOOM_STEP : 1 / ZOOM_STEP;
        b.disabled = !view.window
            || same_window(zoomed_window(view, factor), view.window);
    }
}

/** Pixels the marks must move for `to` to show what `from` showed. A pan of a
 * linear scale is a translation, so this is exact -- and it is the pointer's
 * travel except where the window ran into the end of the graph.
 * @param {*} from
 * @param {*} to
 * @return {number} */
function scale_shift(from, to) {
    const v = from.domain()[0];
    return to(v) - from(v);
}

// A gesture that has not moved anything yet
const NO_GESTURE = {k: [1, 1], a: [0, 0], b: [0, 0]};

/** Show the marks and the pointer targets under gesture `g`. Scaling them is
 * what a pinch looks like in flight; the drawing that follows puts the marks
 * back at their own size.
 * @param {object} view
 * @param {{k: [number,number], a: [number,number], b: [number,number]}} g */
function place_marks(view, g) {
    const args = view.args, s = view.mark_shift,
        tx = view.left + g.k[0] * (s[0] - g.a[0]) + g.b[0],
        ty = view.top + g.k[1] * (s[1] - g.a[1]) + g.b[1],
        tr = `translate(${tx},${ty})`
            + (g.k[0] === 1 && g.k[1] === 1 ? "" : ` scale(${g.k[0]},${g.k[1]})`);
    view.svg.attr("transform", tr);
    view.overlay.attr("transform", tr);
}

/** Re-tick both axes for `w` and draw them again, leaving the marks alone.
 * `make_axis_pair` reads the plot edges it wrote last time as pins, so a
 * redraw holds the frame still unless the caller clears them first.
 * @param {object} view
 * @param {{x: list<number>, y: list<number>}} w */
function redraw_axes(view, w) {
    d3.select(view.svg.node().ownerSVGElement).selectAll("g.hg-axis").remove();
    make_axis_pair(view, d3.scaleLinear().domain(w.x), d3.scaleLinear().domain(w.y));
    draw_axes(view.svg, view);
}

/** Slide or pinch the plot, re-ticking the axes as it goes, then draw the
 * marks where they land. The marks ride a transform during the gesture rather
 * than being redrawn per frame -- a dot plot would have to re-run its
 * collision simulation, a CDF to re-path every series.
 *
 * A mouse drags with its button down; a touch takes two fingers, pinching to
 * zoom and sliding to pan, which leaves one finger free to scroll the page.
 * @param {object} view */
function enable_pan(view) {
    const args = view.args, svg = view.svg.node().ownerSVGElement;
    let base = null, active = false, at = NO_GESTURE, pending = false, frame = 0,
        expandable = null, mouse_start = null, pinch_start = null;

    /** A gesture scaling by `k` about plot point `a` and moving it to `b`,
     * with any axis this graph does not zoom held still.
     * @param {number} k
     * @param {[number,number]} a
     * @param {[number,number]} b
     * @return {object} */
    function gesture(k, a, b) {
        const zx = zooms_axis(view, "x"), zy = zooms_axis(view, "y");
        return {k: [zx ? k : 1, zy ? k : 1], a: a,
            b: [zx ? b[0] : a[0], zy ? b[1] : a[1]]};
    }
    /** @param {object} g
     * @return {{x: list<number>, y: list<number>}} */
    function window_at(g) {
        return {
            x: gesture_axis_window(base.x, 0, view.plotWidth, g, 0, view.home.x),
            y: gesture_axis_window(base.y, view.plotHeight, 0, g, 1, view.home.y)
        };
    }
    function draw_frame() {
        pending = false;
        redraw_axes(view, window_at(at));
        place_marks(view, at);
    }
    /** @param {object} g */
    function update(g) {
        at = g;
        if (!pending) {
            pending = true;
            frame = requestAnimationFrame(draw_frame);
        }
    }
    function begin() {
        base = {x: view.x.scale.copy(), y: view.y.scale.copy(), window: view.window};
        // a growable box would otherwise resize as the tick labels change
        expandable = view.expandable;
        view.expandable = false;
        at = NO_GESTURE;
        active = true;
        // a resize now would destroy the <svg> this gesture started on while
        // the handlers below still hold its `args` and scales
        view.gesturing = true;
        addClass(svg, "panning");
        // the pointer is about to leave whatever it was over without a
        // mouseout, so nothing else will take this down
        tooltip.close();
    }
    function finish() {
        if (pending) {
            cancelAnimationFrame(frame);
            pending = false;
        }
        removeClass(svg, "panning");
        view.expandable = expandable;
        active = false;
        view.gesturing = false;
        const g = at, slid = g.k[0] === 1 && g.k[1] === 1;
        if (slid && g.a[0] === g.b[0] && g.a[1] === g.b[1]) {
            flush_resize(view);
            return; // nothing moved
        }
        view.panned = true;
        view.window = window_at(g);
        // The frame stood still for the gesture; now let the axes take the
        // room their new labels need. A slide leaves the marks where the new
        // window wants them -- panning a linear scale is a translation -- so
        // they need drawing again only if the frame moved out from under them.
        // A pinch changes their size, so they always do.
        const frame0 = [view.top, view.right, view.bottom, view.left];
        redraw_axes(view, view.window);
        if (slid
            && frame0[0] === view.top
            && frame0[1] === view.right
            && frame0[2] === view.bottom
            && frame0[3] === view.left) {
            // measured from where the gesture began, not from the last frame
            view.mark_shift = [
                view.mark_shift[0] + scale_shift(base.x, view.x.scale),
                view.mark_shift[1] + scale_shift(base.y, view.y.scale)
            ];
            place_marks(view, NO_GESTURE);
        } else {
            render_view(view);
        }
        flush_resize(view);
    }

    function mousemove(evt) {
        const d = [evt.clientX - mouse_start[0], evt.clientY - mouse_start[1]];
        if (!active && Math.abs(d[0]) + Math.abs(d[1]) < PAN_THRESHOLD) {
            return;
        }
        if (!active) {
            begin();
        }
        update(gesture(1, [0, 0], d));
    }
    function mouseup() {
        document.removeEventListener("mousemove", mousemove);
        document.removeEventListener("mouseup", mouseup);
        if (active) {
            finish();
        }
    }

    /** Where two fingers are, as a plot-pixel midpoint and a spread.
     * @param {TouchEvent} evt
     * @return {{m: [number,number], d: number}} */
    function two_touches(evt) {
        const r = svg.getBoundingClientRect(),
            t0 = evt.touches[0], t1 = evt.touches[1];
        return {
            m: [(t0.clientX + t1.clientX) / 2 - r.left - view.left,
                (t0.clientY + t1.clientY) / 2 - r.top - view.top],
            d: Math.hypot(t1.clientX - t0.clientX, t1.clientY - t0.clientY)
        };
    }
    function touchmove(evt) {
        if (!active || evt.touches.length !== 2) {
            return;
        }
        evt.preventDefault(); // the page is not scrolling with two fingers here
        const t = two_touches(evt);
        update(gesture(pinch_start.d > 0 ? t.d / pinch_start.d : 1,
            pinch_start.m, t.m));
    }
    function touchend(evt) {
        if (evt.touches.length >= 2) {
            return;
        }
        svg.removeEventListener("touchmove", touchmove);
        svg.removeEventListener("touchend", touchend);
        svg.removeEventListener("touchcancel", touchend);
        if (active) {
            finish();
        }
    }

    addClass(svg, "pannable");
    svg.addEventListener("mousedown", function (evt) {
        if (evt.button !== 0) {
            return;
        }
        mouse_start = [evt.clientX, evt.clientY];
        active = view.panned = false;
        document.addEventListener("mousemove", mousemove);
        document.addEventListener("mouseup", mouseup);
        evt.preventDefault(); // no text selection, no native image drag
    });
    svg.addEventListener("touchstart", function (evt) {
        if (evt.touches.length !== 2) {
            return;
        }
        evt.preventDefault();
        pinch_start = two_touches(evt);
        view.panned = false;
        begin();
        svg.addEventListener("touchmove", touchmove, {passive: false});
        svg.addEventListener("touchend", touchend);
        svg.addEventListener("touchcancel", touchend);
    }, {passive: false});
}

/** Draw a graph, or draw it again after its window changed.
 * @param {object} view
 * @return {*} */
function render_view(view) {
    const element = view.element, t0 = performance.now();
    if (!has_box(element)) {
        // Hidden, folded away, or detached. Drawing now would trade a good
        // graph for a degenerate one, so keep what is there and wait for a
        // box -- which is also how a graph first drawn inside a hidden panel
        // comes out right once the panel opens.
        view.resize_stale = true;
        return null;
    }
    // after the filters, which build their own axes
    if (view.window) {
        (view.x = view.x || {}).extent = view.window.x.slice();
        (view.y = view.y || {}).extent = view.window.y.slice();
    }
    // The old drawing's handlers and tooltip go with it. Handlers bound to
    // the container outlive the <svg> they belong to, so every render gets a
    // signal that the next one aborts.
    view.abort && view.abort.abort();
    view.abort = new AbortController();
    view.signal = view.abort.signal;
    for (const t of document.querySelectorAll(".graphtip")) {
        t.remove();
    }
    element.replaceChildren();

    // the box `size_box` derived, before `make_axis_pair` grows it: that is
    // what `check_resize` recomputes to decide whether anything moved
    view.box = compute_box(element, view.args);
    view.resize_stale = false;
    const svg = d3.select(element).append("svg")
            .attr("class", "hotgraph")
            .attr("width", view.box[0])
            .attr("height", view.box[1]),
        clipid = `hg-clip-${++clip_counter}`;
    view.clip = svg.append("clipPath").attr("id", clipid).append("rect");
    view.svg = svg.append("g").attr("clip-path", `url(#${clipid})`).append("g");
    // pointer targets cover the margins, so they sit outside the clip
    view.overlay = svg.append("g");

    const g = graphers[view.args.gtype],
        result = g["function"].call(view.svg, element, view);

    view.zoom_axes = g.zoom ?? "xy";
    if (view.highlight) {
        fire_highlight(element, view.highlight.q, view.highlight.ids);
    }
    if (!g.blank) {
        view.window = view.window
            || {x: view.x.scale.domain().slice(), y: view.y.scale.domain().slice()};
        view.home = view.home || {x: view.window.x.slice(), y: view.window.y.slice()};
        view.mark_shift = [0, 0];
        if (view.args.interactive !== false) {
            enable_pan(view);
        }
    }
    update_zoom_buttons(view);
    view.render_ms = performance.now() - t0;
    return result;
}

/* auto-resize */

// One observer for every graph. A `ResizeObserver` holds its targets strongly,
// so one per graph would pin the container, its view, and its data for the
// life of the page; `check_element_resize` sweeps as it goes instead.
let resize_observer = null;
const resize_elements = new Set();

/** Redraw `view` if the box its container now implies differs from the one it
 * drew. The observer and the window listener are only hints; this is the test.
 *
 * It compares the *derived* box, never the observed one: the container's
 * height is this graph's own output, while what the box derives from -- the
 * width, the container's CSS in px, the viewport, the element's top -- this
 * graph does not affect.
 * @param {object} view */
function check_resize(view) {
    if (!has_box(view.element)) {
        view.resize_stale = true;
        return;
    }
    const b = compute_box(view.element, view.args);
    // `view.box` is null when nothing has been drawn yet: a graph first
    // rendered without a box, only now getting one
    if (!view.resize_stale
        && view.box
        && Math.abs(b[0] - view.box[0]) < RESIZE_THRESHOLD
        && Math.abs(b[1] - view.box[1]) < RESIZE_THRESHOLD) {
        return;
    }
    schedule_resize(view);
}

/** Draw `view` again for its new box: on the next frame if the last render was
 * cheap enough to keep up with a drag, once the box settles if it was not.
 * Never synchronously, which is what raises "ResizeObserver loop completed
 * with undelivered notifications".
 * @param {object} view */
function schedule_resize(view) {
    if (view.gesturing) {
        // the drag handlers hold the `args` and the scales this would replace
        view.resize_wanted = true;
    } else if (view.render_ms <= RESIZE_FRAME_BUDGET) {
        view.resize_frame = view.resize_frame
            || requestAnimationFrame(() => resize_view(view));
    } else {
        view.resize_timer && clearTimeout(view.resize_timer);
        view.resize_timer = setTimeout(() => resize_view(view), RESIZE_DEBOUNCE);
    }
}

/** @param {object} view */
function resize_view(view) {
    view.resize_frame = view.resize_timer = 0;
    if (view.gesturing) {
        view.resize_wanted = true;
    } else {
        render_view(view); // no-ops, and stays stale, if the box went away
    }
}

/** Act on a resize that arrived while a gesture was in flight. The gesture may
 * have ended in a redraw that already covers it, which the box test sees.
 * @param {object} view */
function flush_resize(view) {
    if (view.resize_wanted) {
        view.resize_wanted = false;
        check_resize(view);
    }
}

/** @param {Element} e */
function check_element_resize(e) {
    const view = graph_views.get(e);
    if (!view || !e.isConnected) {
        resize_elements.delete(e);
        resize_observer.unobserve(e);
    } else {
        check_resize(view);
    }
}

/** Follow `view`'s container.
 * @param {object} view */
function observe_resize(view) {
    if (!window.ResizeObserver) {
        return;
    }
    if (!resize_observer) {
        resize_observer = new ResizeObserver(function (entries) {
            for (const entry of entries) {
                check_element_resize(entry.target);
            }
        });
        // `vh` lengths and `maxHeight: "viewport"` follow the window, and a
        // window that gets shorter without getting narrower changes no
        // container's box, so the observer alone would never hear about it
        window.addEventListener("resize", function () {
            for (const e of resize_elements) {
                check_element_resize(e);
            }
        });
    }
    resize_elements.add(view.element);
    resize_observer.observe(view.element);
}

handle_ui.on("js-hotgraph-zoom", function () {
    const view = graph_view_near(this);
    if (!view || !view.window) {
        return;
    }
    const w = zoomed_window(view, hasClass(this, "zoom-out") ? ZOOM_STEP : 1 / ZOOM_STEP);
    if (!same_window(w, view.window)) {
        view.window = w;
        render_view(view);
    }
});

// `make_axis_pair` derives the plot rectangle from the measured ticks and
// labels plus the insets, except on any edge the caller pins.
function normalize_args(args) {
    args.expandable = normalize_expandable(args.expandable);
    args.insetTop = args.insetTop ?? INSET_TOP;
    args.insetRight = args.insetRight ?? INSET_RIGHT;
    args.insetBottom = args.insetBottom ?? INSET_BOTTOM;
    args.insetLeft = args.insetLeft ?? INSET_LEFT;
    args.x = instantiate_axis(args.x || {});
    args.y = instantiate_axis(args.y || {});
    if (args.xorder) {
        args.xorder = instantiate_axis(args.xorder);
    }
    project_data(args, args);
    // Other arguments:
    // args.aspectRatio: box width divided by height, as in CSS; overrides the
    //   container's `aspect-ratio`, whose `auto` means DEFAULT_ASPECT_RATIO
    // args.height: box height, used instead of the ratio; still clamped
    // args.minHeight, args.maxHeight: clamp the box, overriding the
    //   container's; min wins, as in CSS, and neither caps `expandable`.
    //   `maxHeight: "viewport"` keeps the box bottom above the fold.
    // args.expandable: false or "height" (see `normalize_expandable`)
    // args.interactive: Set to false to disable pointer interaction
    // args.autoresize: Set to false to stop following the container's box
    // args.insetTop, args.insetRight, args.insetBottom, args.insetLeft:
    //   override the INSET_* defaults for this graph
    // args.plotTop, args.plotRight, args.plotBottom, args.plotLeft: pin an
    //   edge of the plot rectangle, in box coordinates, so that a grid of
    //   graphs can share one plotting area; a pin supersedes its inset, and
    //   the ink that inset located follows the plot edge instead. See
    //   `make_axis_pair`.
}

function make_graph(selector, args) {
    const element = typeof selector === "string"
        ? document.querySelector(selector) : selector;
    if (!element) {
        return null;
    }
    if (!d3) {
        const erre = $e("div", "msg-error");
        feedback.append_item_near(erre, {message: "<0>Graphs are not supported on this browser", status: 2});
        element.append(erre);
        return null;
    }

    // build args
    let g = graphers[args.gtype];
    while (g && g.prepare_function) {
        const vargs = g.prepare_function(args);
        if (!vargs || vargs === args) {
            break;
        }
        args = vargs;
        g = graphers[args.gtype];
    }
    if (!g) {
        return null;
    }
    normalize_args(args);

    // build view
    const view = {
        element: element, args: Object.freeze(args),
        x: {}, y: {}, expandable: args.expandable,
        window: null, home: null,
        panned: false, mark_shift: [0, 0], highlight: null,
        box: null, abort: null, render_ms: 0, gesturing: false,
        resize_stale: false, resize_wanted: false,
        resize_frame: 0, resize_timer: 0
    };
    graph_views.set(element, view);
    // a pan ends over the redrawn graph; that click is not the user's
    element.addEventListener("click", function (evt) {
        if (view.panned) {
            view.panned = false;
            evt.stopPropagation();
            evt.preventDefault();
        }
    }, true);
    const result = render_view(view);
    if (args.autoresize !== false) {
        observe_resize(view);
    }
    return result;
}

return make_graph;
})(window.d3);
