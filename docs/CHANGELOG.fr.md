# Journal des modifications

Version anglaise : [CHANGELOG.md](../CHANGELOG.md).

## 1.2.0-beta.1 — 23/10/2026

Version de test : publiée seulement sous l'étiquette d'image `1.2.0-beta.1` (jamais `latest`). La 1.2.0 définitive suit dans une à deux semaines.

Nouveautés
- Home Assistant par MQTT (admin → MQTT / Home Assistant) : le worker garde une connexion au serveur MQTT et publie chaque minute, en conservé, l'état de la station sur `aisseastats/<station>/state` (navires du jour, navires actifs, nouveaux navires du jour, messages du jour et de la dernière heure, portée du jour, record de portée, âge du dernier message, réception oui/non). Avec la découverte, un appareil « AISSeaStats » et ses 9 capteurs apparaissent seuls dans Home Assistant. `availability` vaut `online`, et le serveur publie lui-même `offline` si le worker s'arrête (last will). Identifiant et mot de passe, TLS avec ou sans vérification du certificat, bouton de test. Pas de message par navire
- Alertes navires (admin → Alertes navires et résumés) : liste de surveillance, détresse, militaire, autorités et secours, matières dangereuses, très grand navire, pavillon rare, grande plaisance, première visite, nouveau record de portée, propagation exceptionnelle ; actifs par défaut une fois activées : surveillance, détresse, militaire, record de portée. Une fois par navire, motif et passage ; plusieurs motifs en un seul message avec type, pavillon, distance, direction, destination et lien vers la fiche. Heures calmes (sauf détresse) et maximum par heure ; les 100 derniers messages sont listés avec leur résultat
- Résumés quotidien et/ou hebdomadaire à l'heure choisie : navires, nouveaux navires, messages, meilleure portée, navires remarquables, heure la plus chargée ; un bouton envoie tout de suite le résumé du jour
- Canaux par type de message (réception, navires, résumés) parmi ntfy, Telegram, webhook, e-mail et MQTT. Les alertes de réception partent toujours vers tous les canaux configurés, MQTT compris s'il est activé ; « Envoyer un test » des alertes teste aussi MQTT. Les messages webhook et MQTT portent `kind` et, pour un navire, `mmsi`, `triggers`, `distance_nm` et `url`
- Admin → Station : adresse publique facultative de la page, pour les liens des alertes et résumés et le lien « Visiter » de Home Assistant ; `?mmsi=<numéro>` ouvre la fiche d'un navire sur la page
- Constellation : destinations déclarées par les navires de la période autour de la station, avec leurs types (bulles en relief), ou réseau des ports reliés par les changements de destination des navires, flèches dans le sens du trajet ; top 10, 20 ou 30 ; clic sur une bulle ou une flèche pour lister ses navires ; même information en liste
- Records : réception la plus lointaine, plus grand navire, plus rapide, pavillon le plus rare, journée la plus chargée, plus longue présence continue, premier navire reçu
- Signal et distance : niveau moyen du signal par tranches de 2 NM sur la période, pour toute la station et par direction
- Règles de l'admin : explication qu'aucune série de MMSI n'est réservée aux navires militaires

