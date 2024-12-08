<?php

namespace App\Extensions\Gateways\TripletexSubscription;

use App\Classes\Extensions\Gateway;

class TripletexSubscription extends Gateway
{
    /**
     * Get the extension metadata
     *
     * @return array
     */
    public function getMetadata()
    {
        return [
            'display_name' => 'Tripletex Subscription',
            'version' => '1.0.0',
            'author' => 'Your Company/Developer Name',
            'website' => 'https://yourwebsite.com',
        ];
    }

    /**
     * Get all the configuration for the extension
     *
     * @return array
     */
    public function getConfig()
    {
        return [
            [
                'name' => 'tripletex_api_key',
                'friendlyName' => 'Tripletex API Key',
                'type' => 'text',
                'description' => 'The API key for accessing the Tripletex API.',
                'required' => true,
            ],
            [
                'name' => 'tripletex_webhook_secret',
                'friendlyName' => 'Tripletex Webhook Secret',
                'type' => 'text',
                'description' => 'The secret used for verifying webhooks from Tripletex.',
                'required' => true,
            ],
            [
                'name' => 'tripletex_test_mode',
                'friendlyName' => 'Tripletex Test Mode',
                'type' => 'boolean',
                'description' => 'Enable test mode to simulate payments without processing real transactions.',
                'required' => false,
            ],
            [
                'name' => 'tripletex_account_id',
                'friendlyName' => 'Tripletex Account ID',
                'type' => 'text',
                'description' => 'The Tripletex account ID associated with your invoices.',
                'required' => true,
            ],
        ];
    }

    /**
     * Get the URL to redirect to for subscription payment
     *
     * @param float $total Total amount for the payment
     * @param array $products Products being purchased
     * @param int $invoiceId The invoice ID to be paid
     * @return string
     */
    public function pay($total, $products, $invoiceId)
    {
        // Set up subscription details
        $apiKey = $this->getConfig()['tripletex_api_key'];
        $accountId = $this->getConfig()['tripletex_account_id'];
        $testMode = $this->getConfig()['tripletex_test_mode'] ? true : false;

        // Create subscription request payload
        $subscriptionData = [
            'invoiceId' => $invoiceId,
            'amount' => $total,
            'accountId' => $accountId,
            'testMode' => $testMode,  // Enable test mode if configured
            'recurring' => true,      // Subscription flag
            'interval' => 'monthly',  // Subscription interval (can be 'weekly', 'monthly', etc.)
            'currency' => 'NOK',      // Currency (adjust as needed)
        ];

        // Send the subscription data to Tripletex over HTTPS
        $response = $this->sendToTripletex($subscriptionData, $apiKey);

        // Handle the response and return the appropriate redirect URL
        if ($response['status'] == 'success') {
            return 'https://subscription-redirect-url.com'; // Replace with actual Tripletex subscription URL
        } else {
            // Handle error response
            return 'https://subscription-error-url.com'; // Replace with error URL
        }
    }

    /**
     * Send the subscription data to Tripletex API over HTTPS
     *
     * @param array $subscriptionData Subscription data to be sent
     * @param string $apiKey Tripletex API key
     * @return array
     */
    private function sendToTripletex($subscriptionData, $apiKey)
    {
        $url = 'https://api.tripletex.no/v2/subscriptions'; // HTTPS Tripletex Subscription endpoint

        // Prepare headers for API request
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];

        // Send the request using cURL over HTTPS
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($subscriptionData));

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /**
     * Handle the incoming webhook from Tripletex over HTTPS to confirm subscription status
     *
     * @return void
     */
    public function handleWebhook()
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        // Verify the webhook signature
        if (!$this->verifyWebhook($data)) {
            http_response_code(400); // Invalid signature
            return;
        }

        // Handle subscription-related events
        if (isset($data['event'])) {
            switch ($data['event']) {
                case 'subscription_created':
                    $this->handleSubscriptionCreated($data);
                    break;
                case 'subscription_updated':
                    $this->handleSubscriptionUpdated($data);
                    break;
                case 'subscription_canceled':
                    $this->handleSubscriptionCanceled($data);
                    break;
                default:
                    error_log("Unhandled event: " . $data['event']);
                    break;
            }
        }

        http_response_code(200); // OK
    }

    /**
     * Verify the webhook signature from Tripletex (ensure it's over HTTPS)
     *
     * @param array $data Webhook data
     * @return bool
     */
    private function verifyWebhook($data)
    {
        $webhookSecret = $this->getConfig()['tripletex_webhook_secret'];
        $signature = $_SERVER['HTTP_X_TRIPLETEX_SIGNATURE'] ?? '';

        return hash_equals($signature, hash_hmac('sha256', json_encode($data), $webhookSecret));
    }

    /**
     * Handle the 'subscription_created' event from Tripletex
     *
     * @param array $data Webhook data
     * @return void
     */
    private function handleSubscriptionCreated($data)
    {
        $subscriptionId = $data['subscription']['id'];
        $status = $data['subscription']['status'];

        if ($status == 'active') {
            $this->updateSubscriptionStatus($subscriptionId, 'active');
        }
    }

    /**
     * Handle the 'subscription_updated' event from Tripletex
     *
     * @param array $data Webhook data
     * @return void
     */
    private function handleSubscriptionUpdated($data)
    {
        $subscriptionId = $data['subscription']['id'];
        $status = $data['subscription']['status'];

        if ($status == 'active') {
            $this->updateSubscriptionStatus($subscriptionId, 'active');
        } elseif ($status == 'inactive') {
            $this->updateSubscriptionStatus($subscriptionId, 'inactive');
        }
    }

    /**
     * Handle the 'subscription_canceled' event from Tripletex
     *
     * @param array $data Webhook data
     * @return void
     */
    private function handleSubscriptionCanceled($data)
    {
        $subscriptionId = $data['subscription']['id'];

        $this->updateSubscriptionStatus($subscriptionId, 'canceled');
    }

    /**
     * Update the subscription status in Paymenter (or your database)
     *
     * @param int $subscriptionId The subscription ID
     * @param string $status The new status of the subscription
     * @return void
     */
    private function updateSubscriptionStatus($subscriptionId, $status)
    {
        // Implement the logic for updating the subscription status in your system
        // Example: updateSubscriptionStatusInPaymenter($subscriptionId, $status);
    }
}
