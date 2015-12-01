// graph.js -- HotCRP JavaScript library for graph drawing
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

var hotcrp_graphs = (function ($, d3) {
var BOTTOM_MARGIN = 30;

function pathNodeMayBeNearer(pathNode, point, dist) {
    function oob(l, t, r, b) {
        return l - point[0] >= dist || point[0] - r >= dist
            || t - point[1] >= dist || point[1] - b >= dist;
    }
    // check bounding rectangle of path
    if ("clientX" in point) {
        var bounds = pathNode.getBoundingClientRect();
        var dx = point[0] - point.clientX, dy = point[1] - point.clientY;
        if (oob(bounds.left + dx, bounds.top + dy,
                bounds.right + dx, bounds.bottom + dy))
            return false;
    }
    // check path
    var npsl = pathNode.normalizedPathSegList || pathNode.pathSegList;
    var xo, yo, x0, y0, x, y, l, t, r, b, found;
    for (var i = 0; i < npsl.numberOfItems && !found; ++i) {
        var item = npsl.getItem(i);
        if (item.pathSegType == 1)
            x = xo, y = yo;
        else
            x = item.x, y = item.y;
        if (item.pathSegType == 2) {
            xo = l = r = x;
            yo = t = b = y;
        } else if (item.pathSegType == 6) {
            l = Math.min(x0, x, item.x1, item.x2);
            t = Math.min(y0, y, item.y1, item.y2);
            r = Math.max(x0, x, item.x1, item.x2);
            b = Math.max(y0, y, item.y1, item.y2);
        } else if (item.pathSegType == 1 || item.pathSegType == 4) {
            l = Math.min(x0, x);
            t = Math.min(y0, y);
            r = Math.max(x0, x);
            b = Math.max(y0, y);
        } else
            return true;
        if (!oob(l, t, r, b))
            return true;
        x0 = x, y0 = y;
    }
    return false;
}

function closestPoint(pathNode, point, inbest) {
    // originally from Mike Bostock http://bl.ocks.org/mbostock/8027637
    var pathLength = pathNode.getTotalLength(),
        precision = pathLength / pathNode.pathSegList.numberOfItems * .125,
        best, bestLength, bestDistance2 = Infinity;

    if (inbest && !pathNodeMayBeNearer(pathNode, point, inbest.distance))
        return inbest;

    function check(pLength) {
        var p = pathNode.getPointAtLength(pLength);
        var dx = point[0] - p.x, dy = point[1] - p.y, d2 = dx * dx + dy * dy;
        if (d2 < bestDistance2) {
            best = [p.x, p.y];
            best.pathNode = pathNode;
            bestLength = pLength;
            bestDistance2 = d2;
            return true;
        } else
            return false;
    }

    // linear scan for coarse approximation
    var sl;
    for (sl = 0; sl <= pathLength; sl += precision)
        check(sl);

    // binary search for precise estimate
    precision *= .5;
    while (precision > .5) {
        if (!((sl = bestLength - precision) >= 0 && check(sl))
            && !((sl = bestLength + precision) <= pathLength && check(sl)))
            precision *= .5;
    }

    best.distance = Math.sqrt(bestDistance2);
    best.pathLength = bestLength;
    return inbest && inbest.distance < best.distance + 0.01 ? inbest : best;
}

function tangentAngle(pathNode, length) {
    var length0 = Math.max(0, length - 0.25);
    if (length0 == length)
        length += 0.25;
    var p0 = pathNode.getPointAtLength(length0),
        p1 = pathNode.getPointAtLength(length);
    return Math.atan2(p1.y - p0.y, p1.x - p0.x);
}


/* CDF functions */
function submission_delay_seq(ri) {
    var seq = [], i;
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - ri[i][0]) / 86400);
    seq.ntotal = ri.length;
    return seq;
}
submission_delay_seq.label = function (dl) {
    return "Days after assignment";
};

function procrastination_seq(ri, dl) {
    var seq = [], i;
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - dl[ri[i][2]]) / 86400);
    seq.ntotal = ri.length;
    return seq;
}
procrastination_seq.label = function (dl) {
    return dl.length > 1 ? "Days until round deadline" : "Days until deadline";
};

function max_procrastination_seq(ri, dl) {
    var seq = [], i, dlx = Math.max.apply(null, dl);
    for (i in ri)
        if (ri[i][1] > 0)
            seq.push((ri[i][1] - dlx) / 86400);
    seq.ntotal = ri.length;
    return seq;
}
max_procrastination_seq.label = function (dl) {
    return dl.length > 1 ? "Days until maximum deadline" : "Days until deadline";
};
procrastination_seq.tick_format = max_procrastination_seq.tick_format =
    function (x) { return -x; };

