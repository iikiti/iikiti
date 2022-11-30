export const slotless = (superClass) => {
    return class slotless extends superClass {
        firstUpdated() {
            super.firstUpdated();
            let slotElem = this.querySelector(':scope > slot');
            if(!slotElem) {
                return;
            }
            slotElem.parentNode.removeChild(slotElem);
        }
    }
}