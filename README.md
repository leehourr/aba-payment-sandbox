# ABA Payment Sandbox

A Laravel-based sandbox application for testing ABA Bank PayWay QR payment integration.

> ⚠️ **Important**: This is a sandbox environment that does NOT connect to real bank accounts. No actual transactions or money transfers will occur during testing.

## Overview

This project provides a safe testing environment for ABA PayWay QR API integration with:

- QR code payment generation and processing
- Payment status tracking and callbacks
- API endpoints for payment workflows
- Frontend demo pages for testing

## PayWay API Documentation

For detailed API documentation, refer to: **[PayWay QR API Documentation](https://developer.payway.com.kh/qr-api-14530840e0)**

## Quick Start

1. Clone and install:
```bash
git clone <repository-url>
cd aba-payment-sandbox
composer install
```

2. Configure environment:
```bash
cp .env.example .env
php artisan key:generate
```

3. Add PayWay sandbox credentials to `.env`:
```env
PAYWAY_MERCHANT_ID=your_sandbox_merchant_id
PAYWAY_API_KEY=your_sandbox_api_key
PAYWAY_BASE_URL=https://api-sandbox.payway.com.kh
```

4. Start the application:
```bash
php artisan serve
```

## Testing

Demo pages available at:
- `/frontend/test-api.html` - API testing interface
- `/frontend/external-links-demo.html` - Integration examples


