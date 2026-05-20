<script setup>
import { computed, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import HighlightedCode from './HighlightedCode.vue';
import { guessCodeLanguage } from '../utils/highlightCode';
import { mergeScopeIntoTarget } from '../utils/scopeQuery';

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    caption: {
        type: String,
        default: '',
    },
    searchPlaceholder: {
        type: String,
        default: 'Search…',
    },
    columns: {
        type: Array,
        default: () => [],
    },
    rows: {
        type: Array,
        default: () => [],
    },
});

const route = useRoute();
const query = ref('');

const normalized = (value) => {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return value;
    }

    return { text: value };
};

const isHighlightedCell = (column, value) => {
    if (column.kind === 'highlight') {
        return true;
    }

    return Boolean(value?.language || guessCodeLanguage(value?.text));
};

const resolveRowTarget = (target) => mergeScopeIntoTarget(target, route.query);

const filteredRows = computed(() => {
    const keyword = query.value.trim().toLowerCase();

    if (!keyword) {
        return props.rows;
    }

    return props.rows.filter((row) =>
        Object.values(row).some((value) => String(typeof value === 'object' ? value.text ?? value.meta ?? '' : value).toLowerCase().includes(keyword)),
    );
});
</script>

<template>
    <section class="table-shell">
        <div class="table-toolbar">
            <div>
                <h3 class="table-title">{{ title }}</h3>
                <div v-if="caption" class="table-caption">{{ caption }}</div>
            </div>

            <input v-model="query" class="table-search" :placeholder="searchPlaceholder" type="search">
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th v-for="column in columns" :key="column.key" :style="{ textAlign: column.align ?? 'left' }">
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in filteredRows" :key="row.id">
                    <td v-for="column in columns" :key="column.key" :style="{ textAlign: column.align ?? 'left' }">
                        <template v-if="column.kind === 'primary'">
                            <component
                                :is="row.href ? RouterLink : 'div'"
                                :to="row.href ? resolveRowTarget(row.href) : undefined"
                                class="row-link"
                            >
                                <div class="table-cell-primary">
                                    <HighlightedCode
                                        v-if="isHighlightedCell(column, normalized(row[column.key]))"
                                        :code="normalized(row[column.key]).text"
                                        :language="normalized(row[column.key]).language"
                                        inline
                                    />
                                    <template v-else>{{ normalized(row[column.key]).text }}</template>
                                </div>
                                <div v-if="normalized(row[column.key]).meta" class="table-cell-meta">
                                    {{ normalized(row[column.key]).meta }}
                                </div>
                            </component>
                        </template>

                        <span
                            v-else-if="column.kind === 'tone'"
                            class="tone-pill"
                            :class="`tone-${normalized(row[column.key]).tone ?? 'blue'}`"
                        >
                            {{ normalized(row[column.key]).text }}
                        </span>

                        <HighlightedCode
                            v-else-if="column.kind === 'highlight'"
                            :code="normalized(row[column.key]).text"
                            :language="normalized(row[column.key]).language"
                            inline
                        />

                        <span v-else-if="column.kind === 'code'" class="table-cell-code">
                            {{ normalized(row[column.key]).text }}
                        </span>

                        <div v-else class="table-cell-primary">
                            {{ normalized(row[column.key]).text }}
                            <div v-if="normalized(row[column.key]).meta" class="table-cell-meta">
                                {{ normalized(row[column.key]).meta }}
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
