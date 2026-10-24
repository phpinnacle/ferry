export default function fieldBinding({ state, disabled, sourceKeys }) {
    return {
        state,
        disabled,
        search: '',
        visibilityFilter: 'all',

        isBound(key) {
            return this.state.bound[key] === true;
        },

        matchesFilter(bound) {
            return this.visibilityFilter === 'all' || bound === (this.visibilityFilter === 'bound');
        },

        isVisible(key, text) {
            return this.matchesFilter(this.isBound(key))
                && text.toLocaleLowerCase().includes(this.search.trim().toLocaleLowerCase());
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
