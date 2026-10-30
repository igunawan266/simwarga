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
                fn(el.dataset, el);
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.actions.escape?.();
        });
        // Generic forms: <form data-form="name"> -> actions['form:name'](values, form)
        document.addEventListener('submit', (e) => {
            const form = e.target.closest('form[data-form]');
            if (!form) return;
            e.preventDefault();
            this.actions[`form:${form.dataset.form}`]?.(Object.fromEntries(new FormData(form)), form);
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
        const admin = session.user.is_super_admin
            ? '<button class="btn" data-action="admin-plans">Kelola Paket</button>'
            : ['tenant_owner', 'tenant_admin'].includes(t?.role) ? `<button class="btn" data-action="billing">Tagihan${t.status === 'pending' ? ' <span class="dot-warn"></span>' : ''}</button>` : '';
        el.innerHTML = `${admin}<div class="user-chip"><span class="avatar">${initials(session.user.name)}</span>
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
            const st = f.office?.status;
            const label = f.number === 0 ? 'Main Lobby' : t ? t.name : st === 'reserved' ? 'Reserved' : st === 'maintenance' ? 'Maintenance' : 'Tersedia untuk disewa';
            const sub = f.number === 0 ? 'Resepsionis & directory' : t ? `${f.office?.number ?? ''} · ${t.industry ?? ''}` : f.office?.number ?? '';
            const color = f.number === 0 ? '#c0c8d4' : t ? t.color : st === 'reserved' ? '#fbbf24' : '#334155';
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
        $('#info-panel').classList.remove('info-panel--wide');
        $('#info-content').innerHTML = html;
        $('#info-panel').hidden = false;
        $('#info-panel').scrollTop = 0;
    }

    hidePanel() {
        $('#info-panel').hidden = true;
    }

    floorPanel(f, { isMine, minePending = false, isSuperAdmin = false }) {
        const t = f.tenant;
        if (f.number === 0) return this.lobbyPanel();
        const st = f.office?.status;
        const vacant = !t && st === 'available';
        const status = t ? 'Occupied' : st === 'reserved' ? 'Reserved' : st === 'maintenance' ? 'Maintenance' : 'Available';
        let action = '';
        if (isMine && minePending) {
            action = '<button class="btn btn--primary btn--block" data-action="billing">Lihat tagihan</button>';
        } else if (isMine) {
            action = '<button class="btn btn--primary btn--block" data-action="enter-office">Enter My Office</button>';
        } else if (vacant && !isSuperAdmin) {
            action = `<button class="btn btn--primary btn--block" data-action="rent" data-floor="${f.number}">Sewa lantai ini</button>`;
        }
        const desc = t ? t.industry ?? '' : st === 'reserved'
            ? 'Lantai ini sedang dipesan dan menunggu pembayaran.'
            : st === 'maintenance' ? 'Lantai ini sedang dalam perawatan.' : 'Lantai ini tersedia untuk disewa tenant baru.';
        this.showPanel(`
            <div class="eyebrow">Floor ${String(f.number).padStart(2, '0')}</div>
            <h3>${esc(t ? t.name : st === 'reserved' ? 'Reserved Office' : 'Available Office')}</h3>
            <div class="muted">${esc(desc)}</div>
            <dl class="kv">
                <dt>Nomor kantor</dt><dd>${esc(f.office?.number ?? '—')}</dd>
                <dt>Status</dt><dd>${esc(status)}</dd>
                ${vacant && f.office?.monthly_price ? `<dt>Biaya sewa lantai</dt><dd>${rupiah(f.office.monthly_price)}/bln</dd>` : ''}
                ${t ? `<dt>Aktivitas</dt><dd>${'●'.repeat(t.activity_level)}${'○'.repeat(3 - t.activity_level)}</dd>` : ''}
            </dl>
            ${action}
            <div id="admin-floor"></div>
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

// ---------------------------------------------------------------------- Super Admin views
const rupiah = (n) => `Rp ${Number(n || 0).toLocaleString('id-ID')}`;

/** Admin section rendered inside the floor panel. */
export function adminFloorHtml(row, tenants, detail, inviteUrl = null) {
    const o = row.office;
    if (!o) return '';
    const officeForm = `
        <form class="admin-form" data-form="office" data-office="${o.id}">
            <label>Harga sewa / bulan (Rp)<input name="monthly_price" type="number" min="0" step="1000" value="${o.monthly_price}"></label>
            ${row.tenant ? '' : `<label>Status kantor<select name="status">
                <option value="available" ${o.status === 'available' ? 'selected' : ''}>Available</option>
                <option value="maintenance" ${o.status === 'maintenance' ? 'selected' : ''}>Maintenance</option></select></label>`}
            <button class="btn" type="submit">Simpan kantor</button>
        </form>`;

    if (row.tenant && detail) {
        const s = detail.subscription;
        const enabled = detail.rooms.filter((r) => r.enabled).length;
        return `
            <div class="section-label">Super Admin · Tenant</div>
            <dl class="kv">
                <dt>Status tenant</dt><dd>${esc(detail.tenant.status)}</dd>
                <dt>Paket</dt><dd>${s ? `${esc(s.plan_name)} · ${esc(s.status)}` : 'Belum berlangganan'}</dd>
                ${s?.ends_at ? `<dt>Berakhir</dt><dd>${esc(s.ends_at)}</dd>` : ''}
                <dt>Ruangan aktif</dt><dd>${enabled} / ${detail.rooms.length}</dd>
                <dt>Agent AI</dt><dd>${detail.agents.length}</dd>
                <dt>Anggota</dt><dd>${detail.members.length}</dd>
                <dt>Sewa</dt><dd>${rupiah(o.monthly_price)}</dd>
            </dl>
            ${membersHtml(detail.members, { actorRole: 'super_admin', myUserId: null, scope: 'admin', tenantId: detail.tenant.id, inviteUrl })}
            <form class="admin-form" data-form="tenant-status" data-tenant="${detail.tenant.id}">
                <label>Ubah status tenant<select name="status">
                    ${['pending', 'active', 'suspended', 'expired'].map((st) => `<option value="${st}" ${detail.tenant.status === st ? 'selected' : ''}>${st}</option>`).join('')}
                </select></label>
                <button class="btn" type="submit">Simpan status</button>
            </form>
            ${officeForm}
            <button class="btn btn--danger btn--block" data-action="admin-release" data-office="${o.id}">Kosongkan kantor ${esc(o.number)}</button>
            <p class="muted small">Ruangan tenant di kantor ini akan dihapus; Agent AI tetap ada tanpa ruangan.</p>`;
    }

    const free = tenants.filter((t) => !t.office && t.status !== 'suspended');
    return `
        <div class="section-label">Super Admin · Sewakan kantor ${esc(o.number)}</div>
        ${o.status === 'maintenance' ? '<div class="empty">Kantor dalam maintenance. Ubah status ke Available untuk menyewakan.</div>' : `
        <form class="admin-form" data-form="assign" data-office="${o.id}">
            <label>Tenant tanpa kantor<select name="tenant_id" required>
                <option value="">— pilih tenant —</option>
                ${free.map((t) => `<option value="${t.id}">${esc(t.name)} (${esc(t.status)})</option>`).join('')}
            </select></label>
            <button class="btn btn--primary btn--block" type="submit" ${free.length ? '' : 'disabled'}>Sewakan ke tenant</button>
        </form>
        <details class="admin-details"><summary>+ Tenant baru</summary>
            <form class="admin-form" data-form="new-tenant" data-office="${o.id}">
                <label>Nama perusahaan<input name="company_name" required minlength="2" maxlength="150"></label>
                <label>Industri<input name="industry" maxlength="100"></label>
                <label>Email kontak<input name="contact_email" type="email" maxlength="191"></label>
                <label>Warna brand<input name="brand_color" type="color" value="#22d3ee"></label>
                <label>Status<select name="status"><option value="pending">pending</option><option value="active">active</option></select></label>
                <button class="btn btn--primary btn--block" type="submit">Buat &amp; sewakan</button>
            </form>
        </details>`}
        ${officeForm}`;
}

export function plansPanelHtml(plans) {
    const num = (name, value, label, step = 1) => `<label>${label}<input name="${name}" type="number" min="0" step="${step}" value="${value}"></label>`;
    return `
        <div class="eyebrow">Super Admin</div>
        <h3>Paket Langganan</h3>
        <div class="muted">Perubahan berlaku untuk pembelian & perpanjangan baru. Langganan berjalan memakai snapshot harga saat dibeli.</div>
        ${plans.map((p) => `
            <form class="admin-form plan-card" data-form="plan" data-plan="${p.id}">
                <div class="plan-card__head"><b>${esc(p.name)}</b><span class="badge">${esc(p.code)}</span></div>
                <label>Nama<input name="name" value="${esc(p.name)}" required maxlength="100"></label>
                <div class="grid-2">
                    ${num('price', p.price, 'Harga (Rp)', 1000)}
                    ${num('duration_days', p.duration_days, 'Durasi (hari)')}
                    ${num('max_agents', p.max_agents, 'Maks. Agent AI')}
                    ${num('max_operational_rooms', p.max_operational_rooms, 'Ruang operasional')}
                    ${num('max_offices', p.max_offices, 'Maks. kantor')}
                    ${num('monthly_token_quota', p.monthly_token_quota, 'Kuota token/bulan', 1000)}
                </div>
                <label>Deskripsi<textarea name="description" rows="2" maxlength="2000">${esc(p.description ?? '')}</textarea></label>
                <label class="check"><input type="checkbox" name="is_active" value="1" ${p.is_active ? 'checked' : ''}> Aktif dijual</label>
                <label class="check"><input type="checkbox" name="is_custom" value="1" ${p.is_custom ? 'checked' : ''}> Harga custom (kontrak)</label>
                <button class="btn" type="submit">Simpan ${esc(p.code)}</button>
            </form>`).join('')}
    `;
}

// ---------------------------------------------------------------------- team / members
const ROLE_LABEL = { tenant_owner: 'Owner', tenant_admin: 'Admin', tenant_staff: 'Staff' };
const STATUS_COLOR = { active: '#22d3ee', invited: '#fbbf24', disabled: '#64748b' };

/** Mirrors MemberService rules so the UI only offers actions the server will accept. */
function canManage(actorRole, myUserId, member) {
    if (actorRole === 'super_admin') return true;
    if (member.user_id === myUserId) return false;
    if (actorRole === 'tenant_owner') return true;
    return actorRole === 'tenant_admin' && member.role === 'tenant_staff';
}

function inviteRoles(actorRole) {
    if (actorRole === 'super_admin' || actorRole === 'tenant_owner') return ['tenant_owner', 'tenant_admin', 'tenant_staff'];
    return actorRole === 'tenant_admin' ? ['tenant_staff'] : [];
}

/**
 * Members list + invite form. ctx = { actorRole, myUserId, scope: 'office'|'admin', tenantId, inviteUrl }
 */
export function membersHtml(members, ctx) {
    const attrs = `data-scope="${ctx.scope}" data-tenant="${ctx.tenantId ?? ''}"`;
    const rows = members.map((m) => {
        const editable = canManage(ctx.actorRole, ctx.myUserId, m);
        const roles = inviteRoles(ctx.actorRole);
        const controls = !editable ? '' : `
            <form class="member-row__form" data-form="member" data-member="${m.id}" ${attrs}>
                <select name="role" aria-label="Peran ${esc(m.name)}">
                    ${(roles.includes(m.role) ? roles : [m.role, ...roles]).map((r) => `<option value="${r}" ${r === m.role ? 'selected' : ''}>${ROLE_LABEL[r]}</option>`).join('')}
                </select>
                ${m.status === 'invited' ? '' : `<select name="status" aria-label="Status ${esc(m.name)}">
                    <option value="active" ${m.status === 'active' ? 'selected' : ''}>Aktif</option>
                    <option value="disabled" ${m.status === 'disabled' ? 'selected' : ''}>Nonaktif</option></select>`}
                <button class="btn" type="submit">Simpan</button>
                ${m.status === 'invited' ? `<button class="btn" type="button" data-action="member-reinvite" data-member="${m.id}" ${attrs}>Tautan baru</button>` : ''}
                <button class="btn btn--danger" type="button" data-action="member-remove" data-member="${m.id}" ${attrs}>Hapus</button>
            </form>`;
        return `<div class="member-row">
            <div class="agent-card"><span class="bot">${initials(m.name)}</span>
                <div><b>${esc(m.name)}${m.user_id === ctx.myUserId ? ' (Anda)' : ''}</b><small>${esc(m.email)}</small></div>
                <span class="status-pill" style="--c:${STATUS_COLOR[m.status] || '#94a3b8'}">${esc(ROLE_LABEL[m.role] || m.role)}${m.status === 'active' ? '' : ` · ${esc(m.status)}`}</span>
            </div>${controls}</div>`;
    }).join('') || '<div class="empty">Belum ada anggota. Undang Owner pertama tenant ini.</div>';

    const roles = inviteRoles(ctx.actorRole);
    const invite = !roles.length ? '<p class="muted small">Hanya Owner dan Admin yang dapat mengundang anggota.</p>' : `
        <form class="admin-form" data-form="invite" ${attrs}>
            <div class="grid-2">
                <label>Email<input name="email" type="email" required maxlength="191" placeholder="nama@perusahaan.com"></label>
                <label>Peran<select name="role">${roles.map((r) => `<option value="${r}" ${r === 'tenant_staff' ? 'selected' : ''}>${ROLE_LABEL[r]}</option>`).join('')}</select></label>
            </div>
            <button class="btn btn--primary" type="submit">Buat undangan</button>
        </form>`;

    const link = !ctx.inviteUrl ? '' : `
        <div class="invite-link">
            <b>Tautan undangan (berlaku 7 hari, sekali pakai)</b>
            <code>${esc(ctx.inviteUrl)}</code>
            <button class="btn" data-action="copy" data-text="${esc(ctx.inviteUrl)}">Salin tautan</button>
            <small>Kirimkan tautan ini ke anggota. Tautan hanya ditampilkan sekali; buat "Tautan baru" bila hilang. Pengiriman email otomatis belum tersedia (butuh konfigurasi SMTP).</small>
        </div>`;

    return `<div class="section-label">Tim &amp; Akses (${members.length})</div>${link}${rows}${invite}`;
}

export function teamPanelHtml(data, inviteUrl) {
    return `
        <div class="eyebrow">Kantor Anda</div>
        <h3>Tim &amp; Akses</h3>
        <div class="muted">Anggota login dengan akun masing-masing dan hanya melihat data tenant ini. Owner mengelola semua peran; Admin mengelola Staff.</div>
        ${membersHtml(data.members, { actorRole: data.my_role, myUserId: data.my_user_id, scope: 'office', inviteUrl })}`;
}

// ---------------------------------------------------------------------- registration & billing
export function registerPanelHtml(floor, plans, session) {
    const guest = !session?.authenticated;
    const officePrice = floor.office?.monthly_price || 0;
    const choosable = plans.filter((p) => !p.is_custom);
    const firstCode = (choosable.find((p) => p.code === 'BUSINESS') || choosable[0])?.code;
    return `
        <div class="eyebrow">Sewa kantor · Floor ${String(floor.number).padStart(2, '0')} · Suite ${esc(floor.office?.number ?? '')}</div>
        <h3>Daftarkan perusahaan Anda</h3>
        <div class="muted">Kantor akan di-reserve untuk Anda dan invoice dibuat. Langganan aktif setelah pembayaran terverifikasi.</div>
        <form class="admin-form" data-form="register" data-floor="${floor.number}" novalidate>
            <div class="section-label">1 · Pilih paket</div>
            <div class="plan-options">
                ${plans.map((p) => `
                    <label class="plan-option ${p.is_custom ? 'is-disabled' : ''}">
                        <input type="radio" name="plan_code" value="${esc(p.code)}" data-price="${p.price}" ${p.code === firstCode ? 'checked' : ''} ${p.is_custom ? 'disabled' : ''}>
                        <span class="plan-option__body">
                            <b>${esc(p.name)}</b>
                            <span class="plan-option__price">${p.is_custom ? 'Harga kontrak' : `${rupiah(p.price)}<small>/${p.duration_days} hari</small>`}</span>
                            <small>${esc(p.description ?? '')}</small>
                            <small>${p.max_agents} Agent AI · ${p.max_operational_rooms} ruang operasional</small>
                        </span>
                    </label>`).join('')}
            </div>
            <div class="section-label">2 · Perusahaan</div>
            <label>Nama perusahaan<input name="company_name" required minlength="2" maxlength="150"></label>
            <div class="grid-2">
                <label>Industri<input name="industry" maxlength="100"></label>
                <label>Warna brand<input name="brand_color" type="color" value="#22d3ee"></label>
            </div>
            ${guest ? `
            <div class="section-label">3 · Akun Owner</div>
            <label>Nama Anda<input name="owner_name" required minlength="2" maxlength="150" autocomplete="name"></label>
            <label>Email<input name="email" type="email" required maxlength="191" autocomplete="email"></label>
            <div class="grid-2">
                <label>Password<input name="password" type="password" required minlength="10" autocomplete="new-password"></label>
                <label>Ulangi password<input name="password_confirm" type="password" required autocomplete="new-password"></label>
            </div>
            <p class="muted small">Sudah punya akun? <a href="#" data-action="open-login">Login dulu</a> agar perusahaan baru terhubung ke akun Anda.</p>`
            : `<p class="muted small">Perusahaan akan didaftarkan atas akun <b>${esc(session.user.email)}</b> sebagai Owner.</p>`}
            <div class="register-total" data-office-price="${officePrice}">
                <span>Total invoice pertama</span><b id="register-total">—</b>
            </div>
            <div class="form-error" id="register-error" hidden></div>
            <button class="btn btn--primary btn--block" type="submit">Reserve kantor &amp; buat invoice</button>
        </form>`;
}

export function billingPanelHtml(b) {
    const s = b.subscription;
    const pending = s?.status === 'pending';
    const reservedUntil = b.office?.status === 'reserved' && b.office.reserved_until;
    const statusLabel = { pending: 'Menunggu pembayaran', trial: 'Trial', active: 'Aktif', expired: 'Kedaluwarsa', cancelled: 'Dibatalkan', past_due: 'Lewat jatuh tempo' };
    return `
        <div class="eyebrow">Tagihan &amp; Langganan</div>
        <h3>${esc(s ? `Paket ${s.plan_name}` : 'Belum ada langganan')}</h3>
        <dl class="kv">
            <dt>Status langganan</dt><dd>${esc(s ? statusLabel[s.status] ?? s.status : '—')}</dd>
            <dt>Kantor</dt><dd>${b.office ? `Suite ${esc(b.office.number)} · Lantai ${b.office.floor_number}` : 'Belum ada'}</dd>
            ${reservedUntil ? `<dt>Reserved sampai</dt><dd>${esc(reservedUntil)}</dd>` : ''}
            ${s?.ends_at ? `<dt>Berakhir</dt><dd>${esc(s.ends_at)}</dd>` : ''}
        </dl>
        ${reservedUntil ? '<div class="prototype-box"><b>RESERVASI SEMENTARA</b>Bayar invoice sebelum batas waktu. Jika lewat, kantor otomatis dilepas dan invoice kedaluwarsa.</div>' : ''}
        <div class="section-label">Invoice</div>
        ${b.invoices.map((i) => `
            <div class="invoice-card">
                <div class="invoice-card__head"><b>${esc(i.invoice_number)}</b>${invoicePill(i.status)}</div>
                <small>${esc(i.description)}</small>
                ${i.line_items.map((l) => `<div class="invoice-line"><span>${esc(l.description)}</span><span>${rupiah(l.amount)}</span></div>`).join('')}
                <div class="invoice-line invoice-line--total"><span>Total</span><span>${rupiah(i.total)}</span></div>
                ${i.status === 'unpaid' ? `<small>Jatuh tempo ${esc(i.due_at)}</small>
                    <button class="btn btn--primary btn--block" disabled title="Phase 4">Bayar via Tripay</button>
                    <small class="muted">PHASE 4: pembayaran Virtual Account / QRIS / E-Wallet melalui Tripay. Tidak ada pembayaran yang disimulasikan.</small>` : ''}
            </div>`).join('') || '<div class="empty">Belum ada invoice.</div>'}
        ${!pending && b.tenant_status === 'active' ? '<button class="btn btn--block" data-action="enter-office">Masuk kantor</button>' : ''}`;
}

function invoicePill(status) {
    const c = { unpaid: '#fbbf24', paid: '#34d399', expired: '#64748b', failed: '#f87171', refunded: '#a78bfa', cancelled: '#64748b' }[status] || '#94a3b8';
    return `<span class="status-pill" style="--c:${c}">${esc(status)}</span>`;
}
