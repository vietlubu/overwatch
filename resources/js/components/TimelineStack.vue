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
    rows: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <section class="detail-card">
        <div class="section-title-row">
            <div>
                <h3 class="table-title">{{ title }}</h3>
                <div v-if="caption" class="table-caption">{{ caption }}</div>
            </div>
        </div>

        <div class="timeline-stack">
            <div v-for="row in rows" :key="row.label" class="timeline-row">
                <div class="timeline-label">
                    <span>{{ row.label }}</span>
                    <span class="tone-pill" :class="`tone-${row.tone}`">{{ row.value }}</span>
                </div>
                <div class="timeline-track">
                    <div class="timeline-fill" :class="`sparkline-bar ${row.tone}`" :style="{ width: `${row.width}%` }" />
                </div>
            </div>
        </div>
    </section>
</template>
