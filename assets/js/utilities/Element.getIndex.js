
let getIndex = Symbol();

if(!Element.prototype[getIndex]) {
    /**
     * 
     * @returns {int}
     */
    Element.prototype[getIndex] = function() {
        return Array.prototype.indexOf.call(this.parentElement.children, this);
    }
}

export default getIndex;
