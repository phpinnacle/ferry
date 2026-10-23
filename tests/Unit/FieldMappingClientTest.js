import assert from 'node:assert/strict';
import { test } from 'node:test';
import fieldMapping from '../../resources/js/field-mapping.js';

function sourceEntry(source) { return { kind: 'source', source }; }
function value(value) { return { kind: 'value', value }; }

const scalarModes = { title: { name: 'whole', contact: 'whole' }, email: { name: 'whole', contact: 'whole' } };
const itemModes = { title: { name: 'item', contact: 'whole' }, email: { name: 'item', contact: 'whole' } };

function editor(state = {}, disabled = false, options = {}) {
    const ports = [
        ['source', 'title', 100, 80],
        ['source', 'email', 100, 160],
        ['target', 'name', 400, 80],
        ['target', 'contact', 400, 160],
    ].map(([side, id, left, top]) => ({
        dataset: { side, id, label: id },
        getBoundingClientRect: () => ({ left: options.inverse ? 500 - left : left, top, width: 16, height: 16 }),
        querySelector() { return this; },
    }));
    const connectionModes = structuredClone(options.connectionModes ?? scalarModes);
    const component = fieldMapping({ state: { sources: options.sourceSettings ?? {}, targets: state }, disabled, connectionModes, requiredTargets: options.requiredTargets, inverse: options.inverse, stateKeys: options.stateKeys, staticDefaults: options.staticDefaults });
    component.$root = { querySelectorAll: (selector) => selector === '[data-mapping-port]' ? ports : [] };
    component.$refs = { canvas: { getBoundingClientRect: () => ({ left: 20, top: 40, width: 500, height: 300 }) } };
    component.$nextTick = (callback) => callback();
    return component;
}

test('native static editors switch modes locally and preserve typed defaults', () => {
    const component = editor({}, false, {
        stateKeys: { name: 'encoded_name', contact: 'encoded_contact' },
        staticDefaults: { name: false },
    });
    component.setMappings({});
    assert.deepEqual(component.state.targets, { encoded_name: value(false) });
    component.state.targets.encoded_name.value = true;
    component.connect('title', 'name');
    assert.deepEqual(component.state.targets, { encoded_name: sourceEntry('title') });
    component.refresh();
    assert.deepEqual(component.connections.map(({ source, target }) => ({ source, target })), [{ source: 'title', target: 'name' }]);
    component.remove('name');
    assert.deepEqual(component.state.targets, { encoded_name: value(false) });
});

for (const [type, initial, edit] of [
    ['array', ['default'], value => value.push('edited')],
    ['object', { options: { enabled: true } }, value => { value.options.enabled = false; }],
]) {
    test(`repeated disconnects restore an independent ${type} default from reactive state`, () => {
        const expected = structuredClone(initial);
        const defaults = { name: new Proxy(initial, {}) };
        const component = editor({}, false, { staticDefaults: defaults });
        component.setMappings({});

        for (let cycle = 0; cycle < 2; cycle++) {
            edit(component.state.targets.name.value);
            assert.deepEqual(defaults.name, expected);
            component.connect('title', 'name');
            component.remove('name');
            assert.deepEqual(component.state.targets.name, value(expected));
        }
    });
}

for (const value of [null, '', false, 0, []]) {
    test(`removing a connection restores its static form default ${JSON.stringify(value)} as a mapped value`, () => {
        const component = editor({ name: sourceEntry('title') }, false, {
            staticDefaults: { name: value }, requiredTargets: ['name'],
        });
        component.remove('name', 'title');
        assert.deepEqual(component.state.targets, { name: { kind: 'value', value } });
        assert.equal(component.isMapped('target', 'name'), true);
        assert.equal(component.mappedTargetCount(), 1);
        assert.deepEqual(component.missingRequired(), []);
        component.refresh();
        assert.deepEqual(component.connections, []);
        assert.equal(component.isDisabled('source', 'title'), false);
    });
}

