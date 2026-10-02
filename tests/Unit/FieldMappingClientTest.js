import assert from 'node:assert/strict';
import { test } from 'node:test';
import fieldMapping from '../../resources/js/field-mapping.js';

function editor(state = {}, disabled = false, options = {}) {
    const ports = [
        ['source', 'title', 100, 80],
        ['source', 'email', 100, 160],
        ['target', 'name', 400, 80],
        ['target', 'contact', 400, 160],
    ].map(([side, id, left, top]) => ({
        dataset: { side, id, label: id, type: options[`${side}Types`]?.[id] ?? '' },
        getBoundingClientRect: () => ({ left: options.inverse ? 500 - left : left, top, width: 16, height: 16 }),
    }));
    const component = fieldMapping({ state, disabled, requiredTargets: options.requiredTargets, multipleTargets: options.multipleTargets, inverse: options.inverse });
    component.$root = { querySelectorAll: () => ports };
    component.$refs = { canvas: { getBoundingClientRect: () => ({ left: 20, top: 40, width: 500, height: 300 }) } };
    component.$nextTick = (callback) => callback();
    return component;
}

test('connects by selecting fields in either order and appends sources to a multiple destination', () => {
    const component = editor({}, false, { multipleTargets: ['name'] });
    component.select('source', 'title');
    component.select('target', 'name');
    component.select('target', 'name');
    component.select('source', 'email');
    assert.deepEqual(component.state, { name: ['title', 'email'] });
    component.remove('name', 'email');
    component.select('source', 'email');
    component.select('target', 'contact');
    assert.deepEqual(component.state, { name: ['title'], contact: 'email' });
    assert.equal(component.selected, null);
});

test('occupied sources cannot be selected in either selection order', () => {
    const component = editor({ name: 'title', contact: 'email' });
    component.select('source', 'title');
    assert.equal(component.selected, null);
    component.select('target', 'contact');
    assert.deepEqual(component.state, { name: 'title', contact: 'email' });
    assert.equal(component.connectionError, '');

    component.cancel();
    component.select('target', 'contact');
    assert.equal(component.isIncompatible('source', 'title'), true);
    component.select('source', 'title');
    assert.deepEqual(component.state, { name: 'title', contact: 'email' });
    assert.deepEqual(component.selected, { side: 'target', id: 'contact' });

    component.connect('title', 'contact');
    assert.match(component.connectionError, /already has a connection/);
});

test('removing a connection frees a source and connecting the same pair is allowed', () => {
    const component = editor({ name: 'title', contact: 'email' });
    component.connect('title', 'name');
    assert.equal(component.connectionError, '');
    component.remove('name');
    assert.equal(component.isSourceLocked('title'), false);
    component.connect('title', 'contact');
    assert.deepEqual(component.state, { contact: 'title' });
});

test('multiple permission applies only to listed destinations and still enforces types', () => {
    const component = editor({}, false, { multipleTargets: ['name'] });
    component.connect('title', 'name');
    component.connect('email', 'name');
    assert.deepEqual(component.state, { name: ['title', 'email'] });
    assert.equal(component.isSourceLocked('title'), true);
    component.connect('title', 'contact');
    assert.deepEqual(component.state, { name: ['title', 'email'] });
    component.remove('name');
    component.connect('title', 'contact');
    component.connect('email', 'contact');
    assert.deepEqual(component.state, { contact: 'email' });

    component.port('source', 'title').dataset.type = 'boolean';
    component.port('target', 'name').dataset.type = 'string';
    component.connect('title', 'name');
    assert.deepEqual(component.state, { contact: 'email' });
    assert.match(component.connectionError, /incompatible types/);
});

test('occupied source ports cannot be selected or connected from a destination port', () => {
    for (const side of ['source', 'target']) {
        const component = editor({ name: 'title' });
        const id = side === 'source' ? 'title' : 'contact';
        component.fieldBindings(side, id)['@click']();
        if (side === 'source') assert.equal(component.selected, null);
        component.fieldBindings(side === 'source' ? 'target' : 'source', side === 'source' ? 'contact' : 'title')['@click']();
        assert.deepEqual(component.state, { name: 'title' });
        assert.equal(component.connectionError, '');
    }
});

