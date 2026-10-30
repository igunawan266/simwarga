import * as THREE from 'three';
import { STATUS_COLORS, prefersReducedMotion } from '../core/config.js';

const WALK_SPEED = 1.5; // m/s
const CORRIDOR_Z = -0.25;
const UP = new THREE.Vector3(0, 1, 0);

/**
 * All AI agents (and the ambient lounge guests) drawn with instanced meshes:
 * one draw call each for bodies, heads and visors regardless of headcount.
 * Visor colour = live agent status. Behaviour is a small per-agent state machine.
 */
export class AgentCrowd {
    constructor(stage, interior, agents) {
        this.stage = stage;
        this.interior = interior;
        this.group = new THREE.Group();
        this.group.position.copy(interior.group.position);
        this.actors = [];

        const guests = interior.guestSpots || [];
        const total = agents.length + guests.length;
        const bodyMat = new THREE.MeshStandardMaterial({ color: 0xf1f5f9, roughness: 0.35, metalness: 0.3 });
        this.bodies = new THREE.InstancedMesh(new THREE.CapsuleGeometry(0.3, 0.62, 4, 12), bodyMat, total);
        this.heads = new THREE.InstancedMesh(new THREE.SphereGeometry(0.28, 16, 12), bodyMat, total);
        this.visors = new THREE.InstancedMesh(new THREE.BoxGeometry(0.4, 0.11, 0.08), new THREE.MeshBasicMaterial({ color: 0xffffff }), total);
        this.bodies.userData.isAgents = true;
        [this.bodies, this.heads, this.visors].forEach((m) => {
            m.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
            this.group.add(m);
        });

        // Assign each agent a seat in its room (overflow agents stand near the door).
        const used = new Map();
        agents.forEach((a, i) => {
            const room = interior.rooms.get(a.room_id);
            let home;
            if (room && room.seats.length) {
                const k = used.get(a.room_id) || 0;
                used.set(a.room_id, k + 1);
                home = k < room.seats.length ? room.seats[k] : { pos: room.center.clone().add(new THREE.Vector3((k % 3) - 1, 0, 1.2)), rot: 0 };
            } else if (room) {
                home = { pos: room.center.clone().add(new THREE.Vector3(Math.random() * 2 - 1, 0, Math.random() * 2 - 1)), rot: Math.random() * 6 };
            } else {
                home = { pos: new THREE.Vector3(14 - i, 0, CORRIDOR_Z), rot: 0 }; // unassigned agent waits in the corridor
            }
            const seated = room?.seats.includes(home);
            this.actors.push({
                index: i, agent: a, room, home, seated,
                pos: home.pos.clone(), rot: home.rot, path: [], wait: 2 + Math.random() * 8, mode: 'home', phase: Math.random() * 10,
            });
            this.setColor(i, a.status);
        });
        guests.forEach((g, j) => {
            const i = agents.length + j;
            this.actors.push({ index: i, guest: true, home: g, seated: true, pos: g.pos.clone(), rot: g.rot, path: [], wait: Infinity, mode: 'home', phase: j });
            this.visors.setColorAt(i, new THREE.Color(0xfbbf24));
        });
        this.visors.instanceColor.needsUpdate = true;

        stage.scene.add(this.group);
        this.dummy = new THREE.Object3D();
        this.unsubscribe = stage.onUpdate((dt, t) => this.update(dt, t));
    }

    setColor(i, status) {
        this.visors.setColorAt(i, new THREE.Color(STATUS_COLORS[status] || STATUS_COLORS.idle));
        if (this.visors.instanceColor) this.visors.instanceColor.needsUpdate = true;
    }

    /** Apply live statuses from the server (unknown ids are ignored). */
    updateStatuses(list) {
        const byId = new Map(list.map((a) => [a.id, a.status]));
        this.actors.forEach((a) => {
            if (a.guest || !byId.has(a.agent.id) || byId.get(a.agent.id) === a.agent.status) return;
            a.agent.status = byId.get(a.agent.id);
            this.setColor(a.index, a.agent.status);
        });
    }

    actorFromHit(hit) {
        if (hit.object !== this.bodies && hit.object !== this.heads) return null;
        const a = this.actors[hit.instanceId];
        return a && !a.guest ? a : null;
    }

    get hitTargets() {
        return [this.bodies, this.heads];
    }

    /** Route via the corridor: from -> own door -> corridor -> target door -> target. */
    route(actor, to, targetDoor) {
        const pts = [];
        const fromDoor = actor.room?.door;
        if (fromDoor) pts.push(fromDoor.clone(), new THREE.Vector3(fromDoor.x, 0, CORRIDOR_Z));
        if (targetDoor) pts.push(new THREE.Vector3(targetDoor.x, 0, CORRIDOR_Z), targetDoor.clone());
        pts.push(to.clone());
        return pts;
    }

