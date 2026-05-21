import axios from 'axios';
import { apiBlueprint } from './mockApi';
import { pickScopeQuery } from '../utils/scopeQuery';

export const fetchDashboard = async ({ range = '24h', routeQuery = {} } = {}) => {
    const response = await axios.get(apiBlueprint.dashboard.endpoint, {
        params: {
            range,
            ...pickScopeQuery(routeQuery),
        },
    });

    return response.data;
};