function seq_to_cdf(seq) {
    var cdf = [], i, n = seq.ntotal || seq.length;
    seq.sort(d3.ascending);
    for (i = 0; i <= seq.length; ++i) {
        if (i != 0 && (i == seq.length || seq[i-1] != seq[i]))
            cdf.push([seq[i-1], i/n]);
        if (i != seq.length && (i == 0 || seq[i-1] != seq[i]))
            cdf.push([seq[i], i/n]);
    }
    cdf.cdf = true;
    return cdf;
}


function expand_extent(e, delta) {
    if (e[1] - e[0] >= 10 && e[0] == 1)
        return [0, e[1]];
    else if (e[1] - e[0] < 10)
        return [e[0] - delta, e[1] + delta];
    else
        return e;
}


function make_axes(svg, width, height, xAxis, yAxis, args) {
    var css = {"text-anchor": "end", "font-size": "smaller", "pointer-events": "none"};

    svg.append("g")
        .attr("class", "x axis")
        .attr("transform", "translate(0," + height + ")")
        .call(xAxis).call(args.xaxis_setup || function () {})
      .append("text")
        .attr("x", width).attr("y", 0).attr("dy", "-.5em")
        .style(css).text(args.xlabel || "");

    svg.append("g")
        .attr("class", "y axis")
        .call(yAxis).call(args.yaxis_setup || function () {})
      .append("text")
        .attr("transform", "rotate(-90)")
        .attr("y", 6).attr("dy", ".71em")
        .style(css).text(args.ylabel || "");

    if (args.xticks && args.xticks.rewrite)
        args.xticks.rewrite(svg.select(".x.axis"), svg);
    if (args.yticks && args.yticks.rewrite)
        args.yticks.rewrite(svg.select(".y.axis"), svg);
}

function pid_sorter(a, b) {
    var d = (typeof a === "string" ? parseInt(a, 10) : a) -
            (typeof b === "string" ? parseInt(b, 10) : b);
    return d ? d : (a < b ? -1 : (a == b ? 0 : 1));
}

function clicker(pids) {
    var m, x, i, url;
    if (!pids)
        return;
    for (i = 0, x = []; i < pids.length; ++i) {
        m = parseInt(pids[i], 10);
        if (!x.length || x[x.length - 1] != m)
            x.push(m);
    }
    if (x.length == 1 && pids.length == 1 && /[A-Z]$/.test(pids[0]))
        url = hoturl("paper", {p: x[0], anchor: "r" + pids[0]});
    else if (x.length == 1)
        url = hoturl("paper", {p: x[0]});
    else
        url = hoturl("search", {q: x.join(" ")});
    if (d3.event.metaKey)
        window.open(url, "_blank");
    else
        window.location = url;
}


/* actual graphs */
var hotcrp_graphs = {};

