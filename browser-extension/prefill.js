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
        if (payload.version !== 2) {
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
        for (const field of ['ecosystems', 'issueComponents', 'screenshots']) {
            if (!Array.isArray(payload[field]) || payload[field].some((value) => typeof value !== 'string')) {
                throw new Error(`The prefill field ${field} is invalid.`);
            }
        }
        if (!Array.isArray(payload.supportingOrganizations)
            || payload.supportingOrganizations.some((organization) =>
                organization === null
                || typeof organization !== 'object'
                || typeof organization.name !== 'string'
                || typeof organization.howTheyHelped !== 'string')) {
            throw new Error('The prefill supporting organizations are invalid.');
        }
        if (typeof payload.enableIssueTracker !== 'boolean'
            || typeof payload.issueGuidelines !== 'string'
            || typeof payload.issueSummaryTemplate !== 'string') {
            throw new Error('The prefill issue settings are invalid.');
        }
        for (const field of ['homepage', 'changelog', 'demo']) {
            if (typeof payload[field] !== 'string') {
                throw new Error(`The prefill resource ${field} is invalid.`);
            }
        }
        if (!Array.isArray(payload.documentation)
            || payload.documentation.some((link) =>
                link === null
                || typeof link !== 'object'
                || typeof link.title !== 'string'
                || typeof link.url !== 'string')) {
            throw new Error('The prefill documentation is invalid.');
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
        try {
            const payload = validatePayload(decodePayload(encoded));
            removeFragment();
            return payload;
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

    function setCheckbox(selector, value, warnings) {
        const element = document.querySelector(selector);
        if (!(element instanceof HTMLInputElement) || element.type !== 'checkbox') {
            warnings.push(`Checkbox not found: ${selector}`);
            return false;
        }

        element.checked = value;
        dispatchChange(element);
        return true;
    }

    function waitForAutocompleteSuggestion(element, value) {
        return new Promise((resolve) => {
            const started = Date.now();
            const findSuggestion = () => {
                const target = normalizeText(value);
                const suggestions = Array.from(document.querySelectorAll(
                    '#autocomplete li, .ui-autocomplete li',
                ));
                const suggestion = suggestions.find((item) => {
                    const text = normalizeText(item.textContent || '');
                    return text === target || text.startsWith(`${target} `);
                });
                if (suggestion instanceof HTMLElement) {
                    suggestion.dispatchEvent(new MouseEvent('mousedown', {bubbles: true}));
                    suggestion.click();
                    dispatchChange(element);
                    resolve(true);
                    return;
                }
                if (Date.now() - started >= 2000) {
                    resolve(false);
                    return;
                }
                window.setTimeout(findSuggestion, 100);
            };
            findSuggestion();
        });
    }

    function waitForAutocompleteReady(selector) {
        return new Promise((resolve) => {
            const started = Date.now();
            const check = () => {
                const element = document.querySelector(selector);
                if (!(element instanceof HTMLInputElement)) {
                    resolve(false);
                    return;
                }

                const autocomplete = document.querySelector(`${selector}-autocomplete`);
                if (autocomplete === null
                    || autocomplete.classList.contains('autocomplete-processed')) {
                    resolve(true);
                    return;
                }

                if (Date.now() - started >= MAX_WAIT_MS) {
                    resolve(false);
                    return;
                }
                window.setTimeout(check, POLL_INTERVAL_MS);
            };
            check();
        });
    }

    async function setAutocompleteValue(selector, value, warnings) {
        if (!await waitForAutocompleteReady(selector)) {
            warnings.push(`Autocomplete field not ready: ${selector}`);
            return false;
        }
        if (!setInputValue(selector, value, warnings)) {
            return false;
        }

        const element = document.querySelector(selector);
        if (!(element instanceof HTMLInputElement)) {
            return false;
        }
        element.focus();
        element.dispatchEvent(new KeyboardEvent('keyup', {bubbles: true, key: 'End'}));
        if (!await waitForAutocompleteSuggestion(element, value)) {
            warnings.push(`Autocomplete suggestion not found: ${value}`);
            return false;
        }

        return true;
    }

    async function setAutocompleteValues(selectors, values, warnings) {
        let filled = 0;
        for (const [index, value] of values.entries()) {
            const selector = selectors[index];
            if (selector === undefined) {
                warnings.push(`Autocomplete field not available for: ${value}`);
                continue;
            }
            if (await setAutocompleteValue(selector, value, warnings)) filled++;
        }

        return filled;
    }

    function fillIssueComponents(components, warnings) {
        if (components.length === 0) {
            return 0;
        }

        const inputs = Array.from(document.querySelectorAll(
            'input[id^="edit-field-project-components-und-"][id$="-value"]',
        ));
        if (inputs.length === 0) {
            warnings.push('Issue components fields not found.');
            return 0;
        }

        let filled = 0;
        components.forEach((component, index) => {
            const input = inputs[index];
            if (!(input instanceof HTMLInputElement)) {
                warnings.push(`Issue component field not available for: ${component}`);
                return;
            }
            input.value = component;
            dispatchChange(input);
            filled++;
        });

        return filled;
    }

    async function fillSupportingOrganizations(organizations, warnings) {
        let filled = 0;
        for (const [index, organization] of organizations.entries()) {
            const organizationSelector = `#edit-field-supporting-organizations-und-${index}-field-supporting-organization-und-0-target-id`;
            const helpedSelector = `#edit-field-supporting-organizations-und-${index}-field-how-they-helped-und-0-value`;
            if (await setAutocompleteValue(organizationSelector, organization.name, warnings)) filled++;
            if (setInputValue(helpedSelector, organization.howTheyHelped, warnings)) filled++;
        }

        return filled;
    }

    function fillDocumentation(documentation, warnings) {
        let filled = 0;
        documentation.forEach((link, index) => {
            const titleSelector = `#edit-field-project-documentation-und-${index}-title`;
            const urlSelector = `#edit-field-project-documentation-und-${index}-url`;
            if (setInputValue(titleSelector, link.title, warnings)) filled++;
            if (setInputValue(urlSelector, link.url, warnings)) filled++;
        });

        return filled;
    }

    async function fillForm(payload) {
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
        filled += await setAutocompleteValues(
            payload.ecosystems.map((_, index) => `#edit-field-project-ecosystem-und-${index}-target-id`),
            payload.ecosystems,
            warnings,
        );
        filled += await fillSupportingOrganizations(payload.supportingOrganizations, warnings);
        filled += fillIssueComponents(payload.issueComponents, warnings);
        if (setInputValue('#edit-field-project-issue-guidelines-und-0-value', payload.issueGuidelines, warnings)) filled++;
        if (setInputValue('#edit-field-issue-summary-template-und-0-value', payload.issueSummaryTemplate, warnings)) filled++;
        if (setCheckbox('#edit-field-project-has-issue-queue-und', payload.enableIssueTracker, warnings)) filled++;
        if (payload.homepage !== '' && setInputValue('#edit-field-project-homepage-und-0-url', payload.homepage, warnings)) filled++;
        if (payload.changelog !== '' && setInputValue('#edit-field-project-changelog-und-0-url', payload.changelog, warnings)) filled++;
        if (payload.demo !== '' && setInputValue('#edit-field-project-demo-und-0-url', payload.demo, warnings)) filled++;
        filled += fillDocumentation(payload.documentation, warnings);
        payload.screenshots.forEach((screenshot, index) => {
            if (setInputValue(`#edit-field-project-screenshots-und-${index}-url`, screenshot, warnings)) filled++;
        });

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
            if (formIsReady() && document.readyState !== 'loading') {
                fillForm(payload).then(showBanner).catch((error) => {
                    console.warn('drupalorg-cli prefill failed while filling the form.', error);
                });
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
