// pages-trips.jsx — Trips list + (PageRoadbookDesktop now in pages-roadbook.jsx)

// ─── Trips list ──────────────────────────────────────────────────────────────
function PageTripsListDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  const trips = [
    { title:"L'Odyssée des Eaux Royales", from:'Lille', to:'Gand', km:143, days:4, when:'14 – 17 mai 2026', status:'active' },
    { title:"Côte d'Opale — escapade", from:'Boulogne', to:'Bray-Dunes', km:86, days:2, when:'5 – 6 avril 2026', status:'done' },
    { title:"Loire à Vélo — segment 3", from:'Blois', to:'Saumur', km:214, days:5, when:'12 – 16 juin 2026', status:'draft' },
    { title:"Tour des Flandres amateur", from:'Gand', to:'Bruges', km:98, days:2, when:'22 – 23 juillet', status:'archived' },
  ];
  const badge = { active:{l:'En cours',c:t.green,bg:t.greenSoft}, done:{l:'Terminé',c:t.inkSoft,bg:t.surfaceAlt}, draft:{l:'Brouillon',c:t.accent,bg:t.accentSoft}, archived:{l:'Archivé',c:t.inkMute,bg:t.surfaceAlt} };

  const MapBg = () => (
    <>
      <div style={{ position:'absolute', inset:0, opacity:.3, zIndex:0 }}><OsmMap w={w} h={h}/></div>
      <div style={{ position:'absolute', inset:0, zIndex:0, background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)' }}/>
    </>
  );

  return (
    <div style={{ width:w, height:h, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden', position:'relative' }}>
      <MapBg/>
      <div style={{ position:'relative', zIndex:1, display:'flex', flexDirection:'column', flex:1, minHeight:0 }}>
        <TopBarDesktop page="trips"/>
        <div style={{ padding:'28px 48px 14px', display:'flex', alignItems:'flex-end', justifyContent:'space-between' }}>
          <h1 style={{ fontFamily:t.serif, fontSize:40, letterSpacing:-0.7, fontWeight:500, margin:0 }}>Mes voyages</h1>
          <div style={{ display:'flex', gap:8 }}>
            <div style={{ display:'flex', alignItems:'center', gap:6, padding:'9px 14px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:9, fontSize:13, color:t.inkMute }}>
              {I.search}<span>Rechercher un voyage…</span>
            </div>
            <Btn variant="ghost" icon={I.filter}>Filtrer par date</Btn>
            <Btn variant="accent" icon={I.plus}>Nouveau voyage</Btn>
          </div>
        </div>
        <div style={{ padding:'6px 48px 16px', display:'grid', gridTemplateColumns:'repeat(2,1fr)', gap:16, flex:1, minHeight:0, overflowY:'auto', alignContent:'start' }}>
          {trips.map((tr,i) => {
            const b = badge[tr.status];
            return (
              <div key={i} style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:16, overflow:'hidden', display:'flex', boxShadow:t.shadowSoft }}>
                <div style={{ width:190, flexShrink:0 }}><OsmMap w={190} h={200}/></div>
                <div style={{ flex:1, padding:'16px 18px', display:'flex', flexDirection:'column' }}>
                  <div style={{ display:'flex', justifyContent:'space-between', alignItems:'start', marginBottom:6 }}>
                    <Pill sm bg={b.bg} color={b.c} bold>{b.l}</Pill>
                    <div style={{ color:t.inkMute, display:'flex' }}>{I.moreH}</div>
                  </div>
                  <div style={{ fontFamily:t.serif, fontSize:18, letterSpacing:-0.3, fontWeight:500, lineHeight:1.15, marginBottom:5 }}>{tr.title}</div>
                  <div style={{ fontSize:12, color:t.inkSoft, marginBottom:12 }}>{tr.from} → {tr.to} · {tr.when}</div>
                  <div style={{ display:'flex', gap:16, fontSize:11, color:t.inkSoft, marginTop:'auto', paddingTop:10, borderTop:`1px solid ${t.lineSoft}` }}>
                    <span><b style={{ color:t.ink, fontSize:13 }}>{tr.km}</b> km</span>
                    <span><b style={{ color:t.ink, fontSize:13 }}>{tr.days}</b> jours</span>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
        <DesktopFooter/>
      </div>
    </div>
  );
}

Object.assign(window, { PageTripsListDesktop });
