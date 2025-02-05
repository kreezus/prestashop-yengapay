<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 */

class YengaPayValidationModuleFrontController extends ModuleFrontController
{
    /**
     * @throws PrestaShopException
     */
    public function postProcess()
    {
        if (!($this->module instanceof YengaPay)) {
            Tools::redirect('index.php?controller=order&step=1');
            return;
        }

        $cart = $this->context->cart;

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0) {
            Tools::redirect('index.php?controller=order&step=1');
            return;
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
            return;
        }

        $currency = $this->context->currency;
        $total_in_currency = (float)$cart->getOrderTotal(true, Cart::BOTH);

        // Préparer les articles pour YengaPay
        $articles = [];
        foreach ($cart->getProducts() as $item) {
            $product = new Product($item['id_product']);
            $image = Image::getCover($item['id_product']);
            // Récupérer l'id de la langue courante
            $id_lang = $this->context->language->id;
            // Si link_rewrite est un tableau, prendre la valeur pour la langue courante
            $link_rewrite = is_array($product->link_rewrite) ? 
            $product->link_rewrite[$id_lang] : 
            $product->link_rewrite;

            $image_url = $this->context->link->getImageLink($link_rewrite, $image['id_image'], 'home_default');
            
            $description = '';
            if (is_array($product->description_short)) {
                $id_lang = $this->context->language->id;
                $description = isset($product->description_short[$id_lang]) ? 
                $product->description_short[$id_lang] : '';
            } else {
                $description = $product->description_short;
            }
    
            $articles[] = [
                'title' => $item['name'],
                'description' => strip_tags($description),
                'pictures' => [$image_url],
                'price' => $item['total']
            ];
        }

        // Préparation des données pour l'API YengaPay
        $payment_data = [
            'paymentAmount' => $total_in_currency,
            'reference' => strval($cart->id),
            'articles' => $articles
        ];

        try {
            // Validation de la commande
            $this->module->validateOrder(
                (int)$cart->id,
                Configuration::get('PS_OS_WS_PAYMENT'),  // Statut "En attente du paiement via un module externe"
                $total_in_currency,
                $this->module->displayName,
                null,
                array(),
                (int)$currency->id,
                false,
                $customer->secure_key
            );
        
            // Récupérer la commande créée
            $order = new Order($this->module->currentOrder);
            if (!Validate::isLoadedObject($order)) {
                throw new Exception('La commande n\'a pas pu être créée.');
            }
        
            // Récupérer la référence de la commande générée par PrestaShop
            $reference = $order->reference;
        
            // Préparation des données pour l'API YengaPay avec la référence de la commande
            $payment_data = [
                'paymentAmount' => $total_in_currency,
                'reference' => $reference, // Utilisation de la référence de la commande
                'articles' => $articles
            ];
        
            // Construction de l'URL de l'API
            $api_url = sprintf(
                'https://api.yengapay.com/api/v1/groups/%s/payment-intent/%s',
                Configuration::get('YENGAPAY_GROUP_ID'),
                Configuration::get('YENGAPAY_PROJECT_ID')
            );
        
            // Préparation de la requête
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'x-api-key: ' . Configuration::get('YENGAPAY_API_KEY'),
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
        
            // Exécution de la requête
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        
            if ($http_code !== 200 && $http_code !== 201) {
                throw new Exception('Erreur API YengaPay (' . $http_code . '): ' . $response);
            }
        
            $response_data = json_decode($response, true);
            if (!isset($response_data['checkoutPageUrlWithPaymentToken'])) {
                throw new Exception('URL de paiement non trouvée dans la réponse');
            }
        
            // Redirection vers la page de paiement YengaPay
            Tools::redirect($response_data['checkoutPageUrlWithPaymentToken']);
        
        } catch (Exception $e) {
            // Log de l'erreur
            PrestaShopLogger::addLog(
                'YengaPay Error: ' . $e->getMessage(),
                3,
                null,
                'Cart',
                $cart->id,
                true
            );
            error_log('YengaPay Error: ' . $e->getMessage());
            // Redirection vers la page d'erreur
            $this->errors[] = $this->module->l('An error occurred while processing your payment. Please try again or contact the merchant.');
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }
    }
}