// args: {selector: JQUERYSELECTOR,
//        series: [{d: [ARRAY], label: STRING, className: STRING}],
//        xlabel: STRING, ylabel: STRING, xtick_format: STRING}
function hotcrp_graphs_cdf(args) {
    var margin = {top: 20, right: 20, bottom: BOTTOM_MARGIN, left: 50},
        width = $(args.selector).width() - margin.left - margin.right,
        height = 500 - margin.top - margin.bottom;

    var x = d3.scale.linear().range([0, width]);
    var y = d3.scale.linear().range([height, 0]);

    var svg = d3.select(args.selector).append("svg")
        .attr("width", width + margin.left + margin.right)
        .attr("height", height + margin.top + margin.bottom)
      .append("g")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    // massage data
    var series = args.series;
    if (!series.length) {
        series = d3.values(series);
        series.sort(function (a, b) {
            return d3.ascending(a.priority || 0, b.priority || 0);
        });
    }
    series = series.filter(function (d) {
        return (d.d ? d.d : d).length > 0;
    });
    var data = series.map(function (d) {
        d = d.d ? d.d : d;
        return d.cdf ? d : seq_to_cdf(d);
    });

    // axis domains
    var i = data.reduce(function (e, d) {
        e[0] = Math.min(e[0], d[0][0]);
        e[1] = Math.max(e[1], d[d.length - 1][0]);
        return e;
    }, [Infinity, -Infinity]);
    x.domain([i[0] - (i[1] - i[0])/32, i[1] + (i[1] - i[0])/32]);
    var i = d3.max(data, function (d) { return d[d.length - 1][1]; });
    y.domain([0, Math.ceil(i * 10) / 10]);

    // axes
    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    args.xticks && args.xticks(xAxis, x.domain());
    args.xtick_format && xAxis.tickFormat(args.xtick_format);
    var yAxis = d3.svg.axis().scale(y).orient("left");
    var line = d3.svg.line().x(function (d) {return x(d[0]);})
        .y(function (d) {return y(d[1]);});

    // CDF lines
    var xmax = x.domain()[1];
    data.forEach(function (d, i) {
        var klass = "gcdf";
        if (series[i].className)
            klass += " " + series[i].className;
        if (d[d.length - 1][0] != xmax)
            d.push([xmax, d[d.length - 1][1]]);
        svg.append("path").attr("dataindex", i)
            .datum(d)
            .attr("class", klass)
            .attr("d", line);
    });

    svg.append("path").attr("class", "gcdf gcdf_hover0");
    svg.append("path").attr("class", "gcdf gcdf_hover1");
    var hovers = svg.selectAll(".gcdf_hover0, .gcdf_hover1");
    hovers.style("display", "none");

    make_axes(svg, width, height, xAxis, yAxis, args);

    svg.append("rect").attr("x", -margin.left).attr("width", width + margin.left)
        .attr("height", height + margin.bottom)
        .style({"fill": "none", "pointer-events": "all"})
        .on("mouseover", mousemoved).on("mousemove", mousemoved)
        .on("mouseout", mouseout);

    var hovered_path, hubble;
    function mousemoved() {
        var m = d3.mouse(this), p = {distance: 16};
        m.clientX = d3.event.clientX;
        m.clientY = d3.event.clientY;
        for (i in data)
            if (series[i].label)
                p = closestPoint(svg.select("[dataindex='" + i + "']").node(), m, p);
        if (p.pathNode != hovered_path) {
            if (p.pathNode)
                hovers.datum(data[p.pathNode.getAttribute("dataindex")])
                    .attr("d", line).style("display", null);
            else
                hovers.style("display", "none");
            hovered_path = p.pathNode;
        }
        var u = p.pathNode ? series[p.pathNode.getAttribute("dataindex")] : null;
        if (u && u.label) {
            hubble = hubble || make_bubble("", {color: "tooltip", "pointer-events": "none"});
            var dir = Math.abs(tangentAngle(p.pathNode, p.pathLength));
            hubble.text(u.label)
                .dir(dir >= 0.25*Math.PI && dir <= 0.75*Math.PI ? "h" : "b")
                .at(p[0] + margin.left, p[1], this);
        } else if (hubble)
            hubble = hubble.remove() && null;
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_path = hubble = null;
    }
};
hotcrp_graphs.cdf = hotcrp_graphs_cdf;


hotcrp_graphs.procrastination = function (selector, revdata) {
    var args = {selector: selector, series: {}};

    // collect data
    var alldata = [], d, i, l, cid, u;
    for (cid in revdata.reviews) {
        var d = {d: revdata.reviews[cid]};
        if ((u = revdata.users[cid]) && u.name)
            d.label = u.name;
        if (cid && cid == hotcrp_user.cid) {
            d.className = "revtimel_hilite";
            d.priority = 1;
        } else if (u && u.light)
            d.className = "revtimel_light";
        Array.prototype.push.apply(alldata, d.d);
        if (cid !== "conflicts")
            args.series[cid] = d;
    }
    args.series.all = {d: alldata, className: "revtimel_all", priority: 2};

    var dlf = max_procrastination_seq;

    // infer deadlines when not set
    if (dlf != submission_delay_seq) {
        for (i in revdata.deadlines)
            if (!revdata.deadlines[i]) {
                var subat = alldata.filter(function (d) { return d[2] == i; })
                    .map(function (d) { return d[0]; });
                subat.sort(d3.ascending);
                revdata.deadlines[i] = subat.length ? d3.quantile(subat, 0.8) : 0;
            }
    }
    // make cdfs
    for (i in args.series)
        args.series[i].d = seq_to_cdf(dlf(args.series[i].d, revdata.deadlines));

    if (dlf.tick_format)
        args.xtick_format = dlf.tick_format;
    args.xlabel = dlf.label(revdata.deadlines);
    args.ylabel = "Fraction of assignments completed";

    hotcrp_graphs_cdf(args);
};


