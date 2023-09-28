import { html } from 'lit';
import '../utilities/String.capitalize';

export class BlockEditorComponent {

    static properties = {

        _isComponent: { state: true, type: Boolean},
    
    };

    constructor() {
        super();
        this._isComponent = this.getTagClassName() === 'BlockEditorComponent';
    }

    getTagClassName() {
        return this.tagName.split('-').map(name => name.toLowerCase().capitalize()).join('');
    }

    // Render the UI as a function of component state
    render() {
        if(this._isComponent) {
            console.log('component rendered: ' + this.getAttribute('is'));
        }
        return html`<slot></slot>`;
    }

};

customElements.define('block-editor-component', BlockEditorComponent);