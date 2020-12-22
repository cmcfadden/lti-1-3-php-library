<?php
namespace LTI;

use Firebase\JWT\JWT;

class LtiServiceConnector {

    const NEXT_PAGE_REGEX = "/^Link:.*<([^>]*)>; ?rel=\"next\"/i";

    private $registration;
    private $access_tokens = [];

    public function __construct(LtiRegistration $registration) {
        $this->registration = $registration;
    }

    public function getAccessToken($scopes) {

        // Don't fetch the same key more than once.
        sort($scopes);
        $scope_key = md5(implode('|', $scopes));
        if (isset($this->access_tokens[$scope_key])) {
            return $this->access_tokens[$scope_key];
        }

        // Build up JWT to exchange for an auth token
        $client_id = $this->registration->getClientId();
        $jwt_claim = [
                "iss" => $client_id,
                "sub" => $client_id,
                "aud" => $this->registration->getAuthServer(),
                "iat" => time() - 5,
                "exp" => time() + 60,
                "jti" => 'lti-service-token' . hash('sha256', random_bytes(64))
        ];

        // Sign the JWT with our private key (given by the platform on registration)
        $jwt = JWT::encode($jwt_claim, $this->registration->getToolPrivateKey(), 'RS256', $this->registration->getKid());

        // Build auth token request headers
        $auth_request = [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $jwt,
            'scope' => implode(' ', $scopes)
        ];

        // Make request to get auth token
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->registration->getAuthTokenUrl());
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($auth_request));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $resp = curl_exec($ch);
        $token_data = json_decode($resp, true);
        curl_close ($ch);

        return $this->access_tokens[$scope_key] = $token_data['access_token'];
    }

    public function makeServiceRequest($scopes, $method, $url, $body = null, $content_type = 'application/json', $accept = 'application/json') {
        $ch = curl_init();
        $headers = [
            'Authorization: Bearer ' . $this->getAccessToken($scopes),
            'Accept:' . $accept,
        ];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, strval($body));
            $headers[] = 'Content-Type: ' . $content_type;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        if (curl_errno($ch)){
            echo 'Request Error:' . curl_error($ch);
        }
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close ($ch);

        $resp_headers = substr($response, 0, $header_size);
        $resp_body = substr($response, $header_size);
        return [
            'headers' => array_filter(explode("\r\n", $resp_headers)),
            'body' => json_decode($resp_body, true),
        ];
    }
}