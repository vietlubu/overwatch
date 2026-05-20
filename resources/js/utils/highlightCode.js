import hljs from 'highlight.js/lib/core';
import bash from 'highlight.js/lib/languages/bash';
import json from 'highlight.js/lib/languages/json';
import php from 'highlight.js/lib/languages/php';
import sql from 'highlight.js/lib/languages/sql';

let registered = false;

const preferredLanguages = ['sql', 'json', 'php', 'bash'];

const escapeHtml = (value) =>
    String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');

const ensureLanguages = () => {
    if (registered) {
        return;
    }

    hljs.registerLanguage('bash', bash);
    hljs.registerLanguage('json', json);
    hljs.registerLanguage('php', php);
    hljs.registerLanguage('sql', sql);
    registered = true;
};

const sqlPattern = /^(select|insert|update|delete|with|from|alter|create|drop|truncate)\b/i;

export const guessCodeLanguage = (value = '') => {
    const source = String(value ?? '').trim();

    if (!source) {
        return null;
    }

    if (sqlPattern.test(source)) {
        return 'sql';
    }

    if (
        (source.startsWith('{') && source.endsWith('}')) ||
        (source.startsWith('[') && source.endsWith(']'))
    ) {
        return 'json';
    }

    if (
        source.startsWith('<?php') ||
        source.includes('App\\') ||
        source.includes('$this->') ||
        source.includes('::class') ||
        /^\d+\s+\|\s+/.test(source)
    ) {
        return 'php';
    }

    if (
        source.startsWith('#') ||
        /^([a-z0-9_-]+):\s+/im.test(source) ||
        source.includes('x-nightwatch-token:') ||
        source.includes('content-type:')
    ) {
        return 'bash';
    }

    return null;
};

export const renderHighlightedCode = (value, language) => {
    const source = String(value ?? '');

    if (!source) {
        return {
            html: '',
            language: language ?? 'plaintext',
        };
    }

    ensureLanguages();

    const resolvedLanguage = language ?? guessCodeLanguage(source);

    try {
        if (resolvedLanguage && hljs.getLanguage(resolvedLanguage)) {
            return {
                html: hljs.highlight(source, {
                    language: resolvedLanguage,
                    ignoreIllegals: true,
                }).value,
                language: resolvedLanguage,
            };
        }

        const auto = hljs.highlightAuto(source, preferredLanguages);

        return {
            html: auto.value,
            language: auto.language ?? 'plaintext',
        };
    } catch {
        return {
            html: escapeHtml(source),
            language: resolvedLanguage ?? 'plaintext',
        };
    }
};
