<?php

namespace App\Extensions\Gateways\TripletexFaktura;

use App\Classes\Extensions\Gateway;

class TripletexFaktura extends Gateway
{
    /**
     * Get the extension metadata
     *
     * @return array
     */
    public function getMetadata()
    {
        return [
            'display_name' => 'Tripletex Faktura',
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
     * Get the URL to redirect to for invoice payment
     *
     * @param float $total Total amount for the payment
     * @param array $products Products being purchased
     * @param int $invoiceId The invoice ID to be paid
     * @return string
     */
    public function pay($total, $products, $invoiceId)
    {
        // Set up invoice details
        $apiKey = $this->getConfig()['tripletex_api_key'];
        $accountId = $this->getConfig()['tripletex_account_id'];
        $testMode = $this->getConfig()['tripletex_test_mode'] ? true : false;

        // Create invoice request payload
        $invoiceData = [
            'invoiceId' => $invoiceId,
            'amount' => $total,
            'accountId' => $accountId,
            'testMode' => $testMode,  // Enable test mode if configured
            'currency' => 'NOK',      // Currency (adjust as needed)
        ];

        // Send the invoice data to Tripletex over HTTPS
        $response = $this->sendToTripletex($invoiceData, $apiKey);

        // Handle the response and return the appropriate redirect URL
        if ($response['status'] == 'success') {
            return 'https://invoice-redirect-url.com'; // Replace with actual Tripletex invoice payment URL
        } else {
            // Handle error response
            return 'https://invoice-error-url.com'; // Replace with error URL
        }
    }

    /**
     * Send the invoice data to Tripletex API over HTTPS
     *
     * @param array $invoiceData Invoice data to be sent
     * @param string $apiKey Tripletex API key
     * @return array
     */
    private function sendToTripletex($invoiceData, $apiKey)
    {
        $url = 'https://api.tripletex.no/v2/invoices'; // HTTPS Tripletex Faktura endpoint

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
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($invoiceData));

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /**
     * Handle the incoming webhook from Tripletex over HTTPS to confirm invoice payment status
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

        // Handle invoice-related events
        if (isset($data['event'])) {
            switch ($data['event']) {
                case 'invoice_created':
                    $this->handleInvoiceCreated($data);
                    break;
                case 'invoice_paid':
                    $this->handleInvoicePaid($data);
                    break;
                case 'invoice_canceled':
                    $this->handleInvoiceCanceled($data);
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
     * Handle the 'invoice_created' event from Tripletex
     *
     * @param array $data Webhook data
     * @return void
     */
    private function handleInvoiceCreated($data)
    {
        $invoiceId = $data['invoice']['id'];
        $status = $data['invoice']['status'];

        if ($status == 'paid') {
            $this->updateInvoiceStatus($invoiceId, 'paid');
        }
    }

    /**
     * Handle the 'invoice_paid' event from Tripletex
     *
     * @param array $data Webhook data
     * @return void
     */
    private function handleInvoicePaid($data)
    {
        $invoiceId = $data['invoice']['id'];
        $status = $data['invoice']['status'];

        if ($status == 'paid') {
            $this->updateInvoiceStatus($invoiceId, 'paid');
        }
    }

    /**
     * Handle the 'invoice_canceled' event from Tripletex
     *
     * @param array $data Webhook data
     * @return void
     */
    private function handleInvoiceCanceled($data)
    {
        $invoiceId = $data['invoice']['id'];

        $this->updateInvoiceStatus($invoiceId, 'canceled');
    }

    /**
     * Update the invoice status in Paymenter (or your database)
     *
     * @param int $invoiceId The invoice ID
     * @param string $status The new status of the invoice
     * @return void
     */
    private function updateInvoiceStatus($invoiceId, $status)
    {
        // Implement the logic for updating the invoice status in your system
        // Example: updateInvoiceStatusInPaymenter($invoiceId, $status);
    }
}
