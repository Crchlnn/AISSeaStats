# Journal des modifications

Version anglaise : [CHANGELOG.md](../CHANGELOG.md).

## 1.0.0-beta.6 — 30/09/2026

Nouveautés
- Alertes (admin) : un message quand aucun message AIS n'a été reçu depuis N minutes (30 par défaut), un autre quand la réception revient. Canaux : ntfy, Telegram, webhook (contenu lisible par Discord, Slack, Mattermost, Gotify, Home Assistant) et e-mail par SMTP (STARTTLS ou SSL/TLS). « Envoyer un test » donne le résultat de chaque canal
- URL heartbeat facultative (healthchecks.io, Uptime Kuma…), appelée toutes les 5 minutes tant que la réception fonctionne, pour être prévenu quand la station elle-même est éteinte

Changements
- Admin : la tuile « Base de données » devient « Données (tables) », avec l'explication de la taille plus grande du volume Docker de MariaDB (journal de transactions de taille fixe)
- Admin : la liste des zones tient sur un téléphone
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
