<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\CheckoutRequest;
use App\DTOs\Billing\PaymentVerification;
use App\DTOs\Billing\VerifiedPayment;
use App\Exceptions\Billing\CheckoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class InfinitePayGateway implements CheckoutGateway
{
    private const string BASE_URL = 'https://api.checkout.infinitepay.io';

    public function create(CheckoutRequest $request): string
    {
        $payload = [
            'handle' => $request->merchantHandle,
            'order_nsu' => $request->orderReference,
            'items' => [['quantity' => 1, 'price' => $request->amount->minorUnits, 'description' => 'Subscription invoice']],
            'webhook_url' => $request->webhookUrl,
            'options' => ['single_payment' => true, 'valid_until' => $request->expiresAt->format(DATE_ATOM)],
        ];
        if ($request->redirectUrl !== null) {
            $payload['redirect_url'] = $request->redirectUrl;
        }
        $response = $this->post('/links', $payload);
        $url = $response->json('url');
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! in_array(parse_url($url, PHP_URL_HOST), ['checkout.infinitepay.io', 'checkout.infinitepay.com.br'], true)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_PORT) !== null) {
            throw new CheckoutException('The provider returned an invalid checkout URL.');
        }

        return $url;
    }

    public function check(PaymentVerification $reference): ?VerifiedPayment
    {
        $response = $this->post('/payment_check', [
            'handle' => $reference->merchantHandle, 'order_nsu' => $reference->orderReference,
            'transaction_nsu' => $reference->transactionNsu, 'slug' => $reference->slug,
        ]);
        if ($response->json('success') === false) {
            return null;
        }
        if ($response->json('success') !== true || ! is_bool($response->json('paid'))) {
            throw new CheckoutException('The provider returned an invalid confirmation.');
        }
        if (! $response->json('paid')) {
            return null;
        }
        $amount = $response->json('amount');
        $paidAmount = $response->json('paid_amount');
        $installments = $response->json('installments');
        $method = $response->json('capture_method');
        if (! is_int($amount) || ! is_int($paidAmount) || ! is_int($installments) || ! is_string($method)) {
            throw new CheckoutException('The provider returned invalid payment fields.');
        }

        return new VerifiedPayment($reference, $amount, $paidAmount, $installments, $method);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload): Response
    {
        $key = config('infinitepay.api_key');
        if (! is_string($key) || trim($key) === '') {
            throw new CheckoutException('InfinitePay API key is not configured.');
        }
        try {
            $response = Http::baseUrl(self::BASE_URL)->withHeaders(['X-Api-Key' => $key])
                ->acceptJson()->asJson()->withoutRedirecting()->timeout(5)->connectTimeout(2)->post($path, $payload);
        } catch (ConnectionException) {
            throw new CheckoutException('The provider could not be reached.');
        }
        if (! $response->successful()) {
            throw new CheckoutException('The provider did not accept the request.', 503, $response->clientError());
        }

        return $response;
    }
}
