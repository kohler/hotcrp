<?php

ini_set("display_errors", 0);

spl_autoload_register(function ($class_name) {
    $f = str_replace("\\", "/", $class_name);
    $p0 = strpos($f, "/");
    $p1 = $p2 = strpos($f, "/", $p0 + 1);
    $x = strtolower(substr($f, 0, $p1));
    if ($x === "nyholm/psr7server") {
        $x = "nyholm/psr7-server";
    } else if ($x === "defuse/crypto") {
        $x = "defuse/php-encryption";
    }
    $d = __DIR__ . "/vendor/{$x}/src";
    if (!is_dir($d)) {
        $p2 = strpos($f, "/", $p1 + 1);
        $x .= "-" . strtolower(substr($f, $p1 + 1, $p2 - $p1 - 1));
        if ($x === "psr/http-message" && strpos($f, "Factory") !== false) {
            $x = "psr/http-factory";
        }
        $d = __DIR__ . "/vendor/{$x}/src";
    }
    require_once($d . substr($f, $p2) . ".php");
});


use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Nyholm\Psr7\Stream;


class ClientEntity implements ClientEntityInterface {
    use EntityTrait, ClientTrait;
    public $client_secret;

    function __construct($client_id, $client_secret, $name, $redirect_uri) {
        $this->setIdentifier($client_id);
        $this->client_secret = $client_secret;
        $this->name = $name;
        $this->redirectUri = $redirect_uri;
        $this->isConfidential = true;
    }
}

class AccessTokenEntity implements AccessTokenEntityInterface {
    use AccessTokenTrait, TokenEntityTrait, EntityTrait;
}

class AuthCodeEntity implements AuthCodeEntityInterface {
    use EntityTrait, TokenEntityTrait, AuthCodeTrait;
}

class RefreshTokenEntity implements RefreshTokenEntityInterface {
    use RefreshTokenTrait, EntityTrait;
}

class ScopeEntity implements ScopeEntityInterface {
    use EntityTrait, ScopeTrait;
}

class UserEntity implements UserEntityInterface {
    public $id;
    public function getIdentifier() {
        return $this->id;
    }
}



class BlankClientRepository implements ClientRepositoryInterface {
    private $clients = [];
    function add(ClientEntity $client) {
        $this->clients[$client->getIdentifier()] = $client;
    }
    public function getClientEntity($client_id) {
        return $this->clients[$client_id] ?? null;
    }
    public function validateClient($client_id, $clientSecret, $grantType) {
        error_log("LOOKING FOR $client_id");
        $c = $this->clients[$client_id] ?? null;
        return $c
            && ($c->client_secret === null || $c->client_secret === $clientSecret);
    }
}

class BlankScopeRepository implements ScopeRepositoryInterface {
    public function getScopeEntityByIdentifier($scope_id) {
        if ($scope_id === "openid" || $scope_id === "profile" || $scope_id === "email") {
            $scope = new ScopeEntity;
            $scope->setIdentifier($scope_id);
            return $scope;
        } else {
            return null;
        }
    }
    public function finalizeScopes(array $scopes, $grantType, ClientEntityInterface $clientEntity, $userIdentifier = null) {
        return $scopes;
    }
}

class BlankAccessTokenRepository implements AccessTokenRepositoryInterface {
    private $revoked_tokens = [];
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity) {
    }
    public function revokeAccessToken($tokenId) {
        $this->revoked_tokens[$tokenId] = true;
    }
    public function isAccessTokenRevoked($tokenId) {
        return isset($this->revoked_tokens[$tokenId]);
    }
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null) {
        $accessToken = new AccessTokenEntity();
        $accessToken->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }
        $accessToken->setUserIdentifier($userIdentifier);
        return $accessToken;
    }
}

class BlankAuthCodeRepository implements AuthCodeRepositoryInterface {
    private $revoked_codes = [];
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity) {
    }
    public function revokeAuthCode($codeId) {
        $this->revoked_codes[$codeId] = true;
    }
    public function isAuthCodeRevoked($codeId) {
        return isset($this->revoked_codes[$codeId]);
    }
    public function getNewAuthCode() {
        return new AuthCodeEntity();
    }
}

class BlankRefreshTokenRepository implements RefreshTokenRepositoryInterface {
    private $revoked_tokens = [];
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity) {
    }
    public function revokeRefreshToken($tokenId) {
        $this->revoked_tokens[$tokenId] = true;
    }
    public function isRefreshTokenRevoked($tokenId) {
        return isset($this->revoked_tokens[$tokenId]);
    }
    public function getNewRefreshToken() {
        return new RefreshTokenEntity();
    }
}

