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

async function setup(failed = false) {
    const selectors = ['svg', '[data-layer]', '[data-seats]', '[data-selected]', '[data-records]', '[role=status]', '[data-geometry-warning]'];
    const nodes = Object.fromEntries(selectors.map(s => [s, new Element()]));
    nodes['[data-layer]'].value = 'pc';
    const root = {dataset: {source: '/maps/up-pilot.json', finder: '/india/elections/constituencies'}, querySelector: s => nodes[s]};
    const collection = JSON.parse(readFileSync(path.join(__dirname, '../public/maps/up-pilot.json')));
    vm.runInNewContext(readFileSync(path.join(__dirname, '../public/js/electoral-map-pilot.js'), 'utf8'), {
        document: {querySelector: () => root, createElementNS: () => new Element()},
        location: {origin: 'https://pollmedia.example'}, URL, URLSearchParams,
        Option: function(label, value) {this.label = label; this.value = value;},
        fetch: async () => ({ok: !failed, json: async () => collection}),
    });
    await new Promise(resolve => setImmediate(resolve));
    return nodes;
}

test('PC/AC selection keeps seat codes separate and produces state-scoped record links', async () => {
    const n = await setup();
    assert.equal(n.svg.children.length, 80);
    assert.match(n['[data-selected]'].textContent, /Pilibhit.*PC 26/);
    assert.equal(new URL(n['[data-records]'].href).searchParams.get('state'), 'Uttar Pradesh');
    n['[data-seats]'].value = '44'; n['[data-seats]'].events.change();
    assert.equal(n['[data-geometry-warning]'].hidden, false);
    assert.match(n['[data-geometry-warning]'].textContent, /Self-intersection/);
    assert.equal(n.svg.children.find(p => p.dataset.code === '44').dataset.geometryStatus, 'flagged');
    n['[data-layer]'].value = 'ac'; n['[data-layer]'].events.change();
    assert.equal(n.svg.children.length, 403);
    assert.equal(n['[data-records]'].hidden, true);
    n['[data-seats]'].value = '127'; n['[data-seats]'].events.change();
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
