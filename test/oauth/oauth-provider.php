<?php

ini_set("display_errors", 0);
require __DIR__ . "/vendor/autoload.php";

const OAUTH_ISSUER = "hotcrp-oauth-test";
const COOKIE_NAME = "hotcrp-oauth-test-ck";


use League\OAuth2\Server\AuthorizationServer;
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
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Response;


class ClientEntity implements ClientEntityInterface {
    use EntityTrait, ClientTrait;
    public $client_secret;

    function __construct($x) {
        $this->setIdentifier($x->client_id);
        $this->client_secret = $x->client_secret;
        $this->name = $x->name;
        $this->redirectUri = $x->redirect_uri;
        $this->isConfidential = true;
    }
}

class AccessTokenEntity implements AccessTokenEntityInterface {
    use AccessTokenTrait, TokenEntityTrait, EntityTrait;
}

class AuthCodeEntity implements AuthCodeEntityInterface {
    use EntityTrait, TokenEntityTrait, AuthCodeTrait;
    public $nonce;

    function __construct($nonce) {
        $this->nonce = $nonce;
    }
}

class RefreshTokenEntity implements RefreshTokenEntityInterface {
    use RefreshTokenTrait, EntityTrait;
}

class ScopeEntity implements ScopeEntityInterface {
    use EntityTrait, ScopeTrait;
}

class UserEntity implements UserEntityInterface {
    public $email;
    public $email_verified;
    public $given_name;
    public $family_name;
    public $name;
    public $orcid;
    public $affiliation;
    public $groups;
    public $roles;

    function __construct($x = null) {
        $x = $x ?? (object) ["email" => null];
        $this->email = $x->email;
        $this->email_verified = $x->email_verified ?? null;
        $this->given_name = $x->given_name ?? null;
        $this->family_name = $x->family_name ?? null;
        $this->name = $x->name ?? null;
        $this->orcid = $x->orcid ?? null;
        $this->affiliation = $x->affiliation ?? null;
        $this->groups = $x->groups ?? null;
        $this->roles = $x->roles ?? null;
    }
    public function getIdentifier() {
        return $this->email;
    }
}


class My implements ClientRepositoryInterface, AuthCodeRepositoryInterface, AccessTokenRepositoryInterface {
    public $users = [];
    private $clients = [];
    public $codes = [];
    public $tokens = [];
    public $interactions = [];
    public $nonce;
    private $last_auth;

    static public $main;


    function getClientEntity($client_id) {
        return $this->clients[$client_id] ?? null;
    }
    function validateClient($client_id, $clientSecret, $grantType) {
        $c = $this->clients[$client_id] ?? null;
        return $c && ($c->client_secret === null || $c->client_secret === $clientSecret);
    }

    function persistNewAuthCode(AuthCodeEntityInterface $ac) {
        $this->codes[] = (object) [
            "code" => $ac->getIdentifier(),
            "sub" => $ac->getUserIdentifier(),
            "aud" => $ac->getClient()->getIdentifier(),
            "iat" => time(),
            "exp" => $ac->getExpiryDateTime()->getTimestamp(),
            "nonce" => $ac->nonce
        ];
    }
    function getAuthCode($codeId) {
        foreach ($this->codes as $c) {
            if ($c->code === $codeId)
                return $c;
        }
        return null;
    }
    function revokeAuthCode($codeId) {
        if (($c = $this->getAuthCode($codeId)))
            $c->revoked = true;
    }
    function isAuthCodeRevoked($codeId) {
        $this->last_auth = $this->getAuthCode($codeId);
        return $this->last_auth && ($this->last_auth->revoked ?? false);
    }
    function getNewAuthCode() {
        return new AuthCodeEntity($this->nonce);
    }
    function getLastAuthCode() {
        return $this->last_auth;
    }

