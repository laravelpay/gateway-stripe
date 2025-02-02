# Stripe integration for Laravel Pay
This package uses Stripes Session API so users can directly checkout on the stripe hosted checkout page.

Before you can install this package, make sure you have the composer package `laravelpay/framework` installed. Learn more here https://github.com/laravelpay/framework

## Installation
Run this command inside your Laravel application

```
php artisan gateway:install laravelpay/gateway-stripe
```

## Setup

### Secret Key
Sandbox Secret key can be found at https://dashboard.stripe.com/test/apikeys


Live Secret Key can be found at https://dashboard.stripe.com/apikeys

### Create Webhook
Create Webhook in Sandbox Mode: https://dashboard.stripe.com/test/webhooks/create

Create Webhook in Live Mode: https://dashboard.stripe.com/webhooks/create

Set the webhook endpoint to `https://your-application.com/payments/webhooks/stripe-checkout` replace your-application.com with your actual domain. 

Select event `checkout.session.completed`

## Create your gateway configuration using command below

```
php artisan gateway:setup stripe-checkout
```