/* grouped quadtree */
// mark bounds of each node
function grouped_quadtree_mark_bounds(q, ordinalf) {
    var b = [Infinity, -Infinity, -Infinity, Infinity], p, i;
    ordinalf = ordinalf || (function () { var m = 0; return function () { return ++m; }; })();
    q.ordinal = ordinalf();
    for (p = q.point; p; p = p.next)
        if (q.point.maxr == null || p.r > q.point.maxr) {
            b[0] = Math.min(b[0], p[1] - p.r);
            b[1] = Math.max(b[1], p[0] + p.r);
            b[2] = Math.max(b[2], p[1] + p.r);
            b[3] = Math.min(b[3], p[0] - p.r);
            q.point.maxr = p.r;
        }
    for (i = 0; i < 4; ++i)
        if ((p = q.nodes[i])) {
            grouped_quadtree_mark_bounds(p, ordinalf);
            b[0] = Math.min(b[0], p.bounds[0]);
            b[1] = Math.max(b[1], p.bounds[1]);
            b[2] = Math.max(b[2], p.bounds[2]);
            b[3] = Math.min(b[3], p.bounds[3]);
        }
    q.bounds = b;
}

function grouped_quadtree_gfind(point, min_distance) {
    var q = this, closest = null;
    if (min_distance == null)
        min_distance = Infinity;
    function visitor(node) {
        var p;
        if (node.bounds[0] > point[1] + min_distance
            || node.bounds[1] < point[0] - min_distance
            || node.bounds[2] < point[1] - min_distance
            || node.bounds[3] > point[0] + min_distance)
            return true;
        if (!(p = node.point))
            return;
        var dx = p[0] - point[0], dy = p[1] - point[1];
        if (Math.abs(dx) - p.maxr < min_distance
            || Math.abs(dy) - p.maxr < min_distance) {
            var dd = Math.sqrt(dx * dx + dy * dy);
            for (; p; p = p.next) {
                var d = Math.max(dd - p.r, 0);
                if (d < min_distance || (d == 0 && p.r < closest.r))
                    closest = p, min_distance = d;
            }
        }
    }
    this.visit(visitor);
    return closest;
}

function grouped_quadtree(data, xs, ys, rf) {
    function make_extent() {
        var xe = xs.range(), ye = ys.range();
        return [[Math.min(xe[0], xe[1]), Math.min(ye[0], ye[1])],
                [Math.max(xe[0], xe[1]), Math.max(ye[0], ye[1])]];
    }
    var q = d3.geom.quadtree().extent(make_extent())([]),
        d, nd = [], vp, vd, dx, dy;
    if (rf == null)
        rf = function (n) { return Math.sqrt(n); };
    else if (typeof rf === "number")
        rf = (function (f) {
            return function (n) { return Math.sqrt(n) * f; };
        })(rf);
    for (var i = 0; (d = data[i]); ++i) {
        if (d[0] == null || d[1] == null)
            continue;
        vd = [xs(d[0]), ys(d[1]), [d[2]], d[3]];
        if ((vp = q.find(vd))) {
            dx = Math.abs(vp[0] - vd[0]);
            dy = Math.abs(vp[1] - vd[1]);
            if (dx > 2 || dy > 2 || dx * dx + dy * dy > 4)
                vp = null;
        }
        while (vp && vp[3] != vd[3] && vp.next)
            vp = vp.next;
        if (vp && vp[3] == vd[3]) {
            vp[2].push(d[2]);
            vp.n += 1;
            vp.r = rf(vp.n);
        } else {
            vp ? vp.next = vd : q.add(vd);
            vd.n = 1;
            nd.push(vd);
            vd.r = rf(vd.n);
        }
    }
    grouped_quadtree_mark_bounds(q);
    delete q.add;
    q.gfind = grouped_quadtree_gfind;
    return {data: nd, quadtree: q};
}

function data_to_scatter(data) {
    if (!$.isArray(data)) {
        for (var i in data)
            i && data[i].forEach(function (d) { d.push(i); });
        data = d3.merge(d3.values(data));
    }
    return data;
}

