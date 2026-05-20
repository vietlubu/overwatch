<script setup>
import { RouterView } from 'vue-router';
import AppShell from './components/AppShell.vue';
import { filters, navSections } from './data/mockApi';
</script>

<template>
    <AppShell v-slot="{ activeFilter, setActiveFilter }" :filters="filters" :nav-sections="navSections">
        <RouterView v-slot="{ Component, route }">
            <component
                :is="Component"
                :key="route.fullPath"
                :active-filter="activeFilter"
                :filters="filters"
                @update:active-filter="setActiveFilter"
            />
        </RouterView>
    </AppShell>
</template>
