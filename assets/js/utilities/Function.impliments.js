export default Function.prototype.implement = function() {
    // Loop through each interface passed in and then check
    // that its members are implemented in the context object (this)
    for(var i = 0; i < arguments.length; i++) {
         var interf = arguments[i];
         // Is the interface a class type?
         if (interf.constructor === Object) {
             for (prop in interf) {
                 // Check methods and fields vs context object (this)
                 if (interf[prop].constructor === Function) {
                     if (!this.prototype[prop] ||
                          this.prototype[prop].constructor !== Function) {
                          throw new Error('Method [' + prop
                               + '] missing from class definition.');
                     }
                 } else {
                     if (this.prototype[prop] === undefined) {
                          throw new Error('Field [' + prop
                               + '] missing from class definition.');
                     }
                 }
             }
         }
    }
    // Remember to return the class being tested
    return this;
};