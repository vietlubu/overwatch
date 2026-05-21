<script setup>
defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    entries: {
        type: Array,
        default: () => [],
    },
    activeIndex: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits(['close', 'select']);
</script>

<template>
    <div v-if="open" class="overlay-shell" @click.self="emit('close')">
        <section class="project-picker">
            <div class="overlay-header">
                <div>
                    <div class="overlay-eyebrow">Project Scope</div>
                    <h2 class="overlay-title">Select project</h2>
                    <p class="overlay-copy">Use `j`/`k` or arrow keys to move. Press `Enter` to apply the scope.</p>
                </div>
                <div class="overlay-hint">`Esc` close</div>
            </div>

            <div v-if="loading" class="picker-empty">Loading projects…</div>
            <div v-else-if="error" class="picker-empty picker-error">{{ error }}</div>
            <div v-else-if="!entries.length" class="picker-empty">No projects available yet.</div>

            <div v-else class="picker-list">
                <button
                    v-for="(entry, index) in entries"
                    :key="entry.id"
                    type="button"
                    class="picker-item"
                    :class="{ active: index === activeIndex }"
                    @click="emit('select', entry)"
                >
                    <div class="picker-main">
                        <div class="picker-label">{{ entry.label }}</div>
                        <div class="picker-copy">{{ entry.description }}</div>
                    </div>
                    <div class="picker-meta">
                        <span class="picker-chip">{{ entry.projectKey }}</span>
                        <span v-for="tag in entry.tags ?? []" :key="tag" class="picker-chip">{{ tag }}</span>
                    </div>
                </button>
            </div>
        </section>
    </div>
</template>
