import ascii from "rollup-plugin-ascii";
import node from "rollup-plugin-node-resolve";
import {terser} from "rollup-plugin-terser";

export default [{
  input: "index.js",
  plugins: [
    node(),
    ascii(),
    terser()
  ],
  output: {
    extend: true,
    file: "d3-hotcrp.min.js",
    format: "umd",
    indent: false,
    name: "d3",
  }
}];
