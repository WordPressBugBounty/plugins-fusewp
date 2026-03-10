<?php

namespace Authifly\Provider;

use Authifly\Adapter\OAuth2;

/**
 * BirdSend OAuth2 provider adapter.
 *
 * https://developer.birdsend.co/api-documentation.html#authentication
 *
 * OAuth flow: https://developer.birdsend.co/
 * - Authorization: https://app.birdsend.co/oauth/authorize
 * - Token: https://app.birdsend.co/oauth/token
 * - API: https://api.birdsend.co/v1/
 */
class Birdsend extends OAuth2
{
    /**
     * {@inheritdoc}
     */
    protected $apiBaseUrl = 'https://api.birdsend.co/v1/';

    /**
     * {@inheritdoc}
     */
    protected $authorizeUrl = 'https://app.birdsend.co/oauth/authorize';

    /**
     * {@inheritdoc}
     */
    protected $accessTokenUrl = 'https://app.birdsend.co/oauth/token';

    /**
     * {@inheritdoc}
     */
    protected $apiDocumentation = 'https://developer.birdsend.co/';

    /**
     * {@inheritdoc}
     * read - for GET requests, write - for POST/PATCH/DELETE
     */
    protected $scope = 'write read';

    protected $supportRequestState = false;

    /**
     * {@inheritdoc}
     */
    protected function initialize()
    {
        parent::initialize();

        $refresh_token = $this->getStoredData('refresh_token');

        if (empty($refresh_token)) {
            $refresh_token = $this->config->get('refresh_token');
        }

        $this->tokenRefreshParameters = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => $this->scope,
        ];

        $access_token = $this->getStoredData('access_token');

        if (empty($access_token)) {
            $access_token = $this->config->get('access_token');
        }

        if ( ! empty($access_token)) {
            $this->apiRequestHeaders = [
                'Authorization' => 'Bearer ' . $access_token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json'
            ];
        }
    }
}
