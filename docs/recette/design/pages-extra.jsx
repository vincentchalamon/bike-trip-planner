// pages-extra.jsx — Legal, empty/loading/error states, picto legend, infographic

// ─── Privacy / Legal pages ──────────────────────────────────────────────────
function PagePrivacyDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  const MapBg = () => (<><div style={{position:'absolute',inset:0,opacity:.3,zIndex:0}}><OsmMap w={w} h={h}/></div><div style={{position:'absolute',inset:0,zIndex:0,background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)'}}/></>);
  const sections = [
    { id:'intro', title:'Introduction', content:'Bike Trip Planner collecte uniquement les données strictement nécessaires au fonctionnement du service. Nous n\'utilisons pas de cookies tiers à des fins publicitaires.' },
    { id:'base', title:'Base légale', content:'Le traitement est fondé sur le contrat (CGU) pour la gestion des voyages, et sur l\'intérêt légitime pour l\'amélioration du service via des analytics anonymes.' },
    { id:'data', title:'Données collectées', content:'Email (auth), voyages créés (tracés, étapes, hébergements), préférences (langue, thème). Les tracés GPS restent sur vos appareils entre sessions — nous traitons uniquement les métadonnées de voyage côté serveur.' },
    { id:'retention', title:'Conservation', content:'Les données sont conservées 13 mois après la dernière activité. Vous pouvez demander la suppression à tout moment depuis Paramètres → Mes données.' },
    { id:'rights', title:'Vos droits', content:'Accès, rectification, suppression, portabilité, opposition. Contactez dpo@biketripplanner.app. Délai de réponse : 30 jours maximum.' },
    { id:'analytics', title:'Cookies & analytics', content:'Cookies techniques essentiels uniquement (session JWT). Analytics agrégées et anonymes en interne (sans IP, sans empreinte de navigateur, sans tracking cross-site, suppression à 13 mois).' },
    { id:'contact', title:'Contact DPO', content:'Data Protection Officer : dpo@biketripplanner.app — pour toute demande relative à vos données personnelles.' },
  ];
  return (
    <div style={{ width:w, height:h, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden', position:'relative' }}>
      <MapBg/>
      <div style={{ position:'relative', zIndex:1, display:'flex', flexDirection:'column', flex:1, minHeight:0 }}>
      <TopBarDesktop/>
      <div style={{ display:'grid', gridTemplateColumns:'220px 1fr', flex:1, minHeight:0 }}>
        <div style={{ padding:'28px 16px', borderRight:`1px solid ${t.line}`, overflowY:'auto', background:t.surface, position:'sticky', top:0 }}>
          <div style={{ fontSize:11, fontWeight:600, color:t.inkSoft, textTransform:'uppercase', letterSpacing:0.5, marginBottom:10 }}>Sommaire</div>
          {sections.map((s,i)=>(
            <div key={i} style={{ padding:'7px 10px', borderRadius:7, fontSize:12.5, color:i===0?t.ink:t.inkSoft, fontWeight:i===0?600:400, cursor:'pointer', marginBottom:2 }}>{s.title}</div>
          ))}
        </div>
        <div style={{ padding:'40px 60px', overflowY:'auto' }}>
          <Pill bg={t.blueSoft} color={t.blue} bold style={{ marginBottom:16 }}>Politique de confidentialité</Pill>
          <h1 style={{ fontFamily:t.serif, fontSize:40, letterSpacing:-0.7, fontWeight:500, margin:0, marginBottom:6 }}>Confidentialité</h1>
          <p style={{ fontSize:13.5, color:t.inkSoft, marginBottom:36, lineHeight:1.5 }}>Dernière mise à jour : 24 avril 2026 · Base légale : RGPD (UE 2016/679)</p>
          <div style={{ maxWidth:680, display:'flex', flexDirection:'column', gap:28 }}>
            {sections.map((s,i)=>(
              <div key={i}>
                <h2 style={{ fontFamily:t.serif, fontSize:22, fontWeight:500, letterSpacing:-0.3, margin:0, marginBottom:10 }}>{s.title}</h2>
                <p style={{ fontSize:14, color:t.inkSoft, lineHeight:1.7, margin:0 }}>{s.content}</p>
                {i < sections.length-1 && <div style={{ height:1, background:t.lineSoft, marginTop:28 }}/>}
              </div>
            ))}
          </div>
        </div>
      </div>
      <DesktopFooter/>
      </div>
    </div>
  );
}

function PageLegalDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  return (
    <div style={{ width:w, height:h, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden', position:'relative' }}>
      <div style={{position:'absolute',inset:0,opacity:.3,zIndex:0}}><OsmMap w={w} h={h}/></div>
      <div style={{position:'absolute',inset:0,zIndex:0,background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)'}}/>
      <div style={{ position:'relative', zIndex:1, display:'flex', flexDirection:'column', flex:1, minHeight:0 }}>
      <TopBarDesktop/>
      <div style={{ display:'grid', gridTemplateColumns:'220px 1fr', flex:1, minHeight:0 }}>
        <div style={{ padding:'28px 16px', borderRight:`1px solid ${t.line}`, background:t.surface }}>
          <div style={{ fontSize:11, fontWeight:600, color:t.inkSoft, textTransform:'uppercase', letterSpacing:0.5, marginBottom:10 }}>Sommaire</div>
          {['Éditeur','Hébergeur','Contact','Propriété intellectuelle','Licence code source'].map((s,i)=>(
            <div key={i} style={{ padding:'7px 10px', borderRadius:7, fontSize:12.5, color:i===0?t.ink:t.inkSoft, fontWeight:i===0?600:400, cursor:'pointer', marginBottom:2 }}>{s}</div>
          ))}
        </div>
        <div style={{ padding:'40px 60px', overflowY:'auto' }}>
          <Pill bg={t.surfaceAlt} color={t.inkSoft} bold style={{ marginBottom:16 }}>Mentions légales</Pill>
          <h1 style={{ fontFamily:t.serif, fontSize:40, letterSpacing:-0.7, fontWeight:500, margin:0, marginBottom:6 }}>Mentions légales</h1>
          <p style={{ fontSize:13.5, color:t.inkSoft, marginBottom:36 }}>Conformément à la loi pour la confiance dans l'économie numérique (LCEN).</p>
          <div style={{ maxWidth:680, display:'flex', flexDirection:'column', gap:24 }}>
            {[
              { t:'Éditeur', c:'Vincent Chalamon — vincent@chalamon.fr' },
              { t:'Hébergeur', c:'OVHcloud SAS — 2 rue Kellermann, 59100 Roubaix · support.ovhcloud.com' },
              { t:'Contact', c:'contact@biketripplanner.app' },
              { t:'Propriété intellectuelle', c:'Le code source est publié sous licence AGPL v3.0 (github.com/vincentchalamon/bike-trip-planner). Les données cartographiques sont © OpenStreetMap contributors (ODbL 1.0).' },
              { t:'Licence code source', c:'GNU Affero General Public License v3.0 — usage commercial autorisé sous conditions, avec partage des modifications.' },
            ].map((s,i)=>(
              <div key={i}>
                <h2 style={{ fontFamily:t.serif, fontSize:20, fontWeight:500, margin:0, marginBottom:8 }}>{s.t}</h2>
                <p style={{ fontSize:13.5, color:t.inkSoft, lineHeight:1.65, margin:0 }}>{s.c}</p>
                {i < 4 && <div style={{ height:1, background:t.lineSoft, marginTop:24 }}/>}
              </div>
            ))}
          </div>
        </div>
      </div>
      <DesktopFooter/>
      </div>
    </div>
  );
}

// ─── STATES panel ─────────────────────────────────────────────────────────────
function PageStatesDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  const MapBg2 = () => (<><div style={{position:'absolute',inset:0,opacity:.3,zIndex:0}}><OsmMap w={w} h={h}/></div><div style={{position:'absolute',inset:0,zIndex:0,background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)'}}/></>);
  const Skel = ({ w: sw = '100%', h: sh = 14, r = 6 }) => (
    <div className={t.name==='dark'?'dark-shimmer-bg':'shimmer-bg'} style={{ width:sw, height:sh, borderRadius:r }}/>
  );

  return (
    <div style={{ width:w, height:h, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden', position:'relative' }}>
      <MapBg2/>
      <div style={{ position:'relative', zIndex:1, display:'flex', flexDirection:'column', flex:1, minHeight:0 }}>
      <TopBarDesktop/>
      <div style={{ flex:1, overflowY:'auto', padding:'32px 48px' }}>
        <h1 style={{ fontFamily:t.serif, fontSize:36, letterSpacing:-0.6, fontWeight:500, margin:'0 0 32px' }}>États UI</h1>
        <div style={{ display:'grid', gridTemplateColumns:'repeat(3,1fr)', gap:28 }}>

          {/* Empty states */}
          <div>
            <div style={{ fontSize:11.5, fontWeight:700, color:t.accent, letterSpacing:1, textTransform:'uppercase', marginBottom:14 }}>Empty states</div>
            {/* No trips */}
            <div style={{ background:t.surface, border:`2px dashed ${t.line}`, borderRadius:14, padding:32, textAlign:'center', marginBottom:12 }}>
              <div style={{ width:56,height:56,borderRadius:16,background:t.surfaceAlt,color:t.inkMute,display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 14px' }}>{React.cloneElement(I.trips,{width:24,height:24})}</div>
              <div style={{ fontSize:14,fontWeight:600,marginBottom:6 }}>Aucun voyage encore</div>
              <p style={{ fontSize:12.5,color:t.inkSoft,margin:'0 0 16px',lineHeight:1.5 }}>Créez votre premier itinéraire en quelques secondes.</p>
              <Btn variant="accent" size="sm" icon={I.plus}>Créer un voyage</Btn>
            </div>
            {/* No search results (filter active, 0 matches) — mutually exclusive with "Aucun voyage encore" on /trips */}
            <div style={{ background:t.surface, border:`2px dashed ${t.line}`, borderRadius:14, padding:32, textAlign:'center', marginBottom:12 }}>
              <div style={{ width:56,height:56,borderRadius:16,background:t.surfaceAlt,color:t.inkMute,display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 14px' }}>
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                  <line x1="8" y1="8" x2="14" y2="14" stroke={t.red} strokeWidth="2.2"/><line x1="14" y1="8" x2="8" y2="14" stroke={t.red} strokeWidth="2.2"/>
                </svg>
              </div>
              <div style={{ fontSize:14,fontWeight:600,marginBottom:6 }}>Aucun voyage ne correspond à votre recherche</div>
              <p style={{ fontSize:12,color:t.inkSoft,margin:'0 0 16px',lineHeight:1.5 }}>Filtres actifs : titre « Pyrénées » · dates 14–17 mai 2026</p>
              <div style={{ display:'flex', gap:8, justifyContent:'center' }}>
                <Btn variant="accent" size="sm">Réinitialiser les filtres</Btn>
                <Btn variant="ghost" size="sm" icon={I.plus}>Nouveau voyage</Btn>
              </div>
            </div>
            {/* No alerts */}
            <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, padding:20, textAlign:'center', marginBottom:12 }}>
              <div style={{ fontSize:22, marginBottom:6 }}>🎉</div>
              <div style={{ fontSize:13,fontWeight:600,marginBottom:4 }}>Aucune alerte sur cette étape</div>
              <div style={{ fontSize:11.5,color:t.inkSoft }}>L'étape est dégagée.</div>
            </div>
            {/* No accommodations */}
            <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, padding:20 }}>
              <div style={{ display:'flex', alignItems:'center', gap:8, marginBottom:8 }}>
                {React.cloneElement(I.info,{width:14,height:14,color:t.inkMute})}
                <span style={{ fontSize:12.5, color:t.inkSoft }}>Aucun hébergement dans un rayon de 5 km.</span>
              </div>
              <span style={{ fontSize:11.5, color:t.accent, fontWeight:600, cursor:'pointer' }}>› Élargir la zone de recherche</span>
            </div>
          </div>

          {/* Skeleton / loading states */}
          <div>
            <div style={{ fontSize:11.5, fontWeight:700, color:t.accent, letterSpacing:1, textTransform:'uppercase', marginBottom:14 }}>Skeleton / Loading</div>
            {/* Trip card skeleton */}
            <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:14, overflow:'hidden', marginBottom:12, display:'flex' }}>
              <div className={t.name==='dark'?'dark-shimmer-bg':'shimmer-bg'} style={{ width:160,height:140 }}/>
              <div style={{ flex:1, padding:16, display:'flex', flexDirection:'column', gap:8 }}>
                <Skel w="40%" h={12}/>
                <Skel w="90%" h={18}/>
                <Skel w="60%" h={12}/>
                <div style={{ marginTop:'auto', display:'flex', gap:8 }}>
                  <Skel w={50} h={12}/>
                  <Skel w={50} h={12}/>
                </div>
              </div>
            </div>
            {/* Stage detail skeleton */}
            <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:14, padding:16, marginBottom:12, display:'flex', flexDirection:'column', gap:8 }}>
              <div style={{ display:'flex', gap:8 }}><Skel w={64} h={20} r={10}/><Skel w={80} h={20} r={10}/></div>
              <Skel w="75%" h={22}/>
              <Skel h={60} r={10}/>
              <div style={{ display:'grid', gridTemplateColumns:'repeat(4,1fr)', gap:6 }}>
                {[0,1,2,3].map(i=><Skel key={i} h={44} r={10}/>)}
              </div>
            </div>
            {/* Processing spinner */}
            <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, padding:'16px 20px', display:'flex', alignItems:'center', gap:12 }}>
              <div style={{ width:32,height:32,borderRadius:'50%',border:`3px solid ${t.line}`,borderTopColor:t.accent,animation:'spin 1s linear infinite',flexShrink:0 }}/>
              <div>
                <div style={{ fontSize:13.5,fontWeight:600,marginBottom:2 }}>Recalcul en cours…</div>
                <div style={{ fontSize:11.5,color:t.inkSoft }}>Mise à jour des étapes après modification</div>
              </div>
            </div>
          </div>

          {/* Error states */}
          <div>
            <div style={{ fontSize:11.5, fontWeight:700, color:t.accent, letterSpacing:1, textTransform:'uppercase', marginBottom:14 }}>Erreurs</div>
            {/* GPX parse error */}
            <div style={{ background:t.redSoft, border:`1px solid ${t.red}30`, borderRadius:12, padding:16, marginBottom:12 }}>
              <div style={{ display:'flex', gap:8, alignItems:'flex-start' }}>
                {React.cloneElement(I.alert,{width:16,height:16,color:t.red})}
                <div>
                  <div style={{ fontSize:13,fontWeight:600,color:t.red,marginBottom:3 }}>Fichier GPX invalide</div>
                  <div style={{ fontSize:12,color:t.inkSoft,lineHeight:1.5 }}>Le fichier ne contient aucun waypoint. Vérifiez qu'il s'agit bien d'un tracé GPX.</div>
                  <Btn variant="ghost" size="sm" style={{ marginTop:8, borderColor:t.red, color:t.red }}>Réessayer</Btn>
                </div>
              </div>
            </div>
            {/* URL not supported */}
            <div style={{ background:t.redSoft, border:`1px solid ${t.red}30`, borderRadius:12, padding:16, marginBottom:12 }}>
              <div style={{ display:'flex', gap:8, alignItems:'flex-start' }}>
                {React.cloneElement(I.alert,{width:16,height:16,color:t.red})}
                <div>
                  <div style={{ fontSize:13,fontWeight:600,color:t.red,marginBottom:3 }}>URL non supportée</div>
                  <div style={{ fontSize:12,color:t.inkSoft }}>Formats supportés : Komoot, RideWithGPS, Strava, GPX.</div>
                </div>
              </div>
            </div>
            {/* Offline */}
            <div style={{ background:t.name==='dark'?'#1a1a22':'#f0f0fa', border:`1px solid ${t.blue}30`, borderRadius:12, padding:'10px 16px', marginBottom:12, display:'flex', alignItems:'center', gap:10 }}>
              {React.cloneElement(I.wifiOff,{width:14,height:14,color:t.blue})}
              <div style={{ flex:1, fontSize:12.5, color:t.blue }}>Hors ligne — données en cache affichées.</div>
              <Btn variant="ghost" size="sm" style={{ borderColor:t.blue, color:t.blue, fontSize:11 }}>Réessayer</Btn>
            </div>
            {/* Share link revoked */}
            <div style={{ background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, padding:20, textAlign:'center' }}>
              <div style={{ fontSize:24, marginBottom:8 }}>🔗</div>
              <div style={{ fontSize:13.5,fontWeight:600,marginBottom:6 }}>Lien de partage révoqué</div>
              <p style={{ fontSize:12.5,color:t.inkSoft,margin:'0 0 14px',lineHeight:1.5 }}>Le propriétaire a désactivé ce lien de partage.</p>
              <Btn variant="ghost" size="sm">Retour à l'accueil</Btn>
            </div>
          </div>

          {/* Form states */}
          <div style={{ gridColumn:'span 3' }}>
            <div style={{ fontSize:11.5, fontWeight:700, color:t.accent, letterSpacing:1, textTransform:'uppercase', marginBottom:14 }}>États de formulaires</div>
            <div style={{ display:'grid', gridTemplateColumns:'repeat(4,1fr)', gap:14 }}>
              {/* URL detection states */}
              {[
                { label:'URL — en cours d\'analyse', badge:'Analyse…', badgeColor:t.inkMute, badgeBg:t.surfaceAlt, inputBorder:t.line },
                { label:'Komoot détecté', badge:'Komoot Tour ✓', badgeColor:t.green, badgeBg:t.greenSoft, inputBorder:t.green },
                { label:'Strava détecté', badge:'Strava Route ✓', badgeColor:'#FC4C02', badgeBg:'#fff1e8', inputBorder:'#FC4C02' },
                { label:'URL non supportée', badge:'Non reconnu ✗', badgeColor:t.red, badgeBg:t.redSoft, inputBorder:t.red },
              ].map((s,i)=>(
                <div key={i}>
                  <div style={{ fontSize:11, color:t.inkSoft, marginBottom:5 }}>{s.label}</div>
                  <div style={{ padding:'9px 12px', background:t.surface, border:`1.5px solid ${s.inputBorder}`, borderRadius:9, fontFamily:t.mono, fontSize:12, color:t.ink, marginBottom:5, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>
                    {i===0?'https://...(analyse)':i===1?'komoot.com/tour/284…':i===2?'strava.com/routes/…':'https://maps.google.com/…'}
                  </div>
                  <div style={{ display:'inline-flex', alignItems:'center', gap:5, padding:'3px 9px', background:s.badgeBg, borderRadius:5, fontSize:11, color:s.badgeColor, fontWeight:600 }}>
                    {s.badge}
                  </div>
                </div>
              ))}
            </div>

            <div style={{ display:'grid', gridTemplateColumns:'repeat(4,1fr)', gap:14, marginTop:16 }}>
              {/* Magic link states */}
              {[
                { label:'État 1 — Formulaire saisie', content: (
                  <div>
                    <div style={{ padding:'10px 14px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:9, fontSize:13.5, color:t.inkMute, marginBottom:8, display:'flex', alignItems:'center', gap:8 }}>
                      {React.cloneElement(I.mail,{width:14,height:14})} votre@email.com
                    </div>
                    <Btn variant="accent" size="sm" full>Recevoir le lien</Btn>
                  </div>
                )},
                { label:'État 2 — Email envoyé (timer 60s)', content: (
                  <div style={{ padding:'14px 16px', background:t.greenSoft, border:`1px solid ${t.green}30`, borderRadius:10, textAlign:'center' }}>
                    <div style={{ width:36,height:36,borderRadius:10,background:t.green,color:'#fff',display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 8px' }}>{React.cloneElement(I.check,{width:16,height:16})}</div>
                    <div style={{ fontSize:13,fontWeight:600,color:t.green,marginBottom:2 }}>Email envoyé ✓</div>
                    <div style={{ fontSize:11.5,color:t.inkSoft,marginBottom:10 }}>Vérifie ta boîte · noe@…coop</div>
                    <div style={{ padding:'6px 12px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:7, fontSize:12, color:t.inkMute, opacity:0.5 }}>Renvoyer (42s)</div>
                  </div>
                )},
                { label:'État 3 — Lien expiré / invalide', content: (
                  <div style={{ padding:'14px 16px', background:t.redSoft, border:`1px solid ${t.red}30`, borderRadius:10, textAlign:'center' }}>
                    <div style={{ width:36,height:36,borderRadius:10,background:t.red,color:'#fff',display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 8px' }}>{React.cloneElement(I.alert,{width:16,height:16})}</div>
                    <div style={{ fontSize:13,fontWeight:600,color:t.red,marginBottom:2 }}>Lien expiré ou invalide</div>
                    <div style={{ fontSize:11.5,color:t.inkSoft,marginBottom:10 }}>Les liens expirent après 15 min.</div>
                    <Btn variant="accent" size="sm" style={{ width:'100%' }}>Redemander un lien</Btn>
                  </div>
                )},
                { label:'Email invalide (inline)', content: (
                  <div>
                    <div style={{ padding:'10px 14px', background:t.surface, border:`1.5px solid ${t.red}`, borderRadius:9, fontSize:13.5, color:t.ink, marginBottom:4, display:'flex', alignItems:'center', gap:8 }}>
                      {React.cloneElement(I.mail,{width:14,height:14,color:t.red})} pas-un-email
                    </div>
                    <div style={{ fontSize:11.5, color:t.red, display:'flex', alignItems:'center', gap:4 }}>
                      {React.cloneElement(I.alert,{width:11,height:11})} Format d'email invalide.
                    </div>
                  </div>
                )},
              ].map((s,i)=>(
                <div key={i}>
                  <div style={{ fontSize:11, color:t.inkSoft, marginBottom:5 }}>{s.label}</div>
                  {s.content}
                </div>
              ))}
            </div>

            {/* Validation inline — URL + GPX */}
            <div style={{ fontSize:11.5, fontWeight:700, color:t.accent, letterSpacing:1, textTransform:'uppercase', marginTop:20, marginBottom:14 }}>Validations inline (URL + GPX)</div>
            <div style={{ display:'grid', gridTemplateColumns:'repeat(4,1fr)', gap:14 }}>
              {[
                { label:'URL non supportée', content: (
                  <div>
                    <div style={{ padding:'10px 14px', background:t.surface, border:`1.5px solid ${t.red}`, borderRadius:9, fontSize:12, fontFamily:t.mono, color:t.ink, marginBottom:4, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>
                      https://instagram.com/p/xyz
                    </div>
                    <div style={{ fontSize:11.5, color:t.red, display:'flex', alignItems:'center', gap:4 }}>
                      {React.cloneElement(I.alert,{width:11,height:11})} URL non supportée.
                    </div>
                    <div style={{ fontSize:10.5, color:t.inkMute, marginTop:3 }}>Formats : Komoot, RWGPS, Strava, GPX.</div>
                  </div>
                )},
                { label:'URL YouTube (non supportée)', content: (
                  <div>
                    <div style={{ padding:'10px 14px', background:t.surface, border:`1.5px solid ${t.red}`, borderRadius:9, fontSize:12, fontFamily:t.mono, color:t.ink, marginBottom:4, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>
                      https://youtube.com/watch?v=…
                    </div>
                    <div style={{ fontSize:11.5, color:t.red, display:'flex', alignItems:'center', gap:4 }}>
                      {React.cloneElement(I.alert,{width:11,height:11})} Lien vidéo détecté — non supporté.
                    </div>
                  </div>
                )},
                { label:'GPX trop lourd (>30 Mo)', content: (
                  <div style={{ border:`2px solid ${t.red}`, borderRadius:14, padding:'20px 16px', textAlign:'center', background:t.redSoft }}>
                    <div style={{ width:40,height:40,borderRadius:12,background:t.surface,color:t.red,display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 10px' }}>
                      {React.cloneElement(I.alert,{width:20,height:20})}
                    </div>
                    <div style={{ fontSize:13,fontWeight:600,color:t.red,marginBottom:3 }}>Fichier trop volumineux</div>
                    <div style={{ fontSize:11.5,color:t.inkSoft }}>Le fichier dépasse 30 Mo.</div>
                    <div style={{ fontSize:10.5,color:t.inkMute,marginTop:4,fontFamily:t.mono }}>route-europe.gpx · 48,2 Mo</div>
                  </div>
                )},
                { label:'GPX corrompu / illisible', content: (
                  <div style={{ border:`2px solid ${t.red}`, borderRadius:14, padding:'20px 16px', textAlign:'center', background:t.redSoft }}>
                    <div style={{ width:40,height:40,borderRadius:12,background:t.surface,color:t.red,display:'flex',alignItems:'center',justifyContent:'center',margin:'0 auto 10px' }}>
                      {React.cloneElement(I.alert,{width:20,height:20})}
                    </div>
                    <div style={{ fontSize:13,fontWeight:600,color:t.red,marginBottom:3 }}>Impossible de lire ce fichier GPX</div>
                    <div style={{ fontSize:11.5,color:t.inkSoft }}>Le fichier semble corrompu ou n'est pas au format GPX.</div>
                    <Btn variant="ghost" size="sm" style={{ marginTop:8, borderColor:t.red, color:t.red }}>Réessayer</Btn>
                  </div>
                )},
              ].map((s,i)=>(
                <div key={i}>
                  <div style={{ fontSize:11, color:t.inkSoft, marginBottom:5 }}>{s.label}</div>
                  {s.content}
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
      <DesktopFooter/>
      </div>
    </div>
  );
}

// ─── PICTO LEGEND ─────────────────────────────────────────────────────────────
function PagePictoLegendDesktop({ w = 1280, h = 860 }) {
  const t = useTheme();
  const MapBg3 = () => (<><div style={{position:'absolute',inset:0,opacity:.3,zIndex:0}}><OsmMap w={w} h={h}/></div><div style={{position:'absolute',inset:0,zIndex:0,background:t.name==='dark'?'rgba(26,24,20,.75)':'rgba(250,247,240,.8)'}}/></>);
  const groups = [
    {
      title:'Fin d\'étape', items:[
        { icon:'●', color:'#4a7a3e', label:'Fin étape 1', desc:'Pastille numérotée' },
        { icon:'●', color:'#c2671e', label:'Fin étape 2', desc:'' },
        { icon:'●', color:'#3d6b91', label:'Fin étape 3', desc:'' },
        { icon:'●', color:'#b86a3e', label:'Fin étape 4', desc:'' },
      ]
    },
    {
      title:'Hébergements (9 types)', items:Object.entries(ACC_TYPES).map(([k,v])=>({ icon:v.icon, color:v.color, label:v.label, desc:`OSM: tourism=${k}` }))
    },
    {
      title:'Points d\'eau & ravitaillement', items:[
        { icon:'💧', color:'#3d6b91', label:'Point d\'eau', desc:'Fontaine potable' },
        { icon:'🛒', color:'#4a7a3e', label:'Ravitaillement', desc:'Supérette, boulangerie…' },
        { icon:'☕', color:'#8a5a2e', label:'Café / pause', desc:'Suggestion de pause' },
      ]
    },
    {
      title:'Services vélo & sécurité', items:[
        { icon:'🔧', color:'#c2671e', label:'Atelier vélo', desc:'Réparation à proximité' },
        { icon:'🚉', color:'#3d6b91', label:'Gare SNCF/SNCB', desc:'Évacuation d\'urgence' },
        { icon:'💊', color:'#b83a3a', label:'Pharmacie / hôpital', desc:'Services de santé' },
        { icon:'🌍', color:'#6b3e7a', label:'Passage frontière', desc:'Changement de pays' },
        { icon:'🌊', color:'#3d6b91', label:'Cours d\'eau sans pont', desc:'Traversée difficile' },
        { icon:'🌅', color:'#c2671e', label:'Départ avant l\'aube', desc:'Risque nuit' },
      ]
    },
    {
      title:'POI culturels & événements', items:[
        { icon:'🏛️', color:'#6b5a3e', label:'Musée / monument', desc:'Avec horaires + prix' },
        { icon:'🏰', color:'#5a3e6b', label:'Château', desc:'Wikidata enrichi' },
        { icon:'🔭', color:'#3e6b5a', label:'Point de vue', desc:'Panorama' },
        { icon:'🎪', color:'#8a3a6b', label:'Événement daté', desc:'Festival, marché…' },
        { icon:'🛍️', color:'#6b5a3e', label:'Marché forain', desc:'data.gouv.fr' },
        { icon:'📍', color:'#c2671e', label:'Waypoint utilisateur', desc:'Ajout manuel' },
      ]
    },
  ];
  return (
    <div style={{ width:w, height:h, color:t.ink, fontFamily:t.sans, display:'flex', flexDirection:'column', overflow:'hidden', position:'relative' }}>
      <MapBg3/>
      <div style={{ position:'relative', zIndex:1, display:'flex', flexDirection:'column', flex:1, minHeight:0 }}>
      <TopBarDesktop/>
      <div style={{ flex:1, overflowY:'auto', padding:'32px 48px' }}>
        <h1 style={{ fontFamily:t.serif, fontSize:36, letterSpacing:-0.6, fontWeight:500, margin:'0 0 8px' }}>Système de pictogrammes</h1>
        <p style={{ fontSize:14, color:t.inkSoft, marginBottom:32, lineHeight:1.5 }}>Référence visuelle — light & dark mode. Utilisé sur la carte MapLibre et dans les panneaux d'étape.</p>
        <div style={{ display:'flex', flexDirection:'column', gap:24 }}>
          {groups.map((g,gi)=>(
            <div key={gi}>
              <div style={{ fontSize:12, fontWeight:700, color:t.accent, letterSpacing:1.2, textTransform:'uppercase', marginBottom:10 }}>{g.title}</div>
              <div style={{ display:'flex', flexWrap:'wrap', gap:10 }}>
                {g.items.map((item,i)=>(
                  <div key={i} style={{ display:'flex', alignItems:'center', gap:10, padding:'10px 14px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:12, minWidth:180, boxShadow:t.shadowSoft }}>
                    <div style={{ width:38,height:38,borderRadius:10,background:`${item.color}18`,border:`1.5px solid ${item.color}40`,display:'flex',alignItems:'center',justifyContent:'center',fontSize:18,flexShrink:0 }}>
                      {item.icon}
                    </div>
                    <div>
                      <div style={{ fontSize:12.5, fontWeight:600, color:t.ink }}>{item.label}</div>
                      {item.desc && <div style={{ fontSize:11, color:t.inkMute, marginTop:1 }}>{item.desc}</div>}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
      <DesktopFooter/>
      </div>
    </div>
  );
}

// ─── INFOGRAPHIC 1:1 1080×1080 ────────────────────────────────────────────────
function PageInfographicDesktop({ w = 1080, h = 1080 }) {
  const t = makeTheme('amber', false); // Always light for infographic

  return (
    <ThemeCtx.Provider value={t}>
      <div style={{ width:w, height:h, background:`linear-gradient(135deg, #2a2418 0%, #3d3428 100%)`, color:'#fff', fontFamily:'"Inter Tight", system-ui, sans-serif', overflow:'hidden', position:'relative', display:'flex', flexDirection:'column' }}>
        {/* Background map (faint) */}
        <div style={{ position:'absolute', inset:0, opacity:0.12 }}>
          <OsmMap w={w} h={h}/>
        </div>

        {/* Colored accent band top */}
        <div style={{ height:6, background:`linear-gradient(90deg, ${t.accent}, #b86a3e, #4a7a3e, #3d6b91)`, flexShrink:0 }}/>

        <div style={{ position:'relative', flex:1, display:'flex', flexDirection:'column', padding:64 }}>
          {/* Header */}
          <div style={{ display:'flex', alignItems:'center', gap:12, marginBottom:32 }}>
            <Logo size={36}/>
            <span style={{ fontSize:14, fontWeight:600, color:'rgba(255,255,255,.7)', letterSpacing:0.5 }}>Bike Trip Planner</span>
          </div>

          {/* Title */}
          <h1 style={{ fontFamily:'"Fraunces", Georgia, serif', fontSize:58, fontWeight:500, letterSpacing:-1.5, lineHeight:1.05, margin:'0 0 12px', color:'#fff' }}>
            L'Odyssée des<br/><span style={{ fontStyle:'italic', color:'#f1c999' }}>Eaux Royales</span>
          </h1>
          <div style={{ fontSize:16, color:'rgba(255,255,255,.65)', marginBottom:40 }}>Lille → Gand · 14 – 17 mai 2026</div>

          {/* Map */}
          <div style={{ borderRadius:20, overflow:'hidden', marginBottom:36, flex:'0 0 320px', border:'3px solid rgba(255,255,255,.1)' }}>
            <OsmMap w={952} h={320}/>
          </div>

          {/* Stats grid */}
          <div style={{ display:'grid', gridTemplateColumns:'repeat(4,1fr)', gap:14, marginBottom:32 }}>
            {[
              { l:'Distance', v:'143 km' },
              { l:'Dénivelé', v:'+1 430 m' },
              { l:'Durée', v:'4 jours' },
              { l:'Budget', v:'~€270' },
            ].map((s,i)=>(
              <div key={i} style={{ background:'rgba(255,255,255,.08)', border:'1px solid rgba(255,255,255,.12)', borderRadius:14, padding:'16px 18px', backdropFilter:'blur(10px)' }}>
                <div style={{ fontSize:11, color:'rgba(255,255,255,.5)', fontWeight:600, letterSpacing:0.5, textTransform:'uppercase', marginBottom:6 }}>{s.l}</div>
                <div style={{ fontFamily:'"Fraunces", Georgia, serif', fontSize:26, fontWeight:500, letterSpacing:-0.5 }}>{s.v}</div>
              </div>
            ))}
          </div>

          {/* Stages */}
          <div style={{ display:'grid', gridTemplateColumns:'repeat(4,1fr)', gap:10 }}>
            {STAGES.map((s,i)=>{
              const c = [t.forest,t.accent,t.blue,t.rose][i];
              return (
                <div key={i} style={{ background:'rgba(255,255,255,.06)', border:`1.5px solid ${c}50`, borderRadius:12, padding:'12px 14px' }}>
                  <div style={{ display:'flex', alignItems:'center', gap:6, marginBottom:6 }}>
                    <div style={{ width:20,height:20,borderRadius:5,background:c,color:'#fff',fontSize:10,fontWeight:700,display:'flex',alignItems:'center',justifyContent:'center' }}>{s.day}</div>
                    <span style={{ fontSize:10, color:'rgba(255,255,255,.5)' }}>{s.date}</span>
                  </div>
                  <div style={{ fontSize:12.5, fontWeight:600, lineHeight:1.2, marginBottom:4 }}>{s.from} → {s.to}</div>
                  <div style={{ fontSize:11, color:'rgba(255,255,255,.55)' }}>{s.km} km · +{s.up} m</div>
                </div>
              );
            })}
          </div>

          {/* Footer */}
          <div style={{ marginTop:'auto', paddingTop:20, borderTop:'1px solid rgba(255,255,255,.1)', display:'flex', justifyContent:'space-between', alignItems:'center' }}>
            <div style={{ fontSize:11, color:'rgba(255,255,255,.35)' }}>biketripplanner.app</div>
            <div style={{ fontSize:11, color:'rgba(255,255,255,.35)' }}>© OpenStreetMap contributors</div>
          </div>
        </div>

        {/* Colored accent band bottom */}
        <div style={{ height:6, background:`linear-gradient(90deg, #3d6b91, #4a7a3e, #b86a3e, ${t.accent})`, flexShrink:0 }}/>
      </div>
    </ThemeCtx.Provider>
  );
}

Object.assign(window, {
  PagePrivacyDesktop, PageLegalDesktop, PageStatesDesktop,
  PagePictoLegendDesktop, PageInfographicDesktop,
});
