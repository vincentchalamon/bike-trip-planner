// app.jsx — Full canvas: desktop-only, light + dark, all screens

function DarkToggle({ dark, setDark }) {
  return (
    <div style={{ position:'fixed', top:16, right:16, zIndex:100, background:'#1a1814', color:'#f5ede0', padding:'7px 13px', borderRadius:999, display:'flex', gap:9, alignItems:'center', fontSize:12, fontWeight:500, fontFamily:'"Inter Tight", system-ui, sans-serif', boxShadow:'0 4px 16px rgba(0,0,0,.3)', border:'1px solid rgba(255,255,255,.1)', cursor:'pointer', userSelect:'none' }} onClick={()=>setDark(!dark)}>
      <span>{dark?'☾':'☀'}</span>
      <span>{dark?'Dark':'Light'}</span>
      <div style={{ width:28,height:14,borderRadius:7,background:dark?'#c2671e':'#555',position:'relative' }}>
        <div style={{ position:'absolute',top:2,left:dark?14:2,width:10,height:10,borderRadius:'50%',background:'#fff',transition:'left 0.15s' }}/>
      </div>
    </div>
  );
}

function App() {
  const [dark, setDark] = React.useState(false);
  const theme = React.useMemo(() => makeTheme('amber', dark), [dark]);

  return (
    <ThemeCtx.Provider value={theme}>
      <DarkToggle dark={dark} setDark={setDark}/>
      <DesignCanvas>

        {/* ── Canvas header ── */}
        <div style={{ padding:'0 60px 52px', maxWidth:1020 }}>
          <div style={{ fontSize:11, fontWeight:700, letterSpacing:2, textTransform:'uppercase', color:'#8b7e66', marginBottom:12 }}>Design complet · Desktop 1280×860 · Light + Dark</div>
          <h1 style={{ fontFamily:'"Fraunces", Georgia, serif', fontSize:56, letterSpacing:-1.3, fontWeight:500, margin:0, marginBottom:12, color:'#2a2418', lineHeight:1.05 }}>
            Bike Trip Planner<br/><span style={{ fontStyle:'italic', color:'#c2671e' }}>tous les écrans</span>.
          </h1>
          <p style={{ fontSize:15, color:'#5a4e3a', maxWidth:780, lineHeight:1.55, margin:0 }}>
            Toutes les pages du produit — wizard 4 étapes, roadbook complet, authentification, gestion des voyages, paramètres, légal, états UI, légende pictos, infographie 1080×1080 — avec features futures (IA, Garmin, batch mode) dessinées comme pleinement fonctionnelles. Basculez light/dark (coin haut-droit).
          </p>
        </div>

        {/* ══════ ① PARCOURS PRINCIPAL ══════ */}
        <DCSection title="① Parcours principal — Wizard 4 étapes" subtitle="Landing → Préparation (chat IA multi-tours) → Aperçu & config → Analyse narrative → Mon voyage" gap={60}>
          <DCArtboard label="/ · Landing — full page (desktop 1280 × 5400)" width={1280} height={5400}><PageLandingDesktop/></DCArtboard>
          <DCArtboard label="/trips/new · Étape 1 — Préparation (Lien · GPX · Chat IA)" width={1280} height={860}><PageTripsNewDesktop/></DCArtboard>
          <DCArtboard label="/trips/new · Étape 2 — Aperçu & configuration" width={1280} height={860}><PageTripsNewDesktopStep2/></DCArtboard>
          <DCArtboard label="/trips/new · Étape 3 — Analyse narrative" width={1280} height={860}><PageProcessingDesktop/></DCArtboard>
          <DCArtboard label="/trips/[id] · Étape 4 — Mon voyage (roadbook complet)" width={1280} height={860}><PageRoadbookDesktop/></DCArtboard>
        </DCSection>

        {/* ══════ ② GESTION DES VOYAGES ══════ */}
        <DCSection title="② Gestion des voyages" subtitle="Liste · vue partagée lecture seule" gap={60}>
          <DCArtboard label="/trips · Liste des voyages" width={1280} height={860}><PageTripsListDesktop/></DCArtboard>
          <DCArtboard label="/s/[code] · Vue partagée (read-only + bandeau)" width={1280} height={860}><PagePublicSharedDesktop/></DCArtboard>
        </DCSection>

        {/* ══════ ③ AUTHENTIFICATION ══════ */}
        <DCSection title="③ Authentification" subtitle="Magic link · 3 états · vérification · demande d'accès" gap={60}>
          <DCArtboard label="/login · État 1 — Formulaire magic link" width={1280} height={860}><PageLoginDesktop state="form"/></DCArtboard>
          <DCArtboard label="/login · État 2 — Email envoyé (timer 60s)" width={1280} height={860}><PageLoginDesktop state="sent"/></DCArtboard>
          <DCArtboard label="/login · État 2b — Renvoyer actif (après 60s)" width={1280} height={860}><PageLoginDesktop state="sent-ready"/></DCArtboard>
          <DCArtboard label="/auth/verify · En cours" width={1280} height={860}><PageAuthVerifyDesktop state="verifying"/></DCArtboard>
          <DCArtboard label="/auth/verify · État 3 — Lien expiré / invalide" width={1280} height={860}><PageAuthVerifyDesktop state="expired"/></DCArtboard>
          <DCArtboard label="/access-requests/verify · Confirmation" width={1280} height={860}><PageAccessRequestDesktop/></DCArtboard>
        </DCSection>

        {/* ══════ ④ COMPTE & LÉGAL ══════ */}
        <DCSection title="④ Compte utilisateur & légal" subtitle="Paramètres RGPD · confidentialité · mentions légales" gap={60}>
          <DCArtboard label="/account/settings · Paramètres compte" width={1280} height={860}><PageAccountSettingsDesktop/></DCArtboard>
          <DCArtboard label="/privacy · Politique de confidentialité" width={1280} height={860}><PagePrivacyDesktop/></DCArtboard>
          <DCArtboard label="/legal · Mentions légales" width={1280} height={860}><PageLegalDesktop/></DCArtboard>
        </DCSection>

        {/* ══════ ⑤ SUPPORT & ERREURS ══════ */}
        <DCSection title="⑤ Support & états d'erreur" subtitle="FAQ (modal) · 404 · 500" gap={60}>
          <DCArtboard label="/faq · FAQ (modale)" width={1280} height={860}><ModalFaq/></DCArtboard>
          <DCArtboard label="/not-found · 404 Hors-piste" width={1280} height={860}><PageNotFoundDesktop/></DCArtboard>
          <DCArtboard label="/error · 500 Erreur serveur" width={1280} height={860}><PageErrorDesktop/></DCArtboard>
        </DCSection>

        {/* ══════ ⑥ MODALS ══════ */}
        <DCSection title="⑥ Modals" subtitle="Partage (lien + infographie + texte + Garmin Connect) · Config panel · Aide (raccourcis + FAQ)" gap={60}>
          <DCArtboard label="Modal · Partager (avec Garmin Connect — Sprint 31)" width={1280} height={860}><ModalShareDesktop/></DCArtboard>
          <DCArtboard label="Modal · Paramètres du voyage" width={1280} height={860}><ModalConfigPanel/></DCArtboard>
          <DCArtboard label="Modal · Aide unifiée (raccourcis + FAQ)" width={1280} height={860}><ModalHelp/></DCArtboard>
        </DCSection>

        {/* ══════ ⑦ ÉTATS & COMPOSANTS ══════ */}
        <DCSection title="⑦ États UI & validations" subtitle="Empty states · skeleton/loading · erreurs · magic link 3 états · validations inline (email, URL, GPX)" gap={60}>
          <DCArtboard label="États UI — empty / skeleton / errors / magic link / validations" width={1280} height={860}><PageStatesDesktop/></DCArtboard>
        </DCSection>

        {/* ══════ ⑧ POI CULTURELS ══════ */}
        <DCSection title="⑧ Popover POI culturel" subtitle="Marqueurs pulsants · Variante A enrichie (Wikidata) · Variante B minimale (OSM)" gap={60}>
          <DCArtboard label="POI Popover — pulsation + variantes A/B" width={1280} height={860}><PagePOIPopoverDesktop/></DCArtboard>
        </DCSection>

        {/* ══════ ⑨ LÉGENDE PICTOS ══════ */}
        <DCSection title="⑨ Système de pictogrammes" subtitle="Légende complète · 9 hébergements · POI · alertes · services" gap={60}>
          <DCArtboard label="Légende pictos — tous les marqueurs carte" width={1280} height={860}><PagePictoLegendDesktop/></DCArtboard>
        </DCSection>

        {/* ══════ ⑩ INFOGRAPHIE ══════ */}
        <DCSection title="⑩ Infographie PNG exportable" subtitle="Format 1:1 · 1080×1080 px · Instagram / WhatsApp / Telegram" gap={60}>
          <DCArtboard label="Infographie — 1080×1080 (format carré)" width={1080} height={1080}><PageInfographicDesktop/></DCArtboard>
        </DCSection>

        <div style={{ height:80 }}/>
      </DesignCanvas>
    </ThemeCtx.Provider>
  );
}

ReactDOM.createRoot(document.getElementById('app')).render(<App/>);
