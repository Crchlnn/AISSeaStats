# AISSeaStats 1.2.0 — Note de cadrage

*Version 0.5 — 22 octobre 2026 — validée : durcissement de la seconde revue ajouté, plan en deux étapes*

La 1.2.0 fait sortir AISSeaStats de sa page : les statistiques et les événements remarquables vont là où l'utilisateur regarde déjà (Home Assistant, téléphone, messagerie). Elle ajoute aussi une nouvelle façon de lire le trafic : une vue en bulles et en liens, de type carte mentale.

## 1. Décisions prises (21 octobre 2026)

| Sujet | Décision |
| --- | --- |
| Canaux | MQTT **et** ntfy pour les alertes navires et les résumés ; ntfy existe déjà depuis la 1.0.0-beta.6 (alertes de réception), il est étendu aux nouveaux événements, comme Telegram, le webhook et l'e-mail |
| Constellation | Options **A** (constellation des destinations) **et B** (réseau des ports) |
| Rendu | **2D avec effet relief** (bulles brillantes, ombres) ; pas de 3D |
| Résumé périodique | **Quotidien et hebdomadaire**, au choix de l'utilisateur (l'un, l'autre ou les deux) |
| Granularité | **Pas de publication navire par navire** (ni MQTT ni ntfy) : compteurs, événements et résumés seulement |
| Adresse publique | **Paramètre optionnel dans l'admin**, pour mettre un lien vers la fiche navire dans les alertes |
| Alertes par défaut | Liste de surveillance, secours/urgence, militaire et nouveau record de portée actifs ; les autres déclencheurs désactivés |
| Heure des résumés | 20 h pour le quotidien, dimanche 20 h pour l'hebdomadaire (modifiables) |
| Seconde revue de sécurité (22 octobre) | Ses 5 améliorations retenues entrent dans la 1.2.0, sans urgence (§7.1) |
| Étapes (22 octobre) | **1.2.0-beta.1** avec tout le périmètre (sans étiquette `latest`), puis **1.2.0** une à deux semaines plus tard (§8) |

## 2. Point de départ (1.0 → 1.1.6)

Acquis : compteurs par tranche de distance, jours de propagation, habitués, heures d'affluence, réception de la station, densité du trafic avec portée, top routes le long des trajets réels, alertes de réception (ntfy, Telegram, webhook, e-mail), image Docker toute prête, pagination, tri et filtres.

Retours des utilisateurs à traiter : MQTT pour Home Assistant (demande Discord), alertes sur les navires et non seulement sur la station, préfixes MMSI militaires peu fiables.

## 3. Prérequis : 1.1.6 sécurité (publiée)

Avant la 1.2.0, publier une 1.1.6 avec le plan de la revue de sécurité du 21 octobre 2026 : limitation des tentatives de connexion admin, pas de recherche de photo pour un navire inconnu, envois refusés non journalisés, contrôle strict d'`INGEST_ALLOW`, worker en `www-data` et conteneurs durcis, option d'arrondi de la position publiée, code d'installation à usage unique, finitions. La 1.2.0 ajoute des connexions sortantes (MQTT) : mieux vaut partir d'une base durcie.

## 4. Périmètre 1.2.0

| Bloc | Contenu | Priorité |
| --- | --- | --- |
| MQTT et Home Assistant | Capteurs publiés par MQTT avec découverte automatique | 1 |
| Alertes navires | Navire de la liste de surveillance, militaire, secours… vers les canaux existants et MQTT | 1 |
| Constellation | Nouvelle vue en bulles et en liens, options A et B (voir §5) | 2 |
| Résumé périodique | Bilan du jour ou de la semaine par les canaux d'alerte | 3 |
| Records | Tableau d'honneur de la station | 3 |
| Signal et distance | Qualité de réception selon la distance | 3 |
| Durcissement | Améliorations de la seconde revue de sécurité (§7.1) | 2 |

### 4.1 MQTT et Home Assistant