test('removing a connection preserves the other connections', () => {
    const component = editor({ name: 'title', contact: 'email' });
    component.remove('name');
    assert.deepEqual(component.state, { contact: 'email' });
});

test('field controls track source locks, selection, and read-only state after creation', () => {
    const component = editor({ name: 'title' });
    const source = component.fieldBindings('source', 'title');
    const target = component.fieldBindings('target', 'contact');

    assert.equal(source[':disabled'](), true);
    assert.equal(source[':aria-disabled'](), true);
    assert.equal(target[':disabled'](), false);
    source['@click']();
    assert.equal(source[':aria-pressed'](), false);

    component.remove('name');
    assert.equal(source[':disabled'](), false);
    source['@click']();
    assert.equal(source[':aria-pressed'](), true);
    target['@click']();
    assert.deepEqual(component.state, { contact: 'title' });
    assert.equal(source[':disabled'](), true);
    assert.equal(source[':aria-pressed'](), false);

    component.disabled = true;
    assert.equal(target[':disabled'](), true);
    assert.equal(target[':aria-disabled'](), true);
});

test('type-incompatible controls stay selectable to explain why a connection is rejected', () => {
    const component = editor({}, false, {
        sourceTypes: { title: 'string' }, targetTypes: { name: 'boolean' },
    });
    const target = component.fieldBindings('target', 'name');
    component.select('source', 'title');

    assert.equal(target[':aria-disabled'](), true);
    assert.equal(target[':disabled'](), false);
    target['@click']();
    assert.deepEqual(component.state, {});
    assert.match(component.connectionError, /incompatible types/);
});

test('separate editors keep their state and port geometry independent', () => {
    const first = editor({ name: 'title' });
    const second = editor({ name: 'title' });
    first.refresh();
    second.refresh();
    first.port('source', 'title').getBoundingClientRect = () => ({ left: 0, top: 0, width: 0, height: 0 });
    first.refresh();
    second.refresh();

    assert.equal(first.connections.length, 0);
    assert.equal(second.connections.length, 1);
    first.remove('name');
    assert.deepEqual(first.state, {});
    assert.deepEqual(second.state, { name: 'title' });
});

test('read-only fields do not change state through selection, port clicks, or removal', () => {
    const component = editor({ name: 'title' }, true);
    component.select('source', 'email');
    component.connect('email', 'name');
    component.remove('name');
    component.fieldBindings('source', 'email')['@click']();
    component.fieldBindings('target', 'name')['@click']();
    assert.deepEqual(component.state, { name: 'title' });
    assert.equal(component.selected, null);
});

test('search and unmapped filters affect visibility without deleting mappings', () => {
    const component = editor({ name: 'title' });
    component.sourceSearch = ' EMAIL ';
    assert.equal(component.isVisible('source', 'email', 'Email address'), true);
    assert.equal(component.isVisible('source', 'title', 'Display name'), false);
    component.sourceSearch = '';
    component.unmappedOnly = true;
    assert.equal(component.isVisible('source', 'title', 'Display name'), false);
    assert.equal(component.isVisible('target', 'name', 'Customer name'), false);
    assert.equal(component.isVisible('source', 'email', 'Email'), true);
    assert.deepEqual(component.state, { name: 'title' });
});

test('line endpoints follow the actual ports and hidden ports do not produce lines', () => {
    const component = editor({ name: 'title', contact: 'email' });
    component.refresh();
    assert.equal(component.connections.length, 2);
    assert.match(component.connections[0].path, /^M 88 48 .*388 48$/);
    component.port('target', 'name').getBoundingClientRect = () => ({ left: 0, top: 0, width: 0, height: 0 });
    component.refresh();
    assert.deepEqual(component.connections.map((connection) => connection.target), ['contact']);
    assert.deepEqual(component.state, { name: 'title', contact: 'email' });
});

test('unknown identifiers cannot replace existing connections', () => {
    const component = editor({ name: 'title' });
    component.connect('unknown', 'name');
    component.connect('title', 'unknown');
    component.refresh();
    assert.deepEqual(component.state, { name: 'title' });
    assert.equal(component.connections.length, 1);
});

