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
            this.actions[`form:${form.dataset.form}`]?.(Object.fromEntries(new FormData(form)), form, e.submitter);
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
            : ['tenant_owner', 'tenant_admin'].includes(t?.role) ? `<button class="btn" data-action="billing">Tagihan${t.status !== 'active' ? ' <span class="dot-warn"></span>' : ''}</button>` : '';
        const bell = t ? '<button class="btn btn--bell" data-action="notifications" aria-label="Notifikasi"><span class="bell"></span><span class="badge-count" id="notif-count" hidden></span></button>' : '';
        el.innerHTML = `${bell}${admin}<div class="user-chip"><span class="avatar">${initials(session.user.name)}</span>
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
            const label = f.number === 0 ? 'Main Lobby' : t ? t.name
                : st === 'reserved' ? 'Reserved' : st === 'occupied' ? 'Disewa' : st === 'maintenance' ? 'Maintenance' : 'Tersedia untuk disewa';
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
        const status = t || st === 'occupied' ? 'Occupied' : st === 'reserved' ? 'Reserved' : st === 'maintenance' ? 'Maintenance' : 'Available';
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
            : st === 'occupied' ? 'Kantor ini disewa tenant yang sedang tidak aktif.'
            : st === 'maintenance' ? 'Lantai ini sedang dalam perawatan.' : 'Lantai ini tersedia untuk disewa tenant baru.';
        this.showPanel(`
            <div class="eyebrow">Floor ${String(f.number).padStart(2, '0')}</div>
            <h3>${esc(t ? t.name : st === 'reserved' ? 'Reserved Office' : st === 'occupied' ? 'Leased Office' : 'Available Office')}</h3>
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
        // Owner/admin-only controls (the server enforces the same rule).
        const manager = ['tenant_owner', 'tenant_admin'].includes(office.tenant.role);
        document.querySelectorAll('[data-owner-only]').forEach((el) => { el.hidden = !manager; });
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

    roomPanel(detail, { loading = false, canManage = false } = {}) {
        if (loading) {
            this.showPanel('<div class="eyebrow">Memuat ruangan…</div><h3>&nbsp;</h3>');
            return;
        }
        const r = detail.room;
        const agents = detail.agents.map((a) => this.agentCard(a)).join('') || '<div class="empty">Belum ada Agent AI di ruangan ini.</div>';
        const tasks = detail.recent_tasks.map((t) => taskRow(t)).join('') || '<div class="empty">Belum ada task untuk agent di ruangan ini.</div>';
        const meeting = r.type === 'meeting_room' && r.enabled
            ? `<div class="prototype-box"><b>SIMULASI VISUAL</b>Animasi agent berjalan ke ruang rapat. Koordinasi AI sungguhan: beri tugas ke agent Manager — ia membagi pekerjaan ke timnya.
                <br><button class="btn" data-action="simulate-meeting">Simulasikan rapat</button></div>` : '';
        this.showPanel(`
            <div class="eyebrow">${esc(r.category === 'common' ? 'Fasilitas umum' : 'Ruang operasional')}</div>
            <h3>${esc(r.name)}</h3>
            <div class="muted">${esc(r.description ?? '')}</div>
            ${r.enabled ? '' : '<div class="prototype-box"><b>RUANGAN TERKUNCI</b>Ruangan ini tidak termasuk paket langganan Anda. Upgrade paket untuk membukanya.</div>'}
            ${meeting}
            <div class="section-label">Agent AI (${detail.agents.length})</div>
            ${agents}
            ${canManage && r.enabled ? `<button class="btn btn--block" data-action="agent-new" data-room="${r.id}">+ Agent baru di ruangan ini</button>` : ''}
            <div class="section-label">Task terbaru</div>
            ${tasks}
            <button class="btn btn--block" style="margin-top:14px" data-action="office-overview">← Kembali ke denah kantor</button>
        `);
    }

    agentCard(a) {
        return `<button class="agent-card agent-card--btn" data-action="agent" data-agent="${a.id}"><span class="bot">${initials(a.name)}</span>
            <div><b>${esc(a.name)}</b><small>${esc(a.job_title)}</small></div>${this.pill(a.status)}</button>`;
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

export function plansPanelHtml(plans, gateway = null) {
    const num = (name, value, label, step = 1) => `<label>${label}<input name="${name}" type="number" min="0" step="${step}" value="${value}"></label>`;
    const gw = !gateway ? '' : `
        <div class="section-label">Payment gateway · Tripay</div>
        <dl class="kv">
            <dt>Status</dt><dd>${gateway.gateway.configured ? `Terkonfigurasi · ${esc(gateway.gateway.mode)}` : 'Belum dikonfigurasi (.env)'}</dd>
            <dt>Kanal aktif</dt><dd>${gateway.channels.filter((c) => Number(c.is_active)).length} / ${gateway.channels.length}</dd>
        </dl>
        <small class="muted">Callback URL untuk dashboard Tripay:</small>
        <div class="invite-link"><code>${esc(gateway.gateway.callback_url)}</code></div>
        <button class="btn" data-action="admin-sync-channels" ${gateway.gateway.configured ? '' : 'disabled'}>Sinkronkan kanal pembayaran</button>`;
    return `
        <div class="eyebrow">Super Admin</div>
        <h3>Paket &amp; Pembayaran</h3>
        ${gw}
        <div class="section-label">Paket langganan</div>
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
    const base = document.querySelector('meta[name="base-url"]')?.content ?? '';
    const hasOpenInvoice = b.invoices.some((i) => i.status === 'unpaid');
    const canRenew = s && ['active', 'past_due', 'expired', 'trial'].includes(s.status) && !hasOpenInvoice;
    const banner = s?.status === 'past_due'
        ? '<div class="prototype-box"><b>LEWAT JATUH TEMPO</b>Kantor masih bisa dipakai selama masa tenggang. Bayar invoice perpanjangan agar akses tidak terhenti.</div>'
        : s?.status === 'expired' || b.tenant_status === 'expired'
            ? '<div class="prototype-box"><b>LANGGANAN BERAKHIR</b>Akses kantor dihentikan sementara. Data, ruangan dan Agent AI tetap tersimpan — perpanjang untuk membukanya kembali.</div>' : '';
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
        ${banner}
        ${canRenew ? '<button class="btn btn--primary btn--block" data-action="renew">Perpanjang langganan</button>' : ''}
        <div class="section-label">Invoice</div>
        ${b.invoices.map((i) => `
            <div class="invoice-card">
                <div class="invoice-card__head"><b>${esc(i.invoice_number)}</b>${invoicePill(i.status)}</div>
                <small>${esc(i.description)}</small>
                ${i.line_items.map((l) => `<div class="invoice-line"><span>${esc(l.description)}</span><span>${rupiah(l.amount)}</span></div>`).join('')}
                <div class="invoice-line invoice-line--total"><span>Total</span><span>${rupiah(i.total)}</span></div>
                ${i.status === 'unpaid' ? `<small>Jatuh tempo ${esc(i.due_at)}</small>
                    <button class="btn btn--primary btn--block" data-action="pay-invoice" data-invoice="${i.id}">Bayar via Tripay</button>
                    <small class="muted">Virtual Account, QRIS, E-Wallet dan kanal lain yang aktif di akun merchant.</small>` : ''}
                ${i.status === 'paid' ? `<small>Lunas ${esc(i.paid_at ?? '')}</small>` : ''}
                <a class="btn" href="${esc(base)}/api/v1/office/invoices/${i.id}/pdf" download>Unduh PDF</a>
            </div>`).join('') || '<div class="empty">Belum ada invoice.</div>'}
        ${!pending && b.tenant_status === 'active' ? '<button class="btn btn--block" data-action="enter-office">Masuk kantor</button>' : ''}`;
}

function invoicePill(status) {
    const c = { unpaid: '#fbbf24', paid: '#34d399', expired: '#64748b', failed: '#f87171', refunded: '#a78bfa', cancelled: '#64748b' }[status] || '#94a3b8';
    return `<span class="status-pill" style="--c:${c}">${esc(status)}</span>`;
}

// ---------------------------------------------------------------------- Tripay payment
export function channelsPanelHtml(data) {
    const groups = {};
    data.channels.forEach((c) => { (groups[c.group] ||= []).push(c); });
    return `
        <div class="eyebrow">Bayar invoice ${esc(data.invoice.number)}</div>
        <h3>Pilih metode pembayaran</h3>
        <div class="muted">Tagihan ${rupiah(data.invoice.total)} · bayar sebelum ${esc(data.invoice.due_at)}. Biaya kanal ditampilkan sebagai perkiraan; nominal final dari Tripay.</div>
        ${data.sandbox ? '<div class="prototype-box"><b>MODE SANDBOX TRIPAY</b>Transaksi uji coba. Tandai lunas melalui simulator di dashboard sandbox Tripay; callback akan mengaktifkan langganan.</div>' : ''}
        ${Object.entries(groups).map(([g, list]) => `
            <div class="section-label">${esc(g)}</div>
            ${list.map((c) => `
                <button class="channel-btn" data-action="pay-with" data-invoice="${data.invoice.id}" data-method="${esc(c.code)}">
                    ${c.icon_url ? `<img src="${esc(c.icon_url)}" alt="" loading="lazy">` : '<span class="channel-btn__icon"></span>'}
                    <span><b>${esc(c.name)}</b><small>Biaya ± ${rupiah(c.fee_estimate)}</small></span>
                    <span class="channel-btn__total">${rupiah(c.total_estimate)}</span>
                </button>`).join('')}`).join('') || '<div class="empty">Belum ada kanal pembayaran aktif. Hubungi pengelola platform.</div>'}
        <button class="btn btn--block" style="margin-top:12px" data-action="billing">← Kembali ke tagihan</button>`;
}

export function paymentPanelHtml(p, invoiceId) {
    const steps = (p.instructions || []).map((ins) => `
        <details class="admin-details"><summary>${esc(ins.title)}</summary>
            <ol class="pay-steps">${(ins.steps || []).map((s) => `<li>${esc(String(s).replace(/<[^>]*>/g, ''))}</li>`).join('')}</ol>
        </details>`).join('');
    return `
        <div class="eyebrow">Instruksi pembayaran</div>
        <h3>${esc(p.method_name)}</h3>
        <dl class="kv">
            <dt>Total dibayar</dt><dd><b>${rupiah(p.total)}</b></dd>
            <dt>Biaya kanal</dt><dd>${rupiah(p.fee_customer)}</dd>
            <dt>Referensi</dt><dd>${esc(p.reference ?? '-')}</dd>
            <dt>Berlaku sampai</dt><dd>${esc(p.expired_at)}</dd>
            <dt>Status</dt><dd id="pay-status">${esc(p.status)}</dd>
        </dl>
        ${p.pay_code ? `<div class="invite-link"><b>Kode bayar / nomor VA</b><code class="pay-code">${esc(p.pay_code)}</code>
            <button class="btn" data-action="copy" data-text="${esc(p.pay_code)}">Salin kode</button></div>` : ''}
        ${p.qr_url ? `<div class="pay-qr"><img src="${esc(p.qr_url)}" alt="QR pembayaran" width="220" height="220"></div>` : ''}
        ${p.checkout_url ? `<a class="btn btn--primary btn--block" href="${esc(p.checkout_url)}" target="_blank" rel="noopener noreferrer">Buka halaman pembayaran Tripay</a>` : ''}
        ${steps}
        ${p.is_sandbox ? '<p class="muted small">Mode sandbox: gunakan simulator pembayaran di dashboard Tripay.</p>' : ''}
        <p class="muted small">Status diperbarui otomatis setelah Tripay mengonfirmasi pembayaran. Menutup halaman ini tidak membatalkan pembayaran.</p>
        <button class="btn btn--block" data-action="check-payment" data-invoice="${invoiceId}">Cek status pembayaran</button>
        <button class="btn btn--block" style="margin-top:8px" data-action="pay-invoice" data-invoice="${invoiceId}">Ganti metode</button>`;
}

// ---------------------------------------------------------------------- notifications
export function notificationsPanelHtml(items) {
    return `
        <div class="eyebrow">Kantor Anda</div>
        <h3>Notifikasi</h3>
        ${items.map((n) => `
            <div class="notif ${n.read ? '' : 'is-unread'}">
                <b>${esc(n.title)}</b>
                <p>${esc(n.body)}</p>
                <small>${esc(n.created_at)}</small>
                ${n.data?.invoice_id ? '<button class="btn" data-action="billing">Lihat tagihan</button>' : ''}
                ${n.data?.task_id ? `<button class="btn" data-action="task" data-task="${Number(n.data.task_id)}">Buka task</button>` : ''}
            </div>`).join('') || '<div class="empty">Belum ada notifikasi.</div>'}`;
}

export function setUnread(count) {
    const el = document.getElementById('notif-count');
    if (!el) return;
    el.textContent = count > 9 ? '9+' : String(count);
    el.hidden = count === 0;
}

// ---------------------------------------------------------------------- AI agents & tasks
const TASK_COLORS = { queued: '#94a3b8', running: '#38bdf8', waiting: '#fbbf24', waiting_approval: '#f59e0b', completed: '#34d399', failed: '#f87171', cancelled: '#64748b' };
const TASK_LABEL = { queued: 'antre', running: 'dikerjakan', waiting: 'menunggu tim', waiting_approval: 'menunggu persetujuan', completed: 'selesai', failed: 'gagal', cancelled: 'dibatalkan' };
export const TASK_OPEN = ['queued', 'running', 'waiting', 'waiting_approval'];
const taskPill = (s) => `<span class="status-pill" style="--c:${TASK_COLORS[s] || '#94a3b8'}">${esc(TASK_LABEL[s] || s)}</span>`;
const fmtTokens = (n) => Number(n || 0).toLocaleString('id-ID');

export function taskRow(t) {
    return `<button class="agent-card agent-card--btn" data-action="task" data-task="${t.id}"><span class="bot">#</span>
        <div><b>${esc(t.title)}</b><small>${esc([t.agent_name, t.created_at].filter(Boolean).join(' · '))}</small></div>${taskPill(t.status)}</button>`;
}

export function agentPanelHtml(a, tasks, canManage) {
    return `
        <div class="eyebrow">Agent AI · ${esc(a.department)}</div>
        <h3>${esc(a.name)}</h3>
        <div class="muted">${esc(a.job_title)}${a.job_description ? ` — ${esc(a.job_description)}` : ''}</div>
        <dl class="kv">
            <dt>Status</dt><dd><span class="status-pill" style="--c:${STATUS_COLORS[a.status] || '#94a3b8'}">${esc(a.status)}</span></dd>
            <dt>Aktif</dt><dd>${a.is_active ? 'Ya' : 'Tidak'}</dd>
            <dt>Ruangan</dt><dd>${esc(a.room_name ?? 'Belum ditempatkan')}</dd>
            <dt>Model</dt><dd>${esc(a.model?.name ?? '-')}${a.effort ? ` · effort ${esc(a.effort)}` : ''}</dd>
            <dt>Knowledge base</dt><dd>${a.knowledge_base_id ? 'Terhubung' : 'Tidak ada'}</dd>
            <dt>Token bulan ini</dt><dd>${fmtTokens(a.tokens_used_month)}${a.token_limit_monthly ? ` / ${fmtTokens(a.token_limit_monthly)}` : ''}</dd>
            ${a.team?.length ? `<dt>Tim</dt><dd>${a.team.map((m) => esc(m.name)).join(', ')}</dd>` : ''}
        </dl>
        ${canManage ? `<div class="btn-row">
            <button class="btn" data-action="agent-edit" data-agent="${a.id}">Edit</button>
            <button class="btn" data-action="${a.is_active ? 'agent-deactivate' : 'agent-activate'}" data-agent="${a.id}">${a.is_active ? 'Nonaktifkan' : 'Aktifkan'}</button>
            <button class="btn btn--danger" data-action="agent-delete" data-agent="${a.id}">Hapus</button></div>` : ''}
        <div class="section-label">Beri tugas</div>
        ${a.is_active ? `<form class="admin-form" data-form="assign-task" data-agent="${a.id}">
            <label>Judul<input name="title" required minlength="3" maxlength="200" placeholder="mis. Rencana konten bulan depan"></label>
            <label>Instruksi<textarea name="instruction" rows="4" required minlength="10" maxlength="20000" placeholder="Jelaskan tujuan, konteks, dan format hasil yang diharapkan."></textarea></label>
            <label>Prioritas<select name="priority"><option value="normal">Normal</option><option value="high">Tinggi</option><option value="urgent">Mendesak</option><option value="low">Rendah</option></select></label>
            ${a.team?.length ? '<p class="muted small">Agent ini memimpin tim: ia dapat membagi tugas ke anggota tim lalu menyusun laporan akhir.</p>' : ''}
            <button class="btn btn--primary" type="submit">Kirim tugas</button></form>`
            : '<div class="empty">Aktifkan agent untuk memberi tugas.</div>'}
        <div class="section-label">Riwayat task</div>
        ${tasks.map(taskRow).join('') || '<div class="empty">Belum ada task.</div>'}`;
}

export function agentFormHtml(agent, { rooms, models, agents, roomId, knowledge = [], tools = [] }) {
    const v = agent ?? {};
    const room = agent ? agent.room_id : roomId;
    const opt = (value, label, selected) => `<option value="${esc(value)}" ${selected ? 'selected' : ''}>${esc(label)}</option>`;
    return `
        <div class="eyebrow">${agent ? 'Edit agent' : 'Agent baru'}</div>
        <h3>${agent ? esc(agent.name) : 'Rekrut Agent AI'}</h3>
        <form class="admin-form" data-form="agent" data-agent="${agent?.id ?? ''}">
            <div class="grid-2">
                <label>Nama<input name="name" required minlength="2" maxlength="100" value="${esc(v.name ?? '')}"></label>
                <label>Jabatan<input name="job_title" required minlength="2" maxlength="100" value="${esc(v.job_title ?? '')}" placeholder="AI Marketing"></label>
                <label>Departemen<input name="department" required maxlength="50" value="${esc(v.department ?? '')}"></label>
                <label>Ruangan<select name="room_id">${opt('', '— belum ditempatkan —', !room)}${rooms.filter((r) => r.enabled).map((r) => opt(r.id, r.name, r.id === room)).join('')}</select></label>
                <label>Model AI<select name="ai_model_id">${models.map((m) => opt(m.id, m.display_name, Number(m.id) === v.model?.id)).join('')}</select></label>
                <label>Effort<select name="effort">${['low', 'medium', 'high', 'xhigh', 'max'].map((e) => opt(e, e, e === (v.effort ?? 'medium'))).join('')}</select></label>
                <label>Knowledge base<select name="knowledge_base_id">${opt('', '— tidak ada —', !v.knowledge_base_id)}${(knowledge || []).filter((k) => k.status === 'ready').map((k) => opt(k.id, k.name, k.id === v.knowledge_base_id)).join('')}</select></label>
                <label>Manager<select name="manager_agent_id">${opt('', '— tidak ada —', !v.manager_agent_id)}${agents.filter((a) => a.id !== v.id).map((a) => opt(a.id, `${a.name} (${a.job_title})`, a.id === v.manager_agent_id)).join('')}</select></label>
                <label>Batas token/bulan (0 = paket)<input name="token_limit_monthly" type="number" min="0" step="1000" value="${Number(v.token_limit_monthly ?? 0)}"></label>
            </div>
            <label>Deskripsi pekerjaan<textarea name="job_description" rows="2" maxlength="2000">${esc(v.job_description ?? '')}</textarea></label>
            <label>System prompt<textarea name="system_prompt" rows="6" maxlength="20000" placeholder="Peran, tanggung jawab, gaya bahasa, batasan.">${esc(v.system_prompt ?? '')}</textarea></label>
            ${tools.length ? `<fieldset class="tool-picks" data-tool-picks><legend>Tools yang boleh dipakai</legend>
                ${tools.map((t) => `<label class="check"><input type="checkbox" value="${t.id}" ${(v.tool_ids || []).includes(t.id) ? 'checked' : ''} ${t.is_active ? '' : 'disabled'}>
                    ${esc(t.name)} <small>${esc(t.method)} · ${t.approval_mode === 'auto' ? 'otomatis' : 'perlu persetujuan'}${t.is_active ? '' : ' · nonaktif'}</small></label>`).join('')}
            </fieldset>` : ''}
            <p class="muted small">Agent baru dibuat dalam keadaan nonaktif. Effort lebih tinggi = jawaban lebih mendalam, token lebih banyak.</p>
            <div class="form-error" hidden></div>
            <button class="btn btn--primary" type="submit">${agent ? 'Simpan perubahan' : 'Buat agent'}</button>
        </form>`;
}

export function taskPanelHtml(d) {
    const t = d.task;
    const logs = d.logs.map((l) => `<li class="log log--${esc(l.level)}"><b>${esc(l.event)}</b> ${esc(l.message)}
        ${Number(l.input_tokens) + Number(l.output_tokens) ? `<small>${fmtTokens(Number(l.input_tokens) + Number(l.output_tokens))} token · ${Number(l.duration_ms)} ms</small>` : ''}<small>${esc(l.created_at)}</small></li>`).join('');
    return `
        <div class="eyebrow">Task #${t.id}${t.parent_task_id ? ` · sub-tugas dari #${t.parent_task_id}` : ''}</div>
        <h3>${esc(t.title)}</h3>
        <dl class="kv">
            <dt>Agent</dt><dd>${esc(t.agent_name ?? '-')}</dd>
            <dt>Status</dt><dd id="task-status">${taskPill(t.status)}</dd>
            <dt>Prioritas</dt><dd>${esc(t.priority)}</dd>
            <dt>Token</dt><dd>${fmtTokens(Number(t.input_tokens) + Number(t.output_tokens))}</dd>
            ${t.completed_at ? `<dt>Selesai</dt><dd>${esc(t.completed_at)}</dd>` : ''}
        </dl>
        ${t.parent_task_id ? `<button class="btn" data-action="task" data-task="${t.parent_task_id}">← Task induk</button>` : ''}
        <div class="section-label">Instruksi</div>
        <div class="task-text">${esc(t.instruction)}</div>
        ${t.status === 'completed' ? `<div class="section-label">Hasil</div><div class="task-text task-text--result">${esc(t.result)}</div>` : ''}
        ${t.sources?.length ? `<div class="section-label">Sumber dari knowledge base (${t.sources.length})</div>
            ${t.sources.map((s) => `<blockquote class="source"><small>${esc(s.knowledge_base)} · bagian ${Number(s.chunk)}</small>“${esc(s.quote)}”</blockquote>`).join('')}` : ''}
        ${t.error_message ? `<div class="prototype-box"><b>${t.status === 'queued' ? 'AKAN DICOBA LAGI' : 'TIDAK SELESAI'}</b>${esc(t.error_message)}</div>` : ''}
        ${d.children.length ? `<div class="section-label">Didelegasikan ke tim (${d.children.length})</div>${d.children.map((c) => taskRow(c)).join('')}` : ''}
        ${t.tool_calls?.length ? `<div class="section-label">Pemanggilan tool (${t.tool_calls.length})</div>${t.tool_calls.map(toolCallHtml).join('')}` : ''}
        ${t.status === 'waiting_approval' ? '<button class="btn btn--primary btn--block" data-action="approvals">Tinjau persetujuan tool</button>' : ''}
        <div class="section-label">Riwayat eksekusi</div>
        <ol class="logs">${logs || '<li class="log">Belum ada aktivitas.</li>'}</ol>
        ${['queued', 'waiting', 'waiting_approval'].includes(t.status) ? `<button class="btn btn--danger btn--block" data-action="task-cancel" data-task="${t.id}">Batalkan task</button>` : ''}
        ${TASK_OPEN.includes(t.status) ? '<p class="muted small">Diperbarui otomatis. Task diproses oleh worker <code>agents:work</code> di server.</p>' : ''}`;
}

export function tasksPanelHtml(tasks) {
    return `
        <div class="eyebrow">Kantor Anda</div>
        <h3>Task Monitoring</h3>
        <div class="muted">Task terbaru yang diberikan kepada Agent AI (tanpa sub-tugas delegasi).</div>
        ${tasks.map(taskRow).join('') || '<div class="empty">Belum ada task. Buka agent lalu beri tugas.</div>'}`;
}

// ---------------------------------------------------------------------- knowledge base
const KB_COLORS = { ready: '#34d399', indexing: '#38bdf8', error: '#f87171', pending: '#94a3b8' };
const kbSize = (kb) => (kb.char_count ? `${Number(kb.char_count).toLocaleString('id-ID')} karakter · ${kb.chunk_count} bagian` : '');

export function knowledgePanelHtml(list) {
    return `
        <div class="eyebrow">Kantor Anda</div>
        <h3>Knowledge Base</h3>
        <div class="muted">Dokumen perusahaan (SOP, katalog, FAQ, kebijakan) yang dipakai agent sebagai sumber fakta. Hubungkan ke agent lewat form Edit agent. Hasil task menampilkan kutipan sumbernya.</div>
        ${list.map((kb) => `
            <div class="kb-card">
                <div class="kb-card__head"><b>${esc(kb.name)}</b><span class="status-pill" style="--c:${KB_COLORS[kb.status] || '#94a3b8'}">${esc(kb.status)}</span></div>
                <small>${esc(kb.original_filename ?? 'Teks')} ${kbSize(kb) ? '· ' + kbSize(kb) : ''}</small>
                ${kb.error_message ? `<small class="kb-error">${esc(kb.error_message)}</small>` : ''}
                ${kb.agents?.length ? `<small>Dipakai oleh: ${kb.agents.map(esc).join(', ')}</small>` : ''}
                <div class="btn-row">
                    <button class="btn" data-action="kb-open" data-kb="${kb.id}">Pratinjau</button>
                    <button class="btn btn--danger" data-action="kb-delete" data-kb="${kb.id}">Hapus</button>
                </div>
            </div>`).join('') || '<div class="empty">Belum ada knowledge base.</div>'}
        <div class="section-label">Tambah knowledge base</div>
        <form class="admin-form" data-form="knowledge" enctype="multipart/form-data">
            <label>Nama<input name="name" maxlength="150" placeholder="mis. SOP Customer Service"></label>
            <label>Deskripsi (opsional)<input name="description" maxlength="1000" placeholder="Isi dan kapan dokumen ini dipakai"></label>
            <label>File (.pdf, .docx, .txt, .md, .csv, .html — maks. 10 MB)<input name="file" type="file" accept=".pdf,.docx,.txt,.md,.csv,.html,.htm"></label>
            <label>…atau tempel teks<textarea name="text" rows="5" placeholder="Tempel isi dokumen di sini bila tidak mengunggah file."></textarea></label>
            <div class="form-error" hidden></div>
            <button class="btn btn--primary" type="submit">Simpan &amp; proses</button>
        </form>`;
}

export function knowledgeDetailHtml(d) {
    const kb = d.knowledge_base;
    return `
        <div class="eyebrow">Knowledge base</div>
        <h3>${esc(kb.name)}</h3>
        <div class="muted">${esc(kb.description ?? '')}</div>
        <dl class="kv">
            <dt>Status</dt><dd>${esc(kb.status)}</dd>
            <dt>Sumber</dt><dd>${esc(kb.original_filename ?? 'Teks')}</dd>
            <dt>Ukuran</dt><dd>${kbSize(kb) || '-'}</dd>
            <dt>Agent</dt><dd>${d.agents.map((a) => esc(a.name)).join(', ') || 'Belum dipakai'}</dd>
        </dl>
        <div class="section-label">Pratinjau (${d.preview.length} bagian pertama)</div>
        ${d.preview.map((c) => `<blockquote class="source"><small>bagian ${c.seq + 1}</small>${esc(c.text)}${c.text.length >= 600 ? '…' : ''}</blockquote>`).join('') || '<div class="empty">Tidak ada isi.</div>'}
        <button class="btn btn--block" data-action="knowledge">← Semua knowledge base</button>`;
}

// ---------------------------------------------------------------------- tools & approvals
const CALL_COLORS = { pending_approval: '#f59e0b', approved: '#38bdf8', rejected: '#64748b', executed: '#34d399', failed: '#f87171' };
const CALL_LABEL = { pending_approval: 'menunggu persetujuan', approved: 'disetujui', rejected: 'ditolak', executed: 'dijalankan', failed: 'gagal' };
const prettyJson = (v) => esc(JSON.stringify(v ?? {}, null, 2));

function toolCallHtml(c) {
    return `<div class="kb-card">
        <div class="kb-card__head"><b>${esc(c.tool_name)}</b><span class="status-pill" style="--c:${CALL_COLORS[c.status] || '#94a3b8'}">${esc(CALL_LABEL[c.status] || c.status)}</span></div>
        <small>Putaran ${Number(c.round)}${c.http_status ? ` · HTTP ${Number(c.http_status)}` : ''}${c.duration_ms ? ` · ${Number(c.duration_ms)} ms` : ''}</small>
        <pre class="code">${prettyJson(c.input_decoded)}</pre>
        ${c.decision_note ? `<small>Catatan: ${esc(c.decision_note)}</small>` : ''}
        ${c.result ? `<details><summary>Hasil</summary><pre class="code">${esc(c.result)}</pre></details>` : ''}
    </div>`;
}

export function toolsPanelHtml(tools, planAllows) {
    return `
        <div class="eyebrow">Kantor Anda</div>
        <h3>Tools &amp; Integrasi</h3>
        <div class="muted">Endpoint HTTP milik perusahaan Anda (CRM, katalog, status pesanan) yang boleh dipanggil agent. Kunci API disimpan terenkripsi di server dan tidak pernah dikirim ke browser atau ke model AI. Mode <b>perlu persetujuan</b> menahan task sampai owner/admin menyetujui setiap panggilan.</div>
        ${planAllows ? '' : '<div class="prototype-box"><b>PAKET</b>Integrasi API tidak termasuk dalam paket Anda. Upgrade paket untuk menambahkan tools.</div>'}
        ${tools.map((t) => `
            <div class="kb-card">
                <div class="kb-card__head"><b>${esc(t.name)}</b><span class="status-pill" style="--c:${t.is_active ? '#34d399' : '#64748b'}">${t.is_active ? 'aktif' : 'nonaktif'}</span></div>
                <small><code>${esc(t.method)} ${esc(t.url)}</code></small>
                <small>${t.approval_mode === 'auto' ? 'Berjalan otomatis' : 'Perlu persetujuan'} · ${t.params.length} parameter${t.has_secret ? ` · header rahasia ${esc(t.secret_header_name)}` : ''}</small>
                ${t.agents?.length ? `<small>Dipakai oleh: ${t.agents.map(esc).join(', ')}</small>` : '<small>Belum dipakai agent mana pun.</small>'}
                <div class="btn-row">
                    <button class="btn" data-action="tool-edit" data-tool="${t.id}">Edit &amp; uji</button>
                    <button class="btn btn--danger" data-action="tool-delete" data-tool="${t.id}">Hapus</button>
                </div>
            </div>`).join('') || '<div class="empty">Belum ada tool.</div>'}
        <div class="btn-row">
            ${planAllows ? '<button class="btn btn--primary" data-action="tool-new">Tambah tool</button>' : ''}
            <button class="btn" data-action="approvals">Persetujuan tertunda</button>
        </div>`;
}

const PARAM_TYPES = ['string', 'integer', 'number', 'boolean'];

export function toolParamRowHtml(p = {}) {
    return `<div class="param-row" data-param>
        <input data-p="name" placeholder="nama_param" pattern="[a-z][a-z0-9_]{0,63}" value="${esc(p.name ?? '')}" aria-label="Nama parameter">
        <select data-p="type" aria-label="Tipe">${PARAM_TYPES.map((t) => `<option ${t === (p.type ?? 'string') ? 'selected' : ''}>${t}</option>`).join('')}</select>
        <label class="check"><input type="checkbox" data-p="required" ${p.required ? 'checked' : ''}> wajib</label>
        <input data-p="description" placeholder="Penjelasan untuk agent" maxlength="300" value="${esc(p.description ?? '')}" aria-label="Deskripsi">
        <button type="button" class="btn btn--danger" data-action="tool-param-remove" aria-label="Hapus parameter">×</button>
    </div>`;
}

export function toolFormHtml(tool) {
    const v = tool ?? { method: 'GET', approval_mode: 'approval', is_active: true, params: [], headers: {} };
    const headers = Object.keys(v.headers || {}).length ? JSON.stringify(v.headers) : '';
    const sample = Object.fromEntries((v.params || []).map((p) => [p.name, p.type === 'string' ? '' : null]));
    return `
        <div class="eyebrow">${tool ? 'Edit tool' : 'Tool baru'}</div>
        <h3>${tool ? esc(tool.name) : 'Tambah Tool HTTP'}</h3>
        <form class="admin-form" data-form="tool" data-tool="${tool?.id ?? ''}">
            <div class="grid-2">
                <label>Nama<input name="name" required minlength="2" maxlength="100" value="${esc(v.name ?? '')}" placeholder="Cek status pesanan"></label>
                <label>Mode<select name="approval_mode">
                    <option value="approval" ${v.approval_mode === 'approval' ? 'selected' : ''}>Perlu persetujuan</option>
                    <option value="auto" ${v.approval_mode === 'auto' ? 'selected' : ''}>Otomatis (hanya untuk endpoint baca)</option></select></label>
                <label>Metode<select name="method">${['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => `<option ${m === v.method ? 'selected' : ''}>${m}</option>`).join('')}</select></label>
                <label>Status<select name="is_active"><option value="1" ${v.is_active ? 'selected' : ''}>Aktif</option><option value="0" ${v.is_active ? '' : 'selected'}>Nonaktif</option></select></label>
            </div>
            <label>URL (https; parameter path ditulis {nama})<input name="url" required maxlength="500" value="${esc(v.url ?? '')}" placeholder="https://api.perusahaan.id/orders/{order_id}"></label>
            <label>Deskripsi untuk agent<textarea name="description" rows="2" required minlength="10" maxlength="1000" placeholder="Kapan tool ini dipakai dan apa hasilnya.">${esc(v.description ?? '')}</textarea></label>
            <div class="section-label">Parameter</div>
            <div data-params>${(v.params || []).map(toolParamRowHtml).join('')}</div>
            <button type="button" class="btn" data-action="tool-param-add">+ Parameter</button>
            <p class="muted small">GET mengirim parameter sebagai query string; metode lain sebagai body JSON. Parameter yang tidak wajib boleh dikosongkan oleh agent.</p>
            <div class="grid-2">
                <label>Header rahasia (nama)<input name="secret_header_name" maxlength="64" pattern="[A-Za-z0-9\\-]{1,64}" value="${esc(v.secret_header_name ?? '')}" placeholder="X-Api-Key"></label>
                <label>Nilai header rahasia<input name="secret_header_value" type="password" autocomplete="new-password" maxlength="2000" placeholder="${v.has_secret ? '•••••• tersimpan — isi untuk mengganti' : 'disimpan terenkripsi'}"></label>
            </div>
            <label>Header tambahan (JSON, opsional)<input name="headers" value="${esc(headers)}" placeholder='{"Accept":"application/json"}'></label>
            <div class="form-error" hidden></div>
            <button class="btn btn--primary" type="submit">${tool ? 'Simpan perubahan' : 'Simpan tool'}</button>
        </form>
        ${tool ? `<div class="section-label">Uji panggilan</div>
        <form class="admin-form" data-form="tool-test" data-tool="${tool.id}">
            <label>Input (JSON)<textarea name="input" rows="3" class="mono">${esc(JSON.stringify(sample))}</textarea></label>
            <button class="btn" type="submit">Jalankan uji</button>
            <div data-test-result></div>
        </form>` : ''}
        <button class="btn btn--block" data-action="tools">← Semua tools</button>`;
}

export function toolTestResultHtml(r) {
    return `<div class="kb-card"><div class="kb-card__head"><b>HTTP ${Number(r.status)}</b><small>${Number(r.duration_ms)} ms</small></div><pre class="code">${esc(r.body)}</pre></div>`;
}

export function approvalsPanelHtml(items) {
    return `
        <div class="eyebrow">Kantor Anda</div>
        <h3>Persetujuan Tool</h3>
        <div class="muted">Agent menunggu izin sebelum memanggil sistem Anda. Periksa input yang akan dikirim. Task berlanjut setelah semua panggilannya diputuskan; penolakan dikirim ke agent beserta catatan Anda.</div>
        ${items.map((c) => `
            <div class="kb-card">
                <div class="kb-card__head"><b>${esc(c.tool)}</b><small>${esc(c.created_at)}</small></div>
                <small>${esc(c.agent_name ?? '-')} · task <button class="link" data-action="task" data-task="${c.task_id}">#${c.task_id} ${esc(c.task_title)}</button></small>
                ${c.method ? `<small><code>${esc(c.method)} ${esc(c.url)}</code></small>` : ''}
                <pre class="code">${prettyJson(c.input)}</pre>
                <form class="admin-form" data-form="tool-decision" data-call="${c.id}">
                    <input name="note" maxlength="500" placeholder="Catatan (opsional, dikirim ke agent bila ditolak)">
                    <div class="btn-row">
                        <button class="btn btn--primary" type="submit" data-decision="approve">Setujui</button>
                        <button class="btn btn--danger" type="submit" data-decision="reject">Tolak</button>
                    </div>
                </form>
            </div>`).join('') || '<div class="empty">Tidak ada panggilan tool yang menunggu persetujuan.</div>'}`;
}
