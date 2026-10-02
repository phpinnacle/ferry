export default function fieldMapping({ state, disabled, inverse = false, requiredTargets = [], multipleTargets = [] }) {
    let ports;

    return {
        state,
        disabled,
        inverse,
        requiredTargets,
        multipleTargets,
        sourceSearch: '',
        targetSearch: '',
        unmappedOnly: false,
        selected: null,
        connections: [],
        width: 1,
        height: 1,
        announcement: '',
        connectionError: '',
        observer: null,

        init() {
            this.observer = new ResizeObserver(() => this.refresh());
            this.observer.observe(this.$refs.canvas);
            this.$root.querySelectorAll('[data-mapping-row]').forEach((row) => this.observer.observe(row));
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
            return this.state ?? {};
        },

        pairs() {
            return Object.entries(this.mappings()).flatMap(([id, mapped]) => {
                if (this.inverse) return [{ source: id, target: mapped }];

                const sources = this.multipleTargets.includes(id) ? mapped : [mapped];
                return sources.map((source) => ({ source, target: id }));
            });
        },

        mappedTargetCount() {
            return new Set(this.pairs().map(({ target }) => target)).size;
        },

        isMapped(side, id) {
            return this.pairs().some((pair) => pair[side] === id);
        },

        isSelected(side, id) {
            return this.selected?.side === side && this.selected.id === id;
        },

        isDisabled(side, id) {
            return this.disabled || this.isLocked(side, id);
        },

        isLocked(side, id) {
            return side === 'source'
                ? this.isSourceLocked(id)
                : this.inverse && !this.multipleTargets.includes(id) && this.isMapped(side, id);
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

            return {
                'is-mapped': mapped,
                'is-selected': this.isSelected(side, id),
                'is-incompatible': this.isIncompatible(side, id) || this.isLocked(side, id),
                'is-required-missing': required && !mapped,
                'has-type-conflict': side === 'target' && this.hasTypeConflict(id),
            };
        },

        missingRequired() {
            return this.requiredTargets.filter((id) => !this.isMapped('target', id));
        },

        canConnect(source, target) {
            const sourcePort = this.port('source', source);
            const targetPort = this.port('target', target);
            if (!sourcePort || !targetPort) return false;

            const sourceType = sourcePort.dataset.type;
            const targetType = targetPort.dataset.type;
            return !sourceType || !targetType || sourceType === targetType;
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

        isSourceLocked(source) {
            return this.isMapped('source', source);
        },

        hasTypeConflict(target) {
            return this.pairs().some((pair) => pair.target === target && !this.canConnect(pair.source, target));
        },

        isVisible(side, id, text) {
            const search = side === 'source' ? this.sourceSearch : this.targetSearch;
            return text.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase())
                && (!this.unmappedOnly || !this.isMapped(side, id));
        },

        select(side, id) {
            if (this.isDisabled(side, id)) return;

            this.connectionError = '';

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
            if (this.disabled || !this.port('source', source) || !this.port('target', target)) return;

            if (!this.canConnect(source, target)) {
                this.connectionError = 'These fields have incompatible types. Choose fields with the same type.';
                this.announcement = this.connectionError;
                return;
            }

            if (!this.isSourceAvailable(source, target)) {
                this.connectionError = 'This source already has a connection. Disconnect it first or choose another source.';
                this.announcement = this.connectionError;
                return;
            }

            const multiple = this.multipleTargets.includes(target);
            const mappings = multiple ? { ...this.mappings() } : this.withoutConnection(target);

            if (this.inverse) {
                mappings[source] = target;
            } else if (multiple) {
                const sources = mappings[target] ?? [];
                mappings[target] = sources.includes(source) ? sources : [...sources, source];
            } else {
                mappings[target] = source;
            }

            this.state = mappings;
            this.selected = null;
            this.connectionError = '';
            this.announcement = 'Fields connected.';
        },

        withoutConnection(target, source = null) {
            const mappings = { ...this.mappings() };

            if (this.inverse) {
                this.pairs().forEach((pair) => {
                    if (pair.target === target && (source === null || pair.source === source)) delete mappings[pair.source];
                });
            } else if (source !== null && this.multipleTargets.includes(target)) {
                const remaining = (mappings[target] ?? []).filter((id) => id !== source);
                if (remaining.length) mappings[target] = remaining;
                else delete mappings[target];
            } else {
                delete mappings[target];
            }

            return mappings;
        },

        remove(target, source = null) {
            if (this.disabled) return;

            this.state = this.withoutConnection(target, source);
            this.announcement = 'Connection removed.';
        },

        cancel() {
            this.selected = null;
            this.connectionError = '';
        },

        port(side, id) {
            ports ??= Array.from(this.$root.querySelectorAll('[data-mapping-port]'));

            return ports.find((port) => {
                return port.dataset.side === side && port.dataset.id === id;
            });
        },

        point(port, canvas) {
            const rect = port?.getBoundingClientRect();
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
