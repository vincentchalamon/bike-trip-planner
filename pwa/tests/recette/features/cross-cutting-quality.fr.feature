# language: fr
Fonctionnalité: Qualité transverse — performance, accessibilité, SEO
  En tant qu'utilisateur et webmaster,
  je veux que l'application soit rapide, accessible et référençable,
  afin d'offrir une expérience optimale et un bon référencement.

  @desktop @performance @critique
  Scénario: Score Lighthouse performance supérieur à 80
    Quand j'effectue un audit Lighthouse sur la page d'accueil
    Alors le score de performance est supérieur ou égal à 80

  @desktop @performance @critique
  Scénario: LCP inférieur à 2.5 secondes
    Quand j'effectue un audit Lighthouse sur la page d'accueil
    Alors le LCP (Largest Contentful Paint) est inférieur à 2500ms

  @desktop @performance @critique
  Scénario: CLS inférieur à 0.1
    Quand j'effectue un audit Lighthouse sur la page d'accueil
    Alors le CLS (Cumulative Layout Shift) est inférieur à 0.1

  @desktop @a11y @critique
  Scénario: Score Lighthouse accessibilité supérieur à 90
    Quand j'effectue un audit Lighthouse sur la page d'accueil
    Alors le score d'accessibilité est supérieur ou égal à 90

  @desktop @a11y @critique
  Scénario: Aucune violation critique axe-core sur la page d'accueil
    Quand j'effectue une analyse axe-core sur la page d'accueil
    Alors aucune violation critique d'accessibilité n'est détectée

  @desktop @a11y @critique
  Scénario: Aucune violation critique axe-core sur la page du voyage
    Étant donné que j'ai créé un voyage complet
    Quand j'effectue une analyse axe-core sur la page du voyage
    Alors aucune violation critique d'accessibilité n'est détectée

  @desktop @seo @critique
  Scénario: Score Lighthouse SEO supérieur à 90
    Quand j'effectue un audit Lighthouse sur la page d'accueil
    Alors le score SEO est supérieur ou égal à 90

  @desktop @seo
  Scénario: Balises méta présentes et valides
    Quand je charge la page d'accueil
    Alors la balise <title> est présente et non vide
    Et la balise meta description est présente

  @desktop @a11y
  Scénario: Tous les éléments interactifs ont un label accessible
    Quand je charge la page du voyage
    Alors tous les boutons ont un aria-label ou un texte visible

  @desktop @performance
  Scénario: Chargement de la page d'accueil en moins de 3 secondes
    Quand je charge la page d'accueil
    Alors la page est interactive en moins de 3000ms

  @desktop @performance
  Scénario: Création de voyage complétée en moins de 10 secondes
    Quand je soumets un lien et que le calcul est simulé
    Alors les 3 étapes s'affichent en moins de 10 secondes
