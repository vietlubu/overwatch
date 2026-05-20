const bars = (...entries) => entries.map(([value, tone = 'blue']) => ({ value, tone }));
const meta = (...entries) => entries.map(([label, tone]) => ({ label, tone }));
const cell = (text, extras = {}) => ({ text, ...extras });
const timelineRow = (label, value, width, tone) => ({ label, value, width, tone });
const info = (label, value) => ({ label, value });

const backTo = (screenKey) => ({ name: 'screen', params: { screenKey } });

export const filters = [
    { key: '1h', label: '1H' },
    { key: '24h', label: '24H' },
    { key: '7d', label: '7D' },
    { key: '14d', label: '14D' },
    { key: '30d', label: '30D' },
];

export const navSections = [
    {
        title: 'Overview',
        items: [
            { key: 'dashboard', label: 'Dashboard', icon: '[]', to: { name: 'dashboard' } },
            { key: 'issues', label: 'Issues', icon: '[!]', badge: '1', to: { name: 'screen', params: { screenKey: 'issues' } } },
        ],
    },
    {
        title: 'Activity',
        items: [
            { key: 'requests', label: 'Requests', icon: '->', to: { name: 'screen', params: { screenKey: 'requests' } } },
            { key: 'jobs', label: 'Jobs', icon: '{}', to: { name: 'screen', params: { screenKey: 'jobs' } } },
            { key: 'commands', label: 'Commands', icon: '>_', to: { name: 'screen', params: { screenKey: 'commands' } } },
            { key: 'scheduled-tasks', label: 'Scheduled Tasks', icon: '[*]', to: { name: 'screen', params: { screenKey: 'scheduled-tasks' } } },
            { key: 'exceptions', label: 'Exceptions', icon: '[x]', to: { name: 'screen', params: { screenKey: 'exceptions' } } },
            { key: 'queries', label: 'Queries', icon: '[?]', to: { name: 'screen', params: { screenKey: 'queries' } } },
            { key: 'notifications', label: 'Notifications', icon: '[@]', to: { name: 'screen', params: { screenKey: 'notifications' } } },
            { key: 'mail', label: 'Mail', icon: '[M]', to: { name: 'screen', params: { screenKey: 'mail' } } },
            { key: 'cache', label: 'Cache', icon: '[$]', to: { name: 'screen', params: { screenKey: 'cache' } } },
            { key: 'outgoing-requests', label: 'Outgoing Requests', icon: '<-', to: { name: 'screen', params: { screenKey: 'outgoing-requests' } } },
        ],
    },
    {
        title: 'Monitoring',
        items: [
            { key: 'users', label: 'Users', icon: '[U]', to: { name: 'screen', params: { screenKey: 'users' } } },
            { key: 'logs', label: 'Logs', icon: '[L]', to: { name: 'screen', params: { screenKey: 'logs' } } },
        ],
    },
    {
        title: 'Workspace',
        items: [
            { key: 'settings', label: 'Settings', icon: '[=]', to: { name: 'screen', params: { screenKey: 'settings' } } },
            { key: 'support', label: 'Support', icon: '[?]', to: { name: 'screen', params: { screenKey: 'support' } } },
        ],
    },
];

export const dashboardScreen = {
    eyebrow: 'Overwatch',
    title: 'Dashboard',
    subtitle: 'Prototype UI for the ingest server, using Nightwatch information architecture while shaping data around Overwatch tables and future APIs.',
    sections: [
        {
            title: 'Activity',
            icon: '[]',
            caption: 'Rollups mapped from nw_request_route_1m, nw_job_queue_1m, and nw_command_1m.',
            metrics: [
                {
                    label: 'REQUESTS',
                    caption: 'route rollup · avg 283.9ms',
                    value: '158',
                    meta: meta(['1xx/3xx 158', 'green'], ['4xx 0', 'yellow'], ['5xx 0', 'red']),
                    bars: bars([8, 'blue'], [18, 'blue'], [14, 'blue'], [38, 'sky'], [24, 'sky'], [76, 'blue'], [64, 'blue']),
                },
                {
                    label: 'DURATION',
                    caption: '36.12ms - 1.97s',
                    value: '283.9ms',
                    meta: meta(['AVG', 'blue'], ['P95 1.05s', 'yellow']),
                    bars: bars([12, 'yellow'], [16, 'yellow'], [44, 'yellow'], [24, 'blue'], [66, 'yellow'], [31, 'blue']),
                },
                {
                    label: 'ISSUES',
                    caption: 'exception group rollup',
                    value: '1 open',
                    meta: meta(['Unhandled', 'red'], ['Impacted users 0', 'sky']),
                    bars: bars([0, 'green'], [0, 'green'], [0, 'green'], [88, 'red'], [0, 'green'], [0, 'green']),
                },
            ],
            plots: [
                {
                    title: 'Slowest Routes',
                    caption: 'The same panel can later be backed by `/api/requests/slowest`.',
                    fromLabel: 'May 20, 20:28 +07:00',
                    toLabel: 'May 20, 21:28 +07:00',
                    series: [
                        { label: 'AVG', tone: 'blue', values: [0.12, 0.2, 0.14, 0.28, 0.18, 0.34, 0.26, 0.3] },
                        { label: 'P95', tone: 'yellow', values: [0.22, 0.48, 0.2, 0.78, 0.36, 0.9, 0.58, 0.42] },
                    ],
                },
                {
                    title: 'Job Throughput',
                    caption: 'Queue health blended from nw_jobs and nw_job_attempt_details.',
                    fromLabel: 'May 20, 20:28 +07:00',
                    toLabel: 'May 20, 21:28 +07:00',
                    series: [
                        { label: 'processed', tone: 'green', values: [0.2, 0.38, 0.16, 0.52, 0.44, 0.3, 0.62, 0.5] },
                        { label: 'failed', tone: 'red', values: [0, 0.12, 0, 0.08, 0, 0.22, 0, 0] },
                    ],
                },
            ],
            tables: [
                {
                    title: 'Top Routes',
                    caption: 'Preview of a possible `/api/requests/routes` payload.',
                    searchPlaceholder: 'grep route_name, method…',
                    columns: [
                        { key: 'route', label: 'Route', kind: 'primary' },
                        { key: 'requests', label: 'Requests' },
                        { key: 'avg', label: 'Avg', kind: 'code' },
                        { key: 'p95', label: 'P95', kind: 'code' },
                    ],
                    rows: [
                        { id: 'dash-route-1', route: cell('POST /graphql', { meta: 'nightwatch.graphql' }), requests: cell('79'), avg: cell('88.45ms'), p95: cell('474.79ms') },
                        { id: 'dash-route-2', route: cell('GET /api/posts', { meta: 'api.posts.index' }), requests: cell('42'), avg: cell('42.14ms'), p95: cell('188.11ms') },
                        { id: 'dash-route-3', route: cell('GET /healthz', { meta: 'healthz' }), requests: cell('21'), avg: cell('2.11ms'), p95: cell('8.04ms') },
                    ],
                },
            ],
        },
        {
            title: 'Application',
            icon: '[*]',
            caption: 'Execution-centric summaries based on nw_executions plus child event tables.',
            metrics: [
                {
                    label: 'JOBS',
                    caption: 'processed 4 · released 0',
                    value: '0 failed',
                    meta: meta(['Processed', 'green'], ['Failed', 'red']),
                    bars: bars([0, 'red'], [36, 'green'], [58, 'green'], [24, 'green'], [66, 'green']),
                },
                {
                    label: 'COMMANDS',
                    caption: '4 command executions',
                    value: '555.24ms',
                    meta: meta(['AVG', 'blue'], ['P95 676.72ms', 'yellow']),
                    bars: bars([14, 'blue'], [38, 'blue'], [44, 'blue'], [28, 'yellow'], [62, 'yellow']),
                },
                {
                    label: 'CACHE',
                    caption: '94 events across 21 keys',
                    value: '66 writes',
                    meta: meta(['hits 0', 'blue'], ['misses 14', 'yellow'], ['failures 0', 'green']),
                    bars: bars([4, 'yellow'], [0, 'green'], [78, 'blue'], [26, 'yellow'], [54, 'blue'], [82, 'blue']),
                },
            ],
            panels: [
                {
                    title: 'Thresholds',
                    caption: 'Future project-level settings payload.',
                    entries: [
                        info('Latency budget', 'P95 <span class="value-strong">650ms</span>'),
                        info('Error budget', '<span class="value-strong">0</span> unhandled exceptions / hour'),
                        info('Alert fan-out', 'Slack + mail digest every 5 minutes'),
                    ],
                },
                {
                    title: 'Retention',
                    caption: 'Mirrors `overwatch.storage.*` config.',
                    entries: [
                        info('Fact tables', '30 days'),
                        info('Rollup tables', '180 days'),
                        info('Raw ingest batches', '30 days'),
                    ],
                },
            ],
        },
        {
            title: 'Users',
            icon: '[U]',
            caption: 'User dimensions taken from nw_users plus execution activity.',
            metrics: [
                {
                    label: 'AUTHENTICATED USERS',
                    caption: '2 distinct users in the last hour',
                    value: '72 requests',
                    meta: meta(['active', 'green'], ['idle', 'yellow']),
                    bars: bars([0, 'green'], [18, 'green'], [0, 'green'], [42, 'green'], [72, 'green']),
                },
                {
                    label: 'IMPACTED USERS',
                    caption: 'no users affected by current issue',
                    value: '0',
                    meta: meta(['exceptions', 'red'], ['requests', 'blue']),
                    bars: bars([0, 'red'], [0, 'red'], [0, 'red'], [0, 'red']),
                },
            ],
            tables: [
                {
                    title: 'Most Active Users',
                    caption: 'Likely future endpoint: `/api/users/activity`.',
                    searchPlaceholder: 'grep name, email…',
                    columns: [
                        { key: 'user', label: 'User', kind: 'primary' },
                        { key: 'requests', label: 'Requests' },
                        { key: 'exceptions', label: 'Exceptions', kind: 'tone' },
                    ],
                    rows: [
                        { id: 'dash-user-1', user: cell('CGBS12', { meta: 'CGBS12@gmail.com' }), requests: cell('72'), exceptions: cell('0', { tone: 'green' }) },
                        { id: 'dash-user-2', user: cell('mike', { meta: 'mike@app.dev' }), requests: cell('18'), exceptions: cell('1', { tone: 'red' }) },
                    ],
                },
            ],
        },
    ],
};

