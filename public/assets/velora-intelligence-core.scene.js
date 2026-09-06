/* VELORA — Trading Intelligence Core · Phase 1 scene (Three.js r170, self-hosted ESM)
   Renders ONLY geometry / particles / rings / light / grid. All text lives in HTML (controller).
   One renderer, one canvas inside the stage. Deterministic: every parameter is a pure function
   of scroll progress p (plus a low-rate idle clock in chapter 1, max 0.3 Hz, off under reduced motion). */
import * as THREE from './vendor/three/three.module.min.js';

const clamp = (v, a = 0, b = 1) => Math.min(b, Math.max(a, v));
const lerp = (a, b, t) => a + (b - a) * t;
const seg = (p, a, b) => clamp((p - a) / (b - a));
const smooth = t => t * t * (3 - 2 * t);
const TAU = Math.PI * 2;
const cssColor = (name, fallback) => {
  const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  return new THREE.Color(v || fallback);
};

export const THREE_REVISION = THREE.REVISION;

export function createScene(opts) {
  const { canvas, tier, reduced, dirSign, els } = opts;          // els: {stage, leaders, frame}
  const isMobile = tier === 'b';
  const N = isMobile ? 800 : 2200;
  const maxDpr = isMobile ? 1.5 : 2;

  /* ---------- brand tokens → colors (no brand hex in JS; fallbacks mirror :root) ---------- */
  const C = {
    bg: cssColor('--bg', '#04050a'), gold: cssColor('--gold', '#e9c45c'), gold2: cssColor('--gold2', '#c9972e'),
    gold3: cssColor('--gold3', '#f7e3a1'), gold4: cssColor('--gold4', '#a3741f'),
  };

  /* ---------- renderer / camera ---------- */
  const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: !isMobile, powerPreference: 'high-performance' });
  renderer.setClearColor(0x000000, 0);
  renderer.toneMapping = THREE.NeutralToneMapping;
  renderer.toneMappingExposure = 1.0;
  const scene = new THREE.Scene();
  scene.fog = new THREE.Fog(C.bg.getHex(), 8, 17);
  const FOV = 35;
  const camera = new THREE.PerspectiveCamera(FOV, 1, 0.1, 60);
  camera.position.set(0, 0, 7.5);

  /* ---------- procedural studio environment (metal reads without any texture asset) ---------- */
  {
    const c2 = document.createElement('canvas'); c2.width = 256; c2.height = 128;
    const g = c2.getContext('2d');
    const v = g.createLinearGradient(0, 0, 0, 128);
    v.addColorStop(0, '#0d0f16'); v.addColorStop(.42, '#1a1508'); v.addColorStop(.5, '#06070c'); v.addColorStop(1, '#020305');
    g.fillStyle = v; g.fillRect(0, 0, 256, 128);
    const kx = dirSign > 0 ? 176 : 80;
    const key = g.createRadialGradient(kx, 38, 4, kx, 38, 84);
    key.addColorStop(0, 'rgba(249,230,168,.95)'); key.addColorStop(.35, 'rgba(233,196,92,.45)'); key.addColorStop(1, 'rgba(233,196,92,0)');
    g.fillStyle = key; g.fillRect(0, 0, 256, 128);
    const rx = dirSign > 0 ? 48 : 208;
    const rim = g.createRadialGradient(rx, 70, 2, rx, 70, 60);
    rim.addColorStop(0, 'rgba(190,170,140,.30)'); rim.addColorStop(1, 'rgba(190,170,140,0)');   // warm-neutral rim (review)
    g.fillStyle = rim; g.fillRect(0, 0, 256, 128);
    const tex = new THREE.CanvasTexture(c2); tex.mapping = THREE.EquirectangularReflectionMapping; tex.colorSpace = THREE.SRGBColorSpace;
    const pm = new THREE.PMREMGenerator(renderer);
    scene.environment = pm.fromEquirectangular(tex).texture;
    scene.environmentIntensity = 1.0;
    tex.dispose(); pm.dispose();
  }

  /* ---------- lights ---------- */
  const key = new THREE.DirectionalLight(C.gold3.getHex(), 2.2); key.position.set(dirSign * 3, 4, 3); scene.add(key);
  const rim = new THREE.DirectionalLight(0xcdbfa6, 0.5); rim.position.set(-dirSign * 4, 1.5, -4); scene.add(rim);
  scene.add(new THREE.HemisphereLight(0x2a2a30, 0x04050a, 0.45));

  const group = new THREE.Group(); scene.add(group);

  /* ---------- core: low-poly icosphere (D4: detail 3 ≈ 1,280 tris) + fresnel rim + facet hairlines ---------- */
  const icoGeo = new THREE.IcosahedronGeometry(1, 3); icoGeo.computeVertexNormals();
  const coreMat = new THREE.MeshStandardMaterial({ color: 0x0a0c14, roughness: .38, metalness: .18, flatShading: true, emissive: C.gold2.getHex(), emissiveIntensity: 0 });
  const coreU = { uScanY: { value: 99 }, uScanOn: { value: 0 }, uBand: { value: .05 } };
  coreMat.onBeforeCompile = sh => {
    Object.assign(sh.uniforms, coreU);
    sh.vertexShader = sh.vertexShader.replace('#include <common>', '#include <common>\nvarying float vWY;')
      .replace('#include <begin_vertex>', '#include <begin_vertex>\nvWY = (modelMatrix * vec4(transformed,1.0)).y;');
    sh.fragmentShader = sh.fragmentShader.replace('#include <common>', '#include <common>\nvarying float vWY; uniform float uScanY, uScanOn, uBand;')
      .replace('#include <emissivemap_fragment>', '#include <emissivemap_fragment>\ntotalEmissiveRadiance += vec3(0.98,0.86,0.5) * smoothstep(uBand, 0.0, abs(vWY - uScanY)) * uScanOn * 1.8;');
  };
  const core = new THREE.Mesh(icoGeo, coreMat); group.add(core);
  const rimMat = new THREE.ShaderMaterial({
    transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
    uniforms: { uCol: { value: C.gold.clone() }, uI: { value: .35 } },
    vertexShader: `varying vec3 vN, vV; void main(){ vec4 mv = modelViewMatrix*vec4(position,1.0); vN = normalize(normalMatrix*normal); vV = normalize(-mv.xyz); gl_Position = projectionMatrix*mv; }`,
    fragmentShader: `uniform vec3 uCol; uniform float uI; varying vec3 vN, vV; void main(){ float f = pow(1.0 - max(dot(normalize(vN), normalize(vV)), 0.0), 3.2); gl_FragColor = vec4(uCol, f*uI); }`,
  });
  const rimMesh = new THREE.Mesh(icoGeo, rimMat); rimMesh.scale.setScalar(1.012); core.add(rimMesh);
  const edges = new THREE.LineSegments(new THREE.EdgesGeometry(new THREE.IcosahedronGeometry(1.002, 3), 1),
    new THREE.LineBasicMaterial({ color: C.gold.getHex(), transparent: true, opacity: 0, depthWrite: false }));
  core.add(edges);

  /* markers (chapter 3) */
  const markerDirs = [[.35, .55, .76], [-.62, .12, .78], [.18, -.52, .84]].map(d => new THREE.Vector3(d[0] * dirSign, d[1], d[2]).normalize());
  const markerMat = new THREE.MeshBasicMaterial({ color: C.gold3.getHex(), transparent: true, opacity: 0 });
  const markerGeo = new THREE.SphereGeometry(.028, 10, 8);
  const markers = markerDirs.map(d => { const m = new THREE.Mesh(markerGeo, markerMat); m.position.copy(d).multiplyScalar(1.01); core.add(m); return m; });

  /* ---------- rings: 3 tubes rebuilt on the CPU only when their morph key changes ---------- */
  const RAD = 8, TUB = isMobile ? 120 : 200;
  const R = [1.5, 1.84, 2.2];
  const tiltAxis0 = [new THREE.Vector3(1, 0, 0), new THREE.Vector3(.25, 0, 1).normalize(), new THREE.Vector3(1, 0, .55).normalize()];
  const tiltAng = [1.15 * dirSign, -0.95 * dirSign, 0.5 * dirSign];
  const prec = [0.05, -0.035, 0.028];
  const ringMat = new THREE.MeshStandardMaterial({ color: C.gold.getHex(), metalness: .95, roughness: .3, emissive: C.gold4.getHex(), emissiveIntensity: .12 });
  const rings = R.map(() => {
    const geo = new THREE.BufferGeometry();
    const pos = new Float32Array((RAD + 1) * (TUB + 1) * 3), nor = new Float32Array(pos.length);
    const idx = [];
    for (let c = 0; c < TUB; c++) for (let r = 0; r < RAD; r++) { const a = c * (RAD + 1) + r, b = a + RAD + 1; idx.push(a, b, a + 1, b, b + 1, a + 1); }
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3)); geo.setAttribute('normal', new THREE.BufferAttribute(nor, 3)); geo.setIndex(idx);
    const mesh = new THREE.Mesh(geo, ringMat); group.add(mesh); return mesh;
  });
  const axisNow = tiltAxis0.map(a => a.clone());
  function arc(u, cy, L, k, out) {
    const th = L * k, ph = th * u;
    if (k > 1e-4) { out.px = Math.sin(ph) / k; out.py = (Math.cos(ph) - 1) / k + cy; }
    else { out.px = L * u; out.py = cy; }
    out.nx = Math.sin(ph); out.ny = Math.cos(ph);
  }
  const _o = {};
  let ringState = '';
  function buildRings(mArr, cons, W, Wf, rowY, rowYf) {
    const keyS = [mArr.map(m => m.toFixed(3)).join(), cons.toFixed(3), W.toFixed(3), Wf.toFixed(3), rowY.map(v => v.toFixed(3)).join(), rowYf.map(v => v.toFixed(3)).join()].join('|');
    if (keyS === ringState) return; ringState = keyS;
    rings.forEach((mesh, i) => {
      const m = mArr[i];
      const Rr = R[i], L = lerp(TAU * Rr, lerp(W, Wf, cons), m), k = (1 - m) / Rr;
      const ry = lerp(rowY[i], rowYf[i], cons), cy = lerp(Rr, ry, m), tube = lerp(.011, .03, m);
      const pos = mesh.geometry.attributes.position.array, nor = mesh.geometry.attributes.normal.array;
      let j = 0;
      for (let c = 0; c <= TUB; c++) {
        arc(c / TUB - .5, cy, L, k, _o);
        for (let r = 0; r <= RAD; r++) {
          const v = r / RAD * TAU, cv = Math.cos(v), sv = Math.sin(v);
          const nx = cv * _o.nx, ny = cv * _o.ny, nz = sv;
          pos[j] = _o.px + tube * nx; pos[j + 1] = _o.py + tube * ny; pos[j + 2] = tube * nz;
          nor[j] = nx; nor[j + 1] = ny; nor[j + 2] = nz; j += 3;
        }
      }
      mesh.geometry.attributes.position.needsUpdate = true; mesh.geometry.attributes.normal.needsUpdate = true;
      mesh.geometry.computeBoundingSphere();
    });
  }

  /* perspective floor grid (motif of the live #bg3d) */
  let gridRef = null;
  {
    const pts = [];
    for (let x = -8; x <= 8; x += .5) pts.push(x, -1.9, -9, x, -1.9, 3);
    for (let z = -9; z <= 3; z += .5) pts.push(-8, -1.9, z, 8, -1.9, z);
    const g = new THREE.BufferGeometry(); g.setAttribute('position', new THREE.Float32BufferAttribute(pts, 3));
    gridRef = new THREE.LineSegments(g, new THREE.LineBasicMaterial({ color: C.gold4.getHex(), transparent: true, opacity: .22, fog: true }));
    group.add(gridRef);
  }

  /* scan plane + cross-section circle (chapter 3) */
  const scanMat = new THREE.ShaderMaterial({
    transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide,
    uniforms: { uCol: { value: C.gold.clone() }, uI: { value: 0 } },
    vertexShader: `varying vec2 vUv; void main(){ vUv = uv; gl_Position = projectionMatrix*modelViewMatrix*vec4(position,1.0); }`,
    fragmentShader: `uniform vec3 uCol; uniform float uI; varying vec2 vUv; void main(){ float d = length(vUv-0.5)*2.0; float a = (1.0-smoothstep(0.35,1.0,d))*0.16*uI; gl_FragColor = vec4(uCol, a); }`,
  });
  const scan = new THREE.Mesh(new THREE.PlaneGeometry(3.6, 3.6), scanMat); scan.rotation.x = -Math.PI / 2; group.add(scan);
  const circ = new THREE.LineLoop(new THREE.BufferGeometry().setFromPoints(Array.from({ length: 96 }, (_, i) => new THREE.Vector3(Math.cos(i / 96 * TAU), 0, Math.sin(i / 96 * TAU)))),
    new THREE.LineBasicMaterial({ color: C.gold3.getHex(), transparent: true, opacity: 0, depthWrite: false }));
  group.add(circ);
  const rowBoxGeo = (() => {
    const r = .12, w = .5, h = .5, pts = [], n = 6;
    const corner = (cx, cy, a0) => { for (let k = 0; k <= n; k++) { const a = a0 + k / n * Math.PI / 2; pts.push(new THREE.Vector3(cx + Math.cos(a) * r, cy + Math.sin(a) * r, 0)); } };
    corner(w - r, h - r, 0); corner(-w + r, h - r, Math.PI / 2); corner(-w + r, -h + r, Math.PI); corner(w - r, -h + r, 1.5 * Math.PI);
    return new THREE.BufferGeometry().setFromPoints(pts);
  })();
  const rowBoxes = [0, 1, 2].map(() => { const l = new THREE.LineLoop(rowBoxGeo, new THREE.LineBasicMaterial({ color: C.gold2.getHex(), transparent: true, opacity: 0, depthWrite: false })); l.visible = false; group.add(l); return l; });

  /* ---------- particles: feed → swarm → rings → ledger rows → dashboard frame/KPI/equity ---------- */
  const pGeo = new THREE.BufferGeometry();
  const KPI = 4, EQ = 24;
  {
    const spawn = new Float32Array(N * 3), oa = new Float32Array(N * 4), ob = new Float32Array(N * 4), ring = new Float32Array(N * 2),
      rand = new Float32Array(N * 2), fr = new Float32Array(N * 2), hi = new Float32Array(N), dock = new Float32Array(N * 2), feed = new Float32Array(N);
    const rnd = (() => { let s = 1337; return () => (s = (s * 16807) % 2147483647) / 2147483647; })();   // deterministic
    for (let i = 0; i < N; i++) {
      spawn[i * 3] = (rnd() - .5) * 20; spawn[i * 3 + 1] = (rnd() - .5) * 13; spawn[i * 3 + 2] = -1 - rnd() * 7;
      const e1 = new THREE.Vector3(rnd() - .5, rnd() - .5, rnd() - .5).normalize();
      const e2 = new THREE.Vector3(rnd() - .5, rnd() - .5, rnd() - .5).cross(e1).normalize();
      const rad = 1.35 + rnd() * 1.4;
      oa.set([e1.x * rad, e1.y * rad, e1.z * rad, (0.25 + rnd() * .6) * dirSign], i * 4);
      ob.set([e2.x * rad, e2.y * rad, e2.z * rad, rnd() * TAU], i * 4);
      ring[i * 2] = rnd(); ring[i * 2 + 1] = Math.floor(rnd() * 3);
      rand[i * 2] = rnd(); rand[i * 2 + 1] = rnd();
      const t = rnd() * 4, side = Math.floor(t), f = t - side;
      fr.set(side === 0 ? [-1 + 2 * f, 1] : side === 1 ? [1, 1 - 2 * f] : side === 2 ? [1 - 2 * f, -1] : [-1, -1 + 2 * f], i * 2);
      hi[i] = i % 97 === 0 ? 1 : 0;
      // ch5 docking target: 0 = frame outline, 1 = KPI card outline (x = card index + perimeter t), 2 = equity path sample
      const dr = rnd();
      if (dr < .22) { dock[i * 2] = 1; dock[i * 2 + 1] = Math.floor(rnd() * KPI) + rnd() * .999; }
      else if (dr < .40) { dock[i * 2] = 2; dock[i * 2 + 1] = rnd(); }
      else { dock[i * 2] = 0; dock[i * 2 + 1] = 0; }
      feed[i] = rnd() < .30 ? 1 : 0;                                        // ch1: 30% "active" population on feed paths
    }
    pGeo.setAttribute('position', new THREE.BufferAttribute(spawn, 3));
    pGeo.setAttribute('aSpawn', new THREE.BufferAttribute(spawn, 3));
    pGeo.setAttribute('aOrbitA', new THREE.BufferAttribute(oa, 4));
    pGeo.setAttribute('aOrbitB', new THREE.BufferAttribute(ob, 4));
    pGeo.setAttribute('aRing', new THREE.BufferAttribute(ring, 2));
    pGeo.setAttribute('aRand', new THREE.BufferAttribute(rand, 2));
    pGeo.setAttribute('aFrame', new THREE.BufferAttribute(fr, 2));
    pGeo.setAttribute('aHi', new THREE.BufferAttribute(hi, 1));
    pGeo.setAttribute('aDock', new THREE.BufferAttribute(dock, 2));
    pGeo.setAttribute('aFeed', new THREE.BufferAttribute(feed, 1));
    pGeo.boundingSphere = new THREE.Sphere(new THREE.Vector3(), 30);
  }
  const kpiRects = Array.from({ length: KPI }, () => new THREE.Vector4(0, 0, 0, 0));   // (cx, cy, hw, hh) in group-local units
  const eqPts = Array.from({ length: EQ }, () => new THREE.Vector2());
  const pU = {
    uTime: { value: 0 }, uFlow: { value: 0 }, uChaos: { value: 1 }, uLock: { value: 0 }, uMorph: { value: new THREE.Vector3() }, uTilt: { value: 0 }, uCons: { value: 0 },
    uQuant: { value: 0 }, uHi: { value: 0 }, uScanY: { value: 99 }, uScanOn: { value: 0 }, uDpr: { value: 1 }, uDist: { value: 7.5 }, uAlpha: { value: 1 },
    uW: { value: 4.5 }, uWf: { value: 3.5 }, uFrame: { value: new THREE.Vector2(2, 1.4) }, uDock: { value: 0 },
    uRadii: { value: new THREE.Vector3(...R) }, uRowY: { value: new THREE.Vector3(.6, 0, -.6) }, uRowYf: { value: new THREE.Vector3(.9, .6, .3) },
    uSpin: { value: new THREE.Vector3() }, uAxis: { value: axisNow }, uAng: { value: new THREE.Vector3(...tiltAng) },
    uKpi: { value: kpiRects }, uEq: { value: eqPts },
    uColA: { value: C.gold4.clone() }, uColB: { value: C.gold.clone() }, uColH: { value: C.gold3.clone() },
  };
  const pMat = new THREE.ShaderMaterial({
    uniforms: pU, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
    vertexShader: `
      uniform float uTime,uFlow,uChaos,uLock,uTilt,uCons,uQuant,uHi,uScanY,uScanOn,uDpr,uDist,uW,uWf,uDock;
      uniform vec2 uFrame; uniform vec3 uRadii,uRowY,uRowYf,uSpin,uAng,uMorph; uniform vec3 uAxis[3];
      uniform vec4 uKpi[${KPI}]; uniform vec2 uEq[${EQ}];
      attribute vec3 aSpawn; attribute vec4 aOrbitA,aOrbitB; attribute vec2 aRing,aRand,aFrame,aDock; attribute float aHi,aFeed;
      varying float vA,vL,vH;
      vec3 rod(vec3 v, vec3 k, float a){ float c=cos(a), s=sin(a); return v*c + cross(k,v)*s + k*dot(k,v)*(1.0-c); }
      float pick(vec3 v,int i){ return i==0?v.x:(i==1?v.y:v.z); }
      vec2 rectPt(vec4 r, float t){ float s=fract(t)*4.0; float q=floor(s); float f=s-q;
        if(q<0.5) return vec2(mix(-r.z,r.z,f), r.w); if(q<1.5) return vec2(r.z, mix(r.w,-r.w,f));
        if(q<2.5) return vec2(mix(r.z,-r.z,f), -r.w); return vec2(-r.z, mix(-r.w,r.w,f)); }
      void main(){
        int i = int(aRing.y+0.5);
        float morph = pick(uMorph,i);
        float R = pick(uRadii,i), ry = mix(pick(uRowY,i), pick(uRowYf,i), uCons), spin = pick(uSpin,i), ang = pick(uAng,i)*(1.0-uTilt);
        float u = fract(aRing.x + spin*(1.0-morph)) - 0.5;
        float W = mix(uW,uWf,uCons), L = mix(6.2831853*R, W, morph), k = (1.0-morph)/R, ph = L*k*u;
        vec3 rp = (k>1e-4) ? vec3(sin(ph)/k, (cos(ph)-1.0)/k + mix(R,ry,morph), 0.0) : vec3(L*u, ry, 0.0);
        // ch4 beat 4: particles quantise from a loose band onto the row line
        vec3 jit = (vec3(aRand.x,aRand.y,aRand.x*aRand.y)-0.5)*mix(0.05,0.006,uQuant);
        rp += jit;
        rp = rod(rp, uAxis[i], ang);
        // ch5 docking: frame outline / KPI card outline / equity curve
        vec3 fp;
        if(aDock.x>1.5){ float s=aDock.y*float(${EQ - 1}); int j=int(floor(s)); float f=s-float(j); vec2 e=mix(uEq[j], uEq[min(j+1,${EQ - 1})], f); fp=vec3(e,0.0); }
        else if(aDock.x>0.5){ int j=int(floor(aDock.y)); fp=vec3(uKpi[j].xy + rectPt(uKpi[j], aDock.y), 0.0); }
        else fp = vec3(aFrame.x*uFrame.x, aFrame.y*uFrame.y, 0.0);
        vec3 st = mix(rp, fp, smoothstep(aRand.y*0.4, aRand.y*0.4+0.6, uCons)*uDock);
        float a = uTime*aOrbitA.w + aOrbitB.w;
        vec3 sw = cos(a)*aOrbitA.xyz + sin(a)*aOrbitB.xyz;
        sw += 0.12*uChaos*vec3(sin(uTime*1.3+aRand.x*31.0), cos(uTime*1.1+aRand.y*17.0), sin(uTime*0.9+aRand.x*7.0));
        float f = smoothstep(aRand.x*0.55, aRand.x*0.55+0.45, uFlow);
        // ch1: passive field drifts slowly; the active 30% travel along feed paths toward the core (≤0.3 Hz)
        vec3 drift = 0.15*vec3(sin(uTime*0.2+aRand.y*9.0), cos(uTime*0.17+aRand.x*5.0), 0.0);
        float lane = fract(aRand.x*7.0 + uTime*0.08);
        vec3 feedP = mix(aSpawn*vec3(1.0,1.0,1.0), vec3(0.0,0.0,0.0), lane*0.85);
        vec3 sp = mix(aSpawn + drift, feedP, aFeed*(1.0-f)*0.6);
        vec3 pos = mix(sp, sw, f);
        float l = smoothstep(aRand.y*0.5, aRand.y*0.5+0.5, uLock);
        pos = mix(pos, st, l);
        float sz = mix(mix(mix(1.8,2.6,aFeed),2.2,f), 2.5, l);
        float al = mix(mix(mix(0.42,0.70,aFeed),0.55,f), 0.8, l);
        float h = aHi*uHi; sz += h*3.5; al += h*0.3;
        float sc = smoothstep(0.14,0.0,abs(pos.y-uScanY))*uScanOn; sz += sc*1.6; al += sc*0.4;
        vec4 mv = modelViewMatrix*vec4(pos,1.0);
        gl_PointSize = sz*uDpr*(uDist/max(0.5,-mv.z));
        gl_Position = projectionMatrix*mv;
        vA = al; vL = l; vH = max(h, sc);
      }`,
    fragmentShader: `
      uniform vec3 uColA,uColB,uColH; uniform float uAlpha; varying float vA,vL,vH;
      void main(){ vec2 c = gl_PointCoord-0.5; float d = length(c); if(d>0.5) discard;
        float soft = smoothstep(0.5,0.12,d); vec3 col = mix(mix(uColA,uColB,vL), uColH, vH);
        gl_FragColor = vec4(col, soft*vA*uAlpha); }`,
  });
  const points = new THREE.Points(pGeo, pMat); points.frustumCulled = false; group.add(points);

  /* ---------- overlay refs (HTML captions positioned by projection; text stays HTML) ---------- */
  const caps = [...els.leaders.querySelectorAll('.vic-cap[data-cap]')], lines = [...els.leaders.querySelectorAll('line')], rowCaps = [...els.leaders.querySelectorAll('.vic-cap[data-row]')];

  /* ---------- state ---------- */
  let sw = 1, sh = 1, dpr = 1, pointer = { x: 0, y: 0 }, t0 = performance.now(), prevKey = '';
  const stats = { draws: 0, tris: 0, points: 0, fps: 0, particles: N, dpr: 1, ms: 0 };
  let fpsN = 0, fpsT = performance.now();
  const _v = new THREE.Vector3();
  let dockRects = null;                                                   // {kpi:[{cx,cy,hw,hh}] px rel. to real .db-shell, eq:[{x,y}], w, h}

  function resize() {
    const r = els.stage.getBoundingClientRect();
    sw = Math.max(1, r.width); sh = Math.max(1, r.height);
    dpr = Math.min(devicePixelRatio || 1, maxDpr);
    if (sw * sh * dpr * dpr > 2.6e6) dpr = Math.max(1, Math.min(dpr, 1.5));   // pixel budget above ~1.6 MP
    renderer.setPixelRatio(dpr); renderer.setSize(sw, sh, false);
    camera.aspect = sw / sh; camera.updateProjectionMatrix();
    pU.uDpr.value = dpr; stats.dpr = dpr; prevKey = '';
  }
  resize();
  function setDock(d) { dockRects = d; prevKey = ''; }

  function toStage(v3) { _v.copy(v3).project(camera); return { x: (_v.x + 1) / 2 * sw, y: (1 - _v.y) / 2 * sh }; }

  /* main update: p = story progress (<0 before, 0..1 chapters, >1 after); frameRect = handoff frame in stage-relative px (or null) */
  function render(now, p, frameRect) {
    const t = reduced ? 0 : (now - t0) / 1000;

    /* chapter parameters */
    const q1 = seg(p, 0, .18), q2 = seg(p, .18, .40), q3 = seg(p, .40, .62), q4 = seg(p, .62, .82), q5 = seg(p, .82, 1);
    const flow = smooth(q1), chaos = 1 - smooth(q2), lock = smooth(q2), cons = smooth(q5);
    /* ch4 six beats (D6/review): rings open .00–.15 → staggered unbend .10–.45 → core recedes .20–.55 → quantise .45–.75 → row boxes .55–.85 → lock .85–1 */
    const tilt = smooth(seg(q4, 0, .15));
    const morphArr = [smooth(seg(q4, .10, .33)), smooth(seg(q4, .16, .39)), smooth(seg(q4, .22, .45))];
    const morph = (morphArr[0] + morphArr[1] + morphArr[2]) / 3;
    const recede = smooth(seg(q4, .20, .55));
    const quant = smooth(seg(q4, .45, .75));
    const boxes = smooth(seg(q4, .55, .85));
    const lockIn = smooth(seg(q4, .85, 1));
    const scanOn = smooth(seg(q3, 0, .12)) * (1 - smooth(seg(q3, .86, 1)));
    const hi = [seg(q3, .3, .42), seg(q3, .46, .58), seg(q3, .62, .74)].map(smooth);
    const illum = p < 0 ? .42 : lerp(lerp(lerp(lerp(.42, .62, smooth(q2)), .9, smooth(q3)), .7, recede * .6), .95, cons);
    const fade = 1 - smooth(seg(q5, .8, 1));                           // canvas hands off to the DOM frame
    const dockOn = frameRect && dockRects ? 1 : 0;

    /* composition rect (stage-relative px): whole stage, or the handoff frame in ch5 */
    let rect = { left: 0, top: 0, width: sw, height: sh };
    if (cons > 0 && frameRect) rect = { left: lerp(0, frameRect.left, cons), top: lerp(0, frameRect.top, cons), width: lerp(sw, frameRect.width, cons), height: lerp(sh, frameRect.height, cons) };

    /* camera dolly (ch5 pull-back flattens perspective → "becomes UI") */
    const D = lerp(7.5, 11, cons); camera.position.z = D; pU.uDist.value = D;
    const halfH = Math.tan(FOV / 2 * Math.PI / 180) * D, pxPerUnit = sh / (2 * halfH);
    const rpx = 0.17 * Math.min(rect.width, rect.height) * lerp(1, .55, morph * .3) * lerp(1, .32, cons);
    const s = rpx / pxPerUnit;
    const cx = rect.left + rect.width / 2, cy = rect.top + rect.height / 2;
    const upp = 1 / (s * pxPerUnit);                                     // group-local units per px
    const fw = frameRect ? frameRect.width : sw * .86, fh = frameRect ? frameRect.height : sh * .86;
    const hw = fw / 2 * upp, hh = fh / 2 * upp;
    group.position.set(((cx / sw) * 2 - 1) * halfH * camera.aspect, (1 - (cy / sh) * 2) * halfH, 0);
    group.scale.setScalar(s);
    const par = reduced ? 0 : (1 - morph) * 0.052;                       // ≤3° pointer parallax
    group.rotation.set(-pointer.y * par, pointer.x * par, 0);

    /* ledger rows (local units) */
    const W = 0.6 * sw * upp, Wf = 1.7 * hw;
    const rowGap = 0.12 * sh * upp;
    const rowY = [.55 * rowGap, -.55 * rowGap, -1.65 * rowGap], rowYf = [hh * .55, hh * .35, hh * .15];
    pU.uW.value = W; pU.uWf.value = Wf; pU.uFrame.value.set(hw * 1.045, hh * 1.06); pU.uRowY.value.set(...rowY); pU.uRowYf.value.set(...rowYf);

    /* ch5 docking targets from the real dashboard geometry (KPI cards + equity curve), mapped into local units */
    if (dockRects && frameRect) {
      const kx = frameRect.width / Math.max(1, dockRects.w), ky = frameRect.height / Math.max(1, dockRects.h);
      dockRects.kpi.forEach((k, i) => {
        const px = frameRect.left + k.cx * kx, py = frameRect.top + k.cy * ky;
        kpiRects[i].set((px - cx) * upp, (cy - py) * upp, k.hw * kx * upp, k.hh * ky * upp);
      });
      dockRects.eq.forEach((e, i) => { const px = frameRect.left + e.x * kx, py = frameRect.top + e.y * ky; eqPts[i].set((px - cx) * upp, (cy - py) * upp); });
    }
    pU.uDock.value = dockOn;

    /* core placement: recedes behind the data in ch4 (beat 3), becomes the window mark in ch5 */
    const coreY = lerp(0, 1.9 * rowGap, recede);
    core.position.set(lerp(0, -dirSign * hw * .84, cons), lerp(coreY, hh * .78, cons), lerp(0, -0.6, recede) * (1 - cons));
    const breathe = reduced ? 1 : 1 + 0.012 * Math.sin(t * 1.2);          // ≈0.19 Hz
    core.scale.setScalar(lerp(1, .5, recede) * lerp(1, .55, cons) * breathe);
    core.rotation.y = reduced ? 0.4 * dirSign : (t * 0.05 * dirSign) * (1 - scanOn * .85) * (1 - recede);
    coreMat.emissiveIntensity = lerp(lerp(lerp(.0, .05, lock), .14, smooth(q3) * (1 - .5 * recede)), .04, cons);
    rimMat.uniforms.uI.value = lerp(.12, .55, lock) * fade;              // ch1 rim .12 (review)
    edges.material.opacity = lerp(0, .22, lock) * (1 - cons * .8);        // edges .22 (review)
    key.intensity = 2.2 * illum; rim.intensity = .5 * lerp(.6, 1, lock);
    scene.environmentIntensity = lerp(.55, .95, illum);

    /* rings */
    rings.forEach((mesh, i) => {
      const a = reduced ? 0 : t * prec[i] * dirSign * (1 - tilt);
      axisNow[i].copy(tiltAxis0[i]).applyAxisAngle(_v.set(0, 1, 0), a);
      mesh.quaternion.setFromAxisAngle(axisNow[i], tiltAng[i] * (1 - tilt));
      mesh.visible = fade > 0.02;
    });
    pU.uTilt.value = tilt;
    gridRef.material.opacity = .22 * (1 - .6 * recede) * (1 - cons); gridRef.position.y = -0.4 * recede;
    /* ledger row outlines (beat 5) — echo of the journal list; snap to full opacity at lock (beat 6) */
    const ro = boxes * (1 - smooth(seg(cons, 0, .5)));
    rowBoxes.forEach((rb, i) => {
      const local = clamp((boxes - i * .18) / .64);
      rb.visible = ro > .01 && local > 0; rb.material.opacity = (.35 + .25 * lockIn) * local * (1 - smooth(seg(cons, 0, .5)));
      rb.scale.set(W * lerp(1.06, 1.12, local), rowGap * .72, 1); rb.position.set(0, rowY[i] + rowGap * .12, -0.02);
    });
    ringMat.emissiveIntensity = lerp(.14, .35, lock) * lerp(1, .5, cons) + .08 * lockIn * (1 - cons);   // ring emissive .14 (review)
    ringMat.color.copy(C.gold).lerp(C.gold4, (1 - lock) * .55);
    buildRings(morphArr, cons, W, Wf, rowY, rowYf);
    pU.uSpin.value.set(t * .05, -t * .035, t * .028);

    /* scan (ch3) ≈0.29 Hz */
    const scanY = reduced ? .15 : 0.9 * Math.sin(t * (TAU / 3.4));
    scan.position.y = scanY; scan.visible = scanOn > .01; scanMat.uniforms.uI.value = scanOn;
    const rr = Math.sqrt(Math.max(0, 1 - scanY * scanY)) * core.scale.x;
    circ.position.set(core.position.x, scanY, core.position.z); circ.scale.set(rr, 1, rr); circ.material.opacity = .85 * scanOn; circ.visible = scanOn > .01;
    core.updateMatrixWorld();
    coreU.uScanY.value = group.position.y + scanY * s; coreU.uScanOn.value = scanOn; coreU.uBand.value = .045 * s;
    const mk = Math.max(...hi) * (1 - smooth(seg(q4, 0, .2)));
    markerMat.opacity = mk;
    markers.forEach((m, i) => { m.scale.setScalar(.2 + hi[i] * 1.0); m.visible = hi[i] > 0 && q4 < .2; });

    /* particles */
    pU.uTime.value = t; pU.uFlow.value = p < 0 ? 0 : flow; pU.uChaos.value = chaos; pU.uLock.value = lock; pU.uMorph.value.set(...morphArr); pU.uCons.value = cons; pU.uQuant.value = quant;
    pU.uHi.value = mk; pU.uScanY.value = scanY; pU.uScanOn.value = scanOn;
    pU.uAlpha.value = (p < 0 ? .75 : 1) * lerp(1, .45, cons) * fade;

    /* HTML overlay: markers → captions (desktop), rows → ledger captions */
    caps.forEach((cap, i) => {
      const on = hi[i] > .5 && q4 <= 0 && !isMobile;
      cap.classList.toggle('on', on); lines[i].classList.toggle('on', on);
      if (!on) return;
      const mp = toStage(markers[i].getWorldPosition(_v));
      const tx = sw * (0.5 + dirSign * 0.33), ty = sh * (0.24 + i * 0.16);
      cap.style.left = tx + 'px'; cap.style.top = ty + 'px';
      lines[i].setAttribute('x1', mp.x); lines[i].setAttribute('y1', mp.y); lines[i].setAttribute('x2', tx); lines[i].setAttribute('y2', ty);
    });
    rowCaps.forEach((cap, i) => {
      const on = boxes > (.15 + i * .18) && cons < .3;                   // rows appear 0.15 after their canvas box (review)
      cap.classList.toggle('on', on); if (!on) return;
      _v.set(-W / 2 * 1.12, rowY[i] + .12 * rowGap, 0);
      const a = toStage(group.localToWorld(_v));
      _v.set(W / 2 * 1.12, rowY[i] + .12 * rowGap, 0);
      const b2 = toStage(group.localToWorld(_v));
      cap.style.left = Math.min(a.x, b2.x) + 'px'; cap.style.width = Math.abs(b2.x - a.x) + 'px'; cap.style.top = a.y + 'px';
    });

    canvas.style.opacity = fade.toFixed(3);
    const visible = p > -1.2 && p < 1.1 && fade > 0.005 && document.visibilityState === 'visible';
    // reduced motion: render only when the scroll state changes (no idle clock)
    const key2 = reduced ? [p.toFixed(4), sw, sh, frameRect ? (frameRect.left | 0) + ',' + (frameRect.width | 0) : ''].join(',') : now;
    if (visible && key2 !== prevKey) {
      prevKey = key2;
      const t1 = performance.now();
      renderer.render(scene, camera);
      stats.ms = performance.now() - t1;
      const ri = renderer.info; stats.draws = ri.render.calls; stats.tris = ri.render.triangles; stats.points = ri.render.points;
      fpsN++; if (now - fpsT > 800) { stats.fps = Math.round(fpsN * 1000 / (now - fpsT)); fpsN = 0; fpsT = now; }
    }
    return stats;
  }

  function setPointer(x, y) { pointer.x = x; pointer.y = y; }
  function dispose() {
    renderer.dispose(); pGeo.dispose(); icoGeo.dispose(); markerGeo.dispose(); rowBoxGeo.dispose();
    rings.forEach(r => r.geometry.dispose()); [coreMat, rimMat, ringMat, pMat, scanMat, markerMat].forEach(m => m.dispose());
  }
  return { render, resize, setPointer, setDock, dispose, stats, three: THREE.REVISION };
}
