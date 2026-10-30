import * as THREE from 'three';
import { DIM, COLORS, floorY, floorHeight, isLowPower } from '../core/config.js';
import { canvasTexture, labelTexture, windowTexture, roundRect, fit } from '../core/textures.js';
import { Furniture } from '../office/Furniture.js';

const ACTIVITY_SPEED = [0, 1.2, 2.2, 3.4];

/**
 * The tower: lobby, office floors (glass, slabs, signage, activity lights, low-detail
 * interiors visible through the glass), roof crown and the surrounding city.
 * Built entirely from the /api/v1/building directory payload.
 */
export class Building {
    constructor(stage, directory) {
        this.stage = stage;
        this.directory = directory;
        this.group = new THREE.Group();
        this.group.name = 'building';
        this.floors = new Map();
        this.hitMeshes = [];
        this.hovered = null;
        this.active = null;
        this.mine = null;
        this.cutawayAbove = null;
        this.topFloor = Math.max(...directory.floors.map((f) => f.number));
        this.height = floorY(this.topFloor) + DIM.FLOOR_H;

        this.buildGround();
        this.buildCity();
        directory.floors.forEach((f) => this.buildFloor(f));
        this.buildLowDetailInteriors();
        this.buildLobby();
        this.buildStructure();
        this.buildRoof();

        stage.scene.add(this.group);
        this.unsubscribe = stage.onUpdate((dt, t) => this.update(dt, t));
    }

    /** Free GPU resources (used when the directory changes and the tower is rebuilt). */
    dispose() {
        this.unsubscribe();
        this.stage.scene.remove(this.group);
        const seen = new Set();
        this.group.traverse((o) => {
            if (o.geometry && !o.geometry.userData.shared) o.geometry.dispose();
            const mats = Array.isArray(o.material) ? o.material : o.material ? [o.material] : [];
            mats.forEach((m) => {
                if (seen.has(m)) return;
                seen.add(m);
                m.map?.dispose();
                m.emissiveMap?.dispose();
                // Furniture materials are shared caches; only dispose ones this building created.
                if (!m.userData.shared) m.dispose();
            });
        });
    }

    // ---------------------------------------------------------------- environment
    buildGround() {
        const ground = new THREE.Mesh(
            new THREE.CircleGeometry(700, 64),
            new THREE.MeshStandardMaterial({ color: 0x06101f, roughness: 0.9, metalness: 0.1 }),
        );
        ground.rotation.x = -Math.PI / 2;
        ground.position.y = -0.02;
        this.group.add(ground);

        const grid = new THREE.GridHelper(700, 140, COLORS.cyan, 0x12305a);
        grid.material.transparent = true;
        grid.material.opacity = 0.12;
        grid.position.y = 0.01;
        this.group.add(grid);

        const plaza = new THREE.Mesh(
            new THREE.BoxGeometry(DIM.W + 36, 0.3, DIM.D + 44),
            new THREE.MeshStandardMaterial({ color: 0x111f36, roughness: 0.4, metalness: 0.4 }),
        );
        plaza.position.set(0, 0.15, 6);
        this.group.add(plaza);

        // Light strips that frame the plaza.
        const strip = new THREE.MeshBasicMaterial({ color: COLORS.cyan, transparent: true, opacity: 0.55 });
        [[0, DIM.D / 2 + 28, DIM.W + 36, 0.25], [-(DIM.W / 2 + 18), 6, 0.25, DIM.D + 44], [DIM.W / 2 + 18, 6, 0.25, DIM.D + 44]].forEach(([x, z, w, d]) => {
            const m = new THREE.Mesh(new THREE.BoxGeometry(w, 0.05, d), strip);
            m.position.set(x, 0.32, z);
            this.group.add(m);
        });
    }

