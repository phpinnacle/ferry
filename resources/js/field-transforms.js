export default function fieldTransforms({ action, context, disabled }) {
    return {
        configureSorting(element) {
            Sortable.get(element).option('onMove', event => this.canMove(event));
        },

        allowedMoves() {
            return JSON.parse(this.$refs.items.dataset.allowedMoves);
        },

        canPlace(key, position) {
            return this.allowedMoves()[key]?.includes(position) ?? false;
        },

        canMove(event) {
            if (disabled) return false;
            if (event.related === event.dragged) return true;
            const key = event.dragged.getAttribute('x-sortable-item');
            const order = this.order().filter(item => item !== key);
            const related = order.indexOf(event.related.getAttribute('x-sortable-item'));
            const position = related < 0 ? order.length : related + Number(event.willInsertAfter);
            return this.canPlace(key, position);
        },

        openFromSpace(event) {
            if (disabled || event.defaultPrevented || event.target.closest('button, a, .ferry-transform')) return;
            if (this.$refs.items.children.length === 0 && !event.target.closest('.ferry-transforms-placeholder')) return;
            const button = this.$refs.add.querySelector('button');
            if (button.disabled) return;
            button.focus();
            // Follow native pointer activation so the dropdown owns opening and focus.
            button.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, button: 0 }));
            button.click();
        },

        order() {
            return Array.from(this.$refs.items.children, item => item.getAttribute('x-sortable-item'));
        },

        save(order) {
            if (disabled) return;
            return this.$wire.mountAction(action, { order }, context);
        },

        reorder(event) {
            if (disabled || event.oldDraggableIndex === event.newDraggableIndex) return;
            const order = this.order();
            const key = event.item.getAttribute('x-sortable-item');
            if (!this.canPlace(key, order.indexOf(key))) {
                // Restore after Filament's sortable end handler has completed.
                this.$nextTick(() => Sortable.get(this.$refs.items).sort(Object.keys(this.allowedMoves())));
                return;
            }
            return this.save(order);
        },

        move(key, offset) {
            if (disabled) return;
            const order = this.order();
            const index = order.indexOf(key);
            const destination = index + offset;
            if (index < 0 || destination < 0 || destination >= order.length || !this.canPlace(key, destination)) return;
            order.splice(destination, 0, ...order.splice(index, 1));
            return this.save(order);
        },
    };
}