test('connects by selecting fields in either order and appends sources to a multiple destination', () => {
    const component = editor({}, false, { connectionModes: itemModes });
    component.select('source', 'title');
    component.select('target', 'name');
    component.select('target', 'name');
    component.select('source', 'email');
    assert.deepEqual(component.state.targets, { name: [sourceEntry('title'), sourceEntry('email')] });
    component.remove('name', 'email');
    component.select('source', 'email');
    component.select('target', 'contact');
    assert.deepEqual(component.state.targets, { name: [sourceEntry('title')], contact: sourceEntry('email') });
    assert.equal(component.selected, null);
});

test('removing a connection frees a source and connecting the same pair is allowed', () => {
    const component = editor({ name: sourceEntry('title'), contact: sourceEntry('email') });
    component.connect('title', 'name');
    component.remove('name');
    assert.equal(component.isDisabled('source', 'title'), false);
    component.connect('title', 'contact');
    assert.deepEqual(component.state.targets, { contact: sourceEntry('title') });
});

for (const inverse of [false, true]) {
    test(`${inverse ? 'inverse' : 'forward'}: selection marks compatible candidates and dims incompatible fields regardless of mappings`, () => {
        const state = { name: sourceEntry('title') };
        const component = editor(state, false, {
            inverse,
            connectionModes: { title: { name: 'whole', contact: 'whole' }, email: {} },
        });

        for (const [side, id] of [['source', 'title'], ['target', 'name']]) {
            assert.equal(component.rowClasses(side, id, false)['is-mapped'], true);
            assert.equal(component.rowClasses(side, id, false)['is-incompatible'], false);
        }
        assert.equal(component.fieldBindings('source', 'title')[':disabled'](), true);

        component.select('target', 'contact');
        assert.equal(component.rowClasses('source', 'title', false)['is-incompatible'], true);
        assert.equal(component.rowClasses('source', 'email', false)['is-incompatible'], true);
        component.cancel();
        component.select('source', 'email');
        assert.equal(component.rowClasses('target', 'name', false)['is-incompatible'], true);
        assert.equal(component.rowClasses('target', 'contact', false)['is-incompatible'], true);
        assert.deepEqual(component.state.targets, state);
    });
}

test('multiple permission applies only to listed destinations and still enforces types', () => {
    const component = editor({}, false, { connectionModes: itemModes });
    component.connect('title', 'name');
    component.connect('email', 'name');
    assert.deepEqual(component.state.targets, { name: [sourceEntry('title'), sourceEntry('email')] });
    assert.equal(component.isDisabled('source', 'title'), true);
    component.connect('title', 'contact');
    assert.deepEqual(component.state.targets, { name: [sourceEntry('title'), sourceEntry('email')] });
    component.remove('name');
    component.connect('title', 'contact');
    component.connect('email', 'contact');
    assert.deepEqual(component.state.targets, { contact: sourceEntry('email') });

    delete component.connectionModes.title.name;
    component.connect('title', 'name');
    assert.deepEqual(component.state.targets, { contact: sourceEntry('email') });
});

test('occupied source ports cannot be selected or connected from a destination port', () => {
    for (const side of ['source', 'target']) {
        const component = editor({ name: sourceEntry('title') });
        const id = side === 'source' ? 'title' : 'contact';
        component.fieldBindings(side, id)['@click']();
        if (side === 'source') assert.equal(component.selected, null);
        component.fieldBindings(side === 'source' ? 'target' : 'source', side === 'source' ? 'contact' : 'title')['@click']();
        assert.deepEqual(component.state.targets, { name: sourceEntry('title') });
    }
});

test('field controls track source locks, selection, and read-only state after creation', () => {
    const component = editor({ name: sourceEntry('title') });
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
    assert.deepEqual(component.state.targets, { contact: sourceEntry('title') });
    assert.equal(source[':disabled'](), true);
    assert.equal(source[':aria-pressed'](), false);

    component.disabled = true;
    assert.equal(target[':disabled'](), true);
    assert.equal(target[':aria-disabled'](), true);
});

