import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../prefill.js', import.meta.url), 'utf8');
const payload = JSON.parse(await readFile(new URL('fixtures/payload-v2.json', import.meta.url), 'utf8'));

function apiFor(location = {
    hostname: 'www.drupal.org',
    pathname: '/node/add/project-module',
    hash: '',
}, document = {}) {
    const context = {
        __drupalorgCliPrefillTest: true,
        TextDecoder,
        atob,
        window: {location, setTimeout, clearTimeout},
        document,
        HTMLInputElement: FakeInput,
        HTMLTextAreaElement: FakeTextarea,
        HTMLSelectElement: FakeSelect,
        HTMLElement: FakeElement,
        Event: class { constructor(type) { this.type = type; } },
        MouseEvent: class { constructor(type) { this.type = type; } },
        KeyboardEvent: class { constructor(type) { this.type = type; } },
        console,
    };
    context.globalThis = context;
    vm.runInNewContext(source, context);

    return context.__drupalorgCliPrefill;
}

function encode(value) {
    return Buffer.from(JSON.stringify(value), 'utf8')
        .toString('base64url');
}

function plain(value) {
    return JSON.parse(JSON.stringify(value));
}

class FakeElement {
    constructor(id = '', textContent = '') {
        this.id = id;
        this.textContent = textContent;
        this.value = '';
        this.checked = false;
        this.type = 'text';
        this.events = [];
        this.classList = {contains: () => false};
    }

    dispatchEvent(event) {
        this.events.push(event.type);
    }

    click() {
        if (this.onClick) this.onClick();
    }

    focus() {}
}

class FakeInput extends FakeElement {}
class FakeTextarea extends FakeElement {}
class FakeSelect extends FakeElement {}
class FakeForm extends FakeElement {}

