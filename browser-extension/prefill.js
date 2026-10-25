(() => {
    'use strict';

    const FRAGMENT_PREFIX = 'drupalorg-cli-prefill=';
    const FORM_SELECTOR = '#project-module-node-form';
    const MAX_WAIT_MS = 10000;
    const POLL_INTERVAL_MS = 100;
    const ROW_WAIT_MS = 2000;
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
        if (element instanceof HTMLTextAreaElement) {
            const editor = window.CKEDITOR?.instances?.[element.id]
                || element.ckeditorInstance
                || element.editor
                || element.__ckeditorInstance;
            if (editor && typeof editor.setData === 'function') {
                editor.setData(value);
            }
        }
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

    function rowWaitTimeout() {
        return typeof window.__drupalorgCliPrefillRowWaitMs === 'number'
            ? window.__drupalorgCliPrefillRowWaitMs
            : ROW_WAIT_MS;
    }

    function waitForRows(selector, count) {
        if (document.querySelectorAll(selector).length >= count) {
            return Promise.resolve(true);
        }

        return new Promise((resolve) => {
            const started = Date.now();
            let observer = null;
            let timer = null;
            const finish = (result) => {
                if (observer !== null) observer.disconnect();
                if (timer !== null) window.clearTimeout(timer);
                resolve(result);
            };
            const check = () => {
                if (document.querySelectorAll(selector).length >= count) {
                    finish(true);
                    return;
                }
                if (Date.now() - started >= rowWaitTimeout()) {
                    finish(false);
                    return;
                }
                timer = window.setTimeout(check, POLL_INTERVAL_MS);
            };
            if (typeof window.MutationObserver === 'function') {
                observer = new window.MutationObserver(check);
                observer.observe(document.body || document.documentElement, {
                    childList: true,
                    subtree: true,
                });
            }
            check();
        });
    }

    async function ensureNumberOfRows(rowSelector, addSelectors, requiredCount, warnings) {
        while (document.querySelectorAll(rowSelector).length < requiredCount) {
            const addButton = addSelectors
                .map((selector) => document.querySelector(selector))
                .find((element) => element !== null);
            if (addButton === undefined || typeof addButton.click !== 'function') {
                warnings.push(`Could not create another row for: ${rowSelector}`);
                return false;
            }

            addButton.click();
            if (!await waitForRows(rowSelector, Math.min(
                requiredCount,
                document.querySelectorAll(rowSelector).length + 1,
            ))) {
                warnings.push(`Timed out creating rows for: ${rowSelector}`);
                return false;
            }
        }

        return true;
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

    async function fillForm(payload, onFillStarted = null) {
        const warnings = [];
        let filled = 0;
        const markStarted = () => {
            if (onFillStarted !== null) onFillStarted();
        };
        if (setInputValue('#edit-title', payload.title, warnings)) { filled++; markStarted(); }
        if (selectOptionByValue('#edit-field-project-type-und', payload.projectType, warnings)) { filled++; markStarted(); }
        if (setInputValue('#edit-field-project-machine-name-und-0-value', payload.machineName, warnings)) { filled++; markStarted(); }
        if (setInputValue('#edit-body-und-0-summary', payload.summary, warnings)) { filled++; markStarted(); }
        if (setInputValue('#edit-body-und-0-value', payload.descriptionHtml, warnings)) { filled++; markStarted(); }
        if (selectRadioByLabel('taxonomy_vocabulary_44[und]', payload.maintenanceStatus, warnings)) { filled++; markStarted(); }
        if (selectRadioByLabel('taxonomy_vocabulary_46[und]', payload.developmentStatus, warnings)) { filled++; markStarted(); }
        if (selectCategories(payload.categories, warnings)) { filled++; markStarted(); }
        await ensureNumberOfRows(
            'input[id^="edit-field-project-ecosystem-und-"][id$="-target-id"]',
            [
                '[name="field_project_ecosystem_add_more"]',
                '#edit-field-project-ecosystem-und-add-more',
                '[data-field-name="field_project_ecosystem"] .field-multiple-add-more-submit',
            ],
            payload.ecosystems.length,
            warnings,
        );
        const ecosystemCount = await setAutocompleteValues(
            payload.ecosystems.map((_, index) => `#edit-field-project-ecosystem-und-${index}-target-id`),
            payload.ecosystems,
            warnings,
        );
        if (ecosystemCount > 0) markStarted();
        filled += ecosystemCount;
        await ensureNumberOfRows(
            'input[id^="edit-field-supporting-organizations-und-"][id$="-target-id"]',
            [
                '[name="field_supporting_organizations_add_more"]',
                '#edit-field-supporting-organizations-und-add-more',
            ],
            payload.supportingOrganizations.length,
            warnings,
        );
        const organizationCount = await fillSupportingOrganizations(payload.supportingOrganizations, warnings);
        if (organizationCount > 0) markStarted();
        filled += organizationCount;
        await ensureNumberOfRows(
            'input[id^="edit-field-project-components-und-"][id$="-value"]',
            [
                '[name="field_project_components_add_more"]',
                '#edit-field-project-components-und-add-more',
            ],
            payload.issueComponents.length,
            warnings,
        );
        const componentCount = fillIssueComponents(payload.issueComponents, warnings);
        if (componentCount > 0) markStarted();
        filled += componentCount;
        if (setInputValue('#edit-field-project-issue-guidelines-und-0-value', payload.issueGuidelines, warnings)) { filled++; markStarted(); }
        if (setInputValue('#edit-field-issue-summary-template-und-0-value', payload.issueSummaryTemplate, warnings)) { filled++; markStarted(); }
        if (setCheckbox('#edit-field-project-has-issue-queue-und', payload.enableIssueTracker, warnings)) { filled++; markStarted(); }
        if (payload.homepage !== '' && setInputValue('#edit-field-project-homepage-und-0-url', payload.homepage, warnings)) { filled++; markStarted(); }
        if (payload.changelog !== '' && setInputValue('#edit-field-project-changelog-und-0-url', payload.changelog, warnings)) { filled++; markStarted(); }
        if (payload.demo !== '' && setInputValue('#edit-field-project-demo-und-0-url', payload.demo, warnings)) { filled++; markStarted(); }
        await ensureNumberOfRows(
            'input[id^="edit-field-project-documentation-und-"][id$="-title"]',
            [
                '[name="field_project_documentation_add_more"]',
                '#edit-field-project-documentation-und-add-more',
            ],
            payload.documentation.length,
            warnings,
        );
        const documentationCount = fillDocumentation(payload.documentation, warnings);
        if (documentationCount > 0) markStarted();
        filled += documentationCount;
        await ensureNumberOfRows(
            'input[id^="edit-field-project-screenshots-und-"][id$="-url"]',
            [
                '[name="field_project_screenshots_add_more"]',
                '#edit-field-project-screenshots-und-add-more',
            ],
            payload.screenshots.length,
            warnings,
        );
        let screenshotCount = 0;
        payload.screenshots.forEach((screenshot, index) => {
            if (setInputValue(`#edit-field-project-screenshots-und-${index}-url`, screenshot, warnings)) {
                filled++;
                screenshotCount++;
            }
        });
        if (screenshotCount > 0) markStarted();

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
                let fillPromise;
                let fillStarted = false;
                const markFillStarted = () => {
                    if (!fillStarted) {
                        fillStarted = true;
                        // Do not remove the only retry payload when every
                        // selector fails before a controlled fill starts.
                        removeFragment();
                    }
                };
                fillPromise = fillForm(payload, markFillStarted);
                fillPromise.then(showBanner).catch((error) => {
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

    if (globalThis.__drupalorgCliPrefillTest === true) {
        globalThis.__drupalorgCliPrefill = {
            decodePayload,
            validatePayload,
            readPayload,
            fillForm,
            fillWhenReady,
        };
        return;
    }

    const payload = readPayload();
    if (payload !== null) {
        // Submission is intentionally left to the user.
        fillWhenReady(payload);
    }
})();
