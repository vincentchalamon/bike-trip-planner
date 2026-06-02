// modals.jsx — Share modal (with Garmin Connect) + Config panel

function ModalShell({ children, width = 560, title, sub }) {
  const t = useTheme();
  return (
    <div style={{ width:1280, height:860, background:t.name==='dark'?'rgba(15,13,10,.72)':'rgba(40,30,20,.35)', display:'flex', alignItems:'center', justifyContent:'center', fontFamily:t.sans, position:'relative' }}>
      <div style={{ position:'absolute', inset:0, opacity:.3, filter:'blur(2px)', overflow:'hidden' }}><PageRoadbookDesktop/></div>
      <div style={{ position:'absolute', inset:0, background:t.name==='dark'?'rgba(15,13,10,.55)':'rgba(40,30,20,.3)' }}/>
      <div style={{ position:'relative', width, background:t.surface, borderRadius:16, boxShadow:'0 20px 80px rgba(0,0,0,.25)', border:`1px solid ${t.line}`, overflow:'hidden', color:t.ink, maxHeight:820 }}>
        <div style={{ padding:'18px 22px', borderBottom:`1px solid ${t.line}`, display:'flex', justifyContent:'space-between', alignItems:'start' }}>
          <div>
            <div style={{ fontFamily:t.serif, fontSize:22, fontWeight:500, letterSpacing:-0.3 }}>{title}</div>
            {sub && <div style={{ fontSize:12, color:t.inkSoft, marginTop:3 }}>{sub}</div>}
          </div>
          <div style={{ width:28,height:28,borderRadius:8,background:t.surfaceAlt,color:t.inkSoft,display:'flex',alignItems:'center',justifyContent:'center',cursor:'pointer' }}>{I.close}</div>
        </div>
        {children}
      </div>
    </div>
  );
}

