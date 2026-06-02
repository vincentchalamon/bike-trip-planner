// ui2.jsx — New shared components for the full redesign
// Depends on: ui.jsx (ThemeCtx, useTheme, I, Pill, Btn, Card, etc.)

// ─── OSM Map container (realistic Leaflet-style, no SVG drawings) ────────────
function OsmMap({ w, h, active = null, showMarkers = true, showPOI = false, dark: forceDark }) {
  const t = useTheme();
  const dark = forceDark !== undefined ? forceDark : (t.name === 'dark');

  // OSM tile style colors
  const bg      = dark ? '#1a1f2e' : '#f8f4ec';
  const water   = dark ? '#1a2a3d' : '#a8d8ea';
  const park    = dark ? '#1e2d1e' : '#d4e9c4';
  const road1   = dark ? '#2a2420' : '#ffffff';
  const road2   = dark ? '#252018' : '#f0ece0';
  const build   = dark ? '#1e1c18' : '#e8e0d4';
  const text    = dark ? '#9a9a8a' : '#666050';
  const dayColors = ['#4a7a3e','#c2671e','#3d6b91','#b86a3e'];

  const toXY = ([nx, ny]) => [nx * w, ny * h];

  return (
    <svg width={w} height={h} style={{ display: 'block' }}>
      {/* Base */}
      <rect width={w} height={h} fill={bg}/>
      {/* Parks */}
      <path d={`M 0 ${h*.6} Q ${w*.2} ${h*.5}, ${w*.4} ${h*.65} T ${w*.8} ${h*.55} L ${w} ${h*.65} L ${w} ${h} L 0 ${h} Z`} fill={park} opacity={0.7}/>
      {/* Water */}
      <path d={`M 0 ${h*.85} Q ${w*.4} ${h*.75}, ${w*.7} ${h*.9} T ${w} ${h*.72}`} fill="none" stroke={water} strokeWidth={Math.max(3, w/120)} opacity={0.9}/>
      <path d={`M ${w*.2} 0 Q ${w*.3} ${h*.2}, ${w*.25} ${h*.4}`} fill="none" stroke={water} strokeWidth={Math.max(2, w/180)} opacity={0.8}/>
      {/* Buildings */}
      {Array.from({length: 18}).map((_, i) => {
        const x = (i * 73 + 15) % (w - 30); const y = (i * 57 + 20) % (h - 20);
        return <rect key={i} x={x} y={y} width={18+i%12} height={14+i%8} rx={1} fill={build} opacity={0.6}/>;
      })}
      {/* Primary roads */}
      <path d={`M 0 ${h*.5} Q ${w*.5} ${h*.45}, ${w} ${h*.55}`} fill="none" stroke={road1} strokeWidth={3} opacity={0.8}/>
      <path d={`M ${w*.35} 0 Q ${w*.4} ${h*.4}, ${w*.3} ${h}`} fill="none" stroke={road1} strokeWidth={3} opacity={0.7}/>
      {/* Secondary roads */}
      <path d={`M 0 ${h*.3} Q ${w*.3} ${h*.28}, ${w*.6} ${h*.35}`} fill="none" stroke={road2} strokeWidth={1.5}/>
      <path d={`M ${w*.6} 0 Q ${w*.65} ${h*.3}, ${w*.7} ${h*.6}`} fill="none" stroke={road2} strokeWidth={1.5}/>
      {/* City names */}
      <g fontFamily={t.sans} fontSize={Math.max(9, w/80)} fill={text} fontWeight={500}>
        <text x={w*.06} y={h*.78}>Lille</text>
        <text x={w*.4} y={h*.67}>Tournai</text>
        <text x={w*.63} y={h*.42}>Oudenaarde</text>
        <text x={w*.84} y={h*.18}>Gand</text>
      </g>
      {/* Route polylines */}
      {ROUTE_DAYS.map((pts, i) => {
        const pathD = pts.map(([nx, ny], j) => `${j===0?'M':'L'} ${(nx*w).toFixed(1)} ${(ny*h).toFixed(1)}`).join(' ');
        const c = dayColors[i];
        return (
          <g key={i}>
            <path d={pathD} fill="none" stroke="#000" strokeWidth={6} opacity={0.12} strokeLinecap="round" strokeLinejoin="round" transform="translate(0 2)"/>
            <path d={pathD} fill="none" stroke={c} strokeWidth={active===null||active===i?4:2.5}
              opacity={active===null||active===i?1:0.3} strokeLinecap="round" strokeLinejoin="round"/>
          </g>
        );
      })}
      {/* Stage end markers */}
      {showMarkers && ROUTE_DAYS.map((pts, i) => {
        const [ex, ey] = toXY(pts[pts.length-1]);
        const c = dayColors[i];
        const R = Math.max(11, w/60);
        return (
          <g key={`m${i}`}>
            <circle cx={ex} cy={ey+5} r={R-2} fill="#000" opacity={0.2}/>
            <circle cx={ex} cy={ey} r={R} fill="#fff" stroke={c} strokeWidth={2.5}/>
            <text x={ex} y={ey+R*.35} textAnchor="middle" fontFamily={t.sans} fontSize={R*.85} fontWeight={700} fill={c}>{i+1}</text>
          </g>
        );
      })}
      {/* Start marker */}
      {(() => {
        const [sx, sy] = toXY(ROUTE[0]);
        return (
          <g>
            <circle cx={sx} cy={sy+5} r={6} fill="#000" opacity={0.2}/>
            <circle cx={sx} cy={sy} r={8} fill={t.name==='dark'?'#f0ebe0':'#1f1d1a'}/>
            <circle cx={sx} cy={sy} r={3} fill="#fff"/>
          </g>
        );
      })()}
      {/* POI icons — see pulsating cultural POIs below */}
      {/* Pulsating cultural POI markers */}
      {showPOI && (
        <g>
          {/* Cultural POIs with pulse halo */}
          {[
            { cx: w*.25, cy: h*.62, icon: '🏛️', label: 'Villa Cavrois' },
            { cx: w*.52, cy: h*.44, icon: '🏰', label: 'Château de Tournai' },
            { cx: w*.72, cy: h*.32, icon: '⛪', label: 'Église St-Walburge' },
          ].map((poi, pi) => (
            <g key={`cpoi-${pi}`}>
              {/* Animated pulse halo */}
              <circle cx={poi.cx} cy={poi.cy} r={14} fill="none" stroke={dark?'#f1c999':'#c2671e'} strokeWidth={1.5} opacity={0.6}>
                <animate attributeName="r" values="8;18;8" dur="2s" repeatCount="indefinite"/>
                <animate attributeName="opacity" values="0.7;0.1;0.7" dur="2s" repeatCount="indefinite"/>
              </circle>
              <circle cx={poi.cx} cy={poi.cy} r={10} fill="none" stroke={dark?'#f1c999':'#c2671e'} strokeWidth={1} opacity={0.35}>
                <animate attributeName="r" values="10;22;10" dur="2s" repeatCount="indefinite" begin="0.3s"/>
                <animate attributeName="opacity" values="0.5;0;0.5" dur="2s" repeatCount="indefinite" begin="0.3s"/>
              </circle>
              {/* POI dot */}
              <circle cx={poi.cx} cy={poi.cy} r={8} fill={dark?'#2a2418':'#fff'} stroke={dark?'#f1c999':'#c2671e'} strokeWidth={2}/>
              <text x={poi.cx} y={poi.cy+3.5} textAnchor="middle" fontSize={9}>{poi.icon}</text>
            </g>
          ))}
          {/* Non-cultural POIs (static, no pulse) */}
          <circle cx={w*.38} cy={h*.7} r={6} fill="#3d6b91" stroke="#fff" strokeWidth={1.5}/>
          <circle cx={w*.15} cy={h*.55} r={5} fill="#4a7a3e" stroke="#fff" strokeWidth={1.5}/>
          <circle cx={w*.60} cy={h*.58} r={5} fill="#8a5a2e" stroke="#fff" strokeWidth={1.5}/>
        </g>
      )}
      {/* OSM attribution */}
      <text x={w-8} y={h-5} textAnchor="end" fontFamily={t.sans} fontSize={9} fill={dark?'rgba(255,255,255,0.4)':'rgba(0,0,0,0.4)'}>© OpenStreetMap contributors</text>
    </svg>
  );
}

