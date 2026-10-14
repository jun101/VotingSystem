'use client';

import { useEffect, useRef } from 'react';

const POINTS = 420;
const RAYS = 40;
const NODES = 7;

type Palette = { dot: string; ray: string[]; nodeFill: string; haloIn: string; haloOut: string };

/** The colours are read from the design tokens once the page is running: no raw value here. */
function readPalette(): Palette {
  const style = getComputedStyle(document.documentElement);
  const token = (name: string) => style.getPropertyValue(name).trim();

  return {
    dot: token('--color-primary'),
    ray: [token('--color-primary'), token('--color-backdrop-from'), token('--color-accent')],
    nodeFill: token('--color-surface'),
    haloIn: token('--color-surface'),
    haloOut: token('--color-primary-line'),
  };
}

/**
 * A slowly turning sphere of dots with light rays and a few orbiting ballots, drawn on a canvas
 * behind the hero's live ballot. Decoration only: hidden from assistive technology, still when the
 * visitor asked for less motion, and paused while the page is not on screen.
 */
export function HeroSphere({ className }: { className?: string }) {
  const canvas = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const element = canvas.current;
    const context = element?.getContext('2d');

    if (!element || !context) return;

    const ctx = context;
    const palette = readPalette();
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    let width = 0;
    let height = 0;
    let rotation = 0;
    let last = 0;
    let frame = 0;
    let visible = true;
    let mx = 0;
    let my = 0;
    let tx = 0;
    let ty = 0;

    const colorAt = (k: number) => palette.ray[k % palette.ray.length] ?? palette.dot;
    const golden = Math.PI * (3 - Math.sqrt(5));
    const points = Array.from({ length: POINTS }, (_, i) => {
      const y = 1 - (i / (POINTS - 1)) * 2;
      const r = Math.sqrt(1 - y * y);

      return { x: Math.cos(golden * i) * r, y, z: Math.sin(golden * i) * r };
    });
    const nodes = Array.from({ length: NODES }, (_, k) => ({
      angle: k * 0.9,
      lift: 0.3 + k * 0.35,
      speed: 0.12 + k * 0.02,
    }));
    const rays = Array.from({ length: RAYS }, (_, k) => ({
      angle: (k / RAYS) * Math.PI * 2 + (k % 3) * 0.07,
      length: 0.25 + ((k * 37) % 70) / 100,
      phase: k * 1.7,
      color: colorAt(k),
    }));

    function resize() {
      const box = element!.getBoundingClientRect();

      width = box.width;
      height = box.height;
      element!.width = Math.round(width * ratio);
      element!.height = Math.round(height * ratio);
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    function draw(now: number) {
      const dt = Math.min((now - last) / 1000, 0.05);
      last = now;

      if (!reduce) rotation += dt * 0.3;

      mx += (tx - mx) * 0.05;
      my += (ty - my) * 0.05;

      ctx.clearRect(0, 0, width, height);

      const radius = Math.min(width, height) * 0.33;
      const ox = width / 2 + mx * 24;
      const oy = height / 2 + my * 24;
      const halo = ctx.createRadialGradient(
        ox - radius * 0.3,
        oy - radius * 0.35,
        radius * 0.1,
        ox,
        oy,
        radius,
      );

      halo.addColorStop(0, palette.haloIn);
      halo.addColorStop(1, palette.haloOut);
      ctx.beginPath();
      ctx.arc(ox, oy, radius, 0, Math.PI * 2);
      ctx.globalAlpha = 0.55;
      ctx.fillStyle = halo;
      ctx.fill();
      ctx.globalAlpha = 1;
      ctx.lineCap = 'round';
      ctx.lineWidth = 1.6;

      for (const ray of rays) {
        const pulse = 0.5 + 0.5 * Math.sin(now / 900 + ray.phase);
        const a = ray.angle + rotation * 0.1;
        const r0 = radius * 1.05;
        const r1 = radius * (1.05 + ray.length * (0.6 + 0.4 * pulse));

        ctx.globalAlpha = 0.25 + 0.45 * pulse;
        ctx.strokeStyle = ray.color;
        ctx.beginPath();
        ctx.moveTo(ox + Math.cos(a) * r0, oy + Math.sin(a) * r0);
        ctx.lineTo(ox + Math.cos(a) * r1, oy + Math.sin(a) * r1);
        ctx.stroke();
      }

      ctx.globalAlpha = 1;

      const cr = Math.cos(rotation);
      const sr = Math.sin(rotation);
      const ct = Math.cos(0.35);
      const st = Math.sin(0.35);
      const project = (p: { x: number; y: number; z: number }) => {
        const x = p.x * cr + p.z * sr;
        const z0 = -p.x * sr + p.z * cr;
        const y = p.y * ct - z0 * st;
        const z = p.y * st + z0 * ct;

        return { x: ox + x * radius, y: oy + y * radius, z };
      };

      ctx.fillStyle = palette.dot;

      for (const point of points) {
        const q = project(point);

        if (q.z < -0.1) continue;

        ctx.globalAlpha = 0.15 + 0.6 * ((q.z + 1) / 2);
        ctx.beginPath();
        ctx.arc(q.x, q.y, 1.1 + q.z * 0.9, 0, Math.PI * 2);
        ctx.fill();
      }

      ctx.globalAlpha = 1;

      nodes.forEach((node, k) => {
        if (!reduce) node.angle += dt * node.speed;

        const p = project({
          x: Math.cos(node.angle) * Math.cos(node.lift),
          y: Math.sin(node.lift) * 0.9,
          z: Math.sin(node.angle) * Math.cos(node.lift),
        });

        if (p.z < 0) return;

        const color = colorAt(k);

        ctx.fillStyle = palette.nodeFill;
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(p.x, p.y, 11, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();
        ctx.fillStyle = color;
        ctx.fillRect(p.x - 4, p.y - 3, 8, 6);
      });
    }

    function loop(now: number) {
      draw(now);

      if (!reduce && visible) frame = requestAnimationFrame(loop);
    }

    function onMove(event: MouseEvent) {
      tx = event.clientX / window.innerWidth - 0.5;
      ty = event.clientY / window.innerHeight - 0.5;
    }

    resize();
    draw(performance.now());

    if (!reduce) frame = requestAnimationFrame(loop);

    const observer = new IntersectionObserver((entries) => {
      const was = visible;

      visible = entries.some((entry) => entry.isIntersecting);

      if (visible && !was && !reduce) {
        last = performance.now();
        frame = requestAnimationFrame(loop);
      }
    });

    observer.observe(element);
    window.addEventListener('resize', resize);
    window.addEventListener('mousemove', onMove);

    return () => {
      cancelAnimationFrame(frame);
      observer.disconnect();
      window.removeEventListener('resize', resize);
      window.removeEventListener('mousemove', onMove);
    };
  }, []);

  return <canvas ref={canvas} aria-hidden="true" className={className} />;
}