const sharedExceptionDetail = {
    eyebrow: 'Exception detail',
    title: 'Call to undefined function App\\GraphQL\\Mutations\\unset()',
    subtitle: 'Detailed view stitched from nw_exceptions, nw_executions, request metadata, and source context.',
    backTo: backTo('exceptions'),
    tags: [
        { text: 'Unhandled', tone: 'red' },
        { text: 'HTTP request', tone: 'blue' },
        { text: 'graphql', tone: 'mauve' },
    ],
    metrics: [
        {
            label: 'LAST SEEN',
            caption: 'May 20, 2026, 11:23:40 +07:00',
            value: '1 issue',
            meta: meta(['comments 1', 'blue'], ['handled 0', 'green'], ['unhandled 1', 'red']),
            bars: bars([0, 'green'], [0, 'green'], [88, 'red'], [0, 'green']),
        },
        {
            label: 'RUNTIME',
            caption: 'Request duration',
            value: '151.14ms',
            meta: meta(['peak 5.2MB', 'sky'], ['queries 6', 'yellow']),
            bars: bars([12, 'blue'], [34, 'yellow'], [68, 'red'], [24, 'blue']),
        },
    ],
    summaryPanels: [
        {
            title: 'Occurrence',
            caption: 'Derived from nw_exceptions + nw_request_details.',
            entries: [
                info('First seen', 'May 20, 2026, 11:23:40 +07:00'),
                info('Project', 'Terrace Dev'),
                info('Trace id', 'req_01JTD6Y2N2K4R2'),
                info('Request', 'POST <span class="value-strong">/graphql</span>'),
                info('User', 'CGBS12'),
            ],
        },
        {
            title: 'Runtime',
            caption: 'Execution counters mirrored from nw_executions.',
            entries: [
                info('Queries', '6'),
                info('Logs', '1'),
                info('Outgoing requests', '0'),
                info('Notifications', '0'),
                info('Hydrated models', '3'),
            ],
        },
    ],
    timeline: {
        title: 'Execution Timeline',
        caption: 'Potential response from `/api/executions/{id}/timeline`.',
        rows: [
            timelineRow('bootstrap', '3.4ms', 8, 'blue'),
            timelineRow('before middleware', '7.2ms', 18, 'sky'),
            timelineRow('resolver action', '112.9ms', 84, 'red'),
            timelineRow('render + send', '27.6ms', 26, 'yellow'),
        ],
    },
    codePanels: [
        {
            title: 'Error Preview',
            code: `Error: Call to undefined function App\\GraphQL\\Mutations\\unset()\n\nat app/GraphQL/Mutations/Authorisation.php:18\n\n18 | if (unset($request['user'])) {\n19 |     // impossible branch\n20 | }`,
        },
        {
            title: 'Stack Frames',
            code: `#0 app/GraphQL/Mutations/Authorisation.php:18\n#1 packages/overwatch/vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php:46\n#2 vendor/nuwave/lighthouse/src/Execution/Arguments/ArgumentSet.php:97\n#3 vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php:219`,
        },
    ],
    tables: [
        {
            title: 'Related Executions',
            caption: 'Nearby requests sharing the same route or user.',
            searchPlaceholder: 'grep execution id…',
            columns: [
                { key: 'execution', label: 'Execution', kind: 'primary' },
                { key: 'status', label: 'Status', kind: 'tone' },
                { key: 'duration', label: 'Duration', kind: 'code' },
            ],
            rows: [
                { id: 'exc-run-1', execution: cell('req_01JTD6Y2N2K4R2', { meta: 'POST /graphql' }), status: cell('exception', { tone: 'red' }), duration: cell('151.14ms') },
                { id: 'exc-run-2', execution: cell('req_01JTD6Y2KNL3E', { meta: 'POST /graphql' }), status: cell('ok', { tone: 'green' }), duration: cell('88.45ms') },
            ],
        },
    ],
};

