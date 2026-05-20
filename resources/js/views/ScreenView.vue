<script setup>
import { computed } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import MetricCard from '../components/MetricCard.vue';
import TrendPlot from '../components/TrendPlot.vue';
import DataTable from '../components/DataTable.vue';
import KeyValueGrid from '../components/KeyValueGrid.vue';
import TimelineStack from '../components/TimelineStack.vue';
import { getDetail, getScreen } from '../data/mockApi';

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

const screen = computed(() => getScreen(String(route.params.screenKey ?? '')));
const detail = computed(() => {
    if (!route.params.detailId) {
        return null;
    }

    return getDetail(String(route.params.screenKey ?? ''), String(route.params.detailId));
});
</script>

<template>
    <template v-if="screen && !detail">
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
                        <pre>{{ panel.code }}</pre>
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
