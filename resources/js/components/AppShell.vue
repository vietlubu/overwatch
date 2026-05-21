<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { useRoute, useRouter } from 'vue-router';
import KeybindingPanel from './KeybindingPanel.vue';
import ProjectPicker from './ProjectPicker.vue';
import { fetchProjects } from '../data/liveProjects';
import { mergeScopeIntoTarget, pickScopeQuery } from '../utils/scopeQuery';

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

const route = useRoute();
const router = useRouter();
const FILTER_STORAGE_KEY = 'overwatch.active-filter';

const escapeHtml = (value) =>
    String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');

const resolveInitialFilter = () => {
    const defaultFilter = props.filters.find((filter) => filter.key === '1h')?.key ?? props.filters[0]?.key ?? '1h';

    if (typeof window === 'undefined') {
        return defaultFilter;
    }

    const storedFilter = window.localStorage.getItem(FILTER_STORAGE_KEY);

    if (storedFilter && props.filters.some((filter) => filter.key === storedFilter)) {
        return storedFilter;
    }

    return defaultFilter;
};

const activeFilter = ref(resolveInitialFilter());
const projects = ref([]);
const projectsLoading = ref(false);
const projectsError = ref('');
const keybindingVisible = ref(false);
const sequenceActive = ref(false);
const projectPickerOpen = ref(false);
const projectPickerIndex = ref(0);

let keybindingTimerId = null;

const setActiveFilter = (filterKey) => {
    activeFilter.value = filterKey;
};

const renderNavLabel = (label, shortcut) => {
    const safeLabel = String(label ?? '');
    const safeShortcut = String(shortcut ?? '').trim();

    if (!safeShortcut) {
        return escapeHtml(safeLabel);
    }

    const index = safeLabel.toLowerCase().indexOf(safeShortcut.toLowerCase());

    if (index === -1) {
        return `${escapeHtml(safeLabel)} <span class="nav-shortcut-key">[${escapeHtml(safeShortcut)}]</span>`;
    }

    const end = index + safeShortcut.length;

    return [
        escapeHtml(safeLabel.slice(0, index)),
        `<span class="nav-shortcut-key">${escapeHtml(safeLabel.slice(index, end))}</span>`,
        escapeHtml(safeLabel.slice(end)),
    ].join('');
};

const scopedQuery = computed(() => pickScopeQuery(route.query));

const scopedNavSections = computed(() =>
    props.navSections.map((section) => ({
        ...section,
        items: section.items.map((item) => ({
            ...item,
            resolvedTo: mergeScopeIntoTarget(item.to, route.query),
        })),
    })),
);

const projectEntries = computed(() => {
    const entries = [
        {
            id: 'all-projects',
            label: 'All projects',
            description: 'Clear the active project filter.',
            projectKey: 'global',
            projectId: null,
            tags: [],
        },
    ];

    projects.value.forEach((project) => {
        entries.push({
            id: `project-${project.id}`,
            label: project.name,
            description: project.tags?.length
                ? `${project.slug} · ${project.tags.join(', ')}`
                : `Scope to ${project.slug}.`,
            projectKey: project.slug,
            projectId: String(project.id),
            tags: Array.isArray(project.tags) ? project.tags : [],
        });
    });

    return entries;
});

const currentProjectName = computed(() => {
    const projectId = scopedQuery.value.project_id ?? null;

    if (!projectId) {
        return 'All projects';
    }

    const project = projects.value.find((entry) => String(entry.id) === String(projectId));

    return project?.name ?? `Project #${projectId}`;
});

const currentProjectMeta = computed(() => {
    if (!scopedQuery.value.project_id) {
        return 'Overwatch Nightwatch Console · v0.1';
    }

    return 'project scoped · Overwatch Nightwatch Console · v0.1';
});

const commandActions = computed(() => [
    {
        key: 'p',
        description: 'open project picker',
        emphasis: 'project',
        run: () => openProjectPicker(),
    },
    {
        key: 'd',
        description: 'open dashboard',
        emphasis: 'dashboard',
        run: () => navigateTo({ name: 'dashboard' }),
    },
    {
        key: 'i',
        description: 'open issues',
        emphasis: 'issues',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'issues' } }),
    },
    {
        key: 'r',
        description: 'open requests',
        emphasis: 'requests',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'requests' } }),
    },
    {
        key: 'j',
        description: 'open jobs',
        emphasis: 'jobs',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'jobs' } }),
    },
    {
        key: 'c',
        description: 'open commands',
        emphasis: 'commands',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'commands' } }),
    },
    {
        key: 't',
        description: 'open scheduled tasks',
        emphasis: 'scheduled tasks',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'scheduled-tasks' } }),
    },
    {
        key: 'x',
        description: 'open exceptions',
        emphasis: 'exceptions',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'exceptions' } }),
    },
    {
        key: 'q',
        description: 'open queries',
        emphasis: 'queries',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'queries' } }),
    },
    {
        key: 'n',
        description: 'open notifications',
        emphasis: 'notifications',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'notifications' } }),
    },
    {
        key: 'm',
        description: 'open mail',
        emphasis: 'mail',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'mail' } }),
    },
    {
        key: 'a',
        description: 'open cache',
        emphasis: 'cache',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'cache' } }),
    },
    {
        key: 'o',
        description: 'open outgoing requests',
        emphasis: 'outgoing requests',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'outgoing-requests' } }),
    },
    {
        key: 'u',
        description: 'open users',
        emphasis: 'users',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'users' } }),
    },
    {
        key: 'l',
        description: 'open logs',
        emphasis: 'logs',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'logs' } }),
    },
    {
        key: 'w',
        description: 'open settings',
        emphasis: 'settings',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'settings' } }),
    },
    {
        key: 's',
        description: 'open support',
        emphasis: 'support',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'support' } }),
    },
]);

