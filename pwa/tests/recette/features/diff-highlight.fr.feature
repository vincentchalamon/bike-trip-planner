# language: fr
Fonctionnalité: Surlignage des différences après recalcul
  En tant que cycliste,
  je veux voir ce qui a changé sur une étape après un recalcul,
  afin de repérer immédiatement l'impact de mes modifications.

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
