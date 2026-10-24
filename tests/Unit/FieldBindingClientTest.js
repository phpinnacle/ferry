import assert from 'node:assert/strict';
import { test } from 'node:test';
import fieldBinding from '../../resources/js/field-binding.js';

function editor(disabled = false) {
    return fieldBinding({
        disabled,
        sourceKeys: ['name', 'email'],
        state: {
            sources: { name: { transforms: [] } },
            bindings: { name: { destination: 'customer.name' }, email: { destination: '', replacements: [] }, constant_1: { column: 'status', value: 'active' } },
            bound: { name: true },
            previews: { name: { destination: '' }, email: { destination: '', replacements: [] } },
        },
    });
}

test('binds and unbinds locally while retaining unrelated bindings, settings, and constants', () => {
    const component = editor();
    assert.equal(component.boundCount(), 1);
    component.bind('email');
    assert.equal(component.boundCount(), 2);
    assert.equal(component.isBound('email'), true);
    component.state.bindings.email.destination = 'contact.email';
    component.state.bindings.email.replacements.push({ search: 'old', replace: 'new' });
    component.unbind('email');
    assert.equal(component.boundCount(), 1);
    assert.deepEqual(component.state.bindings.email, { destination: '', replacements: [] });
    assert.deepEqual(component.state.bindings.name, { destination: 'customer.name' });
    assert.deepEqual(component.state.bindings.constant_1, { column: 'status', value: 'active' });
    assert.deepEqual(component.state.sources, { name: { transforms: [] } });
    component.bind('email');
    component.state.bindings.email.replacements.push({ search: 'next', replace: 'value' });
    assert.deepEqual(component.state.previews.email.replacements, []);
});

test('repeated bind and unbind operations are idempotent', () => {
    const component = editor();
    const original = structuredClone(component.state);
    component.bind('name');
    component.unbind('email');
    assert.deepEqual(component.state, original);
});

test('unbind restores defaults held in a reactive proxy', () => {
    const component = editor();
    component.state.previews.name = new Proxy({ destination: '' }, {});
    component.unbind('name');
    assert.deepEqual(component.state.bindings.name, { destination: '' });
});

test('disabled bindings cannot be bound or unbound', () => {
    const component = editor(true);
    const original = structuredClone(component.state);
    component.bind('email');
    component.unbind('name');
    assert.deepEqual(component.state, original);
});

test('all, bound and unbound filters combine with search without changing editors or constants', () => {
    const component = editor();
    const state = structuredClone(component.state);
    assert.equal(component.visibilityFilter, 'all');
    for (const [filter, boundVisible, unboundVisible] of [
        ['all', true, true], ['bound', true, false], ['unbound', false, true],
    ]) {
        component.visibilityFilter = filter;
        assert.equal(component.isVisible('name', 'Customer name'), boundVisible);
        assert.equal(component.isVisible('email', 'Email address'), unboundVisible);
        assert.equal(component.matchesFilter(true), boundVisible);
        component.search = ' EMAIL ';
        assert.equal(component.isVisible('name', 'Customer name'), false);
        assert.equal(component.isVisible('email', 'Email address'), unboundVisible);
        component.search = '';
    }
    assert.deepEqual(component.state, state);
});

test('binding and unbinding immediately update visibility under the active filter', () => {
    const component = editor();
    component.visibilityFilter = 'unbound';
    assert.equal(component.isVisible('email', 'Email'), true);
    component.bind('email');
    assert.equal(component.isVisible('email', 'Email'), false);
    component.visibilityFilter = 'bound';
    assert.equal(component.isVisible('email', 'Email'), true);
    component.unbind('email');
    assert.equal(component.isVisible('email', 'Email'), false);
    component.visibilityFilter = 'all';
    assert.equal(component.isVisible('email', 'Email'), true);
});
