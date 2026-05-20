<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';

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

const query = ref('');

const normalized = (value) => {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return value;
    }

    return { text: value };
};

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
                                :to="row.href"
                                class="row-link"
                            >
                                <div class="table-cell-primary">{{ normalized(row[column.key]).text }}</div>
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
