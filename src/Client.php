<?php

declare(strict_types=1);

namespace Ngandu\Flexpay;

use InvalidArgumentException;
use Ngandu\Flexpay\Http\HttpTransport;
use Ngandu\Flexpay\Request\CardRequest;
use Ngandu\Flexpay\Request\MobileRequest;
use Ngandu\Flexpay\Request\PayoutRequest;
use Ngandu\Flexpay\Response\CardResponse;
use Ngandu\Flexpay\Response\CheckResponse;
use Ngandu\Flexpay\Response\MobileResponse;
use Ngandu\Flexpay\Response\PayoutResponse;
use Ngandu\Flexpay\Response\ResponseDecoder;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Offers explicit collection, payout, and transaction-check operations.
 *
 * BaseUrl supplies all network destinations. Immutable requests encode their operation inputs;
 * HttpTransport authenticates and exchanges them, while ResponseDecoder builds the typed results.
 * Callback parsing belongs to CallbackResponse and does not require a client or credentials.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class Client
{
    private HttpTransport $transport;

    private ResponseDecoder $responses;

    public function __construct(
        Credential $credential,
        private BaseUrl $baseUrl,
        ?HttpClientInterface $http = null
    ) {
        $this->transport = new HttpTransport($credential, $http);
        $this->responses = new ResponseDecoder();
    }

    public function mobile(MobileRequest $request): MobileResponse
    {
        return $this->transport->post($this->baseUrl->payment . '/paymentService', $request->toPayload(), $this->responses->mobile(...));
    }

    public function card(CardRequest $request): CardResponse
    {
        return $this->transport->post($this->baseUrl->payment . '/paymentService', $request->toPayload(), $this->responses->card(...));
    }

    public function payout(PayoutRequest $request): PayoutResponse
    {
        return $this->transport->post($this->baseUrl->payout . '/merchantPayOutService', $request->toPayload(), $this->responses->payout(...));
    }

    public function check(string $orderNumber): CheckResponse
    {
        if (trim($orderNumber) === '' || in_array($orderNumber, ['.', '..'], true)) {
            throw new InvalidArgumentException('The order number must be nonblank and must not be a dot path segment');
        }

        return $this->transport->get($this->baseUrl->check . '/check/' . rawurlencode($orderNumber), $this->responses->check(...));
    }
}