for (const inverse of [false, true]) {
    for (const firstSide of ['source', 'target']) {
        test(`${inverse ? 'inverse' : 'forward'}: incompatible controls ignore clicks after selecting a ${firstSide}`, () => {
            const component = editor({}, false, {
                inverse,
                connectionModes: { title: { contact: 'whole' }, email: { name: 'whole', contact: 'whole' } },
            });
            const secondSide = firstSide === 'source' ? 'target' : 'source';
            const firstId = firstSide === 'source' ? 'title' : 'name';
            const secondId = secondSide === 'source' ? 'title' : 'name';
            component.select(firstSide, firstId);
            const announcement = component.announcement;
            const candidate = component.fieldBindings(secondSide, secondId);

            assert.equal(candidate[':aria-disabled'](), true);
            candidate['@click']();
            candidate['@click']();
            assert.deepEqual(component.state.targets, {});
            assert.deepEqual(component.selected, { side: firstSide, id: firstId });
            assert.equal(component.announcement, announcement);
            assert.equal(component.rowClasses('source', 'title', false)['has-type-conflict'], false);

            component.connectionModes.title.name = 'whole';
            candidate['@click']();
            assert.deepEqual(component.state.targets, { name: sourceEntry('title') });
            assert.equal(component.selected, null);
        });
    }
}

test('separate editors keep their state and port geometry independent', () => {
    const first = editor({ name: sourceEntry('title') });
    const second = editor({ name: sourceEntry('title') });
    first.refresh();
    second.refresh();
    first.port('source', 'title').getBoundingClientRect = () => ({ left: 0, top: 0, width: 0, height: 0 });
    first.refresh();
    second.refresh();

    assert.equal(first.connections.length, 0);
    assert.equal(second.connections.length, 1);
    first.remove('name');
    assert.deepEqual(first.state.targets, {});
    assert.deepEqual(second.state.targets, { name: sourceEntry('title') });
});

test('read-only fields do not change state through selection, port clicks, or removal', () => {
    const component = editor({ name: sourceEntry('title') }, true);
    component.select('source', 'email');
    component.connect('email', 'name');
    component.remove('name');
    component.fieldBindings('source', 'email')['@click']();
    component.fieldBindings('target', 'name')['@click']();
    assert.deepEqual(component.state.targets, { name: sourceEntry('title') });
    assert.equal(component.selected, null);
});

test('search and unmapped filters affect visibility without deleting mappings', () => {
    const component = editor({ name: sourceEntry('title') });
    component.sourceSearch = ' EMAIL ';
    assert.equal(component.isVisible('source', 'email', 'Email address'), true);
    assert.equal(component.isVisible('source', 'title', 'Display name'), false);
    component.sourceSearch = '';
    component.unmappedOnly = true;
    assert.equal(component.isVisible('source', 'title', 'Display name'), false);
    assert.equal(component.isVisible('target', 'name', 'Customer name'), false);
    assert.equal(component.isVisible('source', 'email', 'Email'), true);
    assert.deepEqual(component.state.targets, { name: sourceEntry('title') });
});

test('line endpoints follow the actual ports and hidden ports do not produce lines', () => {
    const component = editor({ name: sourceEntry('title'), contact: sourceEntry('email') });
    component.refresh();
    assert.equal(component.connections.length, 2);
    assert.match(component.connections[0].path, /^M 88 48 .*388 48$/);
    component.port('target', 'name').getBoundingClientRect = () => ({ left: 0, top: 0, width: 0, height: 0 });
    component.refresh();
    assert.deepEqual(component.connections.map((connection) => connection.target), ['contact']);
    assert.deepEqual(component.state.targets, { name: sourceEntry('title'), contact: sourceEntry('email') });
});

test('line endpoints follow visible anchors when their centers differ from the port buttons', () => {
    const component = editor({ name: sourceEntry('title') });
    component.port('source', 'title').querySelector = () => ({
        getBoundingClientRect: () => ({ left: 101, top: 65, width: 14, height: 14 }),
    });
    component.port('target', 'name').querySelector = () => ({
        getBoundingClientRect: () => ({ left: 401, top: 65, width: 14, height: 14 }),
    });
    component.refresh();

    assert.match(component.connections[0].path, /^M 88 32 .*388 32$/);
    assert.deepEqual(component.connections[0].midpoint, { x: 238, y: 32 });
    component.port('target', 'name').querySelector = () => ({
        getBoundingClientRect: () => ({ left: 0, top: 0, width: 0, height: 0 }),
    });
    component.refresh();
    assert.deepEqual(component.connections, []);
});

