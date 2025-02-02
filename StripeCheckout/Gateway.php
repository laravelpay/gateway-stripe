<?php

namespace App\Gateways\StripeCheckout;

use LaraPay\Framework\Interfaces\GatewayFoundation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LaraPay\Framework\Payment;
use Illuminate\Http\Request;
use Exception;

class Gateway extends GatewayFoundation
{
    /**
     * Define the gateway identifier. This identifier should be unique. For example,
     * if the gateway name is "PayPal Express", the gateway identifier should be "paypal-express".
     *
     * @var string
     */
    protected string $identifier = 'stripe-checkout';

    /**
     * Define the gateway version.
     *
     * @var string
     */
    protected string $version = '1.0.0';

    public function config(): array
    {
        return [
            'secret_key' => [
                'label'       => 'Stripe Secret Key',
                'description' => 'Your Stripe Secret API Key',
                'type'        => 'text',
                'rules'       => ['required', 'string', 'starts_with:sk_'],
            ],
            'webhook_secret' => [
                'label'       => 'Stripe Webhook Secret',
                'description' => 'Your Stripe Webhook Secret',
                'type'        => 'text',
                'rules'       => ['required', 'string', 'starts_with:whsec_'],
            ],
        ];
    }

    public function pay($payment)
    {
        $stripeSecretKey = $payment->gateway->config('secret_key');

        $response = Http::withToken($stripeSecretKey)->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'line_items' => [[
                    'price_data' => [
                        'currency' => $payment->currency,
                        'unit_amount' => $payment->total() * 100,
                        'product_data' => [
                            'name' => $payment->description,
                        ],
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $payment->callbackUrl(),
                'cancel_url' => $payment->cancelUrl(),
                'metadata' => [
                    'payment_token' => $payment->token,
                ],
            ]);

        if ($response->failed()) {
            // Handle failure (e.g., log error, throw exception)
            throw new \Exception('Stripe Checkout Session creation failed: ' . $response->body());
        }

        $checkoutSession = $response->json();

        // store transaction id locally
        $payment->update([
            'transaction_id' => $checkoutSession['id'],
        ]);

        return redirect($checkoutSession['url']);
    }

    public function callback(Request $request)
    {
        // Handle the Stripe webhook event
        if($request->has('payment_token')) {
            $payment = Payment::where('token', $request->payment_token)->first();

            if (!$payment) {
                throw new Exception('Payment not found');
            }

            if($payment->isPaid()) {
                return redirect($payment->successUrl());
            }

            // make api call to stripe to get the payment status
            $stripeSecretKey = $payment->gateway->config('secret_key');

            $response = Http::withToken($stripeSecretKey)->get('https://api.stripe.com/v1/checkout/sessions/' . $payment->transaction_id);

            if ($response->failed()) {
                // Handle failure (e.g., log error, throw exception)
                throw new \Exception('Stripe Checkout Session retrieval failed: ' . $response->body());
            }

            $checkoutSession = $response->json();

            // check if the payment token is the same as the one in the checkout session
            if($checkoutSession['metadata']['payment_token'] !== $payment->token) {
                throw new Exception('Payment token mismatch');
            }

            if($checkoutSession['payment_status'] === 'paid') {
                $payment->completed($checkoutSession['id'], $checkoutSession);
            }

            return redirect($payment->successUrl());
        }
    }

    /**
     * Handle (webhooks) from Stripe.
     * Stripe calls this endpoint whenever the payment status changes.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \Exception
     */
    public function webhook(Request $request)
    {
        // Get the stripe transaction id from the request
        $transactionId = $request->input('data.object.id');

        if(!$transactionId ) {
            throw new Exception('Transaction ID not found');
        }

        // find the payment by the transaction id
        $payment = Payment::where('transaction_id', $transactionId)->first();

        if(!$payment) {
            throw new Exception('Payment not found');
        }

        // verify the webhook
        $this->verifyStripeWebhook($payment->gateway->config('webhook_secret'));

        if($payment->isPaid()) {
            return response()->json(['message' => 'Payment already completed'], 200);
        }

        // get the payment status from the request
        $paymentStatus = $request->input('data.object.payment_status');

        if($paymentStatus === 'paid') {
            $payment->completed($transactionId, $request->input('data.object'));

            return response()->json(['message' => 'Payment completed'], 200);
        }

        throw new Exception('Webhook does not contain valid data');
    }

    private function verifyStripeWebhook($webhookSecret)
    {
        // Get the raw request body
        $payload = file_get_contents('php://input');

        // Retrieve the Stripe signature header
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null;

        if (!$sigHeader) {
            throw new \Exception("Stripe signature header is missing.");
        }

        // Parse the Stripe signature header
        $timestamp = null;
        $signature = null;
        foreach (explode(',', $sigHeader) as $part) {
            list($key, $value) = explode('=', trim($part), 2);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signature = $value;
            }
        }

        if (!$timestamp || !$signature) {
            throw new \Exception("Invalid Stripe signature header format.");
        }

        // Compute the expected signature
        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        // Compare the computed signature with the Stripe-provided signature
        if (!hash_equals($expectedSignature, $signature)) {
            throw new \Exception("Invalid Stripe webhook signature.");
        }

        Log::error('Stripe webhook verified');

        return json_decode($payload, true); // Return the webhook event as an array
    }
}
