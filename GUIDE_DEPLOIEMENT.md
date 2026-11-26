# Guide : aligner GitHub, TiDB Cloud et Render sur le logiciel local

Ordre conseillé : 0 sauvegardes → 1 base locale → 2 GitHub → 3 TiDB Cloud → 4 Render → 5 vérifications.

## 0. Sauvegardes (obligatoire)
- Logiciel en ligne : connectez-vous en administrateur → « Sauvegarde / restauration » → « Télécharger la sauvegarde ». Gardez le fichier .sql.
- Logiciel local : même chose (ou phpMyAdmin → Exporter).

## 1. Base locale (XAMPP / phpMyAdmin)
Sélectionnez la base `eglise_germe_de_david` → onglet SQL, vérifiez : `SHOW INDEX FROM transactions;`
Si `idx_account_date` est absent, exécutez : `ALTER TABLE transactions ADD INDEX idx_account_date (account_id, tx_date);`
Remplacez ensuite les fichiers du dossier local par ceux de cette version.

## 2. GitHub (dépôt patrick123-cloud/germe-de-david)
Dans le dossier du projet (Git Bash ou terminal) :
```
git status
```
Vérifiez que `.gitignore` est présent (il empêche l'envoi de uploads/, .env et des sauvegardes .sql). Puis :
```
git add -A
git status          # contrôler : aucun .env, aucune sauvegarde, aucune photo dans uploads/
git commit -m "Offrandes par mois/libellé, performances, correction solde cumulé"
git push origin main
```
Fichiers nouveaux ou modifiés : config.php, dashboard.php, reports.php, transactions.php, offrandes.php (nouveau), health.php (nouveau), partials/header.php, partials/footer.php, index.php, assets/style.css, Dockerfile, docker/ (opcache.ini, apache-perf.conf), render.yaml, .dockerignore, install.sql, install_cloud.sql, tidb_upgrade_v2.sql, README.md.

## 3. TiDB Cloud
Console TiDB Cloud → votre cluster → « SQL Editor » (ou « Connect » + client MySQL). Choisissez la base `eglise_germe_de_david`.
1. `SHOW INDEX FROM transactions;`
2. Si `idx_account_date` est absent : `ALTER TABLE transactions ADD INDEX idx_account_date (account_id, tx_date);`
3. Comparer à la base locale : exécutez `tidb_preflight.sql` des deux côtés ; les `SHOW CREATE TABLE` et les compteurs (comptes, catégories, utilisateurs, transactions) doivent correspondre.
4. Si les DONNÉES locales doivent devenir celles de l'en ligne : sur le logiciel local, téléchargez la sauvegarde (étape 0), puis sur le logiciel en ligne → « Sauvegarde / restauration » → « Restaurer ». Cela REMPLACE toutes les tables (utilisateurs compris) : la sauvegarde en ligne de l'étape 0 sert de filet de sécurité. Limite 15 Mo.
5. Ne touchez pas à DB_SSL : TLS avec vérification du certificat doit rester actif. Vérifiez l'IP allowlist (ou l'accès public) du cluster.

## 4. Render
1. Dashboard Render → service `germe-de-david` → Settings : dépôt = GitHub, branche `main`, Auto-Deploy = Yes, Health Check Path = `/health.php`.
2. Environment : DB_HOST, DB_PORT (4000), DB_NAME, DB_USER, DB_PASS, DB_SSL=1, DB_SSL_CA=/etc/ssl/certs/ca-certificates.crt.
3. Manual Deploy → « Clear build cache & deploy ». Consultez les Logs jusqu'à « Live ».
4. Région : choisissez celle la plus proche du cluster TiDB (ex. Frankfurt si TiDB est à Francfort). La région ne se change pas après création : il faut recréer le service. Une région éloignée ralentit chaque requête SQL.
5. Offre gratuite : le service s'endort après ~15 min sans visite (premier chargement 30–60 s). Pour l'éviter, créez un moniteur gratuit (UptimeRobot ou cron-job.org) qui appelle `https://VOTRE-APP.onrender.com/health.php` toutes les 10 minutes (cette page ne sollicite pas la base).
6. Le disque Render gratuit est éphémère : logo et photo envoyés depuis « Paramètres » disparaissent à chaque redéploiement (le logiciel affiche alors le logo par défaut). Renvoyez-les après un déploiement, ou utilisez un stockage d'images externe.

