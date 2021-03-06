<?php

namespace Longman\TelegramBot;

use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\Chat;
use Longman\TelegramBot\Entities\ChosenInlineResult;
use Longman\TelegramBot\Entities\InlineQuery;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Entities\User;
use Longman\TelegramBot\Exception\TelegramException;
use MongoDB\Client;
use MongoDB\Database;
use MongoDB\Driver\Command;
use MongoDB\InsertOneResult;

class DBMongo extends DBBase
{
    protected $dbPublicName = 'mongodb';

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Database
     */
    protected $database;

    protected function initDb(array $credentials, $encoding = 'utf8mb4')
    {
        if (empty($credentials['uri'])) {
            throw new TelegramException('MongoDB uri connection string not provided!');
        }
        if (empty($credentials['database'])) {
            throw new TelegramException('Database not provided!');
        }

        $uriOptions = isset($credentials['uri_options']) ? $credentials['uri_options'] : [];
        $driverOptions = isset($credentials['driver_options']) ? $credentials['driver_options'] : [];

        $credentials['driver_options']['typeMap'] = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

        $client = new Client($credentials['uri'], $uriOptions, $driverOptions);
        $database = $client->selectDatabase($credentials['database']);

        $this->client = $client;
        $this->database = $database;
    }

    /**
     * Check if database connection has been created
     *
     * @return bool
     */
    public function isDbConnected()
    {
        return $this->client !== null;
    }

    /**
     * Fetch update(s) from DB
     *
     * @param int    $limit Limit the number of updates to fetch
     * @param string $id    Check for unique update id
     *
     * @return array|bool Fetched data or false if not connected
     * @throws TelegramException
     */
    public function selectTelegramUpdate($limit = null, $id = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $filter = [];
        $options = ['projection' => ['id' => 1]];

        if ($id !== null) {
            $filter['id'] = $id;
        } else {
            $options['sort'] = ['id' => -1];
        }

        if (is_int($limit)) {
            $options['limit'] = $limit;
        }

        try {
            return $this->database->selectCollection(TB_TELEGRAM_UPDATE)->find($filter, $options)->toArray();
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }
    }

    /**
     * @return Database
     */
    public function getDataBase()
    {
        return $this->database;
    }

    /**
     * Fetch message(s) from DB
     *
     * @param int $limit Limit the number of messages to fetch
     *
     * @return array|bool Fetched data or false if not connected
     * @throws TelegramException
     */
    public function selectMessages($limit = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $options['sort'] = ['id' => -1];

        if (is_int($limit)) {
            $options['limit'] = $limit;
        }

        try {
            return $this->database->selectCollection(TB_MESSAGE)->find([], $options)->toArray();
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }
    }

