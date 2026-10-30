import * as THREE from 'three';

/** Canvas-backed texture for signs, labels and in-world screens. */
export function canvasTexture(width, height, draw) {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    draw(ctx, width, height);
    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.anisotropy = 4;
    tex.userData.redraw = (fn) => { ctx.clearRect(0, 0, width, height); fn(ctx, width, height); tex.needsUpdate = true; };
    return tex;
}

export function labelTexture(text, { sub = '', color = '#f5f8fc', accent = '#22d3ee', bg = 'rgba(7,20,40,0.82)', width = 512, height = 128 } = {}) {
    return canvasTexture(width, height, (ctx, w, h) => {
        ctx.fillStyle = bg;
        roundRect(ctx, 2, 2, w - 4, h - 4, 18);
        ctx.fill();
        ctx.strokeStyle = accent;
        ctx.globalAlpha = 0.7;
        ctx.lineWidth = 3;
        ctx.stroke();
        ctx.globalAlpha = 1;
        ctx.fillStyle = accent;
        ctx.fillRect(24, h / 2 - (sub ? 26 : 14), 6, sub ? 52 : 28);
        ctx.fillStyle = color;
        ctx.font = `600 ${sub ? 42 : 46}px "Space Grotesk", Inter, sans-serif`;
        ctx.textBaseline = 'middle';
        ctx.fillText(fit(ctx, text, w - 70), 46, sub ? h / 2 - 16 : h / 2);
        if (sub) {
            ctx.fillStyle = '#8da2bf';
            ctx.font = '400 26px Inter, sans-serif';
            ctx.fillText(fit(ctx, sub, w - 70), 46, h / 2 + 26);
        }
    });
}

/** A sprite label that always faces the camera. */
export function makeLabel(text, opts = {}) {
    const tex = labelTexture(text, opts);
    const mat = new THREE.SpriteMaterial({ map: tex, transparent: true, depthWrite: false, depthTest: opts.depthTest ?? true });
    const sprite = new THREE.Sprite(mat);
    const scale = opts.scale ?? 4;
    sprite.scale.set(scale, scale * (tex.image.height / tex.image.width), 1);
    return sprite;
}

export function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

export function fit(ctx, text, maxWidth) {
    let t = String(text ?? '');
    if (ctx.measureText(t).width <= maxWidth) return t;
    while (t.length > 1 && ctx.measureText(`${t}…`).width > maxWidth) t = t.slice(0, -1);
    return `${t}…`;
}

/** Lit-window pattern used by the surrounding city blocks. */
export function windowTexture() {
    const tex = canvasTexture(128, 256, (ctx, w, h) => {
        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, w, h);
        for (let y = 4; y < h; y += 12) {
            for (let x = 4; x < w; x += 10) {
                const r = Math.random();
                if (r > 0.72) {
                    ctx.fillStyle = r > 0.93 ? '#ffd8a8' : r > 0.84 ? '#7dd3fc' : '#1e3a5f';
                    ctx.fillRect(x, y, 6, 7);
                }
            }
        }
    });
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    return tex;
}
