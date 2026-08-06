# language: fr
Fonctionnalité: Fonctionnalités IA
  En tant que cycliste,
  je veux une assistance IA sur mon voyage et mes étapes,
  afin de comprendre mon parcours et d'affiner mon plan en langage naturel.

  @desktop @critique
  Scénario: Affichage de la carte de synthèse IA du voyage
    Étant donné que j'ai créé un voyage avec une synthèse IA
    Alors la carte de synthèse IA du voyage est visible
    Et le résumé narratif IA du voyage est visible

  @desktop
  Scénario: Patterns globaux visibles dans la synthèse IA
    Étant donné que j'ai créé un voyage avec une synthèse IA
    Alors les patterns globaux IA sont visibles

  @desktop
  Scénario: Recommandations inter-étapes visibles dans la synthèse IA
    Étant donné que j'ai créé un voyage avec une synthèse IA
    Alors les recommandations IA inter-étapes sont visibles
    Et les alertes IA inter-étapes sont visibles

  @mobile
  Scénario: Détails de la synthèse IA repliés derrière un bouton sur mobile
    Étant donné que j'ai créé un voyage avec une synthèse IA sur mobile
    Alors les détails de la synthèse IA sont repliés
    Quand je déploie les détails de la synthèse IA
    Alors les détails de la synthèse IA sont visibles

  @desktop @critique
  Scénario: Carte de synthèse IA masquée quand le LLM est absent
    Étant donné que j'ai créé un voyage sans synthèse IA
    Alors la carte de synthèse IA du voyage n'est pas visible

  @desktop @critique
  Scénario: Carte "Analyse IA" présente sur une étape
    Étant donné que j'ai créé un voyage avec une analyse IA par étape
    Alors la carte d'analyse IA de l'étape 1 est visible
    Et la description IA de l'étape 1 est affichée

  @desktop
  Scénario: Insights et suggestions IA affichés sur l'étape
    Étant donné que j'ai créé un voyage avec une analyse IA par étape
    Alors les insights IA de l'étape 1 sont affichés
    Et les suggestions IA de l'étape 1 sont affichées

  @desktop
  Scénario: Déploiement de l'analyse complète des alertes au clic
    Étant donné que j'ai créé un voyage avec une analyse IA par étape
    Quand je déploie les alertes complètes de l'analyse IA de l'étape 1
    Alors la liste complète des alertes IA de l'étape 1 est visible

  @desktop
  Scénario: Application des suggestions IA met une modification en file d'attente
    Étant donné que j'ai créé un voyage avec une analyse IA par étape
    Quand j'applique les suggestions IA de l'étape 1
    Alors la file d'attente des modifications est visible

  # ADR-043 : l'ecran "Apercu" et la carte de raffinement IA mono-coup ont ete
  # retires (flux Saisie -> chargement -> voyage). Les scenarios de la carte de
  # raffinement IA ont ete supprimes en consequence.

  @desktop @critique
  Scénario: Distance modifiée surlignée après recalcul
    Étant donné que j'ai créé un voyage complet avec 3 étapes
    Quand l'étape 1 est recalculée avec une distance modifiée
    Alors le surlignage de diff de la distance de l'étape 1 est visible

  @desktop
  Scénario: Surlignage de diff de distance disparaît après environ 3 secondes
    Étant donné que j'ai créé un voyage complet avec 3 étapes
    Quand l'étape 1 est recalculée avec une distance modifiée
    Alors le surlignage de diff de la distance de l'étape 1 est visible
    Et le surlignage de diff de la distance de l'étape 1 disparaît après 3 secondes

  @desktop
  Scénario: Alerte ajoutée surlignée après recalcul
    Étant donné que j'ai créé un voyage complet avec 3 étapes
    Quand l'étape 1 est recalculée avec une nouvelle alerte
    Alors le surlignage de diff des alertes de l'étape 1 est visible
