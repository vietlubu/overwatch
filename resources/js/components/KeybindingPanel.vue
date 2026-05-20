<script setup>
const props = defineProps({
    visible: {
        type: Boolean,
        default: false,
    },
    actions: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['close']);

const escapeHtml = (value) =>
    String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');

const renderCopy = (action) => {
    const copy = String(action.description ?? '');
    const emphasis = String(action.emphasis ?? '').trim();

    if (!emphasis) {
        return escapeHtml(copy);
    }

    const lowerCopy = copy.toLowerCase();
    const lowerEmphasis = emphasis.toLowerCase();
    const start = lowerCopy.indexOf(lowerEmphasis);

    if (start === -1) {
        return escapeHtml(copy);
    }

    const end = start + emphasis.length;

    return [
        escapeHtml(copy.slice(0, start)),
        `<span class="keybinding-focus">${escapeHtml(copy.slice(start, end))}</span>`,
        escapeHtml(copy.slice(end)),
    ].join('');
};
</script>

<template>
    <section v-if="visible" class="keybinding-panel" role="dialog" aria-label="Keybindings">
        <div class="keybinding-panel-header">
            <div class="keybinding-prefix">SPC</div>
            <div class="overlay-hint">`Esc` close</div>
        </div>

        <div class="keybinding-list">
            <article v-for="action in actions" :key="action.key" class="keybinding-row">
                <span class="keybinding-key">{{ action.key }}</span>
                <div class="keybinding-copy" v-html="renderCopy(action)" />
            </article>
        </div>
    </section>
</template>
