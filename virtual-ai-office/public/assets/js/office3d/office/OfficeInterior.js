import * as THREE from 'three';
import { DIM, COLORS, STATUS_COLORS, floorY } from '../core/config.js';
import { canvasTexture, makeLabel, fit, roundRect } from '../core/textures.js';
import { Furniture, M } from './Furniture.js';

const WALL_H = 2.8;
const CORRIDOR_Z = -0.25; // centre line of the corridor between the two room rows
const DOOR_W = 1.8;

/**
 * Detailed interior of the tenant's own office floor. Built lazily the first time the
 * tenant enters, from /api/v1/office. Every room is a clickable hit volume.
 */
export class OfficeInterior {
    constructor(stage, office) {
        this.stage = stage;
        this.office = office;
        this.floorNumber = office.office.floor_number;
        this.group = new THREE.Group();
        this.group.name = 'office-interior';
        this.group.position.y = floorY(this.floorNumber) + DIM.SLAB;
        this.rooms = new Map();   // id -> { data, group, tile, hit, seats, spots, center }
        this.hitMeshes = [];
        this.animated = [];       // { fn(t) }
        this.hovered = null;
        this.selected = null;

        this.glassMat = new THREE.MeshStandardMaterial({ color: 0xbae6fd, metalness: 0.6, roughness: 0.05, transparent: true, opacity: 0.16, side: THREE.DoubleSide, depthWrite: false });
        this.frameMat = new THREE.MeshStandardMaterial({ color: COLORS.silver, metalness: 0.85, roughness: 0.25 });

        this.buildCorridor();
        office.rooms.forEach((r) => this.buildRoom(r));
        stage.scene.add(this.group);
        this.unsubscribe = stage.onUpdate((dt, t) => this.animated.forEach((a) => a(t, dt)));
    }

    buildCorridor() {
        const runner = new THREE.Mesh(new THREE.PlaneGeometry(DIM.PLATE_W, 4.4), new THREE.MeshStandardMaterial({ color: 0x0a1930, roughness: 0.25, metalness: 0.5 }));
        runner.rotation.x = -Math.PI / 2;
        runner.position.set(0, 0.015, CORRIDOR_Z);
        this.group.add(runner);
        const lineMat = new THREE.MeshBasicMaterial({ color: COLORS.cyan, transparent: true, opacity: 0.7 });
        [-1, 1].forEach((s) => {
            const l = new THREE.Mesh(new THREE.PlaneGeometry(DIM.PLATE_W, 0.06), lineMat);
            l.rotation.x = -Math.PI / 2;
            l.position.set(0, 0.02, CORRIDOR_Z + s * 2.1);
            this.group.add(l);
        });
        // Landing pad in front of the elevator doors.
        const pad = new THREE.Mesh(new THREE.RingGeometry(1.1, 1.3, 32), new THREE.MeshBasicMaterial({ color: COLORS.cyan, transparent: true, opacity: 0.8 }));
        pad.rotation.x = -Math.PI / 2;
        pad.position.set(DIM.ELEVATOR_X - 4, 0.03, DIM.ELEVATOR_Z);
        this.group.add(pad);
        this.animated.push((t) => { pad.material.opacity = 0.45 + 0.35 * Math.sin(t * 2.5); });
    }

