(() => {
    'use strict';

    const FRAGMENT_PREFIX = 'drupalorg-cli-prefill=';
    const FORM_SELECTOR = '#project-module-node-form';
    const MAX_WAIT_MS = 10000;
    const POLL_INTERVAL_MS = 100;
    const MAINTENANCE_STATUSES = [
        'Actively maintained',
        'Minimally maintained',
        'Seeking co-maintainer(s)',
        'Seeking new maintainer',
        'Unsupported',
    ];
    const DEVELOPMENT_STATUSES = [
        'Under active development',
        'Maintenance fixes only',
        'No further development',
        'Obsolete',
    ];

    function normalizeText(value) {
        return value.trim().replace(/\s+/g, ' ').toLowerCase();
    }

    function decodePayload(encoded) {
        if (encoded === '') {
            throw new Error('The prefill payload is empty.');
        }

        const padded = encoded.replace(/-/g, '+').replace(/_/g, '/')
            + '='.repeat((4 - encoded.length % 4) % 4);
        const binary = atob(padded);
        const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));

        return JSON.parse(new TextDecoder('utf-8').decode(bytes));
    }

    function validatePayload(payload) {
        if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) {
            throw new Error('The prefill payload is not an object.');
        }
        if (payload.version !== 1) {
            throw new Error('The prefill payload version is not supported.');
        }

        for (const field of [
            'title',
            'machineName',
            'summary',
            'descriptionHtml',
            'maintenanceStatus',
            'developmentStatus',
        ]) {
            if (typeof payload[field] !== 'string') {
                throw new Error(`The prefill field ${field} is invalid.`);
            }
        }
        if (payload.title.trim() === '' || payload.machineName.trim() === '') {
            throw new Error('The prefill title or machine name is empty.');
        }
        if (payload.projectType !== 'full') {
            throw new Error('The prefill project type is invalid.');
        }
        if (!MAINTENANCE_STATUSES.includes(payload.maintenanceStatus)
            || !DEVELOPMENT_STATUSES.includes(payload.developmentStatus)) {
            throw new Error('The prefill maintenance or development status is invalid.');
        }
        if (!Array.isArray(payload.categories)
            || payload.categories.length > 3
            || payload.categories.some((category) => typeof category !== 'string')) {
            throw new Error('The prefill categories are invalid.');
        }

        return payload;
    }

    function removeFragment() {
        history.replaceState(history.state, document.title, window.location.pathname + window.location.search);
    }

    function readPayload() {
        if (window.location.hostname !== 'www.drupal.org'
            || window.location.pathname !== '/node/add/project-module') {
            return null;
        }

        const fragment = window.location.hash.startsWith('#')
            ? window.location.hash.slice(1)
            : window.location.hash;
        if (!fragment.startsWith(FRAGMENT_PREFIX)) {
            return null;
        }

        const encoded = fragment.slice(FRAGMENT_PREFIX.length);
        removeFragment();
        try {
            return validatePayload(decodePayload(encoded));
        } catch (error) {
            console.warn('drupalorg-cli prefill payload was rejected.', error);
            return null;
        }
    }

    function dispatchChange(element) {
        element.dispatchEvent(new Event('input', {bubbles: true}));
        element.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function setInputValue(selector, value, warnings) {
        const element = document.querySelector(selector);
        if (!(element instanceof HTMLInputElement)
            && !(element instanceof HTMLTextAreaElement)
            && !(element instanceof HTMLSelectElement)) {
            warnings.push(`Field not found: ${selector}`);
            return false;
        }

        element.value = value;
        dispatchChange(element);
        return true;
    }

    function selectOptionByValue(selector, value, warnings) {
        const element = document.querySelector(selector);
        if (!(element instanceof HTMLSelectElement)) {
            warnings.push(`Select not found: ${selector}`);
            return false;
        }
        if (!Array.from(element.options).some((option) => option.value === value)) {
            warnings.push(`Option not found for ${selector}: ${value}`);
            return false;
        }

        element.value = value;
        dispatchChange(element);
        return true;
    }

    function selectRadioByLabel(name, text, warnings) {
        const inputs = Array.from(document.querySelectorAll(`input[name="${name}"]`));
        const normalizedTarget = normalizeText(text);
        for (const label of Array.from(document.querySelectorAll('label'))) {
            if (normalizeText(label.textContent || '') !== normalizedTarget) {
                continue;
            }

            const input = label.htmlFor
                ? document.getElementById(label.htmlFor)
                : label.querySelector('input');
            if (!(input instanceof HTMLInputElement) || !inputs.includes(input)) {
                continue;
            }

            input.checked = true;
            dispatchChange(input);
            return true;
        }

        warnings.push(`Radio option not found: ${text}`);
        return false;
    }

    function selectCategories(categories, warnings) {
        if (categories.length === 0) {
            return false;
        }

        const select = document.querySelector('#edit-taxonomy-vocabulary-3-und');
        if (!(select instanceof HTMLSelectElement)) {
            warnings.push('Categories select not found.');
            return false;
        }

        const wanted = new Set(categories.map(normalizeText));
        let selected = 0;
        for (const option of Array.from(select.options)) {
            option.selected = false;
            if (wanted.has(normalizeText(option.textContent || ''))) {
                option.selected = true;
                selected++;
            }
        }
        for (const category of categories) {
            if (!Array.from(select.options).some(
                (option) => normalizeText(option.textContent || '') === normalizeText(category),
            )) {
                warnings.push(`Category not found: ${category}`);
            }
        }

        dispatchChange(select);
        return selected > 0;
    }

    function fillForm(payload) {
        const warnings = [];
        let filled = 0;
        if (setInputValue('#edit-title', payload.title, warnings)) filled++;
        if (selectOptionByValue('#edit-field-project-type-und', payload.projectType, warnings)) filled++;
        if (setInputValue('#edit-field-project-machine-name-und-0-value', payload.machineName, warnings)) filled++;
        if (setInputValue('#edit-body-und-0-summary', payload.summary, warnings)) filled++;
        if (setInputValue('#edit-body-und-0-value', payload.descriptionHtml, warnings)) filled++;
        if (selectRadioByLabel('taxonomy_vocabulary_44[und]', payload.maintenanceStatus, warnings)) filled++;
        if (selectRadioByLabel('taxonomy_vocabulary_46[und]', payload.developmentStatus, warnings)) filled++;
        if (selectCategories(payload.categories, warnings)) filled++;

        return {filled, warnings};
    }

    function showBanner(result) {
        const banner = document.createElement('div');
        banner.id = 'drupalorg-cli-prefill-banner';
        banner.style.background = '#e7f5e8';
        banner.style.border = '1px solid #4caf50';
        banner.style.borderRadius = '4px';
        banner.style.color = '#1b5e20';
        banner.style.margin = '1rem 0';
        banner.style.padding = '0.75rem 1rem';
        banner.textContent = result.warnings.length === 0
            ? `Prefilled by drupalorg-cli: ${result.filled} fields filled. Review all fields before saving.`
            : 'Prefilled by drupalorg-cli with warnings. Some fields could not be filled. Review the form carefully.';

        const form = document.querySelector(FORM_SELECTOR);
        if (form && form.parentNode) {
            form.parentNode.insertBefore(banner, form);
        }
        for (const warning of result.warnings) {
            console.warn(`drupalorg-cli prefill: ${warning}`);
        }
    }

    function formIsReady() {
        return document.querySelector(FORM_SELECTOR) !== null;
    }

    function fillWhenReady(payload) {
        const started = Date.now();
        const attempt = () => {
            if (formIsReady()) {
                showBanner(fillForm(payload));
                return;
            }
            if (Date.now() - started < MAX_WAIT_MS) {
                window.setTimeout(attempt, POLL_INTERVAL_MS);
                return;
            }

            console.warn('drupalorg-cli prefill: project form not found.');
        };
        attempt();
    }

    const payload = readPayload();
    if (payload !== null) {
        // Submission is intentionally left to the user.
        fillWhenReady(payload);
    }
})();