hotcrp_graphs.scatter = function (args) {
    var margin = {top: 20, right: 20, bottom: BOTTOM_MARGIN, left: 50},
        width = $(args.selector).width() - margin.left - margin.right,
        height = 500 - margin.top - margin.bottom,
        data = data_to_scatter(args.data);

    var xe = d3.extent(data, function (d) { return d[0]; }),
        ye = d3.extent(data, function (d) { return d[1]; }),
        x = d3.scale.linear().range(args.xflip ? [width, 0] : [0, width])
                .domain(expand_extent(xe, 0.3)),
        y = d3.scale.linear().range(args.yflip ? [0, height] : [height, 0])
                .domain(expand_extent(ye, 0.3)),
        rf = function (d) { return d.r - 1; };
    data = grouped_quadtree(data, x, y, 4);

    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    args.xticks && args.xticks(xAxis, xe);
    var yAxis = d3.svg.axis().scale(y).orient("left");
    args.yticks && args.yticks(yAxis, ye);

    var svg = d3.select(args.selector).append("svg")
        .attr("width", width + margin.left + margin.right)
        .attr("height", height + margin.top + margin.bottom)
      .append("g")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    function place(sel) {
        return sel.attr("r", rf)
            .attr("cx", function (d) { return d[0]; })
            .attr("cy", function (d) { return d[1]; });
    }

    place(svg.selectAll(".gdot").data(data.data)
          .enter().append("circle")
            .attr("class", function (d) {
                return d[3] ? "gdot " + d[3] : "gdot";
            })
            .style("fill", function (d) { return make_pattern_fill(d[3], "gdot "); }));

    svg.append("circle").attr("class", "gdot gdot_hover0");
    svg.append("circle").attr("class", "gdot gdot_hover1");
    var hovers = svg.selectAll(".gdot_hover0, .gdot_hover1").style("display", "none");

    make_axes(svg, width, height, xAxis, yAxis, args);

    svg.append("rect").attr("x", -margin.left).attr("width", width + margin.left)
        .attr("height", height + margin.bottom)
        .style({"fill": "none", "pointer-events": "all"})
        .on("mouseover", mousemoved).on("mousemove", mousemoved)
        .on("mouseout", mouseout).on("click", mouseclick);

    var hovered_data, hubble;
    function mousemoved() {
        var m = d3.mouse(this), p = data.quadtree.gfind(m, 4);
        if (p != hovered_data) {
            if (p)
                place(hovers.datum(p)).style("display", null);
            else
                hovers.style("display", "none");
            svg.style("cursor", p ? "pointer" : null);
            hovered_data = p;
        }
        if (p) {
            hubble = hubble || make_bubble("", {color: "tooltip", "pointer-events": "none"});
            if (!p.sorted) {
                p[2].sort(pid_sorter);
                p.sorted = true;
            }
            hubble.html("<p>#" + p[2].join(", #") + "</p>")
                .dir("b").near(hovers.node());
        } else if (hubble)
            hubble = hubble.remove() && null;
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_data = hubble = null;
    }

    function mouseclick() {
        clicker(hovered_data ? hovered_data[2] : null);
    }
};

function data_quantize_x(data) {
    if (data.length) {
        data.sort(function (a, b) { return d3.ascending(a[0], b[0]); });
        var epsilon = (data[data.length - 1][0] - data[0][0]) / 5000, active = null;
        data.forEach(function (d) {
            if (active !== null && Math.abs(active - d[0]) <= epsilon)
                d[0] = active;
            else
                active = d[0];
        });
    }
    return data;
}

function data_sort(data) {
    data.sort(function (a, b) {
        return d3.ascending(a[0], b[0]) || d3.ascending(a[1], b[1])
            || (a[3] || "").localeCompare(b[3] || "")
            || pid_sorter(a[2], b[2]);
    });
    return data;
}

function data_to_barchart(data, isfraction, septags) {
    data = data_sort(data_quantize_x(data_to_scatter(data)));

    var active = null, count = 0;
    data = data.reduce(function (newdata, d) {
        if (active && (active[0] != d[0] || active[1] != d[1]))
            active = null;
        if (!active || (septags && active[4] != d[3])) {
            var count = active ? active[3] : 0;
            active = [d[0], d[1], count, count, d[3], []];
            newdata.push(active);
        } else if (active[4] != d[3])
            active[4] = null;
        ++active[3];
        active[5].push(d[2]);
        return newdata;
    }, []);

    if (isfraction) {
        var maxy = {};
        data.forEach(function (d) { maxy[d[0]] = d[3]; });
        data.forEach(function (d) { d[2] /= maxy[d[0]]; d[3] /= maxy[d[0]]; });
    }
    return data;
}

