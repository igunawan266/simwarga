import * as THREE from 'three';

// Shared geometries/materials keep draw-call cost and GPU memory low.
const geo = {
    box: new THREE.BoxGeometry(1, 1, 1),
    cyl: new THREE.CylinderGeometry(0.5, 0.5, 1, 16),
    sphere: new THREE.SphereGeometry(0.5, 16, 12),
    capsule: new THREE.CapsuleGeometry(0.3, 0.7, 4, 10),
};
// Marked so that disposing one building/interior never frees resources other scenes still use.
Object.values(geo).forEach((g) => { g.userData.shared = true; });
const matCache = new Map();
function mat(color, { metal = 0.2, rough = 0.5, emissive = 0x000000, ei = 1, basic = false, opacity = 1 } = {}) {
    const key = [color, metal, rough, emissive, ei, basic, opacity].join('|');
    if (!matCache.has(key)) {
        const params = { color, transparent: opacity < 1, opacity };
        matCache.set(key, basic
            ? new THREE.MeshBasicMaterial(params)
            : new THREE.MeshStandardMaterial({ ...params, metalness: metal, roughness: rough, emissive, emissiveIntensity: ei }));
        matCache.get(key).userData.shared = true;
    }
    return matCache.get(key);
}

export const M = {
    white: () => mat(0xeef2f7, { rough: 0.45 }),
    silver: () => mat(0xc0c8d4, { metal: 0.8, rough: 0.25 }),
    dark: () => mat(0x1b2537, { rough: 0.6 }),
    navy: () => mat(0x12305a, { rough: 0.5 }),
    wood: () => mat(0x8b6a4f, { rough: 0.7 }),
    screen: () => mat(0x67e8f9, { basic: true }),
    cyanGlow: () => mat(0x22d3ee, { basic: true }),
    leaf: () => mat(0x2f8f6b, { rough: 0.8 }),
};

function part(g, geometry, material, [sx, sy, sz], [x, y, z], rot = null) {
    const m = new THREE.Mesh(geometry, material);
    m.scale.set(sx, sy, sz);
    m.position.set(x, y, z);
    if (rot) m.rotation.set(...rot);
    g.add(m);
    return m;
}

