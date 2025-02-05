<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 */

class YengaPayWebhookModuleFrontController extends ModuleFrontController
{
    /**
     * @throws PrestaShopException
     */
    public function postProcess()
    {
        try {
            // Désactive l'affichage du header et footer
            $this->ajax = true;

            // Get only the required header
            $webhook_hash = $this->get_webhook_hash_header();

            // Récupération du payload
            $payload = Tools::file_get_contents('php://input');
            
            if (empty($payload)) {
                throw new Exception('Empty payload received');
            }

            // Verify hash presence
            if (empty($webhook_hash)) {
                throw new Exception('Missing X-Webhook-Hash header');
            }     

            // Vérification de la signature
            $webhook_secret = Configuration::get('YENGAPAY_WEBHOOK_SECRET');

            // Verify webhook secret configuration
            if (empty($this->webhook_secret)) {
                throw new Exception('Veuillez configurer le webhook secret dans les paramètres YengaPay');
            }

            // Verify hash validity
            $calculated_hash = hash_hmac('sha256', $payload, $this->webhook_secret);
            if (!hash_equals($calculated_hash, $webhook_hash)) {
                throw new Exception('Invalid webhook signature');
            }

            // Décodage du payload
            $data = json_decode($payload, true);

            if (!$data) {
                throw new Exception('Payload JSON invalide: ' . json_last_error_msg());
            }

            // Vérification des champs requis
            if (!isset($data['reference']) || !isset($data['paymentStatus'])) {
                throw new Exception('Données de webhook incomplètes');
            }

            // Récupération de la commande
            $cart_id = $data['reference'];
            $order = Order::getByCartId((int)$cart_id);
            
            if (!Validate::isLoadedObject($order)) {
                throw new Exception('Commande non trouvée: ' . $cart_id);
            }

            // Mise à jour du statut de la commande
            switch ($data['paymentStatus']) {
                case 'DONE':
                    $new_status = Configuration::get('PS_OS_PAYMENT');
                    $comment = 'Paiement YengaPay confirmé.';
                    if (isset($data['id'])) {
                        $comment .= ' ID Transaction: ' . $data['id'];
                        // Sauvegarde de l'ID de transaction
                        $order->transaction_id = $data['id'];
                        $order->save();
                    }
                    break;

                case 'FAILED':
                    $new_status = Configuration::get('PS_OS_ERROR');
                    $comment = 'Paiement YengaPay échoué.';
                    break;

                case 'PENDING':
                    $new_status = Configuration::get('PS_OS_WS_PAYMENT');
                    $comment = 'Paiement YengaPay en attente de confirmation.';
                    break;

                case 'CANCELLED':
                    $new_status = Configuration::get('PS_OS_CANCELED');
                    $comment = 'Paiement YengaPay annulé par l\'utilisateur.';
                    break;

                default:
                    throw new Exception('Statut de paiement inconnu: ' . $data['paymentStatus']);
            }

            // Mise à jour du statut
            $order_history = new OrderHistory();
            $order_history->id_order = (int)$order->id;
            $order_history->changeIdOrderState((int)$new_status, $order, true);
            $order_history->addWithemail(true, array(
                'order_name' => $cart_id,
            ));

            // Ajout d'un commentaire
            $msg = new Message();
            $msg->message = $comment;
            $msg->id_order = (int)$order->id;
            $msg->private = true;
            $msg->add();

            // Réponse success
            header('HTTP/1.1 200 OK');
            die(json_encode(['status' => 'success']));

        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'YengaPay Webhook Error: ' . $e->getMessage(),
                3,
                null,
                'YengaPay',
                null,
                true
            );

            header('HTTP/1.1 400 Bad Request');
            die(json_encode(['error' => $e->getMessage()]));
        }
    }

     /**
     * Helper method to safely get the webhook hash header
     * 
     * @return string|null
     */
    private function get_webhook_hash_header() {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            // Search for the header in a case-insensitive manner
            foreach ($headers as $header_name => $value) {
                if (strtolower($header_name) === 'x-webhook-hash') {
                    return $value;
                }
            }
        }
        
        // Fallback for servers that don't support getallheaders()
        $webhook_hash = isset($_SERVER['HTTP_X_WEBHOOK_HASH']) ? $_SERVER['HTTP_X_WEBHOOK_HASH'] : null;
        return $webhook_hash;
    }
}