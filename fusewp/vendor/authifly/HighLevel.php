<?php

namespace Authifly\Provider;

use Authifly\Adapter\OAuth2;
use Authifly\Data;
use Authifly\Exception\InvalidAccessTokenException;

/**
 * Hubspot OAuth2 provider adapter.
 */
class HighLevel extends OAuth2
{
    /**
     * {@inheritdoc}
     */
    protected $apiBaseUrl = 'https://services.leadconnectorhq.com/';

    /**
     * {@inheritdoc}
     */
    protected $authorizeUrl = 'https://marketplace.gohighlevel.com/oauth/chooselocation';

    /**
     * {@inheritdoc}
     */
    protected $accessTokenUrl = 'https://services.leadconnectorhq.com/oauth/token';

    /**
     * {@inheritdoc}
     */
    protected $apiDocumentation = 'https://highlevel.stoplight.io/';

    /**
     * {@inheritdoc}
     */
    protected $scope = 'contacts.write contacts.readonly workflows.readonly locations/tags.write locations/tags.readonly locations/customFields.readonly locations/customFields.write';

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
        ];

        /** Hubspot explicitly require access token to be set as Bearer.  */
        $access_token = $this->getStoredData('access_token');

        if (empty($access_token)) $access_token = $this->config->get('access_token');

        if ( ! empty($access_token)) {
            $this->apiRequestHeaders = [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ];
        }
    }

    protected function validateAccessTokenExchange($response)
    {
        $data = (new Data\Parser())->parse($response);

        $collection = new Data\Collection($data);

        if ( ! $collection->exists('access_token')) {
            throw new InvalidAccessTokenException(
                'Provider returned an invalid access_token: ' . htmlentities($response)
            );
        }

        $this->storeData('access_token', $collection->get('access_token'));
        $this->storeData('token_type', $collection->get('token_type'));

        $this->storeData('userType', $collection->get('userType'));
        $this->storeData('companyId', $collection->get('companyId'));
        $this->storeData('locationId', $collection->get('locationId'));
        $this->storeData('userId', $collection->get('userId'));

        if ($collection->get('refresh_token')) {
            $this->storeData('refresh_token', $collection->get('refresh_token'));
        }

        // calculate when the access token expire
        if ($collection->exists('expires_in')) {
            $expires_at = time() + (int)$collection->get('expires_in');

            $this->storeData('expires_in', $collection->get('expires_in'));
            $this->storeData('expires_at', $expires_at);
        }

        $this->deleteStoredData('authorization_state');

        $this->initialize();

        return $collection;
    }

    public function getAccessToken()
    {
        $tokenNames = [
            'access_token',
            'access_token_secret',
            'token_type',
            'refresh_token',
            'expires_in',
            'expires_at',
            'userType',
            'companyId',
            'locationId',
            'userId'
        ];

        $tokens = [];

        foreach ($tokenNames as $name) {
            if ($this->getStoredData($name)) {
                $tokens[$name] = $this->getStoredData($name);
            }
        }

        return $tokens;
    }

    public function apiRequest($url, $method = 'GET', $parameters = [], $headers = [])
    {
        if (strrpos($url, 'http://') !== 0 && strrpos($url, 'https://') !== 0) {
            $url = $this->apiBaseUrl . $url;
        }

        $parameters = array_replace($this->apiRequestParameters, (array)$parameters);
        $headers    = array_replace($this->apiRequestHeaders, (array)$headers);

        $response = $this->httpClient->request(
            $url,
            $method,     // HTTP Request Method. Defaults to GET.
            $parameters, // Request Parameters
            $headers     // Request Headers
        );

        $this->validateApiResponse('Signed API request has returned an error');

        $response = (new Data\Parser())->parse($response);

        return $response;
    }
}