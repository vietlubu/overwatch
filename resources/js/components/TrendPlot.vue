<script setup>
import { computed } from 'vue';

const props = defineProps({
    series: {
        type: Array,
        default: () => [],
    },
    fromLabel: {
        type: String,
        default: '',
    },
    toLabel: {
        type: String,
        default: '',
    },
});

const tones = {
    blue: '#89b4fa',
    green: '#a6e3a1',
    yellow: '#f9e2af',
    red: '#f38ba8',
    sky: '#89dceb',
    mauve: '#cba6f7',
};

const chartHeight = 160;
const chartWidth = 640;

const normalized = computed(() =>
    props.series.map((line) => {
        const points = line.values.map((point, index) => {
            const x = line.values.length === 1 ? chartWidth / 2 : (index / (line.values.length - 1)) * chartWidth;
            const y = chartHeight - point * chartHeight;

            return `${x},${y}`;
        });

        return {
            ...line,
            color: tones[line.tone] ?? tones.blue,
            polyline: points.join(' '),
        };
    }),
);
</script>

<template>
    <div class="trend-plot">
        <svg
            class="trend-svg"
            :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
            preserveAspectRatio="none"
            role="img"
            aria-hidden="true"
        >
            <template v-for="line in normalized" :key="line.label">
                <polyline
                    :points="line.polyline"
                    fill="none"
                    :stroke="line.color"
                    stroke-width="3"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
                <circle
                    v-for="(point, index) in line.values"
                    :key="`${line.label}-${index}`"
                    :cx="line.values.length === 1 ? chartWidth / 2 : (index / (line.values.length - 1)) * chartWidth"
                    :cy="chartHeight - point * chartHeight"
                    r="4.5"
                    :fill="line.color"
                />
            </template>
        </svg>
    </div>

    <div class="trend-foot">
        <span>{{ fromLabel }}</span>
        <span>{{ toLabel }}</span>
    </div>
</template>
