<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost;

use IndianOpinion\YtToPost\Http\HttpClient;
use IndianOpinion\YtToPost\Http\Retrier;
use IndianOpinion\YtToPost\Jobs\JobRepository;
use IndianOpinion\YtToPost\Limits\DailyCaps;
use IndianOpinion\YtToPost\Limits\QuotaMeter;
use IndianOpinion\YtToPost\Model\CallableCaller;
use IndianOpinion\YtToPost\Model\LlmJsonCaller;
use IndianOpinion\YtToPost\Model\ModelCaller;
use IndianOpinion\YtToPost\Support\Clock;
use IndianOpinion\YtToPost\Support\Sleeper;
use IndianOpinion\YtToPost\YouTube\AccessTokens;
use IndianOpinion\YtToPost\YouTube\OAuthClient;
use IndianOpinion\YtToPost\YouTube\TokenVault;
use IndianOpinion\YtToPost\YouTube\YouTubeClient;

/**
 * Wires the collaborators. Tests build one with fakes.
 */
final class Services
{
    private ?YouTubeClient $youtube = null;
    private ?OAuthClient $oauth = null;

    public function __construct(
        public readonly JobRepository $jobs,
        public readonly Clock $clock,
        public readonly HttpClient $http,
        public readonly Sleeper $sleeper,
        public readonly TokenVault $vault = new TokenVault(),
        private readonly ?ModelCaller $fixedCaller = null
    ) {
    }

    public function oauth(): OAuthClient
    {
        return $this->oauth ??= new OAuthClient($this->http, $this->vault, $this->clock);
    }

    public function youtube(): YouTubeClient
    {
        return $this->youtube ??= new YouTubeClient(
            $this->http,
            new Retrier($this->sleeper),
            $this->quota(),
            new AccessTokens($this->oauth())
        );
    }

    public function quota(): QuotaMeter
    {
        return new QuotaMeter($this->clock);
    }

    public function caps(): DailyCaps
    {
        return new DailyCaps($this->clock);
    }

    /**
     * The model caller: the `yt_to_post_model_caller` filter first (a
     * ModelCaller or a callable), then llm-json-php when it is installed and
     * a model and key are configured. Null when nothing is configured.
     */
    public function caller(): ?ModelCaller
    {
        if ($this->fixedCaller !== null) {
            return $this->fixedCaller;
        }
        $custom = function_exists('apply_filters') ? apply_filters('yt_to_post_model_caller', null) : null;
        if ($custom instanceof ModelCaller) {
            return $custom;
        }
        if (is_callable($custom)) {
            return new CallableCaller($custom);
        }
        if (!LlmJsonCaller::available()) {
            return null;
        }
        $routes = Settings::modelRoutes();
        if (function_exists('apply_filters')) {
            $f = apply_filters('yt_to_post_model_routes', $routes);
            if (is_array($f)) {
                /** @var list<array{kind:string,model:string,base_url:string,token_cap:int,temperature:float}> $f */
                $routes = array_values($f);
            }
        }
        $key = Settings::string('llm_api_key');
        if ($routes === []) {
            return null;
        }
        return new LlmJsonCaller($routes, $key);
    }
}
