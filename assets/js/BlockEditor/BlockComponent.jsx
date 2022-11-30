'use strict';

import $ from "cash-dom";
import React from "react";
import BlockEditingElement from "./BlockEditingElement";

class BlockComponent extends React.Component {

    constructor(props) {
        super(props);
    }

    render() {
        return <BlockEditingElement settings={this.props.settings}>{this.props.children}</BlockEditingElement>;
    }

    componentDidMount() {
        
    }

}

Object.defineProperty( BlockComponent.prototype, "SELECTOR", {
    value: '[data-type="component"]',
    writable: false,
    enumerable: true,
    configurable: true
});

export default BlockComponent;