test('unknown identifiers cannot replace existing connections', () => {
    const component = editor({ name: sourceEntry('title') });
    component.connect('unknown', 'name');
    component.connect('title', 'unknown');
    component.refresh();
    assert.deepEqual(component.state.targets, { name: sourceEntry('title') });
    assert.equal(component.connections.length, 1);
});

test('port clicks draw a connection only after both endpoints are selected', () => {
    const component = editor();
    component.fieldBindings('source', 'title')['@click']();
    component.refresh();
    assert.deepEqual(component.selected, { side: 'source', id: 'title' });
    assert.deepEqual(component.state.targets, {});
    assert.deepEqual(component.connections, []);
    component.fieldBindings('target', 'name')['@click']();
    component.refresh();
    assert.deepEqual(component.state.targets, { name: sourceEntry('title') });
    assert.equal(component.selected, null);
    assert.equal(component.connections.length, 1);
    assert.match(component.connections[0].path, /^M 88 48 .*388 48$/);
});

test('destination port clicks also connect and a repeated click cancels selection', () => {
    const component = editor();
    component.fieldBindings('target', 'name')['@click']();
    component.fieldBindings('source', 'title')['@click']();
    assert.deepEqual(component.state.targets, { name: sourceEntry('title') });
    component.fieldBindings('target', 'name')['@click']();
    assert.deepEqual(component.selected, { side: 'target', id: 'name' });
    component.fieldBindings('target', 'name')['@click']();
    assert.equal(component.selected, null);
    assert.deepEqual(component.state.targets, { name: sourceEntry('title') });
});

test('Escape cancels selection and ignores incompatible clicks without changing mappings', () => {
    const component = editor({}, false, {
        connectionModes: { title: { contact: 'whole' }, email: { name: 'whole', contact: 'whole' } },
    });
    component.select('source', 'title');
    component.select('target', 'name');
    component.cancel();
    assert.equal(component.selected, null);
    assert.equal(component.isIncompatible('target', 'name'), false);
    assert.deepEqual(component.state.targets, {});
});

test('required destination progress follows connections and removal', () => {
    const component = editor({}, false, { requiredTargets: ['name', 'contact'] });
    assert.deepEqual(component.missingRequired(), ['name', 'contact']);
    component.connect('title', 'name');
    assert.deepEqual(component.missingRequired(), ['contact']);
    component.remove('name');
    assert.deepEqual(component.missingRequired(), ['name', 'contact']);
});

test('incompatible selections are ignored without replacing an existing connection', () => {
    const component = editor({ contact: sourceEntry('email') }, false, {
        connectionModes: { title: { name: 'whole' }, email: { contact: 'whole' } },
    });
    component.select('source', 'title');
    assert.equal(component.isIncompatible('target', 'name'), false);
    assert.equal(component.isIncompatible('target', 'contact'), true);
    component.select('target', 'contact');
    assert.deepEqual(component.state.targets, { contact: sourceEntry('email') });
    component.select('target', 'name');
    assert.deepEqual(component.state.targets, { contact: sourceEntry('email'), name: sourceEntry('title') });
});

test('server connection modes identify existing conflicts and allow compatible replacements', () => {
    const component = editor({ name: sourceEntry('title') }, false, {
        connectionModes: { title: { contact: 'whole' }, email: { name: 'whole', contact: 'whole' } },
    });
    assert.equal(component.hasTypeConflict('name'), true);
    component.connect('email', 'name');
    component.connect('title', 'contact');
    assert.deepEqual(component.state.targets, { name: sourceEntry('email'), contact: sourceEntry('title') });
    assert.equal(component.hasTypeConflict('name'), false);
});

