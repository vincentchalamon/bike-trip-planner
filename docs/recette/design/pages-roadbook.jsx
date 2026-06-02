// pages-roadbook.jsx — Step 4 "Mon Voyage" roadbook with all P2 features
// Sprint 24, 27, 28 features drawn as fully functional

function PageRoadbookDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  const [activeStage, setActiveStage] = React.useState(1);
  const [aiChatOpen, setAiChatOpen] = React.useState(false);
  const [showEvents, setShowEvents] = React.useState(false);
  const stage = STAGES[activeStage];
  const alerts = RICH_ALERTS[activeStage] || [];
  const dayColors = [t.forest, t.accent, t.blue, t.rose];
  const stageColor = dayColors[activeStage];

  return (
    <div style={{ width:w, height:h, background:t.bg, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden', position:'relative' }}>
      {/* Recomputation progress bar (Sprint 24) */}
      <RecomputeBar progress={0}/>

      <TopBarDesktop page="trips" showUndo showShare/>

      {/* Trip header */}
      <div style={{ padding:'14px 28px 12px', borderBottom:`1px solid ${t.line}`, display:'flex', justifyContent:'space-between', alignItems:'flex-start', flexShrink:0 }}>
        <div>
          <div style={{ display:'flex', alignItems:'center', gap:8, fontSize:12, color:t.inkSoft, marginBottom:4 }}>
            <span>Mes voyages</span><span>›</span>
            <span style={{ color:t.ink, fontWeight:500 }}>L'Odyssée des Eaux Royales</span>
          </div>
          <h1 style={{ fontFamily:t.serif, fontSize:28, letterSpacing:-0.6, fontWeight:500, margin:0, marginBottom:4 }}>{TRIP.title}</h1>
          <div style={{ display:'flex', gap:12, fontSize:12.5, color:t.inkSoft, alignItems:'center', flexWrap:'wrap' }}>
            <span>{TRIP.dateRange}</span>
            <span>·</span><span>{TRIP.totalKm} km · +{TRIP.totalUp} m · {TRIP.days} étapes</span>
            <span>·</span><Pill sm bg={t.accentSoft} color={t.accentInk}>{TRIP.level}</Pill>
          </div>
        </div>
        {/* AI global summary (Sprint 27) */}
        <div style={{ maxWidth:380, background:t.surfaceAlt, border:`1px solid ${t.line}`, borderRadius:12, padding:'10px 14px', display:'flex', gap:8, alignItems:'flex-start' }}>
          <div style={{ width:24, height:24, borderRadius:6, background:t.accent, display:'flex', alignItems:'center', justifyContent:'center', flexShrink:0, marginTop:1 }}>
            {React.cloneElement(I.sparkle,{width:11,height:11,color:'#fff'})}
          </div>
          <p style={{ fontFamily:t.serif, fontSize:12.5, fontStyle:'italic', lineHeight:1.55, color:t.ink, margin:0 }}>
            Un parcours varié entre polders flamands et collines de l'Escaut, idéal pour 4 jours de bikepacking paisibles avec hébergements de charme.
          </p>
        </div>
      </div>

      <div style={{ display:'grid', gridTemplateColumns:'280px 1fr 360px', flex:1, minHeight:0 }}>
        {/* LEFT — vertical timeline */}
        <div style={{ borderRight:`1px solid ${t.line}`, overflowY:'auto', padding:'14px 12px', background:t.surface }}>
          <div style={{ fontSize:10.5, fontWeight:600, color:t.inkSoft, textTransform:'uppercase', letterSpacing:0.5, padding:'4px 8px 10px' }}>Étapes</div>
          {STAGES.map((s, i) => {
            const a = i === activeStage;
            const c = dayColors[i];
            const isLast = i === STAGES.length - 1;
            return (
              <div key={i}>
                <div onClick={() => setActiveStage(i)} style={{ display:'flex', gap:10, paddingBottom:isLast?0:2, cursor:'pointer' }}>
                  {/* Timeline */}
                  <div style={{ display:'flex', flexDirection:'column', alignItems:'center', flexShrink:0 }}>
                    <div style={{ width:30, height:30, borderRadius:'50%', background:c, color:'#fff', display:'flex', alignItems:'center', justifyContent:'center', fontWeight:700, fontSize:13, zIndex:1, boxShadow:a?`0 0 0 3px ${c}40`:'none', transition:'box-shadow 0.15s' }}>{s.day}</div>
                    {!isLast && <div style={{ width:2, flex:1, background:t.line, minHeight:20, margin:'3px 0' }}/>}
                  </div>
                  {/* Stage info */}
                  <div style={{ flex:1, padding:'4px 8px 14px', borderRadius:9, background:a?t.surfaceAlt:'transparent', border:a?`1px solid ${t.line}`:'1px solid transparent' }}>
                    <div style={{ fontSize:11.5, fontWeight:600, color:a?t.ink:t.inkSoft, lineHeight:1.2, marginBottom:2 }}>{s.from} → {s.to}</div>
                    <div style={{ fontSize:10.5, color:t.inkMute }}>{s.date} · {s.km} km · +{s.up} m</div>
                    <div style={{ display:'flex', gap:4, marginTop:5 }}>
                      {(RICH_ALERTS[i]||[]).slice(0,3).map((al,j) => {
                        const cm = {critical:t.red,warning:t.accent,nudge:t.blue}[al.sev];
                        return <span key={j} style={{ width:5,height:5,borderRadius:'50%',background:cm }}/>;
                      })}
                    </div>
                  </div>
                </div>
                {/* "+ Ajouter étape / repos" between stages */}
                {!isLast && (
                  <div style={{ display:'flex', gap:4, marginLeft:40, marginBottom:4, opacity:0.4 }}>
                    <span style={{ fontSize:10, color:t.accent, cursor:'pointer', padding:'1px 6px', border:`1px dashed ${t.accent}`, borderRadius:4 }}>+ étape</span>
                    <span style={{ fontSize:10, color:t.inkSoft, cursor:'pointer', padding:'1px 6px', border:`1px dashed ${t.line}`, borderRadius:4 }}>🛏 repos</span>
                  </div>
                )}
              </div>
            );
          })}
        </div>

        {/* CENTER — OSM map with elev under */}
        <div style={{ display:'flex', flexDirection:'column', overflow:'hidden' }}>
          <div style={{ flex:1, position:'relative', overflow:'hidden' }}>
            <OsmMap w={640} h={h-310} active={activeStage} showPOI/>
            {/* Map controls */}
            <div style={{ position:'absolute', top:12, right:12, display:'flex', flexDirection:'column', gap:4 }}>
              <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:7, padding:6, fontSize:12, fontFamily:t.mono, color:t.inkSoft, boxShadow:t.shadowSoft, fontSize:10.5 }}>50.742°N 3.602°E</div>
              {['+','−'].map((x,i)=>(
                <div key={i} style={{ width:30,height:30,background:t.surface,border:`1px solid ${t.line}`,borderRadius:7,display:'flex',alignItems:'center',justifyContent:'center',fontSize:16,fontWeight:500,color:t.ink,cursor:'pointer',boxShadow:t.shadowSoft }}>{x}</div>
              ))}
            </div>
            {/* Map view toggle */}
            <div style={{ position:'absolute', top:12, left:12, display:'flex', gap:3, background:t.surface, border:`1px solid ${t.line}`, borderRadius:8, padding:3, boxShadow:t.shadowSoft }}>
              {['Carte','Satellite'].map((x,i)=>(
                <div key={x} style={{ padding:'4px 10px', borderRadius:5, fontSize:11, fontWeight:600, background:i===0?t.ink:'transparent', color:i===0?t.bg:t.inkSoft, cursor:'pointer' }}>{x}</div>
              ))}
            </div>
          </div>
          {/* Elevation profile */}
          <div style={{ borderTop:`1px solid ${t.line}`, padding:'10px 16px', background:t.surface, flexShrink:0 }}>
            <div style={{ display:'flex', justifyContent:'space-between', marginBottom:5 }}>
              <span style={{ fontSize:12, fontWeight:600 }}>Profil altimétrique — {stage.from} → {stage.to}</span>
              <span style={{ fontSize:10.5, color:t.inkMute, fontFamily:t.mono }}>alt. max 142 m</span>
            </div>
            <ElevProfile w={608} h={64} up={stage.up} down={stage.down} distance={stage.km} showMarkers/>
            <div style={{ display:'flex', justifyContent:'space-between', fontSize:9.5, color:t.inkMute, fontFamily:t.mono, marginTop:3 }}>
              <span>0 km</span><span>10</span><span>20</span><span>30</span><span>{stage.km} km</span>
            </div>
          </div>
          {/* Supply timeline */}
          <div style={{ padding:'10px 16px', borderTop:`1px solid ${t.line}`, background:t.surfaceAlt, flexShrink:0 }}>
            <SupplyTimeline stageKm={stage.km}/>
          </div>
        </div>

        {/* RIGHT — stage detail */}
        <div style={{ overflowY:'auto', borderLeft:`1px solid ${t.line}`, background:t.surfaceAlt, display:'flex', flexDirection:'column', gap:10, padding:'16px 16px' }}>
          {/* Stage header */}
          <div style={{ display:'flex', gap:8, alignItems:'center', marginBottom:4 }}>
            <Pill bg={stageColor} color="#fff" bold>JOUR {stage.day}</Pill>
            <span style={{ fontSize:12, color:t.inkSoft }}>{stage.date}</span>
            {/* Downloads dropdown */}
            <div style={{ marginLeft:'auto', display:'flex', gap:4 }}>
              {['GPX','FIT'].map((x,i)=>(
                <div key={i} style={{ padding:'3px 7px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:5, fontSize:10, fontWeight:600, color:t.inkSoft, cursor:'pointer', display:'flex', alignItems:'center', gap:3 }}>
                  <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M12 3v13M6 11l6 6 6-6"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                  {x}
                </div>
              ))}
            </div>
          </div>
          <div style={{ fontFamily:t.serif, fontSize:20, fontWeight:500, letterSpacing:-0.3 }}>{stage.from} → {stage.to}</div>

          {/* AI stage summary (Sprint 27) */}
          <AISummaryCard text="Étape facile à travers le bassin minier rénové, avec une belle remontée vers les Flandres belges sur les derniers 10 km. Surface 64 % asphalte, 36 % gravier — chaussures renforcées recommandées."/>

          {/* Key metrics + editable distance */}
          <div style={{ display:'grid', gridTemplateColumns:'repeat(4,1fr)', gap:6 }}>
            {[
              { l:'KM', v:stage.km, accent:true, editable:true },
              { l:'D+', v:`+${stage.up}m` },
              { l:'DEP', v:stage.dep },
              { l:'ETA', v:stage.arr },
            ].map((m,i)=>(
              <div key={i} style={{ padding:'9px 10px', background:t.surface, borderRadius:10, border:`1px solid ${m.accent?t.accent:t.line}` }}>
                <div style={{ fontSize:9, color:t.inkSoft, fontWeight:600, letterSpacing:0.4, textTransform:'uppercase' }}>{m.l}</div>
                <div style={{ fontFamily:t.serif, fontSize:18, fontWeight:500, letterSpacing:-0.3, marginTop:2, display:'flex', alignItems:'center', gap:4 }}>
                  {m.v}
                  {m.editable && React.cloneElement(I.edit,{width:11,height:11,color:t.inkMute})}
                </div>
              </div>
            ))}
          </div>

          {/* Difficulty gauge */}
          <DifficultyGauge score={activeStage===2?78:activeStage===1?52:30}/>

          {/* Enhanced weather */}
          <WeatherCard weather={stage.weather} stageKm={stage.km}/>

          {/* Alerts grouped by severity */}
          <div>
            <div style={{ fontSize:11, fontWeight:600, color:t.inkSoft, letterSpacing:0.4, textTransform:'uppercase', marginBottom:6 }}>Alertes ({alerts.length})</div>
            <AlertGroup alerts={alerts}/>
          </div>

          {/* Events panel (collapsible) */}
          <div>
            <div onClick={()=>setShowEvents(!showEvents)} style={{ display:'flex', alignItems:'center', gap:6, cursor:'pointer', padding:'8px 10px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:9 }}>
              <span style={{ fontSize:12.5, fontWeight:600, flex:1 }}>Événements locaux</span>
              <Pill sm bg={t.blueSoft} color={t.blue} bold>2</Pill>
              <span style={{ color:t.inkMute, transform:showEvents?'rotate(90deg)':'none', display:'flex', transition:'transform 0.15s' }}>{I.chevron}</span>
            </div>
            {showEvents && (
              <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderTop:'none', borderRadius:'0 0 9px 9px', padding:'8px 12px' }}>
                <div style={{ fontSize:12, color:t.ink, marginBottom:4 }}>🎪 Marché de Tournai — sam. 15 mai · 08h–13h</div>
                <div style={{ fontSize:12, color:t.ink }}>🎵 Festival Banlieues Bleues — 14-17 mai</div>
              </div>
            )}
          </div>

          {/* Accommodations inline (9 types) */}
          <div>
            <div style={{ fontSize:11, fontWeight:600, color:t.inkSoft, letterSpacing:0.4, textTransform:'uppercase', marginBottom:6 }}>Hébergements</div>
            {/* Selected */}
            {stage.lodging && (
              <div style={{ display:'flex', gap:10, padding:'10px 12px', border:`1.5px solid ${t.accent}`, borderRadius:10, background:t.accentSofter, marginBottom:6 }}>
                <div style={{ width:34, height:34, borderRadius:9, background:t.accent, color:'#fff', display:'flex', alignItems:'center', justifyContent:'center', flexShrink:0, fontSize:16 }}>
                  {ACC_TYPES.guest_house.icon}
                </div>
                <div style={{ flex:1 }}>
                  <div style={{ fontSize:13, fontWeight:600 }}>{stage.lodging.name}</div>
                  <div style={{ fontSize:11, color:t.inkSoft, marginTop:1 }}>{stage.lodging.type} · {stage.lodging.dist} · ⭐ {stage.lodging.rating}</div>
                </div>
                <div style={{ textAlign:'right' }}>
                  <div style={{ fontSize:13, fontWeight:700, color:t.accent }}>{stage.lodging.price}</div>
                </div>
              </div>
            )}
            {/* Other suggestions */}
            {[
              { n:'Ibis Budget', type:'hotel', price:'€55', dist:'1,4 km' },
              { n:'Camping Municipal', type:'camp_site', price:'€12', dist:'2,1 km' },
              { n:'Refuge du Chemin', type:'wilderness_hut', price:'€0-10', dist:'3,8 km' },
            ].map((o,i)=>(
              <div key={i} style={{ display:'flex', gap:8, padding:'7px 10px', border:`1px solid ${t.lineSoft}`, borderRadius:8, background:t.surface, marginBottom:3, opacity:0.6 }}>
                <span style={{ fontSize:14, flexShrink:0 }}>{ACC_TYPES[o.type]?.icon}</span>
                <div style={{ flex:1 }}>
                  <div style={{ fontSize:11.5, fontWeight:500 }}>{o.n}</div>
                  <div style={{ fontSize:10, color:t.inkMute }}>{ACC_TYPES[o.type]?.label} · {o.dist}</div>
                </div>
                <span style={{ fontSize:11.5, color:t.inkMute, flexShrink:0 }}>{o.price}</span>
              </div>
            ))}
            <div style={{ display:'flex', gap:6, marginTop:6 }}>
              <span style={{ fontSize:11, color:t.accent, fontWeight:600, cursor:'pointer' }}>› Élargir la zone (5 km)</span>
              <span style={{ fontSize:11, color:t.inkMute }}>·</span>
              <span style={{ fontSize:11, color:t.accent, fontWeight:600, cursor:'pointer' }}>+ Ajouter manuellement</span>
            </div>
          </div>
        </div>
      </div>

      {/* AI chat bubble (Sprint 28) */}
      <AIChatBubble/>

      <DesktopFooter/>
    </div>
  );
}

// ─── Public shared view (read-only) ──────────────────────────────────────────
function PagePublicSharedDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  return (
    <div style={{ width:w, height:h, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden', position:'relative' }}>
      <div style={{ position:'absolute', inset:0, opacity:.3, zIndex:0 }}><OsmMap w={w} h={h}/></div>
      <div style={{ position:'absolute', inset:0, zIndex:0, background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)' }}/>
      <div style={{ position:'relative', zIndex:1, display:'flex', flexDirection:'column', flex:1, minHeight:0 }}>
      {/* Read-only banner */}
      <div style={{ background:t.name==='dark'?'#1d2a3d':'#dde8f0', borderBottom:`1px solid ${t.blue}30`, padding:'7px 24px', display:'flex', alignItems:'center', gap:8, fontSize:12.5, color:t.blue, flexShrink:0 }}>
        {React.cloneElement(I.share,{width:13,height:13})}
        <span><strong>Vue partagée en lecture seule</strong> — vous consultez un itinéraire partagé par Noé Fritz.</span>
      </div>

      {/* Simplified top bar */}
      <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', padding:'12px 24px', borderBottom:`1px solid ${t.line}`, background:t.bg }}>
        <div style={{ display:'flex', alignItems:'center', gap:10 }}>
          <Logo size={26}/>
          <span style={{ fontSize:14, fontWeight:600, color:t.ink }}>Bike Trip Planner</span>
        </div>
        <div style={{ display:'flex', gap:8 }}>
          <Btn variant="ghost" size="sm" icon={I.download}>Télécharger GPX</Btn>
          <Btn variant="ghost" size="sm" icon={I.download}>Télécharger FIT</Btn>
        </div>
      </div>

      {/* Hero map */}
      <div style={{ position:'relative', height:280, overflow:'hidden', flexShrink:0 }}>
        <OsmMap w={w} h={280}/>
        <div style={{ position:'absolute', inset:0, background:t.name==='dark'?'linear-gradient(180deg,rgba(10,8,5,0)40%,rgba(10,8,5,0.9))':'linear-gradient(180deg,rgba(250,247,240,0)40%,rgba(250,247,240,0.92))' }}/>
        <div style={{ position:'absolute', bottom:20, left:48, right:48, display:'flex', justifyContent:'space-between', alignItems:'flex-end' }}>
          <div>
            <h1 style={{ fontFamily:t.serif, fontSize:38, fontWeight:500, letterSpacing:-0.7, margin:0 }}>{TRIP.title}</h1>
            <div style={{ fontSize:14, color:t.inkSoft, marginTop:6 }}>Lille → Gand · {TRIP.totalKm} km · {TRIP.days} jours · {TRIP.dateRange}</div>
          </div>
          <div style={{ display:'flex', gap:20 }}>
            {[{v:TRIP.totalKm,u:'km'},{v:`+${TRIP.totalUp}`,u:'m D+'},{v:TRIP.days,u:'étapes'}].map((m,i)=>(
              <div key={i} style={{ textAlign:'center' }}>
                <div style={{ fontFamily:t.serif, fontSize:26, fontWeight:500, letterSpacing:-0.5 }}>{m.v}</div>
                <div style={{ fontSize:10, color:t.inkSoft, textTransform:'uppercase', letterSpacing:0.5, marginTop:3 }}>{m.u}</div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Stages */}
      <div style={{ flex:1, overflowY:'auto', padding:'24px 48px' }}>
        <div style={{ fontSize:11.5, fontWeight:600, color:t.accent, letterSpacing:1, textTransform:'uppercase', marginBottom:14 }}>Itinéraire</div>
        <div style={{ display:'grid', gridTemplateColumns:'repeat(4,1fr)', gap:14 }}>
          {STAGES.map((s,i) => {
            const c = [t.forest,t.accent,t.blue,t.rose][i];
            return (
              <div key={i} style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:14, overflow:'hidden' }}>
                <div style={{ height:4, background:c }}/>
                <div style={{ padding:'14px 14px 12px' }}>
                  <div style={{ display:'flex', gap:6, alignItems:'center', marginBottom:8 }}>
                    <div style={{ width:24,height:24,borderRadius:7,background:c,color:'#fff',fontSize:12,fontWeight:700,display:'flex',alignItems:'center',justifyContent:'center' }}>{s.day}</div>
                    <span style={{ fontSize:11, color:t.inkSoft }}>{s.date}</span>
                  </div>
                  <div style={{ fontFamily:t.serif, fontSize:16, fontWeight:500, letterSpacing:-0.2, lineHeight:1.2, marginBottom:6 }}>{s.from} → {s.to}</div>
                  <ElevProfile w={200} h={38} up={s.up} down={s.down} distance={s.km} color={c}/>
                  <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:4, marginTop:8, fontSize:11 }}>
                    <div><b style={{ fontSize:14, fontFamily:t.serif }}>{s.km}</b> <span style={{ color:t.inkMute }}>km</span></div>
                    <div><b style={{ fontSize:14, fontFamily:t.serif }}>+{s.up}</b> <span style={{ color:t.inkMute }}>m</span></div>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      </div>
      <DesktopFooter/>
      </div>
    </div>
  );
}

Object.assign(window, { PageRoadbookDesktop, PagePublicSharedDesktop });
