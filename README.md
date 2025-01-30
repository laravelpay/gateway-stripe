# Stripe integration for Laravel Pay
This package uses Stripes Session API so users can directly checkout on the stripe hosted checkout page.

Before you can install this package, make sure you have the composer package `laravelpay/framework` installed. Learn more here https://github.com/laravelpay/framework

## Installation
Run this command inside your Laravel application

```
php artisan gateway:install laravelpay/gateway-stripe
```

## Setup
```
php artisan gateway:setup stripe-checkout
```
