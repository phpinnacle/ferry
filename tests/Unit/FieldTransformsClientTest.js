import assert from 'node:assert/strict';
import { test } from 'node:test';
import fieldTransforms from '../../resources/js/field-transforms.js';

function editor(keys, disabled = false) {
    const calls = [];
    const component = fieldTransforms({ action: 'fieldTransforms', context: { schemaComponent: 'form.mapping' }, disabled });
    component.$refs = { items: { children: keys.map(key => ({ getAttribute: () => key })) } };
    component.$wire = { mountAction: (...args) => calls.push(args) };
    return { component, calls };
}

test('dragging sends the visible badge order to its field action', () => {
    const { component, calls } = editor(['2', '0', '1']);
    component.reorder({ oldDraggableIndex: 2, newDraggableIndex: 0 });
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
