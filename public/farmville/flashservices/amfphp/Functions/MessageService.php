<?php

declare(strict_types=1);

require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";
require_once AMFPHP_ROOTPATH . "Helpers/logger.php";

use App\Models\UserWorld;
use App\Models\WorldActionReceipt;
use Illuminate\Support\Facades\DB;

/**
 * Persistence boundary for the legacy FarmVille message center.
 *
 * The Flash client stores comments in the recipient's active world's
 * messageManager.  A normal post targets the farmer whose farm is open; a
 * reply targets the author of the original comment.  Message signs use the
 * same messageManager shape, so this service deliberately preserves unknown
 * fields on existing entries.
 */
class MessageService
{
    private const MAX_MESSAGE_LENGTH = 140;
    private const ADD_ACTION = 'message.add';

    public static function addMessage($playerObj, $request, $market = null): array
    {
        $senderId = self::uid($playerObj->getUid());
        $params = self::params($request);
        $message = self::messageText($params[0] ?? null);
        $recipientId = self::uid($params[1] ?? null);
        $replyMessageId = self::positiveInt($params[2] ?? 0);

        if ($senderId === null || $recipientId === null || $message === null) {
            self::logRejected('addMessage', $senderId, $recipientId, 'invalid input');
            return self::failure('Invalid message');
        }

        $worldType = self::activeWorldType($recipientId);
        $requestKey = self::requestKey($request, [
            'message' => $message,
            'recipientId' => $recipientId,
            'replyMessageId' => $replyMessageId,
        ]);

        try {
            $result = DB::transaction(function () use (
                $senderId,
                $recipientId,
                $worldType,
                $message,
                $requestKey,
            ): array|false {
                $world = self::lockedWorld($recipientId, $worldType);
                if ($world === null) {
                    return false;
                }

                if ($requestKey !== null) {
                    $receipt = WorldActionReceipt::query()
                        ->where('uid', $senderId)
                        ->where('action', self::ADD_ACTION)
                        ->where('request_key', $requestKey)
                        ->lockForUpdate()
                        ->first();

                    if ($receipt !== null) {
                        $response = is_array($receipt->response) ? $receipt->response : [];
                        $response['replayed'] = true;
                        return $response;
                    }
                }

                $messageManager = self::decodeMessageManager($world->messageManager);
                $messageId = self::nextMessageId($messageManager['messages']);
                if ($messageId === null) {
                    return false;
                }

                $messageManager['messages'][] = [
                    'id' => $messageId,
                    'message' => $message,
                    'authorId' => $senderId,
                    // Social comments are not attached to a physical sign.
                    'objectId' => 0,
                    'isNew' => true,
                    'timestamp' => time(),
                ];

                $world->messageManager = serialize($messageManager);
                $world->save();

                $response = ['messageId' => $messageId];
                if ($requestKey !== null) {
                    WorldActionReceipt::query()->create([
                        'uid' => $senderId,
                        'action' => self::ADD_ACTION,
                        'request_key' => $requestKey,
                        'response' => $response,
                    ]);
                }

                return $response;
            });
        } catch (\Throwable $exception) {
            Logger::error('MessageService', 'addMessage failed: ' . $exception->getMessage(), [
                'senderId' => $senderId,
                'recipientId' => $recipientId,
            ]);
            return self::failure('Unable to save message');
        }

        if ($result === false) {
            self::logRejected('addMessage', $senderId, $recipientId, 'recipient world unavailable');
            return self::failure('Unable to save message');
        }

        invalidateWorldCache($recipientId, $worldType);
        Logger::debug('MessageService', 'message persisted', [
            'senderId' => $senderId,
            'recipientId' => $recipientId,
            'messageId' => $result['messageId'] ?? null,
            'replayed' => (bool) ($result['replayed'] ?? false),
        ]);

        return ['data' => $result];
    }