const clearKeybindingTimer = () => {
    if (keybindingTimerId !== null) {
        window.clearTimeout(keybindingTimerId);
        keybindingTimerId = null;
    }
};

const resetSequence = () => {
    clearKeybindingTimer();
    sequenceActive.value = false;
    keybindingVisible.value = false;
};

const beginSequence = () => {
    resetSequence();
    sequenceActive.value = true;
    keybindingTimerId = window.setTimeout(() => {
        keybindingVisible.value = true;
    }, 1000);
};

const navigateTo = async (target) => {
    await router.push(mergeScopeIntoTarget(target, route.query));
};

const fetchProjectOptions = async () => {
    projectsLoading.value = true;
    projectsError.value = '';

    try {
        projects.value = await fetchProjects();
    } catch (error) {
        projectsError.value = error?.response?.data?.message ?? error?.message ?? 'Unable to load projects.';
    } finally {
        projectsLoading.value = false;
    }
};

const syncProjectPickerIndex = () => {
    const matchIndex = projectEntries.value.findIndex(
        (entry) => String(entry.projectId ?? '') === String(scopedQuery.value.project_id ?? ''),
    );

    projectPickerIndex.value = matchIndex >= 0 ? matchIndex : 0;
};

const openProjectPicker = async () => {
    resetSequence();
    projectPickerOpen.value = true;

    if (!projects.value.length && !projectsLoading.value) {
        await fetchProjectOptions();
    }

    syncProjectPickerIndex();
};

const closeProjectPicker = () => {
    projectPickerOpen.value = false;
};

const applyProjectScope = async (entry) => {
    if (!entry) {
        return;
    }

    const query = { ...route.query };

    if (entry.projectId) {
        query.project_id = entry.projectId;
    } else {
        delete query.project_id;
    }

    closeProjectPicker();
    await router.push({
        name: route.name,
        params: route.params,
        query,
    });
};

const isTypingTarget = (event) => {
    const target = event.target;

    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
};

const moveProjectSelection = (delta) => {
    if (!projectEntries.value.length) {
        return;
    }

    const length = projectEntries.value.length;
    projectPickerIndex.value = (projectPickerIndex.value + delta + length) % length;
};

const handleProjectPickerKeydown = async (event) => {
    if (!projectPickerOpen.value) {
        return false;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        closeProjectPicker();
        return true;
    }

    if (event.key === 'ArrowDown' || event.key === 'j') {
        event.preventDefault();
        moveProjectSelection(1);
        return true;
    }

    if (event.key === 'ArrowUp' || event.key === 'k') {
        event.preventDefault();
        moveProjectSelection(-1);
        return true;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        await applyProjectScope(projectEntries.value[projectPickerIndex.value]);
        return true;
    }

    return true;
};

const handleGlobalKeydown = async (event) => {
    if (await handleProjectPickerKeydown(event)) {
        return;
    }

    if (!sequenceActive.value && isTypingTarget(event)) {
        return;
    }

    if (!sequenceActive.value) {
        if (event.code === 'Space') {
            event.preventDefault();
            beginSequence();
        }

        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        resetSequence();
        return;
    }

    if (event.code === 'Space') {
        event.preventDefault();
        return;
    }

    const key = String(event.key ?? '').toLowerCase();
    const action = commandActions.value.find((entry) => entry.key === key);

    if (!action) {
        resetSequence();
        return;
    }

    event.preventDefault();
    resetSequence();
    await action.run();
};

watch(
    () => route.fullPath,
    () => {
        syncProjectPickerIndex();
    },
);

watch(
    () => projects.value,
    () => {
        if (projectPickerOpen.value) {
            syncProjectPickerIndex();
        }
    },
);

watch(activeFilter, (value) => {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(FILTER_STORAGE_KEY, value);
    }
});

onMounted(() => {
    fetchProjectOptions();
    window.addEventListener('keydown', handleGlobalKeydown);
});

onBeforeUnmount(() => {
    clearKeybindingTimer();
    window.removeEventListener('keydown', handleGlobalKeydown);
});
</script>

<template>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="brand-title prompt-label">{{ currentProjectName }}</div>
                <div class="brand-meta">{{ currentProjectMeta }}</div>
            </div>

            <nav class="sidebar-nav">
                <section v-for="section in scopedNavSections" :key="section.title" class="nav-section">
                    <div class="nav-section-title">{{ section.title }}</div>

                    <RouterLink
                        v-for="item in section.items"
                        :key="item.key"
                        :to="item.resolvedTo"
                        class="nav-link"
                    >
                        <span class="nav-main">
                            <span class="nav-icon">{{ item.icon }}</span>
                            <span class="nav-label" v-html="renderNavLabel(item.label, item.shortcut)" />
                        </span>
                        <span v-if="item.badge" class="nav-badge">{{ item.badge }}</span>
                    </RouterLink>
                </section>
            </nav>
        </aside>

        <main class="workspace">
            <div class="workspace-inner">
                <slot :active-filter="activeFilter" :set-active-filter="setActiveFilter" />
            </div>
        </main>

        <KeybindingPanel
            :visible="keybindingVisible"
            :actions="commandActions"
            @close="resetSequence"
        />

        <ProjectPicker
            :open="projectPickerOpen"
            :loading="projectsLoading"
            :error="projectsError"
            :entries="projectEntries"
            :active-index="projectPickerIndex"
            @close="closeProjectPicker"
            @select="applyProjectScope"
        />
    </div>
</template>
