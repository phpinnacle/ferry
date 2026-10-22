export default function fieldTransforms({ action, context, disabled }) {
    return {
        order() {
            return Array.from(this.$refs.items.children, item => item.getAttribute('x-sortable-item'));
        },

        save(order) {
            if (disabled) return;
            return this.$wire.mountAction(action, { order }, context);
        },

        reorder(event) {
            if (event.oldDraggableIndex === event.newDraggableIndex) return;
            return this.save(this.order());
        },

        move(key, offset) {
            const order = this.order();
            const index = order.indexOf(key);
            const destination = index + offset;
            if (index < 0 || destination < 0 || destination >= order.length) return;
            order.splice(destination, 0, ...order.splice(index, 1));
            return this.save(order);
        },
    };
}