    public static function markSeenMessages($playerObj, $request, $market = null): array
    {
        $uid = self::uid($playerObj->getUid());
        $params = self::params($request);
        $messageIds = self::messageIds($params[0] ?? []);
        if ($uid === null) {
            return self::failure('Invalid player');
        }
        if ($messageIds === []) {
            return ['data' => []];
        }

        $worldType = self::activeWorldType($uid);
        try {
            $updated = DB::transaction(function () use ($uid, $worldType, $messageIds): int|false {
                $world = self::lockedWorld($uid, $worldType);
                if ($world === null) {
                    return false;
                }

                $messageManager = self::decodeMessageManager($world->messageManager);
                $updated = 0;
                foreach ($messageManager['messages'] as &$message) {
                    if (in_array((int) ($message['id'] ?? 0), $messageIds, true)
                        && (bool) ($message['isNew'] ?? true)) {
                        $message['isNew'] = false;
                        $updated++;
                    }
                }
                unset($message);

                if ($updated > 0) {
                    $world->messageManager = serialize($messageManager);
                    $world->save();
                }

                return $updated;
            });
        } catch (\Throwable $exception) {
            Logger::error('MessageService', 'markSeenMessages failed: ' . $exception->getMessage(), [
                'uid' => $uid,
            ]);
            return self::failure('Unable to update messages');
        }

        if ($updated === false) {
            return self::failure('Unable to update messages');
        }

        invalidateWorldCache($uid, $worldType);
        return ['data' => []];
    }

    public static function removeMessage($playerObj, $request, $market = null): array
    {
        $uid = self::uid($playerObj->getUid());
        $params = self::params($request);
        $messageId = self::positiveInt($params[0] ?? 0);
        $recipientId = self::uid($params[1] ?? null);

        if ($uid === null || $messageId === null || $recipientId === null) {
            return self::failure('Invalid message');
        }

        $worldType = self::activeWorldType($recipientId);
        try {
            $removed = DB::transaction(function () use (
                $uid,
                $recipientId,
                $worldType,
                $messageId,
            ): bool {
                $world = self::lockedWorld($recipientId, $worldType);
                if ($world === null) {
                    return false;
                }

                $messageManager = self::decodeMessageManager($world->messageManager);
                $foundIndex = null;
                $messageAuthorId = null;
                foreach ($messageManager['messages'] as $index => $message) {
                    if ((int) ($message['id'] ?? 0) === $messageId) {
                        $foundIndex = $index;
                        $messageAuthorId = self::uid($message['authorId'] ?? null);
                        break;
                    }
                }

                if ($foundIndex === null) {
                    return false;
                }

                // The farm owner may moderate their own farm. Other users can
                // remove only comments they authored, including while visiting.
                if ($uid !== $recipientId && $uid !== $messageAuthorId) {
                    return false;
                }

                array_splice($messageManager['messages'], $foundIndex, 1);
                $world->messageManager = serialize($messageManager);
                $world->save();
                return true;
            });
        } catch (\Throwable $exception) {
            Logger::error('MessageService', 'removeMessage failed: ' . $exception->getMessage(), [
                'uid' => $uid,
                'recipientId' => $recipientId,
                'messageId' => $messageId,
            ]);
            return self::failure('Unable to remove message');
        }

        if ($removed !== true) {
            return ['data' => ['success' => false]];
        }

        invalidateWorldCache($recipientId, $worldType);
        return ['data' => ['success' => true]];
    }

    public static function setAllowEmail($playerObj, $request, $market = null): array
    {
        $uid = self::uid($playerObj->getUid());
        $params = self::params($request);
        $isAllowed = self::booleanValue($params[0] ?? null);
        if ($uid === null || $isAllowed === null) {
            return self::failure('Invalid email preference');
        }

        $worldType = self::activeWorldType($uid);
        try {
            $saved = DB::transaction(function () use ($uid, $worldType, $isAllowed): bool {
                $world = self::lockedWorld($uid, $worldType);
                if ($world === null) {
                    return false;
                }

                $messageManager = self::decodeMessageManager($world->messageManager);
                $messageManager['allowSendEmails'] = $isAllowed;
                $world->messageManager = serialize($messageManager);
                $world->save();
                return true;
            });
        } catch (\Throwable $exception) {
            Logger::error('MessageService', 'setAllowEmail failed: ' . $exception->getMessage(), [
                'uid' => $uid,
            ]);
            return self::failure('Unable to save email preference');
        }

        if ($saved !== true) {
            return self::failure('Unable to save email preference');
        }

        invalidateWorldCache($uid, $worldType);
        return ['data' => []];
    }

