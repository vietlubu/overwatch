<script setup>
import { ref } from 'vue';
import { RouterLink } from 'vue-router';

const props = defineProps({
    filters: {
        type: Array,
        required: true,
    },
    navSections: {
        type: Array,
        required: true,
    },
});

const activeFilter = ref(props.filters[0]?.key ?? '1h');

const setActiveFilter = (filterKey) => {
    activeFilter.value = filterKey;
};
</script>

<template>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <div class="brand-mark">[]</div>
                <div class="brand-copy">
                    <div class="brand-title">Terrace Dev</div>
                    <div class="brand-subtitle">Overwatch Nightwatch Console</div>
                    <div class="brand-meta">Laravel 12 ingest · Vue prototype</div>
                </div>
                <div class="brand-meta">v0.1</div>
            </div>

            <nav class="sidebar-nav">
                <section v-for="section in navSections" :key="section.title" class="nav-section">
                    <div class="nav-section-title">{{ section.title }}</div>

                    <RouterLink
                        v-for="item in section.items"
                        :key="item.key"
                        :to="item.to"
                        class="nav-link"
                    >
                        <span class="nav-icon">{{ item.icon }}</span>
                        <span class="nav-label">{{ item.label }}</span>
                        <span v-if="item.badge" class="nav-badge">{{ item.badge }}</span>
                    </RouterLink>
                </section>
            </nav>

            <div class="sidebar-footer">
                <div class="footer-user">
                    <div class="footer-avatar">V</div>
                    <div>
                        <div class="nav-label">vietlubu</div>
                        <div class="brand-meta">operator@overwatch</div>
                    </div>
                </div>
                <div class="brand-meta">...</div>
            </div>
        </aside>

        <main class="workspace">
            <div class="workspace-inner">
                <slot :active-filter="activeFilter" :set-active-filter="setActiveFilter" />
            </div>
        </main>
    </div>
</template>
