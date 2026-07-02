<?php

namespace Authifly\Provider;

use Authifly\Adapter\OAuth2;

class Copper extends OAuth2
{
    /**
     * {@inheritdoc}
     */
    protected $apiBaseUrl = 'https://api.copper.com/developer_api/v1/';

    /**
     * {@inheritdoc}
     */
    protected $authorizeUrl = 'https://app.copper.com/oauth/authorize';

    /**
     * {@inheritdoc}
     */
    protected $accessTokenUrl = 'https://app.copper.com/oauth/token';

    /**
     * {@inheritdoc}
     */
    protected $apiDocumentation = 'https://developer.copper.com/';

    /**
     * {@inheritdoc}
     */
    protected $scope = 'developer/v1/all';

    protected $supportRequestState = false;

    /**
     * {@inheritdoc}
     */
    protected function initialize()
    {
        parent::initialize();

        $access_token = $this->getStoredData('access_token');

        if (empty($access_token)) {
            $access_token = $this->config->get('access_token');
        }

        if (!empty($access_token)) {
            $this->apiRequestHeaders = [
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ];
        }
    }
}
