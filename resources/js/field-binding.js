export default function fieldBinding({ state, disabled, sourceKeys }) {
    return {
        state,
        disabled,
        search: '',
        unboundOnly: false,

        isBound(key) {
            return this.state.bound[key] === true;
        },

        boundCount() {
            return sourceKeys.filter(key => this.isBound(key)).length;
        },

        bind(key) {
            if (this.disabled || this.isBound(key)) return;
            this.state = { ...this.state, bound: { ...this.state.bound, [key]: true } };
        },

        unbind(key) {
            if (this.disabled || !this.isBound(key)) return;
            const bound = { ...this.state.bound };
            delete bound[key];
            this.state = {
                ...this.state,
                bound,
                bindings: { ...this.state.bindings, [key]: JSON.parse(JSON.stringify(this.state.previews[key])) },
            };
        },
    };
}