for (const inverse of [false, true]) {
    const mode = inverse ? 'inverse' : 'forward';

    test(`${mode}: multiple destinations preserve every line and remove only the selected source`, () => {
        const state = { name: [sourceEntry('title'), sourceEntry('email')] };
        const component = editor(state, false, { inverse, connectionModes: itemModes, requiredTargets: ['name'] });
        component.refresh();

        assert.equal(component.connections.length, 2);
        assert.deepEqual(component.connections.map(({ source }) => source), ['title', 'email']);
        assert.equal(component.mappedTargetCount(), 1);
        assert.deepEqual(component.missingRequired(), []);
        component.connect('title', 'name');
        assert.deepEqual(component.state.targets, state);
        component.remove('name', 'title');
        assert.deepEqual(component.state.targets, { name: [sourceEntry('email')] });
        assert.equal(component.isDisabled('source', 'title'), false);
        assert.equal(component.isDisabled('source', 'email'), true);
        assert.deepEqual(component.missingRequired(), []);

        component.connect('title', 'contact');
        component.remove('name');
        assert.deepEqual(component.state.targets, { contact: sourceEntry('title') });
        assert.deepEqual(component.missingRequired(), ['name']);
    });

    test(`${mode}: port clicks append a source and drawing follows the visible left-to-right direction`, () => {
        const component = editor({ name: [sourceEntry('title')] }, false, { inverse, connectionModes: itemModes });
        component.fieldBindings('target', 'name')['@click']();
        component.refresh();
        assert.equal(component.connections.length, 1);
        component.fieldBindings('source', 'email')['@click']();
        assert.deepEqual(component.state.targets, { name: [sourceEntry('title'), sourceEntry('email')] });
        component.refresh();
        assert.equal(component.connections.length, 2);
        assert.match(component.connections[1].path, inverse ? /^M 88 48 .*388 128$/ : /^M 88 128 .*388 48$/);
        assert.deepEqual(component.connections[1].midpoint, { x: 238, y: 88 });
    });

    test(`${mode}: conflicts are checked for every source in a multiple destination`, () => {
        const component = editor({ name: [sourceEntry('title'), sourceEntry('email')] }, false, {
            inverse,
            connectionModes: { title: { name: 'item', contact: 'whole' }, email: { contact: 'whole' } },
        });
        assert.equal(component.hasTypeConflict('name'), true);
        component.remove('name', 'email');
        assert.equal(component.hasTypeConflict('name'), false);
        component.connect('email', 'name');
        assert.deepEqual(component.state.targets, { name: [sourceEntry('title')] });
    });

    test(`${mode}: removing the final line removes its state key and read-only mode preserves all lines`, () => {
        const state = { name: [sourceEntry('title')] };
        const component = editor(state, true, { inverse, connectionModes: itemModes });
        component.connect('email', 'name');
        component.remove('name', 'title');
        assert.deepEqual(component.state.targets, state);
        component.disabled = false;
        component.remove('name', 'title');
        assert.deepEqual(component.state.targets, {});
        assert.equal(component.isMapped('target', 'name'), false);
    });
}

test('inverse: destination selection follows the same rules as the forward layout', () => {
    const component = editor({ name: [sourceEntry('title')], contact: sourceEntry('email') }, false, { inverse: true, connectionModes: itemModes });
    assert.equal(component.isDisabled('target', 'name'), false);
    assert.equal(component.isDisabled('target', 'contact'), false);
    assert.equal(component.isDisabled('source', 'title'), true);
    component.select('target', 'contact');
    component.fieldBindings('target', 'contact')['@click']();
    assert.equal(component.selected, null);
    component.remove('contact');
    assert.equal(component.isDisabled('target', 'contact'), false);
    component.select('target', 'name');
    component.select('source', 'email');
    assert.deepEqual(component.state.targets, { name: [sourceEntry('title'), sourceEntry('email')] });
});

test('empty Livewire state can be connected in either orientation', () => {
    for (const inverse of [false, true]) {
        for (const state of [null, []]) {
            const component = editor(state, false, { inverse, connectionModes: itemModes });
            component.refresh();
            assert.deepEqual(component.connections, []);
            component.connect('title', 'name');
            assert.deepEqual(component.state.targets, { name: [sourceEntry('title')] });
        }
    }
});

