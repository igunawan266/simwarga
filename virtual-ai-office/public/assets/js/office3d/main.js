import * as THREE from 'three';
import { Stage } from './core/Stage.js';
import { CameraDirector } from './core/CameraDirector.js';
import { api, ApiError } from './core/api.js';
import { DIM, floorY, floorHeight } from './core/config.js';
import { Building } from './building/Building.js';
import { Elevator } from './building/Elevator.js';
import { Hud, esc, adminFloorHtml, plansPanelHtml, teamPanelHtml, registerPanelHtml, billingPanelHtml } from './ui/Hud.js';

/**
 * Application controller / state machine.
 *   explore  — orbit the tower, hover & click floors
 *   lobby    — ground-floor view with the building directory
 *   elevator — scripted ride to the tenant's floor (input locked)
 *   office   — dollhouse view of the tenant's own office
 *   room     — close-up of one room with its detail panel
 */
class App {
    constructor() {
        this.mode = 'explore';
        this.session = null;
        this.office = null;      // /api/v1/office payload
        this.interior = null;    // OfficeInterior (lazy)
        this.crowd = null;       // AgentCrowd (lazy)
        this.selectedFloor = null;
        this.selectedRoom = null;
        this.pointer = new THREE.Vector2();
        this.raycaster = new THREE.Raycaster();

        this.hud = new Hud({
            explore: () => this.explore(),
            lobby: () => this.goLobby(),
            'enter-office': () => this.enterOffice(),
            'toggle-floors': () => this.hud.toggleFloors(),
            floor: (d) => this.focusFloor(Number(d.floor)),
            room: (d) => this.openRoom(Number(d.room)),
            'office-overview': () => this.officeOverview(),
            'close-panel': () => this.closePanel(),
            'open-login': () => this.hud.openLogin(),
            'close-login': () => this.hud.closeLogin(),
            submitLogin: (email, pw) => this.login(email, pw),
            logout: () => this.logout(),
            'simulate-meeting': () => this.simulateMeeting(),
            escape: () => this.escape(),
            // Super Admin
            'admin-plans': () => this.adminPlans(),
            'admin-release': (d, el) => this.adminRelease(Number(d.office), el),
            'form:office': (v, f) => this.adminSave(() => api.admin.updateOffice(f.dataset.office, v), 'Kantor disimpan.'),
            'form:tenant-status': (v, f) => this.adminSave(() => api.admin.updateTenant(f.dataset.tenant, v), 'Status tenant disimpan.', true),
            'form:assign': (v, f) => this.adminSave(() => api.admin.assignOffice(f.dataset.office, v.tenant_id), 'Kantor berhasil disewakan.', true),
            'form:new-tenant': (v, f) => this.adminSave(async () => {
                const tenant = await api.admin.createTenant(v);
                return api.admin.assignOffice(f.dataset.office, tenant.id);
            }, 'Tenant dibuat dan kantor disewakan.', true),
            // Registration & billing
            rent: (d) => this.openRegister(Number(d.floor)),
            'form:register': (v, f) => this.submitRegister(v, f),
            billing: () => this.openBilling(),
            // Team & access
            team: () => this.openTeam(),
            copy: (d) => navigator.clipboard?.writeText(d.text).then(() => this.hud.toast('Tautan disalin.'), () => this.hud.toast('Salin manual tautan di atas.', 'error')),
            'form:invite': (v, f) => this.memberAction(f.dataset, (scope, tid) => (scope === 'admin' ? api.admin.inviteMember(tid, v) : api.members.invite(v)), 'Undangan dibuat.'),
            'form:member': (v, f) => this.memberAction(f.dataset, (scope, tid) => (scope === 'admin' ? api.admin.updateMember(tid, f.dataset.member, v) : api.members.update(f.dataset.member, v)), 'Anggota diperbarui.'),
            'member-reinvite': (d) => this.memberAction(d, (scope, tid) => (scope === 'admin' ? api.admin.reinviteMember(tid, d.member) : api.members.reinvite(d.member)), 'Tautan undangan baru dibuat.'),
            'member-remove': (d, el) => this.confirmTwice(el, () => this.memberAction(d, (scope, tid) => (scope === 'admin' ? api.admin.removeMember(tid, d.member) : api.members.remove(d.member)), 'Anggota dihapus.')),
            'form:plan': (v, f) => this.adminSave(
                () => api.admin.updatePlan(f.dataset.plan, { ...v, is_active: v.is_active ? 1 : 0, is_custom: v.is_custom ? 1 : 0 }),
                'Paket disimpan.',
            ),
        });
    }

