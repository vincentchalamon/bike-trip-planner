Recette globale

- Traduire les textes de l'API grâce à l'en-tête "Accept-Language".
- Ajouter un bouton pour changer le thème : système (défaut), clair, sombre.
- Lorsqu'une donnée est en attente de chargement (par exemple : dénivelé, distance, timeline, etc.), un spinner doit être affiché à la place de la donnée. Le spinner ne doit être affiché que pendant le chargement de la donnée concernée. Une fois la donnée chargée, le spinner disparaît et la donnée s'affiche.
- Il manque les champs `fatigueFactor` et `elevationPenalty`.
- (Title) Compléter la liste des noms suggérés jusqu'à 20 noms. Il doit s'agir de femmes d'aventure célèbres, issues de différentes époques et de différentes régions du monde.
- (Title) Lorsque le titre est récupéré depuis l'API, un message doit suggérer un autre titre aléatoire parmi la liste des noms suggérés, pour inciter l'utilisateur à découvrir d'autres femmes d'aventure célèbres. L'utilisateur peut cliquer sur ce message pour appliquer le titre suggéré (auquel cas, la suggestion est appliquée et le message disparaît), ou cliquer sur une croix pour fermer le message sans appliquer la suggestion (auquel cas, le message disparaît sans appliquer la suggestion).
- (Departure/Arrival) Le moteur de recherche doit s'afficher dès le clic pour éditer le champ. À la sélection d'une suggestion, le champ doit se fermer et afficher la valeur sélectionnée.
- (Departure/Arrival) La valeur sélectionnée dans le moteur de recherche ne semble pas envoyée à l'API.
- (Departure/Arrival) Les icônes d'édition doivent toujours être visibles, elles indiquent à l'utilisateur qu'il peut éditer les champs.
- (Magic Link) Le champ doit toujours être éditable, même lorsque les données sont en cours de chargement. Si l'utilisateur saisit une nouvelle URL, toutes les données sont réinitialisées et un nouveau TripRequest est initialisé.
- (Magic Link) Le spinner ne doit être affiché que lorsque les données concernées sont en cours de chargement.
- (Magic Link) Toutes les URL doivent être acceptées, même extérieures à Komoot/Google/etc. Si la valeur saisie n'est pas une URL valide, un message d'erreur doit être affiché pour inviter l'utilisateur à saisir une URL valide. Si une URL valide est renseigné mais qu'aucun RouteFetcher ne supporte cette URL, un message d'erreur doit être affiché pour indiquer que l'URL n'est pas supportée.
- (Export PDF) Le bouton doit être positionné en haut à droite de la page, à côté du bouton de changement de thème. Ces 2 boutons doivent être adaptés au design.
- (Calendrier) Le mois et l'année doivent être centrés. Les boutons de navigation doivent avoir une position fixe et ne pas bouger en fonction de la taille du mois et l'année.
- (Calendrier) Ajouter une animation fluide lors du pliage/dépliage du calendrier.
- (Calendrier) Lorsque l'utilisateur sélectionne une date de début, il ne doit pas pouvoir sélectionner une date de fin antérieure à la date de début.
- (Calendrier) Lorsque l'utilisateur sélectionne une date de début et de fin, puis sélectionne à nouveau une date de début, la date de fin doit être effacée pour éviter les incohérences.
- (Timeline) Dès la saisie de l'URL, la timeline doit s'afficher avec un spinner indiquant un chargement. Une fois les données chargées, le spinner disparaît et la timeline s'affiche avec les étapes du parcours.
- (Timeline) Le bouton "+ Add stage" doit être présent entre chaque jour.
- (Accommodation) Le bouton "+ Add accommodation" doit être présent dans chaque étape sauf la dernière. Actuellement, il n'est pas présent dans la première étape.
- (Stage) Le bouton "+ Add stage" doit être aligné avec les autres éléments de la timeline, et ne pas être décalé vers la droite.
- (Stage) Au survol et au clic sur le bouton "+ Add stage", le curseur doit indiquer que le bouton est cliquable.
- (Accommodation) Remplacer "hotel", "chalet", "camp_site" par des icônes visuelles et un texte explicite (traduit) pour une meilleure compréhension.
- (Accommodation) Afficher le lien vers l'hébergement en plus petit et cliquable à côté du titre de l'hébergement, par exemple : "Mont des Bruyères - www.campingmontdesbruyeres.com".
- (Accommodation) Afficher la fourchette de prix au format numérique pour une meilleure compréhension, par exemple : "10€ - 17€".
- (Accommodation) La liste des hébergements doit être automatiquement ordonnée par prix croissant.
- (Accommodation) Chaque hébergement ne doit avoir qu'1 seul bouton d'édition, situé à côté du bouton de suppression au survol. Au clic sur celui-ci, tous les champs deviennent éditables : titre, URL, type d'hébergement (liste définie), fourchette de prix.
- (Accommodation) Au clic sur le bouton "+ Add accommodation", tous les champs du nouveau bloc sont par défaut affichés éditables.
- (Accommodation) Au survol et au clic sur le bouton "+ Add accommodation", le curseur doit indiquer que le bouton est cliquable.

