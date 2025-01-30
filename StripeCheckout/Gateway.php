<?php

namespace App\Gateways\StripeCheckout;

use LaraPay\Framework\Interfaces\GatewayFoundation;
use Illuminate\Support\Facades\Http;
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
            'public_key' => [
                'label' => 'Public Key',
                'description' => 'Enter your Stripe public key.',
                'type' => 'text',
                'rules' => ['required'],
            ],
            'webhook_secret' => [
                'label' => 'Webhook Secret',
                'description' => 'Enter your Stripe webhook secret.',
                'type' => 'text',
                'rules' => ['required'],
            ],
        ];
    }

    public function pay($payment)
    {
        $payment_types = ($payment->currency == 'EUR') ? ['card', 'ideal', 'bancontact'] : ['card'];

        $stripeSecretKey = $payment->gateway->config('secret_key');

        $response = Http::withToken($stripeSecretKey)
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'payment_method_types' => $payment_types,
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
                'success_url' => $payment->successUrl(),
                'cancel_url' => $payment->cancelUrl(),
                'metadata' => [
                    'payment_id' => $payment->id,
                ],
            ]);

        if ($response->failed()) {
            // Handle failure (e.g., log error, throw exception)
            throw new \Exception('Stripe Checkout Session creation failed: ' . $response->body());
        }

        $checkoutSession = $response->json();

        return redirect($checkoutSession['url'], 303);
    }

    public function callback(Request $request)
    {
        // Handle the Stripe webhook event
    }
}