    async boot() {
        const canvas = document.getElementById('scene');
        if (!hasWebGL()) {
            this.hud.webglError();
            return;
        }
        this.hud.progress(15, 'Menghubungkan ke gedung…');
        let directory;
        try {
            [directory, this.session] = await Promise.all([api.building(), api.session()]);
        } catch (e) {
            this.hud.progress(100, `Gagal memuat data gedung: ${e.message}`);
            return;
        }
        this.directory = directory;
        document.getElementById('building-name').textContent = directory.building.name;

        this.hud.progress(45, 'Membangun struktur gedung…');
        await nextFrame();
        this.stage = new Stage(canvas);
        this.camera = new CameraDirector(this.stage);
        this.building = new Building(this.stage, directory);
        this.hud.progress(75, 'Menyalakan elevator…');
        await nextFrame();
        this.elevator = new Elevator(this.stage, this.building);

        this.hud.renderSession(this.session);
        this.hud.renderFloors(directory.floors, { mine: this.myFloor() });
        this.building.setMine(this.myFloor());
        this.bindPointer(canvas);

        this.hud.progress(100, 'Selamat datang');
        this.stage.start();
        await nextFrame();
        this.hud.hideLoader();
        this.hud.breadcrumb([directory.building.name, 'Explore Building']);
        await this.camera.flyTo([95, 62, 120], [0, 26, 0], { duration: 3.2, ease: 'power2.out', limits: { min: 45, max: 320 } });

        // Just accepted an invitation: welcome the new member and take them up to their office.
        if (new URLSearchParams(location.search).has('welcome') && this.session?.tenant?.office) {
            history.replaceState(null, '', location.pathname);
            this.hud.toast(`Selamat bergabung di ${this.session.tenant.name}!`);
            this.enterOffice();
        }
    }

    isSuperAdmin() {
        return !!this.session?.user?.is_super_admin;
    }

    // ---------------------------------------------------------------- super admin
    async loadAdminFloor(n, inviteUrl = null) {
        const slot = document.getElementById('admin-floor');
        if (!slot) return;
        slot.innerHTML = '<div class="empty">Memuat data admin…</div>';
        try {
            const [{ floors }, { tenants }] = await Promise.all([api.admin.floors(), api.admin.tenants()]);
            const row = floors.find((r) => r.floor.number === n);
            const detail = row?.tenant ? await api.admin.tenant(row.tenant.id) : null;
            if (this.selectedFloor !== n) return; // user moved on
            document.getElementById('admin-floor').innerHTML = adminFloorHtml(row, tenants, detail, inviteUrl);
        } catch (e) {
            slot.innerHTML = `<div class="empty">${esc(e.message)}</div>`;
        }
    }

    async adminPlans() {
        if (this.mode === 'office' || this.mode === 'room') await this.explore();
        this.hud.showPanel('<div class="eyebrow">Memuat paket…</div>');
        try {
            const { plans } = await api.admin.plans();
            this.hud.showPanel(plansPanelHtml(plans));
            document.getElementById('info-panel').classList.add('info-panel--wide');
        } catch (e) {
            this.hud.toast(e.message, 'error');
        }
    }

    /** Two-step confirm: the first click arms the button, a second click within 4 s runs fn. */
    confirmTwice(el, fn) {
        if (!el.classList.contains('is-armed')) {
            el.classList.add('is-armed');
            el.dataset.label = el.textContent;
            el.textContent = 'Klik lagi untuk konfirmasi';
            setTimeout(() => { el.classList.remove('is-armed'); el.textContent = el.dataset.label; }, 4000);
            return;
        }
        fn();
    }

    adminRelease(officeId, el) {
        this.confirmTwice(el, () => this.adminSave(() => api.admin.releaseOffice(officeId), 'Kantor dikosongkan.', true));
    }

    // ---------------------------------------------------------------- team & access
    async openTeam(inviteUrl = null) {
        try {
            const data = await api.members.list();
            this.hud.showPanel(teamPanelHtml(data, inviteUrl));
            document.getElementById('info-panel').classList.add('info-panel--wide');
        } catch (e) {
            this.hud.toast(e.message, 'error');
        }
    }

