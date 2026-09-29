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

```
AIS-catcher ... -M DTM -H http://<ip-du-pi>:8095/ingest.php interval 15 gzip on userpwd aisseastats:<jeton> id MaStation
```

En mode « managed », ajoutez une **sortie HTTP** avec la même URL, `userpwd`, intervalle 15 et gzip activé. Gardez `-M DTM` (signal, horodatage, pays du pavillon) : sans lui, le pavillon est déduit du MMSI et le niveau de signal n'est pas enregistré.

Les premiers chiffres arrivent dans la minute. Les routes apparaissent quand un navire a quitté la zone de réception depuis 2 h.

## Essayer avec des données simulées

```sh
docker compose exec app php bin/simulate.php --hours 72
docker compose exec app php bin/reset-data.php --yes    # pour repartir de zéro ensuite
```

## Commandes utiles

| Action | Commande |
| --- | --- |
| Mettre à jour | `git pull && docker compose up -d --build` |
| Journaux | `docker compose logs -f app worker` |
| État | `docker compose ps` |
| Mot de passe admin perdu | `docker compose exec app php bin/reset-admin.php` |
| Arrêter (données conservées) | `docker compose down` |
| Tout supprimer | `docker compose down -v` |

## Bon à savoir

- **Zones nommées** (page admin) : par défaut une route va d'un secteur à un autre (`SO → NE`). Ajoutez une écluse, un port ou une ville pour lire `Port → Écluse nord`.
- **Photos** : Wikimedia Commons, recherche par numéro IMO. Les bateaux sans IMO (fluvial, plaisance) ont une silhouette. MarineTraffic et VesselFinder sont proposés en liens.
- **Vie privée** : l'historique des passages d'un plaisancier identifiable par MMSI peut être une donnée personnelle. Gardez la page sur le réseau local ; l'admin permet d'effacer un MMSI.
