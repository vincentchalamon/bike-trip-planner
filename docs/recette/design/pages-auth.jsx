// pages-auth.jsx — Auth pages + account settings + legal
// Landing page kept here for simplicity

// ─── LANDING ─────────────────────────────────────────────────────────────────
function PageLandingDesktop({ w = 1280, h = 5400 }) {
  const t = useTheme();
  const Section = ({ bg, children, pad = '80px 64px' }) => (
    <section style={{ background: bg || t.bg, padding: pad }}>
      <div style={{ maxWidth: 1080, margin: '0 auto' }}>{children}</div>
    </section>
  );
  const H2 = ({ title, sub }) => (
    <div style={{ textAlign:'center', marginBottom:48 }}>
      <h2 style={{ fontFamily:t.serif, fontSize:44, fontWeight:500, letterSpacing:-1, lineHeight:1.05, margin:0, color:t.ink }}>{title}</h2>
      {sub && <p style={{ fontSize:16, color:t.inkSoft, margin:'14px auto 0', maxWidth:580, lineHeight:1.55 }}>{sub}</p>}
    </div>
  );
  return (
    <div style={{ width:w, minHeight:h, background:t.bg, color:t.ink, fontFamily:t.sans, overflow:'hidden' }}>
      {/* HERO */}
      <div style={{ position:'relative', height:760, overflow:'hidden' }}>
        <div style={{ position:'absolute', inset:0 }}>
          <OsmMap w={w} h={760}/>
          <div style={{ position:'absolute', inset:0, background:'linear-gradient(180deg,rgba(26,20,10,.62)0%,rgba(26,20,10,.48)40%,rgba(26,20,10,.85)100%)' }}/>
        </div>
        <div style={{ position:'relative', display:'flex', alignItems:'center', justifyContent:'space-between', padding:'22px 48px', zIndex:2 }}>
          <div style={{ display:'flex', alignItems:'center', gap:10 }}>
            <Logo size={32}/><span style={{ fontSize:16, fontWeight:600, letterSpacing:-0.2, color:'#fff' }}>Bike Trip Planner</span>
          </div>
          <div style={{ display:'flex', alignItems:'center', gap:16 }}>
            <div style={{ display:'flex', background:'rgba(255,255,255,.12)', borderRadius:6, padding:2, gap:1 }}>
              {['FR','EN'].map((l,i) => (
                <div key={l} style={{ padding:'3px 8px', borderRadius:5, fontSize:12, fontWeight:600, background:i===0?'rgba(255,255,255,.2)':'transparent', color:'#fff', cursor:'pointer' }}>{l}</div>
              ))}
            </div>
            <div style={{ display:'flex', background:'rgba(255,255,255,.12)', borderRadius:6, padding:2, gap:1 }}>
              {['☀','☾'].map((ic,i) => (
                <div key={i} style={{ padding:'3px 9px', borderRadius:5, fontSize:13, background:i===0?'rgba(255,255,255,.2)':'transparent', color:'#fff', cursor:'pointer', lineHeight:1 }}>{ic}</div>
              ))}
            </div>
            <div style={{ padding:'7px 14px', border:'1px solid rgba(255,255,255,.3)', borderRadius:8, fontSize:13, color:'#fff', background:'rgba(255,255,255,.08)' }}>Se connecter</div>
            <div style={{ padding:'7px 14px', background:t.accent, borderRadius:8, fontSize:13, color:'#fff', fontWeight:600 }}>Demander l'accès</div>
          </div>
        </div>
        <div style={{ position:'relative', zIndex:2, textAlign:'center', padding:'80px 48px 0', maxWidth:860, margin:'0 auto' }}>
          <Pill bg="rgba(255,255,255,.14)" color="#fff" style={{ marginBottom:24, border:'1px solid rgba(255,255,255,.22)' }}>
            <span style={{ width:6,height:6,borderRadius:'50%',background:t.accent }}/> Beta privée · accès sur demande
          </Pill>
          <h1 style={{ fontFamily:t.serif, fontSize:72, lineHeight:1.02, fontWeight:500, letterSpacing:-2, margin:0, color:'#fff', textShadow:'0 4px 24px rgba(0,0,0,.4)' }}>
            Votre prochain voyage à vélo,<br/><span style={{ fontStyle:'italic', color:'#f1c999' }}>planifié dans les moindres détails</span>.
          </h1>
          <p style={{ fontSize:18, lineHeight:1.55, color:'rgba(255,255,255,.88)', margin:'24px auto 0', maxWidth:640, textShadow:'0 2px 12px rgba(0,0,0,.3)' }}>
            Komoot, RideWithGPS, Strava, GPX. Dénivelé, météo, hébergements, alertes. Consultable hors-ligne, partageable en un lien.
          </p>
          <div style={{ display:'flex', gap:14, justifyContent:'center', marginTop:32 }}>
            <div style={{ padding:'14px 28px', background:t.accent, borderRadius:10, fontSize:15, color:'#fff', fontWeight:600, display:'flex', alignItems:'center', gap:8, boxShadow:`0 8px 24px ${t.accent}55` }}>
              {React.cloneElement(I.bike,{width:18,height:18})} Créer mon itinéraire
            </div>
            <div style={{ padding:'14px 28px', border:'1px solid rgba(255,255,255,.35)', borderRadius:10, fontSize:15, color:'#fff', background:'rgba(255,255,255,.1)' }}>
              Voir la démo
            </div>
          </div>
        </div>
        {/* Scroll indicator */}
        <div style={{ position:'absolute', bottom:30, left:'50%', transform:'translateX(-50%)', zIndex:2 }}>
          <div style={{ width:24,height:40,borderRadius:999,border:'2px solid rgba(255,255,255,.35)',display:'flex',justifyContent:'center',paddingTop:8 }}>
            <div style={{ width:4,height:8,borderRadius:2,background:'rgba(255,255,255,.6)' }}/>
          </div>
        </div>
      </div>

      {/* HOW IT WORKS — 4 steps */}
      <Section bg={t.bg}>
        <H2 title="De l'import au roadbook, en quatre étapes."/>
        <div style={{ display:'grid', gridTemplateColumns:'repeat(4,1fr)', gap:10 }}>
          {[
            { n:1, i:I.link, t:'Import', d:'Lien Komoot, RWGPS, Strava ou fichier GPX.' },
            { n:2, i:I.map, t:'Aperçu & config', d:'Visualisez l\'itinéraire, réglez profil et préférences.' },
            { n:3, i:I.bolt, t:'Analyse', d:'Dénivelé, surfaces, météo, hébergements, alertes — tout calculé.' },
            { n:4, i:I.sparkle, t:'Personnalisation', d:'Affinez les étapes, sélectionnez vos hébergements, exportez.' },
          ].map((s,idx,arr) => (
            <div key={idx} style={{ textAlign:'center', padding:'22px 10px', background:t.surface, borderRadius:14, border:`1px solid ${t.line}`, boxShadow:t.shadowSoft }}>
              <div style={{ position:'relative', width:52,height:52,margin:'0 auto 16px' }}>
                <div style={{ width:52,height:52,borderRadius:'50%',background:idx===arr.length-1?t.accent:t.accentSoft,border:`2px solid ${t.accent}40`,display:'flex',alignItems:'center',justifyContent:'center',color:idx===arr.length-1?'#fff':t.accent }}>
                  {React.cloneElement(s.i,{width:20,height:20})}
                </div>
                <div style={{ position:'absolute',top:-4,right:-4,width:20,height:20,borderRadius:'50%',background:t.ink,color:t.bg,fontSize:10,fontWeight:700,display:'flex',alignItems:'center',justifyContent:'center' }}>{s.n}</div>
              </div>
              <h3 style={{ fontSize:14,fontWeight:600,margin:0,marginBottom:6,color:t.ink }}>{s.t}</h3>
              <p style={{ fontSize:12,color:t.inkSoft,lineHeight:1.5,margin:0 }}>{s.d}</p>
            </div>
          ))}
        </div>
      </Section>

      {/* FEATURES BENTO */}
      <Section bg={t.surfaceAlt}>
        <H2 title="Tout ce qu'il faut pour rouler serein."/>
        <div style={{ display:'grid', gridTemplateColumns:'repeat(12,1fr)', gridAutoRows:170, gap:12 }}>
          {/* Terrain — tall */}
          <div style={{ gridColumn:'span 5', gridRow:'span 2', background:t.surface, border:`1px solid ${t.line}`, borderRadius:18, padding:24, display:'flex', flexDirection:'column' }}>
            <div style={{ display:'flex', gap:8, alignItems:'center', marginBottom:12 }}>
              <div style={{ width:34,height:34,borderRadius:9,background:t.accentSoft,color:t.accent,display:'flex',alignItems:'center',justifyContent:'center' }}>{React.cloneElement(I.mountain,{width:16,height:16})}</div>
              <Pill sm bg={t.accentSoft} color={t.accentInk} bold>Données terrain</Pill>
            </div>
            <h3 style={{ fontFamily:t.serif, fontSize:24, fontWeight:500, letterSpacing:-0.5, margin:0, marginBottom:8, lineHeight:1.15 }}>Chaque mètre de dénivelé, anticipé.</h3>
            <p style={{ fontSize:13, color:t.inkSoft, lineHeight:1.6, margin:0, marginBottom:14 }}>Surfaces (asphalte, gravier, chemins), pentes abruptes, ruptures de rythme. Repérez les 9 % avant qu'ils ne vous surprennent.</p>
            <div style={{ marginTop:'auto', background:t.surfaceAlt, borderRadius:10, padding:12, border:`1px solid ${t.line}` }}>
              <ElevProfile w={340} h={50} up={412} down={378} distance={42.5}/>
              <div style={{ display:'flex', gap:5, marginTop:8 }}>
                <Pill sm bg={t.redSoft} color={t.red}>Pente 9,2 % · km 28</Pill>
                <Pill sm bg={t.accentSoft} color={t.accentInk}>Gravier · 3 km</Pill>
              </div>
            </div>
          </div>
          {/* Weather */}
          <div style={{ gridColumn:'span 4', background:t.accent, color:'#fff', borderRadius:18, padding:20, position:'relative', overflow:'hidden' }}>
            <div style={{ position:'absolute', top:-16, right:-16, width:100, height:100, borderRadius:'50%', background:'rgba(255,255,255,.1)' }}/>
            <div style={{ position:'absolute', top:16, right:16, color:'rgba(255,255,255,.6)' }}>{React.cloneElement(I.wind,{width:32,height:32})}</div>
            <div style={{ fontSize:11, fontWeight:700, letterSpacing:1.5, textTransform:'uppercase', opacity:.8, marginBottom:8 }}>Météo 14 j.</div>
            <h3 style={{ fontFamily:t.serif, fontSize:20, fontWeight:500, letterSpacing:-0.4, margin:0, marginBottom:6, lineHeight:1.15 }}>Vent, humidité, pluie, confort — sur vos dates exactes.</h3>
            <div style={{ display:'flex', gap:3, marginTop:12 }}>
              {[17,19,22,24,21,18,20].map((d,i) => (
                <div key={i} style={{ flex:1, textAlign:'center' }}>
                  <div style={{ fontSize:9, opacity:.7 }}>J+{i}</div>
                  <div style={{ fontSize:11, fontWeight:600 }}>{d}°</div>
                </div>
              ))}
            </div>
          </div>
          {/* Supply */}
          <div style={{ gridColumn:'span 3', background:t.surface, border:`1px solid ${t.line}`, borderRadius:18, padding:20 }}>
            <div style={{ width:34,height:34,borderRadius:9,background:t.accentSoft,color:t.accent,display:'flex',alignItems:'center',justifyContent:'center',marginBottom:10 }}>{React.cloneElement(I.coffee,{width:16,height:16})}</div>
            <h3 style={{ fontSize:14,fontWeight:600,margin:0,marginBottom:6 }}>Ravitaillement</h3>
            <p style={{ fontSize:12,color:t.inkSoft,lineHeight:1.5,margin:0 }}>Supérettes, fontaines, cafés — filtrés par horaires.</p>
          </div>
          {/* AI — wide */}
          <div style={{ gridColumn:'span 7', gridRow:'span 2', background:'linear-gradient(135deg, #1f1d1a 0%, #3d3428 100%)', color:'#fff', borderRadius:18, padding:28, position:'relative', overflow:'hidden' }}>
            <div style={{ position:'absolute', top:24, right:24, color:t.accent, opacity:.25 }}>{React.cloneElement(I.sparkle,{width:70,height:70})}</div>
            <Pill sm bg="rgba(194,103,30,.2)" color="#f1c999" bold style={{ marginBottom:14 }}>✦ Assistant IA</Pill>
            <h3 style={{ fontFamily:t.serif, fontSize:30, fontWeight:500, letterSpacing:-0.7, margin:0, marginBottom:10, lineHeight:1.05 }}>Décrivez. L'IA compose.</h3>
            <p style={{ fontSize:13.5, color:'rgba(255,255,255,.72)', lineHeight:1.6, margin:0, maxWidth:440 }}>Donnez vos contraintes — distance, dénivelé, dates, région, hébergements — l'IA assemble des étapes cohérentes depuis votre point de départ. Dialogue multi-tours.</p>
            <div style={{ marginTop:18, background:'rgba(255,255,255,.06)', border:'1px solid rgba(255,255,255,.1)', borderRadius:10, padding:12, fontFamily:t.mono, fontSize:11.5, color:'rgba(255,255,255,.85)' }}>
              « 3 jours, 250 km, collines modérées, départ Lille, gîtes »
            </div>
            <div style={{ display:'flex', gap:7, marginTop:10 }}>
              {[{d:'J1',t:'Lille → Tournai',km:'78 km'},{d:'J2',t:'Tournai → Mons',km:'92 km'},{d:'J3',t:'Mons → Dinant',km:'80 km'}].map((s,i)=>(
                <div key={i} style={{ flex:1, background:'rgba(255,255,255,.08)', borderRadius:8, padding:8 }}>
                  <div style={{ fontSize:9.5, color:t.accent, fontWeight:700 }}>{s.d}</div>
                  <div style={{ fontSize:11.5, fontWeight:600, marginTop:2 }}>{s.t}</div>
                  <div style={{ fontSize:10.5, color:'rgba(255,255,255,.5)' }}>{s.km}</div>
                </div>
              ))}
            </div>
          </div>
          {/* Accommodations */}
          <div style={{ gridColumn:'span 5', background:t.surface, border:`1px solid ${t.line}`, borderRadius:18, padding:0, overflow:'hidden', display:'flex' }}>
            <div style={{ flex:1, padding:18 }}>
              <div style={{ width:34,height:34,borderRadius:9,background:t.accentSoft,color:t.accent,display:'flex',alignItems:'center',justifyContent:'center',marginBottom:10 }}>{React.cloneElement(I.bed,{width:16,height:16})}</div>
              <h3 style={{ fontSize:14,fontWeight:600,margin:0,marginBottom:6 }}>9 types d'hébergements</h3>
              <div style={{ display:'flex', gap:4, flexWrap:'wrap' }}>
                {Object.values(ACC_TYPES).map((a,i) => <span key={i} style={{ fontSize:14 }} title={a.label}>{a.icon}</span>)}
              </div>
            </div>
            <div style={{ width:150, position:'relative' }}><OsmMap w={150} h={170} simplified/></div>
          </div>
          {/* Alerts */}
          <div style={{ gridColumn:'span 4', background:'#1f1d1a', color:'#fff', borderRadius:18, padding:20 }}>
            <div style={{ display:'flex', gap:8, marginBottom:8 }}>
              <div style={{ width:30,height:30,borderRadius:8,background:'rgba(255,255,255,.1)',color:t.accent,display:'flex',alignItems:'center',justifyContent:'center' }}>{React.cloneElement(I.alert,{width:14,height:14})}</div>
              <h3 style={{ fontSize:14,fontWeight:600,margin:0,alignSelf:'center' }}>20+ alertes sécurité</h3>
            </div>
            <p style={{ fontSize:12,color:'rgba(255,255,255,.65)',lineHeight:1.5,margin:0,marginBottom:10 }}>Trafic dense, pentes, pavés, coucher de soleil, frontières — avec actions contextuelles.</p>
            <div style={{ display:'flex', gap:5, flexWrap:'wrap' }}>
              <Pill sm bg="rgba(184,68,32,.2)" color="#ffa88a" bold>Critique</Pill>
              <Pill sm bg="rgba(194,103,30,.2)" color="#f1c999" bold>Attention</Pill>
              <Pill sm bg="rgba(61,107,145,.2)" color="#9cc3e0" bold>Info</Pill>
            </div>
          </div>
          {/* Exports */}
          <div style={{ gridColumn:'span 3', background:t.surface, border:`1px solid ${t.line}`, borderRadius:18, padding:20 }}>
            <div style={{ width:34,height:34,borderRadius:9,background:t.accentSoft,color:t.accent,display:'flex',alignItems:'center',justifyContent:'center',marginBottom:10 }}>{React.cloneElement(I.download,{width:16,height:16})}</div>
            <h3 style={{ fontSize:14,fontWeight:600,margin:0,marginBottom:6 }}>Exports GPX / FIT</h3>
            <p style={{ fontSize:12,color:t.inkSoft,lineHeight:1.5,margin:0 }}>Garmin Connect, FIT par étape, GPX enrichi avec waypoints POI.</p>
          </div>
        </div>
      </Section>

      {/* SOURCES (3 real + IA) */}
      <Section bg={t.bg} pad="70px 64px">
        <H2 title="Importez depuis ce que vous avez."/>
        <div style={{ display:'flex', justifyContent:'center', gap:28, flexWrap:'wrap' }}>
          {[
            { n:'Komoot', d:'Tour & Collection', c:'#6AA127', wordmark:'komoot' },
            { n:'RideWithGPS', d:'Route', c:'#ED1C24', wordmark:'RWGPS' },
            { n:'Strava', d:'Activité', c:'#FC4C02', wordmark:'strava' },
            { n:'GPX', d:'Fichier', c:'#5a4e3a', wordmark:'GPX' },
            { n:'IA', d:'Génération IA', c:t.accent, wordmark:'✦ IA' },
          ].map((s,i)=>(
            <div key={i} style={{ display:'flex', flexDirection:'column', alignItems:'center', gap:10, width:110 }}>
              <div style={{ width:72,height:72,borderRadius:17,background:t.surface,border:`1px solid ${t.line}`,display:'flex',alignItems:'center',justifyContent:'center',boxShadow:t.shadowSoft }}>
                <span style={{ fontSize: s.wordmark.length > 5 ? 11 : 18, fontWeight:800, color:s.c, letterSpacing: s.wordmark.length > 5 ? 0.5 : -0.5, textTransform: s.n === 'Strava' || s.n === 'Komoot' ? 'lowercase' : 'none', fontStyle: s.n === 'Komoot' ? 'italic' : 'normal' }}>{s.wordmark}</span>
              </div>
              <div style={{ textAlign:'center' }}>
                <div style={{ fontSize:12.5,fontWeight:600,color:t.ink }}>{s.n}</div>
                <div style={{ fontSize:10.5,color:t.inkMute }}>{s.d}</div>
              </div>
            </div>
          ))}
        </div>
      </Section>

      {/* SCREENS PREVIEW */}
      <Section bg={t.surfaceAlt} pad="80px 64px">
        <H2 title="Sur tous vos écrans." sub="Le même roadbook — desktop pour planifier, mobile pour rouler."/>
        <div style={{ display:'flex', gap:28, justifyContent:'center', alignItems:'flex-start', maxWidth:1000, margin:'0 auto' }}>
          {/* Desktop mockup */}
          <div style={{ flex:'0 0 680px' }}>
            <div style={{ fontSize:11.5, fontWeight:600, color:t.inkSoft, letterSpacing:1, textTransform:'uppercase', marginBottom:10 }}>Desktop</div>
            <div style={{ background:t.ink, borderRadius:12, padding:'4px 4px 0', boxShadow:t.shadow }}>
              {/* Browser chrome */}
              <div style={{ display:'flex', alignItems:'center', gap:6, padding:'6px 12px', marginBottom:4 }}>
                <div style={{ display:'flex', gap:4 }}>
                  {['#ed6a5e','#f5bf4f','#61c554'].map((c,i)=><div key={i} style={{width:8,height:8,borderRadius:'50%',background:c}}/>)}
                </div>
                <div style={{ flex:1, background:'rgba(255,255,255,.1)', borderRadius:4, padding:'2px 10px', fontSize:10, color:'rgba(255,255,255,.5)', fontFamily:'"JetBrains Mono", monospace' }}>biketripplanner.app/trips/1</div>
              </div>
              {/* Screen content */}
              <div style={{ background:t.surface, borderRadius:'0 0 8px 8px', overflow:'hidden', display:'grid', gridTemplateColumns:'200px 1fr 220px', height:280 }}>
                {/* Left: stage list */}
                <div style={{ borderRight:`1px solid ${t.line}`, padding:10, overflow:'hidden' }}>
                  <div style={{ fontSize:9, fontWeight:600, color:t.inkSoft, textTransform:'uppercase', letterSpacing:0.5, marginBottom:8 }}>Étapes</div>
                  {STAGES.slice(0,3).map((s,i) => {
                    const c = [t.forest,t.accent,t.blue][i];
                    return (
                      <div key={i} style={{ display:'flex', gap:6, padding:'6px 4px', borderRadius:6, background:i===1?t.surfaceAlt:'transparent', marginBottom:3 }}>
                        <div style={{ width:18,height:18,borderRadius:'50%',background:c,color:'#fff',display:'flex',alignItems:'center',justifyContent:'center',fontSize:9,fontWeight:700,flexShrink:0 }}>{s.day}</div>
                        <div>
                          <div style={{ fontSize:9.5, fontWeight:600, color:t.ink }}>{s.from} → {s.to}</div>
                          <div style={{ fontSize:8.5, color:t.inkMute }}>{s.km} km</div>
                        </div>
                      </div>
                    );
                  })}
                </div>
                {/* Center: map */}
                <div style={{ overflow:'hidden' }}><OsmMap w={260} h={280} active={1}/></div>
                {/* Right: stage detail */}
                <div style={{ borderLeft:`1px solid ${t.line}`, padding:10, overflow:'hidden' }}>
                  <div style={{ display:'inline-flex', padding:'2px 6px', borderRadius:4, background:t.accent, color:'#fff', fontSize:8.5, fontWeight:700, marginBottom:6 }}>JOUR 2</div>
                  <div style={{ fontFamily:t.serif, fontSize:11, fontWeight:500, marginBottom:6 }}>Roubaix → Tournai</div>
                  <ElevProfile w={200} h={28} up={412} down={378} distance={42.5}/>
                  <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:4, marginTop:6 }}>
                    <div style={{ padding:4, background:t.surfaceAlt, borderRadius:4 }}>
                      <div style={{ fontSize:7.5, color:t.inkMute }}>KM</div>
                      <div style={{ fontSize:10, fontWeight:600 }}>42.5</div>
                    </div>
                    <div style={{ padding:4, background:t.surfaceAlt, borderRadius:4 }}>
                      <div style={{ fontSize:7.5, color:t.inkMute }}>D+</div>
                      <div style={{ fontSize:10, fontWeight:600 }}>+412m</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          {/* Mobile mockup */}
          <div style={{ flex:'0 0 190px' }}>
            <div style={{ fontSize:11.5, fontWeight:600, color:t.inkSoft, letterSpacing:1, textTransform:'uppercase', marginBottom:10 }}>Mobile</div>
            <div style={{ background:t.ink, borderRadius:24, padding:'14px 6px 6px', boxShadow:t.shadow }}>
              {/* Notch */}
              <div style={{ width:60, height:16, background:t.ink, borderRadius:8, margin:'-8px auto 6px' }}/>
              <div style={{ background:t.surface, borderRadius:18, overflow:'hidden' }}>
                {/* Status bar */}
                <div style={{ padding:'4px 14px', display:'flex', justifyContent:'space-between', fontSize:9, fontWeight:600 }}>
                  <span>9:41</span><span>● ●</span>
                </div>
                {/* Map */}
                <OsmMap w={178} h={120} active={1}/>
                {/* Stage info */}
                <div style={{ padding:'8px 12px' }}>
                  <div style={{ display:'inline-flex', padding:'1px 5px', borderRadius:3, background:t.accent, color:'#fff', fontSize:7.5, fontWeight:700, marginBottom:4 }}>JOUR 2</div>
                  <div style={{ fontFamily:t.serif, fontSize:11, fontWeight:500, marginBottom:3 }}>Roubaix → Tournai</div>
                  <div style={{ fontSize:9, color:t.inkSoft, marginBottom:4 }}>42,5 km · +412 m · 4h40</div>
                  <ElevProfile w={154} h={22} up={412} down={378} distance={42.5}/>
                </div>
                {/* Tab bar hint */}
                <div style={{ height:20, borderTop:`1px solid ${t.line}`, display:'flex', alignItems:'center', justifyContent:'center', gap:20 }}>
                  {[t.accent, t.inkMute, t.inkMute].map((c,i) => <div key={i} style={{ width:16, height:3, borderRadius:2, background:c }}/>)}
                </div>
              </div>
            </div>
            <div style={{ marginTop:10, display:'flex', alignItems:'center', gap:5, justifyContent:'center' }}>
              {React.cloneElement(I.wifiOff,{width:12,height:12,color:t.inkMute})}
              <span style={{ fontSize:11, color:t.inkSoft }}>Hors-ligne disponible</span>
            </div>
          </div>
        </div>
      </Section>

      {/* EARLY ACCESS */}
      <Section bg={t.name==='dark'?'#2a2620':'#2a2418'} pad="80px 64px">
        <div style={{ textAlign:'center' }}>
          <h2 style={{ fontFamily:t.serif, fontSize:44, fontWeight:500, letterSpacing:-1, margin:0, marginBottom:16, color:'#fff' }}>Rejoignez la beta privée.</h2>
          <p style={{ fontSize:15, color:'rgba(255,255,255,.72)', maxWidth:500, margin:'0 auto 28px', lineHeight:1.6 }}>Gratuit pendant la phase beta. Invitation par email dès qu'une place se libère.</p>
          <div style={{ display:'flex', gap:8, maxWidth:440, margin:'0 auto', background:'rgba(255,255,255,.08)', borderRadius:11, padding:5, border:'1px solid rgba(255,255,255,.18)' }}>
            <div style={{ flex:1, display:'flex', alignItems:'center', gap:8, padding:'0 12px', fontSize:13.5, color:'rgba(255,255,255,.55)' }}>
              {React.cloneElement(I.mail,{width:14,height:14})} vous@exemple.com
            </div>
            <div style={{ padding:'11px 20px', background:t.accent, borderRadius:7, fontSize:13.5, color:'#fff', fontWeight:600 }}>Demander l'accès</div>
          </div>
        </div>
      </Section>

      {/* FOOTER */}
      <div style={{ padding:'28px 64px', borderTop:`1px solid ${t.line}`, background:t.surfaceAlt }}>
        <div style={{ maxWidth:1080, margin:'0 auto', display:'flex', justifyContent:'space-between', alignItems:'center' }}>
          <div style={{ fontSize:12, color:t.inkMute }}>© 2026 Bike Trip Planner</div>
          <div style={{ display:'flex', gap:18, fontSize:12, color:t.inkSoft }}>
            {['FAQ','Confidentialité','/privacy','Mentions légales','/legal'].filter((x,i)=>i%2===0).map((l,i)=>(
              <span key={i} style={{ cursor:'pointer' }}>{['FAQ','Confidentialité','Mentions légales','GitHub'][i]}</span>
            ))}
            <span style={{ display:'flex', alignItems:'center', gap:4, cursor:'pointer' }}>{React.cloneElement(I.github,{width:12,height:12})} GitHub</span>
          </div>
        </div>
      </div>

      {/* Cookie banner */}
      <div style={{ background:t.surface, borderTop:`1px solid ${t.line}`, padding:'12px 24px', display:'flex', alignItems:'center', justifyContent:'space-between', gap:16, boxShadow:`0 -2px 12px rgba(0,0,0,.08)` }}>
        <div style={{ flex:1, fontSize:12.5, color:t.inkSoft, lineHeight:1.5 }}>
          Cookies techniques essentiels et analytics anonymes (sans IP, sans empreinte de navigateur, sans cross-site tracking).{' '}
          <span style={{ color:t.accent, fontWeight:600, cursor:'pointer' }}>Personnaliser</span>
        </div>
        <div style={{ display:'flex', gap:8, flexShrink:0 }}>
          <Btn variant="ghost" size="sm">Tout refuser</Btn>
          <Btn variant="accent" size="sm">Tout accepter</Btn>
        </div>
      </div>
    </div>
  );
}

