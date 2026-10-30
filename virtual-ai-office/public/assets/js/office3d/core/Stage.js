import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { COLORS, isLowPower } from './config.js';

/**
 * Renderer, scene, camera, controls and the frame loop.
 * The loop stops completely while the tab is hidden and runs at a reduced
 * rate after the user has been idle for a while, to keep laptops cool.
 */
export class Stage {
    constructor(canvas) {
        this.canvas = canvas;
        this.renderer = new THREE.WebGLRenderer({ canvas, antialias: !isLowPower, powerPreference: 'high-performance' });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, isLowPower ? 1.25 : 1.75));
        this.renderer.outputColorSpace = THREE.SRGBColorSpace;
        this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
        this.renderer.toneMappingExposure = 0.9;

        this.scene = new THREE.Scene();
        this.scene.fog = new THREE.FogExp2(COLORS.navy900, 0.0042);

        const pmrem = new THREE.PMREMGenerator(this.renderer);
        this.scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
        this.scene.environmentIntensity = 0.45;
        pmrem.dispose();

        this.camera = new THREE.PerspectiveCamera(42, 1, 0.5, 1500);
        this.camera.position.set(160, 120, 190);

        this.controls = new OrbitControls(this.camera, canvas);
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.06;
        this.controls.enablePan = false;
        this.controls.minDistance = 40;
        this.controls.maxDistance = 320;
        this.controls.maxPolarAngle = Math.PI * 0.48;
        this.controls.target.set(0, 30, 0);
        this.controls.addEventListener('start', () => this.wake());

        this.addSky();
        this.addLights();

        this.clock = new THREE.Clock();
        this.updaters = new Set();
        this.lastInteraction = performance.now();
        this.frameSkip = 0;

        window.addEventListener('resize', () => this.resize());
        ['pointermove', 'pointerdown', 'wheel', 'keydown'].forEach((ev) => window.addEventListener(ev, () => this.wake(), { passive: true }));
        document.addEventListener('visibilitychange', () => (document.hidden ? this.stop() : this.start()));
        this.resize();
    }

    addSky() {
        const geo = new THREE.SphereGeometry(900, 32, 16);
        const mat = new THREE.ShaderMaterial({
            side: THREE.BackSide,
            depthWrite: false,
            fog: false,
            uniforms: {
                top: { value: new THREE.Color(0x020814) },
                mid: { value: new THREE.Color(0x0b1f3a) },
                horizon: { value: new THREE.Color(0x1d4f7a) },
            },
            vertexShader: 'varying vec3 vP; void main(){ vP = normalize(position); gl_Position = projectionMatrix * modelViewMatrix * vec4(position,1.0); }',
            fragmentShader: `uniform vec3 top; uniform vec3 mid; uniform vec3 horizon; varying vec3 vP;
                void main(){ float h = vP.y; vec3 c = h > 0.12 ? mix(mid, top, smoothstep(0.12, 0.7, h)) : mix(horizon, mid, smoothstep(-0.05, 0.12, h));
                gl_FragColor = vec4(c, 1.0); }`,
        });
        this.scene.add(new THREE.Mesh(geo, mat));

        // A few stars, one draw call.
        const starGeo = new THREE.BufferGeometry();
        const pts = [];
        for (let i = 0; i < 600; i++) {
            const v = new THREE.Vector3().setFromSphericalCoords(850, Math.random() * Math.PI * 0.42, Math.random() * Math.PI * 2);
            pts.push(v.x, v.y, v.z);
        }
        starGeo.setAttribute('position', new THREE.Float32BufferAttribute(pts, 3));
        this.scene.add(new THREE.Points(starGeo, new THREE.PointsMaterial({ color: 0x9fb8d8, size: 1.4, sizeAttenuation: false, fog: false, transparent: true, opacity: 0.7 })));
    }

    addLights() {
        this.scene.add(new THREE.HemisphereLight(0x9ccfff, 0x0b1f3a, 0.7));
        const moon = new THREE.DirectionalLight(0xdbeafe, 1.1);
        moon.position.set(-120, 200, 140);
        this.scene.add(moon);
        const rim = new THREE.DirectionalLight(0x22d3ee, 0.6);
        rim.position.set(150, 60, -120);
        this.scene.add(rim);
    }

    /** fn(dt, elapsed) is called every rendered frame. Returns an unsubscribe function. */
    onUpdate(fn) {
        this.updaters.add(fn);
        return () => this.updaters.delete(fn);
    }

    wake() {
        this.lastInteraction = performance.now();
    }

    start() {
        if (this.running) return;
        this.running = true;
        this.clock.getDelta();
        const loop = () => {
            if (!this.running) return;
            this.raf = requestAnimationFrame(loop);
            // After 20 s without input or scripted motion render at ~30 fps.
            const idle = performance.now() - this.lastInteraction > 20000;
            if (idle && (this.frameSkip = (this.frameSkip + 1) % 2) === 1) return;
            const dt = Math.min(this.clock.getDelta(), 0.1);
            const t = this.clock.elapsedTime;
            this.controls.update();
            for (const fn of this.updaters) fn(dt, t);
            this.renderer.render(this.scene, this.camera);
            this.frames = (this.frames || 0) + 1;
        };
        loop();
    }

    stop() {
        this.running = false;
        cancelAnimationFrame(this.raf);
    }

    resize() {
        const w = this.canvas.clientWidth || window.innerWidth;
        const h = this.canvas.clientHeight || window.innerHeight;
        this.renderer.setSize(w, h, false);
        this.camera.aspect = w / h;
        this.camera.fov = w < 700 ? 55 : 42;
        this.camera.updateProjectionMatrix();
    }
}
