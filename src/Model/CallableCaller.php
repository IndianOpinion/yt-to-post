<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Model;

use IndianOpinion\YtToPost\Exception\ModelOutputException;

/**
 * Wraps a plain callable `fn (ModelRequest $r): array|ModelResponse`, for
 * sites that return a callable from the `yt_to_post_model_caller` filter.
 */
final class CallableCaller implements ModelCaller
{
    /** @var \Closure(ModelRequest):mixed */
    private \Closure $fn;

    /**
     * @param callable(ModelRequest):mixed $fn
     */
    public function __construct(callable $fn, private readonly string $provider = 'custom', private readonly string $model = 'custom')
    {
        $this->fn = \Closure::fromCallable($fn);
    }

    public function call(ModelRequest $request): ModelResponse
    {
        $out = ($this->fn)($request);
        if ($out instanceof ModelResponse) {
            return $out;
        }
        if (is_array($out)) {
            /** @var array<string,mixed> $out */
            return new ModelResponse($out, 1, $this->provider, $this->model);
        }
        throw new ModelOutputException('The custom model caller did not return an array.');
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }
}