// ─── Elevation profile ────────────────────────────────────────────────────────
function ElevProfile({ w, h, up, down, distance, color, filled = true, showMarkers = false }) {
  const t = useTheme();
  const c = color || t.accent;
  const N = 80;
  const seed = up + down + distance;
  const pts = [];
  for (let i = 0; i < N; i++) {
    const x = i / (N-1);
    const base = Math.sin(x*Math.PI*1.1)*0.6 + Math.sin(seed*0.02+i*0.5)*0.1 + Math.cos(seed*0.03+i*0.2)*0.07;
    pts.push([x, Math.max(0.05, Math.min(1, base+0.15))]);
  }
  const path = pts.map(([x,y],i) => `${i===0?'M':'L'} ${(x*w).toFixed(1)} ${((1-y)*(h-4)+2).toFixed(1)}`).join(' ');
  const gid = `elev-${Math.round(seed)}-${Math.round(w)}-${t.name}`;
  return (
    <svg width={w} height={h} style={{display:'block'}}>
      <defs>
        <linearGradient id={gid} x1="0" x2="0" y1="0" y2="1">
          <stop offset="0" stopColor={c} stopOpacity="0.35"/>
          <stop offset="1" stopColor={c} stopOpacity="0"/>
        </linearGradient>
      </defs>
      {filled && <path d={`${path} L ${w} ${h} L 0 ${h} Z`} fill={`url(#${gid})`}/>}
      <path d={path} fill="none" stroke={c} strokeWidth={2} strokeLinejoin="round"/>
      {showMarkers && [0.25,0.55,0.78].map((x,i) => {
        const p = pts[Math.floor(x*(N-1))];
        const py = (1-p[1])*(h-4)+2;
        return <circle key={i} cx={x*w} cy={py} r={3} fill={t.surface} stroke={c} strokeWidth={1.5}/>;
      })}
    </svg>
  );
}