    /** Run a member mutation for the office team or (Super Admin) a tenant, then refresh that view. */
    async memberAction({ scope, tenant }, fn, message) {
        let result;
        try {
            result = await fn(scope, tenant);
        } catch (e) {
            this.hud.toast(e.message, 'error', 5000);
            return;
        }
        this.hud.toast(message);
        const inviteUrl = result?.invite_url ?? null;
        if (scope === 'admin') {
            await this.loadAdminFloor(this.selectedFloor, inviteUrl);
        } else {
            await this.openTeam(inviteUrl);
        }
    }

    /** Run an admin mutation; on success optionally rebuild the building from fresh directory data. */
    async adminSave(fn, message, rebuild = false) {
        try {
            await fn();
        } catch (e) {
            this.hud.toast(e.message, 'error', 5000);
            return;
        }
        this.hud.toast(message);
        if (rebuild) {
            await this.refreshBuilding();
            if (this.selectedFloor !== null && this.mode === 'explore') {
                this.hud.floorPanel(this.building.floorData(this.selectedFloor), { isMine: false, isSuperAdmin: true });
                this.loadAdminFloor(this.selectedFloor);
            }
        }
    }

    /** Re-read the public directory and rebuild the tower (after leasing changes). */
    async refreshBuilding() {
        this.directory = await api.building();
        this.building.dispose();
        this.building = new Building(this.stage, this.directory);
        this.building.setMine(this.myFloor());
        this.building.setActive(this.selectedFloor);
        this.hud.renderFloors(this.directory.floors, { mine: this.myFloor() });
    }

    // ---------------------------------------------------------------- registration & billing
    async openRegister(floorNumber) {
        const floor = this.building.floorData(floorNumber);
        if (!floor || floor.tenant || floor.office?.status !== 'available') {
            this.hud.toast('Lantai ini sudah tidak tersedia.', 'error');
            return;
        }
        try {
            const { plans } = await api.plans();
            this.hud.showPanel(registerPanelHtml(floor, plans, this.session));
            document.getElementById('info-panel').classList.add('info-panel--wide');
        } catch (e) {
            this.hud.toast(e.message, 'error');
            return;
        }
        const form = document.querySelector('form[data-form="register"]');
        const total = () => {
            const plan = form.querySelector('input[name=plan_code]:checked');
            const office = Number(form.querySelector('.register-total').dataset.officePrice || 0);
            document.getElementById('register-total').textContent = plan
                ? `Rp ${(Number(plan.dataset.price) + office).toLocaleString('id-ID')}` : '—';
        };
        form.addEventListener('change', total);
        total();
    }

    async submitRegister(values, form) {
        const err = form.querySelector('#register-error');
        const btn = form.querySelector('button[type=submit]');
        err.hidden = true;
        btn.disabled = true;
        try {
            await api.register({ ...values, floor_number: form.dataset.floor });
        } catch (e) {
            err.textContent = e.message;
            err.hidden = false;
            btn.disabled = false;
            if (e.status === 409) this.refreshBuilding();
            return;
        }
        this.session = await api.session();
        this.hud.renderSession(this.session);
        await this.refreshBuilding();
        this.hud.toast('Kantor berhasil di-reserve. Invoice telah dibuat.');
        this.openBilling();
    }

    async openBilling() {
        try {
            const billing = await api.billing();
            this.hud.showPanel(billingPanelHtml(billing));
        } catch (e) {
            this.hud.toast(e.message, 'error');
        }
    }

    myFloor() {
        return this.session?.tenant?.office?.floor_number ?? null;
    }

    // ---------------------------------------------------------------- navigation
    async explore() {
        if (this.mode === 'elevator') return;
        this.leaveOffice();
        this.setMode('explore');
        this.closePanel();
        this.building.setActive(null);
        this.hud.breadcrumb([this.directory.building.name, 'Explore Building']);
        await this.camera.flyTo([95, 62, 120], [0, 26, 0], { duration: 2.2, limits: { min: 45, max: 320 } });
    }

    async focusFloor(n) {
        if (this.mode === 'elevator') return;
        if (this.mode === 'office' || this.mode === 'room') this.leaveOffice();
        if (n === 0) return this.goLobby();
        this.setMode('explore');
        this.selectedFloor = n;
        this.building.setActive(n);
        this.hud.highlightFloor(n);
        const f = this.building.floorData(n);
        this.hud.floorPanel(f, {
            isMine: n === this.myFloor(),
            minePending: this.session?.tenant?.status === 'pending',
            isSuperAdmin: this.isSuperAdmin(),
        });
        if (this.isSuperAdmin()) this.loadAdminFloor(n);
        this.hud.breadcrumb([this.directory.building.name, `Floor ${String(n).padStart(2, '0')}`]);
        const y = floorY(n) + floorHeight(n) / 2;
        await this.camera.flyTo([38, y + 14, 62], [0, y, 0], { duration: 1.6, limits: { min: 30, max: 320 } });
    }

