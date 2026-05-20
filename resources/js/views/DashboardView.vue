<script setup>
import { computed } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import MetricCard from '../components/MetricCard.vue';
import TrendPlot from '../components/TrendPlot.vue';
import KeyValueGrid from '../components/KeyValueGrid.vue';
import DataTable from '../components/DataTable.vue';
import { dashboardScreen } from '../data/mockApi';

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

const screen = computed(() => dashboardScreen);
</script>

<template>
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

        <div class="metric-grid">
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
