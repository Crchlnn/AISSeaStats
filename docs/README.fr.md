# AISSeaStats — guide rapide

Statistiques longue durée pour une station [AIS-catcher](https://github.com/jvde-github/AIS-catcher), façon SkyStats : navires vus par heure, jour et mois, top routes sur une carte, top navires, navires remarquables (militaires, secours, grande plaisance, matières dangereuses, très grands navires, pavillons rares, liste de surveillance), flotte par type et pavillon, portée par direction, et une fiche navire avec photo au clic.

![Page de statistiques : chiffres clés, navires vus par heure, top routes et destinations déclarées](screenshots/overview.png)

<p>
  <img src="screenshots/vessel-card.png" alt="Fiche navire : identité, trace sur la carte, passages récents" width="49%">
  <img src="screenshots/fleet-range.png" alt="Top navires, flotte par type et pavillon, portée par direction" width="49%">
</p>

## Prérequis

| | Minimum | Recommandé |
| --- | --- | --- |
| Machine | Linux 64 bits : Raspberry Pi 4 ou 5 sous **Raspberry Pi OS 64 bits**, ou toute machine amd64 | Pi 4 (2 Go et plus) ou Pi 5 |
| Mémoire disponible | 512 Mo | 1 Go |
| Disque libre | 1 Go | 2 Go, idéalement sur SSD plutôt que carte SD |
| Docker | Docker Engine avec le plugin Compose | dernière version stable |
| AIS-catcher | 0.29 ou plus récent, joignable par le réseau | dernière version |

Les systèmes 32 bits (`armv7l`, `armv6l`) ne sont pas pris en charge : l'image officielle MariaDB n'existe qu'en 64 bits.

Vérifications avant installation :

```sh
uname -m                  # doit afficher aarch64 ou x86_64
docker compose version    # doit afficher une version
free -h                   # colonne « available »
df -h .                   # colonne « Avail »
```

Pas encore de Docker ? `curl -fsSL https://get.docker.com | sh`, puis `sudo usermod -aG docker $USER` et reconnectez-vous.

`install.sh` refait ces vérifications et s'arrête avec un message clair s'il manque quelque chose.

## Installation

```sh
git clone https://github.com/Crchlnn/AISSeaStats.git
cd AISSeaStats
./install.sh
```

**Comptez 5 à 10 minutes sur un Raspberry Pi** pour la première installation (6 min 30 mesurées sur un Pi 4), 1 à 2 minutes sur un PC : l'image compile une fois les extensions PHP. Les mises à jour sont beaucoup plus rapides.

Le script crée `.env` avec des mots de passe aléatoires, construit l'image et démarre trois conteneurs : `db`, `app` et `worker`.

Ouvrez ensuite `http://<ip-du-pi>:8095`. L'assistant demande le nom et la position de la station, le fuseau, la langue et un mot de passe d'administration, puis affiche le **jeton** et la ligne à ajouter à AIS-catcher. Faites-le tout de suite après l'installation : tant qu'il n'est pas terminé, n'importe qui sur le réseau local peut le faire.

## Brancher AIS-catcher

### Mode « managed » (interface web)

Dans AIS-catcher : **Output → HTTP → ajouter une sortie**, puis :

| Champ AIS-catcher | Valeur |
| --- | --- |
| Description | libre, par ex. `AISSeaStats` |
| Link | vide |
| URL | `http://<ip-du-pi>:8095/ingest.php` |
| Interval | `15` |
| ID | facultatif : nom de la station, visible dans le journal d'ingestion |
| Credentials | `aisseastats:<jeton>` |
| Protocol | `AISCATCHER` (valeur par défaut) |
| Gzip | activé |
| Response | au choix (activé : la réponse d'AISSeaStats apparaît dans le journal d'AIS-catcher) |
| Unique / Downsample Position | désactivés |

Laissez **Active** coché et **enregistrez avec l'icône de disquette** en haut à droite : tant que « You have unsaved changes » est affiché, rien n'est pris en compte.

L'assistant peut aussi pré-remplir le nom et la position de la station depuis le `config.json` d'AIS-catcher (lu dans le navigateur, jamais envoyé).

### Ligne de commande ou service

Ajoutez ces options à votre commande AIS-catcher existante (ne lancez pas la ligne seule) :

```
-M DTM -H http://<ip-du-pi>:8095/ingest.php interval 15 gzip on userpwd aisseastats:<jeton> id MaStation
```

Gardez `-M DTM` (signal, horodatage, pays du pavillon) : sans lui, le pavillon est déduit du MMSI et le niveau de signal n'est pas enregistré.

### Quelle adresse mettre ?

AIS-catcher tourne en général dans son propre conteneur Docker : `localhost` et `127.0.0.1` y désignent ce conteneur, pas AISSeaStats. Utilisez :

- l'adresse IP du Pi, par ex. `http://192.168.1.20:8095/ingest.php`, ou
- son nom dans votre DNS local (Pi-hole, box), **sans `.local`**, par ex. `http://monpi:8095/ingest.php`.

Les noms en `.local` (mDNS/Bonjour) marchent souvent dans le navigateur, mais pas depuis un conteneur Docker.

Les premiers chiffres arrivent dans la minute. Les routes apparaissent quand un navire a quitté la zone de réception depuis 2 h.

## Mettre à jour

Vos statistiques sont conservées : elles sont dans le volume Docker `aisseastats_db-data`, et vos réglages dans la base et dans `.env`, que ni Git ni la mise à jour ne touchent. Les évolutions de la base s'appliquent automatiquement au démarrage.

```sh
cd AISSeaStats                       # le dossier d'installation
git pull                             # récupère la nouvelle version
docker compose up -d --build         # reconstruit et redémarre (quelques secondes à une minute)
```

Rechargez ensuite la page (Ctrl+F5 / Cmd+Maj+R si l'affichage n'a pas changé). La version figure en bas de page, les nouveautés dans l'[historique des versions](#historique-des-versions).

- **Par prudence, faites une sauvegarde avant** (commande ci-dessous) : la mise à jour ne supprime rien, mais une sauvegarde ne coûte rien.
- **`git pull` refuse** (« your local changes would be overwritten ») : vous avez modifié un fichier suivi. `git stash`, puis `git pull`, puis `git stash pop` pour récupérer votre modification ; vos données ne sont pas concernées.
- **Revenir à une version précédente** : `git checkout v1.0.0-beta.3` (par exemple), puis `docker compose up -d --build`. Les évolutions de la base ne sont pas annulées : préférez restaurer une sauvegarde faite avant la mise à jour.
- Seul `docker compose down -v` efface les données (le `-v` supprime le volume).

## Historique des versions

La plus récente en premier. Détail, corrections et évolutions de la base pour chaque version : [CHANGELOG.fr.md](CHANGELOG.fr.md) (français) · [CHANGELOG.md](../CHANGELOG.md) (English).

| Version | Date | Nouveautés |
|---|---|---|
| 1.0.0-beta.6 | 30/09/2026 | Alertes quand la réception s'arrête puis revient : ntfy, Telegram, webhook (Discord, Slack, Gotify, Home Assistant…) et e-mail, avec bouton de test ; URL heartbeat facultative pour détecter une station éteinte ; type des péniches tiré des données AIS Inland ; taille de la base plus claire dans l'admin |
| 1.0.0-beta.5 | 30/09/2026 | Dictionnaire des destinations dans l'admin (ex. `SAINT-MALO, ST-MALO, FR SML` → `FRSML`) ; destinations sans signification (`0`, `Q`…) affichées « Inconnue » ; clic sur une barre du graphique des navires pour en voir la liste ; portée maximale portée à 1 500 NM par défaut (propagation troposphérique), les positions lointaines devant être confirmées pour établir un record |
| 1.0.0-beta.4 | 30/09/2026 | Un seul sélecteur de période pour toute la page, mémorisé ; actualisation automatique ; clic sur un type, un pavillon, une route ou une destination pour lister ses navires ; barres de flotte lisibles sur téléphone ; vos propres photos de navires, puis Wikimedia Commons / Wikidata ; installation pré-remplie depuis le `config.json` d'AIS-catcher ; corrections de la carte « Access blocked » et des graphiques vides |
| 1.0.0-beta.3 | 29/09/2026 | Pavillon déduit du MMSI ; « pavillon rare » seulement à partir de 100 navires connus ; instructions AIS-catcher plus claires |
| 1.0.0-beta.2 | 29/09/2026 | Correction de « Invalid form token » sous Docker ; durée d'installation indiquée ; vérification mémoire et disque dans `install.sh` |
| 1.0.0-beta.1 | 29/09/2026 | Première version de test |

## Essayer avec des données simulées

```sh
docker compose exec app php bin/simulate.php --hours 72
docker compose exec app php bin/reset-data.php --yes    # pour repartir de zéro ensuite
```

## Commandes utiles

| Action | Commande |
| --- | --- |
| Mettre à jour | `git pull && docker compose up -d --build` |
| Sauvegarder | `docker compose exec db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" aisseastats' \| gzip > aisseastats.sql.gz` |
| Restaurer | `gunzip -c aisseastats.sql.gz \| docker compose exec -T db sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" aisseastats'` |
| Journaux | `docker compose logs -f app worker` |
| État | `docker compose ps` |
| Mot de passe admin perdu | `docker compose exec app php bin/reset-admin.php` |
| Arrêter (données conservées) | `docker compose down` |
| Tout supprimer | `docker compose down -v` |

## Bon à savoir

- **Zones nommées** (page admin) : par défaut une route va d'un secteur à un autre (`SO → NE`). Ajoutez une écluse, un port ou une ville pour lire `Port → Écluse nord`.
- **Dictionnaire des destinations** (page admin) : les équipages saisissent la destination librement (`FRSML`, `FR SML`, `SAINT-MALO`, `ST-MALO`…). Regroupez les variantes d'un port sous un seul nom, par exemple `SAINT-MALO, ST-MALO, FR SML` → `FRSML`. Les valeurs sans signification (`0`, `Q`…) s'affichent « Inconnue ».
- **Portée maximale plausible** (1 500 NM par défaut) : la propagation troposphérique peut amener des messages de plus de 1 000 NM. Au-delà de 50 NM, une position ne compte pour les records de portée que si le navire a été reçu peu avant à une position cohérente.
- **Graphique des navires** : cliquez sur une barre pour voir la liste des navires de cette heure, ce jour ou ce mois.
- **Photos** : dans l'ordre, votre propre photo (admin → Photos de navires, ou lien « Ajouter une photo » sur la fiche quand vous êtes connecté), puis une photo libre de Wikimedia Commons ou Wikidata (par IMO ou MMSI). Beaucoup de bateaux de pêche et de plaisance n'ont aucune photo libre : ajoutez la vôtre. MarineTraffic, VesselFinder et ShipSpotting sont proposés en liens : leurs conditions n'autorisent pas la récupération automatique de leurs photos.
- **Alertes** (page admin) : AIS-catcher ne prévient pas quand la réception s'arrête ; AISSeaStats envoie un message après N minutes sans rien recevoir (30 par défaut), puis un autre quand la réception revient. Canaux, au choix et cumulables : **ntfy** (application sur téléphone, serveur public ntfy.sh ou le vôtre, avec un nom de sujet difficile à deviner), **Telegram** (bot créé avec @BotFather), **webhook** (JSON lisible par Discord, Slack, Gotify, Home Assistant…) et **e-mail** (serveur SMTP de votre messagerie). Le bouton « Envoyer un test » vérifie chaque canal. Ces alertes partent du Pi : s'il est éteint ou sans réseau, seule une URL **heartbeat** (healthchecks.io, Uptime Kuma en mode Push…) permet d'être prévenu.
- **Taille de la base** : l'admin affiche la taille des statistiques (quelques Mo). Le dossier de MariaDB dépasse 100 Mo dès l'installation, car il contient des fichiers de taille fixe, surtout le journal de transactions de 96 Mo. C'est normal, seules les données grandissent.
- **Carte « Access blocked »** ou **graphique vide après changement de période** : corrigés en 1.0.0-beta.4, mettez à jour puis rechargez la page.
