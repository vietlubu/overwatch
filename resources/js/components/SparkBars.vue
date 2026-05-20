<script setup>
defineProps({
    bars: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div class="sparkline">
        <div
            v-for="(bar, index) in bars"
            :key="`${bar.tone}-${index}`"
            class="sparkline-bar"
            :class="bar.tone ?? 'blue'"
            :style="{ height: `${bar.value}%` }"
        />
    </div>
</template>
