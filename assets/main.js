'use strict';

import $ from 'cash-dom';
import '/assets/js/utilities/XPathResult.js';

// Get all custom elements
let ce = Array.from(document.evaluate( "//*[contains(name(),'-')]", document, null, XPathResult.ANY_TYPE, null ));
var ceTagObj = {};

ce.forEach((elem) => {
    ceTagObj[elem.tagName.toLowerCase()] = true;
});

Object.keys(ceTagObj).forEach((key) => {
    import(
        /* webpackChunkName: "js/chunks/block-editor-web-components" */
        /* webpackMode: "lazy" */
        /* webpackPrefetch: true */
        /* webpackPreload: true */
        '/assets/js/components/' + key + '.js'
    );
});