function reviewFormDocument() {
    const elements = new Map();
    const rows = {
        ecosystem: [],
        organization: [],
        component: [],
        documentation: [],
        screenshot: [],
    };
    const addButtons = new Map();
    const form = new FakeForm('project-module-node-form');
    form.parentNode = {insertBefore() {}};

    const add = (element) => {
        elements.set(`#${element.id}`, element);
        return element;
    };
    const addInput = (id) => {
        const input = new FakeInput(id);
        return add(input);
    };
    const addRow = (kind) => {
        const index = rows[kind].length;
        const row = {};
        if (kind === 'ecosystem') {
            row.value = addInput(`edit-field-project-ecosystem-und-${index}-target-id`);
        } else if (kind === 'organization') {
            row.name = addInput(`edit-field-supporting-organizations-und-${index}-field-supporting-organization-und-0-target-id`);
            row.helped = addInput(`edit-field-supporting-organizations-und-${index}-field-how-they-helped-und-0-value`);
        } else if (kind === 'component') {
            row.value = addInput(`edit-field-project-components-und-${index}-value`);
        } else if (kind === 'documentation') {
            row.title = addInput(`edit-field-project-documentation-und-${index}-title`);
            row.url = addInput(`edit-field-project-documentation-und-${index}-url`);
        } else {
            row.url = addInput(`edit-field-project-screenshots-und-${index}-url`);
        }
        rows[kind].push(row);
    };
    const addMore = (kind, name) => {
        const button = new FakeElement(name);
        button.onClick = () => addRow(kind);
        addButtons.set(name, button);
    };

    for (const kind of Object.keys(rows)) addRow(kind);
    addMore('ecosystem', 'field_project_ecosystem_add_more');
    addMore('organization', 'field_supporting_organizations_add_more');
    addMore('component', 'field_project_components_add_more');
    addMore('documentation', 'field_project_documentation_add_more');
    addMore('screenshot', 'field_project_screenshots_add_more');

    const title = addInput('edit-title');
    const machineName = addInput('edit-field-project-machine-name-und-0-value');
    const summary = addInput('edit-body-und-0-summary');
    const description = new FakeTextarea('edit-body-und-0-value');
    description.ckeditorInstance = {setData(value) { description.editorValue = value; }};
    add(description);
    const projectType = new FakeSelect('edit-field-project-type-und');
    projectType.options = [{value: 'full', textContent: 'Full project'}];
    add(projectType);
    const categories = new FakeSelect('edit-taxonomy-vocabulary-3-und');
    categories.options = [{value: 'site', textContent: 'Site structure', selected: false}];
    add(categories);
    const issueQueue = addInput('edit-field-project-has-issue-queue-und');
    issueQueue.type = 'checkbox';
    const issueGuidelines = addInput('edit-field-project-issue-guidelines-und-0-value');
    const issueTemplate = addInput('edit-field-issue-summary-template-und-0-value');
    const homepage = addInput('edit-field-project-homepage-und-0-url');
    for (const input of [issueGuidelines, issueTemplate, homepage]) add(input);

    const labels = [
        ['maintenance', 'Actively maintained'],
        ['development', 'Under active development'],
    ].map(([id, text]) => {
        const label = new FakeElement(id, text);
        label.htmlFor = id;
        const input = addInput(id);
        input.type = 'radio';
        label.input = input;
        return label;
    });

    return {
        readyState: 'complete',
        body: {},
        documentElement: {},
        form,
        rows,
        elements,
        addButtons,
        labels,
        querySelector(selector) {
            if (selector === '#project-module-node-form') return form;
            if (selector.endsWith('-autocomplete')) return null;
            if (selector.startsWith('[name="') && selector.endsWith('"]')) {
                const name = selector.slice(7, -2);
                return addButtons.get(name) || null;
            }
            return elements.get(selector) || null;
        },
        querySelectorAll(selector) {
            if (selector === 'label') return labels;
            if (selector === 'input[name="taxonomy_vocabulary_44[und]"]') return [labels[0].input];
            if (selector === 'input[name="taxonomy_vocabulary_46[und]"]') return [labels[1].input];
            if (selector.startsWith('#autocomplete') || selector.startsWith('.ui-autocomplete')) {
                return ['Views', 'Commerce', 'Example organization', 'Second organization']
                    .map((text) => new FakeElement('', text));
            }
            if (selector.includes('project-ecosystem') && selector.includes('target-id')) {
                return rows.ecosystem.map((row) => row.value);
            }
            if (selector.includes('supporting-organizations') && selector.endsWith('target-id"]')) {
                return rows.organization.map((row) => row.name);
            }
            if (selector.includes('project-components') && selector.endsWith('-value"]')) {
                return rows.component.map((row) => row.value);
            }
            if (selector.includes('project-documentation') && selector.endsWith('-title"]')) {
                return rows.documentation.map((row) => row.title);
            }
            if (selector.includes('project-screenshots') && selector.endsWith('-url"]')) {
                return rows.screenshot.map((row) => row.url);
            }
            return [];
        },
        getElementById(id) {
            return elements.get(`#${id}`) || null;
        },
        createElement() {
            return new FakeElement();
        },
    };
}

test('decodes and validates the version 2 payload contract', () => {
    const api = apiFor();

    assert.deepEqual(plain(api.decodePayload(encode(payload))), payload);
    assert.deepEqual(plain(api.validatePayload(payload)), payload);
});

test('rejects an unsupported payload version', () => {
    const api = apiFor();

    assert.throws(() => api.validatePayload({...payload, version: 1}), /not supported/);
});

test('rejects malformed base64url and missing required fields', () => {
    const api = apiFor();

    assert.throws(() => api.decodePayload('not-json'));
    assert.throws(() => api.validatePayload({...payload, title: ''}), /empty/);
});

test('accepts the Drupal.org form route and leaves the fragment until fill starts', () => {
    const encoded = encode(payload);
    const location = {
        hostname: 'www.drupal.org',
        pathname: '/node/add/project-module',
        hash: `#drupalorg-cli-prefill=${encoded}`,
    };

    assert.deepEqual(plain(apiFor(location).readPayload()), payload);
    assert.equal(location.hash, `#drupalorg-cli-prefill=${encoded}`);
});

test('ignores payloads outside the Drupal.org creation route', () => {
    const encoded = encode(payload);

    assert.equal(apiFor({
        hostname: 'example.test',
        pathname: '/node/add/project-module',
        hash: `#drupalorg-cli-prefill=${encoded}`,
    }).readPayload(), null);
    assert.equal(apiFor({
        hostname: 'www.drupal.org',
        pathname: '/project/example',
        hash: `#drupalorg-cli-prefill=${encoded}`,
    }).readPayload(), null);
});

