<?php

declare(strict_types=1);

namespace App\Chat\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ProblemResponseFactory
{
    private const string CONTENT_TYPE = 'application/problem+json; charset=utf-8';

    /**
     * Builds a 422 problem+json from a Symfony constraint-violation list.
     *
     * @param ConstraintViolationListInterface $violations Validator result
     *
     * @return JsonResponse
     */
    public static function fromViolationList(ConstraintViolationListInterface $violations): JsonResponse
    {
        $flattened = [];
        foreach ($violations as $violation) {
            $flattened[] = [
                'propertyPath' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return self::violations($flattened);
    }

    /**
     * Builds a generic RFC 7807 problem+json error (400 / 401 / 403 / 415 / 429).
     *
     * @param int                   $status  HTTP status code
     * @param string                $detail  Human-readable error detail
     * @param array<string, string> $headers Extra response headers (e.g. Retry-After)
     * @param array<string, scalar> $extra   Extra body members (e.g. a machine reason)
     *
     * @return JsonResponse
     */
    public static function error(int $status, string $detail, array $headers = [], array $extra = []): JsonResponse
    {
        return self::build($status, array_merge([
            '@type' => 'Error',
            'title' => 'An error occurred',
            'detail' => $detail,
            'status' => $status,
            'type' => '/errors/' . $status,
        ], $extra), $headers);
    }

    /**
     * Builds an RFC 7807 ConstraintViolation problem+json (422 by default).
     *
     * @param list<array{propertyPath: string, message: string}> $violations Field violations
     * @param int                                                $status     HTTP status code
     * @param array<string, string>                              $headers    Extra response headers
     *
     * @return JsonResponse
     */
    public static function violations(array $violations, int $status = Response::HTTP_UNPROCESSABLE_ENTITY, array $headers = []): JsonResponse
    {
        $detail = implode("\n", array_map(
            static fn (array $violation): string => $violation['propertyPath'] . ': ' . $violation['message'],
            $violations,
        ));

        return self::build($status, [
            '@type' => 'ConstraintViolation',
            'title' => 'An error occurred',
            'detail' => $detail,
            'violations' => $violations,
            'status' => $status,
            'type' => '/validation_errors',
        ], $headers);
    }

    /**
     * Wraps a problem+json body into a JsonResponse with the RFC 7807 content type.
     *
     * @param int                   $status  HTTP status code
     * @param array<string, mixed>  $body    Problem+json body members
     * @param array<string, string> $headers Extra response headers
     *
     * @return JsonResponse
     */
    private static function build(int $status, array $body, array $headers): JsonResponse
    {
        $response = new JsonResponse($body, $status, $headers);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }
}