    function persistNewAccessToken(AccessTokenEntityInterface $at) {
        $this->tokens[] = (object) [
            "token" => $at->getIdentifier(),
            "sub" => $at->getUserIdentifier(),
            "aud" => $at->getClient()->getIdentifier(),
            "iat" => time(),
            "exp" => $at->getExpiryDateTime()->getTimestamp()
        ];
    }
    function getAccessToken($tokenId) {
        foreach ($this->tokens as $t) {
            if ($t->token === $t)
                return $t;
        }
        return null;
    }
    function revokeAccessToken($tokenId) {
        if (($t = $this->getAccessToken($tokenId)))
            $t->revoked = true;
    }
    function isAccessTokenRevoked($tokenId) {
        return ($t = $this->getAccessToken($tokenId)) && ($t->revoked ?? false);
    }
    function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null) {
        $accessToken = new AccessTokenEntity();
        $accessToken->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }
        $accessToken->setUserIdentifier($userIdentifier);
        return $accessToken;
    }

    function user_by_email($email) {
        foreach ($this->users ?? [] as $u) {
            if (strcasecmp($email, $u->email) === 0)
                return $u;
        }
        return null;
    }
    function record_cookie_authorization($cookie, $email) {
        $iat = time();
        $this->interactions[] = (object) ["cookie" => $cookie, "sub" => $email, "iat" => $iat, "exp" => $iat + 10 * 86400];
    }

    static function load_main() {
        self::$main = new My;
        if (file_exists(__DIR__ . "/localdb.json")) {
            $db = json_decode(file_get_contents(__DIR__ . "/localdb.json"));
        } else {
            $db = json_decode(file_get_contents(__DIR__ . "/db.json"));
        }
        foreach ($db->users ?? [] as $u) {
            self::$main->users[] = new UserEntity($u);
        }
        foreach ($db->clients ?? [] as $c) {
            self::$main->clients[$c->client_id] = new ClientEntity($c);
        }
        $aj = json_decode(file_get_contents(__DIR__ . "/auths.json") ? : "null");
        if (is_object($aj) && is_array($aj->codes ?? null)) {
            self::$main->codes = $aj->codes;
        }
        if (is_object($aj) && is_array($aj->tokens ?? null)) {
            self::$main->tokens = $aj->tokens;
        }
        if (is_object($aj) && is_array($aj->interactions ?? null)) {
            self::$main->interactions = $aj->interactions;
        }
    }

    static function write_auths() {
        $now = time();
        $codes = $tokens = $interactions = [];
        foreach (self::$main->codes as $x) {
            if ($x->exp >= $now)
                $codes[] = $x;
        }
        foreach (self::$main->tokens as $x) {
            if ($x->exp >= $now)
                $tokens[] = $x;
        }
        foreach (self::$main->interactions as $x) {
            if ($x->exp >= $now)
                $interactions[] = $x;
        }
        file_put_contents(__DIR__ . "/auths.json", json_encode(["codes" => $codes, "tokens" => $tokens, "interactions" => $interactions], JSON_PRETTY_PRINT) . "\n");
    }
}

My::load_main();


