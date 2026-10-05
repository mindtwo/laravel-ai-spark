<?php

namespace mindtwo\LaravelAiSpark\Concerns;

use Prism\Prism\Exceptions\PrismException;
use Psr\Http\Message\ResponseInterface;

trait FailsOnRedirect
{
    /**
     * Cloudflare Access answers an unauthenticated request with a redirect to its login
     * page instead of an error status. Fail loudly rather than parsing HTML as JSON.
     */
    protected function failOnRedirect(ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400) {
            throw PrismException::providerResponseError(sprintf(
                'Spark redirected the request (%d to %s). Cloudflare Access most likely rejected the service token.',
                $status,
                $response->getHeaderLine('Location') ?: 'unknown location',
            ));
        }

        return $response;
    }
}
