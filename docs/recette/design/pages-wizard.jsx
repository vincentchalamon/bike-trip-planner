// pages-wizard.jsx — 4 Wizard steps: Préparation · Aperçu · Analyse · Mon Voyage
// P0 fixes: AI chat multi-turn, narrative progression, 3 presets, Leaflet map

// ──────────────────────────────────────────────────────────────────────────────
// STEP 1 — PRÉPARATION : Lien · GPX · AI Chat multi-tours
// ──────────────────────────────────────────────────────────────────────────────
function PageTripsNewDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  const [mode, setMode] = React.useState('link'); // 'link' | 'gpx' | 'ai'
  const [urlVal, setUrlVal] = React.useState('https://www.komoot.com/tour/2847193');
  const [gpxState, setGpxState] = React.useState('idle'); // idle | hover | uploading | success | error
  const [aiMessages, setAiMessages] = React.useState([
    { role:'assistant', text:'Bonjour ! Décrivez votre envie et je composerai un itinéraire sur mesure. Longueur, région, niveau, nombre de jours, préférences hébergement…' },
    { role:'user', text:'3 jours à vélo depuis Lille, ~50 km/j, collines flamandes, je veux dormir en gîtes.' },
    { role:'assistant', text:'Voici ce que je propose :\n\n**J1 · Lille → Tournai** · 48 km · +340 m · Gîte du Moulin à Eau\n**J2 · Tournai → Oudenaarde** · 52 km · +680 m · Chambre d\'hôtes Au Fil de l\'Escaut\n**J3 · Oudenaarde → Gand** · 38 km · +210 m · Arrivée en centre-ville\n\nTotal : 138 km · +1 230 m D+. Souhaitez-vous ajuster une étape ?' },
    { role:'user', text:'Peux-tu rallonger le J1 jusqu\'à 55 km ?' },
    { role:'assistant', text:'J1 mis à jour : **Lille → Tournai via Roubaix** · 56 km · +412 m — la variante passe par le Parc de la Ferme Dupire et longe la Marque sur 8 km. Gîte conservé. Voulez-vous valider cet itinéraire ?' },
  ]);

  const tabStyle = (k) => ({
    flex:1, padding:'10px 16px', borderRadius:9, fontSize:13, fontWeight:600,
    background: mode===k ? t.surface : 'transparent',
    color: mode===k ? t.ink : t.inkSoft,
    textAlign:'center', cursor:'pointer',
    boxShadow: mode===k ? t.shadowSoft : 'none',
    display:'flex', alignItems:'center', justifyContent:'center', gap:6,
    transition:'all 0.15s',
  });

  const gpxStateUI = {
    idle:      { border: `2px dashed ${t.line}`,   bg: t.surfaceAlt, icon: I.upload,    label:'Glissez un fichier .gpx',    sub:'ou cliquez pour parcourir · 30 Mo max' },
    hover:     { border: `2px dashed ${t.accent}`, bg: t.accentSofter, icon: I.upload,  label:'Déposez votre fichier',        sub:'GPX détecté' },
    uploading: { border: `2px dashed ${t.line}`,   bg: t.surfaceAlt, icon: I.loader,    label:'Import en cours…',            sub:'2,3 Mo · 142 points GPS' },
    success:   { border: `2px solid ${t.green}`,   bg: t.greenSoft,  icon: I.check,     label:'sortie-lombardie.gpx importé', sub:'182 km · +1 280 m D+' },
    error:     { border: `2px solid ${t.red}`,     bg: t.redSoft,    icon: I.alert,     label:'Fichier non reconnu',          sub:'Format GPX requis · taille max 30 Mo' },
  };
  const gpxUI = gpxStateUI[gpxState];

  return (
    <div style={{ width:w, height:h, background:t.bg, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden' }}>
      <TopBarDesktop page="new"/>
      <div style={{ display:'flex', flex:1, minHeight:0 }}>
        <WizardStepper active={1} t={t}/>
        <div style={{ flex:1, display:'flex', flexDirection:'column', minHeight:0 }}>
          <div style={{ flex:1, overflowY:'auto', padding:'36px 56px' }}>
            <div style={{ maxWidth:640 }}>
              <h1 style={{ fontFamily:t.serif, fontSize:38, letterSpacing:-0.7, fontWeight:500, margin:0, marginBottom:8, lineHeight:1.1 }}>D'où vient votre tracé ?</h1>
              <p style={{ fontSize:14, color:t.inkSoft, marginBottom:28, lineHeight:1.55 }}>Collez un lien, importez un GPX, ou laissez l'IA générer votre itinéraire en dialogue.</p>

              {/* Source selector */}
              <div style={{ display:'flex', gap:3, background:t.surfaceAlt, padding:4, borderRadius:12, marginBottom:28 }}>
                <div onClick={()=>setMode('link')} style={tabStyle('link')}>{I.link} Lien URL</div>
                <div onClick={()=>setMode('gpx')} style={tabStyle('gpx')}>{I.upload} Fichier GPX</div>
                <div onClick={()=>setMode('ai')} style={{ ...tabStyle('ai'), color: mode==='ai' ? t.accent : t.inkSoft, borderBottom: mode==='ai' ? `2px solid ${t.accent}` : 'none' }}>
                  {React.cloneElement(I.sparkle,{width:14,height:14,color:mode==='ai'?t.accent:t.inkSoft})} Assistant IA
                </div>
              </div>

              {/* --- LINK MODE --- */}
              {mode === 'link' && (
                <div>
                  <div style={{ fontSize:11, color:t.inkSoft, fontWeight:600, marginBottom:6 }}>URL Komoot · RideWithGPS · Strava — détection automatique</div>
                  <div style={{ display:'flex', alignItems:'center', gap:8, padding:'11px 14px', background:t.surface, border:`1.5px solid ${t.accent}`, borderRadius:10, marginBottom:10 }}>
                    <span style={{ color:t.accent, display:'flex' }}>{I.link}</span>
                    <span style={{ fontSize:13.5, fontFamily:t.mono, flex:1, color:t.ink, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>{urlVal}</span>
                  </div>
                  {/* Detection badge */}
                  <div style={{ display:'inline-flex', alignItems:'center', gap:6, padding:'4px 10px', background:t.greenSoft, borderRadius:6, marginBottom:16 }}>
                    {React.cloneElement(I.check,{width:12,height:12,color:t.green})}
                    <span style={{ fontSize:11.5, color:t.green, fontWeight:600 }}>Komoot Tour détecté</span>
                  </div>
                  {/* Route preview */}
                  <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, overflow:'hidden', marginBottom:24, display:'flex' }}>
                    <div style={{ width:180, flexShrink:0 }}><OsmMap w={180} h={130} simplified/></div>
                    <div style={{ flex:1, padding:'14px 16px' }}>
                      <div style={{ fontFamily:t.serif, fontSize:18, fontWeight:500, letterSpacing:-0.3, marginBottom:4 }}>Flandres côtières</div>
                      <div style={{ fontSize:12, color:t.inkSoft, marginBottom:8 }}>182 km · +1 280 m · 4 étapes suggérées</div>
                      <div style={{ display:'flex', gap:6 }}>
                        <Pill sm bg={t.accentSoft} color={t.accentInk}>Bitume 74 %</Pill>
                        <Pill sm bg={t.surfaceAlt} color={t.inkSoft}>Gravier 22 %</Pill>
                        <Pill sm bg={t.accentSoft} color={t.accentInk}>Intermédiaire</Pill>
                      </div>
                    </div>
                  </div>
                  <Btn variant="accent" size="lg" full icon={React.cloneElement(I.chevron,{width:14,height:14})}>Continuer — Aperçu</Btn>
                </div>
              )}

              {/* --- GPX MODE --- */}
              {mode === 'gpx' && (
                <div>
                  <div onClick={()=>setGpxState(gpxState==='idle'?'hover':gpxState==='hover'?'uploading':gpxState==='uploading'?'success':gpxState==='success'?'error':'idle')}
                    style={{ border:gpxUI.border, borderRadius:14, padding:'36px 24px', textAlign:'center', background:gpxUI.bg, cursor:'pointer', transition:'all 0.2s', marginBottom:16 }}>
                    <div style={{ width:52, height:52, borderRadius:14, background:t.surface, color: gpxState==='success'?t.green:gpxState==='error'?t.red:t.inkSoft, display:'flex', alignItems:'center', justifyContent:'center', margin:'0 auto 14px', boxShadow:t.shadowSoft }}>
                      {React.cloneElement(gpxState==='uploading'?I.loader:gpxUI.icon, { width:24, height:24, className: gpxState==='uploading'?'spin':'' })}
                    </div>
                    <div style={{ fontSize:14, fontWeight:600, color:t.ink, marginBottom:4 }}>{gpxUI.label}</div>
                    <div style={{ fontSize:12, color:t.inkSoft }}>{gpxUI.sub}</div>
                    {gpxState==='uploading' && (
                      <div style={{ marginTop:12, height:4, background:t.surfaceAlt, borderRadius:2, overflow:'hidden', width:200, margin:'12px auto 0' }}>
                        <div style={{ width:'60%', height:'100%', background:t.accent, borderRadius:2, animation:'pulse 1s infinite' }}/>
                      </div>
                    )}
                  </div>
                  <div style={{ fontSize:11, color:t.inkMute, textAlign:'center', marginBottom:24 }}>Cliquez sur la zone pour simuler les états (idle → hover → uploading → succès → erreur)</div>
                  {gpxState === 'success' && (
                    <Btn variant="accent" size="lg" full>Continuer — Aperçu</Btn>
                  )}
                </div>
              )}

              {/* --- AI CHAT MODE (multi-turn) --- */}
              {mode === 'ai' && (
                <div style={{ background:t.surface, border:`1.5px solid ${t.accent}`, borderRadius:16, overflow:'hidden' }}>
                  {/* Chat header */}
                  <div style={{ padding:'12px 16px', background:t.accentSofter, borderBottom:`1px solid ${t.accent}30`, display:'flex', alignItems:'center', gap:8 }}>
                    <div style={{ width:28, height:28, borderRadius:8, background:t.accent, display:'flex', alignItems:'center', justifyContent:'center' }}>
                      {React.cloneElement(I.sparkle,{width:14,height:14,color:'#fff'})}
                    </div>
                    <div>
                      <div style={{ fontSize:13, fontWeight:600, color:t.accentInk }}>Assistant IA · Génération d'itinéraire</div>
                      <div style={{ fontSize:10.5, color:t.accentInk, opacity:0.75 }}>Dialogue multi-tours · LLaMA 8B · contextualisé</div>
                    </div>
                  </div>

                  {/* Messages history */}
                  <div style={{ maxHeight:280, overflowY:'auto', padding:'14px 16px', display:'flex', flexDirection:'column', gap:10 }}>
                    {aiMessages.map((m, i) => (
                      <div key={i} style={{ display:'flex', gap:8, alignItems:'flex-start', flexDirection: m.role==='user'?'row-reverse':'row' }}>
                        {m.role==='assistant' && (
                          <div style={{ width:24, height:24, borderRadius:'50%', background:t.accent, flexShrink:0, display:'flex', alignItems:'center', justifyContent:'center', marginTop:2 }}>
                            {React.cloneElement(I.sparkle,{width:10,height:10,color:'#fff'})}
                          </div>
                        )}
                        <div style={{
                          maxWidth:'80%', padding:'9px 12px', borderRadius:11,
                          background: m.role==='user' ? t.ink : t.surfaceAlt,
                          color: m.role==='user' ? t.bg : t.ink,
                          fontSize:12.5, lineHeight:1.55,
                          borderBottomRightRadius: m.role==='user'?2:11,
                          borderBottomLeftRadius: m.role==='assistant'?2:11,
                        }}>
                          {m.text.split('\n').map((line,j) => (
                            <div key={j} style={{ minHeight: line===''?8:undefined }}>
                              {line.startsWith('**') ? <strong>{line.replace(/\*\*/g,'')}</strong> : line}
                            </div>
                          ))}
                        </div>
                      </div>
                    ))}
                  </div>

                  {/* Input area */}
                  <div style={{ padding:'10px 12px', borderTop:`1px solid ${t.line}`, background:t.bg, display:'flex', gap:8 }}>
                    <div style={{ flex:1, padding:'8px 12px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:9, fontSize:12.5, color:t.inkSoft }}>
                      Affinez ou demandez une modification…
                    </div>
                    <button style={{ padding:'8px 14px', background:t.accent, border:'none', borderRadius:9, color:'#fff', fontSize:12, fontWeight:600, cursor:'pointer', flexShrink:0 }}>Envoyer</button>
                  </div>

                  {/* Validate CTA */}
                  <div style={{ padding:'10px 12px', borderTop:`1px solid ${t.line}`, display:'flex', justifyContent:'flex-end' }}>
                    <Btn variant="primary" size="md" icon={React.cloneElement(I.check,{width:13,height:13})}>Valider et continuer</Btn>
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
      <DesktopFooter/>
    </div>
  );
}

// ──────────────────────────────────────────────────────────────────────────────
// STEP 2 — APERÇU : Map + stats + config + single-shot AI + CTA
// ──────────────────────────────────────────────────────────────────────────────
function PageTripsNewDesktopStep2({ w = 1280, h = 860 }) {
  const t = useTheme();
  return (
    <div style={{ width:w, height:h, background:t.bg, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden' }}>
      <TopBarDesktop page="new"/>
      <div style={{ display:'flex', flex:1, minHeight:0 }}>
        <WizardStepper active={2} t={t}/>
        <div style={{ flex:1, display:'grid', gridTemplateColumns:'1fr 380px', minHeight:0 }}>
          {/* Left: map + elev + stats */}
          <div style={{ display:'flex', flexDirection:'column', overflow:'hidden', borderRight:`1px solid ${t.line}` }}>
            {/* Editable trip title */}
            <div style={{ padding:'12px 16px', borderBottom:`1px solid ${t.line}`, background:t.surface, display:'flex', alignItems:'center', gap:8 }}>
              <span style={{ fontFamily:t.serif, fontSize:18, fontWeight:500, letterSpacing:-0.3, color:t.ink }}>{TRIP.title}</span>
              <div style={{ width:22, height:22, borderRadius:6, background:t.surfaceAlt, color:t.inkSoft, display:'flex', alignItems:'center', justifyContent:'center', cursor:'pointer', flexShrink:0 }}>
                {React.cloneElement(I.edit,{width:11,height:11})}
              </div>
              
            </div>
            <div style={{ flex:1, overflow:'hidden', position:'relative' }}>
              <OsmMap w={900} h={h-310} active={null}/>
            </div>
            {/* Elev */}
            <div style={{ borderTop:`1px solid ${t.line}`, padding:'12px 20px', background:t.surface, flexShrink:0 }}>
              <div style={{ fontSize:11.5, fontWeight:600, marginBottom:6 }}>Profil altimétrique</div>
              <div style={{ display:'flex', justifyContent:'center' }}>
                <ElevProfile w={820} h={60} up={TRIP.totalUp} down={1380} distance={TRIP.totalKm} showMarkers/>
              </div>
            </div>
            {/* Stats */}
            <div style={{ display:'flex', gap:10, padding:'12px 20px', background:t.surfaceAlt, borderTop:`1px solid ${t.line}`, flexShrink:0 }}>
              {[{l:'Distance',v:TRIP.totalKm,u:'km'},{l:'Dénivelé +',v:'+'+TRIP.totalUp,u:'m'},{l:'Dénivelé −',v:'−1 380',u:'m'},{l:'Durée est.',v:`${TRIP.days} j`,u:''}].map((m,i)=>(
                <div key={i} style={{ flex:1, padding:12, background:t.surface, borderRadius:10, border:`1px solid ${t.line}` }}>
                  <div style={{ fontSize:10, color:t.inkSoft, fontWeight:600, letterSpacing:0.4, textTransform:'uppercase' }}>{m.l}</div>
                  <div style={{ fontFamily:t.serif, fontSize:20, fontWeight:500, letterSpacing:-0.3, marginTop:3 }}>{m.v} <span style={{ fontSize:11, color:t.inkSoft, fontFamily:t.sans }}>{m.u}</span></div>
                </div>
              ))}
            </div>
          </div>

          {/* Right: config + AI single-shot */}
          <div style={{ display:'flex', flexDirection:'column', overflowY:'auto', padding:'20px 20px' }}>
            {/* Presets — 3 only */}
            <div style={{ marginBottom:14 }}>
              <div style={{ fontSize:11, color:t.inkSoft, fontWeight:600, letterSpacing:0.4, marginBottom:8, textTransform:'uppercase' }}>Profil cycliste</div>
              <div style={{ display:'flex', gap:5 }}>
                {['Débutant','Intermédiaire','Expert'].map((p,i) => (
                  <div key={i} style={{ flex:1, padding:'8px 6px', borderRadius:8, border:`1.5px solid ${i===1?t.accent:t.line}`, background:i===1?t.accentSofter:'transparent', textAlign:'center', fontSize:11.5, fontWeight:600, color:i===1?t.accentInk:t.inkSoft, cursor:'pointer' }}>{p}</div>
                ))}
              </div>
            </div>
            {/* Pacing sliders */}
            {[
              { l:'Distance max / jour', v:'80 km', pct:28 },
              { l:'Vitesse moyenne',     v:'15 km/h', pct:22 },
              { l:'Heure de départ',     v:'08h00', pct:33 },
              { l:'Fatigue',             v:'10 %', pct:20 },
              { l:'Pénalité dénivelé',  v:'25 %', pct:25 },
            ].map((s,i)=>(
              <div key={i} style={{ display:'flex', alignItems:'center', gap:8, marginBottom:8 }}>
                <span style={{ fontSize:11, color:t.inkSoft, width:110, flexShrink:0 }}>{s.l}</span>
                <div style={{ flex:1, height:4, background:t.surfaceAlt, borderRadius:2, position:'relative' }}>
                  <div style={{ width:`${s.pct}%`, height:'100%', background:t.accent, borderRadius:2 }}/>
                  <div style={{ position:'absolute', left:`calc(${s.pct}% - 6px)`, top:-4, width:12, height:12, borderRadius:'50%', background:'#fff', border:`2px solid ${t.accent}` }}/>
                </div>
                <span style={{ fontSize:11, fontFamily:t.mono, fontWeight:600, color:t.ink, width:46, textAlign:'right', flexShrink:0 }}>{s.v}</span>
              </div>
            ))}
            {/* E-bike */}
            <div style={{ display:'flex', alignItems:'center', gap:8, marginBottom:14, paddingTop:6, borderTop:`1px solid ${t.line}` }}>
              <span style={{ fontSize:11.5, color:t.inkSoft, flex:1 }}>Mode e-bike</span>
              <div style={{ width:34, height:18, borderRadius:9, background:t.surfaceAlt, position:'relative' }}>
                <div style={{ position:'absolute', top:2, left:2, width:14, height:14, borderRadius:'50%', background:'#fff' }}/>
              </div>
            </div>
            {/* Dates + accommodation */}
            <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:8, marginBottom:14 }}>
              <div style={{ padding:'8px 10px', background:t.surface, border:`1.5px solid ${t.accent}`, borderRadius:8, fontSize:12.5, fontFamily:t.mono, fontWeight:600 }}>14 — 17 mai 2026</div>
              <div style={{ padding:'8px 10px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:8 }}>
                <div style={{ fontSize:9.5, color:t.inkMute, marginBottom:2 }}>Hébergements</div>
                <div style={{ display:'flex', gap:3, flexWrap:'wrap' }}>
                  {['Gîte','Hôtel','Camping'].map((x,i) => <Pill key={i} sm bg={i<2?t.accentSofter:t.surfaceAlt} color={i<2?t.accentInk:t.inkSoft} bold>{x}</Pill>)}
                </div>
              </div>
            </div>
            {/* AI single-shot refinement */}
            <div style={{ borderTop:`1px solid ${t.line}`, paddingTop:14, marginBottom:14 }}>
              <div style={{ display:'flex', alignItems:'center', gap:6, marginBottom:8 }}>
                {React.cloneElement(I.sparkle,{width:13,height:13,color:t.accent})}
                <span style={{ fontSize:12.5, fontWeight:600 }}>Affiner avec l'IA</span>
                <span style={{ fontSize:10.5, color:t.inkMute }}>(ajustement ponctuel)</span>
              </div>
              <div style={{ background:t.surface, border:`1.5px solid ${t.accent}`, borderRadius:10, padding:12, marginBottom:8 }}>
                <div style={{ fontSize:12.5, color:t.ink, lineHeight:1.6, minHeight:54 }}>Rallonge l'étape 2, ajoute un café dans les Flandres…</div>
                <div style={{ display:'flex', gap:5, justifyContent:'flex-end', marginTop:6 }}>
                  <div style={{ padding:'4px 10px', background:t.surfaceAlt, borderRadius:6, fontSize:10.5, color:t.inkSoft }}>Effacer</div>
                  <div style={{ padding:'4px 10px', background:t.accent, borderRadius:6, fontSize:10.5, color:'#fff', fontWeight:600 }}>Appliquer</div>
                </div>
              </div>
              {/* Proposed changes */}
              {[{t:'J2 rallongée',d:'38 → 44 km via Menin',c:t.accent},{t:'Café ajouté',d:'Kafé Kasteel, Courtrai · km 24',c:t.green}].map((c,i) => (
                <div key={i} style={{ display:'flex', gap:8, padding:'7px 10px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:8, borderLeft:`3px solid ${c.c}`, marginBottom:4 }}>
                  <div style={{ flex:1 }}>
                    <div style={{ fontSize:12, fontWeight:600 }}>{c.t}</div>
                    <div style={{ fontSize:10.5, color:t.inkSoft }}>{c.d}</div>
                  </div>
                  {React.cloneElement(I.check,{width:13,height:13,color:t.green})}
                </div>
              ))}
            </div>
            <div style={{ marginTop:'auto', display:'flex', flexDirection:'column', gap:8 }}>
              <Btn variant="accent" size="lg" full icon={React.cloneElement(I.chevron,{width:14,height:14})}>Lancer l'analyse</Btn>
              <Btn variant="ghost" size="md" full icon={I.back}>Retour</Btn>
            </div>
          </div>
        </div>
      </div>
      <DesktopFooter/>
    </div>
  );
}

// ──────────────────────────────────────────────────────────────────────────────
// STEP 3 — ANALYSE : Narrative progression (P0.2)
// ──────────────────────────────────────────────────────────────────────────────
const COMPUTE_ACTS = [
  {
    id:'route', label:'Analyse du tracé', color:'#4a7a3e', done:true,
    sub:'2 847 points GPS · 143 km parsés · 4 étapes découpées',
    tasks:['ROUTE','STAGES'],
  },
  {
    id:'terrain', label:'Analyse du terrain', color:'#6b5a3e', done:true,
    sub:'Dénivelé +1 430 m · gravier 22% · 3 sections pavées détectées',
    tasks:['TERRAIN','OSM_SCAN'],
  },
  {
    id:'pois', label:'Points d\'intérêt', color:'#3d6b91', running:true, progress:72,
    sub:'Interrogation OpenStreetMap… 142 POI trouvés · 3 POI culturels enrichis via Wikidata',
    tasks:['POIS','CULTURAL_POIS','WATER_POINTS','BIKE_SHOPS'],
  },
  {
    id:'lodging', label:'Hébergements', color:'#8a5a2e', pending:true,
    sub:'',
    tasks:['LODGING'],
  },
  {
    id:'weather', label:'Météo & confort', color:'#3d6b91', pending:true,
    sub:'',
    tasks:['WEATHER','WIND'],
  },
  {
    id:'events', label:'Événements locaux', color:'#6b3a6e', pending:true,
    sub:'',
    tasks:['CALENDAR'],
  },
  {
    id:'safety', label:'Sécurité & évacuation', color:'#8a3a2e', pending:true,
    sub:'',
    tasks:['RAILWAY_STATIONS','BORDER_CROSSINGS','HEALTH_SERVICES'],
  },
];

function PageProcessingDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  return (
    <div style={{ width:w, height:h, background:t.bg, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden' }}>
      <TopBarDesktop page="new"/>
      <div style={{ display:'flex', flex:1, minHeight:0 }}>
        <WizardStepper active={3} t={t}/>
        <div style={{ display:'grid', gridTemplateColumns:'1.1fr 1fr', flex:1, minHeight:0 }}>
          {/* Left: progression narrative */}
          <div style={{ padding:'40px 52px', overflowY:'auto', borderRight:`1px solid ${t.line}` }}>
            <h1 style={{ fontFamily:t.serif, fontSize:34, letterSpacing:-0.7, fontWeight:500, margin:0, marginBottom:8, lineHeight:1.1 }}>
              <span style={{ fontStyle:'italic', color:t.accent }}>L'Odyssée des Eaux Royales</span>
            </h1>
            <p style={{ fontSize:14, color:t.inkSoft, marginBottom:28, lineHeight:1.55 }}>Traitement en parallèle — environ 30 secondes. Vous pouvez basculer d'onglet.</p>

            {/* Global progress */}
            <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, padding:16, marginBottom:24 }}>
              <div style={{ display:'flex', justifyContent:'space-between', marginBottom:8, fontSize:12, fontWeight:600 }}>
                <span style={{ display:'flex', alignItems:'center', gap:6, color:t.ink, fontWeight:600 }}><span style={{width:8,height:8,borderRadius:'50%',background:t.accent,animation:'pulse 1.4s infinite',flexShrink:0}}/> Analyse en cours</span>
                <span style={{ color:t.accent, fontFamily:t.mono }}>47 %</span>
              </div>
              <div style={{ height:6, background:t.surfaceAlt, borderRadius:3, overflow:'hidden' }}>
                <div style={{ height:'100%', width:'47%', background:`linear-gradient(90deg,${t.accent},${t.accent}cc)`, borderRadius:3 }}/>
              </div>
            </div>

            {/* Acts */}
            <div style={{ display:'flex', flexDirection:'column', gap:3 }}>
              {COMPUTE_ACTS.map((act, i) => (
                <div key={act.id} style={{ padding:'12px 14px', borderRadius:10, background: act.running ? t.accentSofter : 'transparent', border: act.running ? `1px solid ${t.accent}30` : '1px solid transparent' }}>
                  <div style={{ display:'flex', gap:12, alignItems:'flex-start' }}>
                    {/* Status dot */}
                    <div style={{ width:24, height:24, borderRadius:'50%', flexShrink:0, marginTop:1,
                      background: act.done ? t.green : act.running ? t.surface : t.surfaceAlt,
                      color: act.done ? '#fff' : t.inkMute,
                      display:'flex', alignItems:'center', justifyContent:'center',
                      border: act.running ? `2px solid ${t.accent}` : 'none',
                    }}>
                      {act.done && React.cloneElement(I.check,{width:11,height:11})}
                      {act.running && <div style={{ width:8,height:8,borderRadius:'50%',background:t.accent,animation:'pulse 1s infinite' }}/>}
                      {act.pending && <span style={{ fontSize:10, fontWeight:700 }}>{i+1}</span>}
                    </div>
                    <div style={{ flex:1 }}>
                      <div style={{ display:'flex', justifyContent:'space-between', alignItems:'baseline' }}>
                        <div style={{ fontSize:13.5, fontWeight: act.running?700:500, color: act.pending?t.inkMute:t.ink }}>{act.label}</div>
                        {act.running && <span style={{ fontSize:11, color:t.accent, fontFamily:t.mono, fontWeight:600 }}>{act.progress}%</span>}
                      </div>
                      {/* Task badges */}
                      <div style={{ display:'flex', gap:4, flexWrap:'wrap', marginTop:4, marginBottom: act.sub?4:0 }}>
                        {act.tasks.map(tk => (
                          <span key={tk} style={{ fontSize:9.5, padding:'1px 6px', borderRadius:4, background: act.done?t.greenSoft:act.running?t.accentSofter:t.surfaceAlt, color: act.done?t.green:act.running?t.accentInk:t.inkMute, fontFamily:t.mono, fontWeight:600 }}>{tk}</span>
                        ))}
                      </div>
                      {act.sub && <div style={{ fontSize:11.5, color:t.inkSoft, lineHeight:1.45 }}>{act.sub}</div>}
                      {act.running && (
                        <div style={{ height:3, background:t.surfaceAlt, borderRadius:2, marginTop:8, overflow:'hidden' }}>
                          <div style={{ height:'100%', width:`${act.progress}%`, background:t.accent, borderRadius:2 }}/>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Right: live preview */}
          <div style={{ background:t.surfaceAlt, padding:28, display:'flex', flexDirection:'column', gap:14, overflow:'hidden' }}>
            <div style={{ fontSize:11.5, fontWeight:600, color:t.inkSoft, textTransform:'uppercase', letterSpacing:0.5 }}>Aperçu temps réel</div>
            <div style={{ background:t.surface, borderRadius:12, overflow:'hidden', border:`1px solid ${t.line}` }}>
              <OsmMap w={460} h={220}/>
            </div>
            <div style={{ background:t.surface, borderRadius:12, padding:14, border:`1px solid ${t.line}` }}>
              <div style={{ fontSize:12, fontWeight:600, marginBottom:8 }}>Profil altimétrique</div>
              <div style={{ display:'flex', justifyContent:'center' }}>
                <ElevProfile w={430} h={70} up={TRIP.totalUp} down={1380} distance={TRIP.totalKm} showMarkers/>
              </div>
            </div>
            <div style={{ display:'grid', gridTemplateColumns:'repeat(3,1fr)', gap:8 }}>
              {[{l:'Distance',v:'143',u:'km'},{l:'Dénivelé +',v:'1 430',u:'m'},{l:'POI trouvés',v:'142',u:''}].map((m,i)=>(
                <div key={i} style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:10, padding:12 }}>
                  <div style={{ fontSize:10, color:t.inkSoft, fontWeight:600, textTransform:'uppercase', letterSpacing:0.3 }}>{m.l}</div>
                  <div style={{ fontFamily:t.serif, fontSize:22, fontWeight:500, letterSpacing:-0.5, marginTop:3 }}>{m.v} <span style={{ fontSize:11, color:t.inkSoft, fontFamily:t.sans }}>{m.u}</span></div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
      <DesktopFooter/>
    </div>
  );
}

Object.assign(window, { PageTripsNewDesktop, PageTripsNewDesktopStep2, PageProcessingDesktop, COMPUTE_ACTS });
