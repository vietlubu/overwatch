import axios from 'axios';
import { apiBlueprint } from './mockApi';

const liveScreenKeys = new Set(['issues', 'requests', 'exceptions', 'jobs', 'commands', 'scheduled-tasks', 'queries', 'notifications', 'mail', 'cache', 'outgoing-requests', 'users', 'logs']);

const pickScope = (query = {}) => {
    const scope = {};

    if (query.project_id) {
        scope.project_id = query.project_id;
    }

    return scope;
};

const withDetailBackToScope = (payload, routeQuery = {}) => {
    if (!payload?.backTo) {
        return payload;
    }

    return {
        ...payload,
        backTo: {
            ...payload.backTo,
            query: {
                ...(payload.backTo.query ?? {}),
                ...pickScope(routeQuery),
            },
        },
    };
};

export const isLiveScreen = (screenKey) => liveScreenKeys.has(String(screenKey ?? ''));

export const fetchLiveScreen = async (screenKey, { range = '24h', routeQuery = {} } = {}) => {
    const blueprint = apiBlueprint[screenKey];

    if (!blueprint?.listEndpoint) {
        throw new Error(`No live list endpoint configured for [${screenKey}].`);
    }

    const response = await axios.get(blueprint.listEndpoint, {
        params: {
            range,
            per_page: 100,
            ...pickScope(routeQuery),
        },
    });

    return response.data;
};

export const fetchLiveDetail = async (screenKey, detailId, { routeQuery = {} } = {}) => {
    const blueprint = apiBlueprint[screenKey];

    if (!blueprint?.detailEndpoint) {
        throw new Error(`No live detail endpoint configured for [${screenKey}].`);
    }

    const endpoint = blueprint.detailEndpoint.replace(
        blueprint.detailToken ?? '{detailId}',
        encodeURIComponent(detailId),
    );

    const response = await axios.get(endpoint, {
        params: pickScope(routeQuery),
    });

    return withDetailBackToScope(response.data, routeQuery);
};

export const runLiveDetailAction = async (action, { routeQuery = {} } = {}) => {
    if (!action?.endpoint) {
        throw new Error('No action endpoint configured.');
    }

    const response = await axios({
        method: action.method ?? 'post',
        url: action.endpoint,
        params: pickScope(routeQuery),
    });

    return withDetailBackToScope(response.data, routeQuery);
};

export const fetchIssueCount = async ({ range = '24h', routeQuery = {} } = {}) => {
    const response = await axios.get('/api/issues', {
        params: {
            range,
            per_page: 1,
            ...pickScope(routeQuery),
        },
    });

    return Number(response.data?.pagination?.total ?? 0);
};
