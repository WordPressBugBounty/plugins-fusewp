<?php

namespace Authifly\Provider;

use Authifly\Adapter\OAuth2;
use Authifly\Data\Collection;
use Authifly\Data;
use Authifly\Exception\HttpClientFailureException;
use Authifly\Exception\HttpRequestFailedException;
use Authifly\Exception\InvalidAccessTokenException;
use Authifly\Exception\InvalidAuthorizationStateException;
use Authifly\Exception\RuntimeException;
use Authifly\Storage\WPTransientStorage;

/**
 * Salesforce OAuth2 provider adapter.
 */
class Salesforce extends OAuth2
{
    /**
     * @see https://help.salesforce.com/s/articleView?id=sf.remoteaccess_oauth_endpoints.htm&type=5
     * {@inheritdoc}
     */
    protected $authorizeUrl = 'https://login.salesforce.com/services/oauth2/authorize';

    /**
     * {@inheritdoc}
     */
    protected $accessTokenUrl = 'https://login.salesforce.com/services/oauth2/token';

    /**
     * {@inheritdoc}
     */
    protected $apiDocumentation = 'https://help.salesforce.com/s/articleView?id=sf.remoteaccess_authenticate.htm&language=en_US&type=5';

    /**
     * {@inheritdoc}
     */
    protected $scope = 'api refresh_token';

    /**
     * State is handled (and validated) via the handshake storage since the adapter storage doesn't persist
     * across the redirect. See getAuthorizeUrl() and exchangeCodeForAccessToken().
     */
    protected $supportRequestState = false;

    /**
     * @var WPTransientStorage|null
     */
    protected $handshakeStorage;


    /**
     * {@inheritdoc}
     */
    protected function initialize()
    {
        parent::initialize();

        $apiBaseUrl = $this->config->get('apiBaseUrl');

        if ( ! empty($apiBaseUrl)) $this->apiBaseUrl = $apiBaseUrl;

        $access_token = $this->getStoredData('access_token');

        if (empty($access_token)) $access_token = $this->config->get('access_token');

        if ( ! empty($access_token)) {
            $this->apiRequestHeaders = [
                'Authorization' => 'Bearer ' . $access_token
            ];
        }

        $this->tokenRefreshParameters['client_id']     = $this->clientId;
        $this->tokenRefreshParameters['client_secret'] = $this->clientSecret;
    }

    /**
     * Persistent storage for data that must survive the OAuth redirect round trip (state and PKCE code verifier).
     *
     * The adapter storage passed in by consumers is in-memory (OAuthCredentialStorage) and only holds tokens.
     *
     * @return WPTransientStorage
     * @throws RuntimeException
     */
    protected function getHandshakeStorage()
    {
        if ( ! $this->handshakeStorage) {
            // scoped by callback so concurrent authorizations from different consumers (e.g. MailOptin, FuseWP) don't collide.
            $this->handshakeStorage = new WPTransientStorage('authifly_sf_oauth_' . substr(md5((string)$this->callback), 0, 10));
        }

        return $this->handshakeStorage;
    }

    /**
     * Adds state and PKCE (code_challenge) to the authorization request.
     *
     * Salesforce (External Client Apps and connected apps with "Require PKCE") rejects authorization
     * requests without a code challenge.
     *
     * @see https://help.salesforce.com/s/articleView?id=xcloud.remoteaccess_pkce.htm&type=5
     *
     * {@inheritdoc}
     */
    protected function getAuthorizeUrl($parameters = [])
    {
        $state         = 'HA-' . bin2hex(random_bytes(16));
        $code_verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');

        $storage = $this->getHandshakeStorage();
        $storage->set('state', $state);
        $storage->set('code_verifier', $code_verifier);

        $this->AuthorizeUrlParameters['state']                 = $state;
        $this->AuthorizeUrlParameters['code_challenge']        = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
        $this->AuthorizeUrlParameters['code_challenge_method'] = 'S256';

        return parent::getAuthorizeUrl($parameters);
    }

