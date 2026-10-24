import assert from 'node:assert/strict';
import { test } from 'node:test';
import fieldTransforms from '../../resources/js/field-transforms.js';

function editor(keys, disabled = false, allowedMoves = keys.map(() => keys.map((_, index) => index))) {
    const calls = [];
    const activations = [];
    const button = {
        disabled: false,
        focus: () => activations.push('focus'),
        dispatchEvent: event => activations.push([event.type, event.bubbles, event.button]),
        click: () => activations.push('click'),
    };
    const component = fieldTransforms({ action: 'fieldTransforms', context: { schemaComponent: 'form.mapping' }, disabled });
    component.$refs = { items: { children: keys.map(key => ({ getAttribute: () => key })), dataset: { allowedMoves: JSON.stringify(allowedMoves) } }, add: { querySelector: () => button } };
    component.$nextTick = callback => callback();
    component.$wire = { mountAction: (...args) => calls.push(args) };
    return { component, calls, button, activations };
}

test('dragging sends the visible badge order to its field action', () => {
    const { component, calls } = editor(['2', '0', '1']);
    component.reorder({ oldDraggableIndex: 2, newDraggableIndex: 0, item: { getAttribute: () => '2' } });
    assert.deepEqual(calls, [['fieldTransforms', { order: ['2', '0', '1'] }, { schemaComponent: 'form.mapping' }]]);
});

test('dropping in the same position makes no request', () => {
    const { component, calls } = editor(['0', '2']);
    component.reorder({ oldDraggableIndex: 1, newDraggableIndex: 1 });
    assert.deepEqual(calls, []);
});

test('keyboard moves preserve every badge and respect the list boundaries', () => {
    const { component, calls } = editor(['0', '1', '2']);
    component.move('1', -1);
    component.move('1', 1);
    component.move('0', -1);
    component.move('2', 1);
    component.move('missing', 1);
    assert.deepEqual(calls.map(([, args]) => args.order), [['1', '0', '2'], ['0', '2', '1']]);
});

test('read-only controls cannot send drag or keyboard mutations', () => {
    const { component, calls } = editor(['0', '2'], true);
    component.reorder({ oldDraggableIndex: 1, newDraggableIndex: 0 });
    component.move('2', -1);
    assert.deepEqual(calls, []);
});

test('the empty Transform label and filled input space activate the native add button', (t) => {
    const originalMouseEvent = globalThis.MouseEvent;
    globalThis.MouseEvent = class {
        constructor(type, options) { this.type = type; Object.assign(this, options); }
    };
    t.after(() => {
        if (originalMouseEvent === undefined) delete globalThis.MouseEvent;
        else globalThis.MouseEvent = originalMouseEvent;
    });
    for (const keys of [[], ['0']]) {
        const { component, calls, activations } = editor(keys);
        let stopped = false;
        component.openFromSpace({ stopPropagation: () => { stopped = true; }, target: { closest: selector => keys.length === 0 && selector === '.ferry-transforms-placeholder' ? {} : null } });
        assert.equal(stopped, true);
        assert.deepEqual(activations, ['focus', ['mousedown', true, 0], 'click']);
        assert.deepEqual(calls, []);
    }
});

test('empty footer space does not open the catalog', () => {
    const { component, calls, activations } = editor([]);
    let stopped = false;
    component.openFromSpace({ stopPropagation: () => { stopped = true; }, target: { closest: () => null } });
    assert.equal(stopped, false);
    assert.deepEqual(activations, []);
    assert.deepEqual(calls, []);
});

test('chip controls, prevented clicks and disabled inputs keep their own behavior', () => {
    for (const [disabled, prevented, control, unavailable] of [
        [true, false, false, false],
        [false, true, false, false],
        [false, false, true, false],
        [false, false, false, true],
    ]) {
        const { component, calls, button, activations } = editor(['0'], disabled);
        button.disabled = unavailable;
        let stopped = false;
        component.openFromSpace({ stopPropagation: () => { stopped = true; }, defaultPrevented: prevented, target: { closest: () => control ? {} : null } });
        assert.equal(stopped, !disabled && !prevented);
        assert.deepEqual(activations, []);
        assert.deepEqual(calls, []);
    }
});

const typeMoves = [[0, 1], [0, 1, 2], [1, 2]];

function dragMove(component, draggedKey, relatedKey, after = false) {
    const items = component.$refs.items.children;
    return {
        dragged: items.find(item => item.getAttribute('x-sortable-item') === draggedKey),
        related: items.find(item => item.getAttribute('x-sortable-item') === relatedKey) ?? { getAttribute: () => null },
        willInsertAfter: after,
    };
}

test('drag positions use the server matrix without requests, including intermediate DOM orders', () => {
    const { component, calls } = editor(['0', '1', '2'], false, typeMoves);
    assert.equal(component.canMove(dragMove(component, '0', '1', true)), true);
    assert.equal(component.canMove(dragMove(component, '0', '2', true)), false);
    assert.equal(component.canMove(dragMove(component, '2', '0')), false);
    assert.equal(component.canMove(dragMove(component, '2', '1')), true);
    assert.equal(component.canMove(dragMove(component, '1', '0')), true);
    assert.equal(component.canMove(dragMove(component, '0', '0')), true);
    assert.equal(component.canMove(dragMove(component, '0', null, true)), false);
    const reordered = editor(['1', '0', '2'], false, typeMoves).component;
    assert.equal(reordered.canMove(dragMove(reordered, '0', '2', true)), false);
    component.$refs.items.dataset.allowedMoves = JSON.stringify([[0], [1], [2]]);
    assert.equal(component.canMove(dragMove(component, '0', '1', true)), false);
    assert.deepEqual(calls, []);
});

test('keyboard reorders send only permitted changes and read refreshed permissions', () => {
    const { component, calls } = editor(['0', '1', '2'], false, typeMoves);
    component.move('2', -2);
    component.move('0', 2);
    assert.deepEqual(calls, []);
    component.move('1', -1);
    assert.deepEqual(calls, [['fieldTransforms', { order: ['1', '0', '2'] }, { schemaComponent: 'form.mapping' }]]);
    component.$refs.items.dataset.allowedMoves = JSON.stringify([[0], [1], [2]]);
    component.move('1', -1);
    assert.equal(calls.length, 1);
});

test('sorting uses the public move hook and restores refused drops without a request', (t) => {
    const originalSortable = globalThis.Sortable;
    const restored = [];
    let onMove;
    globalThis.Sortable = { get: () => ({
        option: (name, callback) => { assert.equal(name, 'onMove'); onMove = callback; },
        sort: order => restored.push(order),
    }) };
    t.after(() => {
        if (originalSortable === undefined) delete globalThis.Sortable;
        else globalThis.Sortable = originalSortable;
    });
    const { component, calls } = editor(['0', '1', '2'], false, typeMoves);
    component.configureSorting(component.$refs.items);
    assert.equal(onMove(dragMove(component, '2', '0')), false);
    component.$refs.items.children = ['2', '0', '1'].map(key => ({ getAttribute: () => key }));
    component.reorder({ oldDraggableIndex: 2, newDraggableIndex: 0, item: component.$refs.items.children[0] });
    assert.deepEqual(restored, [['0', '1', '2']]);
    assert.deepEqual(calls, []);
});
