import * as THREE from 'three';
import { DIM, COLORS, floorY, prefersReducedMotion } from '../core/config.js';

const CAB_W = 3.2;
const CAB_H = 3.3;
const CAB_D = 3.4;
const SECONDS_PER_FLOOR = 0.42;

/**
 * Exterior glass elevator. Its doors face the building (-x) and open onto the
 * office floor. While nobody is riding it wanders between floors to keep the
 * building alive; a ride request cancels that immediately.
 */
export class Elevator {
    constructor(stage, building) {
        this.stage = stage;
        this.topFloor = building.topFloor;
        this.floor = 0;
        this.busy = false;
        this.group = new THREE.Group();
        this.group.position.set(DIM.ELEVATOR_X, 0, DIM.ELEVATOR_Z);

        this.buildShaft(building.height);
        this.buildCab();
        stage.scene.add(this.group);
        stage.onUpdate((dt, t) => this.update(dt, t));
        this.scheduleIdle();
    }

    buildShaft(height) {
        const h = height + 2;
        const frameMat = new THREE.MeshStandardMaterial({ color: COLORS.silver, metalness: 0.85, roughness: 0.25 });
        const railGeo = new THREE.BoxGeometry(0.22, h, 0.22);
        [[-1, -1], [1, -1], [-1, 1], [1, 1]].forEach(([sx, sz]) => {
            const rail = new THREE.Mesh(railGeo, frameMat);
            rail.position.set(sx * (CAB_W / 2 + 0.35), h / 2, sz * (CAB_D / 2 + 0.35));
            this.group.add(rail);
        });
        const glass = new THREE.Mesh(
            new THREE.BoxGeometry(CAB_W + 0.7, h, CAB_D + 0.7),
            new THREE.MeshStandardMaterial({ color: 0x9fd8ff, metalness: 0.9, roughness: 0.05, transparent: true, opacity: 0.08, depthWrite: false }),
        );
        glass.position.y = h / 2;
        this.group.add(glass);

        // Landing markers at every floor.
        const markGeo = new THREE.BoxGeometry(0.12, 0.12, CAB_D + 0.7);
        const markMat = new THREE.MeshBasicMaterial({ color: COLORS.cyan, transparent: true, opacity: 0.5 });
        for (let n = 0; n <= this.topFloor; n++) {
            const m = new THREE.Mesh(markGeo, markMat);
            m.position.set(-(CAB_W / 2 + 0.36), floorY(n) + DIM.SLAB, 0);
            this.group.add(m);
        }

        const cable = new THREE.Mesh(new THREE.CylinderGeometry(0.03, 0.03, 1, 6), new THREE.MeshBasicMaterial({ color: 0x64748b }));
        this.cable = cable;
        this.cableTop = h;
        this.group.add(cable);
    }