const screens = {
    issues: {
        kind: 'collection',
        icon: '[!]',
        eyebrow: 'Open issues',
        title: 'Issues',
        subtitle: 'An operator-first queue of unresolved exception groups, derived from nw_exceptions where handled = false.',
        metrics: [
            {
                label: 'OPEN',
                caption: 'active issue groups',
                value: '1',
                meta: meta(['unresolved', 'red'], ['impacted users 0', 'sky']),
                bars: bars([0, 'green'], [0, 'green'], [88, 'red']),
            },
            {
                label: 'LAST SEEN',
                caption: 'May 20, 2026, 11:23:40 +07:00',
                value: '151.14ms',
                meta: meta(['latest runtime', 'yellow']),
                bars: bars([22, 'yellow'], [66, 'red']),
            },
        ],
        table: {
            title: 'Unresolved groups',
            caption: 'API sketch: `/api/issues`',
            searchPlaceholder: 'grep class, route, user…',
            columns: [
                { key: 'issue', label: 'Issue', kind: 'primary' },
                { key: 'severity', label: 'Severity', kind: 'tone' },
                { key: 'lastSeen', label: 'Last seen', kind: 'code' },
                { key: 'count', label: 'Count' },
            ],
            rows: [
                {
                    id: 'issue-unset',
                    href: { name: 'screen', params: { screenKey: 'issues', detailId: 'issue-unset' } },
                    issue: cell('Call to undefined function App\\GraphQL\\Mutations\\unset()', { meta: 'POST /graphql · CGBS12' }),
                    severity: cell('critical', { tone: 'red' }),
                    lastSeen: cell('11:23:40 +07'),
                    count: cell('1'),
                },
            ],
        },
        details: {
            'issue-unset': {
                ...sharedExceptionDetail,
                backTo: backTo('issues'),
                eyebrow: 'Issue detail',
            },
        },
    },
    requests: {
        kind: 'collection',
        eyebrow: 'HTTP activity',
        title: 'Requests',
        subtitle: 'Grouped route performance built from nw_request_route_1m with drill-down into nw_request_details.',
        actions: [{ label: 'Open routes map' }],
        metrics: [
            {
                label: 'REQUESTS',
                caption: '158 total',
                value: '158',
                meta: meta(['1xx/3xx 158', 'green'], ['4xx 0', 'yellow'], ['5xx 0', 'red']),
                bars: bars([12, 'blue'], [26, 'blue'], [18, 'blue'], [42, 'sky'], [68, 'blue']),
            },
            {
                label: 'DURATION',
                caption: '36.12ms - 1.97s',
                value: '283.9ms',
                meta: meta(['AVG', 'blue'], ['P95 1.05s', 'yellow']),
                bars: bars([18, 'yellow'], [26, 'blue'], [74, 'yellow'], [34, 'blue']),
            },
        ],
        plots: [
            {
                title: 'Requests by minute',
                caption: 'A compact line view for request volume and latency.',
                fromLabel: '10:28 +07:00',
                toLabel: '11:28 +07:00',
                series: [
                    { label: 'requests', tone: 'sky', values: [0.08, 0.2, 0.16, 0.44, 0.26, 0.82, 0.58, 0.66] },
                    { label: 'p95', tone: 'yellow', values: [0.1, 0.5, 0.14, 0.9, 0.3, 0.74, 0.86, 0.42] },
                ],
            },
        ],
        table: {
            title: '5 Routes',
            caption: 'Potential response shape: `{ summary, rows, pagination }`',
            searchPlaceholder: 'grep route, url, execution…',
            columns: [
                { key: 'route', label: 'Route', kind: 'primary' },
                { key: 'requests', label: 'Requests' },
                { key: 'users', label: 'Users' },
                { key: 'avg', label: 'Avg', kind: 'code' },
                { key: 'p95', label: 'P95', kind: 'code' },
                { key: 'failures', label: '5XX', kind: 'tone' },
            ],
            rows: [
                { id: 'req-graphql', href: { name: 'screen', params: { screenKey: 'requests', detailId: 'request-graphql' } }, route: cell('POST /graphql', { meta: 'nightwatch.graphql' }), requests: cell('79'), users: cell('1'), avg: cell('88.45ms'), p95: cell('474.79ms'), failures: cell('0', { tone: 'green' }) },
                { id: 'req-api-posts', href: { name: 'screen', params: { screenKey: 'requests', detailId: 'request-api-posts' } }, route: cell('GET /api/posts', { meta: 'api.posts.index' }), requests: cell('42'), users: cell('2'), avg: cell('42.14ms'), p95: cell('188.11ms'), failures: cell('0', { tone: 'green' }) },
                { id: 'req-upload', route: cell('POST /api/assets/upload', { meta: 'api.assets.upload' }), requests: cell('9'), users: cell('1'), avg: cell('316.22ms'), p95: cell('1.12s'), failures: cell('1', { tone: 'red' }) },
                { id: 'req-healthz', route: cell('GET /healthz', { meta: 'healthz' }), requests: cell('21'), users: cell('0'), avg: cell('2.11ms'), p95: cell('8.04ms'), failures: cell('0', { tone: 'green' }) },
            ],
        },
        details: {
            'request-graphql': {
                eyebrow: 'Request detail',
                title: 'POST /graphql',
                subtitle: 'Execution detail based on nw_request_details joined to nw_executions, cache, query, notification, and user tables.',
                backTo: backTo('requests'),
                tags: [
                    { text: '200 OK', tone: 'green' },
                    { text: 'user CGBS12', tone: 'sky' },
                    { text: 'graphql', tone: 'mauve' },
                ],
                metrics: [
                    {
                        label: 'REQUEST',
                        caption: 'received May 20, 2026, 11:23:37 +07:00',
                        value: '79',
                        meta: meta(['status 200', 'green'], ['payload 4.45kB', 'blue']),
                        bars: bars([12, 'blue'], [68, 'sky'], [22, 'blue'], [14, 'green']),
                    },
                    {
                        label: 'DURATION',
                        caption: 'AVG 88.45ms',
                        value: '474.79ms',
                        meta: meta(['P95', 'yellow'], ['P99 1.31s', 'red']),
                        bars: bars([12, 'yellow'], [26, 'yellow'], [84, 'red'], [18, 'blue']),
                    },
                ],
                summaryPanels: [
                    {
                        title: 'Info',
                        caption: 'Mirrors nw_request_details columns.',
                        entries: [
                            info('Method', 'POST'),
                            info('Route', '/graphql'),
                            info('Status code', '200'),
                            info('Server', 'ip-172-31-42-81'),
                            info('Payload state', 'present'),
                        ],
                    },
                    {
                        title: 'Events',
                        caption: 'Counts borrowed from nw_executions.',
                        entries: [
                            info('Queries', '6 events / 6 calls'),
                            info('Notification', '1 event'),
                            info('Cache', '1 event / 3 misses'),
                            info('Queue jobs', '0'),
                            info('Mail', '0'),
                        ],
                    },
                ],
                timeline: {
                    title: 'Timeline',
                    caption: 'The frontend expects a normalized duration breakdown array.',
                    rows: [
                        timelineRow('bootstrap', '4.1ms', 10, 'blue'),
                        timelineRow('middleware', '9.6ms', 18, 'sky'),
                        timelineRow('graphql resolver', '44.2ms', 76, 'mauve'),
                        timelineRow('render', '11.8ms', 22, 'yellow'),
                        timelineRow('send', '5.5ms', 11, 'green'),
                    ],
                },
                codePanels: [
                    {
                        title: 'Headers',
                        code: `accept: application/json\ncontent-type: application/json\nx-nightwatch-token: nw_****\nx-forwarded-for: 103.27.24.91`,
                    },
                    {
                        title: 'Request Payload',
                        code: `{\n  "operationName": "posts",\n  "variables": {},\n  "query": "query posts { posts { id title author { name } } }"\n}`,
                    },
                ],
                tables: [
                    {
                        title: 'Execution Timeline Events',
                        caption: 'How a future `/api/requests/{id}/events` could look.',
                        searchPlaceholder: 'grep event, span…',
                        columns: [
                            { key: 'event', label: 'Event', kind: 'primary' },
                            { key: 'source', label: 'Source' },
                            { key: 'duration', label: 'Duration', kind: 'code' },
                        ],
                        rows: [
                            { id: 'req-ev-1', event: cell('graphql.posts resolver', { meta: 'App\\GraphQL\\Resolvers\\PostResolver' }), source: cell('request'), duration: cell('44.20ms') },
                            { id: 'req-ev-2', event: cell('select * from posts', { meta: 'query' }), source: cell('db'), duration: cell('11.48ms') },
                            { id: 'req-ev-3', event: cell('notification: PostViewed', { meta: 'broadcast' }), source: cell('notification'), duration: cell('7.91ms') },
                        ],
                    },
                ],
            },
            'request-api-posts': {
                eyebrow: 'Request detail',
                title: 'GET /api/posts',
                subtitle: 'List endpoint with eager-loading and modest cache churn.',
                backTo: backTo('requests'),
                tags: [{ text: '200 OK', tone: 'green' }, { text: 'api.posts.index', tone: 'blue' }],
                metrics: [
                    { label: 'REQUEST', caption: '42 total', value: '42', meta: meta(['users 2', 'sky']), bars: bars([18, 'blue'], [32, 'sky'], [28, 'blue']) },
                    { label: 'DURATION', caption: 'AVG 42.14ms', value: '188.11ms', meta: meta(['P95', 'yellow']), bars: bars([22, 'yellow'], [44, 'blue'], [58, 'yellow']) },
                ],
                summaryPanels: [
                    { title: 'Info', caption: '', entries: [info('Method', 'GET'), info('Route', '/api/posts'), info('Status', '200'), info('Payload state', 'absent')] },
                ],
                timeline: { title: 'Timeline', caption: '', rows: [timelineRow('bootstrap', '2.8ms', 9, 'blue'), timelineRow('controller', '18.4ms', 56, 'green'), timelineRow('json encode', '5.1ms', 18, 'sky')] },
            },
        },
    },
    jobs: {
        kind: 'collection',
        eyebrow: 'Queued work',
        title: 'Jobs',
        subtitle: 'Job queue rollups and per-job drill-down sourced from nw_jobs, nw_queued_jobs, and nw_job_attempt_details.',
        metrics: [
            { label: 'JOBS', caption: '0 failed', value: '4 processed', meta: meta(['released 0', 'yellow']), bars: bars([44, 'green'], [22, 'green'], [58, 'green'], [0, 'red']) },
            { label: 'DURATION', caption: '—', value: 'n/a', meta: meta(['P95 unavailable', 'mauve']), bars: bars([0, 'blue'], [0, 'blue']) },
        ],
        table: {
            title: '4 Jobs',
            caption: 'A collection-level payload can join current state from nw_jobs and recent attempts.',
            searchPlaceholder: 'grep queue, class…',
            columns: [
                { key: 'job', label: 'Job', kind: 'primary' },
                { key: 'attempts', label: 'Attempts' },
                { key: 'processed', label: 'Processed', kind: 'tone' },
                { key: 'queue', label: 'Queue', kind: 'code' },
            ],
            rows: [
                { id: 'job-send-post', href: { name: 'screen', params: { screenKey: 'jobs', detailId: 'job-send-post-notification' } }, job: cell('App\\Jobs\\SendNewPostNotificationJob', { meta: 'redis · notifications' }), attempts: cell('1'), processed: cell('processed', { tone: 'green' }), queue: cell('notifications') },
                { id: 'job-sync-media', job: cell('App\\Jobs\\SyncMediaLibrary', { meta: 'sqs · media' }), attempts: cell('2'), processed: cell('processed', { tone: 'green' }), queue: cell('media') },
                { id: 'job-reindex', job: cell('App\\Jobs\\ReindexSearch', { meta: 'redis · indexing' }), attempts: cell('1'), processed: cell('processed', { tone: 'green' }), queue: cell('indexing') },
            ],
        },
        details: {
            'job-send-post-notification': {
                eyebrow: 'Job detail',
                title: 'App\\Jobs\\SendNewPostNotificationJob',
                subtitle: 'Consolidated job view centered on nw_jobs with last attempt facts from nw_job_attempt_details.',
                backTo: backTo('jobs'),
                tags: [{ text: 'processed', tone: 'green' }, { text: 'notifications', tone: 'sky' }],
                metrics: [
                    { label: 'ATTEMPTS', caption: 'last attempt job_01JTD6X', value: '1', meta: meta(['failed 0', 'green']), bars: bars([76, 'green']) },
                    { label: 'DURATION', caption: 'runtime unavailable in current schema', value: '—', meta: meta(['extend later', 'mauve']), bars: bars([6, 'mauve']) },
                ],
                summaryPanels: [
                    {
                        title: 'Info',
                        caption: '',
                        entries: [
                            info('Job id', 'job_01JTD6XF4TM4V'),
                            info('Connection', 'redis'),
                            info('Queue', 'notifications'),
                            info('First queued at', 'May 20, 2026, 11:24:12 +07:00'),
                            info('Last status', 'processed'),
                        ],
                    },
                ],
                tables: [
                    {
                        title: 'Attempts',
                        caption: 'Suggested endpoint: `/api/jobs/{jobId}/attempts`',
                        searchPlaceholder: 'grep attempt id…',
                        columns: [
                            { key: 'attempt', label: 'Attempt', kind: 'primary' },
                            { key: 'status', label: 'Status', kind: 'tone' },
                            { key: 'when', label: 'When', kind: 'code' },
                        ],
                        rows: [
                            { id: 'job-attempt-1', attempt: cell('attempt #1', { meta: 'exec job_exec_01JTD6XF4T' }), status: cell('processed', { tone: 'green' }), when: cell('11:24:12 +07') },
                        ],
                    },
                ],
            },
        },
    },
    commands: {
        kind: 'collection',
        eyebrow: 'Console activity',
        title: 'Commands',
        subtitle: 'Command execution summaries backed by nw_command_1m and command detail rows from nw_command_details.',
        metrics: [
            { label: 'COMMANDS', caption: '4 executions', value: '4', meta: meta(['unsuccessful 1', 'red'], ['successful 3', 'green']), bars: bars([22, 'green'], [42, 'green'], [64, 'yellow'], [78, 'red']) },
            { label: 'DURATION', caption: '488.43ms - 676.72ms', value: '555.24ms', meta: meta(['AVG', 'blue'], ['P95 676.72ms', 'yellow']), bars: bars([22, 'blue'], [48, 'yellow'], [68, 'yellow']) },
        ],
        table: {
            title: '4 Commands',
            caption: '',
            searchPlaceholder: 'grep artisan name…',
            columns: [
                { key: 'command', label: 'Command', kind: 'primary' },
                { key: 'status', label: 'Status', kind: 'tone' },
                { key: 'avg', label: 'Avg', kind: 'code' },
                { key: 'p95', label: 'P95', kind: 'code' },
            ],
            rows: [
                { id: 'cmd-schedule-notification', href: { name: 'screen', params: { screenKey: 'commands', detailId: 'cmd-schedule-notification' } }, command: cell('app:send-scheduled-notification', { meta: 'App\\Console\\Commands\\SendScheduledNotification' }), status: cell('successful', { tone: 'green' }), avg: cell('498.54ms'), p95: cell('561.67ms') },
                { id: 'cmd-nw-rollup', command: cell('nightwatch:rollup', { meta: 'NightwatchRollupCommand' }), status: cell('successful', { tone: 'green' }), avg: cell('312.18ms'), p95: cell('401.84ms') },
                { id: 'cmd-cache-warm', command: cell('cache:warm-home', { meta: 'App\\Console\\Commands\\WarmHomepageCache' }), status: cell('unsuccessful', { tone: 'red' }), avg: cell('676.72ms'), p95: cell('676.72ms') },
            ],
        },
        details: {
            'cmd-schedule-notification': {
                eyebrow: 'Command detail',
                title: 'app:send-scheduled-notification',
                subtitle: 'Terminal-mode detail page for a single command execution group.',
                backTo: backTo('commands'),
                tags: [{ text: 'successful', tone: 'green' }, { text: 'artisan', tone: 'blue' }],
                metrics: [
                    { label: 'CALLS', caption: '4 total', value: '4', meta: meta(['exit code 0', 'green']), bars: bars([42, 'green'], [56, 'green'], [64, 'green']) },
                    { label: 'DURATION', caption: '498.54ms - 561.67ms', value: '538.33ms', meta: meta(['AVG', 'blue']), bars: bars([18, 'yellow'], [34, 'yellow'], [68, 'yellow']) },
                ],
                summaryPanels: [
                    {
                        title: 'Command',
                        caption: '',
                        entries: [
                            info('Name', 'app:send-scheduled-notification'),
                            info('Class', 'App\\Console\\Commands\\SendScheduledNotification'),
                            info('Last exit code', '0'),
                            info('Source', 'schedule'),
                        ],
                    },
                ],
                tables: [
                    {
                        title: 'Recent Runs',
                        caption: '',
                        searchPlaceholder: 'grep run id…',
                        columns: [
                            { key: 'run', label: 'Run', kind: 'primary' },
                            { key: 'status', label: 'Status', kind: 'tone' },
                            { key: 'duration', label: 'Duration', kind: 'code' },
                        ],
                        rows: [
                            { id: 'cmd-run-1', run: cell('May 20, 11:24:12 +07', { meta: 'exec cmd_01JTD6Y0' }), status: cell('successful', { tone: 'green' }), duration: cell('561.67ms') },
                            { id: 'cmd-run-2', run: cell('May 20, 10:54:12 +07', { meta: 'exec cmd_01JTD6W2' }), status: cell('successful', { tone: 'green' }), duration: cell('534.84ms') },
                        ],
                    },
                ],
            },
        },
    },
    'scheduled-tasks': {
        kind: 'collection',
        eyebrow: 'Scheduler',
        title: 'Scheduled Tasks',
        subtitle: 'Schedule telemetry from nw_schedule_1m and per-run metadata from nw_scheduled_task_details.',
        metrics: [
            { label: 'RUNS', caption: '14 total', value: '14', meta: meta(['failed 0', 'green'], ['skipped 0', 'green']), bars: bars([22, 'green'], [38, 'green'], [56, 'green'], [68, 'green']) },
            { label: 'DURATION', caption: '560.32ms - 773.22ms', value: '633.65ms', meta: meta(['AVG', 'blue'], ['P95 773.22ms', 'yellow']), bars: bars([20, 'yellow'], [42, 'yellow'], [74, 'yellow']) },
        ],
        table: {
            title: '2 Tasks',
            caption: '',
            searchPlaceholder: 'grep cron, command…',
            columns: [
                { key: 'task', label: 'Task', kind: 'primary' },
                { key: 'schedule', label: 'Schedule', kind: 'code' },
                { key: 'status', label: 'Status', kind: 'tone' },
                { key: 'avg', label: 'Avg', kind: 'code' },
            ],
            rows: [
                { id: 'sched-notification', href: { name: 'screen', params: { screenKey: 'scheduled-tasks', detailId: 'sched-appsend-notification' } }, task: cell('php artisan app:send-scheduled-notification', { meta: 'every 5 minutes' }), schedule: cell('*/5 * * * *'), status: cell('processed', { tone: 'green' }), avg: cell('609.09ms') },
                { id: 'sched-cache-clean', task: cell('php artisan cache:prune-stale-tags', { meta: 'every 15 minutes' }), schedule: cell('*/15 * * * *'), status: cell('processed', { tone: 'green' }), avg: cell('185.12ms') },
            ],
        },
        details: {
            'sched-appsend-notification': {
                eyebrow: 'Scheduled task detail',
                title: 'php artisan app:send-scheduled-notification',
                subtitle: 'A detail screen shaped around nw_scheduled_task_details with related command activity.',
                backTo: backTo('scheduled-tasks'),
                tags: [{ text: 'processed', tone: 'green' }, { text: 'every 5 minutes', tone: 'mauve' }],
                metrics: [
                    { label: 'RUNS', caption: '4', value: '4', meta: meta(['processed', 'green']), bars: bars([24, 'green'], [56, 'green'], [62, 'green'], [70, 'green']) },
                    { label: 'DURATION', caption: '560.32ms - 633.66ms', value: '605.09ms', meta: meta(['AVG', 'blue']), bars: bars([28, 'yellow'], [48, 'yellow'], [72, 'yellow']) },
                ],
                summaryPanels: [
                    {
                        title: 'Info',
                        caption: '',
                        entries: [
                            info('Cron', '*/5 * * * *'),
                            info('Timezone', 'Asia/Ho_Chi_Minh'),
                            info('Without overlapping', 'Yes'),
                            info('Run in background', 'No'),
                            info('On one server', 'No'),
                        ],
                    },
                    {
                        title: 'Events',
                        caption: '',
                        entries: [
                            info('Queries', '0'),
                            info('Outgoing requests', '0'),
                            info('Mail', '0'),
                            info('Queue jobs', '0'),
                        ],
                    },
                ],
                timeline: {
                    title: 'Runtime breakdown',
                    caption: '',
                    rows: [
                        timelineRow('bootstrap', '48ms', 18, 'blue'),
                        timelineRow('schedule dispatch', '502ms', 86, 'yellow'),
                        timelineRow('terminating', '33ms', 11, 'green'),
                    ],
                },
                tables: [
                    {
                        title: 'Recent Runs',
                        caption: '',
                        searchPlaceholder: 'grep run timestamp…',
                        columns: [
                            { key: 'run', label: 'Run', kind: 'primary' },
                            { key: 'status', label: 'Status', kind: 'tone' },
                            { key: 'duration', label: 'Duration', kind: 'code' },
                        ],
                        rows: [
                            { id: 'sched-run-1', run: cell('May 20, 11:25:00 +07', { meta: 'exec sched_01JT...' }), status: cell('processed', { tone: 'green' }), duration: cell('633.66ms') },
                            { id: 'sched-run-2', run: cell('May 20, 11:20:00 +07', { meta: 'exec sched_01JT...' }), status: cell('processed', { tone: 'green' }), duration: cell('609.09ms') },
                        ],
                    },
                ],
            },
        },
    },
    exceptions: {
        kind: 'collection',
        eyebrow: 'Exceptions',
        title: 'Exceptions',
        subtitle: 'Exception groups and latest occurrences stored in nw_exceptions.',
        metrics: [
            { label: 'EXCEPTIONS', caption: '1 group', value: '1', meta: meta(['handled 0', 'green'], ['unhandled 1', 'red']), bars: bars([0, 'green'], [88, 'red']) },
        ],
        table: {
            title: '1 Exception',
            caption: '',
            searchPlaceholder: 'grep class, file, message…',
            columns: [
                { key: 'exception', label: 'Exception', kind: 'primary' },
                { key: 'status', label: 'Status', kind: 'tone' },
                { key: 'lastSeen', label: 'Last seen', kind: 'code' },
            ],
            rows: [
                { id: 'exc-unset', href: { name: 'screen', params: { screenKey: 'exceptions', detailId: 'exc-unset' } }, exception: cell('Call to undefined function App\\GraphQL\\Mutations\\unset()', { meta: 'app/GraphQL/Mutations/Authorisation.php:18' }), status: cell('unhandled', { tone: 'red' }), lastSeen: cell('11:23:40 +07') },
            ],
        },
        details: {
            'exc-unset': sharedExceptionDetail,
        },
    },
    queries: {
        kind: 'collection',
        eyebrow: 'Database activity',
        title: 'Queries',
        subtitle: 'Grouped SQL performance aligned with nw_query_group_1m and individual samples from nw_queries.',
        metrics: [
            { label: 'CALLS', caption: '1.6K total calls', value: '1.6K', meta: meta(['avg 13.25ms', 'blue'], ['p95 77.53ms', 'yellow']), bars: bars([8, 'blue'], [22, 'blue'], [54, 'red'], [16, 'blue'], [32, 'yellow'], [70, 'blue']) },
            { label: 'DURATION', caption: '370μs - 415.64ms', value: '13.25ms', meta: meta(['AVG', 'blue']), bars: bars([18, 'yellow'], [34, 'blue'], [86, 'red']) },
        ],
        table: {
            title: '112 Queries',
            caption: '',
            searchPlaceholder: 'grep sql, table, file…',
            columns: [
                { key: 'query', label: 'Query', kind: 'primary' },
                { key: 'connection', label: 'Connection' },
                { key: 'calls', label: 'Calls' },
                { key: 'avg', label: 'Avg', kind: 'code' },
            ],
            rows: [
                { id: 'query-activity-log', href: { name: 'screen', params: { screenKey: 'queries', detailId: 'query-activity-log' } }, query: cell('insert into activity_logs (...)', { meta: 'App\\Models\\ActivityLog' }), connection: cell('pgsql / write'), calls: cell('6'), avg: cell('5.48ms') },
                { id: 'query-select-users', query: cell('select * from users where id = ?', { meta: 'App\\Models\\User' }), connection: cell('pgsql / read'), calls: cell('120'), avg: cell('1.70ms') },
                { id: 'query-update-jobs', query: cell('update jobs set reserved_at = ?', { meta: 'queue worker' }), connection: cell('pgsql / write'), calls: cell('11'), avg: cell('80.9ms') },
            ],
        },
        details: {
            'query-activity-log': {
                eyebrow: 'Query detail',
                title: 'insert into activity_logs (`user_id`, `type`, `data`, `updated_at`, `created_at`) values (?, ?, ?, ?, ?)',
                subtitle: 'Future detail endpoint can blend one grouped query with example samples and related request contexts.',
                backTo: backTo('queries'),
                tags: [{ text: 'write', tone: 'yellow' }, { text: 'pgsql', tone: 'blue' }],
                metrics: [
                    { label: 'CALLS', caption: '6 calls', value: '6', meta: meta(['total 32.89ms', 'blue']), bars: bars([8, 'blue'], [24, 'blue'], [56, 'yellow'], [22, 'blue']) },
                    { label: 'DURATION', caption: '570μs - 26.27ms', value: '5.48ms', meta: meta(['AVG', 'blue'], ['P95 26.27ms', 'yellow']), bars: bars([12, 'blue'], [38, 'yellow'], [74, 'yellow']) },
                ],
                summaryPanels: [
                    {
                        title: 'Info',
                        caption: '',
                        entries: [
                            info('File', 'app/Models/ActivityLog.php'),
                            info('Connection', 'pgsql'),
                            info('Type', 'write'),
                            info('Total time', '32.89ms'),
                            info('P95', '26.27ms'),
                        ],
                    },
                ],
                codePanels: [
                    {
                        title: 'Normalized SQL',
                        code: `insert into activity_logs (\n  user_id,\n  type,\n  data,\n  updated_at,\n  created_at\n) values (?, ?, ?, ?, ?)`,
                    },
                ],
                tables: [
                    {
                        title: 'Sample Calls',
                        caption: '',
                        searchPlaceholder: 'grep request id…',
                        columns: [
                            { key: 'call', label: 'Call', kind: 'primary' },
                            { key: 'duration', label: 'Duration', kind: 'code' },
                            { key: 'connection', label: 'Connection' },
                        ],
                        rows: [
                            { id: 'query-call-1', call: cell('11:23:37 +07', { meta: 'POST /graphql · req_01JTD6Y2N2K4R2' }), duration: cell('4.65ms'), connection: cell('pgsql / write') },
                            { id: 'query-call-2', call: cell('11:23:36 +07', { meta: 'POST /graphql · req_01JTD6Y1S9QAMZ' }), duration: cell('5.11ms'), connection: cell('pgsql / write') },
                        ],
                    },
                ],
            },
        },
    },
    notifications: {
        kind: 'collection',
        eyebrow: 'Notification events',
        title: 'Notifications',
        subtitle: 'A forward-looking screen for nw_notification_events, following the same list/detail grammar.',
        metrics: [
            { label: 'EVENTS', caption: '3 sent', value: '3', meta: meta(['failed 0', 'green']), bars: bars([12, 'green'], [28, 'green'], [42, 'green']) },
        ],
        table: {
            title: 'Recent notifications',
            caption: '',
            searchPlaceholder: 'grep class, channel…',
            columns: [
                { key: 'notification', label: 'Notification', kind: 'primary' },
                { key: 'channel', label: 'Channel', kind: 'tone' },
                { key: 'duration', label: 'Duration', kind: 'code' },
            ],
            rows: [
                { id: 'notif-order-shipped', href: { name: 'screen', params: { screenKey: 'notifications', detailId: 'notif-order-shipped' } }, notification: cell('App\\Notifications\\PostViewed', { meta: 'POST /graphql' }), channel: cell('database', { tone: 'sky' }), duration: cell('7.91ms') },
                { id: 'notif-digest', notification: cell('App\\Notifications\\DailyDigestReady', { meta: 'schedule' }), channel: cell('mail', { tone: 'yellow' }), duration: cell('14.42ms') },
            ],
        },
        details: {
            'notif-order-shipped': {
                eyebrow: 'Notification detail',
                title: 'App\\Notifications\\PostViewed',
                subtitle: 'A clean placeholder detail grounded in nw_notification_events columns.',
                backTo: backTo('notifications'),
                tags: [{ text: 'database', tone: 'sky' }, { text: 'successful', tone: 'green' }],
                summaryPanels: [
                    { title: 'Info', caption: '', entries: [info('Channel', 'database'), info('Class', 'App\\Notifications\\PostViewed'), info('Duration', '7.91ms'), info('Failed', 'No')] },
                ],
            },
        },
    },
    mail: {
        kind: 'collection',
        eyebrow: 'Mail events',
        title: 'Mail',
        subtitle: 'Prepared for nw_mail_events with the same collection/detail layout as other event types.',
        metrics: [
            { label: 'MAIL', caption: '1 sent', value: '1', meta: meta(['failed 0', 'green']), bars: bars([22, 'green']) },
        ],
        table: {
            title: 'Recent mail',
            caption: '',
            searchPlaceholder: 'grep subject, mailable…',
            columns: [
                { key: 'mail', label: 'Mail', kind: 'primary' },
                { key: 'recipients', label: 'Recipients' },
                { key: 'duration', label: 'Duration', kind: 'code' },
            ],
            rows: [
                { id: 'mail-weekly-digest', href: { name: 'screen', params: { screenKey: 'mail', detailId: 'mail-weekly-digest' } }, mail: cell('Weekly digest', { meta: 'App\\Mail\\WeeklyDigestMail' }), recipients: cell('3'), duration: cell('18.55ms') },
            ],
        },
        details: {
            'mail-weekly-digest': {
                eyebrow: 'Mail detail',
                title: 'Weekly digest',
                subtitle: 'Simple mail detail shape aligned with nw_mail_events.',
                backTo: backTo('mail'),
                tags: [{ text: 'smtp', tone: 'yellow' }, { text: 'sent', tone: 'green' }],
                summaryPanels: [
                    { title: 'Info', caption: '', entries: [info('Class', 'App\\Mail\\WeeklyDigestMail'), info('Mailer', 'smtp'), info('To count', '3'), info('Attachments', '0'), info('Duration', '18.55ms')] },
                ],
            },
        },
    },
    cache: {
        kind: 'collection',
        eyebrow: 'Cache events',
        title: 'Cache',
        subtitle: 'Keys and cache event rollups shaped from nw_cache_events.',
        metrics: [
            { label: 'EVENTS', caption: '94 total', value: '94', meta: meta(['writes 66', 'blue'], ['misses 14', 'yellow'], ['failures 0', 'green']), bars: bars([4, 'yellow'], [18, 'yellow'], [78, 'blue'], [52, 'blue']) },
        ],
        table: {
            title: '21 Keys',
            caption: '',
            searchPlaceholder: 'grep cache key, store…',
            columns: [
                { key: 'key', label: 'Key', kind: 'primary' },
                { key: 'store', label: 'Store' },
                { key: 'events', label: 'Events' },
                { key: 'type', label: 'Last type', kind: 'tone' },
            ],
            rows: [
                { id: 'cache-lightyear', href: { name: 'screen', params: { screenKey: 'cache', detailId: 'cache-lightyear' } }, key: cell('lightyear:cache:session:9f0b', { meta: 'ttl 60s' }), store: cell('redis'), events: cell('16'), type: cell('write', { tone: 'blue' }) },
                { id: 'cache-homepage', key: cell('homepage:posts:index', { meta: 'ttl 300s' }), store: cell('redis'), events: cell('8'), type: cell('miss', { tone: 'yellow' }) },
            ],
        },
        details: {
            'cache-lightyear': {
                eyebrow: 'Cache detail',
                title: 'lightyear:cache:session:9f0b',
                subtitle: 'Detail model ready for `/api/cache/{key}`.',
                backTo: backTo('cache'),
                tags: [{ text: 'redis', tone: 'sky' }, { text: 'write', tone: 'blue' }],
                summaryPanels: [
                    { title: 'Info', caption: '', entries: [info('Store', 'redis'), info('TTL', '60s'), info('Events', '16'), info('Failures', '0')] },
                ],
                tables: [
                    {
                        title: 'Recent Events',
                        caption: '',
                        searchPlaceholder: 'grep event type…',
                        columns: [
                            { key: 'event', label: 'Event', kind: 'primary' },
                            { key: 'type', label: 'Type', kind: 'tone' },
                            { key: 'duration', label: 'Duration', kind: 'code' },
                        ],
                        rows: [
                            { id: 'cache-1', event: cell('11:24:12 +07', { meta: 'POST /graphql' }), type: cell('write', { tone: 'blue' }), duration: cell('0.91ms') },
                            { id: 'cache-2', event: cell('11:23:41 +07', { meta: 'POST /graphql' }), type: cell('miss', { tone: 'yellow' }), duration: cell('0.31ms') },
                        ],
                    },
                ],
            },
        },
    },
    'outgoing-requests': {
        kind: 'collection',
        eyebrow: 'External traffic',
        title: 'Outgoing Requests',
        subtitle: 'Host-level visibility from nw_outgoing_host_1m with per-call samples from nw_outgoing_requests.',
        metrics: [
            { label: 'REQUESTS', caption: '1', value: '1', meta: meta(['4xx 0', 'green'], ['5xx 0', 'green']), bars: bars([86, 'blue']) },
            { label: 'DURATION', caption: '542.48ms', value: '542.48ms', meta: meta(['AVG', 'blue'], ['P95 542.48ms', 'yellow']), bars: bars([72, 'yellow']) },
        ],
        table: {
            title: '1 Domain',
            caption: '',
            searchPlaceholder: 'grep host, method…',
            columns: [
                { key: 'domain', label: 'Domain', kind: 'primary' },
                { key: 'requests', label: 'Requests' },
                { key: 'avg', label: 'Avg', kind: 'code' },
                { key: 'status', label: 'Last status', kind: 'tone' },
            ],
            rows: [
                { id: 'outgoing-discord', href: { name: 'screen', params: { screenKey: 'outgoing-requests', detailId: 'outgoing-discord' } }, domain: cell('discord-webhook-proxy.bearstack.vn', { meta: 'GET /' }), requests: cell('1'), avg: cell('542.48ms'), status: cell('200', { tone: 'green' }) },
            ],
        },
        details: {
            'outgoing-discord': {
                eyebrow: 'Outgoing request detail',
                title: 'discord-webhook-proxy.bearstack.vn',
                subtitle: 'Host-centric view using nw_outgoing_requests plus related request context.',
                backTo: backTo('outgoing-requests'),
                tags: [{ text: 'GET', tone: 'sky' }, { text: '200', tone: 'green' }],
                metrics: [
                    { label: 'REQUESTS', caption: '1 total', value: '1', meta: meta(['latency 542.48ms', 'yellow']), bars: bars([88, 'yellow']) },
                ],
                summaryPanels: [
                    {
                        title: 'Info',
                        caption: '',
                        entries: [
                            info('URL', 'https://discord-webhook-proxy.bearstack.vn'),
                            info('Method', 'GET'),
                            info('Status code', '200'),
                            info('Request bytes', '64 B'),
                            info('Response bytes', '542 B'),
                        ],
                    },
                ],
                tables: [
                    {
                        title: 'Related Calls',
                        caption: '',
                        searchPlaceholder: 'grep execution id…',
                        columns: [
                            { key: 'call', label: 'Call', kind: 'primary' },
                            { key: 'status', label: 'Status', kind: 'tone' },
                            { key: 'duration', label: 'Duration', kind: 'code' },
                        ],
                        rows: [
                            { id: 'outgoing-1', call: cell('May 20, 11:23:37 +07', { meta: 'POST /graphql' }), status: cell('200', { tone: 'green' }), duration: cell('542.48ms') },
                        ],
                    },
                ],
            },
        },
    },
    users: {
        kind: 'collection',
        eyebrow: 'Users',
        title: 'Users',
        subtitle: 'User dimension list from nw_users enriched with activity summaries from executions and routes.',
        metrics: [
            { label: 'AUTHENTICATED USERS', caption: '2 distinct', value: '2', meta: meta(['requests 158', 'blue'], ['exceptions 1', 'red']), bars: bars([24, 'green'], [42, 'green'], [64, 'green']) },
            { label: 'REQUESTS', caption: '73 active / 85 idle', value: '158', meta: meta(['active', 'green'], ['idle', 'yellow']), bars: bars([32, 'green'], [18, 'yellow'], [76, 'green']) },
        ],
        table: {
            title: '2 Users',
            caption: '',
            searchPlaceholder: 'grep id, name, username…',
            columns: [
                { key: 'user', label: 'User', kind: 'primary' },
                { key: 'requests', label: 'Requests' },
                { key: 'avg', label: 'Avg', kind: 'code' },
                { key: 'exceptions', label: 'Exceptions', kind: 'tone' },
            ],
            rows: [
                { id: 'user-cgbs12', href: { name: 'screen', params: { screenKey: 'users', detailId: 'user-cgbs12' } }, user: cell('CGBS12', { meta: 'CGBS12@gmail.com' }), requests: cell('72'), avg: cell('85ms'), exceptions: cell('0', { tone: 'green' }) },
                { id: 'user-mike', user: cell('mike', { meta: 'mike@app.dev' }), requests: cell('86'), avg: cell('112ms'), exceptions: cell('1', { tone: 'red' }) },
            ],
        },
        details: {
            'user-cgbs12': {
                eyebrow: 'User detail',
                title: 'CGBS12',
                subtitle: 'User-centric slice over nw_users, nw_request_details, and exception activity.',
                backTo: backTo('users'),
                tags: [{ text: '72 requests', tone: 'green' }, { text: '0 exceptions', tone: 'sky' }],
                metrics: [
                    { label: 'REQUESTS', caption: 'last hour', value: '72', meta: meta(['5xx 0', 'green']), bars: bars([18, 'green'], [42, 'green'], [84, 'green']) },
                    { label: 'DURATION', caption: 'request avg', value: '85ms', meta: meta(['p95 188ms', 'yellow']), bars: bars([18, 'blue'], [36, 'yellow'], [62, 'yellow']) },
                ],
                summaryPanels: [
                    {
                        title: 'Info',
                        caption: '',
                        entries: [
                            info('Name', 'CGBS12'),
                            info('External id', '661'),
                            info('Username', 'CGBS12'),
                            info('Last seen', 'May 20, 2026, 11:24:07 +07:00'),
                            info('First seen', 'May 20, 2026, 11:17:20 +07:00'),
                        ],
                    },
                ],
                tables: [
                    {
                        title: 'Top Routes',
                        caption: '',
                        searchPlaceholder: 'grep route…',
                        columns: [
                            { key: 'route', label: 'Route', kind: 'primary' },
                            { key: 'requests', label: 'Requests' },
                            { key: 'avg', label: 'Avg', kind: 'code' },
                        ],
                        rows: [
                            { id: 'user-route-1', route: cell('POST /graphql', { meta: 'nightwatch.graphql' }), requests: cell('72'), avg: cell('85ms') },
                        ],
                    },
                    {
                        title: 'Recent Requests',
                        caption: '',
                        searchPlaceholder: 'grep execution id…',
                        columns: [
                            { key: 'request', label: 'Request', kind: 'primary' },
                            { key: 'status', label: 'Status', kind: 'tone' },
                            { key: 'duration', label: 'Duration', kind: 'code' },
                        ],
                        rows: [
                            { id: 'user-req-1', request: cell('POST /graphql', { meta: '11:24:07 +07' }), status: cell('200', { tone: 'green' }), duration: cell('92ms') },
                            { id: 'user-req-2', request: cell('POST /graphql', { meta: '11:23:37 +07' }), status: cell('200', { tone: 'green' }), duration: cell('88ms') },
                        ],
                    },
                ],
            },
        },
    },
    logs: {
        kind: 'collection',
        eyebrow: 'Logs',
        title: 'Logs',
        subtitle: 'Log events from nw_logs. The UI is intentionally ready for a list + drawer interaction later.',
        metrics: [
            { label: 'LOGS', caption: '1 recent error', value: '1', meta: meta(['level error', 'red']), bars: bars([88, 'red']) },
        ],
        table: {
            title: 'Recent logs',
            caption: '',
            searchPlaceholder: 'grep level, message…',
            columns: [
                { key: 'log', label: 'Log', kind: 'primary' },
                { key: 'level', label: 'Level', kind: 'tone' },
                { key: 'source', label: 'Source' },
            ],
            rows: [
                { id: 'log-graphql-error', href: { name: 'screen', params: { screenKey: 'logs', detailId: 'log-graphql-error' } }, log: cell('Call to undefined function App\\GraphQL\\Mutations\\unset()', { meta: 'POST /graphql · 11:23:40 +07' }), level: cell('error', { tone: 'red' }), source: cell('request') },
            ],
        },
        details: {
            'log-graphql-error': {
                eyebrow: 'Log detail',
                title: 'Logs',
                subtitle: 'This route uses the same data model as a future right-side drawer, but kept as a dedicated screen for simplicity.',
                backTo: backTo('logs'),
                tags: [{ text: 'error', tone: 'red' }, { text: 'request', tone: 'blue' }],
                summaryPanels: [
                    {
                        title: 'Source',
                        caption: '',
                        entries: [
                            info('Occurred at', 'May 20, 2026, 11:23:40 +07:00'),
                            info('Execution id', 'req_01JTD6Y2N2K4R2'),
                            info('Route', 'POST /graphql'),
                            info('Level', 'error'),
                        ],
                    },
                ],
                codePanels: [
                    {
                        title: 'Log Context',
                        code: `{\n  "exception": {\n    "message": "Call to undefined function App\\\\GraphQL\\\\Mutations\\\\unset()",\n    "file": "app/GraphQL/Mutations/Authorisation.php",\n    "line": 18\n  },\n  "request": {\n    "method": "POST",\n    "route": "/graphql",\n    "user": "CGBS12"\n  }\n}`,
                    },
                ],
            },
        },
    },
    settings: {
        kind: 'placeholder',
        icon: '[=]',
        eyebrow: 'Project settings',
        title: 'Settings',
        subtitle: 'This prototype leaves room for project thresholds, retention, key rotation, and self-test controls.',
        empty: {
            title: 'Settings shell is ready',
            copy: 'When API endpoints are available, this screen can host project configuration from `nw_projects`, ingest token management, and `config/overwatch.php`-derived controls.',
        },
    },
    support: {
        kind: 'placeholder',
        icon: '[?]',
        eyebrow: 'Docs and support',
        title: 'Support',
        subtitle: 'Useful links, diagnostics, and operator notes can live here later.',
        empty: {
            title: 'Support view reserved',
            copy: 'Good next additions here would be listener health, schema docs for the mock API, and shortcuts to self-test / rollup / cleanup commands.',
        },
    },
};

