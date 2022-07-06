let iteratorSymbol = Symbol.iterator;

if(!XPathResult.prototype[iteratorSymbol]) {
    XPathResult.prototype[iteratorSymbol] = function () {
        return {
            next: () => {
                let node = this.iterateNext();
                return {value: node, done: !node};
            }
        };
    };
}

let XPathResultArray = XPathResult.prototype[iteratorSymbol];

export default XPathResultArray;