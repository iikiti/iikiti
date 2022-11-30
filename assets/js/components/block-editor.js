import { BlockEditorComponent } from './block-editor-component';

export class BlockEditor extends BlockEditorComponent {
    constructor() {
        super();
    }

    // Render the UI as a function of component state
    render() {
        console.log('Block editor rendered');
        return super.render();
    }
};

customElements.define('block-editor', BlockEditor);