    async goLobby() {
        if (this.mode === 'elevator') return;
        if (this.mode === 'office' || this.mode === 'room') {
            await this.rideDown();
        }
        this.setMode('lobby');
        this.building.setActive(0);
        this.hud.highlightFloor(0);
        this.hud.lobbyPanel(this.directory);
        this.hud.breadcrumb([this.directory.building.name, 'Main Lobby']);
        await this.camera.flyTo([5, 3.6, DIM.D / 2 - 2.5], [-1, 2.4, -4], { duration: 2.2, limits: { min: 4, max: 120, maxPolar: Math.PI * 0.52 } });
    }

    async enterOffice() {
        if (this.mode === 'elevator') return;
        if (this.mode === 'office' || this.mode === 'room') return this.officeOverview();
        if (!this.session?.authenticated) return this.hud.openLogin();
        if (!this.session.tenant) {
            this.hud.toast(this.session.user.is_super_admin
                ? 'Super Admin tidak memiliki kantor tenant. Console Super Admin hadir di Phase 5.'
                : 'Akun Anda belum terdaftar pada tenant mana pun.', 'error');
            return;
        }
        try {
            this.office = await api.office();
        } catch (e) {
            if (e.status === 402) {
                // Registered but not paid yet: show the invoice instead of the office.
                this.hud.toast(e.message, 'error', 5000);
                if (['tenant_owner', 'tenant_admin'].includes(this.session.tenant.role)) this.openBilling();
                return;
            }
            this.hud.toast(e.message, 'error', 5000);
            return;
        }
        await this.rideUp(this.office.office.floor_number);
    }

    /** Full experience: lobby -> elevator doors -> ride -> doors open -> into the office. */
    async rideUp(floor) {
        this.setMode('elevator');
        this.closePanel();
        this.elevator.reserve();
        this.hud.breadcrumb([this.directory.building.name, 'Elevator', `Menuju lantai ${floor}`]);

        const cab = this.elevator.cab;
        const base = this.elevator.group.position;
        // 1. Camera to the elevator landing at ground level while the cab comes down.
        await this.elevator.closeDoors();
        const comingDown = this.elevator.moveTo(0, { fast: true });
        await this.camera.flyTo([base.x + 16, 6, base.z + 18], [base.x, 2, base.z], { duration: 2 });
        await comingDown;
        await this.elevator.openDoors();
        this.hud.toast(`Selamat datang, ${this.session.user.name}. Elevator menuju lantai ${floor}.`);
        await sleep(400);
        await this.elevator.closeDoors();

        // 2. Ride: the camera follows the cab up the facade.
        const stopFollow = this.camera.follow(cab, new THREE.Vector3(15, 4, 19), new THREE.Vector3(-6, 1.5, 0));
        await this.elevator.moveTo(floor);
        stopFollow();

        // 3. Build the interior (lazy) and open the floor up while the doors open.
        const ready = this.ensureInterior();
        this.building.setCutaway(floor);
        await Promise.all([ready, this.elevator.openDoors()]);

        // 4. Step out of the cab and rise into the dollhouse overview.
        const landing = this.elevator.landingPoint(floor);
        await this.camera.flyTo([landing.x + 1, landing.y + 2.2, landing.z + 0.5], [landing.x - 8, landing.y + 1.5, landing.z], { duration: 1.4, ease: 'power2.inOut' });
        this.elevator.closeDoors();
        await this.officeOverview();
        this.elevator.release();
    }

    async rideDown() {
        const floor = this.office?.office.floor_number ?? 0;
        this.setMode('elevator');
        this.closePanel();
        this.elevator.reserve();
        const landing = this.elevator.landingPoint(floor);
        await this.camera.flyTo([landing.x + 14, landing.y + 4, landing.z + 18], [landing.x, landing.y + 1, landing.z], { duration: 1.4 });
        await this.elevator.moveTo(floor, { fast: true });
        await this.elevator.openDoors();
        await this.elevator.closeDoors();
        this.leaveOffice();
        const stopFollow = this.camera.follow(this.elevator.cab, new THREE.Vector3(15, 4, 19), new THREE.Vector3(-6, 1.5, 0));
        await this.elevator.moveTo(0);
        stopFollow();
        await this.elevator.openDoors();
        this.elevator.release();
    }

