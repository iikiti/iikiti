'use strict';

import $ from 'cash-dom';
import React from "react";
import BlockEditingElement from './BlockEditingElement';

class BlockEditor extends React.Component {

    constructor(props) {
        super(props);
    }

    componentDidMount() {
        
    }

    render() {
        let settings = this.props.settings;
        settings.element = "block-editor";
        return <BlockEditingElement settings={this.props.settings}>
                <button>+</button>
            </BlockEditingElement>;
    }

}

Object.defineProperty( BlockEditor.prototype, "SELECTOR", {
    value: 'block-editor',
    writable: false,
    enumerable: true,
    configurable: true
});

export default BlockEditor;
