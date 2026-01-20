BiblioEnspy - Plugin Moodle (local_biblio_enspy) {Reprise de projet}

Ce plugin est une extension logicielle pour Moodle de l'ecosysteme BiblioEnspy conçue pour moderniser l'accès aux ressources documentaires de l'ENSPY (École Nationale Supérieure Polytechnique de Yaoundé). Il crée un pont entre la plateforme pédagogique Moodle et le fonds documentaire BiblioEnspy de l'école via une synchronisation en temps.

Fonctionnalités Clés :
Authentification Hybride & Délégation de Confiance

    Accès "Zéro Clic" : Si l'étudiant est connecté à Moodle, le plugin l'authentifie automatiquement auprès des services BiblioEnspy.

    Inscription Forcée (register.php) : Collecte des données critiques (Matricule, Département, Niveau) et création d'un mot de passe dédié pour les accès futurs sur les applications Web et Mobile indépendantes.

Consultation & Recherche Avancée

    Catalogue, Filtres Dynamiques, Système de Recommandation

Synchronisation & Notifications Temps Réel


Installation & Déploiement
Prérequis

    Moodle 4.5 ou supérieur.

    PHP 8.2+ avec les extensions curl, sodium, intl, et gd.

    Composer installé sur le serveur.

    Un compte Firebase avec une base de données Firestore.

Procédure de déploiement

    Clonage du dépôt :
    Bash

    cd /opt/lampp/htdocs/moodle/local
    git clone https://github.com/Team-IHM-2027/PluginBiblioEnspy.git biblio_enspy

    Installation des dépendances :
    Bash

    cd biblio_enspy
    composer install

    Configuration Cloud :

        Placez votre fichier de clés de service firebase_credentials.json à la racine du plugin.

        Configurez vos identifiants Firebase dans le script notification_listener.js.

    Finalisation :

        Rendez-vous sur Administration du site > Notifications pour installer les tables de base de données.

        Configurez le serveur SMTP (Gmail) dans Moodle pour l'envoi des mails d'inscription.

Architecture Technique

    Frontend : JavaScript (RequireJS/jQuery) avec SDK Firebase.

    Backend : PHP (Moodle Local API) & Firestore Admin SDK via Composer.

    Persistance : Synchronisation entre Firestore (NoSQL) et la table locale Moodle mdl_local_biblio_notif_sync (SQL) pour garantir la performance.


👥 Équipe Projet

Ce projet est développé par les étudiants de 4GI-2027 (Génie Informatique) de l'ENSPY dans le cadre des travaux du cours dIHM.
##L'actuel depot est une continuité du travaille des 2 promotions precedantes du 4GI.
