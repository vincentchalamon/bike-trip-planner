// UI primitives + icons + map/elevation SVG helpers shared across all page designs.
// Relies on: TOKENS, makeTheme (tokens.jsx) ; TRIP, STAGES, ROUTE, ROUTE_DAYS (shared-data.jsx).

const ThemeCtx = React.createContext(null);
const useTheme = () => React.useContext(ThemeCtx);

// ── Primitives ──────────────────────────────────────────────
function Pill({ children, bg, color, sm, bold, style = {} }) {
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 5,
      padding: sm ? '2px 8px' : '4px 10px', borderRadius: 999,
      background: bg, color,
      fontSize: sm ? 10.5 : 11.5, fontWeight: bold ? 600 : 500,
      whiteSpace: 'nowrap', letterSpacing: 0.1,
      ...style,
    }}>{children}</span>
  );
}

function Card({ children, style = {}, pad = 18, tint }) {
  const t = useTheme();
  return (
    <div style={{
      background: tint || t.surface,
      border: `1px solid ${t.line}`,
      borderRadius: 14,
      padding: pad,
      ...style,
    }}>{children}</div>
  );
}

function Btn({ children, variant = 'primary', size = 'md', icon, style = {}, onClick, full }) {
  const t = useTheme();
  const sz = {
    xs: { p: '5px 10px', fs: 11.5, r: 7 },
    sm: { p: '7px 12px', fs: 12.5, r: 8 },
    md: { p: '9px 16px', fs: 13,   r: 9 },
    lg: { p: '12px 22px', fs: 14,   r: 10 },
  }[size];
  const v = {
    primary: { bg: t.ink,     c: t.bg,   b: 'none' },
    accent:  { bg: t.accent,  c: '#fff', b: 'none' },
    ghost:   { bg: 'transparent', c: t.inkSoft, b: `1px solid ${t.line}` },
    soft:    { bg: t.surfaceAlt,  c: t.ink,     b: 'none' },
    danger:  { bg: t.red, c: '#fff', b: 'none' },
  }[variant];
  return (
    <button onClick={onClick} style={{
      padding: sz.p, fontSize: sz.fs, fontWeight: 500,
      background: v.bg, color: v.c, border: v.b, borderRadius: sz.r,
      cursor: 'pointer', display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
      gap: 6, fontFamily: 'inherit',
      width: full ? '100%' : 'auto',
      ...style,
    }}>{icon}{children}</button>
  );
}