Durcissement (seconde revue de sécurité, 22/10/2026)
- API publique : chaque requête est arrêtée au bout de 20 secondes (`max_statement_time` de MariaDB)
- La construction de l'image échoue s'il manque une extension PHP nécessaire (mbstring, pdo_mysql, json, zlib, OPcache)
- README : « Exposer sur Internet » (reverse proxy HTTPS, admin et ingestion gardés sur le réseau local, écoute sur 127.0.0.1 avec `docker-compose.override.yml`, limitation du débit, pare-feu)
- CI : la vraie pile Docker (base, web, worker) est démarrée et testée de bout en bout (installation avec le code à usage unique, ingestion gzip, connexion admin, worker, données conservées après redémarrage, processus en `www-data`) ; tests MQTT contre Mosquitto
- `docker-compose.hardened.yml` (facultatif) : conteneur web sans privilèges Linux hormis ceux dont ses serveurs ont besoin, `no-new-privileges` ; en cours de validation par la CI, donc pas utilisé par défaut
- Avertissement non bloquant pour un mot de passe admin de moins de 8 caractères (à l'installation et au changement)
- Publication : l'étiquette majeure (`1`) est aussi publiée, `AISSEASTATS_VERSION=1` suit donc toutes les versions 1.x

Base de données : migrations 009 (journal des alertes), 010 (historique des destinations déclarées), 011 (niveau de signal par distance), appliquées automatiquement. L'historique des destinations et les niveaux de signal démarrent avec cette version.

## 1.1.6 — 21/10/2026

Sécurité (revue de code du 21/10/2026 ; aucun constat critique ni haut)
- Connexion admin : les échecs sont comptés par adresse et au total ; après 10 échecs depuis une adresse, ou 100 en tout, en 15 minutes, la connexion est refusée pendant 15 minutes, même avec le bon mot de passe. La pause de 2 secondes après un échec disparaît : elle occupait un processus PHP, si bien que quelques échecs par seconde pouvaient figer le site. `bin/reset-admin.php` lève aussi le blocage
- Assistant d'installation : demande un code à usage unique affiché à la fin d'`install.sh`, dans les journaux du conteneur et par `bin/setup-code.php`, pour que seule la personne qui gère le serveur devienne administrateur ; deux formulaires envoyés en même temps ne peuvent plus terminer tous les deux l'installation
- Photos de navires : cherchées en ligne seulement pour les navires reçus par la station, 30 recherches par minute au plus ; les anciennes recherches sont purgées
- Ingestion : les envois avec un jeton faux ou absent ne sont plus journalisés comme des lots (n'importe qui pouvait remplir le journal ou fausser les indicateurs) ; l'admin affiche toujours le dernier refus, pour repérer un mauvais jeton dans AIS-catcher. Le résumé public ne contient plus le dernier lot
- `INGEST_ALLOW` : une entrée au préfixe absent ou invalide (`192.168.1.0/`, `/abc`, `/40`) est ignorée et signalée dans les journaux ; elle autorisait toutes les adresses
- Conteneur worker : tourne en `www-data`, sans capacités Linux et avec `no-new-privileges`
- Admin → Station : option pour arrondir à environ 1 km la position publiée sur la page et l'API ; les distances utilisent toujours la position exacte
- Les sessions admin expirent après 8 h d'inactivité ; la page publique n'ouvre plus de session pour chaque visiteur
- Noms de zones échappés sur la carte de l'admin ; `SECURITY.md` et le libellé du réglage des photos mis à jour (la recherche de photos n'est plus le seul appel sortant)

Base de données : migration 008 (échecs de connexion admin), appliquée automatiquement.

## 1.1.5 — 15/10/2026

Améliorations
- Carte des top routes : chaque route est tracée le long de son trajet réel le plus typique, celui de ses 12 derniers passages qui ressemble le plus aux autres, au lieu d'une ligne droite entre l'entrée moyenne et la sortie moyenne. Sur un fleuve ou un chenal sinueux, la route suit l'eau ; un détour ou une position aberrante n'est pas choisi. Sans positions pour la période (gardées 30 jours par défaut), la ligne droite est conservée
- Flèches des routes dimensionnées en pixels (identiques à tous les zooms) et détourées : les routes courtes d'un fleuve ou d'un port ne sont plus recouvertes par de grands triangles ; la flèche d'une route suit sa mise en évidence depuis la liste
- Mot de passe d'administration : plus de longueur minimale (il ne doit simplement pas être vide), à l'installation comme au changement, à la demande de plusieurs utilisateurs ; deux mots de passe différents ont désormais leur propre message

Pas de migration de base de données.

## 1.1.4 — 13/10/2026

Améliorations
- Carte de densité du trafic : la portée de la période (trait plein) et le record absolu de portée (pointillés) sont tracés par secteur de 10° autour de la station, comme le graphique de portée ; la case des calques en haut à droite de la carte affiche ou masque chacun (repliée en icône sur téléphone). Sans données de densité, la carte se cadre sur la portée
- Après une mise à jour, le worker construit l'historique de densité par 240 heures par minute au lieu de 48 : 3 minutes environ pour 30 jours de positions au lieu de 15. En attendant, la carte indique jusqu'à quand l'historique est traité, au lieu de « Pas encore de données »

Pas de migration de base de données.

## 1.1.3 — 13/10/2026

Corrections
- Un lot d'AIS-catcher pouvait être perdu avec « Record has changed since last read in table 'stats_hourly' » (MariaDB 11.8, celle de l'image `mariadb:11`, refuse par défaut une transaction qui écrit une ligne modifiée entre-temps par le worker) (ticket #2). Les sessions de base de données passent en READ COMMITTED, et un lot arrêté par un tel conflit, un interblocage ou une attente de verrou trop longue est rejoué depuis le début (jusqu'à 4 fois), compté une seule fois
- Deux lots arrivant en même temps (deux récepteurs) n'écrasent plus mutuellement les compteurs des navires : les navires et passages mis à jour sont verrouillés jusqu'à l'enregistrement du lot

Nouveautés
- Densité du trafic : heatmap des trajets sous la carte des routes (ticket #3, suggestion de @Phil353556). Navire-heures par zone d'environ 1 km, calculés par le worker chaque heure et gardés 400 jours, pour toutes les périodes de la page ; stations de base et aides à la navigation exclues. Après la mise à jour, le worker la remplit en quelques minutes avec les positions encore conservées (30 jours par défaut)
- Leaflet.heat 0.2.0 (licence BSD-2) inclus pour dessiner la heatmap

Base de données : migration 007 (table `traffic_cell`), appliquée automatiquement.

## 1.1.2 — 10/10/2026

Installation et mises à jour
- Image toute prête `ghcr.io/crchlnn/aisseastats` (arm64 et amd64), utilisée par défaut : `install.sh` la télécharge (environ une minute) et ne construit l'image sur la machine que si le téléchargement échoue ; `./install.sh --build` la construit toujours. Mise à jour : `docker compose pull && docker compose up -d`, ou `docker compose up -d --build` comme avant ; les deux peuvent alterner sur la même station
- `AISSEASTATS_VERSION` dans `.env` fixe une étiquette d'image (`1.1`, `1.1.2`…) ; vide = dernière version
- Installation sans Git documentée (`docker-compose.yml` et `.env` seulement), ainsi que les outils de mise à jour automatique comme Watchtower
- Publication de l'image : vérifie que l'étiquette Git correspond à la version, `latest` seulement pour les versions finales (pas les bêtas), `edge` pour un lancement manuel sur main, plus d'attestation affichée comme plateforme « unknown »

Améliorations
- Top navires et habitués : 10, 20, 50 ou 100 par page (mémorisé), avec pages précédente / suivante ; retour à la page 1 quand la période ou le classement change
- Liste des navires d'une barre : tri par messages (par nom pour une heure) ou par distance ; un clic sur une tranche de distance ne liste que ses navires (jusqu'à 300 par tranche), un second clic les montre tous
- Dans cette liste, les distances sont arrondies vers le bas : un navire à 19,96 NM s'affiche 19,9 NM à côté de son carré « < 20 NM »

Pas de migration de base de données.

## 1.1.1 — 10/10/2026

Améliorations
- Liste des navires d'une barre du graphique (heure, jour ou mois) : un carré de couleur indique la tranche de distance de chaque navire, aux couleurs du graphique, avec sa distance maximale ; les totaux par tranche de toute la barre s'affichent en haut
- Portée par direction : l'infobulle nomme le navire qui détient le record de chaque secteur, avec sa date et son heure (pour la période et le record absolu)
- Navires les plus lointains : heure du record à côté de son jour

Base de données : migration 006 (heure de chaque record de portée ; les records des 30 derniers jours retrouvent leur heure à partir des positions échantillonnées quand l'une correspond), appliquée automatiquement.

## 1.1.0 — 10/10/2026

Nouvelles statistiques
- Graphique des navires par tranche de distance (moins de 20 NM, 20 à 50 NM, 50 NM et plus, inconnue), sur 48 h, par jour et par mois
- Jours de propagation exceptionnelle (conduits troposphériques) signalés sur le graphique, avec un lien vers les prévisions ; navires les plus lointains de la période avec jour et direction
- Habitués : les navires qui reviennent le plus régulièrement ; temps entre deux passages (moyen, minimum, maximum) sur la fiche navire
- Heures d'affluence : navires en moyenne par jour de la semaine et par heure (fuseau de la station)
- Réception de la station : bande heure par heure sur les 14 derniers jours, part des heures avec messages et liste des interruptions

Base de données : migration 005 (distance par navire et par heure ; les 48 dernières heures sont reconstituées à partir des positions), appliquée automatiquement.

## 1.0.0-beta.8 — 06/10/2026

Nouveautés
- La capture de débogage écrit aussi un fichier JSON Lines daté (`capture_AAAAMMJJ_HHMMSS.jsonl`), en direct et sans troncature, listé dans l'admin avec téléchargement et suppression ; conservé 30 jours. Contribution de @Phil353556 (pull request #1)
- Fiche navire : lien vers le navire sur aiscatcher.org (le site de la communauté AIS-catcher)

Corrections
- Lien MarineTraffic retiré : MarineTraffic n'ouvre plus un navire à partir de son MMSI, ni par adresse ni par recherche (ses pages utilisent un identifiant interne)
- Fichiers de capture : volume Docker `debug-logs` monté sur `/data/debug`, accessible en écriture par PHP et conservé lors des mises à jour, sans réglage manuel ; tests du fichier de capture

Documentation
- Le README renvoie à la référence des champs JSON d'AIS-catcher

## 1.0.0-beta.7 — 30/09/2026

Nouveautés
- Admin, « Débogage : messages reçus » : enregistre les messages bruts de quelques navires (ou de tous pendant 1 heure), les affiche par navire et par type AIS, et les télécharge en JSON. S'arrête tout seul ; effacé après 7 jours
- Fiche navire : quand le nom manque, explique qu'il n'a pas encore été reçu (message d'identité AIS type 5 ou 24)

Changements
- Graphique des navires : 30 et 90 jours affichent toujours 30 et 90 barres, et 1 an 12 mois, même sur une station récente (les jours vides ne sont plus masqués)

Base de données : migration 004 (table de capture de débogage), appliquée automatiquement.

## 1.0.0-beta.6 — 30/09/2026

Nouveautés
- Alertes (admin) : un message quand aucun message AIS n'a été reçu depuis N minutes (30 par défaut), un autre quand la réception revient. Canaux : ntfy, Telegram, webhook (contenu lisible par Discord, Slack, Mattermost, Gotify, Home Assistant) et e-mail par SMTP (STARTTLS ou SSL/TLS). « Envoyer un test » donne le résultat de chaque canal
- URL heartbeat facultative (healthchecks.io, Uptime Kuma…), appelée toutes les 5 minutes tant que la réception fonctionne, pour être prévenu quand la station elle-même est éteinte
- Navigation intérieure : le type de navire est tiré des données AIS Inland (code ERI) en attendant le message statique du navire ; les péniches n'apparaissent plus en « Type non déclaré »

Changements
- Admin : la tuile « Base de données » devient « Données (tables) », avec l'explication de la taille plus grande du volume Docker de MariaDB (journal de transactions de taille fixe)
- Admin : la liste des zones tient sur un téléphone
- README : captures d'écran
- CI : actions/checkout v5 et machines Ubuntu 24.04 ; le test de fumée part d'une base vide

Pas de migration de base de données.

## 1.0.0-beta.5 — 30/09/2026

Retours du deuxième jour de tests.

Nouveautés
- Dictionnaire des destinations (admin) : regroupez les variantes d'un port sous un seul nom, par exemple « SAINT-MALO, ST-MALO, FR SML » → FRSML. L'admin liste les destinations reçues sur les 90 derniers jours, avec un raccourci « Regrouper… »
- Les destinations sans signification (« 0 », « Q », « NONE »…) s'affichent « Inconnue »
- Clic sur une barre du graphique des navires : liste des navires de cette heure, ce jour ou ce mois

Changements
- Portée maximale plausible portée de 200 à 1 500 NM par défaut (jusqu'à 3 000) : la propagation troposphérique amène des messages de plus de 1 000 NM. Au-delà de 50 NM, une position ne compte pour les records de portée que si le même navire a été reçu peu avant à une position cohérente
- Mention « vie privée » liée au MMSI retirée de la documentation (un MMSI identifie un navire, pas une personne)
- Historique des versions dans le README (anglais et français) et journal des modifications en français

Base de données : migration 003 (portée 200 → 1 500 NM si l'ancienne valeur par défaut était encore en place), appliquée automatiquement.

## 1.0.0-beta.4 — 30/09/2026

Retours des deux premières stations.

Corrections
- Tuiles de carte « Access blocked » (OpenStreetMap) : la page envoie désormais son origine comme Referer
- Le graphique des navires pouvait rester vide après un changement de période sur certains navigateurs : les graphiques ne sont plus animés et sont mis à jour sur place
- Installation : les gestionnaires de mots de passe ne proposent plus d'enregistrer la longitude comme identifiant
- La carte ne se recentre plus à chaque actualisation automatique

Nouveautés
- Un seul sélecteur de période pour toute la page (48 h, 7 j, 30 j, 90 j, 1 an, tout) ; le graphique des navires choisit des barres par heure, jour ou mois
- La dernière période et le choix du « Top navires » sont mémorisés
- Le graphique 48 h affiche la date sous chaque minuit, avec un séparateur
- Actualisation automatique : état chaque minute, le reste toutes les 5 minutes, avec l'heure de la dernière mise à jour
- Clic sur un type de navire, un pavillon, une route ou une destination pour lister ses navires
- Types et pavillons de la flotte en barres lisibles sur téléphone, avec noms de pays et drapeaux plus grands
- Destinations déclarées fusionnées quand elles ne diffèrent que par des espaces ou de la ponctuation (« FR SML » = « FRSML »)
- Photos : vos propres photos (admin), puis Wikimedia Commons et Wikidata par IMO ou MMSI ; lien ShipSpotting
- L'installation peut pré-remplir la station depuis le `config.json` d'AIS-catcher et reprend le fuseau horaire du navigateur
- Aide champ par champ pour la sortie HTTP d'AIS-catcher, avertissement pour les adresses .local
- README : mise à jour, dépannage, sauvegarde et restauration

Base de données : migration 002 (photos de navires, cache de recherche de photos), appliquée automatiquement.

## 1.0.0-beta.3 — 29/09/2026

- Pavillon déduit du MMSI quand AIS-catcher ne l'envoie pas
- « Pavillon rare » seulement à partir de 100 navires connus (réglable)
- Instructions AIS-catcher plus claires à la fin de l'installation
- Note de cadrage rendue neutre pour le dépôt public

## 1.0.0-beta.2 — 29/09/2026

- Correction : l'assistant d'installation et les formulaires de l'admin échouaient avec « Invalid form token » sous Docker (session démarrée après l'envoi de la page)
- Test de fumée dans la CI : installation, connexion admin et réception
- Coordonnées d'exemple neutres dans l'installation
- Durée d'installation indiquée (5 à 10 min sur un Raspberry Pi)
- `install.sh` vérifie la mémoire et l'espace disque

## 1.0.0-beta.1 — 29/09/2026

Première version de test.

- Réception depuis la sortie HTTP d'AIS-catcher (protocoles AISCATCHER et LIST, gzip, Basic ou jeton Bearer)
- Navires, statistiques horaires et journalières, positions échantillonnées, passages, portée par secteur de 10°
- Routes déduites des passages, secteurs de boussole ou zones nommées
- Règles de navires remarquables et liste de surveillance
- Page de statistiques (FR/EN, clair/sombre, mobile) avec fiche navire et photos Wikimedia Commons
- Assistant d'installation et page d'administration
- Docker Compose : MariaDB 11, lighttpd + PHP-FPM 8.3, worker en arrière-plan
- Simulateur de trafic de démonstration et tests sans dépendance