---

Recette réalisée avec : https://www.komoot.com/fr-fr/tour/2795080048

- La météo ne semble jamais affichée nulle part.
- (Departure/Arrival) Les valeurs ne sont pas récupérées depuis l'API, elles sont toujours vides.
- (Export PDF) Le bouton semble bloqué sur "Computing...".
- (Timeline) La timeline doit être découpée par date. Actuellement, les 2 premiers jours sont regroupés dans la timeline, puis le 3ème jour est affiché.
- (Stage) Le bouton "+ Add accommodation" doit être présent dans chaque étape sauf la dernière. Actuellement, il n'est pas présent dans la première étape.
- (Stage) Lorsqu'il n'est pas possible de déterminer les noms des lieux de départ et d'arrivée, détecter la ville ou la commune la plus proche, sinon afficher les coordonnées GPS simplifiées au lieu de "Unknown location".
- (Stage) Au clic sur le bouton "+ Add stage", une HTTP 422 survient dans l'API, et aucun bloc de stage n'est ajouté à la timeline.
- (Stage) Il doit être possible de supprimer un stage même si cela crée une incohérence dans le parcours. Une alerte doit alors apparaître sur l'une des étapes restante pour indiquer l'incohérence du parcours.
- (Stage) Il doit être possible de supprimer les stages même s'il en reste 1 ou 0. L'utilisateur est alors invité à ajouter de nouveaux stages manuellement. Une alerte doit alors apparaître pour indiquer que le parcours est incomplet tant qu'il n'y a pas au moins 2 étapes.

---

Recette réalisée avec : https://www.komoot.com/fr-fr/collection/2367431/-la-diagonale-ardechoise

- L'import de l'itinéraire ne fonctionne pas : `Computation failed: Collection data not found in Komoot page.`.

---

Recette réalisée avec : https://maps.app.goo.gl/ZGxbgky6ThriXMeV8

- L'import de l'itinéraire ne fonctionne pas : `Computation failed: Cannot extract Google My Maps ID from URL: https://maps.app.goo.gl/ZGxbgky6ThriXMeV8`.

---

Recette réalisée avec : https://www.google.com/maps/dir/Monument+aux+Pigeons+Voyageurs,+Av.+Mathias+Delobel,+59800+Lille/M%C3%A9morial+National+du+Canada+%C3%A0+Vimy,+Route+d%C3%A9partementale+55,+Chem.+des+Canadiens,+62580+Givenchy-en-Gohelle/Camping+La+Paille+Haute,+145+Rue+de+Sailly,+62156+Boiry-Notre-Dame/Camping+Le+Mont+Des+Bruyeres,+806+Rue+Basly,+59230+Saint-Amand-les-Eaux/Monument+aux+Pigeons+Voyageurs,+Av.+Mathias+Delobel,+59800+Lille/@50.4755257,3.0811129,11z/data=!4m52!4m51!1m20!1m1!1s0x47c2d50014cd509d:0x6045253f965cd02e!2m2!1d3.0504031!2d50.6383275!3m4!1m2!1d2.9787367!2d50.5894524!3s0x47dd2b24bd009343:0x1ca83b8c5f880472!3m4!1m2!1d2.9823524!2d50.5844611!3s0x47dd2b3a2900d9fd:0x8df8e0a7d4eeadb!3m4!1m2!1d2.9825307!2d50.5811356!3s0x47dd2b384d4e77eb:0xb45708e2273e0ec9!1m5!1m1!1s0x47dd376aa65c325d:0xb819d1715a18b36b!2m2!1d2.7737521!2d50.3795055!1m10!1m1!1s0x47dd4ad92a25fd37:0x5395558a37d205b4!2m2!1d2.948464!2d50.273365!3m4!1m2!1d3.0708505!2d50.3748737!3s0x47c2cbe05230cd8b:0xbe3a925ebf5175c5!1m5!1m1!1s0x47c2e88e0c0efc0f:0x9d44d43e8c5f1da8!2m2!1d3.463237!2d50.4346561!1m5!1m1!1s0x47c2d50014cd509d:0x6045253f965cd02e!2m2!1d3.0504031!2d50.6383275!3e1?entry=ttu&g_ep=EgoyMDI2MDIyNC4wIKXMDSoASAFQAw%3D%3D

- L'import de l'itinéraire ne fonctionne pas : `Please enter a valid Komoot or Google My Maps URL`.
