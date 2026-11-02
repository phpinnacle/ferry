<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use PHPinnacle\Ferry\Data\RegisteredSchema;
use stdClass;
use UnexpectedValueException;

/** @phpstan-import-type RegistrySchema from RegisteredSchema */
class SchemaRegistryClient
{
    public function __construct(
        private readonly PendingRequest $http,
    ) {}

    /** @return list<RegisteredSchema> */
    public function schemas(string $subjectPrefix, int $offset, int $limit, bool $includeDeleted = false): array
    {
        /** @var list<RegistrySchema> $data */
        $data = $this->get('schemas', [
            'latestOnly' => 'true',
            'subjectPrefix' => $subjectPrefix,
            'offset' => $offset,
            'limit' => $limit,
            ...($includeDeleted ? ['deleted' => 'true'] : []),
        ]);

        return array_map(RegisteredSchema::fromArray(...), $data);
    }

    /** @return list<int> */
    public function versions(string $subject, bool $includeDeleted = false): array
    {
        /** @var list<int> */
        return $this->get(
            'subjects/' . rawurlencode($subject) . '/versions',
            $includeDeleted ? ['deleted' => 'true'] : [],
        );
    }

    public function schema(string $subject, int $version, bool $includeDeleted = false): RegisteredSchema
    {
        /** @var RegistrySchema $data */
        $data = $this->get(
            'subjects/' . rawurlencode($subject) . '/versions/' . $version,
            $includeDeleted ? ['deleted' => 'true'] : [],
        );

        return RegisteredSchema::fromArray($data);
    }

    public function resolvedAvroSchema(string $subject, int $version, bool $includeDeleted = false): string
    {
        return $this->http
            ->get('subjects/' . rawurlencode($subject) . '/versions/' . $version . '/schema', [
                'format' => 'resolved',
                ...($includeDeleted ? ['deleted' => 'true'] : []),
            ])
            ->throw()
            ->body();
    }

    public function compatibility(?string $subject = null): string
    {
        /** @var array{compatibilityLevel: string} $data */
        $data = $this->get(
            $subject === null ? 'config' : 'config/' . rawurlencode($subject),
            $subject === null ? [] : ['defaultToGlobal' => 'true'],
        );

        return $data['compatibilityLevel'];
    }

    public function deleteSubject(string $subject, bool $permanent = false): void
    {
        $this->http->send('DELETE', 'subjects/' . rawurlencode($subject), [
            'query' => $permanent ? ['permanent' => 'true'] : [],
        ])->throw();
    }

    public function deleteVersion(string $subject, int $version, bool $permanent = false): void
    {
        $this->http->send('DELETE', 'subjects/' . rawurlencode($subject) . '/versions/' . $version, [
            'query' => $permanent ? ['permanent' => 'true'] : [],
        ])->throw();
    }

    public function restoreVersion(string $subject, int $version): RegisteredSchema
    {
        $path = 'subjects/' . rawurlencode($subject);
        $data = json_decode(
            $this->http
                ->get($path . '/versions/' . $version, ['deleted' => 'true'])
                ->throw()
                ->body(),
            flags: JSON_THROW_ON_ERROR,
        );

        if (!$data instanceof stdClass) {
            throw new UnexpectedValueException('Expected a Schema Registry response object.');
        }

        $payload = array_intersect_key((array) $data, array_flip([
            'schema',
            'schemaType',
            'references',
            'metadata',
            'ruleSet',
        ]));
        $this->http->post($path . '/versions', $payload)->throw();

        /** @var RegistrySchema $restored */
        $restored = $this->responseData($this->http->post($path, $payload)->throw());

        return RegisteredSchema::fromArray($restored);
    }

    /**
     * @param array<string, string|int> $query
     * @return array<array-key, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->responseData($this->http->get($path, $query)->throw());
    }

    /** @return array<array-key, mixed> */
    private function responseData(Response $response): array
    {
        $data = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        return is_array($data)
            ? $data
            : throw new UnexpectedValueException('Expected a Schema Registry response object or list.');
    }
}
