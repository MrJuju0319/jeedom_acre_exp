# Plugin ACRE SPC

Le plugin **ACRE SPC** permet d'intégrer une centrale ACRE/Siemens SPC dans Jeedom. Chaque équipement gère désormais sa propre connexion et son environnement Python isolé, ce qui simplifie le déploiement et garantit l'indépendance entre centrales.

## Pré-requis
- Une centrale ACRE/Siemens SPC disposant de l'API Web (licence « 3rd Party »).
- Les identifiants d'un utilisateur autorisé à interroger l'API ainsi que son code PIN.
- Python 3 disponible sur votre système (le plugin s'assure de la présence du module `venv`).

## Installation
1. Installez le plugin depuis le Market Jeedom ou copiez-le dans `plugins/acreexp`.
2. Depuis la page de configuration du plugin, vérifiez les paramètres globaux :
   - **Intervalle de rafraîchissement** : délai minimal (en secondes) entre deux interrogations automatiques d'une centrale.
   - **Binaire Python** : chemin vers le binaire Python 3 à utiliser (laisser vide pour autodétection).
3. Cliquez sur **Relancer** l'installation des dépendances. Le script vérifie la disponibilité de Python et prépare le dossier de travail du plugin.

## Configuration d'un équipement
1. Dans la page du plugin, cliquez sur **Ajouter**.
2. Renseignez les informations générales de l'équipement (nom, objet parent, visibilité…).
3. Complétez ensuite la section **Connexion à la centrale** :
   - Adresse IP ou nom d'hôte de la centrale.
   - Port d'accès à l'API (443 par défaut en HTTPS).
   - Activation de HTTPS si nécessaire.
   - Identifiant et code/PIN de l'utilisateur SPC.
4. Sauvegardez l'équipement : le plugin crée automatiquement un environnement Python virtuel dédié et y installe les dépendances nécessaires.
5. Utilisez le bouton **Synchroniser maintenant** pour récupérer secteurs et zones.

Chaque équipement dispose de son propre dossier dans `plugins/acreexp/data/` contenant le fichier de configuration minimal, le cache de session et le virtualenv. Vous pouvez supprimer ces éléments en supprimant simplement l'équipement dans Jeedom.

## Commandes générées
Pour chaque zone et chaque secteur détecté, le plugin crée automatiquement deux commandes info :
- `…::state` : état numérique (0, 1, 2…) renvoyé par la centrale.
- `…::label` : libellé textuel associé à l'état.

Ces commandes sont historisables et peuvent être utilisées dans vos scénarios ou dashboards.

## Rafraîchissement automatique
Un cron natif se charge d'interroger les centrales actives. L'intervalle de rafraîchissement est commun à tous les équipements et configurable depuis la page du plugin. Le bouton **Synchroniser maintenant** force une interrogation immédiate et met à jour les commandes en créant celles manquantes si besoin.

## Dépannage
- Vérifiez le log `acreexp` pour suivre les opérations.
- Le log `acreexp_dep` contient les traces de l'installation des dépendances Python.
- Assurez-vous que l'utilisateur SPC utilisé possède les droits nécessaires sur l'API.
- En cas de difficultés avec Python, renseignez explicitement le chemin du binaire dans la configuration du plugin.
