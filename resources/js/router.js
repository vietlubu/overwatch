import { createRouter, createWebHashHistory } from 'vue-router';
import DashboardView from './views/DashboardView.vue';
import ScreenView from './views/ScreenView.vue';

export const router = createRouter({
    history: createWebHashHistory(),
    routes: [
        {
            path: '/',
            name: 'dashboard',
            component: DashboardView,
        },
        {
            path: '/:screenKey/:detailId?',
            name: 'screen',
            component: ScreenView,
            props: true,
        },
    ],
});
