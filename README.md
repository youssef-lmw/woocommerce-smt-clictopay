# WooCommerce SMT ClicToPay

![Version](https://img.shields.io/badge/version-3.0.1-blue)
![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-21759b)
![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588a)
![PHP](https://img.shields.io/badge/PHP-7.2%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-GPL--2.0-green)

Module **WordPress / WooCommerce** de paiement en ligne par carte bancaire pour **SPS ClicToPay — Monétique Tunisie**.

Version **3.0.0** : réécriture complète de l'intégration des API `register.do` et `getOrderStatusExtended.do`.

> ### ⚠️ Lisez ceci avant de mettre à jour depuis la v2
>
> Les versions antérieures validaient une commande à partir d'une clé de réponse qui n'existe pas dans l'API, et ne vérifiaient jamais le statut réel de la commande. **Une commande pouvait donc passer en « payée » sans qu'aucun paiement n'ait eu lieu.** Si vous utilisez une v2 en production, mettez à jour et relisez vos commandes payées. Voir [Migration depuis la v2](#migration-depuis-la-v2).

---

## Sommaire

- [Ce que fait le module](#ce-que-fait-le-module)
- [Comment fonctionne un paiement](#comment-fonctionne-un-paiement)
- [Installation](#installation)
- [Configuration](#configuration)
- [Écran de tests d'intégration](#écran-de-tests-dintégration)
- [Journaux](#journaux)
- [Dépannage](#dépannage)
- [Passage en production](#passage-en-production)
- [Migration depuis la v2](#migration-depuis-la-v2)
- [Crédits](#crédits)

---

## Ce que fait le module

- Paiement par carte bancaire tunisienne (Visa / MasterCard), authentification 3-D Secure gérée par ClicToPay.
- **Confirmation systématique côté serveur** : une commande n'est validée que si l'API confirme l'encaissement.
- Retour et échec servis par l'endpoint WooCommerce `/wc-api/cfw_ctp_return`, protégé par la clé de commande. Aucune page à créer, aucun shortcode à placer.
- Numéro de commande suffixé par tentative : réessayer un paiement échoué ne déclenche plus d'erreur de doublon.
- Montant converti dans l'unité mineure de la devise. Devises : **TND**, **EUR**, **USD**.
- Journalisation complète des échanges API, mot de passe masqué.
- Écran intégré pour exécuter les tests d'intégration depuis le site marchand.
- Compatible **HPOS** (High-Performance Order Storage).

Testé avec WordPress 6.7 et WooCommerce 9.x. PHP 7.2 minimum.

---

## Comment fonctionne un paiement

```
  Client                  Boutique                    ClicToPay
    │                        │                            │
    │  valide la commande    │                            │
    ├───────────────────────>│                            │
    │                        │  POST register.do          │
    │                        │  (montant, devise,         │
    │                        │   returnUrl, failUrl)      │
    │                        ├───────────────────────────>│
    │                        │                            │
    │                        │  orderId + formUrl         │
    │                        │<───────────────────────────┤
    │                        │  (orderId enregistré       │
    │                        │   sur la commande)         │
    │  redirection formUrl   │                            │
    │<───────────────────────┤                            │
    │                                                     │
    │  saisie carte + 3-D Secure                          │
    ├────────────────────────────────────────────────────>│
    │                                                     │
    │  redirection returnUrl OU failUrl                   │
    │<────────────────────────────────────────────────────┤
    │                        │                            │
    ├───────────────────────>│                            │
    │                        │  POST                      │
    │                        │  getOrderStatusExtended.do │
    │                        ├───────────────────────────>│
    │                        │                            │
    │                        │  statut réel de la commande│
    │                        │<───────────────────────────┤
    │                        │                            │
    │                        │  paiement confirmé ?       │
    │                        │   oui → commande payée     │
    │                        │   non → commande échouée   │
    │  page finale           │                            │
    │<───────────────────────┤                            │
```

**Le point essentiel :** `returnUrl` et `failUrl` pointent vers **le même handler**, et la décision ne dépend jamais de l'URL sur laquelle le navigateur est revenu. Seule la réponse de `getOrderStatusExtended.do` décide.

Pourquoi ? Une URL de retour est une simple adresse dans un navigateur. N'importe qui peut l'ouvrir à la main, sans avoir payé. Un module qui marque la commande « payée » parce que le client est arrivé sur `returnUrl` peut être vidé de son stock gratuitement. C'est aussi le premier point vérifié lors de la validation technique d'une intégration.

---

## Installation

1. Téléchargez [la dernière version](https://github.com/youssef-lmw/woocommerce-smt-clictopay/releases/latest) au format zip.
2. Décompressez l'archive dans `wp-content/plugins/` sous le nom `woocommerce-smt-clictopay`.
3. Activez le module depuis la page `Extensions` de WordPress.

Le site doit être **accessible en HTTPS depuis Internet**. ClicToPay y redirige le client après le paiement : une installation locale (`localhost`, WAMP, Local by Flywheel) ne peut pas fonctionner.

## Configuration

`WooCommerce > Réglages > Paiements > Credit Card using ClicToPay`

| Champ | Rôle |
| --- | --- |
| **Enable/Disable** | Affiche le moyen de paiement à la caisse |
| **Title** | Libellé vu par le client (ex. *Carte de crédit*) |
| **Description** | Texte sous le libellé, à la caisse |
| **Test mode** | Coché : environnement de test. Décoché : environnement de production |
| **Api-User Login** | Nom d'utilisateur **API** fourni par ClicToPay |
| **Api-User Password** | Mot de passe **API** fourni par ClicToPay |
| **Payment page language** | Langue de la page de paiement : `fr`, `en` ou `ar` |
| **Debug log** | Journalise chaque appel API — à laisser actif pendant la phase de test |

> Les identifiants **API** et ceux du **portail de monitoring** sont distincts, même lorsque le mot de passe est identique. C'est le compte **API** qui doit être saisi ici. Reportez-vous aux paramètres d'accès qui vous ont été communiqués.

---

## Écran de tests d'intégration

`WooCommerce > ClicToPay Tests`

Avant d'ouvrir un compte de production, ClicToPay demande de valider l'intégration et exige que les résultats proviennent **du site marchand lui-même** : ceux produits avec un client HTTP externe (Postman et assimilés) ne sont pas recevables — ils prouveraient seulement que l'API fonctionne, pas que *votre intégration* l'appelle correctement.

Cet écran exécute donc les appels depuis votre serveur, avec vos identifiants, et affiche la **réponse JSON brute** à reporter dans la grille de validation qui vous a été transmise. Chaque appel est en plus écrit dans les journaux WooCommerce.

### Ce que proposent les boutons

Un tunnel de commande normal ne produit jamais certaines requêtes : il faut les provoquer. Chaque bouton en couvre une.

| Bouton | Ce qu'il envoie | Ce qu'il démontre |
| --- | --- | --- |
| **Enregistrement valide** | `register.do` avec tous les paramètres obligatoires | L'intégration sait ouvrir une transaction |
| **Paramètre manquant** | `register.do` sans le montant | L'API rejette la requête et aucune transaction n'est ouverte |
| **Numéro dupliqué** | `register.do` deux fois avec le même numéro de commande | La seconde requête est rejetée, pas de double enregistrement silencieux |
| **Identifiant inconnu** | `getOrderStatusExtended.do` sur un identifiant inexistant | L'erreur est traitée proprement, sans erreur PHP |

### Recherche de statut

Le champ **Order status lookup** rejoue `getOrderStatusExtended.do` sur n'importe quel identifiant de commande ClicToPay. C'est par là que l'on récupère le JSON des cas qui exigent un vrai passage en caisse : paiement accepté, paiement refusé, et accès à l'URL de retour sans paiement.

L'identifiant ClicToPay d'une commande est inscrit à deux endroits :

- dans les **notes de la commande** : *« ClicToPay order registered. orderId: … »* ;
- dans la métadonnée `_cfw_ctp_order_id`.

### Bon à savoir

- Le numéro de commande utilisé par le test de doublon est consommé au premier passage : relancer le bouton renverra une erreur de doublon **aux deux** requêtes. Conservez le résultat du premier passage.
- Une URL de page de paiement expire après une vingtaine de minutes. Passé ce délai, relancez l'enregistrement.
- Pour que le montant corresponde à celui attendu par la grille de validation, prévoyez un produit de test au prix exact demandé, sans frais de port ni taxe.
- Les cartes de test sont fournies par ClicToPay avec vos accès sandbox. Elles ne fonctionnent que sur l'environnement de test et n'ont pas leur place dans un dépôt public.

---

## Journaux

`WooCommerce > État > Journaux`, source **`clictopay`**.

Chaque appel écrit trois lignes : l'URL et les paramètres envoyés, le code HTTP, puis la réponse brute. Le mot de passe est remplacé par `***`.

```
[register] POST https://…/payment/rest/register.do {"userName":"…","password":"***","orderNumber":"…",…}
[register] HTTP 200 {"errorCode":0,"orderId":"…","formUrl":"https://…"}
[getOrderStatusExtended] HTTP 200 {"errorCode":"0","orderStatus":…,"actionCode":…,…}
```

C'est de ces journaux que doivent provenir les réponses reportées dans la grille de validation.

> Les journaux contiennent des identifiants de transaction et des données de commande. Ne les publiez pas tels quels et purgez-les une fois la validation obtenue.

---

## Dépannage

| Symptôme | Cause probable | Correctif |
| --- | --- | --- |
| *Set the ClicToPay API user and password…* sur l'écran de tests | Identifiants non enregistrés | Les saisir dans les réglages de la passerelle **et enregistrer** |
| Accès refusé par l'API | Identifiants d'un autre environnement, ou compte du portail utilisé à la place du compte API | Vérifier le couple identifiants / `Test mode` |
| Erreur de numéro de commande dupliqué | Le numéro a déjà servi | Comportement normal ; le module suffixe automatiquement les nouvelles tentatives |
| Erreur de devise inconnue | Devise de la boutique non gérée | Utiliser TND, EUR ou USD |
| Le client ne revient jamais sur la boutique | Site inaccessible depuis Internet, ou HTTPS absent | Déployer sur un domaine public en HTTPS |
| Paiement encaissé mais commande « en attente » | Le retour n'a pas atteint `/wc-api/cfw_ctp_return` | Vérifier les permaliens et les règles de cache / pare-feu sur `wc-api` |
| Le moyen de paiement n'apparaît pas à la caisse | Passerelle désactivée, ou devise non gérée | Cocher *Enable/Disable*, vérifier la devise |

La signification des codes renvoyés par l'API figure dans la documentation d'intégration fournie par ClicToPay avec vos accès.

---

## Passage en production

1. Terminer la validation de l'intégration et la transmettre à votre interlocuteur ClicToPay.
2. À réception des identifiants de production, les saisir dans les réglages.
3. **Décocher `Test mode`** : le module bascule sur l'environnement de production.
4. Effectuer une transaction réelle de faible montant, puis la rembourser depuis le portail marchand.
5. Laisser `Debug log` actif quelques jours, le temps de vérifier les premiers paiements.

> Tant que la validation n'est pas obtenue, laissez la passerelle **désactivée** sur une boutique ouverte au public : en `Test mode`, un vrai client serait envoyé vers l'environnement de test.

---

## Migration depuis la v2

La v3 conserve l'identifiant de passerelle `cc_ctp` et les mêmes clés de réglages : **les identifiants API déjà enregistrés sont conservés**.

Après mise à jour :

- Les pages `ClicToPay Check Payment` et `Failed Payment` créées par la v2 ne servent plus et peuvent être supprimées.
- Le shortcode `[clictopay_check_payment]` n'existe plus.
- **Relisez les commandes passées en « payée » avec la v2.** La vérification de statut était défaillante : certaines ont pu être validées sans encaissement. Recoupez-les avec le portail de monitoring.

Ce qui a changé sous le capot :

| v2 | v3 |
| --- | --- |
| `getOrderStatus.do` | `getOrderStatusExtended.do` |
| Validation sur une clé de réponse inexistante | Validation sur le statut réel de la commande |
| Pas de `failUrl` envoyée | `failUrl`, `description`, `language`, `pageView` envoyées |
| Identifiants passés en GET dans l'URL | Requêtes en POST |
| Redirection par JavaScript depuis deux pages créées à l'activation | Endpoint `/wc-api/cfw_ctp_return`, redirection serveur |
| Identifiant de transaction non conservé | Stocké sur la commande |
| Numéro de commande = ID WooCommerce | Suffixé par tentative |
| Déstockage appelé deux fois | Géré par `payment_complete()` |
| Aucun journal | Journalisation complète |

---

## Support

Les identifiants, les accès au portail de monitoring et la validation technique de votre intégration sont gérés par **ClicToPay — Monétique Tunisie**. Adressez-vous au contact indiqué dans votre dossier d'intégration, ou via [clictopay.com](https://www.clictopay.com).

Pour un bug du module : [ouvrir une issue](https://github.com/youssef-lmw/woocommerce-smt-clictopay/issues).

> N'incluez jamais d'identifiants, de journaux bruts, de numéros de carte ni de données de commande dans une issue publique.

## Auteur

**Youssef Gharbi** — [LinkedIn](https://www.linkedin.com/in/gharbi-youssef/)

## Crédits

Merci aux contributeurs de ce dépôt, en particulier [@machour](https://github.com/machour) pour la correction de la conversion dinars/millimes et la mise à niveau de l'intégration.

Travaux antérieurs ayant servi de base aux premières versions :

1. https://github.com/agencep/ClicToPay-Mon-tique-Tunisie-1.7
2. https://github.com/sunnyluthra/smt-woocommerce-payment-gateway
3. https://github.com/BesrourMS/ClicToPay-Woocommerce

## Licence

GPL-2.0-or-later — voir [LICENSE](LICENSE).