hotcrp_graphs.barchart = function (args) {
    var margin = {top: 20, right: 20, bottom: BOTTOM_MARGIN, left: 50},
        width = $(args.selector).width() - margin.left - margin.right,
        height = 500 - margin.top - margin.bottom,
        data = data_to_barchart(args.data, !!args.yfraction, true);

    var xe = d3.extent(data, function (d) { return d[0]; }),
        ge = d3.extent(data, function (d) { return d[1]; }),
        ye = [0, d3.max(data, function (d) { return d[3]; })],
        deltae = d3.extent(data, function (d, i) {
            var delta = i ? d[0] - data[i-1][0] : 0;
            return delta || Infinity;
        }),
        x = d3.scale.linear().range(args.xflip ? [width, 0] : [0, width])
                .domain(expand_extent(xe, 0.2)),
        y = d3.scale.linear().range(args.yflip ? [0, height] : [height, 0])
                .domain(ye);

    var barwidth = width/20;
    if (deltae[0] != Infinity)
        barwidth = Math.max(Math.min(barwidth, Math.abs(x(xe[0] + deltae[0]) - x(xe[0]))), 10);
    var gdelta = -(ge[1] + 1) * barwidth / 2;

    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    args.xticks && args.xticks(xAxis, xe);
    var yAxis = d3.svg.axis().scale(y).orient("left");
    args.yticks && args.yticks(yAxis, ye);

    var svg = d3.select(args.selector).append("svg")
        .attr("width", width + margin.left + margin.right)
        .attr("height", height + margin.top + margin.bottom)
      .append("g")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    function place(sel, close) {
        return sel.attr("d", function (d) {
            return ["M", x(d[0]) + gdelta + barwidth * d[1], y(d[2]),
                    "V", y(d[3]), "h", barwidth,
                    "V", y(d[2])].join(" ") + (close || "");
        });
    }

    place(svg.selectAll(".gbar").data(data)
          .enter().append("path")
            .attr("class", function (d) {
                return d[4] ? "gbar " + d[4] : "gbar";
            })
            .style("fill", function (d) { return make_pattern_fill(d[4], "gdot "); }));

    make_axes(svg, width, height, xAxis, yAxis, args);

    svg.append("path").attr("class", "gbar gbar_hover0");
    svg.append("path").attr("class", "gbar gbar_hover1");
    var hovers = svg.selectAll(".gbar_hover0, .gbar_hover1")
        .style("display", "none").style("pointer-events", "none");

    svg.selectAll(".gbar").on("mouseover", mouseover).on("mouseout", mouseout)
        .on("click", mouseclick);

    var hovered_data, hubble;
    function mouseover() {
        var p = d3.select(this).data()[0];
        if (p != hovered_data) {
            if (p)
                place(hovers.datum(p), "Z").style("display", null);
            else
                hovers.style("display", "none");
            svg.style("cursor", p ? "pointer" : null);
            hovered_data = p;
        }
        if (p) {
            hubble = hubble || make_bubble("", {color: "tooltip", "pointer-events": "none"});
            if (!p.sorted) {
                p[5].sort(pid_sorter);
                p.sorted = true;
            }
            hubble.html("<p>#" + p[5].join(", #") + "</p>")
                .dir("l").near(this);
        }
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_data = hubble = null;
    }

    function mouseclick() {
        clicker(hovered_data ? hovered_data[5] : null);
    }
};

function data_to_boxplot(data, septags) {
    data = data_sort(data_quantize_x(data_to_scatter(data)));

    var active = null, count = 0;
    data = data.reduce(function (newdata, d) {
        if (!active || active[0] != d[0] || (septags && active[4] != d[3])) {
            active = {"0": d[0], ymin: d[1], c: d[3], d: [], p: []};
            newdata.push(active);
        } else if (active[2] != d[3])
            active.c = null;
        active.ymax = d[1];
        active.d.push(d[1]);
        active.p.push(d[2]);
        return newdata;
    }, []);

    data.map(function (d) {
        d.q = [d.d[0], d3.quantile(d.d, 0.25), d3.quantile(d.d, 0.5),
               d3.quantile(d.d, 0.75), d.d[d.d.length - 1]];
        if (d.d.length > 20) {
            d.q[0] = d3.quantile(d.d, 0.05);
            d.q[4] = d3.quantile(d.d, 0.95);
        }
    });

    return data;
}

