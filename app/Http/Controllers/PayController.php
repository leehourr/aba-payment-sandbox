<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class PayController extends Controller
{
    // PayWay API endpoints
    private const QR_GENERATION_URL = 'https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/generate-qr';
    private const CARD_LINK_URL = 'https://checkout-sandbox.payway.com.kh/api/payment-credential/v3/cof/link-card';
    
    // Payment types
    private const PAYMENT_TYPE_REDIRECT = 'redirect';
    private const PAYMENT_TYPE_DIRECT = 'direct';

    /**
     * Handle payment processing
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function payHandler(Request $request): JsonResponse
    {
        try {
            $validatedData = $this->validateRequest($request);
            $paymentData = $this->preparePaymentData($validatedData);
            $response = $this->sendPaymentRequest($paymentData);
            
            return $this->handlePaymentResponse($response, $paymentData['tran_id']);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse('Validation failed', ['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Payment processing failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Validate the incoming request
     */
    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_option' => 'required|string',
            'description' => 'nullable|string|max:255',
        ]);
    }

    /**
     * Prepare payment data and generate hash
     */
    private function preparePaymentData(array $validatedData): array
    {
        $reqTime = now()->utc()->format('YmdHis');
        $merchantId = env('MERCHANT_ID');
        $tranId = $this->generateTransactionId();
        $apiKey = env('API_KEY');

        // Payment data
        $paymentData = [
            'req_time' => $reqTime,
            'merchant_id' => $merchantId,
            'tran_id' => $tranId,
            'amount' => $validatedData['amount'],
            'currency' => 'USD',
            'payment_option' => $validatedData['payment_option'],
            'description' => $validatedData['description'] ?? '',
            'lifetime' => 6,
            'qr_image_template' => 'template4_color',
        ];

        // Generate hash
        $hashData = $this->prepareHashData($paymentData);
        $paymentData['hash'] = base64_encode(hash_hmac('sha512', $hashData, $apiKey, true));

        return $paymentData;
    }

    /**
     * Generate unique transaction ID
     */
    private function generateTransactionId(): string
    {
        return 'TX' . now()->format('mdHis') . substr(uniqid(), -4);
    }

    /**
     * Prepare data string for hash generation
     */
    private function prepareHashData(array $paymentData): string
    {
        // Hash fields in required order
        $hashFields = [
            $paymentData['req_time'],
            $paymentData['merchant_id'],
            $paymentData['tran_id'],
            $paymentData['amount'],
            '', // items
            '', // shipping
            '', // firstname
            '', // lastname
            '', // email
            '', // phone
            '', // type
            $paymentData['payment_option'],
            '', // return_url
            '', // cancel_url
            '', // continue_success_url
            '', // return_deeplink
            $paymentData['currency'],
            '', // custom_fields
            '', // return_params
            '', // payout
            $paymentData['lifetime'],
            '', // additional_params
            '', // google_pay_token
            '', // skip_success_page
            $paymentData['qr_image_template'],
        ];

        return implode('', array_map('strval', $hashFields));
    }

    /**
     * Send payment request to PayWay
     */
    private function sendPaymentRequest(array $paymentData): \Illuminate\Http\Client\Response
    {
        $apiUrl = $this->getApiUrl($paymentData['payment_option']);
        
        return Http::withOptions([
            'verify' => false, // Disable SSL verification for development
            'timeout' => 30,
        ])->post($apiUrl, [
            'req_time' => $paymentData['req_time'],
            'merchant_id' => $paymentData['merchant_id'],
            'tran_id' => $paymentData['tran_id'],
            'amount' => $paymentData['amount'],
            'currency' => $paymentData['currency'],
            'payment_option' => $paymentData['payment_option'],
            'lifetime' => $paymentData['lifetime'],
            'qr_image_template' => $paymentData['qr_image_template'],
            'hash' => $paymentData['hash'],
        ]);
    }

    /**
     * Get appropriate API URL based on payment option
     */
    private function getApiUrl(string $paymentOption): string
    {
        return ($paymentOption === 'cards') ? self::CARD_LINK_URL : self::QR_GENERATION_URL;
    }

    /**
     * Handle PayWay response
     */
    private function handlePaymentResponse(\Illuminate\Http\Client\Response $response, string $tranId): JsonResponse
    {
        if (!$response->successful()) {
            return $this->handleFailedResponse($response);
        }

        $contentType = $response->header('Content-Type');

        if (str_contains($contentType, 'text/html')) {
            return $this->handleHtmlResponse($response, $tranId);
        }

        return $this->handleJsonResponse($response, $tranId);
    }

    /**
     * Handle HTML response (card payments)
     */
    private function handleHtmlResponse(\Illuminate\Http\Client\Response $response, string $tranId): JsonResponse
    {
        $responseBody = $response->body();

        return response()->json([
            'success' => true,
            'message' => 'Checkout page received',
            'transaction_id' => $tranId,
            'payment_type' => self::PAYMENT_TYPE_REDIRECT,
            'checkout_html' => $responseBody,
            'content_type' => $response->header('Content-Type'),
            'response_size' => strlen($responseBody),
            'instructions' => 'Display the checkout_html for card payment'
        ]);
    }

    /**
     * Handle JSON response (QR and other payments)
     */
    private function handleJsonResponse(\Illuminate\Http\Client\Response $response, string $tranId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Payment processed successfully',
            'transaction_id' => $tranId,
            'payment_type' => self::PAYMENT_TYPE_DIRECT,
            'data' => $response->json()
        ]);
    }

    /**
     * Handle failed PayWay response
     */
    private function handleFailedResponse(\Illuminate\Http\Client\Response $response): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Payment gateway error',
            'error' => 'Unexpected HTTP status: ' . $response->status() . ' ' . $response->reason(),
            'debug_info' => [
                'http_status' => $response->status(),
                'response_headers' => $response->headers(),
                'raw_response' => $response->body(),
                'content_type' => $response->header('Content-Type'),
            ]
        ], $response->status());
    }

    /**
     * Generate error response
     */
    private function errorResponse(string $message, array $additionalData = [], int $statusCode = 500): JsonResponse
    {
        $responseData = [
            'success' => false,
            'message' => $message,
        ];

        return response()->json(array_merge($responseData, $additionalData), $statusCode);
    }
}
