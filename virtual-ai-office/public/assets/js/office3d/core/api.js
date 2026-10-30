// Thin fetch wrapper: same-origin cookies, CSRF header on writes, JSON in/out.
const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;
let csrfToken = meta('csrf-token');
const csrfHeader = meta('csrf-header') || 'X-CSRF-TOKEN';
const base = (meta('base-url') || '').replace(/\/$/, '');

export class ApiError extends Error {
    constructor(status, message, payload) {
        super(message);
        this.status = status;
        this.payload = payload;
    }
}

async function request(method, path, body) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    let payload;
    if (method !== 'GET') {
        headers[csrfHeader] = csrfToken;
        payload = new URLSearchParams(body || {});
    }
    const res = await fetch(`${base}/api/v1/${path}`, { method, headers, body: payload, credentials: 'same-origin' });
    let json = null;
    try { json = await res.json(); } catch { /* empty body */ }
    if (json?.csrf) csrfToken = json.csrf;
    if (!res.ok) {
        // CI4 failures: {messages: {error: "..."}} or validation {messages: {field: "..."}}; `error` is the numeric status.
        const m = json?.messages;
        const msg = m?.error || (m && Object.values(m)[0]) || (typeof json?.error === 'string' ? json.error : null) || `HTTP ${res.status}`;
        throw new ApiError(res.status, msg, json);
    }
    return json?.data;
}

export const api = {
    building: () => request('GET', 'building'),
    session: () => request('GET', 'session'),
    login: (email, password) => request('POST', 'session/login', { email, password }),
    logout: () => request('POST', 'session/logout'),
    office: () => request('GET', 'office'),
    room: (id) => request('GET', `office/rooms/${encodeURIComponent(id)}`),
    plans: () => request('GET', 'plans'),
    register: (data) => request('POST', 'register', data),
    billing: () => request('GET', 'office/billing'),
    members: {
        list: () => request('GET', 'office/members'),
        invite: (data) => request('POST', 'office/members', data),
        update: (id, data) => request('PATCH', `office/members/${Number(id)}`, data),
        remove: (id) => request('DELETE', `office/members/${Number(id)}`),
        reinvite: (id) => request('POST', `office/members/${Number(id)}/reinvite`),
    },
    admin: {
        inviteMember: (tid, data) => request('POST', `admin/tenants/${Number(tid)}/members`, data),
        updateMember: (tid, id, data) => request('PATCH', `admin/tenants/${Number(tid)}/members/${Number(id)}`, data),
        removeMember: (tid, id) => request('DELETE', `admin/tenants/${Number(tid)}/members/${Number(id)}`),
        reinviteMember: (tid, id) => request('POST', `admin/tenants/${Number(tid)}/members/${Number(id)}/reinvite`),
        floors: () => request('GET', 'admin/floors'),
        tenants: () => request('GET', 'admin/tenants'),
        tenant: (id) => request('GET', `admin/tenants/${Number(id)}`),
        createTenant: (data) => request('POST', 'admin/tenants', data),
        updateTenant: (id, data) => request('PATCH', `admin/tenants/${Number(id)}`, data),
        updateOffice: (id, data) => request('PATCH', `admin/offices/${Number(id)}`, data),
        assignOffice: (id, tenantId) => request('POST', `admin/offices/${Number(id)}/assign`, { tenant_id: tenantId }),
        releaseOffice: (id) => request('POST', `admin/offices/${Number(id)}/release`),
        plans: () => request('GET', 'admin/plans'),
        updatePlan: (id, data) => request('PATCH', `admin/plans/${Number(id)}`, data),
    },
};