    /**
     * @param      $id
     * @param null $chat_id
     * @param null $message_id
     * @param null $inline_query_id
     * @param null $chosen_inline_result_id
     * @param null $callback_query_id
     * @param null $edited_message_id
     *
     * @return bool|string
     * @throws TelegramException
     */
    protected function insertTelegramUpdateToDb(
        $id,
        $chat_id = null,
        $message_id = null,
        $inline_query_id = null,
        $chosen_inline_result_id = null,
        $callback_query_id = null,
        $edited_message_id = null
    ) {
        try {
            $insertOneResult = $this->database->selectCollection(TB_TELEGRAM_UPDATE)->insertOne([
                'id' => $id,
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'inline_query_id' => $inline_query_id,
                'chosen_inline_result_id' => $chosen_inline_result_id,
                'callback_query_id' => $callback_query_id,
                'edited_message_id' => $edited_message_id
            ]);
        } catch (\Exception $exception) {
            if (strpos($exception->getMessage(), 'duplicate key error') !== false) {
                return true;
            }

            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $this->getInsertOneResult($insertOneResult);
    }

    /**
     * @param User $user
     *
     * @return bool|string
     */
    protected function insertUserToDb(User $user, $date)
    {
        $date = $date ?: $this->getTimestamp();
        $userToSave = [
            'id' => $user->getId(),
            'is_bot' => $user->getIsBot(),
            'username' => $user->getUsername(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'language_code' => $user->getLanguageCode(),
            'created_at' => $date,
            'updated_at' => $date
        ];
        try {
            $insertOneResult = $this->database->selectCollection(TB_USER)->insertOne($userToSave);
        } catch (\Exception $exception) {
            if (strpos($exception->getMessage(), 'duplicate key error') !== false) {
                unset($userToSave['id'], $userToSave['created_at']);
                $this->database->selectCollection(TB_USER)->updateOne(['id' => $user->getId()], ['$set' => $userToSave]);

                 return true;
            }

            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $this->getInsertOneResult($insertOneResult);
    }

    /**
     * @param User $user
     * @param Chat $chat
     *
     * @return bool|string
     * @throws TelegramException
     */
    protected function insertUserChatRelation(User $user, Chat $chat)
    {
        try {
            $insertOneResult = $this->database->selectCollection(TB_USER_CHAT)->insertOne([
                'user_id' => $user->getId(),
                'chat_id' => $chat->getId()
            ]);
        } catch (\Exception $exception) {
            if (strpos($exception->getMessage(), 'duplicate key error') !== false) {
                return true;
            }

            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $this->getInsertOneResult($insertOneResult);
    }

    /**
     * @param $chat
     * @param $id
     * @param $oldId
     * @param $type
     * @param $createdAt
     * @param $updatedAt
     *
     * @return bool|string
     * @throws TelegramException
     */
    protected function insertChatToDb(Chat $chat, $id, $oldId, $type, $createdAt, $updatedAt, $user = null)
    {
        $chatToSave = [
            'id' => $id,
            'type' => $type,
            'title' => $chat->getTitle(),
            'username' => $chat->getUsername(),
            'all_members_are_administrators' => $chat->getAllMembersAreAdministrators(),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'old_id' => $oldId
        ];

        if ($user instanceof User && $user->getId() === $id) {
            $chatToSave['user_id'] = $user->getId();
            $chatToSave['is_bot'] = $user->getIsBot();
            $chatToSave['username'] = $user->getUsername();
            $chatToSave['first_name'] = $user->getFirstName();
            $chatToSave['last_name'] = $user->getLastName();
            $chatToSave['language_code'] = $user->getLanguageCode();
        }

        try {
            $insertOneResult = $this->database->selectCollection(TB_CHAT)->insertOne($chatToSave);
        } catch (\Exception $exception) {
            if (strpos($exception->getMessage(), 'duplicate key error') !== false) {
                unset($chatToSave['created_at'], $chatToSave['old_id'], $chatToSave['id']);
                $this->database->selectCollection(TB_CHAT)->updateOne(['id' => $chat->getId()], [
                    '$set' => $chatToSave
                ]);

                return true;
            }

            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $this->getInsertOneResult($insertOneResult);
    }

    /**
     * Insert inline query request into database
     *
     * @param InlineQuery $inline_query
     *
     * @return bool|string
     * @throws TelegramException
     */
    public function insertInlineQueryRequest(InlineQuery $inline_query)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $date    = $this->getTimestamp();
        $user_id = null;

        $user = $inline_query->getFrom();
        if ($user instanceof User) {
            $user_id = $user->getId();
            $this->insertUser($user, $date);
        }

        try {
            $insertOneResult = $this->database->selectCollection(TB_INLINE_QUERY)->insertOne([
                'id' => $inline_query->getId(),
                'user_id' => $user_id,
                'location' => $inline_query->getLocation(),
                'query' => $inline_query->getQuery(),
                'offset' => $inline_query->getOffset(),
                'created_at' => $date
            ]);
        } catch (\Exception $exception) {
            if (strpos($exception->getMessage(), 'duplicate key error') !== false) {
                return true;
            }

            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $this->getInsertOneResult($insertOneResult);
    }

    public function insertRequest(Update $update)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $update_id   = $update->getUpdateId();
        $update_type = $update->getUpdateType();

        // @todo Make this simpler: if ($message = $update->getMessage()) ...
        if ($update_type === 'message') {
            $message = $update->getMessage();

            if ($this->insertMessageRequest($message)) {
                $message_id = $message->getMessageId();
                $chat_id    = $message->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    $message_id
                );
            }
        } elseif ($update_type === 'edited_message') {
            $edited_message = $update->getEditedMessage();

            if ($edited_message_local_id = $this->insertEditedMessageRequest($edited_message)) {
                $chat_id                 = $edited_message->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    null,
                    null,
                    null,
                    null,
                    $edited_message_local_id
                );
            }
        } elseif ($update_type === 'channel_post') {
            $channel_post = $update->getChannelPost();

            if ($this->insertMessageRequest($channel_post)) {
                $message_id = $channel_post->getMessageId();
                $chat_id    = $channel_post->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    $message_id
                );
            }
        } elseif ($update_type === 'edited_channel_post') {
            $edited_channel_post = $update->getEditedChannelPost();

            if ($edited_channel_post_local_id = $this->insertEditedMessageRequest($edited_channel_post)) {
                $chat_id                      = $edited_channel_post->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    null,
                    null,
                    null,
                    null,
                    $edited_channel_post_local_id
                );
            }
        } elseif ($update_type === 'inline_query') {
            $inline_query = $update->getInlineQuery();

            if ($this->insertInlineQueryRequest($inline_query)) {
                $inline_query_id = $inline_query->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    null,
                    null,
                    $inline_query_id
                );
            }
        } elseif ($update_type === 'chosen_inline_result') {
            $chosen_inline_result = $update->getChosenInlineResult();

            if ($chosen_inline_result_local_id = $this->insertChosenInlineResultRequest($chosen_inline_result)) {
                return $this->insertTelegramUpdate(
                    $update_id,
                    null,
                    null,
                    null,
                    $chosen_inline_result_local_id
                );
            }
        } elseif ($update_type === 'callback_query') {
            $callback_query = $update->getCallbackQuery();

            if ($this->insertCallbackQueryRequest($callback_query)) {
                $callback_query_id = $callback_query->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    null,
                    null,
                    null,
                    null,
                    $callback_query_id
                );
            }
        }

        return false;
    }

    /**
     * @param ChosenInlineResult $chosen_inline_result
     * @param                    $user_id
     * @param                    $created_at
     *
     * @return bool|string
     * @throws TelegramException
     */
    protected function insertChosenInlineResultRequestToDb(
        ChosenInlineResult $chosen_inline_result,
        $user_id,
        $created_at
    ) {
        try {
            $insertOneResult = $this->database->selectCollection(TB_CHOSEN_INLINE_RESULT)->insertOne([
                'result_id' => $chosen_inline_result->getResultId(),
                'user_id' => $user_id,
                'location' => $chosen_inline_result->getLocation(),
                'inline_message_id' => $chosen_inline_result->getInlineMessageId(),
                'query' => $chosen_inline_result->getQuery(),
                'created_at' => $created_at
            ]);

        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $this->getInsertOneResult($insertOneResult);
    }

    /**
     * @param $message_id
     * @param $chat_id
     *
     * @return bool
     */
    protected function isMessage($message_id, $chat_id)
    {
        try {
            return (bool) $this->database->selectCollection(TB_MESSAGE)->count(['id' => $message_id, 'chat_id' => $chat_id]);
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }
    }

    /**
     * @param CallbackQuery $callback_query
     * @param               $user_id
     * @param               $chat_id
     * @param               $message_id
     * @param               $created_at
     *
     * @return bool|string
     * @throws TelegramException
     */
    protected function insertCallbackQueryRequestToDb(
        CallbackQuery $callback_query,
        $user_id,
        $chat_id,
        $message_id,
        $created_at
    ) {

        try {
            $insertOneResult = $this->database->selectCollection(TB_CALLBACK_QUERY)->insertOne([
                'id' => $callback_query->getId(),
                'user_id' => $user_id,
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'inline_message_id' => $callback_query->getInlineMessageId(),
                'data' => $callback_query->getData(),
                'created_at' => $created_at
            ]);
        } catch (\Exception $exception) {
            if (strpos($exception->getMessage(), 'duplicate key error') !== false) {
                return true;
            }
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $this->getInsertOneResult($insertOneResult);
    }

    /**
     * @param Message $message
     * @param         $chat_id
     * @param         $user_id
     * @param         $date
     * @param         $forward_from
     * @param         $forward_from_chat
     * @param         $forward_date
     * @param         $reply_to_chat_id
     * @param         $reply_to_message_id
     * @param         $entities
     * @param         $photo
     * @param         $new_chat_photo
     * @param         $new_chat_members_ids
     * @param         $left_chat_member_id
     *
     * @return bool|string
     * @throws TelegramException
     */
    protected function insertMessageRequestToDb(
        Message $message,
        $chat_id,
        $user_id,
        $date,
        $forward_from,
        $forward_from_chat,
        $forward_date,
        $reply_to_chat_id,
        $reply_to_message_id,
        $entities,
        $photo,
        $new_chat_photo,
        $new_chat_members_ids,
        $left_chat_member_id
    ) {


        try {
            $insertOneResult = $this->database->selectCollection(TB_MESSAGE)->insertOne([
                'id' => $message->getMessageId(),
                'user_id' => $user_id,
                'chat_id' => $chat_id,
                'date' => $date,
                'forward_from' => $forward_from,
                'forward_from_chat' => $forward_from_chat,
                'forward_from_message_id' => $message->getForwardFromMessageId(),
                'forward_date' => $forward_date,
                'reply_to_chat' => $reply_to_chat_id,
                'reply_to_message' => $reply_to_message_id,
                'media_group_id' => $message->getMediaGroupId(),
                'text' => $message->getText(),
                'entities' => $entities,
                'audio' => $message->getAudio(),
                'document' => $message->getDocument(),
                'animation' => $message->getAnimation(),
                'game' => $message->getGame(),
                'photo' => $photo,
                'sticker' => $message->getSticker(),
                'video' => $message->getVoice(),
                'voice' => $message->getVoice(),
                'video_note' => $message->getVideoNote(),
                'caption' => $message->getCaption(),
                'contact' => $message->getContact(),
                'location' => $message->getLocation(),
                'venue' => $message->getVenue(),
                'new_chat_members' => $new_chat_members_ids,
                'left_chat_member' => $left_chat_member_id,
                'new_chat_title' => $message->getNewChatTitle(),
                'new_chat_photo' => $new_chat_photo,
                'delete_chat_photo' => $message->getDeleteChatPhoto(),
                'group_chat_created' => $message->getGroupChatCreated(),
                'supergroup_chat_created' => $message->getSupergroupChatCreated(),
                'channel_chat_created' => $message->getChannelChatCreated(),
                'migrate_from_chat_id' => $message->getMigrateFromChatId(),
                'migrate_to_chat_id' => $message->getMigrateToChatId(),
                'pinned_message' => $message->getPinnedMessage(),
                'connected_website' => $message->getConnectedWebsite(),
                'passport_data' => $message->getPassportData()
            ]);
            $result = $this->getInsertOneResult($insertOneResult);
        } catch (\Exception $exception) {
            if (strpos($exception->getMessage(), 'duplicate key error') !== false) {
                return true;
            }

            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $result;
    }

    /**
     * @param Message $edited_message
     * @param Chat    $chat
     * @param         $user_id
     * @param         $edit_date
     * @param         $entities
     *
     * @return bool|string
     * @throws TelegramException
     */
    protected function insertEditedMessageRequestToDb(
        Message $edited_message,
        Chat $chat,
        $user_id,
        $edit_date,
        $entities
    ) {

        try {
            $insertOneResult = $this->database->selectCollection(TB_EDITED_MESSAGE)->insertOne([
                'chat_id' => $chat->getId(),
                'message_id' => $edited_message->getMessageId(),
                'user_id' => $user_id,
                'edit_date' => $edit_date,
                'text' => $edited_message->getText(),
                'entities' => $entities,
                'caption' => $edited_message->getCaption()
            ]);

        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $this->getInsertOneResult($insertOneResult);
    }

    /**
     * @param $select array
     *
     * @return array|bool
     * @throws TelegramException
     */
    protected function selectChatsFromDb($select)
    {
        $or = [];
        if (!$select['groups'] || !$select['users'] || !$select['supergroups'] || !$select['channels']) {
            if ($select['groups']) {
                $or[] = ['type' => 'group'];
            }
            if ($select['supergroups']) {
                $or[] = ['type' => 'supergroup'];
            }
            if ($select['channels']) {
                $or[] = ['type' => 'channel'];
            }
            if ($select['users']) {
                $or[] = ['type' => 'private'];
            }
        }

        $and = [];
        if (null !== $select['date_from']) {
            $and[] = [
                'updated_at' => ['$gte' => $select['date_from']]
            ];
        }

        if (null !== $select['date_to']) {
            $and[] = [
                'updated_at' => ['$lte' => $select['date_to']]
            ];
        }

        if (null !== $select['chat_id']) {
            $and[] = [
                'id' => $select['chat_id']
            ];
        }

        if (null !== $select['text']) {
            $and[] = [
                'title' => ['$regex' => "/.*{$select['text']}.*/i"]
            ];
            if ($select['users']) {
                $and[] = [
                    'first_name' => ['$regex' => "/.*{$select['text']}.*/i"],
                    'last_name' => ['$regex' => "/.*{$select['text']}.*/i"],
                    'username' => ['$regex' => "/.*{$select['text']}.*/i"],
                ];
            }
        }

        $filter = [];
        if (!empty($and)) {
            $filter['$and'] = $and;
        }

        if (!empty($or)) {
            $filter['$or'] = $or;
        }

        try {
            $result = $this->database->selectCollection(TB_CHAT)->find($filter, ['sort' => ['updated_at' => 1]])->toArray();
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        $aliases = [
            'id' => 'chat_id',
            'username' => 'chat_username',
            'created_at' => 'chat_created_at',
            'updated_at' => 'chat_updated_at'
        ];

        foreach ($result as &$chat) {
            $this->setAliaces($chat, $aliases);
        }

        return $result;
    }

    /**
     * @param $input
     * @param $aliases
     */
    protected function setAliaces(&$input, $aliases)
    {
        foreach ($input as $key => $value) {
            if (isset($aliases[$key])) {
                unset($input[$key]);
                $input[$aliases[$key]] = $value;
            }
        }
    }

    /**
     * Get Telegram API request count for current chat / message
     *
     * @param integer $chat_id
     * @param string  $inline_message_id
     *
     * @return array|bool Array containing TOTAL and CURRENT fields or false on invalid arguments
     * @throws TelegramException
     */
    public function getTelegramRequestCount($chat_id = null, $inline_message_id = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }


        $date        = $this->getTimestamp();
        $date_minute = $this->getTimestamp(strtotime('-1 minute'));

        try {
            $limitPerSecAll = $this->database->selectCollection(TB_REQUEST_LIMITER)->aggregate([
                [
                    '$match' => [
                        'created_at' => [
                            '$gte' => $date
                        ]
                    ]
                ],
                [
                    '$group' => [
                        '_id' => '$chat_id'
                    ]
                ],
                [
                    '$group' => [
                        '_id' => null,
                        'count' => ['$sum' => 1]
                    ]
                ]

            ])->toArray();

            $limitPerSec = $this->database->selectCollection(TB_REQUEST_LIMITER)->count([
                '$and' => [
                    [
                        'created_at' => [
                            '$gte' => $date
                        ]
                    ],
                    [
                        '$or' => [
                            [
                                'chat_id' => $chat_id,
                                'inline_message_id' => null
                            ],
                            [
                                'inline_message_id' => $inline_message_id,
                                'chat_id' => null
                            ]
                        ]
                    ]
                ]
            ]);

            $limitPerMinute = $this->database->selectCollection(TB_REQUEST_LIMITER)->count([
                'created_at' => [
                    '$gte' => $date_minute
                ],
                'chat_id' => $chat_id
            ]);
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return [
            'LIMIT_PER_SEC_ALL' => isset($limitPerSecAll[0]['count']) ? $limitPerSecAll[0]['count'] : 0,
            'LIMIT_PER_SEC' => $limitPerSec,
            'LIMIT_PER_MINUTE' => $limitPerMinute
        ];
    }

    /**
     * @param $chat_id
     * @param $inline_message_id
     * @param $method
     * @param $created_at
     *
     * @return bool|string
     * @throws TelegramException
     */
    protected function insertTelegramRequestToDb($chat_id, $inline_message_id, $method, $created_at)
    {
        try {
            $insertOneResult = $this->database->selectCollection(TB_REQUEST_LIMITER)->insertOne([
                'method' => $method,
                'chat_id' => $chat_id,
                'inline_message_id' => $inline_message_id,
                'created_at' => $this->getTimestamp()
            ]);
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $this->getInsertOneResult($insertOneResult);
    }

    /**
     * @param       $table
     * @param array $fields_values
     * @param array $where_fields_values
     *
     * @return bool
     * @throws TelegramException
     */
    protected function updateInDb($table, array $fields_values, array $where_fields_values)
    {
        try {
            $updatedResult = $this->database->selectCollection($table)->updateOne($where_fields_values, ['$set' => $fields_values]);
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $updatedResult->isAcknowledged();
    }

    /**
     * Select a conversation from the DB
     *
     * @param string   $user_id
     * @param string   $chat_id
     * @param int|null $limit
     *
     * @return array|bool
     * @throws TelegramException
     */
    public function selectConversation($user_id, $chat_id, $limit = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $options = [];
        if (is_int($limit)) {
            $options['limit'] = $limit;
        }
        try {
            return $this->database->selectCollection(TB_CONVERSATION)->find(['status' => 'active', 'chat_id' => $chat_id, 'user_id' => $user_id], $options)->toArray();
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }
    }

    /**
     * Insert the conversation in the database
     *
     * @param string $user_id
     * @param string $chat_id
     * @param string $command
     *
     * @return bool|string
     * @throws TelegramException
     */
    public function insertConversation($user_id, $chat_id, $command)
    {
        if (!$this->isDbConnected()) {
            return false;
        }
        $date = $this->getTimestamp();

        $conversation = [
            'status' => 'active',
            'user_id' => $user_id,
            'chat_id' => $chat_id,
            'command' => $command,
            'notes' => '[]',
            'created_at' => $date,
            'updated_at' => $date
        ];
        $conversation['id'] = $this->generateId($conversation);
        try {
            $insertOneResult = $this->database->selectCollection(TB_CONVERSATION)->insertOne($conversation);
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $this->getInsertOneResult($insertOneResult);
    }

    public function generateId(array $itemData = null)
    {
        $id = microtime(true).json_encode($_SERVER);
        if ($itemData !== null) {
            $id .= json_encode($itemData);
        }

        return md5($id);
    }

    /**
     * Select cached shortened URL from the database
     *
     * @param string $url
     * @param string $user_id
     *
     * @return array|bool
     * @throws TelegramException
     * @deprecated Botan.io service is no longer working
     */
    public function selectShortUrl($url, $user_id)
    {
        if (!$this->isDbConnected()) {
            return false;
        }
        $options = [
            'limit' => 1,
            'sort' => ['created_at' => -1],
            'projection' => [
                'short_url' => 1
            ]
        ];

        try {
            $result = $this->database->selectCollection(TB_BOTAN_SHORTENER)->find(['user_id' => $user_id, 'url' => $url], $options)->toArray();
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return isset($result[0]['short_url']) ? $result[0]['short_url'] : false;
    }

    /**
     * Insert shortened URL into the database
     *
     * @param string $url
     * @param string $user_id
     * @param string $short_url
     *
     * @return bool|string
     * @throws TelegramException
     * @deprecated Botan.io service is no longer working
     *
     */
    public function insertShortUrl($url, $user_id, $short_url)
    {
        if (!$this->isDbConnected()) {
            return false;
        }
        try {
            $insertOneResult = $this->database->selectCollection(TB_BOTAN_SHORTENER)->insertOne([
                'user_id' => $user_id,
                'url' => $url,
                'short_url' => $short_url,
                'created_at' => $this->getTimestamp()
            ]);
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return $this->getInsertOneResult($insertOneResult);
    }

    protected function getInsertOneResult(InsertOneResult $insertOneResult)
    {
        $mongoId = $insertOneResult->getInsertedId()->__toString();

        return $mongoId ?: false;
    }

    /**
     * @return bool|string
     * @throws TelegramException
     */
    public function getDbVersion()
    {
        if (!$this->isDbConnected()) {
            return false;
        }
        $command = new Command(['buildinfo' => 1]);
        try {
            $result = $this->database->command($command)->toArray();
        } catch (\Exception $exception) {
            $errorMsg = __METHOD__ . " {$exception->getMessage()}";
            TelegramLog::error($errorMsg);
            error_log($errorMsg);

            throw new TelegramException($errorMsg);
        }

        return isset($result[0]['version']) ? $result[0]['version'] : false;
    }
}