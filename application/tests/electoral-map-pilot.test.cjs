const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Element {
    constructor() { this.children = []; this.dataset = {}; this.attributes = {}; this.events = {}; this.value = ''; }
    replaceChildren() { this.children = []; }
    append(child) { this.children.push(child); }
    add(child) { this.append(child); }
    setAttribute(key, value) { this.attributes[key] = value; }
    getAttribute(key) { return this.attributes[key]; }
    addEventListener(key, callback) { this.events[key] = callback; }
    querySelectorAll() { return this.children; }
}

async function setup(failed = false, stateSlug = 'uttar-pradesh', initialKind = 'pc') {
    const selectors = ['svg', '[data-layer]', '[data-seats]', '[data-selected]', '[data-records]', '[role=status]', '[data-geometry-warning]'];
    const nodes = Object.fromEntries(selectors.map(s => [s, new Element()]));
    nodes['[data-layer]'].value = initialKind;
    const collection = JSON.parse(readFileSync(path.join(__dirname, `../public/maps/electoral/${stateSlug}.json`)));
    const root = {dataset: {source: `/maps/electoral/${stateSlug}.json`, state: collection.metadata.state, finder: '/india/elections/constituencies'}, querySelector: s => nodes[s]};
    vm.runInNewContext(readFileSync(path.join(__dirname, '../public/js/electoral-map-pilot.js'), 'utf8'), {
        document: {querySelector: () => root, createElementNS: () => new Element()},
        location: {origin: 'https://pollmedia.example'}, URL, URLSearchParams,
        Option: function(label, value) {this.label = label; this.value = value;},
        fetch: async () => ({ok: !failed, json: async () => collection}),
    });
    await new Promise(resolve => setImmediate(resolve));
    nodes.collection = collection;
    return nodes;
}

function choose(nodes, kind, code) {
    nodes['[data-seats]'].value = nodes.collection.features.find(f => f.properties.kind === kind && f.properties.code === code).id;
    nodes['[data-seats]'].events.change();
}

test('PC/AC selection keeps seat codes separate and produces state-scoped record links', async () => {
    const n = await setup();
    assert.equal(n.svg.children.length, 80);
    assert.match(n['[data-selected]'].textContent, /Pilibhit.*PC 26/);
    assert.equal(new URL(n['[data-records]'].href).searchParams.get('state'), 'Uttar Pradesh');
    choose(n, 'pc', 44);
    assert.equal(n['[data-geometry-warning]'].hidden, false);
    assert.match(n['[data-geometry-warning]'].textContent, /Self-intersection/);
    assert.equal(n.svg.children.find(p => p.dataset.code === '44').dataset.geometryStatus, 'flagged');
    n['[data-layer]'].value = 'ac'; await n['[data-layer]'].events.change();
    assert.equal(n.svg.children.length, 403);
    assert.equal(n['[data-records]'].hidden, true);
    choose(n, 'ac', 127);
    assert.match(n['[data-selected]'].textContent, /Pilibhit.*AC 127/);
    assert.equal(new URL(n['[data-records]'].href).searchParams.get('kind'), 'ac');
    const seat = n.svg.children.find(p => p.dataset.code === '128');
    let prevented = false;
    seat.events.keydown({key: 'Enter', preventDefault: () => {prevented = true;}});
    assert.equal(prevented, true);
    assert.match(n['[data-selected]'].textContent, /Barkhera/);
    assert.equal(seat.attributes['aria-pressed'], 'true');
    assert.doesNotMatch(n.svg.attributes.viewBox, /NaN|Infinity/);
});

test('failed data download leaves a useful directory fallback', async () => {
    const n = await setup(true);
    assert.match(n['[role=status]'].textContent, /could not load.*directory/);
    assert.equal(n.svg.children.length, 0);
});

test('every state renders PC and AC without invalid view bounds', async () => {
    const catalogue = JSON.parse(readFileSync(path.join(__dirname, '../public/maps/electoral/catalogue.json')));
    for (const state of Object.values(catalogue.states)) {
        const n = await setup(false, state.slug);
        assert.equal(n.svg.children.length, state.counts.pc, state.slug);
        assert.doesNotMatch(n.svg.attributes.viewBox, /NaN|Infinity/, state.slug);
        n['[data-layer]'].value = 'ac'; await n['[data-layer]'].events.change();
        assert.equal(n.svg.children.length, state.counts.ac, state.slug);
        assert.doesNotMatch(n.svg.attributes.viewBox, /NaN|Infinity/, state.slug);
        if (!state.counts.ac) assert.match(n['[role=status]'].textContent, /no separate boundary/);
    }
});

test('duplicate seat codes select only their individual source record', async () => {
    const n = await setup(false, 'sikkim', 'ac');
    const counts = new Map();
    for (const f of n.collection.features.filter(f => f.properties.kind === 'ac')) {
        const group = counts.get(f.properties.code) || []; group.push(f); counts.set(f.properties.code, group);
    }
    const duplicate = [...counts.values()].find(group => group.length > 1);
    assert.ok(duplicate);
    for (const record of duplicate) {
        n['[data-seats]'].value = record.id; n['[data-seats]'].events.change();
        const pressed = n.svg.children.filter(p => p.attributes['aria-pressed'] === 'true');
        assert.equal(pressed.length, 1);
        assert.equal(pressed[0].dataset.id, record.id);
        assert.equal(pressed[0].dataset.reviewStatus, 'flagged');
        assert.match(n['[data-geometry-warning]'].textContent, /share this seat code/);
    }
});

test('missing source name is labelled and cannot produce a guessed record link', async () => {
    const catalogue = JSON.parse(readFileSync(path.join(__dirname, '../public/maps/electoral/catalogue.json')));
    for (const state of Object.values(catalogue.states)) {
        const n = await setup(false, state.slug, 'ac');
        const missing = n.collection.features.find(f => f.properties.kind === 'ac' && !f.properties.name?.trim());
        if (!missing) continue;
        n['[data-seats]'].value = missing.id; n['[data-seats]'].events.change();
        assert.match(n['[data-selected]'].textContent, /Name missing in source/);
        assert.equal(n['[data-records]'].hidden, true);
        return;
    }
    assert.fail('Missing-name fixture expected in the pinned source');
});
