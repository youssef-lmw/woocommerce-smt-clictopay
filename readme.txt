=== WooCommerce SMT ClicToPay ===
Contributors: youssef-lmw
Tags: woocommerce, payment gateway, clictopay, monetique tunisie, tunisie
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 3.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accepter les paiements en ligne par carte bancaire via SPS ClicToPay (Monétique Tunisie) dans WooCommerce.

== Description ==

Ce module permet à une boutique WooCommerce d'accepter les paiements par carte bancaire tunisienne via la plateforme ClicToPay de Monétique Tunisie.

L'intégration suit le manuel d'intégration ClicToPay : la commande est enregistrée par `register.do`, le client est redirigé vers la page de paiement ClicToPay, et le résultat est systématiquement confirmé côté serveur par `getOrderStatusExtended.do`. Une commande n'est marquée payée que lorsque `orderStatus` vaut 2 : la redirection du navigateur n'est jamais considérée comme une preuve de paiement.

Devises prises en charge : TND (788), EUR (978), USD (840).

= Fonctionnalités =

* Paiement par carte bancaire avec 3-D Secure géré par ClicToPay.
* Vérification obligatoire du statut via getOrderStatusExtended.do.
* URLs de retour et d'échec servies par l'endpoint /wc-api/cfw_ctp_return, protégées par la clé de commande.
* Numéro de commande suffixé par tentative : un paiement réessayé ne déclenche plus l'erreur de doublon.
* Journalisation complète des échanges API, mot de passe masqué.
* Écran d'exécution des tests d'intégration depuis le site marchand.
* Compatible HPOS.

== Installation ==

1. Téléversez le dossier `woocommerce-smt-clictopay` dans `/wp-content/plugins/`.
2. Activez le module depuis le menu « Extensions » de WordPress.
3. Allez dans WooCommerce > Réglages > onglet Paiements.
4. Ouvrez « Credit Card using ClicToPay » et saisissez vos identifiants API.

Aucune page n'est à créer. Le site doit être accessible en HTTPS depuis Internet pour que ClicToPay puisse y rediriger le client.

== Frequently Asked Questions ==

= Le module gère-t-il les paiements récurrents ? =

Non.

= Un certificat SSL est-il obligatoire ? =

Oui.

= Comment valider l'intégration auprès de ClicToPay ? =

Ouvrez WooCommerce > ClicToPay Tests. Cet écran exécute depuis votre site les cas qu'un tunnel de commande normal ne produit pas (paramètre manquant, numéro de commande dupliqué, orderId inconnu) et affiche la réponse JSON brute à reporter dans la grille de validation qui vous a été transmise. Chaque appel est également écrit dans WooCommerce > État > Journaux, source « clictopay ».

= Mes réglages sont-ils conservés depuis la version 2 ? =

Oui. L'identifiant de passerelle et les clés de réglages sont inchangés.

== Changelog ==

= 3.0.1 =
* Demande systématiquement le gabarit DESKTOP de la page de paiement. Le gabarit MOBILE décrit dans la documentation renvoie une erreur 404 : les clients sur téléphone ne pouvaient pas payer. Le gabarit DESKTOP est responsive. Signalé par @machour (#6).

= 3.0.0 =
* Appel de getOrderStatusExtended.do à la place de getOrderStatus.do.
* Une commande n'est validée que si orderStatus vaut 2. L'ancienne vérification lisait une clé « ErrorMessage » inexistante et pouvait valider une commande non payée.
* Envoi de failUrl, description, language et pageView à register.do.
* Stockage de l'orderId ClicToPay sur la commande et dans ses notes.
* Numéro de commande suffixé par tentative pour éviter l'erreur de doublon lors d'un réessai.
* Montant arrondi à l'unité mineure ; prise en charge de TND, EUR et USD.
* Remplacement de la redirection JavaScript et des deux pages auto-créées par l'endpoint /wc-api/cfw_ctp_return, validé par la clé de commande.
* Suppression du déstockage en double : payment_complete() s'en charge déjà.
* Abandon de get_page_by_title(), supprimée dans WordPress 6.7.
* Journalisation de toutes les requêtes et réponses via WC_Logger.
* Nouvel écran WooCommerce > ClicToPay Tests pour la validation de l'intégration.
* Déclaration de compatibilité HPOS.
* Requêtes envoyées en POST : les identifiants ne circulent plus dans l'URL.

= 2.0.1 =
* Correctifs d'intégration et montée de version des compatibilités.

= 2.0.0 =
* ClicToPay V2.

== Upgrade Notice ==

= 3.0.1 =
Corrige une erreur 404 sur la page de paiement pour les clients naviguant depuis un téléphone. Mise à jour immédiate recommandée si la passerelle est en production.

= 3.0.0 =
Corrige une faille critique : une commande pouvait être marquée payée sans paiement confirmé. Mise à jour fortement recommandée. Les pages « ClicToPay Check Payment » et « Failed Payment » créées par la version 2 ne servent plus et peuvent être supprimées.