// ─── Top bar desktop (full version) ──────────────────────────────────────────
function TopBarDesktop({ page = 'none', showUndo = false, showShare = false, onShare, lang = 'FR', dark: isDark, onToggleDark }) {
  const t = useTheme();
  return (
    <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', padding:'0 24px', borderBottom:`1px solid ${t.line}`, background:t.bg, height:52, flexShrink:0, gap:8 }}>
      {/* Left: logo + nav */}
      <div style={{ display:'flex', alignItems:'center', gap:20 }}>
        <div style={{ display:'flex', alignItems:'center', gap:8 }}>
          <Logo size={28}/>
          <span style={{ fontSize:14, fontWeight:600, color:t.ink, letterSpacing:-0.2 }}>Bike Trip Planner</span>
        </div>
        <div style={{ display:'flex', gap:2, background:t.surfaceAlt, padding:3, borderRadius:8 }}>
          {[{k:'new',l:'Nouveau voyage'},{k:'trips',l:'Mes voyages'}].map(x => (
            <div key={x.k} style={{ padding:'5px 12px', borderRadius:6, fontSize:12.5, fontWeight:500, background:x.k===page?t.surface:'transparent', color:x.k===page?t.ink:t.inkSoft, boxShadow:x.k===page?t.shadowSoft:'none', cursor:'pointer' }}>{x.l}</div>
          ))}
        </div>
      </div>

      {/* Right */}
      <div style={{ display:'flex', alignItems:'center', gap:6 }}>
        {/* Undo/Redo — only on roadbook */}
        {showUndo && (
          <div style={{ display:'flex', gap:2, marginRight:4 }}>
            <div style={{ width:30, height:30, borderRadius:7, border:`1px solid ${t.line}`, background:t.surface, display:'flex', alignItems:'center', justifyContent:'center', color:t.inkSoft, cursor:'pointer' }}>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
            </div>
            <div style={{ width:30, height:30, borderRadius:7, border:`1px solid ${t.line}`, background:t.surface, display:'flex', alignItems:'center', justifyContent:'center', color:t.inkSoft, cursor:'pointer', opacity:0.45 }}>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13"/></svg>
            </div>
          </div>
        )}
        {/* Share button */}
        {showShare && (
          <Btn variant="accent" size="sm" icon={I.share} style={{ marginRight:4 }}>Partager</Btn>
        )}
        {/* Help */}
        <div style={{ width:30, height:30, borderRadius:7, border:`1px solid ${t.line}`, background:t.surface, display:'flex', alignItems:'center', justifyContent:'center', color:t.inkSoft, cursor:'pointer', fontSize:13, fontWeight:700 }}>?</div>
        {/* Language pills */}
        <div style={{ display:'flex', background:t.surfaceAlt, borderRadius:6, padding:'2px', gap:1 }}>
          {['FR','EN'].map(l => (
            <div key={l} style={{ padding:'3px 8px', borderRadius:5, fontSize:11, fontWeight:600, background:l===lang?t.surface:'transparent', color:l===lang?t.ink:t.inkSoft, cursor:'pointer', boxShadow:l===lang?t.shadowSoft:'none' }}>{l}</div>
          ))}
        </div>

        {/* Theme toggle */}
        <div style={{ display:'flex', background:t.surfaceAlt, borderRadius:6, padding:'2px', gap:1 }}>
          {[{icon:'☀',dark:false},{icon:'☾',dark:true}].map((l,i) => (
            <div key={i} style={{ padding:'3px 9px', borderRadius:5, fontSize:13, background:(t.name==='dark'===l.dark)?t.surface:'transparent', color:(t.name==='dark'===l.dark)?t.ink:t.inkSoft, cursor:'pointer', boxShadow:(t.name==='dark'===l.dark)?t.shadowSoft:'none', lineHeight:1 }}>{l.icon}</div>
          ))}
        </div>
        {/* Profile button */}
        <div style={{ width:30, height:30, borderRadius:'50%', background:t.accent, color:'#fff', display:'flex', alignItems:'center', justifyContent:'center', fontWeight:600, fontSize:13, cursor:'pointer', flexShrink:0 }}>N</div>
      </div>
    </div>
  );
}

// ─── Desktop footer ────────────────────────────────────────────────────────────
function DesktopFooter() {
  const t = useTheme();
  return (
    <div style={{ padding:'12px 28px', borderTop:`1px solid ${t.line}`, background:t.surfaceAlt, display:'flex', justifyContent:'space-between', alignItems:'center', flexShrink:0 }}>
      <div style={{ fontSize:11, color:t.inkMute }}>© 2026 Bike Trip Planner</div>
      <div style={{ display:'flex', gap:18, fontSize:11.5, color:t.inkSoft }}>
        {['FAQ','Confidentialité','Mentions légales','GitHub'].map((l,i) => (
          <span key={i} style={{ cursor:'pointer', display:'flex', alignItems:'center', gap:4 }}>{i===3?<>{React.cloneElement(I.github,{width:12,height:12})}{l}</>:l}</span>
        ))}
      </div>
    </div>
  );
}

// ─── Cookie banner ─────────────────────────────────────────────────────────────
function CookieBanner() {
  const t = useTheme();
  return (
    <div style={{ position:'absolute', bottom:0, left:0, right:0, background:t.surface, borderTop:`1px solid ${t.line}`, padding:'12px 24px', display:'flex', alignItems:'center', justifyContent:'space-between', gap:16, zIndex:20, boxShadow:`0 -2px 12px rgba(0,0,0,0.08)` }}>
      <div style={{ flex:1, fontSize:12.5, color:t.inkSoft, lineHeight:1.5 }}>
        Cookies techniques essentiels et analytics anonymes (sans IP, sans empreinte de navigateur, sans cross-site tracking). <span style={{ color:t.accent, fontWeight:600, cursor:'pointer' }}>Personnaliser</span>
      </div>
      <div style={{ display:'flex', gap:8, flexShrink:0 }}>
        <Btn variant="ghost" size="sm">Tout refuser</Btn>
        <Btn variant="accent" size="sm">Tout accepter</Btn>
      </div>
    </div>
  );
}

// ─── Alert with actions (P0.8) ─────────────────────────────────────────────────
const ACTION_META = {
  AUTO_FIX: { label:'Corriger', icon:'wrench' },
  DETOUR:   { label:'Itinéraire alternatif', icon:'map' },
  NAVIGATE: { label:'Naviguer', icon:'navigation' },
  DISMISS:  { label:'Écarter', icon:'check' },
};
const ICON_FOR_ACTION = {
  wrench: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>,
  map: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>,
  navigation: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>,
  check: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"/></svg>,
};