    /** Builds the tenant interior once per visit. Dynamic imports keep it out of the initial load. */
    ensureInterior() {
        this.interiorReady ||= Promise.all([import('./office/OfficeInterior.js'), import('./office/AgentCrowd.js')])
            .then(([{ OfficeInterior }, { AgentCrowd }]) => {
                this.interior = new OfficeInterior(this.stage, this.office);
                this.crowd = new AgentCrowd(this.stage, this.interior, this.office.agents);
            });
        return this.interiorReady;
    }

    async officeOverview() {
        await this.ensureInterior();
        this.setMode('office');
        this.selectedRoom = null;
        this.interior.setSelected(null);
        this.hud.highlightRoom(null);
        this.hud.renderOffice(this.office);
        this.closePanel();
        const o = this.office;
        this.hud.breadcrumb([this.directory.building.name, `Floor ${o.office.floor_number}`, o.tenant.name]);
        const cam = this.interior.overviewCamera();
        await this.camera.flyTo(cam.position, cam.target, { duration: 1.8, limits: { min: 12, max: 90, maxPolar: Math.PI * 0.42, pan: true } });
    }

    async openRoom(id) {
        if (!this.interior || !this.interior.rooms.has(id)) return;
        this.setMode('room');
        this.selectedRoom = id;
        this.interior.setSelected(id);
        this.hud.highlightRoom(id);
        const room = this.interior.rooms.get(id);
        this.hud.breadcrumb([this.directory.building.name, this.office.tenant.name, room.data.name]);
        this.hud.roomPanel(null, { loading: true });
        const cam = this.interior.roomCamera(id);
        const fly = this.camera.flyTo(cam.position, cam.target, { duration: 1.4, limits: { min: 5, max: 40, maxPolar: Math.PI * 0.45, pan: true } });
        try {
            const detail = await api.room(id);
            if (this.selectedRoom === id) this.hud.roomPanel(detail);
        } catch (e) {
            this.hud.toast(e.message, 'error');
        }
        await fly;
    }

    simulateMeeting() {
        const n = this.crowd?.simulateMeeting(14) ?? 0;
        this.hud.toast(n ? `${n} agent menuju Meeting Room (simulasi visual).` : 'Tidak ada agent yang tersedia untuk rapat.');
    }

    leaveOffice() {
        this.interiorReady = null;
        if (!this.interior) return;
        this.crowd?.dispose();
        this.interior.dispose();
        this.crowd = null;
        this.interior = null;
        this.building.setCutaway(null);
    }

    closePanel() {
        this.hud.hidePanel();
        document.getElementById('info-panel').classList.remove('info-panel--wide');
        if (this.mode === 'explore') {
            this.building?.setActive(null);
            this.hud.highlightFloor(null);
        }
    }

    escape() {
        if (this.mode === 'room') return this.officeOverview();
        this.hud.closeLogin();
        this.closePanel();
    }

    setMode(mode) {
        this.mode = mode;
        this.hud.setMode(mode);
        this.hud.tooltip(null);
        if (this.building && mode !== 'explore') this.building.setHover(null);
    }

    // ---------------------------------------------------------------- session
    async login(email, password) {
        if (!email || password.length < 8) {
            this.hud.loginError('Masukkan email dan password (min. 8 karakter).');
            return;
        }
        this.hud.loginBusy(true);
        try {
            this.session = await api.login(email, password);
        } catch (e) {
            this.hud.loginError(e instanceof ApiError && e.status === 429 ? 'Terlalu banyak percobaan. Tunggu 1 menit.' : e.message);
            return;
        } finally {
            this.hud.loginBusy(false);
        }
        this.hud.closeLogin();
        this.hud.renderSession(this.session);
        this.hud.renderFloors(this.directory.floors, { mine: this.myFloor() });
        this.building.setMine(this.myFloor());
        if (this.session.tenant?.office) {
            this.enterOffice();
        } else {
            this.hud.toast(this.session.user.is_super_admin ? 'Login sebagai Super Admin.' : 'Login berhasil.');
        }
    }

