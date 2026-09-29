# AISSeaStats — guide rapide

Statistiques longue durée pour une station [AIS-catcher](https://github.com/jvde-github/AIS-catcher), façon SkyStats : navires vus par heure, jour et mois, top routes sur une carte, top navires, navires remarquables (militaires, secours, grande plaisance, matières dangereuses, très grands navires, pavillons rares, liste de surveillance), flotte par type et pavillon, portée par direction, et une fiche navire avec photo au clic.

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

Rechargez ensuite la page (Ctrl+F5 / Cmd+Maj+R si l'affichage n'a pas changé). La version figure en bas de page, les nouveautés dans [CHANGELOG.md](../CHANGELOG.md).

- **Par prudence, faites une sauvegarde avant** (commande ci-dessous) : la mise à jour ne supprime rien, mais une sauvegarde ne coûte rien.
- **`git pull` refuse** (« your local changes would be overwritten ») : vous avez modifié un fichier suivi. `git stash`, puis `git pull`, puis `git stash pop` pour récupérer votre modification ; vos données ne sont pas concernées.
- **Revenir à une version précédente** : `git checkout v1.0.0-beta.3` (par exemple), puis `docker compose up -d --build`. Les évolutions de la base ne sont pas annulées : préférez restaurer une sauvegarde faite avant la mise à jour.
- Seul `docker compose down -v` efface les données (le `-v` supprime le volume).

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
- **Photos** : dans l'ordre, votre propre photo (admin → Photos de navires, ou lien « Ajouter une photo » sur la fiche quand vous êtes connecté), puis une photo libre de Wikimedia Commons ou Wikidata (par IMO ou MMSI). Beaucoup de bateaux de pêche et de plaisance n'ont aucune photo libre : ajoutez la vôtre. MarineTraffic, VesselFinder et ShipSpotting sont proposés en liens : leurs conditions n'autorisent pas la récupération automatique de leurs photos.
- **Carte « Access blocked »** ou **graphique vide après changement de période** : corrigés en 1.0.0-beta.4, mettez à jour puis rechargez la page.
- **Vie privée** : l'historique des passages d'un plaisancier identifiable par MMSI peut être une donnée personnelle. Gardez la page sur le réseau local ; l'admin permet d'effacer un MMSI.