/** Furniture factory. Every item is a Group whose origin sits on the floor. */
export const Furniture = {
    desk(w = 2.2, d = 1.1, { monitor = true } = {}) {
        const g = new THREE.Group();
        part(g, geo.box, M.white(), [w, 0.08, d], [0, 0.76, 0]);
        part(g, geo.box, M.silver(), [0.06, 0.74, d * 0.9], [-w / 2 + 0.1, 0.37, 0]);
        part(g, geo.box, M.silver(), [0.06, 0.74, d * 0.9], [w / 2 - 0.1, 0.37, 0]);
        if (monitor) {
            part(g, geo.box, M.dark(), [0.9, 0.55, 0.05], [0, 1.15, -d / 2 + 0.2]);
            part(g, geo.box, M.screen(), [0.82, 0.47, 0.01], [0, 1.15, -d / 2 + 0.23]);
            part(g, geo.box, M.silver(), [0.06, 0.3, 0.06], [0, 0.92, -d / 2 + 0.18]);
        }
        return g;
    },

    chair(color = 0x1e3a5f) {
        const g = new THREE.Group();
        part(g, geo.box, mat(color, { rough: 0.6 }), [0.55, 0.1, 0.55], [0, 0.48, 0]);
        part(g, geo.box, mat(color, { rough: 0.6 }), [0.55, 0.6, 0.08], [0, 0.8, 0.26]);
        part(g, geo.cyl, M.silver(), [0.08, 0.45, 0.08], [0, 0.22, 0]);
        part(g, geo.cyl, M.dark(), [0.5, 0.04, 0.5], [0, 0.02, 0]);
        return g;
    },

    sofa(w = 3, color = 0x1e3a5f) {
        const g = new THREE.Group();
        const m = mat(color, { rough: 0.85 });
        part(g, geo.box, m, [w, 0.45, 1], [0, 0.32, 0]);
        part(g, geo.box, m, [w, 0.7, 0.25], [0, 0.7, 0.4]);
        part(g, geo.box, m, [0.25, 0.6, 1], [-w / 2 + 0.12, 0.5, 0]);
        part(g, geo.box, m, [0.25, 0.6, 1], [w / 2 - 0.12, 0.5, 0]);
        return g;
    },

    coffeeTable(w = 1.6, d = 0.9) {
        const g = new THREE.Group();
        part(g, geo.box, mat(0xdbe4ee, { metal: 0.1, rough: 0.15, opacity: 0.75 }), [w, 0.05, d], [0, 0.42, 0]);
        part(g, geo.box, M.silver(), [w * 0.9, 0.04, 0.06], [0, 0.2, 0]);
        part(g, geo.cyl, M.silver(), [0.06, 0.42, 0.06], [-w / 2 + 0.1, 0.21, 0]);
        part(g, geo.cyl, M.silver(), [0.06, 0.42, 0.06], [w / 2 - 0.1, 0.21, 0]);
        return g;
    },

    receptionDesk(w = 6, color = 0x0b1f3a) {
        const g = new THREE.Group();
        part(g, geo.box, mat(color, { metal: 0.5, rough: 0.3 }), [w, 1.1, 1], [0, 0.55, 0]);
        part(g, geo.box, M.white(), [w + 0.2, 0.08, 1.2], [0, 1.14, 0]);
        part(g, geo.box, M.cyanGlow(), [w, 0.06, 0.02], [0, 0.4, 0.51]);
        return g;
    },

    meetingTable(w = 5, d = 2, seats = 8) {
        const g = new THREE.Group();
        part(g, geo.box, M.white(), [w, 0.08, d], [0, 0.76, 0]);
        part(g, geo.box, M.silver(), [w * 0.7, 0.72, 0.3], [0, 0.36, 0]);
        part(g, geo.box, M.cyanGlow(), [w * 0.6, 0.01, 0.08], [0, 0.81, 0]);
        const perSide = Math.ceil(seats / 2);
        for (let i = 0; i < perSide; i++) {
            const x = -w / 2 + (w / perSide) * (i + 0.5);
            const c1 = Furniture.chair();
            c1.position.set(x, 0, d / 2 + 0.45);
            g.add(c1);
            const c2 = Furniture.chair();
            c2.position.set(x, 0, -d / 2 - 0.45);
            c2.rotation.y = Math.PI;
            g.add(c2);
        }
        return g;
    },

    counter(w = 4, d = 0.8) {
        const g = new THREE.Group();
        part(g, geo.box, M.white(), [w, 0.95, d], [0, 0.47, 0]);
        part(g, geo.box, M.dark(), [w + 0.05, 0.05, d + 0.05], [0, 0.97, 0]);
        part(g, geo.box, M.navy(), [w, 0.9, 0.4], [0, 2.1, -d / 2 + 0.2]); // wall cabinet
        return g;
    },

    coffeeMachine() {
        const g = new THREE.Group();
        part(g, geo.box, M.silver(), [0.55, 0.7, 0.5], [0, 0.35, 0]);
        part(g, geo.box, M.dark(), [0.45, 0.2, 0.05], [0, 0.5, 0.26]);
        part(g, geo.box, mat(0xfbbf24, { basic: true }), [0.08, 0.08, 0.02], [0.15, 0.62, 0.27]);
        return g;
    },

    cabinet(w = 1.2, h = 2) {
        const g = new THREE.Group();
        part(g, geo.box, M.white(), [w, h, 0.5], [0, h / 2, 0]);
        part(g, geo.box, M.silver(), [0.04, h * 0.9, 0.02], [0, h / 2, 0.26]);
        return g;
    },

    plant(h = 1) {
        const g = new THREE.Group();
        part(g, geo.cyl, M.white(), [0.5, 0.5, 0.5], [0, 0.25, 0]);
        part(g, geo.sphere, M.leaf(), [0.9 * h, 1.1 * h, 0.9 * h], [0, 0.5 + 0.5 * h, 0]);
        return g;
    },

    /** Wall screen; pass a texture to show live data. */
    screen(w = 3, h = 1.7, texture = null) {
        const g = new THREE.Group();
        part(g, geo.box, M.dark(), [w + 0.15, h + 0.15, 0.08], [0, 0, 0]);
        const face = new THREE.Mesh(new THREE.PlaneGeometry(w, h), texture ? new THREE.MeshBasicMaterial({ map: texture }) : M.screen());
        face.position.z = 0.045;
        g.add(face);
        g.userData.face = face;
        return g;
    },

    serverRack() {
        const g = new THREE.Group();
        part(g, geo.box, mat(0x0f172a, { metal: 0.6, rough: 0.4 }), [0.8, 2.2, 1], [0, 1.1, 0]);
        const leds = [];
        for (let i = 0; i < 8; i++) {
            leds.push(part(g, geo.box, mat(i % 3 === 0 ? 0x34d399 : 0x22d3ee, { basic: true }), [0.5, 0.03, 0.01], [0, 0.35 + i * 0.24, 0.51]));
        }
        g.userData.leds = leds;
        return g;
    },

    whiteboard(w = 2.4) {
        const g = new THREE.Group();
        part(g, geo.box, M.white(), [w, 1.2, 0.05], [0, 1.6, 0]);
        part(g, geo.box, M.silver(), [w + 0.1, 0.05, 0.1], [0, 0.98, 0.03]);
        return g;
    },

    /** Stylised AI robot used for the lobby receptionist (static mesh, not instanced). */
    robot(visor = 0x22d3ee) {
        const g = new THREE.Group();
        part(g, geo.capsule, M.white(), [1.1, 1.1, 1.1], [0, 0.95, 0]);
        part(g, geo.sphere, M.white(), [0.62, 0.58, 0.6], [0, 1.85, 0]);
        part(g, geo.box, mat(visor, { basic: true }), [0.48, 0.14, 0.1], [0, 1.88, 0.27]);
        part(g, geo.cyl, M.silver(), [0.03, 0.25, 0.03], [0, 2.25, 0]);
        part(g, geo.sphere, mat(visor, { basic: true }), [0.09, 0.09, 0.09], [0, 2.4, 0]);
        return g;
    },
};
