# EGLISE GERME DE DAVID — Gestion financière

Application PHP 8 + MySQL/TiDB Cloud pour XAMPP et hébergement PHP/MySQL. Interface adaptée ordinateur et téléphone.

## Installation nouvelle
1. Décompresser dans `C:\xampp\htdocs\eglise_germe_de_david`.
2. Démarrer Apache et MySQL.
3. Importer `install.sql` dans phpMyAdmin.
4. Vérifier `config.php` (XAMPP par défaut root / mot de passe vide).
5. Ouvrir `/eglise_germe_de_david/setup_admin.php`, créer l'administrateur puis se connecter.

## Mise à niveau de la première version
Faire une sauvegarde d'abord. Dans phpMyAdmin, sélectionner `eglise_germe_de_david`, importer `upgrade.sql`, puis remplacer les fichiers de l'application par cette version. Ne réimportez pas `install.sql` sur une base existante. Le premier compte créé précédemment doit recevoir le rôle admin : `UPDATE users SET role='admin' WHERE id=1;` si nécessaire.

## Comptabilité et modèles
- Comptes séparés : offrandes ordinaires, offrandes spéciales, loyer parcelle, eau, social et comptes ajoutés.
- USD et FC restent séparés dans les écritures ; un taux paramétrable permet une conversion automatique dans le convertisseur.
- Solde = solde initial + entrées - sorties ; solde cumulé reporté de mois en mois.
- Le solde final d'une année devient le solde initial de la même devise et du même compte l'année suivante.
- Offrandes ordinaires : mois, entrée, sortie, solde, solde cumulé. Offrandes spéciales : date, entrées, sorties, libellés et soldes. Les colonnes de conversion et le taux du fichier Excel servent de repère visuel dans le modèle d'origine ; la comptabilité applicative n'effectue pas de conversion automatique afin d'éviter les mélanges de monnaies.
- Le suivi du loyer est disponible par compte, catégorie/locataire et devise.

## Sécurité
- Rôles admin, trésorier, consultation.
- Modifications/suppressions des écritures autorisées avant clôture et enregistrées dans l'audit.
- Une clôture mensuelle unique par compte/devise ; opérations des mois clôturés verrouillées ; toute correction post-clôture exige une intervention administrative sur la base après sauvegarde.
- Export CSV ouvrable dans Excel ; impression navigateur et Enregistrer en PDF.
- Sauvegarde SQL et restauration réservées à l'administrateur.
- Logo, photo et description de l'église, taux USD/FC et URL distante personnalisables dans Paramètres.
- Les dépenses sont regroupées automatiquement par mots-clés du libellé (carburant pasteur, entretien, transport, SNEL, garde, assistance, etc.) dans les rapports mensuels et annuels.

## Accès distant
Le logiciel local ne devient pas accessible sur Internet par le seul fait d'être dans XAMPP. Configurez un hébergement PHP/MySQL ou un tunnel HTTPS sécurisé vers le serveur. Dans Paramètres, enregistrez l'URL publique ; le menu Accès à distance permet de l'ouvrir. Ne publiez jamais phpMyAdmin. Gardez le PC et le tunnel allumés si vous utilisez le serveur local.

## Limites et vérifications
Tester l'application sur une copie de la base avant utilisation réelle. Faire des sauvegardes régulières hors du PC serveur. La restauration SQL attend un dump compatible avec cette application. Le PDF est produit par la fonction d'impression du navigateur ; le CSV est exportable et lisible par Excel.
\nLe taux de change initial proposé est de 2 800 FC pour 1 USD : vérifiez et ajustez-le dans Paramètres avant utilisation. La page d'accueil affiche la photo ajoutée dans Paramètres.\n
## Déploiement cloud gratuit (PHP + TiDB Cloud)

1. Créez un cluster TiDB Cloud Starter (offre/quotas gratuits selon disponibilité du compte et de la région).
2. Créez une base `eglise_germe_de_david`, puis exécutez `install_cloud.sql` dans cette base. Ce script ne contient pas `CREATE DATABASE` ni `USE` : sélectionnez la base dans votre client SQL.
3. Sur l'hébergement PHP/Docker, définissez `DB_HOST`, `DB_PORT` (TiDB Cloud utilise généralement 4000), `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SSL=1` et, si nécessaire, `DB_SSL_CA`. Ne publiez pas ces secrets dans Git.
4. `DB_SSL=1` active TLS avec vérification du certificat. Ne désactivez pas la vérification du certificat serveur.
5. Vérifiez la liste d'adresses IP autorisées (IP allowlist) du cluster et ajoutez l'adresse de sortie de l'hébergeur PHP.
6. Déployez le dossier de l'application avec le `Dockerfile` fourni. Le fichier `render.yaml` est un exemple de configuration ; vérifiez l'offre gratuite et les limitations de stockage/veille de l'hébergeur avant la mise en production.
7. Après déploiement, ouvrez `setup_admin.php` pour créer le premier compte administrateur, puis supprimez ou protégez cette page une fois l'initialisation terminée.

**Attention aux fichiers locaux :** l'hébergement gratuit peut utiliser un disque éphémère. Les images/logo uploadés dans `uploads/` peuvent disparaître après un redéploiement. Pour une installation cloud durable, utilisez un stockage d'images externe ou conservez une sauvegarde des fichiers.

## Catégories de transactions

Les catégories peuvent maintenant être choisies à la saisie d'une recette ou d'une dépense. La liste est filtrée par compte et par type (recette/dépense). La catégorie reste facultative pour préserver la compatibilité avec les anciennes écritures.
