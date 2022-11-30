import $ from 'cash-dom';
import React from 'react';
import BlockComponent from './BlockComponent';
import BlockElementUtilityPanel from './BlockElementUtilityPanel';

class BlockEditingElement extends React.Component {

    /** @type {boolean} */
    initialized = false;

    element = {'element': null, attributes: {}};

    constructor(props) {
        super(props);
        this.state = {
            'children': []
        };
        this.initialize();
    }

    initialize() {
        if(this.initialized) {
            return this;
        }
        this.element.element = this.props.settings.element || this.props.settings.type;
        this.generateChildren();
        //this.buildUtilities();
        //this.setupHover();
        this.initialized = true;
        return this;
    }

    generateChildren() {
        var children = [];
        if(!this.props.settings.content.components) {
            return;
        }
        this.props.settings.content.components.forEach((settings) => {
            if(typeof settings === 'string') {
                children.push(settings);
                return;
            }
            let component = <BlockComponent key={crypto.randomUUID()} settings={settings}>
                    <button>+</button>
                </BlockComponent>;
            children.push(component);
            children.push(<button key={crypto.randomUUID()}>+</button>);
        });
        this.state.children = children;
    }

    buildUtilities() {
        let contentElement = this.getElement().querySelector(":scope > content");
        if(!contentElement) {
            return;
        }
        this.getElement().appendChild(this.createComponentAttacher("top-left"));
        this.getElement().appendChild(this.createComponentAttacher("bottom-right"));
    }

    setupHover() {
        $(this.getElement()).on('mouseover', this.handleMouseover.bind(this))
            .on('mouseout', this.handleMouseout.bind(this));
    }

    handleMouseover(e) {
        let me = $(this.getElement());
        if(
            $(e.target).closest(
                me.find(BlockEditingElement.prototype.SubHoverSelector)
            ).length > 0
        ){
            me.removeClass('hover');
        } else {
            me.addClass('hover');
        }
    }

    handleMouseout(e) {
        $(this.getElement()).removeClass('hover');
    }

    createComponentAttacher(classStr) {
        let attacher = document.createElement('button');
        let attacherInner = document.createTextNode('+');
        attacher.appendChild(attacherInner);
        attacher.classList.add("min-w-full", "absolute");
        if(classStr && classStr != "") {
            attacher.classList.add(classStr);
        }
        return attacher;
    }

    /**
     *
     * @returns {Element|null}
     */
    getElement() {
        return this instanceof HTMLElement ? this : this.element;
    }

    /**
     *
     * @returns {Element|null}
     */
    getRootEditor() {
        return this.getElement().closest('block-editor');
    }

    /**
     *
     * @returns {Element|null}
     */
    getParent() {
        return this.getElement().parentElement;
    }

    componentDidMount() {
        
    }

    render() {
        let element = <this.element.element {...(this.props.settings.attributes||{})}>
                <content is="" {...(this.props.settings.content.attributes||{})}>
                    {this.element.element === "block-editor" && this.props.children}
                    {this.state.children}
                </content>
                <BlockElementUtilityPanel side="top-right"></BlockElementUtilityPanel>
                <BlockElementUtilityPanel side="bottom-left"></BlockElementUtilityPanel>
            </this.element.element>;
        return element;
    }

}

Object.defineProperty( BlockEditingElement.prototype, "SubHoverSelector", {
    value: ':scope > content > [data-type="component"]',
    writable: false,
    enumerable: true,
    configurable: true
});

export default BlockEditingElement;
