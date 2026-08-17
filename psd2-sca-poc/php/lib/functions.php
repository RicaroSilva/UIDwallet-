<?php
// lib/functions.php
// Shared logic for the LusoPay Checkout service (PHP port). Conceptually
// mirrors the three PaSO roles from the original Node.js prototype:
//   - build_transaction_data() / build_holder_binding_proof()  -> Relying Party (merchant)
//   - authorize_payment()                                       -> payment scheme (bank lookup) + Authorizing Party (validation)
//   - notify_cyclos()                                           -> the authorizing party's notification to the LusoPay/Cyclos backend
// Unlike the Node version (separate long-running processes per role), this
// is a single stateless PHP app: each request is independent, so the
// pending transaction_data + holder_binding_proof travel back and forth
// through the browser between the "start" and "confirm" steps instead of
// living in server memory.

declare(strict_types=1);

const CYCLOS_ENDPOINT = 'https://dev.lusopay.com:8444/web_dev/run/adduidpayment';
const BANK_REGISTRY_PATH = __DIR__ . '/../bank-registry.json';

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function escape_html(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function hash_transaction_data(array $transactionData): string
{
    return hash('sha256', json_encode($transactionData, JSON_UNESCAPED_SLASHES));
}

function build_transaction_data(string $merchant, string $amount, string $currency): array
{
    return [
        'type' => 'urn:eudi:sca:payment:1',
        'transaction_id' => 'txn_' . bin2hex(random_bytes(16)),
        'payee' => $merchant,
        'amount' => number_format((float) $amount, 2, '.', ''),
        'currency' => $currency,
    ];
}

function build_holder_binding_proof(array $transactionData): array
{
    return [
        'jti' => bin2hex(random_bytes(16)),
        'amr' => ['knowledge_pin', 'inherence_biometric'],
        'transaction_data_hash' => hash_transaction_data($transactionData),
    ];
}

function load_bank_registry(): array
{
    $json = file_get_contents(BANK_REGISTRY_PATH);
    return json_decode($json, true) ?? [];
}

/**
 * Re-validates a transaction_data + holder_binding_proof pair (as if
 * received from the wallet) and, if valid, "authorizes" it: looks up the
 * buyer's bank and returns the outcome. This folds together what the
 * Node.js prototype split across lusopay-router.js (bank lookup) and
 * authorizing-party.js (field validation + dynamic linking check).
 */
function authorize_payment(array $transactionData, array $holderBindingProof, string $publicId): array
{
    $errors = [];

    foreach (['type', 'transaction_id', 'payee', 'amount', 'currency'] as $field) {
        if (empty($transactionData[$field])) {
            $errors[] = "missing transaction_data.{$field}";
        }
    }

    $amr = $holderBindingProof['amr'] ?? null;
    if (empty($holderBindingProof['jti'])) {
        $errors[] = 'missing holder_binding_proof.jti';
    }
    if (!is_array($amr) || count($amr) < 2) {
        $errors[] = 'holder_binding_proof.amr must list at least two independent SCA factors';
    }
    if (empty($holderBindingProof['transaction_data_hash'])) {
        $errors[] = 'missing holder_binding_proof.transaction_data_hash';
    }

    if ($errors !== []) {
        return ['status' => 'REJECTED', 'errors' => $errors];
    }

    $recomputedHash = hash_transaction_data($transactionData);
    if ($recomputedHash !== $holderBindingProof['transaction_data_hash']) {
        return [
            'status' => 'REJECTED',
            'errors' => ["dynamic linking check failed: transaction_data_hash does not match transaction_data (expected {$recomputedHash}, got {$holderBindingProof['transaction_data_hash']})"],
        ];
    }

    $bankRegistry = load_bank_registry();
    $bankEntry = $bankRegistry[$publicId] ?? null;
    if ($bankEntry === null) {
        return ['status' => 'REJECTED', 'errors' => ["unknown user_id \"{$publicId}\""]];
    }

    return [
        'status' => 'AUTHORIZED',
        'transaction_id' => $transactionData['transaction_id'],
        'authorized_at' => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        'bank' => $bankEntry['bank'],
    ];
}

/**
 * Best-effort notification to the LusoPay/Cyclos backend. Failures are
 * logged but never change the authorization decision already made.
 */
function notify_cyclos(string $publicId, array $transactionData): void
{
    $payload = [
        'publicId' => $publicId,
        'amount' => $transactionData['amount'],
        'currency' => $transactionData['currency'],
        'description' => 'Payment to ' . $transactionData['payee'],
        'transactionId' => $transactionData['transaction_id'],
    ];

    $ch = curl_init(CYCLOS_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 4,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        error_log("[LUSOPAY-CHECKOUT] failed to reach Cyclos backend: {$error}");
        return;
    }
    error_log("[LUSOPAY-CHECKOUT] Cyclos backend responded with HTTP {$status}: {$response}");
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Loads the EC P-256 signing key used for request objects. This key is NOT
 * trust-anchored (no x509 certificate) -- it exists only so requests
 * delivered via request_uri are structurally valid, signed JWTs, matching
 * what RFC 9101 (JAR) requires, for the "redirect_uri" client_id scheme
 * where the wallet does not need to verify the signer's identity, only
 * that the object is well-formed. Because nothing depends on this specific
 * key's secrecy, it's a fixed key committed to the repo (keys/signing-key.pem)
 * rather than generated at runtime: openssl_pkey_new() (key GENERATION)
 * fails with "system library: No such process" on at least one deployment
 * target's OpenSSL build, while loading an existing key and signing with
 * it both work fine there. Generate a replacement with:
 *   openssl ecparam -name prime256v1 -genkey -noout -out keys/signing-key.pem
 */
function get_signing_key(): array
{
    $keyPath = __DIR__ . '/../keys/signing-key.pem';

    if (!is_file($keyPath)) {
        throw new RuntimeException(
            "signing key not found at {$keyPath} -- generate one with: " .
            'openssl ecparam -name prime256v1 -genkey -noout -out keys/signing-key.pem'
        );
    }

    $privateKey = openssl_pkey_get_private(file_get_contents($keyPath));
    if ($privateKey === false) {
        throw new RuntimeException('openssl_pkey_get_private failed: ' . openssl_error_string());
    }

    $details = openssl_pkey_get_details($privateKey);

    return [
        'private_key' => $privateKey,
        'jwk' => [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => base64url_encode($details['ec']['x']),
            'y' => base64url_encode($details['ec']['y']),
        ],
    ];
}

function der_ecdsa_signature_to_jose(string $der, int $componentLength = 32): string
{
    $offset = 1; // skip SEQUENCE tag (0x30)
    $offset += (ord($der[1]) & 0x80) ? 1 + (ord($der[1]) & 0x7f) : 1; // skip sequence length

    $readInteger = function (string $der, int &$offset) {
        $offset++; // skip INTEGER tag (0x02)
        $len = ord($der[$offset]);
        $offset++;
        $value = ltrim(substr($der, $offset, $len), "\x00");
        $offset += $len;
        return $value;
    };

    $r = $readInteger($der, $offset);
    $s = $readInteger($der, $offset);

    return str_pad($r, $componentLength, "\x00", STR_PAD_LEFT) . str_pad($s, $componentLength, "\x00", STR_PAD_LEFT);
}

/**
 * Signs a JWT with ES256, embedding the public key as a "jwk" header
 * parameter so the wallet can verify the signature is internally valid
 * without needing a pre-registered or trust-anchored key.
 */
function sign_request_object_jwt(array $payload): string
{
    $signingKey = get_signing_key();

    $header = [
        'alg' => 'ES256',
        'typ' => 'oauth-authz-req+jwt',
        'jwk' => $signingKey['jwk'],
    ];

    $signingInput = base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.' . base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));

    openssl_sign($signingInput, $derSignature, $signingKey['private_key'], OPENSSL_ALGO_SHA256);
    $joseSignature = der_ecdsa_signature_to_jose($derSignature);

    return $signingInput . '.' . base64url_encode($joseSignature);
}

function qr_code_data_uri(string $payload): string
{
    $qrCode = new \Endroid\QrCode\QrCode($payload);
    $qrCode->setMargin(1);
    $writer = new \Endroid\QrCode\Writer\PngWriter();
    return $writer->write($qrCode)->getDataUri();
}

// --- Issuance (OpenID4VCI) -------------------------------------------
// The mirror image of the checkout flow above: instead of asking a wallet
// to present an existing credential, this issues a brand new one (a
// "LusoPay Card" carrying a name + lusopay_id) as a real SD-JWT VC, using
// the pre-authorized_code flow (no separate login/PIN step).
//
// The issuer identity (LUSOPAY_ISSUER_URL) is deliberately the bare domain
// root, not a subpath: OpenID4VCI requires the issuer metadata to be
// served at /.well-known/openid-credential-issuer, inserted BEFORE any
// path segment of the issuer URL. Keeping the issuer at the root avoids
// needing an IIS rewrite rule for that insertion.

const LUSOPAY_ISSUER_URL = 'https://pay.lusopay.com';
const LUSOPAY_CREDENTIAL_VCT = 'urn:lusopay:card:1';
const ISSUANCE_STORE_DIR = __DIR__ . '/../issuance-store';

function base64url_decode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder !== 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function issuance_store_path(string $key): string
{
    if (!is_dir(ISSUANCE_STORE_DIR)) {
        mkdir(ISSUANCE_STORE_DIR, 0700, true);
    }
    // $key is always our own generated random token/code -- safe as a filename.
    return ISSUANCE_STORE_DIR . '/' . $key . '.json';
}

/**
 * Starts a new issuance session for the given claims and returns the
 * pre-authorized_code to embed in the credential offer. Also seeds a
 * PENDING status row under that same code -- unlike the code/token rows
 * (deleted once consumed), this one survives so issue-test.php can poll
 * it and find out once the wallet has actually accepted the credential.
 */
function create_issuance_session(array $claims): string
{
    $code = bin2hex(random_bytes(16));
    file_put_contents(issuance_store_path("code-{$code}"), json_encode(['claims' => $claims]));
    mark_issuance_status($code, 'PENDING');
    return $code;
}

/**
 * Exchanges a pre-authorized_code for an access_token + c_nonce, per the
 * OpenID4VCI pre-authorized_code token grant. Single-use: the code is
 * consumed and replaced by a token-keyed session. The original code
 * travels along inside the token session so api/credential.php can later
 * mark that code's status ISSUED once it actually builds the credential.
 */
function exchange_pre_authorized_code(string $code): ?array
{
    $path = issuance_store_path("code-{$code}");
    if (!is_file($path)) {
        return null;
    }
    $session = json_decode(file_get_contents($path), true);
    unlink($path);

    $accessToken = bin2hex(random_bytes(24));
    $cNonce = bin2hex(random_bytes(16));
    file_put_contents(issuance_store_path("token-{$accessToken}"), json_encode([
        'claims' => $session['claims'],
        'c_nonce' => $cNonce,
        'issuance_code' => $code,
    ]));

    return ['access_token' => $accessToken, 'c_nonce' => $cNonce];
}

function get_issuance_session_by_access_token(string $accessToken): ?array
{
    $path = issuance_store_path("token-{$accessToken}");
    if (!is_file($path)) {
        return null;
    }
    return json_decode(file_get_contents($path), true);
}

function consume_issuance_session(string $accessToken): void
{
    @unlink(issuance_store_path("token-{$accessToken}"));
}

function mark_issuance_status(string $code, string $status): void
{
    file_put_contents(issuance_store_path("status-{$code}"), json_encode(['status' => $status]));
}

function get_issuance_status(string $code): string
{
    $path = issuance_store_path("status-{$code}");
    if (!is_file($path)) {
        return 'PENDING';
    }
    $data = json_decode(file_get_contents($path), true);
    return $data['status'] ?? 'PENDING';
}

/**
 * Converts a P-256 JWK (x, y coordinates) into a PEM public key, by
 * prefixing the raw EC point with the fixed DER header for a P-256
 * SubjectPublicKeyInfo structure. PHP has no direct "import raw EC point"
 * API, so this is the standard workaround.
 */
function jwk_to_pem(array $jwk): string
{
    $x = base64url_decode($jwk['x']);
    $y = base64url_decode($jwk['y']);
    $derPrefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der = $derPrefix . "\x04" . $x . $y;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function jose_ecdsa_signature_to_der(string $jose, int $componentLength = 32): string
{
    $encodeInteger = function (string $bytes): string {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . chr(strlen($bytes)) . $bytes;
    };

    $rEnc = $encodeInteger(substr($jose, 0, $componentLength));
    $sEnc = $encodeInteger(substr($jose, $componentLength, $componentLength));
    $seqBody = $rEnc . $sEnc;

    return "\x30" . chr(strlen($seqBody)) . $seqBody;
}

/**
 * Verifies a compact JWT's ES256 signature against a P-256 JWK, e.g. to
 * check a wallet's key-possession "proof" JWT is genuinely signed by the
 * key it claims (embedded in its own header as "jwk").
 */
function verify_es256_jwt(string $jwt, array $jwk): bool
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return false;
    }
    [$headerB64, $payloadB64, $sigB64] = $parts;

    $publicKey = openssl_pkey_get_public(jwk_to_pem($jwk));
    if ($publicKey === false) {
        return false;
    }

    $signingInput = "{$headerB64}.{$payloadB64}";
    $derSignature = jose_ecdsa_signature_to_der(base64url_decode($sigB64));

    return openssl_verify($signingInput, $derSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
}

/**
 * did:jwk is self-certifying -- the DID is just the base64url-encoded JWK
 * itself, so any resolver can decode it locally with no network lookup.
 *
 * The wallet's SD-JWT VC verifier picks its trust method from the
 * payload's "iss" claim, not from the header's "kid" -- putting a
 * did:jwk only in "kid" (with "iss" left as an https URL) still failed
 * with "Unsupported signing method for SD-JWT VC. Only did and x5c are
 * supported at the moment.", confirmed by trying exactly that. So "iss"
 * itself must be the did:jwk; "kid" is that same DID plus a "#0" key
 * fragment.
 */
function issuer_did_jwk(array $jwk): string
{
    return 'did:jwk:' . base64url_encode(json_encode($jwk, JSON_UNESCAPED_SLASHES));
}

/**
 * Builds a signed SD-JWT VC (issuer-signed JWT + selectively disclosable
 * claims), bound to the holder's key via "cnf.jwk" so a later presentation
 * of this credential must be accompanied by a Key Binding JWT proving
 * possession of that same key.
 */
function build_sd_jwt_vc(string $vct, array $disclosableClaims, array $holderJwk): string
{
    $disclosures = [];
    $sdHashes = [];
    foreach ($disclosableClaims as $name => $value) {
        $salt = base64url_encode(random_bytes(16));
        $disclosureB64 = base64url_encode(json_encode([$salt, $name, $value], JSON_UNESCAPED_SLASHES));
        $disclosures[] = $disclosureB64;
        $sdHashes[] = base64url_encode(hash('sha256', $disclosureB64, true));
    }

    $signingKey = get_signing_key();
    $issuerDid = issuer_did_jwk($signingKey['jwk']);

    $now = time();
    $payload = [
        'iss' => $issuerDid,
        'vct' => $vct,
        'iat' => $now,
        'exp' => $now + 31536000,
        'cnf' => ['jwk' => $holderJwk],
        '_sd' => $sdHashes,
        '_sd_alg' => 'sha-256',
    ];

    $header = ['alg' => 'ES256', 'typ' => 'dc+sd-jwt', 'kid' => $issuerDid . '#0'];
    $signingInput = base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.' . base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));

    openssl_sign($signingInput, $derSignature, $signingKey['private_key'], OPENSSL_ALGO_SHA256);
    $jws = $signingInput . '.' . base64url_encode(der_ecdsa_signature_to_jose($derSignature));

    return $jws . '~' . implode('~', $disclosures) . '~';
}

// --- Presentation of the LusoPay Card (OpenID4VP) ---------------------
// The mirror image of build_sd_jwt_vc() above: given the compact
// presentation string a wallet posts back for a "LusoPay Card" request
// (issuer-signed JWT + disclosures + an optional Key Binding JWT, per the
// SD-JWT spec's "~"-joined compact serialization), pull out the
// disclosed claims (name, lusopay_id) so a caller can act on them.

const PRESENTATION_STORE_DIR = __DIR__ . '/../presentation-store';

/**
 * Splits a compact SD-JWT VC presentation into its issuer-signed JWT,
 * disclosed claims, and (if present) Key Binding JWT. The Key Binding JWT
 * -- proof the presenter holds the credential's bound key -- is only
 * distinguished from a disclosure by shape: it's the trailing segment and,
 * unlike a disclosure (a base64url-encoded JSON array), is itself a
 * compact JWT (three "."-separated parts).
 */
function parse_sd_jwt_vc_presentation(string $presentation): array
{
    $segments = explode('~', $presentation);
    $issuerJwt = array_shift($segments);

    $kbJwt = null;
    $last = end($segments);
    if ($last !== false && $last !== '' && count(explode('.', $last)) === 3) {
        $kbJwt = array_pop($segments);
    }

    $claims = [];
    foreach ($segments as $disclosureB64) {
        if ($disclosureB64 === '') {
            continue;
        }
        $decoded = json_decode(base64url_decode($disclosureB64), true);
        if (is_array($decoded) && count($decoded) === 3) {
            [, $name, $value] = $decoded;
            $claims[$name] = $value;
        }
    }

    [$headerB64, $payloadB64] = explode('.', $issuerJwt);

    return [
        'issuer_jwt' => $issuerJwt,
        'kb_jwt' => $kbJwt,
        'header' => json_decode(base64url_decode($headerB64), true),
        'payload' => json_decode(base64url_decode($payloadB64), true),
        'claims' => $claims,
    ];
}

function presentation_store_path(string $session): string
{
    if (!is_dir(PRESENTATION_STORE_DIR)) {
        mkdir(PRESENTATION_STORE_DIR, 0700, true);
    }
    // $session is always our own generated random token -- safe as a filename.
    return PRESENTATION_STORE_DIR . '/' . $session . '.json';
}

function save_card_presentation(string $session, array $result): void
{
    file_put_contents(presentation_store_path($session), json_encode($result));
}

function get_card_presentation(string $session): ?array
{
    $path = presentation_store_path($session);
    if (!is_file($path)) {
        return null;
    }
    return json_decode(file_get_contents($path), true);
}

// --- OIDC bridge (Cyclos "generic OpenID Connect" identity provider) --
// Cyclos's identity provider only knows classic browser-redirect OIDC: it
// sends the user to an authorization_endpoint expecting a login page, and
// later calls a token_endpoint for an id_token. There's no such login
// page here -- oidc/authorize.php shows the same LusoPay Card OpenID4VP
// QR as pay-with-card.php instead, and only "logs the user in" (issues an
// authorization code, then an id_token) once the wallet has actually
// presented the card. Two stores: one row per in-flight browser visit to
// authorize.php ("session-*"), one row per issued authorization code
// ("code-*", single-use, exchanged at the token endpoint).

const OIDC_ISSUER = 'https://pay.lusopay.com/uidwallettest/oidc';
const OIDC_CLIENT_ID = 'lusopay-wallet-idp';
// Paste this exact value into Cyclos's "Chave secreta" field. Committed
// here only because this is a test/PoC deployment (same tradeoff as the
// committed EC signing key above) -- rotate it before any real use.
const OIDC_CLIENT_SECRET = 'e729d161cadd1d30b93dc41f16bc1fd562b6a1ebec5621a5';
const OIDC_ALLOWED_REDIRECT_URI = 'https://dev.lusopay.com:8444/web_dev/api/identity-providers/callback';
const OIDC_SIGNING_KID = 'lusopay-oidc-1';
const OIDC_STORE_DIR = __DIR__ . '/../oidc-store';

function oidc_store_path(string $key): string
{
    if (!is_dir(OIDC_STORE_DIR)) {
        mkdir(OIDC_STORE_DIR, 0700, true);
    }
    // $key is always our own generated random token -- safe as a filename.
    return OIDC_STORE_DIR . '/' . $key . '.json';
}

/**
 * Starts a pending browser session for one visit to oidc/authorize.php,
 * so oidc/wallet-callback.php (a different request, from the wallet) and
 * oidc/status.php (polled by the browser) can find it by id.
 */
function create_oidc_session(string $redirectUri, string $state, string $nonce): string
{
    $oidcSession = bin2hex(random_bytes(16));
    file_put_contents(oidc_store_path("session-{$oidcSession}"), json_encode([
        'status' => 'PENDING',
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'nonce' => $nonce,
        'code' => null,
    ]));
    return $oidcSession;
}

function get_oidc_session(string $oidcSession): ?array
{
    $path = oidc_store_path("session-{$oidcSession}");
    if (!is_file($path)) {
        return null;
    }
    return json_decode(file_get_contents($path), true);
}

/**
 * Marks a pending session READY with a freshly issued authorization code,
 * once the wallet has presented the card. oidc/status.php's polling picks
 * this up and the browser is redirected back to Cyclos with that code.
 */
function complete_oidc_session(string $oidcSession, array $claims): ?string
{
    $session = get_oidc_session($oidcSession);
    if ($session === null) {
        return null;
    }

    $code = bin2hex(random_bytes(24));
    file_put_contents(oidc_store_path("code-{$code}"), json_encode([
        'claims' => $claims,
        'nonce' => $session['nonce'],
    ]));

    $session['status'] = 'READY';
    $session['code'] = $code;
    file_put_contents(oidc_store_path("session-{$oidcSession}"), json_encode($session));

    return $code;
}

/**
 * Exchanges a single-use authorization code for the claims to embed in
 * the id_token, per the OAuth2 authorization_code grant.
 */
function consume_oidc_authorization_code(string $code): ?array
{
    $path = oidc_store_path("code-{$code}");
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode(file_get_contents($path), true);
    unlink($path);
    return $data;
}

/**
 * Builds a signed OIDC id_token (ES256, kid matching oidc/jwks.json) for
 * the given claims. sub is the LusoPay ID -- the one identifier Cyclos
 * needs to link or create an account.
 */
function build_oidc_id_token(array $claims, ?string $nonce): string
{
    $signingKey = get_signing_key();

    $header = ['alg' => 'ES256', 'typ' => 'JWT', 'kid' => OIDC_SIGNING_KID];
    $now = time();
    $payload = [
        'iss' => OIDC_ISSUER,
        'sub' => (string) $claims['lusopay_id'],
        'aud' => OIDC_CLIENT_ID,
        'iat' => $now,
        'exp' => $now + 300,
        'name' => $claims['name'],
        'lusopay_id' => $claims['lusopay_id'],
    ];
    if (!empty($claims['email'])) {
        // Vouched for by us, the credential's issuer -- not independently
        // re-verified at presentation time, but Cyclos needs a non-empty
        // email to link/create the account, per its own generic OIDC
        // provider's account-matching logic.
        $payload['email'] = $claims['email'];
        $payload['email_verified'] = true;
    }
    if ($nonce !== null && $nonce !== '') {
        $payload['nonce'] = $nonce;
    }

    $signingInput = base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.' . base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));

    openssl_sign($signingInput, $derSignature, $signingKey['private_key'], OPENSSL_ALGO_SHA256);

    return $signingInput . '.' . base64url_encode(der_ecdsa_signature_to_jose($derSignature));
}

// --- Wallet-identified checkout (real OpenID4VP) + payment execution --
// checkout.php's actual flow: instead of the buyer typing in their own
// "Public ID" and a simulated confirmation, they present their LusoPay
// Card (same DCQL request as pay-with-card.php) so we learn their
// publicId from the wallet -- the merchant's own receiving publicId
// comes from wherever integrates checkout.php (e.g. the WooCommerce
// gateway's settings), not from the buyer. Both go to Cyclos's existing
// adduidpayment endpoint (the same CYCLOS_ENDPOINT already used by
// notify_cyclos() above) alongside the amount/description.

const CHECKOUT_STORE_DIR = __DIR__ . '/../checkout-store';

function checkout_store_path(string $session): string
{
    if (!is_dir(CHECKOUT_STORE_DIR)) {
        mkdir(CHECKOUT_STORE_DIR, 0700, true);
    }
    // $session is always our own generated random token -- safe as a filename.
    return CHECKOUT_STORE_DIR . '/' . $session . '.json';
}

/**
 * Starts a new checkout session holding the amount/currency/description
 * this payment is for, keyed by a random session id embedded in the
 * OpenID4VP request's response_uri so api/checkout-response.php can find
 * it again once the wallet answers.
 */
function create_checkout_session(array $params, ?string $session = null): string
{
    // Callers that need to know the session id up front -- before the
    // wallet has answered -- (e.g. the WooCommerce Blocks payment method,
    // which polls for this session's status while the checkout order
    // itself is still being held back) can pass their own, validated by
    // the caller the same way pay-with-card.php validates its own.
    if ($session === null) {
        $session = bin2hex(random_bytes(16));
    }
    file_put_contents(checkout_store_path($session), json_encode(['params' => $params, 'status' => 'PENDING']));
    return $session;
}

function get_checkout_session(string $session): ?array
{
    $path = checkout_store_path($session);
    if (!is_file($path)) {
        return null;
    }
    return json_decode(file_get_contents($path), true);
}

function update_checkout_session(string $session, array $fields): void
{
    $data = get_checkout_session($session);
    if ($data === null) {
        return;
    }
    file_put_contents(checkout_store_path($session), json_encode(array_merge($data, $fields)));
}

/**
 * Calls Cyclos's adduidpayment with both sides of the payment -- the
 * buyer's publicId (from the presented LusoPay Card) and the merchant's
 * own receiving publicId (configured wherever integrates checkout.php,
 * e.g. the WooCommerce gateway's settings) -- plus the amount/description,
 * and returns whether it reports the payment as done.
 *
 * ASSUMPTION, not yet confirmed against the real script: the receiving
 * side's field name ("receiverPublicId") is a guess, and the response is
 * assumed to be a bare "true"/"false" body, a JSON boolean, or
 * {"result": true/false}. Adjust both once the actual script is written.
 */
function execute_wallet_payment(string $payerPublicId, string $receiverPublicId, string $amount, string $currency, string $description): bool
{
    $payload = [
        'publicId' => $payerPublicId,
        'receiverPublicId' => $receiverPublicId,
        'amount' => $amount,
        'currency' => $currency,
        'description' => $description,
    ];

    $ch = curl_init(CYCLOS_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("[LUSOPAY-CHECKOUT] adduidpayment unreachable: {$error}");
        return false;
    }

    $trimmed = trim($response);
    if (strcasecmp($trimmed, 'true') === 0) {
        return true;
    }
    if (strcasecmp($trimmed, 'false') === 0) {
        return false;
    }
    $decoded = json_decode($trimmed, true);
    if (is_bool($decoded)) {
        return $decoded;
    }
    if (is_array($decoded) && array_key_exists('result', $decoded)) {
        return (bool) $decoded['result'];
    }

    error_log("[LUSOPAY-CHECKOUT] unrecognized adduidpayment response: {$response}");
    return false;
}
