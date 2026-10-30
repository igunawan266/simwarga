// Shared dimensions (meters) and palette for every 3D component.
export const DIM = {
    W: 42,            // building footprint width (x)
    D: 30,            // building footprint depth (z)
    PLATE_W: 40,      // usable office floor plate
    PLATE_D: 28,
    LOBBY_H: 6,
    FLOOR_H: 4,
    SLAB: 0.35,
    ELEVATOR_X: 23.4,
    ELEVATOR_Z: 7.5,
};

export const COLORS = {
    navy950: 0x040b17,
    navy900: 0x071428,
    navy800: 0x0b1f3a,
    navy700: 0x12305a,
    cyan: 0x22d3ee,
    sky: 0x38bdf8,
    silver: 0xc0c8d4,
    white: 0xf5f8fc,
    warm: 0xffd8a8,
};

export const STATUS_COLORS = {
    online: '#22d3ee',
    working: '#38bdf8',
    idle: '#94a3b8',
    waiting: '#fbbf24',
    error: '#f87171',
    offline: '#475569',
};

/** Bottom (walking surface) y of a floor. */
export function floorY(n) {
    return n === 0 ? 0 : DIM.LOBBY_H + (n - 1) * DIM.FLOOR_H;
}

export function floorHeight(n) {
    return n === 0 ? DIM.LOBBY_H : DIM.FLOOR_H;
}

export const isLowPower = (() => {
    const mobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
    return mobile || (navigator.hardwareConcurrency || 8) <= 4;
})();

export const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