class BlankScopeRepository implements ScopeRepositoryInterface {
    public function getScopeEntityByIdentifier($scope_id) {
        if ($scope_id === "openid" || $scope_id === "profile" || $scope_id === "email") {
            $scope = new ScopeEntity;
            $scope->setIdentifier($scope_id);
            return $scope;
        }
        return null;
    }
    public function finalizeScopes(array $scopes, $grantType, ClientEntityInterface $clientEntity, $userIdentifier = null) {
        return $scopes;
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

/** @param string $text
 * @return string */
function base64url_encode($text) {
    return rtrim(str_replace(["+", "/"], ["-", "_"], base64_encode($text)), "=");
}

/** @param object $payload
 * @return string */
function make_plaintext_jwt($payload) {
    $jose = '{"alg":"none","typ":"JWT"}';
    return base64url_encode($jose) . "." . base64url_encode(json_encode($payload)) . ".";
}

class IdentityBearerTokenResponse extends BearerTokenResponse {
    private My $my;
    private ServerRequestInterface $req;
    function __construct($my, $req) {
        $this->my = $my;
        $this->req = $req;
    }
    function getExtraParams(AccessTokenEntityInterface $at) {
        $idt = [
            "iss" => OAUTH_ISSUER,
            "aud" => $at->getClient()->getIdentifier(),
            "exp" => $at->getExpiryDateTime()->getTimestamp()
        ];
        if (($u = $this->my->user_by_email($at->getUserIdentifier()))) {
            foreach (["email", "email_verified", "name", "given_name", "family_name", "orcid", "affiliation", "groups", "roles"] as $k) {
                if (isset($u->$k))
                    $idt[$k] = $u->$k;
            }
        }
        if (($auth = $this->my->getLastAuthCode()) && $auth->nonce) {
            $idt["nonce"] = $auth->nonce;
        }
        return ["id_token" => make_plaintext_jwt($idt)];
    }
}


function create_server(ServerRequestInterface $req) {
    $privateKey = __DIR__ . "/private.key";
    $encryptionKey = "Op0qf6EYNPNx5L4Bwdl/WUYalZlksd4gbeHMmsjfaaY";

    // Setup the authorization server
    $server = new AuthorizationServer(
        My::$main,
        My::$main,
        new BlankScopeRepository,
        $privateKey,
        $encryptionKey,
        new IdentityBearerTokenResponse(My::$main, $req)
    );

    global $grant;
    $grant = new \League\OAuth2\Server\Grant\AuthCodeGrant(
         My::$main,
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

function get_cookie(ServerRequestInterface $req) {
    $cookie = ($req->getCookieParams())[COOKIE_NAME] ?? null;
    if (is_string($cookie) && ctype_xdigit($cookie) && strlen($cookie) > 10) {
        return $cookie;
    }
    return null;
}

function http_date($t) {
    return gmdate("D, d M Y H:i:s", $t) . " GMT";
}

function ensure_cookie(ServerRequestInterface $req, Response $res) {
    $value = bin2hex(random_bytes(16));
    $exp = http_date(time() + 86400 * 366);
    return $res->withHeader("Set-Cookie", COOKIE_NAME . "={$value}; Expires={$exp}; HttpOnly; SameSite=Lax");
}

function redirection(Response $res, $redirectUri, $param) {
    $redirectUri .= (str_contains($redirectUri, "?") ? "&" : "?") . http_build_query($param);
    return $res->withStatus(302)->withHeader("Location", $redirectUri);
}

function handle_auth_req(ServerRequestInterface $req, Response $res, AuthorizationServer $server) {
    $authreq = $server->validateAuthorizationRequest($req);

    if (!($cookie = get_cookie($req))) {
        $res = ensure_cookie($req, $res);
    }

    $prompt = trim(($req->getQueryParams())["prompt"] ?? "");
    $max_age = ($req->getQueryParams())["max_age"] ?? null;
    $id_token_hint = ($req->getQueryParams())["id_token_hint"] ?? null;
    $login_hint = ($req->getQueryParams())["login_hint"] ?? null;
    $state = ($req->getQueryParams())["state"] ?? null;

    if (($prompt === "none" && !$id_token_hint)
        || ($max_age && !ctype_digit($max_age))) {
        return redirection($res, $authreq->getRedirectUri(), ["state" => $state, "error" => "invalid_request"]);
    }

    if ($prompt !== "login" && $cookie && $login_hint) {
        $autht = 0;
        foreach (My::$main->interactions as $ix) {
            if ($ix->cookie === $cookie
                && strcasecmp($ix->sub, $login_hint) === 0) {
                $autht = max($autht, $ix->iat);
            }
        }
        if ($autht && (!$max_age || $autht + intval($max_age) >= time())) {
            $authreq->setUser(My::$main->user_by_email($login_hint));
            $authreq->setAuthorizationApproved(true);
            My::$main->nonce = ($req->getQueryParams())["nonce"] ?? null;
            return $server->completeAuthorizationRequest($authreq, $res);
        }
    }

    if ($prompt === "none") {
        return redirection($res, $authreq->getRedirectUri(), ["state" => $state, "error" => "login_required"]);
    }

    ob_start();
    echo '<html><head><title>Authorization test</title></head>',
        '<style>html { background: black } .buttons { margin: 3rem auto 0; width: fit-content; min-width: 3em; display: grid; font-size: 2rem; gap: 0.5rem } button { font-family: inherit; font-size: 100%; border-radius: 5px } button.success { background-color: #00cf00 }</style>',
        '<body><form action="/submit?',
        htmlspecialchars($req->getUri()->getQuery()), '" method="POST">',
        '<div class="buttons">';
    foreach (My::$main->users as $u) {
        echo '<button type="submit" name="allow" value="', htmlspecialchars($u->email), '" class="success">Allow ', htmlspecialchars($u->email), '</button>';
    }
    echo '<button type="submit" name="deny" value="1" style="background-color: #cf0000">Deny</button>',
        '</div></form></body></html>';
    return $res->withStatus(200)->withBody(Stream::create(ob_get_clean()));
}

function handle_req(ServerRequestInterface $req) {
    $res = new \Nyholm\Psr7\Response;
    $server = create_server($req);
    $uri = $req->getUri();
    try {
        if ($uri->getPath() === "/auth") {
            return handle_auth_req($req, $res, $server);
        } else if ($uri->getPath() === "/submit") {
            $authreq = $server->validateAuthorizationRequest($req);
            $email = ($req->getParsedBody())["allow"] ?? null;
            if ($email && ($u = My::$main->user_by_email($email))) {
                $authreq->setUser($u);
                $authreq->setAuthorizationApproved(true);
                if (($cookie = get_cookie($req))) {
                    My::$main->record_cookie_authorization($cookie, $u->email);
                }
            } else {
                $authreq->setUser(new UserEntity);
                $authreq->setAuthorizationApproved(false);
            }
            My::$main->nonce = ($req->getQueryParams())["nonce"] ?? null;
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

My::write_auths();