// ─── LOGIN ────────────────────────────────────────────────────────────────────
function PageLoginDesktop({ w = 1280, h = 860, state = 'form' }) {
  const t = useTheme();
  return (
    <div style={{ width:w, height:h, background:t.paper||t.bg, color:t.ink, fontFamily:t.sans, display:'flex', alignItems:'center', justifyContent:'center', position:'relative', overflow:'hidden' }}>
      <div style={{ position:'absolute', inset:0, opacity:.3 }}><OsmMap w={w} h={h}/></div>
      <div style={{ position:'absolute', inset:0, background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)' }}/>
      <div style={{ position:'relative', width:440, background:t.surface, border:`1px solid ${t.line}`, borderRadius:18, padding:36, boxShadow:t.shadow }}>
        <div style={{ display:'flex', alignItems:'center', gap:10, marginBottom:24 }}>
          <Logo size={34}/><span style={{ fontSize:16, fontWeight:600 }}>Bike Trip Planner</span>
        </div>
        {state === 'form' && (
          <>
            <h2 style={{ fontFamily:t.serif, fontSize:30, letterSpacing:-0.6, fontWeight:500, margin:0, marginBottom:8 }}>Bon retour.</h2>
            <p style={{ fontSize:13.5, color:t.inkSoft, margin:0, marginBottom:22, lineHeight:1.5 }}>Entrez votre email — nous vous enverrons un lien magique. Pas de mot de passe.</p>
            <div style={{ fontSize:11.5, fontWeight:600, color:t.inkSoft, marginBottom:5, textTransform:'uppercase', letterSpacing:0.4 }}>Email</div>
            <div style={{ display:'flex', alignItems:'center', gap:8, padding:'11px 14px', background:t.bg, border:`1.5px solid ${t.accent}`, borderRadius:10, marginBottom:16 }}>
              {React.cloneElement(I.mail,{width:14,height:14,color:t.accent})}
              <span style={{ fontSize:13.5, color:t.ink, fontFamily:t.mono }}>noe@les-tilleuls.coop</span>
            </div>
            <Btn variant="accent" size="lg" full>Envoyer le lien magique</Btn>
            <div style={{ fontSize:12, color:t.inkSoft, textAlign:'center', marginTop:20 }}>
              Pas encore de compte ? <span style={{ color:t.accent, fontWeight:600, cursor:'pointer' }}>Demander l'accès</span>
            </div>
          </>
        )}
        {state === 'sent' && (
          <>
            <div style={{ width:64,height:64,borderRadius:16,background:t.greenSoft,color:t.green,display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 20px' }}>
              {React.cloneElement(I.check,{width:28,height:28})}
            </div>
            <h2 style={{ fontFamily:t.serif, fontSize:26, fontWeight:500, textAlign:'center', margin:'0 0 10px' }}>Email envoyé, vérifie ta boîte.</h2>
            <p style={{ fontSize:13, color:t.inkSoft, textAlign:'center', lineHeight:1.55, margin:'0 0 22px' }}>Lien envoyé à <strong>noe@les-tilleuls.coop</strong>. Il expire dans 15 minutes.</p>
            {/* Resend disabled (timer) */}
            <Btn variant="ghost" size="lg" full style={{ opacity:0.5, cursor:'not-allowed' }}>Renvoyer un lien (42s)</Btn>
            <div style={{ fontSize:11.5, color:t.inkMute, textAlign:'center', marginTop:10 }}>Le bouton sera actif dans 42 secondes.</div>
          </>
        )}
        {state === 'sent-ready' && (
          <>
            <div style={{ width:64,height:64,borderRadius:16,background:t.greenSoft,color:t.green,display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 20px' }}>
              {React.cloneElement(I.check,{width:28,height:28})}
            </div>
            <h2 style={{ fontFamily:t.serif, fontSize:26, fontWeight:500, textAlign:'center', margin:'0 0 10px' }}>Email envoyé, vérifie ta boîte.</h2>
            <p style={{ fontSize:13, color:t.inkSoft, textAlign:'center', lineHeight:1.55, margin:'0 0 22px' }}>Lien envoyé à <strong>noe@les-tilleuls.coop</strong>. Il expire dans 15 minutes.</p>
            <Btn variant="accent" size="lg" full icon={React.cloneElement(I.mail,{width:14,height:14})}>Renvoyer un lien</Btn>
            <div style={{ fontSize:11.5, color:t.inkSoft, textAlign:'center', marginTop:10 }}>Pas reçu ? Vérifiez vos spams.</div>
          </>
        )}
      </div>
    </div>
  );
}

// ─── AUTH VERIFY ─────────────────────────────────────────────────────────────
function PageAuthVerifyDesktop({ w = 1280, h = 860, state = 'verifying' }) {
  const t = useTheme();
  return (
    <div style={{ width:w, height:h, background:t.bg, display:'flex', alignItems:'center', justifyContent:'center', fontFamily:t.sans, position:'relative', overflow:'hidden' }}>
      <div style={{ position:'absolute', inset:0, opacity:.3 }}><OsmMap w={w} h={h}/></div>
      <div style={{ position:'absolute', inset:0, background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)' }}/>
      <div style={{ position:'relative', width:480, background:t.surface, border:`1px solid ${t.line}`, borderRadius:18, padding:44, boxShadow:t.shadow, textAlign:'center', color:t.ink }}>
        {state === 'verifying' && (
          <>
            <div style={{ width:64,height:64,borderRadius:'50%',border:`3px solid ${t.line}`,borderTopColor:t.accent,margin:'0 auto 20px',animation:'spin 1s linear infinite' }}/>
            <h2 style={{ fontFamily:t.serif, fontSize:26, margin:0, marginBottom:10, fontWeight:500 }}>Vérification du lien…</h2>
            <p style={{ fontSize:13.5, color:t.inkSoft, lineHeight:1.55 }}>Ouverture de votre session dans un instant.</p>
          </>
        )}
        {state === 'expired' && (
          <>
            <div style={{ width:64,height:64,borderRadius:'50%',background:t.redSoft,color:t.red,display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 20px' }}>{React.cloneElement(I.alert,{width:28,height:28})}</div>
            <h2 style={{ fontFamily:t.serif, fontSize:24, margin:0, marginBottom:10, fontWeight:500 }}>Lien expiré ou invalide.</h2>
            <p style={{ fontSize:13, color:t.inkSoft, margin:'0 0 22px', lineHeight:1.55 }}>Les liens magiques expirent après 15 minutes.</p>
            <div style={{ display:'flex', gap:8, justifyContent:'center' }}>
              <Btn variant="accent">Renvoyer un lien</Btn>
              <Btn variant="ghost">Retour</Btn>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

// ─── ACCESS REQUEST ───────────────────────────────────────────────────────────
function PageAccessRequestDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  return (
    <div style={{ width:w, height:h, background:t.bg, display:'flex', alignItems:'center', justifyContent:'center', fontFamily:t.sans, position:'relative', overflow:'hidden' }}>
      <div style={{ position:'absolute', inset:0, opacity:.3 }}><OsmMap w={w} h={h}/></div>
      <div style={{ position:'absolute', inset:0, background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)' }}/>
      <div style={{ position:'relative', width:480, background:t.surface, border:`1px solid ${t.line}`, borderRadius:18, padding:44, boxShadow:t.shadow, textAlign:'center', color:t.ink }}>
        <div style={{ width:64,height:64,borderRadius:16,background:t.accentSoft,color:t.accent,display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 20px' }}>
          {React.cloneElement(I.check,{width:28,height:28})}
        </div>
        <h2 style={{ fontFamily:t.serif, fontSize:30, fontWeight:500, letterSpacing:-0.5, margin:0, marginBottom:12 }}>Demande prise en compte.</h2>
        <p style={{ fontSize:14, color:t.inkSoft, marginBottom:26, lineHeight:1.6 }}>Merci pour votre intérêt ! Votre demande d'accès anticipé a bien été enregistrée. Vous recevrez une invitation par email dès que votre dossier sera analysé.</p>
        <Btn variant="ghost" size="lg" full>Retour à l'accueil</Btn>
      </div>
    </div>
  );
}

// ─── FAQ ──────────────────────────────────────────────────────────────────────
function PageFaqDesktop({ w = 1280, h = 860 }) {
  const t = useTheme(); // Not used as standalone page anymore (FAQ is a modal), but kept for reference
  const faqs = [
    { q:'Quelles sources d\'import sont supportées ?', a:'Komoot (Tours et Collections), RideWithGPS, Strava, fichiers GPX standards, et un générateur IA multi-tours.', open:true },
    { q:'Comment est calculé le dénivelé ?' },
    { q:'Les données météo sont-elles fiables au-delà de 7 jours ?' },
    { q:'Puis-je éditer mes étapes ?' },
    { q:'Comment fonctionne le mode hors-ligne ?' },
    { q:'Mes données sont-elles partagées ?' },
    { q:'Combien coûte l\'outil ?' },
  ];
  return (
    <div style={{ width:w, height:h, background:t.bg, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden' }}>
      <TopBarDesktop/>
      <div style={{ display:'grid', gridTemplateColumns:'240px 1fr', flex:1, minHeight:0 }}>
        <div style={{ padding:'28px 20px', borderRight:`1px solid ${t.line}`, overflowY:'auto', background:t.surface }}>
          <div style={{ fontSize:11.5, fontWeight:600, color:t.inkSoft, letterSpacing:0.5, textTransform:'uppercase', marginBottom:10 }}>Sections</div>
          {['Prise en main','Import & formats','Calculs & météo','Partage','Hors-ligne','Tarifs'].map((s,i)=>(
            <div key={i} style={{ padding:'8px 12px', borderRadius:8, fontSize:13, color:i===0?t.ink:t.inkSoft, background:i===0?t.surfaceAlt:'transparent', fontWeight:i===0?600:500, marginBottom:2, cursor:'pointer' }}>{s}</div>
          ))}
          <div style={{ marginTop:24, padding:14, background:t.surfaceAlt, borderRadius:10, border:`1px solid ${t.line}` }}>
            <div style={{ fontSize:12, color:t.inkSoft }}>Documentation complète sur <span style={{ color:t.accent, fontWeight:600, cursor:'pointer' }}>GitHub →</span></div>
          </div>
        </div>
        <div style={{ padding:'36px 56px', overflowY:'auto' }}>
          <h1 style={{ fontFamily:t.serif, fontSize:40, letterSpacing:-0.7, fontWeight:500, margin:0, marginBottom:8 }}>Foire aux questions</h1>
          <p style={{ fontSize:14, color:t.inkSoft, marginBottom:24, lineHeight:1.5 }}>Tout ce qu'il faut savoir avant votre premier voyage.</p>
          <div style={{ maxWidth:700, display:'flex', flexDirection:'column', gap:8 }}>
            {faqs.map((f,i)=>(
              <div key={i} style={{ border:`1px solid ${t.line}`, borderRadius:12, background:t.surface, overflow:'hidden' }}>
                <div style={{ padding:'16px 20px', display:'flex', alignItems:'center', justifyContent:'space-between', background:f.open?t.surfaceAlt:'transparent', cursor:'pointer' }}>
                  <span style={{ fontSize:14, fontWeight:600 }}>{f.q}</span>
                  <span style={{ color:t.inkMute, display:'flex', transform:f.open?'rotate(90deg)':'none' }}>{I.chevron}</span>
                </div>
                {f.open && <div style={{ padding:'0 20px 16px', fontSize:13, color:t.inkSoft, lineHeight:1.6 }}>{f.a}</div>}
              </div>
            ))}
          </div>
        </div>
      </div>
      <DesktopFooter/>
    </div>
  );
}

// ─── ACCOUNT SETTINGS (new P1) ───────────────────────────────────────────────
function PageAccountSettingsDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  return (
    <div style={{ width:w, height:h, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden', position:'relative' }}>
      <div style={{ position:'absolute', inset:0, opacity:.3 }}><OsmMap w={w} h={h}/></div>
      <div style={{ position:'absolute', inset:0, background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)' }}/>
      <div style={{ position:'relative', zIndex:1, display:'flex', flexDirection:'column', height:'100%' }}>
      <TopBarDesktop/>
      <div style={{ display:'grid', gridTemplateColumns:'240px 1fr', flex:1, minHeight:0 }}>
        {/* Sidebar */}
        <div style={{ padding:'28px 20px', borderRight:`1px solid ${t.line}`, background:t.surface }}>
          <div style={{ display:'flex', alignItems:'center', gap:10, marginBottom:24 }}>
            <div style={{ width:42,height:42,borderRadius:'50%',background:t.accent,color:'#fff',display:'flex',alignItems:'center',justifyContent:'center',fontWeight:700,fontSize:17 }}>N</div>
            <div>
              <div style={{ fontSize:13.5, fontWeight:600 }}>Noé Fritz</div>
              <div style={{ fontSize:11.5, color:t.inkSoft }}>noe@les-tilleuls.coop</div>
            </div>
          </div>
          <div style={{ padding:'9px 12px', borderRadius:8, fontSize:13, color:t.ink, background:t.surfaceAlt, fontWeight:600, marginBottom:2 }}>Mon compte</div>
          <div style={{ marginTop:'auto', paddingTop:24, borderTop:`1px solid ${t.line}`, marginTop:24 }}>
            <div style={{ padding:'9px 12px', borderRadius:8, fontSize:13, color:t.red, cursor:'pointer', display:'flex', alignItems:'center', gap:7 }}>
              {React.cloneElement(I.logout,{width:14,height:14})} Se déconnecter
            </div>
          </div>
        </div>
        {/* Main */}
        <div style={{ padding:'36px 56px', overflowY:'auto' }}>
          <h1 style={{ fontFamily:t.serif, fontSize:36, letterSpacing:-0.6, fontWeight:500, margin:0, marginBottom:28 }}>Mon compte</h1>

          {/* Email */}
          <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:14, padding:22, marginBottom:16 }}>
            <div style={{ fontSize:12.5, fontWeight:600, color:t.inkSoft, marginBottom:10, textTransform:'uppercase', letterSpacing:0.4 }}>Email</div>
            <div style={{ display:'flex', alignItems:'center', gap:12 }}>
              <div style={{ flex:1, padding:'10px 14px', background:t.surfaceAlt, borderRadius:9, fontSize:13.5, fontFamily:t.mono, color:t.ink }}>noe@les-tilleuls.coop</div>
              <Btn variant="ghost" size="sm" icon={I.edit}>Modifier via magic link</Btn>
            </div>
          </div>

          {/* Preferences */}
          <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:14, padding:22, marginBottom:16 }}>
            <div style={{ fontSize:12.5, fontWeight:600, color:t.inkSoft, marginBottom:14, textTransform:'uppercase', letterSpacing:0.4 }}>Préférences</div>
            <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:12 }}>
              <span style={{ fontSize:13.5 }}>Langue préférée</span>
              <div style={{ display:'flex', background:t.surfaceAlt, borderRadius:7, padding:2, gap:1 }}>
                {['FR','EN'].map((l,i)=>(
                  <div key={l} style={{ padding:'5px 12px', borderRadius:5, fontSize:12, fontWeight:600, background:i===0?t.accent:'transparent', color:i===0?'#fff':t.inkSoft, cursor:'pointer' }}>{l}</div>
                ))}
              </div>
            </div>
            <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between' }}>
              <span style={{ fontSize:13.5 }}>Thème</span>
              <div style={{ display:'flex', background:t.surfaceAlt, borderRadius:7, padding:2, gap:1 }}>
                {['Clair','Sombre','Auto'].map((l,i)=>(
                  <div key={l} style={{ padding:'5px 10px', borderRadius:5, fontSize:12, fontWeight:600, background:i===0?t.surface:'transparent', color:i===0?t.ink:t.inkSoft, cursor:'pointer', boxShadow:i===0?t.shadowSoft:'none' }}>{l}</div>
                ))}
              </div>
            </div>
          </div>

          {/* RGPD */}
          <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:14, padding:22, marginBottom:16 }}>
            <div style={{ fontSize:12.5, fontWeight:600, color:t.inkSoft, marginBottom:12, textTransform:'uppercase', letterSpacing:0.4 }}>Mes données (RGPD)</div>
            <p style={{ fontSize:13, color:t.inkSoft, lineHeight:1.55, margin:'0 0 14px' }}>Téléchargez l'intégralité de vos données (voyages, paramètres, historique) au format JSON.</p>
            <Btn variant="ghost" size="sm" icon={I.download}>Télécharger mes données (JSON)</Btn>
          </div>

          {/* Danger zone */}
          <div style={{ background:t.surface, border:`2px solid ${t.red}30`, borderRadius:14, padding:22 }}>
            <div style={{ fontSize:12.5, fontWeight:600, color:t.red, marginBottom:10, textTransform:'uppercase', letterSpacing:0.4 }}>Zone de danger</div>
            <p style={{ fontSize:13, color:t.inkSoft, lineHeight:1.55, margin:'0 0 14px' }}>La suppression du compte est irréversible. Tous vos voyages seront définitivement effacés.</p>
            <Btn variant="danger" size="sm" icon={I.alert}>Supprimer mon compte…</Btn>
          </div>
        </div>
      </div>
      <DesktopFooter/>
      </div>
    </div>
  );
}

// ─── 404 / 500 ────────────────────────────────────────────────────────────────
function PageNotFoundDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  return (
    <div style={{ width:w, height:h, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', position:'relative', overflow:'hidden' }}>
      <div style={{ position:'absolute', inset:0, opacity:.3 }}><OsmMap w={w} h={h}/></div>
      <div style={{ position:'absolute', inset:0, background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)' }}/>
      <div style={{ position:'relative', zIndex:1, display:'flex', flexDirection:'column', height:'100%' }}>
      <TopBarDesktop/>
      <div style={{ flex:1, display:'flex', alignItems:'center', justifyContent:'center', padding:40, textAlign:'center' }}>
        <div style={{ maxWidth:520 }}>
          <div style={{ fontFamily:t.serif, fontSize:160, fontWeight:500, letterSpacing:-6, color:t.accent, lineHeight:1, fontStyle:'italic' }}>404</div>
          <h2 style={{ fontFamily:t.serif, fontSize:34, letterSpacing:-0.6, fontWeight:500, margin:'-10px 0 14px' }}>Hors-piste.</h2>
          <p style={{ fontSize:15, color:t.inkSoft, marginBottom:26, lineHeight:1.55 }}>Cette route n'existe pas — ou le lien de partage a été révoqué.</p>
          <div style={{ display:'flex', gap:10, justifyContent:'center' }}>
            <Btn variant="accent" size="lg" icon={I.home}>Retour à l'accueil</Btn>
            <Btn variant="ghost" size="lg" icon={I.trips}>Mes voyages</Btn>
          </div>
        </div>
      </div>
      <DesktopFooter/>
      </div>
    </div>
  );
}

function PageErrorDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  return (
    <div style={{ width:w, height:h, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', position:'relative', overflow:'hidden' }}>
      <div style={{ position:'absolute', inset:0, opacity:.3 }}><OsmMap w={w} h={h}/></div>
      <div style={{ position:'absolute', inset:0, background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)' }}/>
      <div style={{ position:'relative', zIndex:1, display:'flex', flexDirection:'column', height:'100%' }}>
      <TopBarDesktop/>
      <div style={{ flex:1, display:'flex', alignItems:'center', justifyContent:'center', padding:40, textAlign:'center' }}>
        <div style={{ maxWidth:520 }}>
          <div style={{ width:100,height:100,borderRadius:24,background:t.redSoft,color:t.red,display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 24px' }}>
            {React.cloneElement(I.alert,{width:44,height:44})}
          </div>
          <div style={{ fontSize:11.5,fontWeight:600,color:t.red,letterSpacing:1.5,textTransform:'uppercase',marginBottom:12 }}>Erreur serveur · 500</div>
          <h2 style={{ fontFamily:t.serif, fontSize:36, letterSpacing:-0.6, fontWeight:500, margin:'0 0 14px' }}>Un caillou dans le dérailleur.</h2>
          <p style={{ fontSize:14.5, color:t.inkSoft, marginBottom:20, lineHeight:1.6 }}>Nos mécanos sont sur le coup — ça devrait être corrigé dans quelques minutes.</p>
          <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:10, padding:13, fontSize:11.5, color:t.inkMute, fontFamily:t.mono, textAlign:'left', marginBottom:22 }}>
            request_id: req_9f4a2b1c8e3d<br/>timestamp: 2026-04-24T14:32:18Z
          </div>
          <div style={{ display:'flex', gap:10, justifyContent:'center' }}>
            <Btn variant="accent" size="lg">Réessayer</Btn>
            <Btn variant="ghost" size="lg">Signaler le problème</Btn>
          </div>
        </div>
      </div>
      <DesktopFooter/>
      </div>
    </div>
  );
}

Object.assign(window, {
  PageLandingDesktop, PageLoginDesktop, PageAuthVerifyDesktop,
  PageAccessRequestDesktop, PageFaqDesktop,
  PageAccountSettingsDesktop, PageNotFoundDesktop, PageErrorDesktop,
});
