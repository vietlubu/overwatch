<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import MetricCard from '../components/MetricCard.vue';
import TrendPlot from '../components/TrendPlot.vue';
import DataTable from '../components/DataTable.vue';
import KeyValueGrid from '../components/KeyValueGrid.vue';
import TimelineStack from '../components/TimelineStack.vue';
import HighlightedCode from '../components/HighlightedCode.vue';
import { getDetail, getScreen } from '../data/mockApi';
import { fetchLiveDetail, fetchLiveScreen, isLiveScreen } from '../data/liveScreens';

const props = defineProps({
    screenKey: {
        type: String,
        default: '',
    },
    detailId: {
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
});

const emit = defineEmits(['update:active-filter']);

const route = useRoute();

const screenKey = computed(() => String(route.params.screenKey ?? ''));
const detailId = computed(() => (route.params.detailId ? String(route.params.detailId) : null));

const loading = ref(false);
const error = ref(null);
const screen = ref(null);
const detail = ref(null);

let requestToken = 0;

const buildErrorState = (reason) => {
    const status = reason?.response?.status ?? null;
    const message = reason?.response?.data?.message ?? reason?.message ?? 'Unable to load this screen.';

    return {
        status,
        message,
    };
};

const loadState = async () => {
    const currentToken = ++requestToken;
    const currentScreenKey = screenKey.value;
    const currentDetailId = detailId.value;
    const baseScreen = getScreen(currentScreenKey);

    screen.value = baseScreen;
    detail.value = currentDetailId ? getDetail(currentScreenKey, currentDetailId) : null;
    error.value = null;

    if (!isLiveScreen(currentScreenKey)) {
        return;
    }

    loading.value = true;

    try {
        if (currentDetailId) {
            const payload = await fetchLiveDetail(currentScreenKey, currentDetailId, {
                routeQuery: route.query,
            });

            if (currentToken !== requestToken) {
                return;
            }

            detail.value = payload;
        } else {
            const payload = await fetchLiveScreen(currentScreenKey, {
                range: props.activeFilter || '24h',
                routeQuery: route.query,
            });

            if (currentToken !== requestToken) {
                return;
            }

            screen.value = payload;
        }
    } catch (reason) {
        if (currentToken !== requestToken) {
            return;
        }

        error.value = buildErrorState(reason);
    } finally {
        if (currentToken === requestToken) {
            loading.value = false;
        }
    }
};

watch(
    () => [screenKey.value, detailId.value, props.activeFilter, route.fullPath],
    () => {
        loadState();
    },
    { immediate: true },
);
</script>

<template>
    <section v-if="loading" class="placeholder-card">
        <div>
            <div class="empty-mark">[~]</div>
            <h2 class="empty-title">Loading telemetry</h2>
            <p class="empty-copy">Fetching the latest Overwatch screen data.</p>
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

    <template v-else-if="screen && !detail">
        <PageHeader
            :eyebrow="screen.eyebrow"
            :title="screen.title"
            :subtitle="screen.subtitle"
            :filters="filters"
            :active-filter="activeFilter"
            :actions="screen.actions ?? []"
            @update:active-filter="emit('update:active-filter', $event)"
        />

        <section v-if="screen.kind === 'placeholder'" class="placeholder-card">
            <div>
                <div class="empty-mark">{{ screen.icon }}</div>
                <h2 class="empty-title">{{ screen.empty.title }}</h2>
                <p class="empty-copy">{{ screen.empty.copy }}</p>
            </div>
        </section>

        <template v-else>
            <div v-if="screen.metrics?.length" class="metric-grid">
                <MetricCard v-for="metric in screen.metrics" :key="metric.label" :metric="metric" />
            </div>

            <div v-if="screen.plots?.length" class="panel-grid">
                <section v-for="plot in screen.plots" :key="plot.title" class="detail-card">
                    <div class="section-title-row">
                        <div>
                            <h3 class="table-title">{{ plot.title }}</h3>
                            <div class="table-caption">{{ plot.caption }}</div>
                        </div>
                    </div>
                    <TrendPlot :series="plot.series" :from-label="plot.fromLabel" :to-label="plot.toLabel" />
                </section>
            </div>

            <div v-if="screen.panels?.length" class="panel-grid">
                <KeyValueGrid
                    v-for="panel in screen.panels"
                    :key="panel.title"
                    :title="panel.title"
                    :caption="panel.caption"
                    :entries="panel.entries"
                />
            </div>

            <DataTable
                v-if="screen.table"
                :title="screen.table.title"
                :caption="screen.table.caption"
                :search-placeholder="screen.table.searchPlaceholder"
                :columns="screen.table.columns"
                :rows="screen.table.rows"
            />
        </template>
    </template>

    <template v-else-if="screen && detail">
        <PageHeader
            :eyebrow="detail.eyebrow"
            :title="detail.title"
            :subtitle="detail.subtitle"
            :filters="filters"
            :active-filter="activeFilter"
            @update:active-filter="emit('update:active-filter', $event)"
        />

        <div class="detail-toolbar detail-card">
            <RouterLink :to="detail.backTo" class="back-button">&lt;- Back to {{ screen.title }}</RouterLink>
            <div class="page-actions">
                <span
                    v-for="tag in detail.tags"
                    :key="tag.text"
                    class="tone-pill"
                    :class="`tone-${tag.tone}`"
                >
                    {{ tag.text }}
                </span>
            </div>
        </div>

        <div class="detail-stack">
            <div v-if="detail.metrics?.length" class="metric-grid">
                <MetricCard v-for="metric in detail.metrics" :key="metric.label" :metric="metric" />
            </div>

            <div v-if="detail.summaryPanels?.length" class="detail-grid">
                <KeyValueGrid
                    v-for="panel in detail.summaryPanels"
                    :key="panel.title"
                    :title="panel.title"
                    :caption="panel.caption"
                    :entries="panel.entries"
                />
            </div>

            <div v-if="detail.timeline || detail.codePanels?.length" class="detail-hero">
                <TimelineStack
                    v-if="detail.timeline"
                    :title="detail.timeline.title"
                    :caption="detail.timeline.caption"
                    :rows="detail.timeline.rows"
                />

                <div v-if="detail.codePanels?.length" class="detail-stack">
                    <section v-for="panel in detail.codePanels" :key="panel.title" class="code-panel">
                        <div class="code-caption">{{ panel.title }}</div>
                        <HighlightedCode :code="panel.code" :language="panel.language" />
                    </section>
                </div>
            </div>

            <div v-if="detail.tables?.length" class="section-grid">
                <DataTable
                    v-for="table in detail.tables"
                    :key="table.title"
                    :title="table.title"
                    :caption="table.caption"
                    :search-placeholder="table.searchPlaceholder"
                    :columns="table.columns"
                    :rows="table.rows"
                />
            </div>
        </div>
    </template>

    <section v-else class="placeholder-card">
        <div>
            <div class="empty-mark">[x]</div>
            <h2 class="empty-title">Unknown screen</h2>
            <p class="empty-copy">This route is not mapped yet. Pick another screen from the sidebar.</p>
        </div>
    </section>
</template>