    buildCity() {
        const count = isLowPower ? 45 : 90;
        const tex = windowTexture();
        tex.repeat.set(2, 3);
        const mat = new THREE.MeshStandardMaterial({ color: 0x0a1a30, roughness: 0.6, metalness: 0.5, emissive: 0xffffff, emissiveMap: tex, emissiveIntensity: 0.8 });
        const mesh = new THREE.InstancedMesh(new THREE.BoxGeometry(1, 1, 1), mat, count);
        const m = new THREE.Matrix4();
        const q = new THREE.Quaternion();
        let i = 0;
        let guard = 0;
        while (i < count && guard++ < 2000) {
            const r = 95 + Math.random() * 320;
            const a = Math.random() * Math.PI * 2;
            const x = Math.cos(a) * r;
            const z = Math.sin(a) * r;
            if (Math.abs(x) < 60 && z > 0 && z < 160) continue; // keep the main view corridor open
            const w = 14 + Math.random() * 18;
            const d = 14 + Math.random() * 18;
            const h = 12 + Math.pow(Math.random(), 1.6) * (r < 200 ? 70 : 110);
            m.compose(new THREE.Vector3(x, h / 2, z), q, new THREE.Vector3(w, h, d));
            mesh.setMatrixAt(i++, m);
        }
        mesh.count = i;
        mesh.instanceMatrix.needsUpdate = true;
        this.city = mesh;
        this.group.add(mesh);
    }

    // ---------------------------------------------------------------- floors
    buildFloor(data) {
        const n = data.number;
        const y = floorY(n);
        const h = floorHeight(n);
        const g = new THREE.Group();
        g.name = `floor-${n}`;
        const tenantColor = new THREE.Color(data.tenant?.color || '#1e3a5f');

        const slabMat = new THREE.MeshStandardMaterial({ color: 0x8e9bb0, roughness: 0.4, metalness: 0.55 });
        const slab = new THREE.Mesh(new THREE.BoxGeometry(DIM.W, DIM.SLAB, DIM.D), slabMat);
        slab.position.y = y + DIM.SLAB / 2;
        g.add(slab);

        const lineMat = new THREE.LineBasicMaterial({ color: COLORS.cyan, transparent: true, opacity: 0.35 });
        const edges = new THREE.LineSegments(new THREE.EdgesGeometry(slab.geometry), lineMat);
        edges.position.copy(slab.position);
        g.add(edges);

        // Glass curtain wall (4 sides, no caps so the interior stays visible from above).
        const glassMat = new THREE.MeshStandardMaterial({
            color: 0x2a6fb0, metalness: 0.9, roughness: 0.08, transparent: true, opacity: 0.24,
            side: THREE.DoubleSide, depthWrite: false, envMapIntensity: 1.2,
        });
        if (data.tenant) {
            // Occupied floors glow faintly in the tenant's brand colour.
            glassMat.emissive = tenantColor.clone();
            glassMat.emissiveIntensity = 0.22;
        }
        const gh = h - DIM.SLAB;
        const gy = y + DIM.SLAB + gh / 2;
        [[0, DIM.D / 2, DIM.W, 0], [0, -DIM.D / 2, DIM.W, Math.PI], [DIM.W / 2, 0, DIM.D, Math.PI / 2], [-DIM.W / 2, 0, DIM.D, -Math.PI / 2]].forEach(([x, z, w, ry]) => {
            const p = new THREE.Mesh(new THREE.PlaneGeometry(w, gh), glassMat);
            p.position.set(x, gy, z);
            p.rotation.y = ry;
            p.renderOrder = 2;
            g.add(p);
        });

        // Ceiling glow — what makes an occupied floor read as "lit" from outside.
        const glowMat = new THREE.MeshBasicMaterial({
            color: data.tenant ? tenantColor.clone().lerp(new THREE.Color(0xffffff), 0.35) : new THREE.Color(0x0f2140),
            transparent: true, opacity: data.tenant ? 0.55 : 0.35, side: THREE.DoubleSide,
        });
        if (n > 0) {
            const glow = new THREE.Mesh(new THREE.PlaneGeometry(DIM.PLATE_W, DIM.PLATE_D), glowMat);
            glow.rotation.x = Math.PI / 2;
            glow.position.y = y + h - 0.06;
            g.add(glow);
        }

        // Facade sign + activity indicator.
        let sign = null;
        let indicator = null;
        if (n > 0) {
            const tex = this.signTexture(data);
            sign = new THREE.Mesh(new THREE.PlaneGeometry(13, 1.6), new THREE.MeshBasicMaterial({ map: tex, transparent: true, depthWrite: false }));
            sign.position.set(-DIM.W / 2 + 7.5, y + h - 1.15, DIM.D / 2 + 0.06);
            g.add(sign);

            indicator = new THREE.Mesh(
                new THREE.BoxGeometry(0.5, 0.5, 0.5),
                new THREE.MeshBasicMaterial({ color: data.tenant ? tenantColor : new THREE.Color(0x334155), transparent: true }),
            );
            indicator.position.set(DIM.W / 2 - 0.8, y + h - 0.9, DIM.D / 2 + 0.3);
            g.add(indicator);
        }

        // Invisible hit volume for hover / click.
        const hit = new THREE.Mesh(new THREE.BoxGeometry(DIM.W + 0.6, h, DIM.D + 0.6), new THREE.MeshBasicMaterial({ visible: false }));
        hit.position.y = y + h / 2;
        hit.userData.floor = n;
        g.add(hit);
        this.hitMeshes.push(hit);

        this.group.add(g);
        this.floors.set(n, { data, group: g, slabMat, lineMat, glassMat, glowMat, sign, indicator, shown: true, phase: Math.random() * 6 });
    }

