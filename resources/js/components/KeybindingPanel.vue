<script setup>
defineProps({
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
</script>

<template>
    <section v-if="visible" class="keybinding-panel" role="dialog" aria-label="Keybindings">
        <div class="keybinding-panel-header">
            <div class="keybinding-prefix">SPC</div>
            <div class="overlay-hint">`Esc` close</div>
        </div>

        <div class="keybinding-list">
            <article v-for="action in actions" :key="action.key" class="keybinding-row">
                <div class="keybinding-row-main">
                    <span class="keybinding-key">{{ action.key }}</span>
                    <span class="keybinding-arrow">-&gt;</span>
                    <span class="keybinding-label">{{ action.label }}</span>
                </div>
                <div class="keybinding-copy">{{ action.description }}</div>
            </article>
        </div>
    </section>
</template>
