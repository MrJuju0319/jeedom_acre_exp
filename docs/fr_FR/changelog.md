# Changelog

## 1.1.0
- Réécriture complète : chaque équipement dispose de son propre virtualenv Python et d'un fichier de configuration dédié.
- Suppression du démon externe au profit d'un cron Jeedom qui déclenche automatiquement les interrogations.
- Nouveau script d'installation des dépendances vérifiant la présence de Python 3/venv et préparation du répertoire `data` du plugin.

## 1.0.1
- Harmonisation de l'interface du plugin avec le template officiel Jeedom.
- Ajout d'une page de configuration globale et d'un point d'entrée AJAX pour synchroniser les centrales.
- Génération automatique de la documentation au format attendu par le Market.

## 1.0.0
- Version initiale du plugin ACRE SPC pour Jeedom.
