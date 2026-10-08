<?php

declare(strict_types=1);

namespace Ngandu\Flexpay;

use Ngandu\Flexpay\Exception\NetworkException;
use Ngandu\Flexpay\Request\CardRequest;
use Ngandu\Flexpay\Request\MobileRequest;
use Ngandu\Flexpay\Request\PayoutRequest;
use Ngandu\Flexpay\Request\Request;
use Ngandu\Flexpay\Response\CardResponse;
use Ngandu\Flexpay\Response\CheckResponse;
use Ngandu\Flexpay\Response\FlexpayResponse;
use Ngandu\Flexpay\Response\PaymentResponse;
use Ngandu\Flexpay\Response\PayoutResponse;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\PropertyInfo\Extractor\ConstructorExtractor;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class Client.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class Client
{
    private HttpClientInterface $http;

    private Serializer $serializer;

    public function __construct(
        public Credential $credential,
        public Environment $environment = Environment::SANDBOX,
        ?HttpClientInterface $http = null,
    ) {
        $this->serializer = new Serializer(
            normalizers: [
                new BackedEnumNormalizer(),
                new ObjectNormalizer(propertyTypeExtractor: new ConstructorExtractor()),
            ]
        );

        $this->http = new RetryableHttpClient(
            client: ($http ?? HttpClient::create())->withOptions([
                'auth_bearer' => $this->credential->token,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]),
            strategy: new GenericRetryStrategy(delayMs: 500),
            maxRetries: 3
        );
    }

    /**
     * Cette interface permet d’envoyer une requête de paiement à FlexPay
     *
     * @since flexpay v1
     * @throws NetworkException
     */
    public function mobile(MobileRequest $request): PaymentResponse
    {
        $request->setCredential($this->credential);

        return $this->send(
            method: 'POST',
            url: $this->environment->getMobilePaymentUrl(),
            type: PaymentResponse::class,
            options: [
                'json' => $request->getPayload(),
            ]
        );
    }

    /**
     * Cette interface permet d’envoyer une requête de paiement à FlexPay
     * Ce paiement va se faire en deux étapes :
     * - Générer l’url de paiement
     * - Redirection vers la page de paiements
     *
     * @since flexpay v1.1
     * @throws NetworkException
     */
    public function card(CardRequest $request): CardResponse
    {
        $request->setCredential($this->credential);

        return $this->send(
            method: 'POST',
            url: $this->environment->getCardPaymentUrl(),
            type: CardResponse::class,
            options: [
                'json' => $request->getPayload(),
            ]
        );
    }

    /**
     * @throws NetworkException
     */
    public function pay(Request $request): PaymentResponse|CardResponse
    {
        return match (true) {
            $request instanceof MobileRequest => $this->mobile($request),
            $request instanceof CardRequest => $this->card($request),
            default => throw new RuntimeException('Unsupported request')
        };
    }

    /**
     * Cette interface permet de vérifier l’état d’une requête de paiement envoyée à FlexPay.
     *
     * @param string $orderNumber Le code de la transaction généré par FlexPay lors de la requête de paiement
     *
     * @since flexpay v1
     * @throws NetworkException quand une erreur
     */
    public function check(string $orderNumber): CheckResponse
    {
        return $this->send(
            method: 'GET',
            url: $this->environment->getCheckStatusUrl($orderNumber),
            type: CheckResponse::class
        );
    }

    /**
     * Cette interface permet à un marchand d’envoyer à partir de son compte de l’argent électronique vers un
     * numéro de téléphone qui a un compte mobile money.
     *
     * @since flexpay v1.1
     * @throws NetworkException
     */
    public function payout(PayoutRequest $request): PayoutResponse
    {
        $request->setCredential($this->credential);

        return $this->send(
            method: 'POST',
            url: $this->environment->getPayoutUrl(),
            type: PayoutResponse::class,
            options: [
                'json' => $request->getPayload(),
            ]
        );
    }

    /**
     * Cette interface permet de vérifier l’état d’une requête de paiement envoyée à FlexPay
     */
    public function handleCallback(array $data): PaymentResponse
    {
        /** @var PaymentResponse $payment */
        $payment = $this->getMappedData(PaymentResponse::class, $data);

        return $payment;
    }

    /**
     * @template T of FlexpayResponse
     * @param class-string<T> $type
     * @param array<string, mixed> $options
     * @return T
     * @throws NetworkException
     */
    private function send(string $method, string $url, string $type, array $options = []): FlexpayResponse
    {
        try {
            return $this->getMappedData(
                type: $type,
                data: $this->http->request($method, $url, $options)->toArray()
            );
        } catch (HttpExceptionInterface $exception) {
            throw $this->createExceptionFromResponse($exception);
        } catch (HttpClientExceptionInterface $exception) {
            throw new NetworkException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @template T of FlexpayResponse
     * @param class-string<T> $type
     * @return T
     */
    private function getMappedData(string $type, array $data): FlexpayResponse
    {
        /** @var T $mapped */
        $mapped = $this->serializer->denormalize($data, $type);

        return $mapped;
    }

    private function createExceptionFromResponse(HttpExceptionInterface $exception): NetworkException
    {
        $response = $exception->getResponse();

        try {
            $body = $response->toArray(throw: false);
        } catch (HttpClientExceptionInterface) {
            $body = [];
        }

        return NetworkException::create(
            message: is_string($body['message'] ?? null) ? $body['message'] : '',
            type: is_string($body['error'] ?? null) ? $body['error'] : null,
            status: $response->getStatusCode(),
            previous: $exception
        );
    }
}
