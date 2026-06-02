// pages-poi.jsx — POI Popover showcase (Variante A enrichie + B minimale, light + dark)

function PagePOIPopoverDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  return (
    <div style={{ width:w, height:h, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden', position:'relative' }}>
      <div style={{ position:'absolute', inset:0, opacity:.3, zIndex:0 }}><OsmMap w={w} h={h}/></div>
      <div style={{ position:'absolute', inset:0, zIndex:0, background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)' }}/>
      <div style={{ position:'relative', zIndex:1, display:'flex', flexDirection:'column', flex:1, minHeight:0 }}>
        <TopBarDesktop/>
        <div style={{ flex:1, overflowY:'auto', padding:'32px 48px' }}>
          <h1 style={{ fontFamily:t.serif, fontSize:36, letterSpacing:-0.6, fontWeight:500, margin:'0 0 8px' }}>Popover POI culturel</h1>
          <p style={{ fontSize:14, color:t.inkSoft, marginBottom:32, lineHeight:1.5 }}>Les POI culturels pulsent sur la carte pour inviter au clic. Deux variantes de popover selon la richesse des données.</p>

          {/* Map demo with pulsating markers */}
          <div style={{ marginBottom:32 }}>
            <div style={{ fontSize:11.5, fontWeight:700, color:t.accent, letterSpacing:1, textTransform:'uppercase', marginBottom:10 }}>Carte — marqueurs pulsants (culturels) vs statiques (services)</div>
            <div style={{ borderRadius:16, overflow:'hidden', border:`1px solid ${t.line}`, position:'relative', height:260 }}>
              <OsmMap w={w-96} h={260} showPOI/>
              {/* Legend overlay */}
              <div style={{ position:'absolute', bottom:12, left:12, background:t.surface, border:`1px solid ${t.line}`, borderRadius:10, padding:'8px 12px', display:'flex', gap:14, fontSize:11, boxShadow:t.shadowSoft }}>
                <div style={{ display:'flex', alignItems:'center', gap:5 }}>
                  <div style={{ width:12, height:12, borderRadius:'50%', border:`2px solid ${t.accent}`, position:'relative' }}>
                    <div style={{ position:'absolute', inset:-4, borderRadius:'50%', border:`1px solid ${t.accent}`, opacity:0.3 }}/>
                  </div>
                  <span style={{ color:t.inkSoft }}>POI culturel (pulsant)</span>
                </div>
                <div style={{ display:'flex', alignItems:'center', gap:5 }}>
                  <div style={{ width:8, height:8, borderRadius:'50%', background:'#3d6b91' }}/>
                  <span style={{ color:t.inkSoft }}>Service (statique)</span>
                </div>
              </div>
            </div>
          </div>

          {/* Popover variants */}
          <div style={{ display:'grid', gridTemplateColumns:'repeat(2,1fr)', gap:28 }}>
            {/* Variante A */}
            <div>
              <div style={{ fontSize:11.5, fontWeight:700, color:t.accent, letterSpacing:1, textTransform:'uppercase', marginBottom:14 }}>Variante A — enrichie (Wikidata + DataTourisme)</div>
              <div style={{ display:'flex', flexDirection:'column', gap:16 }}>
                <div>
                  <div style={{ fontSize:11, color:t.inkSoft, marginBottom:6 }}>État : ouvert</div>
                  <POIPopoverRich/>
                </div>
                <div>
                  <div style={{ fontSize:11, color:t.inkSoft, marginBottom:6 }}>État : fermé</div>
                  <POIPopoverRichClosed/>
                </div>
              </div>
            </div>

            {/* Variante B */}
            <div>
              <div style={{ fontSize:11.5, fontWeight:700, color:t.accent, letterSpacing:1, textTransform:'uppercase', marginBottom:14 }}>Variante B — minimale (OSM seul)</div>
              <div style={{ display:'flex', flexDirection:'column', gap:16 }}>
                <div>
                  <div style={{ fontSize:11, color:t.inkSoft, marginBottom:6 }}>Lieu de culte (pas de photo, pas d'horaires)</div>
                  <POIPopoverMinimal/>
                </div>
                <div>
                  <div style={{ fontSize:11, color:t.inkSoft, marginBottom:6 }}>Point de vue gratuit</div>
                  <POIPopoverMinimalFree/>
                </div>
              </div>
            </div>
          </div>

          {/* Spec table */}
          <div style={{ marginTop:32, background:t.surface, border:`1px solid ${t.line}`, borderRadius:14, padding:20 }}>
            <div style={{ fontSize:12, fontWeight:700, color:t.accent, letterSpacing:1, textTransform:'uppercase', marginBottom:12 }}>Logique d'affichage</div>
            <div style={{ display:'grid', gridTemplateColumns:'160px 1fr 1fr', gap:1, fontSize:12 }}>
              {/* Header */}
              <div style={{ padding:'8px 10px', fontWeight:700, background:t.surfaceAlt, borderRadius:'8px 0 0 0' }}>Donnée</div>
              <div style={{ padding:'8px 10px', fontWeight:700, background:t.surfaceAlt }}>Variante A</div>
              <div style={{ padding:'8px 10px', fontWeight:700, background:t.surfaceAlt, borderRadius:'0 8px 0 0' }}>Variante B</div>
              {[
                ['Photo (P18)', 'Wikimedia Commons', '—'],
                ['Nom', 'Wikidata label', 'OSM name tag'],
                ['Catégorie', 'Enrichie (château, musée…)', 'Tag OSM brut'],
                ['Description', '2-3 lignes multilingues', '—'],
                ['Horaires', 'Structurés + état actuel', '—'],
                ['Prix', 'Estimé ou "Gratuit"', '—'],
                ['Lien Wikipedia', '✓', '—'],
                ['Bouton Naviguer', '✓', '✓'],
              ].map(([label, a, b], i) => (
                <React.Fragment key={i}>
                  <div style={{ padding:'6px 10px', color:t.inkSoft, borderTop:`1px solid ${t.lineSoft}` }}>{label}</div>
                  <div style={{ padding:'6px 10px', borderTop:`1px solid ${t.lineSoft}` }}>{a}</div>
                  <div style={{ padding:'6px 10px', color: b === '—' ? t.inkMute : t.ink, borderTop:`1px solid ${t.lineSoft}` }}>{b}</div>
                </React.Fragment>
              ))}
            </div>
          </div>
        </div>
        <DesktopFooter/>
      </div>
    </div>
  );
}

Object.assign(window, { PagePOIPopoverDesktop });
