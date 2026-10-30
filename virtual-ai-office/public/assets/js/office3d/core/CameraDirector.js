import * as THREE from 'three';
import { prefersReducedMotion } from './config.js';

/** GSAP-driven camera moves. Orbit controls are disabled while a move is in flight. */
export class CameraDirector {
    constructor(stage) {
        this.stage = stage;
        this.camera = stage.camera;
        this.controls = stage.controls;
        this.tl = null;
    }

    /**
     * @param {THREE.Vector3|number[]} position
     * @param {THREE.Vector3|number[]} target
     */
    flyTo(position, target, { duration = 2, ease = 'power3.inOut', limits } = {}) {
        const pos = toVec(position);
        const tgt = toVec(target);
        this.cancel();
        this.controls.enabled = false;
        // Relax limits during the move so the path is never clamped.
        this.controls.minDistance = 0;
        this.controls.maxDistance = Infinity;

        return new Promise((resolve) => {
            const d = prefersReducedMotion ? 0.01 : duration;
            this.tl = window.gsap.timeline({
                onUpdate: () => this.stage.wake(),
                onComplete: () => {
                    this.controls.enabled = true;
                    if (limits) this.setLimits(limits);
                    this.tl = null;
                    resolve();
                },
            });
            this.tl.to(this.camera.position, { x: pos.x, y: pos.y, z: pos.z, duration: d, ease }, 0);
            this.tl.to(this.controls.target, { x: tgt.x, y: tgt.y, z: tgt.z, duration: d, ease }, 0);
        });
    }

    /** Keep the camera at a fixed offset from a moving object (elevator ride). */
    follow(object, offset, lookOffset) {
        this.controls.enabled = false;
        const tmp = new THREE.Vector3();
        const stop = this.stage.onUpdate(() => {
            this.stage.wake();
            object.getWorldPosition(tmp);
            this.camera.position.lerp(tmp.clone().add(offset), 0.12);
            this.controls.target.lerp(tmp.clone().add(lookOffset), 0.12);
        });
        return () => { stop(); this.controls.enabled = true; };
    }

    setLimits({ min = 10, max = 320, maxPolar = Math.PI * 0.48, pan = false } = {}) {
        this.controls.minDistance = min;
        this.controls.maxDistance = max;
        this.controls.maxPolarAngle = maxPolar;
        this.controls.enablePan = pan;
    }

    cancel() {
        if (this.tl) {
            this.tl.kill();
            this.tl = null;
            this.controls.enabled = true;
        }
    }
}

function toVec(v) {
    return v instanceof THREE.Vector3 ? v : new THREE.Vector3(...v);
}