    async logout() {
        try {
            await api.logout();
        } catch { /* session already gone */ }
        this.session = { authenticated: false };
        this.office = null;
        this.hud.renderSession(this.session);
        this.hud.renderFloors(this.directory.floors);
        this.building.setMine(null);
        if (this.mode === 'office' || this.mode === 'room') {
            this.leaveOffice();
        }
        this.mode = 'explore';
        this.explore();
        this.hud.toast('Anda telah logout.');
    }

    // ---------------------------------------------------------------- picking
    bindPointer(canvas) {
        let pending = false;
        let last = null;
        canvas.addEventListener('pointermove', (e) => {
            last = e;
            if (pending) return;
            pending = true;
            requestAnimationFrame(() => {
                pending = false;
                this.hover(last);
            });
        });
        let down = null;
        canvas.addEventListener('pointerdown', (e) => { down = { x: e.clientX, y: e.clientY }; });
        canvas.addEventListener('pointerup', (e) => {
            if (!down || Math.hypot(e.clientX - down.x, e.clientY - down.y) > 6) return; // it was a drag
            this.click(e);
        });
        canvas.addEventListener('pointerleave', () => this.hud.tooltip(null));
    }

    pick(e, targets) {
        const rect = this.stage.canvas.getBoundingClientRect();
        this.pointer.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
        this.raycaster.setFromCamera(this.pointer, this.stage.camera);
        return this.raycaster.intersectObjects(targets, false)[0] || null;
    }

    hover(e) {
        if (this.mode === 'elevator') return;
        if (this.mode === 'explore' || this.mode === 'lobby') {
            const hit = this.pick(e, this.building.hitTargets);
            const n = hit ? hit.object.userData.floor : null;
            this.building.setHover(n);
            this.hud.highlightFloor(n, 'is-hover');
            if (n === null) return this.hud.tooltip(null);
            const f = this.building.floorData(n);
            const name = n === 0 ? 'Main Lobby' : f.tenant ? f.tenant.name : 'Available Office';
            this.hud.tooltip(`<b>${esc(name)}</b><small>${n === 0 ? 'Ground floor' : `Floor ${n} · ${esc(f.office?.number ?? '')}`}</small>`, e.clientX, e.clientY);
            this.stage.canvas.style.cursor = 'pointer';
            return;
        }
        if (this.interior) {
            const agentHit = this.pick(e, this.crowd.hitTargets);
            const actor = agentHit && this.crowd.actorFromHit(agentHit);
            if (actor) {
                this.hud.tooltip(`<b>${esc(actor.agent.name)}</b><small>${esc(actor.agent.job_title)} · ${esc(actor.agent.status)}</small>`, e.clientX, e.clientY);
                this.stage.canvas.style.cursor = 'pointer';
                return;
            }
            const hit = this.pick(e, this.interior.hitMeshes);
            const id = hit ? hit.object.userData.roomId : null;
            this.interior.setHover(id);
            if (id === null) {
                this.stage.canvas.style.cursor = '';
                return this.hud.tooltip(null);
            }
            const r = this.interior.rooms.get(id).data;
            this.hud.tooltip(`<b>${esc(r.name)}</b><small>${r.enabled ? `${r.agent_count} Agent AI · klik untuk masuk` : 'Terkunci'}</small>`, e.clientX, e.clientY);
            this.stage.canvas.style.cursor = 'pointer';
        }
    }

    click(e) {
        if (this.mode === 'elevator') return;
        if (this.mode === 'explore' || this.mode === 'lobby') {
            const hit = this.pick(e, this.building.hitTargets);
            if (hit) this.focusFloor(hit.object.userData.floor);
            return;
        }
        if (this.interior) {
            const agentHit = this.pick(e, this.crowd.hitTargets);
            const actor = agentHit && this.crowd.actorFromHit(agentHit);
            if (actor) {
                this.hud.agentPanel(actor.agent, actor.room);
                return;
            }
            const hit = this.pick(e, this.interior.hitMeshes);
            if (hit) this.openRoom(hit.object.userData.roomId);
        }
    }
}

function hasWebGL() {
    try {
        const c = document.createElement('canvas');
        return !!(c.getContext('webgl2') || c.getContext('webgl'));
    } catch {
        return false;
    }
}
const nextFrame = () => new Promise((r) => requestAnimationFrame(() => r()));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Scripted sequences (elevator ride, camera moves) must finish on time even on slow GPUs,
// so GSAP should skip ahead on long frames instead of stretching the timeline.
window.gsap.ticker.lagSmoothing(0);

const app = new App();
app.boot();
if (new URLSearchParams(location.search).has('debug')) window.__office = app;
