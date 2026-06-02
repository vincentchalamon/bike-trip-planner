# Ordre 2 — Checklists par écran

Checklist de recette par écran : éléments attendus, comportements, états interactifs (hover / focus / disabled / loading / empty / error), responsive et accessibilité clavier.

Les écrans suivent l'[inventaire](01-inventaire-ecrans.md). Légende des cases : `[ ]` à vérifier en recette.

## Grille d'états standard

Appliquée à tout composant interactif, sauf mention contraire :

- **hover** : retour visuel au survol (curseur, couleur, élévation).
- **focus** : anneau de focus visible au clavier (`:focus-visible`), jamais supprimé sans remplacement.
- **disabled** : état grisé, non focusable ou `aria-disabled`, pas d'action.
- **loading** : indicateur (spinner / skeleton), `aria-busy`, action bloquée pendant le traitement.
- **empty** : message explicite + action de sortie quand une liste est vide.
- **error** : message lisible + action de récupération (réessayer / corriger).

## A11y clavier (transverse)

- [ ] Tab parcourt tous les éléments interactifs dans un ordre logique.
- [ ] Focus visible partout, jamais piégé hors d'une modale.
- [ ] Modales : focus trap, `Esc` ferme, focus restauré au déclencheur à la fermeture.
- [ ] Raccourcis documentés (modale d'aide) : `J`/`K` étape suiv./préc., `Ctrl+Z`/`Ctrl+Y` undo/redo, `?` aide, `Esc` fermer, `T` thème, `M` carte.
- [ ] Régions live (`aria-live`) annoncent loading / succès / erreur.
- [ ] axe : 0 violation critique (helper `expectNoCriticalA11yViolations`).

---

## 1. Accueil — `/`

**Anon (landing) :**
- [ ] Barre de nav : logo, sélecteur de langue, toggle thème, "Se connecter", "Demander l'accès".
- [ ] Hero : titre, sous-titre, CTA "Créer mon itinéraire" + "Voir la démo".
- [ ] Sections : "Comment ça marche" (4 étapes), bento avantages, sources supportées, aperçu écrans, CTA early access, footer (liens légaux).
- [ ] CTA et champs : hover, focus, disabled (early access en cours d'envoi).
- [ ] Responsive 390px : sections empilées, lisibles.

**Auth (planner) :**
- [ ] Bascule vers `TripPlanner` sans flash anon (state `loading` -> `null`).
- [ ] error boundary capture une erreur de rendu sans page blanche.

## 2. Connexion — `/login`

- [ ] Champ email, bouton "Envoyer le lien magique", lien "Demander l'accès".
- [ ] Validation email : format invalide -> message inline, bouton disabled.
- [ ] loading : envoi en cours, bouton disabled.
- [ ] Sous-état "email envoyé" : confirmation + cooldown 60s (bouton renvoyer disabled puis actif).
- [ ] Redirection vers `/` si déjà authentifié (anon-only).
- [ ] Clavier : Entrée soumet, focus initial sur le champ email.

## 3. Mes voyages — `/trips`

- [ ] Header : titre, recherche, filtre par date, "Nouveau voyage".
- [ ] loading : spinner + texte (`aria-busy`, `aria-live=polite`).
- [ ] empty : `TripsEmptyState` (incite à créer).
- [ ] no-results (filtres actifs) : message + résumé filtres + reset.
- [ ] error : message + bouton réessayer.
- [ ] populated : grille `TripCard` (hover sur carte), pagination si `> 1` page.
- [ ] Suppression : dialog de confirmation, focus trap, `Esc` annule.
- [ ] Responsive : 1 colonne mobile / 2 colonnes desktop ; date inputs max 10rem.

## 4. Nouveau voyage — `/trips/new`

- [ ] `WizardStepper` reflète l'étape courante ; `?step=` synchro avec l'UI.
- [ ] step 1 : onglets "Lien URL" / "Fichier GPX" / "Assistant IA".
  - [ ] URL : détection auto (badge "Komoot détecté" etc.), URL non supportée -> message.
  - [ ] GPX : drop zone (états idle / uploading + barre / success / error fichier invalide).
  - [ ] Assistant IA : chat (saisie, envoi, bulles user/assistant, "Valider et continuer").
- [ ] step 2 : carte, profil altimétrique, stats, sliders pacing, dates, hébergements, `AiRefinementCard`, CTA "Lancer l'analyse" + "Retour".
- [ ] step 3 : barre de progression globale + actes de calcul (done/running/pending) + aperçu temps réel.
- [ ] step 4 : redirection vers `/trips/[id]`.
- [ ] disabled : "Continuer" tant que la source n'est pas valide.
- [ ] Clavier : navigation entre onglets, sliders au clavier (flèches).

## 5. Détail voyage — `/trips/[id]`

- [ ] loading : skeletons (résumé, sidebar timeline, panneau étape).
- [ ] error : `TripNotFound`.
- [ ] populated : header voyage (titre éditable, dates, km, D+, difficulté), synthèse IA globale.
- [ ] Layout 3 colonnes desktop : timeline étapes | carte + profils | détail étape.
- [ ] Détail étape : métriques (km éditable, D+, départ, ETA), météo, alertes groupées par sévérité, événements (repliable), hébergements (sélection + alternatives), téléchargements GPX/FIT.
- [ ] Édition : champ distance éditable (focus, validation, recalcul), `inline-recomputation-bar` pendant le recalcul.
- [ ] Diff IA : surlignage transitoire des champs modifiés (~3s) + annonce lecteur d'écran.
- [ ] Undo/redo : boutons + raccourcis.
- [ ] computing : barre de recalcul, `aria-busy`.
- [ ] no-dates : `no-dates-banner` quand les dates manquent.
- [ ] locked : `trip-locked-banner` si verrouillé.
- [ ] Responsive : colonnes empilées sur mobile, `ViewModeToggle`.

## 6. Vue partagée — `/s/[code]`

- [ ] Bannière "lecture seule" (`SharedViewBanner`) visible en haut.
- [ ] Top bar simplifiée : logo, "Télécharger GPX" / "Télécharger FIT".
- [ ] loading : spinner centré.
- [ ] error (lien révoqué / non trouvé) : `TripNotFound` variant share.
- [ ] populated : `TripSummary` read-only, `ViewModeToggle` (timeline/map/split), `RoadbookMasterDetail` read-only.
- [ ] Aucun contrôle d'édition visible (pas de champ éditable, pas d'undo/redo).
- [ ] Responsive : split flex-col mobile / flex-row desktop ; carte masquée sur mobile en split.

## 7. Paramètres du compte — `/account/settings`

- [ ] Header sticky : logo (masqué mobile), retour.
- [ ] Section Email : affichage + "Modifier via magic link".
- [ ] Section Préférences : langue FR/EN, thème clair/sombre/auto.
- [ ] Section RGPD : "Télécharger mes données (JSON)" (loading pendant l'export).
- [ ] Section Danger Zone : "Supprimer mon compte…" -> dialog destructif (confirmation explicite).
- [ ] Section Déconnexion : logout.
- [ ] error : échec d'une action -> message + récupération.
- [ ] Responsive : max-width 800px, padding adaptatif.

## 8. Vérification demande d'accès — `/access-requests/verify`

- [ ] loading : spinner + "Verifying…" (`role=status`).
- [ ] succès : redirection backend puis `/?access=confirmed`.
- [ ] error (params manquants) : redirection auto vers `/`.

## 9. Vérification lien magique — `/auth/verify/[token]`

- [ ] loading : spinner + "Verifying…".
- [ ] succès : session ouverte, redirection vers `/`.
- [ ] error (token expiré/invalide) : `LinkExpired` + "Renvoyer un lien" -> `/login`.

## 10. FAQ — `/faq`

- [ ] Accordéon 3 catégories ; items repliables (hover, focus, expand/collapse au clavier `Entrée`/`Espace`).
- [ ] `aria-expanded` cohérent.
- [ ] Liens retour fonctionnels.
- [ ] Responsive : max-width 2xl.

## 11. Mentions légales — `/legal`

- [ ] Sommaire latéral + sections (éditeur, hébergeur, contact, propriété intellectuelle, licence).
- [ ] Liens d'ancrage du sommaire fonctionnels (focus déplacé).
- [ ] Footer présent.

## 12. Confidentialité — `/privacy`

- [ ] Sommaire latéral + 9 sections (responsable -> contact).
- [ ] Mention base légale RGPD + date de mise à jour.
- [ ] Liens d'ancrage fonctionnels.
- [ ] Footer présent.

## États système (404 / 500)

- [ ] 404 : message "Hors-piste", CTA "Retour à l'accueil" + "Mes voyages".
- [ ] 500 : icône d'alerte, badge "Erreur serveur · 500", `request_id` + timestamp, CTA "Réessayer" + "Signaler".
