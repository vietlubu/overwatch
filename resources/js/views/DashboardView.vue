<script setup>
import { ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import MetricCard from '../components/MetricCard.vue';
import TrendPlot from '../components/TrendPlot.vue';
import KeyValueGrid from '../components/KeyValueGrid.vue';
import DataTable from '../components/DataTable.vue';
import { fetchDashboard } from '../data/liveDashboard';

const props = defineProps({
    filters: {
        type: Array,
        default: () => [],
    },
    activeFilter: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['update:active-filter']);

const route = useRoute();

const loading = ref(false);
const error = ref(null);
const screen = ref(null);

let requestToken = 0;

const buildErrorState = (reason) => {
    const status = reason?.response?.status ?? null;
    const message = reason?.response?.data?.message ?? reason?.message ?? 'Unable to load the dashboard.';

    return {
        status,
        message,
    };
};

const loadDashboard = async () => {
    const currentToken = ++requestToken;

    loading.value = true;
    error.value = null;

    try {
        const payload = await fetchDashboard({
            range: props.activeFilter || '24h',
            routeQuery: route.query,
        });

        if (currentToken !== requestToken) {
            return;
        }

        screen.value = payload;
    } catch (reason) {
        if (currentToken !== requestToken) {
            return;
        }

        screen.value = null;
        error.value = buildErrorState(reason);
    } finally {
        if (currentToken === requestToken) {
            loading.value = false;
        }
    }
};

watch(
    () => [props.activeFilter, route.fullPath],
    () => {
        loadDashboard();
    },
    { immediate: true },
);
</script>

<template>
    <section v-if="loading" class="placeholder-card">
        <div>
            <div class="empty-mark">[~]</div>
            <h2 class="empty-title">Loading dashboard</h2>
            <p class="empty-copy">Fetching the latest Overwatch summary.</p>
        </div>
    </section>

    <section v-else-if="error" class="placeholder-card error-card">
        <div>
            <div class="empty-mark">[x]</div>
            <h2 class="empty-title">API load failed</h2>
            <p class="empty-copy">{{ error.message }}</p>
            <p v-if="error.status" class="table-caption">HTTP {{ error.status }}</p>
        </div>
    </section>

    <template v-else-if="screen">
        <PageHeader
            :eyebrow="screen.eyebrow"
            :title="screen.title"
            :subtitle="screen.subtitle"
            :filters="filters"
            :active-filter="activeFilter"
            @update:active-filter="emit('update:active-filter', $event)"
        />

        <section v-for="section in screen.sections" :key="section.title" class="section-card">
            <div class="section-title-row">
                <h2 class="section-title">
                    <span class="section-icon">{{ section.icon }}</span>
                    {{ section.title }}
                </h2>
                <div class="table-caption">{{ section.caption }}</div>
            </div>

            <div v-if="section.metrics?.length" class="metric-grid">
                <MetricCard v-for="metric in section.metrics" :key="metric.label" :metric="metric" />
            </div>

            <div v-if="section.plots?.length" class="overview-grid" style="margin-top: 18px;">
                <div v-for="plot in section.plots" :key="plot.title" class="detail-card">
                    <div class="section-title-row">
                        <div>
                            <h3 class="table-title">{{ plot.title }}</h3>
                            <div class="table-caption">{{ plot.caption }}</div>
                        </div>
                    </div>
                    <TrendPlot :series="plot.series" :from-label="plot.fromLabel" :to-label="plot.toLabel" />
                </div>
            </div>

            <div v-if="section.panels?.length" class="panel-grid" style="margin-top: 18px;">
                <KeyValueGrid
                    v-for="panel in section.panels"
                    :key="panel.title"
                    :title="panel.title"
                    :caption="panel.caption"
                    :entries="panel.entries"
                />
            </div>

            <div v-if="section.tables?.length" class="section-grid" style="margin-top: 18px;">
                <DataTable
                    v-for="table in section.tables"
                    :key="table.title"
                    :title="table.title"
                    :caption="table.caption"
                    :search-placeholder="table.searchPlaceholder"
                    :columns="table.columns"
                    :rows="table.rows"
                />
            </div>
        </section>
    </template>
</template>