    signTexture(data) {
        const t = data.tenant;
        return canvasTexture(1024, 128, (ctx, w, h) => {
            ctx.fillStyle = 'rgba(4,11,23,0.78)';
            roundRect(ctx, 0, 0, w, h, 14);
            ctx.fill();
            ctx.fillStyle = t ? t.color : '#475569';
            ctx.fillRect(0, 0, 10, h);
            ctx.textBaseline = 'middle';
            ctx.fillStyle = '#22d3ee';
            ctx.font = '700 54px "Space Grotesk", Inter, sans-serif';
            ctx.fillText(String(data.number).padStart(2, '0'), 34, h / 2);
            ctx.fillStyle = t ? '#f5f8fc' : '#8da2bf';
            ctx.font = `600 ${t ? 46 : 40}px "Space Grotesk", Inter, sans-serif`;
            const vacantLabel = data.office?.status === 'reserved' ? 'RESERVED · MENUNGGU PEMBAYARAN' : data.office?.status === 'maintenance' ? 'MAINTENANCE' : 'AVAILABLE · FOR LEASE';
            if (!t && data.office?.status === 'reserved') ctx.fillStyle = '#fbbf24';
            ctx.fillText(fit(ctx, t ? t.name : vacantLabel, 700), 130, h / 2 - (t ? 10 : 0));
            if (t) {
                ctx.fillStyle = '#8da2bf';
                ctx.font = '400 26px Inter, sans-serif';
                ctx.fillText(fit(ctx, `${data.office?.number ?? ''}  ·  ${t.industry ?? ''}`, 700), 130, h / 2 + 34);
            }
        });
    }

    /** Desks + monitors + people on every occupied floor, as three instanced meshes. */
    buildLowDetailInteriors() {
        const occupied = this.directory.floors.filter((f) => f.number > 0 && f.tenant);
        const desksPer = 24;
        const peoplePer = 10;
        const deskMesh = new THREE.InstancedMesh(new THREE.BoxGeometry(2.4, 0.8, 1.2), new THREE.MeshStandardMaterial({ color: 0xdfe6ee, roughness: 0.5 }), occupied.length * desksPer);
        const screenMesh = new THREE.InstancedMesh(new THREE.BoxGeometry(1.1, 0.7, 0.06), new THREE.MeshBasicMaterial({ color: 0x67e8f9 }), occupied.length * desksPer);
        const peopleMesh = new THREE.InstancedMesh(new THREE.CapsuleGeometry(0.32, 0.9, 3, 8), new THREE.MeshStandardMaterial({ color: 0xe2e8f0, roughness: 0.4, emissive: 0x0e7490, emissiveIntensity: 0.4 }), occupied.length * peoplePer);

        this.lowDetail = { deskMesh, screenMesh, peopleMesh, ranges: new Map(), matrices: { desk: [], screen: [], people: [] } };
        const m = new THREE.Matrix4();
        let di = 0;
        let pi = 0;
        occupied.forEach((f) => {
            const y = floorY(f.number) + DIM.SLAB;
            const start = { desk: di, people: pi };
            for (let r = 0; r < 4; r++) {
                for (let c = 0; c < 6; c++) {
                    const x = -15 + c * 6;
                    const z = -9 + r * 6;
                    m.makeTranslation(x, y + 0.4, z);
                    deskMesh.setMatrixAt(di, m);
                    this.lowDetail.matrices.desk[di] = m.clone();
                    m.makeTranslation(x, y + 1.15, z - 0.35);
                    screenMesh.setMatrixAt(di, m);
                    this.lowDetail.matrices.screen[di] = m.clone();
                    di++;
                }
            }
            for (let p = 0; p < peoplePer; p++) {
                m.makeTranslation(-16 + Math.random() * 32, y + 0.8, -10 + Math.random() * 20);
                peopleMesh.setMatrixAt(pi, m);
                this.lowDetail.matrices.people[pi] = m.clone();
                pi++;
            }
            this.lowDetail.ranges.set(f.number, { desk: [start.desk, di], people: [start.people, pi] });
        });
        [deskMesh, screenMesh, peopleMesh].forEach((mesh) => { mesh.instanceMatrix.needsUpdate = true; this.group.add(mesh); });
    }

