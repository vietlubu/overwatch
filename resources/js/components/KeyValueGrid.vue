<script setup>
defineProps({
    title: {
        type: String,
        default: '',
    },
    caption: {
        type: String,
        default: '',
    },
    entries: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <section class="info-card">
        <div class="section-title-row">
            <div>
                <h3 class="table-title">{{ title }}</h3>
                <div v-if="caption" class="table-caption">{{ caption }}</div>
            </div>
        </div>

        <div class="key-value-grid">
            <div v-for="entry in entries" :key="entry.label" class="key-value-row">
                <div class="key-value-label">{{ entry.label }}</div>
                <div class="key-value-value" v-html="entry.value" />
            </div>
        </div>
    </section>
</template>
