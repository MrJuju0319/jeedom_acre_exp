# Plugin Acreexp

Le plugin **Acreexp** permet de piloter les centrales Acre directement depuis Jeedom sans passer par MQTT. Il prend en charge plusieurs centrales simultanément et s'intègre dans l'interface standard de Jeedom.

## Prérequis

- Jeedom 4.4 ou supérieur.
- Accès réseau aux centrales Acre (HTTP ou HTTPS) avec les identifiants nécessaires.

## Installation

1. Copier le plugin dans le répertoire `plugins/acreexp` de votre instance Jeedom.
2. Installer le plugin depuis l'interface Jeedom puis l'activer.

## Configuration du plugin

1. Rendez-vous dans **Plugins → Sécurité → Acreexp**.
2. Cliquez sur **Ajouter** pour créer une nouvelle centrale.
3. Renseignez :
   - Adresse IP ou nom de domaine
   - Port
   - Protocole (HTTP/HTTPS)
   - Nom d'utilisateur et mot de passe
   - Taux de rafraîchissement des états
4. Sauvegardez l'équipement.

## Synchronisation des commandes

- Utilisez le bouton **Synchroniser** pour récupérer automatiquement les zones, secteurs et portes disponibles sur la centrale.
- Les commandes sont nommées en suivant la convention `Zone1-XXXX-isoler`, `Secteur1-XXXX-MES`, etc.
- Après synchronisation, les commandes apparaissent dans l'onglet **Commandes** avec leurs états respectifs.

## Rafraîchissement des états

- Le plugin déclenche automatiquement les rafraîchissements selon l'intervalle défini dans l'équipement.
- Vous pouvez forcer un rafraîchissement via le bouton **Rafraîchir**.

## Gestion de plusieurs centrales

Chaque équipement Jeedom correspond à une centrale Acre distincte. Ajoutez autant d'équipements que nécessaire, chacun avec ses propres paramètres de connexion et de rafraîchissement.

## Dépannage

- Vérifiez la connectivité réseau (port, protocole, firewall).
- Contrôlez les identifiants utilisés.
- Consultez les logs du plugin (niveau `debug` disponible) pour diagnostiquer les éventuelles erreurs de communication.

## Support

Pour toute question ou suggestion, ouvrez une issue sur le dépôt Git du plugin ou contactez la communauté Jeedom.
