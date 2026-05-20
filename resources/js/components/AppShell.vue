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

const activeFilter = ref(props.filters.find((filter) => filter.key === '1h')?.key ?? props.filters[0]?.key ?? '1h');
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
            description: 'Clear project and environment filters.',
            projectKey: 'global',
            projectId: null,
            environment: null,
        },
    ];

    projects.value.forEach((project) => {
        entries.push({
            id: `project-${project.id}-all`,
            label: project.name,
            description: `All environments for ${project.slug}.`,
            projectKey: project.slug,
            projectId: String(project.id),
            environment: null,
        });

        project.environments.forEach((environment) => {
            entries.push({
                id: `project-${project.id}-${environment}`,
                label: project.name,
                description: `${project.slug} scoped to ${environment}.`,
                projectKey: project.slug,
                projectId: String(project.id),
                environment,
            });
        });
    });

    return entries;
});

const currentScopeLabel = computed(() => {
    const projectId = scopedQuery.value.project_id ?? null;
    const environment = scopedQuery.value.environment ?? null;

    if (!projectId) {
        return 'All projects';
    }

    const project = projects.value.find((entry) => String(entry.id) === String(projectId));
    const projectName = project?.name ?? `Project #${projectId}`;

    return environment ? `${projectName} · ${environment}` : `${projectName} · all envs`;
});

const currentProjectName = computed(() => {
    const projectId = scopedQuery.value.project_id ?? null;

    if (!projectId) {
        return 'Terrace Dev';
    }

    const project = projects.value.find((entry) => String(entry.id) === String(projectId));

    return project?.name ?? `Project #${projectId}`;
});

const currentProjectMeta = computed(() => {
    const environment = scopedQuery.value.environment ?? null;

    if (!scopedQuery.value.project_id) {
        return 'Overwatch Nightwatch Console · v0.1';
    }

    return environment
        ? `${environment} · Overwatch Nightwatch Console · v0.1`
        : `all environments · Overwatch Nightwatch Console · v0.1`;
});

const commandActions = computed(() => [
    {
        key: 'p',
        label: 'Project scope',
        description: 'Open the project picker.',
        run: () => openProjectPicker(),
    },
    {
        key: 'd',
        label: 'Dashboard',
        description: 'Go to the dashboard view.',
        run: () => navigateTo({ name: 'dashboard' }),
    },
    {
        key: 'i',
        label: 'Issues',
        description: 'Go to the issues queue.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'issues' } }),
    },
    {
        key: 'r',
        label: 'Requests',
        description: 'Go to the HTTP requests screen.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'requests' } }),
    },
    {
        key: 'j',
        label: 'Jobs',
        description: 'Go to the jobs screen.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'jobs' } }),
    },
    {
        key: 'c',
        label: 'Commands',
        description: 'Go to the commands screen.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'commands' } }),
    },
    {
        key: 't',
        label: 'Scheduled tasks',
        description: 'Go to scheduled tasks.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'scheduled-tasks' } }),
    },
    {
        key: 'x',
        label: 'Exceptions',
        description: 'Go to grouped exceptions.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'exceptions' } }),
    },
    {
        key: 'q',
        label: 'Queries',
        description: 'Go to grouped database queries.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'queries' } }),
    },
    {
        key: 'n',
        label: 'Notifications',
        description: 'Go to notification events.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'notifications' } }),
    },
    {
        key: 'm',
        label: 'Mail',
        description: 'Go to mail events.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'mail' } }),
    },
    {
        key: 'a',
        label: 'Cache',
        description: 'Go to cache activity.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'cache' } }),
    },
    {
        key: 'o',
        label: 'Outgoing requests',
        description: 'Go to outgoing requests.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'outgoing-requests' } }),
    },
    {
        key: 'u',
        label: 'Users',
        description: 'Go to users.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'users' } }),
    },
    {
        key: 'l',
        label: 'Logs',
        description: 'Go to logs.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'logs' } }),
    },
    {
        key: 'w',
        label: 'Settings',
        description: 'Go to workspace settings.',
        run: () => navigateTo({ name: 'screen', params: { screenKey: 'settings' } }),
    },
    {
        key: 's',
        label: 'Support',
        description: 'Go to support and docs.',
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
        (entry) =>
            String(entry.projectId ?? '') === String(scopedQuery.value.project_id ?? '') &&
            String(entry.environment ?? '') === String(scopedQuery.value.environment ?? ''),
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

    if (entry.environment) {
        query.environment = entry.environment;
    } else {
        delete query.environment;
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
                <div class="scope-meta">
                    <span class="scope-label">Scope</span>
                    <span class="scope-value">{{ currentScopeLabel }}</span>
                </div>
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
                            <span class="nav-label">{{ item.label }}</span>
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
