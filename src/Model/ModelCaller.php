<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Model;

use IndianOpinion\YtToPost\Exception\AccountHoldException;
use IndianOpinion\YtToPost\Exception\ModelCallException;
use IndianOpinion\YtToPost\Exception\ModelOutputException;

/**
 * Anything that turns a schema request into validated JSON. The default is
 * llm-json-php; a site can plug in its own with the
 * `yt_to_post_model_caller` filter and run without that dependency.
 */
interface ModelCaller
{
    /**
     * @throws AccountHoldException when the provider account is out of credit
     * @throws ModelOutputException when the output does not fit the schema
     * @throws ModelCallException for anything that may change on retry
     */
    public function call(ModelRequest $request): ModelResponse;

    /** Provider name of the first route, for spend checks. */
    public function provider(): string;

    /** Model id of the first route, for spend checks and token ratios. */
    public function model(): string;
}