    /** Show low-detail interiors only for floors <= maxFloor, and never for `except`. */
    setLowDetailVisibility(maxFloor = Infinity, except = null) {
        const { deskMesh, screenMesh, peopleMesh, ranges, matrices } = this.lowDetail;
        const zero = new THREE.Matrix4().makeScale(0, 0, 0);
        ranges.forEach((r, n) => {
            const show = n <= maxFloor && n !== except;
            for (let i = r.desk[0]; i < r.desk[1]; i++) {
                deskMesh.setMatrixAt(i, show ? matrices.desk[i] : zero);
                screenMesh.setMatrixAt(i, show ? matrices.screen[i] : zero);
            }
            for (let i = r.people[0]; i < r.people[1]; i++) peopleMesh.setMatrixAt(i, show ? matrices.people[i] : zero);
        });
        [deskMesh, screenMesh, peopleMesh].forEach((mesh) => { mesh.instanceMatrix.needsUpdate = true; });
    }

    // ---------------------------------------------------------------- lobby, structure, roof
    buildLobby() {
        const g = new THREE.Group();
        g.name = 'lobby';
        const fl = new THREE.Mesh(new THREE.PlaneGeometry(DIM.PLATE_W, DIM.PLATE_D), new THREE.MeshStandardMaterial({ color: 0x0d203b, roughness: 0.15, metalness: 0.7 }));
        fl.rotation.x = -Math.PI / 2;
        fl.position.y = DIM.SLAB + 0.01;
        g.add(fl);

        // Entrance canopy and sign.
        const canopy = new THREE.Mesh(new THREE.BoxGeometry(18, 0.4, 8), new THREE.MeshStandardMaterial({ color: COLORS.silver, metalness: 0.8, roughness: 0.25 }));
        canopy.position.set(0, 5.2, DIM.D / 2 + 4);
        g.add(canopy);
        const canopyEdge = new THREE.Mesh(new THREE.BoxGeometry(18.2, 0.12, 0.12), new THREE.MeshBasicMaterial({ color: COLORS.cyan }));
        canopyEdge.position.set(0, 5.0, DIM.D / 2 + 8);
        g.add(canopyEdge);
        const signTex = canvasTexture(1024, 160, (ctx, w, h) => {
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.shadowColor = '#22d3ee';
            ctx.shadowBlur = 24;
            ctx.fillStyle = '#e0f7ff';
            ctx.font = '700 84px "Space Grotesk", Inter, sans-serif';
            ctx.fillText('VIRTUAL AI OFFICE', w / 2, h / 2);
        });
        const sign = new THREE.Mesh(new THREE.PlaneGeometry(16, 2.5), new THREE.MeshBasicMaterial({ map: signTex, transparent: true, depthWrite: false }));
        sign.position.set(0, 6.4, DIM.D / 2 + 0.2);
        g.add(sign);

        // Reception desk + AI receptionist + tenant directory screen.
        const desk = Furniture.receptionDesk(10, 0x0b1f3a);
        desk.position.set(0, DIM.SLAB, 2);
        g.add(desk);
        const bot = Furniture.robot(0x22d3ee);
        bot.position.set(0, DIM.SLAB, -0.4);
        this.receptionist = bot;
        g.add(bot);

        this.directoryScreen = this.makeDirectoryScreen();
        this.directoryScreen.position.set(-12, 3.2, -DIM.PLATE_D / 2 + 0.6);
        g.add(this.directoryScreen);

        [[-17, 10], [17, 10], [-17, -10], [17, -10]].forEach(([x, z]) => {
            const p = Furniture.plant(1.3);
            p.position.set(x, DIM.SLAB, z);
            g.add(p);
        });
        [[-12, 5, -Math.PI / 2], [12, 5, Math.PI / 2]].forEach(([x, z, ry]) => {
            const s = Furniture.sofa(4, 0x1e3a5f);
            s.position.set(x, DIM.SLAB, z);
            s.rotation.y = ry;
            g.add(s);
        });

        // Gate lights at the entrance.
        const gateMat = new THREE.MeshBasicMaterial({ color: COLORS.cyan, transparent: true, opacity: 0.8 });
        [-3, 3].forEach((x) => {
            const gate = new THREE.Mesh(new THREE.BoxGeometry(0.15, 1.1, 1.2), gateMat);
            gate.position.set(x, DIM.SLAB + 0.55, DIM.D / 2 - 3);
            g.add(gate);
        });

        this.lobby = g;
        this.group.add(g);
    }

