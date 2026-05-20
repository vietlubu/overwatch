<script setup>
import { computed } from 'vue';
import { renderHighlightedCode } from '../utils/highlightCode';

const props = defineProps({
    code: {
        type: [String, Number],
        default: '',
    },
    language: {
        type: String,
        default: '',
    },
    inline: {
        type: Boolean,
        default: false,
    },
});

const highlighted = computed(() => renderHighlightedCode(props.code, props.language || null));
</script>

<template>
    <code
        v-if="inline"
        class="syntax-code inline-code hljs"
        :class="`language-${highlighted.language}`"
        v-html="highlighted.html"
    />

    <pre v-else class="syntax-block"><code class="syntax-code hljs" :class="`language-${highlighted.language}`" v-html="highlighted.html" /></pre>
</template>
