// URL: https://beta.observablehq.com/@mbostock/d3-sunburst
// Title: D3 Sunburst
// Author: Mike Bostock (@mbostock)
// Version: 187
// Runtime version: 1

const m0 = {
  id: "a601aba88046a626@187",
  variables: [
    {
      inputs: ["md"],
      value: (function(md){return(
md`# D3 Sunburst

The [Flare visualization toolkit](https://flare.prefuse.org) package hierarchy.`
)})
    },
    {
      name: "chart",
      inputs: ["partition","data","d3","DOM","width","color","arc","format"],
      value: (function(partition,data,d3,DOM,width,color,arc,format)
{
  const root = partition(data);



          // 시각화할 요소를 선택하는 부분
          // .style로 표시한 부분들은 요소들의 관한 스타일을 표시하고 지정해주는 부분임.
  const svg = d3.select(DOM.svg(width, width))
      .style("width", "100%")           
      .style("height", "auto")
      .style("padding", "10px")
      .style("font", "10px sans-serif")
      .style("box-sizing", "border-box");
  

          // append("g")의 의미는 그룹내 모든 element들에게 동일한 속성을 적용한다는 것을 의미
  const g = svg.append("g");


        // attr()의 의미는 요소들의 attributes를 변화하기 위해 주로 사용됨  
  g.append("g")
      .attr("fill-opacity", 0.6)
    .selectAll("path")
    .data(root.descendants().filter(d => d.depth))
    .enter().append("path")
      .attr("fill", d => { while (d.depth > 1) d = d.parent; return color(d.data.name); })
      .attr("d", arc)
    .append("title")
      .text(d => `${d.ancestors().map(d => d.data.name).reverse().join("/")}\n${format(d.value)}`);

  g.append("g")
      .attr("pointer-events", "none")
      .attr("text-anchor", "middle")
    .selectAll("text")
    .data(root.descendants().filter(d => d.depth && (d.y0 + d.y1) / 2 * (d.x1 - d.x0) > 10))
    .enter().append("text")
      .attr("transform", function(d) {
        const x = (d.x0 + d.x1) / 2 * 180 / Math.PI;
        const y = (d.y0 + d.y1) / 2;
        return `rotate(${x - 90}) translate(${y},0) rotate(${x < 180 ? 0 : 180})`;
      })
      .attr("dy", "0.35em")
      .text(d => d.data.name);

  document.body.appendChild(svg.node());

  const box = g.node().getBBox();

  svg.remove()
      .attr("width", box.width)
      .attr("height", box.height)
      .attr("viewBox", `${box.x} ${box.y} ${box.width} ${box.height}`);

  return svg.node();
}
)
    },
    {
      name: "data",
      inputs: ["require"],
      value: (function(require){return(
require("@observablehq/flare")
)})
    },
    {
      name: "partition",
      inputs: ["d3","radius"],
      value: (function(d3,radius){return(
data => d3.partition()
    .size([2 * Math.PI, radius])
  (d3.hierarchy(data)
    .sum(d => d.size)
    .sort((a, b) => b.value - a.value))
)})
    },
    {
      name: "color",
      inputs: ["d3","data"],
      value: (function(d3,data){return(
d3.scaleOrdinal().range(d3.quantize(d3.interpolateRainbow, data.children.length + 1))
)})
    },
    {
      name: "format",
      inputs: ["d3"],
      value: (function(d3){return(
d3.format(",d")
)})
    },
    {
      name: "width",
      value: (function(){return(
932
)})
    },
    {
      name: "radius",
      inputs: ["width"],
      value: (function(width){return(
width / 2
)})
    },
    {
      name: "arc",
      inputs: ["d3","radius"],
      value: (function(d3,radius){return(
d3.arc()
    .startAngle(d => d.x0)
    .endAngle(d => d.x1)
    .padAngle(d => Math.min((d.x1 - d.x0) / 2, 0.005))
    .padRadius(radius / 2)
    .innerRadius(d => d.y0)
    .outerRadius(d => d.y1 - 1)
)})
    },
    {
      name: "d3",
      inputs: ["require"],
      value: (function(require){return(
require("d3@5")
)})
    }
  ]
};

const notebook = {
  id: "a601aba88046a626@187",
  modules: [m0]
};

export default notebook;