## 5. Vérifications
- Connexion, Tableau de bord (chargement rapide), menu « Offrandes par mois ».
- Choisir un mois, comparer les totaux par libellé avec le logiciel local (mêmes chiffres attendus).
- Rapports : « Offrandes ordinaires — modèle mensuel » : le solde cumulé de décembre = solde final de l'année.
- Saisir puis supprimer une opération de test (mois non clôturé).

---
# Publier la mise à jour « suppression groupée des offrandes » (v3)

Fichiers concernés : `offrandes.php` (modifié), `bulk_delete_lib.php` (nouveau), `assets/style.css` (modifié), `README.md`. **Aucun script SQL à exécuter.**

## A. Logiciel local (XAMPP)
1. Sauvegarde : « Sauvegarde / restauration » → télécharger le .sql.
2. Copiez `offrandes.php`, `bulk_delete_lib.php` et `assets/style.css` dans `C:\xampp\htdocs\eglise_germe_de_david\` (en remplaçant), puis rechargez la page avec Ctrl+F5.
3. Test : Offrandes par mois → « Supprimer des offrandes » → choisir un mois test → Aperçu (rien ne se supprime) → vérifier les chiffres.

## B. GitHub
```
git add offrandes.php bulk_delete_lib.php assets/style.css README.md GUIDE_DEPLOIEMENT.md
git status
git commit -m "Suppression groupée des offrandes par libellé, mois et type"
git push origin main
```

## C. En ligne (Render)
1. Auto-Deploy = Yes : le déploiement démarre seul après le push (sinon : Manual Deploy → Deploy latest commit).
2. Suivez les Logs jusqu'à « Live ». TiDB n'a rien à modifier.
3. Même test qu'en local avec un mois d'essai.

## D. Bon à savoir
- Local et en ligne ont chacun leur base : une suppression faite d'un côté n'est pas répétée de l'autre. Pour aligner, refaites la suppression des deux côtés, ou restaurez la sauvegarde locale en ligne (voir étape 3.4 plus haut).
- Une suppression n'est annulable que par la restauration d'une sauvegarde ; les lignes supprimées restent lisibles dans le journal d'audit (action `DELETE_BULK`).
- Un mois clôturé est protégé : déverrouillez-le d'abord si nécessaire.

---
# Publier la mise à jour v4 (recherche, rapports, catégories, impression avec zoom)

Fichiers nouveaux : `tx_lib.php`, `repair_lib.php`, `repair.php`, `operation.php`, `delete_operation.php`, `rapport_periodique.php`, `categories_rapport.php`, `tidb_upgrade_v4.sql`.
Fichiers modifiés : `transactions.php`, `offrandes.php`, `reports.php`, `dashboard.php`, `export.php`, `partials/header.php`, `assets/app.js`, `assets/style.css`, `install.sql`, `install_cloud.sql`, README.md.

## A. Avant tout
1. Logiciel en ligne → « Sauvegarde / restauration » → télécharger la sauvegarde SQL (filet de sécurité).
2. Aucun script SQL obligatoire : les données déjà enregistrées en ligne sont conservées.

## B. GitHub puis Render
```
git add -A
git status
git commit -m "v4 : recherche par jour/mois, rapports periodiques, categories, impression avec zoom"
git push origin main
```
Render redéploie seul (Auto-Deploy = Yes). Suivez les Logs jusqu'à « Live ».

## C. Réparer les données en ligne (automatique)
1. Connectez-vous à l'application en ligne avec un compte **administrateur** : la mise à jour s'exécute automatiquement (message vert « Mise à jour automatique des données effectuée »).
2. Vérification : menu « Mise à jour & réparation » → « Opérations sans catégorie séparée » doit afficher 0.
3. Si un message d'erreur parle de droits (ALTER TABLE), exécutez `tidb_upgrade_v4.sql` dans le SQL Editor de TiDB Cloud puis relancez la page.
4. Local (XAMPP) : même chose, indépendamment : chaque installation répare sa propre base.

## D. Tests conseillés
- Recettes / dépenses → « Un jour précis » → choisir une date avec des opérations.
- Entrées / sorties → « Par mois » → « Une année » : soldes cumulés cohérents avec le tableau de bord.
- Rapport par catégorie : onglet « séparée » puis « regroupées ».
- Aperçu et impression : zoom −/+, paysage, Imprimer / PDF.
- Saisir puis supprimer une opération de test (mois non clôturé).