export const apiBlueprint = {
    dashboard: {
        endpoint: '/api/dashboard',
        shape: ['activity.metrics[]', 'application.metrics[]', 'users.tables[]'],
    },
    requests: {
        listEndpoint: '/api/requests',
        detailEndpoint: '/api/requests/{executionId}',
        detailToken: '{executionId}',
        tables: ['nw_request_route_1m', 'nw_request_details', 'nw_executions'],
    },
    exceptions: {
        listEndpoint: '/api/exceptions',
        detailEndpoint: '/api/exceptions/{groupHash}',
        detailToken: '{groupHash}',
        tables: ['nw_exceptions', 'nw_executions'],
    },
    jobs: {
        listEndpoint: '/api/jobs',
        detailEndpoint: '/api/jobs/{jobId}',
        detailToken: '{jobId}',
        tables: ['nw_jobs', 'nw_queued_jobs', 'nw_job_attempt_details'],
    },
    commands: {
        listEndpoint: '/api/commands',
        detailEndpoint: '/api/commands/{groupHash}',
        detailToken: '{groupHash}',
        tables: ['nw_command_1m', 'nw_command_details', 'nw_executions'],
    },
    'scheduled-tasks': {
        listEndpoint: '/api/scheduled-tasks',
        detailEndpoint: '/api/scheduled-tasks/{groupHash}',
        detailToken: '{groupHash}',
        tables: ['nw_schedule_1m', 'nw_scheduled_task_details', 'nw_executions'],
    },
    queries: {
        listEndpoint: '/api/queries',
        detailEndpoint: '/api/queries/{groupHash}',
        detailToken: '{groupHash}',
        tables: ['nw_query_group_1m', 'nw_queries'],
    },
};

export const getScreen = (screenKey) => screens[screenKey] ?? null;
export const getDetail = (screenKey, detailId) => screens[screenKey]?.details?.[detailId] ?? null;