    makeDirectoryScreen() {
        const floors = [...this.directory.floors].filter((f) => f.number > 0).reverse();
        const tex = canvasTexture(512, 640, (ctx, w, h) => {
            ctx.fillStyle = '#04101f';
            ctx.fillRect(0, 0, w, h);
            ctx.fillStyle = '#22d3ee';
            ctx.font = '700 30px "Space Grotesk", Inter, sans-serif';
            ctx.fillText('BUILDING DIRECTORY', 28, 52);
            ctx.fillRect(28, 66, 120, 3);
            ctx.font = '500 22px Inter, sans-serif';
            floors.forEach((f, i) => {
                const y = 108 + i * 42;
                ctx.fillStyle = '#22d3ee';
                ctx.fillText(String(f.number).padStart(2, '0'), 28, y);
                ctx.fillStyle = f.tenant ? '#f5f8fc' : '#475569';
                ctx.fillText(fit(ctx, f.tenant ? f.tenant.name : 'Available', 380), 80, y);
            });
        });
        const screen = new THREE.Mesh(new THREE.PlaneGeometry(4, 5), new THREE.MeshBasicMaterial({ map: tex }));
        const frame = new THREE.Mesh(new THREE.BoxGeometry(4.3, 5.3, 0.15), new THREE.MeshStandardMaterial({ color: COLORS.silver, metalness: 0.8, roughness: 0.3 }));
        frame.position.z = -0.09;
        const g = new THREE.Group();
        g.add(frame, screen);
        return g;
    }

    buildStructure() {
        // Vertical mullions / columns around the perimeter (one instanced mesh).
        const positions = [];
        for (let x = -DIM.W / 2; x <= DIM.W / 2 + 0.01; x += 6) positions.push([x, DIM.D / 2], [x, -DIM.D / 2]);
        for (let z = -DIM.D / 2 + 6; z < DIM.D / 2 - 0.01; z += 6) positions.push([DIM.W / 2, z], [-DIM.W / 2, z]);
        const mesh = new THREE.InstancedMesh(
            new THREE.BoxGeometry(0.3, 1, 0.3),
            new THREE.MeshStandardMaterial({ color: COLORS.silver, metalness: 0.85, roughness: 0.25 }),
            positions.length,
        );
        const m = new THREE.Matrix4();
        positions.forEach(([x, z], i) => {
            m.compose(new THREE.Vector3(x, this.height / 2, z), new THREE.Quaternion(), new THREE.Vector3(1, this.height, 1));
            mesh.setMatrixAt(i, m);
        });
        this.mullions = mesh;
        this.group.add(mesh);
    }

    buildRoof() {
        const g = new THREE.Group();
        const top = this.height;
        const roofMat = new THREE.MeshStandardMaterial({ color: COLORS.silver, metalness: 0.7, roughness: 0.3, transparent: true });
        const roof = new THREE.Mesh(new THREE.BoxGeometry(DIM.W + 0.6, 0.6, DIM.D + 0.6), roofMat);
        roof.position.y = top + 0.3;
        g.add(roof);

        const crownMat = new THREE.MeshStandardMaterial({ color: 0x163a66, metalness: 0.8, roughness: 0.2, transparent: true });
        const crown1 = new THREE.Mesh(new THREE.BoxGeometry(26, 5, 18), crownMat);
        crown1.position.y = top + 3.1;
        const crown2 = new THREE.Mesh(new THREE.BoxGeometry(14, 4, 10), crownMat);
        crown2.position.y = top + 7.6;
        g.add(crown1, crown2);
        [crown1, crown2].forEach((c) => {
            const e = new THREE.LineSegments(new THREE.EdgesGeometry(c.geometry), new THREE.LineBasicMaterial({ color: COLORS.cyan, transparent: true, opacity: 0.8 }));
            e.position.copy(c.position);
            g.add(e);
        });

        const antenna = new THREE.Mesh(new THREE.CylinderGeometry(0.15, 0.3, 16, 8), new THREE.MeshStandardMaterial({ color: COLORS.silver, metalness: 0.9, roughness: 0.2, transparent: true }));
        antenna.position.y = top + 17.6;
        g.add(antenna);
        this.beacon = new THREE.Mesh(new THREE.SphereGeometry(0.45, 12, 8), new THREE.MeshBasicMaterial({ color: 0xff3b5c, transparent: true }));
        this.beacon.position.y = top + 25.8;
        g.add(this.beacon);

        const pad = new THREE.Mesh(new THREE.RingGeometry(5, 5.4, 48), new THREE.MeshBasicMaterial({ color: COLORS.cyan, side: THREE.DoubleSide, transparent: true }));
        pad.rotation.x = -Math.PI / 2;
        pad.position.set(0, top + 5.65, 0);
        g.add(pad);

        g.userData.shown = true;
        this.roof = g;
        this.group.add(g);
    }

