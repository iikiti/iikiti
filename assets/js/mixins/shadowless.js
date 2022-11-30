export const shadowless = (superClass) => {
    return class shadowless extends superClass {
        createRenderRoot() {
            // turn off shadow dom to access external styles
            return this;
        }
    }
}