- Admin → MQTT : serveur, port, identifiant, mot de passe, TLS, préfixe des sujets (`aisseastats/<station>/…`), préfixe de découverte (`homeassistant`).
- Capteurs publiés chaque minute, avec `retain` : navires aujourd'hui, navires actifs maintenant, messages aujourd'hui, messages par heure, portée du jour, record de portée, âge du dernier message, état de la réception (`binary_sensor` connectivité), nouveaux navires du jour.
- Découverte automatique Home Assistant : les capteurs apparaissent seuls, regroupés dans un appareil « AISSeaStats – <station> ».
- Disponibilité : message de dernière volonté (`offline`) si le worker s'arrête.
- Événements (§4.2) publiés sur `aisseastats/<station>/event` (JSON), donc utilisables dans les automatisations.
- Client MQTT 3.1.1 intégré, sans dépendance (comme le client SMTP) : CONNECT, PUBLISH QoS 0 et 1, retain, TLS, keepalive. Bouton « Envoyer un test » comme pour les alertes.
- Hors périmètre : un message par navire (décision du 21 octobre), abonnement (commandes depuis Home Assistant), MQTT 5.

### 4.2 Alertes navires

- Déclencheurs, chacun activable : liste de surveillance, militaire, secours/urgence, autorités, matières dangereuses, très grand navire, pavillon rare, première visite d'un navire, nouveau record de portée, début d'un jour de propagation exceptionnelle.
- Une seule alerte par navire et par passage, pas une par message.
- Plages silencieuses (par exemple 22 h – 7 h) et plafond par heure, contre les rafales.
- Message : nom, type, pavillon, distance, direction, lien vers la fiche navire (si une adresse publique est configurée).
- Canaux : ntfy, Telegram, webhook, e-mail (ceux de la 1.0.0-beta.6) plus MQTT, chacun activable par type d'événement.
- Déclencheurs actifs par défaut : liste de surveillance, secours/urgence, militaire, nouveau record de portée ; les autres désactivés.
- Lien vers la fiche navire si l'adresse publique est renseignée dans l'admin (par exemple `https://ais.exemple.fr/?mmsi=227000000`).
- Journal des 100 dernières alertes dans l'admin.
- Préfixes militaires : expliquer dans l'admin qu'aucun bloc MMSI n'est réservé aux militaires (le pays seul est codé), que le type AIS 35 reste la base, et que la liste est un complément local.

### 4.3 Résumé périodique

Quotidien (20 h par défaut), hebdomadaire (dimanche 20 h par défaut), ou les deux, heures modifiables : navires vus, nouveaux, top 3 des routes, portée, navire le plus lointain, interruptions de réception, alertes du jour. Par les canaux d'alerte et MQTT.

### 4.4 Records (tableau d'honneur)

Navire le plus lointain, le plus grand, le plus rapide, pavillon le plus rare, journée la plus chargée, plus longue présence continue, premier navire reçu. Avec date et lien vers la fiche.

### 4.5 Signal et distance

Nuage de points ou courbe : niveau de signal moyen par tranche de distance (et par secteur). Utile pour régler l'antenne et comparer avant / après un changement. Nécessite d'agréger le signal par distance à l'ingestion (nouvelle petite table).

## 5. Constellation : bulles et liens

Objectif : une vue « carte mentale » qui donne envie d'explorer, comme la figure de référence (bulles brillantes reliées par des traits, une bulle centrale). La question est de choisir **quelles données** relier, pour que la vue apprenne quelque chose que les autres cartes ne montrent pas.

### 5.1 Options

| Option | Bulles | Liens | Ce qu'on apprend | Données |
| --- | --- | --- | --- | --- |
| **A. Constellation des destinations** (retenue) | La station au centre ; autour, les destinations déclarées (taille = nombre de navires) ; au second niveau, les types de navires | Station → destination ; destination → type (épaisseur = navires) | Où vont les navires qui passent devant la station, et lesquels : pétroliers vers tel port, ferries vers telle île, péniches vers tel port fluvial | Déjà disponibles (destination, dictionnaire de destinations, type) |
| **B. Réseau des ports** (retenue) | Les ports | Port précédent → port suivant d'un même navire (épaisseur = nombre de navires) | Les lignes régulières et les escales qui relient les ports vus depuis la station | À collecter : historique des changements de destination (nouvelle table) ; utile après quelques semaines |
| **C. Compagnons** | Les navires | Deux navires vus ensemble, proches et en mouvement (moins de 0,3 NM pendant au moins 10 minutes) | Remorqueurs et pilotes avec les navires qu'ils assistent, convois, navires jumeaux | Positions (30 jours) ; calcul plus lourd, à faire dans le worker |