// ─── SHARE MODAL ─────────────────────────────────────────────────────────────
// Source: share-modal.tsx — 4 sections: link, infographic, text, Garmin Connect
function ModalShareDesktop() {
  const t = useTheme();
  const shareUrl = 'https://biketripplanner.app/s/7kR4mN';
  return (
    <ModalShell width={600} title="Partager ce voyage" sub="Lien lecture seule · infographie PNG · texte · Garmin Connect">
      <div style={{ overflowY:'auto', maxHeight:680 }}>
        <div style={{ padding:'20px 22px', display:'flex', flexDirection:'column', gap:0 }}>

          {/* Section 1: Lien partageable */}
          <div style={{ marginBottom:20 }}>
            <div style={{ display:'flex', alignItems:'center', gap:8, marginBottom:10 }}>
              {React.cloneElement(I.link,{width:14,height:14,color:t.inkSoft})}
              <span style={{ fontSize:13, fontWeight:600 }}>Lien partageable</span>
            </div>
            <div style={{ display:'flex', gap:8, alignItems:'center', background:t.surfaceAlt, padding:'9px 12px', borderRadius:10, marginBottom:6 }}>
              <span style={{ flex:1, fontSize:12.5, color:t.accent, fontFamily:t.mono, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>{shareUrl}</span>
              <Btn variant="ghost" size="xs" icon={React.cloneElement(I.copy,{width:12,height:12})}>Copier</Btn>
              <Btn variant="ghost" size="xs" icon={React.cloneElement(I.alert,{width:12,height:12})} style={{ color:t.red, borderColor:`${t.red}40` }}>Révoquer</Btn>
            </div>
            <div style={{ fontSize:11, color:t.inkSoft }}>Accès en lecture seule · sans compte requis.</div>
          </div>

          <div style={{ height:1, background:t.line, marginBottom:20 }}/>

          {/* Section 2: Infographie */}
          <div style={{ marginBottom:20 }}>
            <div style={{ display:'flex', alignItems:'center', gap:8, marginBottom:10 }}>
              {React.cloneElement(I.camera,{width:14,height:14,color:t.inkSoft})}
              <span style={{ fontSize:13, fontWeight:600 }}>Infographie PNG</span>
              <Pill sm bg={t.accentSoft} color={t.accentInk} bold>1080×1080</Pill>
            </div>
            {/* Canvas preview */}
            <div style={{ borderRadius:12, overflow:'hidden', marginBottom:10, position:'relative', height:200, border:`1px solid ${t.line}` }}>
              {/* Simulated infographic preview */}
              <div style={{ width:'100%', height:'100%', background:`linear-gradient(135deg, #2a2418, #3d3428)`, display:'flex', flexDirection:'column', padding:20 }}>
                <div style={{ fontSize:14, fontWeight:600, color:'#f1c999', fontFamily:'"Fraunces", serif', marginBottom:4 }}>{TRIP.title}</div>
                <div style={{ fontSize:11, color:'rgba(255,255,255,.55)', marginBottom:10 }}>Lille → Gand · {TRIP.totalKm} km · {TRIP.days} jours</div>
                <div style={{ flex:1, borderRadius:8, overflow:'hidden', opacity:.8 }}>
                  <OsmMap w={556} h={110} simplified/>
                </div>
                <div style={{ display:'flex', gap:10, marginTop:10 }}>
                  {[{v:`${TRIP.totalKm} km`},{v:`+${TRIP.totalUp}m`},{v:`${TRIP.days} j`}].map((s,i)=>(
                    <div key={i} style={{ background:'rgba(255,255,255,.08)', borderRadius:6, padding:'5px 10px' }}>
                      <div style={{ fontSize:12, fontWeight:600, color:'#fff' }}>{s.v}</div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
            <Btn variant="ghost" size="sm" icon={I.download}>Télécharger PNG (1080×1080)</Btn>
          </div>

          <div style={{ height:1, background:t.line, marginBottom:20 }}/>

          {/* Section 3: Texte résumé */}
          <div style={{ marginBottom:20 }}>
            <div style={{ display:'flex', alignItems:'center', gap:8, marginBottom:10 }}>
              {React.cloneElement(I.list,{width:14,height:14,color:t.inkSoft})}
              <span style={{ fontSize:13, fontWeight:600 }}>Résumé texte</span>
            </div>
            <div style={{ background:t.surfaceAlt, borderRadius:10, padding:'12px 14px', fontSize:11.5, fontFamily:t.mono, color:t.ink, lineHeight:1.7, marginBottom:10, maxHeight:140, overflowY:'auto' }}>
              <div><strong>L'Odyssée des Eaux Royales</strong></div>
              <div>Distance : 143 km · Dénivelé + : 1 430 m</div>
              <div>Dates : 14 — 17 mai 2026</div>
              <div>—</div>
              {STAGES.map((s,i)=>(
                <div key={i}>J{s.day} · {s.from} → {s.to} · {s.km} km · +{s.up} m</div>
              ))}
              <div>—</div>
              <div>Voir en ligne : {shareUrl}</div>
            </div>
            <Btn variant="ghost" size="sm" icon={React.cloneElement(I.copy,{width:12,height:12})}>Copier le texte</Btn>
          </div>

          <div style={{ height:1, background:t.line, marginBottom:20 }}/>

          {/* Section 4: Garmin Connect (Sprint 31) */}
          <div>
            <div style={{ display:'flex', alignItems:'center', gap:8, marginBottom:10 }}>
              <div style={{ width:22,height:22,borderRadius:5,background:'#1a6632',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0 }}>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="white"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
              </div>
              <span style={{ fontSize:13, fontWeight:600 }}>Garmin Connect</span>
              <Pill sm bg="#e8f5e9" color="#1a6632" bold>Sprint 31</Pill>
            </div>

            {/* State A: not connected */}
            <div style={{ background:t.surfaceAlt, border:`1px solid ${t.line}`, borderRadius:12, padding:16, marginBottom:8 }}>
              <div style={{ display:'flex', gap:12, alignItems:'center' }}>
                <div style={{ width:44,height:44,borderRadius:12,background:'#1a6632',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0 }}>
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="white"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
                </div>
                <div style={{ flex:1 }}>
                  <div style={{ fontSize:13, fontWeight:600, marginBottom:2 }}>Non connecté à Garmin Connect</div>
                  <div style={{ fontSize:11.5, color:t.inkSoft }}>Envoyez vos étapes directement vers votre montre Garmin.</div>
                </div>
                <Btn variant="primary" size="sm" style={{ background:'#1a6632', flexShrink:0 }}>Connecter Garmin</Btn>
              </div>
            </div>

            {/* State B: connected */}
            <div style={{ background:t.greenSoft, border:`1px solid ${t.green}30`, borderRadius:12, padding:16 }}>
              <div style={{ display:'flex', gap:12, alignItems:'center', marginBottom:10 }}>
                <div style={{ width:44,height:44,borderRadius:'50%',background:'#1a6632',color:'#fff',display:'flex',alignItems:'center',justifyContent:'center',fontWeight:700,fontSize:16,flexShrink:0 }}>G</div>
                <div style={{ flex:1 }}>
                  <div style={{ fontSize:12, color:t.green, fontWeight:600, marginBottom:1 }}>Connecté</div>
                  <div style={{ fontSize:13, fontWeight:600 }}>noe@les-tilleuls.coop</div>
                </div>
                <span style={{ fontSize:11.5, color:t.inkSoft, cursor:'pointer' }}>Déconnecter</span>
              </div>
              <Btn variant="primary" size="md" full style={{ background:'#1a6632', marginBottom:6 }} icon={I.share}>Envoyer ce voyage vers Garmin Connect</Btn>
              <div style={{ fontSize:11, color:t.inkSoft, textAlign:'center' }}>4 fichiers FIT seront envoyés (une par étape)</div>
            </div>
          </div>
        </div>
      </div>
    </ModalShell>
  );
}

// ─── CONFIG PANEL MODAL ──────────────────────────────────────────────────────
function ModalConfigPanel() {
  const t = useTheme();
  return (
    <ModalShell width={360} title="Paramètres" sub="Voyage · compte · thème · langue">
      <div style={{ padding:'16px 20px', maxHeight:640, overflowY:'auto', display:'flex', flexDirection:'column', gap:18 }}>
        {/* Dates */}
        <div>
          <div style={{ fontSize:12.5, fontWeight:600, marginBottom:8 }}>Dates</div>
          <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:6 }}>
            <div>
              <div style={{ fontSize:10.5, color:t.inkSoft, marginBottom:3 }}>Départ</div>
              <div style={{ padding:'7px 10px', background:t.surface, border:`1.5px solid ${t.accent}`, borderRadius:8, fontSize:12.5, fontFamily:t.mono, fontWeight:600 }}>14 mai</div>
            </div>
            <div>
              <div style={{ fontSize:10.5, color:t.inkSoft, marginBottom:3 }}>Retour</div>
              <div style={{ padding:'7px 10px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:8, fontSize:12.5, fontFamily:t.mono, fontWeight:600, color:t.inkSoft }}>17 mai</div>
            </div>
          </div>
        </div>
        <div style={{ height:1, background:t.line }}/>
        {/* Pacing */}
        <div>
          <div style={{ fontSize:12.5, fontWeight:600, marginBottom:8 }}>Profil cycliste</div>
          <div style={{ display:'flex', gap:4, marginBottom:10 }}>
            {['Débutant','Intermédiaire','Expert'].map((p,i) => (
              <div key={i} style={{ flex:1, padding:'6px 4px', borderRadius:7, border:`1px solid ${i===1?t.accent:t.line}`, background:i===1?t.accent:'transparent', textAlign:'center', fontSize:11.5, fontWeight:600, color:i===1?'#fff':t.inkSoft, cursor:'pointer' }}>{p}</div>
            ))}
          </div>
          {[
            { l:'Distance max / jour', v:'80 km', pct:28 },
            { l:'Vitesse moyenne', v:'15 km/h', pct:22 },
            { l:'Heure de départ', v:'08h00', pct:33 },
            { l:'Fatigue', v:'10 %', pct:20 },
            { l:'Pénalité dénivelé', v:'25 %', pct:25 },
          ].map((s,i)=>(
            <div key={i} style={{ display:'flex', alignItems:'center', gap:7, marginBottom:7 }}>
              <span style={{ fontSize:11, color:t.inkSoft, width:115, flexShrink:0 }}>{s.l}</span>
              <div style={{ flex:1, height:4, background:t.surfaceAlt, borderRadius:2, position:'relative' }}>
                <div style={{ width:`${s.pct}%`, height:'100%', background:t.accent, borderRadius:2 }}/>
                <div style={{ position:'absolute', left:`calc(${s.pct}% - 6px)`, top:-4, width:12, height:12, borderRadius:'50%', background:'#fff', border:`2px solid ${t.accent}` }}/>
              </div>
              <span style={{ fontSize:10.5, fontFamily:t.mono, fontWeight:600, width:46, textAlign:'right', flexShrink:0 }}>{s.v}</span>
            </div>
          ))}
          <div style={{ display:'flex', alignItems:'center', gap:7, marginTop:2 }}>
            <span style={{ fontSize:11, color:t.inkSoft, flex:1 }}>Mode e-bike</span>
            <div style={{ width:30, height:16, borderRadius:8, background:t.surfaceAlt, position:'relative' }}>
              <div style={{ position:'absolute', top:2, left:2, width:12, height:12, borderRadius:'50%', background:'#fff' }}/>
            </div>
          </div>
        </div>
        <div style={{ height:1, background:t.line }}/>
        {/* Accommodation types */}
        <div>
          <div style={{ fontSize:12.5, fontWeight:600, marginBottom:8 }}>Hébergements</div>
          <div style={{ display:'flex', flexDirection:'column', gap:5 }}>
            {Object.entries(ACC_TYPES).map(([k,v],i) => (
              <div key={k} style={{ display:'flex', alignItems:'center', gap:8 }}>
                <div style={{ width:26, height:14, borderRadius:7, background:i<3?t.accent:t.surfaceAlt, position:'relative', flexShrink:0 }}>
                  <div style={{ position:'absolute', top:1, left:i<3?14:1, width:12, height:12, borderRadius:'50%', background:'#fff' }}/>
                </div>
                <span style={{ fontSize:12 }}>{v.icon}</span>
                <span style={{ fontSize:12, color:i<3?t.ink:t.inkSoft }}>{v.label}</span>
              </div>
            ))}
          </div>
        </div>
        <div style={{ height:1, background:t.line }}/>
        {/* Theme + Language */}
        <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center' }}>
          <span style={{ fontSize:12.5, fontWeight:600 }}>Thème</span>
          <div style={{ display:'flex', background:t.surfaceAlt, borderRadius:6, padding:2, gap:1 }}>
            {['Clair','Sombre','Auto'].map((x,i)=>(
              <div key={x} style={{ padding:'4px 9px', borderRadius:5, fontSize:11.5, fontWeight:600, background:i===0?t.accent:'transparent', color:i===0?'#fff':t.inkSoft, cursor:'pointer' }}>{x}</div>
            ))}
          </div>
        </div>
        <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center' }}>
          <span style={{ fontSize:12.5, fontWeight:600 }}>Langue</span>
          <div style={{ display:'flex', background:t.surfaceAlt, borderRadius:6, padding:2, gap:1 }}>
            {['FR','EN'].map((l,i)=>(
              <div key={l} style={{ padding:'4px 10px', borderRadius:5, fontSize:11.5, fontWeight:600, background:i===0?t.accent:'transparent', color:i===0?'#fff':t.inkSoft, cursor:'pointer' }}>{l}</div>
            ))}
          </div>
        </div>
        <div style={{ height:1, background:t.line }}/>
        {/* Trip actions */}
        <div>
          <div style={{ fontSize:12.5, fontWeight:600, marginBottom:8 }}>Actions voyage</div>
          <div style={{ display:'flex', flexDirection:'column', gap:5 }}>
            <Btn variant="ghost" size="sm" full icon={React.cloneElement(I.copy,{width:12,height:12})}>Dupliquer ce voyage</Btn>
            <Btn variant="ghost" size="sm" full icon={I.share}>Partager</Btn>
          </div>
        </div>
      </div>
    </ModalShell>
  );
}

// ─── HELP MODAL (P1) — Raccourcis + FAQ en onglets ──────────────────────────
function ModalHelp() {
  const t = useTheme();
  const [tab, setTab] = React.useState('shortcuts');
  const shortcuts = [
    { key:'J / K', action:'Étape suivante / précédente' },
    { key:'Ctrl+Z', action:'Annuler la dernière action' },
    { key:'Ctrl+Y', action:'Rétablir' },
    { key:'?', action:'Afficher cette aide' },
    { key:'Esc', action:'Fermer les panneaux' },
    { key:'T', action:'Basculer le thème clair/sombre' },
    { key:'M', action:'Afficher / masquer la carte' },
  ];
  return (
    <ModalShell width={520} title="Aide">
      <div style={{ padding:'0 22px 22px' }}>
        <div style={{ display:'flex', gap:2, background:t.surfaceAlt, padding:3, borderRadius:9, margin:'14px 0 18px' }}>
          {[{k:'shortcuts',l:'Raccourcis clavier'},{k:'faq',l:'FAQ rapide'}].map(x => (
            <div key={x.k} onClick={()=>setTab(x.k)} style={{ flex:1, padding:'8px 12px', borderRadius:7, fontSize:13, fontWeight:500, background:x.k===tab?t.surface:'transparent', color:x.k===tab?t.ink:t.inkSoft, textAlign:'center', cursor:'pointer', boxShadow:x.k===tab?t.shadowSoft:'none' }}>{x.l}</div>
          ))}
        </div>
        {tab === 'shortcuts' && (
          <div style={{ display:'flex', flexDirection:'column', gap:4 }}>
            {shortcuts.map((s,i)=>(
              <div key={i} style={{ display:'flex', alignItems:'center', gap:12, padding:'9px 12px', borderRadius:9, background:t.surfaceAlt }}>
                <kbd style={{ padding:'3px 8px', background:t.surface, border:`1px solid ${t.line}`, borderRadius:5, fontFamily:t.mono, fontSize:12, fontWeight:700, color:t.ink, flexShrink:0, boxShadow:`0 1px 2px rgba(0,0,0,.1)` }}>{s.key}</kbd>
                <span style={{ fontSize:13, color:t.inkSoft }}>{s.action}</span>
              </div>
            ))}
          </div>
        )}
        {tab === 'faq' && (
          <div style={{ display:'flex', flexDirection:'column', gap:8 }}>
            {[
              { q:'Quelles sources d\'import ?', a:'Komoot, RideWithGPS, Strava, GPX, IA.' },
              { q:'Comment fonctionne le mode hors-ligne ?', a:'Les voyages consultés sont mis en cache localement sur Android via la PWA.' },
              { q:'Puis-je exporter vers Garmin ?', a:'Oui — exports FIT par étape, et envoi direct vers Garmin Connect (requiert connexion OAuth).' },
              { q:'Mes données sont-elles partagées ?', a:'Non. Vos voyages sont privés. Un lien de partage génère une URL anonyme révocable à tout moment.' },
            ].map((f,i)=>(
              <div key={i} style={{ border:`1px solid ${t.line}`, borderRadius:10, background:t.surface, padding:'12px 14px' }}>
                <div style={{ fontSize:13, fontWeight:600, marginBottom:4 }}>{f.q}</div>
                <div style={{ fontSize:12.5, color:t.inkSoft, lineHeight:1.5 }}>{f.a}</div>
              </div>
            ))}
          </div>
        )}
      </div>
    </ModalShell>
  );
}

// ─── FAQ MODAL ─────────────────────────────────────────────────────────────
function ModalFaq() {
  const t = useTheme();
  const faqs = [
    { q:'Quelles sources d\'import ?', a:'Komoot (Tours et Collections), RideWithGPS, Strava, fichiers GPX standards, et un générateur IA multi-tours.', open:true },
    { q:'Comment est calculé le dénivelé ?', a:'Modèle DEM SRTM 30m avec pénalité configurable. Tient compte de la fatigue cumulative et de la vitesse de montée.' },
    { q:'Les données météo sont fiables au-delà de 7 jours ?', a:'Jusqu\'à 10 jours : prévisions horaires Open-Meteo. Au-delà : normales saisonnières avec indicateur d\'incertitude.' },
    { q:'Comment fonctionne le mode hors-ligne ?', a:'Les voyages consultés sont mis en cache localement via la PWA Android.' },
    { q:'Puis-je exporter vers Garmin ?', a:'Oui — exports FIT par étape et envoi direct vers Garmin Connect (OAuth 2.0 PKCE).' },
    { q:'Mes données sont-elles partagées ?', a:'Non. Vos voyages sont privés. Un lien de partage génère une URL anonyme révocable.' },
    { q:'Combien coûte l\'outil ?', a:'Gratuit pendant la beta. Les premiers utilisateurs conserveront un accès gratuit à vie aux fonctionnalités de base.' },
  ];
  return (
    <ModalShell width={640} title="Foire aux questions">
      <div style={{ overflowY:'auto', maxHeight:660, padding:'12px 22px 22px' }}>
        <div style={{ display:'flex', flexDirection:'column', gap:8 }}>
          {faqs.map((f,i)=>(
            <div key={i} style={{ border:`1px solid ${t.line}`, borderRadius:11, background:t.surface, overflow:'hidden' }}>
              <div style={{ padding:'13px 16px', display:'flex', alignItems:'center', justifyContent:'space-between', background:f.open?t.surfaceAlt:'transparent', cursor:'pointer' }}>
                <span style={{ fontSize:13.5, fontWeight:600 }}>{f.q}</span>
                <span style={{ color:t.inkMute, display:'flex', transform:f.open?'rotate(90deg)':'none' }}>{I.chevron}</span>
              </div>
              {f.open && <div style={{ padding:'0 16px 14px', fontSize:13, color:t.inkSoft, lineHeight:1.6 }}>{f.a}</div>}
            </div>
          ))}
        </div>
      </div>
    </ModalShell>
  );
}

Object.assign(window, { ModalShareDesktop, ModalConfigPanel, ModalHelp, ModalFaq });