hotcrp_graphs.boxplot = function (args) {
    var margin = {top: 20, right: 20, bottom: BOTTOM_MARGIN, left: 50},
        width = $(args.selector).width() - margin.left - margin.right,
        height = 500 - margin.top - margin.bottom,
        data = data_to_boxplot(args.data, !!args.yfraction, true);

    var xe = d3.extent(data, function (d) { return d[0]; }),
        ye = [d3.min(data, function (d) { return d.ymin; }),
              d3.max(data, function (d) { return d.ymax; })],
        deltae = d3.extent(data, function (d, i) {
            var delta = i ? d[0] - data[i-1][0] : 0;
            return delta || Infinity;
        }),
        x = d3.scale.linear().range(args.xflip ? [width, 0] : [0, width])
                .domain(expand_extent(xe, 0.2)),
        y = d3.scale.linear().range(args.yflip ? [0, height] : [height, 0])
                .domain(expand_extent(ye, 0.2));

    var barwidth = width/80;
    if (deltae[0] != Infinity)
        barwidth = Math.max(Math.min(barwidth, Math.abs(x(xe[0] + deltae[0]) - x(xe[0])) * 0.5), 6);

    var xAxis = d3.svg.axis().scale(x).orient("bottom");
    args.xticks && args.xticks(xAxis, xe);
    var yAxis = d3.svg.axis().scale(y).orient("left");
    args.yticks && args.yticks(yAxis, ye);

    var svg = d3.select(args.selector).append("svg")
        .attr("width", width + margin.left + margin.right)
        .attr("height", height + margin.top + margin.bottom)
      .append("g")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    function place_whisker(l, sel) {
        sel.attr("x1", function (d) { return x(d[0]); })
            .attr("x2", function (d) { return x(d[0]); })
            .attr("y1", function (d) { return y(d.q[l]); })
            .attr("y2", function (d) { return y(d.q[l + 1]); });
    }

    function place_box(sel) {
        sel.attr("x", function (d) { return x(d[0]) - barwidth / 2; })
            .attr("y", function (d) {
                return Math.min(y(d.q[3]), y(d.q[1]) - 2);
            })
            .attr("width", barwidth)
            .attr("height", function (d) {
                return Math.max(y(d.q[1]) - y(d.q[3]), 4);
            });
    }

    function place_median(sel) {
        sel.attr("x1", function (d) { return x(d[0]) - barwidth / 2; })
            .attr("x2", function (d) { return x(d[0]) + barwidth / 2; })
            .attr("y1", function (d) { return y(d.q[2]); })
            .attr("y2", function (d) { return y(d.q[2]); });
    }

    place_whisker(0, svg.selectAll(".gbox.whiskerl").data(data)
            .enter().append("line")
            .attr("class", function (d) { return "gbox whiskerl " + (d.c||""); }));

    place_whisker(3, svg.selectAll(".gbox.whiskerh").data(data)
            .enter().append("line")
            .attr("class", function (d) { return "gbox whiskerh " + (d.c||""); }));

    place_box(svg.selectAll(".gbox.box").data(data)
            .enter().append("rect")
            .attr("class", function (d) { return "gbox box " + (d.c||""); }));

    place_median(svg.selectAll(".gbox.median").data(data)
            .enter().append("line")
            .attr("class", function (d) { return "gbox median " + (d.c||""); }));

    svg.selectAll(".gbox.outlier").data(d3.merge(data.map(function (d) {
          return d.d.filter(function (y) { return y < d.q[0] || y > d.q[4]; })
              .map(function (y) { return [d[0], y, d.c||""]; });
      }))).enter().append("circle")
        .attr("cx", function (d) { return x(d[0]); })
        .attr("cy", function (d) { return y(d[1]); })
        .attr("r", 2)
        .attr("class", function (d) { return "gbox outlier " + d[2]; });

    make_axes(svg, width, height, xAxis, yAxis, args);

    svg.append("line").attr("class", "gbox whiskerl gbox_hover0");
    svg.append("line").attr("class", "gbox whiskerh gbox_hover0");
    svg.append("rect").attr("class", "gbox box gbox_hover0");
    svg.append("line").attr("class", "gbox median gbox_hover0");
    svg.append("line").attr("class", "gbox whiskerl gbox_hover1");
    svg.append("line").attr("class", "gbox whiskerh gbox_hover1");
    svg.append("rect").attr("class", "gbox box gbox_hover1");
    svg.append("line").attr("class", "gbox median gbox_hover1");
    var hovers = svg.selectAll(".gbox_hover0, .gbox_hover1")
        .style("display", "none").style("ponter-events", "none");

    svg.selectAll(".gbox").on("mouseover", mouseover).on("mouseout", mouseout)
        .on("click", mouseclick);

    var hovered_data, hubble;
    function mouseover() {
        var p = d3.select(this).data()[0];
        if (p != hovered_data) {
            if (p) {
                hovers.style("display", null).datum(p);
                place_whisker(0, hovers.filter(".whiskerl"));
                place_whisker(3, hovers.filter(".whiskerh"));
                place_box(hovers.filter(".box"));
                place_median(hovers.filter(".median"));
            } else
                hovers.style("display", "none");
            svg.style("cursor", p ? "pointer" : null);
            hovered_data = p;
        }
        if (p) {
            hubble = hubble || make_bubble("", {color: "tooltip dark", "pointer-events": "none"});
            if (!p.x) {
                var yformat = function (value) { return value; };
                if (args.yticks && args.yticks.unparse_html)
                    yformat = args.yticks.unparse_html;
                p.x = [];
                for (var i = 0; i < p.p.length; ++i)
                    p.x.push(p.p[i] + " (" + yformat(p.d[i]) + ")");
                p.x.sort(pid_sorter);
                p.x = '<p><span class="nw">#' + p.x.join(',</span> <span class="nw">#') + '</span></p>';
            }
            hubble.html(p.x).dir("l").near(hovers.filter(".box").node());
        }
    }

    function mouseout() {
        hovers.style("display", "none");
        hubble && hubble.remove();
        hovered_data = hubble = null;
    }

    function mouseclick() {
        clicker(hovered_data ? hovered_data.p : null);
    }
};

