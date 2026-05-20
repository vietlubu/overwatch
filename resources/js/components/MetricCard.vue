<script setup>
import SparkBars from './SparkBars.vue';

defineProps({
    metric: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <article class="metric-card">
        <div class="metric-head">
            <div class="card-label">{{ metric.label }}</div>
            <div class="table-caption">{{ metric.caption }}</div>
        </div>
        <h3 class="metric-value">{{ metric.value }}</h3>
        <div v-if="metric.meta" class="metric-meta">
            <span v-for="item in metric.meta" :key="item.label" :class="`dot-legend tone-${item.tone}`">
                {{ item.label }}
            </span>
        </div>
        <SparkBars v-if="metric.bars?.length" :bars="metric.bars" />
    </article>
</template>
