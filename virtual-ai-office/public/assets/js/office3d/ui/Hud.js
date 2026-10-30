import { STATUS_COLORS } from '../core/config.js';

const $ = (sel, root = document) => root.querySelector(sel);
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const initials = (name) => esc(String(name || '?').trim().slice(0, 2).toUpperCase());

/** DOM overlay: everything the user reads or clicks outside the canvas. */
export class Hud {
    constructor(actions) {
        this.actions = actions;
        this.app = $('#app');
        document.addEventListener('click', (e) => {
            const el = e.target.closest('[data-action]');
            if (!el) return;
            const fn = this.actions[el.dataset.action];
            if (fn) {
                e.preventDefault();
                fn(el.dataset);
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.actions.escape?.();
        });
        $('#login-form').addEventListener('submit', (e) => {
            e.preventDefault();
            const f = e.currentTarget;
            this.actions.submitLogin?.(f.email.value.trim(), f.password.value);
        });
    }

    // ---------------------------------------------------------------- loader / mode
    progress(pct, text) {
        $('#loader-progress').style.width = `${pct}%`;
        if (text) $('#loader-text').textContent = text;
    }

    hideLoader() {
        $('#loader').classList.add('is-done');
    }

    setMode(mode) {
        this.app.dataset.mode = mode;
        const map = { explore: 'explore', lobby: 'lobby', office: 'enter-office', room: 'enter-office', elevator: 'enter-office' };
        document.querySelectorAll('.mode-btn').forEach((b) => b.classList.toggle('is-active', b.dataset.action === map[mode]));
        $('#legend').hidden = !(mode === 'office' || mode === 'room');
        $('#office-hud').hidden = !(mode === 'office' || mode === 'room');
        this.app.classList.remove('floors-open');
    }

    breadcrumb(parts) {
        $('#breadcrumb').innerHTML = parts.map((p, i) => (i === parts.length - 1 ? `<b>${esc(p)}</b>` : esc(p))).join(' &nbsp;/&nbsp; ');
    }

    toggleFloors() {
        this.app.classList.toggle('floors-open');
    }

    // ---------------------------------------------------------------- session
    renderSession(session) {
        const el = $('#session');
        if (!session?.authenticated) {
            el.innerHTML = '<button class="btn btn--primary" data-action="open-login">Login</button>';
            return;
        }
        const t = session.tenant;
        const sub = session.user.is_super_admin ? 'Super Admin' : t ? `${t.name} · ${t.role.replace('tenant_', '')}` : 'Tanpa kantor tenant';
        el.innerHTML = `<div class="user-chip"><span class="avatar">${initials(session.user.name)}</span>
            <div>${esc(session.user.name)}<small>${esc(sub)}</small></div></div>
            <button class="btn" data-action="logout">Logout</button>`;
    }

    openLogin() {
        $('#login-modal').hidden = false;
        $('#login-error').hidden = true;
        setTimeout(() => $('#login-form').email.focus(), 50);
    }

    closeLogin() {
        $('#login-modal').hidden = true;
        $('#login-form').reset();
    }

    loginBusy(busy) {
        const btn = $('#login-form button[type="submit"]');
        btn.disabled = busy;
        btn.textContent = busy ? 'Memverifikasi…' : 'Login & naik elevator';
    }

    loginError(msg) {
        const el = $('#login-error');
        el.textContent = msg;
        el.hidden = false;
    }

    // ---------------------------------------------------------------- floors
    renderFloors(floors, { mine = null } = {}) {
        this.floorsData = floors;
        $('#floor-list').innerHTML = [...floors].reverse().map((f) => {
            const t = f.tenant;
            const label = f.number === 0 ? 'Main Lobby' : t ? t.name : 'Tersedia untuk disewa';
            const sub = f.number === 0 ? 'Resepsionis & directory' : t ? `${f.office?.number ?? ''} · ${t.industry ?? ''}` : f.office?.number ?? '';
            const color = f.number === 0 ? '#c0c8d4' : t ? t.color : '#334155';
            return `<li><button class="floor-item ${f.number === mine ? 'is-mine' : ''}" data-action="floor" data-floor="${f.number}">
                <span class="floor-no">${f.number === 0 ? 'L' : String(f.number).padStart(2, '0')}</span>
                <span class="floor-tenant">${esc(label)}<small>${esc(sub)}</small></span>
                <span class="dot" style="--c:${esc(color)}"></span></button></li>`;
        }).join('');
    }

    highlightFloor(n, cls = 'is-active') {
        document.querySelectorAll('.floor-item').forEach((b) => b.classList.toggle(cls, Number(b.dataset.floor) === n));
    }

    // ---------------------------------------------------------------- panels
    showPanel(html) {
        $('#info-content').innerHTML = html;
        $('#info-panel').hidden = false;
        $('#info-panel').scrollTop = 0;
    }

    hidePanel() {
        $('#info-panel').hidden = true;
    }

    floorPanel(f, { isMine, loggedIn }) {
        const t = f.tenant;
        if (f.number === 0) return this.lobbyPanel();
        const action = isMine
            ? '<button class="btn btn--primary btn--block" data-action="enter-office">Enter My Office</button>'
            : !t && !loggedIn ? '<button class="btn btn--block" data-action="open-login">Login untuk menyewa</button>' : '';
        this.showPanel(`
            <div class="eyebrow">Floor ${String(f.number).padStart(2, '0')}</div>
            <h3>${esc(t ? t.name : 'Available Office')}</h3>
            <div class="muted">${esc(t ? t.industry ?? '' : 'Lantai ini tersedia untuk disewa tenant baru.')}</div>
            <dl class="kv">
                <dt>Nomor kantor</dt><dd>${esc(f.office?.number ?? '—')}</dd>
                <dt>Status</dt><dd>${esc(t ? 'Occupied' : 'Available')}</dd>
                ${t ? `<dt>Aktivitas</dt><dd>${'▮'.repeat(t.activity_level)}${'▯'.repeat(3 - t.activity_level)}</dd>` : ''}
            </dl>
            ${action}
            ${!t ? `<div class="prototype-box"><b>PROTOTYPE · PHASE 2/4</b>Pemesanan lantai & pembayaran Tripay akan aktif pada Phase 4. Data lantai ini sudah tersimpan di tabel <code>floors</code> / <code>offices</code>.</div>` : ''}
        `);
    }

    lobbyPanel(directory) {
        const floors = directory?.floors ?? this.floorsData ?? [];
        const occupied = floors.filter((f) => f.tenant).length;
        const total = floors.filter((f) => f.number > 0).length;
        this.showPanel(`
            <div class="eyebrow">Ground Floor</div>
            <h3>Main Lobby</h3>
            <div class="muted">AI Receptionist menyambut tamu dan mengarahkan ke lantai tenant.</div>
            <dl class="kv">
                <dt>Lantai kantor</dt><dd>${total}</dd>
                <dt>Terisi</dt><dd>${occupied} tenant</dd>
                <dt>Tersedia</dt><dd>${total - occupied} lantai</dd>
            </dl>
            <div class="section-label">Building Directory</div>
            ${floors.filter((f) => f.tenant).reverse().map((f) => `
                <button class="room-chip" style="width:100%" data-action="floor" data-floor="${f.number}">
                    <span>${esc(f.tenant.name)}</span><small>Lantai ${f.number}</small>
                </button>`).join('') || '<div class="empty">Belum ada tenant.</div>'}
        `);
    }

    // ---------------------------------------------------------------- office
    renderOffice(office) {
        $('#office-name').textContent = office.tenant.name;
        $('#office-meta').textContent = `Lantai ${office.office.floor_number} · Suite ${office.office.number}`;
        $('#office-color').style.background = office.tenant.color;
        $('#office-color').style.color = office.tenant.color;
        const plan = $('#office-plan');
        plan.textContent = office.subscription ? `${office.subscription.plan}${office.subscription.status === 'trial' ? ' · trial' : ''}` : 'No plan';
        plan.classList.toggle('badge--warn', office.subscription?.status !== 'active');
        const s = office.stats;
        $('#office-stats').innerHTML = `
            <div class="stat"><b>${s.agents_total}</b><small>Agent AI</small></div>
            <div class="stat"><b>${(s.by_status.working || 0) + (s.by_status.online || 0)}</b><small>Aktif</small></div>
            <div class="stat"><b>${s.tasks_open}</b><small>Task open</small></div>`;
        $('#room-chips').innerHTML = office.rooms.map((r) => `
            <button class="room-chip ${r.enabled ? '' : 'is-locked'}" data-action="room" data-room="${r.id}">
                <span>${esc(r.name)}</span><small>${r.enabled ? `${r.agent_count} agent` : 'terkunci'}</small>
            </button>`).join('');
    }

    highlightRoom(id) {
        document.querySelectorAll('.room-chip[data-room]').forEach((b) => b.classList.toggle('is-active', Number(b.dataset.room) === id));
    }

    roomPanel(detail, { loading = false } = {}) {
        if (loading) {
            this.showPanel('<div class="eyebrow">Memuat ruangan…</div><h3>&nbsp;</h3>');
            return;
        }
        const r = detail.room;
        const agents = detail.agents.map((a) => this.agentCard(a)).join('') || '<div class="empty">Belum ada Agent AI di ruangan ini.</div>';
        const tasks = detail.recent_tasks.map((t) => `<div class="agent-card"><span class="bot">#</span><div><b>${esc(t.title)}</b><small>${esc(t.created_at)}</small></div>${this.pill(t.status)}</div>`).join('')
            || '<div class="empty">Belum ada task. Task Assignment tersedia pada Phase 3.</div>';
        const meeting = r.type === 'meeting_room' && r.enabled
            ? `<div class="prototype-box"><b>SIMULASI VISUAL</b>Agent berjalan ke ruang rapat lalu kembali ke meja. Koordinasi AI sungguhan (Multi-Agent Orchestration) dibangun di Phase 3.
                <br><button class="btn" data-action="simulate-meeting">Simulasikan rapat</button></div>` : '';
        this.showPanel(`
            <div class="eyebrow">${esc(r.category === 'common' ? 'Fasilitas umum' : 'Ruang operasional')}</div>
            <h3>${esc(r.name)}</h3>
            <div class="muted">${esc(r.description ?? '')}</div>
            ${r.enabled ? '' : '<div class="prototype-box"><b>RUANGAN TERKUNCI</b>Ruangan ini tidak termasuk paket langganan Anda. Upgrade paket (Phase 4) untuk membukanya.</div>'}
            ${meeting}
            <div class="section-label">Agent AI (${detail.agents.length})</div>
            ${agents}
            <div class="section-label">Task terbaru</div>
            ${tasks}
            <div class="prototype-box"><b>PHASE 3 · AI ENGINE</b>Create/Edit Agent, Task Assignment dan Execution History akan aktif di sini.
                <br><button class="btn" disabled title="Phase 3">+ Agent baru</button><button class="btn" disabled title="Phase 3">Assign task</button></div>
            <button class="btn btn--block" style="margin-top:14px" data-action="office-overview">← Kembali ke denah kantor</button>
        `);
    }

    agentPanel(a, room) {
        this.showPanel(`
            <div class="eyebrow">Agent AI</div>
            <h3>${esc(a.name)}</h3>
            <div class="muted">${esc(a.job_title)} · ${esc(a.department)}</div>
            <dl class="kv">
                <dt>Status</dt><dd>${this.pill(a.status)}</dd>
                <dt>Ruangan</dt><dd>${esc(room?.data.name ?? 'Belum ditempatkan')}</dd>
                <dt>Aktif</dt><dd>${a.is_active ? 'Ya' : 'Tidak'}</dd>
            </dl>
            ${room ? `<button class="btn btn--block" data-action="room" data-room="${room.data.id}">Buka ${esc(room.data.name)}</button>` : ''}
            <div class="prototype-box"><b>PHASE 3 · AI ENGINE</b>Konfigurasi model, system prompt, tools, knowledge base dan riwayat eksekusi agent.</div>
        `);
    }

    agentCard(a) {
        return `<div class="agent-card"><span class="bot">${initials(a.name)}</span>
            <div><b>${esc(a.name)}</b><small>${esc(a.job_title)}</small></div>${this.pill(a.status)}</div>`;
    }

    pill(status) {
        const c = STATUS_COLORS[status] || '#94a3b8';
        return `<span class="status-pill" style="--c:${c}">${esc(status)}</span>`;
    }

    // ---------------------------------------------------------------- misc
    tooltip(html, x, y) {
        const el = $('#tooltip');
        if (!html) {
            el.hidden = true;
            return;
        }
        el.innerHTML = html;
        el.hidden = false;
        el.style.left = `${x}px`;
        el.style.top = `${y}px`;
    }

    toast(msg, type = 'info', ms = 3800) {
        const el = document.createElement('div');
        el.className = `toast ${type === 'error' ? 'toast--error' : ''}`;
        el.textContent = msg;
        $('#toasts').appendChild(el);
        setTimeout(() => { el.classList.add('is-out'); setTimeout(() => el.remove(), 320); }, ms);
    }

    webglError() {
        $('#webgl-error').hidden = false;
        this.hideLoader();
    }
}

export { esc };
