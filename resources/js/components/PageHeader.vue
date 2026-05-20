<script setup>
const props = defineProps({
    eyebrow: {
        type: String,
        default: '',
    },
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: '',
    },
    filters: {
        type: Array,
        default: () => [],
    },
    activeFilter: {
        type: String,
        default: '',
    },
    actions: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['update:active-filter']);
</script>

<template>
    <header class="page-header">
        <div class="page-header-copy">
            <div v-if="eyebrow" class="eyebrow">{{ eyebrow }}</div>
            <h1 class="page-title prompt-label">{{ title }}</h1>
            <p v-if="subtitle" class="page-subtitle">{{ subtitle }}</p>
        </div>

        <div class="page-actions">
            <div v-if="filters.length" class="filter-bar">
                <button
                    v-for="filter in filters"
                    :key="filter.key"
                    type="button"
                    class="filter-button"
                    :class="{ active: activeFilter === filter.key }"
                    @click="emit('update:active-filter', filter.key)"
                >
                    {{ filter.label }}
                </button>
            </div>

            <button
                v-for="action in actions"
                :key="action.label"
                type="button"
                class="action-button"
                :class="{ primary: action.primary }"
            >
                {{ action.label }}
            </button>
        </div>
    </header>
</template>
