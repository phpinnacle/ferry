export default function fieldMapping({ state, disabled, inverse = false, requiredTargets = [], connectionModes, stateKeys = {}, staticDefaults = {} }) {
    let ports;
    const idsByStateKey = Object.fromEntries(Object.entries(stateKeys).map(([id, key]) => [key, id]));
    const isStaticValue = (entry) => entry?.kind === 'value';

    return {
        state,
        disabled,
        inverse,
        requiredTargets,
        connectionModes,
        sourceSearch: '',
        targetSearch: '',
        unmappedOnly: false,
        selected: null,
        connections: [],
        width: 1,
        height: 1,
        announcement: '',
        observer: null,

        init() {
            this.observer = new ResizeObserver(() => this.refresh());
            this.observer.observe(this.$refs.canvas);
            this.$root.querySelectorAll('[data-mapping-row], [data-mapping-anchor]').forEach((element) => this.observer.observe(element));
            ['state', 'sourceSearch', 'targetSearch', 'unmappedOnly'].forEach((property) => {
                this.$watch(property, () => {
                    if (property !== 'state') this.cancel();
                    this.$nextTick(() => this.refresh());
                });
            });
            this.$nextTick(() => this.refresh());
        },

        destroy() {
            this.observer?.disconnect();
        },

        mappings() {
            return Object.fromEntries(Object.entries(this.state?.targets ?? {}).map(([key, value]) => [idsByStateKey[key] ?? key, value]));
        },

        setMappings(mappings) {
            for (const [target, value] of Object.entries(staticDefaults)) {
                if (!Object.hasOwn(mappings, target)) mappings[target] = { kind: 'value', value };
            }
            this.state = {
                ...this.state,
                targets: Object.fromEntries(Object.entries(mappings).map(([id, value]) => [stateKeys[id] ?? id, value])),
            };
        },

        isConstant(target) {
            return isStaticValue(this.mappings()[target]);
        },

        pairs() {
            return Object.entries(this.mappings()).flatMap(([id, mapped]) => {
                if (isStaticValue(mapped)) return [];

                const sources = Array.isArray(mapped) ? mapped : [mapped];
                return sources.map((entry) => ({ source: entry.source, target: id }));
            });
        },

        mappedTargetCount() {
            return Object.keys(this.mappings()).length;
        },

        isMapped(side, id) {
            return side === 'target'
                ? Object.hasOwn(this.mappings(), id)
                : this.pairs().some((pair) => pair.source === id);
        },

        isSelected(side, id) {
            return this.selected?.side === side && this.selected.id === id;
        },

        isDisabled(side, id) {
            return this.disabled || side === 'source' && this.isMapped(side, id);
        },

        fieldBindings(side, id) {
            return {
                '@click': () => this.select(side, id),
                ':aria-pressed': () => this.isSelected(side, id),
                ':aria-disabled': () => this.isDisabled(side, id) || this.isIncompatible(side, id),
                ':disabled': () => this.isDisabled(side, id),
            };
        },

        rowClasses(side, id, required) {
            const mapped = this.isMapped(side, id);
            const incompatible = this.isIncompatible(side, id);

            return {
                'is-mapped': mapped,
                'is-selected': this.isSelected(side, id),
                'is-incompatible': incompatible,
                'is-compatible': Boolean(this.selected && this.selected.side !== side && !this.isDisabled(side, id) && !incompatible),
                'is-required-missing': required && !mapped,
                'has-type-conflict': side === 'target' && this.hasTypeConflict(id),
            };
        },

        missingRequired() {
            return this.requiredTargets.filter((id) => !this.isMapped('target', id));
        },

        canConnect(source, target) {
            return Boolean(this.port('source', source) && this.port('target', target) && this.connectionModes[source]?.[target]);
        },

        targetMode(target) {
            return Array.isArray(this.mappings()[target]) ? 'item' : 'whole';
        },

        isIncompatible(side, id) {
            const origin = this.selected;
            if (!origin || origin.side === side) return false;

            const source = side === 'source' ? id : origin.id;
            const target = side === 'target' ? id : origin.id;
            return !this.canConnect(source, target) || !this.isSourceAvailable(source, target);
        },

        isSourceAvailable(source, target) {
            return !this.pairs().some((pair) => pair.target !== target && pair.source === source);
        },

        hasTypeConflict(target) {
            return this.pairs().some((pair) => pair.target === target && (!this.canConnect(pair.source, target) || this.connectionModes[pair.source]?.[target] !== this.targetMode(target)));
        },

        isVisible(side, id, text) {
            const search = side === 'source' ? this.sourceSearch : this.targetSearch;
            return text.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase())
                && (!this.unmappedOnly || !this.isMapped(side, id));
        },

        select(side, id) {
            if (this.isDisabled(side, id) || this.isIncompatible(side, id)) return;

            if (this.selected && this.selected.side !== side) {
                const source = side === 'source' ? id : this.selected.id;
                const target = side === 'target' ? id : this.selected.id;
                this.connect(source, target);
                return;
            }

            this.selected = this.isSelected(side, id) ? null : { side, id };
            this.announcement = this.selected
                ? `Select a ${side === 'source' ? 'destination' : 'source'} to connect.`
                : 'Selection cancelled.';
        },

        connect(source, target) {
            if (this.disabled || !this.canConnect(source, target) || !this.isSourceAvailable(source, target)) return;

            const mappings = this.mappings();
            const multiple = this.connectionModes[source][target] === 'item';
            const entry = { kind: 'source', source };
            if (multiple) {
                const sources = Array.isArray(mappings[target]) ? mappings[target] : [];
                mappings[target] = sources.some(entry => entry.source === source) ? sources : [...sources, entry];
            } else {
                mappings[target] = entry;
            }

            this.setMappings(mappings);
            this.selected = null;
            this.announcement = 'Fields connected.';
        },

        remove(target, source = null) {
            if (this.disabled) return;

            const mappings = this.mappings();

            if (source !== null && Array.isArray(mappings[target])) {
                const remaining = mappings[target].filter((entry) => entry.source !== source);
                if (remaining.length) mappings[target] = remaining;
                else delete mappings[target];
            } else {
                delete mappings[target];
            }

            this.setMappings(mappings);
            this.announcement = 'Connection removed.';
        },

        cancel() {
            this.selected = null;
        },

        port(side, id) {
            ports ??= new Map(Array.from(this.$root.querySelectorAll('[data-mapping-port]'), port => [
                `${port.dataset.side}:${port.dataset.id}`, port,
            ]));

            return ports.get(`${side}:${id}`);
        },

        point(port, canvas) {
            const rect = port?.querySelector('[data-mapping-anchor]').getBoundingClientRect();
            if (!rect || rect.width === 0 || rect.height === 0) return null;

            return {
                x: rect.left + rect.width / 2 - canvas.left,
                y: rect.top + rect.height / 2 - canvas.top,
            };
        },

        path(source, target) {
            const bend = Math.max(36, Math.abs(target.x - source.x) / 2);
            return `M ${source.x} ${source.y} C ${source.x + bend} ${source.y}, ${target.x - bend} ${target.y}, ${target.x} ${target.y}`;
        },

        refresh() {
            const canvas = this.$refs.canvas.getBoundingClientRect();
            this.width = Math.max(1, canvas.width);
            this.height = Math.max(1, canvas.height);
            this.connections = this.pairs().flatMap(({ target, source }) => {
                const sourcePort = this.port('source', source);
                const targetPort = this.port('target', target);
                const start = this.point(sourcePort, canvas);
                const end = this.point(targetPort, canvas);
                return start && end ? [{
                    source,
                    target,
                    path: this.inverse ? this.path(end, start) : this.path(start, end),
                    label: `Disconnect ${sourcePort.dataset.label} from ${targetPort.dataset.label}`,
                }] : [];
            });
        },
    };
}
