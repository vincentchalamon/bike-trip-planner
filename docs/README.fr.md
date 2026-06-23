# Documentation

Documentation de **Bike Trip Planner**, organisée selon le besoin du lecteur. Elle applique le
principe [Diátaxis](https://diataxis.fr/) — des guides distincts pour **apprendre**, **faire**,
**consulter** et **comprendre** — sans imposer les noms de dossiers littéraux.

Vous cherchez la présentation produit ? Voir le README ([Français](../README.fr.md) ·
[English](../README.md)).

## Démarrer — apprendre

| Document | Pour |
|---|---|
| [Démarrage rapide](getting-started.fr.md) | Première utilisation : prérequis, installation, démarrage local de la stack |

## Faire — accomplir une tâche

| Document | Pour |
|---|---|
| [Contribuer](contributing.fr.md) | Workflow de dev, QA, tests, et régénération des captures d'écran |
| [Déploiement](deployment.md) | Pipeline de production, Coolify, monitoring, rollback (EN) |
| [Runbooks](runbooks/) | Procédures d'astreinte : workers, BDD, Redis, Mercure, releases (EN) |
| [Outillage Claude Code](claude-code-tooling.fr.md) | Serveurs MCP, hooks et skills pour le développement assisté par IA |

## Consulter — référence

| Document | Pour |
|---|---|
| [Fonctionnalités](../FEATURES.md) | Inventaire complet des fonctionnalités avec leur statut |
| [Moteur d'alertes](../README.md#alert-engine) | Table de référence canonique des règles (sévérité, priorité, déclencheur) |
| [Sources de données externes](../README.fr.md#sources-de-donn%C3%A9es-externes) | OSM, DataTourisme, Wikidata, Open-Meteo |
| [Légal & licences](legal-and-licensing.fr.md) | Licence, attribution des données, posture RGPD |

## Comprendre — la conception

| Document | Pour |
|---|---|
| [Architecture](architecture.md) | Vue d'ensemble du système : comment les briques s'assemblent, et pourquoi (EN) |
| [Décisions d'architecture (ADR)](adr/) | Chaque choix technique majeur, avec contexte et alternatives (EN) |
| [IA optionnelle (multi-fournisseur, clé personnelle)](adr/adr-042-optional-multi-provider-ai-byo-token.md) | Le modèle d'IA opt-in, par utilisateur — choisir un fournisseur (Anthropic, Gemini, OpenAI) et apporter sa propre clé (EN) |
| [Pipeline IA / LLaMA](LLaMA.md) | Structure historique du pipeline IA auto-hébergé (obsolète — remplacé par l'ADR-042) |

---

Les docs « apprendre » et « faire » ainsi que le README produit sont traduits en français
(`*.fr.md`). Les docs de référence et d'explication (déploiement, runbooks, ADR, architecture)
sont maintenues en anglais. Index en anglais : [docs/README.md](README.md).
