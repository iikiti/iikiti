
let furthest = Symbol();

if(!("furthest" in Element.prototype) && !Element.prototype[furthest]) {
	/**
	 * 
	 * @returns {int}
	 */
	Element.prototype[furthest] = function(s) {
		var el = this.parentElement || this.parentNode;
		var anc = null;

		while (el !== null && el.nodeType === 1) {
			if (el.matches(s)) anc = el;
			el = el.parentElement || el.parentNode;
		}
		return anc;
	}
}

export default furthest;