    buildCab() {
        const cab = new THREE.Group();
        const frameMat = new THREE.MeshStandardMaterial({ color: COLORS.white, metalness: 0.6, roughness: 0.25 });
        const floorM = new THREE.Mesh(new THREE.BoxGeometry(CAB_W, 0.2, CAB_D), frameMat);
        floorM.position.y = 0.1;
        const roof = new THREE.Mesh(new THREE.BoxGeometry(CAB_W, 0.25, CAB_D), frameMat);
        roof.position.y = CAB_H;
        cab.add(floorM, roof);

        const light = new THREE.Mesh(new THREE.PlaneGeometry(CAB_W - 0.6, CAB_D - 0.6), new THREE.MeshBasicMaterial({ color: 0xcffafe, side: THREE.DoubleSide }));
        light.rotation.x = Math.PI / 2;
        light.position.y = CAB_H - 0.14;
        cab.add(light);

        const glassMat = new THREE.MeshStandardMaterial({ color: 0xbae6fd, metalness: 0.7, roughness: 0.05, transparent: true, opacity: 0.25, side: THREE.DoubleSide, depthWrite: false });
        [[CAB_W / 2, 0, Math.PI / 2, CAB_D], [0, CAB_D / 2, 0, CAB_W], [0, -CAB_D / 2, 0, CAB_W]].forEach(([x, z, ry, w]) => {
            const p = new THREE.Mesh(new THREE.PlaneGeometry(w, CAB_H - 0.2), glassMat);
            p.position.set(x, CAB_H / 2, z);
            p.rotation.y = ry;
            cab.add(p);
        });

        const edge = new THREE.LineSegments(
            new THREE.EdgesGeometry(new THREE.BoxGeometry(CAB_W, CAB_H, CAB_D)),
            new THREE.LineBasicMaterial({ color: COLORS.cyan }),
        );
        edge.position.y = CAB_H / 2;
        cab.add(edge);

        // Sliding doors on the building side.
        const doorMat = new THREE.MeshStandardMaterial({ color: COLORS.silver, metalness: 0.9, roughness: 0.2 });
        const doorGeo = new THREE.BoxGeometry(0.08, CAB_H - 0.3, CAB_D / 2);
        this.doorL = new THREE.Mesh(doorGeo, doorMat);
        this.doorR = new THREE.Mesh(doorGeo, doorMat);
        this.doorL.position.set(-CAB_W / 2, CAB_H / 2, -CAB_D / 4);
        this.doorR.position.set(-CAB_W / 2, CAB_H / 2, CAB_D / 4);
        cab.add(this.doorL, this.doorR);

        this.indicator = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.25, 0.9), new THREE.MeshBasicMaterial({ color: COLORS.cyan }));
        this.indicator.position.set(-CAB_W / 2 - 0.05, CAB_H + 0.3, 0);
        cab.add(this.indicator);

        this.cab = cab;
        cab.position.y = floorY(0) + DIM.SLAB - 0.2;
        this.group.add(cab);
    }

    /** World position just outside the doors (where a passenger steps out). */
    landingPoint(n) {
        return new THREE.Vector3(DIM.ELEVATOR_X - CAB_W / 2 - 2, floorY(n) + DIM.SLAB, DIM.ELEVATOR_Z);
    }

    moveTo(n, { fast = false } = {}) {
        window.gsap.killTweensOf(this.cab.position);
        const distance = Math.abs(n - this.floor);
        const duration = prefersReducedMotion ? 0.01 : Math.max(1.2, distance * SECONDS_PER_FLOOR) * (fast ? 0.6 : 1);
        this.floor = n;
        return new Promise((resolve) => {
            window.gsap.to(this.cab.position, {
                y: floorY(n) + DIM.SLAB - 0.2,
                duration,
                ease: 'power2.inOut',
                onUpdate: () => this.stage.wake(),
                onComplete: resolve,
            });
        });
    }

    openDoors() {
        return this.slideDoors(CAB_D / 2 - 0.05);
    }

    closeDoors() {
        return this.slideDoors(0);
    }

    slideDoors(offset) {
        const d = prefersReducedMotion ? 0.01 : 0.9;
        return new Promise((resolve) => {
            window.gsap.to(this.doorL.position, { z: -CAB_D / 4 - offset, duration: d, ease: 'power2.inOut' });
            window.gsap.to(this.doorR.position, { z: CAB_D / 4 + offset, duration: d, ease: 'power2.inOut', onComplete: resolve });
        });
    }

    /** Take control for a passenger ride (stops the idle wandering). */
    reserve() {
        this.busy = true;
        clearTimeout(this.idleTimer);
    }

    release() {
        this.busy = false;
        this.scheduleIdle();
    }

    scheduleIdle() {
        clearTimeout(this.idleTimer);
        this.idleTimer = setTimeout(async () => {
            if (this.busy || document.hidden) return this.scheduleIdle();
            const next = Math.floor(Math.random() * (this.topFloor + 1));
            await this.moveTo(next);
            if (this.busy) return;
            await this.openDoors();
            await new Promise((r) => setTimeout(r, 900));
            if (!this.busy) await this.closeDoors();
            this.scheduleIdle();
        }, 3500 + Math.random() * 4000);
    }

    update(dt, t) {
        const top = this.cableTop;
        const cabTop = this.cab.position.y + CAB_H;
        const len = Math.max(0.01, top - cabTop);
        this.cable.scale.y = len;
        this.cable.position.y = cabTop + len / 2;
        this.indicator.material.color.setHSL(0.52, 0.9, 0.5 + 0.15 * Math.sin(t * 4));
    }
}
