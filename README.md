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

## Version 2 — offrandes par mois et performances

- **Offrandes par mois et par libellé** (`offrandes.php`, menu « Offrandes par mois ») : somme de chaque libellé pour un mois ou toute l'année, recherche d'un libellé, calcul mensuel (solde du mois et solde cumulé), tableau libellé × 12 mois et export CSV. Les libellés identiques (majuscules/espaces) sont additionnés ; USD et FC restent séparés.
- **Correction** : le « solde cumulé » du modèle Offrandes ordinaires comptait deux fois les mois précédents ; le solde cumulé des Offrandes spéciales partait du solde de début d'année au lieu du début du mois.
- **Rapidité** : le tableau de bord passe d'environ 250 requêtes SQL à 7, les rapports d'environ 630 à 11 ; la liste des opérations ne fait plus une requête par ligne. Requêtes préparées émulées (1 aller-retour réseau par requête vers TiDB ; `DB_EMULATE=0` pour désactiver), OPcache, compression gzip, cache navigateur des CSS/JS/images, `health.php` pour le contrôle de santé Render.
- Base existante : exécuter une seule fois `tidb_upgrade_v2.sql` (nouvel index). Voir `GUIDE_DEPLOIEMENT.md`.

## Version 3 — suppression groupée des offrandes

- Page « Offrandes par mois » → panneau **« Supprimer des offrandes (par libellé, mois ou type) »** (administrateur et trésorier).
- Critères : compte, année, mois (ou toute l'année), type (entrées / sorties), devise, libellé (identique ou contenant).
- Déroulement : **Aperçu** (nombre et totaux par compte/devise/type, liste des opérations) → saisir `SUPPRIMER` → suppression définitive.
- Sécurités : mois clôturés jamais supprimés ; toute l'année sans type ni libellé refusée (utiliser « Réinitialiser les données ») ; suppression en une seule transaction (tout ou rien) ; chaque ligne supprimée est conservée dans le journal d'audit (`DELETE_BULK`).
- Fonctionne en local (XAMPP) et en ligne (Render + TiDB) : même code, chaque installation agit sur **sa propre base**. Pour nettoyer les deux, répétez la suppression des deux côtés.
- Aucune modification de la base de données n'est nécessaire. Nouveau fichier : `bulk_delete_lib.php`.

## Version 4 — recherche, rapports périodiques, catégories, impression avec zoom

**Conservation des données** : aucune opération existante n'est modifiée ni supprimée. La seule modification de la base est l'ajout (automatique) de la colonne `transactions.category_name`.

- **Recherche** (menu « Recettes / dépenses ») : par jour précis, par mois, par année, période libre ou global ; texte sur le libellé et la catégorie ; filtres compte, type, devise ; totaux (entrées, sorties, solde) de tous les résultats ; pagination ; export CSV avec les mêmes filtres. « Offrandes par mois » accepte aussi un jour précis et liste les opérations correspondant au libellé cherché.
- **Fiche d'opération** (`operation.php`) : détail complet de chaque opération (date, compte, catégorie, libellé, montant, solde du compte après l'opération, auteur, historique) et impression individuelle. Lien « Fiche » et « Imprimer » sur chaque ligne.
- **Suppression d'une opération mal saisie** : bouton « Supprimer » sur la ligne (recherche, rapport détaillé, fiche). La ligne est supprimée, une copie complète reste dans le journal d'audit ; les mois clôturés restent protégés ; réservé administrateur/trésorier.
- **Rapports entrées / sorties** (`rapport_periodique.php`, menu « Entrées / sorties ») : détail de chaque opération avec solde courant, ou totaux par jour, semaine (ISO), mois, année, pour un jour, un mois, une année, une période ou le rapport global. Pour chaque période : entrées, sorties, solde restant (entrées − sorties) et solde cumulé (report initial + solde). USD et FC séparés.
- **Rapports par catégorie** (`categories_rapport.php`) : « séparé » = chaque catégorie telle qu'enregistrée (Dîme, DÎME et dîme restent distinctes), avec chaque opération sur sa ligne ; « regroupé » = catégories identiques additionnées sans tenir compte de la casse (les variantes regroupées sont listées).
- **Catégorie dans son propre champ** : colonne `transactions.category_name`. À la saisie : texte libre > catégorie prédéfinie > libellé.
- **Aperçu avant impression avec zoom** : bouton « Aperçu et impression » (rapports, recherche, fiche, offrandes, inventaires) → fenêtre d'aperçu avec zoom 30–250 % (boutons, curseur, Ctrl + molette), « Ajuster à la largeur », orientation, format de papier (A4/A5/Letter), puis « Imprimer / PDF ». Le zoom choisi est appliqué à l'impression.
- **Mise à jour et réparation** (`repair.php`, administrateur) : ajoute la colonne si absente, remplit la catégorie de chaque opération déjà enregistrée, recrée les soldes initiaux annuels manquants, signale les doublons probables et les clôtures incohérentes (sans rien supprimer). Se lance **automatiquement** à la première connexion d'un administrateur après le déploiement ; relançable à la main. Script SQL équivalent : `tidb_upgrade_v4.sql` (facultatif).

## Version 5 — catégories, comptes, mois clôturés

- **Erreur 1062 `uq_category` corrigée** : la base compare sans tenir compte des majuscules ni des accents (« CARBURANT » = « Carburant »). L'ajout d'une catégorie ou d'un compte déjà existant affiche maintenant un message au lieu de planter.
- **Catégorie automatique** : en enregistrant une opération, un libellé (ou une catégorie saisie) qui n'est pas encore configuré est ajouté aux catégories du compte. Le rapport « Somme des dépenses » utilise la catégorie enregistrée : plus de ligne « Autres ».
- **Comptes & catégories** : suppression d'une catégorie (les opérations gardent son nom), désactivation / réactivation d'un compte, suppression d'un compte (avec ses opérations seulement si l'on tape SUPPRIMER ; copie dans le journal d'audit), bouton « Importer les libellés déjà saisis » pour créer les catégories des anciennes opérations.
- **Mois clôturé** : Rapports & inventaires → « Rouvrir ou réinitialiser un mois clôturé » (administrateur) : *rouvrir* déverrouille le mois, *réinitialiser* rouvre puis refait la clôture avec les chiffres actuels.
- Aucune modification de la base de données n'est nécessaire.