test('a static value occupies a destination without drawing a line or locking a source', () => {
    const component = editor({ name: value('Customer'), contact: sourceEntry('email') }, false, { requiredTargets: ['name'] });
    component.refresh();

    assert.equal(component.isConstant('name'), true);
    assert.equal(component.isConstant('contact'), false);
    assert.equal(component.isMapped('target', 'name'), true);
    assert.equal(component.mappedTargetCount(), 2);
    assert.deepEqual(component.missingRequired(), []);
    assert.deepEqual(component.connections.map((connection) => connection.target), ['contact']);
    assert.equal(component.isDisabled('source', 'title'), false);
});

test('inline static edits are reflected in mappings and connections replace their editor', () => {
    const component = editor({ contact: sourceEntry('email'), name: value('') });
    component.state.targets.contact = value('x');

    component.connect('title', 'name');
    assert.deepEqual(component.state.targets, { contact: value('x'), name: sourceEntry('title') });
    component.remove('contact');
    assert.deepEqual(component.state.targets, { name: sourceEntry('title') });
});

for (const inverse of [false, true]) {
    test(`${inverse ? 'inverse' : 'forward'}: read-only connections cannot replace static values`, () => {
        const component = editor({ name: value('Customer') }, true, { inverse });
        component.connect('title', 'name');
        component.remove('name');
        assert.deepEqual(component.state.targets, { name: value('Customer') });
    });
}

for (const inverse of [false, true]) {
    test(`${inverse ? 'inverse' : 'forward'}: whole arrays and item collections replace each other without flattening`, () => {
        const component = editor({}, false, { inverse, connectionModes: {
            title: { name: 'whole' }, email: { name: 'item' },
        } });
        component.connect('email', 'name');
        assert.deepEqual(component.state.targets, { name: [sourceEntry('email')] });
        assert.equal(component.targetMode('name'), 'item');
        component.connect('title', 'name');
        assert.deepEqual(component.state.targets, { name: sourceEntry('title') });
        assert.equal(component.targetMode('name'), 'whole');
        assert.equal(component.isDisabled('source', 'email'), false);
        assert.equal(component.isDisabled('target', 'name'), false);
        component.refresh();
        assert.equal(component.connections.length, 1);
        component.remove('name', 'title');
        assert.deepEqual(component.state.targets, {});
        component.connect('email', 'name');
        assert.deepEqual(component.state.targets, { name: [sourceEntry('email')] });
    });
}

for (const inverse of [false, true]) {
    test(`${inverse ? 'inverse' : 'forward'}: connections and removals preserve arbitrary source settings`, () => {
        const sourceSettings = { title: { alias: 'Name', enabled: false }, email: { format: 'lowercase' } };
        const component = editor({}, false, { inverse, sourceSettings, connectionModes: itemModes });
        component.connect('title', 'name');
        component.connect('email', 'name');
        component.remove('name', 'email');
        component.connect('email', 'contact');
        component.remove('name');
        assert.deepEqual(component.state.sources, sourceSettings);
        assert.deepEqual(component.state.targets, { contact: sourceEntry('email') });
    });
}

for (const inverse of [false, true]) {
    for (const occupied of [sourceEntry('title'), value(false)]) {
        test(`${inverse ? 'inverse' : 'forward'}: occupied targets show availability from types during selection (${occupied.kind})`, () => {
            const state = { name: occupied, contact: value('') };
            const component = editor(state, false, {
                inverse,
                connectionModes: { title: { name: 'whole' }, email: { contact: 'whole' } },
            });
            component.select('source', 'email');
            assert.equal(component.rowClasses('target', 'name', false)['is-incompatible'], true);
            assert.equal(component.rowClasses('target', 'name', false)['is-compatible'], false);
            assert.equal(component.fieldBindings('target', 'name')[':aria-disabled'](), true);
            assert.equal(component.rowClasses('target', 'contact', false)['is-compatible'], true);
            component.select('target', 'name');
            assert.deepEqual(component.state.targets, state);
            component.select('target', 'contact');
            assert.deepEqual(component.state.targets, { name: occupied, contact: sourceEntry('email') });
            assert.equal(component.rowClasses('target', 'name', false)['is-incompatible'], false);
            assert.equal(component.rowClasses('target', 'contact', false)['is-compatible'], false);
        });
    }
}


