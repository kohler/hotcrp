// URL: https://beta.observablehq.com/@mbostock/d3-sunburst
// Title: "Journal Distribution Visualization"
// Author: Mike Bostock (@mbostock)
// Version: 187
// Runtime version: 1

const m0 = {
  id: "a601aba88046a626@187",
  variables: [
    {
      inputs: ["md"],
      value: (function(md){return(

          // 큰제목설정
md`# Paper Distribution Visualization
The [Flare visualization toolkit](https://flare.prefuse.org) package hierarchy.`
)})
    },
    {
      name: "chart",
      inputs: ["partition","data","d3","DOM","width","color","arc","format"],
      value: (function(partition,data,d3,DOM,width,color,arc,format)
{
  const root = partition(data);
  //데이터 구조를 생성하는 파티션 변수와 data를 결합하여 root에 저장



          // 시각화할 요소를 선택하는 부분
          // .style로 표시한 부분들은 요소들의 관한 스타일을 표시하고 지정해주는 부분임.
  //svg 경로는 칠하고, 채우는 등 모양의 윤곽을 나타냄.
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
    .selectAll("path")  //<svg>요소에 추가 된 <g>의 <path>요소 참조, 현재 path요소가 없으므로 새로 path의 위치 생성
    .data(root.descendants().filter(d => d.depth))  //path요소에 대해 알려준다. root변수를 전달
    .enter().append("path") //path요소와 데이터를 연결, g요소 아래에 빈 path요소 생성
      .attr("fill", d => { while (d.depth > 1) d = d.parent; return color(d.data.name); })
      //d.depth는 시각화에 나타나는 링 개수 제한
      .attr("d", arc)  //path요소의 모든 'd'속성을 arc변수의 값으로 채운다. d는 path요소의 각 행에 대한 실제 경로 포함 
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
       
          //데이터set 위치를 설정하여 설정된 위치에서 데이터 set을 불러와줌
require("./topicPaper/index.js") // 같은 위치에 있는 flare파일 속 index.js파일의 데이터를 불러온다는 의미
)})
    },
    {
      //데이터구조
      //partition은 데이터를 sunburst패턴으로 구성하고 크기를 적절하게 설정해준다.
      name: "partition",
      inputs: ["d3","radius"],
      value: (function(d3,radius){return(
data => d3.partition()
    .size([2 * Math.PI, radius])  //size는 파티션의 크기(width,height)를 설정
     //Math.PI는 sunburst가 차지하는 라디안 수를 d3에 알린다. 따라서 *2를 지우면 반원이 된다. radius는 중심에서 외부까지의 거리를 d3에 알린다.
  (d3.hierarchy(data) 
    .sum(d => d.size)
    .sort((a, b) => b.value - a.value))  //각 노드 정렬
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
900
)})
    },
    {
      name: "radius",
      inputs: ["width"],
      value: (function(width){return(
width / 2
)})
    },
    { //호의 크기
      name: "arc",
      inputs: ["d3","radius"],
      value: (function(d3,radius){return(
//d3.arc()는 data를  기반으로 각 호의 크기를 계산.
d3.arc()  //아래의 4가지 변수(start,end,inner,outer)는 각 호의 대해 4개의 외부 선을 정의 
    .startAngle(d => d.x0)  //d.x0: 원호의 시작
    .endAngle(d => d.x1)  //d.x1: 호 끝의 라디안 위치
    .padAngle(d => Math.min((d.x1 - d.x0) / 2, 0.005))
    .padRadius(radius / 2)
    .innerRadius(d => d.y0)  //d.y0: 내부호의 라디안 위치
    .outerRadius(d => d.y1 - 1)  //d.y1: 외부호의 라디안 위치
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