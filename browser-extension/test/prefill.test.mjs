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
}) {
    const context = {
        __drupalorgCliPrefillTest: true,
        TextDecoder,
        atob,
        window: {location},
        document: {},
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
