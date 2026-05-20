export const pickScopeQuery = (query = {}) => {
    const scope = {};

    if (query.project_id !== undefined && query.project_id !== null && query.project_id !== '') {
        scope.project_id = String(query.project_id);
    }

    if (query.environment !== undefined && query.environment !== null && query.environment !== '') {
        scope.environment = String(query.environment);
    }

    return scope;
};

export const mergeScopeIntoTarget = (target, query = {}) => {
    const scope = pickScopeQuery(query);

    if (!Object.keys(scope).length) {
        return target;
    }

    return {
        ...target,
        query: {
            ...(target?.query ?? {}),
            ...scope,
        },
    };
};