function AlertGroup({ alerts, compact = false }) {
  const t = useTheme();
  const [collapsed, setCollapsed] = React.useState({});

  const grouped = { critical: [], warning: [], nudge: [] };
  (alerts || []).forEach(a => (grouped[a.sev] || grouped.nudge).push(a));

  const sevStyle = {
    critical: { c: t.red,    bg: t.redSoft,    label: 'Critique' },
    warning:  { c: t.accent, bg: t.accentSoft, label: 'Attention' },
    nudge:    { c: t.blue,   bg: t.blueSoft,   label: 'Info' },
  };

  return (
    <div style={{ display:'flex', flexDirection:'column', gap:6 }}>
      {['critical','warning','nudge'].map(sev => {
        const items = grouped[sev];
        if (!items.length) return null;
        const s = sevStyle[sev];
        const isCollapsed = collapsed[sev];
        return (
          <div key={sev} style={{ border:`1px solid ${s.c}30`, borderRadius:11, overflow:'hidden' }}>
            {/* Header */}
            <div onClick={() => setCollapsed(p=>({...p,[sev]:!p[sev]}))} style={{ display:'flex', alignItems:'center', gap:8, padding:'8px 12px', background:s.bg, cursor:'pointer', userSelect:'none' }}>
              <Pill sm color={s.c} bg={`${s.c}20`} bold>{s.label}</Pill>
              <span style={{ fontSize:11.5, fontWeight:600, color:s.c }}>{items.length}</span>
              <span style={{ marginLeft:'auto', color:s.c, display:'flex', transform:isCollapsed?'none':'rotate(90deg)', transition:'transform 0.15s' }}>
                {I.chevron}
              </span>
            </div>
            {/* Items */}
            {!isCollapsed && (
              <div style={{ display:'flex', flexDirection:'column', gap:1, background:t.surface }}>
                {items.map((a,i) => (
                  <div key={i} style={{ padding:compact?'8px 12px':'10px 12px', borderTop:i>0?`1px solid ${t.lineSoft}`:undefined }}>
                    <div style={{ fontSize:compact?12:13, fontWeight:600, color:t.ink, marginBottom:2 }}>{a.title}</div>
                    <div style={{ fontSize:11, color:t.inkSoft, lineHeight:1.45, marginBottom:a.action?6:0 }}>{a.body}</div>
                    {a.action && (
                      <button style={{ display:'inline-flex', alignItems:'center', gap:4, padding:'3px 8px', borderRadius:5, border:`1px solid ${s.c}`, background:'transparent', color:s.c, fontSize:10.5, fontWeight:600, cursor:'pointer' }}>
                        {ICON_FOR_ACTION[ACTION_META[a.action]?.icon]}
                        {ACTION_META[a.action]?.label}
                      </button>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}

// Augment STAGES.alerts with action field for demo
const RICH_ALERTS = {
  0: [
    { sev:'nudge', icon:'info', title:'Pause café — Lambersart', body:'Pâtisserie Aux Deux Mondes · km 14,2 · ouvert 07h-19h', action:'NAVIGATE' },
    { sev:'nudge', icon:'sparkles', title:'POI culturel — Villa Cavrois', body:'Monument historique · ouv. 10h-18h · 9 €', action:'NAVIGATE' },
  ],
  1: [
    { sev:'warning', icon:'wind', title:'Vent de face modéré (Headwind)', body:'22 km/h face route sur 60% du tronçon', action:'DISMISS' },
    { sev:'warning', icon:'steep', title:'Pente soutenue km 28–31', body:'Gradient moyen 9,2% sur 2,8 km · sustained ≥ 8%', action:'DETOUR' },
    { sev:'nudge', icon:'cross', title:'Traversée de frontière', body:'France → Belgique · pensez à votre CNI', action:'NAVIGATE' },
  ],
  2: [
    { sev:'critical', icon:'traffic', title:'Route N60 sans piste cyclable', body:'1,3 km route primaire · détour +2,8 km suggéré', action:'DETOUR' },
    { sev:'warning', icon:'cobble', title:'Secteur pavé — Koppenberg', body:'700 m pavés irréguliers · km 24,5', action:'AUTO_FIX' },
    { sev:'nudge', icon:'water', title:'Point d\'eau · km 12,8', body:'Fontaine publique · qualité potable vérifiée', action:'NAVIGATE' },
  ],
  3: [
    { sev:'nudge', icon:'calendar', title:'Dimanche — commerces fermés', body:'Possiblement après 13h', action:'DISMISS' },
    { sev:'nudge', icon:'train', title:'Gare SNCB à 1,2 km', body:'Retour direct Gand → Lille (1h45)', action:'NAVIGATE' },
  ],
};

// ─── 9 Accommodation types ───────────────────────────────────────────────────
const ACC_TYPES = {
  hotel:         { label:'Hôtel',           icon:'🏨', color:'#3d6b91' },
  motel:         { label:'Motel',           icon:'🛣️', color:'#5a6b7a' },
  guest_house:   { label:'Chambre d\'hôtes',icon:'🏡', color:'#6b5a3e' },
  chalet:        { label:'Chalet',          icon:'🏔️', color:'#3e6b4a' },
  hostel:        { label:'Auberge',         icon:'🏠', color:'#8a5a2e' },
  alpine_hut:    { label:'Refuge alpin',    icon:'⛺', color:'#5a3e7a' },
  camp_site:     { label:'Camping',         icon:'🏕️', color:'#4a7a3e' },
  wilderness_hut:{ label:'Abri',            icon:'🛖', color:'#6b4a2e' },
  shelter:       { label:'Bivouac',         icon:'🌿', color:'#3e6b3e' },
};

// ─── Enhanced weather card ────────────────────────────────────────────────────
function WeatherCard({ weather, stageKm = 0 }) {
  const t = useTheme();
  if (!weather) return null;

  // Simulate enhanced data
  const comfort = weather.wind > 20 ? 42 : weather.wind > 10 ? 68 : 82;
  const humidity = 55 + Math.round(weather.wind * 1.2);
  const rainProb = weather.wind > 20 ? 35 : 12;
  const windType = weather.windDir === 'W' || weather.windDir === 'SW' ? 'headwind' :
                   weather.windDir === 'E' || weather.windDir === 'NE' ? 'tailwind' : 'crosswind';
  const windLabel = { headwind:'Vent de face', tailwind:'Vent dans le dos', crosswind:'Vent latéral' }[windType];
  const windColor = windType === 'headwind' ? t.red : windType === 'tailwind' ? t.green : t.accent;
  const comfortColor = comfort > 70 ? t.green : comfort > 40 ? t.accent : t.red;

  return (
    <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, padding:14 }}>
      <div style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start', marginBottom:12 }}>
        <div>
          <div style={{ fontSize:10.5, color:t.inkSoft, fontWeight:600, letterSpacing:0.4, textTransform:'uppercase', marginBottom:4 }}>Météo</div>
          <div style={{ fontSize:26, fontWeight:600, lineHeight:1 }}>{weather.t}°C</div>
        </div>
        <div style={{ textAlign:'right' }}>
          <div style={{ fontSize:12, fontWeight:600, color:comfortColor, marginBottom:2 }}>Confort {comfort}/100</div>
          <div style={{ width:80, height:6, background:t.surfaceAlt, borderRadius:3, overflow:'hidden' }}>
            <div style={{ width:`${comfort}%`, height:'100%', background:comfortColor, borderRadius:3 }}/>
          </div>
        </div>
      </div>
      <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:8 }}>
        <div style={{ padding:'8px 10px', background:t.surfaceAlt, borderRadius:8 }}>
          <div style={{ fontSize:9.5, color:t.inkMute, fontWeight:600, letterSpacing:0.4, textTransform:'uppercase', marginBottom:3 }}>Vent</div>
          <div style={{ fontSize:13.5, fontWeight:600, color:windColor }}>{weather.wind} km/h</div>
          <div style={{ fontSize:10.5, color:windColor, fontWeight:600 }}>{windLabel}</div>
        </div>
        <div style={{ padding:'8px 10px', background:t.surfaceAlt, borderRadius:8 }}>
          <div style={{ fontSize:9.5, color:t.inkMute, fontWeight:600, letterSpacing:0.4, textTransform:'uppercase', marginBottom:3 }}>Humidité</div>
          <div style={{ fontSize:13.5, fontWeight:600 }}>{humidity}%</div>
          <div style={{ fontSize:10.5, color:t.inkSoft }}>Pluie {rainProb}%</div>
        </div>
      </div>
    </div>
  );
}

// ─── Supply timeline ──────────────────────────────────────────────────────────
function SupplyTimeline({ stageKm = 42.5 }) {
  const t = useTheme();
  const markers = [
    { km: 8.2, type: 'water', label: 'Fontaine' },
    { km: 14.5, type: 'food', label: 'Boulangerie' },
    { km: 22.1, type: 'water', label: 'Aire de repos' },
    { km: 28.8, type: 'bike', label: 'Atelier vélo' },
    { km: 36.4, type: 'food', label: 'Supermarché' },
  ];
  const typeColor = { water:'#3d6b91', food:'#4a7a3e', bike:'#c2671e' };
  const typeIcon = { water:'💧', food:'🛒', bike:'🔧' };

  return (
    <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, padding:14 }}>
      <div style={{ fontSize:11, fontWeight:600, color:t.inkSoft, letterSpacing:0.4, textTransform:'uppercase', marginBottom:10 }}>Timeline ravitaillement</div>
      <div style={{ position:'relative', height:40 }}>
        {/* Track */}
        <div style={{ position:'absolute', left:0, right:0, top:'50%', height:3, background:t.surfaceAlt, borderRadius:2, transform:'translateY(-50%)' }}/>
        {/* Start/end labels */}
        <div style={{ position:'absolute', left:0, bottom:-18, fontSize:10, color:t.inkMute, fontFamily:t.mono }}>0 km</div>
        <div style={{ position:'absolute', right:0, bottom:-18, fontSize:10, color:t.inkMute, fontFamily:t.mono }}>{stageKm} km</div>
        {/* Markers */}
        {markers.map((m, i) => {
          const pct = (m.km / stageKm) * 100;
          const c = typeColor[m.type];
          return (
            <div key={i} title={`${m.label} · km ${m.km}`} style={{ position:'absolute', left:`${pct}%`, top:'50%', transform:'translate(-50%, -50%)', width:20, height:20, borderRadius:'50%', background:c, border:`2px solid ${t.surface}`, display:'flex', alignItems:'center', justifyContent:'center', fontSize:9, cursor:'pointer', boxShadow:'0 1px 4px rgba(0,0,0,0.2)' }}>
              {typeIcon[m.type]}
            </div>
          );
        })}
      </div>
      {/* Legend */}
      <div style={{ display:'flex', gap:12, marginTop:22 }}>
        {Object.entries(typeColor).map(([k,c]) => (
          <div key={k} style={{ display:'flex', alignItems:'center', gap:4, fontSize:10.5, color:t.inkSoft }}>
            <div style={{ width:10, height:10, borderRadius:'50%', background:c }}/>{typeIcon[k]} {k==='water'?'Eau':k==='food'?'Ravitaillement':'Atelier'}
          </div>
        ))}
      </div>
    </div>
  );
}

// ─── Difficulty gauge ─────────────────────────────────────────────────────────
function DifficultyGauge({ score = 55 }) {
  const t = useTheme();
  const color = score >= 70 ? t.red : score >= 40 ? t.accent : t.green;
  const label = score >= 70 ? 'Difficile' : score >= 40 ? 'Intermédiaire' : 'Facile';
  const segments = [
    { label:'Facile', color:t.green, w:40 },
    { label:'Intermédiaire', color:t.accent, w:35 },
    { label:'Difficile', color:t.red, w:25 },
  ];
  return (
    <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, padding:14 }}>
      <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:8 }}>
        <div style={{ fontSize:11, fontWeight:600, color:t.inkSoft, letterSpacing:0.4, textTransform:'uppercase' }}>Difficulté</div>
        <div style={{ fontSize:13, fontWeight:700, color }}>{label} · {score}/100</div>
      </div>
      <div style={{ display:'flex', height:8, borderRadius:4, overflow:'hidden', gap:1 }}>
        {segments.map((s,i) => (
          <div key={i} style={{ flex:s.w, background:s.color, opacity:0.3+0.7*(
            s.label==='Facile'?1:
            s.label==='Intermédiaire'&&score>=40?1:
            s.label==='Difficile'&&score>=70?1:0.15
          ) }}/>
        ))}
      </div>
      {/* Marker */}
      <div style={{ position:'relative', height:14, marginTop:2 }}>
        <div style={{ position:'absolute', left:`${score}%`, transform:'translateX(-50%)', width:0, height:0, borderLeft:'5px solid transparent', borderRight:'5px solid transparent', borderBottom:`7px solid ${color}` }}/>
      </div>
    </div>
  );
}

// ─── AI Summary card (Sprint 27) ──────────────────────────────────────────────
function AISummaryCard({ text, global = false }) {
  const t = useTheme();
  return (
    <div style={{ background:t.name==='dark'?`linear-gradient(135deg, #1e1a14, #2a2418)`:t.surfaceAlt, border:`1px solid ${t.line}`, borderRadius:12, padding:14, position:'relative' }}>
      <div style={{ position:'absolute', top:10, right:10, opacity:0.12 }}>{React.cloneElement(I.sparkle,{width:32,height:32})}</div>
      <div style={{ display:'flex', alignItems:'center', gap:6, marginBottom:8 }}>
        <div style={{ width:22, height:22, borderRadius:6, background:t.accent, display:'flex', alignItems:'center', justifyContent:'center' }}>{React.cloneElement(I.sparkle,{width:11,height:11,color:'#fff'})}</div>
        <span style={{ fontSize:11, fontWeight:700, color:t.accent, letterSpacing:0.5, textTransform:'uppercase' }}>{global?'Résumé IA du voyage':'Résumé IA de l\'étape'}</span>
      </div>
      <p style={{ fontFamily:t.serif, fontSize:global?15:13, fontStyle:'italic', lineHeight:1.6, color:t.ink, margin:0 }}>{text}</p>
    </div>
  );
}

// ─── Batch mode floating button (Sprint 24) ──────────────────────────────────
function BatchModeButton({ count = 3 }) {
  const t = useTheme();
  return (
    <div style={{ position:'absolute', bottom:24, right:24, display:'flex', gap:8, alignItems:'center', zIndex:10 }}>
      <button style={{ padding:'8px 14px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:10, fontSize:12.5, fontWeight:500, color:t.inkSoft, cursor:'pointer', boxShadow:t.shadow }}>Annuler toutes</button>
      <button style={{ padding:'8px 16px', background:t.accent, border:'none', borderRadius:10, fontSize:13, fontWeight:600, color:'#fff', cursor:'pointer', boxShadow:`0 4px 16px ${t.accent}55` }}>
        Appliquer ({count} modifications)
      </button>
    </div>
  );
}

// ─── Progress bar recomputation (Sprint 24) ─────────────────────────────────
function RecomputeBar({ progress = 65 }) {
  const t = useTheme();
  return (
    <div style={{ position:'absolute', top:0, left:0, right:0, height:2, background:t.line, zIndex:10 }}>
      <div style={{ height:'100%', width:`${progress}%`, background:t.accent, borderRadius:1, transition:'width 0.3s' }}/>
    </div>
  );
}

// ─── Locked banner ────────────────────────────────────────────────────────────
function LockedBanner() {
  const t = useTheme();
  return (
    <div style={{ background:t.accentSoft, borderBottom:`1px solid ${t.accent}30`, padding:'7px 24px', display:'flex', alignItems:'center', gap:8, fontSize:12.5, color:t.accentInk, flexShrink:0 }}>
      {React.cloneElement(I.lock,{width:13,height:13})}
      <span><strong>Voyage verrouillé</strong> — lecture seule pendant le calcul ou après expiration.</span>
    </div>
  );
}

// ─── Shimmer skeleton ────────────────────────────────────────────────────────
function Shimmer({ w = '100%', h = 16, r = 8, dark: isDark }) {
  const t = useTheme();
  const cls = t.name === 'dark' ? 'dark-shimmer-bg' : 'shimmer-bg';
  return <div className={cls} style={{ width: w, height: h, borderRadius: r }}/>;
}

// ─── AI Chat bubble (Sprint 28) ──────────────────────────────────────────────
function AIChatBubble() {
  const t = useTheme();
  return (
    <div style={{ position:'absolute', bottom:24, right:24, zIndex:10 }}>
      <div style={{ width:48, height:48, borderRadius:'50%', background:t.accent, color:'#fff', display:'flex', alignItems:'center', justifyContent:'center', cursor:'pointer', boxShadow:`0 4px 20px ${t.accent}66, 0 0 0 4px ${t.accentSoft}` }}>
        {React.cloneElement(I.sparkle,{width:20,height:20})}
      </div>
    </div>
  );
}

// ─── POI Popover — Variante A (enrichie) ─────────────────────────────────────
function POIPopoverRich({ dark: forceDark }) {
  const t = useTheme();
  return (
    <div style={{ width:320, background:t.surface, border:`1px solid ${t.line}`, borderRadius:14, boxShadow:t.shadow, overflow:'hidden', fontFamily:t.sans, color:t.ink }}>
      {/* Photo header */}
      <div style={{ height:140, position:'relative', overflow:'hidden' }}>
        <div style={{ width:'100%', height:'100%', background:`linear-gradient(135deg, ${t.name==='dark'?'#2a3020':'#d4dcc0'} 0%, ${t.name==='dark'?'#1e2818':'#c0d0a0'} 100%)`, display:'flex', alignItems:'center', justifyContent:'center' }}>
          <div style={{ textAlign:'center', color:t.inkMute }}>
            <div style={{ fontSize:36, marginBottom:4 }}>🏰</div>
            <div style={{ fontSize:10, fontFamily:t.mono, opacity:0.6 }}>photo Wikimedia P18</div>
          </div>
        </div>
        {/* Category pill overlay */}
        <div style={{ position:'absolute', top:10, left:10 }}>
          <Pill sm bg="rgba(0,0,0,.55)" color="#fff" bold>Château</Pill>
        </div>
        {/* Distance pill */}
        <div style={{ position:'absolute', top:10, right:10 }}>
          <Pill sm bg="rgba(0,0,0,.55)" color="#fff">1,2 km du tracé</Pill>
        </div>
      </div>
      {/* Content */}
      <div style={{ padding:'12px 14px' }}>
        <div style={{ fontSize:15, fontWeight:600, marginBottom:2 }}>Château des Comtes de Tournai</div>
        <div style={{ fontSize:11.5, color:t.inkSoft, lineHeight:1.55, marginBottom:10 }}>
          Forteresse médiévale du XIIe siècle dominant l'Escaut. Collection d'armes et vue panoramique depuis le donjon.
        </div>
        {/* Hours + price */}
        <div style={{ display:'flex', gap:8, marginBottom:10 }}>
          <div style={{ flex:1, padding:'7px 10px', background:t.surfaceAlt, borderRadius:8 }}>
            <div style={{ fontSize:9.5, color:t.inkMute, fontWeight:600, letterSpacing:0.3, textTransform:'uppercase', marginBottom:2 }}>Horaires</div>
            <div style={{ fontSize:12, fontWeight:600, color:t.green }}>Ouvert jusqu'à 18h</div>
          </div>
          <div style={{ padding:'7px 10px', background:t.surfaceAlt, borderRadius:8, minWidth:70 }}>
            <div style={{ fontSize:9.5, color:t.inkMute, fontWeight:600, letterSpacing:0.3, textTransform:'uppercase', marginBottom:2 }}>Prix</div>
            <div style={{ fontSize:12, fontWeight:600 }}>8 €</div>
          </div>
        </div>
        {/* Actions */}
        <div style={{ display:'flex', gap:6 }}>
          <Btn variant="accent" size="sm" icon={React.cloneElement(I.navigation,{width:12,height:12})} style={{ flex:1 }}>Naviguer</Btn>
          <Btn variant="ghost" size="sm" icon={React.cloneElement(I.globe,{width:12,height:12})} style={{ flex:1 }}>Wikipedia</Btn>
        </div>
      </div>
      {/* Arrow pointer */}
      <div style={{ position:'absolute', bottom:-8, left:'50%', transform:'translateX(-50%)', width:0, height:0, borderLeft:'8px solid transparent', borderRight:'8px solid transparent', borderTop:`8px solid ${t.surface}` }}/>
    </div>
  );
}

// ─── POI Popover — Variante A closed hours ───────────────────────────────────
function POIPopoverRichClosed({ dark: forceDark }) {
  const t = useTheme();
  return (
    <div style={{ width:320, background:t.surface, border:`1px solid ${t.line}`, borderRadius:14, boxShadow:t.shadow, overflow:'hidden', fontFamily:t.sans, color:t.ink }}>
      {/* Photo header */}
      <div style={{ height:140, position:'relative', overflow:'hidden' }}>
        <div style={{ width:'100%', height:'100%', background:`linear-gradient(135deg, ${t.name==='dark'?'#2a2028':'#dcccd8'} 0%, ${t.name==='dark'?'#1e1820':'#d0b8c8'} 100%)`, display:'flex', alignItems:'center', justifyContent:'center' }}>
          <div style={{ textAlign:'center', color:t.inkMute }}>
            <div style={{ fontSize:36, marginBottom:4 }}>🏛️</div>
            <div style={{ fontSize:10, fontFamily:t.mono, opacity:0.6 }}>photo Wikimedia P18</div>
          </div>
        </div>
        <div style={{ position:'absolute', top:10, left:10 }}>
          <Pill sm bg="rgba(0,0,0,.55)" color="#fff" bold>Musée</Pill>
        </div>
      </div>
      <div style={{ padding:'12px 14px' }}>
        <div style={{ fontSize:15, fontWeight:600, marginBottom:2 }}>Villa Cavrois</div>
        <div style={{ fontSize:11.5, color:t.inkSoft, lineHeight:1.55, marginBottom:10 }}>
          Chef-d'œuvre moderniste de Robert Mallet-Stevens (1932). Mobilier d'origine et jardin art déco restaurés.
        </div>
        <div style={{ display:'flex', gap:8, marginBottom:10 }}>
          <div style={{ flex:1, padding:'7px 10px', background:t.surfaceAlt, borderRadius:8 }}>
            <div style={{ fontSize:9.5, color:t.inkMute, fontWeight:600, letterSpacing:0.3, textTransform:'uppercase', marginBottom:2 }}>Horaires</div>
            <div style={{ fontSize:12, fontWeight:600, color:t.red }}>Fermé · ouvre demain à 9h</div>
          </div>
          <div style={{ padding:'7px 10px', background:t.surfaceAlt, borderRadius:8, minWidth:70 }}>
            <div style={{ fontSize:9.5, color:t.inkMute, fontWeight:600, letterSpacing:0.3, textTransform:'uppercase', marginBottom:2 }}>Prix</div>
            <div style={{ fontSize:12, fontWeight:600 }}>9 €</div>
          </div>
        </div>
        <div style={{ display:'flex', gap:6 }}>
          <Btn variant="accent" size="sm" icon={React.cloneElement(I.navigation,{width:12,height:12})} style={{ flex:1 }}>Naviguer</Btn>
          <Btn variant="ghost" size="sm" icon={React.cloneElement(I.globe,{width:12,height:12})} style={{ flex:1 }}>Wikipedia</Btn>
        </div>
      </div>
    </div>
  );
}

// ─── POI Popover — Variante B (minimale, OSM seul) ──────────────────────────
function POIPopoverMinimal() {
  const t = useTheme();
  return (
    <div style={{ width:240, background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, boxShadow:t.shadow, padding:'12px 14px', fontFamily:t.sans, color:t.ink }}>
      <div style={{ display:'flex', gap:8, alignItems:'center', marginBottom:10 }}>
        <div style={{ width:32, height:32, borderRadius:8, background:t.surfaceAlt, display:'flex', alignItems:'center', justifyContent:'center', fontSize:16 }}>⛪</div>
        <div>
          <div style={{ fontSize:13.5, fontWeight:600 }}>Église St-Walburge</div>
          <div style={{ fontSize:11, color:t.inkMute }}>place_of_worship · OSM</div>
        </div>
      </div>
      <Btn variant="accent" size="sm" full icon={React.cloneElement(I.navigation,{width:12,height:12})}>Naviguer</Btn>
    </div>
  );
}

// ─── POI Popover — Variante B free ──────────────────────────────────────────
function POIPopoverMinimalFree() {
  const t = useTheme();
  return (
    <div style={{ width:240, background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, boxShadow:t.shadow, padding:'12px 14px', fontFamily:t.sans, color:t.ink }}>
      <div style={{ display:'flex', gap:8, alignItems:'center', marginBottom:10 }}>
        <div style={{ width:32, height:32, borderRadius:8, background:t.surfaceAlt, display:'flex', alignItems:'center', justifyContent:'center', fontSize:16 }}>🔭</div>
        <div>
          <div style={{ fontSize:13.5, fontWeight:600 }}>Belvédère de l'Escaut</div>
          <div style={{ fontSize:11, color:t.inkMute }}>viewpoint · OSM</div>
        </div>
      </div>
      <div style={{ display:'flex', alignItems:'center', gap:5, marginBottom:8 }}>
        <Pill sm bg={t.greenSoft} color={t.green} bold>Gratuit</Pill>
        <span style={{ fontSize:11, color:t.inkMute }}>· accès libre</span>
      </div>
      <Btn variant="accent" size="sm" full icon={React.cloneElement(I.navigation,{width:12,height:12})}>Naviguer</Btn>
    </div>
  );
}

const WIZARD_STEPS = [
  { n: 1, t: 'Préparation',   s: 'Importez votre tracé' },
  { n: 2, t: 'Aperçu',        s: 'Carte, config, affinage IA' },
  { n: 3, t: 'Analyse',       s: 'Traitement complet' },
  { n: 4, t: 'Mon voyage',    s: 'Personnalisez votre roadbook' },
];

function WizardStepper({ active = 1, t }) {
  return (
    <div style={{ padding: '36px 28px', borderRight: `1px solid ${t.line}`, background: t.surface, width: 340, flexShrink: 0, display:'flex', flexDirection:'column' }}>
      <div style={{ fontSize: 11, color: t.inkMute, fontWeight: 600, letterSpacing: 1, textTransform: 'uppercase', marginBottom: 8 }}>Étape {active} sur 4</div>
      <h2 style={{ fontFamily: t.serif, fontSize: 26, margin: 0, marginBottom: 26, letterSpacing: -0.4, fontWeight: 500 }}>{WIZARD_STEPS[active-1].t}</h2>
      {WIZARD_STEPS.map(s => {
        const done = s.n < active;
        const isActive = s.n === active;
        return (
          <div key={s.n} style={{ display: 'flex', gap: 14, padding: '14px 0', borderTop: s.n > 1 ? `1px solid ${t.lineSoft}` : 'none' }}>
            <div style={{ width: 28, height: 28, borderRadius: '50%', flexShrink: 0, background: done ? t.green : isActive ? t.accent : t.surfaceAlt, color: done || isActive ? '#fff' : t.inkSoft, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 700 }}>
              {done ? <Ic d="m5 12 5 5L20 7" size={14} sw={2.5}/> : s.n}
            </div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 13.5, fontWeight: isActive ? 600 : 500, color: isActive || done ? t.ink : t.inkSoft }}>{s.t}</div>
              <div style={{ fontSize: 11.5, color: t.inkMute, marginTop: 2 }}>{s.s}</div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

function MWizardHeader({ step, t }) {
  return (
    <>
      <div style={{ padding: '14px 20px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: `1px solid ${t.line}` }}>
        <div style={{ color: t.inkSoft, display: 'flex' }}>{I.close}</div>
        <span style={{ fontSize: 13, fontWeight: 600 }}>Nouveau voyage · {step}/4</span>
        <div style={{ width: 20 }}/>
      </div>
      <div style={{ height: 3, background: t.surfaceAlt }}>
        <div style={{ height: '100%', width: `${(step/4)*100}%`, background: t.accent, transition: 'width 0.3s' }}/>
      </div>
    </>
  );
}

Object.assign(window, {
  OsmMap, ElevProfile, TopBarDesktop, DesktopFooter, CookieBanner,
  AlertGroup, RICH_ALERTS, ACC_TYPES, WeatherCard, SupplyTimeline,
  DifficultyGauge, AISummaryCard, BatchModeButton, RecomputeBar,
  LockedBanner, Shimmer, AIChatBubble,
  POIPopoverRich, POIPopoverRichClosed, POIPopoverMinimal, POIPopoverMinimalFree,
  WIZARD_STEPS, WizardStepper, MWizardHeader,
});
