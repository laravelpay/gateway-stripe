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
            'secret_key' => [
                'label' => 'Secret Key',
                'description' => 'Enter your Stripe secret key.',
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
                'success_url' => $payment->webhookUrl(),
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

        // store transaction id locally
        $payment->update([
            'transaction_id' => $checkoutSession['id'],
        ]);

        return redirect($checkoutSession['url'], 303);
    }

    public function callback(Request $request)
    {
        // Handle the Stripe webhook event
        if($request->has('payment_id')) {
            $payment = Payment::find($request->input('payment_id'));

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

            if($checkoutSession['payment_status'] === 'paid') {
                $payment->completed();
            }

            return redirect($payment->successUrl());
        }

        // listen for stripe webhook events
        // todo
    }
}
