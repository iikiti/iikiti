import getIndex from "./Element.getIndex";

let getSiblings = Symbol();

if(!Element.prototype[getSiblings]) {
    /**
     * 
     * @returns {NodeList}
     */
    Element.prototype[getSiblings] = function() {
        let idx = this[getIndex]()+1;
        if(idx < 1) {
            return new NodeList();
        }
        return this.parentElement.querySelectorAll(":scope > *:nth-child(n+" + (idx+1) + ")");
    }
}

export default getSiblings;