    buildRoom(room) {
        const p = room.position;
        const g = new THREE.Group();
        g.position.set(p.x, 0, p.z);
        const front = p.z > 0;              // front row faces the street (+z)
        const s = front ? 1 : -1;           // +s = towards the facade, -s = towards the corridor
        const locked = !room.enabled;

        // Floor tile
        const tileColor = locked ? 0x0a1426 : room.type === 'ceo_office' ? 0x2a2018 : room.category === 'common' ? 0x15325a : 0x112a4c;
        const tile = new THREE.Mesh(
            new THREE.PlaneGeometry(p.w - 0.3, p.d - 0.3),
            new THREE.MeshStandardMaterial({ color: tileColor, roughness: 0.35, metalness: 0.3, emissive: 0x22d3ee, emissiveIntensity: 0 }),
        );
        tile.rotation.x = -Math.PI / 2;
        tile.position.y = 0.02;
        g.add(tile);

        // Glass partitions: corridor wall with a door gap, left wall, and right wall for row ends.
        const corridorZ = -s * (p.d / 2);
        const seg = (p.w - DOOR_W) / 2;
        this.wall(g, seg, [-p.w / 2 + seg / 2, corridorZ], 0);
        this.wall(g, seg, [p.w / 2 - seg / 2, corridorZ], 0);
        this.wall(g, p.d, [-p.w / 2, 0], Math.PI / 2);
        const rowEnd = p.x + p.w / 2 >= DIM.PLATE_W / 2 - 0.1;
        if (rowEnd && room.type !== 'main_lobby') this.wall(g, p.d, [p.w / 2, 0], Math.PI / 2);

        const ctx = { g, p, s, room, seats: [], spots: [] };
        if (!locked) (LAYOUTS[room.type] || LAYOUTS.department).call(this, ctx);

        // Label
        const label = makeLabel(room.name, {
            sub: locked ? 'Terkunci · upgrade paket' : `${room.agent_count} Agent AI`,
            accent: locked ? '#64748b' : '#22d3ee',
            scale: p.w < 8 ? 4.6 : 5.4,
        });
        label.position.set(0, WALL_H + 1.3, 0);
        g.add(label);

        // Hit volume
        const hit = new THREE.Mesh(new THREE.BoxGeometry(p.w, WALL_H, p.d), new THREE.MeshBasicMaterial({ visible: false }));
        hit.position.y = WALL_H / 2;
        hit.userData.roomId = room.id;
        g.add(hit);
        this.hitMeshes.push(hit);

        // Door point (on the corridor side) used by the agents' walking paths.
        const door = new THREE.Vector3(p.x, 0, p.z + corridorZ - s * 0.8);

        this.group.add(g);
        this.rooms.set(room.id, {
            data: room, group: g, tile, hit, label, locked,
            seats: ctx.seats.map((seat) => ({ pos: seat.pos.clone().add(g.position), rot: seat.rot })),
            spots: ctx.spots.map((v) => v.clone().add(g.position)),
            door,
            center: new THREE.Vector3(p.x, 0, p.z),
        });
    }

    wall(g, length, [x, z], ry) {
        const w = new THREE.Mesh(new THREE.BoxGeometry(length, WALL_H, 0.06), this.glassMat);
        w.position.set(x, WALL_H / 2, z);
        w.rotation.y = ry;
        w.renderOrder = 3;
        g.add(w);
        const cap = new THREE.Mesh(new THREE.BoxGeometry(length, 0.08, 0.12), this.frameMat);
        cap.position.set(x, WALL_H, z);
        cap.rotation.y = ry;
        g.add(cap);
    }

    /** Place an object at room-local (x, z) where z is measured towards the facade. */
    put(ctx, obj, x, z, rot = 0) {
        obj.position.set(x, 0, z * ctx.s);
        obj.rotation.y = rot;
        ctx.g.add(obj);
        return obj;
    }

    /** Desk + chair + seat for one agent. The agent faces the monitor. */
    workstation(ctx, x, z, rot = 0) {
        const desk = this.put(ctx, Furniture.desk(), x, z, rot);
        const local = new THREE.Vector3(0, 0, 0.8).applyAxisAngle(new THREE.Vector3(0, 1, 0), rot);
        const chair = Furniture.chair();
        chair.position.copy(desk.position).add(local);
        chair.rotation.y = rot;
        ctx.g.add(chair);
        ctx.seats.push({ pos: chair.position.clone(), rot: rot + Math.PI });
    }

    screenTexture(draw, w = 512, h = 288) {
        const tex = canvasTexture(w, h, draw);
        return tex;
    }

    // ------------------------------------------------------------------ hover / select
    setHover(id) {
        if (this.hovered === id) return;
        this.hovered = id;
        this.refresh();
    }

    setSelected(id) {
        this.selected = id;
        this.refresh();
    }

    refresh() {
        this.rooms.forEach((r, id) => {
            const on = id === this.hovered || id === this.selected;
            r.tile.material.emissiveIntensity = id === this.selected ? 0.1 : on ? 0.07 : 0;
            // In a close-up the room's own label would fill the screen; the side panel names it instead.
            r.label.visible = id !== this.selected;
        });
    }

    roomCamera(id) {
        const r = this.rooms.get(id);
        const y = this.group.position.y;
        const wide = r.data.position.w > 8;
        return {
            position: new THREE.Vector3(r.center.x + (wide ? 0 : 1.5), y + (wide ? 11 : 9), r.center.z + (wide ? 12 : 10)),
            target: new THREE.Vector3(r.center.x, y + 0.6, r.center.z),
        };
    }

