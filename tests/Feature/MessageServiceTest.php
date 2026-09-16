<?php

use App\Models\PlayerMeta;
use App\Models\UserWorld;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Functions/MessageService.php';

    PlayerMeta::clearCache();
});

function messageTestPlayer(string $uid): object
{
    return new class($uid)
    {
        public function __construct(private string $uid)
        {
        }

        public function getUid(): string
        {
            return $this->uid;
        }
    };
}

function messageTestWorld(string $uid, string $type, array $manager = []): UserWorld
{
    return UserWorld::query()->create([
        'uid' => $uid,
        'type' => $type,
        'sizeX' => 12,
        'sizeY' => 12,
        'objects' => '[]',
        'messageManager' => serialize(array_merge([
            'messages' => [],
            'allowSendEmails' => true,
        ], $manager)),
    ]);
}

function messageTestRequest(array $params, ?int $sequence = null): object
{
    $request = (object) ['params' => $params];
    if ($sequence !== null) {
        $request->sequence = $sequence;
        $request->sequenceID = 'test-sequence';
    }
    return $request;
}

it('persists ordinary posts and replies in the recipient active world', function (): void {
    $senderId = '810001';
    $recipientId = '810002';
    $replyRecipientWorld = messageTestWorld($senderId, 'farm', [
        'messages' => [[
            'id' => 8,
            'message' => 'Existing reply target',
            'authorId' => $recipientId,
            'objectId' => 0,
            'isNew' => true,
            'timestamp' => 100,
        ]],
    ]);
    $recipientWorld = messageTestWorld($recipientId, 'winternord', [
        'messages' => [[
            'id' => 4,
            'message' => 'Welcome',
            'authorId' => '810003',
            'objectId' => 0,
            'isNew' => false,
            'timestamp' => 100,
        ]],
    ]);
    PlayerMeta::setValue($recipientId, 'currentWorldType', 'winternord');

    $response = MessageService::addMessage(
        messageTestPlayer($senderId),
        messageTestRequest(['Hello from the neighbor', $recipientId, 0], 1),
    );

    expect($response['data']['messageId'])->toBe(5);

    $messages = unserialize($recipientWorld->fresh()->messageManager)['messages'];
    expect($messages)->toHaveCount(2)
        ->and($messages[1]['message'])->toBe('Hello from the neighbor')
        ->and($messages[1]['authorId'])->toBe($senderId)
        ->and($messages[1]['objectId'])->toBe(0)
        ->and($messages[1]['isNew'])->toBeTrue();

    $reply = MessageService::addMessage(
        messageTestPlayer($recipientId),
        messageTestRequest(['Thanks!', $senderId, 8], 2),
    );

    expect($reply['data']['messageId'])->toBe(9);
    $replyMessages = unserialize($replyRecipientWorld->fresh()->messageManager)['messages'];
    expect($replyMessages[1]['message'])->toBe('Thanks!')
        ->and($replyMessages[1]['authorId'])->toBe($recipientId);
});

it('returns the original message ID for a retried post without duplicating it', function (): void {
    $senderId = '810011';
    $recipientId = '810012';
    messageTestWorld($recipientId, 'farm');

    $request = messageTestRequest(['Retry-safe comment', $recipientId, 0], 17);
    $first = MessageService::addMessage(messageTestPlayer($senderId), $request);
    $second = MessageService::addMessage(messageTestPlayer($senderId), $request);

    expect($first['data']['messageId'])->toBe($second['data']['messageId'])
        ->and($second['data']['replayed'])->toBeTrue()
        ->and(unserialize(UserWorld::query()->where('uid', $recipientId)->value('messageManager'))['messages'])
            ->toHaveCount(1);
});

it('marks only the requested active-world messages as seen', function (): void {
    $uid = '810021';
    $world = messageTestWorld($uid, 'farm', [
        'messages' => [
            ['id' => 1, 'message' => 'one', 'authorId' => '1', 'isNew' => true],
            ['id' => 2, 'message' => 'two', 'authorId' => '2', 'isNew' => true],
        ],
    ]);

    $response = MessageService::markSeenMessages(
        messageTestPlayer($uid),
        messageTestRequest([[1]]),
    );

    expect($response['data'])->toBe([]);
    $messages = unserialize($world->fresh()->messageManager)['messages'];
    expect($messages[0]['isNew'])->toBeFalse()
        ->and($messages[1]['isNew'])->toBeTrue();
});

it('allows the farm owner to moderate comments and authors to remove their own', function (): void {
    $authorId = '810031';
    $ownerId = '810032';
    $world = messageTestWorld($ownerId, 'farm', [
        'messages' => [
            ['id' => 1, 'message' => 'author comment', 'authorId' => $authorId, 'isNew' => true],
            ['id' => 2, 'message' => 'other comment', 'authorId' => '810033', 'isNew' => true],
        ],
    ]);

    $authorResponse = MessageService::removeMessage(
        messageTestPlayer($authorId),
        messageTestRequest([1, $ownerId]),
    );
    $ownerResponse = MessageService::removeMessage(
        messageTestPlayer($ownerId),
        messageTestRequest([2, $ownerId]),
    );

    expect($authorResponse['data']['success'])->toBeTrue()
        ->and($ownerResponse['data']['success'])->toBeTrue()
        ->and(unserialize($world->fresh()->messageManager)['messages'])->toBe([]);
});

it('persists the email preference in the player active world', function (): void {
    $uid = '810041';
    $world = messageTestWorld($uid, 'farm');

    $response = MessageService::setAllowEmail(
        messageTestPlayer($uid),
        messageTestRequest([false]),
    );

    expect($response['data'])->toBe([])
        ->and(unserialize($world->fresh()->messageManager)['allowSendEmails'])->toBeFalse();
});
