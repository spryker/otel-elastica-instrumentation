<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Service\OtelElasticaInstrumentation\OpenTelemetry;

use Elastica\Client;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Spryker\Shared\Opentelemetry\Instrumentation\CachedInstrumentation;
use Spryker\Shared\Opentelemetry\Request\RequestProcessor;
use Spryker\Zed\Opentelemetry\Business\Generator\SpanFilter\SamplerSpanFilter;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

class ElasticaInstrumentation
{
    /**
     * @var string
     */
    protected const METHOD_NAME = 'request';

    /**
     * @var string
     */
    protected const SPAN_NAME = 'elasticsearch-request';

    /**
     * @var string
     */
    protected const HEADER_HOST = 'host';

    /**
     * @var string
     */
    protected const ATTRIBUTE_QUERY_TIME = 'queryTime';

    /**
     * @var string
     */
    protected const ATTRIBUTE_SEARCH_INDEX = 'search.index';

    /**
     * @var string
     */
    protected const ATTRIBUTE_SEARCH_QUERY = 'search.query';

    /**
     * @var string
     */
    protected const ATTRIBUTE_ROOT_URL = 'root.url';

    /**
     * @return void
     */
    public static function register(): void
    {
        hook(
            class: Client::class,
            function: static::METHOD_NAME,
            pre: function (Client $client, array $params): void {
                $instrumentation = CachedInstrumentation::getCachedInstrumentation();
                $request = new RequestProcessor();

                $span = $instrumentation->tracer()
                    ->spanBuilder(static::SPAN_NAME)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(static::ATTRIBUTE_SEARCH_INDEX, $params[0])
                    ->setAttribute(static::ATTRIBUTE_SEARCH_QUERY, serialize($params[2]))
                    ->setAttribute(static::ATTRIBUTE_ROOT_URL, $request->getRequest()->getUri())
                    ->setAttribute(TraceAttributes::URL_DOMAIN, $request->getRequest()->headers->get(static::HEADER_HOST))
                    ->startSpan();
                $span->activate();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: function (Client $client, array $params, $response, ?Throwable $exception): void {
                $span = Span::fromContext(Context::getCurrent());

                if ($exception !== null) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                } else {
                    $span->setAttribute(static::ATTRIBUTE_QUERY_TIME, $response->getQueryTime());
                    $span->setStatus(StatusCode::STATUS_OK);
                }

                $span = SamplerSpanFilter::filter($span, true);

                $span->end();
            }
        );
    }
}