test('port clicks draw a connection only after both endpoints are selected', () => {
    const component = editor();
    component.fieldBindings('source', 'title')['@click']();
    component.refresh();
    assert.deepEqual(component.selected, { side: 'source', id: 'title' });
    assert.deepEqual(component.state, {});
    assert.deepEqual(component.connections, []);
    component.fieldBindings('target', 'name')['@click']();
    component.refresh();
    assert.deepEqual(component.state, { name: 'title' });
    assert.equal(component.selected, null);
    assert.equal(component.connections.length, 1);
    assert.match(component.connections[0].path, /^M 88 48 .*388 48$/);
});

test('destination port clicks also connect and a repeated click cancels selection', () => {
    const component = editor();
    component.fieldBindings('target', 'name')['@click']();
    component.fieldBindings('source', 'title')['@click']();
    assert.deepEqual(component.state, { name: 'title' });
    component.fieldBindings('target', 'name')['@click']();
    assert.deepEqual(component.selected, { side: 'target', id: 'name' });
    component.fieldBindings('target', 'name')['@click']();
    assert.equal(component.selected, null);
    assert.deepEqual(component.state, { name: 'title' });
});

test('Escape cancels selection and clears a rejected connection without changing mappings', () => {
    const component = editor({}, false, {
        sourceTypes: { title: 'string' }, targetTypes: { name: 'boolean' },
    });
    component.select('source', 'title');
    component.select('target', 'name');
    assert.match(component.connectionError, /incompatible types/);
    component.cancel();
    assert.equal(component.selected, null);
    assert.equal(component.connectionError, '');
    assert.equal(component.isIncompatible('target', 'name'), false);
    assert.deepEqual(component.state, {});
});

test('required destination progress follows connections and removal', () => {
    const component = editor({}, false, { requiredTargets: ['name', 'contact'] });
    assert.deepEqual(component.missingRequired(), ['name', 'contact']);
    component.connect('title', 'name');
    assert.deepEqual(component.missingRequired(), ['contact']);
    component.remove('name');
    assert.deepEqual(component.missingRequired(), ['name', 'contact']);
});

test('incompatible selections are marked and rejected without replacing an existing connection', () => {
    const component = editor({ contact: 'email' }, false, {
        sourceTypes: { title: 'string', email: 'boolean' },
        targetTypes: { name: 'string', contact: 'boolean' },
    });
    component.select('source', 'title');
    assert.equal(component.isIncompatible('target', 'name'), false);
    assert.equal(component.isIncompatible('target', 'contact'), true);
    component.select('target', 'contact');
    assert.deepEqual(component.state, { contact: 'email' });
    assert.match(component.connectionError, /incompatible types/);
    component.select('target', 'name');
    assert.deepEqual(component.state, { contact: 'email', name: 'title' });
    assert.equal(component.connectionError, '');
});

test('destination-first port clicks reject mismatched types and highlight incompatible sources', () => {
    const component = editor({}, false, {
        sourceTypes: { title: 'string' }, targetTypes: { name: 'boolean' },
    });
    component.fieldBindings('target', 'name')['@click']();
    assert.equal(component.isIncompatible('source', 'title'), true);
    component.fieldBindings('source', 'title')['@click']();
    assert.deepEqual(component.state, {});
    assert.match(component.connectionError, /incompatible types/);
});

test('untyped fields accept typed fields in either direction and existing conflicts are visible', () => {
    const component = editor({ name: 'title' }, false, {
        sourceTypes: { title: 'string' }, targetTypes: { name: 'boolean' },
    });
    assert.equal(component.hasTypeConflict('name'), true);
    component.connect('email', 'name');
    component.connect('title', 'contact');
    assert.deepEqual(component.state, { name: 'email', contact: 'title' });
    assert.equal(component.hasTypeConflict('name'), false);
});