test('manifests keep the companion constrained and source never submits the form', async () => {
    for (const name of ['manifest.json', 'manifest.firefox.json']) {
        const manifest = JSON.parse(await readFile(new URL(`../${name}`, import.meta.url), 'utf8'));
        assert.deepEqual(manifest.permissions, []);
        assert.deepEqual(manifest.content_scripts[0].matches, [
            'https://www.drupal.org/node/add/project-module*',
        ]);
    }
    assert.equal(source.includes('.submit('), false);
    assert.equal(source.includes('requestSubmit('), false);
});

test('fills existing and AJAX-created multivalue rows and updates CKEditor', async () => {
    const dom = reviewFormDocument();
    const context = {
        __drupalorgCliPrefillTest: true,
        TextDecoder,
        atob,
        console,
        document: dom,
        HTMLInputElement: FakeInput,
        HTMLTextAreaElement: FakeTextarea,
        HTMLSelectElement: FakeSelect,
        HTMLElement: FakeElement,
        Event: class { constructor(type) { this.type = type; } },
        MouseEvent: class { constructor(type) { this.type = type; } },
        KeyboardEvent: class { constructor(type) { this.type = type; } },
        window: {
            location: {hostname: 'www.drupal.org', pathname: '/node/add/project-module', hash: ''},
            setTimeout,
            clearTimeout,
            __drupalorgCliPrefillRowWaitMs: 50,
        },
    };
    context.globalThis = context;
    vm.runInNewContext(source, context);

    const testPayload = plain(payload);
    testPayload.ecosystems = ['Views', 'Commerce'];
    testPayload.supportingOrganizations.push({name: 'Second organization', howTheyHelped: 'Provided review.'});
    testPayload.issueComponents = ['Code', 'Documentation'];
    testPayload.documentation.push({title: 'API docs', url: 'https://example.test/api'});
    testPayload.screenshots.push('https://example.test/second.png');
    const result = await context.__drupalorgCliPrefill.fillForm(testPayload);

    assert.equal(result.warnings.length, 0);
    assert.equal(dom.rows.ecosystem.length, 2);
    assert.equal(dom.rows.organization.length, 2);
    assert.equal(dom.rows.component.length, 2);
    assert.equal(dom.rows.documentation.length, 2);
    assert.equal(dom.rows.screenshot.length, 2);
    assert.equal(dom.elements.get('#edit-title').value, testPayload.title);
    assert.equal(dom.elements.get('#edit-body-und-0-value').editorValue, testPayload.descriptionHtml);
    assert.equal(dom.rows.documentation[1].url.value, 'https://example.test/api');
    assert.equal(dom.rows.screenshot[1].url.value, 'https://example.test/second.png');

    delete dom.elements.get('#edit-body-und-0-value').ckeditorInstance;
    delete dom.elements.get('#edit-body-und-0-value').editorValue;
    const fallbackResult = await context.__drupalorgCliPrefill.fillForm(testPayload);
    assert.equal(fallbackResult.warnings.length, 0);
    assert.equal(dom.elements.get('#edit-body-und-0-value').value, testPayload.descriptionHtml);
    assert.equal(dom.elements.get('#edit-body-und-0-value').editorValue, undefined);
});

test('reports missing multivalue add controls without submitting the form', async () => {
    const api = apiFor(undefined, {
        querySelector: () => null,
        querySelectorAll: () => [],
        body: {},
        documentElement: {},
    });
    const result = await api.fillForm({...payload, ecosystems: ['Views', 'Commerce']});

    assert.ok(result.warnings.some((warning) => warning.includes('Could not create another row')));
    assert.equal(source.includes('requestSubmit'), false);
});

test('keeps the fragment when no field can be filled', async () => {
    const encoded = encode(payload);
    const form = new FakeForm('project-module-node-form');
    form.parentNode = {insertBefore() {}};
    const location = {
        hostname: 'www.drupal.org',
        pathname: '/node/add/project-module',
        hash: `#drupalorg-cli-prefill=${encoded}`,
    };
    const document = {
        readyState: 'complete',
        body: {},
        documentElement: {},
        querySelector: (selector) => selector === '#project-module-node-form' ? form : null,
        querySelectorAll: () => [],
        createElement: () => new FakeElement(),
    };
    const api = apiFor(location, document);
    api.fillWhenReady(payload);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(location.hash, `#drupalorg-cli-prefill=${encoded}`);
});