    /**
     * Validates the returned state and sends the PKCE code_verifier along with the authorization code.
     *
     * {@inheritdoc}
     *
     * @throws InvalidAuthorizationStateException
     */
    protected function exchangeCodeForAccessToken($code)
    {
        $state = filter_input($_SERVER['REQUEST_METHOD'] === 'POST' ? INPUT_POST : INPUT_GET, 'state');

        $storage       = $this->getHandshakeStorage();
        $stored_state  = $storage->get('state');
        $code_verifier = $storage->get('code_verifier');

        // Validate before clearing so a forged callback can't wipe an in-progress authorization.
        if (empty($state) || empty($stored_state) || ! hash_equals((string)$stored_state, (string)$state)) {
            throw new InvalidAuthorizationStateException(
                'The authorization state [state=' . substr(htmlentities((string)$state), 0, 100) . '] '
                . 'of this page is either invalid or has already been consumed.'
            );
        }

        // single use
        $storage->clear();

        $this->tokenExchangeParameters['code_verifier'] = $code_verifier;

        return parent::exchangeCodeForAccessToken($code);
    }

    public function apiRequest($url, $method = 'GET', $parameters = [], $headers = [])
    {
        // refresh tokens if needed
        if ($this->hasAccessTokenExpired() === true) {
            $this->refreshAccessToken();
        }

        if (strrpos($url, 'http://') !== 0 && strrpos($url, 'https://') !== 0) {
            $url = $this->apiBaseUrl . $url;
        }

        //When sending campaigns, Salesforce throws an error if the payload contains an access_token param
        /*if($this->getStoredData('access_token')) {
            $this->apiRequestParameters[$this->accessTokenName] = $this->getStoredData('access_token');
        }*/

        $parameters = array_replace($this->apiRequestParameters, (array)$parameters);
        $headers    = array_replace($this->apiRequestHeaders, (array)$headers);

        $response = $this->httpClient->request(
            $url,
            $method,     // HTTP Request Method. Defaults to GET.
            $parameters, // Request Parameters
            $headers     // Request Headers
        );

        $this->validateApiResponse();

        $response = (new Data\Parser())->parse($response);

        return $response;
    }

    /**
     * @param $response
     *
     * @return Collection
     *
     * @throws InvalidAccessTokenException
     */
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
        $this->storeData('instance_url', $collection->get('instance_url'));

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

    /**
     * {@inheritdoc}
     */
    public function getAccessToken()
    {
        $tokenNames = [
            'access_token',
            'access_token_secret',
            'token_type',
            'refresh_token',
            'expires_in',
            'expires_at',
            'instance_url',
        ];

        $tokens = [];

        foreach ($tokenNames as $name) {
            if ($this->getStoredData($name)) {
                $tokens[$name] = $this->getStoredData($name);
            }
        }

        return $tokens;
    }

    /**
     * @return mixed
     * @throws HttpClientFailureException
     * @throws HttpRequestFailedException
     * @throws InvalidAccessTokenException
     */
    public function getUserInfo()
    {
        return $this->apiRequest(
            'https://login.salesforce.com/services/oauth2/userinfo'
        );
    }

    /**
     * @param $error
     *
     * @return void
     * @throws HttpClientFailureException
     * @throws HttpRequestFailedException
     * @throws InvalidAccessTokenException
     */
    protected function validateApiResponse($error = '')
    {
        $error .= ! empty($error) ? '. ' : '';

        if ($this->httpClient->getResponseClientError()) {
            throw new HttpClientFailureException(
                $error . 'HTTP client error: ' . $this->httpClient->getResponseClientError() . '.'
            );
        }

        // if validateApiResponseHttpCode is set to false, we by pass verification of http status code
        if ( ! $this->validateApiResponseHttpCode) {
            return;
        }

        $status = $this->httpClient->getResponseHttpCode();

        // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/errorcodes.htm
        if (401 === $status) {

            throw new InvalidAccessTokenException(
                $this->httpClient->getResponseBody(),
                $status
            );
        }

        if ($status < 200 || $status > 299) {
            throw new HttpRequestFailedException(
                $this->httpClient->getResponseBody(),
                $status
            );
        }
    }
}