for (const inverse of [false, true]) {
    const mode = inverse ? 'inverse' : 'forward';

    test(`${mode}: multiple destinations preserve every line and remove only the selected source`, () => {
        const state = inverse ? { title: 'name', email: 'name' } : { name: ['title', 'email'] };
        const component = editor(state, false, { inverse, multipleTargets: ['name'], requiredTargets: ['name'] });
        component.refresh();

        assert.equal(component.connections.length, 2);
        assert.deepEqual(component.connections.map(({ source }) => source), ['title', 'email']);
        assert.equal(component.mappedTargetCount(), 1);
        assert.deepEqual(component.missingRequired(), []);
        component.connect('title', 'name');
        assert.deepEqual(component.state, state);
        component.remove('name', 'title');
        assert.deepEqual(component.state, inverse ? { email: 'name' } : { name: ['email'] });
        assert.equal(component.isSourceLocked('title'), false);
        assert.equal(component.isSourceLocked('email'), true);
        assert.deepEqual(component.missingRequired(), []);

        component.connect('title', 'contact');
        component.remove('name');
        assert.deepEqual(component.state, inverse ? { title: 'contact' } : { contact: 'title' });
        assert.deepEqual(component.missingRequired(), ['name']);
    });

    test(`${mode}: port clicks append a source and drawing follows the visible left-to-right direction`, () => {
        const component = editor(inverse ? { title: 'name' } : { name: ['title'] }, false, { inverse, multipleTargets: ['name'] });
        component.fieldBindings('target', 'name')['@click']();
        component.refresh();
        assert.equal(component.connections.length, 1);
        component.fieldBindings('source', 'email')['@click']();
        assert.deepEqual(component.state, inverse ? { title: 'name', email: 'name' } : { name: ['title', 'email'] });
        component.refresh();
        assert.equal(component.connections.length, 2);
        assert.match(component.connections[1].path, inverse ? /^M 88 48 .*388 128$/ : /^M 88 128 .*388 48$/);
    });

    test(`${mode}: conflicts are checked for every source in a multiple destination`, () => {
        const component = editor(inverse ? { title: 'name', email: 'name' } : { name: ['title', 'email'] }, false, {
            inverse,
            multipleTargets: ['name'],
            sourceTypes: { title: 'string', email: 'boolean' }, targetTypes: { name: 'string' },
        });
        assert.equal(component.hasTypeConflict('name'), true);
        component.remove('name', 'email');
        assert.equal(component.hasTypeConflict('name'), false);
        component.connect('email', 'name');
        assert.deepEqual(component.state, inverse ? { title: 'name' } : { name: ['title'] });
        assert.match(component.connectionError, /incompatible types/);
    });

    test(`${mode}: removing the final line removes its state key and read-only mode preserves all lines`, () => {
        const state = inverse ? { title: 'name' } : { name: ['title'] };
        const component = editor(state, true, { inverse, multipleTargets: ['name'] });
        component.connect('email', 'name');
        component.remove('name', 'title');
        assert.deepEqual(component.state, state);
        component.disabled = false;
        component.remove('name', 'title');
        assert.deepEqual(component.state, {});
        assert.equal(component.isMapped('target', 'name'), false);
    });
}

test('inverse: occupied single destinations cannot be selected while multiple destinations stay available', () => {
    const component = editor({ title: 'name', email: 'contact' }, false, { inverse: true, multipleTargets: ['name'] });
    assert.equal(component.isDisabled('target', 'name'), false);
    assert.equal(component.isDisabled('target', 'contact'), true);
    assert.equal(component.isDisabled('source', 'title'), true);
    component.select('target', 'contact');
    component.fieldBindings('target', 'contact')['@click']();
    assert.equal(component.selected, null);
    component.remove('contact');
    assert.equal(component.isDisabled('target', 'contact'), false);
    component.select('target', 'name');
    component.select('source', 'email');
    assert.deepEqual(component.state, { title: 'name', email: 'name' });
});

test('empty Livewire state can be connected in either orientation', () => {
    for (const inverse of [false, true]) {
        for (const state of [null, []]) {
            const component = editor(state, false, { inverse, multipleTargets: ['name'] });
            component.refresh();
            assert.deepEqual(component.connections, []);
            component.connect('title', 'name');
            assert.deepEqual(component.state, inverse ? { title: 'name' } : { name: ['title'] });
        }
    }
});
