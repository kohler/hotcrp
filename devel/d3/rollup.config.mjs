import json from "@rollup/plugin-json";
import nodeResolve from "@rollup/plugin-node-resolve";
import terser from "@rollup/plugin-terser";

export default [{
    input: "index.js",
    plugins: [
        nodeResolve(),
        json(),
        terser({
            mangle: {
                reserved: [
                    "InternMap",
                    "InternSet"
                ]
            }
        })
    ],
    output: {
        file: "d3-hotcrp.min.js",
        name: "d3",
        format: "umd",
        indent: false,
        extend: true
    }
}];