Décision : **A et B** dans la 1.2.0, dans la même carte avec un sélecteur « Destinations / Ports ». **C** plus tard, en curiosité.

Pour B, l'historique des destinations n'existe pas encore : sa collecte démarre avec la 1.2.0-beta.1, pour que la vue ait une à deux semaines de données à la sortie de la 1.2.0. Tant que l'historique est court, la vue l'indique (« ports reliés depuis le … »).

### 5.2 Lecture et interactions

- Période : celle du sélecteur de la page.
- Clic sur une destination : liste de ses navires (comme les autres listes) ; clic sur un type : navires de ce type vers cette destination.
- Survol : nombre de navires et part du trafic.
- Filtre : top 10, 20 ou 30 destinations ; destinations « inconnue » et « sans signification » masquées par défaut.
- Alternative accessible : la même information en liste, sous le graphique.
- Option B : bulles = ports (taille = navires qui y passent), liens = ports successifs d'un même navire, flèche dans le sens du trajet (épaisseur = nombre de navires) ; clic sur un lien : navires de cette liaison.

### 5.3 2D ou 3D

| | 2D « effet relief » | 3D réelle |
| --- | --- | --- |
| Rendu | Bulles brillantes (dégradés, ombres), disposition par forces, comme la figure | Scène orientable à la souris |
| Lisibilité | Bonne : tout est visible, textes nets | Moindre : bulles qui se cachent, textes à la perspective |
| Poids | Environ 30 Ko (d3-force) ou code maison | Environ 600 Ko à 1 Mo (three.js et une surcouche) |
| Raspberry Pi et téléphone | Fluide | Lourd sur les petits appareils |
| Cohérence | Comme les autres graphiques de la page | Très différent du reste |

Décision : **2D avec effet relief**, sans 3D.

## 6. Données et migrations

| Migration | Contenu |
| --- | --- |
| 008 | (prise par la 1.1.6 : échecs de connexion admin) |
| 009 | Paramètres MQTT (dans `setting`), journal des alertes navires (`vessel_alert` : navire, déclencheur, passage, date, canaux, résultat) |
| 010 | Historique des destinations (`destination_change` : navire, ancienne, nouvelle, date), pour l'option B ; livrée dès la beta.1 |
| 011 | Signal par distance (`signal_band` : jour, tranche de distance, secteur, somme, nombre, min, max) |

Aucune donnée existante n'est modifiée ; tout est appliqué automatiquement, comme d'habitude.

## 7. Sécurité

- Mot de passe MQTT et secrets jamais renvoyés dans les formulaires, comme les secrets d'alerte.
- TLS avec vérification du certificat par défaut ; connexion non chiffrée possible mais signalée.
- Les messages d'alerte ne contiennent que des données AIS publiques et le nom de la station, jamais sa position exacte.
- Plafonds contre les rafales d'alertes et de publications.

### 7.1 Durcissement issu de la seconde revue (22 octobre 2026)

Revue statique externe de la 1.1.6 : aucune faille exploitable confirmée ; 7 points, dont 5 retenus.