    /** Visual-only meeting simulation: the busiest agents walk to the meeting room and back. */
    simulateMeeting(durationSec = 14) {
        const seats = this.interior.meetingSeats || [];
        const meetingRoom = [...this.interior.rooms.values()].find((r) => r.data.type === 'meeting_room');
        if (!seats.length || !meetingRoom) return 0;
        const attendees = this.actors.filter((a) => !a.guest && a.agent.status !== 'offline' && a.agent.status !== 'error').slice(0, seats.length);
        attendees.forEach((a, k) => {
            a.mode = 'meeting';
            a.path = this.route(a, seats[k].pos, meetingRoom.door);
            a.targetRot = seats[k].rot;
            a.wait = durationSec;
            a.away = meetingRoom;
        });
        return attendees.length;
    }

    update(dt, t) {
        const d = this.dummy;
        for (const a of this.actors) {
            this.step(a, dt, t);
            const status = a.agent?.status;
            const walking = a.path.length > 0;
            let bob = 0;
            let sway = 0;
            if (!prefersReducedMotion) {
                if (walking) bob = Math.abs(Math.sin(t * 9 + a.phase)) * 0.07;
                else if (status === 'working') bob = Math.sin(t * 6 + a.phase) * 0.015;
                else if (status === 'error') sway = Math.sin(t * 30) * 0.03;
                else if (status !== 'offline') sway = Math.sin(t * 0.8 + a.phase) * 0.15;
            }
            const sit = !walking && a.seated && a.mode !== 'standing' ? -0.25 : 0;
            const y = a.pos.y + 0.62 + bob + sit;

            d.position.set(a.pos.x + sway * 0.1, y, a.pos.z);
            d.rotation.set(0, a.rot, 0);
            d.updateMatrix();
            this.bodies.setMatrixAt(a.index, d.matrix);

            d.position.y = y + 0.66;
            d.rotation.set(0, a.rot + sway, 0);
            d.updateMatrix();
            this.heads.setMatrixAt(a.index, d.matrix);

            const fwd = new THREE.Vector3(0, 0, 0.23).applyAxisAngle(UP, a.rot + sway);
            d.position.set(a.pos.x + fwd.x, y + 0.68, a.pos.z + fwd.z);
            d.updateMatrix();
            this.visors.setMatrixAt(a.index, d.matrix);

            if (status === 'error' || status === 'waiting') {
                const on = Math.sin(t * (status === 'error' ? 8 : 3) + a.phase) > 0;
                this.visors.setColorAt(a.index, new THREE.Color(on ? STATUS_COLORS[status] : 0x1e293b));
                this.visors.instanceColor.needsUpdate = true;
            }
        }
        this.bodies.instanceMatrix.needsUpdate = true;
        this.heads.instanceMatrix.needsUpdate = true;
        this.visors.instanceMatrix.needsUpdate = true;
    }

    step(a, dt) {
        if (a.path.length) {
            const target = a.path[0];
            const delta = target.clone().sub(a.pos);
            delta.y = 0;
            const dist = delta.length();
            if (dist < 0.05) {
                a.path.shift();
                if (!a.path.length && a.targetRot !== undefined) a.rot = a.targetRot;
                return;
            }
            const move = Math.min(dist, WALK_SPEED * dt);
            a.pos.addScaledVector(delta.normalize(), move);
            const want = Math.atan2(delta.x, delta.z);
            a.rot += shortestAngle(a.rot, want) * Math.min(1, dt * 10);
            this.stage.wake();
            return;
        }
        if (a.guest || prefersReducedMotion) return;

        a.wait -= dt;
        if (a.wait > 0) return;

        const status = a.agent.status;
        if (a.mode !== 'home') {
            // Go back to the desk.
            a.path = this.route({ room: a.away }, a.home.pos, a.room?.door);
            a.targetRot = a.home.rot;
            a.mode = 'home';
            a.away = null;
            a.wait = 10 + Math.random() * 20;
            return;
        }
        // Idle agents take a coffee break now and then; online agents occasionally stretch.
        const pantry = this.interior.pantrySpots;
        const pantryRoom = [...this.interior.rooms.values()].find((r) => r.data.type === 'pantry');
        if (status === 'idle' && pantry?.length && pantryRoom) {
            const spot = pantry[Math.floor(Math.random() * pantry.length)];
            a.path = this.route(a, spot, pantryRoom.door);
            a.targetRot = Math.PI;
            a.mode = 'break';
            a.away = pantryRoom;
            a.wait = 6 + Math.random() * 6;
        } else {
            a.wait = 8 + Math.random() * 12;
        }
    }

    /** End a running meeting simulation early: attendees walk back to their desks. */
    endMeeting() {
        this.actors.filter((a) => a.mode === 'meeting').forEach((a) => { a.wait = 0; });
    }

    dispose() {
        this.unsubscribe();
        this.stage.scene.remove(this.group);
        [this.bodies, this.heads, this.visors].forEach((m) => { m.geometry.dispose(); m.dispose(); });
    }
}

function shortestAngle(from, to) {
    let d = (to - from) % (Math.PI * 2);
    if (d > Math.PI) d -= Math.PI * 2;
    if (d < -Math.PI) d += Math.PI * 2;
    return d;
}
