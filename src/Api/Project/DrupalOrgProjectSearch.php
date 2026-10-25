<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

use GuzzleHttp\Promise\PromiseInterface;
use mglaman\DrupalOrg\Client;
use Psr\Http\Message\ResponseInterface;

final class DrupalOrgProjectSearch
{
    private const ENDPOINT = 'https://www.drupal.org/jsonapi/index/project_modules';

    private const INVALID_RESPONSE_MESSAGE = 'Drupal.org project search returned an invalid response.';

    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * @return list<array{
     *     machineName: string,
     *     title: string,
     *     description: string,
     *     url: string
     * }>
     */
    public function search(string $query, int $limit = 10, int $offset = 0): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Drupal.org project search query cannot be empty.');
        }

        if ($limit < 1) {
            throw new \InvalidArgumentException('Drupal.org project search limit must be greater than zero.');
        }

        if ($offset < 0) {
            throw new \InvalidArgumentException('Drupal.org project search offset cannot be negative.');
        }

        $response = $this->client->getGuzzleClient()->request('GET', self::ENDPOINT, $this->requestOptions($query, $limit, $offset));

        return $this->parseResponse($response);
    }

    /**
     * Start one read-only search without waiting for its response.
     *
     * Callers must bound the number of promises they keep in flight.
     *
     * @return PromiseInterface
     */
    public function searchAsync(string $query, int $limit = 10, int $offset = 0): PromiseInterface
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Drupal.org project search query cannot be empty.');
        }

        if ($limit < 1) {
            throw new \InvalidArgumentException('Drupal.org project search limit must be greater than zero.');
        }

        if ($offset < 0) {
            throw new \InvalidArgumentException('Drupal.org project search offset cannot be negative.');
        }

        return $this->client->getGuzzleClient()
            ->requestAsync('GET', self::ENDPOINT, $this->requestOptions($query, $limit, $offset))
            ->then(fn(ResponseInterface $response): array => $this->parseResponse($response));
    }

    /**
     * @return array<string, mixed>
     */
    private function requestOptions(string $query, int $limit, int $offset): array
    {
        return [
            'headers' => [
                'Accept' => 'application/vnd.api+json',
            ],
            'query' => [
                'filter[status]' => 1,
                'filter[type]' => 'project_module',
                'filter[project_type]' => 'full',
                'filter[fulltext]' => $query,
                'sort' => '-search_api_relevance',
                'page[limit]' => $limit,
                'page[offset]' => $offset,
            ],
        ];
    }

    /**
     * @return list<array{
     *     machineName: string,
     *     title: string,
     *     description: string,
     *     url: string
     * }>
     */
    private function parseResponse(ResponseInterface $response): array
    {
        try {
            $payload = json_decode(
                $response->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE, 0, $exception);
        }

        if (!is_array($payload)
            || !array_key_exists('data', $payload)
            || !is_array($payload['data'])
        ) {
            throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE);
        }

        $projects = [];
        foreach ($payload['data'] as $item) {
            if (!is_array($item)
                || !array_key_exists('attributes', $item)
                || !is_array($item['attributes'])
            ) {
                throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE);
            }

            $attributes = $item['attributes'];
            $machineName = $attributes['field_project_machine_name'] ?? null;
            $title = $attributes['title'] ?? null;
            if (!is_string($machineName)
                || trim($machineName) === ''
                || !is_string($title)
                || trim($title) === ''
            ) {
                throw new \RuntimeException(self::INVALID_RESPONSE_MESSAGE);
            }

            $machineName = trim($machineName);
            $title = trim($title);

            $projects[] = [
                'machineName' => $machineName,
                'title' => $title,
                'description' => $this->normalizeDescription($attributes['body'] ?? null),
                'url' => 'https://www.drupal.org/project/' . $machineName,
            ];
        }

        return $projects;
    }

    private function normalizeDescription(mixed $body): string
    {
        if (!is_array($body)) {
            return '';
        }

        foreach (['summary', 'value'] as $key) {
            $text = $body[$key] ?? null;
            if (!is_string($text)) {
                continue;
            }

            $normalizedText = $this->normalizeText($text);
            if ($normalizedText !== '') {
                return $normalizedText;
            }
        }

        return '';
    }

    private function normalizeText(string $value): string
    {
        $text = html_entity_decode(
            strip_tags($value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text ?? '');
    }
}
