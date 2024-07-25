<?php

namespace FoskyM\IssueTrackingYoutrack;

use Flarum\Extend;

class YouTrack
{
    protected $url;
    protected $token;
    protected $project;
    public function __construct($url, $token, $project)
    {
        $this->url = $url;
        $this->token = $token;
        $this->project = $project;
    }

    public function init(): \Cog\YouTrack\Rest\Client\YouTrackClient
    {
        // Instantiate PSR-7 HTTP Client
        $psrHttpClient = new \GuzzleHttp\Client([
            'base_uri' => $this->url,
        ]);

        // Instantiate YouTrack API HTTP Client Adapter
        $httpClient = new \Cog\YouTrack\Rest\HttpClient\GuzzleHttpClient($psrHttpClient);

        // Instantiate YouTrack API Token Authorizer
        $authorizer = new \Cog\YouTrack\Rest\Authorizer\TokenAuthorizer($this->token);

        // Instantiate YouTrack API Client
        $youtrack = new \Cog\YouTrack\Rest\Client\YouTrackClient($httpClient, $authorizer);

        return $youtrack;
    }
}