    // ---------------------------------------------------------------- interaction
    get hitTargets() {
        return this.hitMeshes;
    }

    setHover(n) {
        if (this.hovered === n) return;
        this.hovered = n;
        this.refreshHighlight();
    }

    setActive(n) {
        this.active = n;
        this.refreshHighlight();
    }

    setMine(n) {
        this.mine = n;
        this.refreshHighlight();
    }

    refreshHighlight() {
        this.floors.forEach((f, n) => {
            const on = n === this.hovered || n === this.active;
            f.lineMat.opacity = on ? 1 : n === this.mine ? 0.75 : 0.35;
            f.lineMat.color.set(n === this.mine && !on ? 0x67e8f9 : COLORS.cyan);
            f.slabMat.emissive.set(on ? 0x0e7490 : 0x000000);
            f.slabMat.emissiveIntensity = on ? 0.6 : 0;
        });
    }

    /**
     * Dollhouse view: hide every floor above `n` (and the roof) and make floor n's glass
     * almost invisible. Pass null to restore the full tower.
     */
    setCutaway(n) {
        this.cutawayAbove = n;
        const fade = (obj, visible) => {
            const mats = [];
            obj.traverse((o) => { if (o.material && !mats.includes(o.material)) mats.push(o.material); });
            mats.forEach((mat) => {
                if (mat.userData.baseOpacity === undefined) mat.userData.baseOpacity = mat.opacity;
                mat.transparent = true;
            });
            window.gsap.killTweensOf(mats);
            if (visible) obj.visible = true;
            window.gsap.to(mats, {
                opacity: (i) => (visible ? mats[i].userData.baseOpacity : 0),
                duration: 0.8,
                ease: 'power2.inOut',
                onComplete: () => { if (!visible) obj.visible = false; },
            });
        };
        this.floors.forEach((f, num) => {
            const shouldShow = n === null || num <= n;
            if (shouldShow !== f.shown) {
                f.shown = shouldShow;
                fade(f.group, shouldShow);
            }
        });
        if (this.roof.userData.shown !== (n === null)) {
            this.roof.userData.shown = n === null;
            fade(this.roof, n === null);
        }
        this.mullions.visible = n === null;
        this.setLowDetailVisibility(n === null ? Infinity : n, n);

        // The floor being looked into gets near-invisible glass; its facade sign is hidden.
        this.floors.forEach((f, num) => {
            if (!f.shown) return;
            const base = f.glassMat.userData.baseOpacity ?? 0.2;
            window.gsap.to(f.glassMat, { opacity: num === n ? 0.04 : base, duration: 0.8, overwrite: true });
            if (f.sign) f.sign.visible = num !== n;
        });
    }

    update(dt, t) {
        this.floors.forEach((f) => {
            if (!f.indicator) return;
            const level = f.data.tenant?.activity_level ?? 0;
            f.indicator.material.opacity = level === 0 ? 0.35 : 0.55 + 0.45 * Math.sin(t * ACTIVITY_SPEED[level] + f.phase);
        });
        if (this.beacon) this.beacon.material.opacity = Math.sin(t * 2.4) > 0.6 ? 1 : 0.15;
        if (this.receptionist) this.receptionist.rotation.y = Math.sin(t * 0.6) * 0.35;

        // Simple LOD: drop the low-detail interiors and part of the city when far away.
        const dist = this.stage.camera.position.length();
        const far = dist > 260;
        this.lowDetail.deskMesh.visible = this.lowDetail.screenMesh.visible = this.lowDetail.peopleMesh.visible = !far;
    }

    floorData(n) {
        return this.floors.get(n)?.data;
    }
}
