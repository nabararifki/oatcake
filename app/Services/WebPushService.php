<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WebPushService
{
    private const VAPID_KEY_STORE_RELATIVE_PATH = 'app/private/vapid_keys.json';
    private const RECORD_SIZE_BYTES = 4096;

    /**
     * Generate a fresh EC P-256 (prime256v1) VAPID keypair encoded as unpadded Base64URL.
     *
     * @return array{public_key: string, private_key: string}
     */
    public static function generateVapidKeyPair(): array
    {
        $resource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($resource === false) {
            throw new RuntimeException('Failed to generate EC P-256 VAPID keypair via OpenSSL.');
        }

        $details = openssl_pkey_get_details($resource);
        if (! is_array($details) || ! isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new RuntimeException('Failed to extract EC key coordinates from OpenSSL resource.');
        }

        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $d = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);

        // Uncompressed EC point: 0x04 || X (32B) || Y (32B) = 65 bytes
        $rawPublic = "\x04".$x.$y;

        return [
            'public_key' => self::base64UrlEncode($rawPublic),
            'private_key' => self::base64UrlEncode($d),
        ];
    }

    /**
     * Persist a generated VAPID keypair to private local storage fallback.
     *
     * @param  array{public_key: string, private_key: string}  $keyPair
     */
    public static function storeFallbackKeyPair(array $keyPair): void
    {
        $path = storage_path(self::VAPID_KEY_STORE_RELATIVE_PATH);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($keyPair, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Resolve the active VAPID keypair from config/env or auto-generated local private store.
     *
     * @return array{public_key: string, private_key: string, subject: string}
     */
    public function getVapidKeyPair(): array
    {
        $publicKey = trim((string) config('services.webpush.public_key', ''));
        $privateKey = trim((string) config('services.webpush.private_key', ''));
        $subject = trim((string) config('services.webpush.subject', 'mailto:admin@oatcake.local'));

        if ($subject === '') {
            $subject = 'mailto:admin@oatcake.local';
        }

        if ($publicKey === '' || $privateKey === '') {
            $fallbackPath = storage_path(self::VAPID_KEY_STORE_RELATIVE_PATH);

            if (File::exists($fallbackPath)) {
                $stored = json_decode((string) File::get($fallbackPath), true);
                if (
                    is_array($stored)
                    && is_string($stored['public_key'] ?? null)
                    && is_string($stored['private_key'] ?? null)
                    && strlen(self::base64UrlDecode($stored['public_key'])) === 65
                    && strlen(self::base64UrlDecode($stored['private_key'])) === 32
                ) {
                    $publicKey = $stored['public_key'];
                    $privateKey = $stored['private_key'];
                }
            }

            if ($publicKey === '' || $privateKey === '') {
                $generated = self::generateVapidKeyPair();
                self::storeFallbackKeyPair($generated);
                $publicKey = $generated['public_key'];
                $privateKey = $generated['private_key'];
            }
        }

        return [
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'subject' => $subject,
        ];
    }

    /**
     * Return the active VAPID public key (Base64URL encoded, 65-byte uncompressed point).
     */
    public function getVapidPublicKey(): string
    {
        return $this->getVapidKeyPair()['public_key'];
    }

    /**
     * Persist an in-app notification for a user and dispatch Web Push payloads to all their active subscriptions.
     *
     * @param  array<string, mixed>  $meta
     */
    public function notifyUser(
        User $recipient,
        string $category,
        string $title,
        string $body,
        ?string $actionUrl = null,
        array $meta = []
    ): UserNotification {
        $sanitizedMeta = array_filter(
            $meta,
            static fn ($value, $key): bool => strtolower((string) $key) !== 'nik',
            ARRAY_FILTER_USE_BOTH
        );

        $notification = UserNotification::withoutGlobalScopes()->create([
            'company_id' => $recipient->company_id,
            'user_id' => $recipient->id,
            'category' => $category,
            'title' => mb_substr($title, 0, 200),
            'body' => $body,
            'action_url' => $actionUrl,
            'meta' => $sanitizedMeta === [] ? null : $sanitizedMeta,
        ]);

        $subscriptions = PushSubscription::withoutGlobalScopes()
            ->where('user_id', $recipient->id)
            ->get();

        if ($subscriptions->isEmpty()) {
            return $notification;
        }

        $payload = [
            'title' => $notification->title,
            'body' => $notification->body,
            'icon' => '/icons/icon-192x192.png',
            'badge' => '/icons/icon-192x192.png',
            'tag' => $category,
            'data' => [
                'url' => $actionUrl ?? '/dashboard',
                'notification_id' => $notification->uuid,
                'category' => $category,
            ],
        ];

        $anyPushed = false;
        foreach ($subscriptions as $subscription) {
            if ($this->sendToSubscription($subscription, $payload)) {
                $anyPushed = true;
            }
        }

        if ($anyPushed) {
            $notification->update([
                'pushed_at' => Carbon::now('Asia/Jakarta'),
            ]);
        }

        return $notification;
    }

    /**
     * Resolve active department managers for a company & department, falling back to company admins if none exist.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function resolveDepartmentManagersOrCompanyAdmins(
        int $companyId,
        ?int $departmentId,
        ?int $excludeUserId = null
    ) {
        if ($departmentId !== null) {
            $managers = User::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('role', 'manager')
                ->when($excludeUserId !== null, fn ($q) => $q->where('id', '!=', $excludeUserId))
                ->whereHas('employee', function ($q) use ($companyId, $departmentId): void {
                    $q->withoutGlobalScopes()
                        ->where('company_id', $companyId)
                        ->where('department_id', $departmentId)
                        ->where('status', 'active');
                })
                ->get();

            if ($managers->isNotEmpty()) {
                return $managers;
            }
        }

        return $this->resolveCompanyAdmins($companyId, $excludeUserId);
    }

    /**
     * Resolve active HR admins and super users within a company.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function resolveCompanyAdmins(int $companyId, ?int $excludeUserId = null)
    {
        return User::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('role', ['admin', 'super_user'])
            ->when($excludeUserId !== null, fn ($q) => $q->where('id', '!=', $excludeUserId))
            ->get();
    }

    /**
     * Notify multiple unique recipients safely without duplicating notifications for the same user.
     *
     * @param  iterable<User>  $recipients
     * @param  array<string, mixed>  $meta
     * @return list<UserNotification>
     */
    public function notifyUsers(
        iterable $recipients,
        string $category,
        string $title,
        string $body,
        ?string $actionUrl = null,
        array $meta = []
    ): array {
        $seenUserIds = [];
        $notifications = [];

        foreach ($recipients as $recipient) {
            if (! $recipient instanceof User || ! $recipient->is_active) {
                continue;
            }

            if (isset($seenUserIds[$recipient->id])) {
                continue;
            }

            $seenUserIds[$recipient->id] = true;
            $notifications[] = $this->notifyUser($recipient, $category, $title, $body, $actionUrl, $meta);
        }

        return $notifications;
    }

    /**
     * Encrypt and transmit a Web Push payload to a single PushSubscription using RFC 8291 & RFC 8292.
     * Automatically deletes expired subscriptions (HTTP 404/410) and swallows network failures gracefully.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendToSubscription(PushSubscription $subscription, array $payload): bool
    {
        try {
            $vapid = $this->getVapidKeyPair();
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (! is_string($jsonPayload)) {
                return false;
            }

            $encryptedBody = $this->encryptPayload(
                $jsonPayload,
                $subscription->public_key,
                $subscription->auth_token
            );

            $authorization = $this->buildVapidAuthorizationHeader(
                $subscription->endpoint,
                $vapid['public_key'],
                $vapid['private_key'],
                $vapid['subject']
            );

            $response = Http::timeout(8)
                ->withHeaders([
                    'Authorization' => $authorization,
                    'Content-Encoding' => 'aes128gcm',
                    'TTL' => '86400',
                    'Urgency' => 'normal',
                ])
                ->withBody($encryptedBody, 'application/octet-stream')
                ->post($subscription->endpoint);

            $status = $response->status();

            if ($status === 404 || $status === 410) {
                $subscription->delete();

                return false;
            }

            if (in_array($status, [200, 201, 202], true)) {
                $subscription->forceFill([
                    'last_used_at' => Carbon::now('Asia/Jakarta'),
                ])->saveQuietly();

                return true;
            }

            Log::warning('Web Push delivery returned unexpected status.', [
                'status' => $status,
                'subscription_id' => $subscription->id,
            ]);

            return false;
        } catch (Throwable $e) {
            Log::warning('Web Push delivery failed gracefully: '.$e->getMessage(), [
                'subscription_id' => $subscription->id,
            ]);

            return false;
        }
    }

    /**
     * Build the RFC 8292 VAPID Authorization header (`vapid t=<JWT>, k=<PUBLIC_KEY>`).
     */
    public function buildVapidAuthorizationHeader(
        string $endpoint,
        string $publicKeyB64,
        string $privateKeyB64,
        string $subject
    ): string {
        $parsed = parse_url($endpoint);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $audience = "{$scheme}://{$host}{$port}";

        $header = self::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::base64UrlEncode((string) json_encode([
            'aud' => $audience,
            'exp' => Carbon::now('UTC')->addHours(12)->timestamp,
            'sub' => $subject,
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = "{$header}.{$claims}";

        $privateKeyResource = $this->createEcPrivateKeyResource($privateKeyB64, $publicKeyB64);

        $derSignature = '';
        if (! openssl_sign($signingInput, $derSignature, $privateKeyResource, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign VAPID JWT with EC P-256 private key.');
        }

        $rawSignature = $this->derSignatureToRaw64($derSignature);
        $jwt = $signingInput.'.'.self::base64UrlEncode($rawSignature);

        return "vapid t={$jwt}, k={$publicKeyB64}";
    }

    /**
     * Encrypt plaintext JSON using RFC 8291 (`aes128gcm` content-encoding) with ECDH P-256 + HKDF-SHA256.
     */
    public function encryptPayload(string $plaintext, string $clientPublicKeyB64, string $clientAuthSecretB64): string
    {
        $uaPublic = self::base64UrlDecode($clientPublicKeyB64);
        $authSecret = self::base64UrlDecode($clientAuthSecretB64);

        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new RuntimeException('Invalid client p256dh public key format.');
        }

        if ($authSecret === '') {
            throw new RuntimeException('Invalid client auth secret.');
        }

        // Ephemeral Application Server EC P-256 keypair
        $asKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($asKey === false) {
            throw new RuntimeException('Failed to generate ephemeral ECDH keypair.');
        }

        $asDetails = openssl_pkey_get_details($asKey);
        $asPublic = "\x04"
            .str_pad($asDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            .str_pad($asDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        $uaPublicResource = $this->createEcPublicKeyResource($uaPublic);

        $sharedSecret = openssl_pkey_derive($uaPublicResource, $asKey, 32);
        if ($sharedSecret === false) {
            throw new RuntimeException('ECDH key derivation failed.');
        }
        $sharedSecret = str_pad($sharedSecret, 32, "\x00", STR_PAD_LEFT);

        $salt = random_bytes(16);

        // RFC 8291 Section 3.3 Key Derivation
        $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $keyInfo = "WebPush: info\x00".$uaPublic.$asPublic;
        $ikm = substr(hash_hmac('sha256', $keyInfo."\x01", $prkKey, true), 0, 32);

        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        // RFC 8188 final record delimiter 0x02
        $paddedPlaintext = $plaintext."\x02";
        $tag = '';
        $ciphertext = openssl_encrypt(
            $paddedPlaintext,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('AES-128-GCM encryption failed.');
        }

        // RFC 8188 header: salt (16B) || rs (4B uint32 BE) || idlen (1B) || keyid (65B)
        $header = $salt.pack('N', self::RECORD_SIZE_BYTES).chr(strlen($asPublic)).$asPublic;

        return $header.$ciphertext.$tag;
    }

    /**
     * Reconstruct an OpenSSL EC P-256 private key resource from Base64URL d & uncompressed public key.
     *
     * @return \OpenSSLAsymmetricKey
     */
    private function createEcPrivateKeyResource(string $privateKeyB64, string $publicKeyB64)
    {
        $d = str_pad(self::base64UrlDecode($privateKeyB64), 32, "\x00", STR_PAD_LEFT);
        $pub = self::base64UrlDecode($publicKeyB64);

        if (strlen($d) !== 32 || strlen($pub) !== 65) {
            throw new RuntimeException('Invalid VAPID EC P-256 key lengths.');
        }

        // SEC1 ECPrivateKey DER for prime256v1 (OID 1.2.840.10045.3.1.7)
        $sec1Der = hex2bin('30770201010420')
            .$d
            .hex2bin('a00a06082a8648ce3d030107a144034200')
            .$pub;

        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
            .chunk_split(base64_encode($sec1Der), 64, "\n")
            ."-----END EC PRIVATE KEY-----\n";

        $resource = openssl_pkey_get_private($pem);
        if ($resource === false) {
            throw new RuntimeException('Unable to load VAPID EC private key.');
        }

        return $resource;
    }

    /**
     * Reconstruct an OpenSSL EC P-256 public key resource from a 65-byte uncompressed point.
     *
     * @return \OpenSSLAsymmetricKey
     */
    private function createEcPublicKeyResource(string $uncompressedPoint)
    {
        $spkiDer = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$uncompressedPoint;
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($spkiDer), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        $resource = openssl_pkey_get_public($pem);
        if ($resource === false) {
            throw new RuntimeException('Unable to load client EC public key.');
        }

        return $resource;
    }

    /**
     * Convert an ASN.1 DER ECDSA signature to JWS ES256 64-byte raw (R || S) format.
     */
    private function derSignatureToRaw64(string $der): string
    {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('Invalid DER ECDSA signature sequence.');
        }

        $seqLen = ord($der[$offset++]);
        if (($seqLen & 0x80) !== 0) {
            $lenBytes = $seqLen & 0x7F;
            $offset += $lenBytes;
        }

        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('Invalid DER ECDSA signature R integer.');
        }
        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('Invalid DER ECDSA signature S integer.');
        }
        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);

        $rFixed = substr(str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT), -32);
        $sFixed = substr(str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT), -32);

        return $rFixed.$sFixed;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }
}