/** @param object $payload
 * @return string */
function make_plaintext_jwt($payload) {
    $jose = '{"alg":"none","typ":"JWT"}';
    return base64url_encode($jose) . "." . base64url_encode(json_encode($payload)) . ".";
}

/** @param string $text
 * @return string */
function base64url_encode($text) {
    return rtrim(str_replace(["+", "/"], ["-", "_"], base64_encode($text)), "=");
}

class IdentityBearerTokenResponse extends BearerTokenResponse {
    function getExtraParams(AccessTokenEntityInterface $accessToken) {
        return [
            "id_token" => make_plaintext_jwt(["email" => "farting1111@_.com", "email_verified" => true, "name" => "Farting Man", "given_name" => "Farting", "family_name" => "Man"])
        ];
    }
}


function create_server() {
    $client_repository = new BlankClientRepository;
    $client_repository->add(new ClientEntity(
        "hotcrp-oauth-test",
        "Dudfield",
        "HotCRP OAuth Test",
        "http://localhost:8080/testconf/oauth"
    ));
    $privateKey = __DIR__ . "/private.key";
    $encryptionKey = "Op0qf6EYNPNx5L4Bwdl/WUYalZlksd4gbeHMmsjfaaY";

    // Setup the authorization server
    $server = new \League\OAuth2\Server\AuthorizationServer(
        $client_repository,
        new BlankAccessTokenRepository,
        new BlankScopeRepository,
        $privateKey,
        $encryptionKey,
        new IdentityBearerTokenResponse
    );

    global $grant;
    $grant = new \League\OAuth2\Server\Grant\AuthCodeGrant(
         new BlankAuthCodeRepository,
         new BlankRefreshTokenRepository,
         new \DateInterval('PT10M') // authorization codes will expire after 10 minutes
    );
    $grant->setRefreshTokenTTL(new \DateInterval('P1M')); // refresh tokens will expire after 1 month

    // Enable the authentication code grant on the server
    $server->enableGrantType(
        $grant,
        new \DateInterval('PT1H') // access tokens will expire after 1 hour
    );

    return $server;
}

function handle_req($req) {
    $res = new \Nyholm\Psr7\Response;
    $server = create_server();
    $uri = $req->getUri();
    try {
        if ($uri->getPath() === "/auth") {
            $authreq = $server->validateAuthorizationRequest($req);
            ob_start();
            echo '<html><head><title>Authorization test</title></head><body><form action="/submit?',
                htmlspecialchars($uri->getQuery()), '" method="POST">';
            echo '<button type="submit" name="allow" value="1">Allow</button><button type="submit" name="deny" value="1">Deny</button></form></body></html>';
            return $res->withStatus(200)->withBody(Stream::create(ob_get_clean()));
        } else if ($uri->getPath() === "/submit") {
            $authreq = $server->validateAuthorizationRequest($req);
            $authreq->setUser(new UserEntity);
            if (isset(($req->getParsedBody())["allow"])) {
                $authreq->setAuthorizationApproved(true);
            } else {
                $authreq->setAuthorizationApproved(false);
            }
            return $server->completeAuthorizationRequest($authreq, $res);
        } else if ($uri->getPath() === "/token" && $req->getMethod() === "POST") {
            return $server->respondToAccessTokenRequest($req, $res);
        } else {
            return $res->withStatus(500)->withBody(Stream::create("bad request " . htmlspecialchars($uri->getPath())));
        }
    } catch (OAuthServerException $exception) {
        return $exception->generateHttpResponse($res);
    } catch (\Exception $exception) {
        $body = Stream::create(fopen('php://temp', 'r+'));
        $body->write($exception->getMessage() . " (" . get_class($exception) . ")");
        return $res->withStatus(500)->withBody($body);        
    }
}

function handle_global() {
    $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    $creator = new \Nyholm\Psr7Server\ServerRequestCreator(
        $psr17Factory, // ServerRequestFactory
        $psr17Factory, // UriFactory
        $psr17Factory, // UploadedFileFactory
        $psr17Factory  // StreamFactory
    );
    $req = $creator->fromGlobals();
    $res = handle_req($req);
    http_response_code($res->getStatusCode());
    foreach ($res->getHeaders() as $n => $v) {
        header($n . ": " . join(", ", $v));
    }
    echo $res->getBody();
}

handle_global();
