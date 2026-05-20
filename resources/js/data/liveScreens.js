import axios from 'axios';
import { apiBlueprint } from './mockApi';

const liveScreenKeys = new Set(['requests', 'exceptions', 'jobs', 'commands', 'scheduled-tasks', 'queries', 'notifications', 'mail', 'cache']);

const pickScope = (query = {}) => {
    const scope = {};

    if (query.project_id) {
        scope.project_id = query.project_id;
    }

    if (query.environment) {
        scope.environment = query.environment;
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
