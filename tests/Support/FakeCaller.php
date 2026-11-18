<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Support;

use IndianOpinion\YtToPost\Model\ModelCaller;
use IndianOpinion\YtToPost\Model\ModelRequest;
use IndianOpinion\YtToPost\Model\ModelResponse;

/**
 * A scripted model. Each purpose has a handler; requests are recorded.
 */
final class FakeCaller implements ModelCaller
{
    /** @var list<ModelRequest> */
    public array $requests = [];
    /** @var array<string,\Closure(ModelRequest):(array<string,mixed>|\Throwable)> */
    public array $handlers = [];

    public function __construct(private readonly string $modelId = 'test/model')
    {
    }

    public function on(string $purpose, \Closure $handler): self
    {
        $this->handlers[$purpose] = $handler;
        return $this;
    }

    public function call(ModelRequest $request): ModelResponse
    {
        $this->requests[] = $request;
        $h = $this->handlers[$request->purpose] ?? null;
        if ($h === null) {
            throw new \RuntimeException('No fake model handler for ' . $request->purpose);
        }
        $out = $h($request);
        if ($out instanceof \Throwable) {
            throw $out;
        }
        return new ModelResponse($out, 1, 'fake', $this->modelId, 100, 50, 0.001);
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return $this->modelId;
    }

    /** @return list<string> */
    public function purposes(): array
    {
        return array_map(static fn (ModelRequest $r): string => $r->purpose, $this->requests);
    }
}