    overviewCamera() {
        const y = this.group.position.y;
        return { position: new THREE.Vector3(30, y + 30, 40), target: new THREE.Vector3(0, y, 0) };
    }

    updateScreens(office) {
        this.office = office;
        this.screens?.forEach((fn) => fn());
    }

    dispose() {
        this.unsubscribe();
        this.stage.scene.remove(this.group);
        this.group.traverse((o) => {
            o.geometry?.dispose();
            if (o.material?.map) o.material.map.dispose();
        });
    }
}

// ---------------------------------------------------------------------- room layouts
// `this` is the OfficeInterior; ctx = { g, p, s, room, seats, spots }.
const LAYOUTS = {
    main_lobby(ctx) {
        const { p } = ctx;
        // Reception desk facing the elevator (+x); receptionist sits behind it.
        this.put(ctx, Furniture.receptionDesk(4.5), 0.5, 0.5, Math.PI / 2);
        ctx.seats.push({ pos: new THREE.Vector3(-0.6, 0, 0.5 * ctx.s), rot: Math.PI / 2 });

        // Company identity wall (left partition).
        const t = this.office.tenant;
        const logo = canvasTexture(768, 288, (c, w, h) => {
            c.fillStyle = '#071428';
            c.fillRect(0, 0, w, h);
            c.fillStyle = t.color || '#22d3ee';
            c.fillRect(48, 70, 16, 150);
            c.fillStyle = '#f5f8fc';
            c.font = '700 64px "Space Grotesk", Inter, sans-serif';
            c.textBaseline = 'middle';
            c.fillText(fit(c, t.name, 640), 92, 120);
            c.fillStyle = '#8da2bf';
            c.font = '400 30px Inter, sans-serif';
            c.fillText(fit(c, `${t.industry ?? ''} · Suite ${this.office.office.number}`, 640), 92, 186);
        });
        const wall = Furniture.screen(5.6, 2.1, logo);
        wall.position.set(-p.w / 2 + 0.12, 1.9, 0);
        wall.rotation.y = Math.PI / 2;
        ctx.g.add(wall);

        // Live office activity board — real agent statuses from the API.
        const board = canvasTexture(512, 512, () => {});
        const drawBoard = () => board.userData.redraw((c, w, h) => {
            c.fillStyle = '#04101f';
            c.fillRect(0, 0, w, h);
            c.fillStyle = '#22d3ee';
            c.font = '700 30px "Space Grotesk", Inter, sans-serif';
            c.fillText('AKTIVITAS KANTOR', 28, 50);
            c.font = '500 22px Inter, sans-serif';
            this.office.agents.slice(0, 11).forEach((a, i) => {
                const y = 96 + i * 38;
                c.fillStyle = STATUS_COLORS[a.status] || '#94a3b8';
                c.beginPath();
                c.arc(36, y - 7, 7, 0, Math.PI * 2);
                c.fill();
                c.fillStyle = '#f5f8fc';
                c.fillText(fit(c, `${a.name} — ${a.job_title}`, 330), 56, y);
                c.fillStyle = '#8da2bf';
                c.fillText(a.status, 400, y);
            });
        });
        drawBoard();
        (this.screens ||= []).push(drawBoard);
        const scr = this.put(ctx, Furniture.screen(2.4, 2.4, board), 1.5, p.d / 2 - 0.6, Math.PI);
        scr.position.y = 1.7;

        [[-3.6, -3.8], [3.8, 4.2]].forEach(([x, z]) => this.put(ctx, Furniture.plant(1.1), x, z));
        ctx.spots.push(new THREE.Vector3(2.5, 0, -2 * ctx.s));
    },

    waiting_lounge(ctx) {
        const { p } = ctx;
        // A sofa's back is on its local +z, so rotation picks which way the seat faces.
        const faceIn = ctx.s > 0 ? 0 : Math.PI;
        this.put(ctx, Furniture.sofa(3.4, 0x1e3a5f), -1, 2.2, faceIn);
        this.put(ctx, Furniture.sofa(3.4, 0x1e3a5f), -1, -1.8, faceIn + Math.PI);
        this.put(ctx, Furniture.coffeeTable(1.8, 1), -1, 0.2);
        this.put(ctx, Furniture.sofa(2.2, 0x334155), 3.3, 0.2, Math.PI / 2);
        this.put(ctx, Furniture.plant(1.2), 3.6, 4.2);
        this.put(ctx, Furniture.plant(0.9), -4, 4.2);

        const t = this.office;
        const info = canvasTexture(512, 288, (c, w, h) => {
            c.fillStyle = '#04101f';
            c.fillRect(0, 0, w, h);
            c.fillStyle = '#22d3ee';
            c.font = '700 28px "Space Grotesk", Inter, sans-serif';
            c.fillText('SELAMAT DATANG', 28, 50);
            c.fillStyle = '#f5f8fc';
            c.font = '600 34px "Space Grotesk", Inter, sans-serif';
            c.fillText(fit(c, t.tenant.name, 460), 28, 112);
            c.fillStyle = '#8da2bf';
            c.font = '400 22px Inter, sans-serif';
            c.fillText(`Lantai ${t.office.floor_number} · Suite ${t.office.number}`, 28, 156);
            c.fillText(`${t.stats.agents_total} Agent AI bertugas hari ini`, 28, 192);
        });
        const scr = this.put(ctx, Furniture.screen(2.8, 1.6, info), -p.w / 2 + 0.15, 0.2, Math.PI / 2);
        scr.position.y = 1.8;
        // Guests waiting (ambient characters, not agents).
        const guests = [
            { pos: new THREE.Vector3(-1.8, 0, 2.1 * ctx.s), rot: faceIn + Math.PI },
            { pos: new THREE.Vector3(0.2, 0, -1.7 * ctx.s), rot: faceIn },
            { pos: new THREE.Vector3(3.2, 0, 0.6 * ctx.s), rot: -Math.PI / 2 },
        ];
        this.guestSpots = guests.map((g) => ({ pos: g.pos.add(ctx.g.position), rot: g.rot }));
    },

    pantry(ctx) {
        const { p } = ctx;
        this.put(ctx, Furniture.counter(6, 0.8), -0.5, p.d / 2 - 0.9, Math.PI);
        this.put(ctx, Furniture.coffeeMachine(), -2.6, p.d / 2 - 0.9).position.y = 0.98;
        this.put(ctx, Furniture.cabinet(1.2, 2.1), 3.8, p.d / 2 - 0.7, Math.PI);
        [[-2, -0.5], [1.5, -0.5]].forEach(([x, z]) => {
            const table = this.put(ctx, new THREE.Group(), x, z);
            const top = new THREE.Mesh(new THREE.CylinderGeometry(0.6, 0.6, 0.06, 24), M.white());
            top.position.y = 0.75;
            const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 0.75, 8), M.silver());
            leg.position.y = 0.37;
            table.add(top, leg);
        });
        this.put(ctx, Furniture.plant(1), 4, -3.8);
        ctx.spots.push(new THREE.Vector3(-2.6, 0, (p.d / 2 - 1.9) * ctx.s), new THREE.Vector3(-0.5, 0, (p.d / 2 - 1.9) * ctx.s), new THREE.Vector3(1.5, 0, 0.5 * ctx.s));
        this.pantrySpots = ctx.spots.map((v) => v.clone().add(ctx.g.position));
    },

    meeting_room(ctx) {
        const { p } = ctx;
        this.put(ctx, Furniture.meetingTable(5.2, 2, 10), 0.6, 0.6);
        const tex = canvasTexture(512, 288, (c, w, h) => {
            c.fillStyle = '#071a33';
            c.fillRect(0, 0, w, h);
            c.fillStyle = '#22d3ee';
            c.font = '700 26px "Space Grotesk", Inter, sans-serif';
            c.fillText('MULTI-AGENT SYNC', 28, 46);
            c.strokeStyle = '#22d3ee';
            c.lineWidth = 3;
            const nodes = [[256, 150], [120, 230], [256, 250], [392, 230]];
            nodes.slice(1).forEach(([x, y]) => { c.beginPath(); c.moveTo(256, 150); c.lineTo(x, y); c.stroke(); });
            nodes.forEach(([x, y], i) => { c.fillStyle = i === 0 ? '#22d3ee' : '#38bdf8'; c.beginPath(); c.arc(x, y, i === 0 ? 20 : 14, 0, Math.PI * 2); c.fill(); });
            c.fillStyle = '#8da2bf';
            c.font = '400 18px Inter, sans-serif';
            c.fillText('Manager → Marketing · Sales · CS', 28, 90);
        });
        const scr = this.put(ctx, Furniture.screen(3.2, 1.8, tex), -p.w / 2 + 0.15, 0.6, Math.PI / 2);
        scr.position.y = 1.7;
        // Seats around the table used by the meeting simulation.
        const seats = [];
        for (let i = 0; i < 5; i++) {
            const x = 0.6 - 2.6 + (5.2 / 5) * (i + 0.5);
            seats.push({ pos: new THREE.Vector3(x, 0, (0.6 + 1.45) * ctx.s).add(ctx.g.position), rot: ctx.s > 0 ? Math.PI : 0 });
            seats.push({ pos: new THREE.Vector3(x, 0, (0.6 - 1.45) * ctx.s).add(ctx.g.position), rot: ctx.s > 0 ? 0 : Math.PI });
        }
        this.meetingSeats = seats;
    },

    ceo_office(ctx) {
        const { p } = ctx;
        const desk = this.put(ctx, Furniture.desk(2.6, 1.2), 0, 1.8, 0);
        ctx.seats.push({ pos: new THREE.Vector3(0, 0, (1.8 + 0.8) * ctx.s), rot: ctx.s > 0 ? Math.PI : 0 });
        const chair = Furniture.chair(0x0b1f3a);
        chair.position.set(0, 0, (1.8 + 0.8) * ctx.s);
        if (ctx.s < 0) chair.rotation.y = Math.PI;
        ctx.g.add(chair);
        if (ctx.s < 0) desk.rotation.y = Math.PI;
        this.put(ctx, Furniture.sofa(2.6, 0x334155), 0, -2.6, 0);
        this.put(ctx, Furniture.cabinet(1, 1.6), -p.w / 2 + 0.7, 3.8, Math.PI / 2);
        this.put(ctx, Furniture.plant(1.1), p.w / 2 - 0.8, 4.4);

        const stats = this.office.stats;
        const tex = canvasTexture(512, 288, (c, w, h) => {
            c.fillStyle = '#04101f';
            c.fillRect(0, 0, w, h);
            c.fillStyle = '#22d3ee';
            c.font = '700 24px "Space Grotesk", Inter, sans-serif';
            c.fillText('CEO DASHBOARD', 24, 40);
            const items = [['Agent', stats.agents_total], ['Working', stats.by_status.working], ['Task', stats.tasks_open], ['Error', stats.by_status.error]];
            items.forEach(([k, v], i) => {
                const x = 24 + i * 120;
                c.fillStyle = 'rgba(34,211,238,.1)';
                roundRect(c, x, 70, 108, 110, 12);
                c.fill();
                c.fillStyle = '#f5f8fc';
                c.font = '700 44px "Space Grotesk", Inter, sans-serif';
                c.fillText(String(v ?? 0), x + 14, 136);
                c.fillStyle = '#8da2bf';
                c.font = '400 18px Inter, sans-serif';
                c.fillText(k, x + 14, 166);
            });
            c.fillStyle = '#8da2bf';
            c.fillText('Data langsung dari database tenant', 24, 240);
        });
        const scr = this.put(ctx, Furniture.screen(2.6, 1.5, tex), -p.w / 2 + 0.15, 0.4, Math.PI / 2);
        scr.position.y = 1.7;
    },

    it_server(ctx) {
        const { p } = ctx;
        for (let i = 0; i < 4; i++) {
            const rack = this.put(ctx, Furniture.serverRack(), -p.w / 2 + 1.1 + i * 1.1, p.d / 2 - 1.2, Math.PI);
            const leds = rack.userData.leds;
            this.animated.push((t) => leds.forEach((l, j) => { l.visible = Math.sin(t * (3 + j) + i * 1.7) > -0.3; }));
        }
        this.workstation(ctx, -1.4, -1.5, 0);
        this.workstation(ctx, 1.6, -1.5, 0);
        const scr = this.put(ctx, Furniture.screen(2.2, 1.2), p.w / 2 - 0.2, 0.8, -Math.PI / 2);
        scr.position.y = 1.8;
    },

    department(ctx) {
        const { p } = ctx;
        // 2 columns x 3 rows of workstations facing the facade.
        [-1.5, 1.5].forEach((x) => [2.6, 0, -2.6].forEach((z) => this.workstation(ctx, x, z, ctx.s > 0 ? 0 : Math.PI)));
        this.put(ctx, Furniture.whiteboard(2.4), 0, p.d / 2 - 0.4, Math.PI);
        this.put(ctx, Furniture.plant(0.9), p.w / 2 - 0.6, -p.d / 2 + 1.2);
    },
};