| # | Amélioration | Origine | Détail |
| --- | --- | --- | --- |
| 1 | Contrôle des extensions PHP au build | SEC-07 | Le build échoue si `mbstring`, `pdo_mysql` ou `opcache` manque. `mbstring` est bien compilé dans l'image officielle (vérifié, `--enable-mbstring`) : c'est un garde-fou, pas une correction |
| 2 | Durée maximale des requêtes SQL de l'API publique | SEC-03 | `max_statement_time` (MariaDB) sur les connexions de `api.php` ; conseil de limite de débit au reverse proxy dans le README ; cache serveur de 60 s à évaluer |
| 3 | Écoute sur `127.0.0.1` et section « Exposer sur Internet » | SEC-01 | Fichier `docker-compose.override.yml` documenté (`ports: !override`, Compose ≥ 2.24) plutôt qu'une variable `AISSEASTATS_BIND` (syntaxe `${VAR:+…}` incertaine sur les anciennes versions de Compose) ; le `docker-compose.yml` ne change pas ; README : HTTPS obligatoire, exemple Caddy, limite de débit, pare-feu |
| 4 | Test CI sur la vraie pile Docker, puis durcissement du conteneur web | SEC-04 | Job CI `stack` (beta.1) : construit l'image, démarre db + app + worker avec Docker Compose et lance `tests/stack.sh`. Durcissement dans `docker-compose.hardened.yml` (facultatif, `no-new-privileges`, `cap_drop: [ALL]` + SETUID, SETGID, KILL), testé en variante non bloquante ; intégré au `docker-compose.yml` en 1.2.0 si la CI est verte |
| 5 | Avertissement sur les mots de passe courts | SEC-05 | Message non bloquant sous 8 caractères, à l'installation et au changement ; la décision de la 1.1.5 (pas de longueur minimale) est conservée |

Points non retenus, avec leur justification :

- **SEC-02, webhook vers le réseau local** : voulu (alerte vers un Home Assistant local) et réservé à l'admin ; petite amélioration possible, ne plus afficher le corps des réponses en erreur.
- **SEC-06, photos non réencodées** : envoi réservé à l'admin, image servie avec un type fixe et une CSP « sandbox » ; réencoder demanderait la bibliothèque GD pour un gain minime.

## 8. Planning

| Version | Contenu |
| --- | --- |
| 1.1.6 | Correctifs de la revue de sécurité (publiée) |
| 1.2.0-beta.1 | Tout le périmètre : MQTT et découverte Home Assistant, alertes navires (MQTT, ntfy, Telegram, webhook, e-mail), adresse publique dans l'admin, constellation (destinations et ports, 2D avec effet relief), résumés quotidien et hebdomadaire, records, signal et distance, durcissement §7.1 ; la collecte de l'historique des destinations démarre. Le durcissement du conteneur web (§7.1, point 4) n'est inclus que si le job CI sur la vraie pile Docker est vert, sinon il passe à la 1.2.0 |
| 1.2.0 | Une à deux semaines plus tard : corrections issues des retours des testeurs ; le réseau des ports a ses premières semaines de données |

Pourquoi une beta (décision du 22 octobre 2026, deux étapes au lieu de quatre) :

- **Pas d'étiquette `latest`** pour une version beta : seuls les volontaires l'installent (`AISSEASTATS_VERSION=1.2.0-beta.1` ou construction locale) ; les installations en mise à jour automatique restent sur la 1.1.x.
- **Le réseau des ports** a besoin de semaines d'historique : la collecte démarre avec la beta, la vue est utile à la 1.2.0.
- **Ce qui ne se vérifie qu'en conditions réelles** (client MQTT face à Mosquitto et au module MQTT de Home Assistant, durcissement du conteneur web sur les vraies images) passe par la CI et les testeurs avant d'arriver chez tout le monde.

## 9. Questions ouvertes

Aucune à ce stade (toutes tranchées le 21 octobre 2026).

## 10. Risques

| Risque | Parade |
| --- | --- |
| Rafales d'alertes dans un port chargé | Une alerte par passage, plafond horaire, plages silencieuses |
| Client MQTT maison incomplet face à certains serveurs | Tests contre Mosquitto (avec et sans TLS, avec et sans identifiants) et le module MQTT de Home Assistant |
| Constellation illisible avec trop de destinations | Top N, regroupement « autres », dictionnaire de destinations |
| Réseau des ports pauvre au début | Collecte dès la beta ; message « ports reliés depuis le … » tant que l'historique est court |
| Destinations déclarées fantaisistes | Dictionnaire existant, valeurs sans signification regroupées en « inconnue » |
| Durcissement du conteneur web qui empêche PHP-FPM ou lighttpd de démarrer | Appliqué seulement après le job CI sur la vraie pile Docker ; capacités ajoutées une à une selon le test |