for (const inverse of [false, true]) {
    test(`${inverse ? 'inverse' : 'forward'}: hover and focus highlight only the connection endpoints`, () => {
        const component = editor({ name: [sourceEntry('title'), sourceEntry('email')] }, false, { inverse, connectionModes: itemModes });
        component.refresh();
        component.hoveredConnection = component.connections[0];
        assert.equal(component.rowClasses('source', 'title', false)['is-disconnecting'], true);
        assert.equal(component.rowClasses('target', 'name', false)['is-disconnecting'], true);
        assert.equal(component.rowClasses('source', 'email', false)['is-disconnecting'], false);
        assert.equal(component.rowClasses('target', 'contact', false)['is-disconnecting'], false);
        component.focusedConnection = component.connections[1];
        component.hoveredConnection = null;
        assert.equal(component.rowClasses('source', 'title', false)['is-disconnecting'], false);
        assert.equal(component.rowClasses('source', 'email', false)['is-disconnecting'], true);
        component.refresh();
        assert.equal(component.rowClasses('source', 'email', false)['is-disconnecting'], true);
        component.remove('name', 'email');
        component.refresh();
        assert.equal(component.focusedConnection, null);
        assert.equal(component.rowClasses('source', 'email', false)['is-disconnecting'], false);
    });
}

test('disabled connections never highlight their endpoints', () => {
    const component = editor({ name: sourceEntry('title') }, true);
    component.refresh();
    component.hoveredConnection = component.connections[0];
    component.focusedConnection = component.connections[0];
    assert.equal(component.rowClasses('source', 'title', false)['is-disconnecting'], false);
    assert.equal(component.rowClasses('target', 'name', false)['is-disconnecting'], false);
});


for (const inverse of [false, true]) {
    for (const firstSide of ['source', 'target']) {
        test(`${inverse ? 'inverse' : 'forward'}: empty card footer connects ${firstSide} first without capturing controls`, () => {
            const component = editor({}, false, { inverse });
            const currentTarget = { contains: () => true };
            const emptySpace = { currentTarget, target: { closest: () => null } };
            const control = { currentTarget, target: { closest: () => ({}) } };
            const firstId = firstSide === 'source' ? 'email' : 'contact';
            const secondSide = firstSide === 'source' ? 'target' : 'source';
            const secondId = firstSide === 'source' ? 'contact' : 'email';
            component.selectFromCard(control, firstSide, firstId);
            assert.equal(component.selected, null);
            component.selectFromCard(emptySpace, firstSide, firstId);
            assert.deepEqual(component.selected, { side: firstSide, id: firstId });
            component.selectFromCard(control, secondSide, secondId);
            assert.deepEqual(component.selected, { side: firstSide, id: firstId });
            assert.deepEqual(component.state.targets, {});
            component.selectFromCard(emptySpace, secondSide, secondId);
            assert.equal(component.selected, null);
            assert.deepEqual(component.state.targets, { contact: sourceEntry('email') });
        });
    }
}


test('an interactive group outside the card does not block empty card clicks', () => {
    const component = editor();
    component.selectFromCard({
        target: { closest: () => ({}) },
        currentTarget: { contains: () => false },
    }, 'source', 'email');
    assert.deepEqual(component.selected, { side: 'source', id: 'email' });
});

test('static source cards use local connections and retain their values and settings after disconnecting', () => {
    const component = editor({}, false, { sourceSettings: { encoded_static: { transforms: [] } } });
    component.state.constants = { title: { static: 'Customer' } };
    component.connect('title', 'name');
    assert.deepEqual(component.state.targets, { name: sourceEntry('title') });
    assert.deepEqual(component.state.constants, { title: { static: 'Customer' } });
    component.remove('name', 'title');
    assert.deepEqual(component.state.targets, {});
    assert.deepEqual(component.state.constants, { title: { static: 'Customer' } });
    assert.deepEqual(component.state.sources, { encoded_static: { transforms: [] } });
    component.connect('title', 'contact');
    assert.deepEqual(component.state.targets, { contact: sourceEntry('title') });
});
