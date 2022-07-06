
let getIndex = 'capitalize';

if(!String.prototype[getIndex]) {
    /**
     * 
     * @returns {int}
     */
    String.prototype[getIndex] = function() {
        return this.charAt(0).toUpperCase() + this.slice(1);
    }
}

export default getIndex;