function Field({ label, hint, children, style = {} }) {
  const t = useTheme();
  return (
    <label style={{ display: 'block', ...style }}>
      {label && <div style={{ fontSize: 11.5, fontWeight: 600, color: t.inkSoft, marginBottom: 6, textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</div>}
      {children}
      {hint && <div style={{ fontSize: 11, color: t.inkMute, marginTop: 4 }}>{hint}</div>}
    </label>
  );
}

function Input({ value, placeholder, icon, style = {}, mono }) {
  const t = useTheme();
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 12px', background: t.bg, border: `1px solid ${t.line}`, borderRadius: 9, ...style }}>
      {icon && <span style={{ color: t.inkMute, display: 'flex' }}>{icon}</span>}
      <span style={{ fontSize: 13, color: value ? t.ink : t.inkMute, fontFamily: mono ? t.mono : 'inherit', flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
        {value || placeholder}
      </span>
    </div>
  );
}

// ── Icons ──────────────────────────────────────────────────
const Ic = ({ d, size = 16, sw = 1.7, fill = 'none' }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill={fill} stroke="currentColor" strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0 }}>
    {typeof d === 'string' ? <path d={d} /> : d}
  </svg>
);
const I = {
  bike:     <Ic d="M5 17a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm14 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-7-3 3-6h-3l-3 6h3Zm0 0 3 2m-8-8h3" />,
  map:     <Ic d={<><path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2Z"/><path d="M9 4v14M15 6v14"/></>} />,
  clock:   <Ic d={<><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></>} />,
  upload:  <Ic d={<><path d="M12 3v13M6 9l6-6 6 6"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></>} />,
  link:    <Ic d={<><path d="M10 14a5 5 0 0 1 0-7l3-3a5 5 0 0 1 7 7l-2 2"/><path d="M14 10a5 5 0 0 1 0 7l-3 3a5 5 0 0 1-7-7l2-2"/></>} />,
  download:<Ic d={<><path d="M12 3v13M6 11l6 6 6-6"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></>} />,
  share:   <Ic d={<><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.5 10.5 7-4m-7 7 7 4"/></>} />,
  edit:    <Ic d={<><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></>} />,
  plus:    <Ic d="M12 5v14M5 12h14" />,
  check:   <Ic d="m5 12 5 5L20 7" />,
  alert:   <Ic d={<><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2.1 18.1A2 2 0 0 0 3.8 21h16.4a2 2 0 0 0 1.7-2.9L13.7 3.9a2 2 0 0 0-3.4 0Z"/></>} />,
  info:    <Ic d={<><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></>} />,
  wind:    <Ic d={<><path d="M3 8h13a3 3 0 1 0-3-3M3 16h17a3 3 0 1 1-3 3M3 12h9"/></>} />,
  mountain:<Ic d="m3 19 7-12 4 7 2-3 5 8Z" />,
  sun:     <Ic d={<><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></>} />,
  droplet: <Ic d="M12 3s7 7.5 7 13a7 7 0 1 1-14 0c0-5.5 7-13 7-13Z" />,
  wrench:  <Ic d="M15 4a5 5 0 0 0-6 7l-6 6a1.4 1.4 0 1 0 2 2l6-6a5 5 0 0 0 7-6l-3 3-2-2 2-3Z" />,
  coffee:  <Ic d={<><path d="M4 8h12v6a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8Z"/><path d="M16 10h2a2 2 0 0 1 0 4h-2M8 2v3M12 2v3"/></>} />,
  camera:  <Ic d={<><path d="M3 8h3l2-3h8l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="4"/></>} />,
  close:   <Ic d="M6 6l12 12M18 6l-12 12" />,
  chevron: <Ic d="m9 6 6 6-6 6" />,
  chevUp:  <Ic d="m6 15 6-6 6 6" />,
  chevDn:  <Ic d="m6 9 6 6 6-6" />,
  back:    <Ic d="m15 6-6 6 6 6" />,
  train:   <Ic d={<><rect x="5" y="3" width="14" height="15" rx="2"/><path d="M5 13h14M9 21l-2-3M15 21l2-3M9 8h.01M15 8h.01"/></>} />,
  flag:    <Ic d="M4 21V4m0 0h11l-2 4 2 4H4" />,
  home:    <Ic d="m3 11 9-8 9 8v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1v-9Z" />,
  list:    <Ic d={<><path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></>} />,
  search:  <Ic d={<><circle cx="11" cy="11" r="7"/><path d="m21 21-4.5-4.5"/></>} />,
  settings:<Ic d={<><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.2 4.2l2.8 2.8M17 17l2.8 2.8M1 12h4M19 12h4M4.2 19.8 7 17M17 7l2.8-2.8"/></>} />,
  bed:     <Ic d={<><path d="M3 18V7m0 0h12a4 4 0 0 1 4 4v7M3 13h18"/><circle cx="7" cy="10" r="1.5"/></>} />,
  traffic: <Ic d={<><circle cx="12" cy="12" r="9"/><path d="M4 10h16M10 4v16"/></>} />,
  cobble:  <Ic d={<><rect x="3" y="4" width="7" height="7" rx="1"/><rect x="14" y="4" width="7" height="7" rx="1"/><rect x="3" y="13" width="7" height="7" rx="1"/><rect x="14" y="13" width="7" height="7" rx="1"/></>} />,
  sparkle: <Ic d="M12 3v5M12 16v5M3 12h5M16 12h5M5.6 5.6l3.5 3.5M14.9 14.9l3.5 3.5M5.6 18.4l3.5-3.5M14.9 9.1l3.5-3.5" />,
  compass: <Ic d={<><circle cx="12" cy="12" r="9"/><path d="m14.5 9.5-2 5-5 2 2-5 5-2Z"/></>} />,
  mail:    <Ic d={<><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 7 9-7"/></>} />,
  lock:    <Ic d={<><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></>} />,
  logout:  <Ic d={<><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></>} />,
  trips:   <Ic d={<><path d="M2 17 9 5l6 4 7-7"/><path d="m22 2-5 1 1 5"/></>} />,
  dumbbell:<Ic d={<><path d="M3 8v8M7 6v12M21 8v8M17 6v12M7 12h10"/></>} />,
  thermometer: <Ic d={<><path d="M14 4a2 2 0 1 0-4 0v10a4 4 0 1 0 4 0V4Z"/></>} />,
  arrowUp: <Ic d="M5 12l7-7 7 7M12 5v14" />,
  arrowDn: <Ic d="M5 12l7 7 7-7M12 5v14" />,
  help:    <Ic d={<><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 1.7-2.5 2-2.5 4M12 17h.01"/></>} />,
  ghostFace:<Ic d={<><path d="M4 19V8a8 8 0 1 1 16 0v11l-2.5-2-2.5 2-2.5-2-2.5 2-2.5-2L4 19Z"/><path d="M9 10h.01M15 10h.01"/></>} />,
  loader:  <Ic d="M12 3a9 9 0 0 1 9 9" />,
  bolt:    <Ic d="m13 2-9 11h8l-1 9 9-11h-8l1-9Z" />,
  calendar:<Ic d={<><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></>} />,
  euro:    <Ic d="M18 7a5 5 0 0 0-8.2 2M18 17a5 5 0 0 1-8.2-2M4 10h9M4 14h8" />,
  filter:  <Ic d="M3 4h18l-7 9v6l-4 2v-8L3 4Z" />,
  star:    <Ic d="m12 3 2.9 6 6.5.9-4.7 4.6 1.1 6.5L12 18l-5.8 3 1.1-6.5L2.6 9.9 9.1 9 12 3Z" fill="currentColor" />,
  globe:   <Ic d={<><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></>} />,
  moreH:   <Ic d={<><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></>} />,
  pdf:     <Ic d={<><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></>} />,
  copy:    <Ic d={<><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></>} />,
  navigation: <Ic d="M3 11 22 2 13 21 11 13 3 11Z" />,
  lock:    <Ic d={<><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></>} />,
  logout:  <Ic d={<><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></>} />,
  qrcode:  <Ic d={<><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM20 14v3M14 20h3M20 20h1"/></>} />,
  monitor: <Ic d={<><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></>} />,
  smartphone: <Ic d={<><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></>} />,
  wifiOff: <Ic d={<><path d="M2 2l20 20M8.5 15.5a5 5 0 0 1 7 0M5 12a10 10 0 0 1 3-2M19 12a10 10 0 0 0-5-2.7"/><circle cx="12" cy="19" r="1" fill="currentColor"/></>} />,
  github:  <Ic d="M12 2A10 10 0 0 0 9 21.8c.5.1.7-.2.7-.5v-2c-2.8.6-3.4-1.2-3.4-1.2-.4-1.2-1-1.5-1-1.5-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.6 2.4 1.1 3 .9.1-.7.4-1.1.6-1.4-2.2-.3-4.6-1.1-4.6-5 0-1.1.4-2 1-2.7-.1-.3-.4-1.3.1-2.7 0 0 .8-.3 2.7 1a9.4 9.4 0 0 1 5 0c1.9-1.3 2.7-1 2.7-1 .5 1.4.2 2.4.1 2.7.6.7 1 1.6 1 2.7 0 3.9-2.4 4.7-4.6 5 .4.3.7 1 .7 2v2.9c0 .3.2.6.7.5A10 10 0 0 0 12 2Z" />,
  trips:   <Ic d={<><path d="M2 17 9 5l6 4 7-7"/><path d="m22 2-5 1 1 5"/></>} />,
  loader:  <Ic d="M12 3a9 9 0 0 1 9 9" />,
  bolt:    <Ic d="m13 2-9 11h8l-1 9 9-11h-8l1-9Z" />,
  moreH:   <Ic d={<><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></>} />,
  play:    <Ic d="M6 4v16l14-8L6 4Z" fill="currentColor" />,
};

// ── Map ────────────────────────────────────────────────────
function MapCanvas({ w, h, active = null, showLabels = true, simplified = false }) {
  const t = useTheme();
  const toXY = ([nx, ny]) => [nx * w, ny * h];
  const pathFor = (pts) => pts.map(([nx, ny], i) => `${i === 0 ? 'M' : 'L'} ${(nx * w).toFixed(1)} ${(ny * h).toFixed(1)}`).join(' ');
  const dayColors = [t.forest, t.accent, t.blue, t.rose];
  const mapBg    = t.name === 'dark' ? '#2d2920' : '#ece3d0';
  const mapBg2   = t.name === 'dark' ? '#332e24' : '#e0d5bd';
  const hill1    = t.name === 'dark' ? '#352f25' : '#e0d5b9';
  const hill2    = t.name === 'dark' ? '#2e3329' : '#d0dcbc';
  const hill3    = t.name === 'dark' ? '#2a3329' : '#c0cfa8';
  const water    = t.name === 'dark' ? '#3d5560' : '#9ab5bb';
  const road     = t.name === 'dark' ? '#453e32' : '#d4c9b2';
  const labelC   = t.name === 'dark' ? '#a89c85' : '#8a7f6a';
  const gid = `mapbg-${Math.round(w)}-${t.name}`;
  return (
    <svg width={w} height={h} style={{ display: 'block' }}>
      <defs>
        <linearGradient id={gid} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor={mapBg} />
          <stop offset="1" stopColor={mapBg2} />
        </linearGradient>
      </defs>
      <rect width={w} height={h} fill={`url(#${gid})`} />
      {!simplified && <>
        <path d={`M 0 ${h*0.3} Q ${w*0.3} ${h*0.2}, ${w*0.6} ${h*0.35} T ${w} ${h*0.3} L ${w} 0 L 0 0 Z`} fill={hill1} opacity={0.6} />
        <path d={`M 0 ${h*0.6} Q ${w*0.2} ${h*0.5}, ${w*0.4} ${h*0.65} T ${w*0.8} ${h*0.55} L ${w} ${h*0.65} L ${w} ${h} L 0 ${h} Z`} fill={hill2} opacity={0.6} />
        <path d={`M 0 ${h*0.75} Q ${w*0.3} ${h*0.7}, ${w*0.6} ${h*0.8} T ${w} ${h*0.78} L ${w} ${h} L 0 ${h} Z`} fill={hill3} opacity={0.55} />
        <path d={`M 0 ${h*0.85} Q ${w*0.4} ${h*0.75}, ${w*0.7} ${h*0.9} T ${w} ${h*0.72}`} fill="none" stroke={water} strokeWidth={Math.max(2, w/260)} opacity={0.7} />
        <path d={`M ${w*0.2} 0 Q ${w*0.3} ${h*0.2}, ${w*0.25} ${h*0.4}`} fill="none" stroke={water} strokeWidth={Math.max(1.5, w/320)} opacity={0.6} />
        <path d={`M 0 ${h*0.5} Q ${w*0.5} ${h*0.45}, ${w} ${h*0.55}`} fill="none" stroke={road} strokeWidth={1} />
        <path d={`M ${w*0.35} 0 Q ${w*0.4} ${h*0.4}, ${w*0.3} ${h}`} fill="none" stroke={road} strokeWidth={1} />
      </>}
      {showLabels && (
        <g fontFamily={t.sans} fontSize={Math.max(9, w/70)} fill={labelC} fontWeight={500}>
          <text x={w*0.08} y={h*0.78}>LILLE</text>
          <text x={w*0.42} y={h*0.66}>TOURNAI</text>
          <text x={w*0.64} y={h*0.40}>OUDENAARDE</text>
          <text x={w*0.84} y={h*0.17}>GAND</text>
        </g>
      )}
      {ROUTE_DAYS.map((pts, i) => (
        <path key={`s${i}`} d={pathFor(pts)} fill="none" stroke="#000" strokeWidth={Math.max(4, w/90)} opacity={0.12} strokeLinecap="round" strokeLinejoin="round" transform="translate(0 2)" />
      ))}
      {ROUTE_DAYS.map((pts, i) => (
        <path key={i} d={pathFor(pts)} fill="none"
          stroke={dayColors[i]}
          strokeWidth={active === null || active === i ? Math.max(3, w/110) : Math.max(2, w/160)}
          opacity={active === null || active === i ? 1 : 0.35}
          strokeLinecap="round" strokeLinejoin="round" />
      ))}
      {ROUTE_DAYS.map((pts, i) => {
        const [ex, ey] = toXY(pts[pts.length - 1]);
        const R = Math.max(10, w/55);
        return (
          <g key={`m${i}`}>
            <circle cx={ex} cy={ey+6} r={R-4} fill="#000" opacity={0.15} />
            <circle cx={ex} cy={ey} r={R} fill={t.surface} stroke={dayColors[i]} strokeWidth={2.5} />
            <text x={ex} y={ey+R/3} textAnchor="middle" fontFamily={t.sans} fontSize={R*0.85} fontWeight={700} fill={dayColors[i]}>{i+1}</text>
          </g>
        );
      })}
      {(() => {
        const [sx, sy] = toXY(ROUTE[0]);
        const R = Math.max(7, w/80);
        return (
          <g>
            <circle cx={sx} cy={sy+6} r={R-2} fill="#000" opacity={0.15} />
            <circle cx={sx} cy={sy} r={R} fill={t.ink} />
            <circle cx={sx} cy={sy} r={R/2.5} fill={t.surface} />
          </g>
        );
      })()}
    </svg>
  );
}

// ── Elevation profile ──────────────────────────────────────
function ElevProfile({ w, h, up, down, distance, color, filled = true, showMarkers = false }) {
  const t = useTheme();
  const c = color || t.accent;
  const N = 80;
  const seed = up + down + distance;
  const pts = [];
  for (let i = 0; i < N; i++) {
    const x = i / (N - 1);
    const base = Math.sin(x * Math.PI * 1.1) * 0.6 + Math.sin(seed * 0.02 + i * 0.5) * 0.1 + Math.cos(seed * 0.03 + i * 0.2) * 0.07;
    pts.push([x, Math.max(0.05, Math.min(1, base + 0.15))]);
  }
  const path = pts.map(([x, y], i) => `${i === 0 ? 'M' : 'L'} ${(x * w).toFixed(1)} ${((1 - y) * (h - 4) + 2).toFixed(1)}`).join(' ');
  const filledPath = `${path} L ${w} ${h} L 0 ${h} Z`;
  const gid = `elev-${Math.round(seed)}-${w}-${t.name}`;
  return (
    <svg width={w} height={h} style={{ display: 'block' }}>
      <defs>
        <linearGradient id={gid} x1="0" x2="0" y1="0" y2="1">
          <stop offset="0" stopColor={c} stopOpacity="0.35" />
          <stop offset="1" stopColor={c} stopOpacity="0" />
        </linearGradient>
      </defs>
      {filled && <path d={filledPath} fill={`url(#${gid})`} />}
      <path d={path} fill="none" stroke={c} strokeWidth={2} strokeLinejoin="round" />
      {showMarkers && [0.25, 0.55, 0.78].map((x, i) => {
        const p = pts[Math.floor(x * (N-1))];
        const py = (1 - p[1]) * (h - 4) + 2;
        return <circle key={i} cx={x * w} cy={py} r={3} fill={t.surface} stroke={c} strokeWidth={1.5} />;
      })}
    </svg>
  );
}

// ── Alert row ──────────────────────────────────────────────
function sevMap(t) {
  return {
    critical: { c: t.red,    bg: t.redSoft,    label: 'Critique',  ic: I.alert },
    warning:  { c: t.accent, bg: t.accentSoft, label: 'Attention', ic: I.alert },
    nudge:    { c: t.blue,   bg: t.blueSoft,   label: 'Info',      ic: I.info  },
  };
}
function iconFor(key) {
  return ({ info: I.info, sparkles: I.sparkle, wind: I.wind, steep: I.mountain, cross: I.flag, traffic: I.traffic, cobble: I.cobble, water: I.droplet, calendar: I.calendar, train: I.train })[key] || I.info;
}

function AlertRow({ alert, compact }) {
  const t = useTheme();
  const m = sevMap(t)[alert.sev];
  return (
    <div style={{ display: 'flex', gap: 11, padding: compact ? 10 : 13, background: t.surface, border: `1px solid ${t.line}`, borderRadius: 11 }}>
      <div style={{ width: 32, height: 32, borderRadius: 9, background: m.bg, color: m.c, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
        {iconFor(alert.icon)}
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: 'flex', gap: 6, alignItems: 'center', marginBottom: 2 }}>
          <span style={{ fontSize: 12.5, fontWeight: 600, color: t.ink }}>{alert.title}</span>
          <Pill sm color={m.c} bg={m.bg}>{m.label}</Pill>
        </div>
        <div style={{ fontSize: 11.5, color: t.inkSoft, lineHeight: 1.45 }}>{alert.body}</div>
      </div>
    </div>
  );
}

// ── Desktop top nav ────────────────────────────────────────
function TopNav({ active }) {
  const t = useTheme();
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 28px', borderBottom: `1px solid ${t.line}`, background: t.bg }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 30 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Logo />
          <span style={{ fontSize: 15, fontWeight: 600, color: t.ink, letterSpacing: -0.2 }}>Bike Trip Planner</span>
        </div>
        <div style={{ display: 'flex', gap: 2, background: t.surfaceAlt, padding: 3, borderRadius: 9 }}>
          {[{k:'new',l:'Nouveau voyage'},{k:'trips',l:'Mes voyages'}].map(x => (
            <div key={x.k} style={{ padding: '6px 13px', borderRadius: 7, fontSize: 12.5, fontWeight: 500, background: x.k === active ? t.surface : 'transparent', color: x.k === active ? t.ink : t.inkSoft, boxShadow: x.k === active ? t.shadowSoft : 'none' }}>{x.l}</div>
          ))}
        </div>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <button style={{ padding: 8, border: `1px solid ${t.line}`, background: t.surface, borderRadius: 9, color: t.inkSoft, display: 'flex' }}>{I.help}</button>
      </div>
    </div>
  );
}

function Logo({ size = 30 }) {
  const t = useTheme();
  return (
    <div style={{ width: size, height: size, borderRadius: Math.round(size*0.28), background: t.ink, color: t.bg, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: size*0.5, letterSpacing: -1, position: 'relative' }}>
      <svg width={size*0.6} height={size*0.6} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="6" cy="15" r="4" /><circle cx="18" cy="15" r="4" /><path d="m12 15-3-6h5l-2 6 3-6" />
      </svg>
    </div>
  );
}

// Mobile status bar + tab bar
function StatusBar({ dark }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '6px 22px 4px', fontSize: 13, fontWeight: 600, color: dark ? '#f5ede0' : '#1a1814', fontFamily: 'SF Pro Display, -apple-system, sans-serif' }}>
      <span>9:41</span>
      <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
        <svg width="17" height="10" viewBox="0 0 17 11" fill="none"><path d="M8.4 2.9A6 6 0 0 1 12 4.3l1-1a7.5 7.5 0 0 0-9.2 0l1 1a6 6 0 0 1 3.6-1.4Zm0 2.2c1 0 1.9.3 2.5 1l1-1a5.3 5.3 0 0 0-7 0l1 1c.6-.7 1.5-1 2.5-1Zm1.5 2.5-1.5 1.5L7 7.6a2 2 0 0 1 3 0Z" fill="currentColor"/></svg>
        <svg width="14" height="10" viewBox="0 0 16 11" fill="none"><rect x="0.5" y="0.5" width="13" height="9" rx="2.5" stroke="currentColor" fill="none" opacity="0.5"/><rect x="2" y="2" width="10" height="6" rx="1" fill="currentColor"/><path d="M14.5 3.5V6.5" stroke="currentColor" strokeLinecap="round"/></svg>
      </div>
    </div>
  );
}

function TabBar({ active }) {
  const t = useTheme();
  const items = [
    { k: 'home', l: 'Planifier', i: I.plus },
    { k: 'trips', l: 'Voyages', i: I.trips },
    { k: 'faq', l: 'Aide', i: I.help },
    { k: 'me', l: 'Moi', i: I.settings },
  ];
  return (
    <div style={{ display: 'flex', borderTop: `1px solid ${t.line}`, background: t.surface, padding: '8px 0 22px' }}>
      {items.map(x => {
        const a = x.k === active;
        return (
          <div key={x.k} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2, color: a ? t.accent : t.inkMute }}>
            {x.i}<span style={{ fontSize: 10, fontWeight: 500 }}>{x.l}</span>
          </div>
        );
      })}
    </div>
  );
}

Object.assign(window, { ThemeCtx, useTheme, Pill, Card, Btn, Field, Input, I, Ic, MapCanvas, ElevProfile, sevMap, iconFor, AlertRow, TopNav, Logo, StatusBar, TabBar });
