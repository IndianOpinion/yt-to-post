<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Model;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\ConfigurationException as LlmConfigurationException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\LlmJsonException;
use IndianOpinion\LlmJson\Exception\SchemaViolationException;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Transport\WordPressTransport;
use IndianOpinion\YtToPost\Exception\AccountHoldException;
use IndianOpinion\YtToPost\Exception\ConfigurationException;
use IndianOpinion\YtToPost\Exception\ModelCallException;
use IndianOpinion\YtToPost\Exception\ModelOutputException;

/**
 * The default caller: indianopinion/llm-json-php with the WordPress
 * transport and transient store. Every model call goes through here unless
 * a site plugs in its own caller.
 */
final class LlmJsonCaller implements ModelCaller
{
    /**
     * @param list<array{kind:string,model:string,base_url:string,token_cap:int,temperature:float}> $routes
     */
    public function __construct(
        private readonly array $routes,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly ?Client $client = null
    ) {
        if ($routes === []) {
            throw new ConfigurationException('No model is configured.');
        }
    }

    public static function available(): bool
    {
        return class_exists(Client::class);
    }

    public function provider(): string
    {
        return $this->routes[0]['kind'];
    }

    public function model(): string
    {
        return $this->routes[0]['model'];
    }

    public function call(ModelRequest $request): ModelResponse
    {
        try {
            $client = $this->client ?? $this->buildClient();
            $schema = Schema::fromArray($request->schemaName, $request->schema);
            $req = Request::for($request->purpose, $schema)
                ->withSystem($request->system)
                ->withInput($request->input);
            if ($request->deadlineSeconds !== null && $request->deadlineSeconds >= 5.0) {
                $req = $req->withDeadline($request->deadlineSeconds);
            }
            $result = $client->complete($req);
        } catch (CreditExhaustedException $e) {
            throw new AccountHoldException('The model provider account is out of credit.', 0, $e);
        } catch (LlmConfigurationException $e) {
            throw new ConfigurationException('Model configuration error: ' . $e->getMessage(), 0, $e);
        } catch (SchemaViolationException $e) {
            throw new ModelOutputException('Model output did not match the schema: ' . $e->getMessage(), 0, $e);
        } catch (LlmJsonException $e) {
            throw new ModelCallException('Model call failed: ' . $e->getMessage(), 0, $e);
        }
        $usage = $result->totalUsage();
        /** @var array<string,mixed> $data */
        $data = $result->data();
        return new ModelResponse(
            $data,
            max(1, count($result->attempts())),
            $result->provider(),
            $result->model(),
            $usage->promptTokens,
            $usage->completionTokens,
            $usage->cost
        );
    }

    private function buildClient(): Client
    {
        $routes = [];
        foreach ($this->routes as $r) {
            $provider = match ($r['kind']) {
                'openrouter' => Provider::openRouter($this->apiKey, function_exists('home_url') ? home_url('/') : null, 'yt-to-post'),
                'openai' => Provider::openAi($this->apiKey),
                default => Provider::generic('generic', $r['base_url'], $this->apiKey, $this->apiKey === ''),
            };
            if ($r['kind'] !== 'generic' && $r['base_url'] !== '') {
                $provider = $provider->withBaseUrl($r['base_url']);
            }
            $routes[] = Route::to($provider, $r['model'])
                ->withTokenCap($r['token_cap'])
                ->withTemperature($r['temperature']);
        }
        return Client::create(Chain::of(...$routes), new WordPressTransport())
            ->withStore(new WordPressTransientStore());
    }
}