hotcrp_graphs.formulas_add_qrow = function () {
    var i, h, j;
    for (i = 0; $("#q" + i).length; ++i)
        /* do nothing */;
    j = $(hotcrp_graphs.formulas_qrow.replace(/\$/g, i)).appendTo("#qcontainer");
    hiliter_children(j);
    j.find("input[placeholder]").each(mktemptext);
    j.find(".hotcrp_searchbox").each(function () { taghelp(this, "taghelp_q", taghelp_q); });
};

hotcrp_graphs.option_letter_ticks = function (n, c, sv) {
    var info = make_score_info(n, c, sv), split = 2;
    function format(axis, extent) {
        var count = Math.floor(extent[1] * 2) - Math.ceil(extent[0] * 2) + 1;
        if (count > 11)
            split = 1, count = Math.floor(extent[1]) - Math.ceil(extent[0]) + 1;
        if (c)
            axis.ticks(count);
    }
    format.rewrite = function (axis) {
        $(axis[0]).find("g.tick text").each(function () {
            var $self = $(this);
            $self.css({fill: info.rgb($self.text())});
            if (c)
                $self.text(info.unparse($self.text(), split));
        });
    };
    format.unparse_html = info.unparse_html;
    return format;
};

function get_max_tick_width(axis) {
    return d3.max($(axis[0]).find("g.tick text").map(function () {
        return $(this).width();
    }));
}

hotcrp_graphs.named_integer_ticks = function (map) {
    function format(axis, extent) {
        var count = Math.floor(extent[1]) - Math.ceil(extent[0]) + 1;
        axis.ticks(count).tickFormat(function (value) {
            return map[value];
        });
    }
    if (d3.max(d3.values(map).map(function (s) { return s.length; })) > 4
        || d3.values(map).length > 30)
        format.rewrite = function (axis) {
            var w = get_max_tick_width(axis);
            if (w > 100) {
                $(axis[0]).find("g.tick text").css("font-size", "smaller");
                w = get_max_tick_width(axis);
            }
            axis.selectAll("g.tick text").style("text-anchor", "end")
                .attr("dx", "-0.8em").attr("dy", ".15em")
                .attr("transform", "rotate(-65)");
            w = w * Math.sin(1.13446) + 20; // 65 degrees in radians
            if (w > BOTTOM_MARGIN && axis.classed("x")) {
                var container = $(axis[0]).closest("svg");
                container.attr("height", +container.attr("height") + (w - BOTTOM_MARGIN));
            }
        };
    format.unparse_html = function (value) {
        return map[value] != null ? text_to_html(map[value]) : value;
    };
    return format;
};

hotcrp_graphs.rotate_ticks = function (angle) {
    return function (axis) {
        axis.selectAll("text")
            .attr("x", 0).attr("y", 0).attr("dy", "-.71em")
            .attr("transform", "rotate(" + angle + ")")
            .style("text-anchor", "middle");
    };
};

return hotcrp_graphs;
})(jQuery, d3);