    private static function params($request): array
    {
        $params = is_object($request) ? ($request->params ?? []) : [];
        if (is_object($params)) {
            $params = get_object_vars($params);
        }
        return is_array($params) ? $params : [];
    }

    private static function uid($value): ?string
    {
        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            $value = trim((string) $value);
            return $value !== '' ? $value : null;
        }
        return null;
    }

    private static function positiveInt($value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit(trim($value))) || is_float($value)) {
            $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $number === false ? null : (int) $number;
        }

        // A reply ID of zero means an ordinary top-level comment.
        if ($value === 0 || $value === '0' || $value === null) {
            return 0;
        }

        return null;
    }

    private static function messageText($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $value = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u', '', $value) ?? '';
        if ($value === '') {
            return null;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        return $length <= self::MAX_MESSAGE_LENGTH ? $value : null;
    }

    private static function booleanValue($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        return null;
    }

    private static function messageIds($value): array
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            $parsed = self::positiveInt($id);
            if ($parsed !== null && $parsed > 0) {
                $ids[$parsed] = true;
            }
        }
        return array_map('intval', array_keys($ids));
    }

    private static function activeWorldType(string $uid): string
    {
        $worldType = getCurrentWorldType($uid);
        return is_string($worldType) && trim($worldType) !== '' ? trim($worldType) : 'farm';
    }

    private static function lockedWorld(string $uid, string $worldType): ?UserWorld
    {
        return UserWorld::query()
            ->where('uid', $uid)
            ->where('type', $worldType)
            ->lockForUpdate()
            ->first();
    }

    private static function decodeMessageManager($raw): array
    {
        $manager = is_string($raw) ? @unserialize($raw) : $raw;
        if (is_object($manager)) {
            $manager = get_object_vars($manager);
        }
        if (!is_array($manager)) {
            $manager = [];
        }

        $messages = $manager['messages'] ?? [];
        if (is_object($messages)) {
            $messages = get_object_vars($messages);
        }
        if (!is_array($messages)) {
            $messages = [];
        }

        $cleanMessages = [];
        foreach ($messages as $message) {
            if (is_object($message)) {
                $message = get_object_vars($message);
            }
            if (!is_array($message)) {
                continue;
            }

            $message['id'] = (int) ($message['id'] ?? 0);
            $message['message'] = (string) ($message['message'] ?? '');
            $message['authorId'] = (string) ($message['authorId'] ?? '');
            $message['objectId'] = (int) ($message['objectId'] ?? 0);
            $message['isNew'] = (bool) ($message['isNew'] ?? true);
            $message['timestamp'] = is_numeric($message['timestamp'] ?? null)
                ? (int) $message['timestamp']
                : time();
            $cleanMessages[] = $message;
        }

        return [
            'messages' => $cleanMessages,
            'allowSendEmails' => (bool) ($manager['allowSendEmails'] ?? true),
        ];
    }

    private static function nextMessageId(array $messages): ?int
    {
        $max = 0;
        foreach ($messages as $message) {
            $id = is_array($message) ? (int) ($message['id'] ?? 0) : 0;
            if ($id > $max) {
                $max = $id;
            }
        }

        return $max < PHP_INT_MAX ? $max + 1 : null;
    }

    private static function requestKey($request, array $payload): ?string
    {
        if (!is_object($request)) {
            return null;
        }

        $sequence = $request->sequence ?? null;
        $sequenceId = $request->sequenceID ?? null;
        if ($sequence === null || $sequenceId === null) {
            return null;
        }

        $encoded = json_encode([
            'action' => self::ADD_ACTION,
            'sequence' => (string) $sequence,
            'sequenceID' => (string) $sequenceId,
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($encoded) ? hash('sha256', $encoded) : null;
    }

    private static function failure(string $message): array
    {
        return [
            'errorType' => 1,
            'errorData' => $message,
            'data' => ['messageId' => 0, 'success' => false],
        ];
    }

    private static function logRejected(string $method, ?string $uid, ?string $recipientId, string $reason): void
    {
        Logger::warning('MessageService', $method . ' rejected', [
            'uid' => $uid,
            'recipientId' => $recipientId,
            'reason' => $reason,
        ]);
    }
}
